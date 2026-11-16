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

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $result = $conn->query(
            "SELECT intake_id, batch_details,
                    DATE_FORMAT(batch_details_updated_at, '%Y-%m-%dT%H:%i:%s+08:00') AS batch_details_updated_at
             FROM rescue_intakes
             WHERE batch_id IS NOT NULL AND batch_details IS NOT NULL
             ORDER BY batch_details_updated_at DESC, intake_id DESC"
        );
        $batches = [];
        while ($row = $result->fetch_assoc()) {
            $batch = json_decode($row['batch_details'], true);
            if (!is_array($batch) || !isset($batch['id'])) {
                throw new RuntimeException('A saved rescue batch contains invalid data.');
            }
            $batch['rescueIntakeId'] = (int)$row['intake_id'];
            $batch['updatedAt'] = $row['batch_details_updated_at'];
            $batches[] = $batch;
        }
        $result->free();
        echo json_encode(['success' => true, 'batches' => $batches], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        error_log('Rescue batch load failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Rescue batches could not be loaded. Verify the rescue batch persistence migration has been applied.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid rescue batch data.']);
    exit;
}
if (!validateCsrf($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your session could not be verified. Refresh the page and try again.']);
    exit;
}

$batches = $input['batches'] ?? null;
if (!is_array($batches) || count($batches) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid rescue batch data.']);
    exit;
}

try {
    $conn->begin_transaction();
    $lookup = $conn->prepare('SELECT batch_id FROM rescue_intakes WHERE intake_id = ? FOR UPDATE');
    $update = $conn->prepare(
        'UPDATE rescue_intakes
         SET batch_id = ?, batch_details = ?, batch_details_updated_at = NOW()
         WHERE intake_id = ?'
    );
    if (!$lookup || !$update) {
        throw new RuntimeException('Could not prepare rescue batch persistence.');
    }

    $saved = 0;
    foreach ($batches as $batch) {
        if (!is_array($batch)) {
            throw new InvalidArgumentException('Invalid rescue batch entry.');
        }
        $batchId = trim((string)($batch['id'] ?? ''));
        $intakeId = (int)($batch['rescueIntakeId'] ?? 0);
        $count = (int)($batch['count'] ?? 0);
        $slots = $batch['slots'] ?? [];
        if (!preg_match('/^RB-[A-Za-z0-9-]{1,60}$/', $batchId) ||
            $intakeId <= 0 ||
            $count <= 0 ||
            $count > 500 ||
            !is_array($slots) ||
            count($slots) > 500) {
            throw new InvalidArgumentException('Invalid rescue batch entry.');
        }

        $lookup->bind_param('i', $intakeId);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        if (!$existing) {
            throw new RuntimeException('The rescue intake for a batch could not be found.');
        }
        if (!empty($existing['batch_id']) && $existing['batch_id'] !== $batchId) {
            throw new RuntimeException('The rescue intake is already linked to a different batch.');
        }

        $details = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($details === false || strlen($details) > 1048576) {
            throw new InvalidArgumentException('Rescue batch details are too large to save.');
        }

        $update->bind_param('ssi', $batchId, $details, $intakeId);
        $update->execute();
        $saved++;
    }

    $lookup->close();
    $update->close();
    $conn->commit();
    echo json_encode(['success' => true, 'saved' => $saved]);
} catch (InvalidArgumentException $exception) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Rescue batch save failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Rescue batches could not be saved. Verify the rescue batch persistence migration has been applied.']);
}
