ALTER TABLE `appointments`
    MODIFY COLUMN `status` ENUM(
        'pending',
        'approved',
        'scheduled',
        'rescheduled',
        'completed',
        'no_show',
        'rejected',
        'cancelled',
        'waitlisted',
        'in_progress',
        'action_needed'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS `original_schedule_date` DATE DEFAULT NULL AFTER `scheduled_time`,
    ADD COLUMN IF NOT EXISTS `original_schedule_time` TIME DEFAULT NULL AFTER `original_schedule_date`,
    ADD COLUMN IF NOT EXISTS `reschedule_response_deadline` DATETIME DEFAULT NULL AFTER `original_schedule_time`,
    ADD COLUMN IF NOT EXISTS `reschedule_offered_at` DATETIME DEFAULT NULL AFTER `reschedule_response_deadline`,
    ADD COLUMN IF NOT EXISTS `reschedule_responded_at` DATETIME DEFAULT NULL AFTER `reschedule_offered_at`,
    ADD COLUMN IF NOT EXISTS `cancellation_reason` TEXT DEFAULT NULL,
    ADD KEY IF NOT EXISTS `idx_appointment_reschedule_deadline` (`status`, `reschedule_response_deadline`);
