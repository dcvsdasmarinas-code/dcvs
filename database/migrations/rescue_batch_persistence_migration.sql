ALTER TABLE `rescue_intakes`
  ADD COLUMN `batch_id` VARCHAR(64) NULL AFTER `intake_id`,
  ADD COLUMN `batch_details` LONGTEXT NULL AFTER `notes`,
  ADD COLUMN `batch_details_updated_at` DATETIME NULL AFTER `batch_details`,
  ADD UNIQUE KEY `uq_rescue_intakes_batch_id` (`batch_id`);
