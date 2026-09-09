<?php
/**
 * Barangay Report Validation / Rejection Handler
 * Validates a pending report (forwards to DCVS staff) or rejects it with a reason.
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
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$fullName   = $_SESSION['full_name'] ?? 'Barangay Officer';

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$reportId = (int)($input['report_id'] ?? 0);
$action   = trim($input['action'] ?? '');
$reason   = trim($input['reason'] ?? '');

if ($reportId <= 0 || !in_array($action, ['validate', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Fetch report (must belong to this barangay and be pending)
$stmt = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.status, r.reporter_id, r.report_type,
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

if ($report['status'] !== 'pending') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Report is no longer pending']);
    exit;
}

$brgyName = $report['barangay_name'] ?: 'Barangay';
$reporterId = (int)$report['reporter_id'];
$referenceId = $report['reference_id'] ?? ('RPT-' . $reportId);

if ($action === 'validate') {
    // Mark as validated
    $stmt = $conn->prepare("UPDATE reports SET status = 'validated', validated_by = ?, validated_at = NOW() WHERE report_id = ?");
    $stmt->bind_param('ii', $userId, $reportId);
    $stmt->execute();
    $stmt->close();

    // Notify the resident reporter
    createNotification($conn, $reporterId, 'report_validated', 'Report Validated by Barangay',
        'Your report has been validated by ' . $fullName . ' and forwarded to DCVS for confirmation.',
        'report', $reportId);

    // Notify all DCVS staff
    $staffStmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
    $staffStmt->execute();
    $staffResult = $staffStmt->get_result();
    while ($staffRow = $staffResult->fetch_assoc()) {
        createNotification($conn, (int)$staffRow['user_id'], 'report_validated', 'New Validated Report from Barangay',
            $fullName . ' validated and forwarded a report from ' . $brgyName . ' to DCVS.',
            'report', $reportId);
    }
    $staffStmt->close();

    // Send email to resident reporter
    sendReportEmail($conn, $reportId, 'validated', '', $fullName);

    echo json_encode(['success' => true, 'message' => 'Report validated and forwarded to DCVS staff']);
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

    echo json_encode(['success' => true, 'message' => 'Report rejected']);
}
