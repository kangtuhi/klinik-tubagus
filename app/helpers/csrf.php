<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    $submittedToken = (string) ($_POST['_csrf_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        // Use standard HTTP 403 so Apache/PHP/Laragon does not reinterpret
        // the response as a server error. The request is forbidden because
        // its CSRF proof is missing or invalid.
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('CSRF token tidak valid. Silakan muat ulang halaman dan coba lagi.');
    }
}
