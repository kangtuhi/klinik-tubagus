<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

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
        header { padding: 18px 28px; background: #fff; border-bottom: 1px solid #e3e7eb; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        header strong { font-size: 20px; }
        .actions { display: flex; gap: 12px; align-items: center; }
        a { text-decoration: none; font-weight: 700; }
        .back { color: #475467; }
        .logout { color: #b42318; }
        main { width: min(1200px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .button { display: inline-block; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 13px 12px; text-align: left; border-bottom: 1px solid #edf0f2; }
        th { background: #f8fafc; font-size: 13px; color: #475467; }
        .badge { display: inline-block; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .active { background: #ecfdf3; color: #067647; }
        .inactive { background: #f2f4f7; color: #475467; }
        .suspended { background: #fff1f0; color: #b42318; }
        .owner { font-weight: 700; }
        .muted { color: #98a2b3; font-size: 13px; }
        @media (max-width: 700px) { header { padding: 16px; } main { margin-top: 22px; } .titlebar { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<header>
    <strong>🏥 Klinik Tubagus</strong>
    <div class="actions">
        <a class="back" href="/dashboard/">Dashboard</a>
        <a class="logout" href="/logout.php">Logout</a>
    </div>
</header>

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

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= (int) $user['id'] ?></td>
                        <td class="<?= $user['role_slug'] === 'owner' ? 'owner' : '' ?>">
                            <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?><?= $user['role_slug'] === 'owner' ? ' 👑' : '' ?>
                        </td>
                        <td><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($user['role_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td>
                            <?php if ((int) $user['id'] === (int) $currentUser['id'] && $user['role_slug'] === 'owner'): ?>
                                <span class="muted">Owner utama</span>
                            <?php else: ?>
                                <?php if (Auth::can('users.update')): ?><a href="/dashboard/users/edit.php?id=<?= (int) $user['id'] ?>">Edit</a><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="7">Belum ada user.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
