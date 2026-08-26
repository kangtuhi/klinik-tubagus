-- ============================================================
-- PERMISSION MODUL KUNJUNGAN PASIEN
-- Menambahkan permission kunjungan dengan slug yang wajib diisi.
-- Permission dipisahkan agar akses rekam kunjungan dapat dikontrol
-- melalui RBAC tanpa mengubah permission modul pasien.
-- ============================================================

INSERT INTO permissions (name, slug, description)
VALUES
    ('visits.view', 'visits-view', 'Melihat riwayat dan detail kunjungan pasien'),
    ('visits.create', 'visits-create', 'Membuat kunjungan pasien baru'),
    ('visits.update', 'visits-update', 'Memperbarui data kunjungan pasien') AS incoming
ON DUPLICATE KEY UPDATE
    description = incoming.description,
    slug = incoming.slug;
