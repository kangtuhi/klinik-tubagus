<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';

// ============================================================
// AKSES PERUBAHAN STATUS PASIEN
// Deactivate/reactivate termasuk perubahan data pasien sehingga
// wajib menggunakan permission patients.update.
// ============================================================
require_permission('patients.update');
Session::start();

// ============================================================
// TOKEN CSRF
// Melindungi aksi perubahan status dari request tidak sah.
// ============================================================
if (!Session::has('csrf_patient_status')) {
    Session::set('csrf_patient_status', bin2hex(random_bytes(32)));
}
$csrfToken = (string) Session::get('csrf_patient_status');

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL PASIEN
// Hanya status yang akan diubah; RMKT dan data identitas tetap.
// ============================================================
$select = $pdo->prepare(
    'SELECT id, medical_record_number, full_name, status
     FROM patients
     WHERE id = :id
     LIMIT 1'
);
$select->execute(['id' => $patientId]);
$patient = $select->fetch();

if (!$patient) {
    http_response_code(404);
    exit('Pasien tidak ditemukan.');
}

// ============================================================
// PROSES PERUBAHAN STATUS
// Medical record number sengaja tidak disentuh agar RMKT tetap.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    // ========================================================
    // VALIDASI CSRF
    // ========================================================
    if (!hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('Token keamanan tidak valid.');
    }

    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['deactivate', 'reactivate'], true)) {
        http_response_code(400);
        exit('Aksi status pasien tidak valid.');
    }

    $targetStatus = $action === 'deactivate' ? 'inactive' : 'active';

    try {
        $update = $pdo->prepare(
            'UPDATE patients
             SET status = :status
             WHERE id = :id'
        );
        $update->execute([
            'status' => $targetStatus,
            'id' => $patientId,
        ]);

        header('Location: /dashboard/patients/?status_changed=' . $targetStatus);
        exit;
    } catch (PDOException $exception) {
        http_response_code(500);
        exit('Status pasien gagal diperbarui.');
    }
}

$isActive = $patient['status'] === 'active';
$targetAction = $isActive ? 'deactivate' : 'reactivate';
$targetLabel = $isActive ? 'Deactivate Pasien' : 'Aktifkan Kembali Pasien';
$targetStatusLabel = $isActive ? 'INACTIVE' : 'ACTIVE';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?> — Klinik Tubagus</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #17202a; }
        main { width: min(620px, calc(100% - 32px)); margin: 60px auto; }
        .panel { background: #fff; border: 1px solid #e3e7eb; border-radius: 18px; padding: 28px; box-shadow: 0 12px 35px rgba(0,0,0,.06); }
        h1 { margin: 0 0 10px; }
        .subtitle { color: #667085; margin: 0 0 24px; line-height: 1.6; }
        .patient { padding: 16px; border: 1px solid #e4e7ec; border-radius: 12px; background: #f8fafc; margin-bottom: 20px; }
        .patient strong { display: block; margin-bottom: 5px; }
        .rm { color: #146c43; font-weight: 800; }
        .warning { padding: 14px 16px; border-radius: 10px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; margin-bottom: 22px; line-height: 1.5; }
        .actions { display: flex; justify-content: flex-end; gap: 10px; }
        .button { border: 0; cursor: pointer; padding: 11px 16px; border-radius: 10px; font: inherit; font-weight: 700; text-decoration: none; }
        .secondary { background: #f2f4f7; color: #344054; }
        .danger { background: #b42318; color: #fff; }
        .success { background: #146c43; color: #fff; }
        @media (max-width: 600px) { .actions { flex-direction: column-reverse; } .button { width: 100%; text-align: center; } }
    </style>
</head>
<body>
<?php require __DIR__ . '/../_partials/navbar.php'; ?>

<main>
    <section class="panel">
        <!-- =====================================================
             KONFIRMASI PERUBAHAN STATUS
             Pengguna melihat pasien dan konsekuensi aksi sebelum
             status benar-benar diubah.
             ===================================================== -->
        <h1><?= $isActive ? '🔴 Deactivate Pasien' : '🟢 Aktifkan Kembali Pasien' ?></h1>
        <p class="subtitle">
            <?= $isActive
                ? 'Pasien akan tetap tersimpan di database, tetapi statusnya menjadi INACTIVE.'
                : 'Pasien akan kembali dapat digunakan sebagai pasien ACTIVE.' ?>
        </p>

        <div class="patient">
            <strong><?= htmlspecialchars($patient['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="rm"><?= htmlspecialchars($patient['medical_record_number'], ENT_QUOTES, 'UTF-8') ?></span>
            <div>Status saat ini: <strong><?= strtoupper(htmlspecialchars($patient['status'], ENT_QUOTES, 'UTF-8')) ?></strong></div>
        </div>

        <div class="warning">
            <strong>Perhatian:</strong> nomor rekam medis, NIK, dan data pasien tidak akan dihapus.
            Hanya status pasien yang diubah menjadi <strong><?= $targetStatusLabel ?></strong>.
        </div>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= htmlspecialchars($targetAction, ENT_QUOTES, 'UTF-8') ?>">

            <div class="actions">
                <a class="button secondary" href="/dashboard/patients/">Batal</a>
                <button class="button <?= $isActive ? 'danger' : 'success' ?>" type="submit">
                    <?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
