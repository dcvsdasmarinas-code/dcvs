<?php
session_name(trim((string)($_GET['sess'] ?? '')) === 'DCVS_BARANGAY_SESSION'
    ? 'DCVS_BARANGAY_SESSION'
    : 'DCVS_STAFF_SESSION');
session_start();
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function reportEvidenceReply(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if (($_SESSION['logged_in'] ?? false) !== true
    || !in_array($role, ['staff', 'barangay'], true)
    || $userId <= 0) {
    reportEvidenceReply(401, ['success' => false, 'message' => 'Sign in to add case evidence.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reportEvidenceReply(405, ['success' => false, 'message' => 'Use the evidence upload form.']);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    reportEvidenceReply(403, ['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
}

$reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
if (!$reportId || $reportId <= 0) {
    reportEvidenceReply(400, ['success' => false, 'message' => 'Invalid report evidence request.']);
}

$reportQuery = $conn->prepare("SELECT r.report_id, r.status, r.barangay_id, w.state AS response_state
    FROM reports r
    LEFT JOIN report_response_workflow w ON w.report_id = r.report_id
    WHERE r.report_id = ? AND r.archived_at IS NULL");
if (!$reportQuery) {
    error_log('Could not prepare report evidence access check: ' . $conn->error);
    reportEvidenceReply(500, ['success' => false, 'message' => 'Could not verify this report.']);
}
$reportQuery->bind_param('i', $reportId);
if (!$reportQuery->execute()) {
    error_log('Could not execute report evidence access check: ' . $reportQuery->error);
    $reportQuery->close();
    reportEvidenceReply(500, ['success' => false, 'message' => 'Could not verify this report.']);
}
$report = $reportQuery->get_result()->fetch_assoc();
$reportQuery->close();
if (!$report) reportEvidenceReply(404, ['success' => false, 'message' => 'Report not found.']);
if ($role === 'barangay' && (!$barangayId || (int)$report['barangay_id'] !== $barangayId)) {
    reportEvidenceReply(403, ['success' => false, 'message' => 'This report is outside your barangay.']);
}

$files = $_FILES['evidence_photos'] ?? null;
$names = is_array($files['name'] ?? null) ? $files['name'] : [];
$count = count($names);
if ($count < 1 || $count > 3) {
    reportEvidenceReply(400, ['success' => false, 'message' => 'Choose between 1 and 3 photos.']);
}
$captions = $_POST['captions'] ?? [];
if (!is_array($captions) || count($captions) !== $count) {
    reportEvidenceReply(400, ['success' => false, 'message' => 'Add a caption for each photo.']);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    error_log('Could not initialize MIME detection for case evidence.');
    reportEvidenceReply(500, ['success' => false, 'message' => 'Evidence files could not be validated.']);
}
$extensionByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$validatedFiles = [];
for ($i = 0; $i < $count; $i++) {
    $uploadError = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    $temporaryPath = (string)($files['tmp_name'][$i] ?? '');
    $caption = is_string($captions[$i] ?? null) ? trim($captions[$i]) : '';
    if ($uploadError !== UPLOAD_ERR_OK
        || (int)($files['size'][$i] ?? 0) > 25 * 1024 * 1024
        || $caption === ''
        || (function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption)) > 180
        || !is_uploaded_file($temporaryPath)) {
        finfo_close($finfo);
        reportEvidenceReply(400, ['success' => false, 'message' => 'Each photo must be a valid upload no larger than 25 MB and have a caption up to 180 characters.']);
    }
    $mime = finfo_file($finfo, $temporaryPath);
    if (!is_string($mime) || !isset($extensionByMime[$mime])) {
        finfo_close($finfo);
        reportEvidenceReply(400, ['success' => false, 'message' => 'Upload JPG or PNG photos only.']);
    }
    $validatedFiles[] = ['temporary_path' => $temporaryPath, 'mime' => $mime, 'extension' => $extensionByMime[$mime], 'caption' => $caption];
}
finfo_close($finfo);

$uploadDirectory = __DIR__ . '/../../uploads/report-evidence';
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
    reportEvidenceReply(500, ['success' => false, 'message' => 'Could not prepare the evidence upload directory.']);
}

$storedFiles = [];
$fileNames = [];
try {
    foreach ($validatedFiles as $file) {
        $fileNames[] = bin2hex(random_bytes(18)) . '.' . $file['extension'];
    }
} catch (Throwable $exception) {
    error_log('Could not generate a report evidence file name: ' . $exception->getMessage());
    reportEvidenceReply(500, ['success' => false, 'message' => 'Could not prepare the evidence photos. Please try again.']);
}
foreach ($validatedFiles as $index => $file) {
    $fileName = $fileNames[$index];
    $absolutePath = $uploadDirectory . '/' . $fileName;
    if (!move_uploaded_file($file['temporary_path'], $absolutePath)) {
        foreach ($storedFiles as $storedFile) {
            if (is_file($storedFile['absolute_path']) && !unlink($storedFile['absolute_path'])) {
                error_log('Could not remove a partially saved report evidence photo.');
            }
        }
        reportEvidenceReply(500, ['success' => false, 'message' => 'Could not save the evidence photos.']);
    }
    $storedFiles[] = [
        'absolute_path' => $absolutePath,
        'url' => 'uploads/report-evidence/' . $fileName,
        'mime' => $file['mime'],
        'caption' => $file['caption'],
    ];
}

$attachmentSource = $role === 'barangay' ? 'barangay_action' : 'staff_action';
$sharedWithResident = 1;
$currentState = (string)($report['response_state'] ?: $report['status']);
if (!$conn->begin_transaction()) {
    foreach ($storedFiles as $file) {
        if (is_file($file['absolute_path']) && !unlink($file['absolute_path'])) {
            error_log('Could not remove report evidence photo after transaction start failed.');
        }
    }
    error_log('Could not start report evidence transaction: ' . $conn->error);
    reportEvidenceReply(500, ['success' => false, 'message' => 'Could not start the evidence update. Please try again.']);
}
try {
    $attachment = $conn->prepare("INSERT INTO report_attachments
        (report_id, attachment_type, attachment_source, file_url, mime_type, caption, shared_with_resident, uploaded_by, display_order)
        VALUES (?, 'photo', ?, ?, ?, ?, ?, ?, 0)");
    if (!$attachment) throw new RuntimeException('Could not prepare the evidence record.');
    $attachmentIds = [];
    foreach ($storedFiles as $file) {
        $attachment->bind_param('issssii', $reportId, $attachmentSource, $file['url'], $file['mime'], $file['caption'], $sharedWithResident, $userId);
        if (!$attachment->execute()) throw new RuntimeException('Could not save the evidence record.');
        $attachmentIds[] = (int)$attachment->insert_id;
    }
    $attachment->close();

    $history = $conn->prepare("INSERT INTO report_response_history
        (report_id, actor_id, actor_role, action, from_state, to_state, reason)
        VALUES (?, ?, ?, 'add_photo_evidence', ?, ?, 'Added photo evidence')");
    if (!$history) throw new RuntimeException('Could not prepare the case update.');
    $history->bind_param('iisss', $reportId, $userId, $role, $currentState, $currentState);
    if (!$history->execute()) throw new RuntimeException('Could not save the case update.');
    $historyId = (int)$history->insert_id;
    $history->close();
    $linkEvidence = $conn->prepare("UPDATE report_attachments SET response_history_id = ? WHERE attachment_id = ?");
    if (!$linkEvidence) throw new RuntimeException('Could not link the photos to the case update.');
    foreach ($attachmentIds as $attachmentId) {
        $linkEvidence->bind_param('ii', $historyId, $attachmentId);
        if (!$linkEvidence->execute()) throw new RuntimeException('Could not link the photos to the case update.');
    }
    $linkEvidence->close();
    if (!$conn->commit()) throw new RuntimeException('Could not complete the evidence upload.');
} catch (Throwable $exception) {
    $conn->rollback();
    foreach ($storedFiles as $file) {
        if (is_file($file['absolute_path']) && !unlink($file['absolute_path'])) {
            error_log('Could not remove report evidence photo after failed database save.');
        }
    }
    error_log('Report evidence upload failed: ' . $exception->getMessage());
    reportEvidenceReply(500, ['success' => false, 'message' => 'Evidence could not be saved. Please try again.']);
}

reportEvidenceReply(200, ['success' => true, 'message' => 'Photo evidence added.', 'shared_with_resident' => (bool)$sharedWithResident]);
