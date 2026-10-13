<?php
session_name('DCVS_STAFF_SESSION');
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
require_once __DIR__ . '/../../includes/announcement-event-notifications.php';
require_once __DIR__ . '/../../includes/facebook-announcements.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

if ($action === 'save' && !validateCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

switch ($action) {
    case 'save':
        $response = handleSave($conn, $_SESSION['user_id']);
        break;
    case 'delete':
        $response = handleDelete($conn);
        break;
    case 'toggle':
        $response = handleToggle($conn);
        break;
    case 'status':
        $response = handleStatus($conn);
        break;
    case 'duplicate':
        $response = handleDuplicate($conn, $_SESSION['user_id']);
        break;
    case 'get':
        $response = handleGet($conn);
        break;
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();

function uploadPhoto($file, $prefix) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) return null;
    $filename = $prefix . '_' . uniqid() . '.' . $ext;
    $dest = __DIR__ . '/../../uploads/announcements/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/announcements/' . $filename;
    }
    return null;
}

function validateFields($p) {
    $category = $p['category'] ?? 'announcement';
    if (!in_array($category, ['advisory', 'announcement'])) $category = 'announcement';

    $subjects = ['animal_health', 'services_programs', 'events', 'general_notices'];
    $subject = $p['subject'] ?? 'general_notices';
    if (!in_array($subject, $subjects)) $subject = 'general_notices';

    $status = $p['status'] ?? 'draft';
    if (!in_array($status, ['draft', 'scheduled', 'published', 'archived'])) $status = 'draft';

    $audienceTypes = ['all', 'barangay', 'selected'];
    $audienceType = $p['audience_type'] ?? 'all';
    if (!in_array($audienceType, $audienceTypes)) $audienceType = 'all';

    $audienceBarangays = trim($p['audience_barangays'] ?? '');
    $barangayId = null;
    if ($audienceType === 'barangay') {
        $barangayId = (int)($p['barangay_id'] ?? 0);
        if ($barangayId <= 0) $barangayId = null;
    }
    if ($audienceType === 'selected') {
        $ids = array_filter(array_map('intval', explode(',', $audienceBarangays)));
        $audienceBarangays = implode(',', $ids);
    }
    if ($audienceType === 'all') {
        $audienceBarangays = '';
    }

    $publishAt = null;
    $publishRaw = trim($p['publish_at'] ?? '');
    if ($publishRaw !== '') {
        $ts = strtotime($publishRaw);
        if ($ts) $publishAt = date('Y-m-d H:i:s', $ts);
    }

    if ($status === 'scheduled' && !$publishAt) {
        $publishAt = date('Y-m-d H:i:s', time() + 3600);
    }

    $featured = !empty($p['is_featured']) ? 1 : 0;

    return [$category, $subject, $status, $audienceType, $audienceBarangays, $barangayId, $publishAt, $featured];
}

function announcementEventInput(mysqli $conn, array $input, int $announcementId = 0): array
{
    $eventDate = trim((string)($input['event_date'] ?? ''));
    $eventEndDate = trim((string)($input['end_date'] ?? ''));
    $startTime = trim((string)($input['event_start_time'] ?? ''));
    $endTime = trim((string)($input['event_end_time'] ?? ''));
    $venueProvider = (string)($input['venue_provider'] ?? '');
    $venueName = trim((string)($input['venue_name'] ?? ''));
    $deadlineInput = trim((string)($input['response_deadline'] ?? ''));
    $targetMode = (string)($input['event_audience'] ?? '');
    $programIdRaw = trim((string)($input['program_id'] ?? ''));
    $programId = $programIdRaw === '' ? 0 : filter_var($programIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $allowedProviders = ['dcvs_office', 'barangay_venue', 'other'];

    if ($programId === false) {
        throw new InvalidArgumentException('The linked program is invalid.');
    }
    $missingFields = [];
    if ($eventDate === '') $missingFields[] = 'event date';
    if ($startTime === '' || $endTime === '') $missingFields[] = 'operating hours (start and end time)';
    if (!in_array($venueProvider, $allowedProviders, true)) $missingFields[] = 'venue provider';
    if ($venueName === '') $missingFields[] = 'venue name';
    if ($deadlineInput === '') $missingFields[] = 'response deadline';
    if (!in_array($targetMode, ['all', 'selected'], true)) {
        $missingFields[] = 'barangay audience';
    } elseif ($targetMode === 'selected') {
        $rawTargets = $input['event_targets'] ?? [];
        if (!is_array($rawTargets) || !array_filter($rawTargets, static function ($target): bool {
            return (int)$target > 0;
        })) {
            $missingFields[] = 'at least one target barangay';
        }
    }
    if (in_array($venueProvider, ['barangay_venue', 'other'], true)) {
        $requirementsInput = $input['event_requirements'] ?? [];
        $hasRequirementLabel = is_array($requirementsInput) && array_filter($requirementsInput, static function ($requirement): bool {
            return is_array($requirement) && trim((string)($requirement['label'] ?? '')) !== '';
        });
        if (!$hasRequirementLabel) $missingFields[] = 'at least one preparedness requirement';
    }
    if ($missingFields) {
        throw new InvalidArgumentException('Complete barangay coordination. Missing: ' . implode(', ', $missingFields) . '.');
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
    $parsedEndDate = $eventEndDate === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $eventEndDate);
    $parsedStart = DateTimeImmutable::createFromFormat('!H:i', $startTime);
    $parsedEnd = DateTimeImmutable::createFromFormat('!H:i', $endTime);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $eventDate
        || ($eventEndDate !== '' && (!$parsedEndDate || $parsedEndDate->format('Y-m-d') !== $eventEndDate))
        || !$parsedStart || $parsedStart->format('H:i') !== $startTime
        || !$parsedEnd || $parsedEnd->format('H:i') !== $endTime) {
        throw new InvalidArgumentException('Enter a valid event date and start/end time.');
    }
    if ($eventDate < date('Y-m-d') || ($eventEndDate !== '' && $eventEndDate < $eventDate) || $startTime >= $endTime) {
        throw new InvalidArgumentException('The event date must be today or later, and the end time must be after the start time.');
    }
    if (!in_array($targetMode, ['all', 'selected'], true)) {
        throw new InvalidArgumentException('Choose all barangays or selected barangays.');
    }

    $deadlineTimestamp = strtotime($deadlineInput);
    $eventStartTimestamp = strtotime($eventDate . ' ' . $startTime);
    if (!$deadlineTimestamp || $deadlineTimestamp <= time() || !$eventStartTimestamp || $deadlineTimestamp > $eventStartTimestamp) {
        throw new InvalidArgumentException('Set a response deadline in the future and no later than the event start time.');
    }

    if ($programId > 0) {
        $programStmt = $conn->prepare("SELECT status FROM programs WHERE program_id = ?");
        if (!$programStmt) {
            throw new RuntimeException('Could not prepare linked program validation: ' . $conn->error);
        }
        $programStmt->bind_param('i', $programId);
        if (!$programStmt->execute()) {
            $error = $programStmt->error;
            $programStmt->close();
            throw new RuntimeException('Could not validate linked program: ' . $error);
        }
        $programRow = $programStmt->get_result()->fetch_assoc();
        $programStmt->close();
        if (!$programRow || !in_array($programRow['status'], ['scheduled', 'live'], true)) {
            throw new InvalidArgumentException('Only scheduled or ongoing programs can be linked to an announcement.');
        }

        $duplicateStmt = $conn->prepare('SELECT announcement_id FROM announcements WHERE program_id = ? AND announcement_id <> ? LIMIT 1');
        if (!$duplicateStmt) {
            throw new RuntimeException('Could not prepare duplicate program announcement check: ' . $conn->error);
        }
        $duplicateStmt->bind_param('ii', $programId, $announcementId);
        if (!$duplicateStmt->execute()) {
            $error = $duplicateStmt->error;
            $duplicateStmt->close();
            throw new RuntimeException('Could not check for an existing program announcement: ' . $error);
        }
        $duplicate = $duplicateStmt->get_result()->fetch_assoc();
        $duplicateStmt->close();
        if ($duplicate) {
            throw new InvalidArgumentException('This program already has an announcement. Open the existing announcement instead.');
        }
    }

    $barangayRows = [];
    if ($targetMode === 'all') {
        $result = $conn->query('SELECT barangay_id, barangay_name FROM barangays WHERE psgc_mapping_approved = 1 AND psgc_code IS NOT NULL ORDER BY barangay_id');
        if (!$result) {
            throw new RuntimeException('Could not load approved barangays: ' . $conn->error);
        }
        while ($row = $result->fetch_assoc()) {
            $barangayRows[(int)$row['barangay_id']] = $row['barangay_name'];
        }
        $result->free();
    } else {
        $rawIds = $input['event_targets'] ?? [];
        if (!is_array($rawIds)) {
            throw new InvalidArgumentException('Select at least one target barangay.');
        }
        $targetIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$targetIds) {
            throw new InvalidArgumentException('Select at least one target barangay.');
        }

        $result = $conn->query('SELECT barangay_id, barangay_name FROM barangays WHERE psgc_mapping_approved = 1 AND psgc_code IS NOT NULL');
        if (!$result) {
            throw new RuntimeException('Could not validate approved target barangays: ' . $conn->error);
        }
        $validBarangays = [];
        while ($row = $result->fetch_assoc()) {
            $validBarangays[(int)$row['barangay_id']] = $row['barangay_name'];
        }
        $result->free();
        foreach ($targetIds as $targetId) {
            if (!isset($validBarangays[$targetId])) {
                throw new InvalidArgumentException('One or more selected barangays are invalid. Refresh and try again.');
            }
            $barangayRows[$targetId] = $validBarangays[$targetId];
        }
        ksort($barangayRows);
    }

    $requirementsInput = $input['event_requirements'] ?? [];
    if (!is_array($requirementsInput)) {
        throw new InvalidArgumentException('The event preparedness requirements are invalid.');
    }
    $requirements = [];
    foreach ($requirementsInput as $index => $requirementInput) {
        if (!is_array($requirementInput)) {
            continue;
        }
        $label = trim((string)($requirementInput['label'] ?? ''));
        $quantityRaw = trim((string)($requirementInput['quantity'] ?? ''));
        if ($label === '' && $quantityRaw === '') {
            continue;
        }
        $labelLength = function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label);
        if ($label === '' || $labelLength > 200) {
            throw new InvalidArgumentException('Each preparedness item needs a label of no more than 200 characters.');
        }
        if ($quantityRaw !== '' && (!ctype_digit($quantityRaw) || (int)$quantityRaw <= 0)) {
            throw new InvalidArgumentException('Requirement quantities must be positive whole numbers.');
        }
        $requirements[] = [
            'id' => max(0, (int)($requirementInput['id'] ?? 0)),
            'label' => $label,
            'quantity' => $quantityRaw === '' ? null : (int)$quantityRaw,
            'is_required' => !empty($requirementInput['required']) ? 1 : 0,
            'sort_order' => count($requirements)
        ];
    }
    if (!$requirements && $venueProvider !== 'dcvs_office') {
        throw new InvalidArgumentException('Add at least one preparedness requirement.');
    }

    $targetNames = array_values($barangayRows);
    return [
        'event_date' => $eventDate,
        'event_end_date' => $eventEndDate === '' ? null : $eventEndDate,
        'event_start_time' => $startTime . ':00',
        'event_end_time' => $endTime . ':00',
        'venue_provider' => $venueProvider,
        'venue_name' => $venueName,
        'response_deadline' => date('Y-m-d H:i:s', $deadlineTimestamp),
        'program_id' => $programId > 0 ? $programId : null,
        'target_mode' => $targetMode,
        'targets' => array_keys($barangayRows),
        'target_names' => $targetNames,
        'requirements' => $requirements
    ];
}

function announcementHasCoordinationInput(array $input): bool
{
    foreach (['event_date', 'end_date', 'event_start_time', 'event_end_time', 'venue_provider', 'venue_name', 'response_deadline', 'program_id'] as $field) {
        $value = $input[$field] ?? '';
        if (is_scalar($value) && trim((string)$value) !== '') {
            return true;
        }
    }
    if (($input['event_audience'] ?? '') === 'all') {
        return true;
    }
    if (!empty($input['event_targets']) && is_array($input['event_targets'])) {
        return true;
    }
    $requirements = $input['event_requirements'] ?? [];
    if (!is_array($requirements)) {
        return true;
    }
    foreach ($requirements as $requirement) {
        if (is_array($requirement)
            && ((is_scalar($requirement['label'] ?? '') && trim((string)($requirement['label'] ?? '')) !== '')
                || (is_scalar($requirement['quantity'] ?? '') && trim((string)($requirement['quantity'] ?? '')) !== ''))) {
            return true;
        }
    }
    return false;
}

function announcementMissingRequiredColumn(mysqli $conn, bool $hasCoordination): ?array
{
    $requiredColumns = [
        'announcements' => [
            'title', 'location', 'content', 'category', 'subject', 'link_url', 'barangay_id',
            'program_id', 'status', 'publish_at', 'archived_at', 'audience_type', 'audience_barangays',
            'is_featured', 'photo_url', 'photo_hover_url', 'is_event', 'event_date', 'event_end_date',
            'event_start_time', 'event_end_time', 'venue_provider', 'venue_name', 'response_deadline',
            'facebook_posting_mode', 'facebook_opt_out', 'created_by', 'created_at', 'updated_at'
        ]
    ];
    if ($hasCoordination) {
        $requiredColumns += [
            'announcement_event_barangays' => ['announcement_id', 'barangay_id'],
            'announcement_event_requirements' => ['requirement_id', 'announcement_id', 'requirement_label', 'quantity', 'is_required', 'sort_order'],
            'announcement_event_responses' => ['announcement_id', 'barangay_id', 'status', 'response_note', 'responded_by', 'responded_at'],
            'announcement_event_requirement_responses' => ['announcement_id', 'barangay_id', 'requirement_id', 'is_ready']
        ];
    }

    $check = $conn->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$check) {
        throw new RuntimeException('Could not check required announcement columns: ' . $conn->error);
    }
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $check->bind_param('ss', $table, $column);
            if (!$check->execute()) {
                $error = $check->error;
                $check->close();
                throw new RuntimeException('Could not check required announcement columns: ' . $error);
            }
            if (!$check->get_result()->fetch_row()) {
                $check->close();
                return ['table' => $table, 'column' => $column];
            }
        }
    }
    $check->close();
    return null;
}

function syncAnnouncementEvent(mysqli $conn, int $announcementId, array $event): void
{
    $targetIds = $event['targets'];
    $existingTargetIds = [];
    $stmt = $conn->prepare('SELECT barangay_id FROM announcement_event_barangays WHERE announcement_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event target lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $announcementId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load existing event targets: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existingTargetIds[] = (int)$row['barangay_id'];
    }
    $stmt->close();

    $existingRequirements = [];
    $stmt = $conn->prepare('SELECT requirement_id, requirement_label, quantity, is_required FROM announcement_event_requirements WHERE announcement_id = ? ORDER BY sort_order, requirement_id');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event requirements lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $announcementId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not load existing event requirements: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existingRequirements[] = [
            'id' => (int)$row['requirement_id'],
            'label' => (string)$row['requirement_label'],
            'quantity' => $row['quantity'] === null ? null : (int)$row['quantity'],
            'is_required' => (int)$row['is_required']
        ];
    }
    $stmt->close();

    $requirementsChanged = count($existingRequirements) !== count($event['requirements']);
    if (!$requirementsChanged) {
        foreach ($existingRequirements as $index => $oldRequirement) {
            $newRequirement = $event['requirements'][$index];
            if ($oldRequirement['id'] !== $newRequirement['id']
                || $oldRequirement['label'] !== $newRequirement['label']
                || $oldRequirement['quantity'] !== $newRequirement['quantity']
                || $oldRequirement['is_required'] !== $newRequirement['is_required']) {
                $requirementsChanged = true;
                break;
            }
        }
    }

    $stmt = $conn->prepare('DELETE FROM announcement_event_barangays WHERE announcement_id = ? AND barangay_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event target removal: ' . $conn->error);
    }
    foreach (array_diff($existingTargetIds, $targetIds) as $removedId) {
        $removedId = (int)$removedId;
        $stmt->bind_param('ii', $announcementId, $removedId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not remove a deselected event target: ' . $stmt->error);
        }
    }
    $stmt->close();

    $stmt = $conn->prepare('INSERT IGNORE INTO announcement_event_barangays (announcement_id, barangay_id) VALUES (?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare event target save: ' . $conn->error);
    }
    foreach ($targetIds as $targetId) {
        $targetId = (int)$targetId;
        $stmt->bind_param('ii', $announcementId, $targetId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save event target: ' . $stmt->error);
        }
    }
    $stmt->close();

    if ($requirementsChanged) {
        $stmt = $conn->prepare("UPDATE announcement_event_responses SET status = 'pending', response_note = NULL, responded_by = NULL, responded_at = NULL WHERE announcement_id = ?");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare event response reset: ' . $conn->error);
        }
        $stmt->bind_param('i', $announcementId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not reset event responses after requirement changes: ' . $stmt->error);
        }
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM announcement_event_requirement_responses WHERE announcement_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare event checklist reset: ' . $conn->error);
        }
        $stmt->bind_param('i', $announcementId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not reset event checklists: ' . $stmt->error);
        }
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM announcement_event_requirements WHERE announcement_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare event requirement replacement: ' . $conn->error);
        }
        $stmt->bind_param('i', $announcementId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not replace event requirements: ' . $stmt->error);
        }
        $stmt->close();

        $insertRequirement = $conn->prepare('INSERT INTO announcement_event_requirements (announcement_id, requirement_label, quantity, is_required, sort_order) VALUES (?, ?, ?, ?, ?)');
        if (!$insertRequirement) {
            throw new RuntimeException('Could not prepare event preparedness checklist save: ' . $conn->error);
        }
        foreach ($event['requirements'] as $requirement) {
            $label = $requirement['label'];
            $quantity = $requirement['quantity'];
            $isRequired = (int)$requirement['is_required'];
            $sortOrder = (int)$requirement['sort_order'];
            $insertRequirement->bind_param('isiii', $announcementId, $label, $quantity, $isRequired, $sortOrder);
            if (!$insertRequirement->execute()) {
                throw new RuntimeException('Could not save an event preparedness requirement: ' . $insertRequirement->error);
            }
        }
        $insertRequirement->close();
    } elseif (!$existingRequirements) {
        throw new RuntimeException('The event has no saved preparedness requirements.');
    }

    $stmt = $conn->prepare('INSERT IGNORE INTO announcement_event_responses (announcement_id, barangay_id) VALUES (?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare barangay event response rows: ' . $conn->error);
    }
    foreach ($targetIds as $targetId) {
        $targetId = (int)$targetId;
        $stmt->bind_param('ii', $announcementId, $targetId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not initialize a barangay event response: ' . $stmt->error);
        }
    }
    $stmt->close();

    $stmt = $conn->prepare('INSERT IGNORE INTO announcement_event_requirement_responses (announcement_id, barangay_id, requirement_id, is_ready) SELECT ?, ?, requirement_id, 0 FROM announcement_event_requirements WHERE announcement_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare new barangay checklist rows: ' . $conn->error);
    }
    foreach ($targetIds as $targetId) {
        $targetId = (int)$targetId;
        $stmt->bind_param('iii', $announcementId, $targetId, $announcementId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not initialize a barangay checklist: ' . $stmt->error);
        }
    }
    $stmt->close();
}

function dcvsAnnouncementFacebookTrackingReady(mysqli $conn): void
{
    $result = $conn->query(
        "SELECT COUNT(*) AS column_count,
                SUM(CASE WHEN column_name = 'page_id' THEN 1 ELSE 0 END) AS page_column_count
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'announcement_social_posts'"
    );
    if (!$result) {
        throw new RuntimeException('Could not verify Facebook tracking schema: ' . $conn->error);
    }
    $schema = $result->fetch_assoc();
    $result->free();
    if ((int)($schema['column_count'] ?? 0) === 0) {
        throw new RuntimeException('Facebook tracking table announcement_social_posts is missing.');
    }
    if ((int)($schema['page_column_count'] ?? 0) === 0) {
        throw new RuntimeException('Facebook tracking column announcement_social_posts.page_id is missing.');
    }
}

function dcvsFacebookSyncAnnouncementPreference(
    mysqli $conn,
    int $announcementId,
    string $pageId,
    string $postingMode,
    bool $optOut,
    bool $queuePost
): void {
    if ($optOut) {
        $stmt = $conn->prepare(
            "INSERT INTO announcement_social_posts (announcement_id, platform, page_id, status)
             VALUES (?, 'facebook', ?, 'skipped')
             ON DUPLICATE KEY UPDATE
               status = IF(status IN ('pending', 'failed', 'skipped'), 'skipped', status)"
        );
    } elseif ($postingMode === 'automatic' && $queuePost) {
        $stmt = $conn->prepare(
            "INSERT INTO announcement_social_posts (announcement_id, platform, page_id, status)
             VALUES (?, 'facebook', ?, 'pending')
             ON DUPLICATE KEY UPDATE
               status = IF(status = 'skipped', 'pending', status)"
        );
    } else {
        return;
    }

    if (!$stmt) {
        throw new RuntimeException('Could not prepare Facebook posting preference: ' . $conn->error);
    }
    $stmt->bind_param('is', $announcementId, $pageId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not save Facebook posting preference: ' . $error);
    }
    $stmt->close();
}

function handleSave($conn, $userId) {
    $id = (int)($_POST['id'] ?? 0);
    $saveMode = (string)($_POST['save_mode'] ?? '');
    if (!in_array($saveMode, ['create', 'edit'], true)
        || ($saveMode === 'edit' && $id < 1)
        || ($saveMode === 'create' && $id !== 0)) {
        error_log('[DCVS announcements] Rejected inconsistent save intent; mode='
            . preg_replace('/[^a-z]/', '', $saveMode) . ', id=' . $id . '.');
        return [
            'success' => false,
            'message' => 'The create/edit state was lost. Reopen the announcement and try again.',
        ];
    }
    $existing = null;
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT photo_url, photo_hover_url, is_event, program_id, status, facebook_posting_mode, facebook_opt_out FROM announcements WHERE announcement_id = ?");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Could not load the announcement for editing.'];
        }
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Could not load the announcement for editing.'];
        }
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existing) {
            return ['success' => false, 'message' => 'Announcement not found.'];
        }
    }
    $title = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');
    $barangayId = null;
    $isEvent = (($_POST['category'] ?? '') === 'event')
        && ((int)($existing['is_event'] ?? 0) === 1 || announcementHasCoordinationInput($_POST));
    $event = null;

    if (empty($title) || empty($content)) {
        return ['success' => false, 'message' => 'Title and content are required'];
    }
    list($category, $subject, $status, $audienceType, $audienceBarangays, $barangayId, $publishAt, $featured) = validateFields($_POST);
    try {
        $missingColumn = announcementMissingRequiredColumn($conn, $isEvent);
    } catch (Throwable $exception) {
        error_log('Announcement database preflight failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Could not verify the announcement database setup. Please contact the system administrator.'];
    }
    if ($missingColumn !== null) {
        error_log('Announcement save blocked: missing required database column '
            . $missingColumn['table'] . '.' . $missingColumn['column']);
        return ['success' => false, 'message' => 'The announcement database is missing a required update. Please contact the system administrator.'];
    }
    if ($isEvent) {
        try {
            $event = announcementEventInput($conn, $_POST, $id);
            $category = 'announcement';
            $audienceType = $event['target_mode'];
            $audienceBarangays = '';
            $barangayId = null;
            if ($status === 'scheduled' && (!$publishAt || $publishAt > $event['event_date'] . ' ' . $event['event_start_time'])) {
                return ['success' => false, 'message' => 'The announcement must be published before the event begins.'];
            }
        } catch (InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (RuntimeException $exception) {
            error_log('Event announcement validation failed: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Could not validate the event. Please try again.'];
        }
    }

    if (($_POST['category'] ?? '') === 'event' && !$isEvent) {
        $audienceType = 'all';
        $audienceBarangays = '';
        $barangayId = null;
    }

    $photoUrl = null;
    $photoHoverUrl = null;
    $oldPhotoUrl = null;
    $oldPhotoHoverUrl = null;

    if ($existing) {
        $photoUrl = $oldPhotoUrl = $existing['photo_url'] ?? null;
        $photoHoverUrl = $oldPhotoHoverUrl = $existing['photo_hover_url'] ?? null;
    }

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = uploadPhoto($_FILES['photo'], 'ann_photo');
        if ($uploaded) {
            $photoUrl = $uploaded;
        }
    }

    if ($category === 'advisory' && isset($_FILES['photo_hover']) && $_FILES['photo_hover']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded = uploadPhoto($_FILES['photo_hover'], 'ann_hover');
        if ($uploaded) {
            $photoHoverUrl = $uploaded;
        }
    }

    $now = date('Y-m-d H:i:s');
    $archivedAt = null;
    if ($status === 'archived') {
        $archivedAt = $now;
    }
    if ($id === 0 && $status === 'archived') {
        $status = 'draft';
        $archivedAt = null;
    }

    $facebookOptOut = array_key_exists('facebook_opt_out', $_POST)
        ? !empty($_POST['facebook_opt_out'])
        : (int)($existing['facebook_opt_out'] ?? 0) === 1;
    if (!$existing) {
        $facebookPostingMode = 'automatic';
    } else {
        $facebookPostingMode = ($existing['facebook_posting_mode'] ?? 'manual') === 'automatic'
            ? 'automatic'
            : 'manual';
    }
    $queueFacebookPost = !$existing && $facebookPostingMode === 'automatic' && !$facebookOptOut
        && in_array($status, ['scheduled', 'published'], true);

    if ($existing && (int)$existing['is_event'] === 1 && !$isEvent) {
        try {
            $missingColumn = announcementMissingRequiredColumn($conn, true);
        } catch (Throwable $exception) {
            error_log('Announcement database preflight failed: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Could not verify the announcement database setup. Please contact the system administrator.'];
        }
        if ($missingColumn !== null) {
            error_log('Announcement save blocked: missing required database column '
                . $missingColumn['table'] . '.' . $missingColumn['column']);
            return ['success' => false, 'message' => 'The announcement database is missing a required update. Please contact the system administrator.'];
        }
    }

    $eventValues = $event ?: [
        'program_id' => null,
        'event_date' => null,
        'event_end_date' => null,
        'event_start_time' => null,
        'event_end_time' => null,
        'venue_provider' => null,
        'venue_name' => null,
        'response_deadline' => null
    ];
    $saveLockName = '';
    $saveLockAcquired = false;
    $releaseSaveLock = static function () use ($conn, &$saveLockName, &$saveLockAcquired): void {
        if (!$saveLockAcquired) {
            return;
        }
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) {
            error_log('[DCVS announcements] Could not prepare announcement save-lock release: ' . $conn->error);
            return;
        }
        $stmt->bind_param('s', $saveLockName);
        if (!$stmt->execute()) {
            error_log('[DCVS announcements] Could not release announcement save lock: ' . $stmt->error);
            $stmt->close();
            return;
        }
        $stmt->close();
        $saveLockAcquired = false;
    };
    if (in_array($status, ['published', 'scheduled'], true)) {
        $databaseResult = $conn->query('SELECT DATABASE() AS database_name');
        $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : null;
        if ($databaseResult) {
            $databaseResult->free();
        }
        if (!$databaseRow || empty($databaseRow['database_name'])) {
            error_log('[DCVS announcements] Could not determine database for announcement save lock: ' . $conn->error);
            return ['success' => false, 'message' => 'Could not safely verify duplicate announcements. Please try again.'];
        }
        $saveLockName = 'dcvs_announcement_save_' . substr(hash('sha256', (string)$databaseRow['database_name']), 0, 32);
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        if (!$lockStmt) {
            error_log('[DCVS announcements] Could not prepare announcement save lock: ' . $conn->error);
            return ['success' => false, 'message' => 'Could not safely verify duplicate announcements. Please try again.'];
        }
        $lockStmt->bind_param('s', $saveLockName);
        if (!$lockStmt->execute()) {
            error_log('[DCVS announcements] Could not acquire announcement save lock: ' . $lockStmt->error);
            $lockStmt->close();
            return ['success' => false, 'message' => 'Could not safely verify duplicate announcements. Please try again.'];
        }
        $lockResult = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
        if ((int)($lockResult['acquired'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Another announcement is being saved. Please wait a moment and try again.'];
        }
        $saveLockAcquired = true;
        $duplicateContentClause = $isEvent ? '' : 'AND content = ?';
        $duplicateCheck = $conn->prepare(
            "SELECT announcement_id FROM announcements
             WHERE status IN ('published', 'scheduled') AND archived_at IS NULL
               AND title = ? AND location = ? $duplicateContentClause
               AND category = ? AND subject = ? AND is_event = ?
               AND event_date <=> ? AND event_end_date <=> ?
               AND event_start_time <=> ? AND event_end_time <=> ?
               AND venue_name <=> ? AND announcement_id <> ?
             ORDER BY announcement_id DESC LIMIT 1"
        );
        if (!$duplicateCheck) {
            error_log('[DCVS announcements] Could not prepare duplicate announcement check: ' . $conn->error);
            $releaseSaveLock();
            return ['success' => false, 'message' => 'Could not verify whether this announcement already exists. Please try again.'];
        }
        $duplicateEventFlag = $isEvent ? 1 : 0;
        $duplicateEventDate = $eventValues['event_date'];
        $duplicateEventEndDate = $eventValues['event_end_date'];
        $duplicateEventStart = $eventValues['event_start_time'];
        $duplicateEventEnd = $eventValues['event_end_time'];
        $duplicateVenue = $eventValues['venue_name'];
        $duplicateExcludeId = $id;
        if ($isEvent) {
            $duplicateCheck->bind_param(
                'ssssisssssi',
                $title,
                $location,
                $category,
                $subject,
                $duplicateEventFlag,
                $duplicateEventDate,
                $duplicateEventEndDate,
                $duplicateEventStart,
                $duplicateEventEnd,
                $duplicateVenue,
                $duplicateExcludeId
            );
        } else {
            $duplicateCheck->bind_param(
                'sssssisssssi',
                $title,
                $location,
                $content,
                $category,
                $subject,
                $duplicateEventFlag,
                $duplicateEventDate,
                $duplicateEventEndDate,
                $duplicateEventStart,
                $duplicateEventEnd,
                $duplicateVenue,
                $duplicateExcludeId
            );
        }
        if (!$duplicateCheck->execute()) {
            error_log('[DCVS announcements] Duplicate announcement check failed: ' . $duplicateCheck->error);
            $duplicateCheck->close();
            $releaseSaveLock();
            return ['success' => false, 'message' => 'Could not verify whether this announcement already exists. Please try again.'];
        }
        $duplicateRow = $duplicateCheck->get_result()->fetch_assoc();
        $duplicateCheck->close();
        if ($duplicateRow) {
            $duplicateId = (int)$duplicateRow['announcement_id'];
            $releaseSaveLock();
            return [
                'success' => false,
                'message' => 'A matching published or scheduled announcement already exists (ID '
                    . $duplicateId . '). Open it from the list and edit it instead of creating a duplicate.',
            ];
        }
    }
    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not begin announcement save transaction: ' . $conn->error);
        }
        $transactionStarted = true;
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE announcements SET title=?, location=?, content=?, category=?, subject=?, link_url=?, barangay_id=?, program_id=?, status=?, publish_at=?, archived_at=?, audience_type=?, audience_barangays=?, is_featured=?, photo_url=?, photo_hover_url=?, is_event=?, event_date=?, event_end_date=?, event_start_time=?, event_end_time=?, venue_provider=?, venue_name=?, response_deadline=?, facebook_posting_mode=?, facebook_opt_out=?, updated_at=? WHERE announcement_id=?");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare announcement update: ' . $conn->error);
            }
            $eventFlag = $isEvent ? 1 : 0;
            $facebookOptOutValue = $facebookOptOut ? 1 : 0;
            $stmt->bind_param("ssssssiisssssississssssssisi", $title, $location, $content, $category, $subject, $linkUrl, $barangayId, $eventValues['program_id'], $status, $publishAt, $archivedAt, $audienceType, $audienceBarangays, $featured, $photoUrl, $photoHoverUrl, $eventFlag, $eventValues['event_date'], $eventValues['event_end_date'], $eventValues['event_start_time'], $eventValues['event_end_time'], $eventValues['venue_provider'], $eventValues['venue_name'], $eventValues['response_deadline'], $facebookPostingMode, $facebookOptOutValue, $now, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (title, location, content, category, subject, link_url, barangay_id, program_id, status, publish_at, archived_at, audience_type, audience_barangays, is_featured, photo_url, photo_hover_url, is_event, event_date, event_end_date, event_start_time, event_end_time, venue_provider, venue_name, response_deadline, facebook_posting_mode, facebook_opt_out, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare announcement insert: ' . $conn->error);
            }
            $eventFlag = $isEvent ? 1 : 0;
            $facebookOptOutValue = $facebookOptOut ? 1 : 0;
            $stmt->bind_param("ssssssiisssssississssssssiiss", $title, $location, $content, $category, $subject, $linkUrl, $barangayId, $eventValues['program_id'], $status, $publishAt, $archivedAt, $audienceType, $audienceBarangays, $featured, $photoUrl, $photoHoverUrl, $eventFlag, $eventValues['event_date'], $eventValues['event_end_date'], $eventValues['event_start_time'], $eventValues['event_end_time'], $eventValues['venue_provider'], $eventValues['venue_name'], $eventValues['response_deadline'], $facebookPostingMode, $facebookOptOutValue, $userId, $now, $now);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not save announcement: ' . $stmt->error);
        }
        $savedId = $id > 0 ? $id : (int)$stmt->insert_id;
        $stmt->close();

        if ($isEvent) {
            syncAnnouncementEvent($conn, $savedId, $event);
        } elseif ($existing && (int)$existing['is_event'] === 1) {
            $stmt = $conn->prepare('DELETE FROM announcement_event_barangays WHERE announcement_id = ?');
            if (!$stmt) {
                throw new RuntimeException('Could not prepare event data cleanup: ' . $conn->error);
            }
            $stmt->bind_param('i', $savedId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not clear event data: ' . $stmt->error);
            }
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM announcement_event_requirements WHERE announcement_id = ?');
            if (!$stmt) {
                throw new RuntimeException('Could not prepare event requirement cleanup: ' . $conn->error);
            }
            $stmt->bind_param('i', $savedId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not clear event requirements: ' . $stmt->error);
            }
            $stmt->close();
        }
        if ($isEvent && $status === 'published' && (!$publishAt || $publishAt <= $now)) {
            notifyPublishedAnnouncementEventBarangays($conn, $savedId);
        }
        if (!$conn->commit()) {
            throw new RuntimeException('Could not commit announcement save transaction: ' . $conn->error);
        }
        $transactionStarted = false;
        $releaseSaveLock();
        if ($photoUrl !== $oldPhotoUrl && $oldPhotoUrl && file_exists(__DIR__ . '/../../' . $oldPhotoUrl)) unlink(__DIR__ . '/../../' . $oldPhotoUrl);
        if ($photoHoverUrl !== $oldPhotoHoverUrl && $oldPhotoHoverUrl && file_exists(__DIR__ . '/../../' . $oldPhotoHoverUrl)) unlink(__DIR__ . '/../../' . $oldPhotoHoverUrl);
        $label = $isEvent ? 'Event' : ($category === 'advisory' ? 'Advisory' : 'Announcement');
        $facebookStatus = null;
        $facebookEnabled = filter_var(env('FB_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($queueFacebookPost && !$facebookEnabled) {
            error_log('[DCVS Facebook] Posting is disabled (FB_ENABLED=false); announcement '
                . $savedId . ' remains pending.');
        }
        $facebookTrackingWarning = false;
        $facebookTrackingWriteRequired = $facebookOptOut || $queueFacebookPost;
        $facebookPageId = '';
        $facebookGraphErrorCode = null;
        $facebookTrackingLookupRequired = $facebookTrackingWriteRequired || $existing !== null;
        if ($facebookTrackingLookupRequired) {
            try {
                dcvsAnnouncementFacebookTrackingReady($conn);
                $facebookConfig = dcvsFacebookConfig();
                $facebookPageId = $facebookConfig['page_id'];
                if ($facebookPageId === '') {
                    throw new RuntimeException('Facebook Page ID is not configured for announcement tracking.');
                }
                if ($facebookTrackingWriteRequired) {
                    dcvsFacebookSyncAnnouncementPreference(
                        $conn,
                        $savedId,
                        $facebookPageId,
                        $facebookPostingMode,
                        $facebookOptOut,
                        $queueFacebookPost
                    );
                }
                $socialStmt = $conn->prepare(
                    "SELECT status, page_id, graph_error_code FROM announcement_social_posts
                     WHERE announcement_id = ? AND platform = 'facebook' AND page_id = ? LIMIT 1"
                );
                if (!$socialStmt) {
                    throw new RuntimeException('Could not prepare Facebook status lookup: ' . $conn->error);
                }
                $socialStmt->bind_param('is', $savedId, $facebookPageId);
                if (!$socialStmt->execute()) {
                    $socialError = $socialStmt->error;
                    $socialStmt->close();
                    throw new RuntimeException('Could not load Facebook status: ' . $socialError);
                }
                $socialRow = $socialStmt->get_result()->fetch_assoc();
                $socialStmt->close();
                $facebookStatus = $socialRow['status'] ?? null;
                $facebookGraphErrorCode = $socialRow['graph_error_code'] ?? null;
            } catch (Throwable $facebookTrackingException) {
                $facebookTrackingWarning = true;
                error_log('[DCVS Facebook] Announcement saved, but Facebook posting preference tracking failed ('
                    . get_class($facebookTrackingException) . '): '
                    . dcvsFacebookSanitizeDiagnosticMessage($facebookTrackingException->getMessage()));
            }
        }
        return [
            'success' => true,
            'message' => "$label saved successfully",
            'id' => $savedId,
            'facebook_posting_mode' => $facebookPostingMode,
            'facebook_opt_out' => $facebookOptOut,
            'facebook_status' => $facebookStatus,
            'facebook_graph_error_code' => $facebookGraphErrorCode,
            'facebook_page_id' => $facebookPageId,
            'facebook_post_pending' => $queueFacebookPost && $status === 'published',
            'facebook_enabled' => $facebookEnabled,
            'facebook_message' => !$facebookEnabled ? 'Facebook posting is turned off, so this was not posted.' : null,
            'facebook_tracking_warning' => $facebookTrackingWarning
        ];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        $releaseSaveLock();
        error_log('Announcement save failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Could not save the announcement. Please try again.'];
    }
}

function handleDelete($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT photo_url, photo_hover_url FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($row) {
            if ($row['photo_url'] && file_exists(__DIR__ . '/../../' . $row['photo_url'])) unlink(__DIR__ . '/../../' . $row['photo_url']);
            if ($row['photo_hover_url'] && file_exists(__DIR__ . '/../../' . $row['photo_hover_url'])) unlink(__DIR__ . '/../../' . $row['photo_hover_url']);
        }
        $stmt->close();
        return ['success' => true, 'message' => 'Deleted successfully'];
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Delete failed'];
}

function handleGet($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];
    $stmt = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $row['event_targets'] = [];
        $row['event_requirements'] = [];
        if ((int)($row['is_event'] ?? 0) === 1) {
            $stmt = $conn->prepare('SELECT barangay_id FROM announcement_event_barangays WHERE announcement_id = ? ORDER BY barangay_id');
            if (!$stmt) return ['success' => false, 'message' => 'Could not load event targets.'];
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $stmt->close();
                return ['success' => false, 'message' => 'Could not load event targets.'];
            }
            $result = $stmt->get_result();
            while ($target = $result->fetch_assoc()) $row['event_targets'][] = (int)$target['barangay_id'];
            $stmt->close();

            $stmt = $conn->prepare('SELECT requirement_id, requirement_label, quantity, is_required FROM announcement_event_requirements WHERE announcement_id = ? ORDER BY sort_order, requirement_id');
            if (!$stmt) return ['success' => false, 'message' => 'Could not load event requirements.'];
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $stmt->close();
                return ['success' => false, 'message' => 'Could not load event requirements.'];
            }
            $result = $stmt->get_result();
            while ($requirement = $result->fetch_assoc()) $row['event_requirements'][] = $requirement;
            $stmt->close();
        }
        return ['success' => true, 'data' => $row];
    }
    return ['success' => false, 'message' => 'Not found'];
}

function handleToggle($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT status FROM announcements WHERE announcement_id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Could not load announcement status.'];
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Could not load announcement status.'];
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message' => 'Not found'];
    $_POST['status'] = $row['status'] === 'published' ? 'draft' : 'published';
    return handleStatus($conn);
}

function handleStatus($conn) {
    $id = (int)($_POST['id'] ?? 0);
    $next = $_POST['status'] ?? '';
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];
    if (!in_array($next, ['draft', 'scheduled', 'published', 'archived'])) return ['success' => false, 'message' => 'Invalid status'];

    $stmt = $conn->prepare("SELECT status, facebook_posting_mode, facebook_opt_out FROM announcements WHERE announcement_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message' => 'Not found'];

    $archivedAt = $next === 'archived' ? date('Y-m-d H:i:s') : null;
    $publishAt = null;
    if ($next === 'scheduled') {
        $publishAt = date('Y-m-d H:i:s', time() + 3600);
    }
    if ($next === 'published') {
        $publishAt = date('Y-m-d H:i:s');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE announcements SET status = ?, publish_at = ?, archived_at = ?, updated_at = NOW() WHERE announcement_id = ?");
        if (!$stmt) throw new RuntimeException('Could not prepare announcement status update: ' . $conn->error);
        $stmt->bind_param("sssi", $next, $publishAt, $archivedAt, $id);
        if (!$stmt->execute()) throw new RuntimeException('Could not update announcement status: ' . $stmt->error);
        $stmt->close();
        if ($next === 'published') notifyPublishedAnnouncementEventBarangays($conn, $id);
        $conn->commit();
        $queueFacebookPost = ($next === 'scheduled'
            || ($next === 'published' && $row['status'] !== 'published'))
            && ($row['facebook_posting_mode'] ?? 'manual') === 'automatic'
            && (int)($row['facebook_opt_out'] ?? 0) !== 1;
        try {
            if (dcvsFacebookTrackingTableReady($conn)) {
                $config = dcvsFacebookConfig();
                if ($config['page_id'] !== '') {
                    dcvsFacebookSyncAnnouncementPreference(
                        $conn,
                        $id,
                        $config['page_id'],
                        (string)($row['facebook_posting_mode'] ?? 'manual'),
                        (int)($row['facebook_opt_out'] ?? 0) === 1,
                        $queueFacebookPost
                    );
                }
            }
        } catch (Throwable $facebookTrackingException) {
            error_log('[DCVS Facebook] Status updated, but pending tracking could not be saved ('
                . get_class($facebookTrackingException) . '): '
                . dcvsFacebookSanitizeDiagnosticMessage($facebookTrackingException->getMessage()));
        }
        return [
            'success' => true,
            'message' => 'Status updated',
            'status' => $next,
            'facebook_post_pending' => $queueFacebookPost && $next === 'published'
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Announcement status update failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Could not update announcement status.'];
    }
}

function handleDuplicate($conn, $userId) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) return ['success' => false, 'message' => 'Invalid ID'];

    $stmt = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    if (!$stmt) return ['success' => false, 'message' => 'Could not load the announcement to duplicate.'];
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Could not load the announcement to duplicate.'];
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message' => 'Not found'];

    $now = date('Y-m-d H:i:s');
    $newTitle = $row['title'] . ' (Copy)';
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO announcements (title, location, content, category, subject, link_url, barangay_id, status, publish_at, audience_type, audience_barangays, is_featured, photo_url, photo_hover_url, is_event, event_date, event_end_date, event_start_time, event_end_time, venue_provider, venue_name, response_deadline, facebook_posting_mode, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'automatic', ?, ?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare announcement duplication: ' . $conn->error);
        }
        $isEvent = (int)($row['is_event'] ?? 0);
        $eventDate = $isEvent ? $row['event_date'] : null;
        $eventEndDate = $isEvent ? $row['event_end_date'] : null;
        $eventStartTime = $isEvent ? $row['event_start_time'] : null;
        $eventEndTime = $isEvent ? $row['event_end_time'] : null;
        $venueProvider = $isEvent ? $row['venue_provider'] : null;
        $venueName = $isEvent ? $row['venue_name'] : null;
        $responseDeadline = $isEvent ? $row['response_deadline'] : null;
        $stmt->bind_param(
            'ssssssissississsssssiss',
            $newTitle,
            $row['location'],
            $row['content'],
            $row['category'],
            $row['subject'],
            $row['link_url'],
            $row['barangay_id'],
            $row['audience_type'],
            $row['audience_barangays'],
            $row['is_featured'],
            $row['photo_url'],
            $row['photo_hover_url'],
            $isEvent,
            $eventDate,
            $eventEndDate,
            $eventStartTime,
            $eventEndTime,
            $venueProvider,
            $venueName,
            $responseDeadline,
            $userId,
            $now,
            $now
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not duplicate the announcement: ' . $stmt->error);
        }
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        if ($isEvent === 1) {
            $targets = [];
            $stmt = $conn->prepare('SELECT barangay_id FROM announcement_event_barangays WHERE announcement_id = ? ORDER BY barangay_id');
            if (!$stmt) throw new RuntimeException('Could not prepare duplicated event targets: ' . $conn->error);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException('Could not load duplicated event targets: ' . $stmt->error);
            $result = $stmt->get_result();
            while ($target = $result->fetch_assoc()) $targets[] = (int)$target['barangay_id'];
            $stmt->close();

            $requirements = [];
            $stmt = $conn->prepare('SELECT requirement_label, quantity, is_required FROM announcement_event_requirements WHERE announcement_id = ? ORDER BY sort_order, requirement_id');
            if (!$stmt) throw new RuntimeException('Could not prepare duplicated event requirements: ' . $conn->error);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException('Could not load duplicated event requirements: ' . $stmt->error);
            $result = $stmt->get_result();
            while ($requirement = $result->fetch_assoc()) {
                $requirements[] = [
                    'id' => 0,
                    'label' => $requirement['requirement_label'],
                    'quantity' => $requirement['quantity'] === null ? null : (int)$requirement['quantity'],
                    'is_required' => (int)$requirement['is_required'],
                    'sort_order' => count($requirements)
                ];
            }
            $stmt->close();
            syncAnnouncementEvent($conn, $newId, ['targets' => $targets, 'requirements' => $requirements]);
        }
        $conn->commit();
        return ['success' => true, 'message' => $isEvent === 1 ? 'Event duplicated as a draft' : 'Duplicated as draft', 'id' => $newId];
    } catch (Throwable $exception) {
        $conn->rollback();
        error_log('Announcement duplication failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Could not duplicate the announcement.'];
    }
}