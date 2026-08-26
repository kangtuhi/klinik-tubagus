-- ============================================================
-- AKSES OWNER UNTUK OPERASI KUNJUNGAN
-- Memastikan owner dapat membuat dan memperbarui kunjungan setelah
-- permission definitions dibuat pada migration sebelumnya.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name IN ('visits.create', 'visits.update')
WHERE r.name = 'owner';
