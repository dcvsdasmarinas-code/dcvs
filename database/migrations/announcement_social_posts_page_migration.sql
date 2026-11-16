-- Scope Facebook post records to the Page where the post was published.
-- Legacy records remain visible in the database but do not match a configured Page.
ALTER TABLE `announcement_social_posts`
    ADD COLUMN `page_id` VARCHAR(64) NOT NULL DEFAULT 'legacy-unknown' AFTER `platform`,
    DROP INDEX `uq_announcement_social_posts_announcement_platform`,
    ADD UNIQUE KEY `uq_announcement_social_posts_announcement_platform_page`
        (`announcement_id`, `platform`, `page_id`);
