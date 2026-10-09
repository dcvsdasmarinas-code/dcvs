<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/waitlist.php';

if (!dcvsExpireWaitlistClaims($conn)) {
    fwrite(STDERR, "Appointment waitlist expiry processing failed; see the PHP error log.\n");
    exit(1);
}

echo "Appointment waitlist expiry processing completed.\n";
