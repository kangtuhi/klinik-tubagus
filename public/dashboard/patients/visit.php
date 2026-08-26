<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES FORM KUNJUNGAN
// Hanya pengguna dengan permission visits.create yang dapat
// membuat encounter baru beserta dokumentasi SOAP-nya.
// ============================================================
require_permission('visits.create');
Session::start();

// ============================================================
// TOKEN CSRF
// Melindungi proses penyimpanan clinical record dari request
// lintas situs yang tidak sah.
// ============================================================
if (!Session::has('csrf_patient_visit')) {
    Session::set('csrf_patient_visit', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_patient_visit');

$errors = [];
$patient = null;

// ============================================================
// VALIDASI ID PASIEN
// Encounter wajib terhubung ke pasien yang sudah terdaftar.
// ============================================================
$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL IDENTITAS DAN MASTER MEDICAL RECORD
// Medical record menjadi clinical container untuk seluruh visit.
// ============================================================
$select = $pdo->prepare(
    'SELECT
        p.id,
        p.medical_record_number,
        p.full_name,
        p.status,
        mr.id AS medical_record_id,
        mr.record_status
     FROM patients AS p
     INNER JOIN patient_medical_records AS mr
        ON mr.patient_id = p.id
     WHERE p.id = :id
     LIMIT 1'
);
$select->execute(['id' => $patientId]);
$patient = $select->fetch();

if (!$patient) {
    http_response_code(404);
    exit('Pasien atau master rekam medis tidak ditemukan.');
}

if ((string) $patient['status'] !== 'active') {
    http_response_code(409);
    exit('Pasien tidak aktif. Aktifkan pasien terlebih dahulu sebelum membuat kunjungan.');
}

if ((string) $patient['record_status'] !== 'active') {
    http_response_code(409);
    exit('Master rekam medis pasien sudah ditutup.');
}

// ============================================================
// NILAI AWAL FORM SOAP
// Dokumentasi klinis baru dimulai sebagai draft agar dokter dapat
// melengkapi dan meninjau catatan sebelum finalisasi.
// ============================================================
$form = [
    'visit_date' => date('Y-m-d'),
    'subjective' => '',
    'objective' => '',
    'assessment' => '',
    'plan' => '',
    'notes' => '',
];

// ============================================================
// PROSES SIMPAN DRAFT SOAP
// Visit dan SOAP disimpan dalam satu transaksi agar tidak pernah
// tercipta visit tanpa dokumentasi SOAP yang terkait.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman dan coba lagi.';
    }

    foreach ($form as $field => $default) {
        $form[$field] = trim((string) ($_POST[$field] ?? $default));
    }

    // ========================================================
    // VALIDASI TANGGAL KUNJUNGAN
    // Tanggal harus valid dan tidak boleh berada di masa depan.
    // ========================================================
    $dateObject = DateTime::createFromFormat('Y-m-d', $form['visit_date']);
    $dateIsValid = $dateObject && $dateObject->format('Y-m-d') === $form['visit_date'];

    if (!$dateIsValid) {
        $errors[] = 'Tanggal kunjungan tidak valid.';
    } elseif ($form['visit_date'] > date('Y-m-d')) {
        $errors[] = 'Tanggal kunjungan tidak boleh berada di masa depan.';
    }

    // ========================================================
    // VALIDASI SOAP
    // Pada tahap draft, seluruh komponen SOAP boleh belum lengkap.
    // Ini sengaja agar dokter dapat menyimpan pekerjaan sementara.
    // ========================================================
    if ($form['subjective'] === '' && $form['objective'] === '' && $form['assessment'] === '' && $form['plan'] === '' && $form['notes'] === '') {
        $errors[] = 'Dokumentasi SOAP belum diisi. Isi minimal satu bagian sebelum menyimpan draft.';
    }

    if (!$errors) {
        $visitNumber = 'KJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $createdBy = $_SESSION['user_id'] ?? null;

        try {
            $pdo->beginTransaction();

            // ====================================================
            // SIMPAN ENCOUNTER
            // Field clinical lama tetap dipertahankan sebagai
            // kompatibilitas, tetapi sumber dokumentasi baru adalah
            // tabel patient_visit_soap_notes.
            // ====================================================
            $insertVisit = $pdo->prepare(
                'INSERT INTO patient_visits
                    (patient_id, medical_record_id, visit_number, visit_date,
                     complaint, examination, diagnosis, treatment, notes,
                     status, created_by)
                 VALUES
                    (:patient_id, :medical_record_id, :visit_number, :visit_date,
                     :complaint, :examination, :diagnosis, :treatment, :notes,
                     \'open\', :created_by)'
            );

            $insertVisit->execute([
                'patient_id' => $patientId,
                'medical_record_id' => (int) $patient['medical_record_id'],
                'visit_number' => $visitNumber,
                'visit_date' => $form['visit_date'],
                'complaint' => $form['subjective'] !== '' ? $form['subjective'] : null,
                'examination' => $form['objective'] !== '' ? $form['objective'] : null,
                'diagnosis' => $form['assessment'] !== '' ? $form['assessment'] : null,
                'treatment' => $form['plan'] !== '' ? $form['plan'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'created_by' => $createdBy,
            ]);

            $visitId = (int) $pdo->lastInsertId();

            // ====================================================
            // SIMPAN SOAP DRAFT
            // Satu visit memiliki satu SOAP utama pada arsitektur v1.
            // ====================================================
            $insertSoap = $pdo->prepare(
                'INSERT INTO patient_visit_soap_notes
                    (visit_id, subjective, objective, assessment, plan,
                     notes, status, created_by, updated_by)
                 VALUES
                    (:visit_id, :subjective, :objective, :assessment, :plan,
                     :notes, \'draft\', :created_by, :updated_by)'
            );

            $insertSoap->execute([
                'visit_id' => $visitId,
                'subjective' => $form['subjective'] !== '' ? $form['subjective'] : null,
                'objective' => $form['objective'] !== '' ? $form['objective'] : null,
                'assessment' => $form['assessment'] !== '' ? $form['assessment'] : null,
                'plan' => $form['plan'] !== '' ? $form['plan'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);

            $pdo->commit();

            // ====================================================
            // SELESAI SIMPAN DRAFT
            // Detail visit akan menjadi tempat dokter melanjutkan
            // pengisian dan proses finalisasi pada tahap berikutnya.
            // ====================================================
            header('Location: /dashboard/patients/profile.php?id=' . $patientId . '&visit=created');
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'Nomor kunjungan atau SOAP sudah terdaftar. Silakan coba simpan kembali.';
            } else {
                $errors[] = 'Draft kunjungan gagal disimpan. Silakan coba lagi.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SOAP Klinis — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1000px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 22px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .back { color: #475467; text-decoration: none; font-weight: 700; }
        .patient-box { padding: 15px 17px; margin-bottom: 20px; border: 1px solid #abefc6; border-radius: 12px; background: #ecfdf3; }
        .patient-box strong { display: block; color: #146c43; margin-bottom: 5px; }
        .patient-meta { color: #475467; font-size: 14px; }
        .soap-banner { margin-bottom: 20px; padding: 14px 16px; border: 1px solid #d9e2ec; border-radius: 12px; background: #f8fafc; }
        .soap-banner strong { display: block; margin-bottom: 4px; }
        .soap-banner span { color: #667085; font-size: 13px; }
        .errors { margin-bottom: 20px; padding: 14px 16px; border-radius: 10px; background: #fff1f0; color: #b42318; }
        .errors ul { margin: 0; padding-left: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .field { display: flex; flex-direction: column; gap: 7px; }
        .field.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 14px; }
        .required { color: #b42318; }
        input, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        textarea { min-height: 140px; resize: vertical; }
        input:focus, textarea:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .section-title { grid-column: 1 / -1; margin: 5px 0 0; padding-bottom: 8px; border-bottom: 1px solid #edf0f2; color: #146c43; font-size: 17px; }
        .soap-code { display: inline-block; min-width: 28px; margin-right: 6px; font-weight: 800; }
        .hint { color: #667085; font-size: 12px; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
        .button { border: 0; cursor: pointer; padding: 11px 16px; border-radius: 10px; font: inherit; font-weight: 700; text-decoration: none; }
        .button.secondary { background: #f2f4f7; color: #344054; }
        .button.primary { background: #146c43; color: #fff; }
        @media (max-width: 700px) {
            main { margin-top: 22px; }
            .form-grid { grid-template-columns: 1fr; }
            .field.full, .section-title { grid-column: auto; }
            .titlebar { flex-direction: column; }
            .actions { flex-direction: column-reverse; }
            .button { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER FORM SOAP
             Menampilkan konteks pasien dan tujuan dokumentasi.
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>🩺 SOAP Klinis</h1>
                <p class="subtitle">Buat dokumentasi klinis baru sebagai draft.</p>
            </div>
            <a class="back" href="/dashboard/patients/profile.php?id=<?= (int) $patientId ?>">← Profil Pasien</a>
        </div>

        <div class="patient-box">
            <strong><?= htmlspecialchars((string) $patient['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <div class="patient-meta">
                RM: <?= htmlspecialchars((string) $patient['medical_record_number'], ENT_QUOTES, 'UTF-8') ?>
                · Medical Record #<?= (int) $patient['medical_record_id'] ?>
            </div>
        </div>

        <div class="soap-banner">
            <strong>📝 Status: DRAFT</strong>
            <span>Draft dapat dilanjutkan dan akan difinalisasi melalui workflow klinis berikutnya.</span>
        </div>

        <?php if ($errors): ?>
            <!-- =================================================
                 PESAN VALIDASI
                 Seluruh pesan ditampilkan dengan escaping agar aman.
                 ================================================= -->
            <div class="errors" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-grid">
                <!-- =================================================
                     INFORMASI ENCOUNTER
                     ================================================= -->
                <h2 class="section-title">Informasi Kunjungan</h2>

                <div class="field">
                    <label for="visit_date">Tanggal Kunjungan <span class="required">*</span></label>
                    <input id="visit_date" name="visit_date" type="date" value="<?= htmlspecialchars($form['visit_date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field">
                    <label>Status Dokumentasi</label>
                    <input value="Draft" disabled>
                    <span class="hint">Kunjungan baru belum dianggap final secara klinis.</span>
                </div>

                <!-- =================================================
                     SUBJECTIVE
                     Informasi yang berasal dari keluhan dan cerita pasien.
                     ================================================= -->
                <h2 class="section-title"><span class="soap-code">S</span> Subjective</h2>

                <div class="field full">
                    <label for="subjective">Keluhan dan Riwayat dari Pasien</label>
                    <textarea id="subjective" name="subjective" placeholder="Tuliskan keluhan utama, riwayat keluhan, dan informasi subjektif lainnya."><?= htmlspecialchars($form['subjective'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- =================================================
                     OBJECTIVE
                     Temuan objektif dari pemeriksaan klinis.
                     ================================================= -->
                <h2 class="section-title"><span class="soap-code">O</span> Objective</h2>

                <div class="field full">
                    <label for="objective">Pemeriksaan dan Temuan Klinis</label>
                    <textarea id="objective" name="objective" placeholder="Contoh: tanda vital, pemeriksaan fisik, dan temuan objektif lainnya."><?= htmlspecialchars($form['objective'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- =================================================
                     ASSESSMENT
                     Penilaian klinis dan diagnosis.
                     ================================================= -->
                <h2 class="section-title"><span class="soap-code">A</span> Assessment</h2>

                <div class="field full">
                    <label for="assessment">Diagnosis / Kesan Klinis</label>
                    <textarea id="assessment" name="assessment" placeholder="Tuliskan diagnosis atau kesan klinis sementara."><?= htmlspecialchars($form['assessment'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- =================================================
                     PLAN
                     Rencana terapi dan tindak lanjut.
                     ================================================= -->
                <h2 class="section-title"><span class="soap-code">P</span> Plan</h2>

                <div class="field full">
                    <label for="plan">Terapi / Rencana Tindak Lanjut</label>
                    <textarea id="plan" name="plan" placeholder="Tuliskan terapi, edukasi, follow-up, atau rencana pelayanan berikutnya."><?= htmlspecialchars($form['plan'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- =================================================
                     CATATAN TAMBAHAN
                     Informasi yang tidak perlu dipaksa masuk ke SOAP.
                     ================================================= -->
                <h2 class="section-title">Catatan Tambahan</h2>

                <div class="field full">
                    <label for="notes">Catatan Klinis</label>
                    <textarea id="notes" name="notes" placeholder="Catatan tambahan bila diperlukan."><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <!-- =================================================
                 AKSI FORMULIR
                 Simpan sebagai draft; finalisasi dilakukan terpisah.
                 ================================================= -->
            <div class="actions">
                <a class="button secondary" href="/dashboard/patients/profile.php?id=<?= (int) $patientId ?>">Batal</a>
                <button class="button primary" type="submit">💾 Simpan Draft SOAP</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
