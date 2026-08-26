<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// GUARD AKSES CREATE USER
// Pengguna wajib memiliki permission users.create.
// ============================================================
require_permission('users.create');

$pdo = Database::connection();
$currentUser = current_user();
$errors = [];

$name = '';
$username = '';
$email = '';
$roleId = '';
$status = 'active';

// ============================================================
// AMBIL ROLE YANG TERSEDIA
// Role Owner tetap ditampilkan hanya untuk Owner. Ini adalah
// lapisan UI; keamanan sebenarnya tetap ditegakkan di server.
// ============================================================
$roles = $pdo->query('SELECT id, name, slug FROM roles ORDER BY id ASC')->fetchAll();
$isCurrentUserOwner = (($currentUser['role_slug'] ?? '') === 'owner');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================================
    // VALIDASI CSRF
    // Mencegah request POST yang tidak berasal dari form resmi.
    // ========================================================
    verify_csrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
    $roleId = (string) ($_POST['role_id'] ?? '');
    $status = (string) ($_POST['status'] ?? 'active');

    // ========================================================
    // VALIDASI INPUT DASAR
    // ========================================================
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Nama wajib diisi dan maksimal 150 karakter.';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        $errors[] = 'Username harus 3–100 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.';
    }

    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191)) {
        $errors[] = 'Format email tidak valid.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }

    $roleIdInt = filter_var($roleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($roleIdInt === false) {
        $errors[] = 'Role wajib dipilih.';
    }

    if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
        $errors[] = 'Status tidak valid.';
    }

    if (!$errors) {
        // ====================================================
        // AMBIL ROLE TARGET DARI DATABASE
        // Jangan mempercayai role_id dari browser.
        // ====================================================
        $roleStatement = $pdo->prepare('SELECT id, name, slug FROM roles WHERE id = :id LIMIT 1');
        $roleStatement->execute(['id' => $roleIdInt]);
        $role = $roleStatement->fetch();

        if (!$role) {
            $errors[] = 'Role yang dipilih tidak ditemukan.';
        }

        // ====================================================
        // PROTEKSI OWNER — SERVER-SIDE
        // HANYA akun yang role-nya Owner yang boleh membuat Owner.
        // Pemeriksaan dilakukan terhadap role aktif di database,
        // bukan hanya nilai role dari form atau dropdown.
        // ====================================================
        if (!$errors && $role['slug'] === 'owner') {
            $currentRoleStatement = $pdo->prepare(
                'SELECT r.slug
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 LIMIT 1'
            );
            $currentRoleStatement->execute([
                'user_id' => $currentUser['id'] ?? 0,
            ]);
            $currentRoleSlug = (string) $currentRoleStatement->fetchColumn();

            if ($currentRoleSlug !== 'owner') {
                $errors[] = 'Akses ditolak. Hanya Owner yang dapat membuat akun Owner.';
            }
        }
    }

    if (!$errors) {
        // ====================================================
        // CEK USERNAME / EMAIL DUPLIKAT
        // ====================================================
        if ($email !== '') {
            $duplicate = $pdo->prepare(
                'SELECT username, email FROM users
                 WHERE username = :username OR email = :email
                 LIMIT 1'
            );
            $duplicate->execute([
                'username' => $username,
                'email' => $email,
            ]);
        } else {
            $duplicate = $pdo->prepare(
                'SELECT username, email FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $duplicate->execute(['username' => $username]);
        }

        $existing = $duplicate->fetch();

        if ($existing) {
            if ($existing['username'] === $username) {
                $errors[] = 'Username sudah digunakan.';
            }
            if ($email !== '' && $existing['email'] === $email) {
                $errors[] = 'Email sudah digunakan.';
            }
        }
    }

    if (!$errors) {
        // ====================================================
        // SIMPAN USER BARU
        // Password selalu disimpan menggunakan password_hash().
        // ====================================================
        try {
            $statement = $pdo->prepare(
                'INSERT INTO users (role_id, name, username, email, password, status)
                 VALUES (:role_id, :name, :username, :email, :password, :status)'
            );
            $statement->execute([
                'role_id' => $roleIdInt,
                'name' => $name,
                'username' => $username,
                'email' => $email !== '' ? $email : null,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'status' => $status,
            ]);

            header('Location: /dashboard/users/?created=1');
            exit;
        } catch (PDOException $exception) {
            if ((int) $exception->errorInfo[1] === 1062) {
                $errors[] = 'Username atau email sudah digunakan.';
            } else {
                $errors[] = 'Gagal membuat user. Silakan coba lagi.';
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
    <title>Tambah User — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        header { padding: 18px 28px; background: #fff; border-bottom: 1px solid #e3e7eb; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        header strong { font-size: 20px; }
        .actions { display: flex; gap: 12px; }
        a { text-decoration: none; font-weight: 700; }
        .back { color: #475467; }
        .logout { color: #b42318; }
        main { width: min(760px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 28px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        h1 { margin: 0 0 7px; }
        .subtitle { margin: 0 0 24px; color: #667085; }
        .errors { margin: 0 0 22px; padding: 14px 18px; border-radius: 12px; background: #fff1f0; color: #b42318; }
        .errors li + li { margin-top: 6px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 7px; font-weight: 700; font-size: 14px; }
        input, select { width: 100%; padding: 12px 13px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        input:focus, select:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .hint { margin: 7px 0 0; color: #98a2b3; font-size: 12px; }
        .footer { display: flex; justify-content: flex-end; gap: 12px; margin-top: 25px; }
        .button { border: 0; cursor: pointer; padding: 12px 17px; border-radius: 10px; font-weight: 700; font-size: 14px; }
        .cancel { background: #f2f4f7; color: #344054; }
        .save { background: #146c43; color: #fff; }
        @media (max-width: 650px) { header { padding: 16px; } main { margin-top: 22px; } .grid { grid-template-columns: 1fr; } .full { grid-column: auto; } }
    </style>
</head>
<body>
<header>
    <strong>🏥 Klinik Tubagus</strong>
    <div class="actions">
        <a class="back" href="/dashboard/users/">User List</a>
        <a class="logout" href="/logout.php">Logout</a>
    </div>
</header>

<main>
    <section class="panel">
        <h1>➕ Tambah User</h1>
        <p class="subtitle">Buat akun pengguna baru untuk Klinik Tubagus.</p>

        <?php if ($errors): ?>
            <div class="errors">
                <strong>Periksa kembali:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="grid">
                <div class="full">
                    <label for="name">Nama Lengkap</label>
                    <input id="name" name="name" type="text" maxlength="150" required value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" autocomplete="name">
                </div>

                <div>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" maxlength="100" required value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username">
                    <p class="hint">3–100 karakter: huruf, angka, titik, _ atau -.</p>
                </div>

                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" maxlength="191" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email">
                    <p class="hint">Opsional, tetapi harus unik jika diisi.</p>
                </div>

                <div>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
                    <p class="hint">Minimal 8 karakter.</p>
                </div>

                <div>
                    <label for="password_confirmation">Konfirmasi Password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" required autocomplete="new-password">
                </div>

                <div>
                    <label for="role_id">Role</label>
                    <select id="role_id" name="role_id" required>
                        <option value="">— Pilih Role —</option>
                        <?php foreach ($roles as $role): ?>
                            <?php if ($role['slug'] === 'owner' && !$isCurrentUserOwner) continue; ?>
                            <option value="<?= (int) $role['id'] ?>" <?= (string) $roleId === (string) $role['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$isCurrentUserOwner): ?><p class="hint">Role Owner hanya dapat diberikan oleh Owner.</p><?php endif; ?>
                </div>

                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="footer">
                <a class="button cancel" href="/dashboard/users/">Batal</a>
                <button class="button save" type="submit">Simpan User</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
