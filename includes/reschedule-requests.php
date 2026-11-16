<?php
require_once __DIR__ . '/appointments/availability.php';

function dcvsRescheduleSlotAvailability($conn, $appointmentId, $date, $time, $urgency) {
    $urgency = $urgency === 'urgent' ? 'urgent' : 'routine';
    $daily = dcvsAppointmentDailyCapacity($conn, $date, $urgency, $appointmentId);
    $hasProgram = $daily['has_program'];
    $validUrgency = $hasProgram || $urgency === 'routine';
    $slotCount = dcvsAppointmentSlotCount($conn, $date, $time, $appointmentId, $hasProgram ? $urgency : 'all');
    $slotLimit = $urgency === 'urgent' ? 1 : dcvsRoutineSlotCapacity($conn, $date, $time);
    $slotOpen = dcvsIsStandardTime($time)
        && ($urgency === 'urgent'
            ? dcvsUrgentSlotAvailable($conn, $date, $time)
            : dcvsSlotAvailable($conn, $date, $time));
    $isFuture = strtotime($date . ' ' . $time) > time();
    $available = $validUrgency && $daily['available'] && $slotOpen
        && $slotCount < $slotLimit && $isFuture;

    return [
        'available' => $available,
        'urgency' => $urgency,
        'program_day' => $hasProgram,
        'daily_count' => $daily['count'],
        'daily_limit' => $daily['limit'],
        'slot_count' => $slotCount,
        'slot_limit' => $slotLimit,
        'past' => !$isFuture,
    ];
}

function dcvsValidateRescheduleInput($date, $time, $urgency) {
    $dateValue = DateTime::createFromFormat('!Y-m-d', (string)$date);
    $time = (string)$time;
    $timeValue = preg_match('/^\d{2}:\d{2}(?::00)?$/', $time)
        ? DateTime::createFromFormat('!H:i', substr($time, 0, 5))
        : false;
    return $dateValue && $dateValue->format('Y-m-d') === $date
        && $timeValue && $timeValue->format('H:i') === substr($time, 0, 5)
        && dcvsIsStandardTime($time)
        && in_array($urgency, ['routine', 'urgent'], true);
}
