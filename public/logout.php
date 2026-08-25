<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/core/Auth.php';

Auth::logout();

header('Location: /login.php');
exit;
