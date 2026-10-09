<?php
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (($_POST['staff_action'] ?? '') !== '') {
    session_name('DCVS_STAFF_SESSION');
}
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/adoption-workflow.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function adoptionHandlerRespond($success, $message, $status = 200, $extra = [])
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

function adoptionHandlerDateTime($value)
{
    $value = adoptionInputText($value);
    if ($value === '') {
        return '';
    }
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
        throw new AdoptionWorkflowException('Enter a valid date and time.');
    }
    return $date->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adoptionHandlerRespond(false, 'Invalid request method.', 405);
}

$isStaffAction = !empty($_POST['staff_action']);
$requiredRole = $isStaffAction ? 'staff' : 'resident';
if (!isset($_SESSION['logged_in'], $_SESSION['role'], $_SESSION['user_id'])
    || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== $requiredRole) {
    adoptionHandlerRespond(false, 'Unauthorized access.', 403);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    adoptionHandlerRespond(false, 'Your session token expired. Refresh the page and try again.', 403);
}

try {
    if (!$isStaffAction) {
        $residentId = (int)$_SESSION['user_id'];
        $action = $_POST['action'] ?? '';
        if ($action === 'submit_application') {
            $applicationId = adoptionSubmitApplication(
                $conn,
                $residentId,
                $_POST,
                $_FILES['residency_proof'] ?? null
            );
            adoptionHandlerRespond(true, 'Your application was submitted. The pet is reserved while staff reviews it.', 200, ['application_id' => $applicationId]);
        }

        if ($action === 'respond' || $action === 'withdraw') {
            adoptionResidentAction(
                $conn,
                $residentId,
                adoptionIntegerValue($_POST['application_id'] ?? 0),
                $action,
                adoptionInputText($_POST['response'] ?? null)
            );
            adoptionHandlerRespond(true, $action === 'respond' ? 'Your response was sent to staff.' : 'Your application was withdrawn.');
        }

        adoptionHandlerRespond(false, 'Invalid action.', 400);
    }

    $staffId = (int)$_SESSION['user_id'];
    $action = $_POST['staff_action'];
    if ($action === 'review_application') {
        $decision = $_POST['decision'] ?? '';
        $reason = adoptionInputText($_POST['reason'] ?? null);
        $pickupAt = $decision === 'approved' ? adoptionHandlerDateTime($_POST['pickup_at'] ?? '') : '';
        $interviewAt = adoptionHandlerDateTime($_POST['interview_at'] ?? '');
        $homeVisitAt = adoptionHandlerDateTime($_POST['home_visit_at'] ?? '');
        $homeVisitNotes = adoptionInputText($_POST['home_visit_notes'] ?? null);
        $warnings = adoptionReviewApplication(
            $conn,
            adoptionIntegerValue($_POST['application_id'] ?? 0),
            $staffId,
            $decision,
            $reason,
            $pickupAt,
            $interviewAt,
            $homeVisitAt,
            $homeVisitNotes
        );
        $message = $decision === 'approved'
            ? 'Application approved and the DCVS visit has been scheduled. The pet remains reserved until staff finalizes the adoption.'
            : 'Application review saved.';
        if (!empty($warnings)) {
            $message .= ' ' . implode(' ', $warnings);
        }
        adoptionHandlerRespond(true, $message, 200, ['warnings' => $warnings ?: []]);
    }
    if ($action === 'finalize_adoption') {
        adoptionFinalizeApplication(
            $conn,
            adoptionIntegerValue($_POST['application_id'] ?? 0),
            $staffId
        );
        adoptionHandlerRespond(true, 'Adoption finalized. The pet is now registered to the resident.');
    }
    adoptionHandlerRespond(false, 'Invalid staff action.', 400);
} catch (AdoptionWorkflowException $exception) {
    adoptionHandlerRespond(false, $exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log('Adoption workflow request failed: ' . $exception->getMessage());
    adoptionHandlerRespond(false, 'The adoption request could not be completed. Please try again or contact staff.', 500);
}
