<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/facebook-announcements.php';

function facebookPostTestFail(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

if ($argc < 2 || trim((string)$argv[1]) === '') {
    facebookPostTestFail(
        'Usage: php scripts/facebook_post_test.php "Test message" [uploads/announcements/poster.jpg|poster.png]',
        2
    );
}

try {
    $config = dcvsFacebookConfig();
    if (!$config['enabled']) {
        facebookPostTestFail('Facebook posting is disabled. Set FB_ENABLED=true temporarily to run this test.', 2);
    }

    $testPageId = trim((string)env('FB_TEST_PAGE_ID', ''));
    if ($testPageId === '') {
        facebookPostTestFail('FB_TEST_PAGE_ID is not configured. Set it to the test Page ID before running this test.', 2);
    }
    if ($config['page_id'] !== $testPageId) {
        facebookPostTestFail('FB_PAGE_ID does not match FB_TEST_PAGE_ID. Refusing to post outside the configured test Page.', 2);
    }

    $caption = trim((string)$argv[1]);
    $posterPath = null;
    if (isset($argv[2]) && trim((string)$argv[2]) !== '') {
        $posterPath = trim((string)$argv[2]);
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $posterPath)) {
            $posterPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $posterPath);
        }
    }

    $result = dcvsFacebookPublish($caption, $posterPath);
    fwrite(STDOUT, ($posterPath === null ? 'Text test post published.' : 'Photo test post published.')
        . PHP_EOL
        . 'Facebook post ID: ' . $result['facebook_post_id'] . PHP_EOL
        . 'Permalink: ' . $result['facebook_permalink'] . PHP_EOL);
} catch (DcvsFacebookException $exception) {
    facebookPostTestFail($exception->getMessage());
} catch (Throwable $exception) {
    $safeMessage = dcvsFacebookSanitizeDiagnosticMessage($exception->getMessage());
    error_log('[DCVS Facebook test] Unexpected CLI failure: ' . get_class($exception) . ': ' . $safeMessage);
    facebookPostTestFail('Facebook test failed unexpectedly. Check the server error log for details.');
}
