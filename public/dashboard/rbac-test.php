<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/helpers/auth.php';

require_auth();

$user = current_user();

$permissions = [
    'users.view', 'users.create', 'users.update', 'users.delete',
    'patients.view', 'patients.create', 'patients.update', 'patients.delete',
    'doctors.view', 'doctors.create', 'doctors.update', 'doctors.delete',
    'medical_records.view', 'medical_records.create', 'medical_records.update',
    'medicines.view', 'medicines.create', 'medicines.update',
    'queue.view', 'queue.create', 'queue.update',
    'billing.view', 'billing.create', 'billing.update',
    'reports.view',
];

$results = [];
$allPassed = true;

foreach ($permissions as $permission) {
    $passed = Auth::can($permission);
    $results[$permission] = $passed;
    if (!$passed) {
        $allPassed = false;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RBAC Smoke Test — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(900px, 100%); margin: auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 28px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        h1 { margin-top: 0; }
        .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 20px 0; }
        .meta div { padding: 14px; background: #f8fafc; border-radius: 10px; }
        .label { color: #667085; font-size: 13px; }
        .value { margin-top: 5px; font-weight: 700; }
        .group { margin-top: 24px; }
        .group h2 { font-size: 17px; }
        .row { display: flex; justify-content: space-between; padding: 10px 12px; border-bottom: 1px solid #edf0f2; }
        .pass { color: #067647; font-weight: 700; }
        .fail { color: #b42318; font-weight: 700; }
        .result { margin-top: 24px; padding: 16px; border-radius: 12px; font-weight: 800; }
        .success { background: #ecfdf3; color: #067647; }
        .danger { background: #fff1f0; color: #b42318; }
        a { display: inline-block; margin-top: 20px; color: #146c43; font-weight: 700; text-decoration: none; }
        @media (max-width: 650px) { .meta { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>👑 RBAC Smoke Test</h1>

        <div class="meta">
            <div><div class="label">Authenticated</div><div class="value pass">PASS</div></div>
            <div><div class="label">User</div><div class="value"><?= htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div><div class="label">Role</div><div class="value"><?= htmlspecialchars((string) $user['role_name'], ENT_QUOTES, 'UTF-8') ?></div></div>
        </div>

        <?php
        $groups = [
            'Users' => ['users.view', 'users.create', 'users.update', 'users.delete'],
            'Patients' => ['patients.view', 'patients.create', 'patients.update', 'patients.delete'],
            'Doctors' => ['doctors.view', 'doctors.create', 'doctors.update', 'doctors.delete'],
            'Medical Records' => ['medical_records.view', 'medical_records.create', 'medical_records.update'],
            'Medicines' => ['medicines.view', 'medicines.create', 'medicines.update'],
            'Queue' => ['queue.view', 'queue.create', 'queue.update'],
            'Billing' => ['billing.view', 'billing.create', 'billing.update'],
            'Reports' => ['reports.view'],
        ];
        foreach ($groups as $groupName => $groupPermissions):
        ?>
            <div class="group">
                <h2><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h2>
                <?php foreach ($groupPermissions as $permission): ?>
                    <div class="row">
                        <span><?= htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="<?= $results[$permission] ? 'pass' : 'fail' ?>"><?= $results[$permission] ? 'PASS' : 'FAIL' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="result <?= $allPassed ? 'success' : 'danger' ?>">
            <?= $allPassed ? '🎉 OWNER RBAC TEST: PASS' : '❌ OWNER RBAC TEST: FAIL' ?>
        </div>

        <a href="/dashboard/">← Kembali ke Dashboard</a>
    </section>
</main>
</body>
</html>
