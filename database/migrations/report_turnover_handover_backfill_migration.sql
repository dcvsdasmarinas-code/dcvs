-- Run after response_workflow_lifecycle_migration.sql and
-- report_turnover_handover_plan_migration.sql.
-- Resolved live-animal cases need a turnover task even if the earlier optional
-- turnover checkbox was not selected.

UPDATE report_response_resolutions rr
JOIN reports r ON r.report_id = rr.report_id
SET rr.turnover_required = 1
WHERE r.status = 'resolved'
  AND LOWER(r.report_type) NOT LIKE '%dead%'
  AND rr.animal_disposition IN ('rescued', 'under_veterinary_care', 'other');

INSERT INTO report_turnover_tasks
    (report_id, status, animal_count, holding_location, animal_disposition,
     task_notes, due_at, requested_by, requested_at)
SELECT r.report_id, 'pending', r.animal_count, w.holding_location,
       rr.animal_disposition, rr.resolution_details,
       DATE_ADD(NOW(), INTERVAL 24 HOUR), rr.resolved_by,
       COALESCE(rr.resolved_at, NOW())
FROM reports r
JOIN report_response_workflow w ON w.report_id = r.report_id AND w.state = 'resolved'
JOIN report_response_resolutions rr ON rr.report_id = r.report_id
LEFT JOIN report_turnover_tasks t ON t.report_id = r.report_id
WHERE r.status = 'resolved'
  AND LOWER(r.report_type) NOT LIKE '%dead%'
  AND rr.animal_disposition IN ('rescued', 'under_veterinary_care', 'other')
  AND t.turnover_task_id IS NULL;
