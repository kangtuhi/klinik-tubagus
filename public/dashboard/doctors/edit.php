<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// GUARD AKSES EDIT DOKTER
// Hanya pengguna dengan permission doctors.update yang dapat
// membuka dan menyimpan perubahan data dokter.
// ============================================================
require_permission('doctors.update');

$pdo = Database::connection();
$fieldErrors = [];

// ============================================================
// VALIDASI ID DOKTER
// ID wajib berupa bilangan bulat positif agar query tetap aman.
// ============================================================
$doctorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$doctorId || $doctorId < 1) {
    http_response_code(404);
    exit('Dokter tidak ditemukan.');
}

// ============================================================
// AMBIL DATA DOKTER
// Data awal dipakai untuk mengisi form edit.
// ============================================================
$statement = $pdo->prepare(
    'SELECT id, full_name, sip_number, str_number, specialty, phone, email, status
     FROM doctors
     WHERE id = :id
     LIMIT 1'
);
$statement->execute(['id' => $doctorId]);
$doctor = $statement->fetch();

if (!$doctor) {
    http_response_code(404);
    exit('Dokter tidak ditemukan.');
}

$form = [
    'full_name' => (string) ($doctor['full_name'] ?? ''),
    'sip_number' => (string) ($doctor['sip_number'] ?? ''),
    'str_number' => (string) ($doctor['str_number'] ?? ''),
    'specialty' => (string) ($doctor['specialty'] ?? ''),
    'phone' => (string) ($doctor['phone'] ?? ''),
    'email' => (string) ($doctor['email'] ?? ''),
    'status' => (string) ($doctor['status'] ?? 'active'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================================
    // VALIDASI CSRF
    // Mengikuti helper CSRF standar yang digunakan seluruh proyek.
    // ========================================================
    verify_csrf();

    $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $form['sip_number'] = trim((string) ($_POST['sip_number'] ?? ''));
    $form['str_number'] = trim((string) ($_POST['str_number'] ?? ''));
    $form['specialty'] = trim((string) ($_POST['specialty'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $form['status'] = (string) ($_POST['status'] ?? 'active');

    // ========================================================
    // VALIDASI PER FIELD
    // Setiap field divalidasi independen agar seluruh kesalahan
    // dapat tampil sekaligus tepat di bawah input terkait.
    // ========================================================
    if ($form['full_name'] === '') {
        $fieldErrors['full_name'] = 'Nama dokter wajib diisi.';
    } elseif (mb_strlen($form['full_name']) > 150) {
        $fieldErrors['full_name'] = 'Maksimal 150 karakter.';
    }

    if ($form['sip_number'] !== '' && mb_strlen($form['sip_number']) > 100) {
        $fieldErrors['sip_number'] = 'Maksimal 100 karakter.';
    }

    if ($form['str_number'] !== '' && mb_strlen($form['str_number']) > 100) {
        $fieldErrors['str_number'] = 'Maksimal 100 karakter.';
    }

    if ($form['specialty'] !== '' && mb_strlen($form['specialty']) > 100) {
        $fieldErrors['specialty'] = 'Maksimal 100 karakter.';
    }

    if ($form['phone'] !== '' && mb_strlen($form['phone']) > 30) {
        $fieldErrors['phone'] = 'Maksimal 30 karakter.';
    }

    if ($form['email'] !== '' && (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 191)) {
        $fieldErrors['email'] = 'Format email tidak valid.';
    }

    if (!in_array($form['status'], ['active', 'inactive'], true)) {
        $fieldErrors['status'] = 'Status dokter tidak valid.';
    }

    // ========================================================
    // CEK DUPLIKAT SIP / STR
    // Dokter yang sedang diedit dikecualikan dari pengecekan.
    // ========================================================
    if ($form['sip_number'] !== '' || $form['str_number'] !== '') {
        $duplicate = $pdo->prepare(
            'SELECT id, sip_number, str_number
             FROM doctors
             WHERE id <> :id
               AND ((:sip_number <> \'\' AND sip_number = :sip_check)
                 OR (:str_number <> \'\' AND str_number = :str_check))
             LIMIT 1'
        );
        $duplicate->execute([
            'id' => $doctorId,
            'sip_number' => $form['sip_number'],
            'sip_check' => $form['sip_number'],
            'str_number' => $form['str_number'],
            'str_check' => $form['str_number'],
        ]);
        $existing = $duplicate->fetch();

        if ($existing) {
            if ($form['sip_number'] !== '' && $existing['sip_number'] === $form['sip_number']) {
                $fieldErrors['sip_number'] = 'Nomor SIP sudah terdaftar.';
            }
            if ($form['str_number'] !== '' && $existing['str_number'] === $form['str_number']) {
                $fieldErrors['str_number'] = 'Nomor STR sudah terdaftar.';
            }
        }
    }

    if (!$fieldErrors) {
        // ====================================================
        // UPDATE DOKTER
        // NULLIF mengubah field opsional kosong menjadi NULL.
        // ====================================================
        $update = $pdo->prepare(
            'UPDATE doctors
             SET full_name = :full_name,
                 sip_number = NULLIF(:sip_number, \'\'),
                 str_number = NULLIF(:str_number, \'\'),
                 specialty = NULLIF(:specialty, \'\'),
                 phone = NULLIF(:phone, \'\'),
                 email = NULLIF(:email, \'\'),
                 status = :status
             WHERE id = :id'
        );
        $update->execute([
            'full_name' => $form['full_name'],
            'sip_number' => $form['sip_number'],
            'str_number' => $form['str_number'],
            'specialty' => $form['specialty'],
            'phone' => $form['phone'],
            'email' => $form['email'],
            'status' => $form['status'],
            'id' => $doctorId,
        ]);

        header('Location: /dashboard/doctors/?updated=1');
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
    <title>Edit Dokter — Klinik Tubagus</title>
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
        input, select { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        input:focus, select:focus { outline: 2px solid rgba(20,108,67,.15); border-color: #146c43; }
        input.is-invalid, select.is-invalid { border-color: #d92d20; }
        .hint { color: #667085; font-size: 12px; }
        .field-error { color: #b42318; font-size: 12px; line-height: 1.4; margin-top: -2px; }
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
        <h1>✏️ Edit Dokter</h1>
        <p class="subtitle">Perbarui data profesional dokter Klinik Tubagus.</p>

        <form method="post" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="grid">
                <div class="field full">
                    <label for="full_name">Nama Dokter *</label>
                    <input class="<?= isset($fieldErrors['full_name']) ? 'is-invalid' : '' ?>" id="full_name" name="full_name" type="text" maxlength="150" required aria-invalid="<?= isset($fieldErrors['full_name']) ? 'true' : 'false' ?>" value="<?= htmlspecialchars($form['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($fieldErrors['full_name'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['full_name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </div>

                <div class="field">
                    <label for="sip_number">Nomor SIP</label>
                    <input class="<?= isset($fieldErrors['sip_number']) ? 'is-invalid' : '' ?>" id="sip_number" name="sip_number" type="text" maxlength="100" aria-invalid="<?= isset($fieldErrors['sip_number']) ? 'true' : 'false' ?>" value="<?= htmlspecialchars($form['sip_number'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($fieldErrors['sip_number'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['sip_number'], ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><span class="hint">Nomor SIP harus unik.</span><?php endif; ?>
                </div>

                <div class="field">
                    <label for="str_number">Nomor STR</label>
                    <input class="<?= isset($fieldErrors['str_number']) ? 'is-invalid' : '' ?>" id="str_number" name="str_number" type="text" maxlength="100" aria-invalid="<?= isset($fieldErrors['str_number']) ? 'true' : 'false' ?>" value="<?= htmlspecialchars($form['str_number'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($fieldErrors['str_number'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['str_number'], ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><span class="hint">Nomor STR harus unik.</span><?php endif; ?>
                </div>

                <div class="field">
                    <label for="specialty">Spesialisasi</label>
                    <input class="<?= isset($fieldErrors['specialty']) ? 'is-invalid' : '' ?>" id="specialty" name="specialty" type="text" maxlength="100" aria-invalid="<?= isset($fieldErrors['specialty']) ? 'true' : 'false' ?>" value="<?= htmlspecialchars($form['specialty'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($fieldErrors['specialty'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['specialty'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </div>

                <div class="field">
                    <label for="phone">Telepon</label>
                    <input class="<?= isset($fieldErrors['phone']) ? 'is-invalid' : '' ?>" id="phone" name="phone" type="text" maxlength="30" aria-invalid="<?= isset($fieldErrors['phone']) ? 'true' : 'false' ?>" value="<?= htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($fieldErrors['phone'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['phone'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </div>

                <div class="field">
                    <label for="status">Status</label>
                    <select class="<?= isset($fieldErrors['status']) ? 'is-invalid' : '' ?>" id="status" name="status" aria-invalid="<?= isset($fieldErrors['status']) ? 'true' : 'false' ?>">
                        <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?php if (isset($fieldErrors['status'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['status'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </div>

                <div class="field full">
                    <label for="email">Email</label>
                    <input class="<?= isset($fieldErrors['email']) ? 'is-invalid' : '' ?>" id="email" name="email" type="email" maxlength="191" aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>" value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($fieldErrors['email'])): ?><span class="field-error"><?= htmlspecialchars($fieldErrors['email'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </div>
            </div>

            <div class="actions">
                <a class="button secondary" href="/dashboard/doctors/">← Batal</a>
                <button class="button" type="submit">💾 Simpan Perubahan</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
