-- Existing announcements are explicitly manual-only at rollout.
-- New announcements can opt out; automatic posting is otherwise the default.
ALTER TABLE `announcements`
    ADD COLUMN `facebook_posting_mode` ENUM('manual', 'automatic')
        NOT NULL DEFAULT 'manual'
        AFTER `updated_at`,
    ADD COLUMN `facebook_opt_out` TINYINT(1) NOT NULL DEFAULT 0
        AFTER `facebook_posting_mode`;

ALTER TABLE `announcement_social_posts`
    MODIFY COLUMN `status` ENUM('pending', 'posting', 'posted', 'failed', 'skipped')
        NOT NULL DEFAULT 'pending',
    ADD KEY `idx_announcement_social_posts_page_status_created`
        (`platform`, `page_id`, `status`, `created_at`);
