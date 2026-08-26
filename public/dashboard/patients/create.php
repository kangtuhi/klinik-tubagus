<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES HALAMAN REGISTRASI PASIEN
// Hanya pengguna dengan permission patients.create yang dapat
// membuka dan menyimpan data pasien baru.
// ============================================================
require_permission('patients.create');
Session::start();

// ============================================================
// TOKEN CSRF
// Membuat token sesi untuk melindungi proses registrasi pasien
// dari pengiriman form lintas situs yang tidak sah.
// ============================================================
if (!Session::has('csrf_patient_create')) {
    Session::set('csrf_patient_create', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_patient_create');

$errors = [];
$old = [
    'nik' => '',
    'full_name' => '',
    'gender' => '',
    'birth_place' => '',
    'birth_date' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'blood_type' => 'UNKNOWN',
    'marital_status' => '',
    'occupation' => '',
    'emergency_contact_name' => '',
    'emergency_contact_phone' => '',
];

// ============================================================
// PROSES SUBMIT FORM PASIEN
// Memvalidasi input, menyimpan data, lalu menghasilkan nomor RM
// berdasarkan ID pasien sehingga nomor tetap unik saat dipakai
// oleh beberapa pengguna secara bersamaan.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman dan coba lagi.';
    }

    foreach ($old as $field => $default) {
        $old[$field] = trim((string) ($_POST[$field] ?? $default));
    }

    // ========================================================
    // VALIDASI INPUT WAJIB
    // Memastikan identitas utama pasien tersedia sebelum query
    // database dijalankan.
    // ========================================================
    if ($old['nik'] === '') {
        $errors[] = 'NIK wajib diisi.';
    } elseif (!preg_match('/^\d{10,20}$/', $old['nik'])) {
        $errors[] = 'NIK harus berupa 10 sampai 20 digit angka.';
    }

    if ($old['full_name'] === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    } elseif (mb_strlen($old['full_name']) > 150) {
        $errors[] = 'Nama lengkap maksimal 150 karakter.';
    }

    if (!in_array($old['gender'], ['male', 'female'], true)) {
        $errors[] = 'Jenis kelamin wajib dipilih.';
    }

    if ($old['birth_date'] === '') {
        $errors[] = 'Tanggal lahir wajib diisi.';
    } elseif ($old['birth_date'] > date('Y-m-d')) {
        $errors[] = 'Tanggal lahir tidak boleh berada di masa depan.';
    }

    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }

    if (!in_array($old['blood_type'], ['A', 'B', 'AB', 'O', 'UNKNOWN'], true)) {
        $errors[] = 'Golongan darah tidak valid.';
    }

    if ($old['marital_status'] !== '' && !in_array($old['marital_status'], ['single', 'married', 'divorced', 'widowed'], true)) {
        $errors[] = 'Status perkawinan tidak valid.';
    }

    // ========================================================
    // CEK DUPLIKASI NIK
    // NIK tidak boleh digunakan oleh dua pasien berbeda.
    // ========================================================
    if (!$errors) {
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM patients WHERE nik = :nik LIMIT 1');
        $check->execute(['nik' => $old['nik']]);

        if ($check->fetchColumn() !== false) {
            $errors[] = 'NIK tersebut sudah terdaftar sebagai pasien.';
        }
    }

    // ========================================================
    // SIMPAN PASIEN
    // Menggunakan RM sementara yang unik, kemudian ID hasil INSERT
    // dipakai untuk membentuk nomor rekam medis final.
    // ========================================================
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $temporaryRm = 'TMP-' . bin2hex(random_bytes(12));
            $insert = $pdo->prepare(
                'INSERT INTO patients
                (medical_record_number, nik, full_name, gender, birth_place, birth_date, address, phone, email, blood_type, marital_status, occupation, emergency_contact_name, emergency_contact_phone, status)
                VALUES
                (:medical_record_number, :nik, :full_name, :gender, :birth_place, :birth_date, :address, :phone, :email, :blood_type, :marital_status, :occupation, :emergency_contact_name, :emergency_contact_phone, :status)'
            );

            $insert->execute([
                'medical_record_number' => $temporaryRm,
                'nik' => $old['nik'],
                'full_name' => $old['full_name'],
                'gender' => $old['gender'],
                'birth_place' => $old['birth_place'] !== '' ? $old['birth_place'] : null,
                'birth_date' => $old['birth_date'],
                'address' => $old['address'] !== '' ? $old['address'] : null,
                'phone' => $old['phone'] !== '' ? $old['phone'] : null,
                'email' => $old['email'] !== '' ? $old['email'] : null,
                'blood_type' => $old['blood_type'],
                'marital_status' => $old['marital_status'] !== '' ? $old['marital_status'] : null,
                'occupation' => $old['occupation'] !== '' ? $old['occupation'] : null,
                'emergency_contact_name' => $old['emergency_contact_name'] !== '' ? $old['emergency_contact_name'] : null,
                'emergency_contact_phone' => $old['emergency_contact_phone'] !== '' ? $old['emergency_contact_phone'] : null,
                'status' => 'active',
            ]);

            $patientId = (int) $pdo->lastInsertId();
            $medicalRecordNumber = 'RM-' . date('Y') . '-' . str_pad((string) $patientId, 6, '0', STR_PAD_LEFT);

            $update = $pdo->prepare(
                'UPDATE patients SET medical_record_number = :medical_record_number WHERE id = :id'
            );
            $update->execute([
                'medical_record_number' => $medicalRecordNumber,
                'id' => $patientId,
            ]);

            $pdo->commit();

            // ====================================================
            // SELESAI REGISTRASI
            // Mengarahkan kembali ke daftar pasien setelah data
            // berhasil disimpan.
            // ====================================================
            header('Location: /dashboard/patients/?created=1');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Data pasien gagal disimpan. Silakan periksa kembali data dan coba lagi.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Pasien — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1100px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .back { color: #475467; text-decoration: none; font-weight: 700; }
        .errors { margin-bottom: 20px; padding: 14px 16px; border-radius: 10px; background: #fff1f0; color: #b42318; }
        .errors ul { margin: 0; padding-left: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .field { display: flex; flex-direction: column; gap: 7px; }
        .field.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 14px; }
        .required { color: #b42318; }
        input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        textarea { min-height: 110px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .section-title { grid-column: 1 / -1; margin-top: 6px; padding-bottom: 8px; border-bottom: 1px solid #edf0f2; color: #146c43; font-size: 16px; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
        .button { border: 0; cursor: pointer; padding: 11px 16px; border-radius: 10px; font: inherit; font-weight: 700; text-decoration: none; }
        .button.secondary { background: #f2f4f7; color: #344054; }
        .button.primary { background: #146c43; color: #fff; }
        .hint { color: #667085; font-size: 12px; }
        @media (max-width: 700px) {
            main { margin-top: 22px; }
            .form-grid { grid-template-columns: 1fr; }
            .field.full, .section-title { grid-column: auto; }
            .titlebar { flex-direction: column; }
            .actions { flex-direction: column-reverse; }
            .button { text-align: center; width: 100%; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER FORM REGISTRASI
             Menampilkan judul dan navigasi kembali ke daftar pasien.
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>➕ Tambah Pasien</h1>
                <p class="subtitle">Registrasikan identitas pasien baru Klinik Tubagus.</p>
            </div>
            <a class="back" href="/dashboard/patients/">← Kembali ke Pasien</a>
        </div>

        <?php if ($errors): ?>
            <!-- =================================================
                 PESAN VALIDASI
                 Menampilkan seluruh kesalahan yang ditemukan pada
                 input pengguna atau proses penyimpanan.
                 ================================================= -->
            <div class="errors" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <!-- =================================================
                 IDENTITAS PASIEN
                 Data utama yang menjadi identitas resmi pasien.
                 ================================================= -->
            <div class="form-grid">
                <h2 class="section-title">Identitas Pasien</h2>

                <div class="field">
                    <label for="nik">NIK <span class="required">*</span></label>
                    <input id="nik" name="nik" value="<?= htmlspecialchars($old['nik'], ENT_QUOTES, 'UTF-8') ?>" maxlength="20" inputmode="numeric" autocomplete="off" required>
                    <span class="hint">10–20 digit angka.</span>
                </div>

                <div class="field">
                    <label for="full_name">Nama Lengkap <span class="required">*</span></label>
                    <input id="full_name" name="full_name" value="<?= htmlspecialchars($old['full_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" autocomplete="name" required>
                </div>

                <div class="field">
                    <label for="gender">Jenis Kelamin <span class="required">*</span></label>
                    <select id="gender" name="gender" required>
                        <option value="">Pilih jenis kelamin</option>
                        <option value="male" <?= $old['gender'] === 'male' ? 'selected' : '' ?>>Laki-laki</option>
                        <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>Perempuan</option>
                    </select>
                </div>

                <div class="field">
                    <label for="birth_place">Tempat Lahir</label>
                    <input id="birth_place" name="birth_place" value="<?= htmlspecialchars($old['birth_place'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                </div>

                <div class="field">
                    <label for="birth_date">Tanggal Lahir <span class="required">*</span></label>
                    <input id="birth_date" type="date" name="birth_date" value="<?= htmlspecialchars($old['birth_date'], ENT_QUOTES, 'UTF-8') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="field">
                    <label for="blood_type">Golongan Darah</label>
                    <select id="blood_type" name="blood_type">
                        <?php foreach (['UNKNOWN', 'A', 'B', 'AB', 'O'] as $bloodType): ?>
                            <option value="<?= $bloodType ?>" <?= $old['blood_type'] === $bloodType ? 'selected' : '' ?>><?= $bloodType === 'UNKNOWN' ? 'Belum diketahui' : $bloodType ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="marital_status">Status Perkawinan</label>
                    <select id="marital_status" name="marital_status">
                        <option value="">Belum diisi</option>
                        <option value="single" <?= $old['marital_status'] === 'single' ? 'selected' : '' ?>>Belum menikah</option>
                        <option value="married" <?= $old['marital_status'] === 'married' ? 'selected' : '' ?>>Menikah</option>
                        <option value="divorced" <?= $old['marital_status'] === 'divorced' ? 'selected' : '' ?>>Cerai</option>
                        <option value="widowed" <?= $old['marital_status'] === 'widowed' ? 'selected' : '' ?>>Duda/Janda</option>
                    </select>
                </div>

                <div class="field">
                    <label for="occupation">Pekerjaan</label>
                    <input id="occupation" name="occupation" value="<?= htmlspecialchars($old['occupation'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                </div>

                <div class="field full">
                    <label for="address">Alamat</label>
                    <textarea id="address" name="address"><?= htmlspecialchars($old['address'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <!-- =================================================
                     KONTAK PASIEN
                     Informasi komunikasi pasien.
                     ================================================= -->
                <h2 class="section-title">Kontak Pasien</h2>

                <div class="field">
                    <label for="phone">Nomor Telepon</label>
                    <input id="phone" name="phone" value="<?= htmlspecialchars($old['phone'], ENT_QUOTES, 'UTF-8') ?>" maxlength="30" inputmode="tel" autocomplete="tel">
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>" maxlength="191" autocomplete="email">
                </div>

                <!-- =================================================
                     KONTAK DARURAT
                     Digunakan untuk menghubungi keluarga/orang terdekat
                     ketika pasien membutuhkan kontak darurat.
                     ================================================= -->
                <h2 class="section-title">Kontak Darurat</h2>

                <div class="field">
                    <label for="emergency_contact_name">Nama Kontak Darurat</label>
                    <input id="emergency_contact_name" name="emergency_contact_name" value="<?= htmlspecialchars($old['emergency_contact_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150">
                </div>

                <div class="field">
                    <label for="emergency_contact_phone">Telepon Kontak Darurat</label>
                    <input id="emergency_contact_phone" name="emergency_contact_phone" value="<?= htmlspecialchars($old['emergency_contact_phone'], ENT_QUOTES, 'UTF-8') ?>" maxlength="30" inputmode="tel">
                </div>
            </div>

            <!-- =================================================
                 AKSI FORM
                 Tombol batal dan simpan pasien baru.
                 ================================================= -->
            <div class="actions">
                <a class="button secondary" href="/dashboard/patients/">Batal</a>
                <button class="button primary" type="submit">💾 Simpan Pasien</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
