<?php

putenv('FB_ENABLED=false');
require_once __DIR__ . '/../includes/facebook-announcements.php';

function expectFacebookAnnouncementCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = dcvsFacebookConfig();
expectFacebookAnnouncementCondition($config['enabled'] === false, 'Facebook posting must be disabled by default.');
expectFacebookAnnouncementCondition(
    dcvsFacebookReadableError('190') === 'Facebook connection expired. Ask an admin to reconnect.',
    'Expired Facebook tokens must produce a safe, actionable error.'
);
expectFacebookAnnouncementCondition(
    dcvsFacebookReadableError('200') === 'Facebook does not have permission to publish to this Page. Ask an admin to check Page access.',
    'Facebook permission errors must produce a safe, actionable error.'
);
expectFacebookAnnouncementCondition(
    strpos(dcvsFacebookReadableError('unknown'), 'Facebook could not publish') === 0,
    'Unknown Graph API errors must use the generic safe message.'
);
$invalidFacebookPostIdRejected = false;
try {
    dcvsFacebookUpdatePost('not-a-facebook-post-id', 'Caption');
} catch (DcvsFacebookException $exception) {
    $invalidFacebookPostIdRejected = true;
}
expectFacebookAnnouncementCondition(
    $invalidFacebookPostIdRejected,
    'Facebook post updates must reject malformed post IDs before making a request.'
);

$caption = dcvsFacebookBuildCaption([
    'title' => '  Free Kapon Day  ',
    'location' => 'Paliparan 1',
    'content' => '<p>Register&nbsp;now.</p><p>Bring your pet.</p>',
    'link_url' => 'https://example.test/details',
    'category' => 'event',
    'subject' => 'animal_health',
    'response_deadline' => 'do not include',
    'contact_details' => 'do not include',
    'internal_id' => '12345',
    'staff_name' => 'do not include',
]);
expectFacebookAnnouncementCondition(
    $caption === "📅 Free Kapon Day\n\nRegister now.\n\nBring your pet.\n\nThis activity will take place in Paliparan 1.\n\nFor more information, visit https://example.test/details",
    'Captions must be assembled from public title, location, content, and link only.'
);
$targetedCaption = dcvsFacebookBuildCaption([
    'title' => 'Barangay vaccination',
    'location' => 'District 1',
    'content' => 'Bring vaccination records.',
    'target_barangays' => 'Salawag, Paliparan 1',
]);
expectFacebookAnnouncementCondition(
    $targetedCaption === "Barangay vaccination\n\nBring vaccination records.\n\nThis update is for residents in District 1.",
    'Selected barangay names must not be included, and public location should read naturally.'
);
$sameLocationCaption = dcvsFacebookBuildCaption([
    'title' => 'Barangay vaccination',
    'location' => 'Salawag, Paliparan 1',
    'content' => 'Bring vaccination records.',
    'target_barangays' => 'Salawag, Paliparan 1',
]);
expectFacebookAnnouncementCondition(
    $sameLocationCaption === "Barangay vaccination\n\nBring vaccination records.\n\nThis update is for residents in Salawag, Paliparan 1.",
    'Barangay targets must be omitted even when they match the public location.'
);
$paragraphCaption = dcvsFacebookBuildCaption([
    'title' => 'Service update',
    'content' => '<p>First paragraph.</p><p>Second paragraph.</p>',
]);
expectFacebookAnnouncementCondition(
    $paragraphCaption === "Service update\n\nFirst paragraph.\n\nSecond paragraph.",
    'HTML paragraphs must remain separate in the final Facebook caption.'
);
$advisoryCaption = dcvsFacebookBuildCaption([
    'title' => 'Road closure advisory',
    'location' => 'Salawag',
    'content' => 'Please use the alternate route.',
    'category' => 'advisory',
]);
expectFacebookAnnouncementCondition(
    $advisoryCaption === "⚠️ Road closure advisory\n\nPlease use the alternate route.\n\nThis advisory applies to Salawag.",
    'Advisory captions should use an appropriate warning headline and natural location context.'
);
$eventCaption = dcvsFacebookBuildCaption([
    'title' => 'Vaccination activity',
    'location' => 'Paliparan 1',
    'content' => 'Join us on June 4 at 8:00 AM.',
    'category' => 'event',
]);
expectFacebookAnnouncementCondition(
    $eventCaption === "📅 Vaccination activity\n\nJoin us on June 4 at 8:00 AM.\n\nThis activity will take place in Paliparan 1.",
    'Public event details must be preserved while coordination-only values remain excluded.'
);
$headlineNotRepeated = dcvsFacebookBuildCaption([
    'title' => 'Free Kapon Day',
    'content' => "Free Kapon Day\n\nBring your pet.",
]);
expectFacebookAnnouncementCondition(
    $headlineNotRepeated === "Free Kapon Day\n\nBring your pet.",
    'A title repeated as the opening content paragraph must not be duplicated.'
);
$coordinationOnlyFields = [
    'title' => 'Coordination leak test',
    'location' => 'Public location',
    'content' => 'Public description',
    'venue_name' => 'PRIVATE_VENUE_MARKER',
    'target_barangays' => 'PRIVATE_BARANGAY_MARKER',
    'event_targets' => 'PRIVATE_SELECTED_TARGET_MARKER',
    'preparedness_requirements' => 'PRIVATE_PREPAREDNESS_MARKER',
    'event_requirements' => 'PRIVATE_REQUIREMENT_MARKER',
    'event_date' => '2026-12-18',
    'event_start_time' => '09:15',
    'event_end_time' => '11:45',
    'response_deadline' => 'PRIVATE_DEADLINE_MARKER',
];
$coordinationCaption = dcvsFacebookBuildCaption($coordinationOnlyFields);
foreach ([
    'PRIVATE_VENUE_MARKER',
    'PRIVATE_BARANGAY_MARKER',
    'PRIVATE_SELECTED_TARGET_MARKER',
    'PRIVATE_PREPAREDNESS_MARKER',
    'PRIVATE_REQUIREMENT_MARKER',
    '2026-12-18',
    '09:15',
    '11:45',
    'PRIVATE_DEADLINE_MARKER',
] as $privateMarker) {
    expectFacebookAnnouncementCondition(
        strpos($coordinationCaption, $privateMarker) === false,
        'Coordination-only field leaked into caption: ' . $privateMarker
    );
}
expectFacebookAnnouncementCondition(
    strlen($caption) <= 5000
        && strpos($caption, 'animal_health') === false
        && strpos($caption, 'do not include') === false
        && strpos($caption, '12345') === false,
    'Caption length must be capped and internal fields must never be emitted.'
);
$longCaption = dcvsFacebookBuildCaption(['title' => 'Title', 'content' => str_repeat('x', 6000)]);
expectFacebookAnnouncementCondition(
    (function_exists('mb_strlen')
        ? mb_strlen($longCaption, 'UTF-8')
        : (function_exists('iconv_strlen') ? iconv_strlen($longCaption, 'UTF-8') : preg_match_all('/./us', $longCaption))) === 5000,
    'Facebook captions must be capped at 5,000 characters.'
);

$developmentGuardBlocked = false;
try {
    dcvsFacebookAssertDestinationAllowed([
        'app_environment' => 'development',
        'page_id' => 'official-page',
        'test_page_id' => 'test-page',
    ]);
} catch (DcvsFacebookException $exception) {
    $developmentGuardBlocked = true;
}
expectFacebookAnnouncementCondition($developmentGuardBlocked, 'Non-production must block a Page ID other than the test Page.');
dcvsFacebookAssertDestinationAllowed([
    'app_environment' => 'development',
    'page_id' => 'test-page',
    'test_page_id' => 'test-page',
]);

$diagnostic = dcvsFacebookSanitizeDiagnosticMessage(
        'Request failed at https://graph.facebook.com/v26.0/123/feed?access_token=EAA123456789012345678901234567890 '
        . 'Bearer sensitive-value token=another-secret'
    );
expectFacebookAnnouncementCondition(
    strpos($diagnostic, 'https://') === false
        && strpos($diagnostic, 'EAA123456789012345678901234567890') === false
        && strpos($diagnostic, 'Bearer sensitive-value') === false
        && strpos($diagnostic, 'token=another-secret') === false,
    'Unexpected diagnostics must not contain full URLs or access tokens.'
);

echo "Facebook announcement configuration and error mapping checks passed.\n";
