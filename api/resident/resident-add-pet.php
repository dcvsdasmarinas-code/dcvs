<?php
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
require_once __DIR__ . '/../../includes/adoption-workflow.php';
require_once __DIR__ . '/../../includes/pet-duplicates.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session could not be verified. Refresh the page and try again.']);
        exit;
    }
    if (($_POST['from_adoption'] ?? '') === '1') {
        echo json_encode(['success' => false, 'message' => 'Adopted pets can only be registered through an approved adoption application.']);
        exit;
    }
    $owner_id = $_SESSION['user_id'];
    $identityInput = $_POST;
    $speciesInput = $identityInput['species'] ?? '';
    $genderInput = $identityInput['gender'] ?? '';
    if (!is_scalar($speciesInput) || trim((string)$speciesInput) === '') {
        $identityInput['species'] = 'dog';
    }
    if (!is_scalar($genderInput) || trim((string)$genderInput) === '') {
        $identityInput['gender'] = 'male';
    }
    $identity = petDuplicateIdentityFromInput($identityInput);
    $pet_name = $identity['pet_name'];
    $pet_type = $identity['pet_type'] !== '' ? $identity['pet_type'] : 'dog';
    $breed = $identity['breed'];
    $age = $identity['age'] ?? 0;
    $gender = $identity['gender'] !== '' ? $identity['gender'] : 'male';
    $color = $identity['color'];
    $date_of_birth = $identity['date_of_birth'] !== '' ? $identity['date_of_birth'] : null;
    $microchip_id = $identity['microchip_id'] !== '' ? $identity['microchip_id'] : null;
    $vaccinated = $_POST['vaccinated'] ?? 'no';

    if (empty($pet_name)) {
        echo json_encode(['success' => false, 'message' => 'Pet name is required.']);
        exit;
    }
    $identityErrors = petDuplicateIdentityErrors($identity, $identityInput);
    if ($identityErrors) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => implode(' ', $identityErrors)]);
        exit;
    }

    try {
        adoptionBeginResidentPetRegistration($conn, (int)$owner_id);
    } catch (AdoptionWorkflowException $exception) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
        exit;
    }

    try {
        $duplicateMatches = petDuplicateFindCandidates($conn, (int)$owner_id, $identity);
    } catch (RuntimeException $exception) {
        $conn->rollback();
        error_log('Legacy resident pet duplicate lookup failed: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Similar pet profiles could not be checked. Please try again.']);
        exit;
    }

    $weight = floatval($_POST['weight'] ?? 0);

    $vaccinationStatus = '';
    switch ($vaccinated) {
        case 'yes':   $vaccinationStatus = 'Fully vaccinated'; break;
        case 'partial': $vaccinationStatus = 'Partially vaccinated'; break;
        case 'no':    $vaccinationStatus = 'Not vaccinated'; break;
    }

    // Handle photo upload (or use adoption photo URL from hidden field)
    $photoUrl = '';
    if (!empty($_POST['photo_url'])) {
        $photoUrl = $_POST['photo_url'];
    } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($mime, $allowedTypes, true) || !in_array($ext, $allowedExts, true)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, GIF, or WebP.']);
            exit;
        }
        if ($_FILES['photo']['size'] > $maxSize) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
            exit;
        }

        $uploadDir = __DIR__ . '/../../uploads/pets/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $filename = uniqid('pet_') . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
            $photoUrl = 'uploads/pets/' . $filename;
        }
    }

    $refId = generateReferenceId($conn, '#PET-', 'pets');
    $stmt = $conn->prepare("INSERT INTO pets (owner_id, pet_name, pet_type, breed, age, weight, gender, color, date_of_birth, microchip_id, vaccination_status, photo_url, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        $conn->rollback();
        http_response_code(500);
        error_log('Could not prepare legacy pet registration insert: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Failed to add pet. Please try again.']);
        exit;
    }
    $stmt->bind_param("isssidsssssss", $owner_id, $pet_name, $pet_type, $breed, $age, $weight, $gender, $color, $date_of_birth, $microchip_id, $vaccinationStatus, $photoUrl, $refId);
    
    if ($stmt->execute()) {
        $pet_id = $conn->insert_id;
        $stmt->close();
        try {
            petDuplicateCreateReviewRows($conn, $pet_id, $duplicateMatches);
            adoptionCommit($conn);
        } catch (RuntimeException $exception) {
            $conn->rollback();
            error_log('Legacy pet duplicate review save failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to add pet. Please try again.']);
            exit;
        }

        // Log activity
        $action = "Pet Registered";
        $details = "Registered a new pet: " . $pet_name . " (" . $pet_type . ", " . $gender . ")";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $logStmt->bind_param("iss", $owner_id, $action, $details);
        $logStmt->execute();

        echo json_encode([
            'success' => true,
            'message' => $duplicateMatches
                ? 'Pet added successfully. A possible duplicate profile was sent to DCVS staff for review.'
                : 'Pet added successfully!',
            'pet_id' => $pet_id,
            'duplicate_review' => !empty($duplicateMatches),
        ]);
    } else {
        $insertError = $stmt->error;
        $stmt->close();
        $conn->rollback();
        error_log('Legacy pet insert failed: ' . $insertError);
        echo json_encode(['success' => false, 'message' => 'Failed to add pet. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
