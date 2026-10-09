CREATE TABLE IF NOT EXISTS `sms_queue` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `recipient_phone` VARCHAR(20) NOT NULL,
  `recipient_name` VARCHAR(100) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `sent_by` INT DEFAULT NULL,
  `sent_to_role` VARCHAR(20) DEFAULT NULL,
  `message_type` VARCHAR(50) DEFAULT NULL,
  `sms_api_id` VARCHAR(100) DEFAULT NULL,
  `worker_token` CHAR(32) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sms_queue_status_id` (`status`, `id`),
  KEY `idx_sms_queue_worker_token` (`worker_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
