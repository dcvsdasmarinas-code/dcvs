-- Run once after barangay_report_validation_migration.sql to record cases
-- assigned before the Barangay team has visited the incident location.

ALTER TABLE `reports`
    MODIFY COLUMN `barangay_validation_status` ENUM(
        'site_visit_pending',
        'confirmed_on_site',
        'urgent_response',
        'animal_already_gone',
        'wrong_or_unclear_location'
    ) NULL;
