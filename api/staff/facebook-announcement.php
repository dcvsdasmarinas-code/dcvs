<?php

$sessionCookieName = isset($_COOKIE['DCVS_ADMIN_SESSION']) && !isset($_COOKIE['DCVS_STAFF_SESSION'])
    ? 'DCVS_ADMIN_SESSION'
    : 'DCVS_STAFF_SESSION';
session_name($sessionCookieName);
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/facebook-announcements.php';

header('Content-Type: application/json; charset=utf-8');

function dcvsFacebookEndpointRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    dcvsFacebookEndpointRespond(405, ['success' => false, 'message' => 'POST is required.']);
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])
    || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    dcvsFacebookEndpointRespond(403, ['success' => false, 'message' => 'Staff or admin access is required.']);
}

if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    dcvsFacebookEndpointRespond(403, ['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action === 'preview') {
        $captionFields = [
            'title' => $_POST['title'] ?? '',
            'location' => $_POST['location'] ?? '',
            'content' => $_POST['content'] ?? '',
            'link_url' => $_POST['link_url'] ?? '',
            'category' => $_POST['category'] ?? 'announcement',
            'subject' => $_POST['subject'] ?? 'general_notices',
        ];
        $caption = dcvsFacebookBuildCaption($captionFields);
        if (trim($caption) === '') {
            dcvsFacebookEndpointRespond(422, ['success' => false, 'message' => 'Add a title or announcement content to preview the caption.']);
        }
        dcvsFacebookEndpointRespond(200, ['success' => true, 'caption' => $caption]);
    }

    if (!in_array($action, ['post', 'retry', 'update'], true)) {
        dcvsFacebookEndpointRespond(400, ['success' => false, 'message' => 'Invalid Facebook action.']);
    }
    if (!dcvsFacebookTrackingTableReady($conn)) {
        dcvsFacebookEndpointRespond(503, [
            'success' => false,
            'message' => 'Facebook post tracking is not installed. Apply the Facebook social-post migration first.',
        ]);
    }
    $facebookConfig = dcvsFacebookConfig();
    if (!$facebookConfig['enabled']) {
        dcvsFacebookEndpointRespond(503, [
            'success' => false,
            'message' => 'Facebook posting is disabled (FB_ENABLED=false). Ask an admin to enable it before posting.',
        ]);
    }
    if ($facebookConfig['page_id'] === '') {
        dcvsFacebookEndpointRespond(503, ['success' => false, 'message' => 'Facebook Page is not configured. Ask an admin to configure it.']);
    }
    $facebookPageId = $facebookConfig['page_id'];

    $announcementId = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$announcementId || $announcementId < 1) {
        dcvsFacebookEndpointRespond(422, ['success' => false, 'message' => 'Invalid announcement.']);
    }

    $announcement = dcvsFacebookLoadAnnouncement($conn, (int)$announcementId);
    if (!$announcement) {
        dcvsFacebookEndpointRespond(404, ['success' => false, 'message' => 'Announcement not found.']);
    }
    if ((int)($announcement['facebook_opt_out'] ?? 0) === 1) {
        dcvsFacebookEndpointRespond(409, [
            'success' => false,
            'message' => 'This announcement is opted out of Facebook posting.',
        ]);
    }
    if ($announcement['status'] !== 'published'
        || !empty($announcement['archived_at'])
        || (!empty($announcement['publish_at']) && strtotime($announcement['publish_at']) > time())) {
        dcvsFacebookEndpointRespond(409, ['success' => false, 'message' => 'Only published announcements can be posted to Facebook.']);
    }

    if ($action === 'post' && !dcvsFacebookEnsureSocialPost($conn, (int)$announcementId, $facebookPageId)) {
        dcvsFacebookEndpointRespond(500, ['success' => false, 'message' => 'Could not prepare Facebook post tracking. Apply the Facebook social-post migration and try again.']);
    }
    $socialPost = dcvsFacebookLoadSocialPost($conn, (int)$announcementId, $facebookPageId);
    if (!$socialPost) {
        $message = $action === 'retry'
            ? 'There is no Facebook post to retry for this announcement.'
            : ($action === 'update'
                ? 'There is no Facebook post to update for this announcement.'
                : 'Could not load Facebook post tracking.');
        dcvsFacebookEndpointRespond($action === 'retry' ? 409 : 500, ['success' => false, 'message' => $message]);
    }
    dcvsFacebookRecoverStaleSocialPosts($conn);
    $socialPost = dcvsFacebookLoadSocialPost($conn, (int)$announcementId, $facebookPageId);
    if (!$socialPost) {
        dcvsFacebookEndpointRespond(500, ['success' => false, 'message' => 'Could not load Facebook post tracking.']);
    }
    if ($action === 'post' && $socialPost['status'] === 'skipped') {
        $reopen = $conn->prepare(
            "UPDATE announcement_social_posts
             SET status = 'pending', last_error = NULL, graph_error_code = NULL
             WHERE social_post_id = ? AND page_id = ? AND status = 'skipped'"
        );
        if (!$reopen) {
            dcvsFacebookEndpointRespond(500, ['success' => false, 'message' => 'Could not prepare the Facebook post for manual posting.']);
        }
        $reopen->bind_param('is', $socialPost['social_post_id'], $facebookPageId);
        if (!$reopen->execute()) {
            $reopen->close();
            dcvsFacebookEndpointRespond(500, ['success' => false, 'message' => 'Could not prepare the Facebook post for manual posting.']);
        }
        $reopen->close();
        $socialPost = dcvsFacebookLoadSocialPost($conn, (int)$announcementId, $facebookPageId);
    }

    if ($action === 'update') {
        if ($socialPost['status'] !== 'posted' || empty($socialPost['facebook_post_id'])) {
            error_log('[DCVS Facebook] Existing-post update refused for announcement ' . (int)$announcementId
                . ': status=' . preg_replace('/[^a-z_]/', '', (string)$socialPost['status'])
                . ', post ID=' . (!empty($socialPost['facebook_post_id']) ? 'present' : 'missing') . '.');
            dcvsFacebookEndpointRespond(409, [
                'success' => false,
                'message' => 'Only an existing Facebook post can be updated.',
            ]);
        }
        if (!dcvsFacebookClaimSocialPostUpdate($conn, (int)$socialPost['social_post_id'], $facebookPageId)) {
            dcvsFacebookEndpointRespond(409, [
                'success' => false,
                'message' => 'This Facebook post is already being updated. Please wait and try again.',
            ]);
        }
    } elseif ($action === 'post') {
        if ($socialPost['status'] === 'posted') {
            dcvsFacebookEndpointRespond(409, [
                'success' => false,
                'message' => 'This announcement is already posted to Facebook. No new post was sent.',
            ]);
        }
        if ($socialPost['status'] !== 'pending') {
            dcvsFacebookEndpointRespond(409, [
                'success' => false,
                'message' => $socialPost['status'] === 'failed'
                    ? 'This post failed previously. Use Retry to try again.'
                    : 'A Facebook post is already being processed. Refresh the page before trying again.',
            ]);
        }
    } elseif ($action === 'retry') {
        if ($socialPost['status'] !== 'failed') {
            dcvsFacebookEndpointRespond(409, ['success' => false, 'message' => 'Only failed Facebook posts can be retried.']);
        }
        if ($socialPost['graph_error_code'] === 'posting_timeout' && empty($_POST['confirm_uncertain'])) {
            dcvsFacebookEndpointRespond(409, [
                'success' => false,
                'requires_confirmation' => true,
                'warning' => 'Check the Facebook Page for an existing post before retrying.',
            ]);
        }
    }

    if ($action !== 'update'
        && !dcvsFacebookClaimSocialPost($conn, (int)$socialPost['social_post_id'], $facebookPageId)) {
        dcvsFacebookEndpointRespond(409, ['success' => false, 'message' => 'Another request is already processing this Facebook post.']);
    }

    $caption = dcvsFacebookBuildCaption($announcement);
    if (trim($caption) === '') {
        if ($action === 'update') {
            dcvsFacebookReleaseSocialPostUpdate($conn, (int)$socialPost['social_post_id'], $facebookPageId);
        } else {
            dcvsFacebookMarkSocialPostFailed($conn, (int)$socialPost['social_post_id'], $facebookPageId, 'Facebook post text is empty.', 'invalid_caption');
        }
        dcvsFacebookEndpointRespond(422, ['success' => false, 'message' => 'Add a title or announcement content before posting.']);
    }

    if ($action === 'update') {
        try {
            dcvsFacebookUpdatePost((string)$socialPost['facebook_post_id'], $caption);
        } catch (DcvsFacebookException $exception) {
            dcvsFacebookReleaseSocialPostUpdate($conn, (int)$socialPost['social_post_id'], $facebookPageId);
            error_log('[DCVS Facebook] Existing-post update failed for announcement ' . (int)$announcementId
                . ' (' . ($exception->getGraphErrorCode() ?? 'no_graph_code') . '): '
                . dcvsFacebookSanitizeDiagnosticMessage($exception->getMessage()));
            dcvsFacebookEndpointRespond(502, [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            dcvsFacebookReleaseSocialPostUpdate($conn, (int)$socialPost['social_post_id'], $facebookPageId);
            throw $exception;
        }
        if (!dcvsFacebookReleaseSocialPostUpdate($conn, (int)$socialPost['social_post_id'], $facebookPageId)) {
            error_log('[DCVS Facebook] Facebook post was updated but its update lock could not be released.');
        }
        error_log('[DCVS Facebook] Existing Facebook post updated successfully for announcement '
            . (int)$announcementId . '.');
        dcvsFacebookEndpointRespond(200, [
            'success' => true,
            'facebook_permalink' => $socialPost['facebook_permalink'],
            'caption' => $caption,
            'message' => 'Updated the existing Facebook post.',
        ]);
    }

    $posterPath = null;
    if (!empty($announcement['photo_url'])) {
        $posterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $announcement['photo_url']);
    }
    try {
        $result = dcvsFacebookPublish($caption, $posterPath);
    } catch (DcvsFacebookException $exception) {
        dcvsFacebookMarkSocialPostFailed(
            $conn,
            (int)$socialPost['social_post_id'],
            $facebookPageId,
            $exception->getMessage(),
            $exception->getGraphErrorCode()
        );
        dcvsFacebookEndpointRespond(502, [
            'success' => false,
            'message' => $exception->getMessage(),
            'uncertain' => $exception->getGraphErrorCode() === 'posting_timeout',
        ]);
    } catch (Throwable $exception) {
        error_log('[DCVS Facebook] Unexpected publish failure (' . get_class($exception) . '): '
            . dcvsFacebookSanitizeDiagnosticMessage($exception->getMessage()));
        dcvsFacebookMarkSocialPostFailed(
            $conn,
            (int)$socialPost['social_post_id'],
            $facebookPageId,
            'Facebook post outcome could not be confirmed. Check the Page before retrying.',
            'posting_timeout'
        );
        dcvsFacebookEndpointRespond(502, [
            'success' => false,
            'message' => 'Facebook post outcome could not be confirmed. Check the Page before retrying.',
            'uncertain' => true,
        ]);
    }

    if (!dcvsFacebookMarkSocialPostPosted(
        $conn,
        (int)$socialPost['social_post_id'],
        $facebookPageId,
        $result['facebook_post_id'],
        $result['facebook_permalink']
    )) {
        error_log('[DCVS Facebook] The Page accepted a post but its tracking row was not updated; post ID '
            . preg_replace('/[^0-9_]/', '', $result['facebook_post_id']) . '.');
        dcvsFacebookEndpointRespond(502, [
            'success' => false,
            'message' => 'Facebook may have received the post, but its status could not be saved. Check the Page before retrying.',
            'uncertain' => true,
        ]);
    }

    dcvsFacebookEndpointRespond(200, [
        'success' => true,
        'facebook_permalink' => $result['facebook_permalink'],
        'caption' => $caption,
        'message' => 'Posted to Facebook.',
    ]);
} catch (Throwable $exception) {
    error_log('[DCVS Facebook] Announcement endpoint failure (' . get_class($exception) . '): '
        . dcvsFacebookSanitizeDiagnosticMessage($exception->getMessage()));
    dcvsFacebookEndpointRespond(500, ['success' => false, 'message' => 'Could not process the Facebook request. Try again.']);
}

function dcvsFacebookEnsureSocialPost(mysqli $conn, int $announcementId, string $pageId): bool
{
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO announcement_social_posts (announcement_id, platform, page_id, status)
         VALUES (?, 'facebook', ?, 'pending')"
    );
    if (!$stmt) {
        error_log('[DCVS Facebook] Could not prepare tracking row insert: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('is', $announcementId, $pageId);
    $success = $stmt->execute();
    if (!$success) {
        error_log('[DCVS Facebook] Could not create tracking row: ' . $stmt->error);
    }
    $stmt->close();
    return $success;
}

function dcvsFacebookLoadSocialPost(mysqli $conn, int $announcementId, string $pageId): ?array
{
    $stmt = $conn->prepare(
        "SELECT social_post_id, page_id, status, facebook_post_id, graph_error_code, facebook_permalink
         FROM announcement_social_posts
         WHERE announcement_id = ? AND platform = 'facebook' AND page_id = ? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare Facebook status lookup: ' . $conn->error);
    }
    $stmt->bind_param('is', $announcementId, $pageId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not load Facebook status: ' . $error);
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
