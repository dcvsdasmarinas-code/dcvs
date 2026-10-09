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
require_once 'db.php';
require_once 'includes/adoption-workflow.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'resident') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['from_adoption'] ?? '') === '1') {
        echo json_encode(['success' => false, 'message' => 'Adopted pets can only be registered through an approved adoption application.']);
        exit;
    }
    $owner_id = $_SESSION['user_id'];
    $pet_name = $_POST['pet_name'] ?? '';
    $pet_type = $_POST['species'] ?? 'dog';
    $breed = $_POST['breed'] ?? '';
    $age = $_POST['age'] ?? 0;
    $gender = $_POST['gender'] ?? 'male';
    $color = $_POST['color'] ?? '';
    $vaccinated = $_POST['vaccinated'] ?? 'no';

    if (empty($pet_name)) {
        echo json_encode(['success' => false, 'message' => 'Pet name is required.']);
        exit;
    }

    try {
        adoptionBeginResidentPetRegistration($conn, (int)$owner_id);
    } catch (AdoptionWorkflowException $exception) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
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

        $uploadDir = 'uploads/pets/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $filename = uniqid('pet_') . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
            $photoUrl = $filepath;
        }
    }

    $refId = generateReferenceId($conn, '#PET-', 'pets');
    $stmt = $conn->prepare("INSERT INTO pets (owner_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssidsssss", $owner_id, $pet_name, $pet_type, $breed, $age, $weight, $gender, $color, $vaccinationStatus, $photoUrl, $refId);
    
    if ($stmt->execute()) {
        $pet_id = $conn->insert_id;
        adoptionCommit($conn);

        // Log activity
        $action = "Pet Registered";
        $details = "Registered a new pet: " . $pet_name . " (" . $pet_type . ", " . $gender . ")";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $logStmt->bind_param("iss", $owner_id, $action, $details);
        $logStmt->execute();

        echo json_encode(['success' => true, 'message' => 'Pet added successfully!', 'pet_id' => $pet_id]);
    } else {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to add pet. Please try again.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
