<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/facebook-announcements.php';

function facebookVerifyFail(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function facebookVerifyNormalizeCaption(string $caption): string
{
    $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $caption);
    return trim($normalized === null ? preg_replace('/\s+/', ' ', $caption) : $normalized);
}

function facebookVerifyGraphGet(array $config, string $path, array $parameters = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Facebook verification.');
    }

    $parameters['access_token'] = $config['page_access_token'];
    $url = 'https://graph.facebook.com/' . $config['graph_version'] . '/' . $path
        . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not start the Facebook connection.');
    }
    $configured = curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
        CURLOPT_TIMEOUT => $config['request_timeout_seconds'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    if (!$configured) {
        curl_close($curl);
        throw new RuntimeException('Could not configure the Facebook connection.');
    }
    $body = curl_exec($curl);
    $curlErrorNumber = curl_errno($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false) {
        throw new RuntimeException('Facebook connection failed (cURL error ' . $curlErrorNumber . ').');
    }

    $response = json_decode($body, true);
    if (!is_array($response) || $httpStatus < 200 || $httpStatus >= 300 || !empty($response['error'])) {
        $errorCode = isset($response['error']['code']) ? (string)$response['error']['code'] : '';
        $message = facebookVerifyGraphError($errorCode);
        throw new RuntimeException($message . ' (HTTP ' . $httpStatus . ').');
    }
    return $response;
}

function facebookVerifyGraphError(string $code): string
{
    if ($code === '190') {
        return 'Facebook token invalid or expired';
    }
    if ($code === '10' || $code === '200') {
        return 'Missing permission to read Page posts';
    }
    if ($code === '100' || $code === '803') {
        return 'Post not found: it may have been deleted on Facebook';
    }
    return 'Facebook Graph API request failed';
}

function facebookVerifyAttachmentCaption(array $post): string
{
    $message = trim((string)($post['message'] ?? ''));
    if ($message !== '') {
        return $message;
    }
    $attachments = $post['attachments']['data'] ?? [];
    foreach ($attachments as $attachment) {
        foreach (['name', 'description'] as $field) {
            $value = trim((string)($attachment[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function facebookVerifyHasPoster(array $post): bool
{
    return !empty($post['attachments']['data']) && is_array($post['attachments']['data']);
}

function facebookVerifyBarangayNames(mysqli $conn, array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
        return $id > 0;
    })));
    if (!$ids) {
        return '';
    }
    $idList = implode(',', $ids);
    $result = $conn->query(
        "SELECT barangay_name FROM barangays
         WHERE barangay_id IN ($idList) ORDER BY barangay_name"
    );
    if (!$result) {
        throw new RuntimeException('Could not load announcement location names.');
    }
    $names = [];
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['barangay_name'];
    }
    $result->free();
    return implode(', ', $names);
}

function facebookVerifyLoadAnnouncement(mysqli $conn, int $announcementId): ?array
{
    $stmt = $conn->prepare(
        "SELECT announcement_id, title, location, content, category, subject, link_url, photo_url,
                is_event, event_date, event_start_time, event_end_time, venue_name,
                audience_type, audience_barangays, barangay_id
         FROM announcements WHERE announcement_id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare announcement lookup.');
    }
    $stmt->bind_param('i', $announcementId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not load announcement data.');
    }
    $announcement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$announcement) {
        return null;
    }

    $announcement['target_barangays'] = '';
    if ((int)$announcement['is_event'] === 1) {
        $stmt = $conn->prepare(
            'SELECT b.barangay_name FROM announcement_event_barangays eb
             JOIN barangays b ON b.barangay_id = eb.barangay_id
             WHERE eb.announcement_id = ? ORDER BY b.barangay_name'
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare event locations lookup.');
        }
        $stmt->bind_param('i', $announcementId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Could not load event locations.');
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
        $ids = ($announcement['audience_type'] ?? '') === 'barangay'
            ? [(int)($announcement['barangay_id'] ?? 0)]
            : preg_split('/\s*,\s*/', (string)($announcement['audience_barangays'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $announcement['target_barangays'] = facebookVerifyBarangayNames($conn, $ids);
    }
    return $announcement;
}

function facebookVerifyPostRow(mysqli $conn, int $announcementId, string $pageId): ?array
{
    $stmt = $conn->prepare(
        "SELECT announcement_id, status, facebook_post_id AS fb_post_id,
                page_id, posted_at, facebook_permalink
         FROM announcement_social_posts
         WHERE announcement_id = ? AND platform = 'facebook'
           AND status = 'posted' AND page_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare Facebook tracking lookup.');
    }
    $stmt->bind_param('is', $announcementId, $pageId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not read Facebook tracking row.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function facebookVerifyListRows(mysqli $conn, string $pageId, int $limit): array
{
    $stmt = $conn->prepare(
        "SELECT announcement_id, status, facebook_post_id AS fb_post_id,
                page_id, posted_at, facebook_permalink
         FROM announcement_social_posts
         WHERE platform = 'facebook' AND status = 'posted' AND page_id = ?
         ORDER BY posted_at DESC, social_post_id DESC LIMIT ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare recent Facebook tracking lookup.');
    }
    $stmt->bind_param('si', $pageId, $limit);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not read recent Facebook tracking rows.');
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function facebookVerifyRecentPagePosts(array $config, int $limit): array
{
    $response = facebookVerifyGraphGet($config, rawurlencode($config['page_id']) . '/posts', [
        'limit' => $limit,
        'fields' => 'id,message,created_time,permalink_url,is_published,attachments',
    ]);
    return is_array($response['data'] ?? null) ? $response['data'] : [];
}

function facebookVerifySafePermalink(array $row, array $post): string
{
    $permalink = (string)($post['permalink_url'] ?? $row['facebook_permalink'] ?? '');
    if (preg_match('~^https://(?:www\.)?facebook\.com/[^\s<>]+$~i', $permalink)) {
        return $permalink;
    }
    return '—';
}

$announcementId = null;
$recentCount = 5;
if ($argc > 2) {
    facebookVerifyFail(
        'Usage: php scripts/facebook_verify_post.php [announcement_id|--recent=N]',
        2
    );
}
if ($argc === 2) {
    $argument = (string)$argv[1];
    if (preg_match('/^--recent=(\d+)$/', $argument, $matches)) {
        $recentCount = (int)$matches[1];
        if ($recentCount < 1 || $recentCount > 100) {
            facebookVerifyFail('--recent must be between 1 and 100.', 2);
        }
    } elseif (preg_match('/^\d+$/', $argument) && (int)$argument > 0) {
        $announcementId = (int)$argument;
    } else {
        facebookVerifyFail(
            'Usage: php scripts/facebook_verify_post.php [announcement_id|--recent=N]',
            2
        );
    }
}

try {
    $config = dcvsFacebookConfig();
    if (!$config['enabled']) {
        throw new RuntimeException('Facebook verification requires FB_ENABLED=true.');
    }
    if ($config['app_environment'] === 'production') {
        throw new RuntimeException('Facebook verification is blocked when APP_ENV=production.');
    }
    if ($config['test_page_id'] === '' || $config['page_id'] !== $config['test_page_id']) {
        throw new RuntimeException('FB_PAGE_ID must match FB_TEST_PAGE_ID to verify the test Page.');
    }
    if (!dcvsFacebookTrackingTableReady($conn)) {
        throw new RuntimeException('Page-scoped Facebook post tracking is not installed.');
    }

    $rows = $announcementId !== null
        ? array_filter([facebookVerifyPostRow($conn, $announcementId, $config['page_id'])])
        : facebookVerifyListRows($conn, $config['page_id'], $recentCount);
    if (!$rows) {
        throw new RuntimeException(
            $announcementId !== null
                ? 'No posted Facebook tracking row found for that announcement on the configured test Page.'
                : 'No posted Facebook tracking rows found for the configured test Page.'
        );
    }

    $recentTen = facebookVerifyRecentPagePosts($config, 10);
    $recentTwentyFive = facebookVerifyRecentPagePosts($config, 25);
    $allPassed = true;
    $results = [];
    foreach ($rows as $row) {
        $announcement = facebookVerifyLoadAnnouncement($conn, (int)$row['announcement_id']);
        if (!$announcement) {
            $results[] = [
                'announcement_id' => (string)$row['announcement_id'],
                'status' => (string)$row['status'],
                'found' => 'no',
                'published' => 'no',
                'caption' => 'DIFF',
                'poster' => !empty($announcement['photo_url']) ? 'no' : 'n/a',
                'duplicates' => '0',
                'duplicate_count' => 0,
                'caption_summary' => 'announcement no longer exists in the database',
                'permalink' => facebookVerifySafePermalink($row, []),
                'passed' => false,
            ];
            continue;
        }

        $post = facebookVerifyGraphGet($config, rawurlencode((string)$row['fb_post_id']), [
            'fields' => 'id,message,created_time,permalink_url,is_published,attachments',
        ]);
        $postId = (string)($post['id'] ?? '');
        $foundInRecent = false;
        foreach ($recentTen as $recentPost) {
            if ((string)($recentPost['id'] ?? '') === (string)$row['fb_post_id']) {
                $foundInRecent = true;
                break;
            }
        }
        $found = $postId !== '' && $postId === (string)$row['fb_post_id'] && $foundInRecent;
        $published = !empty($post['is_published']);
        $expectedCaption = dcvsFacebookBuildCaption($announcement);
        $actualCaption = facebookVerifyAttachmentCaption($post);
        $captionMatches = facebookVerifyNormalizeCaption($expectedCaption)
            === facebookVerifyNormalizeCaption($actualCaption);
        $hasPoster = facebookVerifyHasPoster($post);
        $posterExpected = !empty($announcement['photo_url']);
        $duplicateCount = 0;
        $expectedNormalized = facebookVerifyNormalizeCaption($expectedCaption);
        foreach ($recentTwentyFive as $recentPost) {
            if (facebookVerifyNormalizeCaption(facebookVerifyAttachmentCaption($recentPost)) === $expectedNormalized) {
                $duplicateCount++;
            }
        }
        $permalink = facebookVerifySafePermalink($row, $post);
        $passed = $found && $published && $captionMatches
            && (!$posterExpected || $hasPoster) && $duplicateCount <= 1;
        $allPassed = $allPassed && $passed;
        $results[] = [
            'announcement_id' => (string)$row['announcement_id'],
            'status' => (string)$row['status'],
            'found' => $found ? 'yes' : 'no',
            'published' => $published ? 'yes' : 'no',
            'caption' => $captionMatches ? 'MATCH' : 'DIFF',
            'poster' => $posterExpected ? ($hasPoster ? 'yes' : 'no') : 'n/a',
            'duplicates' => $duplicateCount > 1 ? $duplicateCount . ' (FLAG)' : (string)$duplicateCount,
            'duplicate_count' => $duplicateCount,
            'caption_summary' => $captionMatches ? '' : 'expected ' . strlen(facebookVerifyNormalizeCaption($expectedCaption))
                . ' chars, Facebook returned ' . strlen(facebookVerifyNormalizeCaption($actualCaption)) . ' chars',
            'permalink' => $permalink,
            'passed' => $passed,
        ];
    }

    $headers = ['announcement_id', 'DB status', 'FB post found', 'published', 'caption', 'poster present', 'duplicates', 'permalink'];
    $widths = array_map('strlen', $headers);
    foreach ($results as $result) {
        foreach (array_keys($headers) as $index) {
            $key = ['announcement_id', 'status', 'found', 'published', 'caption', 'poster', 'duplicates', 'permalink'][$index];
            $widths[$index] = max($widths[$index], strlen($result[$key]));
        }
    }
    $line = function (array $values) use ($widths): string {
        return implode(' | ', array_map(function ($value, $width) {
            return str_pad((string)$value, $width);
        }, $values, $widths));
    };
    fwrite(STDOUT, $line($headers) . PHP_EOL);
    fwrite(STDOUT, implode('-+-', array_map(function ($width) {
        return str_repeat('-', $width);
    }, $widths)) . PHP_EOL);
    foreach ($results as $result) {
        fwrite(STDOUT, $line([
            $result['announcement_id'],
            $result['status'],
            $result['found'],
            $result['published'],
            $result['caption'],
            $result['poster'],
            $result['duplicates'],
            $result['permalink'],
        ]) . PHP_EOL);
        if (!$result['passed']) {
            $allPassed = false;
            $reasons = [];
            if ($result['found'] !== 'yes') $reasons[] = 'not found in the recent Page posts';
            if ($result['published'] !== 'yes') $reasons[] = 'not published';
            if ($result['caption'] !== 'MATCH') $reasons[] = 'caption differs after whitespace normalization';
            if ($result['poster'] === 'no') $reasons[] = 'expected poster attachment is missing';
            if ($result['duplicate_count'] > 1) $reasons[] = 'duplicate captions found in the last 25 posts';
            if ($result['caption_summary'] !== '') $reasons[] = $result['caption_summary'];
            fwrite(STDERR, 'Announcement ' . $result['announcement_id'] . ': ' . implode('; ', $reasons) . '.' . PHP_EOL);
        }
    }
    exit($allPassed ? 0 : 1);
} catch (Throwable $exception) {
    facebookVerifyFail($exception->getMessage());
}
