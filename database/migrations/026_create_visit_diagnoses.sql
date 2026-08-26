-- ============================================================
-- STRUCTURED DIAGNOSIS ARCHITECTURE v1
-- Diagnosis dipisahkan dari free-text SOAP agar satu kunjungan
-- dapat memiliki diagnosis utama, sekunder, dan banding secara
-- terstruktur tanpa merusak dokumentasi SOAP yang sudah ada.
--
-- Arsitektur:
-- Patient -> Medical Record -> Visit -> SOAP -> Diagnosis
-- ============================================================

CREATE TABLE IF NOT EXISTS patient_visit_diagnoses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visit_id BIGINT UNSIGNED NOT NULL,
    soap_id BIGINT UNSIGNED NULL,

    -- Jenis diagnosis membedakan diagnosis utama, sekunder, dan banding.
    diagnosis_type ENUM('primary', 'secondary', 'differential') NOT NULL DEFAULT 'primary',

    -- Kode dan deskripsi ICD-10 disimpan bersama agar histori klinis
    -- tetap terbaca walaupun katalog ICD-10 berubah di masa depan.
    icd10_code VARCHAR(20) NULL,
    diagnosis_name VARCHAR(255) NOT NULL,

    -- Catatan klinis tambahan untuk diagnosis tertentu.
    clinical_notes TEXT NULL,

    -- Status lifecycle diagnosis.
    status ENUM('active', 'resolved', 'cancelled') NOT NULL DEFAULT 'active',

    -- Audit perubahan data diagnosis.
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_visit_diagnoses_visit (visit_id),
    KEY idx_visit_diagnoses_soap (soap_id),
    KEY idx_visit_diagnoses_type (diagnosis_type),
    KEY idx_visit_diagnoses_icd10 (icd10_code),
    KEY idx_visit_diagnoses_status (status),
    KEY idx_visit_diagnoses_created_by (created_by),
    KEY idx_visit_diagnoses_updated_by (updated_by),

    CONSTRAINT fk_visit_diagnoses_visit
        FOREIGN KEY (visit_id) REFERENCES patient_visits(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_visit_diagnoses_soap
        FOREIGN KEY (soap_id) REFERENCES patient_visit_soap_notes(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_visit_diagnoses_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_visit_diagnoses_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CATATAN MIGRASI DATA LAMA
-- Diagnosis lama pada patient_visits.diagnosis sengaja tidak
-- otomatis dimasukkan ke tabel terstruktur. Free-text lama tidak
-- boleh ditebak menjadi kode ICD-10 karena dapat menghasilkan
-- diagnosis klinis yang salah.
--
-- Data lama tetap dipertahankan pada patient_visits.diagnosis dan
-- dapat dimigrasikan secara terkontrol melalui UI diagnosis baru.
-- ============================================================

-- ============================================================
-- CATATAN INTEGRITAS KLINIS
-- Aplikasi wajib memastikan diagnosis baru terhubung ke visit
-- yang benar dan, bila menggunakan SOAP, hanya mengikat SOAP milik
-- visit tersebut. Aturan FINALIZED/IMMUTABLE dikontrol di layer
-- aplikasi agar diagnosis tidak mengubah SOAP asli secara diam-diam.
-- ============================================================
