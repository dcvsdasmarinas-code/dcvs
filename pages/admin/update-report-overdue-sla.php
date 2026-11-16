<?php
session_name('DCVS_ADMIN_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'admin'
    || empty($_SESSION['user_id'])) {
    header('Location: login-admin.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard-admin.php#settings');
    exit;
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    $_SESSION['sla_settings_error'] = 'Your session could not be verified. Refresh the page and try again.';
    header('Location: dashboard-admin.php#settings');
    exit;
}

$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
if (!$roleCheck) {
    error_log('Could not prepare admin role verification for SLA settings: ' . $conn->error);
    $_SESSION['sla_settings_error'] = 'Could not verify administrator access.';
    header('Location: dashboard-admin.php#settings');
    exit;
}
$adminId = (int)$_SESSION['user_id'];
$roleCheck->bind_param('i', $adminId);
if (!$roleCheck->execute()) {
    error_log('Could not verify admin role for SLA settings: ' . $roleCheck->error);
    $roleCheck->close();
    $_SESSION['sla_settings_error'] = 'Could not verify administrator access.';
    header('Location: dashboard-admin.php#settings');
    exit;
}
$roleRow = $roleCheck->get_result()->fetch_assoc();
$roleCheck->close();
if (!$roleRow || $roleRow['role'] !== 'admin') {
    $_SESSION['sla_settings_error'] = 'Administrator access is required to change these settings.';
    header('Location: dashboard-admin.php#settings');
    exit;
}

$values = [];
foreach (['high_sla_hours', 'medium_sla_hours', 'low_sla_hours'] as $field) {
    $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1 || $value > 720) {
        $_SESSION['sla_settings_error'] = 'Each SLA must be a whole number between 1 and 720 hours.';
        header('Location: dashboard-admin.php#settings');
        exit;
    }
    $values[$field] = $value;
}

$stmt = $conn->prepare("
    INSERT INTO report_overdue_sla_settings
        (setting_id, high_sla_hours, medium_sla_hours, low_sla_hours, updated_by)
    VALUES (1, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        high_sla_hours = VALUES(high_sla_hours),
        medium_sla_hours = VALUES(medium_sla_hours),
        low_sla_hours = VALUES(low_sla_hours),
        updated_by = VALUES(updated_by)
");
if (!$stmt) {
    error_log('Could not prepare DCVS overdue SLA settings update: ' . $conn->error);
    $_SESSION['sla_settings_error'] = 'Could not save SLA settings. Apply the report overdue SLA migration and try again.';
    header('Location: dashboard-admin.php#settings');
    exit;
}
$stmt->bind_param(
    'iiii',
    $values['high_sla_hours'],
    $values['medium_sla_hours'],
    $values['low_sla_hours'],
    $adminId
);
if (!$stmt->execute()) {
    error_log('Could not save DCVS overdue SLA settings: ' . $stmt->error);
    $stmt->close();
    $_SESSION['sla_settings_error'] = 'Could not save SLA settings. Please try again.';
    header('Location: dashboard-admin.php#settings');
    exit;
}
$stmt->close();

$_SESSION['sla_settings_success'] = 'DCVS overdue SLA settings saved.';
header('Location: dashboard-admin.php#settings');
exit;
