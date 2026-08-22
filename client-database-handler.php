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

require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

switch ($action) {
    case 'get_client':
        $response = handleGetClient($conn);
        break;
    case 'add_client':
        $response = handleAddClient($conn, $_SESSION['user_id']);
        break;
    case 'update_client':
        $response = handleUpdateClient($conn, $_SESSION['user_id']);
        break;
    case 'archive_client':
        $response = handleArchiveClient($conn, $_SESSION['user_id']);
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();

function cpdLog($conn, $userId, $action, $details) {
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $action, $details);
    $stmt->execute();
    $stmt->close();
}

function cpdInitials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') $initials .= strtoupper(substr($p, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
}

function handleGetClient($conn) {
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => 'Invalid client ID'];

    $q = $conn->prepare("
        SELECT u.user_id, u.reference_id, u.full_name, u.email, u.phone, u.address,
               u.barangay_id, u.avatar_url, u.status, u.gender, u.birthdate, u.created_at,
               b.barangay_name
        FROM users u
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE u.user_id = ? AND u.role = 'resident' AND u.archived_at IS NULL
    ");
    $q->bind_param("i", $userId);
    $q->execute();
    $client = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$client) return ['success' => false, 'message' => 'Client not found'];

    $pq = $conn->prepare("
        SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color,
               vaccination_status, photo_url, created_at
        FROM pets
        WHERE owner_id = ? AND archived_at IS NULL
        ORDER BY pet_id ASC
    ");
    $pq->bind_param("i", $userId);
    $pq->execute();
    $petsResult = $pq->get_result();
    $pets = [];
    while ($row = $petsResult->fetch_assoc()) $pets[] = $row;
    $pq->close();

    $client['registration_date'] = date('M d, Y', strtotime($client['created_at']));
    $client['avatar'] = (string)$client['avatar_url'];
    $client['initials'] = cpdInitials($client['full_name']);

    return ['success' => true, 'client' => $client, 'pets' => $pets];
}

function handleAddClient($conn, $staffId) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $barangayId = (int)($_POST['barangay_id'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $birthdate = $_POST['birthdate'] ?? '';

    if ($fullName === '' || $email === '' || $phone === '' || $barangayId <= 0) {
        return ['success' => false, 'message' => 'Full name, email, phone and barangay are required'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }

    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'A user with this email already exists'];
    }
    $check->close();

    // Username from email prefix, made unique
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0]));
    if ($base === '') $base = 'resident';
    $username = $base;
    $suffix = 1;
    while (true) {
        $uc = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $uc->bind_param("s", $username);
        $uc->execute();
        if ($uc->get_result()->num_rows === 0) { $uc->close(); break; }
        $uc->close();
        $suffix++;
        $username = $base . $suffix;
    }

    // Random temporary password, returned to staff once
    $tempPass = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 8);
    $hash = password_hash($tempPass, PASSWORD_DEFAULT);

    $refId = generateReferenceId($conn, '#RES-', 'users');

    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, role, barangay_id, phone, address, gender, birthdate, status, reference_id, terms_agreed, terms_agreed_at) VALUES (?, ?, ?, ?, 'resident', ?, ?, ?, ?, ?, 'active', ?, 1, NOW())");
    $stmt->bind_param("ssssisssss", $username, $hash, $email, $fullName, $barangayId, $phone, $address, $gender, $birthdate, $refId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $newId = $stmt->insert_id;
    $stmt->close();

    cpdLog($conn, $staffId, 'Client Added', "Added client '{$fullName}' ({$refId})");
    cpdLog($conn, $newId, 'Account Created', 'Client account registered by DCVS staff.');

    return ['success' => true, 'message' => "Client added. Temporary password: {$tempPass}", 'client_id' => $newId];
}

function handleUpdateClient($conn, $staffId) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $barangayId = (int)($_POST['barangay_id'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $birthdate = $_POST['birthdate'] ?? '';
    $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

    if ($userId <= 0 || $fullName === '' || $email === '' || $phone === '' || $barangayId <= 0) {
        return ['success' => false, 'message' => 'Full name, email, phone and barangay are required'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }

    $exists = $conn->prepare("SELECT user_id, reference_id, full_name FROM users WHERE user_id = ? AND role = 'resident' AND archived_at IS NULL");
    $exists->bind_param("i", $userId);
    $exists->execute();
    $row = $exists->get_result()->fetch_assoc();
    $exists->close();
    if (!$row) return ['success' => false, 'message' => 'Client not found'];

    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
    $check->bind_param("si", $email, $userId);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'A different user already uses this email'];
    }
    $check->close();

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, barangay_id = ?, address = ?, gender = ?, birthdate = ?, status = ? WHERE user_id = ?");
    $stmt->bind_param("sssissssi", $fullName, $email, $phone, $barangayId, $address, $gender, $birthdate, $status, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $stmt->close();

    cpdLog($conn, $staffId, 'Client Updated', "Updated client '{$row['full_name']}' ({$row['reference_id']})");

    return ['success' => true, 'message' => 'Client updated successfully'];
}

function handleArchiveClient($conn, $staffId) {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => 'Invalid client ID'];

    $exists = $conn->prepare("SELECT user_id, reference_id, full_name FROM users WHERE user_id = ? AND role = 'resident' AND archived_at IS NULL");
    $exists->bind_param("i", $userId);
    $exists->execute();
    $row = $exists->get_result()->fetch_assoc();
    $exists->close();
    if (!$row) return ['success' => false, 'message' => 'Client not found'];

    $stmt = $conn->prepare("UPDATE users SET archived_at = NOW(), status = 'inactive' WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $stmt->close();

    cpdLog($conn, $staffId, 'Client Archived', "Archived client '{$row['full_name']}' ({$row['reference_id']})");

    return ['success' => true, 'message' => 'Client archived'];
}
