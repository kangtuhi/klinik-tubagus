<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/core/Database.php';

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function promptHidden(string $label): string
{
    echo $label;

    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell -NoProfile -Command "$p=Read-Host -AsSecureString; $bstr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)"';
        $password = shell_exec($command);
        return trim((string) $password);
    }

    $password = shell_exec('stty -echo; read password; stty echo; echo "$password"');
    echo PHP_EOL;
    return trim((string) $password);
}

try {
    $pdo = Database::connection();

    $roleStatement = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $roleStatement->execute(['slug' => 'owner']);
    $role = $roleStatement->fetch();

    if (!$role) {
        throw new RuntimeException('Role owner belum tersedia. Jalankan seed roles terlebih dahulu.');
    }

    $existingStatement = $pdo->prepare('SELECT id, username, email FROM users WHERE role_id = :role_id LIMIT 1');
    $existingStatement->execute(['role_id' => $role['id']]);
    $existingOwner = $existingStatement->fetch();

    if ($existingOwner) {
        echo "Akun Owner sudah ada: {$existingOwner['username']}" . PHP_EOL;
        exit(0);
    }

    $name = prompt('Nama Owner: ');
    $username = prompt('Username: ');
    $email = prompt('Email: ');
    $password = promptHidden('Password: ');
    $passwordConfirmation = promptHidden('Konfirmasi Password: ');

    if ($name === '' || $username === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Semua field wajib diisi.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Format email tidak valid.');
    }

    if ($password !== $passwordConfirmation) {
        throw new InvalidArgumentException('Konfirmasi password tidak cocok.');
    }

    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password minimal 8 karakter.');
    }

    $duplicateStatement = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $duplicateStatement->execute([
        'username' => $username,
        'email' => $email,
    ]);

    if ($duplicateStatement->fetch()) {
        throw new RuntimeException('Username atau email sudah digunakan.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        'INSERT INTO users (role_id, name, username, email, password, status)
         VALUES (:role_id, :name, :username, :email, :password, :status)'
    );

    $insert->execute([
        'role_id' => $role['id'],
        'name' => $name,
        'username' => $username,
        'email' => $email,
        'password' => $passwordHash,
        'status' => 'active',
    ]);

    echo PHP_EOL;
    echo "========================================" . PHP_EOL;
    echo "OWNER ACCOUNT CREATED SUCCESSFULLY 👑" . PHP_EOL;
    echo "========================================" . PHP_EOL;
    echo "Username : {$username}" . PHP_EOL;
    echo "Email    : {$email}" . PHP_EOL;
    echo "Role     : owner" . PHP_EOL;
    echo "Status   : active" . PHP_EOL;
    echo "Password : HASHED" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
