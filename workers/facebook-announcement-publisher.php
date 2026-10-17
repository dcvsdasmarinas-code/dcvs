<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/facebook-announcements.php';

function facebookWorkerLog(string $message): void
{
    fwrite(STDERR, '[DCVS Facebook worker] ' . $message . PHP_EOL);
}

try {
    if (!dcvsFacebookTrackingTableReady($conn)) {
        throw new RuntimeException('Facebook post tracking is not installed. Apply the Facebook posting migrations.');
    }
    $config = dcvsFacebookConfig();
    if (!$config['enabled']) {
        throw new RuntimeException('Facebook posting is disabled (FB_ENABLED=false). Pending posts were left unchanged.');
    }
    if ($config['page_id'] === '') {
        throw new RuntimeException('Facebook Page is not configured.');
    }
    dcvsFacebookAssertDestinationAllowed($config);
    dcvsFacebookRecoverStaleSocialPosts($conn);

    $publishDue = $conn->prepare(
        "UPDATE announcements
         SET status = 'published', updated_at = NOW()
         WHERE status = 'scheduled' AND publish_at IS NOT NULL
           AND publish_at <= NOW() AND archived_at IS NULL"
    );
    if (!$publishDue || !$publishDue->execute()) {
        $error = $publishDue ? $publishDue->error : $conn->error;
        throw new RuntimeException('Could not publish scheduled announcements: ' . $error);
    }
    $publishDue->close();

    $pending = $conn->prepare(
        "SELECT sp.social_post_id, sp.announcement_id
         FROM announcement_social_posts sp
         JOIN announcements a ON a.announcement_id = sp.announcement_id
         WHERE sp.platform = 'facebook' AND sp.page_id = ?
           AND sp.status = 'pending'
           AND a.facebook_posting_mode = 'automatic'
           AND a.facebook_opt_out = 0
           AND a.status = 'published' AND a.archived_at IS NULL
           AND (a.publish_at IS NULL OR a.publish_at <= NOW())
         ORDER BY sp.created_at, sp.social_post_id
         LIMIT 50"
    );
    if (!$pending) {
        throw new RuntimeException('Could not prepare pending Facebook post lookup: ' . $conn->error);
    }
    $pending->bind_param('s', $config['page_id']);
    if (!$pending->execute()) {
        $error = $pending->error;
        $pending->close();
        throw new RuntimeException('Could not load pending Facebook posts: ' . $error);
    }
    $result = $pending->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'social_post_id' => (int)$row['social_post_id'],
            'announcement_id' => (int)$row['announcement_id'],
        ];
    }
    $pending->close();

    $posted = 0;
    $failed = 0;
    foreach ($rows as $row) {
        if (!dcvsFacebookClaimSocialPost($conn, $row['social_post_id'], $config['page_id'])) {
            continue;
        }

        try {
            $announcement = dcvsFacebookLoadAnnouncement($conn, $row['announcement_id']);
            if ($announcement && (int)($announcement['facebook_opt_out'] ?? 0) === 1) {
                $skip = $conn->prepare(
                    "UPDATE announcement_social_posts
                     SET status = 'skipped', posting_started_at = NULL
                     WHERE social_post_id = ? AND page_id = ? AND status = 'posting'"
                );
                if (!$skip) {
                    throw new RuntimeException('Could not skip an opted-out Facebook post: ' . $conn->error);
                }
                $skip->bind_param('is', $row['social_post_id'], $config['page_id']);
                if (!$skip->execute()) {
                    $error = $skip->error;
                    $skip->close();
                    throw new RuntimeException('Could not skip an opted-out Facebook post: ' . $error);
                }
                $skip->close();
                continue;
            }
            if (!$announcement || ($announcement['facebook_posting_mode'] ?? '') !== 'automatic'
                || $announcement['status'] !== 'published'
                || !empty($announcement['archived_at'])
                || (!empty($announcement['publish_at']) && strtotime($announcement['publish_at']) > time())) {
                $reset = $conn->prepare(
                    "UPDATE announcement_social_posts
                     SET status = 'pending', posting_started_at = NULL
                     WHERE social_post_id = ? AND page_id = ? AND status = 'posting'"
                );
                if (!$reset) {
                    throw new RuntimeException('Could not release an ineligible Facebook post claim: ' . $conn->error);
                }
                $reset->bind_param('is', $row['social_post_id'], $config['page_id']);
                if (!$reset->execute()) {
                    $error = $reset->error;
                    $reset->close();
                    throw new RuntimeException('Could not release an ineligible Facebook post claim: ' . $error);
                }
                $reset->close();
                continue;
            }

            $caption = dcvsFacebookBuildCaption($announcement);
            if (trim($caption) === '') {
                dcvsFacebookMarkSocialPostFailed(
                    $conn,
                    $row['social_post_id'],
                    $config['page_id'],
                    'Facebook post text is empty.',
                    'invalid_caption'
                );
                $failed++;
                continue;
            }
            $posterPath = null;
            if (!empty($announcement['photo_url'])) {
                $posterPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
                    . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $announcement['photo_url']);
            }
            $publication = dcvsFacebookPublish($caption, $posterPath);
            if (!dcvsFacebookMarkSocialPostPosted(
                $conn,
                $row['social_post_id'],
                $config['page_id'],
                $publication['facebook_post_id'],
                $publication['facebook_permalink']
            )) {
                throw new RuntimeException('Facebook accepted the post, but its tracking row could not be updated.');
            }
            $posted++;
        } catch (DcvsFacebookException $exception) {
            dcvsFacebookMarkSocialPostFailed(
                $conn,
                $row['social_post_id'],
                $config['page_id'],
                $exception->getMessage(),
                $exception->getGraphErrorCode()
            );
            $failed++;
            facebookWorkerLog('Announcement ' . $row['announcement_id'] . ': ' . $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[DCVS Facebook worker] Unexpected publish failure (' . get_class($exception) . '): '
                . dcvsFacebookSanitizeDiagnosticMessage($exception->getMessage()));
            dcvsFacebookMarkSocialPostFailed(
                $conn,
                $row['social_post_id'],
                $config['page_id'],
                'Facebook post outcome could not be confirmed. Check the Page before retrying.',
                'posting_timeout'
            );
            $failed++;
        }
    }

    fwrite(STDOUT, 'Facebook worker finished: ' . $posted . ' posted, ' . $failed . ' failed.' . PHP_EOL);
} catch (Throwable $exception) {
    facebookWorkerLog($exception->getMessage());
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
