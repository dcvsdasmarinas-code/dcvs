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

    $worker = __DIR__ . '\\workers\\email_worker.php';
    if (!file_exists($worker)) return false;
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
 * Build a plain, natural email HTML template.
 * Clean white/transparent background, normal typography, responsive.
 */
function dcvsEmailTemplate($title, $contentBody, $opts = []) {
    $portalUrl = DCVS_PORTAL_URL;
    $year = date('Y');

    $badge   = isset($opts['badge']) ? (string)$opts['badge'] : '';
    $ctaLabel = isset($opts['cta_label']) ? (string)$opts['cta_label'] : '';
    $ctaUrl  = isset($opts['cta_url']) ? (string)$opts['cta_url'] : '';

    $badgeHtml = '';
    if ($badge !== '') {
        $badgeHtml = ' <span style="display:inline-block;background:#f0fdf4;color:#0e8131;border:1px solid #cfeedd;border-radius:4px;padding:2px 8px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:11px;font-weight:600;vertical-align:middle;">'
                   . htmlspecialchars($badge) . '</span>';
    }

    $ctaHtml = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $ctaHtml = '<p style="margin:20px 0 0;"><a href="' . htmlspecialchars($ctaUrl) . '" style="display:inline-block;background:#18181b;color:#fafafa;padding:10px 20px;border-radius:6px;font-family:Inter,Helvetica,Arial,sans-serif;font-size:14px;font-weight:500;text-decoration:none;">'
                 . htmlspecialchars($ctaLabel) . '</a></p>';
    }

    $titleHtml = $title !== ''
        ? '<p style="margin:0 0 20px;font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-0.02em;line-height:1.3;">' . $title . $badgeHtml . '</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Inter','Segoe UI',Helvetica,Arial,sans-serif;color:#0f172a;font-size:16px;line-height:1.6;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#ffffff;">
<tr><td style="padding:28px 24px 20px;">
    {$titleHtml}
  {$contentBody}
  {$ctaHtml}
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Render a staff message as a plain text block with a left border accent.
 */
function dcvsStaffMessage($message, $label = 'Message from Staff') {
    if (empty(trim($message))) return '';
    $escaped = htmlspecialchars($message);
    $withBreaks = nl2br($escaped);
    $labelHtml = $label !== '' ? '<p style="margin:0 0 6px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">' . htmlspecialchars($label) . '</p>' : '';
    return '<div style="margin:20px 0;padding:16px 18px;border-left:3px solid #0e8131;">'
         . $labelHtml
         . '<div style="font-family:\'Inter\',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#0f172a;">' . $withBreaks . '</div>'
         . '</div>';
}

/**
 * Build a plain key-value details section for emails.
 */
function dcvsInfoTable($rows, $labelColor = '#475569', $showDivider = true, $valueColor = null) {
    if (empty($rows)) return '';
    $labelTextColor = $labelColor;
    $valueTextColor = $valueColor ?? '#0f172a';
    $html = '<div style="margin:16px 0;padding:12px 0;' . ($showDivider ? 'border-top:1px solid #e2e8f0;' : '') . '">';
    foreach ($rows as $row) {
        $html .= '<p style="margin:0 0 6px;font-size:16px;line-height:1.6;"><span style="font-weight:600;color:' . $labelTextColor . ';">' . htmlspecialchars($row[0]) . ':</span> <span style="color:' . $valueTextColor . ';">' . htmlspecialchars($row[1]) . '</span></p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Build a plain appointment summary section.
 */
function dcvsAppointmentCard($petName, $serviceType, $date, $time, $status, $vetName = '') {
    $font = "font-family:'Inter','Segoe UI',Helvetica,Arial,sans-serif;";
    $white = 'color:#0f172a;';
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:20px 0;border:0;border-collapse:collapse;">';
    $html .= '<tr><td colspan="2" style="padding:0 0 10px;font-size:12px;font-weight:600;' . $white . 'text-transform:uppercase;letter-spacing:0.5px;' . $font . '">Appointment Summary</td></tr>';
    $html .= '<tr><td style="padding:0 16px 4px 0;font-size:16px;line-height:1.6;font-weight:600;' . $white . $font . '">Pet:</td><td style="padding:0 0 4px;font-size:16px;line-height:1.6;' . $white . $font . '">' . htmlspecialchars($petName) . '</td></tr>';
    $html .= '<tr><td style="padding:0 16px 4px 0;font-size:16px;line-height:1.6;font-weight:600;' . $white . $font . '">Service:</td><td style="padding:0 0 4px;font-size:16px;line-height:1.6;' . $white . $font . '">' . htmlspecialchars($serviceType) . '</td></tr>';
    $html .= '<tr><td style="padding:0 16px 4px 0;font-size:16px;line-height:1.6;font-weight:600;' . $white . $font . '">Date:</td><td style="padding:0 0 4px;font-size:16px;line-height:1.6;' . $white . $font . '">' . htmlspecialchars($date) . '</td></tr>';
    $html .= '<tr><td style="padding:0 16px 4px 0;font-size:16px;line-height:1.6;font-weight:600;' . $white . $font . '">Time:</td><td style="padding:0 0 4px;font-size:16px;line-height:1.6;' . $white . $font . '">' . htmlspecialchars($time) . '</td></tr>';
    if (!empty($vetName)) {
        $html .= '<tr><td style="padding:0 16px 4px 0;font-size:16px;line-height:1.6;font-weight:600;' . $white . $font . '">Veterinarian:</td><td style="padding:0 0 4px;font-size:16px;line-height:1.6;' . $white . $font . '">' . htmlspecialchars($vetName) . '</td></tr>';
    }
    $html .= '<tr><td style="padding:0 16px 0 0;font-size:16px;line-height:1.6;font-weight:600;' . $white . $font . '">Status:</td><td style="padding:0;font-size:16px;line-height:1.6;' . $white . $font . '">' . htmlspecialchars($status) . '</td></tr>';
    $html .= '</table>';
    return $html;
}

// ═══════════════════════════════════════════════
//  HIGH-LEVEL NOTIFICATION FUNCTIONS
// ═══════════════════════════════════════════════

/**
 * Send email notification for an appointment status change.
 */
function sendAppointmentEmail($conn, $appointmentId, $newStatus, $staffMessage = '', $staffName = 'DCVS Staff') {
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
    $titleType = $data['appointment_type'] === 'kapon' ? 'Kapon' : $apptType;
    $appointmentDate = $data['scheduled_date'] ?: $data['preferred_date'];
    $appointmentTime = $data['scheduled_time'] ?: $data['preferred_time'];
    $dateFormatted = date('F j, Y', strtotime($appointmentDate));
    $timeFormatted = date('g:i A', strtotime($appointmentTime));

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

    $shortStatus = '';
    if ($newStatus === 'scheduled' || $newStatus === 'approved') $shortStatus = 'Confirmed';
    elseif ($newStatus === 'completed') $shortStatus = 'Completed';
    elseif ($newStatus === 'cancelled') $shortStatus = 'Cancelled';
    elseif ($newStatus === 'rescheduled') $shortStatus = 'Rescheduled';
    elseif ($newStatus === 'no_show') $shortStatus = 'No Show';
    elseif ($newStatus === 'pending') $shortStatus = 'Submitted';
    elseif ($newStatus === 'rejected') $shortStatus = 'Rejected';
    else $shortStatus = $statusDisplay;

    $heroTitle = $shortStatus === 'Submitted' ? 'Appointment Request Submitted' : "Appointment {$shortStatus}";

    if (($newStatus === 'scheduled' || $newStatus === 'approved') && $petName && $petName !== 'N/A') {
        $subject = "{$titleType} Appointment Scheduled for {$petName}";
    } elseif ($petName && $petName !== 'N/A') {
        $subject = "{$apptType} Appointment {$shortStatus} for {$petName}";
    } else {
        $subject = "{$apptType} Appointment {$shortStatus} ({$apptRef})";
    }

    // Greeting
    $body = '<p style="margin:0 0 16px;">Dear ' . htmlspecialchars($residentName) . ',</p>';

    // Summary paragraph
    if ($newStatus === 'scheduled' || $newStatus === 'approved') {
        $body .= '<p style="margin:0 0 16px;">Good news! Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been confirmed and scheduled.</p>';
    } elseif ($newStatus === 'completed') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been completed. Thank you for trusting DCVS!</p>';
    } elseif ($newStatus === 'cancelled') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been cancelled.</p>';
    } elseif ($newStatus === 'rescheduled') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been rescheduled. Please note the new date and time below.</p>';
    } elseif ($newStatus === 'no_show') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been marked as No Show.</p>';
    } else {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $apptType . '</strong> appointment for <strong>' . $petName . '</strong> has been updated by ' . $staffName . '.</p>';
    }

    // Appointment details
    $body .= dcvsAppointmentCard($petName, $apptType, $dateFormatted, $timeFormatted, $statusDisplay);

    // Reference info
    $infoRows = [
        ['Reference ID', $apptRef],
        ['Resident ID', $userRef],
        ['Submission Date', date('F j, Y', strtotime($data['created_at'] ?? $data['preferred_date']))],
    ];
    $body .= dcvsInfoTable($infoRows);

    // Conditional messaging
    if ($newStatus === 'cancelled' && !empty($data['notes'])) {
        $body .= '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Cancellation Reason</p>';
        $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0f172a;">' . nl2br(htmlspecialchars($data['notes'])) . '</p>';
    }

    if ($newStatus === 'rescheduled') {
        $body .= '<p style="margin:0 0 16px;">Please update your schedule accordingly. If you have any questions, feel free to contact DCVS.</p>';
    }

    if ($newStatus === 'completed') {
        $body .= '<p style="margin:0 0 16px;">We hope everything went well. Please don\'t hesitate to reach out for follow-up care or future appointments.</p>';
    }

    if ($newStatus === 'scheduled' || $newStatus === 'approved') {
        $body .= '<p style="margin:0 0 16px;">Please arrive on time at the DCVS office. Bring any necessary documents for your pet.</p>';
    }

    if (!empty($staffMessage)) {
        $body .= dcvsStaffMessage($staffMessage, 'Note from DCVS Staff');
    }

    $body .= '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">If you have any questions, please contact your barangay office or DCVS directly.</p>';

    $html = dcvsEmailTemplate('', $body);
    $queueId = enqueueDcvsEmail($conn, $residentEmail, $residentName, $subject, $html, $data['user_id'], 'resident', 'appointment');
    if ($queueId === null) return false;
    spawnEmailWorker();
    return true;
}

/**
 * Send email notification for a report status change.
 */
function sendReportEmail($conn, $reportId, $newStatus, $staffMessage = '', $staffName = 'DCVS Staff') {
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
    $noteContent = '';
    if ($newStatus === 'resolved') {
        $noteContent = trim($staffMessage);
    } else {
        if (!empty($data['dcvs_notes'])) {
            $noteContent = $data['dcvs_notes'];
        }
        if (!empty($staffMessage) && $staffMessage !== $noteContent) {
            $noteContent = $noteContent ? $noteContent . "\n\n" . $staffMessage : $staffMessage;
        }
    }

    $statusLabels = [
        'confirmed' => 'Confirmed',
        'scheduled' => 'Scheduled for Action',
        'resolved' => 'Resolved',
        'rejected' => 'Not Approved',
        'validated' => 'Validated',
        'pending' => 'Pending'
    ];
    $statusDisplay = $statusLabels[$newStatus] ?? ucfirst($newStatus);

    $shortStatus = '';
    if ($newStatus === 'pending') $shortStatus = 'Submitted';
    elseif ($newStatus === 'validated') $shortStatus = 'Validated';
    elseif ($newStatus === 'confirmed') $shortStatus = 'Confirmed';
    elseif ($newStatus === 'scheduled') $shortStatus = 'Scheduled';
    elseif ($newStatus === 'resolved') $shortStatus = 'Resolved';
    elseif ($newStatus === 'rejected') $shortStatus = 'Not Approved';
    else $shortStatus = $statusDisplay;

    if ($newStatus === 'pending') {
        $subject = "{$reportType} Report Submitted ({$reportRef})";
    } elseif ($newStatus === 'rejected') {
        $subject = "{$reportType} Report — Not Approved ({$reportRef})";
    } elseif ($newStatus === 'resolved') {
        $subject = "{$reportType} Report Resolved";
    } elseif ($newStatus === 'validated') {
        $subject = "{$reportType} Report Validated";
    } elseif ($newStatus === 'confirmed') {
        $subject = "{$reportType} Report Confirmed";
    } else {
        $subject = "{$reportType} Report {$shortStatus} ({$reportRef})";
    }

    // Greeting
    $body = "<p style=\"margin:0 0 16px;\">Dear {$residentName},</p>";

    // Summary paragraph
    if ($newStatus === 'pending') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $reportType . '</strong> report has been successfully received and is now awaiting Barangay validation. Our team will review it promptly.</p>';
    } elseif ($newStatus === 'validated') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $reportType . '</strong> report has been validated by your Barangay and forwarded to DCVS for review.';
        if (!empty($noteContent)) {
            $body .= ' ' . nl2br(htmlspecialchars($noteContent));
        }
        $body .= '</p>';
    } elseif ($newStatus === 'confirmed') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $reportType . '</strong> report has been officially confirmed by DCVS. Our team will take the necessary action.';
        if (!empty($noteContent)) {
            $body .= ' ' . nl2br(htmlspecialchars($noteContent));
        }
        $body .= '</p>';
    } elseif ($newStatus === 'scheduled') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $reportType . '</strong> report has been scheduled for an intervention.</p>';
    } elseif ($newStatus === 'resolved') {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $reportType . '</strong> report has been resolved by DCVS. Thank you for your patience.';
        if (!empty($noteContent)) {
            $body .= ' ' . nl2br(htmlspecialchars($noteContent));
        }
        $body .= '</p>';
    } elseif ($newStatus === 'rejected') {
        $body .= '<p style="margin:0 0 16px;">After review, your <strong>' . $reportType . '</strong> report was not approved by DCVS.</p>';
    } else {
        $body .= '<p style="margin:0 0 16px;">Your <strong>' . $reportType . '</strong> report has been updated by ' . $staffName . '.</p>';
    }

    // Details
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

    $emailUsesThemeText = in_array($newStatus, ['resolved', 'validated', 'confirmed'], true);
    $body .= dcvsInfoTable(
        $infoRows,
        $emailUsesThemeText ? 'inherit' : '#475569',
        !$emailUsesThemeText,
        $emailUsesThemeText ? 'inherit' : null
    );

    if ($newStatus === 'rejected') {
        $reason = $data['rejection_reason'] ?? $staffMessage;
        if (!empty($reason)) {
            $body .= '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Reason</p>';
            $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#0f172a;">' . nl2br(htmlspecialchars($reason)) . '</p>';
        }
    }

    if (!empty($noteContent) && !in_array($newStatus, ['resolved', 'validated', 'confirmed'], true)) {
        $body .= dcvsStaffMessage($noteContent, 'Remarks from DCVS');
    }

    $body .= '<p style="margin:0 0 8px;font-size:13px;color:#64748b;">If you have any questions, please contact your barangay office or DCVS directly.</p>';

    $html = dcvsEmailTemplate("Report {$shortStatus}", $body);
    $queueId = enqueueDcvsEmail($conn, $residentEmail, $residentName, $subject, $html, $data['reporter_id'], 'resident', 'report');
    if ($queueId === null) return false;
    spawnEmailWorker();
    return true;
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

    $body = "<p style=\"margin:0 0 16px;\">Dear {$data['full_name']},</p>";

    $body .= '<p style="margin:0 0 16px;">Great news! The adoption for <strong>' . $petName . '</strong> has been finalized by ' . $staffName . '. <strong>' . $petName . '</strong> is now officially part of your family!</p>';

    $infoRows = [
        ['Reference ID', $apptRef],
        ['Pet Name', $petName],
        ['Service Type', 'Adoption'],
        ['Current Status', 'Completed'],
        ['Completion Date', date('F j, Y')],
    ];
    $body .= dcvsInfoTable($infoRows);

    $body .= '<p style="margin:0 0 16px;">Please remember to schedule follow-up vaccinations and regular checkups at your nearest DCVS clinic to keep your newest family member healthy.</p>';
    $body .= '<p style="margin:0 0 16px;">Welcome to the DCVS family!</p>';

    $html = dcvsEmailTemplate("Adoption Complete — {$petName}", $body);
    return sendDcvsEmail($data['email'], $subject, $html, $conn, $data['full_name'], $data['user_id'], 'resident', 'adoption', $listingId);
}
