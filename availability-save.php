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

require_once 'db.php';
require_once 'includes/availability.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$mode = $_POST['mode'] ?? 'date';

function validDate($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

function decodeSlots($param) {
    $out = [];
    if (empty($param)) return $out;
    $decoded = json_decode($param, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return $out;
    foreach ($decoded as $t) {
        if (is_string($t) && in_array($t, DCVS_STANDARD_TIMES, true)) $out[] = $t;
    }
    return array_values(array_unique($out));
}

// ── Mode: weekly ──
// weekly = JSON object { "day": ["HH:MM","HH:MM"], ... } => times CLOSED on that weekday (1=Mon..7=Sun)
// Rows not listed = open. Rewrites the whole template.
if ($mode === 'weekly') {
    $raw = $_POST['weekly'] ?? '';
    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid weekly payload']);
        exit;
    }

    $closedByDay = [];
    for ($day = 1; $day <= 7; $day++) {
        $times = $payload[$day] ?? $payload[(string)$day] ?? [];
        if (!is_array($times)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid weekly slot list']);
            exit;
        }
        $closedByDay[$day] = [];
        foreach ($times as $time) {
            if (!is_string($time) || !in_array($time, DCVS_STANDARD_TIMES, true)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid weekly time slot']);
                exit;
            }
            if (!in_array($time, $closedByDay[$day], true)) $closedByDay[$day][] = $time;
        }
    }

    $held = $conn->query("SELECT WEEKDAY(preferred_date) + 1 AS day_of_week, preferred_date, LEFT(preferred_time, 5) AS slot_time
                          FROM appointments
                          WHERE preferred_date >= CURDATE()
                            AND status IN ('pending','approved','scheduled','rescheduled','in_progress')
                            AND archived_at IS NULL");
    if (!$held) {
        echo json_encode(['ok' => false, 'error' => 'Could not verify existing appointments before saving.']);
        exit;
    }
    while ($appointment = $held->fetch_assoc()) {
        $day = (int)$appointment['day_of_week'];
        $time = $appointment['slot_time'];
        if (in_array($time, $closedByDay[$day] ?? [], true)) {
            $held->free();
            echo json_encode(['ok' => false, 'error' => 'Cannot close ' . $time . ' on a weekly schedule because an appointment is already held on ' . $appointment['preferred_date'] . '.']);
            exit;
        }
    }
    $held->free();

    if (!$conn->begin_transaction()) {
        echo json_encode(['ok' => false, 'error' => 'Could not start the weekly schedule update.']);
        exit;
    }
    if (!$conn->query("DELETE FROM availability_weekly")) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not replace the weekly schedule.']);
        exit;
    }
    $ins = $conn->prepare("INSERT INTO availability_weekly (day_of_week, slot_time, status) VALUES (?, ?, 'unavailable')");
    if (!$ins) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not prepare the weekly schedule update.']);
        exit;
    }
    foreach ($closedByDay as $day => $times) {
        foreach ($times as $time) {
            $ins->bind_param("is", $day, $time);
            if (!$ins->execute()) {
                $ins->close();
                $conn->rollback();
                echo json_encode(['ok' => false, 'error' => 'Could not save the complete weekly schedule.']);
                exit;
            }
        }
    }
    $ins->close();
    if (!$conn->commit()) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not commit the weekly schedule update.']);
        exit;
    }
    echo json_encode(['ok' => true, 'mode' => 'weekly']);
    $conn->close();
    exit;
}

// ── Mode: date ──
// date + slots = JSON object { 'HH:MM': bool available } for that date. Rewrites the day's overrides.
if ($mode === 'date') {
    $date = $_POST['date'] ?? '';
    if (!validDate($date)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date']);
        exit;
    }
    $raw = json_decode($_POST['slots'] ?? '{}', true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($raw)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date override slots']);
        exit;
    }
    $state = [];
    foreach ($raw as $time => $available) {
        if (!in_array((string)$time, DCVS_STANDARD_TIMES, true) || !is_bool($available)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid date override slot']);
            exit;
        }
        $state[(string)$time] = $available;
    }
    if ($state && count($state) !== count(DCVS_STANDARD_TIMES)) {
        echo json_encode(['ok' => false, 'error' => 'A custom date override must include every standard time slot.']);
        exit;
    }

    // Reject closing any slot that holds a confirmed (scheduled) appointment.
    foreach ($state as $t => $avail) {
        if (!$avail && dcvsSlotHeld($conn, $date, $t, 0, 'all')) {
            echo json_encode(['ok' => false, 'error' => "This time slot has a scheduled appointment and cannot be marked unavailable."]);
            $conn->close();
            exit;
        }
    }

    if (!$conn->begin_transaction()) {
        echo json_encode(['ok' => false, 'error' => 'Could not start the date override update.']);
        exit;
    }
    $del = $conn->prepare("DELETE FROM appointment_slots WHERE slot_date = ?");
    if (!$del) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not prepare the date override update.']);
        exit;
    }
    $del->bind_param("s", $date);
    if (!$del->execute()) {
        $del->close();
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not replace the date override.']);
        exit;
    }
    $del->close();

    if ($state) {
        $ins = $conn->prepare("INSERT INTO appointment_slots (slot_date, slot_time, status) VALUES (?, ?, ?)");
        if (!$ins) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => 'Could not prepare the date override slots.']);
            exit;
        }
        foreach ($state as $time => $available) {
            $status = $available ? 'available' : 'unavailable';
            $ins->bind_param("sss", $date, $time, $status);
            if (!$ins->execute()) {
                $ins->close();
                $conn->rollback();
                echo json_encode(['ok' => false, 'error' => 'Could not save the complete date override.']);
                exit;
            }
        }
        $ins->close();
    }
    if (!$conn->commit()) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not commit the date override update.']);
        exit;
    }
    echo json_encode(['ok' => true, 'mode' => 'date', 'date' => $date, 'slots' => $state]);
    $conn->close();
    exit;
}

// ── Mode: bulk_dates ──
// dates = JSON [YYYY-MM-DD], action = available|closed|restore, slots = full time=>bool map for available.
if ($mode === 'bulk_dates') {
    $rawDates = json_decode($_POST['dates'] ?? '', true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($rawDates) || !$rawDates) {
        echo json_encode(['ok' => false, 'error' => 'Select at least one valid date.']);
        exit;
    }
    $dates = [];
    foreach ($rawDates as $date) {
        if (!validDate($date)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid date in the bulk schedule selection.']);
            exit;
        }
        $dates[$date] = $date;
    }
    $dates = array_values($dates);
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['available', 'closed', 'restore'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid bulk schedule action.']);
        exit;
    }

    $state = [];
    if ($action === 'closed') {
        foreach (DCVS_STANDARD_TIMES as $time) $state[$time] = false;
    } elseif ($action === 'available') {
        $rawSlots = json_decode($_POST['slots'] ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($rawSlots)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid bulk availability slots.']);
            exit;
        }
        foreach (DCVS_STANDARD_TIMES as $time) {
            if (!array_key_exists($time, $rawSlots) || !is_bool($rawSlots[$time])) {
                echo json_encode(['ok' => false, 'error' => 'Bulk availability must include every standard time slot.']);
                exit;
            }
            $state[$time] = $rawSlots[$time];
        }
        if (!in_array(true, $state, true)) {
            echo json_encode(['ok' => false, 'error' => 'Choose at least one available time range.']);
            exit;
        }
    }

    if ($action !== 'restore') {
        foreach ($dates as $date) {
            foreach ($state as $time => $available) {
                if (!$available && dcvsSlotHeld($conn, $date, $time, 0, 'all')) {
                    echo json_encode(['ok' => false, 'error' => 'Cannot close ' . $time . ' on ' . $date . ' because an appointment is already held. No bulk changes were saved.']);
                    exit;
                }
            }
        }
    }

    if (!$conn->begin_transaction()) {
        echo json_encode(['ok' => false, 'error' => 'Could not start the bulk schedule update.']);
        exit;
    }
    $del = $conn->prepare("DELETE FROM appointment_slots WHERE slot_date = ?");
    if (!$del) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not prepare the bulk schedule update.']);
        exit;
    }
    $ins = $action === 'restore' ? null : $conn->prepare("INSERT INTO appointment_slots (slot_date, slot_time, status) VALUES (?, ?, ?)");
    if ($action !== 'restore' && !$ins) {
        $del->close();
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not prepare the bulk schedule slots.']);
        exit;
    }

    foreach ($dates as $date) {
        $del->bind_param("s", $date);
        if (!$del->execute()) {
            $del->close();
            if ($ins) $ins->close();
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => 'Could not replace the bulk date overrides.']);
            exit;
        }
        if ($ins) {
            foreach ($state as $time => $available) {
                $status = $available ? 'available' : 'unavailable';
                $ins->bind_param("sss", $date, $time, $status);
                if (!$ins->execute()) {
                    $del->close();
                    $ins->close();
                    $conn->rollback();
                    echo json_encode(['ok' => false, 'error' => 'Could not save the complete bulk schedule.']);
                    exit;
                }
            }
        }
    }
    $del->close();
    if ($ins) $ins->close();
    if (!$conn->commit()) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => 'Could not commit the bulk schedule update.']);
        exit;
    }
    echo json_encode(['ok' => true, 'mode' => 'bulk_dates', 'action' => $action, 'affected' => count($dates)]);
    $conn->close();
    exit;
}

// ── Mode: bulk ──
// date_from, date_to, weekdays (JSON [1..7]), slots (JSON 'HH:MM'), action = 'block'|'open'
if ($mode === 'bulk') {
    $from = $_POST['date_from'] ?? '';
    $to = $_POST['date_to'] ?? '';
    if (!validDate($from) || !validDate($to)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date range']);
        exit;
    }
    if ($from > $to) {
        echo json_encode(['ok' => false, 'error' => 'End date must be after start date']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['block', 'open', 'available', 'closed'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid action']);
        exit;
    }
    // Map UI terminology to internal: available/open => 'open', closed/block => 'block'
    $internalAction = in_array($action, ['open', 'available'], true) ? 'open' : 'block';
    $weekdays = json_decode($_POST['weekdays'] ?? '[]', true);
    $weekdays = array_filter(array_map('intval', is_array($weekdays) ? $weekdays : []), function ($d) {
        return $d >= 1 && $d <= 7;
    });
    if (!$weekdays) {
        echo json_encode(['ok' => false, 'error' => 'Select at least one weekday']);
        exit;
    }
    $slots = decodeSlots($_POST['slots'] ?? '');
    if (!$slots) {
        echo json_encode(['ok' => false, 'error' => 'Select at least one time slot']);
        exit;
    }

    $count = 0;
    $skipped = 0;
    $status = $internalAction === 'block' ? 'unavailable' : 'available';
    $upsert = "INSERT INTO appointment_slots (slot_date, slot_time, status) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE status = VALUES(status)";
    $ins = $conn->prepare($upsert);
    if ($ins) {
        $cursor = $from;
        while ($cursor <= $to) {
            $dow = (int)date('N', strtotime($cursor));
            if (in_array($dow, $weekdays, true)) {
                foreach ($slots as $t) {
                    // Never close a slot that holds a confirmed appointment.
                    if ($internalAction === 'block' && dcvsSlotHeld($conn, $cursor, $t, 0, 'all')) {
                        $skipped++;
                        continue;
                    }
                    $ins->bind_param("sss", $cursor, $t, $status);
                    $ins->execute();
                    $count += $conn->affected_rows;
                }
            }
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
        $ins->close();
    }

    echo json_encode(['ok' => true, 'mode' => 'bulk', 'action' => $action, 'affected' => $count, 'skipped' => $skipped]);
    $conn->close();
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
$conn->close();