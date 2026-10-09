<?php
/**
 * DCVS Background Email Worker
 * Processes pending emails from the email_queue table and delivers them
 * via the configured mailer without blocking the web request.
 *
 * Intended to be launched detached (e.g. via spawnEmailWorker()).
 */

if (PHP_SAPI !== 'cli') {
    // Allow web invocation too, but keep it safe
    if (!isset($_GET['token']) || !defined('EMAIL_WORKER_TOKEN') || $_GET['token'] !== EMAIL_WORKER_TOKEN) {
        http_response_code(403);
        exit('Forbidden');
    }
}

ignore_user_abort(true);
set_time_limit(120);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailer.php';

function workerProcessQueue($conn) {
    $batchSize = 20;
    $workerToken = bin2hex(random_bytes(16));

    // Claim a batch of pending jobs (mark as processing so concurrent workers skip them)
    $claim = $conn->prepare("UPDATE email_queue SET status='processing', worker_token=? WHERE status='pending' ORDER BY id ASC LIMIT ?");
    $claim->bind_param("si", $workerToken, $batchSize);
    $claim->execute();
    $claim->close();

    $stmt = $conn->prepare("SELECT id, recipient_email, recipient_name, subject, body, sent_by, sent_to_role, message_type, portal_delivered FROM email_queue WHERE status='processing' AND worker_token=? AND attempts <= 3 ORDER BY id ASC LIMIT ?");
    $stmt->bind_param("si", $workerToken, $batchSize);
    $stmt->execute();
    $jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($jobs)) return 0;

    $processed = 0;
    foreach ($jobs as $job) {
        $processed++;
        $id = (int)$job['id'];
        $success = false;
        $errorMsg = null;

        if (empty($job['recipient_email'])) {
            $errorMsg = 'No recipient email on file';
        } else {
            $result = sendDcvsEmail(
                $job['recipient_email'],
                $job['subject'],
                $job['body'],
                $conn,
                $job['recipient_name'] ?? '',
                $job['sent_by'],
                $job['sent_to_role'],
                'staff_message',
                null,
                $job['message_type'],
                (bool)$job['portal_delivered']
            );
            if (is_array($result)) {
                $success = $result['success'];
                $errorMsg = $result['error'] ?? null;
            } else {
                $success = $result;
            }
        }

        $status = $success ? 'sent' : 'failed';
        $upd = $conn->prepare("UPDATE email_queue SET status=?, attempts=attempts+1, error_message=?, worker_token=NULL, processed_at=NOW() WHERE id=? AND worker_token=?");
        $upd->bind_param("ssis", $status, $errorMsg, $id, $workerToken);
        $upd->execute();
        $upd->close();

        if ($success) $sent++;
    }

    return $processed;
}

$count = 0;
$emptyPolls = 0;
while ($emptyPolls < 12) {
    $processed = workerProcessQueue($conn);
    if ($processed > 0) {
        $count += $processed;
        continue;
    }
    if ($count > 0) break;
    $emptyPolls++;
    usleep(100000);
}

if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
if (PHP_SAPI === 'cli') {
    exit("Processed $count email(s).\n");
}
echo "Processed $count email(s).";