-- Add auditable, non-destructive duplicate review for resident pet profiles.
-- Back up the database before applying. Existing pet profiles are not changed.

USE dcvs;

ALTER TABLE pets
    ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL,
    ADD COLUMN IF NOT EXISTS microchip_id VARCHAR(50) NULL,
    ADD KEY idx_pets_duplicate_name (pet_type, pet_name),
    ADD KEY idx_pets_duplicate_microchip (microchip_id);

CREATE TABLE pet_duplicate_reviews (
    review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pet_id INT NOT NULL,
    candidate_pet_id INT NOT NULL,
    match_type ENUM('microchip', 'profile') NOT NULL,
    matched_fields VARCHAR(255) NOT NULL,
    status ENUM('pending', 'not_duplicate', 'linked') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pet_duplicate_review_pair (pet_id, candidate_pet_id),
    KEY idx_pet_duplicate_review_status (status, created_at),
    CONSTRAINT fk_pet_duplicate_review_pet FOREIGN KEY (pet_id) REFERENCES pets (pet_id) ON DELETE RESTRICT,
    CONSTRAINT fk_pet_duplicate_review_candidate FOREIGN KEY (candidate_pet_id) REFERENCES pets (pet_id) ON DELETE RESTRICT,
    CONSTRAINT fk_pet_duplicate_review_actor FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE pet_duplicate_review_log (
    review_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    action ENUM('not_duplicate', 'linked') NOT NULL,
    performed_by INT NULL,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pet_duplicate_review_log_review (review_id, created_at),
    CONSTRAINT fk_pet_duplicate_review_log_review FOREIGN KEY (review_id) REFERENCES pet_duplicate_reviews (review_id) ON DELETE RESTRICT,
    CONSTRAINT fk_pet_duplicate_review_log_actor FOREIGN KEY (performed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
