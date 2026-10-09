ALTER TABLE notifications
  ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER is_read,
  ADD KEY idx_notifications_user_archive_read (user_id, archived_at, is_read);
