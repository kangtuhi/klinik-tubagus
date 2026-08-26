-- ============================================================
-- SOAP CLINICAL DOCUMENTATION
-- Memisahkan dokumentasi klinis dari tabel encounter/kunjungan.
--
-- Arsitektur:
-- Patient -> Medical Record -> Visit -> SOAP Note
--
-- Tabel patient_visits tetap dipertahankan untuk kompatibilitas
-- modul kunjungan yang sudah berjalan. Data SOAP menjadi sumber
-- dokumentasi klinis terstruktur untuk pengembangan berikutnya.
-- ============================================================

CREATE TABLE IF NOT EXISTS patient_visit_soap_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visit_id BIGINT UNSIGNED NOT NULL,

    -- Subjective: keluhan dan informasi yang disampaikan pasien.
    subjective TEXT NULL,

    -- Objective: hasil pemeriksaan klinis dan temuan objektif.
    objective TEXT NULL,

    -- Assessment: penilaian klinis, diagnosis, atau kesan medis.
    assessment TEXT NULL,

    -- Plan: rencana terapi, tindak lanjut, dan rencana pelayanan.
    plan TEXT NULL,

    -- Catatan tambahan tetap dipisahkan agar informasi non-SOAP
    -- tidak dipaksa masuk ke salah satu komponen utama SOAP.
    notes TEXT NULL,

    -- Status dokumentasi membedakan catatan yang masih dapat diedit
    -- dari catatan yang sudah difinalisasi secara klinis.
    status ENUM('draft', 'finalized') NOT NULL DEFAULT 'draft',

    -- Audit penanggung jawab dokumentasi klinis.
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    finalized_by BIGINT UNSIGNED NULL,
    finalized_at TIMESTAMP NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Satu encounter memiliki satu SOAP utama pada arsitektur v1.
    UNIQUE KEY uq_visit_soap_visit (visit_id),
    KEY idx_visit_soap_status (status),
    KEY idx_visit_soap_created_by (created_by),
    KEY idx_visit_soap_updated_by (updated_by),
    KEY idx_visit_soap_finalized_by (finalized_by),

    CONSTRAINT fk_visit_soap_visit
        FOREIGN KEY (visit_id) REFERENCES patient_visits(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_visit_soap_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_visit_soap_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_visit_soap_finalized_by
        FOREIGN KEY (finalized_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BACKFILL DOKUMENTASI KUNJUNGAN LAMA
-- Data existing dipindahkan secara konservatif ke struktur SOAP.
--
-- complaint   -> subjective
-- examination -> objective
-- diagnosis   -> assessment
-- treatment   -> plan
-- notes       -> notes
--
-- Tidak ada data lama yang dihapus dari patient_visits pada tahap
-- ini. Dengan demikian rollback aplikasi tetap lebih aman.
-- ============================================================

INSERT INTO patient_visit_soap_notes (
    visit_id,
    subjective,
    objective,
    assessment,
    plan,
    notes,
    status,
    created_by,
    updated_by,
    created_at,
    updated_at
)
SELECT
    v.id,
    v.complaint,
    v.examination,
    v.diagnosis,
    v.treatment,
    v.notes,
    CASE
        WHEN v.status = 'completed' THEN 'finalized'
        ELSE 'draft'
    END,
    v.created_by,
    v.updated_by,
    v.created_at,
    v.updated_at
FROM patient_visits AS v
WHERE NOT EXISTS (
    SELECT 1
    FROM patient_visit_soap_notes AS s
    WHERE s.visit_id = v.id
);

-- ============================================================
-- HASIL ARSITEKTUR
-- Patient -> Medical Record -> Visit -> SOAP Note.
--
-- patient_visits masih menyimpan field clinical lama untuk
-- kompatibilitas. Pada tahap aplikasi berikutnya, form visit akan
-- diarahkan ke patient_visit_soap_notes sebagai sumber data klinis.
-- Setelah seluruh modul aman dan tervalidasi, field lama dapat
-- dipensiunkan melalui migration terpisah.
-- ============================================================
