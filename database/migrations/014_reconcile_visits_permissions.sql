-- ============================================================
-- REKONSILIASI PERMISSION MODUL KUNJUNGAN
-- Migration ini menjadi langkah final yang idempoten untuk owner.
-- Baris sebelumnya tetap aman karena menggunakan INSERT IGNORE.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
    ON p.name IN ('visits.view', 'visits.create', 'visits.update')
WHERE r.name = 'owner';
