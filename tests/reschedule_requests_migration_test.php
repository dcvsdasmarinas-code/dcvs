<?php
require_once __DIR__ . '/../env.php';

function expectRescheduleMigrationCondition($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

loadDcvsEnv();
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(
    (string)env('DB_HOST', '127.0.0.1'),
    (string)env('DB_USER', 'root'),
    (string)env('DB_PASS', ''),
    (string)env('DB_NAME', 'dcvs')
);
if ($db->connect_errno) {
    throw new RuntimeException('Could not connect to the configured database: ' . $db->connect_error);
}

$tableCheck = $db->query("SELECT ENGINE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_reschedule_requests'");
expectRescheduleMigrationCondition(
    $tableCheck && ($table = $tableCheck->fetch_assoc()) && $table['ENGINE'] === 'InnoDB',
    'Run the resident reschedule migration before this test; the InnoDB request table is missing.'
);

$foreignKeys = $db->query("SELECT COUNT(*) AS fk_count FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_reschedule_requests'
    AND REFERENCED_TABLE_NAME IN ('appointments','users')");
expectRescheduleMigrationCondition(
    $foreignKeys && (int)$foreignKeys->fetch_assoc()['fk_count'] === 2,
    'The request table must retain its appointment and staff foreign keys.'
);

$db->begin_transaction();
try {
    $candidateResult = $db->query("SELECT a.appointment_id
        FROM appointments a
        LEFT JOIN appointment_reschedule_requests r
            ON r.appointment_id = a.appointment_id AND r.status IN ('pending','offered')
        WHERE r.request_id IS NULL
        LIMIT 1 FOR UPDATE");
    $candidate = $candidateResult ? $candidateResult->fetch_row() : null;
    expectRescheduleMigrationCondition(
        $candidate !== null,
        'A rollback-only request test needs an appointment with no active reschedule request.'
    );
    $appointmentId = (int)$candidate[0];

    $insert = $db->prepare("INSERT INTO appointment_reschedule_requests
        (appointment_id, requested_date, requested_time, requested_urgency, resident_reason)
        VALUES (?, DATE_ADD(CURDATE(), INTERVAL 30 DAY), '09:00:00', 'routine', 'rollback-only schema test')");
    expectRescheduleMigrationCondition((bool)$insert, 'Could not prepare the temporary request insert.');
    $insert->bind_param('i', $appointmentId);
    expectRescheduleMigrationCondition($insert->execute(), 'Could not insert a temporary pending request: ' . $insert->error);
    $requestId = (int)$insert->insert_id;
    $insert->close();

    $duplicate = $db->prepare("INSERT INTO appointment_reschedule_requests
        (appointment_id, requested_date, requested_time, requested_urgency)
        VALUES (?, DATE_ADD(CURDATE(), INTERVAL 31 DAY), '10:00:00', 'routine')");
    expectRescheduleMigrationCondition((bool)$duplicate, 'Could not prepare the duplicate request check.');
    $duplicate->bind_param('i', $appointmentId);
    $insertedDuplicate = $duplicate->execute();
    $duplicateError = $duplicate->errno;
    $duplicate->close();
    expectRescheduleMigrationCondition(
        !$insertedDuplicate && $duplicateError === 1062,
        'The active-request unique constraint must reject a second pending request.'
    );

    $update = $db->prepare("UPDATE appointment_reschedule_requests SET status = 'offered' WHERE request_id = ?");
    expectRescheduleMigrationCondition((bool)$update, 'Could not prepare the offered-status check.');
    $update->bind_param('i', $requestId);
    expectRescheduleMigrationCondition($update->execute(), 'Could not move the temporary request to offered.');
    $update->close();

    $duplicate = $db->prepare("INSERT INTO appointment_reschedule_requests
        (appointment_id, requested_date, requested_time, requested_urgency)
        VALUES (?, DATE_ADD(CURDATE(), INTERVAL 32 DAY), '11:00:00', 'routine')");
    expectRescheduleMigrationCondition((bool)$duplicate, 'Could not prepare the offered duplicate check.');
    $duplicate->bind_param('i', $appointmentId);
    $insertedDuplicate = $duplicate->execute();
    $duplicateError = $duplicate->errno;
    $duplicate->close();
    expectRescheduleMigrationCondition(
        !$insertedDuplicate && $duplicateError === 1062,
        'An offered request must continue to block a second active request.'
    );

    $update = $db->prepare("UPDATE appointment_reschedule_requests SET status = 'approved' WHERE request_id = ?");
    expectRescheduleMigrationCondition((bool)$update, 'Could not prepare the closed-status check.');
    $update->bind_param('i', $requestId);
    expectRescheduleMigrationCondition($update->execute(), 'Could not close the temporary request.');
    $update->close();

    $newRequest = $db->prepare("INSERT INTO appointment_reschedule_requests
        (appointment_id, requested_date, requested_time, requested_urgency)
        VALUES (?, DATE_ADD(CURDATE(), INTERVAL 33 DAY), '14:00:00', 'routine')");
    expectRescheduleMigrationCondition((bool)$newRequest, 'Could not prepare a request after closure.');
    $newRequest->bind_param('i', $appointmentId);
    expectRescheduleMigrationCondition(
        $newRequest->execute(),
        'A new request should be allowed after the previous request is closed: ' . $newRequest->error
    );
    $newRequest->close();

    $db->rollback();
    echo "Reschedule migration and active-request constraints passed; test rows rolled back.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->close();
}
