<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// GUARD AKSES HAPUS DOKTER
// Hanya pengguna dengan permission doctors.delete yang dapat
// menghapus data dokter.
// ============================================================
require_permission('doctors.delete');

// ============================================================
// HANYA POST
// Penghapusan data tidak boleh dilakukan melalui GET/link biasa.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ============================================================
// VALIDASI CSRF
// Melindungi aksi penghapusan dari request lintas situs.
// ============================================================
verify_csrf();

$pdo = Database::connection();

// ============================================================
// VALIDASI ID DOKTER
// ID harus berupa bilangan bulat positif.
// ============================================================
$doctorId = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($doctorId === false || $doctorId === null) {
    http_response_code(400);
    exit('ID dokter tidak valid.');
}

// ============================================================
// PASTIKAN DOKTER ADA
// Jangan menjalankan DELETE sebelum record target ditemukan.
// ============================================================
$statement = $pdo->prepare(
    'SELECT id, full_name
     FROM doctors
     WHERE id = :id
     LIMIT 1'
);
$statement->execute(['id' => $doctorId]);
$doctor = $statement->fetch();

if (!$doctor) {
    http_response_code(404);
    exit('Dokter tidak ditemukan.');
}

// ============================================================
// HAPUS DOKTER
// Data dokter dihapus secara permanen setelah seluruh guard lolos.
// ============================================================
try {
    $delete = $pdo->prepare(
        'DELETE FROM doctors
         WHERE id = :id
         LIMIT 1'
    );
    $delete->execute(['id' => $doctorId]);

    header('Location: /dashboard/doctors/?deleted=1');
    exit;
} catch (PDOException $exception) {
    // ========================================================
    // GAGAL HAPUS
    // Jika kelak dokter sudah direferensikan tabel lain, database
    // dapat menolak penghapusan dan kita tampilkan pesan aman.
    // ========================================================
    http_response_code(409);
    exit('Dokter tidak dapat dihapus karena masih digunakan oleh data lain.');
}
