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

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

// Fetch owner information
$ownerQuery = $conn->prepare("
    SELECT 
        u.user_id,
        u.reference_id,
        u.full_name,
        u.email,
        u.phone,
        u.address,
        u.created_at as registration_date,
        b.barangay_name
    FROM users u
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    WHERE u.user_id = ? AND u.role = 'resident'
");
$ownerQuery->bind_param("i", $userId);
$ownerQuery->execute();
$ownerResult = $ownerQuery->get_result();

if ($ownerResult->num_rows === 0) {
    echo json_encode(['error' => 'Owner not found']);
    exit;
}

$owner = $ownerResult->fetch_assoc();
$ownerQuery->close();

// Fetch owner's pets
$petsQuery = $conn->prepare("
    SELECT 
        p.pet_id,
        p.reference_id,
        p.pet_name,
        p.pet_type,
        p.breed,
        p.gender,
        p.age,
        p.color,
        p.vaccination_status,
        p.photo_url,
        p.created_at as registration_date
    FROM pets p
    WHERE p.owner_id = ? AND p.archived_at IS NULL
    ORDER BY p.pet_id ASC
");
$petsQuery->bind_param("i", $userId);
$petsQuery->execute();
$petsResult = $petsQuery->get_result();

$pets = [];
while ($row = $petsResult->fetch_assoc()) {
    $pets[] = $row;
}
$petsQuery->close();

echo json_encode([
    'success' => true,
    'owner' => $owner,
    'pets' => $pets
]);
