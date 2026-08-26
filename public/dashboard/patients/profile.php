<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

require_permission('patients.view');

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$patientId || $patientId < 1) {
    header('Location: /dashboard/patients/index.php');
    exit;
}

$pdo = Database::connection();

$statement = $pdo->prepare(
    'SELECT id, medical_record_number, nik, full_name, gender, birth_place, birth_date,
            address, phone, email, blood_type, marital_status, occupation,
            emergency_contact_name, emergency_contact_phone, status, created_at, updated_at
     FROM patients
     WHERE id = :id
     LIMIT 1'
);
$statement->execute(['id' => $patientId]);
$patient = $statement->fetch();

if (!$patient) {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Pasien Tidak Ditemukan — Klinik Tubagus</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
            main { width: min(760px, calc(100% - 32px)); margin: 70px auto; }
            .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 30px; box-shadow: 0 12px 35px rgba(0,0,0,.06); text-align: center; }
            .button { display: inline-block; margin-top: 18px; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; text-decoration: none; font-weight: 700; }
        </style>
    </head>
    <body>
    <?php require __DIR__ . '/../_partials/navbar.php'; ?>
    <main>
        <section class="panel">
            <h1>😕 Pasien Tidak Ditemukan</h1>
            <p>Data pasien yang diminta tidak tersedia atau sudah tidak dapat diakses.</p>
            <a class="button" href="/dashboard/patients/index.php">← Kembali ke Patients</a>
        </section>
    </main>
    </body>
    </html>
    <?php
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function displayValue(?string $value, string $fallback = '—'): string
{
    return ($value !== null && trim($value) !== '') ? e($value) : '<span class="muted">' . e($fallback) . '</span>';
}

function formatDate(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('d-m-Y', $timestamp) : e($date);
}

function calculateAge(string $birthDate): ?int
{
    try {
        $birth = new DateTimeImmutable($birthDate);
        $today = new DateTimeImmutable('today');
        return $birth->diff($today)->y;
    } catch (Throwable $exception) {
        return null;
    }
}

$age = calculateAge((string) $patient['birth_date']);
$genderLabel = $patient['gender'] === 'male' ? 'Laki-laki' : 'Perempuan';
$maritalStatusLabels = [
    'single' => 'Belum Menikah',
    'married' => 'Menikah',
    'divorced' => 'Cerai',
    'widowed' => 'Duda / Janda',
];
$maritalStatus = $maritalStatusLabels[$patient['marital_status']] ?? null;
$bloodType = $patient['blood_type'] === 'UNKNOWN' ? 'Belum diketahui' : $patient['blood_type'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($patient['full_name']) ?> — Patient Profile</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1180px, calc(100% - 32px)); margin: 32px auto 48px; }
        .back { display: inline-block; margin-bottom: 16px; color: #475467; text-decoration: none; font-weight: 700; }
        .hero { background: #fff; border: 1px solid #e3e7eb; border-radius: 20px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .identity { display: flex; align-items: center; gap: 18px; min-width: 0; }
        .avatar { width: 68px; height: 68px; border-radius: 18px; display: grid; place-items: center; background: #ecfdf3; color: #146c43; font-size: 30px; flex: 0 0 auto; }
        .hero h1 { margin: 0 0 7px; font-size: clamp(24px, 4vw, 34px); }
        .hero-meta { margin: 0; color: #667085; }
        .hero-actions { display: flex; gap: 9px; flex-wrap: wrap; justify-content: flex-end; }
        .button { display: inline-block; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; text-decoration: none; font-weight: 700; }
        .button.secondary { background: #fff; color: #344054; border: 1px solid #d0d5dd; }
        .status { display: inline-block; margin-left: 7px; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; vertical-align: middle; }
        .status.active { background: #ecfdf3; color: #067647; }
        .status.inactive { background: #f2f4f7; color: #475467; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 18px; }
        .card { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 22px; box-shadow: 0 10px 28px rgba(0,0,0,.045); }
        .card.full { grid-column: 1 / -1; }
        .card h2 { margin: 0 0 18px; font-size: 18px; }
        .details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; }
        .item { min-width: 0; }
        .label { display: block; margin-bottom: 6px; color: #667085; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .value { color: #17202a; line-height: 1.5; word-break: break-word; }
        .rm { color: #146c43; font-weight: 800; }
        .highlight { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
        .metric { border: 1px solid #eaecf0; border-radius: 12px; padding: 13px; background: #f8fafc; }
        .metric strong { display: block; font-size: 20px; margin-top: 4px; }
        .muted { color: #98a2b3; }
        .note { margin-top: 18px; padding: 13px 15px; border-radius: 12px; background: #f8fafc; color: #667085; font-size: 13px; line-height: 1.55; }
        @media (max-width: 800px) {
            .hero { align-items: flex-start; flex-direction: column; }
            .hero-actions { justify-content: flex-start; }
        }
        @media (max-width: 620px) {
            main { width: min(100% - 20px, 1180px); margin-top: 20px; }
            .hero, .card { padding: 18px; border-radius: 15px; }
            .grid, .details { grid-template-columns: 1fr; }
            .card.full { grid-column: auto; }
            .highlight { grid-template-columns: 1fr; }
            .identity { align-items: flex-start; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <a class="back" href="/dashboard/patients/index.php">← Kembali ke Patients</a>

    <section class="hero">
        <div class="identity">
            <div class="avatar" aria-hidden="true">👤</div>
            <div>
                <h1><?= e($patient['full_name']) ?></h1>
                <p class="hero-meta">
                    No. RM <strong><?= e($patient['medical_record_number']) ?></strong>
                    <span class="status <?= e($patient['status']) ?>"><?= strtoupper(e($patient['status'])) ?></span>
                </p>
            </div>
        </div>

        <div class="hero-actions">
            <?php if (Auth::can('patients.update')): ?>
                <a class="button" href="/dashboard/patients/edit.php?id=<?= (int) $patient['id'] ?>">✏️ Edit Pasien</a>
            <?php endif; ?>
            <a class="button secondary" href="/dashboard/patients/index.php">Patients</a>
        </div>
    </section>

    <div class="grid">
        <section class="card full">
            <h2>🪪 Ringkasan Pasien</h2>
            <div class="highlight">
                <div class="metric">
                    <span class="label">Nomor Rekam Medis</span>
                    <strong class="rm"><?= e($patient['medical_record_number']) ?></strong>
                </div>
                <div class="metric">
                    <span class="label">NIK</span>
                    <strong><?= e($patient['nik']) ?></strong>
                </div>
                <div class="metric">
                    <span class="label">Usia</span>
                    <strong><?= $age !== null ? $age . ' tahun' : '—' ?></strong>
                </div>
            </div>

            <div class="details">
                <div class="item"><span class="label">Nama Lengkap</span><div class="value"><?= e($patient['full_name']) ?></div></div>
                <div class="item"><span class="label">Jenis Kelamin</span><div class="value"><?= e($genderLabel) ?></div></div>
                <div class="item"><span class="label">Tempat, Tanggal Lahir</span><div class="value"><?= displayValue($patient['birth_place']) ?><?= $patient['birth_place'] ? ', ' : '' ?><?= e(formatDate($patient['birth_date'])) ?></div></div>
                <div class="item"><span class="label">Golongan Darah</span><div class="value"><?= e($bloodType) ?></div></div>
                <div class="item"><span class="label">Status Pernikahan</span><div class="value"><?= displayValue($maritalStatus) ?></div></div>
                <div class="item"><span class="label">Pekerjaan</span><div class="value"><?= displayValue($patient['occupation']) ?></div></div>
            </div>
        </section>

        <section class="card">
            <h2>📞 Kontak</h2>
            <div class="details">
                <div class="item"><span class="label">Nomor Telepon</span><div class="value"><?= displayValue($patient['phone']) ?></div></div>
                <div class="item"><span class="label">Email</span><div class="value"><?= displayValue($patient['email']) ?></div></div>
                <div class="item" style="grid-column: 1 / -1;"><span class="label">Alamat</span><div class="value"><?= displayValue($patient['address']) ?></div></div>
            </div>
        </section>

        <section class="card">
            <h2>🚨 Kontak Darurat</h2>
            <div class="details">
                <div class="item"><span class="label">Nama</span><div class="value"><?= displayValue($patient['emergency_contact_name']) ?></div></div>
                <div class="item"><span class="label">Telepon</span><div class="value"><?= displayValue($patient['emergency_contact_phone']) ?></div></div>
            </div>
        </section>

        <section class="card full">
            <h2>🗂️ Informasi Administratif</h2>
            <div class="details">
                <div class="item"><span class="label">Status Pasien</span><div class="value"><span class="status <?= e($patient['status']) ?>"><?= strtoupper(e($patient['status'])) ?></span></div></div>
                <div class="item"><span class="label">Terdaftar Sejak</span><div class="value"><?= e(formatDate($patient['created_at'])) ?></div></div>
                <div class="item"><span class="label">Terakhir Diperbarui</span><div class="value"><?= e(formatDate($patient['updated_at'])) ?></div></div>
            </div>
            <div class="note">ℹ️ Patient Profile menjadi pusat identitas pasien. Riwayat kunjungan, rekam medis harian, resep, billing, dan modul klinis berikutnya dapat ditautkan ke profil ini tanpa mengubah data identitas utama.</div>
        </section>
    </div>
</main>
</body>
</html>
