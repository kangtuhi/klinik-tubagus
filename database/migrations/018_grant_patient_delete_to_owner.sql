-- ============================================================
-- PERMISSION HAPUS PASIEN UNTUK OWNER
-- Penghapusan permanen pasien dibatasi hanya untuk Owner.
-- Administrator tetap dapat mengelola pasien melalui view/create/
-- update, tetapi tidak mendapatkan patients.delete.
-- ============================================================

INSERT INTO role_permissions (role_id, permission_id)
SELECT
    r.id,
    p.id
FROM roles AS r
INNER JOIN permissions AS p
    ON p.slug = 'patients.delete'
WHERE r.slug = 'owner'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions AS rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

-- ============================================================
-- CABUT DELETE PASIEN DARI ADMINISTRATOR
-- Migration ini menyelaraskan database lama dengan kebijakan RBAC
-- bahwa delete permanen adalah kewenangan Owner.
-- ============================================================

DELETE rp
FROM role_permissions AS rp
INNER JOIN roles AS r
    ON r.id = rp.role_id
INNER JOIN permissions AS p
    ON p.id = rp.permission_id
WHERE r.slug = 'administrator'
  AND p.slug = 'patients.delete';
