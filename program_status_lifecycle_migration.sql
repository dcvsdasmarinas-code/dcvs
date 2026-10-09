-- Allow all program lifecycle states used by the staff portal.
ALTER TABLE `programs`
    MODIFY `status` ENUM('scheduled', 'live', 'paused', 'completed', 'ended', 'cancelled', 'archived') NOT NULL DEFAULT 'scheduled';
