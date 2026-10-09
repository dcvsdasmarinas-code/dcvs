-- Run once against an existing DCVS database to store barangay field validation details.
-- Apply report_attachments_migration.sql first if the report_attachments table does not exist.

ALTER TABLE `reports`
    ADD COLUMN `barangay_validation_status` ENUM(
        'confirmed_on_site',
        'site_visit_pending',
        'urgent_response',
        'animal_already_gone',
        'wrong_or_unclear_location'
    ) NULL AFTER `validated_at`,
    ADD COLUMN `barangay_validation_notes` VARCHAR(500) NULL AFTER `barangay_validation_status`;

ALTER TABLE `report_attachments`
    ADD COLUMN `attachment_source` ENUM('resident', 'barangay_validation') NOT NULL DEFAULT 'resident'
        AFTER `attachment_type`;
