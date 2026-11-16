<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/adoption-workflow.php';

try {
    expireAdoptionReservations($conn);
    fwrite(STDOUT, "Expired adoption reservations processed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Adoption reservation expiry failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
