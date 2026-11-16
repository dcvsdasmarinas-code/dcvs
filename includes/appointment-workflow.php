<?php

function dcvsMarkOverdueAppointmentsNoShow(mysqli $db): int
{
    $stmt = $db->prepare("
        UPDATE appointments
        SET status = 'no_show'
        WHERE archived_at IS NULL
          AND status IN ('approved', 'scheduled', 'rescheduled', 'in_progress')
          AND TIMESTAMP(
              COALESCE(scheduled_date, preferred_date),
              COALESCE(scheduled_time, preferred_time)
          ) <= NOW()
    ");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the overdue appointment update: ' . $db->error);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not mark overdue appointments as no-show: ' . $error);
    }

    $updatedCount = $stmt->affected_rows;
    $stmt->close();
    return $updatedCount;
}
