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
$conn->close();

if (!$pet) {
    header('Location: petlist.php');
    exit;
}

$data = [
    'name' => $pet['name'],
    'species' => $pet['species'],
    'breed' => $pet['breed'],
    'age' => $pet['age'],
    'gender' => $pet['gender'],
    'weight' => $pet['weight'],
    'color' => $pet['color'],
    'photo' => $pet['photo_url'] ?? '',
];

$_SESSION['adopt_data'] = $data;
$_SESSION['adopt_listing_id'] = $listingId;
$_SESSION['show_adopt_modal'] = true;

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

header('Location: dashboard-resident.php');
exit;
