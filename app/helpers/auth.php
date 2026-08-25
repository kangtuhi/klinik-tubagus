<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';

function require_auth(): void
{
    if (!Auth::check()) {
        header('Location: /login.php');
        exit;
    }
}

function current_user(): ?array
{
    return Auth::user();
}
