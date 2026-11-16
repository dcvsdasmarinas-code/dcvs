<?php
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/pet-duplicates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function petDuplicateCheckReply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'resident') {
    petDuplicateCheckReply(401, ['success' => false, 'message' => 'Sign in as a resident to check pet profiles.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    petDuplicateCheckReply(405, ['success' => false, 'message' => 'Use the pet registration form to check profiles.']);
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    petDuplicateCheckReply(403, ['success' => false, 'message' => 'Your session could not be verified. Refresh and try again.']);
}

$identity = petDuplicateIdentityFromInput($_POST);
if ($identity['pet_name'] === '' || $identity['pet_type'] === '') {
    petDuplicateCheckReply(400, ['success' => false, 'message' => 'Enter the pet name and species before checking for similar profiles.']);
}

try {
    $matches = petDuplicateFindCandidates($conn, (int)$_SESSION['user_id'], $identity);
    $residentMatches = [];
    $otherOwnerMatch = false;
    foreach ($matches as $match) {
        if ((int)$match['owner_id'] !== (int)$_SESSION['user_id']) {
            $otherOwnerMatch = true;
            continue;
        }
        $residentMatches[] = [
            'pet_id' => (int)$match['pet_id'],
            'pet_name' => (string)$match['pet_name'],
            'pet_type' => (string)$match['pet_type'],
            'breed' => (string)($match['breed'] ?? ''),
            'gender' => (string)($match['gender'] ?? ''),
            'color' => (string)($match['color'] ?? ''),
            'matched_fields' => $match['matched_fields'],
        ];
    }

    petDuplicateCheckReply(200, [
        'success' => true,
        'resident_matches' => $residentMatches,
        'staff_review_possible' => $otherOwnerMatch,
    ]);
} catch (RuntimeException $exception) {
    error_log('Resident pet duplicate check failed: ' . $exception->getMessage());
    petDuplicateCheckReply(503, ['success' => false, 'message' => 'Similar pet profiles could not be checked right now. Please try again.']);
}
