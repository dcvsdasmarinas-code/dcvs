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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$petId = intval($input['pet_id'] ?? 0);
$newStatus = trim($input['status'] ?? '');

if ($petId <= 0 || !in_array($newStatus, ['available', 'rescued'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid pet_id or status']);
    exit;
}

$check = $conn->prepare("SELECT listing_id, status, name FROM pet_listings WHERE listing_id = ?");
$check->bind_param("i", $petId);
$check->execute();
$row = $check->get_result()->fetch_assoc();
$check->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Animal not found']);
    exit;
}

if (in_array($row['status'], ['reserved', 'adopted'], true)) {
    http_response_code(409);
    echo json_encode(['error' => 'Reserved or adopted pets can only be changed through the adoption application workflow']);
    exit;
}

$stmt = $conn->prepare("UPDATE pet_listings SET status = ?, updated_at = NOW() WHERE listing_id = ? AND status NOT IN ('reserved','adopted')");
$stmt->bind_param("si", $newStatus, $petId);
$stmt->execute();
$updated = $stmt->affected_rows;
$stmt->close();
if ($updated !== 1) {
    http_response_code(409);
    echo json_encode(['error' => 'The pet status changed; reserved or adopted pets cannot be changed through this action']);
    exit;
}

echo json_encode([
    'success' => true,
    'pet_id' => $petId,
    'old_status' => $row['status'],
    'new_status' => $newStatus,
    'name' => $row['name'],
]);
