<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES HALAMAN PASIEN
// Hanya pengguna dengan permission patients.view yang dapat
// membuka daftar pasien.
// ============================================================
require_permission('patients.view');

$pdo = Database::connection();

// ============================================================
// FILTER DAN PENCARIAN PASIEN
// Mendukung pencarian berdasarkan nomor RM, NIK, nama, atau
// nomor telepon serta filter status pasien.
// ============================================================
$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$statusChanged = (string) ($_GET['status_changed'] ?? '');

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(medical_record_number LIKE :search_rm OR nik LIKE :search_nik OR full_name LIKE :search_name OR phone LIKE :search_phone)';
    $searchValue = '%' . $search . '%';
    $params['search_rm'] = $searchValue;
    $params['search_nik'] = $searchValue;
    $params['search_name'] = $searchValue;
    $params['search_phone'] = $searchValue;
}

if (in_array($status, ['active', 'inactive'], true)) {
    $conditions[] = 'status = :status';
    $params['status'] = $status;
} else {
    $status = '';
}

// ============================================================
// DATABASE QUERY
// Mengambil data utama pasien untuk ditampilkan pada tabel.
// ============================================================
$sql = 'SELECT id, medical_record_number, nik, full_name, gender, birth_date, phone, status, created_at
        FROM patients';

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$patients = $statement->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patients — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(1250px, calc(100% - 32px)); margin: 36px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 26px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        .titlebar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
        h1 { margin: 0 0 6px; }
        .subtitle { margin: 0; color: #667085; }
        .button { display: inline-block; padding: 11px 15px; border-radius: 10px; background: #146c43; color: #fff; text-decoration: none; }
        .filters { display: grid; grid-template-columns: minmax(240px, 1fr) 170px auto; gap: 10px; margin-bottom: 22px; }
        input, select { width: 100%; padding: 11px 12px; border: 1px solid #d0d5dd; border-radius: 10px; font: inherit; background: #fff; }
        input:focus, select:focus { outline: 3px solid rgba(20,108,67,.12); border-color: #146c43; }
        .filter-button { border: 0; cursor: pointer; padding: 11px 16px; border-radius: 10px; background: #344054; color: #fff; font: inherit; font-weight: 700; }
        .notice { margin-bottom: 18px; padding: 13px 15px; border-radius: 10px; background: #ecfdf3; border: 1px solid #abefc6; color: #067647; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1120px; }
        th, td { padding: 13px 12px; text-align: left; border-bottom: 1px solid #edf0f2; vertical-align: middle; }
        th { background: #f8fafc; font-size: 13px; color: #475467; }
        .badge { display: inline-block; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .active { background: #ecfdf3; color: #067647; }
        .inactive { background: #f2f4f7; color: #475467; }
        .rm { font-weight: 700; color: #146c43; }
        .muted { color: #98a2b3; font-size: 13px; }
        .empty { padding: 30px; text-align: center; color: #667085; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .action-link { font-weight: 700; text-decoration: none; }
        .action-link.danger { color: #b42318; }
        .action-link.success { color: #067647; }
        @media (max-width: 700px) {
            main { margin-top: 22px; }
            .titlebar { align-items: flex-start; flex-direction: column; }
            .filters { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             HEADER MODUL PASIEN
             Judul halaman dan tombol registrasi pasien baru.
             ===================================================== -->
        <div class="titlebar">
            <div>
                <h1>🏥 Patients</h1>
                <p class="subtitle">Kelola data identitas pasien Klinik Tubagus.</p>
            </div>
            <?php if (Auth::can('patients.create')): ?>
                <a class="button" href="/dashboard/patients/create.php">+ Tambah Pasien</a>
            <?php endif; ?>
        </div>

        <?php if (in_array($statusChanged, ['active', 'inactive'], true)): ?>
            <!-- =================================================
                 NOTIFIKASI STATUS
                 Menyampaikan hasil deactivate/reactivate pasien.
                 ================================================= -->
            <div class="notice">
                ✅ Status pasien berhasil diubah menjadi <strong><?= strtoupper(htmlspecialchars($statusChanged, ENT_QUOTES, 'UTF-8')) ?></strong>.
            </div>
        <?php endif; ?>

        <!-- =====================================================
             FILTER PASIEN
             Pencarian dan filter status dilakukan melalui GET.
             ===================================================== -->
        <form class="filters" method="get">
            <input
                type="search"
                name="q"
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="Cari No. RM, NIK, nama, atau telepon..."
                aria-label="Cari pasien"
            >
            <select name="status" aria-label="Filter status pasien">
                <option value="">Semua Status</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button class="filter-button" type="submit">🔎 Cari</button>
        </form>

        <!-- =====================================================
             TABEL DAFTAR PASIEN
             Menampilkan identitas utama pasien secara ringkas.
             ===================================================== -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>No. Rekam Medis</th>
                        <th>NIK</th>
                        <th>Nama Pasien</th>
                        <th>Gender</th>
                        <th>Tanggal Lahir</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($patients as $patient): ?>
                    <tr>
                        <td><?= (int) $patient['id'] ?></td>
                        <td class="rm"><?= htmlspecialchars($patient['medical_record_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($patient['nik'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($patient['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $patient['gender'] === 'male' ? 'Laki-laki' : 'Perempuan' ?></td>
                        <td><?= htmlspecialchars($patient['birth_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $patient['phone'] !== null && $patient['phone'] !== '' ? htmlspecialchars($patient['phone'], ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>' ?></td>
                        <td>
                            <span class="badge <?= htmlspecialchars($patient['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= strtoupper(htmlspecialchars($patient['status'], ENT_QUOTES, 'UTF-8')) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (Auth::can('patients.update')): ?>
                                <div class="actions">
                                    <a class="action-link" href="/dashboard/patients/edit.php?id=<?= (int) $patient['id'] ?>">Edit</a>
                                    <?php if ($patient['status'] === 'active'): ?>
                                        <a class="action-link danger" href="/dashboard/patients/status.php?id=<?= (int) $patient['id'] ?>">Deactivate</a>
                                    <?php else: ?>
                                        <a class="action-link success" href="/dashboard/patients/status.php?id=<?= (int) $patient['id'] ?>">Aktifkan</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$patients): ?>
                    <tr>
                        <td class="empty" colspan="9">Belum ada pasien yang sesuai dengan pencarian.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
