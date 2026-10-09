-- Rescue Intakes table
CREATE TABLE IF NOT EXISTS rescue_intakes (
    intake_id INT(11) NOT NULL AUTO_INCREMENT,
    rescue_date DATE NOT NULL,
    source ENUM('barangay_reported','resident_surrender','stray_roundup','shelter_transfer','confiscated','found_lost','field_operation') NOT NULL DEFAULT 'barangay_reported',
    barangay_id INT(11) NULL,
    animal_count INT(11) NOT NULL DEFAULT 1,
    notes TEXT,
    recorded_by INT(11) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (intake_id),
    KEY idx_barangay (barangay_id),
    KEY idx_recorded_by (recorded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE rescue_intakes
    MODIFY source ENUM('barangay_reported','resident_surrender','stray_roundup','shelter_transfer','confiscated','found_lost','field_operation') NOT NULL DEFAULT 'barangay_reported',
    MODIFY barangay_id INT(11) NULL;

-- Add rescue_intake_id to pet_listings to link animals back to their intake
ALTER TABLE pet_listings ADD COLUMN rescue_intake_id INT(11) NULL AFTER description;
