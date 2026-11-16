<?php

function dcvsLoadReportOverdueSlaSettings(mysqli $conn): array
{
    $stmt = $conn->prepare("
        SELECT high_sla_hours, medium_sla_hours, low_sla_hours
        FROM report_overdue_sla_settings
        WHERE setting_id = 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the report overdue SLA settings query: ' . $conn->error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not load report overdue SLA settings: ' . $error);
    }
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$settings) {
        throw new RuntimeException('Report overdue SLA settings are missing. Apply the report overdue SLA migration.');
    }

    foreach (['high_sla_hours', 'medium_sla_hours', 'low_sla_hours'] as $key) {
        $settings[$key] = filter_var($settings[$key], FILTER_VALIDATE_INT);
        if ($settings[$key] === false || $settings[$key] < 1 || $settings[$key] > 720) {
            throw new RuntimeException('Report overdue SLA settings must be between 1 and 720 hours.');
        }
    }

    return $settings;
}

function dcvsReportOverdueAction(array $report, string $reason): string
{
    switch (strtolower($reason)) {
        case 'not scheduled':
            $mode = strtolower((string)($report['response_mode'] ?? $report['responseMode'] ?? ''));
            $state = strtolower((string)($report['response_state'] ?? $report['responseState'] ?? ''));
            return $mode === 'dcvs_dispatch'
                || in_array($state, ['escalated_to_dcvs', 'dcvs_dispatch', 'dcvs_processing'], true)
                ? 'Dispatch'
                : 'Scheduling';
        case 'past scheduled date':
            return 'Visit';
        case 'initial response':
            return 'Review';
        case 'progress update':
            return 'Resolution';
        case 'dcvs turnover':
            return 'Turnover';
        default:
            return ucfirst($reason);
    }
}

function dcvsReportOverdueInfo(
    array $report,
    ?DateTimeImmutable $now = null,
    ?array $slaSettings = null
): ?array
{
    $timezone = new DateTimeZone('Asia/Manila');
    $now = $now ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);
    $status = strtolower((string)($report['status'] ?? $report['statusRaw'] ?? $report['_db_status'] ?? ''));
    $state = strtolower((string)($report['response_state'] ?? $report['responseState'] ?? ''));
    $caseStatus = strtolower((string)($report['case_status'] ?? $report['caseStatus'] ?? ''));
    $buildOverdueInfo = static function (string $reason, string $deadline) use ($report): array {
        $action = dcvsReportOverdueAction($report, $reason);
        return [
            'reason' => $reason,
            'action' => $action,
            'badge' => $action . ' overdue',
            'deadline' => $deadline,
        ];
    };
    $isPaused = filter_var(
        $report['deadline_paused'] ?? $report['deadlinePaused'] ?? $report['is_paused'] ?? $report['isPaused'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    );
    $pauseStartedAt = trim((string)($report['deadline_paused_at'] ?? $report['deadlinePausedAt'] ?? ''));
    $resumedAt = trim((string)($report['deadline_resumed_at'] ?? $report['deadlineResumedAt'] ?? ''));
    if ($isPaused
        || in_array($status, ['paused', 'on_hold'], true)
        || in_array($state, ['paused', 'on_hold'], true)
        || in_array($caseStatus, ['paused', 'on_hold'], true)
        || ($pauseStartedAt !== '' && $resumedAt === '')) {
        return null;
    }

    if (!in_array($status, ['pending', 'validated', 'confirmed', 'scheduled'], true)) {
        return null;
    }

    $candidates = [];

    if ($state === 'barangay_response_approved') {
        $candidates[] = ['Initial response', $report['response_deadline'] ?? $report['responseDeadline'] ?? ''];
    } elseif ($state === 'barangay_responding') {
        $candidates[] = ['Progress update', $report['progress_deadline'] ?? $report['progressDeadline'] ?? ''];
    } elseif ($state === 'awaiting_turnover') {
        $candidates[] = ['DCVS turnover', $report['turnover_deadline'] ?? $report['turnoverDeadline'] ?? ''];
    }

    $dcvsActionStates = [
        '',
        'awaiting_dcvs_decision',
        'dcvs_dispatch',
        'dcvs_processing',
        'escalated_to_dcvs',
        'turned_over',
    ];
    if ($caseStatus === 'active'
        && in_array($status, ['confirmed', 'scheduled'], true)
        && in_array($state, $dcvsActionStates, true)) {
        $scheduledAt = trim((string)($report['scheduled_at'] ?? $report['scheduledAt'] ?? ''));
        if ($scheduledAt !== '') {
            try {
                $deadline = new DateTimeImmutable($scheduledAt, $timezone);
            } catch (Exception $exception) {
                throw new RuntimeException('Invalid scheduled date for an active DCVS case.', 0, $exception);
            }
            return $deadline < $now
                ? $buildOverdueInfo('past scheduled date', $deadline->format('Y-m-d H:i:s'))
                : null;
        }

        $stageTimes = [];
        foreach (['escalated_at', 'assigned_at'] as $field) {
            $value = trim((string)($report[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            try {
                $stageTimes[] = new DateTimeImmutable($value, $timezone);
            } catch (Exception $exception) {
                throw new RuntimeException('Invalid DCVS stage timestamp.', 0, $exception);
            }
        }
        if (!$stageTimes) {
            return null;
        }
        if ($slaSettings === null) {
            throw new RuntimeException('DCVS overdue SLA settings are required to calculate an unscheduled case deadline.');
        }

        $urgency = strtolower(trim((string)($report['urgency_level'] ?? $report['urgency'] ?? '')));
        $slaKey = in_array($urgency, ['high', 'critical'], true)
            ? 'high_sla_hours'
            : ($urgency === 'medium' ? 'medium_sla_hours' : ($urgency === 'low' ? 'low_sla_hours' : ''));
        if ($slaKey === '') {
            throw new UnexpectedValueException('Cannot calculate a DCVS overdue deadline for an unknown report priority.');
        }
        $hours = filter_var($slaSettings[$slaKey] ?? null, FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > 720) {
            throw new RuntimeException('DCVS overdue SLA settings must be between 1 and 720 hours.');
        }

        usort($stageTimes, static function (DateTimeImmutable $left, DateTimeImmutable $right): int {
            return $left <=> $right;
        });
        $stageStart = $stageTimes[count($stageTimes) - 1];
        $deadline = $stageStart->modify('+' . $hours . ' hours');
        return $deadline < $now
            ? $buildOverdueInfo('not scheduled', $deadline->format('Y-m-d H:i:s'))
            : null;
    }

    foreach ($candidates as [$reason, $value]) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        try {
            $deadline = new DateTimeImmutable($value, $timezone);
        } catch (Exception $exception) {
            continue;
        }
        if ($deadline < $now) {
            return $buildOverdueInfo($reason, $deadline->format('Y-m-d H:i:s'));
        }
    }

    return null;
}
