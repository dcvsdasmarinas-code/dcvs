-- Run after response_workflow_migration.sql, response_workflow_decision_migration.sql,
-- and response_workflow_lifecycle_migration.sql.
-- Adds admin-configurable DCVS SLA values and timestamps for the current DCVS stage.

ALTER TABLE `report_response_workflow`
    ADD COLUMN IF NOT EXISTS `escalated_at` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `assigned_at` DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `report_overdue_sla_settings` (
    `setting_id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `high_sla_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    `medium_sla_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    `low_sla_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 72,
    `updated_by` INT(11) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_report_overdue_sla_settings_user`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `report_overdue_sla_settings`
    (`setting_id`, `high_sla_hours`, `medium_sla_hours`, `low_sla_hours`)
VALUES (1, 4, 24, 72);

-- Backfill only from recorded workflow stage changes, never from report submission time.
UPDATE `report_response_workflow` rw
JOIN `report_response_history` h
    ON h.report_id = rw.report_id
    AND h.to_state = rw.state
SET rw.escalated_at = h.created_at
WHERE rw.escalated_at IS NULL
  AND rw.state IN (
      'escalated_to_dcvs', 'dcvs_dispatch', 'dcvs_processing', 'turned_over'
  )
  AND h.created_at = (
      SELECT MAX(stage_history.created_at)
      FROM `report_response_history` stage_history
      WHERE stage_history.report_id = rw.report_id
        AND stage_history.to_state = rw.state
  );

UPDATE `report_response_workflow` rw
JOIN (
    SELECT h.report_id, MAX(h.created_at) AS assigned_time
    FROM `report_response_history` h
    WHERE h.action = 'staff_decide' AND h.to_state = 'dcvs_dispatch'
    GROUP BY h.report_id
) assignment_history ON assignment_history.report_id = rw.report_id
SET rw.assigned_at = assignment_history.assigned_time
WHERE rw.assigned_at IS NULL
  AND rw.dispatch_officer_id IS NOT NULL
  AND rw.state = 'dcvs_dispatch';
