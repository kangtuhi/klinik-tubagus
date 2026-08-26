<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

require_permission('users.view');

$pdo = Database::connection();
$statement = $pdo->query(
    'SELECT u.id, u.name, u.username, u.email, u.status, r.name AS role_name, r.slug AS role_slug
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     ORDER BY u.id ASC'
);
$users = $statement->fetchAll();
$currentUser = current_user();
$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1200px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .button { display: inline-block; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1080px; }
        th, td { padding: 13px 12px; text-align: left; border-bottom: 1px solid #edf0f2; vertical-align: middle; }
        th { background: #f8fafc; font-size: 13px; color: #475467; }
        .badge { display: inline-block; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .active { background: #ecfdf3; color: #067647; }
        .inactive { background: #f2f4f7; color: #475467; }
        .suspended { background: #fff1f0; color: #b42318; }
        .owner { font-weight: 700; }
        .muted { color: #98a2b3; font-size: 13px; }
        .actions-cell { display: flex; gap: 8px; flex-wrap: wrap; }
        .action { border: 0; cursor: pointer; padding: 7px 10px; border-radius: 8px; font: inherit; font-weight: 700; background: #f2f4f7; color: #344054; }
        .action.activate { background: #ecfdf3; color: #067647; }
        .action.suspend { background: #fff7ed; color: #c2410c; }
        .action.deactivate { background: #f2f4f7; color: #475467; }
        .action.delete { background: #fff1f0; color: #b42318; }
        form.inline { margin: 0; }
        .notice { margin-bottom: 18px; padding: 12px 15px; border-radius: 10px; background: #ecfdf3; color: #067647; }
        @media (max-width: 700px) { main { margin-top: 22px; } .titlebar { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <div class="titlebar">
            <div>
                <h1>👥 User Management</h1>
                <p class="subtitle">Kelola akun pengguna dan role Klinik Tubagus.</p>
            </div>
            <?php if (Auth::can('users.create')): ?>
                <a class="button" href="/dashboard/users/create.php">+ Tambah User</a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['status_updated'])): ?><div class="notice">✅ Status user berhasil diperbarui.</div><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><div class="notice">✅ User berhasil dihapus.</div><?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Nama</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php $isCurrent = (int) $user['id'] === (int) $currentUser['id']; ?>
                    <tr>
                        <td><?= (int) $user['id'] ?></td>
                        <td class="<?= $user['role_slug'] === 'owner' ? 'owner' : '' ?>">
                            <?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= $user['role_slug'] === 'owner' ? ' 👑' : '' ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($user['email'] === null || trim((string) $user['email']) === ''): ?>
                                <span class="muted">— Tidak menggunakan email —</span>
                            <?php else: ?>
                                <?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($user['role_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= htmlspecialchars((string) ($user['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars((string) ($user['status'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td>
                            <?php if ($user['role_slug'] === 'owner' || $isCurrent): ?>
                                <span class="muted"><?= $user['role_slug'] === 'owner' ? 'Owner utama' : 'Akun sendiri' ?></span>
                            <?php else: ?>
                                <div class="actions-cell">
                                    <?php if (Auth::can('users.update')): ?>
                                        <a href="/dashboard/users/edit.php?id=<?= (int) $user['id'] ?>">Edit</a>
                                        <?php if ($user['status'] === 'active'): ?>
                                            <form class="inline" method="post" action="/dashboard/users/action.php" onsubmit="return confirm('Nonaktifkan user ini? User tidak akan dapat login sampai diaktifkan kembali.');">
                                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="deactivate">
                                                <button class="action deactivate" type="submit">Nonaktifkan</button>
                                            </form>
                                            <form class="inline" method="post" action="/dashboard/users/action.php" onsubmit="return confirm('Suspend user ini?');">
                                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="suspend">
                                                <button class="action suspend" type="submit">Suspend</button>
                                            </form>
                                        <?php else: ?>
                                            <form class="inline" method="post" action="/dashboard/users/action.php" onsubmit="return confirm('Aktifkan kembali user ini?');">
                                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="activate">
                                                <button class="action activate" type="submit">Aktifkan</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (Auth::can('users.delete')): ?>
                                        <form class="inline" method="post" action="/dashboard/users/delete.php" onsubmit="return confirm('⚠️ HAPUS USER INI PERMANEN?\n\nData akun akan dihapus dari database. Pastikan user ini memang sudah tidak diperlukan.');">
                                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                            <button class="action delete" type="submit">Hapus</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="7">Belum ada user.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
