-- Report video attachment migration
-- Adds a video attachment path next to the existing photo attachment on reports.
-- Run once against the configured DCVS database.

ALTER TABLE `reports`
    ADD COLUMN `video_url` VARCHAR(255) NULL AFTER `photo_url`;
