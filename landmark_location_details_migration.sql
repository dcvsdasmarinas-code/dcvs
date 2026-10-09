-- Store office addresses and location verification state for route origins.

ALTER TABLE landmarks
    ADD COLUMN address VARCHAR(500) NULL AFTER name,
    ADD COLUMN location_accuracy ENUM('unverified', 'approximate', 'verified') NOT NULL DEFAULT 'unverified' AFTER coordinates_long,
    ADD COLUMN location_notes VARCHAR(500) NULL AFTER location_accuracy;
