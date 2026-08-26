<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES EDIT KUNJUNGAN
// Hanya pengguna dengan permission visits.update yang dapat
// mengubah data klinis kunjungan pasien.
// ============================================================
require_permission('visits.update');
Session::start();

// ============================================================
// TOKEN CSRF
// Melindungi proses perubahan data kunjungan dari request
// lintas situs yang tidak sah.
// ============================================================
if (!Session::has('csrf_patient_visit_edit')) {
    Session::set('csrf_patient_visit_edit', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_patient_visit_edit');

$errors = [];

// ============================================================
// VALIDASI ID KUNJUNGAN
// ID kunjungan wajib berupa angka positif.
// ============================================================
$visitId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$visitId || $visitId < 1) {
    http_response_code(400);
    exit('ID kunjungan tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL KUNJUNGAN DAN PASIEN
// Data pasien digunakan sebagai konteks agar pengguna tidak
// salah mengubah record klinis pasien lain.
// ============================================================
$select = $pdo->prepare(
    'SELECT
        v.id,
        v.patient_id,
        v.visit_number,
        v.visit_date,
        v.complaint,
        v.examination,
        v.diagnosis,
        v.treatment,
        v.notes,
        v.status,
        p.medical_record_number,
        p.full_name,
        p.status AS patient_status
     FROM patient_visits v
     INNER JOIN patients p ON p.id = v.patient_id
     WHERE v.id = :id
     LIMIT 1'
);
$select->execute(['id' => $visitId]);
$visit = $select->fetch();

if (!$visit) {
    http_response_code(404);
    exit('Kunjungan tidak ditemukan.');
}

// ============================================================
// DATA FORMULIR
// Nilai awal berasal dari record kunjungan yang sedang diedit.
// ============================================================
$form = [
    'visit_date' => (string) $visit['visit_date'],
    'complaint' => (string) ($visit['complaint'] ?? ''),
    'examination' => (string) ($visit['examination'] ?? ''),
    'diagnosis' => (string) ($visit['diagnosis'] ?? ''),
    'treatment' => (string) ($visit['treatment'] ?? ''),
    'notes' => (string) ($visit['notes'] ?? ''),
];

// ============================================================
// PROSES PEMBARUAN KUNJUNGAN
// Status dan nomor kunjungan tidak diubah pada form ini karena
// keduanya merupakan metadata workflow dan identitas record.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    // ========================================================
    // VALIDASI CSRF
    // ========================================================
    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman dan coba lagi.';
    }

    foreach ($form as $field => $default) {
        $form[$field] = trim((string) ($_POST[$field] ?? $default));
    }

    // ========================================================
    // VALIDASI TANGGAL
    // Tanggal kunjungan wajib valid dan tidak boleh di masa depan.
    // ========================================================
    $dateObject = DateTime::createFromFormat('Y-m-d', $form['visit_date']);
    $dateIsValid = $dateObject && $dateObject->format('Y-m-d') === $form['visit_date'];

    if (!$dateIsValid) {
        $errors[] = 'Tanggal kunjungan tidak valid.';
    } elseif ($form['visit_date'] > date('Y-m-d')) {
        $errors[] = 'Tanggal kunjungan tidak boleh berada di masa depan.';
    }

    // ========================================================
    // VALIDASI FIELD WAJIB
    // Keluhan utama dan diagnosis menjadi minimum informasi klinis.
    // ========================================================
    if ($form['complaint'] === '') {
        $errors[] = 'Keluhan utama wajib diisi.';
    }

    if ($form['diagnosis'] === '') {
        $errors[] = 'Diagnosis wajib diisi.';
    }

    if (!$errors) {
        try {
            // ====================================================
            // SIMPAN PERUBAHAN
            // Prepared statement digunakan untuk mencegah SQL
            // injection dan updated_by mencatat pengguna terakhir.
            // ====================================================
            $update = $pdo->prepare(
                'UPDATE patient_visits
                 SET visit_date = :visit_date,
                     complaint = :complaint,
                     examination = :examination,
                     diagnosis = :diagnosis,
                     treatment = :treatment,
                     notes = :notes,
                     updated_by = :updated_by
                 WHERE id = :id'
            );

            $update->execute([
                'visit_date' => $form['visit_date'],
                'complaint' => $form['complaint'],
                'examination' => $form['examination'] !== '' ? $form['examination'] : null,
                'diagnosis' => $form['diagnosis'],
                'treatment' => $form['treatment'] !== '' ? $form['treatment'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'updated_by' => $_SESSION['user_id'] ?? null,
                'id' => $visitId,
            ]);

            // ====================================================
            // SELESAI UPDATE
            // Kembali ke profil pasien agar perubahan langsung
            // dapat diverifikasi dari pusat data pasien.
            // ====================================================
            header(
                'Location: /dashboard/patients/profile.php?id='
                . (int) $visit['patient_id']
                . '&visit=updated'
            );
            exit;
        } catch (PDOException $exception) {
            $errors[] = 'Kunjungan gagal diperbarui. Silakan coba lagi.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Kunjungan — Klinik Tubagus</title>
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
        .errors { margin-bottom: 20px; padding: 14px 16px; border-radius: 10px; background: #fff1f0; color: #b42318; }
        .errors ul { margin: 0; padding-left: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .field { display: flex; flex-direction: column; gap: 7px; }
        .field.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 14px; }
        .required { color: #b42318; }
        input, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        input[readonly], input:disabled { background: #f8fafc; color: #667085; }
        textarea { min-height: 130px; resize: vertical; }
        input:focus, textarea:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .section-title { grid-column: 1 / -1; margin: 5px 0 0; padding-bottom: 8px; border-bottom: 1px solid #edf0f2; color: #146c43; font-size: 16px; }
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
             HEADER EDIT KUNJUNGAN
             Menampilkan konteks pasien dan navigasi kembali.
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>✏️ Edit Kunjungan</h1>
                <p class="subtitle">Perbarui informasi klinis tanpa mengubah identitas record.</p>
            </div>
            <a class="back" href="/dashboard/patients/profile.php?id=<?= (int) $visit['patient_id'] ?>">← Profil Pasien</a>
        </div>

        <div class="patient-box">
            <strong><?= htmlspecialchars((string) $visit['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <div class="patient-meta">
                RM: <?= htmlspecialchars((string) $visit['medical_record_number'], ENT_QUOTES, 'UTF-8') ?>
                · Kunjungan: <?= htmlspecialchars((string) $visit['visit_number'], ENT_QUOTES, 'UTF-8') ?>
                · Status: <?= htmlspecialchars(ucfirst((string) $visit['status']), ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <?php if ($errors): ?>
            <!-- =================================================
                 PESAN VALIDASI
                 Menampilkan kesalahan input atau database.
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

            <!-- =================================================
                 INFORMASI KUNJUNGAN
                 ================================================= -->
            <div class="form-grid">
                <h2 class="section-title">Informasi Kunjungan</h2>

                <div class="field">
                    <label for="visit_number">Nomor Kunjungan</label>
                    <input id="visit_number" value="<?= htmlspecialchars((string) $visit['visit_number'], ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <span class="hint">Nomor kunjungan tidak dapat diubah.</span>
                </div>

                <div class="field">
                    <label for="visit_status">Status</label>
                    <input id="visit_status" value="<?= htmlspecialchars(ucfirst((string) $visit['status']), ENT_QUOTES, 'UTF-8') ?>" disabled>
                    <span class="hint">Status workflow tidak diubah melalui form ini.</span>
                </div>

                <div class="field">
                    <label for="visit_date">Tanggal Kunjungan <span class="required">*</span></label>
                    <input id="visit_date" name="visit_date" type="date" value="<?= htmlspecialchars($form['visit_date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field full">
                    <label for="complaint">Keluhan Utama <span class="required">*</span></label>
                    <textarea id="complaint" name="complaint" required><?= htmlspecialchars($form['complaint'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="field full">
                    <label for="examination">Pemeriksaan</label>
                    <textarea id="examination" name="examination"><?= htmlspecialchars($form['examination'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="field full">
                    <label for="diagnosis">Diagnosis <span class="required">*</span></label>
                    <textarea id="diagnosis" name="diagnosis" required><?= htmlspecialchars($form['diagnosis'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="field full">
                    <label for="treatment">Tindakan / Terapi</label>
                    <textarea id="treatment" name="treatment"><?= htmlspecialchars($form['treatment'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="field full">
                    <label for="notes">Catatan Klinis</label>
                    <textarea id="notes" name="notes"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <!-- =================================================
                 AKSI FORMULIR
                 Membatalkan atau menyimpan perubahan kunjungan.
                 ================================================= -->
            <div class="actions">
                <a class="button secondary" href="/dashboard/patients/profile.php?id=<?= (int) $visit['patient_id'] ?>">Batal</a>
                <button class="button primary" type="submit">💾 Simpan Perubahan</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
