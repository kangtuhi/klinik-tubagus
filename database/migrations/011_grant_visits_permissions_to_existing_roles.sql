-- ============================================================
-- AKSES MODUL KUNJUNGAN UNTUK ROLE KLINIS
-- Role dokter/perawat yang sudah ada dapat melihat kunjungan.
-- Pembuatan dan perubahan tetap diberikan secara eksplisit agar
-- kebijakan akses tidak terlalu longgar.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('doctor', 'dokter', 'nurse', 'perawat')
  AND p.name = 'visits.view';
