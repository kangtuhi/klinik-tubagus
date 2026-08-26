CREATE TABLE IF NOT EXISTS patients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medical_record_number VARCHAR(30) NOT NULL,
    nik VARCHAR(20) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    birth_place VARCHAR(100) NULL,
    birth_date DATE NOT NULL,
    address TEXT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(191) NULL,
    blood_type ENUM('A', 'B', 'AB', 'O', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    marital_status ENUM('single', 'married', 'divorced', 'widowed') NULL,
    occupation VARCHAR(100) NULL,
    emergency_contact_name VARCHAR(150) NULL,
    emergency_contact_phone VARCHAR(30) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_patients_medical_record_number (medical_record_number),
    UNIQUE KEY uq_patients_nik (nik),
    KEY idx_patients_full_name (full_name),
    KEY idx_patients_phone (phone),
    KEY idx_patients_status (status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
