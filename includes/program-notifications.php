<?php
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../sms-sender.php';

function dcvsNotifyProgramAffectedAppointments($conn, $appointments, $programName, $staffId) {
    $notifiedUsers = [];
    foreach ($appointments as $appointment) {
        $userId = (int)$appointment['user_id'];
        if (isset($notifiedUsers[$userId])) continue;
        $notifiedUsers[$userId] = true;

        $date = date('F j, Y', strtotime($appointment['preferred_date']));
        $time = date('g:i A', strtotime($appointment['preferred_time']));
        $message = 'A DCVS program (' . $programName . ') requires staff during your appointment time on ' . $date . ' at ' . $time . '. Your appointment has not been cancelled. Please contact DCVS at (046) 432-1234 or sign in to review/reschedule your appointment.';
        createNotification($conn, $userId, 'appointment_update', 'Program May Affect Your Appointment', $message, 'appointments', (int)$appointment['appointment_id']);

        $name = $appointment['full_name'] ?: 'Resident';
        $email = $appointment['email'] ?? '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $html = dcvsEmailTemplate('Program may affect your appointment', '<p style="margin:0 0 16px;">' . htmlspecialchars($message) . '</p>');
            if (enqueueDcvsEmail($conn, $email, $name, 'DCVS program and your appointment', $html, $staffId, 'resident', 'program_schedule') === null) {
                error_log('Failed to queue program schedule email for resident ' . $userId);
            }
        }

        $phone = $appointment['phone'] ?? '';
        if ($phone !== '' && isSmsEnabled($conn)
            && !queueSms($conn, $phone, $name, 'DCVS: ' . $message, $staffId, 'resident', 'program_schedule')) {
            error_log('Failed to queue program schedule SMS for resident ' . $userId);
        }
    }
    return count($notifiedUsers);
}
