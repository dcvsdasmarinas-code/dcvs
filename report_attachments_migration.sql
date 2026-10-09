-- Run once against an existing DCVS database to support multiple report photos and videos.
-- Existing photo_url and video_url values are preserved and copied into the new table.

CREATE TABLE IF NOT EXISTS `report_attachments` (
    `attachment_id` INT(11) NOT NULL AUTO_INCREMENT,
    `report_id` INT(11) NOT NULL,
    `attachment_type` ENUM('photo', 'video') NOT NULL,
    `file_url` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `display_order` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attachment_id`),
    UNIQUE KEY `uq_report_attachment` (`report_id`, `attachment_type`, `file_url`),
    KEY `idx_report_attachments_order` (`report_id`, `display_order`, `attachment_id`),
    CONSTRAINT `fk_report_attachments_report`
        FOREIGN KEY (`report_id`) REFERENCES `reports` (`report_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `report_attachments`
    (`report_id`, `attachment_type`, `file_url`, `mime_type`, `display_order`)
SELECT `report_id`, 'photo', `photo_url`,
       CASE LOWER(SUBSTRING_INDEX(`photo_url`, '.', -1))
           WHEN 'jpg' THEN 'image/jpeg'
           WHEN 'jpeg' THEN 'image/jpeg'
           WHEN 'png' THEN 'image/png'
           WHEN 'gif' THEN 'image/gif'
           WHEN 'webp' THEN 'image/webp'
           ELSE 'application/octet-stream'
       END,
       0
FROM `reports`
WHERE `photo_url` IS NOT NULL AND `photo_url` <> '';

INSERT IGNORE INTO `report_attachments`
    (`report_id`, `attachment_type`, `file_url`, `mime_type`, `display_order`)
SELECT `report_id`, 'video', `video_url`,
       CASE LOWER(SUBSTRING_INDEX(`video_url`, '.', -1))
           WHEN 'mp4' THEN 'video/mp4'
           WHEN 'webm' THEN 'video/webm'
           WHEN 'ogv' THEN 'video/ogg'
           WHEN 'ogg' THEN 'video/ogg'
           WHEN 'm4v' THEN 'video/x-m4v'
           WHEN 'mov' THEN 'video/quicktime'
           ELSE 'application/octet-stream'
       END,
       1
FROM `reports`
WHERE `video_url` IS NOT NULL AND `video_url` <> '';
