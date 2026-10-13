<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../env.php';

loadDcvsEnv();
if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') !== '1') {
    fwrite(STDERR, "Barangay response workflow is disabled; no cases were escalated.\n");
    exit(1);
}

$dueQuery = $conn->query("
    SELECT rw.report_id, rw.state, rw.response_deadline, rw.progress_deadline,
           r.reference_id, r.reporter_id, r.barangay_id
    FROM report_response_workflow rw
    JOIN reports r ON r.report_id = rw.report_id
    WHERE r.status = 'confirmed' AND r.archived_at IS NULL
      AND (
        (rw.state = 'barangay_response_approved' AND rw.response_deadline <= NOW())
        OR (rw.state = 'barangay_responding' AND rw.progress_deadline <= NOW())
      )
    ORDER BY COALESCE(rw.response_deadline, rw.progress_deadline), rw.report_id
    LIMIT 100
");
if (!$dueQuery) {
    fwrite(STDERR, 'Could not load overdue response cases: ' . $conn->error . "\n");
    exit(1);
}

$dueCases = [];
while ($case = $dueQuery->fetch_assoc()) {
    $dueCases[] = $case;
}
$dueQuery->close();

$escalatedCount = 0;
foreach ($dueCases as $case) {
    $reportId = (int)$case['report_id'];
    $fromState = (string)$case['state'];
    $deadline = $fromState === 'barangay_responding'
        ? (string)$case['progress_deadline']
        : (string)$case['response_deadline'];
    $reference = trim((string)$case['reference_id']) ?: 'Report #' . $reportId;
    $reason = 'Barangay response deadline missed.';

    if (!$conn->begin_transaction()) {
        fwrite(STDERR, "Could not begin escalation transaction for report {$reportId}: {$conn->error}\n");
        exit(1);
    }

    try {
        $update = $conn->prepare("
            UPDATE report_response_workflow
            SET response_mode = 'dcvs_dispatch', state = 'escalated_to_dcvs',
                response_deadline = NULL, progress_deadline = NULL, last_reminded_at = NOW()
            WHERE report_id = ? AND state = ?
              AND (
                (state = 'barangay_response_approved' AND response_deadline <= NOW())
                OR (state = 'barangay_responding' AND progress_deadline <= NOW())
              )
        ");
        if (!$update) throw new RuntimeException('Could not prepare the overdue workflow update: ' . $conn->error);
        $update->bind_param('is', $reportId, $fromState);
        if (!$update->execute()) throw new RuntimeException('Could not escalate the overdue workflow: ' . $update->error);
        $updated = $update->affected_rows === 1;
        $update->close();

        if (!$updated) {
            if (!$conn->commit()) throw new RuntimeException('Could not finish checking a changed workflow: ' . $conn->error);
            continue;
        }

        $actorId = null;
        $actorRole = 'system';
        $action = 'auto_escalate_overdue';
        $toState = 'escalated_to_dcvs';
        $history = $conn->prepare("
            INSERT INTO report_response_history
                (report_id, actor_id, actor_role, action, from_state, to_state, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$history) throw new RuntimeException('Could not prepare escalation history: ' . $conn->error);
        $history->bind_param('iisssss', $reportId, $actorId, $actorRole, $action, $fromState, $toState, $reason);
        if (!$history->execute()) throw new RuntimeException('Could not record escalation history: ' . $history->error);
        $history->close();

        $title = 'Overdue Case Escalated to DCVS';
        $message = $reference . ' missed its barangay response target and was automatically moved to the DCVS dispatch queue.';
        $referenceType = 'report';
        $notificationType = 'report_response_update';
        $staffAndBarangay = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, reference_type, reference_id)
            SELECT u.user_id, ?, ?, ?, ?, ?
            FROM users u
            WHERE u.status = 'active' AND u.deleted_at IS NULL
              AND (u.role = 'staff' OR (u.role = 'barangay' AND u.barangay_id = ?))
        ");
        if (!$staffAndBarangay) throw new RuntimeException('Could not prepare escalation notifications: ' . $conn->error);
        $barangayId = (int)$case['barangay_id'];
        $staffAndBarangay->bind_param(
            'ssssii',
            $notificationType,
            $title,
            $message,
            $referenceType,
            $reportId,
            $barangayId
        );
        if (!$staffAndBarangay->execute()) throw new RuntimeException('Could not notify staff and barangay: ' . $staffAndBarangay->error);
        $staffAndBarangay->close();

        $residentNotification = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, reference_type, reference_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$residentNotification) throw new RuntimeException('Could not prepare resident escalation notification: ' . $conn->error);
        $reporterId = (int)$case['reporter_id'];
        $residentNotification->bind_param(
            'issssi',
            $reporterId,
            $notificationType,
            $title,
            $message,
            $referenceType,
            $reportId
        );
        if (!$residentNotification->execute()) throw new RuntimeException('Could not notify the resident: ' . $residentNotification->error);
        $residentNotification->close();

        if (!$conn->commit()) throw new RuntimeException('Could not commit overdue escalation: ' . $conn->error);
        $escalatedCount++;
    } catch (Throwable $exception) {
        $conn->rollback();
        fwrite(STDERR, "Escalation failed for report {$reportId}: {$exception->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Escalated {$escalatedCount} overdue response case(s) to DCVS.\n");
