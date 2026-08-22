<?php
session_start();

echo "<h1>Session Check After Login</h1>";

echo "<h2>Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Session Details:</h2>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session Status: " . session_status() . "</p>";

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    echo "<p style='color: green;'><strong>User is logged in!</strong></p>";
    echo "<p>Full Name: " . ($_SESSION['full_name'] ?? 'NOT SET') . "</p>";
    echo "<p>Email: " . ($_SESSION['email'] ?? 'NOT SET') . "</p>";
    echo "<p>Phone: " . ($_SESSION['phone'] ?? 'NOT SET') . "</p>";
    echo "<p>Role: " . ($_SESSION['role'] ?? 'NOT SET') . "</p>";
    
    echo "<p><a href='dashboard-resident.php'>Continue to Dashboard</a></p>";
} else {
    echo "<p style='color: red;'><strong>User is NOT logged in!</strong></p>";
    echo "<p><a href='login.php'>Go to Login</a></p>";
}
?>
