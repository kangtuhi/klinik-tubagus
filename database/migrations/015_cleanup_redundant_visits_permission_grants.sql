-- ============================================================
-- MIGRATION KOMPATIBILITAS PERMISSION KUNJUNGAN
-- Tidak menghapus data permission yang sudah terpasang. File ini
-- memastikan instalasi berulang tetap aman dan idempoten.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.name = 'owner'
  AND p.name IN ('visits.view', 'visits.create', 'visits.update');
