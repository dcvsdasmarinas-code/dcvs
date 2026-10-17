<?php

require_once __DIR__ . '/../env.php';
loadDcvsEnv();

if (!class_exists('DcvsFacebookException')) {
    class DcvsFacebookException extends RuntimeException
    {
        private $graphErrorCode;

        public function __construct(string $message, ?string $graphErrorCode = null)
        {
            parent::__construct($message);
            $this->graphErrorCode = $graphErrorCode;
        }

        public function getGraphErrorCode(): ?string
        {
            return $this->graphErrorCode;
        }
    }
}

if (!function_exists('dcvsFacebookConfig')) {
    function dcvsFacebookConfig(): array
    {
        $enabled = filter_var(env('FB_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $pageId = trim((string)env('FB_PAGE_ID', ''));
        $pageToken = trim((string)env('FB_PAGE_ACCESS_TOKEN', ''));
        $graphVersion = trim((string)env('FB_GRAPH_VERSION', 'v26.0'));
        $testPageId = trim((string)env('FB_TEST_PAGE_ID', ''));
        $appEnvironment = strtolower(trim((string)env('APP_ENV', 'development')));

        if (!preg_match('/^v[0-9]+\.[0-9]+$/', $graphVersion)) {
            throw new DcvsFacebookException('Facebook Graph API version is not configured correctly.');
        }
        if ($enabled && ($pageId === '' || !preg_match('/^[0-9]+$/', $pageId) || $pageToken === '')) {
            throw new DcvsFacebookException('Facebook is enabled but its Page connection is incomplete. Ask an admin to configure it.');
        }
        if ($enabled) {
            if ($testPageId === '') {
                error_log('[DCVS Facebook] WARNING: FB_TEST_PAGE_ID is unset; the configured Page cannot be verified as the test Page.');
            } elseif ($pageId !== $testPageId) {
                error_log('[DCVS Facebook] WARNING: FB_PAGE_ID (' . $pageId . ') does not match FB_TEST_PAGE_ID (' . $testPageId . '). Verify the destination before posting.');
            }
        }

        return [
            'enabled' => $enabled,
            'page_id' => $pageId,
            'page_access_token' => $pageToken,
            'graph_version' => $graphVersion,
            'test_page_id' => $testPageId,
            'app_environment' => $appEnvironment,
            'connect_timeout_seconds' => 8,
            'request_timeout_seconds' => 45,
            'max_poster_bytes' => 5 * 1024 * 1024,
        ];
    }
}

if (!function_exists('dcvsFacebookAssertDestinationAllowed')) {
    function dcvsFacebookAssertDestinationAllowed(array $config): void
    {
        if (($config['app_environment'] ?? 'development') !== 'production'
            && (empty($config['test_page_id']) || ($config['page_id'] ?? '') !== $config['test_page_id'])) {
            error_log('[DCVS Facebook] Posting blocked: non-production environment must target the configured test Page.');
            throw new DcvsFacebookException(
                'Facebook posting is blocked outside production unless FB_PAGE_ID matches FB_TEST_PAGE_ID.'
            );
        }
    }
}

if (!function_exists('dcvsFacebookTrackingTableReady')) {
    function dcvsFacebookTrackingTableReady(mysqli $conn): bool
    {
        $result = $conn->query(
            "SELECT COUNT(*) AS column_count
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'announcement_social_posts'
               AND column_name = 'page_id'"
        );
        if (!$result) {
            error_log('[DCVS Facebook] Could not verify the Page-scoped social-post schema.');
            return false;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['column_count'] ?? 0) === 1;
    }
}

if (!function_exists('dcvsFacebookPreviewTargetNames')) {
    function dcvsFacebookPreviewTargetNames(mysqli $conn, $audience, $targetIds): string
    {
        if ($audience === 'all') {
            return 'All barangays';
        }
        if (!is_array($targetIds)) {
            $targetIds = preg_split('/\s*,\s*/', (string)$targetIds, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $targetIds), function ($id) {
            return $id > 0;
        })));
        if (!$ids) {
            return '';
        }
        $idList = implode(',', $ids);
        $result = $conn->query("SELECT barangay_name FROM barangays WHERE barangay_id IN ($idList) ORDER BY barangay_name");
        if (!$result) {
            throw new RuntimeException('Could not load event target names: ' . $conn->error);
        }
        $names = [];
        while ($row = $result->fetch_assoc()) {
            $names[] = $row['barangay_name'];
        }
        $result->free();
        return implode(', ', $names);
    }
}

if (!function_exists('dcvsFacebookLoadAnnouncement')) {
    function dcvsFacebookLoadAnnouncement(mysqli $conn, int $announcementId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT announcement_id, title, location, content, category, subject, link_url, status, publish_at,
                    archived_at, photo_url, is_event, event_date, event_start_time,
                    event_end_time, venue_name, audience_type, audience_barangays,
                    barangay_id, facebook_posting_mode, facebook_opt_out
             FROM announcements WHERE announcement_id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare announcement lookup: ' . $conn->error);
        }
        $stmt->bind_param('i', $announcementId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not load announcement: ' . $error);
        }
        $announcement = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$announcement) {
            return null;
        }

        $announcement['target_barangays'] = '';
        if ((int)$announcement['is_event'] === 1) {
            $stmt = $conn->prepare(
                'SELECT b.barangay_name
                 FROM announcement_event_barangays eb
                 JOIN barangays b ON b.barangay_id = eb.barangay_id
                 WHERE eb.announcement_id = ?
                 ORDER BY b.barangay_name'
            );
            if (!$stmt) {
                throw new RuntimeException('Could not prepare event target lookup: ' . $conn->error);
            }
            $stmt->bind_param('i', $announcementId);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Could not load event targets: ' . $error);
            }
            $targets = $stmt->get_result();
            $names = [];
            while ($target = $targets->fetch_assoc()) {
                $names[] = $target['barangay_name'];
            }
            $stmt->close();
            if (!$names && ($announcement['audience_type'] ?? '') === 'all') {
                $announcement['target_barangays'] = 'All barangays';
            } else {
                $announcement['target_barangays'] = implode(', ', $names);
            }
        } elseif (($announcement['audience_type'] ?? '') !== 'all') {
            $targetIds = ($announcement['audience_type'] ?? '') === 'barangay'
                ? [(int)($announcement['barangay_id'] ?? 0)]
                : preg_split('/\s*,\s*/', (string)($announcement['audience_barangays'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $announcement['target_barangays'] = dcvsFacebookPreviewTargetNames(
                $conn,
                (string)$announcement['audience_type'],
                $targetIds
            );
        }
        return $announcement;
    }
}

if (!function_exists('dcvsFacebookNormalizePublicText')) {
    function dcvsFacebookNormalizePublicText($value, bool $stripHtml = false): string
    {
        $text = is_scalar($value) ? (string)$value : '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($stripHtml) {
            $text = preg_replace('~<\s*br\b[^>]*>|<\s*/(?:p|div|li|h[1-6])\s*>~i', "\n", $text);
            $text = strip_tags((string)$text);
        }
        $paragraphs = preg_split('/\R/u', (string)$text);
        if (!is_array($paragraphs)) {
            $paragraphs = preg_split('/\R/', (string)$text);
        }
        $paragraphs = array_map(static function ($paragraph): string {
            $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', (string)$paragraph);
            if ($normalized === null) {
                $normalized = preg_replace('/\s+/', ' ', (string)$paragraph);
            }
            return trim((string)$normalized);
        }, $paragraphs ?: []);
        $paragraphs = array_values(array_filter($paragraphs, static function (string $paragraph): bool {
            return $paragraph !== '';
        }));
        return implode("\n\n", $paragraphs);
    }
}

if (!function_exists('dcvsFacebookFormatEventTime')) {
    function dcvsFacebookFormatEventTime($value): string
    {
        $time = trim((string)$value);
        if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
            return '';
        }
        if (strlen($time) === 5) {
            $time .= ':00';
        }
        $parsed = DateTimeImmutable::createFromFormat('!H:i:s', $time);
        return $parsed ? $parsed->format('g:i A') : '';
    }
}

if (!function_exists('dcvsFacebookBuildCaption')) {
    function dcvsFacebookBuildCaption(array $fields): string
    {
        $title = dcvsFacebookNormalizePublicText($fields['title'] ?? '');
        $location = dcvsFacebookNormalizePublicText($fields['location'] ?? '');
        $content = dcvsFacebookNormalizePublicText($fields['content'] ?? '', true);
        $linkUrl = dcvsFacebookNormalizePublicText($fields['link_url'] ?? '');
        $contentType = strtolower(trim((string)($fields['category'] ?? 'announcement')));
        $subject = strtolower(trim((string)($fields['subject'] ?? '')));
        $isAdvisory = $contentType === 'advisory';
        $isEvent = $contentType === 'event' || $subject === 'events';
        $heading = $title;
        if ($isAdvisory && $heading !== '') {
            $heading = '⚠️ ' . $heading;
        } elseif ($isEvent && $heading !== '') {
            $heading = '📅 ' . $heading;
        } elseif (in_array($subject, ['animal_health', 'free_rabies'], true) && $heading !== '') {
            $heading = '🐾 ' . $heading;
        }

        if ($content !== '' && $title !== '') {
            $firstParagraphEnd = strpos($content, "\n\n");
            $firstParagraph = $firstParagraphEnd === false
                ? $content
                : substr($content, 0, $firstParagraphEnd);
            if (strcasecmp($firstParagraph, $title) === 0) {
                $content = $firstParagraphEnd === false
                    ? ''
                    : ltrim(substr($content, $firstParagraphEnd + 2));
            }
        }

        $body = $content;
        $locationAppearsInContent = $location !== ''
            && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($location, '/') . '(?![\p{L}\p{N}])/iu', $content);
        if ($location !== '' && !$locationAppearsInContent) {
            $locationSentence = $isEvent
                ? 'This activity will take place in ' . $location . '.'
                : ($isAdvisory
                    ? 'This advisory applies to ' . $location . '.'
                    : 'This update is for residents in ' . $location . '.');
            $body = $body === '' ? $locationSentence : $body . "\n\n" . $locationSentence;
        }

        $linkAppearsInContent = $linkUrl !== '' && stripos($content, $linkUrl) !== false;
        if ($linkUrl !== '' && !$linkAppearsInContent) {
            $linkSentence = 'For more information, visit ' . $linkUrl;
            $body = $body === '' ? $linkSentence : $body . "\n\n" . $linkSentence;
        }

        $sections = array_values(array_filter([$heading, $body], static function (string $section): bool {
            return trim($section) !== '';
        }));
        $caption = implode("\n\n", $sections);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($caption, 'UTF-8') > 5000) {
                $caption = mb_substr($caption, 0, 4999, 'UTF-8') . '…';
            }
        } elseif (function_exists('iconv_strlen') && function_exists('iconv_substr')) {
            if (iconv_strlen($caption, 'UTF-8') > 5000) {
                $caption = iconv_substr($caption, 0, 4999, 'UTF-8') . '…';
            }
        } elseif (strlen($caption) > 5000) {
            $caption = substr($caption, 0, 4999) . '…';
        }
        return $caption;
    }
}

if (!function_exists('dcvsFacebookValidatePoster')) {
    function dcvsFacebookValidatePoster(string $path): array
    {
        $root = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements');
        $realPath = realpath($path);
        $maxBytes = 5 * 1024 * 1024;

        if ($root === false || $realPath === false
            || strpos($realPath, $root . DIRECTORY_SEPARATOR) !== 0
            || !is_file($realPath) || !is_readable($realPath)) {
            throw new DcvsFacebookException('The announcement poster could not be found on the server.');
        }

        $size = filesize($realPath);
        if ($size === false || $size <= 0 || $size > $maxBytes) {
            throw new DcvsFacebookException('Facebook posters must be no larger than 5 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($realPath);
        $image = @getimagesize($realPath);
        $allowedMimes = ['image/jpeg', 'image/png'];
        if (!in_array($mime, $allowedMimes, true) || !$image || $image['mime'] !== $mime) {
            throw new DcvsFacebookException('Facebook posters must be valid JPG or PNG images.');
        }

        return ['path' => $realPath, 'mime' => $mime, 'size' => $size];
    }
}

if (!function_exists('dcvsFacebookPublish')) {
    function dcvsFacebookPublish(string $caption, ?string $posterPath = null): array
    {
        $config = dcvsFacebookConfig();
        if (!$config['enabled']) {
            throw new DcvsFacebookException('Facebook posting is disabled. Ask an admin to enable it.');
        }
        dcvsFacebookAssertDestinationAllowed($config);
        if (trim($caption) === '') {
            throw new DcvsFacebookException('Facebook post text is empty.');
        }
        if (!function_exists('curl_init') || !class_exists('CURLFile') || !class_exists('finfo')) {
            throw new DcvsFacebookException('Facebook posting is unavailable because a required PHP extension is missing.');
        }

        $isPhoto = $posterPath !== null && $posterPath !== '';
        if ($isPhoto) {
            $poster = dcvsFacebookValidatePoster($posterPath);
            $endpoint = 'photos';
            $fields = [
                'published' => 'true',
                'message' => $caption,
                'source' => new CURLFile($poster['path'], $poster['mime'], basename($poster['path'])),
            ];
        } else {
            $endpoint = 'feed';
            $fields = ['message' => $caption];
        }

        $url = 'https://graph.facebook.com/' . $config['graph_version'] . '/'
            . rawurlencode($config['page_id']) . '/' . $endpoint;
        $fields['access_token'] = $config['page_access_token'];
        $curl = curl_init($url);
        if ($curl === false) {
            throw new DcvsFacebookException('Could not start the Facebook connection. Try again later.');
        }

        $optionsSet = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => $config['request_timeout_seconds'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        if (!$optionsSet) {
            curl_close($curl);
            throw new DcvsFacebookException('Could not configure the Facebook connection. Try again later.');
        }
        $responseBody = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            error_log('[DCVS Facebook] Graph API request failed at transport layer; cURL error ' . $curlErrorNumber . '.');
            throw new DcvsFacebookException(
                'Could not confirm the Facebook post outcome. Check the Page before retrying.',
                'posting_timeout'
            );
        }

        $response = json_decode($responseBody, true);
        if (!is_array($response) || $httpStatus < 200 || $httpStatus >= 300 || !empty($response['error'])) {
            $graphErrorCode = null;
            if (isset($response['error']['code'])) {
                $graphErrorCode = (string)$response['error']['code'];
            }
            error_log('[DCVS Facebook] Graph API rejected a post; HTTP ' . $httpStatus
                . ($graphErrorCode !== null ? ', Graph error ' . $graphErrorCode : '') . '.');
            throw new DcvsFacebookException(
                dcvsFacebookReadableError($graphErrorCode),
                $graphErrorCode
            );
        }

        $postId = trim((string)($response['post_id'] ?? $response['id'] ?? ''));
        if ($postId === '') {
            error_log('[DCVS Facebook] Graph API returned success without a post ID.');
            throw new DcvsFacebookException(
                'Facebook did not confirm the post. Check the Page before retrying.',
                'posting_timeout'
            );
        }

        return [
            'facebook_post_id' => $postId,
            'facebook_permalink' => 'https://www.facebook.com/' . rawurlencode($postId),
        ];
    }
}

if (!function_exists('dcvsFacebookUpdatePost')) {
    function dcvsFacebookUpdatePost(string $facebookPostId, string $caption): void
    {
        $facebookPostId = trim($facebookPostId);
        if (!preg_match('/^\d+(?:_\d+)?$/', $facebookPostId)) {
            throw new DcvsFacebookException('The Facebook post ID is invalid.');
        }
        $config = dcvsFacebookConfig();
        if (!$config['enabled']) {
            throw new DcvsFacebookException('Facebook posting is disabled. Ask an admin to enable it.');
        }
        dcvsFacebookAssertDestinationAllowed($config);
        if (trim($caption) === '') {
            throw new DcvsFacebookException('Facebook post text is empty.');
        }
        if (!function_exists('curl_init')) {
            throw new DcvsFacebookException('Facebook posting is unavailable because a required PHP extension is missing.');
        }

        $url = 'https://graph.facebook.com/' . $config['graph_version'] . '/'
            . rawurlencode($facebookPostId);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new DcvsFacebookException('Could not start the Facebook connection. Try again later.');
        }
        $optionsSet = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'message' => $caption,
                'access_token' => $config['page_access_token'],
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => $config['request_timeout_seconds'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        if (!$optionsSet) {
            curl_close($curl);
            throw new DcvsFacebookException('Could not configure the Facebook connection. Try again later.');
        }
        $responseBody = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            error_log('[DCVS Facebook] Graph API post update failed at transport layer; cURL error ' . $curlErrorNumber . '.');
            throw new DcvsFacebookException('Could not confirm the Facebook post update. Check the Page.', 'update_timeout');
        }
        $response = json_decode($responseBody, true);
        if (!is_array($response) || $httpStatus < 200 || $httpStatus >= 300
            || !empty($response['error']) || ($response['success'] ?? false) !== true) {
            $graphErrorCode = isset($response['error']['code'])
                ? (string)$response['error']['code']
                : null;
            error_log('[DCVS Facebook] Graph API rejected a post update; HTTP ' . $httpStatus
                . ($graphErrorCode !== null ? ', Graph error ' . $graphErrorCode : '') . '.');
            throw new DcvsFacebookException(
                dcvsFacebookReadableError($graphErrorCode),
                $graphErrorCode
            );
        }
    }
}

if (!function_exists('dcvsFacebookReadableError')) {
    function dcvsFacebookReadableError(?string $graphErrorCode): string
    {
        switch ($graphErrorCode) {
            case '190':
                return 'Facebook connection expired. Ask an admin to reconnect.';
            case '10':
            case '200':
                return 'Facebook does not have permission to publish to this Page. Ask an admin to check Page access.';
            case '4':
            case '17':
                return 'Facebook is temporarily limiting posts. Try again later.';
            case '368':
                return 'Facebook blocked this post. Review the content or Page restrictions, then retry.';
            default:
                return 'Facebook could not publish this announcement. Try again or ask an admin to check the connection.';
        }
    }
}

if (!function_exists('dcvsFacebookSanitizeDiagnosticMessage')) {
    function dcvsFacebookSanitizeDiagnosticMessage(string $message): string
    {
        $message = preg_replace('~https?://[^\s\'"<>()]+~i', '[URL]', $message);
        $message = preg_replace('/\b(access_token|page_access_token|fb_page_access_token|token|authorization)\s*[:=]\s*[^\s&,;]+/i', '$1=[REDACTED]', $message);
        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $message);
        $message = preg_replace('/\bEA[A-Za-z0-9]{20,}\b/', '[REDACTED_TOKEN]', $message);
        return substr((string)$message, 0, 1000);
    }
}

if (!function_exists('dcvsFacebookClaimSocialPost')) {
    function dcvsFacebookClaimSocialPost(mysqli $conn, int $socialPostId, string $pageId): bool
    {
        $stmt = $conn->prepare(
            "UPDATE announcement_social_posts
             SET status = 'posting', attempt_count = attempt_count + 1,
                 posting_started_at = NOW(), last_error = NULL, graph_error_code = NULL
             WHERE social_post_id = ? AND platform = 'facebook'
               AND page_id = ? AND status IN ('pending', 'failed')"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare Facebook post claim: ' . $conn->error);
        }
        $stmt->bind_param('is', $socialPostId, $pageId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not claim Facebook post: ' . $error);
        }
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }
}

if (!function_exists('dcvsFacebookClaimSocialPostUpdate')) {
    function dcvsFacebookClaimSocialPostUpdate(mysqli $conn, int $socialPostId, string $pageId): bool
    {
        $stmt = $conn->prepare(
            "UPDATE announcement_social_posts
             SET posting_started_at = NOW(), last_error = NULL, graph_error_code = NULL
             WHERE social_post_id = ? AND platform = 'facebook' AND page_id = ?
               AND status = 'posted'
               AND (posting_started_at IS NULL OR TIMESTAMPDIFF(SECOND, posting_started_at, NOW()) >= 300)"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare Facebook post update claim: ' . $conn->error);
        }
        $stmt->bind_param('is', $socialPostId, $pageId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not claim Facebook post update: ' . $error);
        }
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        return $claimed;
    }
}

if (!function_exists('dcvsFacebookReleaseSocialPostUpdate')) {
    function dcvsFacebookReleaseSocialPostUpdate(mysqli $conn, int $socialPostId, string $pageId): bool
    {
        $stmt = $conn->prepare(
            "UPDATE announcement_social_posts
             SET posting_started_at = NULL
             WHERE social_post_id = ? AND platform = 'facebook' AND page_id = ? AND status = 'posted'"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare Facebook post update release: ' . $conn->error);
        }
        $stmt->bind_param('is', $socialPostId, $pageId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not release Facebook post update: ' . $error);
        }
        $released = $stmt->affected_rows === 1;
        $stmt->close();
        return $released;
    }
}

if (!function_exists('dcvsFacebookMarkSocialPostPosted')) {
    function dcvsFacebookMarkSocialPostPosted(
        mysqli $conn,
        int $socialPostId,
        string $pageId,
        string $facebookPostId,
        string $permalink
    ): bool {
        $stmt = $conn->prepare(
            "UPDATE announcement_social_posts
             SET status = 'posted', facebook_post_id = ?, facebook_permalink = ?,
                 posted_at = NOW(), posting_started_at = NULL, last_error = NULL,
                 graph_error_code = NULL
             WHERE social_post_id = ? AND platform = 'facebook' AND page_id = ? AND status = 'posting'"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare Facebook post success update: ' . $conn->error);
        }
        $stmt->bind_param('ssis', $facebookPostId, $permalink, $socialPostId, $pageId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not record Facebook post success: ' . $error);
        }
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        return $updated;
    }
}

if (!function_exists('dcvsFacebookMarkSocialPostFailed')) {
    function dcvsFacebookMarkSocialPostFailed(
        mysqli $conn,
        int $socialPostId,
        string $pageId,
        string $safeMessage,
        ?string $graphErrorCode = null
    ): bool {
        $safeMessage = substr($safeMessage, 0, 500);
        $graphErrorCode = $graphErrorCode === null ? null : substr($graphErrorCode, 0, 40);
        $stmt = $conn->prepare(
            "UPDATE announcement_social_posts
             SET status = 'failed', last_error = ?, graph_error_code = ?,
                 posting_started_at = NULL
             WHERE social_post_id = ? AND platform = 'facebook'
               AND page_id = ? AND status = 'posting'"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare Facebook post failure update: ' . $conn->error);
        }
        $stmt->bind_param('ssis', $safeMessage, $graphErrorCode, $socialPostId, $pageId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not record Facebook post failure: ' . $error);
        }
        $updated = $stmt->affected_rows === 1;
        $stmt->close();
        return $updated;
    }
}

if (!function_exists('dcvsFacebookRecoverStaleSocialPosts')) {
    function dcvsFacebookRecoverStaleSocialPosts(mysqli $conn, int $staleAfterSeconds = 300): int
    {
        $staleAfterSeconds = max(120, min($staleAfterSeconds, 86400));
        $stmt = $conn->prepare(
            "UPDATE announcement_social_posts
             SET status = 'failed', posting_started_at = NULL,
                 graph_error_code = 'posting_timeout',
                 last_error = 'Posting outcome could not be confirmed. Check Facebook before retrying.'
             WHERE platform = 'facebook' AND status = 'posting'
               AND posting_started_at IS NOT NULL
               AND TIMESTAMPDIFF(SECOND, posting_started_at, NOW()) >= ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare stale Facebook post recovery: ' . $conn->error);
        }
        $stmt->bind_param('i', $staleAfterSeconds);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not recover stale Facebook posts: ' . $error);
        }
        $recovered = $stmt->affected_rows;
        $stmt->close();
        return $recovered;
    }
}
