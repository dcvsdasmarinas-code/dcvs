ALTER TABLE `report_response_workflow`
    ADD COLUMN IF NOT EXISTS `high_risk_override_by` INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `high_risk_override_at` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `high_risk_override_reason` TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `dispatch_officer_id` INT(11) DEFAULT NULL;
