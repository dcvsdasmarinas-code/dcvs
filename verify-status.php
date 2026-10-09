<?php
require_once 'db.php';

header('Content-Type: application/json');

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    echo json_encode(['status' => 'invalid', 'verified' => false]);
    exit;
}

$stmt = $conn->prepare("SELECT verified, used, expires_at FROM password_resets WHERE token = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo json_encode(['status' => 'invalid', 'verified' => false]);
    exit;
}

if ($row['used']) {
    echo json_encode(['status' => 'used', 'verified' => false]);
    exit;
}

if (strtotime($row['expires_at']) < time()) {
    echo json_encode(['status' => 'expired', 'verified' => false]);
    exit;
}

if ($row['verified']) {
    echo json_encode(['status' => 'verified', 'verified' => true]);
    exit;
}

echo json_encode(['status' => 'pending', 'verified' => false]);
