-- Store the structured fields submitted by the current resident report form.
-- Existing reports remain NULL and continue using the legacy drawer display.
ALTER TABLE `reports`
  ADD COLUMN `animal_type` VARCHAR(50) NULL AFTER `report_type`,
  ADD COLUMN `animal_breed` VARCHAR(255) NULL AFTER `animal_type`,
  ADD COLUMN `animal_count` VARCHAR(20) NULL AFTER `animal_breed`,
  ADD COLUMN `animal_behavior` VARCHAR(100) NULL AFTER `animal_count`;
