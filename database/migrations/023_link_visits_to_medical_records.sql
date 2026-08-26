-- ============================================================
-- MENGHUBUNGKAN KUNJUNGAN DENGAN MASTER REKAM MEDIS
-- Setiap kunjungan klinis harus berada di dalam master rekam
-- medis pasien yang bersangkutan.
--
-- Migration ini dibuat bertahap agar data kunjungan lama tetap
-- aman: kolom dibuat nullable terlebih dahulu, seluruh data
-- existing di-backfill, lalu kolom dikunci menjadi NOT NULL.
-- ============================================================

-- ============================================================
-- LANGKAH 1: TAMBAHKAN REFERENSI MEDICAL RECORD
-- Nullable digunakan sementara selama proses backfill data lama.
-- ============================================================
ALTER TABLE patient_visits
    ADD COLUMN medical_record_id BIGINT UNSIGNED NULL AFTER patient_id,
    ADD KEY idx_patient_visits_medical_record (medical_record_id);

-- ============================================================
-- LANGKAH 2: BACKFILL DATA KUNJUNGAN LAMA
-- Relasi ditentukan berdasarkan patient_id yang sudah menjadi
-- foreign key pada patient_visits sejak migration awal.
-- ============================================================
UPDATE patient_visits AS v
INNER JOIN patient_medical_records AS mr
    ON mr.patient_id = v.patient_id
SET v.medical_record_id = mr.id
WHERE v.medical_record_id IS NULL;

-- ============================================================
-- LANGKAH 3: KUNCI RELASI
-- Setelah seluruh kunjungan memiliki master rekam medis,
-- medical_record_id tidak boleh lagi kosong.
-- ============================================================
ALTER TABLE patient_visits
    MODIFY COLUMN medical_record_id BIGINT UNSIGNED NOT NULL;

-- ============================================================
-- LANGKAH 4: FOREIGN KEY
-- Mencegah kunjungan menunjuk ke master rekam medis yang tidak
-- ada dan menjaga integritas histori klinis.
-- ============================================================
ALTER TABLE patient_visits
    ADD CONSTRAINT fk_patient_visits_medical_record
        FOREIGN KEY (medical_record_id)
        REFERENCES patient_medical_records(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;

-- ============================================================
-- HASIL ARSITEKTUR
-- Patient -> Medical Record -> Visit.
-- patient_id tetap dipertahankan sebagai relasi langsung ke
-- pasien untuk kompatibilitas query existing dan optimasi,
-- sedangkan medical_record_id menjadi relasi clinical container.
-- ============================================================
