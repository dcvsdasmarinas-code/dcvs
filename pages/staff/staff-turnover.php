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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/staff-app-base.php';
require_once __DIR__ . '/../../env.php';
loadDcvsEnv();
date_default_timezone_set('Asia/Manila');

$appBaseUrl = dcvsStaffAppBaseUrl();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: ' . $appBaseUrl . '/login-staff.php');
    exit;
}

$activePage = 'turnover';
$pageTitle = 'Animal Turnover - DCVS';
$loadError = '';
$workflowEnabled = env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1';
$turnoverRows = [];
$barangayOptions = [];
$reportColumnsResult = $conn->query("SHOW COLUMNS FROM reports");
$reportColumns = [];
if (!$reportColumnsResult) {
    error_log('Could not inspect report fields on Staff turnover page: ' . $conn->error);
    $loadError = 'Could not load turnover details. Please refresh and try again.';
} else {
    while ($column = $reportColumnsResult->fetch_assoc()) {
        $reportColumns[$column['Field']] = true;
    }
    $reportColumnsResult->close();
}
$validationStatusColumn = isset($reportColumns['barangay_validation_status']) ? 'r.barangay_validation_status' : 'NULL';
$validationNotesColumn = isset($reportColumns['barangay_validation_notes']) ? 'r.barangay_validation_notes' : 'NULL';

function dcvsStaffTurnoverMediaUrl($url): string {
    global $appBaseUrl;
    $url = trim((string)$url);
    if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return $url;
    }
    if ($appBaseUrl !== '' && ($url === $appBaseUrl || strpos($url, $appBaseUrl . '/') === 0)) {
        return $url;
    }
    return $appBaseUrl . '/' . preg_replace('~^(?:/|\.{1,2}/)+~', '', $url);
}

$barangayQuery = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name");
if (!$barangayQuery) {
    error_log('Could not load barangay options on Staff turnover page: ' . $conn->error);
    $loadError = 'Could not load barangay filters. Please refresh and try again.';
} else {
    while ($barangayRow = $barangayQuery->fetch_assoc()) {
        $barangayOptions[] = [
            'id' => (int)$barangayRow['barangay_id'],
            'name' => (string)$barangayRow['barangay_name'],
        ];
    }
    $barangayQuery->close();
}

if ($workflowEnabled && $loadError === '') {
    $turnoverQuery = $conn->prepare("
        SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.animal_type, r.animal_count, r.animal_breed,
               r.description, r.location_address, r.incident_at, r.created_at, r.validated_at,
               r.barangay_notes, {$validationStatusColumn} AS barangay_validation_status,
               {$validationNotesColumn} AS barangay_validation_notes,
               b.barangay_id, b.barangay_name,
               t.status AS turnover_status, t.due_at, t.handover_method, t.planned_at,
               t.handler_name, t.contact_number, t.pickup_location, t.safe_transport,
               t.holding_location, t.received_at, receiver.full_name AS receiver_name,
               rr.animal_disposition, rr.resolution_details, rw.state AS response_state,
               reporter.full_name AS reporter_name, reporter.avatar_url AS reporter_avatar,
               validator.full_name AS validator_name,
               latest.reason AS latest_update, latest.created_at AS latest_update_at,
               latest_actor.full_name AS latest_update_actor
        FROM report_turnover_tasks t
        INNER JOIN reports r ON r.report_id = t.report_id
        LEFT JOIN barangays b ON b.barangay_id = r.barangay_id
        LEFT JOIN users receiver ON receiver.user_id = t.received_by
        LEFT JOIN users reporter ON reporter.user_id = r.reporter_id
        LEFT JOIN users validator ON validator.user_id = r.validated_by
        LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
        LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
        LEFT JOIN report_response_history latest ON latest.history_id = (
            SELECT h.history_id FROM report_response_history h
            WHERE h.report_id = r.report_id
            ORDER BY h.created_at DESC, h.history_id DESC LIMIT 1
        )
        LEFT JOIN users latest_actor ON latest_actor.user_id = latest.actor_id
        WHERE r.archived_at IS NULL
          AND r.status = 'resolved'
          AND t.status IN ('pending', 'receipt_confirmed')
        ORDER BY CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,
                 t.due_at ASC, COALESCE(t.received_at, t.requested_at) DESC
    ");
    if (!$turnoverQuery) {
        error_log('Could not prepare Staff turnover list query: ' . $conn->error);
        $loadError = 'Could not load turnover records. Please verify the turnover workflow migrations.';
    } elseif (!$turnoverQuery->execute()) {
        error_log('Could not load Staff turnover records: ' . $turnoverQuery->error);
        $loadError = 'Could not load turnover records. Please refresh and try again.';
        $turnoverQuery->close();
    } else {
        $result = $turnoverQuery->get_result();
        while ($row = $result->fetch_assoc()) {
            $turnoverRows[] = [
                'report_id' => (int)$row['report_id'],
                'reference_id' => (string)($row['reference_id'] ?? ''),
                'report_type' => (string)($row['report_type'] ?? ''),
                'urgency_level' => (string)($row['urgency_level'] ?? ''),
                'animal_type' => (string)($row['animal_type'] ?? ''),
                'animal_count' => (string)($row['animal_count'] ?? ''),
                'animal_breed' => (string)($row['animal_breed'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'location_address' => (string)($row['location_address'] ?? ''),
                'incident_at' => (string)($row['incident_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'validated_at' => (string)($row['validated_at'] ?? ''),
                'barangay_notes' => (string)($row['barangay_notes'] ?? ''),
                'barangay_validation_status' => (string)($row['barangay_validation_status'] ?? ''),
                'barangay_validation_notes' => (string)($row['barangay_validation_notes'] ?? ''),
                'response_state' => (string)($row['response_state'] ?? ''),
                'barangay_id' => (int)($row['barangay_id'] ?? 0),
                'barangay_name' => (string)($row['barangay_name'] ?? '—'),
                'turnover_status' => (string)($row['turnover_status'] ?? ''),
                'due_at' => (string)($row['due_at'] ?? ''),
                'handover_method' => (string)($row['handover_method'] ?? ''),
                'planned_at' => (string)($row['planned_at'] ?? ''),
                'handler_name' => (string)($row['handler_name'] ?? ''),
                'contact_number' => (string)($row['contact_number'] ?? ''),
                'pickup_location' => (string)($row['pickup_location'] ?? ''),
                'safe_transport' => (bool)($row['safe_transport'] ?? false),
                'holding_location' => (string)($row['holding_location'] ?? ''),
                'received_at' => (string)($row['received_at'] ?? ''),
                'receiver_name' => (string)($row['receiver_name'] ?? ''),
                'animal_disposition' => (string)($row['animal_disposition'] ?? ''),
                'resolution_details' => (string)($row['resolution_details'] ?? ''),
                'reporter_name' => (string)($row['reporter_name'] ?? ''),
                'reporter_avatar' => dcvsStaffTurnoverMediaUrl($row['reporter_avatar'] ?? ''),
                'validator_name' => (string)($row['validator_name'] ?? ''),
                'latest_update' => (string)($row['latest_update'] ?? ''),
                'latest_update_at' => (string)($row['latest_update_at'] ?? ''),
                'latest_update_actor' => (string)($row['latest_update_actor'] ?? ''),
                'validation_evidence' => [],
            ];
        }
        $turnoverQuery->close();
        if ($turnoverRows) {
            $evidenceQuery = $conn->query("
                SELECT a.attachment_id, a.report_id, a.attachment_type, a.attachment_source,
                       a.file_url, a.mime_type, a.caption
                FROM report_attachments a
                WHERE a.attachment_source IN ('barangay_validation', 'barangay_secured', 'barangay_turnover', 'barangay_action', 'staff_action')
                ORDER BY a.report_id, a.display_order, a.attachment_id
            ");
            if (!$evidenceQuery) {
                error_log('Could not load turnover validation evidence: ' . $conn->error);
                $loadError = 'Turnover records loaded, but validation evidence could not be loaded.';
            } else {
                $evidenceByReport = [];
                while ($attachment = $evidenceQuery->fetch_assoc()) {
                    $evidenceByReport[(int)$attachment['report_id']][] = [
                        'type' => (string)$attachment['attachment_type'],
                        'url' => in_array((string)$attachment['attachment_source'], ['barangay_action', 'staff_action'], true)
                            ? $appBaseUrl . '/api/shared/report-evidence-file.php?id=' . (int)$attachment['attachment_id'] . '&sess=DCVS_STAFF_SESSION'
                            : dcvsStaffTurnoverMediaUrl($attachment['file_url']),
                        'mime_type' => (string)$attachment['mime_type'],
                        'caption' => (string)($attachment['caption'] ?? ''),
                    ];
                }
                $evidenceQuery->close();
                foreach ($turnoverRows as &$turnoverRow) {
                    $turnoverRow['validation_evidence'] = $evidenceByReport[$turnoverRow['report_id']] ?? [];
                }
                unset($turnoverRow);
            }
        }
    }
}

$pendingCount = 0;
$overdueCount = 0;
$planNeededCount = 0;
$turnedOverThisWeekCount = 0;
$turnedOverTotalCount = 0;
$turnedOverDispositionCounts = ['under_veterinary_care' => 0, 'rescued' => 0];
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$weekStart = $now->modify('monday this week')->setTime(0, 0);
foreach ($turnoverRows as $row) {
    if ($row['turnover_status'] === 'pending') {
        $pendingCount++;
        if ($row['planned_at'] === '' || $row['handover_method'] === '') {
            $planNeededCount++;
        }
        if ($row['due_at'] !== '') {
            try {
                if (new DateTimeImmutable($row['due_at'], new DateTimeZone('Asia/Manila')) < $now) {
                    $overdueCount++;
                }
            } catch (Exception $exception) {
                error_log('Invalid turnover due date for report ' . $row['report_id'] . ': ' . $exception->getMessage());
            }
        }
        continue;
    }
    if ($row['turnover_status'] !== 'receipt_confirmed') {
        continue;
    }
    $turnedOverTotalCount++;
    if (isset($turnedOverDispositionCounts[$row['animal_disposition']])) {
        $turnedOverDispositionCounts[$row['animal_disposition']]++;
    }
    if ($row['received_at'] !== '') {
        try {
            if (new DateTimeImmutable($row['received_at'], new DateTimeZone('Asia/Manila')) >= $weekStart) {
                $turnedOverThisWeekCount++;
            }
        } catch (Exception $exception) {
            error_log('Invalid turnover receipt date for report ' . $row['report_id'] . ': ' . $exception->getMessage());
        }
    }
}

$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$turnoverKpis = [
    'pending' => [
        ['label' => 'For Turnover', 'value' => $pendingCount, 'help' => 'Waiting for DCVS receipt'],
        ['label' => 'Overdue', 'value' => $overdueCount, 'help' => 'Past the turnover due time', 'variant' => 'overdue'],
        ['label' => 'Plan Needed', 'value' => $planNeededCount, 'help' => 'Barangay has not set a plan'],
        ['label' => 'Turned Over This Week', 'value' => $turnedOverThisWeekCount, 'help' => 'Receipt confirmed by DCVS'],
    ],
    'received' => [
        ['label' => 'Total Turned Over', 'value' => $turnedOverTotalCount, 'help' => 'All time'],
        ['label' => 'This Week', 'value' => $turnedOverThisWeekCount, 'help' => 'Receipt confirmed'],
        ['label' => 'Under Veterinary Care', 'value' => $turnedOverDispositionCounts['under_veterinary_care'], 'help' => 'Recorded disposition'],
        ['label' => 'Rescued', 'value' => $turnedOverDispositionCounts['rescued'], 'help' => 'Recorded disposition'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $escape($pageTitle); ?></title>
  <link rel="stylesheet" href="<?php echo $escape($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css')); ?>">
  <link rel="stylesheet" href="<?php echo $escape($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css')); ?>">
  <link rel="stylesheet" href="<?php echo $escape($appBaseUrl . '/assets/css/sb2.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/sb2.css')); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    *{box-sizing:border-box}
    body{margin:0;background:#fff;color:#18181b;font-family:Inter,'Segoe UI',sans-serif}
    .topbar{background:#fff!important;border-bottom-color:#e4e4e7!important;color:#18181b}
    .topbar .breadcrumb-root{color:#0b7a32!important}
    .topbar .breadcrumb-current,.topbar .user-name{color:#18181b!important}
    .topbar .breadcrumb-sep,.topbar .user-role,.topbar .notif-btn{color:#71717a!important}
    .main-content{min-height:calc(100vh - 64px);padding:18px 20px 30px!important;background:#fff}
    .turnover-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px}
    .turnover-kpi{display:flex;min-width:0;flex-direction:column;align-items:center;justify-content:center;padding:18px 20px;border:1px solid #e4e4e7;border-radius:10px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);font-family:'Inter','Segoe UI',sans-serif;text-align:center;transition:border-color .15s,box-shadow .15s}
    .turnover-kpi:hover{border-color:#d4d4d8;box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03)}
    .turnover-kpi.is-overdue{border-color:#fecaca}
    .turnover-kpi-label{margin:0 0 6px;color:#18181b;font:500 14px 'Inter','Segoe UI',sans-serif;text-transform:uppercase;letter-spacing:.03em}
    .turnover-kpi-value{margin:0 0 6px;color:#18181b;font:600 26px/1 'Inter','Segoe UI',sans-serif;letter-spacing:-.02em}
    .turnover-kpi.is-overdue .turnover-kpi-value{color:#dc2626}
    .turnover-kpi-help{color:#71717a;font:400 14px 'Inter','Segoe UI',sans-serif}
    .turnover-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
    .turnover-filters,.turnover-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .turnover-input,.turnover-select,.turnover-button{height:36px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif}
    .turnover-input{width:min(300px,76vw);padding:0 12px;font-weight:400}
    .turnover-input::placeholder{color:#a1a1aa}
    .turnover-select{min-width:145px;padding:0 28px 0 10px}
    .turnover-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0 13px;cursor:pointer}
    .turnover-button:hover{background:#f4f4f5}
    .turnover-button svg{width:14px;height:14px}
    .turnover-tabs{display:inline-flex;align-items:center;padding:3px;border:1px solid #e4e4e7;border-radius:7px;background:#fafafa}
    .turnover-tab{display:inline-flex;align-items:center;gap:7px;min-height:28px;padding:0 11px;border:0;border-radius:5px;background:transparent;color:#71717a;font:500 13px Inter,'Segoe UI',sans-serif;cursor:pointer}
    .turnover-tab[aria-selected="true"]{background:#fff;color:#18181b;box-shadow:0 1px 2px rgba(0,0,0,.12)}
    .turnover-tab-count{min-width:18px;padding:1px 5px;border-radius:999px;background:#f4f4f5;color:#52525b;font-size:11px;text-align:center}
    .turnover-tab[aria-selected="true"] .turnover-tab-count{background:#e4e4e7;color:#18181b}
    .turnover-table-wrap{width:100%;overflow-x:auto;border:1px solid #e4e4e7;border-radius:8px;background:#fff}
    .turnover-table{width:100%;min-width:1050px;border-collapse:collapse}
    .turnover-table th{padding:11px 12px;border-bottom:1px solid #e4e4e7;background:#fafafa;color:#71717a;font-size:14px;font-weight:500;text-align:left;white-space:nowrap}
    .turnover-table td{padding:12px;border-bottom:1px solid #f4f4f5;color:#18181b;font-size:14px;vertical-align:middle}
    .turnover-table tr:last-child td{border-bottom:0}
    .turnover-table tbody tr[data-turnover-report]{cursor:pointer}
    .turnover-table tbody tr[data-turnover-report]:hover{background:#fafafa}
    .turnover-table tbody tr[data-turnover-report]:focus-visible{outline:2px solid #0E8131;outline-offset:-2px}
    .turnover-row-menu-cell{text-align:right!important}
    .turnover-row-actions{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:6px;background:transparent;color:#71717a;cursor:pointer}
    .turnover-row-actions:hover,.turnover-row-actions[aria-expanded="true"]{border-color:#e4e4e7;background:#f4f4f5;color:#18181b}
    .turnover-row-actions svg{width:16px;height:16px}
    .turnover-menu-pop{z-index:1450;min-width:210px;padding:5px;background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14)}
    .turnover-menu-pop .row-menu-item{width:100%;display:flex;align-items:center;gap:9px;padding:9px 10px;border:0;border-radius:5px;background:transparent;color:#27272a;text-align:left;font:500 13px Inter,'Segoe UI',sans-serif;cursor:pointer}
    .turnover-menu-pop .row-menu-item:hover:not(:disabled){background:#f4f4f5}
    .turnover-menu-pop .row-menu-item svg{width:15px;height:15px;flex-shrink:0}
    .turnover-menu-pop .row-menu-item.danger{color:#dc2626}
    .turnover-menu-pop .row-menu-item.danger:hover{background:#fef2f2}
    .turnover-menu-pop .row-menu-item:disabled{opacity:.55;cursor:not-allowed}
    .turnover-report{color:#18181b;font-weight:500}
    .turnover-subtext{display:block;margin-top:3px;color:#71717a;font-size:12px}
    .turnover-status{display:inline-flex;align-items:center;min-height:23px;padding:2px 8px;border:1px solid #e4e4e7;border-radius:5px;background:#fafafa;color:#52525b;font-size:12px;font-weight:500;white-space:nowrap}
    .turnover-status.is-needed{border-color:#fde68a;background:#fef3c7;color:#92400e}
    .turnover-status.is-due-soon{border-color:#fde68a;background:#fffbeb;color:#a16207}
    .turnover-status.is-overdue{border-color:#fecaca;background:#fef2f2;color:#b91c1c}
    .turnover-status.is-on-time{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}
    .turnover-status.is-pending-review{border-color:#fde68a;background:#fef3c7;color:#92400e}
    .turnover-status.is-received{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
    .turnover-status.is-rescued{padding:3px 10px;border:1px solid #0E8131;border-radius:4px;background:#0E8131;color:#fff;font-weight:500;line-height:1.4}
    .turnover-confirm{display:inline-flex;height:36px;align-items:center;justify-content:center;gap:8px;padding:0 16px;border:1px solid #18181b;border-radius:6px;background:#18181b;color:#fafafa;font:500 14px Inter,'Segoe UI',sans-serif;cursor:pointer;transition:background-color .15s}
    .turnover-confirm:hover:not(:disabled){background:#27272a}
    .turnover-confirm:disabled{opacity:.5;cursor:not-allowed}
    .turnover-empty,.turnover-error{padding:32px 16px;color:#71717a;text-align:center}
    .turnover-error{margin-bottom:12px;border:1px solid #fecaca;border-radius:7px;background:#fef2f2;color:#991b1b;text-align:left}
    .turnover-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;padding:12px 8px;font-family:'Inter','Segoe UI',sans-serif}
    .turnover-pagination-right{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
    .turnover-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a}
    .turnover-rows select{height:32px;width:70px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;padding:0 8px;font-size:14px;color:#18181b;cursor:pointer;font-family:inherit}
    .turnover-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap}
    .turnover-page-buttons{display:flex;align-items:center;gap:4px}
    .turnover-page-buttons button{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .turnover-page-buttons button:hover:not(:disabled){background:#f4f4f5}
    .turnover-page-buttons button:disabled{opacity:.5;cursor:not-allowed}
    .turnover-drawer-backdrop{position:fixed;inset:0;z-index:1400;display:flex;justify-content:flex-end;background:rgba(24,24,27,.38);opacity:0;visibility:hidden;transition:opacity .18s,visibility .18s}
    .turnover-drawer-backdrop.is-open{opacity:1;visibility:visible}
    .turnover-drawer{display:flex;width:min(560px,100%);height:100%;flex-direction:column;background:#fff;box-shadow:-12px 0 32px rgba(0,0,0,.16);transform:translateX(100%);transition:transform .2s ease}
    .turnover-drawer-backdrop.is-open .turnover-drawer{transform:translateX(0)}
    .turnover-drawer-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:20px 22px;border-bottom:1px solid #e4e4e7}
    .turnover-drawer-header h2{margin:0;color:#18181b;font-size:18px;font-weight:600}
    .turnover-drawer-header p{margin:5px 0 0;color:#71717a;font-size:13px}
    .turnover-drawer-close{display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#52525b;font-size:15px;cursor:pointer}
    .turnover-drawer-close:hover{background:#f4f4f5}
    .turnover-drawer-body{flex:1;overflow:auto;padding:20px 22px}
    .turnover-reporter{display:flex;align-items:center;gap:12px;margin-bottom:18px}
    .turnover-reporter-avatar{display:flex;width:85px;height:85px;flex:0 0 85px;align-items:center;justify-content:center;overflow:hidden;border-radius:8px;background:#e4e4e7;color:#52525b;font-size:26px;font-weight:600}
    .turnover-reporter-avatar img{width:100%;height:100%;object-fit:cover}
    .turnover-detail-label{display:block;margin-bottom:3px;color:#71717a;font-size:12px}
    .turnover-reporter-name{color:#18181b;font-size:16px;font-weight:600}
    .turnover-drawer-status{margin-left:auto}
    .turnover-priority{display:flex;align-items:center;gap:8px;margin:0 0 16px;color:#18181b;font-size:14px;font-weight:500}
    .turnover-priority-pill{padding:4px 10px;border:1px solid #bfdbfe;border-radius:5px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:500}
    .turnover-detail-card{margin:0 0 18px;padding:15px;border:1px solid #e4e4e7;border-radius:8px;background:#fff}
    .turnover-detail-card h3{margin:0 0 12px;color:#18181b;font-size:14px;font-weight:600}
    .turnover-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .turnover-detail-value{color:#27272a;font-size:13px;line-height:1.5;overflow-wrap:anywhere}
    .turnover-drawer-footer{display:flex;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid #e4e4e7}
    .turnover-drawer-footer:empty{display:none}
    .turnover-drawer-body .handover-plan-card{margin:0 0 18px;border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:10px;overflow:hidden;background:#fff}
    .turnover-drawer-body .handover-plan-card.is-planned{border-color:#60a5fa;border-left-color:#60a5fa}
    .turnover-drawer-body .handover-plan-card.is-received{border-color:#16a34a;border-left-color:#16a34a}
    .turnover-drawer-body .handover-plan-card.is-overdue{border-color:#ef4444;border-left-color:#ef4444}
    .turnover-drawer-body .handover-plan-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;background:#fffbeb}
    .turnover-drawer-body .handover-plan-card.is-planned .handover-plan-heading{background:#eff6ff}
    .turnover-drawer-body .handover-plan-card.is-received .handover-plan-heading{background:#f0fdf4}
    .turnover-drawer-body .handover-plan-heading>div{display:flex;min-width:0;flex-direction:column;gap:2px}
    .turnover-drawer-body .handover-plan-heading strong{font-size:14px;color:#18181b}
    .turnover-drawer-body .handover-plan-heading span{font-size:12px;color:#71717a}
    .turnover-drawer-body .handover-plan-status{display:inline-flex;min-height:23px;flex:0 0 auto;align-items:center;padding:2px 8px;border:1px solid #fde68a;border-radius:5px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:500;white-space:nowrap}
    .turnover-drawer-body .handover-plan-status.is-needed{border-color:#fde68a;background:#fef3c7;color:#92400e}
    .turnover-drawer-body .handover-plan-card.is-planned .handover-plan-status{border-color:#bfdbfe;background:#dbeafe;color:#1d4ed8}
    .turnover-drawer-body .handover-plan-card.is-received .handover-plan-status{border-color:#0E8131;background:#0E8131;color:#fff}
    .turnover-drawer-body .handover-plan-body{padding:8px 12px}
    .turnover-drawer-body .handover-plan-row{display:flex;justify-content:space-between;gap:12px;padding:4px 0;font-size:13px;line-height:1.5}
    .turnover-drawer-body .handover-plan-row span{flex:0 0 38%;color:#71717a;font-size:13px;font-weight:600}
    .turnover-drawer-body .handover-plan-row strong{min-width:0;color:#18181b;font-size:15px;font-weight:400;text-align:right;overflow-wrap:anywhere}
    .turnover-drawer-body .handover-plan-note{margin:0;padding:9px 12px;border-top:1px solid #e4e4e7;background:#f4f4f5;color:#52525b;font-size:12px;line-height:1.5}
    .turnover-drawer-body .pen-validation-card{margin:0 0 18px;border:1px solid #16a34a;border-left:4px solid #16a34a;border-radius:12px;overflow:hidden;background:#fff;font-family:'Inter','Segoe UI',sans-serif}
    .turnover-drawer-body .pen-validation-heading{display:flex;align-items:center;gap:10px;padding:12px 14px;background:#f0fdf4}
    .turnover-drawer-body .pen-validation-heading-icon{width:18px;height:18px;flex:0 0 auto;color:#15803d}
    .turnover-drawer-body .pen-validation-heading-copy{min-width:0}
    .turnover-drawer-body .pen-validation-title{font-size:14px;font-weight:600;color:#18181b}
    .turnover-drawer-body .pen-validation-meta{margin-top:2px;font-size:12px;font-weight:400;color:#71717a}
    .turnover-drawer-body .pen-validation-status{display:inline-flex;align-items:center;margin-left:auto;padding:3px 10px;border-radius:6px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;line-height:1.4;white-space:nowrap}
    .turnover-drawer-body .pen-validation-content{display:flex;flex-direction:column;gap:10px;padding:12px 14px 14px}
    .turnover-drawer-body .pen-validation-message-field-label{display:inline;margin:0;font-size:13px;font-weight:600;line-height:1.5;color:#71717a}
    .turnover-drawer-body .pen-validation-message-field-value{display:inline;font-size:15px;font-weight:400;line-height:1.5;color:#18181b;overflow-wrap:anywhere}
    .turnover-drawer-body .pen-validation-label{margin-bottom:6px;font-size:13px;font-weight:600;color:#71717a}
    .turnover-drawer-body .pen-validation-message{padding:10px 12px;border-radius:9px;background:#f4f4f5;font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;line-height:1.5;color:#18181b;white-space:pre-wrap}
    .turnover-drawer-body .pen-validation-evidence-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,112px));gap:8px}
    .turnover-drawer-body .pen-validation-evidence{display:block;position:relative;width:100%;aspect-ratio:1/1;padding:0;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;background:#f4f4f5;cursor:pointer}
    .turnover-drawer-body .pen-validation-evidence img,.turnover-drawer-body .pen-validation-evidence video{display:block;width:100%;height:100%;object-fit:cover}
    .turnover-drawer-body .pen-validation-evidence video{background:#18181b}
    .turnover-drawer-body .pen-response-proposal{display:flex;gap:10px;padding:10px 12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e3a8a;font-size:13px;line-height:1.5}
    #responseWorkflowModal{z-index:1500}
    #responseWorkflowModal .response-workflow-dialog{background:#fff;color:#18181b}
    #responseWorkflowModal .response-workflow-header{margin-bottom:14px}
    #responseWorkflowModal .response-workflow-header h2{font-size:18px;font-weight:600}
    #responseWorkflowModal .response-workflow-header p{color:#71717a;font-size:14px;line-height:1.5}
    #responseWorkflowModal .response-workflow-field{display:block;margin:0 0 12px;color:#27272a;font-size:13px;font-weight:600}
    #responseWorkflowModal .response-workflow-field input,#responseWorkflowModal .response-workflow-field textarea,#responseWorkflowModal .response-workflow-field select{display:block;width:100%;min-height:38px;margin-top:5px;padding:8px;border:1px solid #d4d4d8;border-radius:7px;background:#fff;color:#18181b;font:inherit}
    #responseWorkflowModal .sd-dialog-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #e4e4e7}
    @media(max-width:1024px){.turnover-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.main-content{padding:16px!important}}
    @media(max-width:600px){.main-content{padding:14px 12px!important}.turnover-toolbar{align-items:stretch}.turnover-filters,.turnover-controls{width:100%}.turnover-input{flex:1;min-width:0}.turnover-controls{justify-content:space-between}.turnover-pagination-right{justify-content:center;gap:12px}.turnover-detail-grid{grid-template-columns:1fr}}
    @media(max-width:520px){.turnover-kpis{gap:10px}.turnover-kpi{padding:14px 10px}.turnover-kpi-label{font-size:12px}.turnover-kpi-value{font-size:23px}}
    .main-content,.turnover-toolbar,.turnover-filters,.turnover-controls,.turnover-table-wrap{min-width:0;max-width:100%}
    .main-content>.turnover-kpis,.main-content>.turnover-toolbar,.main-content>.turnover-error,.main-content>.turnover-table-wrap,.main-content>.turnover-pagination{width:100%;max-width:1550px;margin-inline:auto}
    .turnover-table-wrap{contain:paint;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch}
    @media(max-width:480px){
      .turnover-kpis{grid-template-columns:minmax(0,1fr)}
      .turnover-filters,.turnover-controls{display:grid;grid-template-columns:minmax(0,1fr);gap:8px}
      .turnover-input,.turnover-select,.turnover-button{width:100%;max-width:100%}
      .turnover-tabs{display:flex;width:100%}
      .turnover-tab{flex:1 1 0;justify-content:center;padding-inline:6px}
      .turnover-pagination{justify-content:center;padding-inline:0}
      .turnover-pagination-right{justify-content:center;gap:10px}
      .turnover-page-label{width:auto}
      .turnover-drawer-header,.turnover-drawer-body{padding-inline:16px}
      .turnover-drawer-footer{flex-wrap:wrap;padding-inline:16px}
      .turnover-drawer-footer>*{flex:1 1 100%}
    }
  </style>
</head>
<body class="staff-theme" data-portal="staff">
<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>
<main class="main-content">
  <section class="turnover-kpis" aria-label="Turnover summary">
    <?php foreach ($turnoverKpis['pending'] as $kpi): ?>
      <article class="turnover-kpi<?php echo !empty($kpi['variant']) ? ' is-' . $escape($kpi['variant']) : ''; ?>">
        <h2 class="turnover-kpi-label"><?php echo $escape($kpi['label']); ?></h2>
        <p class="turnover-kpi-value"><?php echo number_format($kpi['value']); ?></p>
        <span class="turnover-kpi-help"><?php echo $escape($kpi['help']); ?></span>
      </article>
    <?php endforeach; ?>
  </section>
  <?php if ($loadError !== ''): ?><div class="turnover-error" role="alert"><?php echo $escape($loadError); ?></div><?php endif; ?>
  <?php if (!$workflowEnabled && $loadError === ''): ?><div class="turnover-error" role="status">The turnover workflow is currently unavailable.</div><?php endif; ?>

  <div class="turnover-toolbar staff-mobile-toolbar staff-toolbar-search-only">
    <div class="turnover-filters staff-toolbar-filters">
      <div class="staff-toolbar-search-row staff-toolbar-no-filter">
      <input class="turnover-input staff-toolbar-search" id="turnoverSearch" type="search" placeholder="Search..." aria-label="Search turnover records">
      </div>
      <select class="turnover-select staff-toolbar-select" id="turnoverBarangayFilter" aria-label="Filter by barangay">
        <option value="">All Barangays</option>
        <?php foreach ($barangayOptions as $barangayOption): ?>
          <option value="<?php echo (int)$barangayOption['id']; ?>"><?php echo $escape($barangayOption['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <select class="turnover-select staff-toolbar-select staff-toolbar-last-filter" id="turnoverMethodFilter" aria-label="Filter by handover method">
        <option value="">All methods</option>
        <option value="barangay_delivery">Barangay delivers</option>
        <option value="dcvs_pickup">DCVS pickup</option>
        <option value="not_set">Not set</option>
      </select>
    </div>
    <div class="turnover-controls">
      <div class="turnover-tabs" role="tablist" aria-label="Turnover record status">
        <button class="turnover-tab" type="button" role="tab" aria-selected="true" data-turnover-tab="pending">For Turnover <span class="turnover-tab-count"><?php echo number_format($pendingCount); ?></span></button>
        <button class="turnover-tab" type="button" role="tab" aria-selected="false" data-turnover-tab="received">Turned Over <span class="turnover-tab-count"><?php echo number_format($turnedOverTotalCount); ?></span></button>
      </div>
      <button class="turnover-button" id="exportTurnover" type="button"><i class="fas fa-download" aria-hidden="true"></i> Export CSV</button>
    </div>
  </div>
  <div class="turnover-table-wrap">
    <table class="turnover-table">
      <thead id="turnoverTableHead"></thead>
      <tbody id="turnoverTableBody"></tbody>
    </table>
  </div>
  <div class="turnover-pagination dt-pagination" id="turnoverPagination"></div>
</main>
<div class="turnover-drawer-backdrop" id="turnoverDrawerBackdrop" aria-hidden="true">
  <aside class="turnover-drawer" id="turnoverDrawer" role="dialog" aria-modal="true" aria-labelledby="turnoverDrawerTitle" tabindex="-1">
    <header class="turnover-drawer-header">
      <div>
        <h2 id="turnoverDrawerTitle">Turnover Details</h2>
        <p id="turnoverDrawerReference"></p>
      </div>
      <button class="turnover-drawer-close" id="turnoverDrawerClose" type="button" aria-label="Close turnover details"><i class="fas fa-times" aria-hidden="true"></i></button>
    </header>
    <div class="turnover-drawer-body" id="turnoverDrawerBody"></div>
    <footer class="turnover-drawer-footer" id="turnoverDrawerFooter"></footer>
  </aside>
</div>
<script>
window.TURNOVER_DATA = <?php echo json_encode($turnoverRows, $jsonFlags); ?>;
window.TURNOVER_KPIS = <?php echo json_encode($turnoverKpis, $jsonFlags); ?>;
window.DCVS_APP_BASE_URL = <?php echo json_encode($appBaseUrl, $jsonFlags); ?>;
window.DCVS_RESPONSE_ROLE = 'staff';
window.DCVS_RESPONSE_CSRF = <?php echo json_encode(csrfToken()); ?>;
window.DCVS_BARANGAY_RESPONSE_ENABLED = <?php echo $workflowEnabled ? 'true' : 'false'; ?>;
</script>
<script src="<?php echo $escape($appBaseUrl . '/assets/js/response-workflow.js'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/response-workflow.js'); ?>"></script>
<script>
(function() {
  'use strict';
  var rows = Array.isArray(window.TURNOVER_DATA) ? window.TURNOVER_DATA : [];
  var searchInput = document.getElementById('turnoverSearch');
  var barangayFilter = document.getElementById('turnoverBarangayFilter');
  var methodFilter = document.getElementById('turnoverMethodFilter');
  var tableHead = document.getElementById('turnoverTableHead');
  var tableBody = document.getElementById('turnoverTableBody');
  var pagination = document.getElementById('turnoverPagination');
  var drawerBackdrop = document.getElementById('turnoverDrawerBackdrop');
  var drawer = document.getElementById('turnoverDrawer');
  var drawerBody = document.getElementById('turnoverDrawerBody');
  var drawerFooter = document.getElementById('turnoverDrawerFooter');
  var drawerReference = document.getElementById('turnoverDrawerReference');
  var drawerClose = document.getElementById('turnoverDrawerClose');
  var kpiCards = Array.prototype.slice.call(document.querySelectorAll('.turnover-kpi'));
  var currentTab = 'pending';
  var currentPage = 1;
  var pageSize = 10;
  var lastFocusedElement = null;
  var turnoverActionMenu = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character];
    });
  }
  function parseDate(value) {
    if (!value) return null;
    var normalized = String(value).replace(' ', 'T');
    if (!/[zZ]|[+-]\d\d:\d\d$/.test(normalized)) normalized += '+08:00';
    var date = new Date(normalized);
    return isNaN(date.getTime()) ? null : date;
  }
  function formatDate(value) {
    var date = parseDate(value);
    return date ? new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit'
    }).format(date) : 'Not set';
  }
  function methodLabel(row, completed) {
    if (row.handover_method === 'dcvs_pickup') return 'DCVS pickup';
    if (row.handover_method === 'barangay_delivery') return completed ? 'Barangay delivered' : 'Barangay delivers';
    return 'Not set';
  }
  function statusFor(row) {
    if (!row.planned_at || !row.handover_method) return {label:'Plan needed', className:'is-needed'};
    var due = parseDate(row.due_at);
    if (!due) return {label:'On time', className:'is-on-time'};
    var remaining = due.getTime() - Date.now();
    if (remaining < 0) return {label:'Overdue', className:'is-overdue'};
    if (remaining <= 24 * 60 * 60 * 1000) return {label:'Due soon', className:'is-due-soon'};
    return {label:'On time', className:'is-on-time'};
  }
  function visibleRows() {
    var term = (searchInput.value || '').trim().toLowerCase();
    var barangayId = barangayFilter.value;
    var method = methodFilter.value;
    return rows.filter(function(row) {
      var isReceived = row.turnover_status === 'receipt_confirmed';
      if ((currentTab === 'received') !== isReceived) return false;
      if (barangayId && String(row.barangay_id) !== barangayId) return false;
      if (method === 'not_set' && row.handover_method) return false;
      if (method && method !== 'not_set' && row.handover_method !== method) return false;
      if (!term) return true;
      return [row.reference_id, row.animal_type, row.animal_count, row.barangay_name,
        row.handover_method, row.receiver_name, row.holding_location,
        row.animal_disposition].join(' ').toLowerCase().indexOf(term) !== -1;
    });
  }
  function actionMenuButton(row) {
    return '<button class="turnover-row-actions" type="button" data-turnover-actions="' + Number(row.report_id) +
      '" aria-label="More options for ' + escapeHtml(row.reference_id || ('Report ' + row.report_id)) + '" aria-expanded="false">' +
      '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>';
  }
  function closeTurnoverActionMenu() {
    if (turnoverActionMenu) {
      var trigger = document.querySelector('[data-turnover-actions="' + turnoverActionMenu.getAttribute('data-report-id') + '"]');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
      turnoverActionMenu.remove();
      turnoverActionMenu = null;
    }
    document.removeEventListener('click', closeTurnoverActionMenu);
  }
  function archiveTurnoverReport(reportId) {
    var row = rows.find(function(item) { return Number(item.report_id) === Number(reportId); });
    if (!row || row.turnover_status !== 'receipt_confirmed') return;
    if (!window.confirm('Archive ' + (row.reference_id || 'this report') + '?')) return;
    var formData = new FormData();
    formData.append('action', 'archive_report');
    formData.append('report_id', String(reportId));
    var appBaseUrl = String(window.DCVS_APP_BASE_URL || '').replace(/\/+$/, '');
    fetch(appBaseUrl + '/api/staff/client-database-handler.php', {method:'POST', body:formData})
      .then(function(response) {
        return response.json().then(function(data) {
          if (!response.ok || !data.success) throw new Error(data.message || 'Could not archive report.');
          return data;
        });
      })
      .then(function() { window.location.reload(); })
      .catch(function(error) {
        console.error('Could not archive turned-over report:', error);
        window.alert(error.message || 'Could not archive report. Please try again.');
      });
  }
  function openTurnoverActionMenu(event, row) {
    closeTurnoverActionMenu();
    var received = row.turnover_status === 'receipt_confirmed';
    var menu = document.createElement('div');
    menu.className = 'cpd row-menu-pop open turnover-menu-pop';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('data-report-id', String(row.report_id));
    menu.style.position = 'fixed';
    menu.innerHTML = received
      ? '<button class="row-menu-item danger" type="button" data-turnover-archive="' + Number(row.report_id) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive Report</button>'
      : '<button class="row-menu-item" type="button" data-turnover-confirm="' + Number(row.report_id) + '"' +
        (row.planned_at && row.handover_method ? '' : ' disabled title="The barangay must set a handover plan first."') +
        '><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>Confirm Turnover Received</button>';
    var rect = event.currentTarget.getBoundingClientRect();
    menu.style.top = Math.min(rect.bottom + 6, window.innerHeight - 90) + 'px';
    menu.style.left = Math.max(8, Math.min(rect.right - 210, window.innerWidth - 218)) + 'px';
    menu.addEventListener('click', function(menuEvent) {
      var confirmButton = menuEvent.target.closest('[data-turnover-confirm]');
      var archiveButton = menuEvent.target.closest('[data-turnover-archive]');
      if (confirmButton && !confirmButton.disabled) {
        closeTurnoverActionMenu();
        var confirmId = Number(confirmButton.getAttribute('data-turnover-confirm'));
        var rowConfirmButton = document.createElement('button');
        rowConfirmButton.setAttribute('data-confirm-turnover', String(confirmId));
        confirmTurnover(rowConfirmButton);
      } else if (archiveButton) {
        var archiveId = Number(archiveButton.getAttribute('data-turnover-archive'));
        closeTurnoverActionMenu();
        archiveTurnoverReport(archiveId);
      }
    });
    document.body.appendChild(menu);
    turnoverActionMenu = menu;
    event.currentTarget.setAttribute('aria-expanded', 'true');
    setTimeout(function() { document.addEventListener('click', closeTurnoverActionMenu); }, 0);
  }
  function renderPagination(totalRows, totalPages) {
    pagination.innerHTML = '<div class="turnover-pagination-right dt-pagination-right">' +
      '<label class="turnover-rows dt-rows"><span>Rows per page</span><select id="turnoverPageSize" aria-label="Rows per page">' +
      [10,20,30,40,50].map(function(size) { return '<option value="' + size + '"' + (pageSize === size ? ' selected' : '') + '>' + size + '</option>'; }).join('') +
      '</select></label><span class="turnover-page-label dt-page-label">Page ' + currentPage + ' of ' + totalPages + '</span>' +
      '<span class="turnover-page-buttons dt-page-btns">' +
      '<button class="dt-page-btn" type="button" data-page-command="first" title="Go to first page" aria-label="Go to first page"' + (currentPage <= 1 ? ' disabled' : '') + '><i class="fas fa-angles-left" aria-hidden="true"></i></button>' +
      '<button class="dt-page-btn" type="button" data-page-command="prev" title="Go to previous page" aria-label="Go to previous page"' + (currentPage <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left" aria-hidden="true"></i></button>' +
      '<button class="dt-page-btn" type="button" data-page-command="next" title="Go to next page" aria-label="Go to next page"' + (currentPage >= totalPages ? ' disabled' : '') + '><i class="fas fa-chevron-right" aria-hidden="true"></i></button>' +
      '<button class="dt-page-btn" type="button" data-page-command="last" title="Go to last page" aria-label="Go to last page"' + (currentPage >= totalPages ? ' disabled' : '') + '><i class="fas fa-angles-right" aria-hidden="true"></i></button>' +
      '</span></div>';
  }
  function render() {
    var received = currentTab === 'received';
    tableHead.innerHTML = received
      ? '<tr><th>Report</th><th>Animal</th><th>Number of Animals</th><th>Barangay</th><th>Handed over</th><th>Date turned over</th><th>Received by</th><th>Holding location</th><th>Disposition</th><th aria-label="Actions"></th></tr>'
      : '<tr><th>Report</th><th>Animal</th><th>Number of Animals</th><th>Barangay</th><th>Method</th><th>Planned</th><th>Due by</th><th>Status</th><th aria-label="Actions"></th></tr>';
    var filtered = visibleRows();
    var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    currentPage = Math.min(Math.max(currentPage, 1), totalPages);
    var pageRows = filtered.slice((currentPage - 1) * pageSize, currentPage * pageSize);
    if (!pageRows.length) {
      tableBody.innerHTML = '<tr><td colspan="' + (received ? 10 : 9) + '" class="turnover-empty">' +
        (filtered.length ? 'No turnover records on this page.' : (received ? 'No animals have been received by DCVS yet.' : 'No animals are awaiting handover.')) +
        '</td></tr>';
    } else {
      tableBody.innerHTML = pageRows.map(function(row) {
        var report = escapeHtml(row.reference_id || ('Report #' + row.report_id));
        var animal = escapeHtml(row.animal_type || 'Animal');
        var count = escapeHtml(row.animal_count || '—');
        var barangay = escapeHtml(row.barangay_name || '—');
        if (received) {
          var dispositionPill = '<span class="turnover-status is-rescued">Turned over</span>';
          return '<tr data-turnover-report="' + Number(row.report_id) + '" tabindex="0" aria-label="View turnover details for ' + report + '"><td><span class="turnover-report">' + report + '</span></td><td>' + animal + '</td><td>' + count + '</td>' +
            '<td>' + barangay + '</td><td>' + escapeHtml(methodLabel(row, true)) + '</td><td>' + escapeHtml(formatDate(row.received_at)) +
            '</td><td>' + escapeHtml(row.receiver_name || 'DCVS staff') + '</td><td>' + escapeHtml(row.holding_location || 'Not recorded') +
            '</td><td>' + dispositionPill + '</td><td class="turnover-row-menu-cell">' + actionMenuButton(row) + '</td></tr>';
        }
        var status = statusFor(row);
        return '<tr data-turnover-report="' + Number(row.report_id) + '" tabindex="0" aria-label="View turnover details for ' + report + '"><td><span class="turnover-report">' + report + '</span></td><td>' + animal + '</td><td>' + count + '</td>' +
          '<td>' + barangay + '</td><td>' + escapeHtml(methodLabel(row)) + '</td><td>' + escapeHtml(formatDate(row.planned_at)) +
          '</td><td>' + escapeHtml(formatDate(row.due_at)) + '</td><td><span class="turnover-status ' + status.className + '">' + status.label +
          '</span></td><td class="turnover-row-menu-cell">' + actionMenuButton(row) + '</td></tr>';
      }).join('');
    }
    renderPagination(filtered.length, totalPages);
  }
  function renderKpis() {
    var cards = window.TURNOVER_KPIS && window.TURNOVER_KPIS[currentTab];
    if (!Array.isArray(cards)) return;
    kpiCards.forEach(function(card, index) {
      var item = cards[index];
      if (!item) return;
      card.classList.toggle('is-overdue', item.variant === 'overdue');
      card.querySelector('.turnover-kpi-label').textContent = item.label;
      card.querySelector('.turnover-kpi-value').textContent = Number(item.value).toLocaleString();
      card.querySelector('.turnover-kpi-help').textContent = item.help;
    });
  }
  function initials(name) {
    return String(name || 'Resident').trim().split(/\s+/).slice(0, 2).map(function(part) {
      return part.charAt(0);
    }).join('').toUpperCase();
  }
  function openDrawer(row) {
    if (!row) return;
    lastFocusedElement = document.activeElement;
    var received = row.turnover_status === 'receipt_confirmed';
    var avatar = row.reporter_avatar
      ? '<img src="' + escapeHtml(row.reporter_avatar) + '" alt="">'
      : escapeHtml(initials(row.reporter_name));
    var priority = String(row.urgency_level || 'low');
    priority = priority.charAt(0).toUpperCase() + priority.slice(1);
    var validationReport = {
      brgy: row.barangay_name,
      brgyValidationStatus: row.barangay_validation_status,
      brgyValidationNotes: row.barangay_validation_notes,
      brgyNotes: row.barangay_notes,
      brgyValidationEvidence: row.validation_evidence || [],
      validated: row.validated_at ? formatDate(row.validated_at) : '',
      responseState: row.response_state,
      statusRaw: 'resolved',
      resolutionDetails: row.resolution_details,
      animalDisposition: row.animal_disposition,
      responseHistory: row.latest_update ? [{
        toState: row.response_state,
        actor: row.latest_update_actor,
        createdAt: row.latest_update_at ? formatDate(row.latest_update_at) : '',
        reason: row.latest_update
      }] : []
    };
    var turnoverReport = {
      statusRaw: 'resolved',
      type: row.report_type,
      animalDisposition: row.animal_disposition,
      turnoverStatus: row.turnover_status,
      turnoverDueAt: row.due_at,
      turnoverDueAtDisplay: row.due_at ? formatDate(row.due_at) : '',
      turnoverMethod: row.handover_method,
      turnoverPlannedAt: row.planned_at ? formatDate(row.planned_at) : '',
      turnoverHandlerName: row.handler_name,
      turnoverContactNumber: row.contact_number,
      turnoverPickupLocation: row.pickup_location,
      turnoverSafeTransport: row.safe_transport,
      turnoverReceivedAtDisplay: row.received_at ? formatDate(row.received_at) : '',
      turnoverReceiver: row.receiver_name,
      holdingLocation: row.holding_location,
      brgy: row.barangay_name
    };
    var validationHtml = typeof window.responseWorkflowBarangayValidationHtml === 'function'
      ? window.responseWorkflowBarangayValidationHtml(validationReport)
      : '<div class="turnover-error" role="alert">Barangay Validation details are unavailable. Refresh this page and try again.</div>';
    var handoverHtml = typeof window.responseWorkflowHandoverHtml === 'function'
      ? window.responseWorkflowHandoverHtml(turnoverReport)
      : '<div class="turnover-error" role="alert">Turnover details are unavailable. Refresh this page and try again.</div>';
    drawerReference.textContent = row.reference_id || ('Report #' + row.report_id);
    var headerStatus = received
      ? '<span class="turnover-status is-rescued turnover-drawer-status">Turned over</span>'
      : '<span class="turnover-status is-pending-review turnover-drawer-status">Awaiting Barangay Turnover</span>';
    drawerBody.innerHTML =
      '<div class="turnover-reporter"><div class="turnover-reporter-avatar">' + avatar + '</div><div><span class="turnover-detail-label">Reported by</span><div class="turnover-reporter-name">' + escapeHtml(row.reporter_name || '—') + '</div></div>' +
      headerStatus + '</div>' +
      '<div class="turnover-priority"><span>Priority</span><span class="turnover-priority-pill">' + escapeHtml(priority) + '</span></div>' +
      validationHtml + handoverHtml;
    drawerFooter.innerHTML = received ? '' :
      '<button class="turnover-confirm sd-btn sd-btn-default" type="button" data-confirm-turnover="' + Number(row.report_id) + '"' +
      (row.planned_at && row.handover_method ? '' : ' disabled title="The barangay must set a handover plan first."') + '>Confirm Turnover Received</button>';
    drawerBackdrop.classList.add('is-open');
    drawerBackdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    drawer.focus();
  }
  function closeDrawer() {
    if (!drawerBackdrop.classList.contains('is-open')) return;
    drawerBackdrop.classList.remove('is-open');
    drawerBackdrop.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') lastFocusedElement.focus();
  }
  function exportCsv() {
    var received = currentTab === 'received';
    var headers = received
      ? ['Report','Animal','Number of Animals','Barangay','Handed over','Date turned over','Received by','Holding location','Disposition']
      : ['Report','Animal','Number of Animals','Barangay','Method','Planned','Due by','Status'];
    var csvRows = [headers];
    visibleRows().forEach(function(row) {
      var fields = received
        ? [row.reference_id, row.animal_type, row.animal_count, row.barangay_name, methodLabel(row, true), formatDate(row.received_at), row.receiver_name || 'DCVS staff', row.holding_location || 'Not recorded', String(row.animal_disposition || 'Not recorded').replace(/_/g, ' ')]
        : [row.reference_id, row.animal_type, row.animal_count, row.barangay_name, methodLabel(row, false), formatDate(row.planned_at), formatDate(row.due_at), statusFor(row).label];
      csvRows.push(fields.map(function(value) {
        return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"';
      }));
    });
    var blob = new Blob([csvRows.map(function(row) { return row.join(','); }).join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = received ? 'staff-turned-over.csv' : 'staff-for-turnover.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  document.querySelectorAll('[data-turnover-tab]').forEach(function(button) {
    button.addEventListener('click', function() {
      currentTab = button.getAttribute('data-turnover-tab');
      currentPage = 1;
      document.querySelectorAll('[data-turnover-tab]').forEach(function(tab) {
        tab.setAttribute('aria-selected', tab === button ? 'true' : 'false');
      });
      renderKpis();
      render();
    });
  });
  [searchInput, barangayFilter, methodFilter].forEach(function(control) {
    control.addEventListener(control === searchInput ? 'input' : 'change', function() {
      currentPage = 1;
      render();
    });
  });
  function confirmTurnover(button) {
    if (!button || button.disabled) return;
    if (typeof window.responseWorkflowAction !== 'function') {
      window.alert('Turnover confirmation is unavailable. Refresh this page and try again.');
      return;
    }
    window.responseWorkflowAction(Number(button.getAttribute('data-confirm-turnover')), 'confirm_turnover');
  }
  tableBody.addEventListener('click', function(event) {
    var actionTrigger = event.target.closest('[data-turnover-actions]');
    if (actionTrigger) {
      event.preventDefault();
      event.stopPropagation();
      var actionRow = rows.find(function(row) {
        return Number(row.report_id) === Number(actionTrigger.getAttribute('data-turnover-actions'));
      });
      if (actionRow) openTurnoverActionMenu(event, actionRow);
      return;
    }
    var button = event.target.closest('[data-confirm-turnover]');
    if (button) {
      confirmTurnover(button);
      return;
    }
    var rowElement = event.target.closest('[data-turnover-report]');
    if (!rowElement) return;
    var selectedRow = rows.find(function(row) {
      return Number(row.report_id) === Number(rowElement.getAttribute('data-turnover-report'));
    });
    openDrawer(selectedRow);
  });
  tableBody.addEventListener('keydown', function(event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    var rowElement = event.target.closest('[data-turnover-report]');
    if (!rowElement || event.target.closest('button')) return;
    event.preventDefault();
    var selectedRow = rows.find(function(row) {
      return Number(row.report_id) === Number(rowElement.getAttribute('data-turnover-report'));
    });
    openDrawer(selectedRow);
  });
  drawerFooter.addEventListener('click', function(event) {
    confirmTurnover(event.target.closest('[data-confirm-turnover]'));
  });
  drawerClose.addEventListener('click', closeDrawer);
  drawerBackdrop.addEventListener('click', function(event) {
    if (event.target === drawerBackdrop) closeDrawer();
  });
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      closeTurnoverActionMenu();
      closeDrawer();
    }
  });
  pagination.addEventListener('change', function(event) {
    if (event.target.id !== 'turnoverPageSize') return;
    pageSize = Number(event.target.value) || 10;
    currentPage = 1;
    render();
  });
  pagination.addEventListener('click', function(event) {
    var button = event.target.closest('[data-page-command]');
    if (!button || button.disabled) return;
    var command = button.getAttribute('data-page-command');
    var pageCount = Math.max(1, Math.ceil(visibleRows().length / pageSize));
    if (command === 'first') currentPage = 1;
    else if (command === 'prev') currentPage--;
    else if (command === 'next') currentPage++;
    else if (command === 'last') currentPage = pageCount;
    render();
  });
  document.getElementById('exportTurnover').addEventListener('click', exportCsv);
  render();
})();
</script>
</body>
</html>
