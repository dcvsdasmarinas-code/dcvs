<?php
/**
 * Barangay Report Validation / Rejection Handler
 * Records Barangay field review and assigns responsibility, forwards to DCVS, or rejects the report.
 */

session_name('DCVS_BARANGAY_SESSION');
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
ini_set('display_errors', '0');
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reports/validation.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$fullName   = $_SESSION['full_name'] ?? 'Barangay Officer';

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType === 'multipart/form-data'
    && empty($_POST)
    && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'The evidence upload exceeded the server request-size limit. Reduce the upload size and try again.']);
    exit;
}
$input = $contentType === 'application/json'
    ? json_decode(file_get_contents('php://input'), true)
    : $_POST;
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$reportId = (int)($input['report_id'] ?? 0);
$actionValue = $input['action'] ?? '';
$action = is_string($actionValue) ? trim($actionValue) : '';
$reasonValue = $input['reason'] ?? '';
$reason = is_string($reasonValue) ? trim($reasonValue) : '';
loadDcvsEnv();
$barangayResponseEnabled = env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1';

if ($reportId <= 0 || !in_array($action, ['validate', 'reject', 'archive', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Fetch report (must belong to this barangay and be pending)
$stmt = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.status, r.reporter_id, r.report_type, r.urgency_level,
           b.barangay_name
    FROM reports r
    LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
    WHERE r.report_id = ? AND r.barangay_id = ? AND r.archived_at IS NULL
");
$stmt->bind_param('ii', $reportId, $barangayId);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Report not found']);
    exit;
}

if ($report['status'] !== 'pending' && $action !== 'archive') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Report is no longer pending']);
    exit;
}

$brgyName = $report['barangay_name'] ?: 'Barangay';
$reporterId = (int)$report['reporter_id'];
$referenceId = $report['reference_id'] ?? ('RPT-' . $reportId);

if ($action === 'validate') {
    if ($contentType !== 'multipart/form-data' || !validateCsrf($input['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
        exit;
    }

    $barangayWillRespond = $barangayResponseEnabled
        && ($input['barangay_will_respond'] ?? ($input['barangay_response_proposed'] ?? '')) === '1';
    $fieldStatusValue = $input['field_status'] ?? '';
    $fieldStatus = is_string($fieldStatusValue) ? trim($fieldStatusValue) : '';
    if ($barangayWillRespond) {
        $fieldStatus = 'site_visit_pending';
    }
    $urgentBarangayResponse = $barangayWillRespond
        && (in_array(strtolower((string)$report['urgency_level']), ['high', 'critical'], true)
            || $fieldStatus === 'urgent_response');
    $validationNotesValue = $input['validation_notes'] ?? '';
    $validationNotes = is_string($validationNotesValue) ? trim($validationNotesValue) : '';
    $validFieldStatuses = dcvsValidationFieldStatuses();
    if (!in_array($fieldStatus, $validFieldStatuses, true)
        || ($barangayWillRespond && $fieldStatus !== 'site_visit_pending')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Select the field status before forwarding this report.']);
        exit;
    }
    if ($validationNotes === '' || (function_exists('mb_strlen') ? mb_strlen($validationNotes, 'UTF-8') : strlen($validationNotes)) > 500) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a message to DCVS of 1 to 500 characters.']);
        exit;
    }

    $maxFiles = 6;
    $maxFileSize = 25 * 1024 * 1024;
    $incomingFiles = [];
    if (isset($_FILES['validation_attachments'])
        && is_array($_FILES['validation_attachments']['name'] ?? null)) {
        foreach ($_FILES['validation_attachments']['name'] as $index => $name) {
            $uploadError = $_FILES['validation_attachments']['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $incomingFiles[] = [
                'name' => $name,
                'tmp_name' => $_FILES['validation_attachments']['tmp_name'][$index] ?? '',
                'error' => $uploadError,
                'size' => $_FILES['validation_attachments']['size'][$index] ?? 0,
            ];
        }
    }
    if (count($incomingFiles) > $maxFiles) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Attach no more than 6 files.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false && $incomingFiles) {
        error_log('Could not initialize MIME detection for barangay validation evidence.');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Evidence files could not be validated. Please try again.']);
        exit;
    }
    $validatedFiles = [];
    foreach ($incomingFiles as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            if ($finfo !== false) finfo_close($finfo);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'An evidence file could not be uploaded. Check each file is at most 25 MB.']);
            exit;
        }
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $mimeType = finfo_file($finfo, (string)$file['tmp_name']);
        $attachmentType = is_string($mimeType)
            ? dcvsValidationAttachmentType($mimeType, $extension)
            : null;
        if ($attachmentType === null) {
            finfo_close($finfo);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Use JPG, PNG, or MP4 evidence files only.']);
            exit;
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxFileSize) {
            finfo_close($finfo);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Each evidence file must be no larger than 25 MB.']);
            exit;
        }
        $validatedFiles[] = [
            'tmp_name' => (string)$file['tmp_name'],
            'extension' => $extension,
            'mime_type' => $mimeType,
            'type' => $attachmentType,
        ];
    }
    if ($finfo !== false) finfo_close($finfo);

    $uploadDirectory = __DIR__ . '/../../uploads/reports/';
    if ($validatedFiles && !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        error_log('Could not create report validation evidence directory.');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Evidence files could not be saved. Please try again.']);
        exit;
    }

    $storedFiles = [];
    foreach ($validatedFiles as $index => $file) {
        $fileName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
        $fileUrl = 'uploads/reports/' . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . $fileName)) {
            foreach ($storedFiles as $storedFile) {
                $path = __DIR__ . '/../../' . $storedFile['file_url'];
                if (is_file($path) && !unlink($path)) error_log('Could not remove an incomplete validation evidence upload.');
            }
            error_log('Could not store barangay validation evidence.');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Evidence files could not be saved. Please try again.']);
            exit;
        }
        $storedFiles[] = [
            'file_url' => $fileUrl,
            'mime_type' => $file['mime_type'],
            'type' => $file['type'],
            'display_order' => $index,
        ];
    }

    if (!$conn->begin_transaction()) {
        foreach ($storedFiles as $storedFile) {
            $path = __DIR__ . '/../../' . $storedFile['file_url'];
            if (is_file($path) && !unlink($path)) error_log('Could not remove validation evidence after transaction failure.');
        }
        error_log('Could not begin barangay report validation transaction: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The report could not be validated. Please try again.']);
        exit;
    }
    $reportStatus = 'validated';
    $confirmedBy = null;
    $confirmedFlag = 0;
    $updateStmt = $conn->prepare("UPDATE reports
        SET status = ?, validated_by = ?, validated_at = NOW(),
            confirmed_by = ?, confirmed_at = IF(? = 1, NOW(), NULL),
            barangay_validation_status = ?, barangay_validation_notes = ?
        WHERE report_id = ? AND barangay_id = ? AND status = 'pending' AND archived_at IS NULL");
    $databaseError = false;
    if ($updateStmt) {
        $updateStmt->bind_param('siiissii', $reportStatus, $userId, $confirmedBy, $confirmedFlag, $fieldStatus, $validationNotes, $reportId, $barangayId);
        if (!$updateStmt->execute()) {
            $databaseError = true;
            error_log('Could not execute barangay report validation update: ' . $updateStmt->error);
            $updated = false;
        } else {
            $updated = $updateStmt->affected_rows === 1;
        }
        $updateStmt->close();
    } else {
        $updated = false;
        $databaseError = true;
        error_log('Could not prepare barangay report validation update: ' . $conn->error);
    }

    $attachmentsSaved = $updated && !$databaseError;
    if ($attachmentsSaved && $storedFiles) {
        $attachmentStmt = $conn->prepare("INSERT INTO report_attachments
            (report_id, attachment_type, attachment_source, file_url, mime_type, display_order)
            VALUES (?, ?, 'barangay_validation', ?, ?, ?)");
        if (!$attachmentStmt) {
            $attachmentsSaved = false;
            error_log('Could not prepare barangay validation attachment insert: ' . $conn->error);
        } else {
            foreach ($storedFiles as $storedFile) {
                $attachmentStmt->bind_param(
                    'isssi',
                    $reportId,
                    $storedFile['type'],
                    $storedFile['file_url'],
                    $storedFile['mime_type'],
                    $storedFile['display_order']
                );
                if (!$attachmentStmt->execute()) {
                    $attachmentsSaved = false;
                    $databaseError = true;
                    error_log('Could not save barangay validation attachment: ' . $attachmentStmt->error);
                    break;
                }
            }
            $attachmentStmt->close();
        }
    }
    if (!$updated || !$attachmentsSaved) {
        $conn->rollback();
        foreach ($storedFiles as $storedFile) {
            $path = __DIR__ . '/../../' . $storedFile['file_url'];
            if (is_file($path) && !unlink($path)) error_log('Could not remove validation evidence after database failure.');
        }
        http_response_code($databaseError ? 500 : 409);
        echo json_encode([
            'success' => false,
            'message' => $databaseError
                ? 'Validation details could not be saved. Please try again.'
                : 'Report is no longer pending validation.',
        ]);
        exit;
    }
    if ($barangayResponseEnabled) {
        $targetHours = (int)env('DCVS_RESPONSE_TARGET_HOURS', '24');
        $progressHours = (int)env('DCVS_PROGRESS_TARGET_HOURS', '48');
        if ($targetHours < 1 || $targetHours > 720 || $progressHours < 1 || $progressHours > 720) {
            error_log('Invalid barangay-response deadline configuration; using 24/48-hour defaults.');
            $targetHours = 24;
            $progressHours = 48;
        }
        $responseMode = $barangayWillRespond ? 'barangay_response' : 'undecided';
        $responseState = $barangayWillRespond ? 'barangay_response_approved' : 'awaiting_dcvs_decision';
        $barangayProposed = 0;
        $responseDeadline = $barangayWillRespond ? 'DATE_ADD(NOW(), INTERVAL ? HOUR)' : 'NULL';
        $progressDeadline = $barangayWillRespond ? 'DATE_ADD(NOW(), INTERVAL ? HOUR)' : 'NULL';
        $workflow = $conn->prepare("INSERT INTO report_response_workflow
                (report_id, response_mode, state, barangay_proposed, response_deadline, progress_deadline)
            VALUES (?, ?, ?, ?, {$responseDeadline}, {$progressDeadline})
            ON DUPLICATE KEY UPDATE response_mode = VALUES(response_mode), state = VALUES(state),
                barangay_proposed = VALUES(barangay_proposed), response_deadline = VALUES(response_deadline),
                progress_deadline = VALUES(progress_deadline), last_progress_at = NULL");
        if (!$workflow) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Response workflow could not be initialized. Please try again.']);
            exit;
        }
        if ($barangayWillRespond) {
            $workflow->bind_param('issiii', $reportId, $responseMode, $responseState, $barangayProposed, $targetHours, $progressHours);
        } else {
            $workflow->bind_param('issi', $reportId, $responseMode, $responseState, $barangayProposed);
        }
        if (!$workflow->execute()) {
            $workflow->close();
            $conn->rollback();
            error_log('Could not initialize report response workflow: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Response workflow could not be initialized. Please try again.']);
            exit;
        }
        $workflow->close();
        if ($urgentBarangayResponse) {
            $urgentFlag = $conn->prepare("UPDATE report_response_workflow SET urgent_escalated_at = NOW() WHERE report_id = ?");
            if (!$urgentFlag) {
                $conn->rollback();
                error_log('Could not prepare urgent response coordination flag: ' . $conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Urgent response coordination could not be recorded. Please try again.']);
                exit;
            }
            $urgentFlag->bind_param('i', $reportId);
            if (!$urgentFlag->execute()) {
                $urgentFlag->close();
                $conn->rollback();
                error_log('Could not save urgent response coordination flag: ' . $conn->error);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Urgent response coordination could not be recorded. Please try again.']);
                exit;
            }
            $urgentFlag->close();
        }
        $history = $conn->prepare("INSERT INTO report_response_history (report_id, actor_id, actor_role, action, from_state, to_state, reason)
            VALUES (?, ?, 'barangay', ?, 'pending_validation', ?, ?)");
        if (!$history) {
            $conn->rollback();
            error_log('Could not prepare initial report response workflow history: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Response workflow could not be initialized. Please try again.']);
            exit;
        }
        $historyAction = $barangayWillRespond ? 'accept_barangay_assignment' : 'forward_to_dcvs_review';
        $history->bind_param('iisss', $reportId, $userId, $historyAction, $responseState, $validationNotes);
        if (!$history->execute()) {
            $history->close();
            $conn->rollback();
            error_log('Could not log initial report response workflow state: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Response workflow could not be initialized. Please try again.']);
            exit;
        }
        $history->close();
    }
    if (!$conn->commit()) {
        error_log('Could not commit barangay report validation: ' . $conn->error);
        $conn->rollback();
        foreach ($storedFiles as $storedFile) {
            $path = __DIR__ . '/../../' . $storedFile['file_url'];
            if (is_file($path) && !unlink($path)) error_log('Could not remove validation evidence after transaction commit failure.');
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Validation details could not be saved. Please try again.']);
        exit;
    }
    if ($barangayWillRespond) {
        createNotification($conn, $reporterId, 'report_response_update', 'Barangay Accepted the Case',
            $brgyName . ' accepted responsibility for your report. The response will start when the team is heading to the incident. ' . $validationNotes,
            'report', $reportId);
        if ($urgentBarangayResponse) {
            $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
            if (!$staffStmt) {
                error_log('Could not prepare urgent response notifications for DCVS: ' . $conn->error);
            } else {
                if (!$staffStmt->execute()) {
                    error_log('Could not load DCVS recipients for urgent response: ' . $staffStmt->error);
                } else {
                    $staffResult = $staffStmt->get_result();
                    while ($staffRow = $staffResult->fetch_assoc()) {
                        createNotification($conn, (int)$staffRow['user_id'], 'urgent_barangay_response',
                            'Urgent Barangay Response',
                            'Urgent coordination is needed for ' . $referenceId . ', assigned to ' . $brgyName . '.',
                            'report', $reportId);
                    }
                }
                $staffStmt->close();
            }
        }
        echo json_encode(['success' => true, 'message' => 'Report validated and assigned to your barangay. Start the response when the team is heading to the incident.']);
    } else {
        createNotification($conn, $reporterId, 'report_validated', 'Report Forwarded to DCVS',
            'Your report has been reviewed by ' . $fullName . ' and is pending DCVS review.',
            'report', $reportId);

        $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
        if (!$staffStmt) {
            error_log('Could not prepare DCVS report review notifications: ' . $conn->error);
        } else {
            if (!$staffStmt->execute()) {
                error_log('Could not load DCVS recipients for forwarded report: ' . $staffStmt->error);
            } else {
                $staffResult = $staffStmt->get_result();
                while ($staffRow = $staffResult->fetch_assoc()) {
                    createNotification($conn, (int)$staffRow['user_id'], 'report_validated',
                        'New Report Pending DCVS Review',
                        $fullName . ' forwarded a report from ' . $brgyName . ' to DCVS review.',
                        'report', $reportId);
                }
            }
            $staffStmt->close();
        }
        sendReportEmail($conn, $reportId, 'validated', $validationNotes, $fullName);
        sendReportSms($conn, $reportId, 'validated', $fullName);
        echo json_encode(['success' => true, 'message' => 'Report forwarded to DCVS review.']);
    }
} else if ($action === 'archive' || $action === 'delete') {
    $stmt = $conn->prepare("UPDATE reports SET archived_at = NOW() WHERE report_id = ? AND archived_at IS NULL");
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $stmt->close();

    createNotification($conn, $reporterId, 'report_archived', 'Report ' . ($action === 'delete' ? 'Removed' : 'Archived'),
        'Your report (' . $referenceId . ') has been ' . ($action === 'delete' ? 'removed from the active list' : 'archived') . ' by ' . $fullName . '.',
        'report', $reportId);

    echo json_encode(['success' => true, 'message' => $action === 'delete' ? 'Report removed' : 'Report archived']);
} else {
    // Reject with reason
    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE reports SET status = 'rejected', rejection_reason = ?, validated_by = ?, validated_at = NOW() WHERE report_id = ?");
    $stmt->bind_param('sii', $reason, $userId, $reportId);
    $stmt->execute();
    $stmt->close();

    // Notify the resident reporter
    createNotification($conn, $reporterId, 'report_rejected', 'Report Rejected',
        'Your report has been rejected by ' . $fullName . '. Reason: ' . $reason,
        'report', $reportId);

    // Send email to resident reporter
    sendReportEmail($conn, $reportId, 'rejected', $reason, $fullName);
    sendReportSms($conn, $reportId, 'rejected', $fullName, $reason);

    echo json_encode(['success' => true, 'message' => 'Report rejected']);
}
