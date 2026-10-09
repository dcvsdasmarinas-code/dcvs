-- Extend the response workflow with progress tracking and separate resolution/turnover records.
-- The report status remains authoritative for the incident; the turnover task has its own status.

ALTER TABLE `report_response_workflow`
    ADD COLUMN IF NOT EXISTS `progress_deadline` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `last_progress_at` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `urgent_escalated_at` DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `report_response_resolutions` (
    `report_id` INT(11) NOT NULL,
    `resolution_details` TEXT NOT NULL,
    `animal_disposition` VARCHAR(100) NOT NULL,
    `turnover_required` TINYINT(1) NOT NULL DEFAULT 0,
    `resolved_by` INT(11) NOT NULL,
    `resolved_at` DATETIME NOT NULL,
    PRIMARY KEY (`report_id`),
    CONSTRAINT `fk_response_resolution_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_response_resolution_user`
        FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `report_turnover_tasks` (
    `turnover_task_id` INT(11) NOT NULL AUTO_INCREMENT,
    `report_id` INT(11) NOT NULL,
    `status` ENUM('pending', 'receipt_confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
    `animal_count` VARCHAR(20) DEFAULT NULL,
    `holding_location` VARCHAR(500) DEFAULT NULL,
    `animal_disposition` VARCHAR(100) NOT NULL,
    `task_notes` TEXT DEFAULT NULL,
    `due_at` DATETIME DEFAULT NULL,
    `requested_by` INT(11) NOT NULL,
    `requested_at` DATETIME NOT NULL,
    `last_reminded_at` DATETIME DEFAULT NULL,
    `received_by` INT(11) DEFAULT NULL,
    `received_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`turnover_task_id`),
    UNIQUE KEY `uq_report_turnover_task` (`report_id`),
    KEY `idx_turnover_status_requested` (`status`, `requested_at`),
    CONSTRAINT `fk_report_turnover_task_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_report_turnover_task_requester`
        FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
    CONSTRAINT `fk_report_turnover_task_receiver`
        FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `report_correction_history` (
    `correction_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `report_id` INT(11) NOT NULL,
    `actor_id` INT(11) NOT NULL,
    `reason` TEXT NOT NULL,
    `before_values` MEDIUMTEXT NOT NULL,
    `after_values` MEDIUMTEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`correction_id`),
    KEY `idx_report_correction_history` (`report_id`, `created_at`),
    CONSTRAINT `fk_report_correction_history_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_report_correction_history_actor`
        FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
