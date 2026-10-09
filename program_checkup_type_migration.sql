ALTER TABLE `programs`
  MODIFY `program_type` ENUM('kapon', 'vaccination', 'checkup', 'deworming', 'adoption', 'rabies_prevention', 'microchipping', 'other') NOT NULL DEFAULT 'kapon';