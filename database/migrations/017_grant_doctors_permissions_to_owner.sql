-- ============================================================
-- PERMISSION MODUL DOKTER UNTUK OWNER
-- Memberikan permission utama modul dokter kepada role Owner.
-- Menggunakan slug role agar konsisten dengan data RBAC saat ini.
-- NOT EXISTS membuat migration aman dijalankan berulang kali.
-- ============================================================

INSERT INTO role_permissions (role_id, permission_id)
SELECT
    r.id,
    p.id
FROM roles AS r
INNER JOIN permissions AS p
    ON p.slug IN (
        'doctors.view',
        'doctors.create',
        'doctors.update',
        'doctors.delete'
    )
WHERE r.slug = 'owner'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions AS rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );
