-- ============================================================
-- MELENGKAPI PERMISSION WORKFLOW KUNJUNGAN
-- Owner dan Administrator menjadi operator utama kunjungan.
-- Histori kunjungan tidak menyediakan permission hard delete.
-- ============================================================

INSERT INTO permissions (name, slug, description)
VALUES
    ('View Visits', 'visits.view', 'Melihat riwayat kunjungan pasien'),
    ('Create Visits', 'visits.create', 'Membuat kunjungan pasien'),
    ('Update Visits', 'visits.update', 'Mengubah data kunjungan pasien')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description);

-- ============================================================
-- BERIKAN AKSES VISIT KEPADA OWNER DAN ADMINISTRATOR
-- Keduanya bertanggung jawab atas operasional klinik.
-- ============================================================

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles AS r
INNER JOIN permissions AS p
    ON p.slug IN (
        'visits.view',
        'visits.create',
        'visits.update'
    )
WHERE r.slug IN ('owner', 'administrator')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions AS rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );
