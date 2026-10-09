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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

if ($action === 'update_report' && !validateCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
    exit;
}

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
    case 'archive_report':
        $response = handleArchiveReport($conn, $_SESSION['user_id']);
        break;
    case 'update_report':
        $response = handleUpdateReport($conn, $_SESSION['user_id']);
        break;
    case 'archive_appointment':
        $response = handleArchiveAppointment($conn, $_SESSION['user_id']);
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

    $history = [];
    $clientName = (string)$client['full_name'];

    if (!empty($client['created_at'])) {
        $ts = strtotime($client['created_at']);
        $history[] = ['t' => 'Account registered', 'a' => 'DCVS', 'd' => date('M d, Y', $ts), 'ts' => $ts];
    }

    $allPets = $conn->prepare("SELECT pet_name, created_at FROM pets WHERE owner_id = ?");
    $allPets->bind_param("i", $userId);
    $allPets->execute();
    $ap = $allPets->get_result();
    while ($row = $ap->fetch_assoc()) {
        if (empty($row['created_at'])) continue;
        $ts = strtotime($row['created_at']);
        $history[] = ['t' => 'Pet registered: ' . $row['pet_name'], 'a' => $clientName, 'd' => date('M d, Y', $ts), 'ts' => $ts];
    }
    $allPets->close();

    $rq = $conn->prepare("SELECT report_type, created_at FROM reports WHERE reporter_id = ? AND archived_at IS NULL");
    $rq->bind_param("i", $userId);
    $rq->execute();
    $rr = $rq->get_result();
    while ($row = $rr->fetch_assoc()) {
        if (empty($row['created_at'])) continue;
        $ts = strtotime($row['created_at']);
        $history[] = ['t' => ucfirst($row['report_type']) . ' report submitted', 'a' => $clientName, 'd' => date('M d, Y', $ts), 'ts' => $ts];
    }
    $rq->close();

    $aq = $conn->prepare("SELECT appointment_type, created_at FROM appointments WHERE user_id = ? AND archived_at IS NULL");
    $aq->bind_param("i", $userId);
    $aq->execute();
    $ar = $aq->get_result();
    while ($row = $ar->fetch_assoc()) {
        if (empty($row['created_at'])) continue;
        $ts = strtotime($row['created_at']);
        $history[] = ['t' => ucfirst($row['appointment_type']) . ' appointment booked', 'a' => $clientName, 'd' => date('M d, Y', $ts), 'ts' => $ts];
    }
    $aq->close();

    $lq = $conn->prepare("SELECT action, details, created_at FROM activity_logs WHERE user_id = ? AND action NOT IN ('Account Created','Pet Registered','submit_report','Submitted a Report','Appointment Requested','Adoption Appointment','Login','Logout') ORDER BY created_at DESC LIMIT 20");
    $lq->bind_param("i", $userId);
    $lq->execute();
    $lr = $lq->get_result();
    while ($row = $lr->fetch_assoc()) {
        if (empty($row['created_at'])) continue;
        $ts = strtotime($row['created_at']);
        $title = ($row['details'] !== null && $row['details'] !== '') ? $row['details'] : ucwords(str_replace('_', ' ', $row['action']));
        $history[] = ['t' => $title, 'a' => $clientName, 'd' => date('M d, Y', $ts), 'ts' => $ts];
    }
    $lq->close();

    usort($history, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    foreach ($history as &$h) unset($h['ts']);
    unset($h);

    return ['success' => true, 'client' => $client, 'pets' => $pets, 'history' => $history];
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

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $avatarSession = ['avatar_url' => ''];
        handleAvatarUpload($conn, $newId, $avatarSession);
    }

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

function handleArchiveReport($conn, $staffId) {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) return ['success' => false, 'message' => 'Invalid report ID'];

    $exists = $conn->prepare("SELECT report_id, reference_id FROM reports WHERE report_id = ? AND archived_at IS NULL");
    $exists->bind_param("i", $reportId);
    $exists->execute();
    $row = $exists->get_result()->fetch_assoc();
    $exists->close();
    if (!$row) return ['success' => false, 'message' => 'Report not found'];

    $stmt = $conn->prepare("UPDATE reports SET archived_at = NOW() WHERE report_id = ?");
    $stmt->bind_param("i", $reportId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $stmt->close();

    cpdLog($conn, $staffId, 'Report Archived', "Archived report ({$row['reference_id']})");

    return ['success' => true, 'message' => 'Report archived'];
}

function handleUpdateReport($conn, $staffId) {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) return ['success' => false, 'message' => 'Invalid report ID'];

    $reportType = trim($_POST['report_type'] ?? '');
    $urgencyLevel = trim($_POST['urgency_level'] ?? 'medium');
    $location = trim($_POST['location_address'] ?? '');
    $barangayId = (int)($_POST['barangay_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $dateSubmitted = trim($_POST['date_submitted'] ?? '');
    $animalType = trim($_POST['animal_type'] ?? '');
    $animalBreed = trim($_POST['animal_breed'] ?? '');
    $animalCount = trim($_POST['animal_count'] ?? '');
    $animalBehavior = trim($_POST['animal_behavior'] ?? '');
    $correctionReason = trim($_POST['correction_reason'] ?? '');

    if ($reportType === '' || $location === '' || $barangayId <= 0 || $description === '' || $correctionReason === '') {
        return ['success' => false, 'message' => 'Report fields and a correction reason are required.'];
    }
    if ((function_exists('mb_strlen') ? mb_strlen($correctionReason, 'UTF-8') : strlen($correctionReason)) > 2000) {
        return ['success' => false, 'message' => 'The correction reason must be no longer than 2,000 characters.'];
    }
    $dateObj = DateTime::createFromFormat('!Y-m-d\TH:i', $dateSubmitted);
    $dateErrors = DateTime::getLastErrors();
    if (!$dateObj || ($dateErrors && (!empty($dateErrors['warning_count']) || !empty($dateErrors['error_count'])))
        || $dateObj->format('Y-m-d\TH:i') !== $dateSubmitted) {
        return ['success' => false, 'message' => 'Enter a valid submission date and time.'];
    }
    $dateSubmitted = $dateObj->format('Y-m-d H:i:s');

    $animalParts = [$animalType, $animalBreed, $animalCount, $animalBehavior];
    $animalFilled = 0;
    foreach ($animalParts as $part) {
        if ($part !== '') $animalFilled++;
    }
    if ($animalFilled !== 0 && $animalFilled !== count($animalParts)) {
        return ['success' => false, 'message' => 'Complete all animal information fields or leave them empty'];
    }

    $validTypes = ['stray', 'rabies', 'injured', 'dead'];
    $validUrgency = ['low', 'medium', 'high', 'critical'];
    if (!in_array($reportType, $validTypes, true)) return ['success' => false, 'message' => 'Invalid report type.'];
    if (!in_array($urgencyLevel, $validUrgency, true)) return ['success' => false, 'message' => 'Invalid urgency level.'];

    $barangayCheck = $conn->prepare("SELECT barangay_id FROM barangays WHERE barangay_id = ? LIMIT 1");
    if (!$barangayCheck) return ['success' => false, 'message' => 'Could not verify the selected barangay.'];
    $barangayCheck->bind_param('i', $barangayId);
    $barangayValid = $barangayCheck->execute() && (bool)$barangayCheck->get_result()->fetch_assoc();
    $barangayCheck->close();
    if (!$barangayValid) return ['success' => false, 'message' => 'Choose a valid barangay.'];

    if (!$conn->begin_transaction()) return ['success' => false, 'message' => 'Could not start the report correction.'];
    try {
        $exists = $conn->prepare("SELECT report_id, reference_id, status, report_type, urgency_level, location_address, barangay_id,
                description, created_at, animal_type, animal_breed, animal_count, animal_behavior
            FROM reports WHERE report_id = ? AND archived_at IS NULL FOR UPDATE");
        if (!$exists) throw new RuntimeException('Could not load the report for correction.');
        $exists->bind_param('i', $reportId);
        if (!$exists->execute()) throw new RuntimeException('Could not load the report for correction.');
        $row = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!$row) throw new RuntimeException('Report not found.');

        $beforeValues = [
            'report_type' => (string)$row['report_type'],
            'urgency_level' => (string)$row['urgency_level'],
            'location_address' => (string)$row['location_address'],
            'barangay_id' => (int)$row['barangay_id'],
            'description' => (string)$row['description'],
            'created_at' => (string)$row['created_at'],
            'animal_type' => (string)($row['animal_type'] ?? ''),
            'animal_breed' => (string)($row['animal_breed'] ?? ''),
            'animal_count' => (string)($row['animal_count'] ?? ''),
            'animal_behavior' => (string)($row['animal_behavior'] ?? ''),
        ];
        $afterValues = [
            'report_type' => $reportType,
            'urgency_level' => $urgencyLevel,
            'location_address' => $location,
            'barangay_id' => $barangayId,
            'description' => $description,
            'created_at' => $dateSubmitted,
            'animal_type' => $animalType,
            'animal_breed' => $animalBreed,
            'animal_count' => $animalCount,
            'animal_behavior' => $animalBehavior,
        ];
        $changedBefore = [];
        $changedAfter = [];
        foreach ($afterValues as $field => $value) {
            if ((string)$beforeValues[$field] !== (string)$value) {
                $changedBefore[$field] = $beforeValues[$field];
                $changedAfter[$field] = $value;
            }
        }
        if (isset($changedAfter['barangay_id'])
            && in_array((string)$row['status'], ['confirmed', 'scheduled', 'resolved'], true)) {
            throw new RuntimeException('An assigned or resolved case cannot be reassigned through a report correction.');
        }
        if (!$changedAfter) {
            if (!$conn->commit()) throw new RuntimeException('Could not complete the report correction check.');
            return ['success' => true, 'message' => 'No report changes were needed.'];
        }

        $beforeJson = json_encode($changedBefore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = json_encode($changedAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($beforeJson === false || $afterJson === false) throw new RuntimeException('Could not prepare the correction audit record.');

        $stmt = $conn->prepare("UPDATE reports SET report_type = ?, urgency_level = ?, location_address = ?, barangay_id = ?,
                description = ?, created_at = ?, animal_type = ?, animal_breed = ?, animal_count = ?, animal_behavior = ?, updated_at = NOW()
            WHERE report_id = ? AND archived_at IS NULL");
        if (!$stmt) throw new RuntimeException('Could not prepare the report correction.');
        $stmt->bind_param('sssissssssi', $reportType, $urgencyLevel, $location, $barangayId, $description, $dateSubmitted, $animalType, $animalBreed, $animalCount, $animalBehavior, $reportId);
        if (!$stmt->execute()) throw new RuntimeException('The report correction could not be saved.');
        $stmt->close();

        $caseCorrection = $conn->prepare("UPDATE confirmed_cases
            SET case_type = ?, location_address = ?, urgency_level = ?, barangay_id = ?
            WHERE report_id = ?");
        if (!$caseCorrection) throw new RuntimeException('Could not prepare the linked case correction.');
        $caseCorrection->bind_param('sssii', $reportType, $location, $urgencyLevel, $barangayId, $reportId);
        if (!$caseCorrection->execute()) throw new RuntimeException('The linked case details could not be synchronized.');
        $caseCorrection->close();

        $audit = $conn->prepare("INSERT INTO report_correction_history
            (report_id, actor_id, reason, before_values, after_values)
            VALUES (?, ?, ?, ?, ?)");
        if (!$audit) throw new RuntimeException('Could not prepare the correction audit record.');
        $audit->bind_param('iisss', $reportId, $staffId, $correctionReason, $beforeJson, $afterJson);
        if (!$audit->execute()) throw new RuntimeException('The correction audit record could not be saved.');
        $audit->close();
        if (!$conn->commit()) throw new RuntimeException('Could not complete the report correction.');
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Audited report correction failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => $exception->getMessage()];
    }

    cpdLog($conn, $staffId, 'Report Updated', "Updated report ({$row['reference_id']})");

    return ['success' => true, 'message' => 'Report updated and the correction was recorded in the audit history.'];
}

function handleArchiveAppointment($conn, $staffId) {
    $apptId = (int)($_POST['appointment_id'] ?? 0);
    if ($apptId <= 0) return ['success' => false, 'message' => 'Invalid appointment ID'];

    $exists = $conn->prepare("SELECT appointment_id, reference_id FROM appointments WHERE appointment_id = ? AND archived_at IS NULL");
    $exists->bind_param("i", $apptId);
    $exists->execute();
    $row = $exists->get_result()->fetch_assoc();
    $exists->close();
    if (!$row) return ['success' => false, 'message' => 'Appointment not found'];

    $stmt = $conn->prepare("UPDATE appointments SET archived_at = NOW() WHERE appointment_id = ?");
    $stmt->bind_param("i", $apptId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    $stmt->close();

    cpdLog($conn, $staffId, 'Appointment Archived', "Archived appointment ({$row['reference_id']})");

    return ['success' => true, 'message' => 'Appointment archived'];
}
