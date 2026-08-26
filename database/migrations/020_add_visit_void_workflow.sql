-- ============================================================
-- HARDENING WORKFLOW KUNJUNGAN: VOID / PEMBATALAN
-- Kunjungan klinis tidak dihapus secara permanen. Record tetap
-- dipertahankan dan dapat diberi status cancelled agar histori
-- klinis tetap utuh.
-- ============================================================

INSERT INTO permissions (name, slug, description)
VALUES
    ('visits.void', 'visits-void', 'Membatalkan kunjungan tanpa menghapus histori')
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug),
    description = VALUES(description);

ALTER TABLE patient_visits
    ADD COLUMN voided_by BIGINT UNSIGNED NULL AFTER updated_by,
    ADD COLUMN voided_at TIMESTAMP NULL AFTER voided_by,
    ADD COLUMN void_reason VARCHAR(500) NULL AFTER voided_at,
    ADD KEY idx_patient_visits_voided_by (voided_by),
    ADD CONSTRAINT fk_patient_visits_voided_by
        FOREIGN KEY (voided_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

-- ============================================================
-- AKSES OWNER DAN ADMINISTRATOR
-- Keduanya bertanggung jawab atas operasional sistem klinik.
-- ============================================================
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug IN ('owner', 'administrator')
  AND p.name = 'visits.void';
