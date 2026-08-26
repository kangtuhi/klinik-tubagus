-- ============================================================
-- PEMBUATAN TABEL KUNJUNGAN PASIEN
-- Menyimpan riwayat kunjungan klinis yang terpisah dari identitas
-- pasien sehingga satu pasien dapat memiliki banyak kunjungan.
-- ============================================================

CREATE TABLE IF NOT EXISTS patient_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    patient_id BIGINT UNSIGNED NOT NULL,
    visit_number VARCHAR(30) NOT NULL,
    visit_date DATE NOT NULL,
    complaint TEXT NULL,
    examination TEXT NULL,
    diagnosis TEXT NULL,
    treatment TEXT NULL,
    notes TEXT NULL,
    status ENUM('open', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_patient_visit_number (visit_number),
    KEY idx_patient_visits_patient_date (patient_id, visit_date, id),
    KEY idx_patient_visits_status (status),
    KEY idx_patient_visits_created_by (created_by),
    KEY idx_patient_visits_updated_by (updated_by),

    CONSTRAINT fk_patient_visits_patient
        FOREIGN KEY (patient_id) REFERENCES patients(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOMOR KUNJUNGAN
-- Nomor kunjungan memakai identitas pasien dan ID record agar
-- mudah dibaca serta tetap unik tanpa mengubah nomor RM pasien.
-- ============================================================
-- Format yang dipakai aplikasi: KJ-YYYYMMDD-000001
-- Nomor final akan dibuat oleh aplikasi saat record kunjungan dibuat.
