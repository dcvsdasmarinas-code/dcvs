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
require_once 'mailer.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

switch ($action) {
    case 'save':
        $response = handleSave($conn, $_SESSION['user_id']);
        break;
    case 'delete':
        $response = handleDelete($conn);
        break;
    case 'toggle':
        $response = handleToggle($conn);
        break;
    case 'get':
        $response = handleGet($conn);
        break;
    case 'mark_adopted':
        $response = handleMarkAdopted($conn);
        break;
    case 'set_status':
        $response = handleSetStatus($conn);
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();

function uploadPhoto($file, $prefix) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) return null;
    $filename = $prefix . '_' . uniqid() . '.' . $ext;
    $dest = __DIR__ . '/uploads/pets/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/pets/' . $filename;
    }
    return null;
}

function handleSave($conn, $userId) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $species = $_POST['species'] ?? 'Cat';
    $breed = trim($_POST['breed'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';
    $weight = trim($_POST['weight'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $rescuedDate = trim($_POST['rescued_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'available';

    if (empty($name) || empty($breed) || empty($age) || empty($weight) || empty($color) || empty($rescuedDate) || empty($description)) {
        return ['success' => false, 'message' => 'All required fields must be filled'];
    }

    if (!in_array($status, ['available', 'adopted', 'withdrawn'])) {
        $status = 'available';
    }

    $photoUrl = $_POST['current_photo'] ?? null;

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT photo_url FROM pet_listings WHERE listing_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$photoUrl) $photoUrl = $existing['photo_url'] ?? null;
    }

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = uploadPhoto($_FILES['photo'], 'pet_photo');
        if ($uploaded) {
            if ($photoUrl && strpos($photoUrl, 'uploads/pets/') === 0 && file_exists(__DIR__ . '/' . $photoUrl)) {
                unlink(__DIR__ . '/' . $photoUrl);
            }
            $photoUrl = $uploaded;
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE pet_listings SET name=?, species=?, breed=?, age=?, gender=?, weight=?, color=?, rescued_date=?, description=?, photo_url=?, status=? WHERE listing_id=?");
        $stmt->bind_param("sssssssssssi", $name, $species, $breed, $age, $gender, $weight, $color, $rescuedDate, $description, $photoUrl, $status, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO pet_listings (name, species, breed, age, gender, weight, color, rescued_date, description, photo_url, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssssi", $name, $species, $breed, $age, $gender, $weight, $color, $rescuedDate, $description, $photoUrl, $status, $userId);
    }

    if ($stmt->execute()) {
        $savedId = $id > 0 ? $id : $stmt->insert_id;
        $stmt->close();
        return ['success' => true, 'message' => 'Pet listing saved successfully', 'id' => $savedId];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Database error: ' . $conn->error];
}

function handleDelete($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT photo_url FROM pet_listings WHERE listing_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM pet_listings WHERE listing_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($row && $row['photo_url'] && strpos($row['photo_url'], 'uploads/pets/') === 0 && file_exists(__DIR__ . '/' . $row['photo_url'])) {
            unlink(__DIR__ . '/' . $row['photo_url']);
        }
        $stmt->close();
        return ['success' => true, 'message' => 'Deleted successfully'];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Delete failed'];
}

function handleGet($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];
    $stmt = $conn->prepare("SELECT * FROM pet_listings WHERE listing_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return ['success' => true, 'data' => $row];
    }
    return ['success' => false, 'message' => 'Not found'];
}

function handleToggle($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT status FROM pet_listings WHERE listing_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return ['success' => false, 'message' => 'Not found'];

    $newStatus = $row['status'] === 'available' ? 'adopted' : 'available';
    $stmt = $conn->prepare("UPDATE pet_listings SET status = ? WHERE listing_id = ?");
    $stmt->bind_param("si", $newStatus, $id);
    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Status updated', 'status' => $newStatus];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Update failed'];
}

function handleMarkAdopted($conn) {
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($listingId <= 0 || $appointmentId <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }

    // Update pet listing status to adopted
    $stmt = $conn->prepare("UPDATE pet_listings SET status = 'adopted' WHERE listing_id = ? AND status = 'available'");
    $stmt->bind_param("i", $listingId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        $stmt->close();
        return ['success' => false, 'message' => 'Pet is no longer available or already adopted'];
    }
    $stmt->close();

    // Update appointment status to completed
    $stmt = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ?");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $stmt->close();

    // Notify the resident
    $stmt = $conn->prepare("SELECT a.user_id, COALESCE(a.pet_name, p.pet_name) AS pet_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.appointment_id = ?");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($r) {
        $petName = $r['pet_name'] ?? 'Pet';
        createNotification($conn, $r['user_id'], 'adoption', 'Adoption Completed',
            "Your adoption for $petName has been completed. Welcome to your new family!", 'pets', $listingId);
        $staffName = $_SESSION['full_name'] ?? 'DCVS Staff';
        sendAdoptionCompleteEmail($conn, $appointmentId, $listingId, $staffName);
    }

    return ['success' => true, 'message' => 'Pet marked as adopted and appointment completed'];
}

function handleSetStatus($conn) {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];
    if (!in_array($status, ['available', 'adopted', 'withdrawn'])) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    $stmt = $conn->prepare("UPDATE pet_listings SET status = ? WHERE listing_id = ?");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true, 'message' => 'Listing updated', 'status' => $status];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Update failed'];
}

