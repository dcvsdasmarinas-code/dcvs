-- Run once after response_workflow_migration.sql.
-- Retain the share flag for compatibility; resident report views expose staff/barangay case photos.
ALTER TABLE `report_attachments`
    MODIFY COLUMN `attachment_source` ENUM(
        'resident',
        'barangay_validation',
        'barangay_secured',
        'barangay_turnover',
        'barangay_action',
        'staff_action'
    ) NOT NULL DEFAULT 'resident',
    ADD COLUMN `caption` VARCHAR(180) DEFAULT NULL AFTER `mime_type`,
    ADD COLUMN `shared_with_resident` TINYINT(1) NOT NULL DEFAULT 0 AFTER `caption`,
    ADD COLUMN `uploaded_by` INT(11) DEFAULT NULL AFTER `shared_with_resident`,
    ADD COLUMN `response_history_id` BIGINT(20) DEFAULT NULL AFTER `uploaded_by`;
