<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/core/Session.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// GUARD AKSES VOID KUNJUNGAN
// Hanya pengguna dengan permission visits.void yang dapat
// membatalkan kunjungan tanpa menghapus record klinis.
// ============================================================
require_permission('visits.void');
Session::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ============================================================
// VALIDASI CSRF
// Gunakan helper CSRF bersama agar nama field dan token session
// konsisten antara halaman histori dan endpoint void.
// ============================================================
verify_csrf();

// ============================================================
// VALIDASI ID KUNJUNGAN
// ============================================================
$visitId = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($visitId === false || $visitId === null) {
    http_response_code(400);
    exit('ID kunjungan tidak valid.');
}

// ============================================================
// ALASAN VOID
// Alasan wajib dicatat agar setiap pembatalan memiliki konteks.
// ============================================================
$reason = trim((string) ($_POST['void_reason'] ?? ''));

if ($reason === '') {
    http_response_code(422);
    exit('Alasan pembatalan wajib diisi.');
}

if (mb_strlen($reason) > 500) {
    http_response_code(422);
    exit('Alasan pembatalan maksimal 500 karakter.');
}

$pdo = Database::connection();

// ============================================================
// AMBIL KUNJUNGAN TARGET
// Kunjungan yang sudah cancelled tidak boleh di-void ulang.
// ============================================================
$statement = $pdo->prepare(
    'SELECT id, patient_id, status
     FROM patient_visits
     WHERE id = :id
     LIMIT 1'
);
$statement->execute(['id' => $visitId]);
$visit = $statement->fetch();

if (!$visit) {
    http_response_code(404);
    exit('Kunjungan tidak ditemukan.');
}

if ((string) $visit['status'] === 'cancelled') {
    http_response_code(409);
    exit('Kunjungan sudah dibatalkan sebelumnya.');
}

// ============================================================
// VOID KUNJUNGAN
// Record tidak dihapus. Status diubah menjadi cancelled dan
// metadata pembatalan disimpan untuk menjaga audit trail.
// ============================================================
try {
    $update = $pdo->prepare(
        'UPDATE patient_visits
         SET status = \'cancelled\',
             voided_by = :voided_by,
             voided_at = CURRENT_TIMESTAMP,
             void_reason = :void_reason,
             updated_by = :updated_by
         WHERE id = :id
           AND status <> \'cancelled\'
         LIMIT 1'
    );

    $update->execute([
        'voided_by' => $_SESSION['user_id'] ?? null,
        'void_reason' => $reason,
        'updated_by' => $_SESSION['user_id'] ?? null,
        'id' => $visitId,
    ]);

    if ($update->rowCount() !== 1) {
        http_response_code(409);
        exit('Kunjungan tidak dapat dibatalkan.');
    }

    header('Location: /dashboard/patients/visits.php?id=' . (int) $visit['patient_id'] . '&voided=1');
    exit;
} catch (PDOException $exception) {
    http_response_code(500);
    exit('Kunjungan gagal dibatalkan. Silakan coba lagi.');
}
