-- Pet Resume Profile: Migration
-- Run this once to add missing columns and tables for the Pet Resume feature.

USE dcvs;

-- 1. Add missing columns to `pets` table
ALTER TABLE pets
  ADD COLUMN IF NOT EXISTS weight DECIMAL(5,2) NULL AFTER age,
  ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL AFTER color,
  ADD COLUMN IF NOT EXISTS sterilization_status ENUM('sterilized','unsterilized') DEFAULT 'unsterilized' AFTER vaccination_status,
  ADD COLUMN IF NOT EXISTS allergies TEXT NULL AFTER sterilization_status,
  ADD COLUMN IF NOT EXISTS current_medications TEXT NULL AFTER allergies,
  ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL AFTER updated_at;

-- 2. Create pet_documents table for vaccination certs, lab results, etc.
CREATE TABLE IF NOT EXISTS pet_documents (
    document_id   INT AUTO_INCREMENT PRIMARY KEY,
    pet_id        INT NOT NULL,
    document_type VARCHAR(50) NOT NULL COMMENT 'e.g. vaccination_cert, lab_result, clearance',
    file_name     VARCHAR(255) NOT NULL,
    file_url      VARCHAR(255) NOT NULL,
    description   TEXT NULL,
    uploaded_by   INT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pet_id) REFERENCES pets(pet_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
