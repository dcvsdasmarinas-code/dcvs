<?php
/**
 * Archive Pet Handler
 * Archives (soft deletes) a pet registration
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $petId = intval($_POST['pet_id'] ?? $_GET['id'] ?? 0);

    if ($petId === 0) {
        $_SESSION['pet_error'] = "Invalid pet ID.";
        header('Location: dashboard-resident.php');
        exit;
    }

    // Check if pet belongs to user
    $checkPet = $conn->prepare("SELECT pet_id FROM pets WHERE pet_id = ? AND owner_id = ?");
    $checkPet->bind_param("ii", $petId, $_SESSION['user_id']);
    $checkPet->execute();
    $checkPet->store_result();

    if ($checkPet->num_rows === 0) {
        $_SESSION['pet_error'] = "Invalid pet selected.";
        header('Location: dashboard-resident.php');
        exit;
    }
    $checkPet->close();

    // Archive pet (soft delete - add archived_at timestamp)
    $stmt = $conn->prepare("UPDATE pets SET archived_at = NOW() WHERE pet_id = ? AND owner_id = ?");
    if (!$stmt) {
        error_log("Prepare failed for archive pet: " . $conn->error);
        $_SESSION['pet_error'] = "Database error. Please try again.";
        header('Location: dashboard-resident.php');
        exit;
    }
    $stmt->bind_param("ii", $petId, $_SESSION['user_id']);

    if ($stmt->execute()) {
        error_log("Pet archived successfully. Pet ID: $petId");
        $_SESSION['pet_success'] = "Pet archived successfully!";
        header('Location: dashboard-resident.php');
        exit;
    } else {
        error_log("Failed to archive pet. Error: " . $stmt->error);
        $_SESSION['pet_error'] = "Failed to archive pet. Please try again.";
        header('Location: dashboard-resident.php');
        exit;
    }

    $stmt->close();
}

$conn->close();
?>
