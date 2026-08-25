<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

require_permission('users.update');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

$pdo = Database::connection();
$currentUser = current_user();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? '');

if ($id === false || $id === null || !in_array($action, ['activate', 'deactivate', 'suspend'], true)) {
    http_response_code(400);
    exit('Permintaan tidak valid.');
}

if ((int) $id === (int) ($currentUser['id'] ?? 0)) {
    http_response_code(403);
    exit('Anda tidak dapat mengubah status akun yang sedang digunakan.');
}

$statement = $pdo->prepare(
    'SELECT u.id, u.status, r.slug AS role_slug
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.id = :id
     LIMIT 1'
);
$statement->execute(['id' => $id]);
$user = $statement->fetch();

if (!$user) {
    http_response_code(404);
    exit('User tidak ditemukan.');
}

if ($user['role_slug'] === 'owner') {
    http_response_code(403);
    exit('Owner utama tidak dapat dinonaktifkan atau disuspend melalui action ini.');
}

$statusMap = [
    'activate' => 'active',
    'deactivate' => 'inactive',
    'suspend' => 'suspended',
];

$update = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id LIMIT 1');
$update->execute([
    'status' => $statusMap[$action],
    'id' => $id,
]);

header('Location: /dashboard/users/?status_updated=1');
exit;
