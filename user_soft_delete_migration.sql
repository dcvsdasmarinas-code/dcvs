ALTER TABLE users
  ADD COLUMN deleted_at DATETIME NULL AFTER archived_at,
  ADD KEY idx_users_deleted_at (deleted_at);
