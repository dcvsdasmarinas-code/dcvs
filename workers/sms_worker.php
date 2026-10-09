<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

ignore_user_abort(true);
set_time_limit(120);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../sms-sender.php';

function processSmsQueueBatch($conn, $batchSize = 10) {
    $workerToken = bin2hex(random_bytes(16));
    $claim = $conn->prepare("UPDATE sms_queue SET status = 'processing', worker_token = ?, attempts = attempts + 1 WHERE status = 'pending' ORDER BY id ASC LIMIT ?");
    if (!$claim) return 0;
    $claim->bind_param('si', $workerToken, $batchSize);
    $claim->execute();
    $claim->close();

    $stmt = $conn->prepare("SELECT id, recipient_phone, recipient_name, message, sent_by, sent_to_role, message_type FROM sms_queue WHERE worker_token = ? ORDER BY id ASC");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $workerToken);
    $stmt->execute();
    $jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($jobs as $job) {
        $result = sendIprogSms($job['recipient_phone'], $job['message']);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $apiId = $result['id'] ?? null;

        logSms(
            $conn,
            $job['recipient_phone'],
            $job['recipient_name'],
            $job['message'],
            $status,
            $error,
            $job['sent_by'],
            $job['sent_to_role'],
            $job['message_type'],
            $apiId
        );

        $update = $conn->prepare("UPDATE sms_queue SET status = ?, error_message = ?, sms_api_id = ?, processed_at = NOW(), worker_token = NULL WHERE id = ? AND worker_token = ?");
        if ($update) {
            $update->bind_param('sssis', $status, $error, $apiId, $job['id'], $workerToken);
            $update->execute();
            $update->close();
        }
    }

    return count($jobs);
}

$didWork = false;
for ($poll = 0; $poll < 12; $poll++) {
    $processed = processSmsQueueBatch($conn);
    if ($processed > 0) {
        $didWork = true;
        continue;
    }
    if ($didWork) break;
    usleep(100000);
}

$conn->close();
