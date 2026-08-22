<?php
session_start();

echo "<h1>Session Test</h1>";

if (!isset($_SESSION['test'])) {
    $_SESSION['test'] = 'Session is working!';
    echo "<p>Session set: " . $_SESSION['test'] . "</p>";
    echo "<p><a href='test-session.php'>Refresh page</a> to see if session persists.</p>";
} else {
    echo "<p>Session persisted: " . $_SESSION['test'] . "</p>";
    echo "<p><strong>Session is working!</strong></p>";
}

echo "<h2>Session Info:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>PHP Session Configuration:</h2>";
echo "<pre>";
echo "Session save path: " . session_save_path() . "<br>";
echo "Session ID: " . session_id() . "<br>";
echo "Session name: " . session_name() . "<br>";
echo "Session status: " . session_status() . "<br>";
echo "</pre>";

echo "<p><a href='login.php'>Go to Login</a></p>";
?>
