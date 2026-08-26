-- ============================================================
-- PERMISSION MODUL KUNJUNGAN PASIEN
-- Permission dipisahkan agar akses rekam kunjungan dapat dikontrol
-- melalui RBAC tanpa mengubah permission modul pasien.
-- ============================================================

INSERT IGNORE INTO permissions (name, description)
VALUES
    ('visits.view', 'Melihat riwayat dan detail kunjungan pasien'),
    ('visits.create', 'Membuat kunjungan pasien baru'),
    ('visits.update', 'Memperbarui data kunjungan pasien');
