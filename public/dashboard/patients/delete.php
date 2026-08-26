<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Database.php';
require_once __DIR__ . '/../../../app/helpers/auth.php';
require_once __DIR__ . '/../../../app/helpers/permission.php';
require_once __DIR__ . '/../../../app/helpers/csrf.php';

// ============================================================
// GUARD AKSES HAPUS PASIEN
// Penghapusan permanen hanya boleh dilakukan oleh pengguna
// yang memiliki permission patients.delete.
// ============================================================
require_permission('patients.delete');

// ============================================================
// HANYA POST
// Penghapusan data tidak boleh dilakukan melalui URL/GET.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ============================================================
// VALIDASI CSRF
// Memastikan request penghapusan berasal dari form aplikasi.
// ============================================================
verify_csrf();

// ============================================================
// VALIDASI ID PASIEN
// ID harus berupa bilangan bulat positif.
// ============================================================
$patientId = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($patientId === false || $patientId === null) {
    http_response_code(400);
    exit('ID pasien tidak valid.');
}

$pdo = Database::connection();

// ============================================================
// PASTIKAN PASIEN ADA
// Jangan menjalankan DELETE jika record target tidak ditemukan.
// ============================================================
$select = $pdo->prepare(
    'SELECT id, medical_record_number, full_name
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
// CEK RIWAYAT KUNJUNGAN
// Riwayat klinis harus dipertahankan. Tabel patient_visits
// menggunakan ON DELETE RESTRICT sehingga pasien yang sudah
// memiliki kunjungan memang tidak boleh dihapus permanen.
// ============================================================
$visitCheck = $pdo->prepare(
    'SELECT COUNT(*)
     FROM patient_visits
     WHERE patient_id = :patient_id'
);
$visitCheck->execute(['patient_id' => $patientId]);
$visitCount = (int) $visitCheck->fetchColumn();

if ($visitCount > 0) {
    http_response_code(409);
    exit('Pasien tidak dapat dihapus karena memiliki riwayat kunjungan. Gunakan Deactivate untuk mempertahankan riwayat klinis.');
}

// ============================================================
// HAPUS PASIEN
// DELETE hanya dilakukan setelah seluruh guard keamanan dan
// pemeriksaan relasi data berhasil dilewati.
// ============================================================
try {
    $delete = $pdo->prepare(
        'DELETE FROM patients
         WHERE id = :id
         LIMIT 1'
    );
    $delete->execute(['id' => $patientId]);

    header('Location: /dashboard/patients/?deleted=1');
    exit;
} catch (PDOException $exception) {
    // ========================================================
    // GAGAL HAPUS
    // Foreign key atau relasi data lain dapat menolak operasi.
    // ========================================================
    http_response_code(409);
    exit('Pasien tidak dapat dihapus karena masih digunakan oleh data lain. Gunakan Deactivate untuk mempertahankan data klinis.');
}
