<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <main>
        <h1>🏥 Klinik Tubagus</h1>
        <p>PHP Engine Online</p>
        <p>Environment: <?= htmlspecialchars($app['env'], ENT_QUOTES, 'UTF-8') ?></p>
    </main>
</body>
</html>
