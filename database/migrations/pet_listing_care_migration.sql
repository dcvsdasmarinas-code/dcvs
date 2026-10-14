-- Persist health and temperament details for public adoption listings.
ALTER TABLE `pet_listings`
  ADD COLUMN IF NOT EXISTS `vaccination_status` ENUM('vaccinated','partially_vaccinated','not_vaccinated','unknown') NOT NULL DEFAULT 'unknown',
  ADD COLUMN IF NOT EXISTS `sterilization_status` ENUM('spayed','neutered','not_sterilized','unknown') NOT NULL DEFAULT 'unknown',
  ADD COLUMN IF NOT EXISTS `deworming_status` ENUM('dewormed','not_dewormed','unknown') NOT NULL DEFAULT 'unknown',
  ADD COLUMN IF NOT EXISTS `vaccinations` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `temperament` VARCHAR(255) DEFAULT NULL;
