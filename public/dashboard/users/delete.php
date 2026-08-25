<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

require_permission('users.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

verify_csrf();

$pdo = Database::connection();
$currentUser = current_user();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || $id === null) {
    http_response_code(400);
    exit('ID user tidak valid.');
}

if ((int) $id === (int) ($currentUser['id'] ?? 0)) {
    http_response_code(403);
    exit('Anda tidak dapat menghapus akun yang sedang digunakan.');
}

$statement = $pdo->prepare(
    'SELECT u.id, u.name, r.slug AS role_slug
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
    exit('Owner utama tidak dapat dihapus.');
}

try {
    $delete = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
    $delete->execute(['id' => $id]);

    header('Location: /dashboard/users/?deleted=1');
    exit;
} catch (PDOException $exception) {
    http_response_code(409);
    exit('User tidak dapat dihapus karena masih digunakan oleh data lain. Nonaktifkan user sebagai gantinya.');
}
