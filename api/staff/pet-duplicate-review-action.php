<?php
session_name('DCVS_STAFF_SESSION');
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
require_once __DIR__ . '/../../includes/staff-app-base.php';

$appBaseUrl = dcvsStaffAppBaseUrl();
$returnUrl = $appBaseUrl . '/pet-duplicate-reviews.php';

function petDuplicateReviewRedirect(string $message, string $type, string $url): void
{
    $_SESSION['pet_duplicate_review_notice'] = $message;
    $_SESSION['pet_duplicate_review_notice_type'] = $type;
    header('Location: ' . $url);
    exit;
}

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    exit('Unauthorized access.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    petDuplicateReviewRedirect('Your session could not be verified. Refresh the page and try again.', 'error', $returnUrl);
}

$reviewId = filter_var($_POST['review_id'] ?? null, FILTER_VALIDATE_INT);
$action = (string)($_POST['action'] ?? '');
$notes = trim((string)($_POST['notes'] ?? ''));
if (!$reviewId || !in_array($action, ['linked', 'not_duplicate'], true) || strlen($notes) > 1000) {
    petDuplicateReviewRedirect('The review action is invalid. No changes were made.', 'error', $returnUrl);
}

$conn->begin_transaction();
try {
    $reviewStmt = $conn->prepare("SELECT review_id, status FROM pet_duplicate_reviews WHERE review_id = ? FOR UPDATE");
    if (!$reviewStmt) {
        throw new RuntimeException('Could not prepare duplicate review lookup: ' . $conn->error);
    }
    $reviewStmt->bind_param('i', $reviewId);
    if (!$reviewStmt->execute()) {
        $error = $reviewStmt->error;
        $reviewStmt->close();
        throw new RuntimeException('Could not load duplicate review: ' . $error);
    }
    $review = $reviewStmt->get_result()->fetch_assoc();
    $reviewStmt->close();
    if (!$review || $review['status'] !== 'pending') {
        $conn->rollback();
        petDuplicateReviewRedirect('This review was already completed or no longer exists.', 'error', $returnUrl);
    }

    $updateStmt = $conn->prepare("
        UPDATE pet_duplicate_reviews
        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
        WHERE review_id = ? AND status = 'pending'
    ");
    if (!$updateStmt) {
        throw new RuntimeException('Could not prepare duplicate review update: ' . $conn->error);
    }
    $updateStmt->bind_param('sisi', $action, $_SESSION['user_id'], $notes, $reviewId);
    if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
        $error = $updateStmt->error;
        $updateStmt->close();
        throw new RuntimeException('Could not update duplicate review: ' . $error);
    }
    $updateStmt->close();

    $logStmt = $conn->prepare("
        INSERT INTO pet_duplicate_review_log (review_id, action, performed_by, notes)
        VALUES (?, ?, ?, ?)
    ");
    if (!$logStmt) {
        throw new RuntimeException('Could not prepare duplicate review audit record: ' . $conn->error);
    }
    $logStmt->bind_param('isis', $reviewId, $action, $_SESSION['user_id'], $notes);
    if (!$logStmt->execute()) {
        $error = $logStmt->error;
        $logStmt->close();
        throw new RuntimeException('Could not save duplicate review audit record: ' . $error);
    }
    $logStmt->close();

    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit duplicate review decision: ' . $conn->error);
    }
} catch (RuntimeException $exception) {
    $conn->rollback();
    error_log('Pet duplicate review action failed: ' . $exception->getMessage());
    petDuplicateReviewRedirect('The review could not be saved. No duplicate profiles were merged or deleted.', 'error', $returnUrl);
}

$message = $action === 'linked'
    ? 'Profiles linked for reference. No records or histories were merged or deleted.'
    : 'Profiles marked as different animals.';
petDuplicateReviewRedirect($message, 'success', $returnUrl);
