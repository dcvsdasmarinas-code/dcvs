<?php
require_once __DIR__ . '/../includes/reports/resident-presentation.php';

function expectResidentReportPresentation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectResidentReportPresentation(
    dcvsResidentReportTableStatusLabel(['status' => 'pending']) === 'Pending Barangay Review'
        && dcvsResidentReportTableStatusLabel(['status' => 'validated']) === 'Pending DCVS Review'
        && dcvsResidentReportTableStatusLabel(['status' => 'confirmed']) === 'In Progress'
        && dcvsResidentReportTableStatusLabel(['status' => 'resolved']) === 'Resolved'
        && dcvsResidentReportTableStatusLabel(['status' => 'rejected']) === 'Closed',
    'Report tables should use the workflow status rather than resident-facing location labels.'
);
expectResidentReportPresentation(
    dcvsResidentReportTableStatusPresentation(['status' => 'pending'])['style']['bg'] === '#FEF3C7'
        && dcvsResidentReportTableStatusPresentation(['status' => 'pending'])['badgeClass'] === 'badge-pending',
    'Pending report status pills should use the yellow pending style.'
);

$similarReport = dcvsResidentReportPresentation([
    'status' => 'pending',
    'duplicate_status' => 'suspected',
    'response_state' => 'awaiting_dcvs_decision',
], ['action' => 'Dispatch']);
expectResidentReportPresentation(
    $similarReport['statusLabel'] === 'Received'
        && $similarReport['statusMessage'] === 'Your report is being reviewed.',
    'A report held for a similar-report review should show Received, not an overdue status.'
);

$barangayReview = dcvsResidentReportPresentation([
    'status' => 'pending',
    'barangay_name' => 'Paliparan III',
]);
expectResidentReportPresentation(
    $barangayReview['statusLabel'] === 'With your barangay'
        && $barangayReview['statusMessage'] === 'Barangay Paliparan III is reviewing your report.',
    'Pending barangay review should name the barangay in resident-friendly language.'
);

$dcvsEscalation = dcvsResidentReportPresentation([
    'status' => 'confirmed',
    'response_mode' => 'dcvs_dispatch',
    'response_state' => 'escalated_to_dcvs',
]);
expectResidentReportPresentation(
    $dcvsEscalation['statusLabel'] === 'With DCVS'
        && $dcvsEscalation['statusMessage'] === 'DCVS is arranging a response team.',
    'An escalated case without dispatch should explain that DCVS is arranging a team.'
);

$dispatchOverdue = dcvsResidentReportPresentation([
    'status' => 'confirmed',
    'response_mode' => 'dcvs_dispatch',
    'response_state' => 'escalated_to_dcvs',
], ['action' => 'Dispatch']);
expectResidentReportPresentation(
    $dispatchOverdue['statusLabel'] === 'With DCVS'
        && $dispatchOverdue['statusHeading'] === 'DCVS is arranging a response'
        && $dispatchOverdue['statusMessage'] === 'This is taking longer than usual. DCVS has been notified and is working on your report.',
    'Dispatch overdue should retain the resident-facing DCVS status and show the follow-up message.'
);

$dispatched = dcvsResidentReportPresentation([
    'status' => 'confirmed',
    'response_mode' => 'dcvs_dispatch',
    'response_state' => 'dcvs_dispatch',
]);
expectResidentReportPresentation(
    $dispatched['statusLabel'] === 'Team on the way',
    'A dispatched case should tell the resident that the team is on the way.'
);

$barangayResponding = dcvsResidentReportPresentation([
    'status' => 'validated',
    'response_mode' => 'barangay_response',
    'response_state' => 'barangay_responding',
    'barangay_name' => 'Salawag',
]);
expectResidentReportPresentation(
    $barangayResponding['statusLabel'] === 'Responding'
        && $barangayResponding['statusMessage'] === 'Barangay Salawag is responding to your report.',
    'A Barangay response should be shown as Responding.'
);

$resolved = dcvsResidentReportPresentation(['status' => 'resolved']);
expectResidentReportPresentation(
    $resolved['statusLabel'] === 'Resolved',
    'A completed report should retain the Resolved status.'
);

echo "Resident report presentation checks passed.\n";
