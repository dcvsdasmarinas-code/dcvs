-- Add time-based program staffing and resident appointment urgency.
ALTER TABLE `programs`
    ADD COLUMN IF NOT EXISTS `program_start_time` TIME DEFAULT NULL AFTER `end_date`,
    ADD COLUMN IF NOT EXISTS `program_end_time` TIME DEFAULT NULL AFTER `program_start_time`,
    ADD COLUMN IF NOT EXISTS `staff_required_count` INT NOT NULL DEFAULT 1 AFTER `program_end_time`,
    MODIFY `status` ENUM('scheduled', 'live', 'paused', 'completed', 'ended', 'cancelled', 'archived') NOT NULL DEFAULT 'scheduled';

ALTER TABLE `appointments`
    ADD COLUMN IF NOT EXISTS `urgency_level` ENUM('routine', 'urgent') NOT NULL DEFAULT 'routine' AFTER `status`;

CREATE TABLE IF NOT EXISTS `appointment_waitlist` (
    `waitlist_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `pet_id` INT NOT NULL,
    `appointment_type` ENUM('adoption', 'kapon', 'vaccination', 'checkup', 'deworming') NOT NULL,
    `urgency_level` ENUM('routine', 'urgent') NOT NULL DEFAULT 'routine',
    `preferred_date` DATE NOT NULL,
    `preferred_time` TIME DEFAULT NULL,
    `status` ENUM('waiting', 'notified', 'booked', 'expired', 'cancelled') NOT NULL DEFAULT 'waiting',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified_at` DATETIME DEFAULT NULL,
    `claim_expires_at` DATETIME DEFAULT NULL,
    `booked_appointment_id` INT DEFAULT NULL,
    INDEX `idx_waitlist_slot` (`preferred_date`, `preferred_time`, `urgency_level`, `status`),
    INDEX `idx_waitlist_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `appointment_waitlist`
    ADD COLUMN IF NOT EXISTS `claim_expires_at` DATETIME DEFAULT NULL AFTER `notified_at`,
    ADD COLUMN IF NOT EXISTS `booked_appointment_id` INT DEFAULT NULL AFTER `claim_expires_at`,
    MODIFY `status` ENUM('waiting', 'notified', 'withdrawn', 'booked', 'expired', 'cancelled') NOT NULL DEFAULT 'waiting';

UPDATE `appointment_waitlist` SET `status` = 'cancelled' WHERE `status` = 'withdrawn';

ALTER TABLE `appointment_waitlist`
    MODIFY `status` ENUM('waiting', 'notified', 'booked', 'expired', 'cancelled') NOT NULL DEFAULT 'waiting';
