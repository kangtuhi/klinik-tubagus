-- ============================================================
-- ARSITEKTUR REKAM MEDIS PASIEN
-- Membuat satu wadah rekam medis permanen untuk setiap pasien.
-- Identitas pasien tetap berada di tabel patients, sedangkan
-- riwayat pelayanan klinis akan terhubung melalui medical record.
-- ============================================================

CREATE TABLE IF NOT EXISTS patient_medical_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id BIGINT UNSIGNED NOT NULL,
    record_status ENUM('active', 'closed') NOT NULL DEFAULT 'active',
    opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Satu pasien hanya memiliki satu master rekam medis.
    UNIQUE KEY uq_patient_medical_records_patient (patient_id),
    KEY idx_patient_medical_records_status (record_status),

    CONSTRAINT fk_patient_medical_records_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INISIALISASI REKAM MEDIS PASIEN LAMA
-- Pasien yang sudah ada otomatis mendapatkan master rekam medis
-- tanpa mengubah atau menghapus data identitas pasien.
-- ============================================================

INSERT INTO patient_medical_records (patient_id)
SELECT p.id
FROM patients AS p
WHERE NOT EXISTS (
    SELECT 1
    FROM patient_medical_records AS pmr
    WHERE pmr.patient_id = p.id
);

-- ============================================================
-- CATATAN ARSITEKTUR
-- patient_visits tetap menjadi tabel encounter/kunjungan.
-- Tahap berikutnya akan menghubungkan patient_visits dengan
-- patient_medical_records secara eksplisit setelah seluruh
-- dependensi dan data existing diverifikasi.
-- ============================================================
