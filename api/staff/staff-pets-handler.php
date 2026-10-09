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
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../includes/adoption-workflow.php';

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
    case 'sync_from_pool':
        $response = handleSyncFromPool($conn, $_SESSION['user_id']);
        break;
    case 'get_all':
        $response = handleGetAll($conn);
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
    $dest = __DIR__ . '/../../uploads/pets/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/pets/' . $filename;
    }
    return null;
}

function storePoolPhoto($image) {
    if (strpos($image, 'data:image/') !== 0) return $image;
    if (!preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,(.+)$#s', $image, $matches)) return '';

    $binary = base64_decode(str_replace(' ', '+', $matches[2]), true);
    if ($binary === false) return '';

    $extension = $matches[1] === 'jpeg' ? 'jpg' : strtolower($matches[1]);
    $directory = __DIR__ . '/../../uploads/pets/';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) return '';

    $filename = 'pet_pool_' . uniqid('', true) . '.' . $extension;
    if (file_put_contents($directory . $filename, $binary) === false) return '';
    return 'uploads/pets/' . $filename;
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
    $saveMode = $_POST['save_mode'] ?? '';

    if ($saveMode === 'draft') {
        $status = 'draft';
    }

    if (empty($name) || empty($breed) || empty($age) || empty($weight) || empty($color) || empty($rescuedDate) || empty($description)) {
        return ['success' => false, 'message' => 'All required fields must be filled'];
    }

    if ($status === 'adopted') {
        return ['success' => false, 'message' => 'A pet can only be marked adopted by approving its adoption application.'];
    }
    if ($saveMode !== 'draft' && !in_array($status, ['available', 'withdrawn'])) {
        $status = 'available';
    }

    $photoUrl = $_POST['current_photo'] ?? null;

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT photo_url, status FROM pet_listings WHERE listing_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($existing['status'] ?? '') === 'reserved') {
            return ['success' => false, 'message' => 'A reserved pet listing cannot be edited until its application is resolved.'];
        }
        if (($existing['status'] ?? '') === 'adopted') {
            return ['success' => false, 'message' => 'An adopted pet listing cannot be changed.'];
        }
        if (!$photoUrl) $photoUrl = $existing['photo_url'] ?? null;
    }

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = uploadPhoto($_FILES['photo'], 'pet_photo');
        if ($uploaded) {
            if ($photoUrl && strpos($photoUrl, 'uploads/pets/') === 0 && file_exists(__DIR__ . '/../../' . $photoUrl)) {
                unlink(__DIR__ . '/../../' . $photoUrl);
            }
            $photoUrl = $uploaded;
        }
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE pet_listings SET name=?, species=?, breed=?, age=?, gender=?, weight=?, color=?, rescued_date=?, description=?, photo_url=?, status=? WHERE listing_id=? AND status NOT IN ('reserved','adopted')");
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

    $applicationStmt = $conn->prepare(
        'SELECT COUNT(*) FROM adoption_applications WHERE pet_listing_id = ?'
    );
    if ($applicationStmt) {
        $applicationStmt->bind_param('i', $id);
        $applicationStmt->execute();
        $hasHistory = (int)$applicationStmt->get_result()->fetch_row()[0] > 0;
        $applicationStmt->close();
        if ($hasHistory) {
            return ['success' => false, 'message' => 'This listing has adoption application history and cannot be deleted.'];
        }
    }

    $stmt = $conn->prepare("SELECT photo_url FROM pet_listings WHERE listing_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM pet_listings WHERE listing_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($row && $row['photo_url'] && strpos($row['photo_url'], 'uploads/pets/') === 0 && file_exists(__DIR__ . '/../../' . $row['photo_url'])) {
            unlink(__DIR__ . '/../../' . $row['photo_url']);
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

    if (in_array($row['status'], ['reserved', 'adopted'], true)) {
        return ['success' => false, 'message' => 'Reserved or adopted pets can only be changed through the application review workflow'];
    }
    $newStatus = $row['status'] === 'available' ? 'adopted' : 'available';
    if ($newStatus === 'adopted') {
        return ['success' => false, 'message' => 'A pet can only be marked adopted by approving its adoption application'];
    }
    $stmt = $conn->prepare("UPDATE pet_listings SET status = ? WHERE listing_id = ? AND status = ?");
    $stmt->bind_param("sis", $newStatus, $id, $row['status']);
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

    $appointmentStmt = $conn->prepare("SELECT user_id, pet_id FROM appointments WHERE appointment_id = ? AND appointment_type = 'adoption'");
    $appointmentStmt->bind_param("i", $appointmentId);
    $appointmentStmt->execute();
    $appointment = $appointmentStmt->get_result()->fetch_assoc();
    $appointmentStmt->close();
    if (!$appointment) {
        return ['success' => false, 'message' => 'Adoption appointment not found'];
    }
    $adopterId = (int)$appointment['user_id'];

    $applicationStmt = $conn->prepare(
        "SELECT resulting_pet_id FROM adoption_applications
         WHERE pet_listing_id = ? AND applicant_id = ? AND status = 'approved'
           AND resulting_pet_id IS NOT NULL LIMIT 1"
    );
    $applicationStmt->bind_param("ii", $listingId, $adopterId);
    $applicationStmt->execute();
    $approvedApplication = $applicationStmt->get_result()->fetch_assoc();
    $applicationStmt->close();
    if (!$approvedApplication || (int)$approvedApplication['resulting_pet_id'] !== (int)$appointment['pet_id']) {
        return ['success' => false, 'message' => 'The pet cannot be marked adopted without an approved application'];
    }

    $listingStmt = $conn->prepare("SELECT status, adopted_by FROM pet_listings WHERE listing_id = ?");
    $listingStmt->bind_param("i", $listingId);
    $listingStmt->execute();
    $poolListing = $listingStmt->get_result()->fetch_assoc();
    $listingStmt->close();
    if (!$poolListing || $poolListing['status'] !== 'adopted' || (int)$poolListing['adopted_by'] !== $adopterId) {
        return ['success' => false, 'message' => 'The approved application is not registered to this resident'];
    }

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
    if ($status === 'adopted') {
        return ['success' => false, 'message' => 'A pet can only be marked adopted by approving its adoption application'];
    }
    if (!in_array($status, ['available', 'withdrawn'])) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    $currentStmt = $conn->prepare("SELECT status FROM pet_listings WHERE listing_id = ?");
    $currentStmt->bind_param('i', $id);
    $currentStmt->execute();
    $current = $currentStmt->get_result()->fetch_assoc();
    $currentStmt->close();
    if (!$current) return ['success' => false, 'message' => 'Listing not found'];
    if (in_array($current['status'], ['reserved', 'adopted'], true)) {
        return ['success' => false, 'message' => 'Reserved or adopted pets can only be changed through the application review workflow'];
    }
    $stmt = $conn->prepare("UPDATE pet_listings SET status = ? WHERE listing_id = ? AND status NOT IN ('reserved','adopted')");
    $stmt->bind_param("si", $status, $id);
    if ($stmt->execute()) {
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated !== 1) return ['success' => false, 'message' => 'The listing status changed and could not be updated'];
        return ['success' => true, 'message' => 'Listing updated', 'status' => $status];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Update failed'];
}

function handleSyncFromPool($conn, $userId) {
    $poolId = $_POST['pool_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $species = $_POST['species'] ?? 'Dog';
    $breed = trim($_POST['breed'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $gender = $_POST['gender'] ?? 'Male';
    $rescueDate = trim($_POST['rescue_date'] ?? '');
    $image = trim($_POST['image'] ?? '');
    $adoptionStatus = $_POST['adoption_status'] ?? 'Available';
    $listingStatus = $_POST['listing_status'] ?? 'Not Listed';
    $existingListingId = (int)($_POST['listing_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $rescueIntakeId = (int)($_POST['rescue_intake_id'] ?? 0);

    $image = storePoolPhoto($image);

    if (empty($name)) {
        return ['success' => false, 'message' => 'Pet name is required'];
    }

    if ($adoptionStatus === 'Adopted') {
        $dbStatus = 'adopted';
    } elseif ($listingStatus === 'Published' && $adoptionStatus === 'Available') {
        $dbStatus = 'available';
    } elseif ($listingStatus === 'Archived') {
        $dbStatus = 'withdrawn';
    } else {
        $dbStatus = 'withdrawn';
    }

    if ($dbStatus === 'adopted') {
        if ($existingListingId <= 0) {
            return ['success' => false, 'message' => 'A pet can only be marked adopted by approving its adoption application'];
        }
        $approvedStmt = $conn->prepare(
            "SELECT COUNT(*) FROM adoption_applications a
             JOIN adoption_listings al ON al.source_listing_id = a.pet_listing_id
                 AND al.pet_id = a.resulting_pet_id
             JOIN pet_listings pl ON pl.listing_id = a.pet_listing_id
             WHERE a.pet_listing_id = ? AND a.status = 'approved'
               AND a.resulting_pet_id IS NOT NULL AND pl.status = 'adopted'"
        );
        $approvedStmt->bind_param('i', $existingListingId);
        $approvedStmt->execute();
        $hasApproval = (int)$approvedStmt->get_result()->fetch_row()[0] > 0;
        $approvedStmt->close();
        if (!$hasApproval) {
            return ['success' => false, 'message' => 'A pet can only be marked adopted by approving its adoption application'];
        }
        return ['success' => true, 'message' => 'The approved adoption is already recorded', 'listing_id' => $existingListingId];
    }

    if ($existingListingId > 0) {
        $stmt = $conn->prepare("UPDATE pet_listings SET name=?, species=?, breed=?, age=?, gender=?, rescued_date=?, photo_url=?, status=?, description=?, rescue_intake_id=COALESCE(NULLIF(?,0), rescue_intake_id) WHERE listing_id=? AND status NOT IN ('reserved','adopted')");
        $stmt->bind_param("sssssssssii", $name, $species, $breed, $age, $gender, $rescueDate, $image, $dbStatus, $notes, $rescueIntakeId, $existingListingId);
        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'Listing synced', 'listing_id' => $existingListingId, 'photo_url' => $image];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Update failed: ' . $conn->error];
    } else {
        // Check if a record with matching name+species+rescued_date already exists
        $check = $conn->prepare("SELECT listing_id FROM pet_listings WHERE name = ? AND species = ? AND rescued_date = ? LIMIT 1");
        $check->bind_param("sss", $name, $species, $rescueDate);
        $check->execute();
        $checkResult = $check->get_result();
        $existing = $checkResult->fetch_assoc();
        $check->close();

        if ($existing) {
            // Update existing record instead of inserting a duplicate
            $existingId = $existing['listing_id'];
            $stmt = $conn->prepare("UPDATE pet_listings SET name=?, species=?, breed=?, age=?, gender=?, rescued_date=?, photo_url=?, status=?, description=?, rescue_intake_id=COALESCE(NULLIF(?,0), rescue_intake_id), updated_at=NOW() WHERE listing_id=? AND status NOT IN ('reserved','adopted')");
            $stmt->bind_param("sssssssssii", $name, $species, $breed, $age, $gender, $rescueDate, $image, $dbStatus, $notes, $rescueIntakeId, $existingId);
            if ($stmt->execute()) {
                $stmt->close();
                return ['success' => true, 'message' => 'Existing listing updated', 'listing_id' => $existingId, 'photo_url' => $image];
            }
            $stmt->close();
            return ['success' => false, 'message' => 'Update failed: ' . $conn->error];
        }

        $weight = '0';
        $color = '';
            $stmt = $conn->prepare("INSERT INTO pet_listings (name, species, breed, age, gender, weight, color, rescued_date, photo_url, status, description, rescue_intake_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?)");
            $stmt->bind_param("sssssssssssii", $name, $species, $breed, $age, $gender, $weight, $color, $rescueDate, $image, $dbStatus, $notes, $rescueIntakeId, $userId);
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();
            return ['success' => true, 'message' => 'Listing created', 'listing_id' => $newId, 'photo_url' => $image];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Insert failed: ' . $conn->error];
    }
}

function handleGetAll($conn) {
    adoptionReleaseExpiredReservations($conn);
    $result = $conn->query("SELECT p.*, ri.source AS rescue_source, ri.rescue_date AS intake_rescue_date, adopter.full_name AS adopter_name, adopter.reference_id AS adopter_reference_id, adopter_barangay.barangay_name AS adopter_barangay, application.application_id AS adoption_application_id, application.status AS adoption_application_status, application.approved_pickup_at, application.finalized_at FROM pet_listings p LEFT JOIN rescue_intakes ri ON ri.intake_id = p.rescue_intake_id LEFT JOIN users adopter ON adopter.user_id = COALESCE(p.adopted_by, (SELECT adopted_pet.owner_id FROM pets adopted_pet JOIN adoption_listings adopted_listing ON adopted_listing.pet_id = adopted_pet.pet_id WHERE LOWER(TRIM(adopted_pet.pet_name)) = LOWER(TRIM(p.name)) AND LOWER(TRIM(adopted_pet.pet_type)) = LOWER(TRIM(p.species)) ORDER BY adopted_listing.created_at DESC, adopted_listing.listing_id DESC LIMIT 1)) LEFT JOIN barangays adopter_barangay ON adopter_barangay.barangay_id = adopter.barangay_id LEFT JOIN adoption_applications application ON application.application_id = (SELECT latest.application_id FROM adoption_applications latest WHERE latest.pet_listing_id = p.listing_id AND latest.status IN ('approved','adopted','expired') ORDER BY latest.application_date DESC, latest.application_id DESC LIMIT 1) ORDER BY p.created_at DESC");
    if (!$result) {
        return ['success' => false, 'message' => 'Could not load adoption pool: ' . $conn->error];
    }
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    return ['success' => true, 'data' => $data];
}
