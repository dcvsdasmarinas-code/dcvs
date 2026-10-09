ALTER TABLE `email_queue`
  ADD COLUMN `worker_token` CHAR(32) DEFAULT NULL AFTER `portal_delivered`,
  ADD KEY `idx_email_queue_worker_token` (`worker_token`);