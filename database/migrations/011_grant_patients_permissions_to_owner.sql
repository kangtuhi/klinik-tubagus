-- ============================================================
-- PERMISSION MODUL PASIEN UNTUK OWNER
-- Memberikan seluruh permission utama modul pasien kepada role Owner.
-- Permission dicari berdasarkan nama permission agar konsisten
-- dengan mekanisme Auth::can() pada aplikasi.
-- ============================================================

INSERT INTO role_permissions (role_id, permission_id)
SELECT
    r.id,
    p.id
FROM roles r
INNER JOIN permissions p
    ON p.name IN (
        'patients.view',
        'patients.create',
        'patients.update'
    )
WHERE r.name = 'Owner'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );
