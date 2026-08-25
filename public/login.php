<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/core/Auth.php';

Session::start();

if (Auth::check()) {
    header('Location: /');
    exit;
}

$error = null;
$identity = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string) ($_POST['identity'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($identity === '' || $password === '') {
        $error = 'Username/email dan password wajib diisi.';
    } elseif (!Auth::attempt($identity, $password)) {
        $error = 'Username/email atau password salah.';
    } else {
        header('Location: /');
        exit;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        .card { width: min(420px, calc(100% - 32px)); padding: 32px; background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; box-shadow: 0 16px 45px rgba(0,0,0,.08); }
        h1 { margin: 0 0 8px; }
        .subtitle { margin: 0 0 24px; color: #667085; }
        label { display: block; margin: 16px 0 7px; font-weight: 600; }
        input { width: 100%; padding: 12px 14px; border: 1px solid #cfd6dd; border-radius: 10px; font-size: 16px; }
        button { width: 100%; margin-top: 22px; padding: 13px; border: 0; border-radius: 10px; background: #146c43; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
        .error { padding: 11px 13px; border-radius: 10px; background: #fff0f0; color: #b42318; border: 1px solid #f3b5b5; }
    </style>
</head>
<body>
<main class="card">
    <h1>🏥 Klinik Tubagus</h1>
    <p class="subtitle">Login ke sistem klinik</p>

    <?php if ($error !== null): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/login.php" autocomplete="on">
        <label for="identity">Username atau Email</label>
        <input id="identity" name="identity" type="text" value="<?= htmlspecialchars($identity, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Masuk</button>
    </form>
</main>
</body>
</html>
