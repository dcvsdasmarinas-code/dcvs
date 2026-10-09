<?php
session_name('DCVS_STAFF_SESSION');
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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/adoption-workflow.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    exit('Unauthorized');
}

$kind = $_GET['kind'] ?? '';
$recordId = (int)($_GET['id'] ?? 0);
if ($recordId <= 0 || !in_array($kind, ['identity', 'application_identity', 'residency'], true)) {
    http_response_code(404);
    exit('Document not found.');
}

if ($kind === 'identity') {
    $stmt = $conn->prepare(
        'SELECT government_id_file FROM adoption_identity_verifications WHERE verification_id = ?'
    );
} elseif ($kind === 'application_identity') {
    $stmt = $conn->prepare(
        'SELECT COALESCE(a.government_id_file, v.government_id_file)
         FROM adoption_applications a
         LEFT JOIN adoption_identity_verifications v ON v.verification_id = a.identity_verification_id
         WHERE a.application_id = ?'
    );
} else {
    $stmt = $conn->prepare(
        'SELECT residency_proof_file FROM adoption_applications WHERE application_id = ?'
    );
}
if (!$stmt) {
    error_log('Adoption document lookup could not be prepared: ' . $conn->error);
    http_response_code(500);
    exit('Document could not be loaded.');
}
$stmt->bind_param('i', $recordId);
$stmt->execute();
$row = $stmt->get_result()->fetch_row();
$stmt->close();
$filename = $row[0] ?? '';
if (!is_string($filename) || !preg_match('/^[a-z]+_[a-f0-9]{48}\.(jpg|png|webp|pdf)$/', $filename)) {
    http_response_code(404);
    exit('Document not found.');
}

$path = adoptionPrivateDirectory() . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Document not found.');
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    http_response_code(500);
    exit('Document type could not be verified.');
}
$mime = finfo_file($finfo, $path);
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
$disposition = isset($_GET['view']) && $_GET['view'] === '1' ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($path);
exit;
