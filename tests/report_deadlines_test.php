<?php
require_once __DIR__ . '/../includes/reports/deadlines.php';

function expectReportOverdue($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = new DateTimeImmutable('2026-03-15 12:00:00', new DateTimeZone('Asia/Manila'));
$slaSettings = [
    'high_sla_hours' => 4,
    'medium_sla_hours' => 24,
    'low_sla_hours' => 72,
];

$initialResponse = dcvsReportOverdueInfo([
    'status' => 'validated',
    'response_state' => 'barangay_response_approved',
    'response_deadline' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($initialResponse !== null && $initialResponse['reason'] === 'Initial response', 'An expired initial response target should be overdue.');
expectReportOverdue(
    $initialResponse['action'] === 'Review' && $initialResponse['badge'] === 'Review overdue',
    'An expired initial response target should use the action-based review badge.'
);

$missedInitialResponse = dcvsReportOverdueInfo([
    'status' => 'validated',
    'response_state' => 'barangay_responding',
    'response_deadline' => '2026-03-15 10:00:00',
    'progress_deadline' => '2026-03-15 13:00:00',
], $now);
expectReportOverdue($missedInitialResponse === null, 'A stale initial-response target must not override a current progress target.');

$expiredProgress = dcvsReportOverdueInfo([
    'status' => 'validated',
    'response_state' => 'barangay_responding',
    'progress_deadline' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($expiredProgress !== null && $expiredProgress['reason'] === 'Progress update', 'An expired progress target should be overdue.');
expectReportOverdue(
    $expiredProgress['action'] === 'Resolution' && $expiredProgress['badge'] === 'Resolution overdue',
    'An expired progress target should use the action-based resolution badge.'
);

$pendingTurnover = dcvsReportOverdueInfo([
    'status' => 'resolved',
    'turnover_status' => 'pending',
    'turnover_due_at' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($pendingTurnover === null, 'A resolved report is not an active report overdue under the active-status rule.');

$completedTurnover = dcvsReportOverdueInfo([
    'status' => 'resolved',
    'turnover_status' => 'receipt_confirmed',
    'turnover_due_at' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($completedTurnover === null, 'A completed turnover must not remain overdue.');

$turnoverHandoff = dcvsReportOverdueInfo([
    'status' => 'validated',
    'response_state' => 'awaiting_turnover',
    'turnover_deadline' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($turnoverHandoff !== null && $turnoverHandoff['reason'] === 'DCVS turnover', 'An expired workflow turnover target should be overdue.');
expectReportOverdue($turnoverHandoff['badge'] === 'Turnover overdue', 'An expired turnover target should use an action-based badge.');

$dcvsActionOverdue = dcvsReportOverdueInfo([
    'status' => 'scheduled',
    'case_status' => 'active',
    'scheduled_at' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue(
    $dcvsActionOverdue !== null
        && $dcvsActionOverdue['action'] === 'Visit'
        && $dcvsActionOverdue['badge'] === 'Visit overdue',
    'An active DCVS case past its scheduled action should show the scheduled-date reason.'
);

$dcvsActionNotDue = dcvsReportOverdueInfo([
    'status' => 'scheduled',
    'case_status' => 'active',
    'scheduled_at' => '2026-03-15 13:00:00',
], $now);
expectReportOverdue($dcvsActionNotDue === null, 'A future DCVS scheduled action must not be overdue.');

$highPriorityUnscheduled = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'high',
    'escalated_at' => '2026-03-15 06:00:00',
    'assigned_at' => '2026-03-15 07:00:00',
], $now, $slaSettings);
expectReportOverdue(
    $highPriorityUnscheduled !== null
        && $highPriorityUnscheduled['action'] === 'Dispatch'
        && $highPriorityUnscheduled['badge'] === 'Dispatch overdue'
        && $highPriorityUnscheduled['deadline'] === '2026-03-15 11:00:00',
    'High-priority unscheduled cases must use the latest stage timestamp plus the configured four-hour SLA.'
);

$schedulingOverdue = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_mode' => 'barangay_response',
    'urgency_level' => 'high',
    'escalated_at' => '2026-03-15 06:00:00',
], $now, $slaSettings);
expectReportOverdue(
    $schedulingOverdue !== null && $schedulingOverdue['badge'] === 'Scheduling overdue',
    'An unscheduled visit in Barangay response mode should use the scheduling badge.'
);

$criticalUsesHighSla = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'critical',
    'escalated_at' => '2026-03-15 07:00:00',
], $now, $slaSettings);
expectReportOverdue(
    $criticalUsesHighSla !== null && $criticalUsesHighSla['deadline'] === '2026-03-15 11:00:00',
    'Critical priority must use the configurable high-priority SLA.'
);

$customHighSla = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'high',
    'escalated_at' => '2026-03-15 07:00:00',
], $now, [
    'high_sla_hours' => 6,
    'medium_sla_hours' => 30,
    'low_sla_hours' => 80,
]);
expectReportOverdue($customHighSla === null, 'The evaluator must honor admin-configured values instead of fixed SLA durations.');

$mediumPriorityNotYetDue = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'escalated_to_dcvs',
    'urgency_level' => 'medium',
    'escalated_at' => '2026-03-14 13:00:00',
], $now, $slaSettings);
expectReportOverdue($mediumPriorityNotYetDue === null, 'A medium-priority case is not overdue until its full configured calendar-hour SLA passes.');

$lowPriorityOverdue = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_processing',
    'urgency_level' => 'low',
    'escalated_at' => '2026-03-12 11:00:00',
], $now, $slaSettings);
expectReportOverdue(
    $lowPriorityOverdue !== null && $lowPriorityOverdue['deadline'] === '2026-03-15 11:00:00',
    'A low-priority case must use the configured 72 calendar-hour SLA.'
);

$unscheduledWithoutStageTimestamp = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'high',
], $now, $slaSettings);
expectReportOverdue($unscheduledWithoutStageTimestamp === null, 'An unscheduled case without an escalation or assignment timestamp must not use its submission date.');

$scheduledOverridesUnscheduledSla = dcvsReportOverdueInfo([
    'status' => 'scheduled',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'high',
    'scheduled_at' => '2026-03-15 13:00:00',
    'escalated_at' => '2026-03-15 01:00:00',
], $now, $slaSettings);
expectReportOverdue($scheduledOverridesUnscheduledSla === null, 'A future scheduled date replaces the unscheduled SLA instead of falling back to an expired SLA.');

$equalToDeadlineIsNotOverdue = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'high',
    'escalated_at' => '2026-03-15 08:00:00',
], new DateTimeImmutable('2026-03-15 12:00:00', new DateTimeZone('Asia/Manila')), $slaSettings);
expectReportOverdue($equalToDeadlineIsNotOverdue === null, 'A case is overdue only after now is strictly greater than its deadline.');

$resolvedDcvsAction = dcvsReportOverdueInfo([
    'status' => 'resolved',
    'case_status' => 'resolved',
    'scheduled_at' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($resolvedDcvsAction === null, 'A resolved DCVS case must not be overdue from its old scheduled action.');

$pausedResponse = dcvsReportOverdueInfo([
    'status' => 'validated',
    'response_state' => 'barangay_responding',
    'progress_deadline' => '2026-03-15 11:00:00',
    'deadline_paused' => true,
], $now);
expectReportOverdue($pausedResponse === null, 'A paused response deadline must not be overdue.');

$pausedDcvsCase = dcvsReportOverdueInfo([
    'status' => 'confirmed',
    'case_status' => 'active',
    'response_state' => 'dcvs_dispatch',
    'urgency_level' => 'high',
    'escalated_at' => '2026-03-15 01:00:00',
    'deadline_paused' => true,
], $now, $slaSettings);
expectReportOverdue($pausedDcvsCase === null, 'A paused DCVS SLA must not be overdue.');

$dcvsReviewNoDeadline = dcvsReportOverdueInfo([
    'status' => 'validated',
    'response_state' => 'awaiting_dcvs_decision',
    'response_deadline' => '2026-03-15 11:00:00',
], $now);
expectReportOverdue($dcvsReviewNoDeadline === null, 'A stale deadline from another phase must not mark DCVS review overdue.');

echo "Report deadline checks passed.\n";
