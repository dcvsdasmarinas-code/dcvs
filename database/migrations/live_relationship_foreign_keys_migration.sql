-- Add missing foreign keys for established DCVS relationships.
-- Run only after confirming the target tables use InnoDB, column types match,
-- and no orphan references exist.
--
-- announcement_social_posts.announcement_id intentionally has no foreign key:
-- the publishing record must survive deletion of its local announcement.

ALTER TABLE `adoption_listings`
  ADD KEY `idx_adoption_listings_source` (`source_listing_id`),
  ADD CONSTRAINT `fk_adoption_listings_source`
    FOREIGN KEY (`source_listing_id`) REFERENCES `adoption_listings` (`listing_id`)
    ON DELETE SET NULL;

ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announcements_program`
    FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`)
    ON DELETE SET NULL;

ALTER TABLE `appointment_waitlist`
  ADD KEY `idx_waitlist_pet` (`pet_id`),
  ADD KEY `idx_waitlist_booked_appointment` (`booked_appointment_id`),
  ADD CONSTRAINT `fk_waitlist_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON DELETE CASCADE,
  ADD CONSTRAINT `fk_waitlist_pet`
    FOREIGN KEY (`pet_id`) REFERENCES `pets` (`pet_id`)
    ON DELETE CASCADE,
  ADD CONSTRAINT `fk_waitlist_booked_appointment`
    FOREIGN KEY (`booked_appointment_id`) REFERENCES `appointments` (`appointment_id`)
    ON DELETE SET NULL;

ALTER TABLE `pet_listings`
  ADD KEY `idx_pet_listings_rescue_intake` (`rescue_intake_id`),
  ADD CONSTRAINT `fk_pet_listings_rescue_intake`
    FOREIGN KEY (`rescue_intake_id`) REFERENCES `rescue_intakes` (`intake_id`)
    ON DELETE SET NULL;

ALTER TABLE `report_attachments`
  ADD KEY `idx_report_attachments_response_history` (`response_history_id`),
  ADD KEY `idx_report_attachments_uploaded_by` (`uploaded_by`),
  ADD CONSTRAINT `fk_report_attachments_response_history`
    FOREIGN KEY (`response_history_id`) REFERENCES `report_response_history` (`history_id`)
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_report_attachments_uploader`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

ALTER TABLE `report_response_history`
  ADD KEY `idx_response_history_actor` (`actor_id`),
  ADD CONSTRAINT `fk_response_history_actor`
    FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

ALTER TABLE `report_response_workflow`
  ADD KEY `idx_response_workflow_approved_by` (`approved_by`),
  ADD KEY `idx_response_workflow_turnover_received_by` (`turnover_received_by`),
  ADD KEY `idx_response_workflow_high_risk_override_by` (`high_risk_override_by`),
  ADD KEY `idx_response_workflow_dispatch_officer` (`dispatch_officer_id`),
  ADD CONSTRAINT `fk_response_workflow_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_response_workflow_turnover_received_by`
    FOREIGN KEY (`turnover_received_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_response_workflow_high_risk_override_by`
    FOREIGN KEY (`high_risk_override_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_response_workflow_dispatch_officer`
    FOREIGN KEY (`dispatch_officer_id`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

ALTER TABLE `rescue_intakes`
  ADD CONSTRAINT `fk_rescue_intakes_barangay`
    FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`barangay_id`)
    ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rescue_intakes_recorder`
    FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `email_logs`
  ADD CONSTRAINT `fk_email_logs_sender`
    FOREIGN KEY (`sent_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `email_queue`
  ADD KEY `idx_email_queue_sent_by` (`sent_by`),
  ADD CONSTRAINT `fk_email_queue_sender`
    FOREIGN KEY (`sent_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

ALTER TABLE `sms_log`
  ADD KEY `idx_sms_log_sent_by` (`sent_by`),
  ADD CONSTRAINT `fk_sms_log_sender`
    FOREIGN KEY (`sent_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

ALTER TABLE `sms_queue`
  ADD KEY `idx_sms_queue_sent_by` (`sent_by`),
  ADD CONSTRAINT `fk_sms_queue_sender`
    FOREIGN KEY (`sent_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

ALTER TABLE `pet_listings`
  ADD KEY `idx_pet_listings_created_by` (`created_by`),
  ADD CONSTRAINT `fk_pet_listings_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);
