ALTER TABLE appointments
    MODIFY COLUMN status ENUM(
        'pending',
        'approved',
        'scheduled',
        'rescheduled',
        'completed',
        'no_show',
        'rejected',
        'cancelled',
        'waitlisted',
        'in_progress'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS scheduled_date DATE NULL AFTER preferred_time,
    ADD COLUMN IF NOT EXISTS scheduled_time TIME NULL AFTER scheduled_date,
    ADD COLUMN IF NOT EXISTS cancelled_by ENUM('resident', 'staff') NULL AFTER status;

UPDATE appointments
SET scheduled_date = preferred_date,
    scheduled_time = preferred_time
WHERE status IN ('approved', 'scheduled', 'rescheduled', 'in_progress')
  AND scheduled_date IS NULL;
