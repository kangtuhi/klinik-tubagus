-- ============================================================
-- PERBAIKAN GRANT PERMISSION KUNJUNGAN
-- Migration ini menghapus ketergantungan pada nama role klinis
-- yang belum tentu tersedia. Owner tetap menjadi role yang pasti
-- mendapatkan akses penuh modul kunjungan.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN ('visits.view', 'visits.create', 'visits.update')
WHERE r.name = 'owner';
