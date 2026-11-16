<?php
require_once __DIR__ . '/appointments/availability.php';
require_once __DIR__ . '/waitlist.php';

function dcvsExpireAppointmentRescheduleOffers($conn) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT appointment_id, user_id, appointment_type, urgency_level, scheduled_date, scheduled_time
            FROM appointments
            WHERE status = 'action_needed' AND reschedule_response_deadline <= NOW() AND archived_at IS NULL
            FOR UPDATE");
        if (!$stmt || !$stmt->execute()) {
            throw new RuntimeException('Could not load expired appointment reschedule offers.');
        }
        $expired = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($expired) {
            $update = $conn->prepare("UPDATE appointments
                SET status = 'cancelled', cancelled_by = 'staff',
                    cancellation_reason = 'Reschedule offer expired without resident response.',
                    reschedule_responded_at = NOW()
                WHERE appointment_id = ? AND status = 'action_needed'
                  AND reschedule_response_deadline <= NOW() AND archived_at IS NULL");
            if (!$update) {
                throw new RuntimeException('Could not prepare expired appointment reschedule updates.');
            }
            foreach ($expired as $appointment) {
                $id = (int)$appointment['appointment_id'];
                $update->bind_param('i', $id);
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new RuntimeException('Could not expire appointment reschedule offer ' . $id . '.');
                }
            }
            $update->close();
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        error_log('Appointment reschedule offer expiry failed: ' . $error->getMessage());
        throw $error;
    }

    foreach ($expired as $appointment) {
        createNotification(
            $conn,
            (int)$appointment['user_id'],
            'appointment_update',
            'Appointment request closed',
            'The proposed appointment time expired because you did not respond within 3 days. The appointment request has been closed.',
            'appointments',
            (int)$appointment['appointment_id']
        );
        dcvsNotifyWaitlistForSlot(
            $conn,
            $appointment['scheduled_date'],
            $appointment['scheduled_time'],
            $appointment['appointment_type'],
            $appointment['urgency_level'] ?: 'routine'
        );
    }
    return count($expired);
}

function dcvsValidateAppointmentRescheduleOffers($conn, array $conflicts, $offersJson, &$errorMessage) {
    $offers = json_decode((string)$offersJson, true);
    if (!is_array($offers)) {
        $errorMessage = 'Choose a proposed date and time for every affected appointment.';
        return false;
    }

    $conflictIds = array_map(function ($appointment) {
        return (int)$appointment['appointment_id'];
    }, $conflicts);
    $urgencyById = [];
    $lockDates = [];
    foreach ($conflicts as $appointment) {
        $id = (int)$appointment['appointment_id'];
        $urgencyById[$id] = ($appointment['urgency_level'] ?? 'routine') === 'urgent' ? 'urgent' : 'routine';
        $lockDates[] = $appointment['schedule_date'] ?? $appointment['preferred_date'] ?? '';
    }
    $offersById = [];
    foreach ($offers as $offer) {
        $id = (int)($offer['appointment_id'] ?? 0);
        $date = (string)($offer['date'] ?? '');
        $time = (string)($offer['time'] ?? '');
        if ($id <= 0 || isset($offersById[$id])
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))
            || !dcvsIsStandardTime($time)
            || strtotime($date . ' ' . $time) <= time()) {
            $errorMessage = 'Each affected appointment needs a valid future date and available appointment time.';
            return false;
        }
        $offersById[$id] = ['date' => $date, 'time' => dcvsTimeKey($time), 'urgency' => $urgencyById[$id] ?? 'routine'];
        $lockDates[] = $date;
    }
    dcvsLockAppointmentCapacityDates($conn, $lockDates);
    sort($conflictIds);
    $offerIds = array_keys($offersById);
    sort($offerIds);
    if ($offerIds !== $conflictIds) {
        $errorMessage = 'The affected appointments changed. Recheck the program conflicts and try again.';
        return false;
    }

    $affectedPlaceholders = implode(',', array_fill(0, count($conflictIds), '?'));
    $idTypes = str_repeat('i', count($conflictIds));
    $groups = [];
    $dailyProposalCounts = [];
    foreach ($offersById as $id => $offer) {
        $key = $offer['date'] . ' ' . $offer['time'] . ' ' . $offer['urgency'];
        $groups[$key] = $groups[$key] ?? ['date' => $offer['date'], 'time' => $offer['time'], 'urgency' => $offer['urgency'], 'count' => 0];
        $groups[$key]['count']++;
        $dailyProposalCounts[$offer['date']][$offer['urgency']] = ($dailyProposalCounts[$offer['date']][$offer['urgency']] ?? 0) + 1;
    }

    foreach ($groups as $group) {
        $date = $group['date'];
        $time = $group['time'] . ':00';
        $slotStmt = $conn->prepare("SELECT COUNT(*) AS total,
                SUM(CASE WHEN urgency_level = 'routine' THEN 1 ELSE 0 END) AS routine_count,
                SUM(CASE WHEN urgency_level = 'urgent' THEN 1 ELSE 0 END) AS urgent_count
            FROM appointments
            WHERE COALESCE(scheduled_date, preferred_date) = ?
              AND COALESCE(scheduled_time, preferred_time) = ?
              AND status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
              AND archived_at IS NULL
              AND appointment_id NOT IN ($affectedPlaceholders)");
        if (!$slotStmt) {
            throw new RuntimeException('Could not validate proposed appointment slot capacity.');
        }
        $params = array_merge([$date, $time], $conflictIds);
        $slotStmt->bind_param('ss' . $idTypes, ...$params);
        if (!$slotStmt->execute()) {
            $slotStmt->close();
            throw new RuntimeException('Could not validate proposed appointment slot capacity.');
        }
        $counts = $slotStmt->get_result()->fetch_assoc();
        $slotStmt->close();
        $hasProgram = dcvsDateHasActiveProgram($conn, $date);
        $urgency = $group['urgency'];
        $slotCount = (int)$counts[$hasProgram ? $urgency . '_count' : 'total'];
        $capacity = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, substr($time, 0, 5));
        if ((!$hasProgram && $urgency === 'urgent') || $slotCount + $group['count'] > $capacity) {
            $errorMessage = 'One or more proposed times do not have enough appointment capacity.';
            return false;
        }

        $dayStmt = $conn->prepare("SELECT urgency_level, COUNT(*) AS total
            FROM appointments
            WHERE COALESCE(scheduled_date, preferred_date) = ?
              AND status IN ('pending','approved','scheduled','rescheduled','in_progress','action_needed')
              AND archived_at IS NULL
              AND appointment_id NOT IN ($affectedPlaceholders)
            GROUP BY urgency_level");
        if (!$dayStmt) {
            throw new RuntimeException('Could not validate proposed appointment daily capacity.');
        }
        $dayParams = array_merge([$date], $conflictIds);
        $dayStmt->bind_param('s' . $idTypes, ...$dayParams);
        if (!$dayStmt->execute()) {
            $dayStmt->close();
            throw new RuntimeException('Could not validate proposed appointment daily capacity.');
        }
        $dailyCounts = ['routine' => 0, 'urgent' => 0];
        $dailyResult = $dayStmt->get_result();
        while ($row = $dailyResult->fetch_assoc()) $dailyCounts[$row['urgency_level'] ?? 'routine'] = (int)$row['total'];
        $dayStmt->close();
        if ($hasProgram) {
            $dailyLimit = $urgency === 'urgent' ? DCVS_MAX_URGENT_APPOINTMENTS_PER_DAY : DCVS_MAX_ROUTINE_APPOINTMENTS_ON_PROGRAM_DAY;
            $dailyCount = $dailyCounts[$urgency] + ($dailyProposalCounts[$date][$urgency] ?? 0);
        } else {
            $dailyLimit = DCVS_MAX_APPOINTMENTS_PER_DAY;
            $dailyCount = $dailyCounts['routine'] + $dailyCounts['urgent']
                + ($dailyProposalCounts[$date]['routine'] ?? 0) + ($dailyProposalCounts[$date]['urgent'] ?? 0);
        }
        if ($dailyCount > $dailyLimit) {
            $errorMessage = 'One or more proposed dates exceed the daily routine appointment limit.';
            return false;
        }
    }

    return $offersById;
}

function dcvsCreateAppointmentRescheduleOffers($conn, array $conflicts, array $offersById) {
    $releasedSlots = [];
    try {
        $update = $conn->prepare("UPDATE appointments
            SET original_schedule_date = COALESCE(scheduled_date, preferred_date),
                original_schedule_time = COALESCE(scheduled_time, preferred_time),
                scheduled_date = ?, scheduled_time = ?, status = 'action_needed',
                reschedule_response_deadline = DATE_ADD(NOW(), INTERVAL 3 DAY),
                reschedule_offered_at = NOW(), reschedule_responded_at = NULL,
                cancelled_by = NULL, cancellation_reason = NULL
            WHERE appointment_id = ? AND status IN ('pending','approved','scheduled','rescheduled')
              AND archived_at IS NULL");
        if (!$update) {
            throw new RuntimeException('Could not prepare appointment reschedule offers.');
        }
        foreach ($conflicts as $appointment) {
            $id = (int)$appointment['appointment_id'];
            $offer = $offersById[$id];
            $date = $offer['date'];
            $time = $offer['time'] . ':00';
            $update->bind_param('ssi', $date, $time, $id);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('An affected appointment changed before its reschedule offer was saved.');
            }
            if ($appointment['schedule_date'] !== $offer['date'] || substr((string)$appointment['schedule_time'], 0, 5) !== $offer['time']) {
                $releasedSlots[] = [
                    'date' => $appointment['schedule_date'],
                    'time' => $appointment['schedule_time'],
                    'appointment_type' => $appointment['appointment_type'],
                    'urgency_level' => $appointment['urgency_level'] ?: 'routine',
                ];
            }
        }
        $update->close();

        foreach ($conflicts as $appointment) {
            $offer = $offersById[(int)$appointment['appointment_id']];
            $newDate = date('F j, Y', strtotime($offer['date']));
            $newTime = date('g:i A', strtotime($offer['time']));
            $deadline = date('F j, Y', strtotime('+3 days'));
            $message = 'DCVS staff suggested ' . $newDate . ' at ' . $newTime
                . ' for your ' . $appointment['appointment_type'] . ' appointment. Respond before ' . $deadline . '.';
            createNotification(
                $conn,
                (int)$appointment['user_id'],
                'appointment_update',
                'Appointment time needs your response',
                $message,
                'appointments',
                (int)$appointment['appointment_id']
            );
        }
    } catch (Throwable $error) {
        error_log('Appointment reschedule offers failed: ' . $error->getMessage());
        throw $error;
    }
    return $releasedSlots;
}
