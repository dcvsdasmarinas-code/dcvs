-- Resident ID verification and reviewed adoption applications.

ALTER TABLE `users`
  ADD COLUMN `government_id_verified_at` datetime DEFAULT NULL AFTER `terms_agreed_at`;

CREATE TABLE `adoption_identity_verifications` (
  `verification_id` int(11) NOT NULL AUTO_INCREMENT,
  `resident_id` int(11) NOT NULL,
  `government_id_file` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_notes` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`verification_id`),
  KEY `idx_adoption_idv_resident_status` (`resident_id`,`status`),
  KEY `idx_adoption_idv_status_submitted` (`status`,`submitted_at`),
  CONSTRAINT `fk_adoption_idv_resident` FOREIGN KEY (`resident_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adoption_idv_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

UPDATE `adoption_applications`
SET `status` = 'pending'
WHERE `status` IS NULL;

ALTER TABLE `adoption_applications`
  MODIFY COLUMN `listing_id` int(11) DEFAULT NULL,
  MODIFY COLUMN `status` enum('pending','under_review','needs_info','approved','rejected','withdrawn','expired') NOT NULL DEFAULT 'pending',
  ADD COLUMN `pet_listing_id` int(11) DEFAULT NULL AFTER `listing_id`,
  ADD COLUMN `identity_verification_id` int(11) DEFAULT NULL AFTER `applicant_id`,
  ADD COLUMN `date_of_birth` date DEFAULT NULL AFTER `application_date`,
  ADD COLUMN `housing_type` enum('owned','rented') DEFAULT NULL AFTER `date_of_birth`,
  ADD COLUMN `landlord_permission` tinyint(1) DEFAULT NULL AFTER `housing_type`,
  ADD COLUMN `home_type` varchar(100) DEFAULT NULL AFTER `landlord_permission`,
  ADD COLUMN `has_fenced_yard` tinyint(1) NOT NULL DEFAULT 0 AFTER `home_type`,
  ADD COLUMN `household_members` smallint(5) unsigned DEFAULT NULL AFTER `has_fenced_yard`,
  ADD COLUMN `has_other_pets` tinyint(1) NOT NULL DEFAULT 0 AFTER `household_members`,
  ADD COLUMN `other_pets_details` text DEFAULT NULL AFTER `has_other_pets`,
  ADD COLUMN `pet_care_experience` text DEFAULT NULL AFTER `other_pets_details`,
  ADD COLUMN `reason_for_adoption` text DEFAULT NULL AFTER `pet_care_experience`,
  ADD COLUMN `residency_proof_file` varchar(100) DEFAULT NULL AFTER `reason_for_adoption`,
  ADD COLUMN `agreement_version` varchar(50) DEFAULT NULL AFTER `residency_proof_file`,
  ADD COLUMN `agreed_at` datetime DEFAULT NULL AFTER `agreement_version`,
  ADD COLUMN `requested_info` text DEFAULT NULL AFTER `notes`,
  ADD COLUMN `info_deadline` datetime DEFAULT NULL AFTER `requested_info`,
  ADD COLUMN `resident_response` text DEFAULT NULL AFTER `info_deadline`,
  ADD COLUMN `resident_response_at` datetime DEFAULT NULL AFTER `resident_response`,
  ADD COLUMN `rejection_reason` text DEFAULT NULL AFTER `resident_response_at`,
  ADD COLUMN `interview_at` datetime DEFAULT NULL AFTER `rejection_reason`,
  ADD COLUMN `home_visit_at` datetime DEFAULT NULL AFTER `interview_at`,
  ADD COLUMN `home_visit_notes` text DEFAULT NULL AFTER `home_visit_at`,
  ADD COLUMN `reviewed_by` int(11) DEFAULT NULL AFTER `home_visit_notes`,
  ADD COLUMN `reviewed_at` datetime DEFAULT NULL AFTER `reviewed_by`,
  ADD COLUMN `resulting_pet_id` int(11) DEFAULT NULL AFTER `reviewed_at`,
  ADD KEY `idx_adoption_app_pet_listing_status` (`pet_listing_id`,`status`),
  ADD KEY `idx_adoption_applicant_status` (`applicant_id`,`status`),
  ADD KEY `idx_adoption_app_expired_info` (`status`,`info_deadline`),
  ADD CONSTRAINT `fk_adoption_app_pet_listing` FOREIGN KEY (`pet_listing_id`) REFERENCES `pet_listings` (`listing_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_adoption_app_identity_verification` FOREIGN KEY (`identity_verification_id`) REFERENCES `adoption_identity_verifications` (`verification_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_adoption_app_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_adoption_app_resulting_pet` FOREIGN KEY (`resulting_pet_id`) REFERENCES `pets` (`pet_id`) ON DELETE SET NULL;
