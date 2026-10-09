-- Run once to add the guarded barangay-response and turnover workflow.
-- Keep DCVS_BARANGAY_RESPONSE_ENABLED=0 until all portal actions are deployed.
-- On databases where this migration was already applied, also run response_workflow_decision_migration.sql.

CREATE TABLE IF NOT EXISTS `report_response_workflow` (
    `report_id` INT(11) NOT NULL,
    `response_mode` ENUM('undecided', 'dcvs_dispatch', 'barangay_response') NOT NULL DEFAULT 'undecided',
    `state` ENUM(
        'awaiting_dcvs_decision',
        'dcvs_dispatch',
        'barangay_response_approved',
        'barangay_responding',
        'animal_secured',
        'awaiting_turnover',
        'turned_over',
        'dcvs_processing',
        'resolved',
        'unable_to_locate',
        'escalated_to_dcvs',
        'returned_to_barangay',
        'closed_not_actionable'
    ) NOT NULL DEFAULT 'awaiting_dcvs_decision',
    `barangay_proposed` TINYINT(1) NOT NULL DEFAULT 0,
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `response_deadline` DATETIME DEFAULT NULL,
    `turnover_deadline` DATETIME DEFAULT NULL,
    `secured_at` DATETIME DEFAULT NULL,
    `holding_location` VARCHAR(500) DEFAULT NULL,
    `animal_condition` VARCHAR(1000) DEFAULT NULL,
    `turnover_requested_at` DATETIME DEFAULT NULL,
    `turnover_received_at` DATETIME DEFAULT NULL,
    `turnover_received_by` INT(11) DEFAULT NULL,
    `dcvs_pickup_requested` TINYINT(1) NOT NULL DEFAULT 0,
    `high_risk_override_by` INT(11) DEFAULT NULL,
    `high_risk_override_at` DATETIME DEFAULT NULL,
    `high_risk_override_reason` TEXT DEFAULT NULL,
    `dispatch_officer_id` INT(11) DEFAULT NULL,
    `last_reminded_at` DATETIME DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`report_id`),
    KEY `idx_response_state_deadline` (`state`, `response_deadline`, `turnover_deadline`),
    CONSTRAINT `fk_response_workflow_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `report_response_history` (
    `history_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `report_id` INT(11) NOT NULL,
    `actor_id` INT(11) NOT NULL,
    `actor_role` ENUM('staff', 'barangay', 'resident', 'system') NOT NULL,
    `action` VARCHAR(60) NOT NULL,
    `from_state` VARCHAR(40) DEFAULT NULL,
    `to_state` VARCHAR(40) NOT NULL,
    `reason` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`history_id`),
    KEY `idx_response_history_report` (`report_id`, `created_at`),
    CONSTRAINT `fk_response_history_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `report_attachments`
    MODIFY COLUMN `attachment_source` ENUM(
        'resident',
        'barangay_validation',
        'barangay_secured',
        'barangay_turnover'
    ) NOT NULL DEFAULT 'resident';
