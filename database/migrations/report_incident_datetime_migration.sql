-- Preserve the resident-provided incident date separately from submission time.
ALTER TABLE `reports`
  ADD COLUMN `incident_at` DATETIME NULL AFTER `description`;

-- Existing report created_at values were populated from the report form date.
UPDATE `reports`
SET `incident_at` = `created_at`
WHERE `incident_at` IS NULL;