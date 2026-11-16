<?php
session_name('DCVS_BARANGAY_SESSION');
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
header('Content-Type: application/json; charset=utf-8');

function eventResponseJson(bool $success, string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eventResponseJson(false, 'Method not allowed.', 405);
}
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'barangay') {
    eventResponseJson(false, 'Unauthorized.', 403);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    eventResponseJson(false, 'Your session token expired. Refresh the page and try again.', 403);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$announcementId = (int)($_POST['announcement_id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$note = trim((string)($_POST['response_note'] ?? ''));
$readyInput = $_POST['ready_requirements'] ?? [];
if ($userId <= 0 || $barangayId <= 0 || $announcementId <= 0) {
    eventResponseJson(false, 'Invalid event response.', 400);
}
if (!in_array($decision, ['confirmed', 'changes_requested'], true)) {
    eventResponseJson(false, 'Choose a valid response.', 400);
}
if ($decision === 'changes_requested' && $note === '') {
    eventResponseJson(false, 'Add a note describing the requested changes.', 400);
}
if (strlen($note) > 4000) {
    eventResponseJson(false, 'The response note must be 4,000 characters or fewer.', 400);
}
if (!is_array($readyInput)) {
    eventResponseJson(false, 'The preparedness checklist is invalid.', 400);
}
$readyIds = array_values(array_unique(array_filter(array_map('intval', $readyInput), static function (int $id): bool {
    return $id > 0;
})));

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "SELECT a.status, a.is_active, a.archived_at, a.expiry_date, a.publish_at,
                a.response_deadline
         FROM announcement_event_responses r
         JOIN announcements a ON a.announcement_id = r.announcement_id
         WHERE r.announcement_id = ? AND r.barangay_id = ?
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event response authorization query: ' . $conn->error);
    }
    $stmt->bind_param('ii', $announcementId, $barangayId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not verify event response access: ' . $stmt->error);
    }
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$event) {
        $conn->rollback();
        eventResponseJson(false, 'This event is not assigned to your barangay.', 404);
    }
    $published = $event['status'] === 'published'
        || ($event['status'] === 'scheduled' && $event['publish_at'] && $event['publish_at'] <= date('Y-m-d H:i:s'));
    if (!(int)$event['is_active'] || $event['archived_at'] !== null || !$published
        || ($event['expiry_date'] !== null && $event['expiry_date'] < date('Y-m-d'))
        || ($event['response_deadline'] !== null && $event['response_deadline'] < date('Y-m-d H:i:s'))) {
        $conn->rollback();
        eventResponseJson(false, 'The response deadline has passed or this event is no longer accepting responses.', 409);
    }

    $stmt = $conn->prepare('SELECT requirement_id, is_required FROM announcement_event_requirements WHERE announcement_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event requirement lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $announcementId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load event requirements: ' . $stmt->error);
    }
    $requirements = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requirements[(int)$row['requirement_id']] = (int)$row['is_required'] === 1;
    }
    $stmt->close();
    if (!$requirements) {
        throw new RuntimeException('The event has no preparedness requirements.');
    }
    foreach ($readyIds as $readyId) {
        if (!isset($requirements[$readyId])) {
            $conn->rollback();
            eventResponseJson(false, 'The checklist changed. Refresh the event and try again.', 409);
        }
    }
    if ($decision === 'confirmed') {
        foreach ($requirements as $requirementId => $isRequired) {
            if ($isRequired && !in_array($requirementId, $readyIds, true)) {
                $conn->rollback();
                eventResponseJson(false, 'Complete every required preparedness item before confirming.', 400);
            }
        }
    }

    $stmt = $conn->prepare('UPDATE announcement_event_responses SET status = ?, response_note = ?, responded_by = ?, responded_at = NOW() WHERE announcement_id = ? AND barangay_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event response save: ' . $conn->error);
    }
    $stmt->bind_param('ssiii', $decision, $note, $userId, $announcementId, $barangayId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not save barangay event response: ' . $stmt->error);
    }
    $stmt->close();

    $stmt = $conn->prepare('DELETE FROM announcement_event_requirement_responses WHERE announcement_id = ? AND barangay_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event checklist update: ' . $conn->error);
    }
    $stmt->bind_param('ii', $announcementId, $barangayId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not update event checklist: ' . $stmt->error);
    }
    $stmt->close();

    $stmt = $conn->prepare('INSERT INTO announcement_event_requirement_responses (announcement_id, barangay_id, requirement_id, is_ready) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event checklist save: ' . $conn->error);
    }
    foreach ($requirements as $requirementId => $isRequired) {
        $isReady = in_array($requirementId, $readyIds, true) ? 1 : 0;
        $stmt->bind_param('iiii', $announcementId, $barangayId, $requirementId, $isReady);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save an event checklist item: ' . $stmt->error);
        }
    }
    $stmt->close();

    $conn->commit();
    eventResponseJson(true, $decision === 'confirmed' ? 'Event preparedness confirmed.' : 'Change request sent to DCVS.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Barangay event response failed: ' . $exception->getMessage());
    eventResponseJson(false, 'Could not save the event response. Please try again.', 500);
}
