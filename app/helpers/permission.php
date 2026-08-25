<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Auth.php';

function require_permission(string $permission): void
{
    if (!Auth::check()) {
        header('Location: /login.php');
        exit;
    }

    if (!Auth::can($permission)) {
        http_response_code(403);
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>403 — Akses Ditolak</title></head><body><main><h1>403 — Akses Ditolak</h1><p>Anda tidak memiliki permission untuk mengakses halaman ini.</p><p><a href="/dashboard/">Kembali ke Dashboard</a></p></main></body></html>';
        exit;
    }
}
