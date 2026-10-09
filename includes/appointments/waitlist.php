<?php
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../sms-sender.php';

const DCVS_WAITLIST_CLAIM_HOURS = 24;

function dcvsNotifyWaitlistForDateRange($conn, $startDate, $endDate) {
    $today = date('Y-m-d');
    $startDate = max((string)$startDate, $today);
    if ($startDate > $endDate) return;

    $stmt = $conn->prepare("SELECT DISTINCT preferred_date, preferred_time, appointment_type, urgency_level FROM appointment_waitlist
        WHERE preferred_date BETWEEN ? AND ? AND status = 'waiting'");
    if (!$stmt) {
        error_log('Failed to prepare waitlist date-range lookup: ' . $conn->error);
        return;
    }
    $stmt->bind_param('ss', $startDate, $endDate);
    if (!$stmt->execute()) {
        error_log('Failed to query waitlist dates: ' . $stmt->error);
        $stmt->close();
        return;
    }
    $slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($slots as $slot) {
        $times = $slot['preferred_time'] === null
            ? DCVS_STANDARD_TIMES
            : [substr($slot['preferred_time'], 0, 5)];
        foreach ($times as $time) {
            if (dcvsNotifyWaitlistForSlot($conn, $slot['preferred_date'], $time, $slot['appointment_type'], $slot['urgency_level'])) break;
        }
    }
}

function dcvsWaitlistSendNotification($conn, $entry, $date, $time) {
    $name = $entry['full_name'] ?: 'Resident';
    $formattedDate = date('F j, Y', strtotime($date));
    $formattedTime = date('g:i A', strtotime($date . ' ' . $time));
    $claimUntil = date('F j, Y g:i A', strtotime($entry['claim_expires_at']));
    $message = 'An appointment slot for ' . ucfirst($entry['appointment_type']) . ' opened on ' . $formattedDate . ' at ' . $formattedTime . '. Please sign in and book within 24 hours (by ' . $claimUntil . '). The slot is not reserved until your request is submitted.';
    createNotification($conn, (int)$entry['user_id'], 'appointment_update', 'Appointment Slot Opened', $message, 'waitlist', (int)$entry['waitlist_id']);

    if (!empty($entry['email']) && filter_var($entry['email'], FILTER_VALIDATE_EMAIL)) {
        $html = dcvsEmailTemplate('Appointment slot opened', '<p style="margin:0 0 16px;">' . htmlspecialchars($message) . '</p>');
        if (enqueueDcvsEmail($conn, $entry['email'], $name, 'DCVS appointment slot opened', $html, null, 'resident', 'waitlist') === null) {
            error_log('Failed to queue waitlist email for user ' . (int)$entry['user_id']);
        }
    }
    if (!empty($entry['phone']) && isSmsEnabled($conn)) {
        if (!queueSms($conn, $entry['phone'], $name, 'DCVS: ' . $message, null, 'resident', 'waitlist')) {
            error_log('Failed to queue waitlist SMS for user ' . (int)$entry['user_id']);
        }
    }
}

/** Notify the oldest matching resident when a slot is available. A notice never reserves the slot. */
function dcvsNotifyWaitlistForSlot($conn, $date, $time, $service = null, $urgency = 'routine', $targetWaitlistId = 0) {
    $time = substr((string)$time, 0, 5);
    if ($date < date('Y-m-d')
        || !in_array($time, DCVS_STANDARD_TIMES, true)
        || !in_array($urgency, ['routine', 'urgent'], true)) return false;

    $slotAvailable = $urgency === 'urgent'
        ? dcvsUrgentSlotAvailable($conn, $date, $time)
        : dcvsClinicSlotAvailable($conn, $date, $time) && dcvsRoutineSlotCapacity($conn, $date, $time) > 0;
    $dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_APPOINTMENTS_PER_DAY;
    $slotLimit = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, $time);
    if (!$slotAvailable
        || dcvsAppointmentDayCount($conn, $date, 0, $urgency) >= $dailyLimit
        || dcvsAppointmentSlotCount($conn, $date, $time, 0, $urgency) >= $slotLimit) {
        return false;
    }

    $conn->begin_transaction();
    try {
        $claimStmt = $conn->prepare("SELECT waitlist_id, user_id, appointment_type, urgency_level
            FROM appointment_waitlist
            WHERE preferred_date = ? AND urgency_level = ?
              AND (preferred_time IS NULL OR preferred_time = ?)
              AND status = 'notified' AND claim_expires_at > NOW()
            LIMIT 1 FOR UPDATE");
        if (!$claimStmt) throw new RuntimeException('Could not check current waitlist claims: ' . $conn->error);
        $fullTime = $time . ':00';
        $claimStmt->bind_param('sss', $date, $urgency, $fullTime);
        if (!$claimStmt->execute()) throw new RuntimeException('Could not query current waitlist claims: ' . $claimStmt->error);
        $existingClaim = $claimStmt->get_result()->fetch_assoc();
        $claimStmt->close();
        if ($existingClaim) {
            $conn->commit();
            return false;
        }

        $sql = "SELECT w.waitlist_id, w.user_id, w.appointment_type, w.urgency_level, u.full_name, u.email, u.phone
            FROM appointment_waitlist w
            JOIN users u ON u.user_id = w.user_id
            WHERE w.preferred_date = ? AND w.urgency_level = ?
              AND (w.preferred_time IS NULL OR w.preferred_time = ?)
              AND w.status = 'waiting'";
        if ($service !== null) $sql .= " AND w.appointment_type = ?";
        if ($targetWaitlistId > 0) $sql .= " AND w.waitlist_id = ?";
        $sql .= " ORDER BY w.created_at, w.waitlist_id LIMIT 1 FOR UPDATE";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Could not prepare waitlist lookup: ' . $conn->error);
        if ($service !== null && $targetWaitlistId > 0) $stmt->bind_param('ssssi', $date, $urgency, $fullTime, $service, $targetWaitlistId);
        elseif ($service !== null) $stmt->bind_param('ssss', $date, $urgency, $fullTime, $service);
        elseif ($targetWaitlistId > 0) $stmt->bind_param('sssi', $date, $urgency, $fullTime, $targetWaitlistId);
        else $stmt->bind_param('sss', $date, $urgency, $fullTime);
        if (!$stmt->execute()) throw new RuntimeException('Could not query appointment waitlist: ' . $stmt->error);
        $entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$entry) {
            $conn->commit();
            return false;
        }

        $update = $conn->prepare("UPDATE appointment_waitlist SET status = 'notified', notified_at = NOW(),
            claim_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), preferred_time = ?
            WHERE waitlist_id = ? AND status = 'waiting'");
        if (!$update) throw new RuntimeException('Could not prepare waitlist claim: ' . $conn->error);
        $update->bind_param('si', $fullTime, $entry['waitlist_id']);
        if (!$update->execute() || $update->affected_rows !== 1) {
            throw new RuntimeException('Could not claim waitlist entry ' . (int)$entry['waitlist_id'] . ': ' . $update->error);
        }
        $update->close();
        $conn->commit();

        $entry['claim_expires_at'] = date('Y-m-d H:i:s', time() + DCVS_WAITLIST_CLAIM_HOURS * 3600);
        dcvsWaitlistSendNotification($conn, $entry, $date, $time);
        return true;
    } catch (Throwable $error) {
        $conn->rollback();
        error_log('Waitlist notification failed for ' . $date . ' ' . $time . ': ' . $error->getMessage());
        return false;
    }
}

/** Expire overdue offers, then offer still-open slots to the next resident in FIFO order. */
function dcvsExpireWaitlistClaims($conn, $userId = null) {
    $sql = "SELECT waitlist_id, preferred_date, preferred_time, appointment_type, urgency_level
        FROM appointment_waitlist WHERE status = 'notified' AND claim_expires_at <= NOW()";
    if ($userId !== null) $sql .= " AND user_id = ?";
    $sql .= " ORDER BY claim_expires_at";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare waitlist expiry query: ' . $conn->error);
        return false;
    }
    if ($userId !== null) $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        error_log('Failed to query expired waitlist claims: ' . $stmt->error);
        $stmt->close();
        return false;
    }
    $expired = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$expired) return true;

    $ids = array_map('intval', array_column($expired, 'waitlist_id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $update = $conn->prepare("UPDATE appointment_waitlist SET status = 'expired'
        WHERE status = 'notified' AND claim_expires_at <= NOW() AND waitlist_id IN ($placeholders)");
    if (!$update) {
        error_log('Failed to prepare expired waitlist update: ' . $conn->error);
        return false;
    }
    $types = str_repeat('i', count($ids));
    $update->bind_param($types, ...$ids);
    if (!$update->execute()) {
        error_log('Failed to expire waitlist claims: ' . $update->error);
        $update->close();
        return false;
    }
    $update->close();

    foreach ($expired as $entry) {
        $times = $entry['preferred_time'] ? [substr($entry['preferred_time'], 0, 5)] : DCVS_STANDARD_TIMES;
        foreach ($times as $time) {
            if (dcvsNotifyWaitlistForSlot($conn, $entry['preferred_date'], $time, $entry['appointment_type'], $entry['urgency_level'])) break;
        }
    }
    return true;
}

function dcvsMarkWaitlistBooked($conn, $userId, $appointmentId, $service, $urgency, $date, $time, $petId = null, $waitlistId = null) {
    $sql = "UPDATE appointment_waitlist SET status = 'booked', booked_appointment_id = ?, claim_expires_at = NULL
        WHERE user_id = ? AND appointment_type = ? AND urgency_level = ? AND preferred_date = ?
          AND status IN ('waiting','notified') AND (preferred_time IS NULL OR preferred_time = ?)";
    if ($petId !== null) $sql .= " AND pet_id = ?";
    if ($waitlistId !== null) $sql .= " AND waitlist_id = ?";
    $sql .= " ORDER BY created_at ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Failed to prepare waitlist booking update: ' . $conn->error);
        return false;
    }
    $fullTime = substr($time, 0, 5) . ':00';
    if ($petId !== null && $waitlistId !== null) $stmt->bind_param('iissssii', $appointmentId, $userId, $service, $urgency, $date, $fullTime, $petId, $waitlistId);
    elseif ($petId !== null) $stmt->bind_param('iissssi', $appointmentId, $userId, $service, $urgency, $date, $fullTime, $petId);
    elseif ($waitlistId !== null) $stmt->bind_param('iissssi', $appointmentId, $userId, $service, $urgency, $date, $fullTime, $waitlistId);
    else $stmt->bind_param('iissss', $appointmentId, $userId, $service, $urgency, $date, $fullTime);
    $ok = $stmt->execute();
    if (!$ok) error_log('Failed to mark waitlist entry booked: ' . $stmt->error);
    $stmt->close();
    return $ok;
}
