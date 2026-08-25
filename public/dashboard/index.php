<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/helpers/auth.php';

require_auth();

$user = current_user();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        header { padding: 18px 28px; background: #fff; border-bottom: 1px solid #e3e7eb; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        header strong { font-size: 20px; }
        .logout { text-decoration: none; color: #b42318; font-weight: 700; }
        main { width: min(900px, calc(100% - 32px)); margin: 40px auto; }
        .hero, .card { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 28px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        h1 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 20px; }
        .label { color: #667085; font-size: 13px; margin-bottom: 7px; }
        .value { font-weight: 700; font-size: 18px; }
        @media (max-width: 700px) { .grid { grid-template-columns: 1fr; } header { padding: 16px; } main { margin-top: 24px; } }
    </style>
</head>
<body>
<header>
    <strong>🏥 Klinik Tubagus</strong>
    <a class="logout" href="/logout.php">Logout</a>
</header>

<main>
    <section class="hero">
        <h1>Selamat datang, <?= htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8') ?> 👑</h1>
        <p>Authentication dan session berhasil. Ini adalah dashboard Owner pertama.</p>

        <div class="grid">
            <div class="card">
                <div class="label">Role</div>
                <div class="value"><?= htmlspecialchars((string) $user['role_name'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="card">
                <div class="label">Username</div>
                <div class="value"><?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="card">
                <div class="label">Status</div>
                <div class="value">ACTIVE</div>
            </div>
        </div>
    </section>
</main>
</body>
</html>
