-- ============================================================
-- AKSES OWNER UNTUK MODUL KUNJUNGAN
-- Owner adalah role tertinggi Klinik Tubagus dan mendapat seluruh
-- permission modul kunjungan klinis.
--
-- Nama role mengikuti data master RBAC yang digunakan aplikasi:
-- "Owner" dengan huruf O kapital.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'Owner'
  AND p.name IN ('visits.view', 'visits.create', 'visits.update');
