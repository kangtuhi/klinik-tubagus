<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Session.php';

final class Auth
{
    public static function attempt(string $identity, string $password): bool
    {
        Session::start();

        $identity = trim($identity);
        if ($identity === '' || $password === '') {
            return false;
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT u.id, u.role_id, u.name, u.username, u.email, u.password, u.status, r.name AS role_name, r.slug AS role_slug
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE (u.username = :username OR u.email = :email)
             LIMIT 1'
        );
        $statement->execute([
            'username' => $identity,
            'email' => $identity,
        ]);
        $user = $statement->fetch();

        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password'])) {
            return false;
        }

        Session::regenerate();
        Session::set('authenticated', true);
        Session::set('user_id', (int) $user['id']);
        Session::set('role_id', (int) $user['role_id']);
        Session::set('role_slug', $user['role_slug']);
        Session::set('role_name', $user['role_name']);
        Session::set('user_name', $user['name']);
        Session::set('username', $user['username']);

        $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => $user['id']]);

        return true;
    }

    public static function check(): bool
    {
        return Session::get('authenticated', false) === true;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id' => Session::get('user_id'),
            'name' => Session::get('user_name'),
            'username' => Session::get('username'),
            'role_id' => Session::get('role_id'),
            'role_name' => Session::get('role_name'),
            'role_slug' => Session::get('role_slug'),
        ];
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }

        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT 1
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id
               AND p.slug = :permission
             LIMIT 1'
        );
        $statement->execute([
            'role_id' => Session::get('role_id'),
            'permission' => $permission,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
