<?php
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token']);
    exit;
}

$stmt = $conn->prepare("SELECT id, verified, used, expires_at FROM password_resets WHERE token = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

if ($row['used']) {
    echo json_encode(['error' => 'Token already used', 'status' => 'used']);
    exit;
}

if (strtotime($row['expires_at']) < time()) {
    echo json_encode(['error' => 'Token expired', 'status' => 'expired']);
    exit;
}

if ($row['verified']) {
    echo json_encode(['message' => 'Already verified', 'status' => 'verified']);
    exit;
}

$stmt = $conn->prepare("UPDATE password_resets SET verified = 1 WHERE id = ?");
$stmt->bind_param("i", $row['id']);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['message' => 'Verified successfully', 'status' => 'verified']);
