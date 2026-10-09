<?php

function dcvsResponseTransition(string $action): ?array {
    static $transitions = [
        'authorize_high_risk_override' => ['role' => 'staff', 'from' => ['awaiting_dcvs_decision'], 'to' => 'awaiting_dcvs_decision', 'reason' => true],
        'return_for_validation' => ['role' => 'staff', 'from' => ['awaiting_dcvs_decision'], 'to' => 'awaiting_dcvs_decision', 'reason' => true],
        'reject_report' => ['role' => 'staff', 'from' => ['awaiting_dcvs_decision'], 'to' => 'closed_not_actionable', 'reason' => true],
        'start_response' => ['role' => 'barangay', 'from' => ['barangay_response_approved'], 'to' => 'barangay_responding'],
        'update_progress' => ['role' => 'barangay', 'from' => ['barangay_responding', 'animal_secured'], 'to' => 'barangay_responding', 'reason' => true],
        'resolve_barangay' => ['role' => 'barangay', 'from' => ['animal_secured'], 'to' => 'resolved'],
        'unable_to_secure' => ['role' => 'barangay', 'from' => ['barangay_response_approved', 'barangay_responding'], 'to' => 'escalated_to_dcvs', 'reason' => true],
        'unable_to_locate' => ['role' => 'barangay', 'from' => ['barangay_responding'], 'to' => 'unable_to_locate', 'reason' => true],
        'mark_secured' => ['role' => 'barangay', 'from' => ['barangay_responding'], 'to' => 'animal_secured'],
        'request_pickup' => ['role' => 'barangay', 'from' => ['animal_secured'], 'to' => 'awaiting_turnover'],
        'revoke_approval' => ['role' => 'staff', 'from' => ['barangay_response_approved', 'barangay_responding'], 'to' => 'dcvs_dispatch', 'reason' => true],
        'confirm_turnover' => ['role' => 'staff', 'from' => ['awaiting_turnover', 'resolved'], 'to' => 'resolved'],
        'begin_processing' => ['role' => 'staff', 'from' => ['turned_over'], 'to' => 'dcvs_processing'],
        'return_to_barangay' => ['role' => 'staff', 'from' => ['animal_secured', 'awaiting_turnover', 'turned_over'], 'to' => 'returned_to_barangay', 'reason' => true],
        'switch_to_dispatch' => ['role' => 'staff', 'from' => ['animal_secured', 'awaiting_turnover'], 'to' => 'awaiting_turnover', 'reason' => true],
        'resume_barangay_response' => ['role' => 'barangay', 'from' => ['returned_to_barangay'], 'to' => 'barangay_responding'],
        'close_not_actionable' => ['role' => 'staff', 'from' => ['unable_to_locate', 'escalated_to_dcvs'], 'to' => 'closed_not_actionable', 'reason' => true],
        'reopen_unable_to_locate' => ['role' => 'staff', 'from' => ['unable_to_locate'], 'to' => 'dcvs_dispatch', 'reason' => true],
        'dispatch_escalation' => ['role' => 'staff', 'from' => ['escalated_to_dcvs'], 'to' => 'dcvs_dispatch', 'reason' => true],
    ];
    return $transitions[$action] ?? null;
}

function dcvsResponseCanTransition(string $action, string $role, string $state): bool {
    $transition = dcvsResponseTransition($action);
    return $transition !== null
        && $transition['role'] === $role
        && in_array($state, $transition['from'], true);
}

function dcvsResponseCanResolveCase(string $role, string $state): bool {
    return $role === 'staff' && in_array($state, ['dcvs_dispatch', 'dcvs_processing'], true);
}

function dcvsResponseHasBarangayProposal(array $report): bool {
    return (int)($report['barangay_proposed'] ?? 0) === 1;
}

function dcvsResponseIsHighRisk(array $report): bool {
    $riskText = strtolower(implode(' ', [
        (string)($report['report_type'] ?? ''),
        (string)($report['animal_count'] ?? ''),
        (string)($report['animal_behavior'] ?? ''),
        (string)($report['description'] ?? ''),
        (string)($report['urgency_level'] ?? ''),
    ]));
    return ($report['report_type'] ?? '') === 'rabies'
        || in_array(strtolower((string)($report['urgency_level'] ?? '')), ['high', 'critical'], true)
        || preg_match('/bit(e|ten)|aggress|rabies|sever|malubha|critical/', $riskText) === 1
        || preg_match('/(?:^|[^0-9])(?:6\\+|[6-9]|[1-9][0-9]+)(?:\\s|$)/', (string)($report['animal_count'] ?? '')) === 1;
}
