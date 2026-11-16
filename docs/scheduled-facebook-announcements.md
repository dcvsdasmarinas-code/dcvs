# Scheduled Facebook Announcements

The CLI worker publishes due scheduled announcements and posts eligible announcements to the configured Facebook Page. Page requests do not publish scheduled announcements; the worker is the single scheduled-publishing path. If Facebook posting is disabled, the worker still publishes due announcements on the site and leaves queued Facebook posts pending.

## Hostinger Cron Job

In hPanel, add a cron job that runs every minute (`* * * * *`). Use the PHP CLI binary selected for the site and the site's absolute document-root path, for example:

```text
php /home/ACCOUNT/domains/DOMAIN/public_html/workers/facebook-announcement-publisher.php
```

Replace `ACCOUNT`, `DOMAIN`, and `public_html` with the actual values shown for the Hostinger site. Do not put the Page access token in the cron command. The worker loads the existing application environment configuration.

The worker is CLI-only and must not be exposed as a web endpoint. When it runs, it checks for due scheduled announcements, queues Facebook posts for announcements that have not opted out, publishes the announcements on the site, and processes pending Facebook posts. Existing due scheduled announcements are switched to automatic posting unless Facebook posting was explicitly opted out.

## Deployment Requirements

- Apply the announcement social-post migrations, including the automatic-posting and Page-scoped tracking migrations.
- Configure `FB_ENABLED=true`, the Facebook Page ID, and a valid Page access token in the production environment.
- Ensure the PHP CLI worker and the web application use the same production environment and database.
- Keep the database/server timezone consistent with the timezone used when staff schedule announcements.
- Confirm the cron job's last-run output in Hostinger after scheduling a test announcement. A Facebook API failure is recorded in the announcement's Facebook status and can be retried from the staff announcements page.
