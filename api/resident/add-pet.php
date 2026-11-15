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
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/adoption-workflow.php';
require_once __DIR__ . '/../../includes/pet-duplicates.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? null)) {
        $_SESSION['pet_error'] = 'Your session could not be verified. Refresh the page and try again.';
        header('Location: dashboard-resident.php#my-pets');
        exit;
    }
    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    if (($_POST['from_adoption'] ?? '') === '1') {
        $applicationId = (int)($_POST['adoption_application_id'] ?? 0);
        if ($applicationId > 0) {
            $approvedStmt = $conn->prepare(
                "SELECT resulting_pet_id FROM adoption_applications
                 WHERE application_id = ? AND applicant_id = ? AND status = 'adopted'
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
    $identity = petDuplicateIdentityFromInput($_POST);
    $petName = $identity['pet_name'];
    $species = $identity['pet_type'];
    $breed = $identity['breed'];
    $gender = $identity['gender'];
    $ageValue = $_POST['age'] ?? '';
    $ageInput = is_scalar($ageValue) ? trim((string)$ageValue) : '';
    $age = $ageInput === '' ? 0 : (int)$ageInput;
    $weight = floatval($_POST['weight'] ?? 0);
    $color = $identity['color'];
    $dateOfBirth = $identity['date_of_birth'];
    $microchipId = $identity['microchip_id'];
    $dateOfBirthValue = $dateOfBirth === '' ? null : $dateOfBirth;
    $microchipIdValue = $microchipId === '' ? null : $microchipId;
    $vaccinated = $_POST['vaccinated'] ?? 'no';

    // Server-side validation
    $errors = petDuplicateIdentityErrors($identity, $_POST);
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])
        || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = "A pet photo is required.";
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

    try {
        $duplicateMatches = petDuplicateFindCandidates($conn, (int)$_SESSION['user_id'], $identity);
    } catch (RuntimeException $exception) {
        $conn->rollback();
        error_log('Pet registration duplicate lookup failed: ' . $exception->getMessage());
        $_SESSION['pet_error'] = 'Similar pet profiles could not be checked. Please try again.';
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

    // Save the required resident-uploaded pet photo.
    $photoUrl = '';
    $filepath = null;
    $photo = $_FILES['photo'];
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];
    $maxSize = 5 * 1024 * 1024;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedType = $finfo ? finfo_file($finfo, $photo['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);
    if (!is_uploaded_file($photo['tmp_name']) || !isset($allowedTypes[$detectedType])) {
        $conn->rollback();
        $_SESSION['pet_error'] = "Invalid photo. Please upload a JPEG, PNG, or GIF image.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    if ((int)$photo['size'] > $maxSize) {
        $conn->rollback();
        $_SESSION['pet_error'] = "File size exceeds 5MB limit.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    $uploadDir = __DIR__ . '/../../uploads/pets/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $conn->rollback();
        error_log('Failed to create pet photo upload directory: ' . $uploadDir);
        $_SESSION['pet_error'] = "The pet photo could not be saved. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$detectedType];
    $filepath = $uploadDir . $filename;
    if (!move_uploaded_file($photo['tmp_name'], $filepath)) {
        $conn->rollback();
        error_log('Failed to move uploaded pet photo.');
        $_SESSION['pet_error'] = "The pet photo could not be saved. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $photoUrl = 'uploads/pets/' . $filename;

    // Insert pet into database
    $petRefId = generateReferenceId($conn, '#PET-', 'pets');
    $stmt = $conn->prepare("INSERT INTO pets (owner_id, pet_name, pet_type, breed, age, weight, gender, color, date_of_birth, microchip_id, vaccination_status, photo_url, reference_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        $conn->rollback();
        if ($filepath !== null && is_file($filepath)) {
            unlink($filepath);
        }
        error_log('Could not prepare pet registration insert: ' . $conn->error);
        $_SESSION['pet_error'] = "Failed to register pet. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $stmt->bind_param("isssidsssssss", $_SESSION['user_id'], $petName, $species, $breed, $age, $weight, $gender, $color, $dateOfBirthValue, $microchipIdValue, $vaccinationStatus, $photoUrl, $petRefId);

    if ($stmt->execute()) {
        $petId = $stmt->insert_id;
        $stmt->close();
        try {
            petDuplicateCreateReviewRows($conn, $petId, $duplicateMatches);
            adoptionCommit($conn);
        } catch (RuntimeException $exception) {
            $conn->rollback();
            if ($filepath !== null && is_file($filepath)) {
                unlink($filepath);
            }
            error_log('Pet registration duplicate review save failed: ' . $exception->getMessage());
            $_SESSION['pet_error'] = "Failed to register pet. Please try again.";
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        $_SESSION['pet_success'] = $duplicateMatches
            ? "Pet registered. A possible duplicate profile was sent to DCVS staff for review; no profile was merged or removed."
            : "Pet registered successfully!";

        // Log activity for pet registration
        $logAction = "Pet Registered";
        $logDetails = "Registered pet: " . $petName . " (" . $species . ", " . $gender . ")";
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $logStmt->bind_param("isss", $_SESSION['user_id'], $logAction, $logDetails, $ip);
        $logStmt->execute();
        $logStmt->close();

        header('Location: dashboard-resident.php' . $sec);
        exit;
    } else {
        $insertError = $stmt->error;
        $stmt->close();
        $conn->rollback();
        if ($filepath !== null && is_file($filepath)) {
            unlink($filepath);
        }
        error_log("Failed to insert pet. Error: " . $insertError);
        $_SESSION['pet_error'] = "Failed to register pet. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
}

$conn->close();
?>
