-- Optional MariaDB alternative to scripts/expire_reservations.php.
-- Enable the event scheduler (SET GLOBAL event_scheduler = ON) and run as a
-- database user with EVENT privilege.
DELIMITER //
CREATE EVENT IF NOT EXISTS `expire_adoption_reservations`
ON SCHEDULE EVERY 1 MINUTE
DO
BEGIN
  UPDATE `adoption_applications`
  SET `status` = 'declined', `decline_reason` = 'expired'
  WHERE `resulting_pet_id` IS NULL
    AND ((`status` = 'reserved' AND `response_deadline` IS NOT NULL AND `response_deadline` <= NOW())
      OR (`status` = 'confirmed' AND `pickup_deadline` IS NOT NULL AND `pickup_deadline` <= NOW()));

  UPDATE `adoption_applications`
  SET `status` = 'expired', `info_deadline` = NULL
  WHERE `status` = 'needs_info' AND `info_deadline` IS NOT NULL AND `info_deadline` <= NOW();

  UPDATE `pet_listings` pl
  SET `status` = 'available', `updated_at` = NOW()
  WHERE pl.`status` = 'reserved'
    AND NOT EXISTS (
      SELECT 1 FROM `adoption_applications` aa
      WHERE aa.`pet_listing_id` = pl.`listing_id`
        AND (aa.`status` IN ('pending','under_review','needs_info','reserved','confirmed')
          OR (aa.`status` = 'approved' AND aa.`resulting_pet_id` IS NULL))
    );
END//
DELIMITER ;
