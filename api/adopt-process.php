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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/adoption-workflow.php';

adoptionReleaseExpiredReservations($conn);

$listingId = (int)($_GET['listing_id'] ?? 0);

if ($listingId <= 0) {
    header('Location: petlist.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM pet_listings WHERE listing_id = ? AND status = 'available'");
$stmt->bind_param("i", $listingId);
$stmt->execute();
$pet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pet) {
   header('Location: petlist.php');
   exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
   $_SESSION['adoption_apply_listing_id'] = $listingId;
   header('Location: login.php');
   exit;
}

if (($_SESSION['role'] ?? '') !== 'resident') {
   header('Location: login.php');
   exit;
}

header('Location: resident-adoption-applications.php?apply=' . $listingId);
exit;
