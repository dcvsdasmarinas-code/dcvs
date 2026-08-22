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

    $conn->query("DELETE FROM availability_weekly");
    $ins = $conn->prepare("INSERT INTO availability_weekly (day_of_week, slot_time, status) VALUES (?, ?, 'unavailable')");
    if ($ins) {
        foreach ($payload as $dayStr => $times) {
            $day = (int)$dayStr;
            if ($day < 1 || $day > 7) continue;
            foreach (decodeSlots(json_encode(is_array($times) ? $times : [])) as $t) {
                $ins->bind_param("is", $day, $t);
                $ins->execute();
            }
        }
        $ins->close();
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
    $state = [];
    $raw = json_decode($_POST['slots'] ?? '[]', true);
    if (is_array($raw)) {
        foreach ($raw as $t => $avail) {
            $time = (string)$t;
            if (in_array($time, DCVS_STANDARD_TIMES, true)) $state[$time] = (bool)$avail;
        }
    }

    // Reject closing any slot that holds a confirmed (scheduled) appointment.
    foreach ($state as $t => $avail) {
        if (!$avail && dcvsSlotHeld($conn, $date, $t)) {
            echo json_encode(['ok' => false, 'error' => "This time slot has a scheduled appointment and cannot be marked unavailable."]);
            $conn->close();
            exit;
        }
    }

    $del = $conn->prepare("DELETE FROM appointment_slots WHERE slot_date = ?");
    $del->bind_param("s", $date);
    $del->execute();
    $del->close();

    $ins = $conn->prepare("INSERT INTO appointment_slots (slot_date, slot_time, status) VALUES (?, ?, ?)");
    if ($ins) {
        foreach ($state as $t => $avail) {
            $status = $avail ? 'available' : 'unavailable';
            $ins->bind_param("sss", $date, $t, $status);
            $ins->execute();
        }
        $ins->close();
    }
    echo json_encode(['ok' => true, 'mode' => 'date', 'date' => $date, 'slots' => $state]);
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
                    if ($internalAction === 'block' && dcvsSlotHeld($conn, $cursor, $t)) {
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