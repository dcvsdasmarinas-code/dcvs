-- Accepted Barangay assignments are not site-confirmed Active Cases.
-- Move legacy assignments back to validated until a Barangay records a confirmed site visit.
START TRANSACTION;

DELETE cc
FROM confirmed_cases cc
JOIN reports r ON r.report_id = cc.report_id
JOIN report_response_workflow rw ON rw.report_id = r.report_id
WHERE r.status = 'confirmed'
  AND rw.response_mode = 'barangay_response'
  AND rw.state = 'barangay_response_approved';

UPDATE reports r
JOIN report_response_workflow rw ON rw.report_id = r.report_id
SET r.status = 'validated',
    r.confirmed_by = NULL,
    r.confirmed_at = NULL
WHERE r.status = 'confirmed'
  AND rw.response_mode = 'barangay_response'
  AND rw.state = 'barangay_response_approved';

COMMIT;
