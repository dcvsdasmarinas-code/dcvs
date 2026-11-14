-- Add auditable, soft duplicate handling for reports.
-- Back up the database before applying. This migration does not remove or
-- rewrite existing reports.

ALTER TABLE reports
    ADD COLUMN duplicate_status ENUM('none', 'suspected', 'linked', 'merged') NOT NULL DEFAULT 'none' AFTER status,
    ADD COLUMN parent_report_id INT NULL AFTER duplicate_status,
    ADD COLUMN merged_by INT NULL AFTER parent_report_id,
    ADD COLUMN merged_at DATETIME NULL AFTER merged_by,
    ADD KEY idx_reports_duplicate_scope (duplicate_status, barangay_id, status, created_at),
    ADD KEY idx_reports_duplicate_parent (parent_report_id, duplicate_status),
    ADD CONSTRAINT fk_reports_duplicate_parent FOREIGN KEY (parent_report_id) REFERENCES reports (report_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_reports_merged_by FOREIGN KEY (merged_by) REFERENCES users (user_id) ON DELETE SET NULL;

CREATE TABLE report_duplicate_settings (
    setting_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    radius_meters SMALLINT UNSIGNED NOT NULL DEFAULT 150,
    window_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_report_duplicate_settings_actor FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO report_duplicate_settings (setting_id, radius_meters, window_days)
VALUES (1, 150, 7);

CREATE TABLE report_duplicate_statuses (
    report_status ENUM('pending', 'validated', 'rejected', 'confirmed', 'scheduled', 'resolved') NOT NULL PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO report_duplicate_statuses (report_status, is_enabled) VALUES
    ('pending', 1),
    ('validated', 1),
    ('rejected', 0),
    ('confirmed', 1),
    ('scheduled', 1),
    ('resolved', 1);

CREATE TABLE report_duplicate_log (
    duplicate_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    parent_report_id INT NULL,
    action ENUM('suspect', 'link', 'merge', 'not_duplicate') NOT NULL,
    performed_by INT NULL,
    performed_role ENUM('resident', 'staff', 'admin', 'barangay') NOT NULL,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_report_duplicate_log_report (report_id, created_at),
    KEY idx_report_duplicate_log_parent (parent_report_id, created_at),
    CONSTRAINT fk_report_duplicate_log_report FOREIGN KEY (report_id) REFERENCES reports (report_id) ON DELETE RESTRICT,
    CONSTRAINT fk_report_duplicate_log_parent FOREIGN KEY (parent_report_id) REFERENCES reports (report_id) ON DELETE SET NULL,
    CONSTRAINT fk_report_duplicate_log_actor FOREIGN KEY (performed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE report_submission_guards (
    guard_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    report_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_report_submission_guard (reporter_id, fingerprint),
    KEY idx_report_submission_guard_created (created_at),
    CONSTRAINT fk_report_submission_guard_reporter FOREIGN KEY (reporter_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_report_submission_guard_report FOREIGN KEY (report_id) REFERENCES reports (report_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER //
CREATE TRIGGER trg_reports_prevent_merged_status_change
BEFORE UPDATE ON reports
FOR EACH ROW
BEGIN
    IF OLD.duplicate_status = 'merged' AND NEW.status <> OLD.status THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Merged supporting reports cannot advance through the case workflow.';
    END IF;
END//

CREATE TRIGGER trg_reports_notify_merged_supporters
AFTER UPDATE ON reports
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO notifications
            (user_id, type, title, message, reference_type, reference_id)
        SELECT DISTINCT child.reporter_id,
            'report_response_update',
            'Update on a Supported Report',
            CONCAT('The status of the incident you supported changed to ', NEW.status, '.'),
            'report',
            NEW.report_id
        FROM reports child
        WHERE child.parent_report_id = NEW.report_id
          AND child.duplicate_status = 'merged'
          AND child.archived_at IS NULL
          AND child.reporter_id <> NEW.reporter_id;
    END IF;
END//
DELIMITER ;
