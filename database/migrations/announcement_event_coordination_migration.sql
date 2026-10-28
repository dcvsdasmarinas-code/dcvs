-- Adds staff-to-barangay event scheduling and preparedness responses.
-- Run once against the DCVS database before deploying the event UI.

ALTER TABLE `announcements`
    ADD COLUMN `is_event` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`,
    ADD COLUMN `event_date` DATE DEFAULT NULL AFTER `is_event`,
    ADD COLUMN `event_start_time` TIME DEFAULT NULL AFTER `event_date`,
    ADD COLUMN `event_end_time` TIME DEFAULT NULL AFTER `event_start_time`,
    ADD COLUMN `venue_provider` ENUM('dcvs_office', 'barangay_venue', 'other') DEFAULT NULL AFTER `event_end_time`,
    ADD COLUMN `venue_name` VARCHAR(200) DEFAULT NULL AFTER `venue_provider`,
    ADD COLUMN `response_deadline` DATETIME DEFAULT NULL AFTER `venue_name`;

CREATE TABLE `announcement_event_barangays` (
    `announcement_id` INT(11) NOT NULL,
    `barangay_id` INT(11) NOT NULL,
    PRIMARY KEY (`announcement_id`, `barangay_id`),
    KEY `idx_announcement_event_barangays_barangay` (`barangay_id`, `announcement_id`),
    CONSTRAINT `fk_announcement_event_barangays_announcement`
        FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_announcement_event_barangays_barangay`
        FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcement_event_requirements` (
    `requirement_id` INT(11) NOT NULL AUTO_INCREMENT,
    `announcement_id` INT(11) NOT NULL,
    `requirement_label` VARCHAR(200) NOT NULL,
    `quantity` INT(11) DEFAULT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`requirement_id`),
    KEY `idx_announcement_event_requirements_announcement` (`announcement_id`, `sort_order`),
    CONSTRAINT `fk_announcement_event_requirements_announcement`
        FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`announcement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcement_event_responses` (
    `announcement_id` INT(11) NOT NULL,
    `barangay_id` INT(11) NOT NULL,
    `status` ENUM('pending', 'confirmed', 'changes_requested') NOT NULL DEFAULT 'pending',
    `response_note` TEXT DEFAULT NULL,
    `responded_by` INT(11) DEFAULT NULL,
    `responded_at` DATETIME DEFAULT NULL,
    `notification_sent_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`announcement_id`, `barangay_id`),
    KEY `idx_announcement_event_responses_barangay` (`barangay_id`, `status`),
    CONSTRAINT `fk_announcement_event_responses_target`
        FOREIGN KEY (`announcement_id`, `barangay_id`)
        REFERENCES `announcement_event_barangays` (`announcement_id`, `barangay_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_announcement_event_responses_user`
        FOREIGN KEY (`responded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcement_event_requirement_responses` (
    `announcement_id` INT(11) NOT NULL,
    `barangay_id` INT(11) NOT NULL,
    `requirement_id` INT(11) NOT NULL,
    `is_ready` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`announcement_id`, `barangay_id`, `requirement_id`),
    CONSTRAINT `fk_announcement_event_requirement_responses_response`
        FOREIGN KEY (`announcement_id`, `barangay_id`)
        REFERENCES `announcement_event_responses` (`announcement_id`, `barangay_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_announcement_event_requirement_responses_requirement`
        FOREIGN KEY (`requirement_id`) REFERENCES `announcement_event_requirements` (`requirement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
