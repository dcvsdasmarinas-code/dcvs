<?php
session_name('DCVS_BARANGAY_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly'  => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'barangay') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$userId     = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$reportId = (int)($input['report_id'] ?? 0);
if ($reportId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
    exit;
}

// Verify report belongs to this barangay and is archived
$stmt = $conn->prepare("SELECT report_id, archived_at FROM reports WHERE report_id = ? AND barangay_id = ? AND archived_at IS NOT NULL");
$stmt->bind_param('ii', $reportId, $barangayId);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Archived report not found']);
    exit;
}

// Restore: set archived_at to NULL
$stmt = $conn->prepare("UPDATE reports SET archived_at = NULL WHERE report_id = ?");
$stmt->bind_param('i', $reportId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Report restored successfully']);
