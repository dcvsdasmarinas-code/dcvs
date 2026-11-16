<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';
require_once __DIR__ . '/../../includes/reports/response-workflow.php';

function resolveReportRespond($success, $message, $data = []) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $data));
        exit;
    }
    $_SESSION[$success ? 'report_success' : 'report_error'] = $message;
    header('Location: dashboard-staff.php#' . ($success ? 'reports-resolved' : 'reports-active'));
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        resolveReportRespond(false, 'Your session could not be verified. Refresh and try again.');
    }
    $caseId = intval($_POST['case_id'] ?? 0);
    $resolutionNotes = trim($_POST['notes'] ?? '');
    $resolvedDate = trim($_POST['resolved_date'] ?? '');
    $resolvedTime = trim($_POST['resolved_time'] ?? '');

    if ($caseId === 0) {
        resolveReportRespond(false, 'Invalid case ID.');
    }
    $resolvedDateTime = DateTime::createFromFormat('!Y-m-d H:i', $resolvedDate . ' ' . $resolvedTime);
    $dateTimeErrors = DateTime::getLastErrors();
    if (!$resolvedDateTime
        || ($dateTimeErrors && ($dateTimeErrors['warning_count'] > 0 || $dateTimeErrors['error_count'] > 0))
        || $resolvedDateTime->format('Y-m-d H:i') !== $resolvedDate . ' ' . $resolvedTime) {
        http_response_code(400);
        resolveReportRespond(false, 'Enter a valid resolution date and time.');
    }
    $resolvedAt = $resolvedDateTime->format('Y-m-d H:i:s');

    // Fetch confirmed case details
    $stmt = $conn->prepare("SELECT cc.*, r.report_id, r.reporter_id, r.barangay_id, r.report_type, r.animal_count, u.full_name AS reporter_name FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id LEFT JOIN users u ON u.user_id = r.reporter_id WHERE cc.case_id = ? AND cc.status = 'active'");
    $stmt->bind_param("i", $caseId);
    $stmt->execute();
    $case = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$case) {
        resolveReportRespond(false, 'Case not found or already resolved.');
    }

    $reportId = $case['report_id'];

    $conn->begin_transaction();

    try {
        loadDcvsEnv();
        if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1') {
            $workflowStateQuery = $conn->prepare("SELECT state FROM report_response_workflow WHERE report_id = ? FOR UPDATE");
            if (!$workflowStateQuery) throw new RuntimeException('Could not load response workflow.');
            $workflowStateQuery->bind_param('i', $reportId);
            if (!$workflowStateQuery->execute()) throw new RuntimeException('Could not load response workflow.');
            $workflowStateRow = $workflowStateQuery->get_result()->fetch_assoc();
            $workflowStateQuery->close();
            $workflowState = (string)($workflowStateRow['state'] ?? '');
            if (!dcvsResponseCanResolveCase('staff', $workflowState)) {
                $conn->rollback();
                http_response_code(409);
                resolveReportRespond(false, 'Complete DCVS dispatch or processing before resolving this response-workflow case.');
            }
        }

        // Update confirmed_cases status to resolved
        $updateCaseStmt = $conn->prepare("UPDATE confirmed_cases SET status = 'resolved', resolved_at = ?, notes = ? WHERE case_id = ?");
        if (!$updateCaseStmt) throw new RuntimeException('Could not update the case.');
        $updateCaseStmt->bind_param("ssi", $resolvedAt, $resolutionNotes, $caseId);
        if (!$updateCaseStmt->execute() || $updateCaseStmt->affected_rows !== 1) throw new RuntimeException('Case was already updated.');
        $updateCaseStmt->close();

        // Update report status to resolved
        $updateReportStmt = $conn->prepare("UPDATE reports SET status = 'resolved' WHERE report_id = ?");
        if (!$updateReportStmt) throw new RuntimeException('Could not update the report status.');
        $updateReportStmt->bind_param("i", $reportId);
        if (!$updateReportStmt->execute()) throw new RuntimeException('Could not update the report status.');
        $updateReportStmt->close();
        if (stripos((string)$case['report_type'], 'dead') === false) {
            $turnoverExists = $conn->prepare("SELECT turnover_task_id FROM report_turnover_tasks
                WHERE report_id = ? LIMIT 1 FOR UPDATE");
            if (!$turnoverExists) throw new RuntimeException('Could not check whether the turnover task already exists.');
            $turnoverExists->bind_param('i', $reportId);
            if (!$turnoverExists->execute()) throw new RuntimeException('Could not check whether the turnover task already exists.');
            $existingTurnover = $turnoverExists->get_result()->fetch_assoc();
            $turnoverExists->close();
            if (!$existingTurnover) {
                $turnoverHours = (int)env('DCVS_TURNOVER_TARGET_HOURS', '24');
                if ($turnoverHours < 1 || $turnoverHours > 720) $turnoverHours = 24;
                $turnoverTask = $conn->prepare("INSERT INTO report_turnover_tasks
                    (report_id, status, animal_count, animal_disposition, task_notes, due_at, requested_by, requested_at)
                    VALUES (?, 'pending', ?, 'other', ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?, NOW())");
                if (!$turnoverTask) throw new RuntimeException('Could not prepare the animal turnover task.');
                $animalCount = (string)($case['animal_count'] ?? '');
                $turnoverTask->bind_param('issii', $reportId, $animalCount, $resolutionNotes, $turnoverHours, $_SESSION['user_id']);
                if (!$turnoverTask->execute()) throw new RuntimeException('Could not create the animal turnover task.');
                $turnoverTask->close();
            }
        }
        if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1') {
            $workflowStmt = $conn->prepare("UPDATE report_response_workflow SET state = 'resolved' WHERE report_id = ? AND state NOT IN ('resolved', 'closed_not_actionable')");
            if (!$workflowStmt) throw new RuntimeException('Could not update response workflow.');
            $workflowStmt->bind_param('i', $reportId);
            if (!$workflowStmt->execute() || $workflowStmt->affected_rows !== 1) throw new RuntimeException('Could not update response workflow.');
            $workflowStmt->close();
            $fromState = $workflowState;
            $history = $conn->prepare("INSERT INTO report_response_history (report_id, actor_id, actor_role, action, from_state, to_state, reason) VALUES (?, ?, 'staff', 'resolve_case', ?, 'resolved', ?)");
            if (!$history) throw new RuntimeException('Could not prepare response history.');
            $staffId = (int)$_SESSION['user_id'];
            $history->bind_param('iiss', $reportId, $staffId, $fromState, $resolutionNotes);
            if (!$history->execute()) throw new RuntimeException('Could not save response history.');
            $history->close();
        }

        // Notify resident
        createNotification($conn, $case['reporter_id'], 'report_resolved',
            'Report Resolved',
            'Your report has been marked as resolved by DCVS.' . ($resolutionNotes ? ' Notes: ' . $resolutionNotes : ''),
            'report', $reportId);
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendReportEmail($conn, $reportId, 'resolved', $resolutionNotes, $staffName);
        sendReportSms($conn, $reportId, 'resolved', $staffName, $resolutionNotes);

        // Notify barangay officers
        if ($case['barangay_id']) {
            $bNotifStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            $bNotifStmt->bind_param("i", $case['barangay_id']);
            $bNotifStmt->execute();
            $bOfficers = $bNotifStmt->get_result();
            while ($bo = $bOfficers->fetch_assoc()) {
                createNotification($conn, $bo['user_id'], 'report_resolved',
                    'Case Resolved by DCVS',
                    'A case from your barangay has been resolved by DCVS.' . ($resolutionNotes ? ' Notes: ' . $resolutionNotes : ''),
                    'report', $reportId);
            }
            $bNotifStmt->close();
        }

        // Log activity
        $action = "Case Resolved";
        $details = "Resolved the " . ucfirst($case['report_type']) . " case reported by " . ($case['reporter_name'] ?: 'a resident') . ".";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();

        resolveReportRespond(true, 'Case marked as resolved successfully.', [
            'report_id' => (int)$reportId,
            'case_id' => (int)$caseId,
            'resolved_at' => $resolvedDateTime->format('M d, Y h:i A'),
            'resolved_at_raw' => $resolvedAt,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        resolveReportRespond(false, 'Failed to resolve case: ' . $e->getMessage());
    }
}

$conn->close();
?>