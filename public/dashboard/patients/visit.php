<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES HALAMAN KUNJUNGAN
// Hanya pengguna dengan permission visits.create yang dapat
// membuat catatan kunjungan klinis baru.
// ============================================================
require_permission('visits.create');
Session::start();

// ============================================================
// TOKEN CSRF
// Melindungi proses penyimpanan kunjungan dari request lintas
// situs yang tidak sah.
// ============================================================
if (!Session::has('csrf_patient_visit')) {
    Session::set('csrf_patient_visit', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_patient_visit');

$errors = [];
$patient = null;

// ============================================================
// VALIDASI ID PASIEN
// Kunjungan wajib terhubung ke pasien yang sudah terdaftar.
// ============================================================
$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL IDENTITAS PASIEN
// Nomor RM dan nama digunakan sebagai konteks formulir klinis.
// ============================================================
$select = $pdo->prepare(
    'SELECT id, medical_record_number, full_name, status
     FROM patients
     WHERE id = :id
     LIMIT 1'
);
$select->execute(['id' => $patientId]);
$patient = $select->fetch();

if (!$patient) {
    http_response_code(404);
    exit('Pasien tidak ditemukan.');
}

if ((string) $patient['status'] !== 'active') {
    http_response_code(409);
    exit('Pasien tidak aktif. Aktifkan pasien terlebih dahulu sebelum membuat kunjungan.');
}

// ============================================================
// NILAI AWAL FORMULIR
// Tanggal kunjungan mengikuti tanggal server aplikasi.
// ============================================================
$form = [
    'visit_date' => date('Y-m-d'),
    'complaint' => '',
    'examination' => '',
    'diagnosis' => '',
    'treatment' => '',
    'notes' => '',
];

// ============================================================
// PROSES PENYIMPANAN KUNJUNGAN
// Input dibersihkan, divalidasi, lalu disimpan menggunakan
// prepared statement untuk menjaga keamanan query database.
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

    if ($form['complaint'] === '') {
        $errors[] = 'Keluhan utama wajib diisi.';
    }

    if ($form['diagnosis'] === '') {
        $errors[] = 'Diagnosis wajib diisi.';
    }

    // ========================================================
    // BUAT NOMOR KUNJUNGAN
    // Nomor menggunakan timestamp mikro agar unik tanpa
    // membutuhkan tabel sequence tambahan.
    // ========================================================
    if (!$errors) {
        $visitNumber = 'KJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        try {
            $insert = $pdo->prepare(
                'INSERT INTO patient_visits
                    (patient_id, visit_number, visit_date, complaint, examination,
                     diagnosis, treatment, notes, status, created_by)
                 VALUES
                    (:patient_id, :visit_number, :visit_date, :complaint, :examination,
                     :diagnosis, :treatment, :notes, \'completed\', :created_by)'
            );

            $insert->execute([
                'patient_id' => $patientId,
                'visit_number' => $visitNumber,
                'visit_date' => $form['visit_date'],
                'complaint' => $form['complaint'],
                'examination' => $form['examination'] !== '' ? $form['examination'] : null,
                'diagnosis' => $form['diagnosis'],
                'treatment' => $form['treatment'] !== '' ? $form['treatment'] : null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);

            // ====================================================
            // SELESAI SIMPAN
            // Kembali ke profile pasien agar kunjungan terbaru
            // langsung dapat dilihat dari pusat data pasien.
            // ====================================================
            header('Location: /dashboard/patients/profile.php?id=' . $patientId . '&visit=created');
            exit;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'Nomor kunjungan bentrok. Silakan coba simpan kembali.';
            } else {
                $errors[] = 'Kunjungan gagal disimpan. Silakan coba lagi.';
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
    <title>Kunjungan Baru — Klinik Tubagus</title>
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
             HEADER KUNJUNGAN
             Menampilkan konteks pasien dan navigasi kembali.
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>🩺 Kunjungan Baru</h1>
                <p class="subtitle">Catat pemeriksaan klinis pasien.</p>
            </div>
            <a class="back" href="/dashboard/patients/profile.php?id=<?= (int) $patientId ?>">← Profil Pasien</a>
        </div>

        <div class="patient-box">
            <strong><?= htmlspecialchars((string) $patient['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <div class="patient-meta">
                RM: <?= htmlspecialchars((string) $patient['medical_record_number'], ENT_QUOTES, 'UTF-8') ?>
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
                    <label for="visit_date">Tanggal Kunjungan <span class="required">*</span></label>
                    <input id="visit_date" name="visit_date" type="date" value="<?= htmlspecialchars($form['visit_date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field">
                    <label>Status</label>
                    <input value="Selesai" disabled>
                    <span class="hint">Kunjungan pertama disimpan sebagai kunjungan selesai.</span>
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
                 Membatalkan atau menyimpan kunjungan baru.
                 ================================================= -->
            <div class="actions">
                <a class="button secondary" href="/dashboard/patients/profile.php?id=<?= (int) $patientId ?>">Batal</a>
                <button class="button primary" type="submit">💾 Simpan Kunjungan</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
