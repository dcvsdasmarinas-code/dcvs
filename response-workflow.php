<?php
session_name(trim((string)($_GET['sess'] ?? '')) === 'DCVS_BARANGAY_SESSION'
    ? 'DCVS_BARANGAY_SESSION'
    : 'DCVS_STAFF_SESSION');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/reports/location.php';
require_once __DIR__ . '/includes/reports/response-workflow.php';

$workflowTimezone = new DateTimeZone('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function responseWorkflowReply(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function responseWorkflowStateStillMatches($conn, int $reportId, string $expectedState): bool {
    $check = $conn->prepare("SELECT report_id FROM report_response_workflow WHERE report_id = ? AND state = ? LIMIT 1");
    if (!$check) return false;
    $check->bind_param('is', $reportId, $expectedState);
    $matches = $check->execute() && (bool)$check->get_result()->fetch_assoc();
    $check->close();
    return $matches;
}

loadDcvsEnv();
if (env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') !== '1') {
    responseWorkflowReply(503, ['success' => false, 'message' => 'Barangay response workflow is not enabled.']);
}

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if (($_SESSION['logged_in'] ?? false) !== true || !in_array($role, ['staff', 'barangay'], true) || $userId <= 0) {
    responseWorkflowReply(401, ['success' => false, 'message' => 'Sign in to continue.']);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    responseWorkflowReply(403, ['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
}

$reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
$action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
$reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
if (!$reportId || $reportId <= 0 || $action === '') {
    responseWorkflowReply(400, ['success' => false, 'message' => 'Invalid response action.']);
}

if ($role === 'staff' && in_array($action, ['staff_decide', 'return_for_validation', 'reject_report'], true)) {
    $initializeWorkflow = $conn->prepare("
        INSERT IGNORE INTO report_response_workflow (report_id, response_mode, state, barangay_proposed)
        SELECT report_id, 'undecided', 'awaiting_dcvs_decision', 0
        FROM reports
        WHERE report_id = ? AND status = 'validated' AND archived_at IS NULL
    ");
    if (!$initializeWorkflow) {
        responseWorkflowReply(500, ['success' => false, 'message' => 'Could not initialize this report for a DCVS decision.']);
    }
    $initializeWorkflow->bind_param('i', $reportId);
    if (!$initializeWorkflow->execute()) {
        $initializeWorkflow->close();
        error_log('Could not initialize response workflow before staff decision: ' . $conn->error);
        responseWorkflowReply(500, ['success' => false, 'message' => 'Could not initialize this report for a DCVS decision.']);
    }
    $initializeWorkflow->close();
}

$stmt = $conn->prepare("SELECT r.report_id, r.reporter_id, r.report_type, r.animal_count, r.animal_behavior, r.urgency_level,
        r.description, r.status AS report_status, r.barangay_id, r.urgency_level,
        w.state, w.response_mode, w.holding_location, w.animal_condition,
        w.response_deadline, w.turnover_deadline, w.barangay_proposed,
        w.high_risk_override_by, w.high_risk_override_reason,
        rr.animal_disposition
    FROM reports r
    LEFT JOIN report_response_workflow w ON w.report_id = r.report_id
    LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
    WHERE r.report_id = ? AND r.archived_at IS NULL");
if (!$stmt) responseWorkflowReply(500, ['success' => false, 'message' => 'Could not load this case.']);
$stmt->bind_param('i', $reportId);
if (!$stmt->execute()) responseWorkflowReply(500, ['success' => false, 'message' => 'Could not load this case.']);
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$report) responseWorkflowReply(404, ['success' => false, 'message' => 'Report not found.']);
if ($role === 'barangay' && (!$barangayId || (int)$report['barangay_id'] !== $barangayId)) {
    responseWorkflowReply(403, ['success' => false, 'message' => 'This case is outside your barangay.']);
}

$state = (string)($report['state'] ?? '');
$target = '';
$isHandoverPlan = false;
$isResolvedReceipt = false;
$mode = (string)($report['response_mode'] ?? 'undecided');
if ($role === 'barangay' && $mode !== 'barangay_response') {
    responseWorkflowReply(403, ['success' => false, 'message' => 'This case is not assigned to your barangay for response.']);
}
$highRisk = dcvsResponseIsHighRisk($report);
$dispatchOfficerId = null;
$dispatchOfficerName = '';
if ($action === 'authorize_high_risk_override') {
    if ($role !== 'staff' || $state !== 'awaiting_dcvs_decision' || $report['report_status'] !== 'validated'
        || !dcvsResponseCanTransition($action, $role, $state)
        || !dcvsResponseHasBarangayProposal($report) || !$highRisk) {
        responseWorkflowReply(409, ['success' => false, 'message' => 'A high-risk override cannot be recorded for this case.']);
    }
    if ($reason === '') {
        responseWorkflowReply(400, ['success' => false, 'message' => 'A reason is required for the high-risk override.']);
    }
    $target = $state;
} elseif (in_array($action, ['return_for_validation', 'reject_report'], true)) {
    if ($role !== 'staff' || $report['report_status'] !== 'validated'
        || !dcvsResponseCanTransition($action, $role, $state)) {
        responseWorkflowReply(409, ['success' => false, 'message' => 'This report is not awaiting a DCVS decision.']);
    }
    if ($reason === '') {
        responseWorkflowReply(400, ['success' => false, 'message' => 'A reason is required for this action.']);
    }
    $target = $action === 'reject_report' ? 'closed_not_actionable' : $state;
} elseif ($action === 'staff_decide') {
    if ($role !== 'staff' || $state !== 'awaiting_dcvs_decision' || $report['report_status'] !== 'validated') {
        responseWorkflowReply(409, ['success' => false, 'message' => 'This report is not awaiting a DCVS response decision.']);
    }
    $mode = is_string($_POST['response_mode'] ?? null) ? $_POST['response_mode'] : '';
    if ($mode !== 'dcvs_dispatch') {
        responseWorkflowReply(400, ['success' => false, 'message' => 'Barangay response is assigned during barangay review; DCVS may only dispatch a DCVS-handled report.']);
    }
    $dispatchOfficerId = $userId;
    $officerCheck = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? AND role = 'staff' AND status = 'active' AND deleted_at IS NULL");
    if (!$officerCheck) responseWorkflowReply(500, ['success' => false, 'message' => 'Could not verify the active DCVS staff member.']);
    $officerCheck->bind_param('i', $dispatchOfficerId);
    if (!$officerCheck->execute() || !($officerRow = $officerCheck->get_result()->fetch_assoc())) {
        $officerCheck->close();
        responseWorkflowReply(403, ['success' => false, 'message' => 'Only an active DCVS staff member can confirm this dispatch.']);
    }
    $dispatchOfficerName = (string)$officerRow['full_name'];
    $officerCheck->close();
    $reason = 'Dispatch confirmed by ' . $dispatchOfficerName . ($reason !== '' ? '. ' . $reason : '.');
    $target = $mode;
} else {
    $isHandoverPlan = $action === 'set_handover_plan';
    $isResolvedReceipt = $action === 'confirm_turnover' && $report['report_status'] === 'resolved';
    $isSiteVisit = $action === 'record_site_visit';
    $isBarangayPreconfirmationAction = $role === 'barangay'
        && $report['report_status'] === 'validated'
        && in_array($action, ['start_response', 'record_site_visit', 'unable_to_secure', 'unable_to_locate', 'update_progress'], true);
    if ($isHandoverPlan) {
        if ($role !== 'barangay' || $report['report_status'] !== 'resolved' || $state !== 'resolved'
            || !dcvsResponseRequiresTurnover((string)$report['report_type'], (string)($report['animal_disposition'] ?? ''))) {
            responseWorkflowReply(409, ['success' => false, 'message' => 'A handover plan can only be set by the barangay for a resolved case.']);
        }
        $turnoverCheck = $conn->prepare("SELECT turnover_task_id FROM report_turnover_tasks WHERE report_id = ? AND status = 'pending' LIMIT 1");
        if (!$turnoverCheck) responseWorkflowReply(500, ['success' => false, 'message' => 'Could not load the pending turnover task.']);
        $turnoverCheck->bind_param('i', $reportId);
        if (!$turnoverCheck->execute()) {
            $turnoverCheck->close();
            responseWorkflowReply(500, ['success' => false, 'message' => 'Could not verify the turnover task for this incident.']);
        }
        $turnoverCheck->close();
    } elseif ($isResolvedReceipt) {
        if ($role !== 'staff' || $state !== 'resolved') {
            responseWorkflowReply(409, ['success' => false, 'message' => 'Only authorized DCVS staff can confirm receipt for a resolved incident.']);
        }
        $turnoverCheck = $conn->prepare("SELECT turnover_task_id FROM report_turnover_tasks WHERE report_id = ? AND status = 'pending' AND planned_at IS NOT NULL LIMIT 1");
        if (!$turnoverCheck) responseWorkflowReply(500, ['success' => false, 'message' => 'Could not load the pending turnover task.']);
        $turnoverCheck->bind_param('i', $reportId);
        if (!$turnoverCheck->execute() || !$turnoverCheck->get_result()->fetch_assoc()) {
            $turnoverCheck->close();
            responseWorkflowReply(409, ['success' => false, 'message' => 'There is no pending handover plan for this incident.']);
        }
        $turnoverCheck->close();
    } elseif (!$isHandoverPlan && !$isSiteVisit
        && !$isBarangayPreconfirmationAction
        && $report['report_status'] !== 'confirmed') {
        responseWorkflowReply(409, ['success' => false, 'message' => 'This report is not an active confirmed case.']);
    }
    if ($isSiteVisit && $report['report_status'] !== 'validated') {
        responseWorkflowReply(409, ['success' => false, 'message' => 'This report already has a site visit result. Refresh the report to see its current status.']);
    }
    $transition = dcvsResponseTransition($action);
    if (!$transition || !dcvsResponseCanTransition($action, $role, $state)) {
        responseWorkflowReply(409, ['success' => false, 'message' => 'This action is not allowed from the current case state.']);
    }
    if (!empty($transition['reason']) && $reason === '') {
        responseWorkflowReply(400, ['success' => false, 'message' => 'A reason is required for this action.']);
    }
    if (in_array($action, ['return_to_barangay', 'resume_barangay_response'], true)
        && ($_POST['custody_confirmed'] ?? '') !== '1') {
        responseWorkflowReply(400, ['success' => false, 'message' => 'Confirm who has custody before continuing.']);
    }
    if ($action === 'close_not_actionable') {
        $resolvedDate = trim((string)($_POST['resolved_date'] ?? ''));
        $resolvedTime = trim((string)($_POST['resolved_time'] ?? ''));
        $resolvedDateTime = DateTime::createFromFormat('!Y-m-d H:i', $resolvedDate . ' ' . $resolvedTime, $workflowTimezone);
        $dateErrors = DateTime::getLastErrors();
        if (!$resolvedDateTime || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $resolvedDateTime->format('Y-m-d H:i') !== $resolvedDate . ' ' . $resolvedTime) {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Enter a valid closure date and time.']);
        }
    }
    if ($action === 'update_progress' && (function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason)) > 2000) {
        responseWorkflowReply(400, ['success' => false, 'message' => 'Progress updates must be no longer than 2,000 characters.']);
    }
    if ($action === 'record_site_visit') {
        $fieldStatus = is_string($_POST['field_status'] ?? null) ? trim($_POST['field_status']) : '';
        $validSiteFindings = ['confirmed_on_site', 'urgent_response', 'animal_already_gone', 'wrong_or_unclear_location'];
        if (!in_array($fieldStatus, $validSiteFindings, true)
            || $reason === ''
            || (function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason)) > 2000) {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Choose a valid site finding and enter notes of up to 2,000 characters.']);
        }
    }
    if ($action === 'set_handover_plan') {
        $handoverMethod = trim((string)($_POST['handover_method'] ?? ''));
        $handoverDate = trim((string)($_POST['handover_date'] ?? ''));
        $handoverTime = trim((string)($_POST['handover_time'] ?? ''));
        $handlerName = trim((string)($_POST['handler_name'] ?? ''));
        $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
        $pickupLocation = trim((string)($_POST['pickup_location'] ?? ''));
        $safeTransport = ($_POST['safe_transport'] ?? '') === '1' ? 1 : 0;
        $plannedAt = DateTime::createFromFormat('!Y-m-d H:i', $handoverDate . ' ' . $handoverTime, $workflowTimezone);
        $dateErrors = DateTime::getLastErrors();
        if (!in_array($handoverMethod, ['barangay_delivery', 'dcvs_pickup'], true)
            || !$plannedAt || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $plannedAt->format('Y-m-d H:i') !== $handoverDate . ' ' . $handoverTime
            || $plannedAt <= new DateTime('now', $workflowTimezone)) {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Choose a handover method and a valid future date and time.']);
        }
        if ($handlerName === '' || (function_exists('mb_strlen') ? mb_strlen($handlerName, 'UTF-8') : strlen($handlerName)) > 150
            || $contactNumber === '' || strlen($contactNumber) > 40
            || ($handoverMethod === 'dcvs_pickup' && $pickupLocation === '')
            || (function_exists('mb_strlen') ? mb_strlen($pickupLocation, 'UTF-8') : strlen($pickupLocation)) > 500) {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Provide the barangay contact details and, for pickup, the animal pickup location.']);
        }
        $plannedAtValue = $plannedAt->format('Y-m-d H:i:s');
        $handoverMethodLabel = $handoverMethod === 'barangay_delivery' ? 'Barangay delivery' : 'DCVS pickup requested';
        $reason = $handoverMethodLabel . ' planned for ' . $plannedAt->format('M j, Y g:i A')
            . '; handover contact: ' . $handlerName . ' (' . $contactNumber . ').'
            . ($pickupLocation !== '' ? ' Pickup location: ' . $pickupLocation . '.' : '')
            . ($safeTransport ? ' Animal will be transported in a carrier or safely secured.' : '');
    } elseif ($action === 'resolve_barangay') {
        $resolutionDetails = trim((string)($_POST['resolution_details'] ?? ''));
        $animalDisposition = trim((string)($_POST['animal_disposition'] ?? ''));
        $turnoverRequired = dcvsResponseRequiresTurnover(
            (string)$report['report_type'],
            $animalDisposition
        );
        $validDispositions = [
            'rescued', 'reunited_with_owner', 'transferred_to_shelter',
            'under_veterinary_care', 'disposed_per_local_protocol', 'other',
        ];
        if ($resolutionDetails === '' || (function_exists('mb_strlen') ? mb_strlen($resolutionDetails, 'UTF-8') : strlen($resolutionDetails)) > 4000
            || !in_array($animalDisposition, $validDispositions, true)) {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Provide resolution details and a valid animal disposition.']);
        }
        $isDeadAnimal = stripos((string)$report['report_type'], 'dead') !== false;
        if (($isDeadAnimal
                && $animalDisposition !== 'disposed_per_local_protocol')
            || (!$isDeadAnimal
                && $animalDisposition === 'disposed_per_local_protocol')) {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Dead-animal cases use the documented disposition process and do not create live-animal turnover tasks.']);
        }
        if ($turnoverRequired && trim((string)($report['holding_location'] ?? '')) === '') {
            responseWorkflowReply(400, ['success' => false, 'message' => 'Record the live animal’s holding location before creating a DCVS turnover task.']);
        }
        $reason = $resolutionDetails . ' Animal disposition: ' . $animalDisposition . '.';
    }
    $target = $transition['to'];
    if ($action === 'update_progress') $target = $state;
}

$storedPhoto = null;
if ($action === 'mark_secured') {
    $location = trim((string)($_POST['holding_location'] ?? ''));
    $condition = trim((string)($_POST['animal_condition'] ?? ''));
    $file = $_FILES['secured_photo'] ?? null;
    if ($location === '' || $condition === '' || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        responseWorkflowReply(400, ['success' => false, 'message' => 'Photo, holding location, and animal condition are required.']);
    }
    if ((int)$file['size'] > 25 * 1024 * 1024) responseWorkflowReply(400, ['success' => false, 'message' => 'Photo must be no larger than 25 MB.']);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($extensions[$mimeType]) || !is_uploaded_file($file['tmp_name'])) {
        responseWorkflowReply(400, ['success' => false, 'message' => 'Upload a JPG or PNG photo.']);
    }
    $uploadDirectory = __DIR__ . '/uploads/report-response';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        responseWorkflowReply(500, ['success' => false, 'message' => 'Could not prepare the evidence upload directory.']);
    }
    $fileName = bin2hex(random_bytes(18)) . '.' . $extensions[$mimeType];
    $relativePath = 'uploads/report-response/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $relativePath)) {
        responseWorkflowReply(500, ['success' => false, 'message' => 'Could not save the secured-animal photo.']);
    }
    $storedPhoto = ['path' => $relativePath, 'mime' => $mimeType, 'location' => $location, 'condition' => $condition];
}

if (!$conn->begin_transaction()) {
    if ($storedPhoto && is_file(__DIR__ . '/' . $storedPhoto['path']) && !unlink(__DIR__ . '/' . $storedPhoto['path'])) {
        error_log('Could not remove secured-animal photo after transaction start failed.');
    }
    responseWorkflowReply(500, ['success' => false, 'message' => 'Could not start the case update. Please try again.']);
}
try {
    if ($action === 'authorize_high_risk_override') {
        $override = $conn->prepare("UPDATE report_response_workflow
            SET high_risk_override_by = ?, high_risk_override_at = NOW(), high_risk_override_reason = ?
            WHERE report_id = ? AND state = 'awaiting_dcvs_decision' AND barangay_proposed = 1");
        $override->bind_param('isi', $userId, $reason, $reportId);
        if (!$override->execute() || $override->affected_rows !== 1) throw new RuntimeException('The override could not be recorded; refresh and try again.');
        $override->close();
    } elseif ($action === 'return_for_validation') {
        $reportUpdate = $conn->prepare("UPDATE reports
            SET status = 'pending', validated_at = NULL, validated_by = NULL,
                barangay_validation_status = NULL, barangay_validation_notes = NULL, dcvs_notes = ?
            WHERE report_id = ? AND status = 'validated'");
        $reportUpdate->bind_param('si', $reason, $reportId);
        if (!$reportUpdate->execute() || $reportUpdate->affected_rows !== 1) throw new RuntimeException('The report could not be returned to the barangay.');
        $reportUpdate->close();
        $reset = $conn->prepare("UPDATE report_response_workflow
            SET response_mode = 'undecided', state = 'awaiting_dcvs_decision', barangay_proposed = 0,
                response_deadline = NULL, turnover_deadline = NULL, high_risk_override_by = NULL,
                high_risk_override_at = NULL, high_risk_override_reason = NULL, dispatch_officer_id = NULL
            WHERE report_id = ? AND state = 'awaiting_dcvs_decision'");
        $reset->bind_param('i', $reportId);
        if (!$reset->execute()) throw new RuntimeException('The workflow could not be reset for barangay review.');
        if ($reset->affected_rows !== 1) {
            $reset->close();
            $stillAwaitingDecision = $conn->prepare("SELECT report_id FROM report_response_workflow
                WHERE report_id = ? AND state = 'awaiting_dcvs_decision' LIMIT 1");
            if (!$stillAwaitingDecision) throw new RuntimeException('Could not verify the workflow state after returning this report.');
            $stillAwaitingDecision->bind_param('i', $reportId);
            if (!$stillAwaitingDecision->execute() || !$stillAwaitingDecision->get_result()->fetch_assoc()) {
                $stillAwaitingDecision->close();
                throw new RuntimeException('The workflow could not be reset for barangay review.');
            }
            $stillAwaitingDecision->close();
        } else {
            $reset->close();
        }
    } elseif ($action === 'reject_report') {
        $reportUpdate = $conn->prepare("UPDATE reports
            SET status = 'rejected', rejection_reason = ?, confirmed_by = ?, confirmed_at = NOW()
            WHERE report_id = ? AND status = 'validated'");
        $reportUpdate->bind_param('sii', $reason, $userId, $reportId);
        if (!$reportUpdate->execute() || $reportUpdate->affected_rows !== 1) throw new RuntimeException('The report could not be rejected.');
        $reportUpdate->close();
        $closeWorkflow = $conn->prepare("UPDATE report_response_workflow
            SET state = 'closed_not_actionable', response_deadline = NULL, turnover_deadline = NULL
            WHERE report_id = ? AND state = 'awaiting_dcvs_decision'");
        $closeWorkflow->bind_param('i', $reportId);
        if (!$closeWorkflow->execute() || $closeWorkflow->affected_rows !== 1) throw new RuntimeException('The workflow could not be closed.');
        $closeWorkflow->close();
    } elseif ($action === 'staff_decide') {
        $caseCheck = $conn->prepare("SELECT case_id FROM confirmed_cases WHERE report_id = ? LIMIT 1");
        $caseCheck->bind_param('i', $reportId);
        $caseCheck->execute();
        $existingCase = $caseCheck->get_result()->fetch_assoc();
        $caseCheck->close();
        if (!$existingCase) {
            $caseInsert = $conn->prepare("INSERT INTO confirmed_cases (report_id, case_type, location_address, coordinates_lat, coordinates_long, barangay_id, urgency_level, status, confirmed_at)
                SELECT report_id, report_type, location_address, coordinates_lat, coordinates_long, barangay_id, urgency_level, 'active', NOW()
                FROM reports WHERE report_id = ?");
            $caseInsert->bind_param('i', $reportId);
            if (!$caseInsert->execute()) throw new RuntimeException('Could not create the active case.');
            $caseInsert->close();
        }
        $reportUpdate = $conn->prepare("UPDATE reports SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE report_id = ? AND status = 'validated'");
        $reportUpdate->bind_param('ii', $userId, $reportId);
        if (!$reportUpdate->execute() || $reportUpdate->affected_rows !== 1) throw new RuntimeException('Report was already processed.');
        $reportUpdate->close();
        $newState = 'dcvs_dispatch';
        $workflow = $conn->prepare("UPDATE report_response_workflow
            SET response_mode = ?, state = ?, approved_by = NULL, approved_at = NULL, response_deadline = NULL,
                turnover_deadline = NULL, dispatch_officer_id = ?,
                high_risk_override_by = NULL, high_risk_override_at = NULL, high_risk_override_reason = NULL
            WHERE report_id = ? AND state = 'awaiting_dcvs_decision'");
        if (!$workflow) throw new RuntimeException('Could not prepare the DCVS assignment.');
        $workflow->bind_param('ssii', $mode, $newState, $dispatchOfficerId, $reportId);
        if (!$workflow->execute() || $workflow->affected_rows !== 1) throw new RuntimeException('Response workflow was already processed.');
        $workflow->close();
        $target = $newState;
    } elseif ($action === 'start_response') {
        $progressHours = (int)env('DCVS_PROGRESS_TARGET_HOURS', '48');
        if ($progressHours < 1 || $progressHours > 720) $progressHours = 48;
        $reason = $reason !== '' ? $reason : 'Barangay response started; team is on the way to the incident.';
        $progress = $conn->prepare("UPDATE report_response_workflow
            SET state = 'barangay_responding', last_progress_at = NOW(),
                progress_deadline = DATE_ADD(NOW(), INTERVAL ? HOUR),
                response_deadline = NULL
            WHERE report_id = ? AND state = 'barangay_response_approved'");
        if (!$progress) throw new RuntimeException('Could not prepare the Barangay response start.');
        $progress->bind_param('ii', $progressHours, $reportId);
        if (!$progress->execute() || $progress->affected_rows !== 1) {
            throw new RuntimeException('The Barangay response could not be started.');
        }
        $progress->close();
    } elseif ($action === 'update_progress') {
        $progressHours = (int)env('DCVS_PROGRESS_TARGET_HOURS', '48');
        if ($progressHours < 1 || $progressHours > 720) $progressHours = 48;
        $progress = $conn->prepare("UPDATE report_response_workflow
            SET last_progress_at = NOW(), progress_deadline = DATE_ADD(NOW(), INTERVAL ? HOUR), response_deadline = NULL
            WHERE report_id = ? AND state = ?");
        if (!$progress) throw new RuntimeException('Could not prepare the response progress update.');
        $progress->bind_param('iis', $progressHours, $reportId, $state);
        if (!$progress->execute()
            || ($progress->affected_rows !== 1 && !responseWorkflowStateStillMatches($conn, $reportId, $state))) {
            throw new RuntimeException('The response progress was not saved.');
        }
        $progress->close();
    } elseif ($action === 'resolve_barangay') {
        $resolutionDetails = trim((string)$_POST['resolution_details']);
        $animalDisposition = trim((string)$_POST['animal_disposition']);
        $turnoverRequired = dcvsResponseRequiresTurnover(
            (string)$report['report_type'],
            $animalDisposition
        );
        $reason = $resolutionDetails . ' Animal disposition: ' . $animalDisposition . '.';
        $resolvedAt = (new DateTimeImmutable('now', $workflowTimezone))->format('Y-m-d H:i:s');
        $resolution = $conn->prepare("INSERT INTO report_response_resolutions
            (report_id, resolution_details, animal_disposition, turnover_required, resolved_by, resolved_at)
            VALUES (?, ?, ?, ?, ?, ?)");
        if (!$resolution) throw new RuntimeException('Could not prepare the resolution record.');
        $resolution->bind_param('isssis', $reportId, $resolutionDetails, $animalDisposition, $turnoverRequired, $userId, $resolvedAt);
        if (!$resolution->execute()) throw new RuntimeException('The resolution record could not be saved.');
        $resolution->close();

        $reportUpdate = $conn->prepare("UPDATE reports SET status = 'resolved' WHERE report_id = ? AND status = 'confirmed'");
        if (!$reportUpdate) throw new RuntimeException('Could not prepare the incident status update.');
        $reportUpdate->bind_param('i', $reportId);
        if (!$reportUpdate->execute() || $reportUpdate->affected_rows !== 1) throw new RuntimeException('The incident has already been resolved or is no longer in progress.');
        $reportUpdate->close();

        $caseUpdate = $conn->prepare("UPDATE confirmed_cases SET status = 'resolved', resolved_at = ?, notes = ? WHERE report_id = ? AND status = 'active'");
        if (!$caseUpdate) throw new RuntimeException('Could not prepare the linked case update.');
        $caseUpdate->bind_param('ssi', $resolvedAt, $resolutionDetails, $reportId);
        if (!$caseUpdate->execute() || $caseUpdate->affected_rows !== 1) throw new RuntimeException('The linked case could not be marked resolved.');
        $caseUpdate->close();

        $workflowUpdate = $conn->prepare("UPDATE report_response_workflow
            SET state = 'resolved', response_deadline = NULL, progress_deadline = NULL
            WHERE report_id = ? AND state = ?");
        if (!$workflowUpdate) throw new RuntimeException('Could not prepare the response completion update.');
        $workflowUpdate->bind_param('is', $reportId, $state);
        if (!$workflowUpdate->execute() || $workflowUpdate->affected_rows !== 1) throw new RuntimeException('The response workflow could not be completed.');
        $workflowUpdate->close();

        if ($turnoverRequired) {
            $turnoverHours = (int)env('DCVS_TURNOVER_TARGET_HOURS', '24');
            if ($turnoverHours < 1 || $turnoverHours > 720) $turnoverHours = 24;
            $turnoverDueAt = (new DateTimeImmutable('now', $workflowTimezone))
                ->modify('+' . $turnoverHours . ' hours')->format('Y-m-d H:i:s');
            $turnover = $conn->prepare("INSERT INTO report_turnover_tasks
                (report_id, status, animal_count, holding_location, animal_disposition, task_notes, due_at, requested_by, requested_at)
                VALUES (?, 'pending', ?, ?, ?, ?, ?, ?, NOW())");
            if (!$turnover) throw new RuntimeException('Could not prepare the DCVS turnover task.');
            $animalCount = (string)($report['animal_count'] ?? '');
            $holdingLocation = (string)($report['holding_location'] ?? '');
            $turnoverNotes = $resolutionDetails;
            $turnover->bind_param('isssssi', $reportId, $animalCount, $holdingLocation, $animalDisposition, $turnoverNotes, $turnoverDueAt, $userId);
            if (!$turnover->execute()) {
                if ($turnover->errno === 1062) throw new RuntimeException('A turnover task already exists for this incident.');
                throw new RuntimeException('The DCVS turnover task could not be created.');
            }
            $turnover->close();
        }
    } elseif ($isHandoverPlan) {
        $taskCheck = $conn->prepare("SELECT turnover_task_id, status FROM report_turnover_tasks WHERE report_id = ? LIMIT 1 FOR UPDATE");
        if (!$taskCheck) throw new RuntimeException('Could not verify the turnover task before saving the plan.');
        $taskCheck->bind_param('i', $reportId);
        if (!$taskCheck->execute()) throw new RuntimeException('Could not verify the turnover task before saving the plan.');
        $task = $taskCheck->get_result()->fetch_assoc();
        $taskCheck->close();
        if (!$task) {
            $turnoverHours = (int)env('DCVS_TURNOVER_TARGET_HOURS', '24');
            if ($turnoverHours < 1 || $turnoverHours > 720) $turnoverHours = 24;
            $createTask = $conn->prepare("INSERT INTO report_turnover_tasks
                (report_id, status, animal_count, holding_location, animal_disposition, task_notes, due_at, requested_by, requested_at)
                SELECT r.report_id, 'pending', r.animal_count, rw.holding_location,
                    COALESCE(NULLIF(rr.animal_disposition, ''), 'other'),
                    COALESCE(rr.resolution_details, ''),
                    DATE_ADD(NOW(), INTERVAL ? HOUR), ?, NOW()
                FROM reports r
                LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
                LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
                WHERE r.report_id = ? AND r.status = 'resolved'
                    AND LOWER(r.report_type) NOT LIKE '%dead%'
                    AND (rr.animal_disposition IS NULL OR rr.animal_disposition NOT IN
                        ('reunited_with_owner', 'transferred_to_shelter', 'disposed_per_local_protocol'))");
            if (!$createTask) throw new RuntimeException('Could not prepare the turnover task for this resolved case.');
            $createTask->bind_param('iii', $turnoverHours, $userId, $reportId);
            if (!$createTask->execute() || $createTask->affected_rows !== 1) {
                throw new RuntimeException('A turnover task could not be created for this resolved case.');
            }
            $createTask->close();
        } elseif ($task['status'] !== 'pending') {
            throw new RuntimeException('This turnover task has already been completed or cancelled.');
        }
        $plan = $conn->prepare("UPDATE report_turnover_tasks
            SET handover_method = ?, planned_at = ?, handler_name = ?, contact_number = ?,
                pickup_location = ?, safe_transport = ?
            WHERE report_id = ? AND status = 'pending'");
        if (!$plan) throw new RuntimeException('Could not prepare the handover plan update.');
        $plan->bind_param('sssssii', $handoverMethod, $plannedAtValue, $handlerName, $contactNumber, $pickupLocation, $safeTransport, $reportId);
        if (!$plan->execute()) throw new RuntimeException('The pending handover plan could not be saved.');
        if ($plan->affected_rows !== 1) {
            $pendingTask = $conn->prepare("SELECT turnover_task_id FROM report_turnover_tasks WHERE report_id = ? AND status = 'pending' LIMIT 1");
            if (!$pendingTask) throw new RuntimeException('Could not verify the saved handover plan.');
            $pendingTask->bind_param('i', $reportId);
            $saved = $pendingTask->execute() && (bool)$pendingTask->get_result()->fetch_assoc();
            $pendingTask->close();
            if (!$saved) throw new RuntimeException('The pending handover plan could not be saved.');
        }
        $plan->close();
    } elseif ($isResolvedReceipt) {
        $receipt = $conn->prepare("UPDATE report_turnover_tasks SET status = 'receipt_confirmed', received_by = ?, received_at = NOW()
            WHERE report_id = ? AND status = 'pending'");
        if (!$receipt) throw new RuntimeException('Could not prepare the turnover receipt.');
        $receipt->bind_param('ii', $userId, $reportId);
        if (!$receipt->execute() || $receipt->affected_rows !== 1) throw new RuntimeException('The turnover task was already completed or cancelled.');
        $receipt->close();
    } elseif ($action === 'record_site_visit') {
        $fieldStatus = trim((string)$_POST['field_status']);
        $reportUpdate = $conn->prepare("UPDATE reports
            SET barangay_validation_status = ?, barangay_validation_notes = ?,
                status = IF(? = 'confirmed_on_site', 'confirmed', status),
                confirmed_by = IF(? = 'confirmed_on_site', ?, confirmed_by),
                confirmed_at = IF(? = 'confirmed_on_site', NOW(), confirmed_at)
            WHERE report_id = ? AND status = 'validated'");
        if (!$reportUpdate) throw new RuntimeException('Could not prepare the site visit result.');
        $reportUpdate->bind_param('ssssisi', $fieldStatus, $reason, $fieldStatus, $fieldStatus, $userId, $fieldStatus, $reportId);
        if (!$reportUpdate->execute() || $reportUpdate->affected_rows !== 1) {
            throw new RuntimeException('The site visit result was not saved.');
        }
        $reportUpdate->close();
        if ($fieldStatus === 'confirmed_on_site') {
            $activeCase = $conn->prepare("INSERT INTO confirmed_cases
                    (report_id, case_type, location_address, coordinates_lat, coordinates_long, barangay_id, urgency_level, status, confirmed_at)
                SELECT r.report_id, r.report_type, r.location_address, r.coordinates_lat, r.coordinates_long,
                       r.barangay_id, r.urgency_level, 'active', NOW()
                FROM reports r
                WHERE r.report_id = ? AND NOT EXISTS (
                    SELECT 1 FROM confirmed_cases existing WHERE existing.report_id = r.report_id
                )");
            if (!$activeCase) throw new RuntimeException('Could not prepare the confirmed map case.');
            $activeCase->bind_param('i', $reportId);
            if (!$activeCase->execute() || $activeCase->affected_rows !== 1) {
                throw new RuntimeException('The confirmed map case could not be created.');
            }
            $activeCase->close();
        }
    } elseif ($action === 'mark_secured' && $storedPhoto) {
        $progressHours = (int)env('DCVS_PROGRESS_TARGET_HOURS', '48');
        if ($progressHours < 1 || $progressHours > 720) $progressHours = 48;
        $update = $conn->prepare("UPDATE report_response_workflow
            SET state = 'animal_secured', secured_at = NOW(), holding_location = ?,
                animal_condition = ?, last_progress_at = NOW(),
                progress_deadline = DATE_ADD(NOW(), INTERVAL ? HOUR), response_deadline = NULL
            WHERE report_id = ? AND state = ?");
        if (!$update) throw new RuntimeException('Could not prepare the animal custody update.');
        $update->bind_param('ssiis', $storedPhoto['location'], $storedPhoto['condition'], $progressHours, $reportId, $state);
        if (!$update->execute()
            || $update->affected_rows !== 1) {
            throw new RuntimeException('Animal custody details were not saved.');
        }
        $update->close();
        $reason = 'Secured at ' . $storedPhoto['location'] . '. Condition: ' . $storedPhoto['condition'];
        $media = $conn->prepare("INSERT INTO report_attachments (report_id, attachment_type, attachment_source, file_url, mime_type, display_order) VALUES (?, 'photo', 'barangay_secured', ?, ?, 0)");
        if (!$media) throw new RuntimeException('Could not prepare the animal evidence record.');
        $media->bind_param('iss', $reportId, $storedPhoto['path'], $storedPhoto['mime']);
        if (!$media->execute()) throw new RuntimeException('Could not save the evidence record.');
        $media->close();
    } else {
        if ($action === 'switch_to_dispatch') {
            $update = $conn->prepare("UPDATE report_response_workflow SET dcvs_pickup_requested = 1 WHERE report_id = ? AND state = ?");
            $update->bind_param('is', $reportId, $state);
        } elseif ($action === 'revoke_approval' || $action === 'reopen_unable_to_locate' || $action === 'dispatch_escalation') {
            $update = $conn->prepare("UPDATE report_response_workflow
                SET state = ?, response_mode = 'dcvs_dispatch',
                    response_deadline = NULL, progress_deadline = NULL
                WHERE report_id = ? AND state = ?");
            $update->bind_param('sis', $target, $reportId, $state);
        } elseif (in_array($action, ['unable_to_secure', 'unable_to_locate'], true)) {
            $update = $conn->prepare("UPDATE report_response_workflow
                SET state = ?, response_deadline = NULL, progress_deadline = NULL
                WHERE report_id = ? AND state = ?");
            $update->bind_param('sis', $target, $reportId, $state);
        } elseif ($action === 'resume_barangay_response') {
            $deadline = (new DateTimeImmutable('now', $workflowTimezone))->modify('+24 hours')->format('Y-m-d H:i:s');
            $update = $conn->prepare("UPDATE report_response_workflow SET state = ?, response_deadline = ? WHERE report_id = ? AND state = ?");
            $update->bind_param('ssis', $target, $deadline, $reportId, $state);
        } else {
            $update = $conn->prepare("UPDATE report_response_workflow SET state = ? WHERE report_id = ? AND state = ?");
            $update->bind_param('sis', $target, $reportId, $state);
        }
        if (!$update || !$update->execute() || ($action !== 'switch_to_dispatch' && $update->affected_rows !== 1)) throw new RuntimeException('Case state changed; refresh and try again.');
        $update->close();
    }
    if ($action === 'request_pickup') {
        $pickup = $conn->prepare("UPDATE report_response_workflow
            SET turnover_requested_at = NOW(),
                turnover_deadline = COALESCE(turnover_deadline, DATE_ADD(NOW(), INTERVAL 1 DAY))
            WHERE report_id = ?");
        $pickup->bind_param('i', $reportId);
        if (!$pickup->execute()) throw new RuntimeException('Could not record the pickup request.');
        $pickup->close();
    }
    if ($action === 'confirm_turnover' && !$isResolvedReceipt) {
        $receipt = $conn->prepare("UPDATE report_response_workflow SET turnover_received_at = NOW(), turnover_received_by = ?, turnover_deadline = NULL WHERE report_id = ?");
        $receipt->bind_param('ii', $userId, $reportId);
        if (!$receipt->execute()) throw new RuntimeException('Could not record turnover receipt.');
        $receipt->close();
    }
    if ($action === 'close_not_actionable') {
        $resolvedAt = $resolvedDateTime->format('Y-m-d H:i:s');
        $caseClose = $conn->prepare("UPDATE confirmed_cases SET status = 'resolved', resolved_at = ?, notes = CONCAT_WS('\\n', NULLIF(notes, ''), ?) WHERE report_id = ? AND status = 'active'");
        $caseClose->bind_param('ssi', $resolvedAt, $reason, $reportId);
        if (!$caseClose->execute() || $caseClose->affected_rows !== 1) throw new RuntimeException('Could not close the active case.');
        $caseClose->close();
        $reportClose = $conn->prepare("UPDATE reports SET status = 'rejected' WHERE report_id = ? AND status = 'confirmed'");
        $reportClose->bind_param('i', $reportId);
        if (!$reportClose->execute() || $reportClose->affected_rows !== 1) throw new RuntimeException('Could not close the report.');
        $reportClose->close();
    }
    if ($action === 'staff_decide' && $mode === 'barangay_response') {
        $historyAction = 'approve_barangay_response';
    } elseif ($action === 'authorize_high_risk_override') {
        $historyAction = 'authorize_high_risk_barangay_response';
    } elseif ($action === 'return_for_validation') {
        $historyAction = 'return_to_barangay_for_validation';
    } elseif ($action === 'reject_report') {
        $historyAction = 'reject_report';
    } else {
        $historyAction = $action;
    }
    $historyReason = $reason;
    $history = $conn->prepare("INSERT INTO report_response_history (report_id, actor_id, actor_role, action, from_state, to_state, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $history->bind_param('iisssss', $reportId, $userId, $role, $historyAction, $state, $target, $historyReason);
    if (!$history->execute()) throw new RuntimeException('Could not save the case history.');
    $history->close();
    if (!$conn->commit()) throw new RuntimeException('Could not complete the case action.');
} catch (Throwable $exception) {
    $conn->rollback();
    if ($storedPhoto && is_file(__DIR__ . '/' . $storedPhoto['path'])) unlink(__DIR__ . '/' . $storedPhoto['path']);
    error_log('Response workflow action failed: ' . $exception->getMessage());
    responseWorkflowReply(409, ['success' => false, 'message' => $exception->getMessage()]);
}

if (function_exists('createNotification')) {
    $notifyStaff = in_array($role, ['barangay'], true);
    $notifyReporter = in_array($role, ['staff', 'barangay'], true) && $action !== 'authorize_high_risk_override';
    $titles = [
        'staff_decide' => 'Case Response Decision',
        'return_for_validation' => 'Report Returned for More Information',
        'reject_report' => 'Report Rejected by DCVS',
        'start_response' => 'Barangay Response Started',
        'record_site_visit' => 'Barangay Site Visit Recorded',
        'mark_secured' => 'Animal Secured by Barangay',
        'request_pickup' => 'DCVS Pickup Requested',
        'set_handover_plan' => 'Animal Handover Plan Set',
        'unable_to_secure' => 'Urgent DCVS Escalation',
        'unable_to_locate' => 'Animal Could Not Be Located',
        'confirm_turnover' => 'Turnover Received by DCVS',
        'revoke_approval' => 'Response Mode Changed',
        'switch_to_dispatch' => 'DCVS Pickup Requested',
        'update_progress' => 'Barangay Response Progress Updated',
        'resolve_barangay' => 'Incident Resolved by Barangay',
    ];
    $title = $titles[$action] ?? 'Case Status Updated';
    if ($action === 'return_for_validation') {
        $message = 'DCVS returned this report to the barangay for more information. Reason: ' . $reason;
    } elseif ($action === 'reject_report') {
        $message = 'DCVS rejected this report. Reason: ' . $reason;
    } elseif ($action === 'staff_decide' && $mode === 'dcvs_dispatch') {
        $message = 'DCVS confirmed this report. ' . $dispatchOfficerName . ' will respond to the incident regardless of the distance to the reported location.';
    } elseif ($action === 'staff_decide') {
        $message = 'DCVS approved the barangay response. The barangay is preparing to respond.';
    } elseif ($action === 'start_response') {
        $message = 'Barangay started responding to this incident. ' . $reason;
    } elseif ($action === 'record_site_visit') {
        $message = 'Barangay site visit result: ' . $reason;
    } elseif ($action === 'update_progress') {
        $message = 'Barangay response progress: ' . $reason;
    } elseif ($action === 'resolve_barangay') {
        $message = 'The barangay resolved the incident. ' . $reason;
    } elseif ($action === 'set_handover_plan') {
        $message = 'Barangay handover plan: ' . $reason . ' Keep the animal safe until DCVS confirms receipt.';
    } elseif ($action === 'confirm_turnover' && $isResolvedReceipt) {
        $title = 'Animal Turnover Receipt Confirmed';
        $message = 'DCVS confirmed receipt of the animal. The incident remains resolved.';
    } else {
        $message = 'Case response status: ' . str_replace('_', ' ', $target) . '.';
    }
    if (function_exists('createNotification')) {
        if ($notifyReporter && (int)$report['reporter_id'] > 0) {
            createNotification($conn, (int)$report['reporter_id'], 'report_response_update', $title, $message, 'report', $reportId);
        }
        if ($notifyStaff || $action === 'staff_decide' || ($action === 'confirm_turnover' && $isResolvedReceipt)) {
            $staffUsers = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
            if ($staffUsers) {
                while ($staffUser = $staffUsers->fetch_assoc()) {
                    createNotification($conn, (int)$staffUser['user_id'], 'report_response_update', $title, $message, 'report', $reportId);
                }
            }
        }
        if ($action === 'staff_decide' && $mode === 'dcvs_dispatch' && $dispatchOfficerId
            && $dispatchOfficerId !== $userId) {
            createNotification($conn, $dispatchOfficerId, 'report_response_assignment', 'DCVS Dispatch Assigned',
                'You have been assigned to respond to report #' . $reportId . '.', 'report', $reportId);
        }
        if ($role === 'staff' && $action !== 'authorize_high_risk_override' && (int)$report['barangay_id'] > 0) {
            $barangayUsers = $conn->prepare("SELECT user_id FROM users WHERE role = 'barangay' AND barangay_id = ? AND status = 'active'");
            if ($barangayUsers) {
                $barangayUsers->bind_param('i', $report['barangay_id']);
                if ($barangayUsers->execute()) {
                    $result = $barangayUsers->get_result();
                    while ($barangayUser = $result->fetch_assoc()) {
                        createNotification($conn, (int)$barangayUser['user_id'], 'report_response_update', $title, $message, 'report', $reportId);
                    }
                }
                $barangayUsers->close();
            }
        }
    }
}
responseWorkflowReply(200, ['success' => true, 'state' => $target, 'message' => 'Case updated successfully.']);
