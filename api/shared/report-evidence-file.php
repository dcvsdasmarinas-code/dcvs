<?php
$sessionName = trim((string)($_GET['sess'] ?? ''));
session_name(in_array($sessionName, ['DCVS_BARANGAY_SESSION', 'PHPSESSID'], true)
    ? $sessionName
    : 'DCVS_STAFF_SESSION');
session_start();
require_once __DIR__ . '/../../db.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$role = (string)($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if (($_SESSION['logged_in'] ?? false) !== true
    || !in_array($role, ['resident', 'staff', 'barangay'], true)
    || $userId <= 0) {
    http_response_code(401);
    exit;
}

$attachmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$attachmentId || $attachmentId <= 0) {
    http_response_code(400);
    exit;
}
$query = $conn->prepare("SELECT a.file_url, a.mime_type, a.attachment_type, a.attachment_source,
        a.shared_with_resident, r.reporter_id, r.barangay_id
    FROM report_attachments a
    JOIN reports r ON r.report_id = a.report_id
    WHERE a.attachment_id = ? AND r.archived_at IS NULL");
if (!$query) {
    error_log('Could not prepare report evidence file access check: ' . $conn->error);
    http_response_code(500);
    exit;
}
$query->bind_param('i', $attachmentId);
if (!$query->execute()) {
    error_log('Could not execute report evidence file access check: ' . $query->error);
    $query->close();
    http_response_code(500);
    exit;
}
$attachment = $query->get_result()->fetch_assoc();
$query->close();
if (!$attachment) {
    http_response_code(404);
    exit;
}

$residentVisibleSources = [
    'barangay_validation',
    'barangay_secured',
    'barangay_turnover',
    'barangay_action',
    'staff_action',
];
$allowed = $role === 'staff'
    || ($role === 'barangay' && $barangayId > 0 && (int)$attachment['barangay_id'] === $barangayId)
    || ($role === 'resident'
        && (int)$attachment['reporter_id'] === $userId
        && ($attachment['attachment_source'] === 'resident'
            || in_array((string)$attachment['attachment_source'], $residentVisibleSources, true)));
if (!$allowed || $attachment['attachment_type'] !== 'photo') {
    http_response_code(403);
    exit;
}

$relativePath = (string)$attachment['file_url'];
if (strpos($relativePath, 'uploads/report-evidence/') !== 0
    || basename($relativePath) !== substr($relativePath, strlen('uploads/report-evidence/'))) {
    http_response_code(404);
    exit;
}
$absolutePath = __DIR__ . '/../../' . $relativePath;
if (!is_file($absolutePath) || !is_readable($absolutePath)) {
    http_response_code(404);
    exit;
}
$mimeType = (string)$attachment['mime_type'];
if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($absolutePath));
readfile($absolutePath);
