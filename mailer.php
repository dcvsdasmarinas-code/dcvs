<?php
/**
 * DCVS Email Notification System
 * Sends professional HTML emails to residents when staff updates appointments or reports.
 */

require_once 'email_config.php';

/**
 * Enqueue a DCVS email to the email_queue table for background delivery.
 * Returns the queue id on success, or null on failure.
 */
function enqueueDcvsEmail($conn, $recipientEmail, $recipientName, $subject, $htmlBody, $sentBy = null, $sentToRole = null, $messageType = null, $portalDelivered = false) {
    if (!$conn || $conn->connect_error) return null;
    $stmt = $conn->prepare("INSERT INTO email_queue (recipient_email, recipient_name, subject, body, status, attempts, sent_by, sent_to_role, message_type, portal_delivered) VALUES (?, ?, ?, ?, 'pending', 0, ?, ?, ?, ?)");
    if (!$stmt) return null;
    $stmt->bind_param("ssssissi", $recipientEmail, $recipientName, $subject, $htmlBody, $sentBy, $sentToRole, $messageType, $portalDelivered);
    $ok = $stmt->execute();
    $id = $ok ? $stmt->insert_id : null;
    $stmt->close();
    return $ok ? $id : null;
}

/**
 * Spawn the background email worker so queued emails get delivered
 * without blocking the web request.
 */
function spawnEmailWorker() {
    // PHP_BINARY under mod_php/Apache is httpd.exe, not the PHP CLI — only use
    // it if it actually looks like a PHP interpreter. Otherwise fall back to the
    // bundled CLI path.
    $candidates = [];
    if (defined('PHP_BINARY') && file_exists(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') !== false) {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = 'C:\\xampp\\php\\php.exe';
    foreach (['C:\\php\\php.exe', 'C:\\Program Files\\PHP\\php.exe'] as $alt) {
        if (file_exists($alt)) $candidates[] = $alt;
    }
    $php = null;
    foreach ($candidates as $cand) {
        if (file_exists($cand)) { $php = $cand; break; }
    }
    if (!$php) return false;

    $worker = __DIR__ . '\\email_worker.php';
    if (!file_exists($worker)) return false;
    // launch detached background process
    $cmd = 'start /B "" "' . $php . '" "' . $worker . '" > NUL 2>&1';
    $h = @popen($cmd, 'r');
    if ($h) { @pclose($h); return true; }
    return false;
}

/**
 * Send an email using the configured method (mail() or SMTP).
 */
function sendDcvsEmail($to, $subject, $htmlBody, $conn = null, $recipientName = '', $sentBy = null, $sentToRole = null, $refType = null, $refId = null, $messageType = null, $portalDelivered = false) {
    $from = MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $from\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "X-Mailer: DCVS Notification System\r\n";

    $success = false;
    $errorMsg = null;
    $errorDetail = null;

    if (USE_SMTP) {
        $smtpResult = sendSmtpMail($to, $subject, $htmlBody);
        if (is_array($smtpResult)) {
            $success = $smtpResult['success'];
            $errorDetail = $smtpResult['error'] ?? null;
        } else {
            $success = $smtpResult;
        }
    } else {
        $success = mail($to, $subject, $htmlBody, $headers);
        if (!$success) {
            $errorDetail = 'PHP mail() returned false';
            error_log("DCVS Mailer: Failed to send email to $to | Subject: $subject");
        }
    }

    if (!$success) {
        $errorMsg = $errorDetail ?: 'Email delivery failed';
    }

    // Log every sent email to the database
    if ($conn) {
        $status = $success ? 'sent' : 'failed';
        logEmail($conn, $to, $recipientName, $subject, $htmlBody, $status, $errorMsg, $sentBy, $sentToRole, $messageType, $portalDelivered, $errorDetail);
    }

    return $success;
}

/**
 * Send email via SMTP using PHPMailer.
 */
function sendSmtpMail($to, $subject, $htmlBody) {
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
    require_once __DIR__ . '/phpmailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPKeepAlive = false;
        $mail->Timeout    = 10;
        $mail->SMTPDebug  = 0;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['</p>','</div>','</td>','</tr>'], "\n", str_replace(['<br>','<br/>','<br />'], "\n", $htmlBody)));
        $mail->MessageID = '<' . time() . '.' . md5($to . $subject) . '@gmail.com>';
        $mail->addCustomHeader('X-Mailer', 'DCVS Notification System');

        $mail->send();
        error_log("DCVS Mailer: Email sent to $to | Subject: $subject");
        return ['success' => true];
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        error_log("DCVS Mailer: SMTP failed to $to — $errorMsg");
        return ['success' => false, 'error' => $errorMsg];
    }
}

/**
 * Build the professional DCVS email HTML template.
 */
function dcvsEmailTemplate($title, $contentBody, $opts = []) {
    $portalUrl = DCVS_PORTAL_URL;
    $year = date('Y');

    $badge   = isset($opts['badge']) ? (string)$opts['badge'] : '';
    $ctaLabel = isset($opts['cta_label']) ? (string)$opts['cta_label'] : '';
    $ctaUrl  = isset($opts['cta_url']) ? (string)$opts['cta_url'] : '';

    $badgeHtml = '';
    if ($badge !== '') {
        $badgeHtml = '<span style="display:inline-block;background:#e7f6ec;color:#0e8131;border:1px solid #cfeedd;border-radius:999px;padding:4px 12px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:10.5px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;vertical-align:middle;">'
                   . htmlspecialchars($badge) . '</span>';
    }

    $ctaHtml = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $ctaHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;"><tr><td>'
                 . '<a href="' . htmlspecialchars($ctaUrl) . '" style="display:inline-block;background:#0e8131;color:#ffffff;padding:12px 22px;border-radius:8px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:0.2px;text-decoration:none;">'
                 . htmlspecialchars($ctaLabel) . '</a></td></tr></table>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Inter,'Segoe UI','Helvetica Neue',Arial,sans-serif;">
<center>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f5f7;">
<tr><td align="center" style="padding:32px 12px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8eaed;">
  <tr><td style="padding:36px 44px 0;">
    <div style="font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#8a93ab;">Republic of the Philippines &nbsp;&middot;&nbsp; City Gov&rsquo;t of Dasmari&ntilde;as</div>
    <div style="margin-top:6px;font-family:Georgia,'Source Serif',serif;font-size:20px;font-weight:700;color:#0e8131;letter-spacing:0.3px;">City Veterinary Services</div>
  </td></tr>
  <tr><td style="padding:30px 44px 6px;">
    <div style="font-family:Georgia,'Source Serif',serif;font-size:22px;font-weight:600;color:#111827;line-height:1.3;">{$title} {$badgeHtml}</div>
  </td></tr>
  <tr><td style="padding:8px 44px 16px;font-size:15px;line-height:1.6;color:#1e293b;font-family:Inter,Helvetica,Arial,sans-serif;">
  {$contentBody}
  {$ctaHtml}
  </td></tr>
  <tr><td style="padding:16px 44px 34px;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="border-top:1px solid #eef1f6;font-size:0;line-height:0;">&nbsp;</td></tr></table>
    <div style="padding-top:14px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;color:#94a3b8;line-height:1.6;">
      City Veterinary Services &middot; Animal Welfare Management System &middot; <a href="{$portalUrl}" style="color:#0e8131;text-decoration:none;">{$portalUrl}</a>
    </div>
  </td></tr>
</table>
</td></tr>
</table>
</center>
</body>
</html>
HTML;
}

/**
 * Render a staff message as plain text with simple dividers (no alert box).
 */
function dcvsStaffMessage($message, $label = 'Message from Staff') {
    if (empty(trim($message))) return '';
    $escaped = htmlspecialchars($message);
    $withBreaks = nl2br($escaped);
    $labelHtml = $label !== '' ? '<div style="font-family:Inter,Helvetica,Arial,sans-serif;font-size:10.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8a93ab;margin-bottom:8px;">' . htmlspecialchars($label) . '</div>' : '';
    return '<div style="margin:20px 0;border-left:3px solid #0e8131;padding:2px 0 2px 20px;">'
         . $labelHtml
         . '<div style="font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#1e293b;">' . $withBreaks . '</div>'
         . '</div>';
}

/**
 * Build an info table for the email (key-value pairs).
 */
function dcvsInfoTable($rows) {
    if (empty($rows)) return '';
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:10px 0 2px;">';
    foreach ($rows as $row) {
        $html .= '<tr>'
               . '<td style="padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:12px;font-weight:600;color:#8a93ab;white-space:nowrap;width:40%;vertical-align:top;letter-spacing:0.2px;">' . htmlspecialchars($row[0]) . '</td>'
               . '<td style="padding:7px 0;font-family:Inter,Helvetica,Arial,sans-serif;font-size:13.5px;color:#1e293b;text-align:right;vertical-align:top;">' . htmlspecialchars($row[1]) . '</td>'
               . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

/**
 * Build a visual appointment summary card for emails.
 */
function dcvsAppointmentCard($petName, $serviceType, $date, $time, $status, $vetName = '') {
    $color = '#0E8131';
    $bg    = '#f0fdf4';
    if (stripos($status, 'cancel') !== false) { $color = '#dc2626'; $bg = '#fef2f2'; }
    elseif (stripos($status, 'reschedule') !== false) { $color = '#7c3aed'; $bg = '#f5f3ff'; }
    elseif (stripos($status, 'completed') !== false || stripos($status, 'resolved') !== false) { $color = '#475569'; $bg = '#f1f5f9'; }
    elseif (stripos($status, 'pending') !== false || stripos($status, 'submitted') !== false) { $color = '#d97706'; $bg = '#fef3c7'; }

    $html = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin:14px 0;">';

    // Header bar
    $html .= '<tr><td style="padding:10px 16px;background:' . $color . ';color:#fff;font-size:13px;font-weight:700;letter-spacing:0.3px;">APPOINTMENT SUMMARY</td></tr>';

    // Body rows
    $rows = [
        ['Pet Name', $petName],
        ['Service Type', $serviceType],
        ['Appointment Date', $date],
        ['Appointment Time', $time],
    ];
    if (!empty($vetName)) {
        $rows[] = ['Veterinarian', $vetName];
    }
    $rows[] = ['Status', $status];

    $i = 0;
    foreach ($rows as $row) {
        $rgb = ($i % 2 === 0) ? '#ffffff' : '#fafcfc';
        $html .= '<tr style="background:' . $rgb . ';">'
               . '<td style="padding:7px 16px;font-weight:600;color:#475569;border-top:1px solid #eef1f6;font-size:12px;width:40%;white-space:nowrap;">' . htmlspecialchars($row[0]) . '</td>'
               . '<td style="padding:7px 16px;color:#1e293b;border-top:1px solid #eef1f6;font-size:13px;">' . htmlspecialchars($row[1]) . '</td>'
               . '</tr>';
        $i++;
    }
    $html .= '</table>';
    return $html;
}

// ═══════════════════════════════════════════════
//  HIGH-LEVEL NOTIFICATION FUNCTIONS
// ═══════════════════════════════════════════════

/**
 * Send email notification for an appointment status change.
 * @param mysqli $conn Database connection
 * @param int    $appointmentId
 * @param string $newStatus   scheduled|completed|cancelled|rescheduled|no_show
 * @param string $staffMessage Optional staff message
 */
function sendAppointmentEmail($conn, $appointmentId, $newStatus, $staffMessage = '', $staffName = 'DCVS Staff') {
    // Fetch appointment + resident details
    $stmt = $conn->prepare("
        SELECT a.*, u.full_name, u.email, u.reference_id AS user_ref,
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

    if (!$data || empty($data['email'])) return;

    $residentName = $data['full_name'];
    $residentEmail = $data['email'];
    $apptRef = $data['reference_id'] ?? '#APT-' . str_pad($appointmentId, 4, '0', STR_PAD_LEFT);
    $userRef = $data['user_ref'] ?? '#RES-' . str_pad($data['user_id'], 4, '0', STR_PAD_LEFT);
    $petName = $data['pet_name'] ?? 'N/A';
    $typeLabels = ['vaccination'=>'Vaccination','checkup'=>'Checkup','kapon'=>'Kapon (Spay/Neuter)','adoption'=>'Adoption'];
    $apptType = $typeLabels[$data['appointment_type']] ?? ucfirst($data['appointment_type']);
    $dateFormatted = date('F j, Y', strtotime($data['preferred_date']));
    $timeFormatted = date('g:i A', strtotime($data['preferred_time']));

    // Status display
    $statusLabels = [
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'rescheduled' => 'Rescheduled',
        'no_show' => 'No Show',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];
    $statusDisplay = $statusLabels[$newStatus] ?? ucfirst($newStatus);

    // Friendly short status for subjects
    $shortStatus = '';
    if ($newStatus === 'scheduled' || $newStatus === 'approved') $shortStatus = 'Confirmed';
    elseif ($newStatus === 'completed') $shortStatus = 'Completed';
    elseif ($newStatus === 'cancelled') $shortStatus = 'Cancelled';
    elseif ($newStatus === 'rescheduled') $shortStatus = 'Rescheduled';
    elseif ($newStatus === 'no_show') $shortStatus = 'No Show';
    elseif ($newStatus === 'pending') $shortStatus = 'Submitted';
    elseif ($newStatus === 'rejected') $shortStatus = 'Rejected';
    else $shortStatus = $statusDisplay;

    // Short hero title
    $heroTitle = $shortStatus === 'Submitted' ? 'Appointment Request Submitted' : "Appointment {$shortStatus}";

    // Subject: action + service first, pet name, ref at end
    if ($petName && $petName !== 'N/A') {
        $subject = "{$apptType} Appointment {$shortStatus} — {$petName} ({$apptRef})";
    } else {
        $subject = "{$apptType} Appointment {$shortStatus} ({$apptRef})";
    }

    // Greeting
    $body = "<p style=\"font-size:15px;color:#1e293b;margin:0 0 8px;\">Dear {$residentName},</p>";

    // Hero section — short title + personalized summary
    if ($newStatus === 'scheduled' || $newStatus === 'approved') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Good news! Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been confirmed and scheduled.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'completed') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been completed. Thank you for trusting DCVS!</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'cancelled') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been cancelled.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'rescheduled') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been rescheduled. Please note the new date and time below.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'no_show') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been marked as No Show.</p>';
        $body .= '</div>';
    } else {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been updated by ' . $staffName . '.</p>';
        $body .= '</div>';
    }

    // Visual appointment summary card
    $body .= dcvsAppointmentCard($petName, $apptType, $dateFormatted, $timeFormatted, $statusDisplay);

    // Details table (refs, submission date, etc.)
    $infoRows = [
        ['Reference ID', $apptRef],
        ['Resident ID', $userRef],
        ['Submission Date', date('F j, Y', strtotime($data['created_at'] ?? $data['preferred_date']))],
    ];
    $body .= '<div style="margin:18px 0 0;">' . dcvsInfoTable($infoRows) . '</div>';

    // Conditional messaging
    if ($newStatus === 'cancelled' && !empty($data['notes'])) {
        $body .= '<div style="margin:14px 0;"><div style="border-top:1px solid #e2e8f0;margin-bottom:10px;"></div>'
               . '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#64748b;">Cancellation reason</p>'
               . '<p style="margin:0;font-size:14px;color:#1e293b;line-height:1.6;">' . nl2br(htmlspecialchars($data['notes'])) . '</p>'
               . '<div style="border-top:1px solid #e2e8f0;margin-top:10px;"></div></div>';
    }

    if ($newStatus === 'rescheduled') {
        $body .= '<p style="margin-top:12px;font-size:14px;color:#475569;">Please update your schedule accordingly. If you have any questions, feel free to contact DCVS.</p>';
    }

    if ($newStatus === 'completed') {
        $body .= '<p style="margin-top:12px;font-size:14px;color:#475569;">We hope everything went well. Please don\'t hesitate to reach out for follow-up care or future appointments.</p>';
    }

    if ($newStatus === 'scheduled' || $newStatus === 'approved') {
        $body .= '<p style="margin-top:12px;font-size:14px;color:#475569;">Please arrive on time at the DCVS office. Bring any necessary documents for your pet.</p>';
    }

    if (!empty($staffMessage)) {
        $body .= dcvsStaffMessage($staffMessage, 'Note from DCVS Staff');
    }

    $body .= '<p style="margin-top:16px;font-size:13px;color:#64748b;">If you have any questions, please contact your barangay office or DCVS directly.</p>';

    $html = dcvsEmailTemplate("Appointment {$shortStatus}", $body);
    return sendDcvsEmail($residentEmail, $subject, $html, $conn, $residentName, $data['user_id'], 'resident', 'appointments', $appointmentId);
}

/**
 * Send email notification for a report status change.
 * @param mysqli $conn Database connection
 * @param int    $reportId
 * @param string $newStatus   confirmed|scheduled|resolved|rejected
 * @param string $staffMessage Optional notes/reason
 */
function sendReportEmail($conn, $reportId, $newStatus, $staffMessage = '', $staffName = 'DCVS Staff') {
    // Fetch report + resident details
    $stmt = $conn->prepare("
        SELECT r.*, u.full_name, u.email, u.reference_id AS user_ref,
               b.barangay_name
        FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
        WHERE r.report_id = ?
    ");
    $stmt->bind_param("i", $reportId);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data || empty($data['email'])) return;

    $residentName = $data['full_name'];
    $residentEmail = $data['email'];
    $reportRef = $data['reference_id'] ?? '#RPT-' . str_pad($reportId, 4, '0', STR_PAD_LEFT);
    $userRef = $data['user_ref'] ?? '#RES-' . str_pad($data['reporter_id'], 4, '0', STR_PAD_LEFT);
    $reportTypeLabels = ['stray'=>'Stray Animal','rabies'=>'Rabies Case','injured'=>'Injured Animal','dead'=>'Dead Animal', 'abuse'=>'Animal Abuse'];
    $reportType = $reportTypeLabels[$data['report_type']] ?? ucfirst($data['report_type']);
    $location = $data['location_address'] ?? 'N/A';
    $barangay = $data['barangay_name'] ?? 'N/A';

    $statusLabels = [
        'confirmed' => 'Confirmed',
        'scheduled' => 'Scheduled for Action',
        'resolved' => 'Resolved',
        'rejected' => 'Not Approved',
        'validated' => 'Validated',
        'pending' => 'Pending'
    ];
    $statusDisplay = $statusLabels[$newStatus] ?? ucfirst($newStatus);

    // Short status for subjects
    $shortStatus = '';
    if ($newStatus === 'pending') $shortStatus = 'Submitted';
    elseif ($newStatus === 'validated') $shortStatus = 'Validated';
    elseif ($newStatus === 'confirmed') $shortStatus = 'Confirmed';
    elseif ($newStatus === 'scheduled') $shortStatus = 'Scheduled';
    elseif ($newStatus === 'resolved') $shortStatus = 'Resolved';
    elseif ($newStatus === 'rejected') $shortStatus = 'Not Approved';
    else $shortStatus = $statusDisplay;

    // Subject: action + report type first, ref at end
    if ($newStatus === 'pending') {
        $subject = "{$reportType} Report Submitted ({$reportRef})";
    } elseif ($newStatus === 'rejected') {
        $subject = "{$reportType} Report — Not Approved ({$reportRef})";
    } else {
        $subject = "{$reportType} Report {$shortStatus} ({$reportRef})";
    }

    // Greeting
    $body = "<p style=\"font-size:15px;color:#1e293b;margin:0 0 8px;\">Dear {$residentName},</p>";

    // Hero section
    if ($newStatus === 'pending') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $reportType . '</strong> report has been successfully received and is now awaiting Barangay validation. Our team will review it promptly.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'validated') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $reportType . '</strong> report has been validated by your Barangay and forwarded to DCVS for review.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'confirmed') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $reportType . '</strong> report has been officially confirmed by DCVS. Our team will take the necessary action.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'scheduled') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $reportType . '</strong> report has been scheduled for an intervention.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'resolved') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $reportType . '</strong> report has been resolved by DCVS. Thank you for your patience.</p>';
        $body .= '</div>';
    } elseif ($newStatus === 'rejected') {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">After review, your <strong>' . $reportType . '</strong> report was not approved by DCVS.</p>';
        $body .= '</div>';
    } else {
        $body .= '<div style="margin-bottom:14px;">';
        $body .= '<p style="margin:0;font-size:14px;color:#475569;">Your <strong>' . $reportType . '</strong> report has been updated by ' . $staffName . '.</p>';
        $body .= '</div>';
    }

    // Details table
    $infoRows = [
        ['Reference ID', $reportRef],
        ['Resident ID', $userRef],
        ['Report Category', $reportType],
        ['Location', $location],
        ['Barangay', $barangay],
        ['Current Status', $statusDisplay],
        ['Submission Date', date('F j, Y', strtotime($data['created_at']))],
    ];

    if ($newStatus === 'scheduled' && !empty($data['scheduled_at'])) {
        $schedDate = date('F j, Y \a\t g:i A', strtotime($data['scheduled_at']));
        $infoRows[] = ['Scheduled Action', $schedDate];
    }

    $body .= '<div style="margin:18px 0 0;">' . dcvsInfoTable($infoRows) . '</div>';

    if ($newStatus === 'rejected') {
        $reason = $data['rejection_reason'] ?? $staffMessage;
        if (!empty($reason)) {
            $body .= '<div style="margin:14px 0;"><div style="border-top:1px solid #e2e8f0;margin-bottom:10px;"></div>'
                   . '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#64748b;">Reason</p>'
                   . '<p style="margin:0;font-size:14px;color:#1e293b;line-height:1.6;">' . nl2br(htmlspecialchars($reason)) . '</p>'
                   . '<div style="border-top:1px solid #e2e8f0;margin-top:10px;"></div></div>';
        }
    }

    $noteContent = '';
    if (!empty($data['dcvs_notes'])) {
        $noteContent = $data['dcvs_notes'];
    }
    if (!empty($staffMessage) && $staffMessage !== $noteContent) {
        $noteContent = $noteContent ? $noteContent . "\n\n" . $staffMessage : $staffMessage;
    }
    if (!empty($noteContent)) {
        $body .= dcvsStaffMessage($noteContent, 'Remarks from DCVS');
    }

    $body .= '<p style="margin-top:16px;font-size:13px;color:#64748b;">If you have any questions, please contact your barangay office or DCVS directly.</p>';

    $html = dcvsEmailTemplate("Report {$shortStatus}", $body);
    return sendDcvsEmail($residentEmail, $subject, $html, $conn, $residentName, $data['reporter_id'], 'resident', 'report', $reportId);
}

/**
 * Send email for adoption completion from mark_adopted.
 */
function sendAdoptionCompleteEmail($conn, $appointmentId, $listingId, $staffName = 'DCVS Staff') {
    $stmt = $conn->prepare("
        SELECT a.*, u.full_name, u.email, u.reference_id AS user_ref,
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

    if (!$data || empty($data['email'])) return;

    $apptRef = $data['reference_id'] ?? '#APT-' . str_pad($appointmentId, 4, '0', STR_PAD_LEFT);
    $petName = $data['pet_name'] ?? 'Pet';

    $subject = "Adoption Complete — Welcome Home, {$petName}! ({$apptRef})";

    $body = "<p style=\"font-size:15px;color:#1e293b;margin:0 0 8px;\">Dear {$data['full_name']},</p>";

    $body .= '<div style="margin-bottom:14px;">';
    $body .= '<p style="margin:0;font-size:14px;color:#475569;">Great news! The adoption for <strong>' . $petName . '</strong> has been finalized by ' . $staffName . '. <strong>' . $petName . '</strong> is now officially part of your family!</p>';
    $body .= '</div>';

    $body .= '<div style="text-align:center;padding:12px 0;"><span style="font-size:48px;">&#x1f436;</span></div>';
    $body .= '<p style="font-size:14px;color:#475569;">Thank you for opening your home to a rescued pet. We are thrilled for you and your new companion!</p>';

    $infoRows = [
        ['Reference ID', $apptRef],
        ['Pet Name', $petName],
        ['Service Type', 'Adoption'],
        ['Current Status', 'Completed'],
        ['Completion Date', date('F j, Y')],
    ];
    $body .= '<div style="margin:18px 0 0;">' . dcvsInfoTable($infoRows) . '</div>';

    $body .= '<p style="font-size:14px;color:#475569;">Please remember to schedule follow-up vaccinations and regular checkups at your nearest DCVS clinic to keep your newest family member healthy.</p>';
    $body .= '<p style="font-size:14px;color:#475569;">Welcome to the DCVS family!</p>';

    $html = dcvsEmailTemplate("Adoption Complete — {$petName}", $body);
    return sendDcvsEmail($data['email'], $subject, $html, $conn, $data['full_name'], $data['user_id'], 'resident', 'adoption', $listingId);
}
