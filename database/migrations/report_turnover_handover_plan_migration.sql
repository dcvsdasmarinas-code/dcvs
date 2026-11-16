-- Run after response_workflow_lifecycle_migration.sql.
-- Record the barangay's delivery or DCVS pickup plan for each pending live-animal turnover.

ALTER TABLE `report_turnover_tasks`
    ADD COLUMN IF NOT EXISTS `handover_method` ENUM('barangay_delivery', 'dcvs_pickup') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `planned_at` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `handler_name` VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `contact_number` VARCHAR(40) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `pickup_location` VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `safe_transport` TINYINT(1) NOT NULL DEFAULT 0;
