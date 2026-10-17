<?php

require_once __DIR__ . '/../includes/facebook-announcements.php';

function expectPublicAnnouncementCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$caption = dcvsFacebookBuildCaption([
    'title' => 'Public title',
    'location' => 'Public location',
    'content' => '<p>Public content.</p>',
    'link_url' => 'https://example.test/public',
    'event_date' => '2026-12-18',
    'event_start_time' => '09:15:00',
    'event_end_time' => '11:45:00',
    'venue_name' => 'PRIVATE_VENUE_MARKER',
    'target_barangays' => 'PRIVATE_TARGET_MARKER',
    'event_requirements' => 'PRIVATE_PREPAREDNESS_MARKER',
    'response_deadline' => 'PRIVATE_DEADLINE_MARKER',
]);
foreach ([
    'PRIVATE_VENUE_MARKER',
    'PRIVATE_TARGET_MARKER',
    'PRIVATE_PREPAREDNESS_MARKER',
    'PRIVATE_DEADLINE_MARKER',
    '2026-12-18',
    '09:15',
    '11:45',
] as $marker) {
    expectPublicAnnouncementCondition(strpos($caption, $marker) === false, 'Caption leak: ' . $marker);
}

$publicAnnouncementSource = file_get_contents(__DIR__ . '/../pages/shared/announcements.php');
if ($publicAnnouncementSource === false
    || !preg_match('/\$aq\s*=\s*\$conn->query\(\s*"([\s\S]*?)"\s*\);/', $publicAnnouncementSource, $queryMatch)) {
    throw new RuntimeException('Could not locate the public announcements query for the allowlist test.');
}
$publicQuery = $queryMatch[1];
expectPublicAnnouncementCondition(
    stripos($publicQuery, 'SELECT *') === false,
    'The public landing query must use an explicit column allowlist.'
);
foreach ([
    'audience_type',
    'audience_barangays',
    'venue_name',
    'event_start_time',
    'event_end_time',
    'response_deadline',
    'announcement_event_',
] as $coordinationField) {
    expectPublicAnnouncementCondition(
        stripos($publicQuery, $coordinationField) === false,
        'Coordination field/table is present in the public query: ' . $coordinationField
    );
}

$today = date('Y-m-d H:i:s');
$announcements = [[
    'title' => 'Public title',
    'location' => 'Paliparan II',
    'content' => "Public content.\nThis is the complete second paragraph that must remain available in the card popover.",
    'category' => 'announcement',
    'subject' => 'general_notices',
    'link_url' => 'https://example.test/public',
    'photo_url' => '',
    'status' => 'published',
    'created_at' => $today,
    'publish_at' => null,
    'is_event' => 1,
    'event_date' => '2026-12-18',
    'event_end_date' => '2026-12-19',
    '_public_targets' => [['id' => 1, 'name' => 'Paliparan II']],
    '_public_all_barangays' => false,
    '_public_category_label' => 'General Notices',
    'venue_name' => 'PRIVATE_VENUE_MARKER',
    'audience_type' => 'selected',
    'audience_barangays' => 'PRIVATE_TARGET_MARKER',
    'event_start_time' => '09:15:00',
    'event_end_time' => '11:45:00',
    'event_requirements' => [['requirement_label' => 'PRIVATE_PREPAREDNESS_MARKER']],
    'response_deadline' => 'PRIVATE_DEADLINE_MARKER',
]];
$publicBarangayNames = [1 => 'Paliparan II'];
ob_start();
require __DIR__ . '/../pages/shared/announcements-ui.php';
$publicHtml = ob_get_clean();
foreach ([
    'PRIVATE_VENUE_MARKER',
    'PRIVATE_TARGET_MARKER',
    'PRIVATE_PREPAREDNESS_MARKER',
    'PRIVATE_DEADLINE_MARKER',
    '09:15',
    '11:45',
] as $marker) {
    expectPublicAnnouncementCondition(strpos($publicHtml, $marker) === false, 'Public HTML leak: ' . $marker);
}
expectPublicAnnouncementCondition(strpos($publicHtml, 'Paliparan II') !== false, 'Public Location/s must remain visible.');
expectPublicAnnouncementCondition(
    strpos($publicHtml, 'Event date: Dec 18, 2026 – Dec 19, 2026') !== false,
    'Public event cards must display their actual event dates.'
);
expectPublicAnnouncementCondition(
    strpos($publicHtml, 'This is the complete second paragraph that must remain available in the card popover.') !== false,
    'Public announcement cards must include the full untruncated content in the popover.'
);
expectPublicAnnouncementCondition(
    strpos($publicHtml, 'aria-describedby="public-ann-popover-1"') !== false
        && strpos($publicHtml, 'role="region" aria-label="Full announcement details"') !== false,
    'Public announcement cards must associate the full-details popover for keyboard users.'
);
expectPublicAnnouncementCondition(
    strpos($publicHtml, '.public-ann-popover { position:fixed;') !== false
        && strpos($publicHtml, 'function positionPopover(item, popover)') !== false
        && strpos($publicHtml, "document.addEventListener('pointerdown'") !== false
        && strpos($publicHtml, "event.pointerType === 'touch'") !== false,
    'Public announcement full-details popovers must float above clipped content and support viewport positioning and touch dismissal.'
);

echo "Public announcement allowlist and coordination leak checks passed.\n";
