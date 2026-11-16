<?php
/**
 * DCVS SMS Notification System (IPROG SMS)
 * Sends SMS notifications through the IPROG SMS API.
 */

/* ─── IPROG SMS Configuration ────────────────────────────────────── */
require_once __DIR__ . '/env.php';
loadDcvsEnv();

if (!defined('IPROGSMS_API_URL')) {
    define('IPROGSMS_API_URL', rtrim((string)env('IPROGSMS_API_URL', 'https://www.iprogsms.com/api/v1/sms_messages'), '/'));
}
if (!defined('IPROGSMS_API_KEY')) {
    define('IPROGSMS_API_KEY', trim((string)env('IPROGSMS_API_KEY', '')));
}
if (!defined('IPROGSMS_SENDER_NAME')) {
    define('IPROGSMS_SENDER_NAME', trim((string)env('IPROGSMS_SENDER_NAME', '')));
}

/* ─── SMS Toggle (reads from db system_settings) ──────────────────── */
function isSmsEnabled($conn) {
    if (!$conn || $conn->connect_error) return false;
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sms_enabled'");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['setting_value'] === '1';
    }
    return false;
}

function getSmsSetting($conn, $key, $default = '') {
    if (!$conn || $conn->connect_error) return $default;
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if ($result && $row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

/* ─── Phone Normalizer (IPROG accepts Philippine international format) ── */
function normalizePhone($phone) {
    $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone));
    if (preg_match('/^09\d{9}$/', $phone)) {
        return '+63' . substr($phone, 1);
    }
    if (preg_match('/^\+639\d{9}$/', $phone)) {
        return $phone;
    }
    if (preg_match('/^639\d{9}$/', $phone)) {
        return '+' . $phone;
    }
    return null;
}

/* ─── Core SMS Sender ─────────────────────────────────────────────── */
/**
 * Send a single SMS via IPROG SMS API.
 * @param string $recipient  Phone number (09XXXXXXXXX or +639XXXXXXXXX)
 * @param string $message    SMS body
 * @return array             ['success' => bool, 'error' => string|null, 'id' => string|null]
 */
function sendIprogSms($recipient, $message) {
    $phone = normalizePhone($recipient);
    if (!$phone) {
        return ['success' => false, 'error' => 'Invalid phone number', 'id' => null];
    }
    if (IPROGSMS_API_KEY === '') {
        $error = 'IPROG SMS is not configured. Set IPROGSMS_API_KEY in .env or the server environment.';
        error_log('DCVS SMS: ' . $error);
        return ['success' => false, 'error' => $error, 'id' => null];
    }

    $formData = [
        'phone_number' => ltrim($phone, '+'),
        'message'      => $message,
    ];
    if (IPROGSMS_SENDER_NAME !== '') {
        $formData['sender_name'] = IPROGSMS_SENDER_NAME;
    }

    $ch = curl_init(IPROGSMS_API_URL);
    if ($ch === false) {
        $error = 'Unable to initialize the HTTP client for IPROG SMS.';
        error_log('DCVS SMS: ' . $error);
        return ['success' => false, 'error' => $error, 'id' => null];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . IPROGSMS_API_KEY,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query($formData, '', '&'),
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("DCVS SMS: IPROG SMS cURL error — $curlErr");
        return ['success' => false, 'error' => $curlErr, 'id' => null];
    }

    $resp = json_decode($body, true);
    $apiStatus = (string)($resp['status'] ?? '');
    $messageId = $resp['message_id'] ?? null;
    $accepted = $httpCode >= 200 && $httpCode < 300 && is_array($resp)
        && ($apiStatus === '200' || $messageId !== null);

    if ($accepted) {
        $outcome = $resp['message'] ?? 'accepted by IPROG SMS';
        error_log("DCVS SMS: Accepted for $phone | HTTP $httpCode | ID: " . ($messageId ?: 'n/a') . " | $outcome");
        return ['success' => true, 'error' => null, 'id' => $messageId];
    }

    $errMsg = $resp['message'] ?? $resp['error'] ?? "HTTP $httpCode";
    if ($httpCode > 0 && strpos($errMsg, 'HTTP ') !== 0) {
        $errMsg = "HTTP $httpCode: " . $errMsg;
    }
    $responseForLog = function_exists('mb_substr') ? mb_substr($body, 0, 1000) : substr($body, 0, 1000);
    error_log("DCVS SMS: IPROG SMS rejected $phone — $errMsg | Response: $responseForLog");
    return ['success' => false, 'error' => $errMsg, 'id' => null];
}

// Preserve existing callers while changing the provider implementation.
function sendTextbeeSms($recipient, $message) {
    return sendIprogSms($recipient, $message);
}

// Alias so all existing sendPhilSms() calls still work
function sendPhilSms($recipient, $message) {
    return sendTextbeeSms($recipient, $message);
}

/* ─── SMS Greeting Helpers ────────────────────────────────────────── */
function getFirstName($fullName) {
    $name = trim($fullName);
    if ($name === '') return '';
    $parts = explode(' ', $name);
    return $parts[0];
}

function getSmsFirstName($fullName) {
    return getFirstName($fullName);
}

function getSmsGreeting($firstName) {
    $manilaTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $hour = (int)$manilaTime->format('G');
    if ($hour >= 5 && $hour < 12) {
        $period = 'morning';
    } elseif ($hour >= 12 && $hour < 18) {
        $period = 'afternoon';
    } else {
        $period = 'evening';
    }
    if ($firstName !== '') {
        return "Good {$period}, {$firstName}!";
    }
    return "Good {$period}!";
}

/* ─── Log SMS to Database ─────────────────────────────────────────── */
function logSms($conn, $to, $recipientName, $message, $status, $errorMsg = null, $sentBy = null, $sentToRole = null, $messageType = null, $smsId = null) {
    if (!$conn || $conn->connect_error) return;
    $stmt = $conn->prepare("INSERT INTO sms_log (recipient_phone, recipient_name, message, status, error_message, sent_by, sent_to_role, message_type, sms_api_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param("sssssssss", $to, $recipientName, $message, $status, $errorMsg, $sentBy, $sentToRole, $messageType, $smsId);
    $stmt->execute();
    $stmt->close();
}

function spawnSmsWorker() {
    $candidates = [];
    if (defined('PHP_BINARY') && file_exists(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') !== false) {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = 'C:\\xampp\\php\\php.exe';
    $php = null;
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) { $php = $candidate; break; }
    }
    if (!$php) return false;

    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'sms_worker.php';
    if (!file_exists($worker)) return false;
    $command = 'start /B "" "' . $php . '" "' . $worker . '" > NUL 2>&1';
    $handle = @popen($command, 'r');
    if ($handle) { @pclose($handle); return true; }
    return false;
}

function queueSms($conn, $recipient, $recipientName, $message, $sentBy = null, $sentToRole = null, $messageType = null) {
    if (!$conn || $conn->connect_error) return false;
    $stmt = $conn->prepare("INSERT INTO sms_queue (recipient_phone, recipient_name, message, sent_by, sent_to_role, message_type) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('sssiss', $recipient, $recipientName, $message, $sentBy, $sentToRole, $messageType);
    $queued = $stmt->execute();
    $stmt->close();
    if (!$queued) return false;

    if (!spawnSmsWorker()) {
        error_log('DCVS SMS: Queued message but could not start workers/sms_worker.php.');
    }
    return true;
}

/* ─── High-Level: Send Appointment SMS ────────────────────────────── */
function sendAppointmentSms($conn, $appointmentId, $newStatus, $staffName = 'DCVS Staff', $staffMessage = '') {
    if (!isSmsEnabled($conn)) return false;

    $stmt = $conn->prepare("
        SELECT a.*, u.full_name, u.phone, u.reference_id AS user_ref,
               p.pet_name, p.reference_id AS pet_ref
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        WHERE a.appointment_id = ?
    ");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data || empty($data['phone'])) return false;

    $petName = $data['pet_name'] ?? 'your pet';
    $appointmentDate = $data['scheduled_date'] ?: $data['preferred_date'];
    $appointmentTime = $data['scheduled_time'] ?: $data['preferred_time'];
    $date = date('F j, Y', strtotime($appointmentDate));
    $time = date('g:i A', strtotime($appointmentTime));

    $typeLabels = ['vaccination'=>'Vaccination','checkup'=>'Checkup','kapon'=>'Kapon (Spay/Neuter)','adoption'=>'Adoption'];
    $apptType = $typeLabels[$data['appointment_type']] ?? ucfirst($data['appointment_type']);
    $titleType = $data['appointment_type'] === 'kapon' ? 'Kapon' : $apptType;
    $statusMap = [
        'scheduled'   => 'confirmed',
        'approved'    => 'confirmed',
        'completed'   => 'completed',
        'cancelled'   => 'cancelled',
        'rescheduled' => 'rescheduled',
        'no_show'     => 'marked as No Show',
        'pending'     => 'submitted',
    ];
    $statusText = $statusMap[$newStatus] ?? $newStatus;

    $subject = ($statusText === 'confirmed' && $petName !== 'your pet')
        ? "{$titleType} Appointment Scheduled for {$petName}"
        : "Appointment " . ucfirst($statusText);
    $firstName = getSmsFirstName($data['full_name']);
    $greeting = getSmsGreeting($firstName);

        $msg = ($statusText === 'confirmed' ? '' : $subject . "\n\n") . $greeting . "\n\n";
        if ($newStatus === 'completed') {
            $msg .= "Your {$apptType} appointment for {$petName} has been completed.\n"
                  . "We hope everything went well. Please don't hesitate to reach out for follow-up care or future appointments.";
        } else {
            $msg .= "Your {$apptType} appointment for {$petName} has been {$statusText}.\n"
                  . "Scheduled date is on {$date} at {$time}.\n"
                  . "Please come to DCVS and bring your pet for this service.";
        }
    if (trim($staffMessage) !== '') {
        $msg .= "\n\nNote from DCVS Staff:\n" . trim($staffMessage);
    }

    return queueSms($conn, $data['phone'], $data['full_name'], $msg, $data['user_id'], 'resident', 'appointment');
}

/* ─── High-Level: Send Report SMS ─────────────────────────────────── */
function sendReportSms($conn, $reportId, $newStatus, $staffName = 'DCVS Staff', $staffMessage = '') {
    if (!isSmsEnabled($conn)) return false;

    $stmt = $conn->prepare("
        SELECT r.*, u.full_name, u.phone, u.reference_id AS user_ref
        FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        WHERE r.report_id = ?
    ");
    $stmt->bind_param("i", $reportId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data || empty($data['phone'])) return false;

    $reportTypeLabels = ['stray'=>'Stray Animal','rabies'=>'Rabies Case','injured'=>'Injured Animal','dead'=>'Dead Animal','abuse'=>'Animal Abuse'];
    $reportType = strtolower($reportTypeLabels[$data['report_type']] ?? ucfirst($data['report_type']));
    $firstName = getSmsFirstName($data['full_name']);
    $greeting = getSmsGreeting($firstName);
    $scheduledAt = trim((string)($data['scheduled_at'] ?? ''));

    if ($newStatus === 'confirmed') {
        $message = "Your {$reportType} report has been officially confirmed by DCVS. Our team will take the necessary action.";
    } elseif ($newStatus === 'scheduled') {
        if ($scheduledAt !== '') {
            $scheduleText = date('F j, Y', strtotime($scheduledAt)) . ' at ' . date('g:i A', strtotime($scheduledAt));
            $message = "Your {$reportType} report has been scheduled for an intervention on {$scheduleText}. Please keep your phone available in case our team needs to coordinate with you.";
        } else {
            $message = "Your {$reportType} report has been scheduled for an intervention. Our team will coordinate the next steps with you.";
        }
    } elseif ($newStatus === 'resolved') {
        $message = "Your {$reportType} report has been resolved by DCVS. Thank you for your patience.";
    } elseif ($newStatus === 'pending') {
        $message = "We received your {$reportType} report. The Barangay team will review it and forward it to DCVS as needed.";
    } elseif ($newStatus === 'validated') {
        $message = "Your {$reportType} report has been validated by your Barangay and forwarded to DCVS for review.";
    } elseif ($newStatus === 'rejected') {
        $message = "After review, your {$reportType} report was not approved by DCVS.";
    } else {
        $message = "Your {$reportType} report has been updated by DCVS.";
    }

    $msg = $greeting . "\n\n" . $message;
    if (trim($staffMessage) !== '') {
        $msg .= ' ' . trim($staffMessage);
    }

        return queueSms($conn, $data['phone'], $data['full_name'], $msg, $data['reporter_id'], 'resident', 'report');
}

/* ─── High-Level: Send Adoption SMS ───────────────────────────────── */
function sendAdoptionSms($conn, $appointmentId, $listingId, $staffName = 'DCVS Staff') {
    if (!isSmsEnabled($conn)) return false;

    $stmt = $conn->prepare("
        SELECT a.*, u.full_name, u.phone,
               pl.name AS pet_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        JOIN pet_listings pl ON pl.listing_id = ?
        WHERE a.appointment_id = ?
    ");
    $stmt->bind_param("ii", $listingId, $appointmentId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data || empty($data['phone'])) return false;

    $petName = $data['pet_name'] ?? 'your adopted pet';
    $apptRef = $data['reference_id'] ?? 'APT-' . str_pad($appointmentId, 4, '0', STR_PAD_LEFT);

    $subject = "Adoption Complete";
    $firstName = getSmsFirstName($data['full_name']);
    $greeting = getSmsGreeting($firstName);

    $msg = $subject . "\n\n" . $greeting . "\n\n"
         . "Great news — the adoption for {$petName} has been finalized! "
            . "Ref: {$apptRef}. Welcome to the DCVS family!";

    $result = sendTextbeeSms($data['phone'], $msg);
    logSms($conn, $data['phone'], $data['full_name'], $msg, $result['success'] ? 'sent' : 'failed', $result['error'], $data['user_id'], 'resident', 'adoption', $result['id']);
    return $result['success'];
}

/* ─── High-Level: Send Announcement SMS ───────────────────────────── */
function sendAnnouncementSms($conn, $announcementId, $title) {
    if (!isSmsEnabled($conn)) return false;

    $subject = "New Announcement";

    $stmt = $conn->prepare("SELECT full_name, phone FROM users WHERE role = 'resident' AND status = 'active' AND phone IS NOT NULL AND phone != ''");
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $sentCount = 0;
    while ($row = $result->fetch_assoc()) {
        $firstName = getSmsFirstName($row['full_name']);
        $greeting = getSmsGreeting($firstName);

        $msg = $subject . "\n\n" . $greeting . "\n\n"
               . "{$title}. Visit the DCVS portal for details.";

        $res = sendTextbeeSms($row['phone'], $msg);
        logSms($conn, $row['phone'], $row['full_name'], $msg, $res['success'] ? 'sent' : 'failed', $res['error'], null, 'resident', 'announcement', $res['id']);
        if ($res['success']) $sentCount++;
    }
    return $sentCount;
}
