-- ============================================================
-- SEQUENCE NOMOR REKAM MEDIS HARIAN
-- Menyimpan nomor urut terakhir untuk setiap tanggal registrasi.
-- Nomor akan kembali ke 00001 ketika tanggal berganti.
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
-- GENERATOR NOMOR RM HARIAN
-- Saat proses CREATE PATIENT mengubah RM sementara menjadi RM
-- final, trigger menggantinya menjadi format:
-- RMKT-YYYYMMDD-00001
-- RMKT-YYYYMMDD-00002
-- dan seterusnya.
-- ============================================================
DROP TRIGGER IF EXISTS trg_patients_daily_medical_record_number;

DELIMITER $$

CREATE TRIGGER trg_patients_daily_medical_record_number
BEFORE UPDATE ON patients
FOR EACH ROW
BEGIN
    DECLARE next_number INT UNSIGNED DEFAULT 1;

    -- ========================================================
    -- GENERATE RM SAAT RM SEMENTARA DIGANTI MENJADI RM FINAL
    -- Nomor urut dikunci melalui INSERT/UPDATE pada tabel sequence
    -- sehingga registrasi bersamaan tetap mendapatkan nomor unik.
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
