ALTER TABLE `adoption_applications`
  MODIFY COLUMN `status` enum('pending','under_review','needs_info','approved','adopted','rejected','withdrawn','expired') NOT NULL DEFAULT 'pending',
  ADD COLUMN `approved_pickup_at` datetime DEFAULT NULL AFTER `reviewed_at`,
  ADD COLUMN `finalized_by` int(11) DEFAULT NULL AFTER `approved_pickup_at`,
  ADD COLUMN `finalized_at` datetime DEFAULT NULL AFTER `finalized_by`,
  ADD KEY `idx_adoption_app_status_pickup` (`status`,`approved_pickup_at`),
  ADD CONSTRAINT `fk_adoption_app_finalizer` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
