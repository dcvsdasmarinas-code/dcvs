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
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';
require_once __DIR__ . '/../../includes/staff-app-base.php';
$appBaseUrl = dcvsStaffAppBaseUrl();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$activePage = 'communication';
$pageTitle = 'Communication';
$staffId = $_SESSION['user_id'];
$staffName = $_SESSION['full_name'] ?? 'DCVS Staff';

$messageTypes = [
    'general'          => 'General Message',
  'report'           => 'Animal Report',
  'appointment'      => 'Appointment',
  'adoption'         => 'Adoption Request',
    'followup'         => 'Follow-up / Reminder',
  'barangay_general'       => 'General Message',
  'barangay_report'        => 'Animal Report',
  'barangay_rescue'        => 'Rescue Case',
  'barangay_pets'          => 'Pet Records',
  'barangay_vaccination'   => 'Vaccination',
  'barangay_coordination'  => 'Barangay Coordination',
  'barangay_followup'      => 'Follow-up / Reminder',
  'barangay_other'         => 'Other',
  'other'            => 'Other'
];
$typeShortLabels = [
    'general'     => 'General',
    'report'      => 'Report',
    'appointment' => 'Appointment',
    'adoption'    => 'Adoption',
    'followup'    => 'Follow-up',
    'barangay_general'      => 'General',
    'barangay_report'       => 'Report',
    'barangay_rescue'       => 'Rescue',
    'barangay_pets'         => 'Pet Records',
    'barangay_vaccination'  => 'Vaccination',
    'barangay_coordination' => 'Coordination',
    'barangay_followup'     => 'Follow-up',
    'barangay_other'        => 'Other',
    'other'       => 'Other',
];

  function communicationNodeText($node) {
    if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
      return $node->nodeValue;
    }
    if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'br') {
      return "\n";
    }

    $text = '';
    foreach ($node->childNodes as $child) {
      $text .= communicationNodeText($child);
    }
    return $text;
  }

  function normalizeCommunicationText($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
  }

  function extractCommunicationMessage($htmlBody) {
    $previousLibxmlState = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML('<?xml encoding="UTF-8">' . $htmlBody, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $paragraphs = $document->getElementsByTagName('p');
    for ($index = 0; $index < $paragraphs->length - 1; $index++) {
      $intro = normalizeCommunicationText($paragraphs->item($index)->textContent);
      if (strpos($intro, 'We sent you the following message regarding') === false) continue;

      $message = normalizeCommunicationText(communicationNodeText($paragraphs->item($index + 1)));
      if ($message !== '') return $message;
    }

    $withBreaks = preg_replace('/<(br\b[^>]*|\/p\s*|\/div\s*|\/tr\s*)>/i', "\n", $htmlBody);
    return normalizeCommunicationText(strip_tags($withBreaks));
  }

  function buildCommunicationSms($firstName, $messageType, $message) {
    $parts = [getSmsGreeting($firstName)];
    $intros = [
      'report' => 'Here is an update regarding the animal report.',
      'appointment' => 'Here is an update regarding the appointment.',
      'adoption' => 'Here is an update regarding the adoption request.',
      'followup' => 'We have a follow-up and reminder for your appointment.'
    ];

    if (isset($intros[$messageType])) $parts[] = $intros[$messageType];
    $parts[] = trim($message);
    return implode("\n\n", array_filter($parts, function($part) { return $part !== ''; }));
  }

// Handle sending message (PRG pattern — redirect after POST to avoid resubmission)
$sendSuccess = $_SESSION['comm_success'] ?? null;
$sendError   = $_SESSION['comm_error'] ?? null;
unset($_SESSION['comm_success'], $_SESSION['comm_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $recipientId  = intval($_POST['recipient_id'] ?? 0);
    $recipientRole = $_POST['recipient_role'] ?? '';
    $messageType  = $_POST['message_type'] ?? 'general';
    $subject      = trim($_POST['subject'] ?? '');
    $message      = trim($_POST['message'] ?? '');

    if (empty($recipientId) || empty($recipientRole) || empty($subject) || empty($message)) {
        $_SESSION['comm_error'] = "All fields are required.";
    } else {
        // Fetch the recipient from DB (always use the registered email, never hardcoded)
        $userStmt = $conn->prepare("SELECT user_id, full_name, email, phone, role FROM users WHERE user_id = ? AND role = ? AND status = 'active' AND deleted_at IS NULL");
        $userStmt->bind_param("is", $recipientId, $recipientRole);
        $userStmt->execute();
        $recipient = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$recipient) {
            $_SESSION['comm_error'] = "Recipient not found or inactive.";
        } else {
            // Always create an in-portal notification (for both resident and barangay)
            $msgPreview = function_exists('mb_substr') ? mb_substr($message, 0, 200) : substr($message, 0, 200);
            createNotification($conn, $recipientId, 'staff_message', 'Message from Staff — ' . $subject,
                $staffName . ' wrote: ' . $msgPreview, 'staff_message', $staffId, $messageType);

            $emailSent = false;

            // Queue email to the registered address for both residents and barangay officers.
            if (in_array($recipientRole, ['resident', 'barangay'], true) && !empty($recipient['email'])) {
                $typeLabel = $messageTypes[$messageType] ?? 'Message';
                $portalLabel = $recipientRole === 'barangay' ? 'barangay' : 'resident';

                $escapedMsg = nl2br(htmlspecialchars($message));
                $htmlBody = dcvsEmailTemplate($typeLabel, '
                    <p style="margin:0 0 16px;">Dear ' . htmlspecialchars($recipient['full_name']) . ',</p>
                    <p style="margin:0 0 16px;">We sent you the following message regarding <strong>' . htmlspecialchars($typeLabel) . '</strong>:</p>
                    <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0f172a;">' . $escapedMsg . '</p>
                    <p style="margin:0 0 8px;font-size:13px;color:#64748b;">You can view this message and respond by logging into the DCVS ' . $portalLabel . ' portal.</p>
                ');

                $queueId = enqueueDcvsEmail(
                    $conn,
                    $recipient['email'],
                    $recipient['full_name'],
                    $subject,
                    $htmlBody,
                    $staffId,
                    $recipientRole,
                    $messageType,
                    true
                );
                $emailSent = $queueId !== null;
                if ($emailSent) spawnEmailWorker();
            }

            // For residents: also send SMS if phone number is available
            $smsSent = false;
            $smsError = null;
            if ($recipientRole === 'resident' && !empty($recipient['phone']) && isSmsEnabled($conn)) {
                $firstName = getFirstName($recipient['full_name']);
                $smsMsg = buildCommunicationSms($firstName, $messageType, $message);
                $smsResult = sendTextbeeSms($recipient['phone'], $smsMsg);
                $smsSent = $smsResult['success'];
                $smsError = $smsResult['error'];
                logSms($conn, $recipient['phone'], $recipient['full_name'], $smsMsg,
                    $smsSent ? 'sent' : 'failed', $smsResult['error'], $staffId, $recipientRole, 'communication', $smsResult['id']);
            }

            if ($recipientRole === 'resident' && $emailSent && $smsSent) {
              $_SESSION['comm_success'] = "Message sent successfully to <strong>" . htmlspecialchars($recipient['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong> via portal, email, and SMS.";
            } elseif ($recipientRole === 'resident' && $emailSent) {
              $_SESSION['comm_success'] = "Message queued for delivery to " . htmlspecialchars($recipient['full_name']) . " — delivered to portal and email." . (!empty($recipient['phone']) ? " SMS was not accepted by IPROG SMS: " . htmlspecialchars($smsError ?: 'unknown error') : " No phone number is on file for SMS.");
            } elseif ($recipientRole === 'resident' && $smsSent) {
              $_SESSION['comm_success'] = "Message sent to " . htmlspecialchars($recipient['full_name']) . " via portal; SMS accepted by IPROG SMS (carrier delivery may still be pending)." . (!empty($recipient['email']) ? " Email was not queued — check mailer settings." : "");
            } elseif ($recipientRole === 'barangay') {
                if ($emailSent) {
                    $_SESSION['comm_success'] = "Message queued for delivery to " . htmlspecialchars($recipient['full_name']) . " — delivered to barangay portal and queued for email.";
                } else {
                    $_SESSION['comm_success'] = "Message delivered to " . htmlspecialchars($recipient['full_name']) . " via barangay portal." . (!empty($recipient['email']) ? " Email was not queued — check mailer settings." : " No email address is on file.");
                }
            } else {
              $_SESSION['comm_success'] = "Message sent to portal." . ($smsError ? " SMS was not accepted by IPROG SMS: " . htmlspecialchars($smsError) : " (Recipient has no email or phone on file.)");
            }
        }
    }

    // PRG: redirect back to the same page so refresh never resubmits
    header('Location: staff-communication.php');
    exit;
}

// ── Handle RESEND of a sent message ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_log_id'])) {
    $resendId = intval($_POST['resend_log_id']);
    $orig = null;
    $rstmt = $conn->prepare("SELECT * FROM email_logs WHERE log_id = ? AND sent_by = ?");
    $rstmt->bind_param("ii", $resendId, $staffId);
    $rstmt->execute();
    $orig = $rstmt->get_result()->fetch_assoc();
    $rstmt->close();

    if (!$orig || empty($orig['recipient_email'])) {
        $_SESSION['comm_error'] = "Unable to resend: original message not found.";
    } else {
        $recipientEmail  = $orig['recipient_email'];
        $recipientName   = $orig['recipient_name'] ?? '';
        $subject         = $orig['subject'] ?? '';
        $body            = $orig['body'] ?? '';
        $messageType     = $orig['message_type'] ?? 'general';
        $sentToRole      = $orig['sent_to_role'] ?? 'resident';

        // Recover only the staff-composed text from the stored email template.
        $plainMsg = extractCommunicationMessage($body);
        $msgPreview = function_exists('mb_substr') ? mb_substr($plainMsg, 0, 200) : substr($plainMsg, 0, 200);

        $emailSent = false;
        $smsSent = false;
        $smsError = null;
        if ($sentToRole === 'barangay') {
            // Redeliver to the barangay portal and queue email to the registered address.
            $rcptStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND role = 'barangay' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
            $rcptStmt->bind_param("s", $recipientEmail);
            $rcptStmt->execute();
            $rcptRow = $rcptStmt->get_result()->fetch_assoc();
            $rcptStmt->close();

            if ($rcptRow) {
                createNotification($conn, $rcptRow['user_id'], 'staff_message', 'Message from Staff — ' . $subject, $staffName . ' wrote: ' . $msgPreview, 'staff_message', $staffId, $messageType);
            }
            $typeLabel = $messageTypes[$messageType] ?? 'Message';
            $htmlBody = dcvsEmailTemplate($typeLabel, '
                <p style="margin:0 0 16px;">Dear ' . htmlspecialchars($recipientName) . ',</p>
                <p style="margin:0 0 16px;">We sent you the following message regarding <strong>' . htmlspecialchars($typeLabel) . '</strong>:</p>
                <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0f172a;">' . nl2br(htmlspecialchars($plainMsg)) . '</p>
                <p style="margin:0 0 8px;font-size:13px;color:#64748b;">You can view this message and respond by logging into the DCVS barangay portal.</p>
            ');
            $queueId = enqueueDcvsEmail($conn, $recipientEmail, $recipientName, $subject, $htmlBody, $staffId, 'barangay', $messageType, true);
            $emailSent = $queueId !== null;
            if ($emailSent) spawnEmailWorker();
        } else {
            // Residents: portal notification + email + SMS
            $rcptStmt = $conn->prepare("SELECT user_id, phone FROM users WHERE email = ? AND role = 'resident' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
            $rcptStmt->bind_param("s", $recipientEmail);
            $rcptStmt->execute();
            $rcptRow = $rcptStmt->get_result()->fetch_assoc();
            $rcptStmt->close();

            if ($rcptRow) {
                createNotification($conn, $rcptRow['user_id'], 'staff_message', 'Message from Staff — ' . $subject, $staffName . ' wrote: ' . $msgPreview, 'staff_message', $staffId, $messageType);

                // Also resend SMS if phone is available
                if (!empty($rcptRow['phone']) && isSmsEnabled($conn)) {
                    $firstName = getFirstName($recipientName);
                    $smsMsg = buildCommunicationSms($firstName, $messageType, $plainMsg);
                    $smsResult = sendTextbeeSms($rcptRow['phone'], $smsMsg);
                    $smsSent = $smsResult['success'];
                    $smsError = $smsResult['error'];
                    logSms($conn, $rcptRow['phone'], $recipientName, $smsMsg,
                      $smsSent ? 'sent' : 'failed', $smsError, $staffId, 'resident', 'communication', $smsResult['id']);
                }
            }
            // Body stored is already the full HTML email template — reuse it
            $queueId = enqueueDcvsEmail($conn, $recipientEmail, $recipientName, $subject, $body, $staffId, $sentToRole, $messageType, true);
            $emailSent = $queueId !== null;
            if ($emailSent) spawnEmailWorker();
        }

        if ($emailSent && $smsSent) {
          $_SESSION['comm_success'] = "Message queued for resend to " . htmlspecialchars($recipientName ?: $recipientEmail) . " — portal and email queued; SMS accepted by IPROG SMS (carrier delivery may still be pending).";
        } elseif ($emailSent) {
          $_SESSION['comm_success'] = "Message queued for resend to " . htmlspecialchars($recipientName ?: $recipientEmail) . " — portal and email queued." . ($smsError ? " SMS was not accepted by IPROG SMS: " . htmlspecialchars($smsError) : " SMS was not sent.");
        } else {
            $_SESSION['comm_error'] = "Resend failed. The message was recorded as failed. Please check email configuration.";
        }
    }

    header('Location: staff-communication.php');
    exit;
}

// ── AJAX endpoint for server-side paginated history table ──
if (isset($_GET['ajax_logs'])) {
    header('Content-Type: application/json');
    $page   = max(1, intval($_GET['page'] ?? 1));
    $perPage = max(1, intval($_GET['per_page'] ?? 20));
    $search = trim($_GET['search'] ?? '');
    $type   = trim($_GET['type'] ?? '');
    $sortCol = $_GET['sort'] ?? 'date';
    $sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $sortMap = [
        'date' => 'el.created_at', 'time' => 'el.created_at',
        'recipient' => 'el.recipient_name', 'email' => 'el.recipient_email',
        'mobile' => 'u.phone', 'barangay' => 'b.barangay_name',
        'subject' => 'el.subject', 'type' => 'el.message_type',
        'delivery' => 'el.status'
    ];
    $orderBy = ($sortMap[$sortCol] ?? 'el.created_at') . ' ' . $sortDir;

    $where = "el.sent_by = ?";
    $params = [$staffId];
    $types = 'i';
    if ($type !== '') {
        $where .= " AND el.message_type = ?";
        $params[] = $type;
        $types .= 's';
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= " AND (el.recipient_name LIKE ? OR el.recipient_email LIKE ? OR el.subject LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sss';
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM email_logs el WHERE $where");
    if ($countStmt) {
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['c'];
        $countStmt->close();
    } else {
        $total = 0;
    }

    $offset = ($page - 1) * $perPage;
    $logs = [];
    $logStmt = $conn->prepare("SELECT el.*, u.phone AS recipient_phone, b.barangay_name AS recipient_barangay FROM email_logs el LEFT JOIN users u ON u.email = el.recipient_email AND u.role = el.sent_to_role LEFT JOIN barangays b ON b.barangay_id = u.barangay_id WHERE $where ORDER BY $orderBy, el.log_id DESC LIMIT ? OFFSET ?");
    if ($logStmt) {
        $logStmt->bind_param($types . 'ii', ...[...$params, $perPage, $offset]);
        $logStmt->execute();
        $res = $logStmt->get_result();
        while ($r = $res->fetch_assoc()) $logs[] = $r;
        $logStmt->close();
    }

    echo json_encode(['total' => $total, 'logs' => $logs, 'page' => $page, 'perPage' => $perPage]);
    exit;
}

// Initial total for the history badge (rows load lazily via AJAX)
$logs = [];
$countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM email_logs WHERE sent_by = ?");
if ($countStmt) {
    $countStmt->bind_param("i", $staffId);
    $countStmt->execute();
    $logsTotal = (int)$countStmt->get_result()->fetch_assoc()['c'];
    $countStmt->close();
} else {
    $logsTotal = 0;
}

// Fetch active residents and barangay users
$residents = getUsersByRole($conn, 'resident');
$barangayUsers = getUsersByRole($conn, 'barangay');
$barangays = [];
$barangayResult = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($barangayResult) {
  while ($barangayRow = $barangayResult->fetch_assoc()) {
    $barangays[] = $barangayRow;
  }
}

// DO NOT close $conn here — sidebar include uses it for notifications
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Communication — DCVS Staff</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <style>
    /* ── Semantic color tokens (light only) ── */
    :root {
      --comm-bg: #f8fafc;
      --comm-card: #ffffff;
      --comm-border: #e2e8f0;
      --comm-border-soft: #f1f5f9;
      --comm-text: #0f172a;
      --comm-text-2: #475569;
      --comm-text-3: #94a3b8;
      --comm-primary: #2563eb;
      --comm-primary-hover: #1d4ed8;
      --comm-primary-soft: #eff6ff;
      --comm-danger: #dc2626;
      --comm-danger-soft: #fef2f2;
      --comm-success: #16a34a;
      --comm-success-soft: #f0fdf4;
      --comm-type-report: #f59e0b;
      --comm-type-adoption: #10b981;
      --comm-type-appointment: #8b5cf6;
      --comm-type-followup: #0ea5e9;
      --comm-type-general: #64748b;
      --comm-type-other: #94a3b8;
    }

    body.staff-theme { background: #ffffff; }

    .comm-page { padding-bottom: 3rem; }

    .comm-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem; }
    .comm-header .page-title { font-size: 1.35rem; font-weight: 800; color: #0f172a; margin: 0; font-family: 'Inter', sans-serif; }
    .comm-header .page-subtitle { font-size: 0.88rem; color: #64748b; margin: 4px 0 0; }

.btn-compose { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--green, #045e1f); color: #fff; border: none; border-radius: 10px; padding: 0.7rem 1.3rem; font-family: inherit; font-size: 0.88rem; font-weight: 600; cursor: pointer; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); transition: all 0.2s; }
  .btn-compose:hover { background: var(--green-dark, #043012); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25); }

    /* ── Toast notifications ── */
    .toast-container{position:fixed;bottom:1.5rem;right:1.5rem;display:flex;flex-direction:column-reverse;gap:0.5rem;z-index:9999;pointer-events:none;}
    .toast{pointer-events:auto;display:flex;align-items:flex-start;gap:0.75rem;min-width:300px;max-width:420px;padding:1rem 1.25rem;border-radius:0.625rem;font-size:0.8125rem;line-height:1.4;font-weight:500;background:#fff;color:#18181b;border:1px solid #e4e4e7;box-shadow:0 8px 24px rgba(0,0,0,0.12),0 2px 6px rgba(0,0,0,0.06);transform:translateX(calc(100% + 1.5rem));opacity:0;transition:transform 0.3s cubic-bezier(0.16,1,0.3,1),opacity 0.25s ease;}
    .toast.show{transform:translateX(0);opacity:1;}
    .toast.hiding{transform:translateX(calc(100% + 1.5rem));opacity:0;}
    .toast-icon{flex-shrink:0;width:1.125rem;height:1.125rem;margin-top:1px;}
    .toast-body{flex:1;min-width:0;}
    .toast-title{font-weight:600;color:#18181b;margin:0;}
    .toast-desc{font-weight:400;color:#71717a;margin:0.125rem 0 0;font-size:0.8125rem;}
    .toast-close{flex-shrink:0;width:1.25rem;height:1.25rem;display:inline-flex;align-items:center;justify-content:center;border:none;background:transparent;color:#71717a;cursor:pointer;border-radius:0.25rem;transition:background 0.12s,color 0.12s;}
    .toast-close:hover{background:#f4f4f5;color:#18181b;}
    .toast.success{border-color:#bbf7d0;background:#f0fdf4;}
    .toast.success .toast-icon{color:#16a34a;}
    .toast.success .toast-title{color:#065f46;}
    .toast.error{border-color:#fecaca;background:#fef2f2;}
    .toast.error .toast-icon{color:#dc2626;}
    .toast.error .toast-title{color:#991b1b;}
    .toast.info{border-color:#bae6fd;background:#f0f9ff;}
    .toast.info .toast-icon{color:#0284c7;}
    .toast.info .toast-title{color:#0c4a6e;}

    /* ── Search & Filter toolbar ── */
    .comm-toolbar { display: flex; align-items: center; justify-content: flex-start; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
    .comm-toolbar .comm-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
    .comm-search { position: relative; flex: 0 1 320px; min-width: 200px; max-width: 320px; }
    .comm-search .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--comm-text-3); font-size: 0.85rem; pointer-events: none; }
    .comm-search input { width: 100%; box-sizing: border-box; padding: 0.6rem 0.85rem 0.6rem 2.3rem; border: 1.5px solid var(--comm-border); border-radius: 9px; font-family: inherit; font-size: 0.86rem; color: var(--comm-text); background: #fff; transition: border-color 0.2s, box-shadow 0.2s; }
    .comm-search input:focus { outline: none; border-color: var(--comm-primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
    .comm-filter select { max-width: 180px; padding: 0.6rem 2rem 0.6rem 0.85rem; border: 1.5px solid var(--comm-border); border-radius: 9px; font-family: inherit; font-size: 0.86rem; color: var(--comm-text-2); background: #fff; cursor: pointer; transition: border-color 0.2s; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2394a3b8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.7rem center; }
    .comm-filter select:focus { outline: none; border-color: var(--comm-primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
    .comm-count { margin-left: auto; font-size: 0.8rem; color: var(--comm-text-3); font-weight: 500; white-space: nowrap; }

    /* ── History card ── */
    .comm-card { background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden; }

    /* ── History table (shadcn) ── */
    .comm-card .sd-data-table-wrap { overflow-x: auto; min-height: 31.5rem; flex: 1; }
    .comm-card .sd-data-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .comm-card .sd-data-table .sd-tr { border-bottom: 1px solid #e4e4e7; transition: background 0.12s; }
    .comm-card .sd-data-table .sd-tr:last-child { border-bottom: none; }
    .comm-card .sd-data-table tbody .sd-tr:hover { background: #f8fafc; }
    .comm-card .sd-th:nth-child(1) { width: 8%; }
    .comm-card .sd-th:nth-child(2) { width: 8%; }
    .comm-card .sd-th:nth-child(3) { width: 12%; }
    .comm-card .sd-th:nth-child(4) { width: 9%; }
    .comm-card .sd-th:nth-child(5) { width: 14%; }
    .comm-card .sd-th:nth-child(6) { width: 9%; }
    .comm-card .sd-th:nth-child(7) { width: 9%; }
    .comm-card .sd-th:nth-child(8) { width: 12%; }
    .comm-card .sd-th:nth-child(9) { width: 9%; }
    .comm-card .sd-th:nth-child(10) { width: 7%; }
    .comm-card .sd-th-action { width: 3%; }
    .comm-card .sd-th { text-align: left; vertical-align: middle; }
    .comm-card .sd-td { padding: 12px; vertical-align: middle; font-size: 13px; color: #3f3f46; line-height: 1.4; }
    .comm-card .sd-td { text-align: left; }
    .comm-card .sd-td-action { text-align: right; }

    .log-col-date { white-space: nowrap; color: var(--comm-text-3); font-size: 0.8rem; }
    .log-recipient .name { font-weight: 600; color: var(--comm-text); overflow-wrap: anywhere; }
    .log-recipient .email { font-size: 0.74rem; color: var(--comm-text-3); margin-top: 1px; overflow-wrap: anywhere; }
    .log-subject .subj { font-weight: 500; color: var(--comm-text); overflow-wrap: anywhere; }

    /* ── Message type (plain black bold text) ── */
    .type-badge { font-size: 0.78rem; font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }

    /* ── Delivery status (plain black bold text) ── */
    .delivery-badges { display: flex; flex-wrap: wrap; gap: 0.3rem; }
    .delivery-badge { font-size: 0.76rem; font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }

    /* ── Inline action button (ghost) ── */
    .comm-card .sd-row-actions { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; padding: 0; border: none; border-radius: 6px; background: transparent; color: #71717a; cursor: pointer; font-size: 0.85rem; transition: background 0.15s ease, color 0.15s ease; }
    .comm-card .sd-row-actions:hover { background: #f4f4f5; color: #18181b; }
    .comm-card .sd-row-actions .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

    /* ── Pagination (shadcn) ── */
    .comm-card .sd-pagination { display: flex; justify-content: flex-end; align-items: center; padding: 0.8rem 1.25rem 1rem; border-top: 1px solid #e4e4e7; flex-shrink: 0; min-height: 52px; }
    .comm-card .sd-pagination-content { display: flex; flex-direction: row; align-items: center; gap: 4px; margin: 0; padding: 0; list-style: none; }
    .comm-card .sd-pagination-link { display: inline-flex; align-items: center; justify-content: center; height: 36px; padding: 0 16px; border: none; border-radius: 6px; background: transparent; color: #18181b; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s ease; gap: 6px; }
    .comm-card .sd-pagination-link:hover { background: #f4f4f5; }
    .comm-card .sd-pagination-link:disabled { opacity: 0.45; pointer-events: none; }
    .comm-card .sd-pagination-link.sd-pagination-previous { padding-left: 10px; }
    .comm-card .sd-pagination-link.sd-pagination-next { padding-right: 10px; }
    .comm-card .sd-pagination-link:focus-visible { outline: none; box-shadow: 0 0 0 2px #18181b; }

    .log-empty { text-align: center; padding: 3.5rem 1.5rem; color: var(--comm-text-3); font-size: 0.88rem; }
    .log-empty i { font-size: 2.4rem; display: block; margin-bottom: 0.6rem; color: #cbd5e1; }

    /* ── Compose dialog (shadcn style) ── */
    #composeDialog { z-index: 10000; padding: 0; background: rgba(0,0,0,0.5); }
    #composeDialog .comm-compose-dialog-content { position: relative; box-sizing: border-box; width: 100%; height: 100vh; height: 100dvh; max-width: none; max-height: none; display: flex; flex-direction: column; gap: 16px; padding: 24px; border: 0; border-radius: 0; background: #fff; box-shadow: none; animation: none; font-family:'Inter','Segoe UI',sans-serif; }
    .comm-compose-header { display: flex; flex-direction: column; gap: 6px; padding-right: 32px; }
    .comm-compose-header h2 { margin: 0; color: #18181b; font-size: 18px; font-weight: 600; line-height: 1; letter-spacing: -0.025em; }
    .comm-compose-header p { margin: 0; color: #71717a; font-size: 14px; font-weight: 400; line-height: 1.5; }
    .comm-compose-close { position: absolute; top: 16px; right: 16px; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 4px; background: transparent; color: #71717a; cursor: pointer; }
    .comm-compose-close:hover { background: #f4f4f5; color: #18181b; }
    .comm-compose-form { display: flex; flex: 1; min-height: 0; flex-direction: column; }
    .comm-compose-body { min-height: 0; overflow-y: auto; padding: 2px 2px 8px 0; }
    .comm-compose-body .form-group { margin-bottom: 16px; }
    .comm-compose-body label { display: block; font-family:'Inter','Segoe UI',sans-serif; font-size: 14px; font-weight: 500; line-height: 1; color: #18181b; margin-bottom: 8px; }
    .comm-compose-body label .req { color: #dc2626; font-weight: 700; margin-left: 1px; }
    .comm-compose-body select,
    .comm-compose-body input,
    .comm-compose-body textarea { width: 100%; height: 36px; padding: 0 12px; border: 1px solid #e4e4e7; border-radius: 6px; font-family:'Inter','Segoe UI',sans-serif; font-size: 14px; font-weight: 400; color: #18181b; background: #fff; transition: border-color 0.15s, box-shadow 0.15s; box-sizing: border-box; }
    .comm-compose-body select:focus,
    .comm-compose-body input:focus,
    .comm-compose-body textarea:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }
    .comm-compose-body textarea { height: auto; min-height: 120px; padding: 8px 12px; resize: vertical; line-height: 1.5; }
    .comm-compose-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding-top: 12px; border-top: 1px solid #e4e4e7; }
    .comm-compose-footer .btn-send { width: auto; }
    .recipient-count { font-size: 0.76rem; color: var(--comm-text-3); margin-top: 0.3rem; }
    .recipient-filter-group { display: none; }
    .recipient-filter-group.visible { display: block; }
    .subject-suggestions { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.35rem; }
    .subject-suggestions button { padding: 0.22rem 0.65rem; font-size: 0.7rem; border: 1px solid var(--comm-border); border-radius: 20px; background: #fff; color: var(--comm-text-2); cursor: pointer; font-family: inherit; transition: all 0.15s; }
    .subject-suggestions button:hover { border-color: var(--comm-primary); color: var(--comm-primary); background: var(--comm-primary-soft); }
    .btn-send { display: flex; align-items: center; justify-content: center; gap: 0.5rem; width: 100%; padding: 0.75rem 1.25rem; background: #000; color: #fff; border: none; border-radius: 10px; font-family: inherit; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
    .btn-send:hover { background: #1a1a1a; }
    .btn-send:disabled { opacity: 0.55; cursor: not-allowed; }

    /* Resend confirm modal */
    .resend-modal-icon { width: 56px; height: 56px; margin: 0 auto 0.9rem; border-radius: 50%; background: var(--comm-success-soft); color: var(--comm-success); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
    .resend-modal-title { font-size: 1.05rem; font-weight: 800; color: var(--comm-text); margin: 0 0 0.4rem; font-family: 'Inter', sans-serif; }
    .resend-modal-text { font-size: 0.86rem; color: var(--comm-text-2); line-height: 1.5; margin: 0 0 1.25rem; }

    /* ── Responsive: tablet & below ── */
    @media (max-width: 768px) {
      .comm-header { flex-direction: column; align-items: flex-start; width: 100%; }
      .comm-header > div { width: 100%; }
      .btn-compose { width: 100%; justify-content: center; }
      .comm-toolbar { flex-direction: column; align-items: stretch; }
      .comm-page { box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0; }
      .comm-toolbar .comm-toolbar-actions { width: 100%; margin-left: 0; }
      .comm-toolbar .comm-toolbar-actions .btn-compose { width: 100%; }
      .comm-count { margin-left: 0; align-self: flex-start; }
      .comm-toolbar .comm-search,
      .comm-toolbar .comm-filter { flex: 1 1 100%; width: 100%; max-width: none; min-width: 0; }
      .comm-toolbar .comm-filter select { width: 100%; max-width: none; box-sizing: border-box; }
      .comm-card { box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0; }
      .comm-card .sd-data-table-wrap { box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0; contain: paint; }
      .comm-card .sd-pagination { flex-direction: column; gap: 0.5rem; align-items: center; }
      .comm-card .sd-data-table { min-width: 1000px; }
    }

    @media (max-width: 640px) {
      .comm-card .sd-data-table-wrap { min-height: 24rem; }
      #composeDialog .comm-compose-dialog-content { padding: 20px; }
      .toast-container { left: 1rem; right: 1rem; }
      .toast { min-width: 0; max-width: 100%; }
    }

    /* ── Dialog font + button style (shadcn) — Communication ── */
    .sd-btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
      font-family:inherit; font-size:14px; font-weight:500; line-height:1;
      white-space:nowrap; cursor:pointer;
      box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
      transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .sd-btn-default{ background:#18181b; color:#fafafa; }
    .sd-btn-default:hover{ background:#27272a; }
    .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    .sd-btn-outline:hover{ background:#f4f4f5; }

    .comm-page{ font-family:'Inter','Segoe UI',sans-serif; }
    .comm-page .page-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    .comm-page .page-subtitle{ font-size:14px; color:#71717a; }
    /* toolbar */
    .comm-toolbar{ border: none; background: #fff; box-shadow: none; padding: 0; border-radius: 0; }
    .comm-search input{ height:36px; padding:0 12px 0 36px; border:1px solid #e4e4e7; border-radius:6px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; }
    .comm-search input:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .comm-search input::placeholder{ color:#a1a1aa; }
    .comm-search .search-icon{ color:#71717a; font-size:14px; left:13px; }
    .comm-filter select{ height:36px; padding:0 26px 0 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .comm-filter select:focus{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .comm-count{ font-size:14px; color:#71717a; }
    /* table data (All Clients table style) */
    .comm-card .sd-th{ background:#fafafa; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; letter-spacing:0; text-transform:none; color:#71717a; height:40px; padding:0 8px; border-bottom:1px solid #e4e4e7; }
    .comm-card .sd-td{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; padding:8px; }
    .comm-card .sd-data-table tbody .sd-tr:hover{ background:#fafafa; }
    .comm-card .th-sort{ display:inline-flex; align-items:center; gap:4px; min-height:28px; height:auto; margin-left:0; padding:0; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:normal; text-align:left; font-family:inherit; }
    .comm-card .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    .comm-card .th-sort.th-sorted{ color:#18181b; }
    .comm-card .th-sort-icon{ width:14px; height:14px; color:#71717a; flex-shrink:0; }
    .comm-card .th-sorted .th-sort-icon{ color:#18181b; }
    .log-recipient .name{ font-family:'Inter','Segoe UI',sans-serif; font-size:13.5px; font-weight:600; color:#18181b; overflow-wrap:anywhere; }
    .log-recipient .email{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; overflow-wrap:anywhere; }
    .log-subject .subj{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; overflow-wrap:anywhere; }
    .log-col-date{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    .log-recipient .email, .type-badge, .delivery-badge{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    .log-empty{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    /* drawer + modal fonts/buttons */
    .resend-modal-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    .btn-compose{ background:#18181b; color:#fafafa; border:1px solid transparent; border-radius:6px; height:36px; padding:0 16px; font-size:14px; font-weight:500; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .btn-compose:hover{ background:#27272a; transform:none; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .btn-send{ background:#18181b; color:#fff; border:1px solid transparent; border-radius:6px; height:36px; font-size:14px; font-weight:500; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .btn-send:hover{ background:#27272a; }
    /* pagination (all-clients design) */
    .comm-page .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 16px; border-top:1px solid #e4e4e7; }
    .comm-page .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .comm-page .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    .comm-page .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    .comm-page .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .comm-page .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    .comm-page .dt-page-btns{ display:flex; align-items:center; gap:4px; }
    .comm-page .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .comm-page .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
    .comm-page .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
  </style>
</head>
<body class="staff-theme" data-portal="staff">

<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>
<main class="main-content">

  <div class="comm-page">
    <?php if ($sendSuccess): ?>
      <script>document.addEventListener('DOMContentLoaded',function(){showToast('Success',{type:'success',description:<?php echo json_encode($sendSuccess, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>});});</script>
    <?php endif; ?>
    <?php if ($sendError): ?>
      <script>document.addEventListener('DOMContentLoaded',function(){showToast('Error',{type:'error',description:'<?php echo addslashes($sendError); ?>'});});</script>
    <?php endif; ?>

    <!-- History toolbar (outside the table container) -->
    <div class="comm-toolbar comm-toolbar-inner">
      <div class="comm-search">
        <i class="fas fa-search search-icon"></i>
        <input type="text" id="logSearch" placeholder="Search by recipient or subject..." oninput="logDebouncedSearch()" />
      </div>
      <div class="comm-filter">
        <select id="logTypeFilter" onchange="renderLogPage(1)">
          <option value="">All Types</option>
          <option value="report">Report</option>
          <option value="adoption">Adoption</option>
          <option value="appointment">Appointment</option>
          <option value="followup">Follow-up</option>
          <option value="general">General</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="comm-toolbar-actions">
        <button type="button" class="sd-btn sd-btn-default btn-compose" onclick="openComposeDialog()">Compose Message</button>
      </div>
    </div>

    <!-- History table -->
    <div class="comm-card">
      <?php if ($logsTotal === 0): ?>
        <div class="log-empty">No messages sent yet.</div>
      <?php else: ?>
        <div class="sd-data-table-wrap">
          <table class="sd-data-table no-responsive">
            <thead>
              <tr class="sd-tr">
                <th class="sd-th"><button type="button" class="th-sort" data-col="date" onclick="commSort('date')">Date<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="time" onclick="commSort('time')">Time<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="recipient" onclick="commSort('recipient')">Recipient Name<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th">Role</th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="email" onclick="commSort('email')">Email<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="mobile" onclick="commSort('mobile')">Mobile Number<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="barangay" onclick="commSort('barangay')">Barangay<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="subject" onclick="commSort('subject')">Subject<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="type" onclick="commSort('type')">Type<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="delivery" onclick="commSort('delivery')">Delivery<svg class="th-sort-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th sd-th-action"></th>
              </tr>
            </thead>
            <tbody id="logTbody"></tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($logsTotal > 0): ?>
        <div class="dt-pagination" id="logPagination">
            <div class="dt-pagination-right">
              <div class="dt-rows">
                <span>Rows per page</span>
                <select onchange="setLogPerPage(this.value)">
                  <option value="20" selected>20</option>
                  <option value="8">8</option>
                  <option value="10">10</option>
                  <option value="15">15</option>
                  <option value="30">30</option>
                </select>
              </div>
              <div class="dt-page-label" id="logPageLabel">Page 1 of 1</div>
              <div class="dt-page-btns">
                <button class="dt-page-btn" id="logPageFirst" title="Go to first page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
                </button>
                <button class="dt-page-btn" id="logPagePrev" title="Go to previous page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <button class="dt-page-btn" id="logPageNext" title="Go to next page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <button class="dt-page-btn" id="logPageLast" title="Go to last page">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                </button>
              </div>
            </div>
        </div>
    <?php endif; ?>
  </div>
</main>

<div class="toast-container" id="toastContainer"></div>

<!-- Compose Message Dialog -->
<div class="modal" id="composeDialog" onclick="if(event.target === this) closeComposeDialog()">
  <section class="comm-compose-dialog-content" role="dialog" aria-modal="true" aria-labelledby="composeDialogTitle" aria-describedby="composeDialogDescription">
    <button type="button" class="comm-compose-close" onclick="closeComposeDialog()" aria-label="Close compose message dialog"><i class="fas fa-times"></i></button>
    <div class="comm-compose-header">
      <h2 id="composeDialogTitle">Compose Message</h2>
      <p id="composeDialogDescription">Send a message directly to a resident or barangay officer.</p>
    </div>
    <form method="POST" action="staff-communication.php" id="composeForm" class="comm-compose-form">
      <div class="comm-compose-body">
      <div class="form-group">
        <label for="recipient_role">Send To <span class="req">*</span></label>
        <select id="recipient_role" name="recipient_role" onchange="onRoleChange()" required>
          <option value="">Select role...</option>
          <option value="resident">Resident</option>
          <option value="barangay">Barangay Officer</option>
        </select>
      </div>
      <div class="form-group recipient-filter-group" id="recipientBarangayGroup">
          <label for="recipientBarangay">Barangay <span class="req">*</span></label>
          <select id="recipientBarangay" onchange="filterCommunicationRecipients()" disabled>
            <option value="">Select Barangay...</option>
            <?php foreach ($barangays as $barangay): ?>
              <option value="<?php echo (int)$barangay['barangay_id']; ?>"><?php echo htmlspecialchars($barangay['barangay_name']); ?></option>
            <?php endforeach; ?>
          </select>
      </div>
      <div class="form-group recipient-filter-group" id="recipientAccountGroup">
        <label for="recipient_id" id="recipientAccountLabel">Registered Accounts <span class="req">*</span></label>
        <select id="recipient_id" name="recipient_id" required disabled>
          <option value="">Select a barangay first...</option>
        </select>
        <div class="recipient-count" id="recipientCount"></div>
      </div>
      <div class="form-group">
        <label for="message_type">Message Type <span class="req">*</span></label>
        <select id="message_type" name="message_type" onchange="onMessageTypeChange()" required>
          <?php foreach ($messageTypes as $key => $label): ?>
            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="subject">Subject <span class="req">*</span></label>
        <input type="text" id="subject" name="subject" placeholder="e.g. Update on your report" required />
        <div class="subject-suggestions" id="subjectSuggestions"></div>
      </div>
      <div class="form-group">
        <label for="message">Message <span class="req">*</span></label>
        <textarea id="message" name="message" placeholder="Type your message here..." required></textarea>
      </div>

      </div>
      <input type="hidden" name="send_message" value="1">
      <div class="comm-compose-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeComposeDialog()">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default btn-send" id="sendBtn"><span id="sendBtnLabel">Send Message</span></button>
      </div>
    </form>
  </section>
</div>

<!-- Resend Confirm Modal -->
<div id="resendModal" class="modal">
  <div class="modal-content" style="max-width:420px;">
    <div class="modal-body" style="text-align:center;padding:2rem;">
      <div class="resend-modal-icon"><i class="fas fa-paper-plane"></i></div>
      <h3 class="resend-modal-title">Resend Message?</h3>
      <p class="resend-modal-text">This will re-deliver the message to the recipient's portal and email again.</p>
      <form method="POST" action="staff-communication.php">
        <input type="hidden" name="resend_log_id" id="resendLogId" value="">
        <div class="modal-actions" style="justify-content:center;">
          <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('resendModal')">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-default"><i class="fas fa-paper-plane"></i> Resend</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
var residents = <?php echo json_encode($residents); ?>;
var barangayUsers = <?php echo json_encode($barangayUsers); ?>;

var subjectTemplates = {
    general:     ['Important Update from DCVS', 'Notification from DCVS Staff'],
    report:      ['Update on Animal Report', 'Action Needed on Report', 'Follow-up on Report'],
    appointment: ['Appointment Confirmation', 'Appointment Reminder', 'Appointment Rescheduled'],
    adoption:    ['Update on Adoption Request', 'Adoption Application Status'],
    followup:    ['Friendly Reminder from DCVS', 'Follow-up on Previous Concern'],
    other:       []
};
var messageTypeOptions = {
    resident: [
        { value: 'general', label: 'General Message' },
        { value: 'report', label: 'Animal Report' },
        { value: 'appointment', label: 'Appointment' },
        { value: 'adoption', label: 'Adoption Request' },
        { value: 'followup', label: 'Follow-up / Reminder' },
        { value: 'other', label: 'Other' }
    ],
    barangay: [
        { value: 'barangay_general', label: 'General Message' },
        { value: 'barangay_report', label: 'Animal Report' },
        { value: 'barangay_rescue', label: 'Rescue Case' },
        { value: 'barangay_pets', label: 'Pet Records' },
        { value: 'barangay_vaccination', label: 'Vaccination' },
        { value: 'barangay_coordination', label: 'Barangay Coordination' },
        { value: 'barangay_followup', label: 'Follow-up / Reminder' },
        { value: 'barangay_other', label: 'Other' }
    ]
};
var barangaySubjectTemplates = {
    barangay_general: ['Barangay Coordination'],
    barangay_report: ['Report Follow-up', 'Report Validation'],
    barangay_rescue: ['Rescue Coordination'],
    barangay_pets: ['Pet Record Verification'],
    barangay_vaccination: ['Vaccination Update'],
    barangay_coordination: ['Barangay Coordination'],
    barangay_followup: ['Follow-up Required', 'Reminder'],
    barangay_other: []
};

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function onRoleChange() {
    updateRecipients();
    updateMessageTypeOptions();
}

function updateMessageTypeOptions() {
    var role = document.getElementById('recipient_role').value;
    var typeSelect = document.getElementById('message_type');
    var options = messageTypeOptions[role] || messageTypeOptions.resident;
    typeSelect.innerHTML = '';
    options.forEach(function(option) {
        var element = document.createElement('option');
        element.value = option.value;
        element.textContent = option.label;
        typeSelect.appendChild(element);
    });
    document.getElementById('subject').value = '';
    onMessageTypeChange();
}

function updateRecipients() {
    var role = document.getElementById('recipient_role').value;
    var select = document.getElementById('recipient_id');
    var count = document.getElementById('recipientCount');
    var barangaySelect = document.getElementById('recipientBarangay');
    var hasRole = role === 'resident' || role === 'barangay';
    document.getElementById('recipientBarangayGroup').classList.toggle('visible', hasRole);
    document.getElementById('recipientAccountGroup').classList.remove('visible');
    document.getElementById('recipientAccountLabel').innerHTML = (role === 'barangay' ? 'Registered Accounts' : 'Registered Clients') + ' <span class="req">*</span>';
    barangaySelect.value = '';
    barangaySelect.disabled = !hasRole;
    select.disabled = true;
    select.required = false;
    select.innerHTML = '<option value="">' + (hasRole ? 'Select a barangay first...' : 'Select a role first...') + '</option>';
    count.textContent = '';
}

function filterCommunicationRecipients() {
    var role = document.getElementById('recipient_role').value;
    var barangayId = document.getElementById('recipientBarangay').value;
    var select = document.getElementById('recipient_id');
    var accountGroup = document.getElementById('recipientAccountGroup');
    var count = document.getElementById('recipientCount');
    var users = role === 'barangay' ? barangayUsers : residents;

    select.innerHTML = '<option value="">' + (barangayId ? 'Select a registered account...' : 'Select a barangay first...') + '</option>';
    accountGroup.classList.toggle('visible', !!barangayId);
    select.disabled = !barangayId;
    select.required = !!barangayId;
    count.textContent = '';
    if (!barangayId) return;

    var matches = users.filter(function(user) {
      return String(user.barangay_id || '') === String(barangayId);
    });

    count.textContent = matches.length + ' registered ' + (role === 'barangay' ? 'account' : 'client') + (matches.length !== 1 ? 's' : '') + ' found';
    matches.forEach(function(user) {
        var option = document.createElement('option');
        option.value = user.user_id;
        option.textContent = user.full_name + (user.email ? ' — ' + user.email : '');
        select.appendChild(option);
    });
    if (!matches.length) {
        select.innerHTML = '<option value="">No registered ' + (role === 'barangay' ? 'accounts' : 'clients') + ' for this barangay</option>';
        select.disabled = true;
        select.required = false;
    }
}

function onMessageTypeChange() {
    var type = document.getElementById('message_type').value;
    var suggestions = document.getElementById('subjectSuggestions');
    var subjectInput = document.getElementById('subject');
    suggestions.innerHTML = '';
    var subs = barangaySubjectTemplates[type] || subjectTemplates[type] || [];
    subs.forEach(function(s) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = s;
        btn.onclick = function() { subjectInput.value = s; };
        suggestions.appendChild(btn);
    });
}

/* ── Compose dialog ── */
function openComposeDialog() {
  document.getElementById('composeDialog').classList.add('active');
    document.body.style.overflow = 'hidden';
  document.getElementById('recipient_role').focus();
}
function closeComposeDialog() {
  document.getElementById('composeDialog').classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('composeDialog').classList.contains('active')) closeComposeDialog();
});

document.getElementById('composeForm')?.addEventListener('submit', function(e) {
  var role = document.getElementById('recipient_role').value;
  var barangay = document.getElementById('recipientBarangay').value;
  var recipient = document.getElementById('recipient_id').value;
  if ((role === 'resident' || role === 'barangay') && (!barangay || !recipient)) {
    e.preventDefault();
    document.getElementById('recipientCount').textContent = !barangay
      ? 'Select a barangay before sending.'
      : 'Select a registered ' + (role === 'barangay' ? 'barangay account' : 'client') + ' before sending.';
    return;
  }
    var btn = document.getElementById('sendBtn');
    btn.disabled = true;
    document.getElementById('sendBtnLabel').textContent = 'Sending...';
});

/* ── Sent Message History: server-side search + filter + pagination (8/page) ── */
var logPerPage = 20;
var logPage = 1;
var searchTimer = null;
var commSortCol = 'date';
var commSortDir = 'desc';

var typeLabels = <?php echo json_encode($typeShortLabels); ?>;

var typeMeta = {
    report:      { label: 'Report' },
    adoption:    { label: 'Adoption' },
    appointment: { label: 'Appointment' },
    followup:    { label: 'Follow-up' },
    general:     { label: 'General' },
    barangay_general:      { label: 'General' },
    barangay_report:       { label: 'Report' },
    barangay_rescue:       { label: 'Rescue' },
    barangay_pets:         { label: 'Pet Records' },
    barangay_vaccination:  { label: 'Vaccination' },
    barangay_coordination: { label: 'Coordination' },
    barangay_followup:     { label: 'Follow-up' },
    barangay_other:        { label: 'Other' },
    other:       { label: 'Other' }
};

function fmtLogDate(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return escapeHtml(s);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function fmtLogTime(s) {
    if (!s) return '';
    var d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return '';
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function logRowHtml(log) {
    var tm = typeMeta[log.message_type] || typeMeta.other;
    var recipientName = (log.recipient_name || log.recipient_email || '');
    var recipientEmail = (log.recipient_email || '');
    var delivery = log.status === 'sent' ? 'Email/Sms Sent' : 'Email/Sms Failed';
    var recipientRole = log.sent_to_role === 'barangay' ? 'Barangay Officer' : (log.sent_to_role === 'resident' ? 'Resident' : (log.sent_to_role || '—'));

    return '<tr class="sd-tr">' +
        '<td class="sd-td log-col-date">' + fmtLogDate(log.created_at) + '</td>' +
        '<td class="sd-td log-col-date">' + fmtLogTime(log.created_at) + '</td>' +
        '<td class="sd-td"><div class="log-recipient"><div class="name">' + escapeHtml(recipientName) + '</div></div></td>' +
        '<td class="sd-td">' + escapeHtml(recipientRole) + '</td>' +
        '<td class="sd-td"><div class="log-recipient"><div class="email">' + escapeHtml(recipientEmail) + '</div></div></td>' +
        '<td class="sd-td log-col-value">' + escapeHtml(log.recipient_phone || '—') + '</td>' +
      '<td class="sd-td log-col-value">' + escapeHtml(log.recipient_barangay || '—') + '</td>' +
        '<td class="sd-td"><div class="log-subject"><div class="subj">' + escapeHtml(log.subject || '') + '</div></div></td>' +
        '<td class="sd-td"><span class="type-badge">' + tm.label + '</span></td>' +
      '<td class="sd-td"><span class="delivery-badge">' + delivery + '</span></td>' +
        '<td class="sd-td sd-td-action">' +
            '<button class="sd-row-actions" title="Resend message" onclick="confirmResend(' + log.log_id + ')"><i class="fas fa-paper-plane"></i><span class="sr-only">Resend</span></button>' +
        '</td>' +
    '</tr>';
}

function renderLogPage(page) {
    if (page === undefined) page = 1;
    var tbody = document.getElementById('logTbody');
    var pag = document.getElementById('logPagination');
    var countEl = document.getElementById('logCount');
    if (!tbody) return;

    var q = (document.getElementById('logSearch')?.value || '').trim();
    var f = (document.getElementById('logTypeFilter')?.value || '');

    var url = "staff-communication.php?ajax_logs=1&page=" + page + "&per_page=" + logPerPage + "&search=" + encodeURIComponent(q) + "&type=" + encodeURIComponent(f) + "&sort=" + commSortCol + "&dir=" + commSortDir;
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var total = data.total || 0;
            var rowLogs = data.logs || [];

            if (total === 0) {
                tbody.innerHTML = '<tr class="sd-tr"><td class="sd-td" colspan="11" style="text-align:center;padding:2.5rem;color:#94a3b8;">No messages match your search.</td></tr>';
                if (pag) pag.style.display = 'none';
                return;
            }

            var tp = Math.ceil(total / logPerPage);
            var start = (page - 1) * logPerPage;

            tbody.innerHTML = rowLogs.map(logRowHtml).join('');

            document.querySelectorAll('.comm-card .th-sort').forEach(function(btn){
                var c = btn.getAttribute('data-col');
                btn.classList.toggle('th-sorted', c === commSortCol);
                var icon = btn.querySelector('.th-sort-icon');
                if (icon) {
                    if (c === commSortCol) {
                        icon.innerHTML = commSortDir === 'asc'
                            ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>'
                            : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
                    } else {
                        icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
                    }
                }
            });

            logPage = page;
            if (pag) {
                var firstEl = document.getElementById('logPageFirst');
                var prevEl = document.getElementById('logPagePrev');
                var nextEl = document.getElementById('logPageNext');
                var lastEl = document.getElementById('logPageLast');
                var labelEl = document.getElementById('logPageLabel');
                if (prevEl) prevEl.disabled = page <= 1;
                if (nextEl) nextEl.disabled = page >= tp;
                if (firstEl) firstEl.disabled = page <= 1;
                if (lastEl) lastEl.disabled = page >= tp;
                if (labelEl) labelEl.textContent = 'Page ' + page + ' of ' + tp;
                if (firstEl) firstEl.onclick = function(){ renderLogPage(1); };
                if (prevEl) prevEl.onclick = function(){ renderLogPage(page - 1); };
                if (nextEl) nextEl.onclick = function(){ renderLogPage(page + 1); };
                if (lastEl) lastEl.onclick = function(){ renderLogPage(tp); };
            }
        })
        .catch(function() {});
}

window.logPageGo = function(page) {
    renderLogPage(page);
};

window.commSort = function(col) {
    if (commSortCol === col) { commSortDir = commSortDir === 'asc' ? 'desc' : 'asc'; }
    else { commSortCol = col; commSortDir = 'asc'; }
    renderLogPage(1);
};

function setLogPerPage(v) {
    logPerPage = parseInt(v, 10);
    renderLogPage(1);
}

/* ── Resend confirm ── */
function confirmResend(id) {
    document.getElementById('resendLogId').value = id;
    openModal('resendModal');
}

function logDebouncedSearch() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { renderLogPage(1); }, 350);
}

if (document.getElementById('logTbody')) renderLogPage(1);

/* ── Toast notifications ── */
function showToast(title, opts) {
  opts = opts || {};
  var type = opts.type || 'success';
  var desc = opts.description || '';
  var duration = opts.duration != null ? opts.duration : 4000;
  var container = document.getElementById('toastContainer');
  var el = document.createElement('div');
  el.className = 'toast ' + type;
  var icons = {
    success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    info: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
  };
  var html = icons[type] || icons.success;
  html += '<div class="toast-body"><p class="toast-title">' + title + '</p>';
  if (desc) html += '<p class="toast-desc">' + desc + '</p>';
  html += '</div>';
  html += '<button class="toast-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
  el.innerHTML = html;
  container.appendChild(el);
  requestAnimationFrame(function() { requestAnimationFrame(function() { el.classList.add('show'); }); });
  el.querySelector('.toast-close').addEventListener('click', function() { dismissToast(el); });
  var timer = setTimeout(function() { dismissToast(el); }, duration);
  el._timer = timer;
  el.addEventListener('mouseenter', function() { clearTimeout(el._timer); });
  el.addEventListener('mouseleave', function() { el._timer = setTimeout(function() { dismissToast(el); }, 2000); });
}
function dismissToast(el) {
  if (el._dismissed) return;
  el._dismissed = true;
  clearTimeout(el._timer);
  el.classList.remove('show');
  el.classList.add('hiding');
  setTimeout(function() { el.remove(); }, 300);
}
</script>
</body>
</html>
