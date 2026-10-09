-- Programs table migration
-- Stores city drives / veterinary programs created by staff

CREATE TABLE IF NOT EXISTS `programs` (
    `program_id` INT AUTO_INCREMENT PRIMARY KEY,
    `program_name` VARCHAR(255) NOT NULL,
    `program_type` ENUM('kapon', 'vaccination', 'checkup', 'deworming', 'adoption', 'rabies_prevention', 'microchipping', 'other') NOT NULL DEFAULT 'kapon',
    `description` TEXT,
    `status` ENUM('scheduled', 'live', 'paused', 'completed', 'ended', 'cancelled', 'archived') NOT NULL DEFAULT 'scheduled',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `program_start_time` TIME DEFAULT NULL,
    `program_end_time` TIME DEFAULT NULL,
    `staff_required_count` INT NOT NULL DEFAULT 1,
    `operating_time` VARCHAR(100) DEFAULT NULL,
    `location_mode` ENUM('single', 'multiple') NOT NULL DEFAULT 'single',
    `venue` VARCHAR(255) DEFAULT NULL,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_dates` (`start_date`, `end_date`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Program-to-Barangay linking table (for multiple barangay coverage)
CREATE TABLE IF NOT EXISTS `program_barangays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `program_id` INT NOT NULL,
    `barangay_id` INT NOT NULL,
    UNIQUE KEY `unique_prog_brgy` (`program_id`, `barangay_id`),
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`program_id`) ON DELETE CASCADE,
    FOREIGN KEY (`barangay_id`) REFERENCES `barangays`(`barangay_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
