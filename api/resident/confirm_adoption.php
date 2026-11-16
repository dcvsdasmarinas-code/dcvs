<?php
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/adoption-workflow.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function confirmAdoptionRespond($success, $message, $status)
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    confirmAdoptionRespond(false, 'Invalid request method.', 405);
}
if (!isset($_SESSION['logged_in'], $_SESSION['role'], $_SESSION['user_id'])
    || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'resident') {
    confirmAdoptionRespond(false, 'Unauthorized access.', 403);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    confirmAdoptionRespond(false, 'Your session token expired. Refresh the page and try again.', 403);
}

try {
    $applicationId = adoptionIntegerValue($_POST['application_id'] ?? 0);
    if ($applicationId <= 0) {
        throw new AdoptionWorkflowException('Adoption application not found.');
    }
    adoptionConfirmApplication($conn, (int)$_SESSION['user_id'], $applicationId);
    confirmAdoptionRespond(true, 'Your pickup period has started. Please pick up your pet within three days.', 200);
} catch (AdoptionWorkflowException $exception) {
    confirmAdoptionRespond(false, $exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log('Resident adoption confirmation failed: ' . $exception->getMessage());
    confirmAdoptionRespond(false, 'Your confirmation could not be completed. Please try again or contact DCVS staff.', 500);
}
