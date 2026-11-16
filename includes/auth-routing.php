<?php

function dcvsAuthPortalForRole($role)
{
    $portals = [
        'resident' => ['session' => 'PHPSESSID', 'dashboard' => 'dashboard-resident.php'],
        'staff' => ['session' => 'DCVS_STAFF_SESSION', 'dashboard' => 'dashboard-staff.php'],
        'admin' => ['session' => 'DCVS_ADMIN_SESSION', 'dashboard' => 'dashboard-admin.php'],
        'barangay' => ['session' => 'DCVS_BARANGAY_SESSION', 'dashboard' => 'dashboard-barangay.php'],
    ];
    return $portals[$role] ?? null;
}

function dcvsConfigureAuthSession()
{
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
    session_set_cookie_params([
        'lifetime' => 30 * 24 * 60 * 60,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function dcvsAuthenticatedUserFromSession()
{
    if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
        return null;
    }
    $portal = dcvsAuthPortalForRole($_SESSION['role'] ?? '');
    return $portal ? $portal : null;
}

function dcvsFindExistingPortalSession()
{
    $portals = [
        'DCVS_STAFF_SESSION' => ['role' => 'staff', 'dashboard' => 'dashboard-staff.php'],
        'DCVS_ADMIN_SESSION' => ['role' => 'admin', 'dashboard' => 'dashboard-admin.php'],
        'DCVS_BARANGAY_SESSION' => ['role' => 'barangay', 'dashboard' => 'dashboard-barangay.php'],
    ];
    $defaultSessionId = session_id();
    session_write_close();

    foreach ($portals as $sessionName => $portal) {
        $cookieSessionId = $_COOKIE[$sessionName] ?? '';
        if (!is_string($cookieSessionId) || !preg_match('/^[A-Za-z0-9,-]{1,128}$/', $cookieSessionId)) {
            continue;
        }
        session_name($sessionName);
        session_id($cookieSessionId);
        session_start();
        $isAuthenticated = !empty($_SESSION['logged_in'])
            && ($_SESSION['role'] ?? '') === $portal['role']
            && !empty($_SESSION['user_id']);
        session_write_close();
        if ($isAuthenticated) {
            session_name('PHPSESSID');
            session_id($defaultSessionId);
            session_start();
            return $portal['dashboard'];
        }
    }

    session_name('PHPSESSID');
    session_id($defaultSessionId);
    session_start();
    return null;
}

function dcvsStartRoleSession($user)
{
    $portal = dcvsAuthPortalForRole($user['role'] ?? '');
    if (!$portal) {
        return false;
    }

    $authData = [
        'user_id' => $user['user_id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'barangay_id' => $user['barangay_id'],
        'phone' => $user['phone'],
        'address' => $user['address'],
        'avatar_url' => $user['avatar_url'] ?? '',
        'reference_id' => $user['reference_id'] ?? '',
        'logged_in' => true,
    ];

    if ($portal['session'] === 'PHPSESSID') {
        $_SESSION = array_merge($_SESSION, $authData);
        return $portal;
    }

    $csrfToken = $_SESSION['csrf_token'] ?? null;
    $_SESSION = [];
    if (is_string($csrfToken) && $csrfToken !== '') {
        $_SESSION['csrf_token'] = $csrfToken;
    }
    session_write_close();

    session_name($portal['session']);
    session_id('');
    dcvsConfigureAuthSession();
    session_start();
    session_regenerate_id(true);
    $_SESSION = $authData;
    if ($user['role'] === 'staff') {
        $_SESSION['fresh_dashboard'] = true;
    }
    session_write_close();
    return $portal;
}
