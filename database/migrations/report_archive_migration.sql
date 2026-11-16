ALTER TABLE reports
    ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER updated_at,
    ADD INDEX idx_reports_barangay_archived (barangay_id, archived_at);
