<?php
require_once __DIR__ . '/../includes/reports/validation.php';

function expectValidationCondition($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectValidationCondition(dcvsValidationAttachmentType('image/jpeg', 'jpg') === 'photo', 'JPG evidence should be stored as a photo.');
expectValidationCondition(dcvsValidationAttachmentType('image/jpeg', 'jpeg') === 'photo', 'JPEG evidence should be stored as a photo.');
expectValidationCondition(dcvsValidationAttachmentType('image/png', 'png') === 'photo', 'PNG evidence should be stored as a photo.');
expectValidationCondition(dcvsValidationAttachmentType('video/mp4', 'mp4') === 'video', 'MP4 evidence should be stored as a video.');
expectValidationCondition(dcvsValidationAttachmentType('image/png', 'jpg') === null, 'MIME and extension mismatches must be rejected.');
expectValidationCondition(dcvsValidationAttachmentType('application/octet-stream', 'bin') === null, 'Unsupported evidence must be rejected.');
foreach ([
    'site_visit_pending',
    'confirmed_on_site',
    'urgent_response',
    'animal_already_gone',
    'wrong_or_unclear_location',
] as $fieldStatus) {
    expectValidationCondition(
        in_array($fieldStatus, dcvsValidationFieldStatuses(), true),
        "Barangay validation should accept the {$fieldStatus} field status."
    );
}

echo "Report validation status and evidence checks passed.\n";
