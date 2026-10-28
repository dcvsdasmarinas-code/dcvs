CREATE TABLE IF NOT EXISTS `appointment_reschedule_requests` (
    `request_id` INT NOT NULL AUTO_INCREMENT,
    `appointment_id` INT NOT NULL,
    `requested_date` DATE NOT NULL,
    `requested_time` TIME NOT NULL,
    `requested_urgency` ENUM('routine','urgent') NOT NULL DEFAULT 'routine',
    `resident_reason` TEXT DEFAULT NULL,
    `status` ENUM('pending','offered','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
    `staff_user_id` INT DEFAULT NULL,
    `staff_message` TEXT DEFAULT NULL,
    `decline_reason` TEXT DEFAULT NULL,
    `offered_date` DATE DEFAULT NULL,
    `offered_time` TIME DEFAULT NULL,
    `offered_urgency` ENUM('routine','urgent') DEFAULT NULL,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME DEFAULT NULL,
    `responded_at` DATETIME DEFAULT NULL,
    `active_appointment_id` INT GENERATED ALWAYS AS (
        CASE WHEN `status` IN ('pending','offered') THEN `appointment_id` ELSE NULL END
    ) STORED,
    PRIMARY KEY (`request_id`),
    UNIQUE KEY `uq_reschedule_active_appointment` (`active_appointment_id`),
    KEY `idx_reschedule_status_requested` (`status`,`requested_at`),
    CONSTRAINT `fk_reschedule_appointment` FOREIGN KEY (`appointment_id`)
        REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reschedule_staff_user` FOREIGN KEY (`staff_user_id`)
        REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
