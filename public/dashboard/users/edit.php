<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// GUARD AKSES EDIT USER
// ============================================================
require_permission('users.update');

$pdo = Database::connection();
$currentUser = current_user();
$fieldErrors = [];
$globalError = null;

// ============================================================
// VALIDASI DAN AMBIL USER TARGET
// ============================================================
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(400);
    exit('ID user tidak valid.');
}

$userStatement = $pdo->prepare(
    'SELECT u.id, u.role_id, u.name, u.username, u.email, u.status, r.name AS role_name, r.slug AS role_slug
     FROM users u INNER JOIN roles r ON r.id = u.role_id
     WHERE u.id = :id LIMIT 1'
);
$userStatement->execute(['id' => $id]);
$user = $userStatement->fetch();

if (!$user) {
    http_response_code(404);
    exit('User tidak ditemukan.');
}

$isOwner = $user['role_slug'] === 'owner';
$isCurrentUser = (int) ($currentUser['id'] ?? 0) === (int) $user['id'];
$name = (string) $user['name'];
$username = (string) $user['username'];
$email = (string) ($user['email'] ?? '');
$roleId = (string) $user['role_id'];
$status = (string) $user['status'];
$password = '';
$passwordConfirmation = '';

$roles = $pdo->query('SELECT id, name, slug FROM roles ORDER BY id ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ========================================================
    // AMBIL OLD INPUT
    // ========================================================
    $name = trim((string) ($_POST['name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
    $roleId = (string) ($_POST['role_id'] ?? '');
    $status = (string) ($_POST['status'] ?? 'active');

    // ========================================================
    // VALIDASI INDEPENDEN PER FIELD
    // Semua error dikumpulkan agar dapat muncul sekaligus.
    // ========================================================
    if ($name === '') {
        $fieldErrors['name'] = 'Nama wajib diisi.';
    } elseif (mb_strlen($name) > 150) {
        $fieldErrors['name'] = 'Nama maksimal 150 karakter.';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        $fieldErrors['username'] = 'Username harus 3–100 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.';
    }

    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191)) {
        $fieldErrors['email'] = 'Format email tidak valid.';
    }

    if ($password !== '' && strlen($password) < 8) {
        $fieldErrors['password'] = 'Password baru minimal 8 karakter.';
    }

    if ($password !== $passwordConfirmation) {
        $fieldErrors['password_confirmation'] = 'Konfirmasi password tidak cocok.';
    }

    $roleIdInt = filter_var($roleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($roleIdInt === false) {
        $fieldErrors['role_id'] = 'Role wajib dipilih.';
    }

    if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
        $fieldErrors['status'] = 'Status tidak valid.';
    }

    // ========================================================
    // PROTEKSI OWNER UTAMA
    // Error dipetakan ke field yang terkait.
    // ========================================================
    if ($isOwner && !$isCurrentUser) {
        $globalError = 'Owner utama hanya dapat dikelola oleh dirinya sendiri.';
    }

    if ($isOwner && $roleIdInt !== false && (int) $roleIdInt !== (int) $user['role_id']) {
        $fieldErrors['role_id'] = 'Role Owner utama tidak dapat diubah.';
    }

    if ($isCurrentUser && $status !== 'active') {
        $fieldErrors['status'] = 'Akun yang sedang digunakan tidak boleh dinonaktifkan atau disuspend.';
    }

    // ========================================================
    // VALIDASI ROLE TARGET DAN ANTI OWNER ESCALATION
    // ========================================================
    $role = null;
    if ($roleIdInt !== false) {
        $roleStatement = $pdo->prepare('SELECT id, name, slug FROM roles WHERE id = :id LIMIT 1');
        $roleStatement->execute(['id' => $roleIdInt]);
        $role = $roleStatement->fetch();

        if (!$role) {
            $fieldErrors['role_id'] = 'Role yang dipilih tidak ditemukan.';
        } elseif ($role['slug'] === 'owner' && ($currentUser['role_slug'] ?? '') !== 'owner') {
            $fieldErrors['role_id'] = 'Hanya Owner yang dapat menetapkan role Owner.';
        }
    }

    // ========================================================
    // CEK DUPLIKAT USERNAME DAN EMAIL SECARA INDEPENDEN
    // User yang sedang diedit dikecualikan dari pengecekan.
    // ========================================================
    if (!isset($fieldErrors['username']) && $username !== '') {
        $duplicateUsername = $pdo->prepare('SELECT id FROM users WHERE id <> :id AND username = :username LIMIT 1');
        $duplicateUsername->execute(['id' => $id, 'username' => $username]);
        if ($duplicateUsername->fetch()) {
            $fieldErrors['username'] = 'Username sudah digunakan.';
        }
    }

    if (!isset($fieldErrors['email']) && $email !== '') {
        $duplicateEmail = $pdo->prepare('SELECT id FROM users WHERE id <> :id AND email = :email LIMIT 1');
        $duplicateEmail->execute(['id' => $id, 'email' => $email]);
        if ($duplicateEmail->fetch()) {
            $fieldErrors['email'] = 'Email sudah digunakan.';
        }
    }

    // ========================================================
    // SIMPAN HANYA JIKA SELURUH VALIDASI LOLOS
    // ========================================================
    if (!$fieldErrors && $globalError === null && $roleIdInt !== false && $role) {
        try {
            $fields = ['role_id = :role_id', 'name = :name', 'username = :username', 'email = :email', 'status = :status'];
            $params = ['role_id' => $roleIdInt, 'name' => $name, 'username' => $username, 'email' => $email !== '' ? $email : null, 'status' => $status, 'id' => $id];

            if ($password !== '') {
                $fields[] = 'password = :password';
                $params['password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $statement = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id LIMIT 1');
            $statement->execute($params);

            header('Location: /dashboard/users/?updated=1');
            exit;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $globalError = 'Username atau email sudah digunakan. Silakan periksa kembali data yang dimasukkan.';
            } else {
                $globalError = 'Gagal memperbarui user. Silakan coba lagi.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit User — Klinik Tubagus</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f4f6f8;color:#17202a}header{padding:18px 28px;background:#fff;border-bottom:1px solid #e3e7eb;display:flex;align-items:center;justify-content:space-between;gap:16px}header strong{font-size:20px}.actions{display:flex;gap:12px}a{text-decoration:none;font-weight:700}.back{color:#475467}.logout{color:#b42318}main{width:min(760px,calc(100% - 32px));margin:36px auto}.panel{background:#fff;border:1px solid #e3e7eb;border-radius:18px;padding:28px;box-shadow:0 12px 35px rgba(0,0,0,.06)}h1{margin:0 0 7px}.subtitle{margin:0 0 24px;color:#667085}.badge{display:inline-block;margin-left:8px;padding:4px 9px;border-radius:999px;background:#fff3cd;color:#8a6116;font-size:12px;vertical-align:middle}.notice{margin:0 0 22px;padding:13px 15px;border-radius:12px;background:#f0fdf4;color:#166534}.global-error{margin:0 0 22px;padding:13px 15px;border-radius:12px;background:#fff1f0;color:#b42318}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.full{grid-column:1/-1}label{display:block;margin-bottom:7px;font-weight:700;font-size:14px}input,select{width:100%;padding:12px 13px;border:1px solid #d0d5dd;border-radius:10px;font:inherit;background:#fff}input:focus,select:focus{outline:3px solid rgba(20,108,67,.12);border-color:#146c43}input.is-invalid,select.is-invalid{border-color:#d92d20}input:disabled,select:disabled{background:#f2f4f7;color:#667085;cursor:not-allowed}.hint{margin:7px 0 0;color:#98a2b3;font-size:12px}.field-error{display:block;margin-top:7px;color:#b42318;font-size:12px;line-height:1.4}.footer{display:flex;justify-content:flex-end;gap:12px;margin-top:25px}.button{border:0;cursor:pointer;padding:12px 17px;border-radius:10px;font-weight:700;font-size:14px}.cancel{background:#f2f4f7;color:#344054}.save{background:#146c43;color:#fff}@media(max-width:650px){header{padding:16px}main{margin-top:22px}.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
</head>
<body>
<header><strong>🏥 Klinik Tubagus</strong><div class="actions"><a class="back" href="/dashboard/users/">User List</a><a class="logout" href="/logout.php">Logout</a></div></header>
<main><section class="panel">
<h1>✏️ Edit User <span class="badge">ID #<?= (int) $user['id'] ?></span></h1><p class="subtitle">Perbarui data akun pengguna Klinik Tubagus.</p>
<?php if ($isOwner): ?><div class="notice">👑 Ini adalah Owner utama. Role tidak dapat diturunkan dan hanya Owner yang sedang login yang dapat mengelola akun ini.</div><?php endif; ?>
<?php if ($globalError !== null): ?><div class="global-error"><?= htmlspecialchars($globalError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" novalidate><div class="grid">
<div class="full"><label for="name">Nama Lengkap</label><input class="<?= isset($fieldErrors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" type="text" maxlength="150" required value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" autocomplete="name"><?php if(isset($fieldErrors['name'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif;?></div>
<div><label for="username">Username</label><input class="<?= isset($fieldErrors['username']) ? 'is-invalid' : '' ?>" id="username" name="username" type="text" maxlength="100" required value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username"><?php if(isset($fieldErrors['username'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['username'], ENT_QUOTES, 'UTF-8') ?></span><?php endif;?></div>
<div><label for="email">Email</label><input class="<?= isset($fieldErrors['email']) ? 'is-invalid' : '' ?>" id="email" name="email" type="email" maxlength="191" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email"><?php if(isset($fieldErrors['email'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['email'], ENT_QUOTES, 'UTF-8') ?></span><?php endif;?></div>
<div><label for="password">Password Baru</label><input class="<?= isset($fieldErrors['password']) ? 'is-invalid' : '' ?>" id="password" name="password" type="password" minlength="8" autocomplete="new-password"><?php if(isset($fieldErrors['password'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['password'], ENT_QUOTES, 'UTF-8') ?></span><?php else:?><p class="hint">Kosongkan jika password tidak ingin diubah.</p><?php endif;?></div>
<div><label for="password_confirmation">Konfirmasi Password Baru</label><input class="<?= isset($fieldErrors['password_confirmation']) ? 'is-invalid' : '' ?>" id="password_confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password"><?php if(isset($fieldErrors['password_confirmation'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['password_confirmation'], ENT_QUOTES, 'UTF-8') ?></span><?php endif;?></div>
<div><label for="role_id">Role</label><select class="<?= isset($fieldErrors['role_id']) ? 'is-invalid' : '' ?>" id="role_id" name="role_id" required <?= $isOwner ? 'disabled' : '' ?>><?php foreach($roles as $role): ?><?php if($role['slug']==='owner'&&($currentUser['role_slug']??'')!=='owner'&&$roleId!==(string)$role['id']) continue; ?><option value="<?= (int)$role['id'] ?>" <?= $roleId===(string)$role['id']?'selected':'' ?>><?= htmlspecialchars($role['name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach;?></select><?php if($isOwner):?><input type="hidden" name="role_id" value="<?= (int)$user['role_id'] ?>"><?php endif;?><?php if(isset($fieldErrors['role_id'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['role_id'],ENT_QUOTES,'UTF-8') ?></span><?php endif;?></div>
<div><label for="status">Status</label><select class="<?= isset($fieldErrors['status']) ? 'is-invalid' : '' ?>" id="status" name="status"><?php foreach(['active'=>'Active','inactive'=>'Inactive','suspended'=>'Suspended'] as $value=>$label):?><option value="<?= $value ?>" <?= $status===$value?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select><?php if(isset($fieldErrors['status'])):?><span class="field-error"><?= htmlspecialchars($fieldErrors['status'],ENT_QUOTES,'UTF-8') ?></span><?php elseif($isCurrentUser):?><p class="hint">Akun yang sedang login harus tetap Active.</p><?php endif;?></div>
</div><div class="footer"><a class="button cancel" href="/dashboard/users/">Batal</a><button class="button save" type="submit">Simpan Perubahan</button></div></form>
</section></main></body></html>
