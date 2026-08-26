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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard/patients/');
    exit;
}

// ============================================================
// VALIDASI CSRF
// ============================================================
$postedToken = (string) ($_POST['csrf_token'] ?? '');
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

// ============================================================
// UPDATE STATUS PASIEN
// Medical record number sengaja tidak disentuh agar RMKT tetap.
// ============================================================
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
