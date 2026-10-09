<?php
/**
 * Add Pet Handler
 * Saves new pet registration to database
 */

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
require_once __DIR__ . '/../includes/adoption-workflow.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    if (($_POST['from_adoption'] ?? '') === '1') {
        $applicationId = (int)($_POST['adoption_application_id'] ?? 0);
        if ($applicationId > 0) {
            $approvedStmt = $conn->prepare(
                "SELECT resulting_pet_id FROM adoption_applications
                 WHERE application_id = ? AND applicant_id = ? AND status = 'approved'
                   AND resulting_pet_id IS NOT NULL"
            );
            $approvedStmt->bind_param('ii', $applicationId, $_SESSION['user_id']);
            $approvedStmt->execute();
            $approved = $approvedStmt->get_result()->fetch_assoc();
            $approvedStmt->close();
            if ($approved) {
                $_SESSION['pet_success'] = 'This approved adoption is already registered in your pet profile.';
                header('Location: dashboard-resident.php#my-pets');
                exit;
            }
        }
        $_SESSION['pet_error'] = 'Adopted pets can only be registered through an approved adoption application.';
        header('Location: dashboard-resident.php#adoption');
        exit;
    }
    error_log("POST request received for add-pet.php");
    error_log("POST data: " . print_r($_POST, true));

    $petName = trim($_POST['pet_name'] ?? '');
    $species = $_POST['species'] ?? '';
    if ($species === 'other' && !empty(trim($_POST['species_custom'] ?? ''))) {
        $species = trim($_POST['species_custom']);
    }
    $breed = trim($_POST['breed'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $age = intval($_POST['age'] ?? 0);
    $weight = floatval($_POST['weight'] ?? 0);
    $color = trim($_POST['color'] ?? '');
    $vaccinated = $_POST['vaccinated'] ?? 'no';

    error_log("Extracted data - Name: $petName, Species: $species, Gender: $gender");
    error_log("User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));

    // Server-side validation
    $errors = [];

    if (empty($petName)) {
        $errors[] = "Pet name is required.";
    }
    if (empty($species)) {
        $errors[] = "Species is required.";
    }
    if (empty($gender)) {
        $errors[] = "Gender is required.";
    }

    if (!empty($errors)) {
        $_SESSION['pet_error'] = implode('<br>', $errors);
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    try {
        adoptionBeginResidentPetRegistration($conn, (int)$_SESSION['user_id']);
    } catch (AdoptionWorkflowException $exception) {
        $_SESSION['pet_error'] = $exception->getMessage();
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    // Build vaccination status text
    $vaccinationStatus = '';
    switch ($vaccinated) {
        case 'yes':
            $vaccinationStatus = 'Fully vaccinated';
            break;
        case 'no':
            $vaccinationStatus = 'Not vaccinated';
            break;
        case 'partial':
            $vaccinationStatus = 'Partially vaccinated';
            break;
    }

    // Handle photo upload (or use photo_url from adoption flow)
    $photoUrl = '';
    if (!empty($_POST['photo_url'])) {
        $photoUrl = $_POST['photo_url'];
    } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo = $_FILES['photo'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($finfo, $photo['tmp_name']);
        finfo_close($finfo);
        if (!in_array($detectedType, $allowedTypes)) {
            $conn->rollback();
            $_SESSION['pet_error'] = "Invalid file type. Please upload a JPEG, PNG, or GIF image.";
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        if ($photo['size'] > $maxSize) {
            $conn->rollback();
            $_SESSION['pet_error'] = "File size exceeds 5MB limit.";
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/../uploads/pets/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($photo['name'], PATHINFO_EXTENSION);
        $filename = uniqid('pet_') . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($photo['tmp_name'], $filepath)) {
            $photoUrl = 'uploads/pets/' . $filename;
            error_log("Photo uploaded successfully: $photoUrl");
        } else {
            error_log("Failed to move uploaded file");
        }
    }

    // Insert pet into database
    error_log("Attempting to insert pet into database");
    error_log("User ID: " . $_SESSION['user_id']);
    $petRefId = generateReferenceId($conn, '#PET-', 'pets');
    $stmt = $conn->prepare("INSERT INTO pets (owner_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssidsssss", $_SESSION['user_id'], $petName, $species, $breed, $age, $weight, $gender, $color, $vaccinationStatus, $photoUrl, $petRefId);

    if ($stmt->execute()) {
        $petId = $stmt->insert_id;
        adoptionCommit($conn);
        error_log("Pet inserted successfully with ID: " . $petId);

        $_SESSION['pet_success'] = "Pet registered successfully!";

        // Log activity for pet registration
        $logAction = "Pet Registered";
        $logDetails = "Registered pet: " . $petName . " (" . $species . ", " . $gender . ")";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $logStmt->bind_param("isss", $_SESSION['user_id'], $logAction, $logDetails, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['pet_success'] = "Pet registered successfully!";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    } else {
        $conn->rollback();
        error_log("Failed to insert pet. Error: " . $stmt->error);
        $_SESSION['pet_error'] = "Failed to register pet. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
