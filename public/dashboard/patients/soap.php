<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES SOAP
// Edit dan finalisasi dokumentasi klinis mengikuti permission
// perubahan data kunjungan yang sudah digunakan modul visit.
// ============================================================
require_permission('visits.update');
Session::start();

// ============================================================
// TOKEN CSRF
// Semua perubahan clinical record harus berasal dari form sah.
// ============================================================
if (!Session::has('csrf_soap')) {
    Session::set('csrf_soap', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_soap');

$visitId = filter_input(INPUT_GET, 'visit_id', FILTER_VALIDATE_INT);
if (!$visitId || $visitId < 1) {
    http_response_code(400);
    exit('ID kunjungan tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL VISIT + SOAP + IDENTITAS PASIEN
// Query memastikan SOAP benar-benar berada di medical record
// pasien yang terkait dengan encounter tersebut.
// ============================================================
$query = $pdo->prepare(
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
        s.subjective,
        s.objective,
        s.assessment,
        s.plan,
        s.notes,
        s.status AS soap_status,
        s.created_by,
        s.updated_by,
        s.finalized_by,
        s.finalized_at
     FROM patient_visits AS v
     INNER JOIN patients AS p ON p.id = v.patient_id
     INNER JOIN patient_medical_records AS mr ON mr.id = v.medical_record_id
     INNER JOIN patient_visit_soap_notes AS s ON s.visit_id = v.id
     WHERE v.id = :visit_id
     LIMIT 1'
);
$query->execute(['visit_id' => $visitId]);
$record = $query->fetch();

if (!$record) {
    http_response_code(404);
    exit('Visit atau SOAP tidak ditemukan. Pastikan migration 024 sudah dijalankan.');
}

if ((string) $record['patient_status'] !== 'active') {
    http_response_code(409);
    exit('Pasien tidak aktif.');
}

if ((string) $record['record_status'] !== 'active') {
    http_response_code(409);
    exit('Master rekam medis pasien sudah ditutup.');
}

$form = [
    'subjective' => (string) ($record['subjective'] ?? ''),
    'objective' => (string) ($record['objective'] ?? ''),
    'assessment' => (string) ($record['assessment'] ?? ''),
    'plan' => (string) ($record['plan'] ?? ''),
    'notes' => (string) ($record['notes'] ?? ''),
];
$errors = [];
$success = null;

// ============================================================
// PROSES EDIT / FINALIZE
// SOAP FINALIZED tidak boleh diedit melalui endpoint biasa.
// Finalisasi dilakukan dalam transaksi agar metadata audit dan
// status clinical documentation berubah secara atomik.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    if ((string) $record['soap_status'] === 'finalized') {
        $errors[] = 'SOAP sudah FINALIZED dan tidak dapat diedit melalui workflow biasa.';
    }

    foreach ($form as $field => $value) {
        $form[$field] = trim((string) ($_POST[$field] ?? $value));
    }

    if ($action === 'save') {
        if ($form['subjective'] === '' && $form['objective'] === '' && $form['assessment'] === '' && $form['plan'] === '' && $form['notes'] === '') {
            $errors[] = 'Dokumentasi SOAP belum diisi. Isi minimal satu bagian.';
        }

        if (!$errors) {
            try {
                // ====================================================
                // SIMPAN PERUBAHAN DRAFT
                // Data legacy patient_visits tetap disinkronkan agar
                // modul lama tetap kompatibel selama masa transisi.
                // ====================================================
                $pdo->beginTransaction();

                $updateSoap = $pdo->prepare(
                    'UPDATE patient_visit_soap_notes
                     SET subjective = :subjective,
                         objective = :objective,
                         assessment = :assessment,
                         plan = :plan,
                         notes = :notes,
                         updated_by = :updated_by
                     WHERE id = :soap_id
                       AND status = \'draft\''
                );
                $updatedBy = $_SESSION['user_id'] ?? null;
                $updateSoap->execute([
                    'subjective' => $form['subjective'] !== '' ? $form['subjective'] : null,
                    'objective' => $form['objective'] !== '' ? $form['objective'] : null,
                    'assessment' => $form['assessment'] !== '' ? $form['assessment'] : null,
                    'plan' => $form['plan'] !== '' ? $form['plan'] : null,
                    'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                    'updated_by' => $updatedBy,
                    'soap_id' => (int) $record['soap_id'],
                ]);

                if ($updateSoap->rowCount() < 1) {
                    throw new RuntimeException('SOAP draft tidak berubah atau sudah difinalisasi.');
                }

                $updateVisit = $pdo->prepare(
                    'UPDATE patient_visits
                     SET complaint = :complaint,
                         examination = :examination,
                         diagnosis = :diagnosis,
                         treatment = :treatment,
                         notes = :notes,
                         updated_by = :updated_by
                     WHERE id = :visit_id'
                );
                $updateVisit->execute([
                    'complaint' => $form['subjective'] !== '' ? $form['subjective'] : null,
                    'examination' => $form['objective'] !== '' ? $form['objective'] : null,
                    'diagnosis' => $form['assessment'] !== '' ? $form['assessment'] : null,
                    'treatment' => $form['plan'] !== '' ? $form['plan'] : null,
                    'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                    'updated_by' => $updatedBy,
                    'visit_id' => $visitId,
                ]);

                $pdo->commit();
                header('Location: /dashboard/patients/soap.php?visit_id=' . $visitId . '&saved=1');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Draft SOAP gagal diperbarui. Silakan coba lagi.';
            }
        }
    } elseif ($action === 'finalize') {
        // ========================================================
        // FINALIZE SOAP
        // Setelah finalisasi, isi clinical documentation dikunci.
        // Koreksi di masa depan akan memakai amendment/addendum,
        // bukan overwrite terhadap catatan final.
        // ========================================================
        if (!$errors) {
            if ($form['subjective'] === '' && $form['objective'] === '' && $form['assessment'] === '' && $form['plan'] === '' && $form['notes'] === '') {
                $errors[] = 'SOAP tidak dapat difinalisasi karena seluruh dokumentasi masih kosong.';
            }

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $finalizedBy = $_SESSION['user_id'] ?? null;

                    $finalize = $pdo->prepare(
                        'UPDATE patient_visit_soap_notes
                         SET status = \'finalized\',
                             finalized_by = :finalized_by,
                             finalized_at = CURRENT_TIMESTAMP,
                             updated_by = :updated_by
                         WHERE id = :soap_id
                           AND status = \'draft\''
                    );
                    $finalize->execute([
                        'finalized_by' => $finalizedBy,
                        'updated_by' => $finalizedBy,
                        'soap_id' => (int) $record['soap_id'],
                    ]);

                    if ($finalize->rowCount() !== 1) {
                        throw new RuntimeException('SOAP sudah finalized atau tidak dapat difinalisasi.');
                    }

                    // Visit menjadi completed setelah dokumentasi klinis
                    // final berhasil dikunci. Void tetap memakai workflow
                    // void tersendiri dan tidak diubah oleh finalisasi ini.
                    $completeVisit = $pdo->prepare(
                        'UPDATE patient_visits
                         SET status = \'completed\', updated_by = :updated_by
                         WHERE id = :visit_id
                           AND status = \'open\''
                    );
                    $completeVisit->execute([
                        'updated_by' => $finalizedBy,
                        'visit_id' => $visitId,
                    ]);

                    $pdo->commit();
                    header('Location: /dashboard/patients/soap.php?visit_id=' . $visitId . '&finalized=1');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'SOAP gagal difinalisasi. Tidak ada perubahan yang disimpan.';
                }
            }
        }
    } else {
        $errors[] = 'Aksi formulir tidak valid.';
    }
}

if (isset($_GET['saved'])) {
    $success = 'Draft SOAP berhasil diperbarui.';
}
if (isset($_GET['finalized'])) {
    $success = 'SOAP berhasil difinalisasi dan dikunci.';
    $record['soap_status'] = 'finalized';
}

$e = static function (?string $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};
$isFinalized = (string) $record['soap_status'] === 'finalized';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SOAP <?= $e($record['visit_number']) ?> — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1000px, calc(100% - 32px)); margin: 32px auto 50px; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .head { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; margin-bottom: 20px; }
        h1 { margin: 0 0 6px; font-size: 26px; }
        .subtitle, .meta { margin: 0; color: #667085; font-size: 13px; line-height: 1.6; }
        .back { text-decoration: none; color: #344054; font-weight: 700; }
        .patient { padding: 15px; border: 1px solid #d9e2ec; border-radius: 12px; background: #f8fafc; margin-bottom: 18px; }
        .patient strong { display: block; margin-bottom: 4px; }
        .status { display: inline-flex; margin-top: 9px; padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .draft { background: #fff7ed; color: #9a3412; }
        .finalized { background: #ecfdf3; color: #146c43; }
        .alert { padding: 13px 15px; border-radius: 10px; margin-bottom: 16px; }
        .success { background: #ecfdf3; color: #146c43; }
        .error { background: #fff1f0; color: #b42318; }
        .error ul { margin: 0; padding-left: 20px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 17px; }
        .field.full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 7px; font-weight: 800; font-size: 14px; }
        textarea { width: 100%; min-height: 150px; resize: vertical; border: 1px solid #d0d5dd; border-radius: 10px; padding: 12px; font: inherit; line-height: 1.5; }
        textarea:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        textarea:disabled { background: #f8fafc; color: #475467; }
        .hint { display: block; margin-top: 6px; color: #667085; font-size: 12px; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        button, .button { border: 0; border-radius: 10px; padding: 11px 16px; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }
        .secondary { background: #f2f4f7; color: #344054; }
        .primary { background: #146c43; color: #fff; }
        .finalize { background: #175cd3; color: #fff; }
        .locked { padding: 15px; margin-top: 20px; border: 1px solid #abefc6; border-radius: 12px; background: #ecfdf3; color: #146c43; }
        @media (max-width: 700px) { .head { flex-direction: column; } .grid { grid-template-columns: 1fr; } .field.full { grid-column: auto; } .actions { flex-direction: column; } button, .button { width: 100%; text-align: center; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER SOAP
             Menampilkan identitas encounter dan status immutable.
             ===================================================== -->
        <div class="head">
            <div>
                <h1>🩺 SOAP Klinis</h1>
                <p class="subtitle"><?= $e($record['visit_number']) ?> · <?= $e($record['visit_date']) ?></p>
            </div>
            <a class="back" href="/dashboard/patients/medical-record.php?id=<?= (int) $record['patient_id'] ?>">← Medical Record</a>
        </div>

        <div class="patient">
            <strong><?= $e($record['full_name']) ?></strong>
            <div class="meta">RM: <?= $e($record['medical_record_number']) ?> · Visit ID: #<?= (int) $visitId ?></div>
            <span class="status <?= $isFinalized ? 'finalized' : 'draft' ?>">
                <?= $isFinalized ? '🔒 FINALIZED' : '📝 DRAFT' ?>
            </span>
        </div>

        <?php if ($success): ?>
            <div class="alert success" role="status"><?= $e($success) ?></div>
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

        <?php if ($isFinalized): ?>
            <div class="locked">
                <strong>🔒 Dokumentasi klinis terkunci.</strong><br>
                SOAP yang sudah FINALIZED tidak dapat diubah melalui workflow edit biasa. Koreksi berikutnya akan menggunakan mekanisme Amendment/Addendum.
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
            <div class="grid">
                <div class="field full">
                    <label for="subjective">S — Subjective</label>
                    <textarea id="subjective" name="subjective" <?= $isFinalized ? 'disabled' : '' ?>><?= $e($form['subjective']) ?></textarea>
                    <span class="hint">Keluhan utama dan informasi subjektif dari pasien.</span>
                </div>

                <div class="field full">
                    <label for="objective">O — Objective</label>
                    <textarea id="objective" name="objective" <?= $isFinalized ? 'disabled' : '' ?>><?= $e($form['objective']) ?></textarea>
                    <span class="hint">Hasil pemeriksaan dan temuan objektif.</span>
                </div>

                <div class="field">
                    <label for="assessment">A — Assessment</label>
                    <textarea id="assessment" name="assessment" <?= $isFinalized ? 'disabled' : '' ?>><?= $e($form['assessment']) ?></textarea>
                    <span class="hint">Diagnosis atau kesan klinis.</span>
                </div>

                <div class="field">
                    <label for="plan">P — Plan</label>
                    <textarea id="plan" name="plan" <?= $isFinalized ? 'disabled' : '' ?>><?= $e($form['plan']) ?></textarea>
                    <span class="hint">Terapi dan rencana tindak lanjut.</span>
                </div>

                <div class="field full">
                    <label for="notes">📝 Catatan Tambahan</label>
                    <textarea id="notes" name="notes" <?= $isFinalized ? 'disabled' : '' ?>><?= $e($form['notes']) ?></textarea>
                </div>
            </div>

            <?php if (!$isFinalized): ?>
                <div class="actions">
                    <a class="button secondary" href="/dashboard/patients/medical-record.php?id=<?= (int) $record['patient_id'] ?>">Batal</a>
                    <button class="primary" type="submit" name="action" value="save">💾 Simpan Draft</button>
                    <button class="finalize" type="submit" name="action" value="finalize" onclick="return confirm('Finalisasi SOAP ini? Setelah finalisasi, catatan tidak dapat diedit melalui workflow biasa.');">🔒 Finalize SOAP</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</main>
</body>
</html>
