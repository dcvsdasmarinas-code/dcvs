<?php
session_name('DCVS_BARANGAY_SESSION');
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['barangay_id']) || ($_SESSION['role'] ?? '') !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
loadDcvsEnv();
$barangayResponseEnabled = env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1';
$responseTargetHours = max(1, (int)env('DCVS_RESPONSE_TARGET_HOURS', '24'));
$progressTargetHours = max(1, (int)env('DCVS_PROGRESS_TARGET_HOURS', '48'));

$userId     = (int)$_SESSION['user_id'];
$barangayId = (int)$_SESSION['barangay_id'];
$fullName   = $conn->real_escape_string($_SESSION['full_name'] ?? 'Barangay Officer');
$barangayName = 'Barangay';
$barangayDistrict = '';

$bq = $conn->prepare("SELECT barangay_name, district FROM barangays WHERE barangay_id = ?");
if ($bq) {
    $bq->bind_param('i', $barangayId);
    $bq->execute();
    $bRow = $bq->get_result()->fetch_assoc();
    if ($bRow) {
        $barangayName     = str_replace('±', 'ñ', (string)$bRow['barangay_name']);
        $barangayDistrict = str_replace('±', 'ñ', (string)$bRow['district']);
    }
    $bq->close();
}

$typeLabels   = ['stray' => 'Stray', 'rabies' => 'Rabies', 'injured' => 'Injured', 'dead' => 'Dead'];
$statusLabels = [
  'pending' => 'Pending Barangay Review', 'validated' => 'Pending DCVS Review',
    'confirmed' => 'In progress', 'scheduled' => 'In progress',
    'resolved' => 'Resolved', 'rejected' => 'Closed',
];

$reports = [];
$rq = $conn->prepare("
    SELECT r.report_id, r.reference_id, r.report_type, r.animal_type, r.animal_breed, r.animal_count, r.animal_behavior,
           r.barangay_validation_status, r.urgency_level, r.status,
           r.location_address, r.description, r.photo_url, r.video_url, r.rejection_reason,
           r.incident_at, r.created_at, r.validated_at, r.confirmed_at, r.updated_at, cc.resolved_at,
           r.barangay_notes, r.dcvs_notes, rw.response_mode, rw.state AS response_state,
           rw.response_deadline, rw.progress_deadline, rw.last_progress_at, rw.turnover_deadline,
           rw.holding_location, rw.animal_condition, rw.secured_at,
           rr.resolution_details, rr.animal_disposition, rr.resolved_at AS response_resolved_at,
           tt.status AS turnover_status, tt.due_at AS turnover_due_at, tt.received_at AS turnover_received_at,
           u.full_name AS reporter_name, u.avatar_url AS reporter_avatar,
           u.reference_id AS reporter_ref, u.phone AS reporter_phone, u.email AS reporter_email,
           v.full_name AS validator_name
    FROM reports r
    JOIN users u ON r.reporter_id = u.user_id
    LEFT JOIN users v ON r.validated_by = v.user_id
    LEFT JOIN confirmed_cases cc ON r.report_id = cc.report_id
    LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
    LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
    LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id
    WHERE r.barangay_id = ? AND r.archived_at IS NULL
    ORDER BY r.created_at DESC
");
$rq->bind_param('i', $barangayId);
$rq->execute();
$rResult = $rq->get_result();
while ($rRow = $rResult->fetch_assoc()) {
  $reportedAt = !empty($rRow['incident_at']) ? $rRow['incident_at'] : $rRow['created_at'];
    $reports[] = [
        'id'            => $rRow['reference_id'],
        'report_id'     => (int)$rRow['report_id'],
        'category'      => $typeLabels[$rRow['report_type']] ?? ucfirst($rRow['report_type']),
        'type'          => $rRow['report_type'],
        'structured'    => $rRow['animal_type'] !== null && $rRow['animal_type'] !== '',
        'animalType'    => (string)($rRow['animal_type'] ?? ''),
        'animalBreed'   => (string)($rRow['animal_breed'] ?? ''),
        'animalCount'   => (string)($rRow['animal_count'] ?? ''),
        'animalBehavior'=> (string)($rRow['animal_behavior'] ?? ''),
        'location'      => $rRow['location_address'],
        'description'   => $rRow['description'],
        'photo'         => $rRow['photo_url'] ?: '',
        'video'         => $rRow['video_url'] ?: '',
        'rejection'     => $rRow['rejection_reason'] ?: '',
        'barangay_notes'=> $rRow['barangay_notes'] ?: '',
        'dcvsNotes'     => (string)($rRow['dcvs_notes'] ?? ''),
        'reporter'      => $rRow['reporter_name'],
        'avatar'        => $rRow['reporter_avatar'] ?: '',
        'reporterRef'   => $rRow['reporter_ref'] ?: '',
        'phone'         => (string)($rRow['reporter_phone'] ?? ''),
        'email'         => (string)($rRow['reporter_email'] ?? ''),
        'brgy'          => $barangayName,
        'submitted'     => date('M j, Y g:i A', strtotime($rRow['created_at'])),
        'incidentDate'  => date('M j, Y', strtotime($reportedAt)),
        'priority'      => ucfirst($rRow['urgency_level']),
        'urgency'       => $rRow['urgency_level'],
        'fieldStatus'   => (string)($rRow['barangay_validation_status'] ?? ''),
        'status'        => ($rRow['response_state'] ?? '') === 'barangay_response_approved'
            ? (($rRow['barangay_validation_status'] ?? '') === 'site_visit_pending'
                ? 'Accepted by Barangay · Site visit pending'
                : 'Accepted by Barangay')
            : ($statusLabels[$rRow['status']] ?? ucfirst($rRow['status'])),
        'assignedTo'    => $rRow['validator_name'] ?: 'Unassigned',
        'dateResolved'  => $rRow['resolved_at'] ? date('M j, Y g:i A', strtotime($rRow['resolved_at'])) : '-',
        '_created_at'   => $rRow['created_at'],
        '_incident_at'  => $reportedAt,
        '_validated_at' => $rRow['validated_at'],
        '_confirmed_at' => $rRow['confirmed_at'],
        '_updated_at'   => $rRow['updated_at'],
        '_db_status'    => $rRow['status'],
        '_validator'    => $rRow['validator_name'] ?: '',
        'responseState' => (string)($rRow['response_state'] ?? ''),
        'responseMode' => (string)($rRow['response_mode'] ?? ''),
        'responseDeadline' => (string)($rRow['response_deadline'] ?? ''),
        'progressDeadline' => (string)($rRow['progress_deadline'] ?? ''),
        'lastProgressAt' => (string)($rRow['last_progress_at'] ?? ''),
        'turnoverDeadline' => (string)($rRow['turnover_deadline'] ?? ''),
        'holdingLocation' => (string)($rRow['holding_location'] ?? ''),
        'animalCondition' => (string)($rRow['animal_condition'] ?? ''),
        'securedAt' => !empty($rRow['secured_at']) ? date('M j, Y g:i A', strtotime($rRow['secured_at'])) : '',
        'resolutionDetails' => (string)($rRow['resolution_details'] ?? ''),
        'animalDisposition' => (string)($rRow['animal_disposition'] ?? ''),
        'responseResolvedAt' => (string)($rRow['response_resolved_at'] ?? ''),
        'turnoverStatus' => (string)($rRow['turnover_status'] ?? ''),
        'turnoverDueAt' => (string)($rRow['turnover_due_at'] ?? ''),
        'turnoverReceivedAt' => (string)($rRow['turnover_received_at'] ?? ''),
    ];
}
$rq->close();

$responseHistoryByReport = [];
if ($barangayResponseEnabled) {
    $historyQuery = $conn->prepare("
        SELECT h.report_id, h.actor_role, h.action, h.from_state, h.to_state, h.reason, h.created_at,
               u.full_name AS actor_name
        FROM report_response_history h
        JOIN reports r ON r.report_id = h.report_id
        LEFT JOIN users u ON u.user_id = h.actor_id
        WHERE r.barangay_id = ? AND r.archived_at IS NULL
        ORDER BY h.created_at ASC, h.history_id ASC
    ");
    if (!$historyQuery) {
        throw new RuntimeException('Could not prepare Barangay response history: ' . $conn->error);
    }
    $historyQuery->bind_param('i', $barangayId);
    if (!$historyQuery->execute()) {
        $historyQuery->close();
        throw new RuntimeException('Could not load Barangay response history: ' . $conn->error);
    }
    $historyResult = $historyQuery->get_result();
    while ($historyRow = $historyResult->fetch_assoc()) {
        $historyReportId = (int)$historyRow['report_id'];
        $historyCreatedAt = strtotime((string)$historyRow['created_at']);
        $responseHistoryByReport[$historyReportId][] = [
            'actorRole' => (string)$historyRow['actor_role'],
            'actorName' => (string)($historyRow['actor_name'] ?: ucfirst((string)$historyRow['actor_role'])),
            'action' => (string)$historyRow['action'],
            'fromState' => (string)($historyRow['from_state'] ?? ''),
            'toState' => (string)$historyRow['to_state'],
            'reason' => (string)($historyRow['reason'] ?? ''),
            'createdAt' => $historyCreatedAt ? date('M j, Y g:i A', $historyCreatedAt) : (string)$historyRow['created_at'],
        ];
    }
    $historyQuery->close();
}
foreach ($reports as &$report) {
    $report['responseHistory'] = $responseHistoryByReport[$report['report_id']] ?? [];
}
unset($report);

$avatarUrl = '';
$aq = $conn->prepare("SELECT avatar_url FROM users WHERE user_id = ?");
if ($aq) {
    $aq->bind_param('i', $userId);
    $aq->execute();
    $aRow = $aq->get_result()->fetch_assoc();
    if ($aRow) $avatarUrl = $aRow['avatar_url'] ?? '';
    $aq->close();
}

$unreadNotifs = 0;
$uq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
if ($uq) {
    $uq->bind_param('i', $userId);
    $uq->execute();
    $unreadNotifs = (int)$uq->get_result()->fetch_row()[0];
    $uq->close();
}

$activePage = 'reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Reports — <?php echo htmlspecialchars('BRGY. ' . strtoupper($barangayName)); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; -webkit-font-smoothing: antialiased; }
    body { background: #fff; font-family: 'Inter', 'Segoe UI', sans-serif; }

    /* ── App wrapper (mirrors dashboard-barangay.php) ── */
    .app-wrapper{min-height:100vh;display:flex;flex-direction:column;background:#fff;}
    body.sb2-active .app-wrapper{padding-left:var(--sidebar-w);transition:padding-left .2s ease;}
    body.sb2-active .app-wrapper .topbar{left:auto!important;}
    body.sb2-active .app-wrapper .main-content{margin-left:0!important;}

    /* ── Topbar ── */
    .topbar{height:64px;background:#fff;border-bottom:1px solid #e4e4e7;display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:40;}
    .topbar-left{display:flex;align-items:center;gap:16px;}
    .topbar-toggle{display:none;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;color:#18181b;cursor:pointer;}
    @media(max-width:768px){.topbar-toggle{display:flex!important;}}
    .topbar-breadcrumb{display:flex;align-items:center;gap:8px;font-size:0.82rem;color:#94a3b8;}
    .breadcrumb-root{color:#0B4D26;font-weight:600;}
    .breadcrumb-sep{font-size:0.6rem;}
    .breadcrumb-current{color:#0f172a;font-weight:600;}
    .barangay-selector{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;background:#fff;border:1px solid #e4e4e7;border-radius:999px;font-size:14px;font-weight:500;color:#18181b;cursor:pointer;transition:all .15s;}
    .barangay-selector:hover{background:#f4f4f5;}
    .topbar-right{display:flex;align-items:center;gap:14px;}
    .notif-dropdown{position:relative;}
    .notif-btn{background:none;border:none;font-size:1.2rem;color:#475569;cursor:pointer;padding:6px;position:relative;border-radius:8px;transition:background .15s;width:36px;height:36px;display:flex;align-items:center;justify-content:center;}
    .notif-btn:hover{background:#f1f5f9;}
    .notif-badge{position:absolute;top:0;right:0;background:#dc2626;color:#fff;font-size:.6rem;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;line-height:1;}
    .notif-panel{position:absolute;top:calc(100% + 8px);right:0;width:380px;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);border:1px solid #e2e8f0;z-index:200;display:none;overflow:hidden;}
    .notif-panel.open{display:block;}
    .notif-panel-header{display:flex;justify-content:space-between;align-items:center;padding:.9rem 1rem;border-bottom:1px solid #e2e8f0;}
    .notif-panel-header h4{margin:0;font-size:.85rem;font-weight:700;color:#0f172a;}
    .notif-panel-header a{font-size:.75rem;color:#0E8131;text-decoration:none;font-weight:600;cursor:pointer;}
    .notif-panel-header a:hover{text-decoration:underline;}
    .notif-panel-body{max-height:360px;overflow-y:auto;}
    .notif-item{display:flex;gap:.75rem;padding:.85rem 1rem;cursor:pointer;transition:background .12s;border-bottom:1px solid #f1f5f9;}
    .notif-item:hover{background:#f8fafc;}
    .notif-item.unread{background:#f0fdf4;}
    .notif-item.unread:hover{background:#dcebe0;}
    .notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;background:transparent;color:#000;}
    .notif-content{flex:1;min-width:0;}
    .notif-title{font-size:.8rem;font-weight:700;color:#0f172a;}
    .notif-msg{font-size:.74rem;color:#64748b;line-height:1.3;margin-top:1px;}
    .notif-time{font-size:.65rem;color:#94a3b8;margin-top:3px;}
    .notif-empty{text-align:center;padding:2rem;color:#94a3b8;font-size:.82rem;}
    @media(max-width:640px){.notif-panel{width:calc(100vw - 2rem)!important;right:.5rem!important;}}
    .user-profile{display:flex;align-items:center;gap:10px;padding:4px 8px;border-radius:6px;cursor:pointer;transition:all .2s cubic-bezier(.4,0,.2,1);border:1px solid transparent;}
    .user-profile:hover{background:#F3F4F6;border-color:#F0F2F5;}
    .user-avatar{width:34px;height:34px;border-radius:50%;background:#D5ECE0;border:1px solid #B8DEC9;display:flex;align-items:center;justify-content:center;color:#0B4D26;font-weight:700;font-size:12px;overflow:hidden;}
    .user-meta{display:flex;flex-direction:column;line-height:1.25;}
    .user-name{font-size:14px;font-weight:600;color:#18181B;}
    .user-role{font-size:12px;color:#71717A;}

    /* ── Main content ── */
    .main-content { padding: 1.25rem 2rem 1.75rem; margin-top: 0 !important; }

    .page-header{display:flex;justify-content:flex-end;margin-bottom:1rem;}

    /* ── Card header (search + filters + buttons) ── */
    .appt-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1rem;}
    .appt-header-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .appt-search-wrap{display:flex;align-items:center;gap:8px;}
    .appt-search-input{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;min-width:200px;}
    .appt-search-input:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}
    .appt-search-input::placeholder{color:#a1a1aa;}
    .appt-status-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;cursor:pointer;outline:none;transition:border-color .15s;}
    .appt-status-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}

    /* ── Buttons ── */
    .pet-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:32px;padding:0 14px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#71717a;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;text-decoration:none;}
    .pet-action-btn:hover{background:#f4f4f5;color:#18181b;border-color:#d4d4d8;}
    .pet-action-btn.primary-btn{background:#18181b;color:#fff;border-color:#18181b;}
    .pet-action-btn.primary-btn:hover{background:#27272a;}
    .export-csv-btn{height:36px;padding:0 16px;border-color:#e4e4e7;background:#fff;color:#18181b;gap:8px;font-size:14px;font-weight:500;box-shadow:0 1px 2px rgba(0,0,0,.05);}
    .export-csv-btn:hover{background:#fafafa;color:#18181b;border-color:#d4d4d8;}
    .export-csv-btn svg{width:14px;height:14px;flex-shrink:0;}

    /* ── Bulk selection ── */
    .bulk-bar{display:none;align-items:center;gap:12px;margin-bottom:4px;font-family:'Inter','Segoe UI',sans-serif;}
    .bulk-bar.active{display:flex;}
    .bulk-bar .bulk-count{font-size:14px;color:#71717a;white-space:nowrap;margin-right:4px;}
    .bulk-bar .bulk-actions{display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;}
    .bulk-bar .bulk-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:30px;padding:0 12px;border-radius:5px;border:none;background:transparent;color:#52525b;font-size:13px;font-weight:500;font-family:'Inter','Segoe UI',sans-serif;cursor:pointer;transition:all .15s;}
    .bulk-bar .bulk-btn:hover{background:#f4f4f5;color:#18181b;}
    .bulk-bar .bulk-btn.danger{color:#dc2626;}
    .bulk-bar .bulk-btn.danger:hover{background:#fef2f2;}
    .bulk-bar .bulk-btn svg{width:14px;height:14px;flex-shrink:0;}

    /* ── Table ── */
    .appt-db-table-area{background:#fff;border:1px solid #e4e4e7;border-radius:8px;overflow-x:auto;}
    .appt-table{width:100%;border-collapse:collapse;font-family:'Inter','Segoe UI',sans-serif;}
    .appt-table thead th{padding:10px 12px;font-size:14px;font-weight:500;color:#71717a;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;white-space:nowrap;}
    .appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:default;transition:background .1s;}
    .appt-table tbody tr:last-child{border-bottom:none;}
    .appt-table tbody tr:hover{background:#f9fafb;}
    .appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
    .appt-table tbody td.sd-td-action{text-align:right;}
    .sd-table-cb{width:16px;height:16px;accent-color:#18181b;cursor:pointer;}

    /* ── Sort buttons ── */
    .sd-th{position:relative;}
    .th-sort{display:inline-flex;align-items:center;gap:6px;height:28px;margin-left:-12px;padding:0 12px;border-radius:6px;background:transparent;border:none;font-size:14px;font-weight:500;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;transition:background .1s,color .1s;}
    .th-sort:hover{background:#e4e4e7;color:#18181b;}
    .th-sort.th-sorted{color:#18181b;}
    .th-sort-icon{width:14px;height:14px;color:#a1a1aa;flex-shrink:0;}
    .th-sorted .th-sort-icon{color:#18181b;}

    /* ── Client cell (ID + type) ── */
    .client-cell{display:flex;flex-direction:column;gap:2px;}
    .client-name{font-weight:600;color:#18181b;font-size:14px;}
    .report-id-value,.location-value{font-weight:400;color:#18181b;font-size:14px;}
    .contact-main{font-size:14px;font-weight:400;color:#18181b;}

    /* ── Status pill ── */
    .appt-status-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;border:1px solid #e4e4e7;font-family:'Inter','Segoe UI',sans-serif;line-height:1.5;}

    .tag-stray{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
    .tag-rabies{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
    .tag-injured{background:#fffbeb;color:#92400e;border-color:#fde68a;}
    .tag-dead{background:#f4f4f5;color:#52525b;border-color:#e4e4e7;}

    /* ── Pagination ── */
    .appt-pagination{display:flex;align-items:center;justify-content:flex-end;padding:12px 16px;border-top:1px solid #e4e4e7;}
    .dt-pagination-right{display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
    .dt-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a;}
    .dt-rows select{height:32px;width:70px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;padding:0 8px;font-size:14px;color:#18181b;cursor:pointer;font-family:inherit;}
    .dt-rows select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
    .dt-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
    .dt-page-btns{display:flex;align-items:center;gap:4px;}
    .dt-page-btn{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04);}
    .dt-page-btn:hover:not(:disabled){background:#f4f4f5;}
    .dt-page-btn:disabled{opacity:.5;cursor:not-allowed;}

    /* ── Action dropdown (same three-dot UI as Staff Portal All Reports) ── */
    .action-dropdown{position:static;display:inline-block;}
    .sd-row-actions{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border-radius:6px;background:transparent;border:none;color:#71717a;cursor:pointer;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1;transition:all .15s;}
    .sd-row-actions:hover{background:#f4f4f5;color:#18181b;}
    .sd-row-actions .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
    .action-menu{position:fixed;z-index:9999;min-width:190px;background:#ffffff;border:1px solid #e6e9ee;border-radius:10px;box-shadow:0 4px 14px rgba(15,23,32,.06);padding:6px;display:none;}
    .action-menu.open{display:block;}
    .action-menu-item{display:flex;align-items:center;gap:10px;width:100%;min-height:36px;padding:9px 10px;border:none;border-radius:8px;background:none;font-family:'Inter','Segoe UI',system-ui,sans-serif;font-size:13px;font-weight:500;color:#334155;cursor:pointer;text-align:left;line-height:1.4;text-decoration:none;}
    .action-menu-item:hover{background:#f8fafc;}
    .action-menu-item svg{flex-shrink:0;width:15px;height:15px;}
    .action-menu-item.danger{color:#dc2626;}
.action-menu-item.danger:hover{background:#fef2f2;}



    /* ── Modal (detail) ── */
    .modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);z-index:300;align-items:center;justify-content:center;padding:16px;}
    .modal.open{display:flex;}
    #responseWorkflowModal.modal.active{display:flex;}
    #responseWorkflowModal .response-workflow-dialog{width:min(440px,calc(100vw - 32px));max-width:min(440px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;padding:22px;border:1px solid #e4e4e7;border-radius:12px;background:#fff;box-shadow:0 18px 48px rgba(0,0,0,.24);}
    #responseWorkflowModal .response-workflow-dialog.is-alert{width:min(440px,calc(100vw - 32px));max-width:min(440px,calc(100vw - 32px));gap:16px;padding:24px;border-color:rgba(15,23,42,.08);box-shadow:0 10px 15px -3px rgba(15,23,42,.1),0 4px 6px -4px rgba(15,23,42,.1);font-family:'Inter','Segoe UI',sans-serif;}
    #responseWorkflowModal .response-workflow-dialog.is-alert #responseWorkflowForm{margin:0;}
    #responseWorkflowModal .response-workflow-dialog.is-alert #responseWorkflowFields:empty{display:none;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .response-workflow-field{display:block;margin:0 0 12px;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1.4;color:#18181b;}
    #responseWorkflowModal .response-workflow-required{color:#dc2626;}
    #responseWorkflowModal .response-workflow-check-description{margin:10px 0 12px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;color:#71717a;}
    #responseWorkflowModal .response-workflow-check-description input{accent-color:#18181b;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .response-workflow-field input,#responseWorkflowModal .response-workflow-dialog.is-alert .response-workflow-field textarea,#responseWorkflowModal .response-workflow-dialog.is-alert .response-workflow-field select{display:block;width:100%;min-height:38px;margin-top:5px;padding:8px 12px;border:1px solid #d4d4d8;border-radius:7px;background:#fff;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;color:#18181b;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .response-workflow-field textarea{min-height:82px;resize:vertical;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .response-workflow-field input[type="file"]{height:auto;padding:7px;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .sd-alert-dialog-footer{margin:4px -24px -24px;padding:14px 24px 18px;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .sd-alert-dialog-footer .sd-btn{display:inline-flex;min-height:36px;align-items:center;justify-content:center;padding:0 14px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1;cursor:pointer;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .sd-alert-dialog-footer .sd-btn:hover{background:#f4f4f5;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .sd-alert-dialog-footer #responseWorkflowSubmit{border-color:#18181b;background:#18181b;color:#fff;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .sd-alert-dialog-footer #responseWorkflowSubmit:hover{background:#27272a;}
    #responseWorkflowModal .response-workflow-dialog.is-alert .sd-alert-dialog-footer .sd-btn:disabled{cursor:wait;opacity:.6;}
    #responseWorkflowModal .response-workflow-field{display:block;margin:0 0 12px;font-size:13px;font-weight:600;color:#27272a;}
    #responseWorkflowModal .response-workflow-field textarea,#responseWorkflowModal .response-workflow-field input,#responseWorkflowModal .response-workflow-field select{display:block;width:100%;min-height:38px;margin-top:5px;padding:8px;border:1px solid #d4d4d8;border-radius:7px;background:#fff;color:#18181b;font:inherit;}
    #responseWorkflowModal .response-workflow-field textarea{min-height:82px;resize:vertical;}
    #responseWorkflowModal .response-workflow-dialog .sd-dialog-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #e4e4e7;}
    .sd-dialog{width:100%;display:flex;justify-content:center;}
    .sd-dialog-content{position:relative;width:100%;max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);animation:modalIn 0.2s ease;max-height:90vh;overflow-y:auto;}
    .sd-dialog-close{position:absolute;top:16px;right:16px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;border:none;background:transparent;color:#71717a;cursor:pointer;transition:all .15s ease;}
    .sd-dialog-close:hover{background:#f4f4f5;color:#18181b;}
    .sd-dialog-header{display:flex;flex-direction:column;gap:6px;text-align:left;}
    .sd-dialog-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1;letter-spacing:-0.025em;color:#18181b;}
    .sd-dialog-body{display:flex;flex-direction:column;gap:16px;}
    .sd-dialog-footer{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding-top:16px;border-top:1px solid #e4e4e7;}
    @keyframes modalIn{from{opacity:0;transform:scale(0.95);}to{opacity:1;transform:scale(1);}}

    /* ── shadcn/ui Alert Dialog (Reject / Validate Report) ── */
    .sd-alert-dialog-content{position:relative;box-sizing:border-box;width:min(560px,calc(100vw - 32px));max-width:560px;background:#ffffff;border:1px solid rgba(15,23,42,0.08);border-radius:12px;padding:24px;display:flex;flex-direction:column;gap:16px;box-shadow:0 10px 15px -3px rgba(15,23,42,0.1),0 4px 6px -4px rgba(15,23,42,0.1);animation:modalIn 0.2s ease;max-height:min(90vh,900px);overflow:hidden;font-family:'Inter','Segoe UI',sans-serif;}
    .sd-alert-dialog-header{display:flex;flex-direction:column;gap:6px;text-align:left;}
    .sd-alert-dialog-title{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;line-height:1.2;letter-spacing:-0.02em;color:#18181b;}
    .sd-alert-dialog-description{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;color:#71717a;}
    .sd-alert-dialog-body{display:flex;flex:1;min-height:0;flex-direction:column;gap:16px;overflow-y:auto;padding-right:2px;}
    .sd-alert-dialog-footer{display:flex;flex:0 0 auto;align-items:center;justify-content:flex-end;gap:8px;margin:0 -24px -24px;padding:14px 24px 18px;border-top:1px solid #e4e4e7;background:#fff;}
    .validation-context{display:flex;flex-wrap:wrap;align-items:center;gap:7px 9px;padding:10px 12px;border:1px solid #e4e4e7;border-radius:8px;background:#f4f4f5;color:#334155;font-size:13px;line-height:1.5;}
    .validation-context-id{color:#18181b;font-weight:600;}
    .validation-context-details{color:#71717a;}
    .validation-context-priority{display:inline-flex;align-items:center;padding:1px 9px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;font-size:12px;font-weight:500;line-height:1.5;}
    .validation-context-priority.is-high,.validation-context-priority.is-critical{border-color:#fecaca;background:#fef2f2;color:#991b1b;}
    .validation-context-priority.is-medium{border-color:#fde68a;background:#fffbeb;color:#92400e;}
    .validation-route-fieldset{border:0;padding:0;margin:0;}
    .validation-route-grid{display:grid;grid-template-columns:1fr;gap:7px;}
    .validation-route-option{display:flex;align-items:flex-start;gap:9px;padding:10px 11px;border:1px solid #e4e4e7;border-radius:8px;cursor:pointer;font-size:13px;line-height:1.4;transition:border-color .15s,background .15s,box-shadow .15s;}
    .validation-route-option:has(input:checked){border-color:#16a34a;background:#f0fdf4;box-shadow:0 0 0 1px #16a34a;}
    .validation-route-option input{margin:3px 0 0;accent-color:#16a34a;flex:0 0 auto;}
    .validation-route-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:2px;}
    .validation-route-copy strong{color:#18181b;font-weight:600;}
    .validation-route-copy>span{color:#71717a;font-size:12px;font-weight:400;line-height:1.4;}
    .validation-route-meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px;}
    .validation-route-meta span{padding:2px 7px;border:1px solid #e4e4e7;border-radius:999px;background:#fff;color:#52525b;font-size:11px;font-weight:500;line-height:1.35;}
    .validation-status-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
    .validation-field-label{display:block;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1.4;color:#18181b;}
    .validation-form-control{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;}
    .validation-status-option{display:flex;align-items:flex-start;gap:9px;min-height:58px;padding:10px 11px;border:1px solid #e4e4e7;border-radius:8px;cursor:pointer;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1.4;transition:border-color .15s,background .15s,box-shadow .15s;}
    .validation-status-option:has(input:checked){border-color:#18181b;background:#fafafa;box-shadow:0 0 0 1px #18181b;}
    .validation-status-option input{margin-top:2px;accent-color:#18181b;flex:0 0 auto;}
    .validation-status-copy{display:flex;flex-direction:column;gap:3px;}
    .validation-status-copy strong{font-weight:500;color:#18181b;}
    .validation-status-copy span{font-size:12px;font-weight:400;line-height:1.4;color:#71717a;}
    .validation-risk-check{display:flex;flex-direction:column;gap:3px;padding:10px 12px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;color:#166534;font-size:12px;line-height:1.45;}
    .validation-risk-check.is-high-risk{border-color:#fecaca;background:#fef2f2;color:#991b1b;}
    .validation-risk-title{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;}
    .validation-risk-copy{font-weight:400;}
    .validation-warning{padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;line-height:1.45;}
    .validation-note-suggestions{display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:6px;}
    .validation-note-suggestions button{padding:3px 9px;border:1px solid #e4e4e7;border-radius:999px;background:#fff;color:#3f3f46;font-family:inherit;font-size:11px;font-weight:500;line-height:1.35;cursor:pointer;}
    .validation-note-suggestions button:hover{border-color:#a1a1aa;background:#f4f4f5;}
    .validation-note-meta-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:5px;}
    .validation-upload{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;min-height:120px;padding:18px;border:1px dashed #a1a1aa;border-radius:8px;background:#fafafa;text-align:center;cursor:pointer;transition:border-color .15s,background .15s;}
    .validation-upload:hover,.validation-upload.is-dragging{border-color:#16a34a;background:#f0fdf4;}
    .validation-upload svg{color:#71717a;}
    .validation-upload strong{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;color:#27272a;}
    .validation-upload-hint,.validation-upload-meta{font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;line-height:1.4;color:#71717a;}
    .validation-file-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;}
    .validation-notes-meta{display:flex;justify-content:flex-end;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;color:#71717a;}
    @media(max-width:600px){.sd-alert-dialog-content{padding:18px;max-height:calc(100dvh - 24px);}.sd-alert-dialog-footer{margin:0 -18px -18px;padding:12px 18px 14px;}.sd-alert-dialog-footer .sd-btn{min-width:0;padding:0 10px;font-size:12px;}.validation-status-grid{grid-template-columns:1fr;}.validation-status-option{min-height:0;}}

    .sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 16px;border-radius:6px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:all .15s;border:1px solid transparent;text-decoration:none;}
    .sd-btn-primary{background:#18181b;color:#fff;border-color:#18181b;}
    .sd-btn-primary:hover{background:#27272a;}
    .sd-btn-secondary{background:#fff;color:#18181b;border-color:#e4e4e7;}
    .sd-btn-secondary:hover{background:#f4f4f5;}
    .sd-btn-danger{background:#dc2626;color:#fff;border-color:#dc2626;}
    .sd-btn-danger:hover{background:#b91c1c;}
    .sd-field-group{display:flex;flex-direction:column;gap:16px;}
    .sd-section{margin-bottom:16px;}
    .sd-section:last-child{margin-bottom:0;}
    .sd-section-title{display:flex;align-items:center;gap:8px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;color:#18181b;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f4f4f5;}
    .sd-section-title span{color:#71717a;font-size:12px;font-weight:600;}
    .sd-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;}
.sd-info-item .sd-info-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;}
.sd-info-item .sd-info-value{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin-top:2px;word-break:break-word;}

    /* Report Details drawer: same proportions and behavior as the Staff Portal drawer. */
    #reportDetailModal.modal{align-items:stretch;justify-content:flex-end;padding:0;background:rgba(0,0,0,.5);backdrop-filter:none;}
    #reportDetailModal .sd-dialog{height:100%;width:100%;display:flex;justify-content:flex-end;}
    #reportDetailModal .sd-dialog-content{max-width:min(480px,92vw);width:min(480px,92vw);height:100%;max-height:none;border:none;border-radius:0;box-shadow:-8px 0 40px rgba(15,23,32,.18);animation:none;transform:translateX(105%);transition:transform .32s cubic-bezier(.32,.72,.25,1);padding:24px;gap:18px;overflow:hidden;}
    #reportDetailModal.open .sd-dialog-content{transform:translateX(0);}
    #reportDetailModal .sd-field-group{flex:1;min-height:0;overflow-y:auto;gap:0;}
    #reportDetailModal .sd-dialog-body{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;gap:0;scrollbar-gutter:stable;}
    #reportDetailModal .sd-dialog-header{flex-shrink:0;}
    #reportDetailModal .sd-dialog-description{margin:0;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.5;color:#71717a;}
    #reportDetailModal .sd-dialog-footer{position:relative;flex-shrink:0;width:100%;margin-top:auto;padding:12px 0 0;border-top:1px solid #e4e4e7;justify-content:flex-start;}
    .report-details-scope{font-family:'Inter','Segoe UI',sans-serif;}
    .report-details-scope .report-summary{display:flex;align-items:center;gap:10px;min-width:0;margin:0 0 14px;}
    .report-details-scope .report-summary-avatar{width:85px;height:85px;flex:0 0 85px;overflow:hidden;border-radius:8px;background:#e4e4e7;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:18px;font-weight:600;}
    .report-details-scope .report-summary-avatar img{width:100%;height:100%;object-fit:cover;}
    .report-details-scope .report-summary-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:2px;}
    .report-details-scope .report-summary-label{font-size:12px;font-weight:400;color:#71717a;}
    .report-details-scope .report-summary-name{overflow:hidden;color:#18181b;font-size:14px;font-weight:600;line-height:1.4;letter-spacing:normal;text-overflow:ellipsis;white-space:nowrap;}
    .report-details-scope .report-summary-status{display:flex;flex:0 1 44%;flex-direction:column;align-items:center;justify-content:center;gap:1px;max-width:44%;padding:5px 8px;border:1px solid;border-radius:6px;font-size:12px;font-weight:500;line-height:1.35;text-align:center;white-space:normal;}
    .report-details-scope .report-summary-status span{display:block;}
    .report-details-scope .report-summary-status.is-compact{flex:0 0 auto;max-width:none;padding:6px 12px;font-size:14px;line-height:1.4;white-space:nowrap;}
    .report-details-scope .report-summary-status.is-stack{flex:0 0 auto;max-width:none;align-items:flex-end;gap:4px;padding:0;border:0;background:transparent;}
    .report-details-scope .report-summary-status.is-stack .response-status-chip{padding:4px 9px;border:1px solid;border-radius:6px;font-size:12px;font-weight:600;line-height:1.25;white-space:nowrap;}
    .report-details-scope .response-status-chip.is-resolved{padding:5px 8px;border-color:#0E8131;border-radius:6px;background:#0E8131;color:#fff;font-size:12px;font-weight:500;line-height:1.35;}
    .report-details-scope .response-status-chip.is-pending{border-color:#fcd34d;background:#fef3c7;color:#92400e;}
    .report-details-scope .response-status-chip.is-turned-over{border-color:#86efac;background:#4ade80;color:#fff;}
    .report-details-scope .response-status-chip.is-dcvs{border-color:#fbbf24;background:#fef3c7;color:#92400e;}
    .report-details-scope .rpt-tabs{display:flex;gap:4px;border-bottom:1px solid #e4e4e7;margin-bottom:18px;}
    .report-details-scope .rpt-tab{border:0;border-bottom:2px solid transparent;background:transparent;color:#71717a;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;padding:8px 12px;margin-bottom:-1px;cursor:pointer;}
    .report-details-scope .rpt-tab:hover{color:#18181b;}
    .report-details-scope .rpt-tab.active{color:#18181b;border-bottom-color:#18181b;font-weight:600;}
    .report-details-scope .rpt-detail-body{display:flex;flex-direction:column;gap:0;}
    .report-details-scope .rpt-section-title{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;color:#18181b;text-transform:none;letter-spacing:0;margin:0 0 10px;}
    .report-details-scope .rpt-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
    .report-details-scope .rpt-col-full{grid-column:1/-1;}
    .report-details-scope .rpt-info-row{padding:8px 0;border-bottom:1px solid #f4f4f5;}
    .report-details-scope .rpt-info-row:last-child{border-bottom:0;}
    .report-details-scope .rpt-info-label{font-size:12px;font-weight:400;color:#71717a;}
    .report-details-scope .rpt-info-value{font-size:14px;font-weight:600;color:#18181b;margin-top:2px;word-break:break-word;}
    .report-details-scope .rpt-desc-box{background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;}
    .report-details-scope .rpt-attachments{display:flex;flex-direction:column;gap:8px;}
    .report-details-scope .rpt-attachments img,.report-details-scope .rpt-attachments video{width:100%;max-height:260px;object-fit:cover;border:1px solid #e4e4e7;border-radius:6px;background:#000;}
    .report-details-scope .rpt-tabs{display:flex;justify-content:center;gap:28px;border-bottom:1px solid #e4e4e7;margin-bottom:14px;}
    .report-details-scope .rpt-tab{padding:0 0 12px;font-size:14px;font-weight:600;color:#a1a1aa;cursor:pointer;border-bottom:2px solid transparent;background:none;border-top:0;border-left:0;border-right:0;font-family:inherit;margin:0;}
    .report-details-scope .rpt-tab.active{color:#18181b;border-bottom-color:#18181b;}
    .report-details-scope .rpt-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px 18px;align-items:start;}
    .report-details-scope .rpt-full-col{grid-column:1/-1;}
    .report-details-scope .rpt-detail-grid .rpt-section-title{margin-top:6px;}
    .report-details-scope .info-rows{display:flex;flex-direction:column;gap:12px;margin-bottom:0;}
    .report-details-scope .info-rows.info-rows-2col{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px;align-items:start;}
    .report-details-scope .info-row{display:flex;align-items:flex-start;gap:11px;}
.report-details-scope .info-row-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;margin-bottom:2px;}
.report-details-scope .info-row-value{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;word-break:break-word;}
    .report-details-scope .response-history{list-style:none;margin:0;padding:0;}
    .report-details-scope .response-history-item{position:relative;padding:0 0 16px 18px;}
    .report-details-scope .response-history-item:last-child{padding-bottom:0;}
    .report-details-scope .response-history-item::before{content:"";position:absolute;left:0;top:6px;width:8px;height:8px;border-radius:50%;background:#16a34a;}
    .report-details-scope .response-history-title{font-size:14px;font-weight:500;color:#18181b;line-height:1.45;}
    .report-details-scope .response-history-meta{margin-top:3px;font-size:14px;font-weight:400;color:#71717a;line-height:1.45;}
    .report-details-scope .response-history-reason{margin-top:5px;font-size:13px;font-weight:400;color:#52525b;line-height:1.45;white-space:pre-wrap;word-break:break-word;}
    .report-details-scope .response-history-empty{margin:0;font-size:14px;font-weight:400;color:#71717a;line-height:1.5;}
    .report-details-scope .response-card{overflow:hidden;border:1px solid #e4e4e7;border-radius:9px;background:#fff;}
    .report-details-scope .response-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:12px 12px 10px;}
    .report-details-scope .response-card-heading{min-width:0;flex:1;}
    .report-details-scope .response-card-title{font-size:14px;font-weight:600;color:#18181b;line-height:1.25;letter-spacing:-.025em;}
    .report-details-scope .response-card-subtitle{margin-top:2px;font-size:12px;font-weight:400;color:#71717a;}
    .report-details-scope .response-deadline{flex:0 1 auto;max-width:58%;border-radius:6px;background:#eff6ff;color:#1d4ed8;padding:4px 7px;font-size:12px;font-weight:500;line-height:1.35;text-align:center;overflow-wrap:anywhere;}
    .report-details-scope .response-deadline.is-overdue{background:#fef2f2;color:#b91c1c;}
    .report-details-scope .response-deadline.is-resolved{background:#dcfce7;color:#15803d;}
    .report-details-scope .response-steps{display:flex;align-items:flex-start;padding:3px 12px 11px;}
    .report-details-scope .response-step{position:relative;display:flex;flex:1;flex-direction:column;align-items:center;gap:6px;text-align:center;color:#a1a1aa;font-size:11px;font-weight:500;line-height:1.35;}
    .report-details-scope .response-step:not(:last-child)::after{position:absolute;z-index:0;top:8px;left:calc(50% + 10px);width:calc(100% - 20px);height:2px;content:"";background:#d4d4d8;}
    .report-details-scope .response-step.is-complete:not(:last-child)::after{background:#22c55e;}
    .report-details-scope .response-step-dot{position:relative;z-index:1;display:flex;width:18px;height:18px;flex:0 0 18px;align-items:center;justify-content:center;border:2px solid #d4d4d8;border-radius:50%;background:#fff;color:#fff;}
    .report-details-scope .response-step.is-complete .response-step-dot{border-color:#16a34a;background:#16a34a;}
    .report-details-scope .response-step.is-current{color:#18181b;font-weight:600;}
    .report-details-scope .response-step.is-current .response-step-dot{border-color:#16a34a;background:#fff;}
    .report-details-scope .response-step.is-overdue .response-step-dot{border-color:#ef4444;background:#fff;}
    .report-details-scope .response-step>span:last-child{max-width:100%;overflow-wrap:anywhere;}
    .report-details-scope .response-notice{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:400;line-height:1.5;}
    .report-details-scope .response-notice-icon{flex:0 0 auto;margin-top:2px;font-size:13px;}
    .report-details-scope .response-notice-text{min-width:0;}
    .report-details-scope .response-notice.is-overdue{background:#fef2f2;color:#b91c1c;}
    .report-details-scope .response-notice.is-warning{background:#fef3c7;color:#92400e;}
    .report-details-scope .response-notice.is-complete{background:#dcfce7;color:#166534;}
    .report-details-scope .response-route-link{margin:0 12px 12px;padding:0;border:0;background:none;color:#2563eb;font:600 12px 'Inter','Segoe UI',sans-serif;cursor:pointer;}
    .report-details-scope .response-custody{margin-top:10px;overflow:hidden;border:1px solid #e4e4e7;border-radius:9px;}
    .report-details-scope .response-custody-title{padding:9px 12px;background:#f4f4f5;font-size:12px;font-weight:600;color:#18181b;}
    .report-details-scope .response-custody-body{padding:8px 12px;}
    .report-details-scope .response-custody-row{display:flex;justify-content:space-between;gap:12px;padding:4px 0;font-size:12px;line-height:1.4;}
    .report-details-scope .response-custody-row span:first-child{color:#71717a;}
    .report-details-scope .response-custody-row span:last-child{text-align:right;color:#18181b;}
    .report-details-scope .response-updates{margin:14px 0 12px;}
    .report-details-scope .response-updates-header{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:4px 0 12px;font-size:14px;font-weight:600;color:#18181b;line-height:1.4;}
    .report-details-scope .response-updates-header button{padding:0;border:0;background:none;color:#2563eb;font:500 12px 'Inter','Segoe UI',sans-serif;cursor:pointer;}
    .response-action-bar{display:flex;width:100%;align-items:center;gap:8px;}
    .response-action-more{display:inline-flex;width:42px;height:40px;flex:0 0 42px;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:8px;background:#fff;color:#18181b;font-size:17px;cursor:pointer;}
    .response-action-more:hover,.response-action-more[aria-expanded="true"]{background:#f4f4f5;}
    .response-action-primary{height:40px;min-width:0;flex:1;white-space:normal;line-height:1.25;}
    .response-action-close{width:100%;height:40px;flex:1;}
    .response-action-menu{position:absolute;left:0;bottom:calc(100% + 8px);z-index:5;width:min(300px,calc(100vw - 40px));padding:6px;border:1px solid #d4d4d8;border-radius:10px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.18);}
    .response-action-menu[hidden]{display:none;}
    .response-action-menu button{display:flex;width:100%;flex-direction:column;align-items:flex-start;gap:2px;padding:9px 10px;border:0;border-radius:6px;background:transparent;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;text-align:left;cursor:pointer;}
    .response-action-menu button:hover{background:#f4f4f5;}
    .response-action-menu button.is-danger{color:#dc2626;}
    .response-action-menu button strong{font-size:12px;font-weight:600;line-height:1.4;}
    .response-action-menu button span{color:#71717a;font-size:12px;font-weight:400;line-height:1.4;}
    .response-action-menu button.is-danger span{color:#71717a;}
    .report-details-scope .rpt-detail-body .rpt-section-title{font-size:14px;font-weight:600;color:#18181b;text-transform:none;letter-spacing:0;margin:18px 0 10px;}
    .report-details-scope .rpt-detail-body[data-tab="details"]{display:flex;flex-direction:column;flex:1;}
    .report-details-scope .rpt-photo-box{display:flex;flex-direction:column;gap:10px;}
    .report-details-scope .rpt-photo-thumb{width:100%;height:auto;border-radius:10px;border:1px solid #e4e4e7;overflow:hidden;cursor:pointer;background:#f4f4f5;display:block;}
    .report-details-scope .rpt-photo-thumb img{width:100%;height:auto;object-fit:cover;display:block;}
    .report-details-scope .rpt-photo-thumb.rpt-video-thumb{background:#000;cursor:default;}
    .report-details-scope .rpt-photo-thumb video{width:100%;display:block;background:#000;}
    @media(max-width:600px){.report-details-scope .rpt-detail-grid{grid-template-columns:1fr;}.report-details-scope .info-rows.info-rows-2col{grid-template-columns:1fr;}}
    @media(max-width:520px){#reportDetailModal .sd-dialog-content{max-width:100%;width:100%;padding:20px;}}

    .sd-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:500;line-height:1;color:#18181b;}
    .sd-input,.sd-select{height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:0 12px;font-size:14px;font-family:'Inter','Segoe UI',sans-serif;color:#18181b;background:#fff;outline:none;transition:border-color .15s;width:100%;}
    .sd-input:focus,.sd-select:focus{border-color:#045e1f;box-shadow:0 0 0 3px rgba(4,94,31,.1);}

    /* ── Toast ── */
    .toast-notification{position:fixed;bottom:24px;right:24px;background:#111827;color:#fff;padding:12px 18px;border-radius:8px;font-size:14px;font-weight:500;box-shadow:0 10px 15px -3px rgba(0,0,0,.2);display:flex;align-items:center;gap:10px;z-index:400;transform:translateY(100px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);}
    .toast-notification.show{transform:translateY(0);opacity:1;}

    @media(max-width:768px){.topbar{padding:0 16px;}.main-content{padding:1rem;}.appt-card-header{flex-direction:column;align-items:stretch;}.appt-header-right{flex-wrap:wrap;}}
  </style>
</head>
<body>

<?php include 'includes/barangay-sidebar.php'; ?>

<div class="app-wrapper">
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Barangay Portal</span>
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="breadcrumb-current" id="breadcrumbCurrent"><?php
          $statusParam = $_GET['status'] ?? '';
          $breadcrumbLabels = [
            'pending' => 'Pending Barangay Review',
            'validated' => 'Pending DCVS Review',
            'resolved'  => 'Resolved Cases',
          ];
          echo htmlspecialchars($breadcrumbLabels[$statusParam] ?? 'All Reports');
        ?></span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="notif-dropdown" id="notifDropdown">
        <button class="notif-btn" id="notif-btn" type="button" title="<?php echo $unreadNotifs; ?> unread notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="notif-badge" id="notifBadge" data-realtime="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadNotifs > 9 ? '9+' : $unreadNotifs; ?></span>
        </button>
        <div class="notif-panel" id="notifPanel" data-notification-panel data-portal="barangay"
          data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
          data-action-url="includes/staff-notification-action.php?portal=barangay"
          data-load-url="get-notifications.php?limit=30" data-app-base-url="">
          <div class="notif-panel-header">
            <h4>Notifications</h4>
            <button class="notif-mark-all" type="button" <?php echo $unreadNotifs === 0 ? 'disabled' : ''; ?>><i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read</button>
          </div>
          <div class="notif-tabs" role="tablist" aria-label="Notification filters">
            <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
            <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $unreadNotifs > 0 ? ($unreadNotifs > 99 ? '99+' : $unreadNotifs) : ''; ?></span></button>
            <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
          </div>
          <div class="notif-feedback" role="status" aria-live="polite"></div>
          <div class="notif-panel-body" id="notifList">
            <div class="notif-empty">Loading notifications…</div>
          </div>
        </div>
      </div>
      <div class="user-profile" id="user-profile-btn" title="View Profile">
        <div class="user-avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
          <?php endif; ?>
        </div>
        <div class="user-meta">
          <span class="user-name"><?php echo htmlspecialchars($fullName); ?></span>
          <span class="user-role">Barangay Officer</span>
        </div>
      </div>
    </div>
  </header>

  <main class="main-content">

    <div class="appt-card-header">
      <div class="appt-header-right">
        <div class="appt-search-wrap">
          <input type="text" id="searchInput" class="appt-search-input" placeholder="Search by ID, category, location..." />
        </div>
        <select class="appt-status-select" id="statusFilter">
          <option value="">All Status</option>
          <option value="pending">Pending Barangay Review</option>
          <option value="validated">Pending DCVS Review</option>
          <option value="confirmed">In progress</option>
          <option value="resolved">Resolved</option>
          <option value="rejected">Closed</option>
        </select>
        <select class="appt-status-select" id="categoryFilter">
          <option value="">All Categories</option>
          <option value="stray">Stray</option>
          <option value="rabies">Rabies</option>
          <option value="injured">Injured</option>
          <option value="dead">Dead</option>
        </select>
        <select class="appt-status-select" id="priorityFilter">
          <option value="">All Priority</option>
          <option value="high">High</option>
          <option value="medium">Medium</option>
          <option value="low">Low</option>
        </select>
        <button class="pet-action-btn" id="clearFiltersBtn" style="display:none;" onclick="clearAllFilters()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Clear Filters
        </button>
      </div>
      <button class="pet-action-btn export-csv-btn" type="button" onclick="exportAll()"><span>Export CSV</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
    </div>

    <div class="bulk-bar" id="bulkBar">
      <div class="bulk-actions">
        <span class="bulk-count"><span id="bulkCount">0</span> reports selected</span>
        <button class="bulk-btn" onclick="bulkValidate()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Validate</button>
        <button class="bulk-btn" onclick="bulkReject()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> Reject</button>
        <button class="bulk-btn" onclick="bulkArchive()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg> Archive</button>
        <button class="bulk-btn danger" onclick="bulkDelete()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Delete</button>
      </div>
    </div>

    <div class="appt-db-table-area" id="tableWrap">
      <table class="appt-table">
        <thead>
          <tr>
            <th style="width:40px;"><input type="checkbox" class="sd-table-cb" id="selectAll" onclick="toggleSelectAll(this)" /></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="id" onclick="TBL.sortBy('id')">Report ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="category" onclick="TBL.sortBy('category')">Category<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th>Location</th>
            <th>Reporter</th>
            <th>Contact</th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="incident" onclick="TBL.sortBy('incident')">Incident Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="priority" onclick="TBL.sortBy('priority')">Priority<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="TBL.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th>Date Resolved</th>
            <th style="width:48px;"></th>
          </tr>
        </thead>
        <tbody id="reportsTableBody"></tbody>
      </table>
    </div>
    <div id="reportsPagination" class="appt-pagination"></div>

  </main>
</div><!-- /app-wrapper -->

<!-- Report Detail Modal -->
<div class="modal" id="reportDetailModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content">
      <button class="sd-dialog-close" onclick="closeModal('reportDetailModal')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Report Details</h2>
        <p class="sd-dialog-description">Review report information and case details.</p>
        <span id="detail-id" style="display:none;">RPT-0000-0</span>
        <span id="detail-category" style="display:none;">Incident Details</span>
      </div>
      <div class="sd-dialog-body report-details-scope" id="detail-body"></div>
      <div class="sd-dialog-footer" id="detail-footer"></div>
    </div>
  </div>
</div>

<!-- Confirm Modal -->
<div class="modal" id="confirmModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:420px;">
      <div style="text-align:center;">
        <div id="confirmIcon" style="margin-bottom:14px;">
          <i class="fas fa-exclamation-triangle" style="font-size:40px;color:#f59e0b;"></i>
        </div>
        <div id="confirmText" style="font-size:14px;color:#71717a;line-height:1.5;">Are you sure?</div>
      </div>
      <div class="sd-dialog-footer" style="justify-content:center;">
        <button type="button" class="sd-btn sd-btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-primary" id="confirmSubmitBtn"><i class="fas fa-check"></i> Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Barangay field validation dialog -->
<div class="modal" id="validateAlertDialog">
  <div class="sd-dialog">
    <form class="sd-alert-dialog-content" id="validationForm" role="dialog" aria-modal="true" aria-labelledby="validateAlertTitle" aria-describedby="validateAlertDesc" enctype="multipart/form-data" style="max-width:560px;">
      <input type="hidden" name="report_id" value="">
      <input type="hidden" name="action" value="validate">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="sd-alert-dialog-header">
        <h3 class="sd-alert-dialog-title" id="validateAlertTitle">Validate Report</h3>
        <p class="sd-alert-dialog-description" id="validateAlertDesc">Record what you found on site. If the incident has not been visited yet, select Site visit pending.</p>
      </div>
      <div class="sd-alert-dialog-body">
        <div class="validation-context" id="validationReportContext"></div>
        <fieldset style="border:0;padding:0;margin:0;">
          <legend class="validation-field-label" style="margin-bottom:8px;">Field status <span style="color:#dc2626;" aria-hidden="true">*</span></legend>
          <div class="validation-status-grid">
            <label class="validation-status-option"><input type="radio" name="field_status" value="site_visit_pending" required checked><span class="validation-status-copy"><strong>Site visit pending</strong><span>The incident has not been checked on site yet.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="confirmed_on_site" required><span class="validation-status-copy"><strong>Confirmed on site</strong><span>Animal and location were verified.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="urgent_response"><span class="validation-status-copy"><strong>Needs urgent response</strong><span>Immediate assistance is needed.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="animal_already_gone"><span class="validation-status-copy"><strong>Animal already gone</strong><span>Animal was no longer at the location.</span></span></label>
            <label class="validation-status-option"><input type="radio" name="field_status" value="wrong_or_unclear_location"><span class="validation-status-copy"><strong>Wrong / unclear location</strong><span>Could not verify the reported location.</span></span></label>
          </div>
        </fieldset>
        <fieldset class="validation-route-fieldset">
          <legend class="validation-field-label" style="margin-bottom:8px;">Who will handle this case? <span style="color:#dc2626;" aria-hidden="true">*</span></legend>
          <div class="validation-route-grid">
            <label class="validation-route-option">
              <input type="radio" name="barangay_will_respond" value="0" required>
              <span class="validation-route-copy"><strong>Forward to DCVS</strong><span>DCVS reviews the report and assigns its own personnel.</span></span>
            </label>
            <?php if ($barangayResponseEnabled): ?>
            <label class="validation-route-option validation-route-barangay">
              <input type="radio" name="barangay_will_respond" value="1" required>
              <span class="validation-route-copy">
                <strong>Barangay will respond</strong>
                <span>Your barangay is assigned now. Start the response when the team is heading to the incident. No DCVS approval needed.</span>
                <span class="validation-route-meta">
                  <span>First response within <?php echo $responseTargetHours; ?> hours</span>
                  <span>Progress update every <?php echo $progressTargetHours; ?> hours</span>
                </span>
              </span>
            </label>
            <?php endif; ?>
          </div>
        </fieldset>
        <div class="validation-risk-check" id="validationRiskCheck" role="status" aria-live="polite">
          <strong class="validation-risk-title"><span aria-hidden="true" id="validationRiskIcon">&#10003;</span><span id="validationRiskTitle">No high-risk flags</span></strong>
          <span class="validation-risk-copy" id="validationRiskCopy">Review the report details before selecting who will handle the case.</span>
        </div>
        <div class="validation-note-field">
          <label class="validation-field-label" for="validationNotes">Review notes / Message to DCVS <span style="color:#dc2626;" aria-hidden="true">*</span></label>
          <textarea class="sd-input validation-form-control" id="validationNotes" name="validation_notes" rows="4" maxlength="500" required placeholder="Add review notes or describe your on-site findings..." style="height:auto;padding:10px 12px;margin-top:7px;resize:vertical;"></textarea>
          <span class="validation-note-meta-row">
            <span class="validation-note-suggestions" aria-label="Suggested validation notes">
              <button type="button" data-validation-note="Report details reviewed; site visit is pending.">Site visit pending</button>
              <button type="button" data-validation-note="Residents nearby were warned.">Residents nearby were warned</button>
              <button type="button" data-validation-note="Needs veterinary assessment.">Needs vet assessment</button>
            </span>
            <span class="validation-notes-meta"><span id="validationNotesCount">0</span>/500</span>
          </span>
        </div>
        <label class="validation-field-label" for="validationAttachments">Photo / Video evidence <span style="font-weight:400;color:#71717a;">Optional</span>
          <span class="validation-upload" id="validationUploadZone">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
            <strong>Drop photos or videos here, or click to browse</strong>
            <span class="validation-upload-hint">JPG, PNG, or MP4 · Up to 6 files · 25 MB each</span>
            <span class="validation-upload-meta" id="validationUploadMeta">No files selected</span>
            <input class="validation-file-input" id="validationAttachments" name="validation_attachments[]" type="file" accept="image/jpeg,image/png,video/mp4" multiple aria-label="Choose photo or video evidence">
          </span>
        </label>
        <div class="validation-warning" id="validationRoutingHint" hidden></div>
      </div>
      <div class="sd-alert-dialog-footer">
        <button type="button" class="sd-btn sd-btn-secondary" id="validateAlertCancel">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-primary" id="validateAlertConfirm">Validate &amp; Forward to DCVS</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Report AlertDialog (shadcn/ui) -->
<div class="modal" id="rejectAlertDialog">
  <div class="sd-dialog">
    <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="rejectAlertTitle" aria-describedby="rejectAlertDesc">
      <div class="sd-alert-dialog-header">
        <h3 class="sd-alert-dialog-title" id="rejectAlertTitle">Reject Report</h3>
        <p class="sd-alert-dialog-description" id="rejectAlertDesc">Are you sure you want to reject this report? This action will update the report status.</p>
      </div>
      <div class="sd-alert-dialog-body">
        <label class="sd-label" for="rejectReasonInput">Rejection Reason</label>
        <textarea class="sd-input" id="rejectReasonInput" rows="3" placeholder="State the reason for rejecting..." style="height:auto;padding:8px 12px;"></textarea>
      </div>
      <div class="sd-alert-dialog-footer">
        <button type="button" class="sd-btn sd-btn-secondary" id="rejectAlertCancel">Cancel</button>
        <button type="button" class="sd-btn sd-btn-danger" id="rejectAlertConfirm">Reject Report</button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Archive Reports AlertDialog (shadcn/ui) -->
<div class="modal" id="archiveAlertDialog">
  <div class="sd-dialog">
    <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="archiveAlertTitle" aria-describedby="archiveAlertDesc">
      <div class="sd-alert-dialog-header">
        <h3 class="sd-alert-dialog-title" id="archiveAlertTitle">Archive Reports</h3>
        <p class="sd-alert-dialog-description" id="archiveAlertDesc">Are you sure you want to archive the selected reports? They will be moved out of the active list.</p>
      </div>
      <div class="sd-alert-dialog-footer">
        <button type="button" class="sd-btn sd-btn-secondary" id="archiveAlertCancel">Cancel</button>
        <button type="button" class="sd-btn sd-btn-primary" id="archiveAlertConfirm">Archive</button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Delete Reports AlertDialog (shadcn/ui) -->
<div class="modal" id="deleteAlertDialog">
  <div class="sd-dialog">
    <div class="sd-alert-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="deleteAlertTitle" aria-describedby="deleteAlertDesc">
      <div class="sd-alert-dialog-header">
        <h3 class="sd-alert-dialog-title" id="deleteAlertTitle">Delete Reports</h3>
        <p class="sd-alert-dialog-description" id="deleteAlertDesc">Are you sure you want to permanently delete the selected reports? This cannot be undone.</p>
      </div>
      <div class="sd-alert-dialog-footer">
        <button type="button" class="sd-btn sd-btn-secondary" id="deleteAlertCancel">Cancel</button>
        <button type="button" class="sd-btn sd-btn-danger" id="deleteAlertConfirm">Delete Permanently</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-notification" id="toast-msg"><i class="fas fa-check-circle"></i><span id="toast-text"></span></div>

<script>
var REPORTS_DATA = <?php echo json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.DCVS_BARANGAY_RESPONSE_ENABLED = <?php echo $barangayResponseEnabled ? 'true' : 'false'; ?>;
window.DCVS_RESPONSE_ROLE = 'barangay';
window.DCVS_RESPONSE_CSRF = <?php echo json_encode(csrfToken()); ?>;
var currentSelectedReport = null;

function getFilteredList() {
  var s = document.getElementById('searchInput').value.toLowerCase();
  var st = document.getElementById('statusFilter').value;
  var cat = document.getElementById('categoryFilter').value;
  var pri = document.getElementById('priorityFilter').value;
  return REPORTS_DATA.filter(function(r) {
    if (st && (r._db_status || '').toLowerCase() !== st.toLowerCase()) return false;
    if (cat && r.type.toLowerCase() !== cat.toLowerCase()) return false;
    if (pri && r.urgency.toLowerCase() !== pri.toLowerCase()) return false;
    if (s && (r.id.toLowerCase().indexOf(s) !== -1 || r.category.toLowerCase().indexOf(s) !== -1 || r.location.toLowerCase().indexOf(s) !== -1 || r.dateResolved.toLowerCase().indexOf(s) !== -1 || (r.reporter || '').toLowerCase().indexOf(s) !== -1 || (r.phone || '').toLowerCase().indexOf(s) !== -1 || (r.email || '').toLowerCase().indexOf(s) !== -1)) return true;
    if (!s) return true;
    return false;
  });
}

/* ── Table body render ── */
function renderRows(list) {
  var tbody = document.getElementById('reportsTableBody');
  tbody.innerHTML = '';
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:32px 16px;color:#71717a;font-size:14px;">No reports found.</td></tr>';
    return;
  }
  list.forEach(function(r) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-rid', r.report_id);

    var pillBg, pillColor, pillBorder;
    switch (r.urgency) {
      case 'high':case 'critical': pillBg='#fee2e2';pillColor='#991b1b';pillBorder='#fecaca';break;
      case 'medium': pillBg='#fef3c7';pillColor='#92400e';pillBorder='#fde68a';break;
      default: pillBg='#eff6ff';pillColor='#1e40af';pillBorder='#bfdbfe';
    }

    var sBg, sColor, sBorder;
    var st = (r._db_status || '').toLowerCase();
    if (st === 'resolved') { sBg='#0E8131'; sColor='#ffffff'; sBorder='#0E8131'; }
    else if (st === 'validated') { sBg='#dcebe0'; sColor='#045e1f'; sBorder='#bbf7d0'; }
    else if (st === 'confirmed' || st === 'scheduled') { sBg='#EFF6FF'; sColor='#1E40AF'; sBorder='#BFDBFE'; }
    else if (st === 'rejected') { sBg='#FEE2E2'; sColor='#991B1B'; sBorder='#FECACA'; }
    else { sBg='#FEF3C7'; sColor='#92400E'; sBorder='#FDE68A'; }

    tr.innerHTML =
      '<td><input type="checkbox" class="sd-table-cb row-cb" value="' + r.report_id + '" onclick="updateBulkBar()" /></td>' +
      '<td><div class="client-cell"><span class="client-name report-id-value">' + r.id + '</span></div></td>' +
      '<td style="font-size:14px;color:#18181b;">' + r.category + '</td>' +
      '<td class="location-value">' + r.location + '</td>' +
      '<td style="font-size:14px;color:#18181b;">' + detailEsc(r.reporter || '-') + '</td>' +
      '<td><div style="font-size:14px;color:#18181b;line-height:1.4;">' + detailEsc(r.phone || '-') + '</div><div style="font-size:12.5px;color:#71717a;line-height:1.4;margin-top:2px;word-break:break-word;">' + detailEsc(r.email || '-') + '</div></td>' +
      '<td style="font-size:14px;color:#18181b;">' + r.incidentDate + '</td>' +
      '<td><span class="appt-status-pill" style="background:' + pillBg + ';color:' + pillColor + ';border-color:' + pillBorder + ';">' + r.priority + '</span></td>' +
      '<td><span class="appt-status-pill" style="background:' + sBg + ';color:' + sColor + ';border-color:' + sBorder + ';">' + r.status + '</span></td>' +
      '<td style="font-size:14px;color:' + (r.dateResolved === '-' ? '#a1a1aa' : '#18181b') + ';">' + r.dateResolved + '</td>' +
      '<td class="sd-td-action"><div class="action-dropdown"><button class="sd-row-actions" onclick="event.stopPropagation();toggleDropdown(this)" title="More options"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button><div class="action-menu">' +
      '<button class="action-menu-item" onclick="closeDropdowns();viewReportLocation(' + r.report_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>View Location</button>' +
      (r._db_status === 'pending' ? '<button class="action-menu-item" onclick="closeDropdowns();validateSingle(' + r.report_id + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Validate</button>' +
        '<button class="action-menu-item" onclick="closeDropdowns();rejectSingle(' + r.report_id + ',\'' + r.id + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>Reject</button>' : '') +
      (r._db_status === 'pending' || r._db_status === 'resolved' ? '<button class="action-menu-item danger" onclick="closeDropdowns();archiveSingle(' + r.report_id + ',\'' + r.id + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</button><button class="action-menu-item danger" onclick="closeDropdowns();deleteSingle(' + r.report_id + ',\'' + r.id + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>Delete</button>' : '') +
      '</div></div></td>';
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', function(e) {
      if (e.target.closest('.sd-table-cb') || e.target.closest('.action-dropdown')) return;
      openReportDetail(r.id);
    });
    tbody.appendChild(tr);
  });
}

/* ── Sort ── */
var TBL = (function() {
  var sortCol = '', sortDir = 'asc';
  var colIdx = {id:1, category:2, incident:6, priority:7, status:8};

  function doSort(col) {
    if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    else { sortCol = col; sortDir = 'asc'; }

    var list = getFilteredList();
    list.sort(function(a, b) {
      var va = '', vb = '';
      if (col === 'id') { va = a.id; vb = b.id; }
      else if (col === 'category') { va = a.category; vb = b.category; }
      else if (col === 'incident') { va = a._incident_at; vb = b._incident_at; }
      else if (col === 'priority') { va = a.urgency; vb = b.urgency; }
      else if (col === 'status') { va = a.status; vb = b.status; }
      va = va.toLowerCase(); vb = vb.toLowerCase();
      var res = va.localeCompare(vb);
      return sortDir === 'asc' ? res : -res;
    });

    document.querySelectorAll('.appt-table thead .th-sort').forEach(function(btn) {
      var c = btn.getAttribute('data-col');
      btn.classList.toggle('th-sorted', c === sortCol);
      var icon = btn.querySelector('.th-sort-icon');
      if (icon) {
        if (c === sortCol) {
          icon.innerHTML = sortDir === 'asc' ? '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>' : '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
        } else {
          icon.innerHTML = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
        }
      }
    });

    renderRows(list);
    paginate();
  }
  return { sortBy: doSort };
})();

/* ── Pagination ── */
var PER_PAGE = 15, currentPage = 1;
function paginate() {
  var tbody = document.getElementById('reportsTableBody');
  var pag = document.getElementById('reportsPagination');
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var total = allRows.length;
  var tp = Math.max(1, Math.ceil(total / PER_PAGE));
  if (currentPage > tp) currentPage = tp;
  if (currentPage < 1) currentPage = 1;
  var start = (currentPage - 1) * PER_PAGE;
  allRows.forEach(function(r, i) { r.style.display = (i >= start && i < start + PER_PAGE) ? '' : 'none'; });

  var h = '<div class="dt-pagination-right">';
  h += '<div class="dt-rows"><span>Rows per page</span><select onchange="setPerPage(this.value)"><option value="15"' + (PER_PAGE === 15 ? ' selected' : '') + '>15</option><option value="25"' + (PER_PAGE === 25 ? ' selected' : '') + '>25</option><option value="50"' + (PER_PAGE === 50 ? ' selected' : '') + '>50</option></select></div>';
  h += '<div class="dt-page-label">Page ' + currentPage + ' of ' + tp + '</div>';
  h += '<div class="dt-page-btns">';
  h += '<button class="dt-page-btn" onclick="goPage(1)"' + (currentPage <= 1 ? ' disabled' : '') + ' title="First"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>';
  h += '<button class="dt-page-btn" onclick="goPage(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + ' title="Prev"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
  h += '<button class="dt-page-btn" onclick="goPage(' + (currentPage + 1) + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Next"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
  h += '<button class="dt-page-btn" onclick="goPage(' + tp + ')"' + (currentPage >= tp ? ' disabled' : '') + ' title="Last"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>';
  h += '</div></div>';
  pag.innerHTML = h;
}
function goPage(p) { currentPage = p; paginate(); }
function setPerPage(v) { PER_PAGE = parseInt(v) || 15; currentPage = 1; paginate(); }

/* ── Bulk selection ── */
function toggleSelectAll(cb) {
  document.querySelectorAll('.row-cb').forEach(function(b) { b.checked = cb.checked; });
  updateBulkBar();
}
function updateBulkBar() {
  var checked = document.querySelectorAll('.row-cb:checked');
  document.getElementById('bulkCount').textContent = checked.length;
  document.getElementById('bulkBar').classList.toggle('active', checked.length > 0);
  var all = document.querySelectorAll('.row-cb');
  var sel = document.getElementById('selectAll');
  sel.checked = all.length > 0 && checked.length === all.length;
  sel.indeterminate = checked.length > 0 && checked.length < all.length;
}
function getSelectedIds() {
  var ids = [];
  document.querySelectorAll('.row-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value)); });
  return ids;
}
/* ── Bulk actions ── */
var bvQueue = [], bvDone = 0, bvTotal = 0, bvFailed = 0, bvSkipped = 0, bvRunning = false;
var brQueue = [], brDone = 0, brTotal = 0, brIndex = 0, brRunning = false;
var bulkSeqRunning = false;
var archiveDialogReports = [];

function isPendingReport(r) { return (r._db_status || '').toLowerCase() === 'pending'; }
function getSelectedReports() {
  var ids = getSelectedIds();
  return ids.map(function(id) { return REPORTS_DATA.find(function(r) { return r.report_id === id; }); }).filter(Boolean);
}

/* Bulk Validate — only Pending reports, one shadcn dialog per report */
function bulkValidate() {
  var selected = getSelectedReports();
  if (!selected.length) { showToast('Select at least one report.'); return; }
  var pending = selected.filter(isPendingReport);
  if (!pending.length) { showToast('Only Pending reports can be validated. Non-Pending reports are ignored.'); return; }
  bvQueue = pending.slice();
  bvDone = 0; bvFailed = 0;
  bvTotal = pending.length;
  bvSkipped = selected.length - pending.length;
  showBulkValidateDialog();
}
function showBulkValidateDialog() {
  var report = bvQueue[0];
  if (!report) { finishBulkValidate(); return; }
  var idx = bvTotal - bvQueue.length + 1;
  var desc = 'Complete the field validation for this report before forwarding it to DCVS.';
  if (bvTotal > 1) desc += ' Progress: ' + idx + ' of ' + bvTotal + ' selected Pending reports.';
  if (bvSkipped && idx === 1) desc += ' ' + bvSkipped + ' selected non-Pending report(s) will be skipped.';
  currentSelectedReport = report;
  openValidationForm(report, desc);
}
function submitBulkValidate() {
  submitValidate();
}
function advanceBulkValidate() {
  if (bvQueue.length) setTimeout(showBulkValidateDialog, 250);
  else finishBulkValidate();
}
function finishBulkValidate() {
  var done = bvDone, total = bvTotal, failed = bvFailed;
  bvQueue = []; bvTotal = 0; bvDone = 0; bvFailed = 0; bvSkipped = 0;
  if (done > 0 || failed > 0) {
    showToast(done + ' of ' + total + ' Pending report(s) validated.' + (failed ? ' ' + failed + ' failed.' : ''));
    setTimeout(function() { location.reload(); }, 900);
  } else {
    showToast('No reports were validated.');
  }
}
function cancelBulkValidate() {
  if (bvRunning) return;
  closeModal('validateAlertDialog');
  var done = bvDone, total = bvTotal;
  bvQueue = []; bvTotal = 0; bvDone = 0; bvFailed = 0; bvSkipped = 0;
  if (done > 0) {
    showToast('Validation cancelled. ' + done + ' of ' + total + ' validated.');
    setTimeout(function() { location.reload(); }, 900);
  } else if (total > 0) {
    showToast('Validation cancelled.');
  }
}

/* Bulk Reject — Pending reports only, existing reject AlertDialog + shared reason */
function bulkReject() {
  var selected = getSelectedReports();
  if (!selected.length) { showToast('Select at least one report.'); return; }
  var pending = selected.filter(isPendingReport);
  if (!pending.length) { showToast('Only Pending reports can be rejected. Non-Pending reports are ignored.'); return; }
  brQueue = pending.slice();
  brDone = 0; brIndex = 0;
  brTotal = pending.length;
  brRunning = false;
  var input = document.getElementById('rejectReasonInput');
  if (input) input.value = '';
  document.getElementById('rejectAlertDesc').textContent = bulkRejectDesc(0);
  var btn = document.getElementById('rejectAlertConfirm');
  btn.disabled = false; btn.textContent = 'Reject Report';
  document.getElementById('rejectAlertCancel').disabled = false;
  openModal('rejectAlertDialog');
}
function bulkRejectDesc(stepIndex) {
  if (brTotal < 2) return 'Are you sure you want to reject this report? This action will update the report status.';
  return 'Are you sure you want to reject ' + brTotal + ' selected Pending report(s)? This action will update the report status. Progress: ' + (stepIndex + 1) + ' of ' + brTotal + '.';
}
function submitBulkRejectStep() {
  var reason = (document.getElementById('rejectReasonInput') || {}).value || '';
  if (!reason.trim()) { showToast('Please state a rejection reason.'); return; }
  if (brRunning) return;
  var report = brQueue.shift();
  if (!report) { finishBulkReject(); return; }
  brRunning = true;
  brIndex++;
  var stepIdx = brIndex;
  var btn = document.getElementById('rejectAlertConfirm');
  var cancel = document.getElementById('rejectAlertCancel');
  btn.disabled = true; cancel.disabled = true; btn.textContent = 'Rejecting...';
  document.getElementById('rejectAlertDesc').textContent = bulkRejectDesc(stepIdx - 1) + ' Processing: ' + report.id + '.';
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: report.report_id, action: 'reject', reason: reason })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    brRunning = false;
    btn.disabled = false; cancel.disabled = false; btn.textContent = 'Reject Report';
    if (d.success) brDone++;
    else { showToast(d.message || ('Failed to reject ' + report.id + '.')); }
    continueBulkReject();
  }).catch(function() {
    brRunning = false;
    btn.disabled = false; cancel.disabled = false; btn.textContent = 'Reject Report';
    showToast('Network error while rejecting ' + report.id + '.');
    continueBulkReject();
  });
}
function continueBulkReject() {
  if (brQueue.length) { document.getElementById('rejectAlertDesc').textContent = bulkRejectDesc(brIndex); return; }
  finishBulkReject();
}
function finishBulkReject() {
  var done = brDone, total = brTotal;
  brQueue = []; brTotal = 0; brDone = 0; brIndex = 0; brRunning = false;
  closeModal('rejectAlertDialog');
  if (done > 0) {
    showToast(done + ' of ' + total + ' Pending report(s) rejected.');
    setTimeout(function() { location.reload(); }, 900);
  } else {
    showToast('No reports were rejected.');
  }
}
function cancelRejectDialog() {
  if (brRunning) return;
  closeModal('rejectAlertDialog');
  if (brTotal > 0) {
    var done = brDone, total = brTotal;
    brQueue = []; brTotal = 0; brDone = 0; brIndex = 0;
    if (done > 0) {
      showToast('Rejection cancelled. ' + done + ' of ' + total + ' rejected.');
      setTimeout(function() { location.reload(); }, 900);
    } else {
      showToast('Rejection cancelled.');
    }
  }
}

/* Bulk Archive / Delete — shadcn AlertDialog + existing backend flow */
function bulkArchive() {
  var reports = getSelectedReports();
  if (!reports.length) { showToast('Select at least one report.'); return; }
  archiveDialogReports = reports.slice();
  document.getElementById('archiveAlertTitle').textContent = 'Archive Reports';
  document.getElementById('archiveAlertDesc').textContent = 'Are you sure you want to archive ' + reports.length + ' selected report(s)? They will be moved out of the active list.';
  document.getElementById('archiveAlertConfirm').textContent = 'Archive';
  openModal('archiveAlertDialog');
}
function bulkDelete() {
  var reports = getSelectedReports();
  if (!reports.length) { showToast('Select at least one report.'); return; }
  document.getElementById('deleteAlertDesc').textContent = 'Are you sure you want to permanently delete ' + reports.length + ' selected report(s)? This cannot be undone.';
  document.getElementById('deleteAlertConfirm').textContent = 'Delete Permanently';
  openModal('deleteAlertDialog');
}
function runBulkSequence(action, reports, opts) {
  if (bulkSeqRunning) return;
  bulkSeqRunning = true;
  var i = 0, ok = 0, failed = 0;
  var btn = document.getElementById(opts.confirmId);
  var cancel = document.getElementById(opts.cancelId);
  var desc = document.getElementById(opts.descId);
  btn.disabled = true; cancel.disabled = true;
  function finish() {
    bulkSeqRunning = false;
    btn.disabled = false; cancel.disabled = false;
    btn.textContent = opts.confirmLabel;
    closeModal(opts.modalId);
    if (ok > 0) {
      showToast(ok + ' of ' + reports.length + ' report(s) ' + opts.verb + '.' + (failed ? ' ' + failed + ' failed.' : ''));
      setTimeout(function() { location.reload(); }, 900);
    } else {
      showToast('No reports were ' + opts.verb + '.' + (failed ? ' ' + failed + ' failed.' : ''));
    }
  }
  function step() {
    if (i >= reports.length) { finish(); return; }
    var r = reports[i];
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing ' + (i + 1) + ' of ' + reports.length;
    if (desc) desc.textContent = opts.baseDesc + ' Processing ' + (i + 1) + ' of ' + reports.length + ': ' + r.id + '.';
    fetch('validate-report.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ report_id: r.report_id, action: action })
    }).then(function(res) { return res.json(); })
    .then(function(d) {
      if (d.success) ok++;
      else { failed++; showToast(d.message || ('Failed for ' + r.id + '.')); }
      i++; setTimeout(step, 250);
    }).catch(function() {
      failed++; showToast('Network error for ' + r.id + '.');
      i++; setTimeout(step, 250);
    });
  }
  step();
}
function submitBulkArchive() {
  var reports = archiveDialogReports.slice();
  if (!reports.length) { closeModal('archiveAlertDialog'); return; }
  runBulkSequence('archive', reports, {
    modalId: 'archiveAlertDialog', descId: 'archiveAlertDesc',
    confirmId: 'archiveAlertConfirm', cancelId: 'archiveAlertCancel',
    confirmLabel: 'Archive',
    baseDesc: 'Are you sure you want to archive ' + reports.length + ' selected report(s)?',
    verb: 'archived'
  });
}
function submitBulkDelete() {
  var reports = getSelectedReports();
  if (!reports.length) { closeModal('deleteAlertDialog'); return; }
  runBulkSequence('delete', reports, {
    modalId: 'deleteAlertDialog', descId: 'deleteAlertDesc',
    confirmId: 'deleteAlertConfirm', cancelId: 'deleteAlertCancel',
    confirmLabel: 'Delete Permanently',
    baseDesc: 'Are you sure you want to permanently delete ' + reports.length + ' selected report(s)?',
    verb: 'deleted'
  });
}

/* ── Action dropdown ── */
function toggleDropdown(btn) {
  var menu = btn.parentElement.querySelector('.action-menu');
  var isOpen = menu.classList.contains('open');
  closeDropdowns();
  if (!isOpen) {
    var rect = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = (rect.bottom + 4) + 'px';
    menu.style.left = Math.max(8, rect.right - 210) + 'px';
    menu.classList.add('open');
  }
}
function closeDropdowns() { document.querySelectorAll('.action-menu.open').forEach(function(m) { m.classList.remove('open'); }); }
function viewReportLocation(reportId) {
  window.location.href = 'dashboard-barangay-geo.php?report_id=' + encodeURIComponent(reportId);
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.action-dropdown')) closeDropdowns();
  if (!e.target.closest('#detail-footer')) closeBarangayResponseMenu();
});

/* ── Report detail modal ── */
function detailEsc(value) {
  return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function barangayInfoRow(label, value) {
  return '<div class="info-row"><div><p class="info-row-label">' + detailEsc(label) + '</p><p class="info-row-value">' + detailEsc(value) + '</p></div></div>';
}
function buildReportDetailHtml(report) {
  var isPending = report._db_status === 'pending';
  var isRejected = report._db_status === 'rejected';
  var displayStatus = report._db_status === 'pending' ? 'Pending Barangay Review'
    : (report._db_status === 'validated' ? 'Pending DCVS Review' : report.status);

  var photoHtml = (report.photo || report.video)
    ? '<div class="sd-section">' +
      '<div class="sd-section-title"><span>04</span> ' + (report.video ? 'Attached Photo &amp; Video Evidence' : 'Attached Photo Evidence') + '</div>' +
      (report.photo ? '<img src="' + report.photo + '" alt="Photo" style="width:100%;border-radius:6px;border:1px solid #e4e4e7;max-height:260px;object-fit:cover;" onerror="this.style.display=\'none\'" />' : '') +
      (report.video ? '<video src="' + report.video + '" controls playsinline preload="metadata" style="width:100%;border-radius:6px;border:1px solid #e4e4e7;background:#000;display:block;' + (report.photo ? 'margin-top:8px;' : '') + '"></video>' : '') +
      '</div>' : '';

  var rejectionHtml = isRejected && report.rejection
    ? '<div class="sd-section">' +
      '<div class="sd-section-title" style="color:#991b1b;border-bottom-color:#fecaca;"><span>Rejection Reason</span></div>' +
      '<p style="font-size:14px;color:#991b1b;line-height:1.6;margin:0;">' + report.rejection + '</p></div>' : '';
  var dcvsReturnHtml = isPending && report.dcvsNotes
    ? '<div class="sd-section" style="border:1px solid #bfdbfe;border-radius:8px;padding:12px;background:#eff6ff;">' +
      '<div class="sd-section-title" style="color:#1d4ed8;">Returned by DCVS</div>' +
      '<p style="font-size:14px;color:#1e3a8a;line-height:1.6;margin:0;">' + detailEsc(report.dcvsNotes) + '</p></div>' : '';
  var responseDeadline = report.responseDeadline || report.progressDeadline
    || (report._db_status === 'resolved' && report.turnoverStatus === 'pending' ? report.turnoverDueAt : '');
  var responseDeadlineTime = responseDeadline ? new Date(responseDeadline.replace(' ', 'T')).getTime() : 0;
  var responseOverdue = !!responseDeadlineTime && responseDeadlineTime < Date.now()
    && (report._db_status !== 'resolved' || report.turnoverStatus === 'pending');
  var isResolvedWithPendingTurnover = report._db_status === 'resolved' && report.turnoverStatus === 'pending';
  var isEscalatedToDcvs = report.responseState === 'escalated_to_dcvs';
  var turnoverLabels = { pending: 'For Turnover — awaiting DCVS receipt', receipt_confirmed: 'Turnover Completed', cancelled: 'Turnover Cancelled' };
  var responseHistoryLabels = {
    accept_barangay_assignment: 'Accepted',
    forward_to_dcvs_review: 'Forwarded to DCVS review',
    start_response: 'Barangay response started',
    update_progress: 'Progress update',
    mark_secured: 'Animal secured',
    request_pickup: 'DCVS pickup requested',
    resolve_barangay: 'Incident resolved by Barangay',
    unable_to_secure: 'Escalated to DCVS',
    unable_to_locate: 'Unable to locate animal',
    confirm_turnover: 'DCVS confirmed animal receipt',
    return_to_barangay: 'Returned to Barangay',
    resume_barangay_response: 'Barangay response resumed',
    staff_decide: 'DCVS response decision',
    approve_barangay_response: 'DCVS approved Barangay response',
    authorize_high_risk_barangay_response: 'High-risk override recorded',
    return_to_barangay_for_validation: 'Returned for more information',
    reject_report: 'Report rejected'
  };
  var responseHistory = Array.isArray(report.responseHistory) ? report.responseHistory : [];
  var responseHistoryHtml = responseHistory.length
    ? '<ol class="response-history">' + responseHistory.slice().reverse().map(function(entry) {
        var title = responseHistoryLabels[entry.action] || String(entry.action || 'Case update').replace(/_/g, ' ');
        var actor = entry.actorName || entry.actorRole || 'System';
        var entryReason = entry.action === 'accept_barangay_assignment'
          ? 'Barangay accepted responsibility'
          : entry.reason;
        return '<li class="response-history-item"><div class="response-history-title">' + detailEsc(title) + '</div>' +
          '<div class="response-history-meta">by ' + detailEsc(actor) + (entry.createdAt ? ' - ' + detailEsc(entry.createdAt) : '') + '</div>' +
          (entryReason ? '<p class="response-history-reason">' + detailEsc(entryReason) + '</p>' : '') +
          '</li>';
      }).join('') + '</ol>'
    : '<p class="response-history-empty">No response updates have been recorded.</p>';
  var responseStage = report.responseState;
  var turnoverComplete = report._db_status === 'resolved' && report.turnoverStatus === 'receipt_confirmed';
  var responseStages = ['Accepted', 'Responding', 'Secured', 'Resolved', 'Turned over'];
  var completedStage = responseStage === 'barangay_response_approved' ? 0
    : (responseStage === 'barangay_responding' || responseStage === 'escalated_to_dcvs' ? 1
      : (responseStage === 'animal_secured' ? 2 : (report._db_status === 'resolved' ? (turnoverComplete ? 4 : 3) : -1)));
  var responseStepsHtml = responseStages.map(function(label, index) {
    var stepClass = index < completedStage ? ' is-complete'
      : (index === completedStage ? (responseOverdue && index === 1 ? ' is-current is-overdue' : ' is-current') : '');
    var checkIcon = index < completedStage
      ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>'
      : '';
    return '<div class="response-step' + stepClass + '"><span class="response-step-dot" aria-hidden="true">' + checkIcon + '</span><span>' + label + '</span></div>';
  }).join('');
  var deadlineLabel = responseStage === 'barangay_response_approved' ? 'Respond by'
    : (isResolvedWithPendingTurnover ? 'Turnover by' : 'Next update by');
  var deadlineText = isEscalatedToDcvs ? 'DCVS is deciding the next action'
    : (turnoverComplete && report.turnoverReceivedAt
      ? 'Receipt confirmed ' + new Date(report.turnoverReceivedAt.replace(' ', 'T')).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
      : (responseOverdue ? (report._db_status === 'resolved' ? 'Turnover overdue'
        : (responseStage === 'barangay_response_approved' ? 'Response overdue' : 'Update overdue'))
        : (responseDeadline ? deadlineLabel + ' ' + new Date(responseDeadlineTime).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
          + (isResolvedWithPendingTurnover ? ' (in ' + Math.ceil((responseDeadlineTime - Date.now()) / 3600000) + 'h)' : '') : '')));
  var noticeClass = responseOverdue ? ' is-overdue'
    : (isResolvedWithPendingTurnover ? ''
      : (turnoverComplete ? ' is-complete' : (responseStage === 'animal_secured' ? ' is-warning' : '')));
  var responseNotice = responseOverdue
    ? (report._db_status === 'resolved'
    ? 'Animal turnover is overdue. DCVS follow-up is required. The incident remains resolved; it will not close or change automatically.'
    : (responseStage === 'barangay_response_approved'
      ? 'Initial response is overdue. DCVS follow-up is required. Start responding or coordinate with DCVS. The case will not close automatically.'
      : 'Progress update is overdue. DCVS follow-up is required. Post an update to reset the next target. The case will not close automatically.'))
    : (responseStage === 'barangay_response_approved'
      ? 'Start your response when the team is heading to the location. The initial response target is ' + <?php echo json_encode($responseTargetHours); ?> + ' hours.'
      : (responseStage === 'barangay_responding'
        ? 'Keep updates coming. Post a progress update or mark the animal secured once you have custody.'
        : (responseStage === 'animal_secured'
          ? 'Record the resolution when the incident is complete. If the live animal needs turnover, create a separate DCVS task.'
          : (isResolvedWithPendingTurnover
            ? 'The incident is resolved. DCVS will confirm receipt of the animal; keep it safe until then.'
            : (turnoverComplete ? 'Case complete. DCVS confirmed receipt of the animal.' :
              (isEscalatedToDcvs ? 'Escalated to DCVS. DCVS was notified and will decide whether to take over. Stay available for questions.' : ''))))));
  var responseRouteButton = responseStage === 'barangay_response_approved'
    ? '<button type="button" class="response-route-link" onclick="viewReportLocation(' + Number(report.report_id) + ')">View report route on geo map</button>' : '';
  var custodyHtml = report.holdingLocation || report.animalCondition
    ? '<div class="response-custody"><div class="response-custody-title">Animal Custody</div><div class="response-custody-body">' +
      '<div class="response-custody-row"><span>Holding location</span><span>' + detailEsc(report.holdingLocation || '—') + '</span></div>' +
      '<div class="response-custody-row"><span>Condition</span><span>' + detailEsc(report.animalCondition || '—') + '</span></div>' +
      (report.securedAt ? '<div class="response-custody-row"><span>Secured</span><span>' + detailEsc(report.securedAt) + '</span></div>' : '') +
      '</div></div>' : '';
  var canUpdateProgress = ['barangay_responding', 'animal_secured'].indexOf(responseStage) !== -1;
  var workflowHtml = window.DCVS_BARANGAY_RESPONSE_ENABLED && report.responseMode === 'barangay_response'
    ? '<section class="rpt-col rpt-full-col">' +
      '<div class="response-card"><div class="response-card-header"><div class="response-card-heading"><div class="response-card-title">Barangay Response</div><div class="response-card-subtitle">Handled by Brgy. ' + detailEsc(report.brgy || 'Barangay') + '</div></div>' +
      (deadlineText ? '<span class="response-deadline' + (responseOverdue ? ' is-overdue' : (report._db_status === 'resolved' && turnoverComplete ? ' is-resolved' : '')) + '">' + detailEsc(deadlineText) + '</span>' : '') +
      '</div><div class="response-steps">' + responseStepsHtml + '</div>' +
      (responseNotice ? '<div class="response-notice' + noticeClass + '"><span class="response-notice-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></span><span class="response-notice-text">' + detailEsc(responseNotice) + '</span></div>' : '') +
      responseRouteButton + '</div>' +
      custodyHtml +
      (report.resolutionDetails ? '<div class="info-rows" style="margin-top:10px;">' + barangayInfoRow('Resolution details', report.resolutionDetails) + barangayInfoRow('Animal disposition', String(report.animalDisposition || '').replace(/_/g, ' ')) + '</div>' : '') +
      (report.turnoverStatus ? '<div class="info-rows" style="margin-top:10px;">' + barangayInfoRow('Turnover task', turnoverLabels[report.turnoverStatus] || report.turnoverStatus) + (report.turnoverDueAt && report.turnoverStatus === 'pending' ? barangayInfoRow('Turnover due', report.turnoverDueAt) : '') + (report.turnoverReceivedAt ? barangayInfoRow('Receipt confirmed', report.turnoverReceivedAt) : '') + '</div>' : '') +
      '</section>' : '';

  var pillBg, pillColor, pillBorder;
  switch (report._db_status) {
    case 'pending': pillBg='#fef3c7';pillColor='#92400e';pillBorder='#fde68a';break;
    case 'validated': pillBg='#dcebe0';pillColor='#045e1f';pillBorder='#bbf7d0';break;
    case 'confirmed':case 'scheduled': pillBg='#EBF5FF';pillColor='#175CD3';pillBorder='#D5E9FD';break;
    case 'resolved': pillBg='#0E8131';pillColor='#ffffff';pillBorder='#0E8131';break;
    case 'rejected': pillBg='#fee2e2';pillColor='#991b1b';pillBorder='#fecaca';break;
    default: pillBg='#f4f4f5';pillColor='#71717a';pillBorder='#e4e4e7';
  }

  var urgBg, urgColor, urgBorder;
  switch (report.urgency) {
    case 'high':case 'critical': urgBg='#fee2e2';urgColor='#991b1b';urgBorder='#fecaca';break;
    case 'medium': urgBg='#fef3c7';urgColor='#92400e';urgBorder='#fde68a';break;
    default: urgBg='#eff6ff';urgColor='#1e40af';urgBorder='#bfdbfe';
  }

  var hasWorkflowStatusStack = isResolvedWithPendingTurnover || turnoverComplete || isEscalatedToDcvs;
  var statusBadgeHtml = isResolvedWithPendingTurnover
    ? '<span class="response-status-chip is-resolved">Resolved</span><span class="response-status-chip is-pending">Turnover pending</span>'
    : (turnoverComplete
      ? '<span class="response-status-chip is-resolved">Resolved</span><span class="response-status-chip is-turned-over">Turned over</span>'
      : (isEscalatedToDcvs
        ? '<span class="response-status-chip is-dcvs">With DCVS</span>'
        : (displayStatus === 'Accepted by Barangay · Site visit pending'
          ? '<span>Accepted by Barangay ·</span><span>Site visit pending</span>'
          : '<span>' + detailEsc(displayStatus) + '</span>')));
  var statusBadgeClass = hasWorkflowStatusStack
    ? 'report-summary-status is-stack'
    : (report._db_status === 'resolved' || String(displayStatus || '').trim().toLowerCase() === 'in progress'
      ? 'report-summary-status is-compact'
      : 'report-summary-status');

  if (report.structured) {
    return '<div class="rpt-tabs">' +
      '<button class="rpt-tab active" data-tab="details" onclick="barangayDetailTab(\'details\', this)">Details</button>' +
      '<button class="rpt-tab" data-tab="attachments" onclick="barangayDetailTab(\'attachments\', this)">Attachments</button>' +
      (workflowHtml ? '<button class="rpt-tab" data-tab="updates" onclick="barangayDetailTab(\'updates\', this)">Progress updates</button>' : '') +
    '</div>' +
    '<div class="rpt-detail-body" data-tab="details">' +
      '<div class="report-summary">' +
        '<div class="report-summary-avatar">' + (report.avatar ? '<img src="' + detailEsc(report.avatar) + '" alt="">' : detailEsc((report.reporter || '?').split(/\s+/).slice(0, 2).map(function(part) { return part.charAt(0); }).join('').toUpperCase())) + '</div>' +
        '<div class="report-summary-copy">' +
          '<span class="report-summary-label">Reported by</span>' +
          '<span class="report-summary-name">' + detailEsc(report.reporter || '—') + '</span>' +
        '</div>' +
        '<span class="' + statusBadgeClass + '" style="color:' + pillColor + ';background:' + pillBg + ';border-color:' + pillBorder + ';">' + statusBadgeHtml + '</span>' +
      '</div>' +
      '<div style="margin-bottom:16px;display:flex;align-items:center;gap:6px;"><span style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;">Priority</span><span class="appt-status-pill" style="background:' + urgBg + ';color:' + urgColor + ';border-color:' + urgBorder + ';">' + detailEsc(report.priority) + '</span></div>' +
      dcvsReturnHtml +
      workflowHtml +
      '<div class="rpt-detail-grid">' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Report Information</div><div class="info-rows info-rows-2col">' + barangayInfoRow('Date Submitted', report.submitted) + barangayInfoRow('Incident Date', report.incidentDate) + barangayInfoRow('Location', report.location) + barangayInfoRow('Report Type', report.category) + '</div></div>' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Animal Information</div><div class="info-rows info-rows-2col">' + barangayInfoRow('Animal Type', report.animalType || '-') + barangayInfoRow('Breed / Appearance', report.animalBreed || '-') + barangayInfoRow('Estimated Number of Animals', report.animalCount || '-') + barangayInfoRow('Animal Behavior', report.animalBehavior || '-') + '</div></div>' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">General Information</div><div class="info-rows info-rows-2col">' + barangayInfoRow('Resident ID', report.reporterRef || '-') + barangayInfoRow('Mobile Number', report.phone || '-') + barangayInfoRow('Email', report.email || '-') + barangayInfoRow('Barangay', report.brgy || '-') + barangayInfoRow('Status', displayStatus) + barangayInfoRow('Assigned Officer', report.assignedTo || '-') + barangayInfoRow('Resolved', report.dateResolved || '-') + '</div></div>' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Incident Details</div><p class="info-row-label" style="margin:0 0 6px;">Description</p><div class="rpt-desc-box">' + detailEsc(report.description || 'No description provided.') + '</div></div>' +
      '</div>' +
    '</div>' +
    '<div class="rpt-detail-body" data-tab="attachments" style="display:none;"><div class="rpt-section-title">Attached Photos</div><div class="rpt-attachments">' + (report.photo ? '<img src="' + detailEsc(report.photo) + '" alt="Attached report photo" onerror="this.style.display=\'none\'">' : '<p style="font-size:14px;color:#71717a;">No attachments.</p>') + (report.video ? '<video src="' + detailEsc(report.video) + '" controls playsinline preload="metadata"></video>' : '') + '</div></div>' +
    (workflowHtml ? '<div class="rpt-detail-body" data-tab="updates" style="display:none;"><div class="response-updates"><div class="response-updates-header"><span>Progress updates (' + responseHistory.length + ')</span>' +
      (canUpdateProgress ? '<button type="button" onclick="responseWorkflowAction(' + Number(report.report_id) + ',\'update_progress\')">+ Add update</button>' : '') +
      '</div>' + responseHistoryHtml + '</div></div>' : '');
  } else {
  return '<div style="display:flex;gap:10px;">' +
      '<span class="appt-status-pill" style="background:' + pillBg + ';color:' + pillColor + ';border-color:' + pillBorder + ';">Status: ' + displayStatus + '</span>' +
      '<span class="appt-status-pill" style="background:' + urgBg + ';color:' + urgColor + ';border-color:' + urgBorder + ';">Priority: ' + report.priority + '</span>' +
    '</div>' +
    '<div class="sd-section">' +
      '<div class="sd-section-title"><span>01</span> Report Details</div>' +
      '<div class="sd-info-grid">' +
        '<div class="sd-info-item"><div class="sd-info-label">Type of Report</div><div class="sd-info-value">' + report.category + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Urgency Level</div><div class="sd-info-value">' + report.priority + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Location</div><div class="sd-info-value">' + report.location + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Date Submitted</div><div class="sd-info-value">' + report.submitted + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Incident Date</div><div class="sd-info-value">' + report.incidentDate + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Reported By</div><div class="sd-info-value">' + (report.reporter || 'Resident') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Assigned Officer</div><div class="sd-info-value">' + report.assignedTo + '</div></div>' +
      '</div>' +
    '</div>' +
    '<div class="sd-section">' +
      '<div class="sd-section-title"><span>02</span> Description</div>' +
      '<div class="sd-info-grid">' +
        '<div class="sd-info-item" style="grid-column:1/-1;"><div class="sd-info-label">Situation &amp; Detailed Description</div><div class="sd-info-value">' + (report.description || 'No description provided.') + '</div></div>' +
      '</div>' +
    '</div>' +
    '<div class="sd-section">' +
      '<div class="sd-section-title"><span>03</span> General Information</div>' +
      '<div class="sd-info-grid">' +
        '<div class="sd-info-item"><div class="sd-info-label">Resident ID</div><div class="sd-info-value">' + detailEsc(report.reporterRef || '-') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Mobile Number</div><div class="sd-info-value">' + detailEsc(report.phone || '-') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Email</div><div class="sd-info-value">' + detailEsc(report.email || '-') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Barangay</div><div class="sd-info-value">' + detailEsc(report.brgy || '-') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Status</div><div class="sd-info-value">' + detailEsc(displayStatus) + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Assigned Officer</div><div class="sd-info-value">' + detailEsc(report.assignedTo || '-') + '</div></div>' +
        '<div class="sd-info-item"><div class="sd-info-label">Resolved</div><div class="sd-info-value">' + detailEsc(report.dateResolved || '-') + '</div></div>' +
      '</div>' +
    '</div>' +
    workflowHtml + photoHtml + rejectionHtml + dcvsReturnHtml;
  }
}

function openReportDetail(reportId) {
  var report = REPORTS_DATA.find(function(r) { return r.id === reportId; });
  if (!report) return;
  currentSelectedReport = report;

  document.getElementById('detail-id').textContent = report.id;
  document.getElementById('detail-category').textContent = report.category;
  document.getElementById('detail-body').innerHTML = buildReportDetailHtml(report);

  var isPending = report._db_status === 'pending';
  var footer = document.getElementById('detail-footer');
  footer.style.display = 'flex';
  if (isPending) {
    var pendingMenuHtml = '<button type="button" class="is-danger" onclick="runBarangayResponseAction(' + report.report_id + ',\'reject\')"><strong>Reject report</strong><span>Record the reason for rejecting this report.</span></button>';
    footer.innerHTML =
      '<div class="response-action-bar">' +
        '<button type="button" class="response-action-more" aria-label="More report actions" aria-expanded="false" onclick="toggleBarangayResponseMenu(event)">&#8942;</button>' +
        '<button type="button" class="sd-btn sd-btn-primary response-action-primary" onclick="doValidate()">Validate Report</button>' +
      '</div>' +
      '<div class="response-action-menu" id="barangayResponseActionMenu" hidden>' + pendingMenuHtml + '</div>';
  } else {
    var responseAction = null;
    var responseMenuItems = [];
    var showFinalClose = report._db_status === 'resolved' || report.responseState === 'escalated_to_dcvs';
    if (window.DCVS_BARANGAY_RESPONSE_ENABLED) {
      if (report.responseState === 'barangay_response_approved') {
        responseAction = { action: 'start_response', label: 'Start Response' };
        responseMenuItems.push({ action: 'unable_to_secure', label: 'Escalate to DCVS', hint: 'Coordinate with DCVS if your team cannot safely handle the case.', danger: true });
      } else if (report.responseState === 'barangay_responding') {
        responseAction = { action: 'mark_secured', label: 'Mark Animal Secured' };
        responseMenuItems.push({ action: 'update_progress', label: 'Add progress update', hint: 'Record the latest response activity.' });
        responseMenuItems.push({ action: 'unable_to_locate', label: 'Unable to locate animal', hint: 'Record that the area was checked and the animal was not found.' });
        responseMenuItems.push({ action: 'unable_to_secure', label: 'Escalate to DCVS', hint: 'Request DCVS follow-up if the animal cannot be safely handled.', danger: true });
      } else if (report.responseState === 'animal_secured') {
        responseAction = { action: 'resolve_barangay', label: 'Record Resolution' };
        responseMenuItems.push({ action: 'update_progress', label: 'Add progress update', hint: 'Record an update before submitting resolution.' });
      } else if (report.responseState === 'returned_to_barangay') {
        responseAction = { action: 'resume_barangay_response', label: 'Resume Response' };
      }
    }
    if (showFinalClose) {
      footer.innerHTML = '<button type="button" class="sd-btn sd-btn-secondary response-action-close" onclick="closeModal(\'reportDetailModal\')">Close</button>';
    } else if (responseAction) {
      var menuItemsHtml = responseMenuItems.map(function(item) {
        return '<button type="button"' + (item.danger ? ' class="is-danger"' : '') +
          ' onclick="runBarangayResponseAction(' + report.report_id + ',\'' + item.action + '\')">' +
          '<strong>' + detailEsc(item.label) + '</strong><span>' + detailEsc(item.hint) + '</span></button>';
      }).join('');
      footer.innerHTML =
        '<div class="response-action-bar">' +
          (menuItemsHtml ? '<button type="button" class="response-action-more" aria-label="More response actions" aria-expanded="false" onclick="toggleBarangayResponseMenu(event)">&#8942;</button>' : '') +
          '<button type="button" class="sd-btn sd-btn-primary response-action-primary" onclick="responseWorkflowAction(' + report.report_id + ',\'' + responseAction.action + '\')">' + detailEsc(responseAction.label) + '</button>' +
        '</div>' +
        (menuItemsHtml ? '<div class="response-action-menu" id="barangayResponseActionMenu" hidden>' + menuItemsHtml + '</div>' : '');
    } else {
      footer.innerHTML = '';
      footer.style.display = 'none';
    }
  }

  document.getElementById('reportDetailModal').classList.add('open');
}

function closeBarangayResponseMenu() {
  var menu = document.getElementById('barangayResponseActionMenu');
  var button = document.querySelector('#detail-footer .response-action-more');
  if (menu) menu.hidden = true;
  if (button) button.setAttribute('aria-expanded', 'false');
}

function toggleBarangayResponseMenu(event) {
  if (event) event.stopPropagation();
  var button = document.querySelector('#detail-footer .response-action-more');
  var menu = document.getElementById('barangayResponseActionMenu');
  if (!button || !menu) return;
  var willOpen = menu.hidden;
  menu.hidden = !willOpen;
  button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}

function runBarangayResponseAction(reportId, action) {
  closeBarangayResponseMenu();
  if (action === 'reject') {
    doReject();
    return;
  }
  responseWorkflowAction(reportId, action);
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openModal(id) { document.getElementById(id).classList.add('open'); }

function barangayDetailTab(tab, el) {
  var root = (el && el.closest) ? el.closest('.modal') : null;
  if (!root) root = document.getElementById('reportDetailModal');
  root.querySelectorAll('.rpt-tab').forEach(function(button) {
    button.classList.toggle('active', button.getAttribute('data-tab') === tab);
  });
  root.querySelectorAll('.rpt-detail-body').forEach(function(body) {
    body.style.display = body.getAttribute('data-tab') === tab ? '' : 'none';
  });
}

/* ── Confirm modal ── */
var pendingConfirmAction = null;
function confirmAction(action, rid, refId, message) {
  pendingConfirmAction = { action: action, rid: rid, refId: refId };
  document.getElementById('confirmText').innerHTML = message;
  var icon = document.getElementById('confirmIcon');
  var btn = document.getElementById('confirmSubmitBtn');
  btn.innerHTML = '<i class="fas fa-check"></i> Confirm';
  btn.className = 'sd-btn sd-btn-primary';
  if (action === 'delete') {
    icon.innerHTML = '<i class="fas fa-trash" style="font-size:40px;color:#dc2626;"></i>';
    btn.className = 'sd-btn sd-btn-danger';
    btn.innerHTML = '<i class="fas fa-trash"></i> Delete Permanently';
  } else if (action === 'archive') {
    icon.innerHTML = '<i class="fas fa-archive" style="font-size:40px;color:#64748b;"></i>';
  }
  btn.onclick = function() { submitConfirmedAction(); };
  openModal('confirmModal');
}
function submitConfirmedAction() {
  if (!pendingConfirmAction) return;
  var btn = document.getElementById('confirmSubmitBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
  var a = pendingConfirmAction;
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: a.rid, action: a.action })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    btn.disabled = false; closeModal('confirmModal');
    if (d.success) { showToast(a.action === 'delete' ? 'Report deleted.' : 'Report archived.'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { btn.disabled = false; closeModal('confirmModal'); showToast('Network error.'); });
}

/* ── shadcn Alert Dialog: Validate / Reject Report (DB update only after confirmation) ── */
function selectReportById(rid) {
  var found = REPORTS_DATA.find(function(x) { return x.report_id === rid; });
  if (found) currentSelectedReport = found;
  return found;
}

function doValidate() {
  if (!currentSelectedReport) return;
  openValidationForm(currentSelectedReport, 'Record what you found on site, then choose who will handle this case.');
}

function openValidationForm(report, description) {
  var form = document.getElementById('validationForm');
  form.reset();
  form.elements.namedItem('report_id').value = report.report_id;
  var context = document.getElementById('validationReportContext');
  var priority = String(report.priority || report.urgency || 'Unknown');
  var priorityClass = priority.toLowerCase();
  context.innerHTML = '<span class="validation-context-id">' + detailEsc(report.id || 'Report') + '</span>' +
    '<span class="validation-context-details">' + detailEsc(report.category || report.type || 'Report') +
    ' · ' + detailEsc(report.animalType || 'Animal unspecified') +
    ' · ' + detailEsc(report.brgy || 'Barangay') + '</span>' +
    '<span class="validation-context-priority is-' + detailEsc(priorityClass) + '">' + detailEsc(priority) + '</span>';
  updateValidationFormMeta();
  updateValidationRoutingChoice();
  document.getElementById('validateAlertDesc').textContent = description;
  document.getElementById('validationUploadMeta').textContent = 'Up to 6 files; JPG, PNG, MP4; max 25 MB each.';
  document.getElementById('validateAlertConfirm').disabled = false;
  var notes = document.getElementById('validationNotes');
  if (notes) notes.focus({ preventScroll: true });
  openModal('validateAlertDialog');
}

function updateValidationRoutingChoice() {
  var selection = document.querySelector('#validationForm [name="barangay_will_respond"]:checked');
  var willRespond = !!(selection && selection.value === '1');
  var submitButton = document.getElementById('validateAlertConfirm');
  var hint = document.getElementById('validationRoutingHint');
  if (!submitButton) return;
  submitButton.textContent = willRespond
    ? 'Validate & Assign to Barangay'
    : 'Validate & Forward to DCVS';
  if (hint) hint.textContent = willRespond
    ? 'This report will be assigned to your barangay immediately. Start the response separately when the team is heading to the incident.'
    : 'This report will be forwarded to DCVS for review and personnel assignment.';
  updateValidationRiskCheck(willRespond);
}

function updateValidationRiskCheck(willRespond) {
  var panel = document.getElementById('validationRiskCheck');
  var title = document.getElementById('validationRiskTitle');
  var icon = document.getElementById('validationRiskIcon');
  var copy = document.getElementById('validationRiskCopy');
  if (!panel || !title || !icon || !copy) return;

  var report = currentSelectedReport || {};
  var fieldStatus = document.querySelector('#validationForm [name="field_status"]:checked');
  var urgency = String(report.urgency || report.priority || '').toLowerCase();
  var behavior = String(report.animalBehavior || '').toLowerCase();
  var count = String(report.animalCount || '').trim();
  var flags = [];
  if (String(report.type || '').toLowerCase() === 'rabies') flags.push('rabies concern');
  if (urgency === 'high' || urgency === 'critical') flags.push(urgency + ' priority');
  if (/aggress|bit(e|ten)|attack/.test(behavior)) flags.push('reported aggressive or biting behavior');
  if (/^(?:6\+|[6-9]\d*|\d{2,})$/.test(count)) flags.push('large number of animals');
  if (fieldStatus && fieldStatus.value === 'urgent_response') flags.push('field status marked urgent');

  var isHighRisk = flags.length > 0;
  panel.classList.toggle('is-high-risk', isHighRisk);
  icon.innerHTML = isHighRisk ? '&#9888;' : '&#10003;';
  title.textContent = isHighRisk ? 'High-risk flags' : 'No high-risk flags';
  if (isHighRisk) {
    copy.textContent = flags.join(' · ') + '. ' +
      (willRespond
        ? 'Coordinate with DCVS according to protocol. This does not prevent your barangay from handling the case if you can respond safely.'
        : 'Forward for DCVS review and coordination. Urgent cases should be escalated according to protocol.');
  } else {
    copy.textContent = willRespond
      ? 'Safe to handle at barangay level if you can secure the animal. You can still escalate to DCVS later.'
      : 'No risk flags detected from the report. DCVS will review and assign personnel.';
  }
}

function updateValidationFormMeta() {
  var notes = document.getElementById('validationNotes');
  var count = document.getElementById('validationNotesCount');
  var input = document.getElementById('validationAttachments');
  var uploadMeta = document.getElementById('validationUploadMeta');
  if (notes && count) count.textContent = String(notes.value.length);
  if (input && uploadMeta) {
    var files = Array.prototype.slice.call(input.files || []);
    uploadMeta.textContent = files.length
      ? files.length + ' file(s) selected: ' + files.map(function(file) { return file.name; }).join(', ')
      : 'No files selected';
  }
}

function bindValidationNotes() {
  var notes = document.getElementById('validationNotes');
  if (!notes) return;
  notes.addEventListener('input', updateValidationFormMeta);
  document.querySelectorAll('[data-validation-note]').forEach(function(button) {
    button.addEventListener('click', function() {
      notes.value = button.getAttribute('data-validation-note') || '';
      updateValidationFormMeta();
      notes.focus();
    });
  });
}

function bindValidationUpload() {
  var input = document.getElementById('validationAttachments');
  var zone = document.getElementById('validationUploadZone');
  if (!input || !zone) return;
  input.addEventListener('change', updateValidationFormMeta);
  zone.addEventListener('dragover', function(event) {
    event.preventDefault();
    zone.classList.add('is-dragging');
  });
  zone.addEventListener('dragleave', function() { zone.classList.remove('is-dragging'); });
  zone.addEventListener('drop', function(event) {
    event.preventDefault();
    zone.classList.remove('is-dragging');
    if (event.dataTransfer && event.dataTransfer.files) {
      input.files = event.dataTransfer.files;
      updateValidationFormMeta();
    }
  });
}

function doReject() {
  if (!currentSelectedReport) return;
  var reasonInput = document.getElementById('rejectReasonInput');
  if (reasonInput) reasonInput.value = '';
  openModal('rejectAlertDialog');
}

function submitValidate() {
  var report = currentSelectedReport;
  if (!report) return;
  var form = document.getElementById('validationForm');
  var attachments = document.getElementById('validationAttachments').files;
  if (attachments.length > 6) { showToast('Attach no more than 6 files.'); return; }
  for (var i = 0; i < attachments.length; i++) {
    if (attachments[i].size > 25 * 1024 * 1024) { showToast('Each evidence file must be no larger than 25 MB.'); return; }
  }
  var btn = document.getElementById('validateAlertConfirm');
  btn.disabled = true; btn.textContent = 'Forwarding...';
  var cancel = document.getElementById('validateAlertCancel');
  cancel.disabled = true;
  bvRunning = bvTotal > 0;
  var formData = new FormData(form);
  fetch('validate-report.php', {
    method: 'POST',
    body: formData
  }).then(function(r) {
    return r.text().then(function(text) {
      var data;
      try {
        data = JSON.parse(text);
      } catch (error) {
        throw new Error('The server returned an invalid response (HTTP ' + r.status + '). Check the PHP error log.');
      }
      if (!r.ok || !data || !data.success) {
        throw new Error((data && data.message) || ('The request failed (HTTP ' + r.status + ').'));
      }
      return data;
    });
  })
  .then(function(d) {
    bvRunning = false;
    btn.disabled = false; updateValidationRoutingChoice(); cancel.disabled = false;
    closeModal('validateAlertDialog');
    if (bvTotal > 0) {
      bvDone++;
      bvQueue.shift();
      advanceBulkValidate();
      return;
    }
    showToast(d.message || 'Report review saved.');
    closeModal('reportDetailModal');
    setTimeout(function() { location.reload(); }, 800);
  }).catch(function(error) {
    bvRunning = false;
    btn.disabled = false; updateValidationRoutingChoice(); cancel.disabled = false;
    console.error('Could not forward barangay-validated report:', error);
    showToast(error instanceof TypeError
      ? 'Could not contact the server while forwarding the report. Check your connection and try again.'
      : (error.message || 'The report could not be forwarded.'));
  });
}

function submitReject() {
  if (brTotal > 0) { submitBulkRejectStep(); return; }
  var report = currentSelectedReport;
  if (!report) return;
  var reason = (document.getElementById('rejectReasonInput') || {}).value || '';
  if (!reason.trim()) { showToast('Please state a rejection reason.'); return; }
  closeModal('rejectAlertDialog');
  fetch('validate-report.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ report_id: report.report_id, action: 'reject', reason: reason })
  }).then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.success) { showToast('Report rejected.'); closeModal('reportDetailModal'); setTimeout(function() { location.reload(); }, 800); }
    else showToast(d.message || 'Failed.');
  }).catch(function() { showToast('Network error.'); });
}

function validateSingle(rid) {
  if (!selectReportById(rid)) return;
  doValidate();
}

function rejectSingle(rid, refId) {
  if (!selectReportById(rid)) return;
  doReject();
}

function archiveSingle(rid, refId) {
  var report = selectReportById(rid);
  if (!report) return;
  archiveDialogReports = [report];
  document.getElementById('archiveAlertTitle').textContent = 'Archive Report';
  document.getElementById('archiveAlertDesc').textContent = 'Are you sure you want to archive ' + refId + '? It will be moved out of the active reports list.';
  document.getElementById('archiveAlertConfirm').textContent = 'Archive';
  openModal('archiveAlertDialog');
}

function deleteSingle(rid, refId) {
  confirmAction('delete', rid, refId, 'Permanently delete <strong>' + refId + '</strong>? This cannot be undone.');
}

/* ── Export CSV ── */
function downloadCSV(rows) {
  var csv = rows.map(function(r) { return r.map(function(c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
  var blob = new Blob([csv], { type: 'text/csv' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'reports-<?php echo htmlspecialchars($barangayName); ?>-' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  showToast('CSV exported.');
}
function exportAll() {
  var rows = [['Report ID','Category','Type','Location','Incident Date','Priority','Status','Date Resolved']];
  getFilteredList().forEach(function(r) { rows.push([r.id,r.category,r.type,r.location,r.incidentDate,r.priority,r.status,r.dateResolved]); });
  downloadCSV(rows);
}

/* ── Clear filters ── */
function clearAllFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('categoryFilter').value = '';
  document.getElementById('priorityFilter').value = '';
  refresh();
}
function updateClearBtn() {
  var has = document.getElementById('searchInput').value || document.getElementById('statusFilter').value || document.getElementById('categoryFilter').value || document.getElementById('priorityFilter').value;
  document.getElementById('clearFiltersBtn').style.display = has ? 'inline-flex' : 'none';
}

/* ── Toast ── */
function showToast(msg) {
  var t = document.getElementById('toast-msg');
  document.getElementById('toast-text').textContent = msg;
  t.classList.add('show');
  setTimeout(function() { t.classList.remove('show'); }, 3000);
}

/* ── URL params ── */
var URL_PARAMS = new URLSearchParams(window.location.search);
var initialStatus = URL_PARAMS.get('status') || '';
var initialView = URL_PARAMS.get('view') || '';
if (initialStatus) document.getElementById('statusFilter').value = initialStatus;

/* ── Refresh ── */
function refresh() {
  var hasUserFilter = document.getElementById('searchInput').value || document.getElementById('categoryFilter').value || document.getElementById('priorityFilter').value || (document.getElementById('statusFilter').value && document.getElementById('statusFilter').value !== initialStatus);
  document.getElementById('clearFiltersBtn').style.display = hasUserFilter ? 'inline-flex' : 'none';
  renderRows(getFilteredList());
  currentPage = 1;
  paginate();
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', function() {
  refresh();

  /* ── Auto-open Report Details drawer (?view=<report_id or reference_id>) ── */
  if (initialView) {
    var viewTarget = REPORTS_DATA.find(function(r) {
      return String(r.report_id) === initialView || r.id === initialView;
    });
    if (viewTarget) openReportDetail(viewTarget.id);
  }

  /* ── Alert Dialog controls: Validate / Reject / Archive / Delete ── */
  document.getElementById('validateAlertCancel').addEventListener('click', function() {
    if (bvTotal > 0) cancelBulkValidate();
    else closeModal('validateAlertDialog');
  });
  document.getElementById('validationForm').addEventListener('submit', function(e) {
    e.preventDefault();
    submitValidate();
  });
  document.querySelectorAll('#validationForm [name="barangay_will_respond"]').forEach(function(input) {
    input.addEventListener('change', updateValidationRoutingChoice);
  });
  document.querySelectorAll('#validationForm [name="field_status"]').forEach(function(input) {
    input.addEventListener('change', function() {
      var route = document.querySelector('#validationForm [name="barangay_will_respond"]:checked');
      updateValidationRiskCheck(!!(route && route.value === '1'));
    });
  });
  bindValidationNotes();
  bindValidationUpload();
  document.getElementById('rejectAlertCancel').addEventListener('click', cancelRejectDialog);
  document.getElementById('rejectAlertConfirm').addEventListener('click', submitReject);
  document.getElementById('archiveAlertCancel').addEventListener('click', function() { if (!bulkSeqRunning) closeModal('archiveAlertDialog'); });
  document.getElementById('archiveAlertConfirm').addEventListener('click', submitBulkArchive);
  document.getElementById('deleteAlertCancel').addEventListener('click', function() { if (!bulkSeqRunning) closeModal('deleteAlertDialog'); });
  document.getElementById('deleteAlertConfirm').addEventListener('click', submitBulkDelete);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeBarangayResponseMenu();
    if (e.key !== 'Escape') return;
    if (document.getElementById('rejectAlertDialog').classList.contains('open')) cancelRejectDialog();
    else if (document.getElementById('validateAlertDialog').classList.contains('open')) {
      if (bvTotal > 0) cancelBulkValidate();
      else closeModal('validateAlertDialog');
    }
    else if (document.getElementById('archiveAlertDialog').classList.contains('open') && !bulkSeqRunning) closeModal('archiveAlertDialog');
    else if (document.getElementById('deleteAlertDialog').classList.contains('open') && !bulkSeqRunning) closeModal('deleteAlertDialog');
  });

  document.getElementById('searchInput').addEventListener('input', refresh);
  document.getElementById('statusFilter').addEventListener('change', refresh);
  document.getElementById('categoryFilter').addEventListener('change', refresh);
  document.getElementById('priorityFilter').addEventListener('change', refresh);
  document.getElementById('selectAll').addEventListener('change', function() { toggleSelectAll(this); });

});
</script>
<link rel="stylesheet" href="assets/css/notification.css?v=<?php echo (int)filemtime(__DIR__ . '/assets/css/notification.css'); ?>">
<script src="assets/js/notification-panel.js?v=<?php echo (int)filemtime(__DIR__ . '/assets/js/notification-panel.js'); ?>" defer></script>
<script src="assets/js/response-workflow.js?v=<?php echo (int)filemtime(__DIR__ . '/assets/js/response-workflow.js'); ?>"></script>

</body>
</html>
