ALTER TABLE `adoption_applications`
  MODIFY COLUMN `status` enum('pending','under_review','needs_info','reserved','confirmed','approved','adopted','rejected','declined','withdrawn','expired') NOT NULL DEFAULT 'pending',
  ADD COLUMN `reserved_at` datetime DEFAULT NULL AFTER `approved_pickup_at`,
  ADD COLUMN `response_deadline` datetime DEFAULT NULL AFTER `reserved_at`,
  ADD COLUMN `confirmed_at` datetime DEFAULT NULL AFTER `response_deadline`,
  ADD COLUMN `pickup_deadline` datetime DEFAULT NULL AFTER `confirmed_at`,
  ADD COLUMN `decline_reason` varchar(255) DEFAULT NULL AFTER `pickup_deadline`,
  ADD KEY `idx_adoption_app_response_deadline` (`status`,`response_deadline`),
  ADD KEY `idx_adoption_app_pickup_deadline` (`status`,`pickup_deadline`);

UPDATE `adoption_applications`
SET `status` = 'reserved',
    `reserved_at` = COALESCE(`reviewed_at`, `application_date`),
    `response_deadline` = DATE_ADD(COALESCE(`reviewed_at`, `application_date`), INTERVAL 3 DAY)
WHERE `status` = 'approved'
  AND `resulting_pet_id` IS NULL;

UPDATE `adoption_applications`
SET `status` = 'declined', `decline_reason` = 'expired'
WHERE `resulting_pet_id` IS NULL
  AND ((`status` = 'reserved' AND `response_deadline` IS NOT NULL AND `response_deadline` <= NOW())
    OR (`status` = 'confirmed' AND `pickup_deadline` IS NOT NULL AND `pickup_deadline` <= NOW()));

UPDATE `pet_listings` pl
SET `status` = 'available', `updated_at` = NOW()
WHERE pl.`status` = 'reserved'
  AND NOT EXISTS (
    SELECT 1 FROM `adoption_applications` aa
    WHERE aa.`pet_listing_id` = pl.`listing_id`
      AND (aa.`status` IN ('pending','under_review','needs_info','reserved','confirmed')
        OR (aa.`status` = 'approved' AND aa.`resulting_pet_id` IS NULL))
  );
