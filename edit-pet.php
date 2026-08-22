<?php
/**
 * Edit Pet Handler
 * Updates existing pet registration
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
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    $petId = intval($_POST['pet_id'] ?? 0);
    $petName = trim($_POST['pet_name'] ?? '');
    $species = $_POST['species'] ?? '';
    $breed = trim($_POST['breed'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $age = intval($_POST['age'] ?? 0);
    $weight = floatval($_POST['weight'] ?? 0);
    $color = trim($_POST['color'] ?? '');
    $vaccinated = $_POST['vaccinated'] ?? 'no';

    // Server-side validation
    $errors = [];

    if ($petId === 0) {
        $errors[] = "Invalid pet ID.";
    }
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

    // Check if pet belongs to user
    $checkPet = $conn->prepare("SELECT pet_id FROM pets WHERE pet_id = ? AND owner_id = ?");
    $checkPet->bind_param("ii", $petId, $_SESSION['user_id']);
    $checkPet->execute();
    $checkPet->store_result();

    if ($checkPet->num_rows === 0) {
        $_SESSION['pet_error'] = "Invalid pet selected.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $checkPet->close();

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

    // Handle photo upload
    $photoUrl = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo = $_FILES['photo'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($finfo, $photo['tmp_name']);
        finfo_close($finfo);
        $ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));

        if (!in_array($detectedType, $allowedTypes) || !in_array($ext, $allowedExts)) {
            $_SESSION['pet_error'] = "Invalid file type. Please upload a JPEG, PNG, GIF, or WebP image.";
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        if ($photo['size'] > $maxSize) {
            $_SESSION['pet_error'] = "File size exceeds 5MB limit.";
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }

        // Create upload directory if it doesn't exist
        $uploadDir = 'uploads/pets/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($photo['name'], PATHINFO_EXTENSION);
        $filename = uniqid('pet_') . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($photo['tmp_name'], $filepath)) {
            $photoUrl = $filepath;
            error_log("Photo uploaded successfully: $photoUrl");
        } else {
            error_log("Failed to move uploaded file");
        }
    }

    // Update pet in database
    if (!empty($photoUrl)) {
        // Update with new photo
        $stmt = $conn->prepare("UPDATE pets SET pet_name = ?, pet_type = ?, breed = ?, age = ?, weight = ?, gender = ?, color = ?, vaccination_status = ?, photo_url = ? WHERE pet_id = ? AND owner_id = ?");
        if (!$stmt) {
            error_log("edit-pet: prepare (with photo) failed: " . $conn->error);
            $_SESSION['pet_error'] = "Database error: " . $conn->error;
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }
        $stmt->bind_param("sssidssssii", $petName, $species, $breed, $age, $weight, $gender, $color, $vaccinationStatus, $photoUrl, $petId, $_SESSION['user_id']);
    } else {
        // Update without changing photo
        $stmt = $conn->prepare("UPDATE pets SET pet_name = ?, pet_type = ?, breed = ?, age = ?, weight = ?, gender = ?, color = ?, vaccination_status = ? WHERE pet_id = ? AND owner_id = ?");
        if (!$stmt) {
            error_log("edit-pet: prepare (no photo) failed: " . $conn->error);
            $_SESSION['pet_error'] = "Database error: " . $conn->error;
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }
        $stmt->bind_param("sssidsssii", $petName, $species, $breed, $age, $weight, $gender, $color, $vaccinationStatus, $petId, $_SESSION['user_id']);
    }

    if ($stmt->execute()) {
        $_SESSION['pet_success'] = "Pet updated successfully!";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    } else {
        $_SESSION['pet_error'] = "Failed to update pet: " . $stmt->error;
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
