INSERT INTO roles (name, slug, description) VALUES
    ('Owner', 'owner', 'Pemilik sistem dengan akses tertinggi'),
    ('Administrator', 'administrator', 'Administrator operasional sistem'),
    ('Dokter', 'doctor', 'Tenaga medis dokter'),
    ('Perawat', 'nurse', 'Tenaga medis perawat'),
    ('Farmasi', 'pharmacy', 'Petugas farmasi dan obat'),
    ('Kasir', 'cashier', 'Petugas pembayaran dan kasir'),
    ('Pendaftaran', 'registration', 'Petugas pendaftaran pasien')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description);
