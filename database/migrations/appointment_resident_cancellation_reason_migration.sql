ALTER TABLE `appointments`
    ADD COLUMN IF NOT EXISTS `cancellation_reason` TEXT NULL AFTER `cancelled_by`;
