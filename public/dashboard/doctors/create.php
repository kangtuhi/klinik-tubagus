<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// GUARD AKSES CREATE DOKTER
// Hanya pengguna dengan permission doctors.create yang dapat
// membuka dan mengirim formulir dokter baru.
// ============================================================
require_permission('doctors.create');

$pdo = Database::connection();
$errors = [];

// ============================================================
// NILAI AWAL FORMULIR
// Menyimpan nilai input agar form tetap nyaman digunakan ketika
// validasi gagal.
// ============================================================
$form = [
    'full_name' => '',
    'sip_number' => '',
    'str_number' => '',
    'specialty' => '',
    'phone' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================================
    // VALIDASI CSRF
    // Melindungi proses pembuatan dokter dari request lintas situs.
    // ========================================================
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $form['sip_number'] = trim((string) ($_POST['sip_number'] ?? ''));
    $form['str_number'] = trim((string) ($_POST['str_number'] ?? ''));
    $form['specialty'] = trim((string) ($_POST['specialty'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));

    // ========================================================
    // VALIDASI INPUT SERVER-SIDE
    // Memastikan data wajib dan format email valid sebelum masuk DB.
    // ========================================================
    if ($form['full_name'] === '') {
        $errors[] = 'Nama dokter wajib diisi.';
    } elseif (mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Nama dokter maksimal 150 karakter.';
    }

    if ($form['sip_number'] !== '' && mb_strlen($form['sip_number']) > 100) {
        $errors[] = 'SIP maksimal 100 karakter.';
    }

    if ($form['str_number'] !== '' && mb_strlen($form['str_number']) > 100) {
        $errors[] = 'STR maksimal 100 karakter.';
    }

    if ($form['specialty'] !== '' && mb_strlen($form['specialty']) > 100) {
        $errors[] = 'Spesialisasi maksimal 100 karakter.';
    }

    if ($form['phone'] !== '' && mb_strlen($form['phone']) > 30) {
        $errors[] = 'Nomor telepon maksimal 30 karakter.';
    }

    if ($form['email'] !== '' && (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 191)) {
        $errors[] = 'Format email dokter tidak valid.';
    }

    // ========================================================
    // CEK DUPLIKAT SIP / STR
    // Memberikan pesan yang lebih jelas daripada error constraint
    // database ketika nomor profesional sudah digunakan.
    // ========================================================
    if (!$errors && ($form['sip_number'] !== '' || $form['str_number'] !== '')) {
        $duplicate = $pdo->prepare(
            'SELECT sip_number, str_number
             FROM doctors
             WHERE (:sip_number <> \'\' AND sip_number = :sip_check)
                OR (:str_number <> \'\' AND str_number = :str_check)
             LIMIT 1'
        );
        $duplicate->execute([
            'sip_number' => $form['sip_number'],
            'sip_check' => $form['sip_number'],
            'str_number' => $form['str_number'],
            'str_check' => $form['str_number'],
        ]);
        $existing = $duplicate->fetch();

        if ($existing) {
            if ($form['sip_number'] !== '' && $existing['sip_number'] === $form['sip_number']) {
                $errors[] = 'Nomor SIP sudah terdaftar.';
            }
            if ($form['str_number'] !== '' && $existing['str_number'] === $form['str_number']) {
                $errors[] = 'Nomor STR sudah terdaftar.';
            }
        }
    }

    if (!$errors) {
        // ====================================================
        // SIMPAN DOKTER BARU
        // Status awal selalu active agar dokter siap digunakan.
        // ====================================================
        $statement = $pdo->prepare(
            'INSERT INTO doctors
                (full_name, sip_number, str_number, specialty, phone, email, status)
             VALUES
                (:full_name, NULLIF(:sip_number, \'\'), NULLIF(:str_number, \'\'),
                 NULLIF(:specialty, \'\'), NULLIF(:phone, \'\'), NULLIF(:email, \'\'), \'active\')'
        );
        $statement->execute($form);

        header('Location: /dashboard/doctors/?created=1');
        exit;
    }
}

$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Dokter — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(900px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        h1 { margin: 0 0 6px; }
        .subtitle { color: #667085; margin: 0 0 24px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .field { display: flex; flex-direction: column; gap: 7px; }
        .field.full { grid-column: 1 / -1; }
        label { font-weight: 700; font-size: 14px; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; }
        input:focus { outline: 2px solid rgba(20,108,67,.15); border-color: #146c43; }
        .hint { color: #667085; font-size: 12px; }
        .errors { margin-bottom: 20px; padding: 13px 16px; border-radius: 12px; background: #fff1f0; color: #b42318; }
        .actions { display: flex; gap: 10px; margin-top: 24px; }
        .button { display: inline-block; border: 0; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; text-decoration: none; font: inherit; font-weight: 700; cursor: pointer; }
        .button.secondary { background: #f2f4f7; color: #344054; }
        @media (max-width: 700px) { main { margin-top: 22px; } .panel { padding: 18px; } .grid { grid-template-columns: 1fr; } .field.full { grid-column: auto; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <h1>🩺 Tambah Dokter</h1>
        <p class="subtitle">Tambahkan data profesional dokter ke Klinik Tubagus.</p>

        <?php if ($errors): ?>
            <div class="errors" role="alert">
                <strong>Periksa kembali data berikut:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="grid">
                <div class="field full">
                    <label for="full_name">Nama Dokter *</label>
                    <input id="full_name" name="full_name" type="text" maxlength="150" required value="<?= htmlspecialchars($form['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="field">
                    <label for="sip_number">Nomor SIP</label>
                    <input id="sip_number" name="sip_number" type="text" maxlength="100" value="<?= htmlspecialchars($form['sip_number'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Nomor SIP harus unik.</span>
                </div>

                <div class="field">
                    <label for="str_number">Nomor STR</label>
                    <input id="str_number" name="str_number" type="text" maxlength="100" value="<?= htmlspecialchars($form['str_number'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Nomor STR harus unik.</span>
                </div>

                <div class="field">
                    <label for="specialty">Spesialisasi</label>
                    <input id="specialty" name="specialty" type="text" maxlength="100" value="<?= htmlspecialchars($form['specialty'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="field">
                    <label for="phone">Telepon</label>
                    <input id="phone" name="phone" type="text" maxlength="30" value="<?= htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="field full">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" maxlength="191" value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="actions">
                <a class="button secondary" href="/dashboard/doctors/">← Batal</a>
                <button class="button" type="submit">💾 Simpan Dokter</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
