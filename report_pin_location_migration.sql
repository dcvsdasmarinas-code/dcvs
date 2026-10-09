-- DCVS PSGC canonical barangays and incident-location metadata.
-- Back up the database before applying. Existing barangay/report/user rows are
-- never deleted, renamed, merged, or reassigned by this migration.
-- Exact, unique official-name matches retain their existing barangay_id.
-- Unmatched official barangays receive new rows; unmatched old rows remain
-- without a PSGC code and are treated as legacy/unmapped records.

ALTER TABLE barangays
    ADD COLUMN psgc_code CHAR(10) NULL,
    ADD COLUMN psgc_mapping_approved TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_barangays_psgc_code (psgc_code);

CREATE TEMPORARY TABLE dcvs_official_barangay_seed (
    psgc_code CHAR(10) NOT NULL PRIMARY KEY,
    barangay_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO dcvs_official_barangay_seed (psgc_code, barangay_name) VALUES
('0402106003', 'Burol'),
('0402106005', 'Langkaan I'),
('0402106008', 'Paliparan I'),
('0402106010', 'Sabang'),
('0402106011', 'Salawag'),
('0402106012', 'Salitran I'),
('0402106013', 'Sampaloc I'),
('0402106014', 'San Agustin I'),
('0402106015', 'San Jose'),
('0402106016', 'Zone I'),
('0402106017', 'Zone II'),
('0402106018', 'Zone III'),
('0402106019', 'Zone IV'),
('0402106020', 'Datu Esmael'),
('0402106021', 'Emmanuel Bergado I'),
('0402106022', 'Fatima I'),
('0402106023', 'Luzviminda I'),
('0402106024', 'Saint Peter I'),
('0402106025', 'San Andres I'),
('0402106026', 'San Antonio De Padua I'),
('0402106027', 'San Dionisio'),
('0402106028', 'San Esteban'),
('0402106029', 'San Francisco I'),
('0402106030', 'San Isidro Labrador I'),
('0402106031', 'San Juan'),
('0402106032', 'San Lorenzo Ruiz I'),
('0402106033', 'San Luis I'),
('0402106034', 'San Manuel I'),
('0402106035', 'San Mateo'),
('0402106036', 'San Miguel'),
('0402106037', 'San Nicolas I'),
('0402106038', 'San Roque'),
('0402106039', 'San Simon'),
('0402106040', 'Santa Cristina I'),
('0402106041', 'Santa Cruz I'),
('0402106042', 'Santa Fe'),
('0402106043', 'Santa Lucia'),
('0402106044', 'Santa Maria'),
('0402106045', 'Santo Cristo'),
('0402106046', 'Santo Niño I'),
('0402106047', 'Burol I'),
('0402106048', 'Burol II'),
('0402106049', 'Burol III'),
('0402106050', 'Emmanuel Bergado II'),
('0402106051', 'Fatima II'),
('0402106052', 'Fatima III'),
('0402106053', 'Langkaan II'),
('0402106054', 'Luzviminda II'),
('0402106055', 'Paliparan II'),
('0402106056', 'Paliparan III'),
('0402106057', 'Saint Peter II'),
('0402106058', 'Salitran II'),
('0402106059', 'Salitran III'),
('0402106060', 'Salitran IV'),
('0402106061', 'Sampaloc II'),
('0402106062', 'Sampaloc III'),
('0402106063', 'Sampaloc IV'),
('0402106064', 'Sampaloc V'),
('0402106065', 'San Agustin II'),
('0402106066', 'San Agustin III'),
('0402106067', 'San Andres II'),
('0402106068', 'San Antonio De Padua II'),
('0402106069', 'San Francisco II'),
('0402106070', 'San Isidro Labrador II'),
('0402106071', 'San Lorenzo Ruiz II'),
('0402106072', 'San Luis II'),
('0402106073', 'San Manuel II'),
('0402106074', 'San Miguel II'),
('0402106075', 'San Nicolas II'),
('0402106076', 'Santa Cristina II'),
('0402106077', 'Santa Cruz II'),
('0402106078', 'Santo Niño II'),
('0402106079', 'Zone I-B'),
('0402106080', 'H-2'),
('0402106081', 'Victoria Reyes');

UPDATE barangays AS existing
JOIN dcvs_official_barangay_seed AS official
  ON LOWER(TRIM(existing.barangay_name)) = LOWER(TRIM(official.barangay_name))
SET existing.psgc_code = official.psgc_code,
    existing.psgc_mapping_approved = 1;

INSERT INTO barangays
    (barangay_name, district, coordinates_lat, coordinates_long, boundary_polygon, psgc_code, psgc_mapping_approved)
SELECT official.barangay_name, 'Dasmariñas', NULL, NULL, NULL, official.psgc_code, 1
FROM dcvs_official_barangay_seed AS official
LEFT JOIN barangays AS existing
  ON LOWER(TRIM(existing.barangay_name)) = LOWER(TRIM(official.barangay_name))
WHERE existing.barangay_id IS NULL;

DROP TEMPORARY TABLE dcvs_official_barangay_seed;

CREATE TABLE landmarks (
    landmark_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    category ENUM('barangay_hall', 'school', 'chapel', 'market', 'subdivision', 'hospital', 'park', 'other') NOT NULL DEFAULT 'other',
    barangay_id INT NULL,
    coordinates_lat DECIMAL(10, 8) NOT NULL,
    coordinates_long DECIMAL(11, 8) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_landmarks_barangay_active (barangay_id, is_active),
    CONSTRAINT fk_landmarks_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (barangay_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE reports
    ADD COLUMN location_status ENUM('legacy_none', 'pinned', 'unpinned_fallback') NOT NULL DEFAULT 'legacy_none',
    ADD COLUMN unpinned_reason VARCHAR(500) NULL,
    ADD COLUMN pin_source ENUM('resident', 'gps_assisted', 'staff') NULL,
    ADD COLUMN landmark_id INT NULL,
    ADD COLUMN location_verified TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN location_verified_by INT NULL,
    ADD COLUMN location_verified_at DATETIME NULL,
    ADD KEY idx_reports_coordinates (coordinates_lat, coordinates_long),
    ADD KEY idx_reports_landmark (landmark_id),
    ADD CONSTRAINT fk_reports_landmark FOREIGN KEY (landmark_id) REFERENCES landmarks (landmark_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_reports_location_verified_by FOREIGN KEY (location_verified_by) REFERENCES users (user_id) ON DELETE SET NULL;

CREATE TABLE report_location_history (
    history_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    old_coordinates_lat DECIMAL(10, 8) NULL,
    old_coordinates_long DECIMAL(11, 8) NULL,
    new_coordinates_lat DECIMAL(10, 8) NULL,
    new_coordinates_long DECIMAL(11, 8) NULL,
    changed_by INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_report_location_history_report (report_id, changed_at),
    CONSTRAINT fk_report_location_history_report FOREIGN KEY (report_id) REFERENCES reports (report_id) ON DELETE CASCADE,
    CONSTRAINT fk_report_location_history_actor FOREIGN KEY (changed_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- DCVS_REPORT_PIN_FEATURE_ENABLED defaults to 0. Keep it disabled until the
-- boundary geometry is reviewed and pin submission/API/map layers are complete.
