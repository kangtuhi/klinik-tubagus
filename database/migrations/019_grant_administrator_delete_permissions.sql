-- ============================================================
-- PERMISSION DELETE UNTUK ADMINISTRATOR
-- Administrator adalah operator utama Klinik Tubagus sehingga
-- boleh menghapus data operasional dan user yang diperbolehkan.
--
-- Proteksi khusus akun Owner dan akun sendiri tetap dilakukan
-- di backend endpoint users/delete.php.
-- ============================================================

INSERT INTO role_permissions (role_id, permission_id)
SELECT
    r.id,
    p.id
FROM roles AS r
INNER JOIN permissions AS p
    ON p.slug IN (
        'users.delete',
        'patients.delete',
        'doctors.delete'
    )
WHERE r.slug = 'administrator'
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions AS rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );
