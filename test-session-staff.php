<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params(30 * 24 * 60 * 60, '/', '', false, true);
session_start();

$cookie = $_COOKIE['DCVS_STAFF_SESSION'] ?? '(not set)';
$sid = session_id();
$loggedIn = isset($_SESSION['logged_in']) ? ($_SESSION['logged_in'] ? 'YES' : 'no') : 'NOT SET';
$hasData = !empty($_SESSION) ? 'YES (' . count($_SESSION) . ' keys)' : 'EMPTY';

// Store test timestamp
if (!isset($_SESSION['test_created'])) {
    $_SESSION['test_created'] = time();
    $_SESSION['test_count'] = 1;
    $msg = 'New session created';
} else {
    $_SESSION['test_count']++;
    $msg = 'Session resumed (visit #' . $_SESSION['test_count'] . ', age: ' . (time() - $_SESSION['test_created']) . 's)';
}

$info = [
    'Message' => $msg,
    'Session ID' => $sid,
    'Cookie Value' => $cookie,
    'Logged In' => $loggedIn,
    'Session Data' => $hasData,
    'Save Path' => session_save_path(),
    'Cookie Params' => json_encode(session_get_cookie_params()),
    'GC Max Lifetime' => ini_get('session.gc_maxlifetime'),
    'Server Time' => date('Y-m-d H:i:s'),
];
?>
<!DOCTYPE html>
<html><head><title>Staff Session Test</title>
<style>body{font-family:monospace;padding:2rem}table{border-collapse:collapse}td,th{padding:8px 14px;border:1px solid #ccc;text-align:left}th{background:#f5f5f5}.pass{color:#090;font-weight:bold}.fail{color:#c00;font-weight:bold}</style></head>
<body>
<h2>Staff Session Diagnostic</h2>
<table>
<?php foreach ($info as $k => $v): ?>
<tr><th><?php echo $k; ?></th><td><?php echo htmlspecialchars($v); ?></td></tr>
<?php endforeach; ?>
</table>
<p><a href="test-session-staff.php">Refresh this page</a></p>
<p>1. Visit this page. It will create a new session.<br>
2. Close this tab.<br>
3. Open a new tab and visit this page again.<br>
4. If you see "Session resumed" the session persists. If "New session created" the session is lost.</p>
</body></html>
