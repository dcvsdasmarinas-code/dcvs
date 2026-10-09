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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    echo "Unauthorized";
    exit;
}

$pet_id = $_GET['pet_id'] ?? null;
if (!$pet_id) {
    echo "No pet specified.";
    exit;
}

$stmt = $conn->prepare("
    SELECT appointment_type, preferred_date, status, notes 
    FROM appointments 
    WHERE pet_id = ? AND status = 'completed'
    ORDER BY preferred_date DESC
");
$stmt->bind_param("i", $pet_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<p style='text-align:center;color:#6b7280;padding:1rem;'>No completed medical history found for this pet.</p>";
} else {
    echo "<ul style='list-style:none; padding:0; margin:0;'>";
    while ($row = $result->fetch_assoc()) {
        $date = date('M d, Y', strtotime($row['preferred_date']));
        $type = ucfirst($row['appointment_type']);
        $notes = htmlspecialchars($row['notes'] ?: 'No notes recorded.');
        
        echo "<li style='border-bottom:1px solid #e5e7eb; padding:1rem 0;'>";
        echo "<div style='display:flex; justify-content:space-between; margin-bottom:0.5rem;'>";
        echo "<strong>$type</strong>";
        echo "<span style='font-size:0.85rem; color:#6b7280;'><i class='fas fa-calendar'></i> $date</span>";
        echo "</div>";
        echo "<p style='margin:0; font-size:0.95rem; color:#374151;'>$notes</p>";
        echo "</li>";
    }
    echo "</ul>";
}
$stmt->close();
