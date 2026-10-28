-- Add the missing user-to-barangay and remember-token-to-user relationships.
-- Precondition: both tables use InnoDB, ID columns have matching integer types,
-- and the orphan checks below return zero for both relationships.
--
-- Deleting a barangay keeps its users and clears their barangay assignment.
-- Deleting a user removes dependent remember tokens.

ALTER TABLE `users`
  ADD KEY `idx_users_barangay_id` (`barangay_id`),
  ADD CONSTRAINT `fk_users_barangay`
    FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`)
    ON DELETE SET NULL;

ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `fk_remember_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON DELETE CASCADE;
