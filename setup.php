<?php
/**
 * DCVS Setup Script
 * Run ONCE after importing dcvs_database_schema.sql
 * Visit: http://localhost/dcvs/setup.php
 */
require_once 'db.php';

$adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
$staffPassword = password_hash('staff123', PASSWORD_DEFAULT);
$barangayPassword = password_hash('barangay123', PASSWORD_DEFAULT);
$residentPassword = password_hash('resident123', PASSWORD_DEFAULT);

$conn->query("UPDATE users SET password = '$adminPassword', password_updated_at = NOW() WHERE email = 'admin@dcvs.gov'");
$conn->query("UPDATE users SET password = '$staffPassword', password_updated_at = NOW() WHERE email = 'staff@dcvs.gov'");

echo "<h2>DCVS Setup Complete</h2>";
echo "<p>Sample account passwords have been hashed.</p>";
echo "<ul>";
echo "<li><strong>Admin:</strong> admin@dcvs.gov / admin123</li>";
echo "<li><strong>Staff:</strong> staff@dcvs.gov / staff123</li>";
echo "</ul>";
echo "<p><a href='login.php'>Go to Login</a></p>";

$conn->close();
