<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
require_once __DIR__ . '/../app/core/Database.php';

header('Content-Type: text/html; charset=UTF-8');

$databaseStatus = [
    'connected' => false,
    'message' => 'Belum diuji',
    'serverVersion' => null,
];

try {
    $pdo = Database::connection();
    $databaseStatus['connected'] = true;
    $databaseStatus['message'] = 'CONNECTED';
    $databaseStatus['serverVersion'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $e) {
    $databaseStatus['message'] = $app['debug'] ? $e->getMessage() : 'CONNECTION FAILED';
}
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
        <h1>🏥 <?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p>PHP Engine: <strong>ONLINE</strong></p>
        <p>Environment: <?= htmlspecialchars($app['env'], ENT_QUOTES, 'UTF-8') ?></p>
        <p>PDO/MySQL: <strong><?= $databaseStatus['connected'] ? 'CONNECTED' : 'FAILED' ?></strong></p>

        <?php if ($databaseStatus['connected']): ?>
            <p>Database: <strong>klinik_tubagus</strong></p>
            <p>MySQL Server: <?= htmlspecialchars((string) $databaseStatus['serverVersion'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <pre><?= htmlspecialchars($databaseStatus['message'], ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </main>
</body>
</html>
