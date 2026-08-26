-- ============================================================
-- CLINICAL ADDENDUM / AMENDMENT
-- Addendum dipakai untuk menambahkan koreksi atau informasi klinis
-- setelah SOAP sudah FINALIZED tanpa mengubah catatan aslinya.
--
-- Arsitektur:
-- Patient -> Medical Record -> Visit -> SOAP -> Addendum
-- ============================================================

CREATE TABLE IF NOT EXISTS patient_visit_clinical_addendums (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visit_id BIGINT UNSIGNED NOT NULL,
    soap_id BIGINT UNSIGNED NOT NULL,

    -- Alasan wajib menjelaskan mengapa addendum dibuat.
    reason VARCHAR(255) NOT NULL,

    -- Isi koreksi/tambahan klinis disimpan sebagai catatan tersendiri.
    content TEXT NOT NULL,

    -- Audit pencatat addendum.
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_clinical_addendum_visit (visit_id),
    KEY idx_clinical_addendum_soap (soap_id),
    KEY idx_clinical_addendum_created_by (created_by),
    KEY idx_clinical_addendum_created_at (created_at),

    CONSTRAINT fk_clinical_addendum_visit
        FOREIGN KEY (visit_id) REFERENCES patient_visits(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_clinical_addendum_soap
        FOREIGN KEY (soap_id) REFERENCES patient_visit_soap_notes(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_clinical_addendum_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CATATAN INTEGRITAS
-- Database sengaja tidak menyediakan UPDATE/DELETE workflow melalui
-- aplikasi untuk addendum. Catatan dibuat sebagai histori immutable.
-- Pemeriksaan bahwa SOAP harus FINALIZED dilakukan di layer aplikasi
-- sebelum INSERT agar koreksi hanya dapat dibuat pada dokumentasi final.
-- ============================================================
