-- Link a staff announcement to its source program and preserve multi-day event dates.
ALTER TABLE `announcements`
    ADD COLUMN IF NOT EXISTS `program_id` INT(11) DEFAULT NULL AFTER `barangay_id`,
    ADD COLUMN IF NOT EXISTS `event_end_date` DATE DEFAULT NULL AFTER `event_date`,
    ADD INDEX `idx_announcements_program_id` (`program_id`);
