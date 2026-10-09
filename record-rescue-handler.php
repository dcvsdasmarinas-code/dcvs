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
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$recordType = trim($input['record_type'] ?? 'rescue_intake');
$animalCount = intval($input['animal_count'] ?? 0);
$source = trim($input['source'] ?? '');
$rescueDate = trim($input['rescue_date'] ?? '');
$barangayId = intval($input['barangay_id'] ?? 0);
$barangayName = trim($input['barangay_name'] ?? '');
$notes = trim($input['notes'] ?? '');
$userId = intval($_SESSION['user_id'] ?? 0);
$isPoolIntake = $recordType === 'pool_intake';

if ($barangayId <= 0 && $barangayName !== '' && strcasecmp($barangayName, 'Other') !== 0) {
    $barangayStmt = $conn->prepare("SELECT barangay_id FROM barangays WHERE barangay_name = ? LIMIT 1");
    if ($barangayStmt) {
        $barangayStmt->bind_param('s', $barangayName);
        $barangayStmt->execute();
        $barangayRow = $barangayStmt->get_result()->fetch_assoc();
        $barangayId = (int)($barangayRow['barangay_id'] ?? 0);
        $barangayStmt->close();
    }
}

if (!$rescueDate || !$source || (!$isPoolIntake && $barangayId <= 0) || $animalCount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$validTypes = ['rescue_intake', 'stray_roundup', 'confiscated', 'pool_intake'];
if (!in_array($recordType, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid record type']);
    exit;
}

$validSources = ['barangay_reported','resident_surrender','stray_roundup','shelter_transfer','confiscated','found_lost','field_operation'];
if (!in_array($source, $validSources, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid source']);
    exit;
}

$conn->begin_transaction();

try {
    if ($barangayId > 0) {
        $intakeQ = $conn->prepare("INSERT INTO rescue_intakes (rescue_date, source, barangay_id, animal_count, notes, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $intakeQ->bind_param("ssiisi", $rescueDate, $source, $barangayId, $animalCount, $notes, $userId);
    } else {
        $intakeQ = $conn->prepare("INSERT INTO rescue_intakes (rescue_date, source, barangay_id, animal_count, notes, recorded_by, created_at) VALUES (?, ?, NULL, ?, ?, ?, NOW())");
        $intakeQ->bind_param("ssisi", $rescueDate, $source, $animalCount, $notes, $userId);
    }
    $intakeQ->execute();
    $intakeId = $intakeQ->insert_id;
    $intakeQ->close();

    if (!$isPoolIntake) {
        $petQ = $conn->prepare("INSERT INTO pet_listings (name, species, breed, age, gender, weight, color, rescued_date, description, rescue_intake_id, status, created_by, created_at) VALUES (?, 'dog', 'Unknown', '1', 'male', '0', 'Unknown', ?, ?, ?, 'rescued', ?, NOW())");

        for ($i = 1; $i <= $animalCount; $i++) {
            $petName = 'Rescue #' . $i;
            $desc = ucfirst(str_replace('_', ' ', $recordType)) . ' from intake #' . $intakeId;
            $petQ->bind_param("sssisi", $petName, $rescueDate, $desc, $intakeId, $userId);
            $petQ->execute();
        }
        $petQ->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'intake_id' => $intakeId,
        'record_type' => $recordType,
        'animal_count' => $animalCount,
        'message' => "Recorded $animalCount rescued animal(s) from intake #$intakeId",
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save record: ' . $e->getMessage()]);
}
