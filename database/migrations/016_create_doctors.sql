-- ============================================================
-- PEMBUATAN TABEL DOKTER
-- Menyimpan identitas profesional dokter yang bekerja di klinik.
-- Data dokter dipisahkan dari users karena dokter dan akun login
-- merupakan dua konsep berbeda dalam sistem Klinik Tubagus.
-- ============================================================

CREATE TABLE IF NOT EXISTS doctors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    sip_number VARCHAR(100) NULL,
    str_number VARCHAR(100) NULL,
    specialty VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(191) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_doctors_sip_number (sip_number),
    UNIQUE KEY uq_doctors_str_number (str_number),
    KEY idx_doctors_full_name (full_name),
    KEY idx_doctors_specialty (specialty),
    KEY idx_doctors_status (status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
