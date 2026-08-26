<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES PROFIL PASIEN
// Profil pasien hanya dapat dilihat oleh pengguna yang memiliki
// permission patients.view.
// ============================================================
require_permission('patients.view');
Session::start();

// ============================================================
// VALIDASI ID PASIEN
// ID pasien diambil dari query string dan wajib berupa angka.
// ============================================================
$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL DATA PASIEN
// Identitas pasien menjadi pusat informasi sebelum data klinis.
// ============================================================
$select = $pdo->prepare('SELECT * FROM patients WHERE id = :id LIMIT 1');
$select->execute(['id' => $patientId]);
$patient = $select->fetch();

if (!$patient) {
    http_response_code(404);
    exit('Pasien tidak ditemukan.');
}

// ============================================================
// HITUNG UMUR PASIEN
// Umur dihitung otomatis dari tanggal lahir agar selalu aktual.
// ============================================================
$age = null;
if (!empty($patient['birth_date'])) {
    try {
        $birthDate = new DateTime((string) $patient['birth_date']);
        $today = new DateTime('today');
        $age = $birthDate->diff($today)->y;
    } catch (Exception $exception) {
        $age = null;
    }
}

// ============================================================
// AMBIL RINGKASAN KUNJUNGAN
// Timeline ringkas ditampilkan langsung di profil pasien sehingga
// profile menjadi pusat perjalanan klinis pasien.
// ============================================================
$visitCount = 0;
$recentVisits = [];

try {
    $countQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM patient_visits WHERE patient_id = :patient_id'
    );
    $countQuery->execute(['patient_id' => $patientId]);
    $visitCount = (int) $countQuery->fetchColumn();

    $recentQuery = $pdo->prepare(
        'SELECT id, visit_number, visit_date, complaint, diagnosis, treatment, status
         FROM patient_visits
         WHERE patient_id = :patient_id
         ORDER BY visit_date DESC, id DESC
         LIMIT 5'
    );
    $recentQuery->execute(['patient_id' => $patientId]);
    $recentVisits = $recentQuery->fetchAll();
} catch (PDOException $exception) {
    // ========================================================
    // KOMPATIBILITAS MIGRATION
    // Jika migration kunjungan belum dijalankan, profile pasien
    // tetap dapat dibuka tanpa merusak modul Patients.
    // ========================================================
    $visitCount = 0;
    $recentVisits = [];
}

// ============================================================
// LABEL DATA PASIEN
// Mengubah nilai database menjadi label yang mudah dibaca UI.
// ============================================================
$genderLabel = [
    'male' => 'Laki-laki',
    'female' => 'Perempuan',
][$patient['gender']] ?? (string) $patient['gender'];

$bloodLabel = [
    'A' => 'A',
    'B' => 'B',
    'AB' => 'AB',
    'O' => 'O',
    'UNKNOWN' => 'Tidak diketahui',
][$patient['blood_type']] ?? (string) $patient['blood_type'];

$maritalLabel = [
    'single' => 'Belum menikah',
    'married' => 'Menikah',
    'divorced' => 'Cerai',
    'widowed' => 'Duda / Janda',
][$patient['marital_status'] ?? ''] ?? ((string) ($patient['marital_status'] ?? '—'));

$statusLabel = (string) $patient['status'] === 'active' ? 'ACTIVE' : 'INACTIVE';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil Pasien — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1100px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .actions { display: flex; gap: 9px; flex-wrap: wrap; justify-content: flex-end; }
        .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 14px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 14px; }
        .button.primary { background: #146c43; color: #fff; }
        .button.secondary { background: #f2f4f7; color: #344054; }
        .hero { display: flex; justify-content: space-between; gap: 18px; align-items: center; padding: 20px; margin-bottom: 20px; border-radius: 15px; background: #f8fafc; border: 1px solid #e3e7eb; }
        .identity { display: flex; align-items: center; gap: 15px; }
        .avatar { width: 58px; height: 58px; display: grid; place-items: center; border-radius: 50%; background: #d1fadf; font-size: 28px; }
        .name { margin: 0 0 5px; font-size: 23px; }
        .rm { color: #146c43; font-weight: 800; letter-spacing: .4px; }
        .badge { display: inline-flex; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; }
        .badge.active { background: #ecfdf3; color: #146c43; }
        .badge.inactive { background: #fef3f2; color: #b42318; }
        .section { margin-top: 24px; }
        .section-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
        .section-title { margin: 0; font-size: 17px; color: #146c43; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .item { padding: 14px; border: 1px solid #e3e7eb; border-radius: 12px; background: #fff; }
        .label { display: block; margin-bottom: 5px; color: #667085; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .value { font-weight: 700; line-height: 1.45; word-break: break-word; }
        .clinical-summary { display: grid; gap: 12px; }
        .visit-card { border: 1px solid #e3e7eb; border-radius: 13px; padding: 15px; }
        .visit-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        .visit-date { color: #146c43; font-weight: 800; }
        .visit-number { margin-top: 3px; color: #667085; font-size: 12px; }
        .visit-content { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .visit-field { padding: 11px; border-radius: 10px; background: #f8fafc; }
        .empty { padding: 24px; border: 1px dashed #d0d5dd; border-radius: 13px; text-align: center; color: #667085; }
        .alert { margin-bottom: 18px; padding: 13px 15px; border-radius: 10px; background: #ecfdf3; color: #146c43; }
        @media (max-width: 800px) {
            main { margin-top: 22px; }
            .titlebar, .hero { flex-direction: column; align-items: stretch; }
            .actions { justify-content: flex-start; }
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .visit-content { grid-template-columns: 1fr; }
        }
        @media (max-width: 520px) {
            .panel { padding: 18px; }
            .grid { grid-template-columns: 1fr; }
            .button { width: 100%; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER PROFIL
             Menampilkan identitas utama dan aksi pasien.
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>👤 Profil Pasien</h1>
                <p class="subtitle">Pusat informasi identitas dan perjalanan klinis pasien.</p>
            </div>
            <div class="actions">
                <?php if (can('patients.update')): ?>
                    <a class="button secondary" href="/dashboard/patients/edit.php?id=<?= (int) $patientId ?>">✏️ Edit</a>
                <?php endif; ?>
                <?php if (can('visits.create') && (string) $patient['status'] === 'active'): ?>
                    <a class="button primary" href="/dashboard/patients/visit.php?id=<?= (int) $patientId ?>">🩺 Kunjungan Baru</a>
                <?php endif; ?>
                <?php if (can('visits.view')): ?>
                    <a class="button secondary" href="/dashboard/patients/visits.php?id=<?= (int) $patientId ?>">📋 Semua Kunjungan</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['visit']) && $_GET['visit'] === 'created'): ?>
            <!-- =================================================
                 NOTIFIKASI BERHASIL
                 Ditampilkan setelah kunjungan baru berhasil dibuat.
                 ================================================= -->
            <div class="alert" role="status">✅ Kunjungan pasien berhasil disimpan.</div>
        <?php endif; ?>

        <!-- =====================================================
             IDENTITAS UTAMA PASIEN
             ===================================================== -->
        <div class="hero">
            <div class="identity">
                <div class="avatar">👤</div>
                <div>
                    <h2 class="name"><?= htmlspecialchars((string) $patient['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="rm"><?= htmlspecialchars((string) $patient['medical_record_number'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
            <span class="badge <?= (string) $patient['status'] === 'active' ? 'active' : 'inactive' ?>">
                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <!-- =====================================================
             DATA IDENTITAS
             Menampilkan data demografis utama pasien.
             ===================================================== -->
        <section class="section">
            <div class="section-head"><h2 class="section-title">🪪 Identitas</h2></div>
            <div class="grid">
                <div class="item"><span class="label">NIK</span><div class="value"><?= htmlspecialchars((string) $patient['nik'], ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Jenis Kelamin</span><div class="value"><?= htmlspecialchars($genderLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Tempat, Tanggal Lahir</span><div class="value"><?= htmlspecialchars((string) ($patient['birth_place'] ?: '—'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars((string) $patient['birth_date'], ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Umur</span><div class="value"><?= $age !== null ? (int) $age . ' tahun' : '—' ?></div></div>
                <div class="item"><span class="label">Golongan Darah</span><div class="value"><?= htmlspecialchars($bloodLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Status Perkawinan</span><div class="value"><?= htmlspecialchars($maritalLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Pekerjaan</span><div class="value"><?= htmlspecialchars((string) ($patient['occupation'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>
        </section>

        <!-- =====================================================
             KONTAK PASIEN
             Menampilkan informasi komunikasi pasien.
             ===================================================== -->
        <section class="section">
            <div class="section-head"><h2 class="section-title">📞 Kontak</h2></div>
            <div class="grid">
                <div class="item"><span class="label">Telepon</span><div class="value"><?= htmlspecialchars((string) ($patient['phone'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Email</span><div class="value"><?= htmlspecialchars((string) ($patient['email'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Alamat</span><div class="value"><?= nl2br(htmlspecialchars((string) ($patient['address'] ?: '—'), ENT_QUOTES, 'UTF-8')) ?></div></div>
            </div>
        </section>

        <!-- =====================================================
             KONTAK DARURAT
             Menampilkan orang yang dapat dihubungi saat keadaan
             darurat pasien.
             ===================================================== -->
        <section class="section">
            <div class="section-head"><h2 class="section-title">🚨 Kontak Darurat</h2></div>
            <div class="grid">
                <div class="item"><span class="label">Nama</span><div class="value"><?= htmlspecialchars((string) ($patient['emergency_contact_name'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Telepon</span><div class="value"><?= htmlspecialchars((string) ($patient['emergency_contact_phone'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>
        </section>

        <!-- =====================================================
             RINGKASAN RIWAYAT KUNJUNGAN
             Menampilkan lima kunjungan terakhir sebagai timeline
             singkat dan menyediakan akses ke seluruh riwayat.
             ===================================================== -->
        <section class="section">
            <div class="section-head">
                <h2 class="section-title">🩺 Riwayat Kunjungan</h2>
                <?php if (can('visits.view')): ?>
                    <a class="button secondary" href="/dashboard/patients/visits.php?id=<?= (int) $patientId ?>">Lihat Semua</a>
                <?php endif; ?>
            </div>

            <?php if (!$recentVisits): ?>
                <div class="empty">Belum ada riwayat kunjungan klinis.</div>
            <?php else: ?>
                <div class="clinical-summary">
                    <?php foreach ($recentVisits as $visit): ?>
                        <article class="visit-card">
                            <div class="visit-head">
                                <div>
                                    <div class="visit-date"><?= htmlspecialchars(date('d-m-Y', strtotime((string) $visit['visit_date'])), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="visit-number"><?= htmlspecialchars((string) $visit['visit_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <span class="badge <?= htmlspecialchars((string) $visit['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst((string) $visit['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="visit-content">
                                <div class="visit-field"><span class="label">Keluhan</span><?= htmlspecialchars((string) ($visit['complaint'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="visit-field"><span class="label">Diagnosis</span><?= htmlspecialchars((string) ($visit['diagnosis'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="visit-field"><span class="label">Tindakan / Terapi</span><?= htmlspecialchars((string) ($visit['treatment'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- =====================================================
             INFORMASI ADMINISTRATIF
             Menampilkan metadata dasar record pasien.
             ===================================================== -->
        <section class="section">
            <div class="section-head"><h2 class="section-title">🗂️ Administrasi</h2></div>
            <div class="grid">
                <div class="item"><span class="label">Total Kunjungan</span><div class="value"><?= (int) $visitCount ?></div></div>
                <div class="item"><span class="label">Dibuat</span><div class="value"><?= htmlspecialchars((string) ($patient['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="item"><span class="label">Diperbarui</span><div class="value"><?= htmlspecialchars((string) ($patient['updated_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>
        </section>
    </section>
</main>
</body>
</html>
