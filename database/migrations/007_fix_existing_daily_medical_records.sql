-- ============================================================
-- PERBAIKAN NOMOR RM HARIAN YANG SUDAH TERLANJUR TERPASANG
-- Migration ini dipakai setelah migration 006 pernah dijalankan
-- tetapi data pasien lama belum berubah ke format RMKT.
-- ============================================================

CREATE TABLE IF NOT EXISTS patient_rm_sequences (
    sequence_date DATE NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SINKRONISASI SEQUENCE HARI INI
-- Mengambil nomor RMKT terbesar yang sudah ada agar nomor baru
-- tidak bertabrakan dengan data yang telah berhasil tersimpan.
-- ============================================================
INSERT INTO patient_rm_sequences (sequence_date, last_number)
SELECT
    CURRENT_DATE,
    COALESCE(MAX(CAST(RIGHT(medical_record_number, 5) AS UNSIGNED)), 0)
FROM patients
WHERE medical_record_number LIKE CONCAT('RMKT-', DATE_FORMAT(CURRENT_DATE, '%Y%m%d'), '-%')
ON DUPLICATE KEY UPDATE
    last_number = GREATEST(last_number, VALUES(last_number));

-- ============================================================
-- AKTIFKAN ULANG GENERATOR RM HARIAN
-- Trigger mengubah RM sementara/format lama menjadi RMKT harian.
-- ============================================================
DROP TRIGGER IF EXISTS trg_patients_daily_medical_record_number;

DELIMITER $$

CREATE TRIGGER trg_patients_daily_medical_record_number
BEFORE UPDATE ON patients
FOR EACH ROW
BEGIN
    DECLARE next_number INT UNSIGNED DEFAULT 1;

    -- ========================================================
    -- GENERATE NOMOR RM FINAL
    -- Hanya perubahan menuju format RM-YYYY-ID yang diproses,
    -- sehingga update data pasien biasa tidak mengubah nomor RM.
    -- ========================================================
    IF NEW.medical_record_number <> OLD.medical_record_number
       AND NEW.medical_record_number LIKE 'RM-%' THEN

        INSERT INTO patient_rm_sequences (sequence_date, last_number)
        VALUES (CURRENT_DATE, 1)
        ON DUPLICATE KEY UPDATE last_number = last_number + 1;

        SELECT last_number
          INTO next_number
          FROM patient_rm_sequences
         WHERE sequence_date = CURRENT_DATE;

        SET NEW.medical_record_number = CONCAT(
            'RMKT-',
            DATE_FORMAT(CURRENT_DATE, '%Y%m%d'),
            '-',
            LPAD(next_number, 5, '0')
        );
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- KONVERSI PASIEN LAMA
-- Langkah pertama memakai nilai sementara agar trigger mendeteksi
-- perubahan pada RM, kemudian langkah kedua memicu generator RMKT.
-- ============================================================
UPDATE patients
SET medical_record_number = CONCAT('TMP-MIGRATE-', id)
WHERE medical_record_number NOT LIKE 'RMKT-%';

UPDATE patients
SET medical_record_number = CONCAT('RM-', YEAR(CURRENT_DATE), '-', LPAD(id, 6, '0'))
WHERE medical_record_number LIKE 'TMP-MIGRATE-%';
