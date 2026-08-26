<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES DIAGNOSIS TERSTRUKTUR
// Penyimpanan diagnosis merupakan perubahan data clinical visit,
// sehingga mengikuti permission visits.update.
// ============================================================
require_permission('visits.update');
Session::start();

// ============================================================
// TOKEN CSRF
// Semua penyimpanan diagnosis harus berasal dari form yang sah.
// ============================================================
if (!Session::has('csrf_diagnosis')) {
    Session::set('csrf_diagnosis', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_diagnosis');

$visitId = filter_input(INPUT_GET, 'visit_id', FILTER_VALIDATE_INT);
if (!$visitId || $visitId < 1) {
    http_response_code(400);
    exit('ID kunjungan tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL KONTEKS VISIT
// Diagnosis harus selalu berada di dalam patient medical record
// yang benar dan tidak boleh menempel ke visit pasien lain.
// ============================================================
$visitQuery = $pdo->prepare(
    'SELECT
        v.id AS visit_id,
        v.patient_id,
        v.medical_record_id,
        v.visit_number,
        v.visit_date,
        v.status AS visit_status,
        p.medical_record_number,
        p.full_name,
        p.status AS patient_status,
        mr.record_status,
        s.id AS soap_id,
        s.status AS soap_status
     FROM patient_visits AS v
     INNER JOIN patients AS p ON p.id = v.patient_id
     INNER JOIN patient_medical_records AS mr ON mr.id = v.medical_record_id
     LEFT JOIN patient_visit_soap_notes AS s ON s.visit_id = v.id
     WHERE v.id = :visit_id
     LIMIT 1'
);
$visitQuery->execute(['visit_id' => $visitId]);
$visit = $visitQuery->fetch();

if (!$visit) {
    http_response_code(404);
    exit('Kunjungan tidak ditemukan.');
}

if ((string) $visit['patient_status'] !== 'active') {
    http_response_code(409);
    exit('Pasien tidak aktif.');
}

if ((string) $visit['record_status'] !== 'active') {
    http_response_code(409);
    exit('Master rekam medis pasien sudah ditutup.');
}

// ============================================================
// INTEGRITAS WORKFLOW
// Diagnosis baru hanya ditambahkan ketika visit masih OPEN.
// Visit COMPLETED/VOIDED tidak boleh menerima perubahan klinis
// melalui workflow create biasa.
// ============================================================
if ((string) $visit['visit_status'] !== 'open') {
    http_response_code(409);
    exit('Diagnosis baru hanya dapat ditambahkan pada kunjungan yang masih OPEN.');
}

$form = [
    'diagnosis_type' => 'primary',
    'icd10_code' => '',
    'diagnosis_name' => '',
    'clinical_notes' => '',
];
$errors = [];
$success = null;

// ============================================================
// PROSES SIMPAN DIAGNOSIS
// Data diagnosis disimpan terpisah dari free-text assessment SOAP.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    $form['diagnosis_type'] = trim((string) ($_POST['diagnosis_type'] ?? 'primary'));
    $form['icd10_code'] = strtoupper(trim((string) ($_POST['icd10_code'] ?? '')));
    $form['diagnosis_name'] = trim((string) ($_POST['diagnosis_name'] ?? ''));
    $form['clinical_notes'] = trim((string) ($_POST['clinical_notes'] ?? ''));

    // ========================================================
    // VALIDASI JENIS DIAGNOSIS
    // Hanya tiga kategori yang ditetapkan oleh migration 026.
    // ========================================================
    $allowedTypes = ['primary', 'secondary', 'differential'];
    if (!in_array($form['diagnosis_type'], $allowedTypes, true)) {
        $errors[] = 'Jenis diagnosis tidak valid.';
    }

    // ========================================================
    // VALIDASI NAMA DIAGNOSIS
    // Nama diagnosis wajib ada karena menjadi deskripsi klinis utama.
    // ========================================================
    if ($form['diagnosis_name'] === '') {
        $errors[] = 'Nama diagnosis wajib diisi.';
    } elseif (mb_strlen($form['diagnosis_name']) > 255) {
        $errors[] = 'Nama diagnosis maksimal 255 karakter.';
    }

    // ========================================================
    // VALIDASI KODE ICD-10
    // Kode bersifat opsional. Jika diisi, format dasar ICD-10 diperiksa
    // tanpa memaksa aplikasi menebak diagnosis dari free-text lama.
    // ========================================================
    if ($form['icd10_code'] !== '') {
        if (mb_strlen($form['icd10_code']) > 20 || !preg_match('/^[A-Z][0-9]{2}(?:\.[A-Z0-9]{1,4})?$/', $form['icd10_code'])) {
            $errors[] = 'Format kode ICD-10 tidak valid.';
        }
    }

    // ========================================================
    // BATAS PANJANG CATATAN
    // Menjaga input tetap sesuai tipe TEXT tanpa menerima payload
    // berlebihan dari form.
    // ========================================================
    if (mb_strlen($form['clinical_notes']) > 65535) {
        $errors[] = 'Catatan klinis terlalu panjang.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // ====================================================
            // SATU DIAGNOSIS UTAMA AKTIF PER VISIT
            // Diagnosis sekunder dan banding boleh lebih dari satu.
            // ====================================================
            if ($form['diagnosis_type'] === 'primary') {
                $primaryCheck = $pdo->prepare(
                    'SELECT id
                     FROM patient_visit_diagnoses
                     WHERE visit_id = :visit_id
                       AND diagnosis_type = \'primary\'
                       AND status = \'active\'
                     LIMIT 1
                     FOR UPDATE'
                );
                $primaryCheck->execute(['visit_id' => $visitId]);

                if ($primaryCheck->fetch()) {
                    throw new DomainException('Diagnosis utama aktif untuk kunjungan ini sudah ada. Tambahkan diagnosis sebagai sekunder/banding atau gunakan workflow perubahan diagnosis.');
                }
            }

            // ====================================================
            // SIMPAN DIAGNOSIS TERSTRUKTUR
            // SOAP ID diambil dari konteks visit, bukan dari input user,
            // sehingga relasi diagnosis -> SOAP tidak dapat dipalsukan.
            // ====================================================
            $insert = $pdo->prepare(
                'INSERT INTO patient_visit_diagnoses
                    (visit_id, soap_id, diagnosis_type, icd10_code,
                     diagnosis_name, clinical_notes, status, created_by, updated_by)
                 VALUES
                    (:visit_id, :soap_id, :diagnosis_type, :icd10_code,
                     :diagnosis_name, :clinical_notes, \'active\', :created_by, :updated_by)'
            );

            $createdBy = $_SESSION['user_id'] ?? null;
            $insert->execute([
                'visit_id' => $visitId,
                'soap_id' => !empty($visit['soap_id']) ? (int) $visit['soap_id'] : null,
                'diagnosis_type' => $form['diagnosis_type'],
                'icd10_code' => $form['icd10_code'] !== '' ? $form['icd10_code'] : null,
                'diagnosis_name' => $form['diagnosis_name'],
                'clinical_notes' => $form['clinical_notes'] !== '' ? $form['clinical_notes'] : null,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);

            $pdo->commit();

            header('Location: /dashboard/patients/diagnosis.php?visit_id=' . $visitId . '&saved=1');
            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Error database tidak diekspos ke pengguna; detail tetap di
            // server log jika logging aplikasi tersedia.
            $errors[] = 'Diagnosis gagal disimpan. Silakan coba lagi.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Diagnosis gagal disimpan. Tidak ada perubahan yang diterapkan.';
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Diagnosis berhasil disimpan.';
}

// ============================================================
// AMBIL DAFTAR DIAGNOSIS VISIT
// Daftar ditampilkan setelah proses simpan untuk memastikan data
// terstruktur benar-benar masuk ke visit yang sedang dibuka.
// ============================================================
$diagnosisQuery = $pdo->prepare(
    'SELECT
        d.id,
        d.diagnosis_type,
        d.icd10_code,
        d.diagnosis_name,
        d.clinical_notes,
        d.status,
        d.created_at,
        d.updated_at,
        creator.name AS created_by_name
     FROM patient_visit_diagnoses AS d
     LEFT JOIN users AS creator ON creator.id = d.created_by
     WHERE d.visit_id = :visit_id
     ORDER BY
        CASE d.diagnosis_type
            WHEN \'primary\' THEN 1
            WHEN \'secondary\' THEN 2
            WHEN \'differential\' THEN 3
            ELSE 4
        END,
        d.id ASC'
);
$diagnosisQuery->execute(['visit_id' => $visitId]);
$diagnoses = $diagnosisQuery->fetchAll();

$e = static function (?string $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$typeLabels = [
    'primary' => 'UTAMA',
    'secondary' => 'SEKUNDER',
    'differential' => 'BANDING',
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnosis Klinis <?= $e($visit['visit_number']) ?> — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1050px, calc(100% - 32px)); margin: 32px auto 50px; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .head { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; margin-bottom: 20px; }
        h1 { margin: 0 0 6px; font-size: 27px; }
        h2 { margin: 0; font-size: 18px; }
        .subtitle, .meta { margin: 0; color: #667085; font-size: 13px; line-height: 1.6; }
        .back { text-decoration: none; color: #344054; font-weight: 700; }
        .patient { padding: 15px; border: 1px solid #d9e2ec; border-radius: 12px; background: #f8fafc; margin-bottom: 22px; }
        .patient strong { display: block; margin-bottom: 4px; }
        .workflow { margin-top: 9px; display: inline-flex; padding: 5px 9px; border-radius: 999px; background: #fff7ed; color: #9a3412; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .alert { padding: 13px 15px; border-radius: 10px; margin-bottom: 18px; }
        .success { background: #ecfdf3; color: #146c43; }
        .error { background: #fff1f0; color: #b42318; }
        .error ul { margin: 0; padding-left: 20px; }
        .form-section { padding: 20px; border: 1px solid #e3e7eb; border-radius: 14px; background: #fff; }
        .section-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 17px; }
        .grid { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 17px; }
        .field.full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 7px; font-weight: 800; font-size: 14px; }
        select, input, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        textarea { min-height: 125px; resize: vertical; line-height: 1.5; }
        select:focus, input:focus, textarea:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .hint { display: block; margin-top: 6px; color: #667085; font-size: 12px; line-height: 1.5; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        button, .button { border: 0; border-radius: 10px; padding: 11px 16px; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }
        .secondary { background: #f2f4f7; color: #344054; }
        .primary { background: #146c43; color: #fff; }
        .list-section { margin-top: 22px; }
        .diagnosis-list { display: grid; gap: 12px; margin-top: 14px; }
        .diagnosis-card { padding: 16px; border: 1px solid #e3e7eb; border-radius: 12px; background: #f8fafc; }
        .diagnosis-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .badge { display: inline-flex; padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 800; }
        .badge.primary { background: #ecfdf3; color: #146c43; }
        .badge.secondary { background: #eff8ff; color: #175cd3; }
        .badge.differential { background: #fffaeb; color: #b54708; }
        .diagnosis-name { margin: 9px 0 4px; font-size: 16px; font-weight: 800; }
        .icd { color: #475467; font-size: 12px; }
        .notes { margin-top: 10px; white-space: pre-wrap; color: #344054; line-height: 1.5; font-size: 13px; }
        .audit { margin-top: 10px; color: #667085; font-size: 11px; }
        .empty { padding: 24px; border: 1px dashed #cfd6dc; border-radius: 12px; text-align: center; color: #667085; }
        @media (max-width: 700px) {
            main { width: min(100% - 20px, 1050px); margin-top: 18px; }
            .panel { padding: 18px; }
            .head, .section-head { flex-direction: column; align-items: flex-start; }
            .grid { grid-template-columns: 1fr; }
            .field.full { grid-column: auto; }
            .actions { flex-direction: column; }
            button, .button { width: 100%; text-align: center; }
            .diagnosis-head { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER DIAGNOSIS
             Menampilkan konteks pasien, visit, dan status workflow.
             ===================================================== -->
        <div class="head">
            <div>
                <h1>🧬 Diagnosis Klinis Terstruktur</h1>
                <p class="subtitle">Tambahkan diagnosis tanpa mengubah free-text SOAP secara diam-diam.</p>
            </div>
            <a class="back" href="/dashboard/patients/medical-record.php?id=<?= (int) $visit['patient_id'] ?>">← Medical Record</a>
        </div>

        <div class="patient">
            <strong><?= $e($visit['full_name']) ?></strong>
            <div class="meta">
                RM: <?= $e($visit['medical_record_number']) ?>
                · <?= $e($visit['visit_number']) ?>
                · <?= $e($visit['visit_date']) ?>
            </div>
            <span class="workflow">Visit OPEN<?= !empty($visit['soap_id']) ? ' · SOAP ' . strtoupper($e($visit['soap_status'])) : ' · SOAP belum tersedia' ?></span>
        </div>

        <?php if ($success): ?>
            <div class="alert success" role="status">✅ <?= $e($success) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="form-section">
            <div class="section-head">
                <div>
                    <h2>Tambah Diagnosis</h2>
                    <p class="subtitle">Diagnosis utama hanya boleh satu yang berstatus aktif pada satu visit.</p>
                </div>
            </div>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

                <div class="grid">
                    <div class="field">
                        <label for="diagnosis_type">Jenis Diagnosis <span style="color:#b42318">*</span></label>
                        <select id="diagnosis_type" name="diagnosis_type" required>
                            <option value="primary" <?= $form['diagnosis_type'] === 'primary' ? 'selected' : '' ?>>Diagnosis Utama</option>
                            <option value="secondary" <?= $form['diagnosis_type'] === 'secondary' ? 'selected' : '' ?>>Diagnosis Sekunder</option>
                            <option value="differential" <?= $form['diagnosis_type'] === 'differential' ? 'selected' : '' ?>>Diagnosis Banding</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="diagnosis_name">Nama Diagnosis <span style="color:#b42318">*</span></label>
                        <input
                            id="diagnosis_name"
                            name="diagnosis_name"
                            type="text"
                            maxlength="255"
                            value="<?= $e($form['diagnosis_name']) ?>"
                            placeholder="Contoh: Hipertensi esensial"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="icd10_code">Kode ICD-10</label>
                        <input
                            id="icd10_code"
                            name="icd10_code"
                            type="text"
                            maxlength="20"
                            value="<?= $e($form['icd10_code']) ?>"
                            placeholder="Contoh: I10"
                        >
                        <span class="hint">Opsional. Kode tidak ditebak otomatis dari teks diagnosis.</span>
                    </div>

                    <div class="field full">
                        <label for="clinical_notes">Catatan Klinis</label>
                        <textarea id="clinical_notes" name="clinical_notes" maxlength="65535" placeholder="Catatan pendukung diagnosis, bila diperlukan."><?= $e($form['clinical_notes']) ?></textarea>
                    </div>
                </div>

                <div class="actions">
                    <a class="button secondary" href="/dashboard/patients/soap.php?visit_id=<?= (int) $visitId ?>">← Kembali ke SOAP</a>
                    <button class="primary" type="submit">💾 Simpan Diagnosis</button>
                </div>
            </form>
        </section>

        <section class="list-section">
            <div class="section-head">
                <div>
                    <h2>📋 Diagnosis Visit</h2>
                    <p class="subtitle">Diagnosis terstruktur yang sudah tersimpan pada kunjungan ini.</p>
                </div>
                <span class="meta"><?= count($diagnoses) ?> diagnosis</span>
            </div>

            <?php if (!$diagnoses): ?>
                <div class="empty">Belum ada diagnosis terstruktur pada visit ini.</div>
            <?php else: ?>
                <div class="diagnosis-list">
                    <?php foreach ($diagnoses as $diagnosis): ?>
                        <article class="diagnosis-card">
                            <div class="diagnosis-head">
                                <span class="badge <?= $e($diagnosis['diagnosis_type']) ?>">
                                    <?= $e($typeLabels[$diagnosis['diagnosis_type']] ?? strtoupper((string) $diagnosis['diagnosis_type'])) ?>
                                </span>
                                <span class="meta">Status: <?= $e($diagnosis['status']) ?></span>
                            </div>

                            <div class="diagnosis-name"><?= $e($diagnosis['diagnosis_name']) ?></div>

                            <?php if (!empty($diagnosis['icd10_code'])): ?>
                                <div class="icd">ICD-10: <strong><?= $e($diagnosis['icd10_code']) ?></strong></div>
                            <?php endif; ?>

                            <?php if (!empty($diagnosis['clinical_notes'])): ?>
                                <div class="notes"><?= $e($diagnosis['clinical_notes']) ?></div>
                            <?php endif; ?>

                            <div class="audit">
                                Dibuat oleh: <strong><?= $e($diagnosis['created_by_name'] ?: 'Pengguna') ?></strong>
                                · <?= $e($diagnosis['created_at']) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
</body>
</html>
