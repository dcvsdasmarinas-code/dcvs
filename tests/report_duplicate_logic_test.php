<?php
require_once __DIR__ . '/../includes/reports/duplicates.php';

function expectReportDuplicateCondition($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$nearbyDistance = dcvsReportDuplicateDistanceMeters(14.3294, 120.9367, 14.3300, 120.9367);
$farDistance = dcvsReportDuplicateDistanceMeters(14.3294, 120.9367, 14.3340, 120.9367);
expectReportDuplicateCondition($nearbyDistance < 150, 'Nearby report pins must be measured within the configured default radius.');
expectReportDuplicateCondition($farDistance > 150, 'Pins outside the configured default radius must remain separate.');

$baseFields = [
    'reporter_id' => 42,
    'report_type' => 'stray',
    'report_datetime' => '2025-03-01 10:30:00',
    'animal_type' => 'Dog',
    'animal_breed' => 'Aspin',
    'animal_count' => '1',
    'animal_behavior' => 'Friendly',
    'barangay_id' => 3,
    'location_detail' => 'Near the covered court',
    'description' => 'Dog seen near the court',
    'latitude' => '14.32940000',
    'longitude' => '120.93670000',
];
$sameData = $baseFields;
$sameData['description'] = "  DOG   seen near the court  ";
expectReportDuplicateCondition(
    dcvsReportSubmissionFingerprint($baseFields) === dcvsReportSubmissionFingerprint($sameData),
    'Submission fingerprints must normalize case and repeated whitespace.'
);
$differentData = $baseFields;
$differentData['report_datetime'] = '2025-03-01 10:35:00';
expectReportDuplicateCondition(
    dcvsReportSubmissionFingerprint($baseFields) !== dcvsReportSubmissionFingerprint($differentData),
    'Different incident times must produce different submission fingerprints.'
);

