-- Tracks Facebook publishing, retries, and published post IDs.
-- No foreign key is intentional: deleting a local announcement must not
-- cascade-delete the record of a Facebook post that already exists.
CREATE TABLE `announcement_social_posts` (
    `social_post_id` INT(11) NOT NULL AUTO_INCREMENT,
    `announcement_id` INT(11) NOT NULL,
    `platform` ENUM('facebook') NOT NULL DEFAULT 'facebook',
    `status` ENUM('pending', 'posting', 'posted', 'failed') NOT NULL DEFAULT 'pending',
    `facebook_post_id` VARCHAR(100) DEFAULT NULL,
    `facebook_permalink` VARCHAR(500) DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `graph_error_code` VARCHAR(40) DEFAULT NULL,
    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `posting_started_at` DATETIME DEFAULT NULL,
    `posted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`social_post_id`),
    UNIQUE KEY `uq_announcement_social_posts_announcement_platform` (`announcement_id`, `platform`),
    UNIQUE KEY `uq_announcement_social_posts_facebook_post` (`facebook_post_id`),
    KEY `idx_announcement_social_posts_status_updated` (`platform`, `status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
