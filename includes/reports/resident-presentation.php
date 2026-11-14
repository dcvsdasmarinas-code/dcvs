<?php

function dcvsResidentReportTableStatusLabel(array $report): string
{
    $status = strtolower(trim((string)($report['status'] ?? $report['statusRaw'] ?? '')));
    $labels = [
        'pending' => 'Pending Barangay Review',
        'validated' => 'Pending DCVS Review',
        'confirmed' => 'In Progress',
        'scheduled' => 'In Progress',
        'resolved' => 'Resolved',
        'rejected' => 'Closed',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function dcvsResidentReportTableStatusPresentation(array $report): array
{
    $status = strtolower(trim((string)($report['status'] ?? $report['statusRaw'] ?? '')));
    $presentations = [
        'pending' => [
            'class' => 'st-pending',
            'badgeClass' => 'badge-pending',
            'style' => ['bg' => '#FEF3C7', 'color' => '#92400E', 'border' => '#FDE68A'],
        ],
        'validated' => [
            'class' => 'st-awaiting',
            'badgeClass' => 'badge-confirmed',
            'style' => ['bg' => '#dcebe0', 'color' => '#045e1f', 'border' => '#bbf7d0'],
        ],
        'confirmed' => [
            'class' => 'st-review',
            'badgeClass' => 'badge-reviewing',
            'style' => ['bg' => '#EFF6FF', 'color' => '#1E40AF', 'border' => '#BFDBFE'],
        ],
        'scheduled' => [
            'class' => 'st-review',
            'badgeClass' => 'badge-reviewing',
            'style' => ['bg' => '#EFF6FF', 'color' => '#1E40AF', 'border' => '#BFDBFE'],
        ],
        'resolved' => [
            'class' => 'st-resolved',
            'badgeClass' => 'badge-confirmed',
            'style' => ['bg' => '#0E8131', 'color' => '#FFFFFF', 'border' => '#0E8131'],
        ],
        'rejected' => [
            'class' => 'st-rejected',
            'badgeClass' => 'badge-closed',
            'style' => ['bg' => '#FEE2E2', 'color' => '#991B1B', 'border' => '#FECACA'],
        ],
    ];

    return $presentations[$status] ?? [
        'class' => 'st-pending',
        'badgeClass' => 'badge-pending',
        'style' => ['bg' => '#FEF3C7', 'color' => '#92400E', 'border' => '#FDE68A'],
    ];
}

function dcvsResidentReportPresentation(array $report, ?array $overdueInfo = null): array
{
    $status = strtolower(trim((string)($report['status'] ?? $report['statusRaw'] ?? '')));
    $duplicateStatus = strtolower(trim((string)($report['duplicate_status'] ?? $report['duplicateStatus'] ?? '')));
    $state = strtolower(trim((string)($report['response_state'] ?? $report['responseState'] ?? '')));
    $mode = strtolower(trim((string)($report['response_mode'] ?? $report['responseMode'] ?? '')));
    $caseStatus = strtolower(trim((string)($report['case_status'] ?? $report['caseStatus'] ?? '')));
    $barangayName = trim((string)($report['barangay_name'] ?? $report['barangayName'] ?? ''));
    $barangay = $barangayName !== '' ? 'Barangay ' . $barangayName : 'your barangay';

    $make = static function (string $label, string $message, string $nextStep, string $tone, string $heading = ''): array {
        $tones = [
            'received' => [
                'class' => 'st-pending',
                'badge' => 'badge-pending',
                'style' => ['bg' => '#FEF3C7', 'color' => '#92400E', 'border' => '#FDE68A'],
            ],
            'review' => [
                'class' => 'st-review',
                'badge' => 'badge-scheduled',
                'style' => ['bg' => '#EFF6FF', 'color' => '#1E40AF', 'border' => '#BFDBFE'],
            ],
            'resolved' => [
                'class' => 'st-resolved',
                'badge' => 'badge-confirmed',
                'style' => ['bg' => '#0E8131', 'color' => '#FFFFFF', 'border' => '#0E8131'],
            ],
            'closed' => [
                'class' => 'st-rejected',
                'badge' => 'badge-pending',
                'style' => ['bg' => '#FEE2E2', 'color' => '#991B1B', 'border' => '#FECACA'],
            ],
        ];
        $tone = $tones[$tone] ?? $tones['received'];
        return [
            'statusLabel' => $label,
            'statusHeading' => $heading !== '' ? $heading : $message,
            'statusMessage' => $message,
            'nextStep' => $nextStep,
            'statusClass' => $tone['class'],
            'badgeClass' => $tone['badge'],
            'statusStyle' => $tone['style'],
        ];
    };

    if ($duplicateStatus === 'suspected') {
        return $make(
            'Received',
            'Your report is being reviewed.',
            'We are checking whether this report matches an existing report. You do not need to submit it again.',
            'received',
            'Your report was received'
        );
    }

    if ($status === 'resolved') {
        return $make('Resolved', 'This report has been resolved.', 'Review the resolution details below for more information.', 'resolved', 'Report resolved');
    }

    if ($status === 'rejected' || $state === 'closed_not_actionable') {
        return $make(
            'Closed',
            'This report has been closed after review.',
            'Check the Updates tab for details, or submit a new report if the concern is still happening.',
            'closed',
            'Report closed'
        );
    }

    $overdueAction = strtolower(trim((string)($overdueInfo['action'] ?? '')));
    if ($overdueAction === 'dispatch') {
        return $make(
            'With DCVS',
            'This is taking longer than usual. DCVS has been notified and is working on your report.',
            'You do not need to submit the report again. Check Updates for the next change.',
            'review',
            'DCVS is arranging a response'
        );
    }

    if ($state === 'dcvs_dispatch') {
        $message = $overdueInfo
            ? 'This is taking longer than usual. DCVS is following up on the response.'
            : 'DCVS has dispatched a response team. The team is on the way.';
        return $make('Team on the way', $message, 'Keep your phone available in case the response team needs more information.', 'review', 'A response team is on the way');
    }

    if ($state === 'barangay_responding') {
        $message = $overdueInfo
            ? 'This is taking longer than usual. ' . $barangay . ' is still handling your report.'
            : $barangay . ' is responding to your report.';
        return $make('Responding', $message, 'Check Updates for progress from the response team.', 'review', 'Response in progress');
    }

    if ($overdueInfo) {
        $dcvsStates = ['escalated_to_dcvs', 'turned_over', 'dcvs_processing', 'awaiting_turnover'];
        $barangayStates = ['barangay_response_approved', 'barangay_responding'];
        $withDcvs = $mode === 'dcvs_dispatch'
            || in_array($state, $dcvsStates, true)
            || $status === 'validated'
            || ($caseStatus === 'active' && !in_array($state, $barangayStates, true));
        $team = $withDcvs ? 'DCVS' : $barangay;
        return $make(
            $withDcvs ? 'With DCVS' : 'With your barangay',
            'This is taking longer than usual. ' . $team . ' is still handling your report.',
            'You do not need to submit the report again. Check Updates for the next change.',
            'review',
            $team . ' is handling your report'
        );
    }

    if ($state === 'escalated_to_dcvs') {
        return $make('With DCVS', 'DCVS is arranging a response team.', 'DCVS will update you when a response team has been dispatched.', 'review', 'DCVS is arranging a response');
    }

    if ($state === 'animal_secured') {
        return $make('Secured', 'The response team has secured the animal.', 'DCVS and the barangay are coordinating the next step.', 'review', 'The animal has been secured');
    }

    if ($state === 'awaiting_turnover') {
        return $make('With your barangay', $barangay . ' is coordinating the handover to DCVS.', 'The report will be updated when DCVS confirms receipt.', 'review', 'Handover in progress');
    }

    if ($state === 'turned_over' || $state === 'dcvs_processing') {
        return $make('With DCVS', 'DCVS is handling your case.', 'You will receive an update when the case moves forward.', 'review', 'Case in progress');
    }

    if ($status === 'pending') {
        return $make('With your barangay', $barangay . ' is reviewing your report.', 'You will receive an update when the barangay review is complete.', 'review', 'Awaiting barangay review');
    }

    if ($status === 'validated' || $state === 'awaiting_dcvs_decision') {
        return $make('With DCVS', 'DCVS is reviewing your report.', 'You will receive an update when DCVS confirms the response plan.', 'review', 'Awaiting DCVS review');
    }

    if ($state === 'barangay_response_approved') {
        return $make('With your barangay', $barangay . ' has accepted your report for handling.', 'The barangay will update you when the response begins.', 'review', 'Report accepted by barangay');
    }

    if ($status === 'confirmed' || $status === 'scheduled') {
        return $make('With DCVS', 'DCVS is preparing a response for your report.', 'You will receive an update when the response team is dispatched.', 'review', 'Response planning in progress');
    }

    return $make('Received', 'Your report is being reviewed.', 'You will receive an update when your report moves to the next step.', 'received', 'Report received');
}
