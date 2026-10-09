<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$pageTitle = 'Activity Logs';
$activePage = 'activity-logs';

// ── Activity logs filtering ──
$actSearch = trim($_GET['act_search'] ?? '');
$actAction = $_GET['act_action'] ?? '';
$actUser = $_GET['act_user'] ?? '';
$actSource = $_GET['act_source'] ?? '';
$actDateFrom = $_GET['act_date_from'] ?? '';
$actDateTo = $_GET['act_date_to'] ?? '';
$actDateFilter = $_GET['act_date'] ?? '';
if ($actDateFilter) {
    $actDateFrom = $actDateFilter;
    $actDateTo = $actDateFilter;
}
$actWhere = "WHERE 1=1";
$actParams = [];
$actTypes = "";
if ($actSearch) {
    $actWhere .= " AND (a.action LIKE ? OR a.details LIKE ? OR u.full_name LIKE ?)";
    $s = "%$actSearch%";
    $actParams = [$s, $s, $s];
    $actTypes = "sss";
}
if ($actAction) {
    $actWhere .= " AND a.action = ?";
    $actParams[] = $actAction;
    $actTypes .= "s";
}
if ($actUser) {
    $actWhere .= " AND a.user_id = ?";
    $actParams[] = intval($actUser);
    $actTypes .= "i";
}
if ($actSource) {
    if ($actSource === 'authentication') {
        $actWhere .= " AND (a.action LIKE '%Login%' OR a.action LIKE '%Logout%' OR a.action LIKE '%Password%' OR a.action LIKE '%Failed%')";
    } elseif ($actSource === 'user_management') {
        $actWhere .= " AND (a.action LIKE '%User%' OR a.action LIKE '%Role%' OR a.action LIKE '%Status%' OR a.action LIKE '%Activate%' OR a.action LIKE '%Deactivate%' OR a.action LIKE '%Delete%')";
    } elseif ($actSource === 'appointments') {
        $actWhere .= " AND (a.action LIKE '%Appointment%')";
    } elseif ($actSource === 'client_management') {
        $actWhere .= " AND (a.action LIKE '%Client%' OR a.action LIKE '%Pet%' OR a.action LIKE '%Resident%')";
    } elseif ($actSource === 'rescue_reports') {
        $actWhere .= " AND (a.action LIKE '%Rescue%')";
    } elseif ($actSource === 'vaccination') {
        $actWhere .= " AND (a.action LIKE '%Vaccin%')";
    } elseif ($actSource === 'reports_analytics') {
        $actWhere .= " AND (a.action LIKE '%Report%' OR a.action LIKE '%Export%' OR a.action LIKE '%Analytics%')";
    } elseif ($actSource === 'settings') {
        $actWhere .= " AND (a.action LIKE '%Setting%')";
    }
}
if ($actDateFrom) {
    $actWhere .= " AND a.created_at >= ?";
    $actParams[] = $actDateFrom . ' 00:00:00';
    $actTypes .= "s";
}
if ($actDateTo) {
    $actWhere .= " AND a.created_at <= ?";
    $actParams[] = $actDateTo . ' 23:59:59';
    $actTypes .= "s";
}
$actSql = "SELECT a.*, u.full_name, u.avatar_url, u.role FROM activity_logs a LEFT JOIN users u ON a.user_id = u.user_id $actWhere ORDER BY a.created_at DESC";
$actQ = $conn->prepare($actSql);
if ($actTypes) $actQ->bind_param($actTypes, ...$actParams);
$actQ->execute();
$allActivity = $actQ->get_result()->fetch_all(MYSQLI_ASSOC);
$actQ->close();

$activityEntityIds = ['reports' => [], 'cases' => [], 'pets' => [], 'users' => []];
$activityAppointmentRefs = [];
foreach ($allActivity as $activity) {
    $details = (string)$activity['details'];
    foreach ([
        'reports' => '/\breport\s+#(\d+)\b/i',
        'cases' => '/\bcase\s+#(\d+)\b/i',
        'pets' => '/\bpet\s+ID:\s*(\d+)\b/i',
        'users' => '/\buser\s+ID:\s*(\d+)\b/i',
    ] as $entity => $pattern) {
        if (preg_match_all($pattern, $details, $matches)) {
            foreach ($matches[1] as $id) $activityEntityIds[$entity][(int)$id] = (int)$id;
        }
    }
    if (preg_match_all('/#?(APT-[A-Z0-9-]+)/i', $details, $matches)) {
        foreach ($matches[1] as $reference) $activityAppointmentRefs[$reference] = '#' . $reference;
    }
}

$activityEntityMaps = ['reports' => [], 'cases' => [], 'pets' => [], 'users' => [], 'appointments' => []];
if ($activityEntityIds['reports']) {
    $ids = implode(',', $activityEntityIds['reports']);
    $result = $conn->query("SELECT r.report_id, r.report_type, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON u.user_id = r.reporter_id WHERE r.report_id IN ($ids)");
    if ($result) {
        while ($row = $result->fetch_assoc()) $activityEntityMaps['reports'][(int)$row['report_id']] = $row;
    } else {
        error_log('Failed to load activity log report names: ' . $conn->error);
    }
}
if ($activityEntityIds['cases']) {
    $ids = implode(',', $activityEntityIds['cases']);
    $result = $conn->query("SELECT cc.case_id, r.report_type, u.full_name AS reporter_name FROM confirmed_cases cc JOIN reports r ON r.report_id = cc.report_id LEFT JOIN users u ON u.user_id = r.reporter_id WHERE cc.case_id IN ($ids)");
    if ($result) {
        while ($row = $result->fetch_assoc()) $activityEntityMaps['cases'][(int)$row['case_id']] = $row;
    } else {
        error_log('Failed to load activity log case names: ' . $conn->error);
    }
}
if ($activityEntityIds['pets']) {
    $ids = implode(',', $activityEntityIds['pets']);
    $result = $conn->query("SELECT p.pet_id, p.pet_name, u.full_name AS owner_name FROM pets p LEFT JOIN users u ON u.user_id = p.owner_id WHERE p.pet_id IN ($ids)");
    if ($result) {
        while ($row = $result->fetch_assoc()) $activityEntityMaps['pets'][(int)$row['pet_id']] = $row;
    } else {
        error_log('Failed to load activity log pet names: ' . $conn->error);
    }
}
if ($activityEntityIds['users']) {
    $ids = implode(',', $activityEntityIds['users']);
    $result = $conn->query("SELECT user_id, full_name FROM users WHERE user_id IN ($ids)");
    if ($result) {
        while ($row = $result->fetch_assoc()) $activityEntityMaps['users'][(int)$row['user_id']] = $row['full_name'];
    } else {
        error_log('Failed to load activity log resident names: ' . $conn->error);
    }
}
if ($activityAppointmentRefs) {
    $references = array_values($activityAppointmentRefs);
    $placeholders = implode(',', array_fill(0, count($references), '?'));
    $appointmentStmt = $conn->prepare("SELECT a.reference_id, a.appointment_type, u.full_name AS resident_name, p.pet_name FROM appointments a LEFT JOIN users u ON u.user_id = a.user_id LEFT JOIN pets p ON p.pet_id = a.pet_id WHERE a.reference_id IN ($placeholders)");
    if ($appointmentStmt) {
        $appointmentStmt->bind_param(str_repeat('s', count($references)), ...$references);
        if ($appointmentStmt->execute()) {
            $result = $appointmentStmt->get_result();
            while ($row = $result->fetch_assoc()) $activityEntityMaps['appointments'][ltrim($row['reference_id'], '#')] = $row;
        } else {
            error_log('Failed to query activity log appointment names: ' . $appointmentStmt->error);
        }
        $appointmentStmt->close();
    } else {
        error_log('Failed to prepare activity log appointment lookup: ' . $conn->error);
    }
}

function activityLogPersonLabel($residentName, $petName = '') {
    $label = $residentName ?: 'a resident';
    return $petName ? $label . ' (pet: ' . $petName . ')' : $label;
}

function activityLogArticle($label) {
    return preg_match('/^[aeiou]/i', trim((string)$label)) ? 'an' : 'a';
}

function formatActivityLogDetails($details, $maps) {
    $details = (string)$details;
    if (preg_match('/^Submitted\s+(.+?)\s+report\s+#(\d+)$/i', $details, $match)) {
        $report = $maps['reports'][(int)$match[2]] ?? null;
        $type = $report['report_type'] ?? $match[1];
        $reporter = $report['reporter_name'] ?? '';
        return 'Submitted ' . activityLogArticle($type) . ' ' . ucfirst($type) . ' report' . ($reporter ? ' from ' . $reporter : '') . '.';
    }
    if (preg_match('/^(Approved|Rejected) appointment\s+#?(APT-[A-Z0-9-]+)\s+for\s+([a-z_-]+)(.*)$/i', $details, $match)) {
        $appointment = $maps['appointments'][strtoupper($match[2])] ?? null;
        if ($appointment) {
            return $match[1] . ' ' . ucfirst($appointment['appointment_type']) . ' appointment for '
                . activityLogPersonLabel($appointment['resident_name'], $appointment['pet_name']) . $match[4];
        }
    }
    if (preg_match('/^Scheduled\s+([a-z_-]+)\s+for\s+(.+?)\s+on\s+(\d{4}-\d{2}-\d{2})(?:\s+\(ID:\s*#?(APT-[A-Z0-9-]+)\))?(\s+at\s+[\d:]+\s+[AP]M)?\.\s*$/i', $details, $match)) {
        $appointment = !empty($match[4]) ? ($maps['appointments'][strtoupper($match[4])] ?? null) : null;
        $person = $appointment
            ? activityLogPersonLabel($appointment['resident_name'], $appointment['pet_name'])
            : $match[2];
        $type = $appointment['appointment_type'] ?? $match[1];
        return 'Scheduled ' . ucfirst($type) . ' appointment for ' . $person . ' on ' . $match[3] . ($match[5] ?? '') . '.';
    }
    if (preg_match('/^Case\s+#(\d+)\s+resolved successfully\.?$/i', $details, $match)) {
        $case = $maps['cases'][(int)$match[1]] ?? null;
        if ($case) return 'Resolved the ' . ucfirst($case['report_type']) . ' case reported by ' . ($case['reporter_name'] ?: 'a resident') . '.';
        return 'Resolved the case.';
    }

    $details = preg_replace('/\s*\(ID:\s*#?APT-[A-Z0-9-]+\)/i', '', $details);
    $details = preg_replace_callback('/\breport\s+#(\d+)\b/i', function($match) use ($maps) {
        $report = $maps['reports'][(int)$match[1]] ?? null;
        if (!$report) return 'report';
        return ucfirst($report['report_type']) . ' report from ' . ($report['reporter_name'] ?: 'a resident');
    }, $details);
    $details = preg_replace_callback('/\bcase\s+#(\d+)\b/i', function($match) use ($maps) {
        $case = $maps['cases'][(int)$match[1]] ?? null;
        if (!$case) return 'case';
        return ucfirst($case['report_type']) . ' case from ' . ($case['reporter_name'] ?: 'a resident');
    }, $details);
    $details = preg_replace_callback('/\bpet\s+ID:\s*(\d+)\b/i', function($match) use ($maps) {
        $pet = $maps['pets'][(int)$match[1]] ?? null;
        return $pet ? $pet['pet_name'] : 'the selected pet';
    }, $details);
    $details = preg_replace_callback('/\buser\s+ID:\s*(\d+)\b/i', function($match) use ($maps) {
        return $maps['users'][(int)$match[1]] ?? 'a resident';
    }, $details);
    $details = preg_replace_callback('/\(#?APT-[A-Z0-9-]+\)/i', function($match) use ($maps) {
        if (!preg_match('/APT-[A-Z0-9-]+/i', $match[0], $reference)) return '';
        $appointment = $maps['appointments'][$reference[0]] ?? null;
        return $appointment ? '(' . ucfirst($appointment['appointment_type']) . ' appointment for ' . activityLogPersonLabel($appointment['resident_name'], $appointment['pet_name']) . ')' : '';
    }, $details);
    $details = preg_replace_callback('/\bappointment\s+#?(#?APT-[A-Z0-9-]+)/i', function($match) use ($maps) {
        $reference = ltrim(strtoupper($match[1]), '#');
        $appointment = $maps['appointments'][$reference] ?? null;
        return $appointment
            ? ucfirst($appointment['appointment_type']) . ' appointment for ' . activityLogPersonLabel($appointment['resident_name'], $appointment['pet_name'])
            : 'appointment';
    }, $details);
    $details = preg_replace('/\b(?:user|pet)\s+ID:\s*\d+\b/i', 'the resident', $details);
    $details = preg_replace('/#?APT-[A-Z0-9-]+/i', '', $details);
    return trim(preg_replace('/\s{2,}/', ' ', $details));
}

$allActUsers = [];
$auResult = $conn->query("SELECT DISTINCT u.user_id, u.full_name FROM activity_logs a LEFT JOIN users u ON a.user_id = u.user_id WHERE a.user_id IS NOT NULL ORDER BY u.full_name ASC");
if ($auResult) $allActUsers = $auResult->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Staff | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />

  <style>
    .activity-cms { background: #ffffff; }
    .al-wrap { padding: 1.5rem 2rem 3rem; background: #ffffff; }

    .table-card { display: flex; flex-direction: column; border: 1px solid #e4e4e7; border-radius: 8px; background: #ffffff; overflow: hidden; }

    /* ── Filter bar (admin style) ── */
    .al-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 0 0 12px 0; }
    .appt-search-wrap { position: relative; }
    .appt-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #71717a; pointer-events: none; }
    .appt-search-input { height: 36px; padding: 0 12px 0 36px; width: 280px; border: 1px solid #e4e4e7; border-radius: 6px; background: #fff; color: #18181b; font-family: 'Inter','Segoe UI',sans-serif; font-size: 14px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); transition: border-color .15s, box-shadow .15s; }
    .appt-search-input:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; background: #fff; }
    .appt-search-input::placeholder { color: #a1a1aa; }
    .appt-status-select { height: 36px; padding: 0 28px 0 12px; border: 1px solid #e4e4e7; border-radius: 6px; background-color: #fff; color: #18181b; font-family: 'Inter','Segoe UI',sans-serif; font-size: 14px; font-weight: 500; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); cursor: pointer; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; transition: border-color .15s, box-shadow .15s; }
    .appt-status-select:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }

    /* ── Table ── */
    .table-card .sd-data-table-wrap { overflow-x: auto; min-height: 31.5rem; flex: 1; }
    .table-card .sd-data-table { width: 100%; border-collapse: collapse; }
    .table-card .sd-data-table .sd-tr { border-bottom: 1px solid #e4e4e7; transition: background 0.12s; }
    .table-card .sd-data-table .sd-tr:last-child { border-bottom: none; }
    .table-card .sd-data-table .sd-tr.sd-last-row { border-bottom: none; }
    .table-card .sd-data-table tbody .sd-tr:hover { background: #f4f4f5; }
    .table-card .sd-th { height: 40px; padding: 0 12px; text-align: left; vertical-align: middle; font-family: 'Inter','Segoe UI',sans-serif; font-size: 12px; font-weight: 500; color: #71717a; white-space: nowrap; text-transform: none; border-bottom: 1px solid #e4e4e7; background: transparent; }
    .table-card .sd-td { padding: 12px; vertical-align: middle; font-family: 'Inter','Segoe UI',sans-serif; font-size: 14px; color: #18181b; line-height: 1.4; }

    /* ── Pagination (all-clients design) ── */
    .activity-cms .dt-pagination { display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid #e4e4e7; }
    .activity-cms .dt-pagination-right { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
    .activity-cms .dt-rows { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #71717a; }
    .activity-cms .dt-rows select { height: 32px; width: 70px; border-radius: 6px; border: 1px solid #e4e4e7; background: #fff; padding: 0 8px; font-size: 14px; color: #18181b; cursor: pointer; font-family: inherit; }
    .activity-cms .dt-rows select:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }
    .activity-cms .dt-page-label { width: 110px; text-align: center; font-size: 14px; font-weight: 500; color: #18181b; white-space: nowrap; }
    .activity-cms .dt-page-btns { display: flex; align-items: center; gap: 4px; }
    .activity-cms .dt-page-btn { height: 32px; width: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid #e4e4e7; background: #fff; color: #18181b; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .activity-cms .dt-page-btn:hover:not(:disabled) { background: #f4f4f5; }
    .activity-cms .dt-page-btn:disabled { opacity: .5; cursor: not-allowed; }

    /* ── Pagination (shadcn, inside table) ── */
    .table-card .sd-pagination { display: flex; justify-content: flex-end; align-items: center; padding: 0.8rem 1.25rem 1rem; border-top: 1px solid #e4e4e7; flex-shrink: 0; min-height: 52px; }
    .table-card .sd-pagination-content { display: flex; flex-direction: row; align-items: center; gap: 4px; margin: 0; padding: 0; list-style: none; }
    .table-card .sd-pagination-link { display: inline-flex; align-items: center; justify-content: center; height: 36px; padding: 0 16px; border: none; border-radius: 6px; background: transparent; color: #18181b; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s ease; gap: 6px; }
    .table-card .sd-pagination-link:hover { background: #f4f4f5; }
    .table-card .sd-pagination-link:disabled { opacity: 0.45; pointer-events: none; }
    .table-card .sd-pagination-link:focus-visible { outline: none; box-shadow: 0 0 0 2px #18181b; }
    .table-card .sd-pagination-link.sd-pagination-previous { padding-left: 10px; }
    .table-card .sd-pagination-link.sd-pagination-next { padding-right: 10px; }

    @media (max-width: 900px) {
      .al-wrap { padding: 1rem 0.75rem 2rem; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../includes/staff-sidebar.php'; ?>
  <main class="main-content activity-cms">
    <div class="al-wrap">
      <div class="al-filters">
        <div class="appt-search-wrap">
          <svg class="appt-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="actSearch" class="appt-search-input" placeholder="Search activity, user, or details..." value="<?php echo htmlspecialchars($actSearch); ?>">
        </div>
        <select class="appt-status-select" id="actActionFilter">
          <option value="">All Actions</option>
          <option value="Login" <?php echo $actAction==='Login'?'selected':''; ?>>Login</option>
          <option value="Logout" <?php echo $actAction==='Logout'?'selected':''; ?>>Logout</option>
          <option value="Client Updated" <?php echo $actAction==='Client Updated'?'selected':''; ?>>Client Updated</option>
          <option value="Appointment Scheduled" <?php echo $actAction==='Appointment Scheduled'?'selected':''; ?>>Appointment Scheduled</option>
          <option value="User Created" <?php echo $actAction==='User Created'?'selected':''; ?>>User Created</option>
          <option value="User Edited" <?php echo $actAction==='User Edited'?'selected':''; ?>>User Edited</option>
          <option value="User Deleted" <?php echo $actAction==='User Deleted'?'selected':''; ?>>User Deleted</option>
          <option value="Role Changed" <?php echo $actAction==='Role Changed'?'selected':''; ?>>Role Changed</option>
          <option value="Status Changed" <?php echo $actAction==='Status Changed'?'selected':''; ?>>Status Changed</option>
          <option value="Password Reset" <?php echo $actAction==='Password Reset'?'selected':''; ?>>Password Reset</option>
        </select>
        <select class="appt-status-select" id="actUserFilter">
          <option value="">All Users</option>
          <?php foreach ($allActUsers as $au): ?>
            <option value="<?php echo $au['user_id']; ?>" <?php echo $actUser==$au['user_id']?'selected':''; ?>><?php echo htmlspecialchars($au['full_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <select class="appt-status-select" id="actSourceFilter">
          <option value="">All Sources</option>
          <option value="authentication" <?php echo $actSource==='authentication'?'selected':''; ?>>Authentication</option>
          <option value="user_management" <?php echo $actSource==='user_management'?'selected':''; ?>>User Management</option>
          <option value="appointments" <?php echo $actSource==='appointments'?'selected':''; ?>>Appointments</option>
          <option value="client_management" <?php echo $actSource==='client_management'?'selected':''; ?>>Client Management</option>
          <option value="rescue_reports" <?php echo $actSource==='rescue_reports'?'selected':''; ?>>Rescue Reports</option>
          <option value="vaccination" <?php echo $actSource==='vaccination'?'selected':''; ?>>Vaccination Records</option>
          <option value="reports_analytics" <?php echo $actSource==='reports_analytics'?'selected':''; ?>>Reports & Analytics</option>
          <option value="settings" <?php echo $actSource==='settings'?'selected':''; ?>>Settings</option>
        </select>
        <div style="position:relative;display:inline-block;">
          <button id="actDateFilter" onclick="toggleActCalendar()" title="Filter by date" style="height:36px;width:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#71717a;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);<?php echo ($actDateFrom||$actDateTo)?'background:#f4f4f5;color:#18181b;border-color:#d4d4d8;':'' ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg></button>
          <div id="actCalendarPopup" style="display:none;position:absolute;top:calc(100% + 6px);right:0;z-index:9999;background:#fff;border:1px solid #e4e4e7;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,0.12);padding:16px;width:280px;font-family:'Inter','Segoe UI',sans-serif;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
              <button onclick="actCalPrev()" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#71717a;cursor:pointer;font-size:16px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
              <div style="display:flex;align-items:center;gap:4px;">
                <select id="actCalMonth" onchange="actCalRender()" style="border:none;background:transparent;font-size:14px;font-weight:600;color:#18181b;cursor:pointer;font-family:inherit;outline:none;"></select>
                <select id="actCalYear" onchange="actCalRender()" style="border:none;background:transparent;font-size:14px;font-weight:600;color:#18181b;cursor:pointer;font-family:inherit;outline:none;"></select>
              </div>
              <button onclick="actCalNext()" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#71717a;cursor:pointer;font-size:16px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:4px;">
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Su</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Mo</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Tu</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">We</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Th</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Fr</span>
              <span style="font-size:12px;font-weight:500;color:#71717a;padding:4px 0;">Sa</span>
            </div>
            <div id="actCalDays" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;"></div>
          </div>
        </div>
        <input type="hidden" id="actDateFilterHidden" value="<?php echo htmlspecialchars($actDateFrom ?: $actDateTo); ?>">
          <button class="appt-status-select" id="actClearBtn" style="display:<?php echo ($actSearch||$actAction||$actUser||$actSource||$actDateFrom||$actDateTo)?'inline-flex':'none'; ?>;padding:0 12px;gap:6px;white-space:nowrap;" onclick="clearActFilters()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear Filters</button>
      </div>

      <div class="table-card" id="recentActivitiesCard">
        <div class="sd-data-table-wrap">
        <table class="sd-data-table activities-table">
          <thead>
            <tr class="sd-tr">
              <th class="sd-th" style="width:45px;">No.</th>
              <th class="sd-th">Log ID</th>
              <th class="sd-th">User</th>
              <th class="sd-th">Role</th>
              <th class="sd-th">Action</th>
              <th class="sd-th">Details</th>
              <th class="sd-th">Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allActivity)): ?>
              <tr class="sd-tr"><td class="sd-td" colspan="7" style="text-align:center;">No recent activities found.</td></tr>
            <?php else: ?>
              <?php $actNum = 1; foreach ($allActivity as $act): ?>
                <tr class="sd-tr" data-action="<?php echo htmlspecialchars(strtolower($act['action'])); ?>">
                  <td class="sd-td" style="color:#71717a; font-size:0.85rem; font-weight:600;"><?php echo $actNum++; ?></td>
                  <td class="sd-td" style="color:#71717a;">#LOG-<?php echo str_pad($act['log_id'], 4, '0', STR_PAD_LEFT); ?></td>
                  <td class="sd-td"><strong><?php echo htmlspecialchars($act['full_name']); ?></strong></td>
                  <td class="sd-td" style="font-weight:500; color:#18181b;"><?php echo ucfirst($act['role'] ?? ''); ?></td>
                  <td class="sd-td" style="color:#18181b;"><?php echo htmlspecialchars($act['action']); ?></td>
                  <td class="sd-td" style="color:#71717a; font-size:0.9rem;"><?php echo htmlspecialchars(formatActivityLogDetails($act['details'], $activityEntityMaps)); ?></td>
                  <td class="sd-td" style="color:#71717a; font-size:0.85rem;"><?php echo date('M d, Y h:i A', strtotime($act['created_at'])); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
      <div class="dt-pagination" id="actPagination">
          <div class="dt-pagination-right">
            <div class="dt-rows">
              <span>Rows per page</span>
              <select onchange="window.actSetPerPage(this.value)">
                <option value="15">15</option>
                <option value="20">20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
              </select>
            </div>
            <div class="dt-page-label" id="actPageLabel">Page 1 of 1</div>
            <div class="dt-page-btns">
              <button class="dt-page-btn" id="actPageFirst" title="Go to first page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
              </button>
              <button class="dt-page-btn" id="actPagePrev" title="Go to previous page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <button class="dt-page-btn" id="actPageNext" title="Go to next page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
              </button>
              <button class="dt-page-btn" id="actPageLast" title="Go to last page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    var PER_PAGE_ACT = 15;
    var tbody = document.querySelector('.activities-table tbody');
    var pag = document.getElementById('actPagination');
    if (!tbody || !pag) return;
    var allRows = Array.from(tbody.querySelectorAll('tr[data-action]'));
    var currentPage = 1;

    function renderTable() {
      allRows.forEach(function(r) { r.style.display = 'none'; r.classList.remove('sd-last-row'); });
      var start = (currentPage - 1) * PER_PAGE_ACT;
      var end = start + PER_PAGE_ACT;
      var visible = allRows.slice(start, end);
      visible.forEach(function(r) { r.style.display = ''; });
      if (visible.length) visible[visible.length - 1].classList.add('sd-last-row');
      renderPagination();
    }

    function renderPagination() {
      var total = allRows.length;
      var tp = Math.max(1, Math.ceil(total / PER_PAGE_ACT));
      if (currentPage > tp) currentPage = tp;
      if (currentPage < 1) currentPage = 1;
      var first = document.getElementById('actPageFirst');
      var prev = document.getElementById('actPagePrev');
      var next = document.getElementById('actPageNext');
      var last = document.getElementById('actPageLast');
      var label = document.getElementById('actPageLabel');
      if (first) first.disabled = currentPage <= 1;
      if (prev) prev.disabled = currentPage <= 1;
      if (next) next.disabled = currentPage >= tp;
      if (last) last.disabled = currentPage >= tp;
      if (label) label.textContent = 'Page ' + currentPage + ' of ' + tp;
      if (first) first.onclick = function() { actPageGo(1); };
      if (prev) prev.onclick = function() { actPageGo(currentPage - 1); };
      if (next) next.onclick = function() { actPageGo(currentPage + 1); };
      if (last) last.onclick = function() { actPageGo(tp); };
    }

    window.actPageGo = function(page) {
      var tp = Math.ceil(allRows.length / PER_PAGE_ACT);
      if (page < 1 || page > tp) return;
      currentPage = page;
      renderTable();
    };

    window.actSetPerPage = function(v) {
      PER_PAGE_ACT = parseInt(v, 10);
      currentPage = 1;
      renderTable();
    };

    renderTable();
  });

  // ── Server-side filter URL builder ──
  function buildActUrl() {
    var p = [];
    var s = document.getElementById('actSearch').value;
    var a = document.getElementById('actActionFilter').value;
    var u = document.getElementById('actUserFilter').value;
    var src = document.getElementById('actSourceFilter').value;
    var df = document.getElementById('actDateFilterHidden').value;
    if (s) p.push('act_search=' + encodeURIComponent(s));
    if (a) p.push('act_action=' + encodeURIComponent(a));
    if (u) p.push('act_user=' + encodeURIComponent(u));
    if (src) p.push('act_source=' + encodeURIComponent(src));
    if (df) p.push('act_date=' + encodeURIComponent(df));
    return 'activity-logs.php' + (p.length ? '?' + p.join('&') : '');
  }
  function clearActFilters() { window.location.href = 'activity-logs.php'; }

  var actTimer;
  document.getElementById('actSearch').addEventListener('input', function() {
    clearTimeout(actTimer);
    actTimer = setTimeout(function() { window.location.href = buildActUrl(); }, 500);
  });
  document.getElementById('actActionFilter').addEventListener('change', function() { window.location.href = buildActUrl(); });
  document.getElementById('actUserFilter').addEventListener('change', function() { window.location.href = buildActUrl(); });
  document.getElementById('actSourceFilter').addEventListener('change', function() { window.location.href = buildActUrl(); });

  // ── Custom calendar ──
  var actCalDate = (function() {
    var v = document.getElementById('actDateFilterHidden').value;
    if (v) { var p = v.split('-'); return new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2])); }
    return new Date();
  })();
  var actCalOpen = false;
  var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function actCalInit() {
    var ms = document.getElementById('actCalMonth');
    var ys = document.getElementById('actCalYear');
    ms.innerHTML = '';
    months.forEach(function(m, i) { ms.innerHTML += '<option value="'+i+'">'+m+'</option>'; });
    ys.innerHTML = '';
    var cy = new Date().getFullYear();
    for (var y = cy - 5; y <= cy + 5; y++) { ys.innerHTML += '<option value="'+y+'">'+y+'</option>'; }
  }

  function actCalRender() {
    var ms = document.getElementById('actCalMonth');
    var ys = document.getElementById('actCalYear');
    var m = parseInt(ms.value);
    var y = parseInt(ys.value);
    actCalDate.setMonth(m);
    actCalDate.setFullYear(y);
    var firstDay = new Date(y, m, 1).getDay();
    var daysInMonth = new Date(y, m + 1, 0).getDate();
    var daysInPrev = new Date(y, m, 0).getDate();
    var today = new Date();
    var sel = document.getElementById('actDateFilterHidden').value;
    var selDate = sel ? new Date(sel + 'T00:00:00') : null;
    var html = '';
    var day = 1;
    var nextDay = 1;
    for (var row = 0; row < 6; row++) {
      for (var col = 0; col < 7; col++) {
        var idx = row * 7 + col;
        if (idx < firstDay) {
          var d = daysInPrev - firstDay + idx + 1;
          html += '<button onclick="actCalSelect('+y+','+m+','+d+',true)" style="width:32px;height:32px;border-radius:8px;border:none;background:transparent;color:#a1a1aa;font-size:13px;cursor:pointer;font-family:inherit;">'+d+'</button>';
        } else if (day <= daysInMonth) {
          var isToday = day === today.getDate() && m === today.getMonth() && y === today.getFullYear();
          var isSelected = selDate && day === selDate.getDate() && m === selDate.getMonth() && y === selDate.getFullYear();
          var bg = isSelected ? '#18181b' : isToday ? '#f4f4f5' : 'transparent';
          var clr = isSelected ? '#fff' : '#18181b';
          var fw = isToday && !isSelected ? 600 : 400;
          html += '<button onclick="actCalSelect('+y+','+m+','+day+',false)" style="width:32px;height:32px;border-radius:8px;border:none;background:'+bg+';color:'+clr+';font-size:13px;cursor:pointer;font-family:inherit;font-weight:'+fw+'">'+day+'</button>';
          day++;
        } else {
          html += '<button onclick="actCalSelect('+y+','+m+','+nextDay+',true)" style="width:32px;height:32px;border-radius:8px;border:none;background:transparent;color:#a1a1aa;font-size:13px;cursor:pointer;font-family:inherit;">'+nextDay+'</button>';
          nextDay++;
        }
      }
      if (day > daysInMonth && row > 3) break;
    }
    document.getElementById('actCalDays').innerHTML = html;
    ms.value = m;
    ys.value = y;
  }

  function actCalSelect(y, m, d, overflow) {
    if (overflow) {
      if (d > 15) { m--; if (m < 0) { m = 11; y--; } } else { m++; if (m > 11) { m = 0; y++; } }
    }
    var dt = new Date(y, m, d);
    var str = dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
    document.getElementById('actDateFilterHidden').value = str;
    window.location.href = buildActUrl();
  }

  function actCalPrev() {
    var ms = document.getElementById('actCalMonth');
    var ys = document.getElementById('actCalYear');
    var m = parseInt(ms.value) - 1;
    var y = parseInt(ys.value);
    if (m < 0) { m = 11; y--; }
    ms.value = m;
    ys.value = y;
    actCalRender();
  }

  function actCalNext() {
    var ms = document.getElementById('actCalMonth');
    var ys = document.getElementById('actCalYear');
    var m = parseInt(ms.value) + 1;
    var y = parseInt(ys.value);
    if (m > 11) { m = 0; y++; }
    ms.value = m;
    ys.value = y;
    actCalRender();
  }

  function toggleActCalendar() {
    var pop = document.getElementById('actCalendarPopup');
    actCalOpen = !actCalOpen;
    if (actCalOpen) {
      actCalInit();
      document.getElementById('actCalMonth').value = actCalDate.getMonth();
      document.getElementById('actCalYear').value = actCalDate.getFullYear();
      actCalRender();
      pop.style.display = 'block';
    } else {
      pop.style.display = 'none';
    }
  }

  document.addEventListener('click', function(e) {
    if (!e.target.closest('#actCalendarPopup') && !e.target.closest('#actDateFilter')) {
      document.getElementById('actCalendarPopup').style.display = 'none';
      actCalOpen = false;
    }
  });
  </script>
</body>
</html>
