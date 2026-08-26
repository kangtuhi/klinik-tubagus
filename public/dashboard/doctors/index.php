<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// GUARD AKSES MODUL DOKTER
// Hanya pengguna dengan permission doctors.view yang dapat
// membuka daftar dokter.
// ============================================================
require_permission('doctors.view');

$pdo = Database::connection();

// ============================================================
// AMBIL DATA DOKTER
// Data diurutkan berdasarkan nama agar daftar mudah digunakan
// saat jumlah dokter bertambah.
// ============================================================
$statement = $pdo->query(
    'SELECT id, full_name, sip_number, str_number, specialty, phone, email, status
     FROM doctors
     ORDER BY full_name ASC, id ASC'
);
$doctors = $statement->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Dokter — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1250px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .button { display: inline-block; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; text-decoration: none; font-weight: 700; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th, td { padding: 13px 12px; text-align: left; border-bottom: 1px solid #edf0f2; vertical-align: middle; }
        th { background: #f8fafc; font-size: 13px; color: #475467; }
        .badge { display: inline-block; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .active { background: #ecfdf3; color: #067647; }
        .inactive { background: #f2f4f7; color: #475467; }
        .muted { color: #98a2b3; }
        .empty { text-align: center; padding: 32px 12px; color: #667085; }
        @media (max-width: 700px) {
            main { margin-top: 22px; }
            .titlebar { align-items: flex-start; flex-direction: column; }
            .panel { padding: 18px; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <div class="titlebar">
            <div>
                <h1>🩺 Data Dokter</h1>
                <p class="subtitle">Kelola data profesional dokter Klinik Tubagus.</p>
            </div>
            <?php if (Auth::can('doctors.create')): ?>
                <a class="button" href="/dashboard/doctors/create.php">+ Tambah Dokter</a>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama Dokter</th>
                        <th>SIP</th>
                        <th>STR</th>
                        <th>Spesialisasi</th>
                        <th>Telepon</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($doctors as $doctor): ?>
                    <tr>
                        <td><?= (int) $doctor['id'] ?></td>
                        <td><strong><?= htmlspecialchars((string) $doctor['full_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars((string) ($doctor['sip_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '<span class="muted">—</span>' ?></td>
                        <td><?= htmlspecialchars((string) ($doctor['str_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '<span class="muted">—</span>' ?></td>
                        <td><?= htmlspecialchars((string) ($doctor['specialty'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '<span class="muted">Umum</span>' ?></td>
                        <td><?= htmlspecialchars((string) ($doctor['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '<span class="muted">—</span>' ?></td>
                        <td><?= htmlspecialchars((string) ($doctor['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '<span class="muted">—</span>' ?></td>
                        <td><span class="badge <?= htmlspecialchars((string) $doctor['status'], ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars((string) $doctor['status'], ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td>
                            <?php if (Auth::can('doctors.update')): ?>
                                <a href="/dashboard/doctors/edit.php?id=<?= (int) $doctor['id'] ?>">Edit</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$doctors): ?>
                    <tr><td colspan="9" class="empty">Belum ada data dokter.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
