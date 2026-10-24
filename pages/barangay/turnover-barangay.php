<?php
session_name('DCVS_BARANGAY_SESSION');
session_start();

if (($_SESSION['logged_in'] ?? false) !== true
    || !isset($_SESSION['user_id'], $_SESSION['barangay_id'])
    || ($_SESSION['role'] ?? '') !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../env.php';
loadDcvsEnv();
date_default_timezone_set('Asia/Manila');

$barangayId = (int)$_SESSION['barangay_id'];
$fullName = (string)($_SESSION['full_name'] ?? 'Barangay Officer');
$barangayName = 'Barangay';
$loadError = '';
$turnoverRows = [];
$workflowEnabled = env('DCVS_BARANGAY_RESPONSE_ENABLED', '0') === '1';

$barangayQuery = $conn->prepare('SELECT barangay_name FROM barangays WHERE barangay_id = ?');
if (!$barangayQuery) {
    error_log('Could not prepare barangay name query for turnover page: ' . $conn->error);
    $loadError = 'Could not load your barangay details. Please refresh and try again.';
} else {
    $barangayQuery->bind_param('i', $barangayId);
    if (!$barangayQuery->execute()) {
        error_log('Could not load barangay name for turnover page: ' . $barangayQuery->error);
        $loadError = 'Could not load your barangay details. Please refresh and try again.';
    } else {
        $barangayRow = $barangayQuery->get_result()->fetch_assoc();
        if ($barangayRow) $barangayName = (string)$barangayRow['barangay_name'];
    }
    $barangayQuery->close();
}

if ($loadError === '' && $workflowEnabled) {
    $turnoverQuery = $conn->prepare("
        SELECT r.report_id, r.reference_id, r.animal_type, r.animal_count, r.status AS report_status,
               reporter.full_name AS reporter_name, reporter.avatar_url AS reporter_avatar,
               t.status AS turnover_status, t.holding_location, t.due_at,
               t.handover_method, t.planned_at, t.handler_name, t.contact_number,
               t.pickup_location, t.safe_transport,
               t.received_at, receiver.full_name AS receiver_name,
               rr.animal_disposition
        FROM report_turnover_tasks t
        INNER JOIN reports r ON r.report_id = t.report_id
        INNER JOIN users reporter ON reporter.user_id = r.reporter_id
        LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id
        LEFT JOIN users receiver ON receiver.user_id = t.received_by
        WHERE r.barangay_id = ?
          AND r.archived_at IS NULL
          AND t.status IN ('pending', 'receipt_confirmed')
        ORDER BY CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,
                 t.due_at ASC, COALESCE(t.received_at, t.requested_at) DESC
    ");
    if (!$turnoverQuery) {
        error_log('Could not prepare Barangay turnover list query: ' . $conn->error);
        $loadError = 'Could not load turnover records. Please verify the turnover workflow migrations.';
    } else {
        $turnoverQuery->bind_param('i', $barangayId);
        if (!$turnoverQuery->execute()) {
            error_log('Could not load Barangay turnover records: ' . $turnoverQuery->error);
            $loadError = 'Could not load turnover records. Please refresh and try again.';
        } else {
            $result = $turnoverQuery->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['report_id'] = (int)$row['report_id'];
                $row['turnover_status'] = (string)$row['turnover_status'];
                $row['handover_method'] = (string)($row['handover_method'] ?? '');
                $row['due_at'] = (string)($row['due_at'] ?? '');
                $row['planned_at'] = (string)($row['planned_at'] ?? '');
                $row['received_at'] = (string)($row['received_at'] ?? '');
                $row['report_status'] = (string)($row['report_status'] ?? '');
                $row['reporter_name'] = (string)($row['reporter_name'] ?? '');
                $row['reporter_avatar'] = (string)($row['reporter_avatar'] ?? '');
                $turnoverRows[] = $row;
            }
        }
        $turnoverQuery->close();
    }
}

$pendingCount = 0;
$planNeededCount = 0;
$overdueCount = 0;
$turnedOverCount = 0;
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$workflowReports = [];
foreach ($turnoverRows as $row) {
    $isPending = $row['turnover_status'] === 'pending';
    $hasPlan = $row['planned_at'] !== '' && $row['handover_method'] !== '';
    if ($isPending) {
        $pendingCount++;
        if (!$hasPlan) $planNeededCount++;
        if ($row['due_at'] !== '') {
            try {
                if (new DateTimeImmutable($row['due_at'], new DateTimeZone('Asia/Manila')) < $now) $overdueCount++;
            } catch (Exception $exception) {
                error_log('Invalid turnover due date for report ' . $row['report_id'] . ': ' . $exception->getMessage());
            }
        }
    } else {
        $turnedOverCount++;
    }

    $workflowReports[] = [
        'report_id' => $row['report_id'],
        'id' => $row['report_id'],
        'type' => (string)($row['animal_type'] ?? ''),
        'animalType' => (string)($row['animal_type'] ?? ''),
        'animalCount' => (string)($row['animal_count'] ?? ''),
        '_db_status' => (string)$row['report_status'],
        'animalDisposition' => (string)($row['animal_disposition'] ?? ''),
        'reporter' => $row['reporter_name'],
        'avatar' => $row['reporter_avatar'],
        'turnoverStatus' => $row['turnover_status'],
        'turnoverDueAt' => $row['due_at'],
        'turnoverDueAtDisplay' => $row['due_at'] !== '' ? date('M j, Y g:i A', strtotime($row['due_at'])) : '',
        'turnoverMethod' => $row['handover_method'],
        'turnoverPlannedAt' => $row['planned_at'] !== '' ? date('M j, Y g:i A', strtotime($row['planned_at'])) : '',
        'turnoverPlannedAtRaw' => $row['planned_at'],
        'turnoverReceivedAt' => $row['received_at'],
        'turnoverReceivedAtDisplay' => $row['received_at'] !== '' ? date('M j, Y g:i A', strtotime($row['received_at'])) : '',
        'turnoverReceiver' => (string)($row['receiver_name'] ?? ''),
        'turnoverHandlerName' => (string)($row['handler_name'] ?? ''),
        'turnoverContactNumber' => (string)($row['contact_number'] ?? ''),
        'turnoverPickupLocation' => (string)($row['pickup_location'] ?? ''),
        'turnoverSafeTransport' => (bool)($row['safe_transport'] ?? false),
        'holdingLocation' => (string)($row['holding_location'] ?? ''),
        'brgy' => $barangayName,
    ];
}

$avatar = (string)($_SESSION['avatar_url'] ?? '');
$activePage = 'turnover';
require_once __DIR__ . '/../../includes/barangay-app-base.php';
$appBaseUrl = dcvsBarangayAppBaseUrl();
$escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Animal Turnover — <?php echo $escape($barangayName); ?> | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="<?php echo $escape($appBaseUrl . '/assets/css/sb2.css'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/css/sb2.css'); ?>">
  <style>
    *,*::before,*::after{box-sizing:border-box}
    html{-webkit-font-smoothing:antialiased}
    body{margin:0;background:#fff;color:#18181b;font-family:Inter,'Segoe UI',sans-serif;font-size:14px}
    body.sb2-active .turnover-app{padding-left:var(--sidebar-w);transition:padding-left .2s ease}
    .turnover-app{min-width:0;min-height:100vh;background:#fff}
    .turnover-topbar{position:sticky;top:0;z-index:40;display:flex;min-width:0;align-items:center;justify-content:space-between;height:64px;padding:0 28px;border-bottom:1px solid #e4e4e7;background:#fff}
    .turnover-topbar-toggle{display:none;width:38px;height:38px;flex:0 0 38px;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:8px;background:#fff;color:#18181b;cursor:pointer}
    .turnover-breadcrumb{display:flex;align-items:center;gap:8px;color:#18181b;font-size:14px;font-weight:600}
    .turnover-breadcrumb a{color:#0b7a32;text-decoration:none}
    .turnover-user{display:flex;align-items:center;gap:10px;color:#18181b;font-size:13px;font-weight:600}
    .turnover-avatar{display:flex;width:34px;height:34px;align-items:center;justify-content:center;overflow:hidden;border-radius:50%;background:#d5ece0;color:#0b4d26}
    .turnover-avatar img{width:100%;height:100%;object-fit:cover}
    .turnover-user small{display:block;margin-top:2px;color:#71717a;font-size:12px;font-weight:400}
    .turnover-main{min-width:0;padding:18px 24px 30px}
    .turnover-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px}
    .turnover-kpi{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:18px 20px;border:1px solid #e4e4e7;border-radius:10px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);font-family:Inter,'Segoe UI',sans-serif;text-align:center;cursor:default;transition:border-color .15s,box-shadow .15s}
    .turnover-kpi:hover{border-color:#d4d4d8;box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03)}
    .turnover-kpi-label{margin:0 0 6px;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif;text-transform:uppercase;letter-spacing:.03em}
    .turnover-kpi-value{margin:0 0 6px;color:#18181b;font:600 26px/1 Inter,'Segoe UI',sans-serif;letter-spacing:-.02em}
    .turnover-kpi-help{color:#71717a;font:400 14px Inter,'Segoe UI',sans-serif}
    .turnover-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}
    .turnover-filters,.turnover-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .turnover-input,.turnover-select,.turnover-button{height:36px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif}
    .turnover-input{width:min(300px,76vw);padding:0 12px;font-weight:400}
    .turnover-select{min-width:155px;padding:0 28px 0 10px}
    .turnover-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0 13px;cursor:pointer;text-decoration:none;white-space:nowrap}
    .turnover-button:hover{background:#f4f4f5}
    .turnover-button svg{width:14px;height:14px}
    .turnover-tabs{display:inline-flex;align-items:center;padding:3px;border:1px solid #e4e4e7;border-radius:7px;background:#fafafa}
    .turnover-tab{display:inline-flex;align-items:center;gap:7px;min-height:28px;padding:0 11px;border:0;border-radius:5px;background:transparent;color:#71717a;font:500 13px Inter,'Segoe UI',sans-serif;cursor:pointer}
    .turnover-tab[aria-selected="true"]{background:#fff;color:#18181b;box-shadow:0 1px 2px rgba(0,0,0,.12)}
    .turnover-tab-count{min-width:17px;padding:1px 5px;border-radius:999px;background:#f4f4f5;color:#52525b;font-size:11px;text-align:center}
    .turnover-table-wrap{width:100%;min-width:0;max-width:100%;contain:paint;overflow-x:auto;border:1px solid #e4e4e7;border-radius:8px;background:#fff}
    .turnover-table{width:100%;min-width:1050px;border-collapse:collapse}
    .turnover-table th{padding:10px 12px;border-bottom:1px solid #e4e4e7;background:#fafafa;color:#71717a;font-size:14px;font-weight:500;text-align:left;white-space:nowrap}
    .turnover-table td{padding:11px 12px;border-bottom:1px solid #f4f4f5;color:#18181b;font-size:14px;vertical-align:middle}
    .turnover-table tr:last-child td{border-bottom:0}
    .turnover-table tbody tr{cursor:pointer}
    .turnover-table tbody tr:hover{background:#fafafa}
    .turnover-report-link{color:#18181b;font-weight:600;text-decoration:none}
    .turnover-report-link:hover{text-decoration:underline}
    .turnover-report-link{padding:0;border:0;background:none;font:inherit;cursor:pointer}
    .turnover-subtext{display:block;margin-top:3px;color:#71717a;font-size:12px;font-weight:400}
    .turnover-status{display:inline-flex;align-items:center;min-height:23px;padding:2px 8px;border:1px solid #e4e4e7;border-radius:5px;background:#fafafa;color:#52525b;font-size:12px;font-weight:500;white-space:nowrap}
    .turnover-status.is-needed{border-color:#fde68a;background:#fffbeb;color:#a16207}
    .turnover-status.is-scheduled{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}
    .turnover-status.is-overdue{border-color:#fecaca;background:#fef2f2;color:#b91c1c}
    .turnover-status.is-received{border-color:#0E8131;background:#0E8131;color:#fff}
    .action-dropdown{position:static;display:inline-block}
    .sd-row-actions{display:inline-flex;width:32px;height:32px;align-items:center;justify-content:center;padding:0;border:0;border-radius:6px;background:transparent;color:#71717a;font:400 14px/1 Inter,'Segoe UI',sans-serif;cursor:pointer;transition:all .15s}
    .sd-row-actions:hover{background:#f4f4f5;color:#18181b}
    .sd-row-actions .sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    .action-menu{position:fixed;z-index:9999;display:none;min-width:190px;padding:6px;border:1px solid #e6e9ee;border-radius:10px;background:#fff;box-shadow:0 4px 14px rgba(15,23,32,.06)}
    .action-menu.open{display:block}
    .action-menu-item{display:flex;width:100%;min-height:36px;align-items:center;gap:10px;padding:9px 10px;border:0;border-radius:8px;background:none;color:#334155;font:500 13px/1.4 Inter,'Segoe UI',system-ui,sans-serif;text-align:left;text-decoration:none;cursor:pointer}
    .action-menu-item:hover{background:#f8fafc}
    .action-menu-item svg{width:15px;height:15px;flex-shrink:0}
    .action-menu-item.danger{color:#dc2626}
    .action-menu-item.danger:hover{background:#fef2f2}
    .turnover-pagination{display:flex;align-items:center;justify-content:flex-end;padding:12px 4px}
    .turnover-pagination-inner{display:flex;align-items:center;gap:20px;flex-wrap:wrap;color:#71717a;font-size:14px}
    .turnover-rows{display:flex;align-items:center;gap:8px}
    .turnover-rows select{height:32px;padding:0 8px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:14px Inter,'Segoe UI',sans-serif}
    .turnover-page-label{min-width:88px;color:#18181b;font-weight:500;text-align:center}
    .turnover-page-buttons{display:flex;gap:4px}
    .turnover-page-buttons button{width:32px;height:32px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;cursor:pointer}
    .turnover-page-buttons button:disabled{opacity:.45;cursor:not-allowed}
    .turnover-empty,.turnover-error{padding:32px 16px;color:#71717a;text-align:center}
    .turnover-error{margin-bottom:12px;border:1px solid #fecaca;border-radius:7px;background:#fef2f2;color:#991b1b;text-align:left}
    .turnover-empty i{display:block;margin-bottom:8px;color:#a1a1aa;font-size:22px}
    #turnoverDetailDrawer{position:fixed;inset:0;z-index:1300;display:none;justify-content:flex-end;background:rgba(0,0,0,.5)}
    #turnoverDetailDrawer.is-open{display:flex}
    .turnover-drawer-panel{display:flex;width:min(480px,92vw);height:100%;min-height:0;flex-direction:column;padding:24px;background:#fff;box-shadow:-8px 0 40px rgba(15,23,32,.18);font-family:Inter,'Segoe UI',sans-serif;transform:translateX(105%);transition:transform .32s cubic-bezier(.32,.72,.25,1)}
    #turnoverDetailDrawer.is-open .turnover-drawer-panel{transform:translateX(0)}
    .turnover-drawer-header{display:flex;flex-shrink:0;align-items:flex-start;justify-content:space-between;gap:16px;padding-bottom:14px;border-bottom:1px solid #e4e4e7}
    .turnover-drawer-title{margin:0;color:#18181b;font-size:18px;font-weight:600}
    .turnover-drawer-reference{margin:4px 0 0;color:#52525b;font-size:14px;line-height:1.45;overflow-wrap:anywhere}
    .turnover-drawer-close{display:flex;width:32px;height:32px;flex:0 0 auto;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#52525b;cursor:pointer}
    .turnover-drawer-close:hover{background:#f4f4f5;color:#18181b}
    .turnover-drawer-body{min-height:0;flex:1;overflow-y:auto;padding-top:18px;scrollbar-gutter:stable}
    .turnover-drawer-summary{display:flex;align-items:center;gap:10px;min-width:0;margin:0 0 18px}
    .turnover-drawer-avatar{display:flex;width:85px;height:85px;flex:0 0 85px;align-items:center;justify-content:center;overflow:hidden;border-radius:8px;background:#e4e4e7;color:#64748b;font-size:18px;font-weight:600}
    .turnover-drawer-avatar img{width:100%;height:100%;object-fit:cover}
    .turnover-drawer-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:2px}
    .turnover-drawer-label{color:#71717a;font-size:12px;font-weight:400}
    .turnover-drawer-name{overflow:hidden;color:#18181b;font-size:14px;font-weight:600;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}
    .turnover-drawer-status{display:flex;flex:0 0 auto;flex-direction:column;align-items:flex-end;gap:4px}
    .turnover-drawer-status span{padding:4px 9px;border:1px solid;border-radius:6px;font-size:12px;font-weight:600;line-height:1.25;white-space:nowrap}
    .turnover-drawer-status .is-pending{border-color:#fcd34d;background:#fef3c7;color:#92400e}
    .turnover-drawer-status .is-turned-over{border-color:#0E8131;background:#0E8131;color:#fff}
    .turnover-drawer-body .handover-plan-card{margin:0;border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:10px;overflow:hidden;background:#fff}
    .turnover-drawer-body .handover-plan-card.is-planned{border-color:#60a5fa;border-left-color:#60a5fa}
    .turnover-drawer-body .handover-plan-card.is-overdue{border-color:#ef4444;border-left-color:#ef4444}
    .turnover-drawer-body .handover-plan-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;background:#fffbeb}
    .turnover-drawer-body .handover-plan-card.is-planned .handover-plan-heading{background:#eff6ff}
    .turnover-drawer-body .handover-plan-heading>div{display:flex;min-width:0;flex-direction:column;gap:2px}
    .turnover-drawer-body .handover-plan-heading strong{font-size:14px;color:#18181b}
    .turnover-drawer-body .handover-plan-heading span{font-size:12px;color:#71717a}
    .turnover-drawer-body .handover-plan-status{flex:0 0 auto;padding:3px 8px;border:1px solid #fde68a;border-radius:6px;background:#fef3c7;color:#92400e!important;font-size:12px!important;font-weight:600}
    .turnover-drawer-body .handover-plan-card.is-planned .handover-plan-status{border-color:#bfdbfe;background:#dbeafe;color:#1d4ed8!important}
    .turnover-drawer-body .handover-plan-card.is-received .handover-plan-status{border-color:#0E8131;background:#0E8131;color:#fff!important}
    .turnover-drawer-body .handover-plan-body{padding:8px 12px}
    .turnover-drawer-body .handover-plan-row{display:flex;justify-content:space-between;gap:12px;padding:4px 0;font-size:13px;line-height:1.5}
    .turnover-drawer-body .handover-plan-row span{flex:0 0 38%;color:#71717a;font-size:13px;font-weight:600}
    .turnover-drawer-body .handover-plan-row strong{min-width:0;color:#18181b;font-size:15px;font-weight:400;text-align:right;overflow-wrap:anywhere}
    .turnover-drawer-body .handover-plan-note{margin:0;padding:9px 12px;border-top:1px solid #e4e4e7;background:#f4f4f5;color:#52525b;font-size:12px;line-height:1.5}
    .turnover-drawer-footer{display:flex;flex-shrink:0;justify-content:flex-end;margin-top:14px;padding-top:14px;border-top:1px solid #e4e4e7}
    .turnover-drawer-footer[hidden]{display:none}
    .turnover-drawer-footer button{display:inline-flex;min-height:36px;align-items:center;justify-content:center;gap:8px;padding:0 13px;border:1px solid #18181b;border-radius:6px;background:#18181b;color:#fff;font:500 14px Inter,'Segoe UI',sans-serif;cursor:pointer}
    .turnover-drawer-footer button:hover{background:#27272a}
    #turnoverArchiveDialog{position:fixed;inset:0;z-index:1500;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.45)}
    #turnoverArchiveDialog.is-open{display:flex}
    .turnover-archive-dialog{width:min(420px,calc(100vw - 32px));padding:22px;border:1px solid #e4e4e7;border-radius:10px;background:#fff;box-shadow:0 12px 32px rgba(0,0,0,.18);font-family:Inter,'Segoe UI',sans-serif}
    .turnover-archive-dialog h2{margin:0;color:#18181b;font-size:18px;font-weight:600}
    .turnover-archive-dialog p{margin:8px 0 0;color:#71717a;font-size:14px;line-height:1.5}
    .turnover-archive-actions{display:flex;justify-content:flex-end;gap:8px;margin:20px -22px -22px;padding:14px 22px;border-top:1px solid #e4e4e7}
    .turnover-archive-actions button{min-height:36px;padding:0 14px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif;cursor:pointer}
    .turnover-archive-actions button:last-child{border-color:#dc2626;background:#dc2626;color:#fff}
    .turnover-archive-actions button:disabled{opacity:.55;cursor:not-allowed}
    .workflow-config{display:none}
    #responseWorkflowModal{display:none;position:fixed;inset:0;z-index:1400;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.45)}
    #responseWorkflowModal.active{display:flex}
    #responseWorkflowModal .response-workflow-dialog{box-sizing:border-box;width:min(460px,calc(100vw - 24px));max-height:calc(100vh - 24px);overflow-y:auto;padding:24px;border:1px solid rgba(15,23,42,.08);border-radius:12px;background:#fff;color:#18181b;box-shadow:0 10px 15px -3px rgba(15,23,42,.1),0 4px 6px -4px rgba(15,23,42,.1);font-family:Inter,'Segoe UI',sans-serif}
    #responseWorkflowModal .response-workflow-header{margin-bottom:16px}
    #responseWorkflowModal .sd-dialog-title{margin:0;color:#18181b;font-size:18px;font-weight:600}
    #responseWorkflowModal .sd-dialog-description{margin:5px 0 0;color:#71717a;font-size:14px;line-height:1.5}
    #responseWorkflowModal .handover-method-options{display:grid;gap:8px;margin:0;padding:0;border:0}
    #responseWorkflowModal .handover-method-options legend{margin-bottom:6px;font-size:14px;font-weight:500}
    #responseWorkflowModal .handover-method-options label{display:flex;gap:10px;padding:11px 12px;border:1px solid #e4e4e7;border-radius:8px;cursor:pointer}
    #responseWorkflowModal .handover-method-options label:has(input:checked){border-color:#18181b;background:#f4f4f5;box-shadow:0 0 0 1px #18181b}
    #responseWorkflowModal .handover-method-options input{margin-top:3px}
    #responseWorkflowModal .handover-method-options span{display:grid;gap:3px}
    #responseWorkflowModal .handover-method-options small{color:#71717a;font-size:12px}
    #responseWorkflowModal .response-workflow-field{display:block;margin-top:12px;color:#27272a;font-size:13px;font-weight:500}
    #responseWorkflowModal .response-workflow-field input,#responseWorkflowModal .response-workflow-field select,#responseWorkflowModal .response-workflow-field textarea{display:block;width:100%;min-height:38px;margin-top:5px;padding:8px;border:1px solid #d4d4d8;border-radius:7px;background:#fff;color:#18181b;font:14px Inter,'Segoe UI',sans-serif}
    #responseWorkflowModal .response-workflow-check{display:flex;gap:8px;align-items:center;margin-top:12px;color:#52525b;font-size:13px}
    #responseWorkflowModal .response-workflow-help{margin:12px 0 0;color:#71717a;font-size:12px;line-height:1.5}
    #responseWorkflowModal .handover-plan-preview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px;padding:10px;border-radius:7px;background:#f4f4f5}
    #responseWorkflowModal .handover-preview-item span,#responseWorkflowModal .handover-preview-item strong{display:block}
    #responseWorkflowModal .handover-preview-item span{color:#71717a;font-size:11px}
    #responseWorkflowModal .handover-preview-item strong{margin-top:3px;font-size:12px}
    #responseWorkflowModal .handover-date-time,#responseWorkflowModal .handover-contact-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    #responseWorkflowModal .sd-dialog-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid #e4e4e7}
    #responseWorkflowModal .sd-btn{min-height:36px;padding:0 14px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif;cursor:pointer}
    #responseWorkflowModal .sd-btn-default{border-color:#18181b;background:#18181b;color:#fff}
    #responseWorkflowModal .sd-btn:disabled{opacity:.55;cursor:not-allowed}
    #responseWorkflowModal .response-workflow-dialog.is-handover{box-sizing:border-box;width:min(460px,calc(100vw - 24px));max-width:min(460px,calc(100vw - 24px));max-height:calc(100vh - 24px);gap:0;overflow-y:auto;padding:24px;border:1px solid rgba(15,23,42,.08);border-radius:12px;background:#fff;color:#18181b;box-shadow:0 10px 15px -3px rgba(15,23,42,.1),0 4px 6px -4px rgba(15,23,42,.1);font-family:Inter,'Segoe UI',sans-serif}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-header{gap:6px;margin-bottom:16px}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-title{font-size:18px;font-weight:600;line-height:1.2;letter-spacing:-.02em;color:#18181b}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-description{font-size:14px;font-weight:400;line-height:1.5;color:#71717a}
    #responseWorkflowModal .response-workflow-dialog.is-handover #responseWorkflowForm{display:flex;flex-direction:column;min-height:0;margin:0}
    #responseWorkflowModal .response-workflow-dialog.is-handover #responseWorkflowFields{display:flex;flex-direction:column;gap:12px}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options{gap:8px;margin:0;padding:0;border:0}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options legend{margin:0 0 2px;color:#18181b;font-size:14px;font-weight:500}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options label{box-sizing:border-box;min-height:58px;align-items:flex-start;gap:10px;margin:0;padding:11px 12px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;color:#18181b;transition:border-color .15s,background .15s,box-shadow .15s}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options label:hover{background:#fafafa}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options label:has(input:checked){border-color:#18181b;background:#f4f4f5;box-shadow:0 0 0 1px #18181b}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options input{width:16px;height:16px;flex:0 0 16px;margin:3px 0 0;accent-color:#18181b}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options span{gap:2px}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options strong{font-size:14px;font-weight:500;line-height:1.4;color:#18181b}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-method-options small{font-size:13px;font-weight:400;line-height:1.45;color:#71717a}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-plan-preview{box-sizing:border-box;display:grid;grid-template-columns:minmax(90px,.35fr) minmax(0,1fr);gap:6px 12px;margin:0;padding:12px;border:1px solid #f4f4f5;border-radius:8px;background:#f4f4f5}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-preview-item{display:flex;min-width:0;flex-direction:column;gap:1px}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-preview-item span{font-size:13px;color:#71717a}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-preview-item strong{min-width:0;overflow-wrap:anywhere;font-size:14px;font-weight:500;line-height:1.4;color:#18181b}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-preview-due{grid-column:1/-1;margin-top:4px;padding-top:7px;border-top:1px solid #e4e4e7}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-date-time,#responseWorkflowModal .response-workflow-dialog.is-handover .handover-contact-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-field{min-width:0;margin:0;color:#18181b;font-size:14px;font-weight:500;line-height:1.4}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-field input{box-sizing:border-box;width:100%;height:36px;min-height:36px;margin-top:6px;padding:7px 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:inherit;font-size:14px;font-weight:400;line-height:1.4;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-field input::placeholder{color:#a1a1aa}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-field input:focus{outline:0;border-color:#18181b;box-shadow:0 0 0 2px rgba(24,24,27,.12)}
    #responseWorkflowModal .response-workflow-dialog.is-handover .handover-pickup-location[hidden]{display:none}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-check{display:flex;align-items:flex-start;gap:8px;margin:0;color:#3f3f46;font-size:14px;font-weight:400;line-height:1.45}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-check input{width:16px;height:16px;flex:0 0 16px;margin:2px 0 0;accent-color:#18181b}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-help{display:flex;align-items:flex-start;gap:8px;margin:0;padding:10px 12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-size:13px;line-height:1.5}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-help span{flex:0 0 auto;color:#1d4ed8}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer{display:flex;justify-content:flex-end;gap:8px;margin:16px -24px -24px;padding:14px 24px 18px;border-top:1px solid #e4e4e7;background:#fff}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer .sd-btn{display:inline-flex;min-width:78px;min-height:36px;align-items:center;justify-content:center;padding:0 14px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:inherit;font-size:14px;font-weight:500;line-height:1;cursor:pointer;transition:background .15s,border-color .15s}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer .sd-btn:hover{background:#f4f4f5}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer #responseWorkflowSubmit{border-color:#18181b;background:#18181b;color:#fff}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer #responseWorkflowSubmit:hover{background:#27272a}
    #responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer #responseWorkflowSubmit:disabled{border-color:#e4e4e7;background:#f4f4f5;color:#a1a1aa;cursor:not-allowed}
    #responseWorkflowModal .response-workflow-dialog.is-handover .response-workflow-required{color:#dc2626}
    @media(max-width:900px){.turnover-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.turnover-main{padding:16px}}
    @media(max-width:600px){.turnover-topbar{height:58px;gap:10px;padding:0 12px}.turnover-topbar-toggle{display:inline-flex}.turnover-breadcrumb{min-width:0;flex:1;overflow:hidden;font-size:13px;white-space:nowrap}.turnover-breadcrumb a{overflow:hidden;text-overflow:ellipsis}.turnover-user{flex:0 0 auto}.turnover-user>span:nth-child(2){display:none}.turnover-main{padding:14px 12px}.turnover-kpis{gap:10px}.turnover-kpi{padding:14px 10px}.turnover-kpi-label{font-size:12px}.turnover-kpi-value{font-size:23px}.turnover-toolbar{align-items:stretch}.turnover-filters,.turnover-controls{width:100%;min-width:0}.turnover-input{flex:1;min-width:0}.turnover-controls{justify-content:space-between}.turnover-tabs{min-width:0;flex:1}.turnover-tab{min-width:0;padding:0 8px}.turnover-button{flex:0 0 auto}.turnover-pagination-inner{justify-content:flex-end;gap:10px}.turnover-rows span{display:none}.turnover-drawer-panel{width:100%;padding:20px}#responseWorkflowModal .response-workflow-dialog.is-handover{padding:18px}#responseWorkflowModal .response-workflow-dialog.is-handover .sd-dialog-footer{margin:16px -18px -18px;padding:12px 18px 14px}}
  </style>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<?php $barangaySidebarStylesheetInHead = true; include __DIR__ . '/../../includes/barangay-sidebar.php'; ?>
<div class="turnover-app">
  <header class="turnover-topbar">
    <button class="turnover-topbar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars" aria-hidden="true"></i></button>
    <div class="turnover-breadcrumb"><a href="<?php echo $escape($appBaseUrl . '/dashboard-barangay.php'); ?>">Barangay Portal</a><span aria-hidden="true">›</span><span>Turnover</span></div>
    <div class="turnover-user">
      <span class="turnover-avatar"><?php if ($avatar !== ''): ?><img src="<?php echo $escape($avatar); ?>" alt=""><?php else: ?><i class="fas fa-user" aria-hidden="true"></i><?php endif; ?></span>
      <span><?php echo $escape('Barangay ' . $barangayName); ?><small>Barangay Officer</small></span>
    </div>
  </header>
  <main class="turnover-main">
    <section class="turnover-kpis" aria-label="Turnover summary">
      <article class="turnover-kpi"><div class="turnover-kpi-label">Awaiting Handover</div><div class="turnover-kpi-value"><?php echo $pendingCount; ?></div><div class="turnover-kpi-help">Not yet received by DCVS</div></article>
      <article class="turnover-kpi"><div class="turnover-kpi-label">Plan Needed</div><div class="turnover-kpi-value"><?php echo $planNeededCount; ?></div><div class="turnover-kpi-help">Set your handover plan</div></article>
      <article class="turnover-kpi"><div class="turnover-kpi-label">Overdue</div><div class="turnover-kpi-value"><?php echo $overdueCount; ?></div><div class="turnover-kpi-help">Past the due time</div></article>
      <article class="turnover-kpi"><div class="turnover-kpi-label">Turned Over</div><div class="turnover-kpi-value"><?php echo $turnedOverCount; ?></div><div class="turnover-kpi-help">Received by DCVS</div></article>
    </section>
    <?php if ($loadError !== ''): ?><div class="turnover-error" role="alert"><?php echo $escape($loadError); ?></div><?php endif; ?>
    <?php if (!$workflowEnabled && $loadError === ''): ?><div class="turnover-error" role="status">Barangay turnover workflow is currently unavailable.</div><?php endif; ?>
    <div class="turnover-toolbar portal-mobile-toolbar">
      <div class="turnover-filters portal-toolbar-controls">
        <div class="portal-toolbar-search-row">
        <input class="turnover-input" id="turnoverSearch" type="search" placeholder="Search by report, animal, or method..." aria-label="Search turnover records">
        </div>
        <div class="portal-toolbar-filter-grid">
        <select class="turnover-select portal-toolbar-last-filter" id="turnoverMethodFilter" aria-label="Filter by handover method">
          <option value="">All methods</option>
          <option value="barangay_delivery">Barangay delivers</option>
          <option value="dcvs_pickup">DCVS pickup</option>
        </select>
        </div>
      </div>
      <div class="turnover-controls portal-toolbar-controls">
        <div class="turnover-tabs" role="tablist" aria-label="Turnover record status">
          <button class="turnover-tab" id="tabPending" type="button" role="tab" aria-selected="true" data-tab="pending">For Turnover <span class="turnover-tab-count"><?php echo $pendingCount; ?></span></button>
          <button class="turnover-tab" id="tabReceived" type="button" role="tab" aria-selected="false" data-tab="received">Turned Over <span class="turnover-tab-count"><?php echo $turnedOverCount; ?></span></button>
        </div>
        <button class="turnover-button portal-toolbar-primary" id="exportTurnover" type="button"><i class="fas fa-download" aria-hidden="true"></i> Export CSV</button>
      </div>
    </div>
    <div class="turnover-table-wrap">
      <table class="turnover-table">
        <thead id="turnoverTableHead"></thead>
        <tbody id="turnoverTableBody"></tbody>
      </table>
    </div>
    <div class="turnover-pagination" id="turnoverPagination"></div>
  </main>
</div>
<div id="turnoverDetailDrawer" aria-hidden="true">
  <section class="turnover-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="turnoverDrawerTitle">
    <header class="turnover-drawer-header">
      <div>
        <h2 class="turnover-drawer-title" id="turnoverDrawerTitle">Turnover Details</h2>
        <p class="turnover-drawer-reference">Review animal turnover details and handover information.</p>
      </div>
      <button class="turnover-drawer-close" type="button" aria-label="Close turnover details">
        <i class="fas fa-times" aria-hidden="true"></i>
      </button>
    </header>
    <div class="turnover-drawer-body" id="turnoverDrawerBody"></div>
    <footer class="turnover-drawer-footer" id="turnoverDrawerFooter" hidden>
      <button type="button" id="turnoverDrawerPlanButton"></button>
    </footer>
  </section>
</div>
<div id="turnoverArchiveDialog" aria-hidden="true">
  <section class="turnover-archive-dialog" role="alertdialog" aria-modal="true" aria-labelledby="turnoverArchiveTitle" aria-describedby="turnoverArchiveDescription">
    <h2 id="turnoverArchiveTitle">Archive turned-over report?</h2>
    <p id="turnoverArchiveDescription"></p>
    <div class="turnover-archive-actions">
      <button type="button" id="turnoverArchiveCancel">Cancel</button>
      <button type="button" id="turnoverArchiveConfirm">Archive</button>
    </div>
  </section>
</div>
<script>
window.REPORTS_DATA = <?php echo json_encode($workflowReports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.DCVS_APP_BASE_URL = <?php echo json_encode($appBaseUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.BRGY_NAME = <?php echo json_encode($barangayName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.DCVS_RESPONSE_ROLE = 'barangay';
window.DCVS_RESPONSE_CSRF = <?php echo json_encode(csrfToken()); ?>;
window.DCVS_BARANGAY_RESPONSE_ENABLED = <?php echo $workflowEnabled ? 'true' : 'false'; ?>;
window.TURNOVER_DATA = <?php echo json_encode($turnoverRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="<?php echo $escape($appBaseUrl . '/assets/js/response-workflow.js'); ?>?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/response-workflow.js'); ?>"></script>
<script>
(function() {
  'use strict';
  var rows = Array.isArray(window.TURNOVER_DATA) ? window.TURNOVER_DATA : [];
  var searchInput = document.getElementById('turnoverSearch');
  var methodFilter = document.getElementById('turnoverMethodFilter');
  var tableHead = document.getElementById('turnoverTableHead');
  var tableBody = document.getElementById('turnoverTableBody');
  var pagination = document.getElementById('turnoverPagination');
  var currentTab = 'pending';
  var currentPage = 1;
  var pageSize = 10;
  var drawer = document.getElementById('turnoverDetailDrawer');
  var drawerBody = document.getElementById('turnoverDrawerBody');
  var drawerCloseButton = drawer.querySelector('.turnover-drawer-close');
  var drawerFooter = document.getElementById('turnoverDrawerFooter');
  var drawerPlanButton = document.getElementById('turnoverDrawerPlanButton');
  var archiveDialog = document.getElementById('turnoverArchiveDialog');
  var archiveDescription = document.getElementById('turnoverArchiveDescription');
  var archiveConfirmButton = document.getElementById('turnoverArchiveConfirm');
  var archiveCancelButton = document.getElementById('turnoverArchiveCancel');
  var archiveReport = null;
  var drawerReportId = null;
  var lastDrawerTrigger = null;

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character];
    });
  }
  function formatDate(value) {
    if (!value) return 'Not set';
    var date = new Date(String(value).replace(' ', 'T') + (String(value).indexOf('+') === -1 ? '+08:00' : ''));
    if (isNaN(date.getTime())) return 'Not set';
    return new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila', month: 'short', day: 'numeric',
      year: 'numeric', hour: 'numeric', minute: '2-digit'
    }).format(date);
  }
  function isOverdue(row) {
    if (row.turnover_status !== 'pending' || !row.due_at) return false;
    var due = new Date(String(row.due_at).replace(' ', 'T') + (String(row.due_at).indexOf('+') === -1 ? '+08:00' : ''));
    return !isNaN(due.getTime()) && due.getTime() < Date.now();
  }
  function methodLabel(row) {
    if (row.handover_method === 'dcvs_pickup') return 'DCVS pickup';
    if (row.handover_method === 'barangay_delivery') return 'Barangay delivers';
    return 'Not set';
  }
  function animalLabel(row) {
    var animal = [row.animal_type || 'Animal', row.animal_count ? ', ' + row.animal_count : ''].join('');
    return animal;
  }
  function mediaUrl(value) {
    var url = String(value || '').trim();
    var baseUrl = String(window.DCVS_APP_BASE_URL || '');
    if (!url || /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(url)) return url;
    if (baseUrl && (url === baseUrl || url.indexOf(baseUrl + '/') === 0)) return url;
    return baseUrl + '/' + url.replace(/^(?:\/|\.{1,2}\/)+/, '');
  }
  function initials(value) {
    return String(value || '?').trim().split(/\s+/).slice(0, 2).map(function(part) {
      return part.charAt(0);
    }).join('').toUpperCase() || '?';
  }
  function openTurnoverDetails(row, trigger) {
    var receiptConfirmed = row.turnover_status === 'receipt_confirmed';
    var report = {
      _db_status: row.report_status,
      animalDisposition: row.animal_disposition,
      type: row.animal_type,
      turnoverStatus: row.turnover_status,
      turnoverDueAt: row.due_at,
      turnoverDueAtDisplay: formatDate(row.due_at),
      turnoverMethod: row.handover_method,
      turnoverPlannedAt: row.planned_at ? formatDate(row.planned_at) : '',
      turnoverReceivedAt: row.received_at,
      turnoverReceivedAtDisplay: row.received_at ? formatDate(row.received_at) : '',
      turnoverReceiver: row.receiver_name,
      turnoverHandlerName: row.handler_name,
      turnoverContactNumber: row.contact_number,
      turnoverPickupLocation: row.pickup_location,
      turnoverSafeTransport: row.safe_transport,
      holdingLocation: row.holding_location,
      brgy: window.BRGY_NAME
    };
    var avatar = mediaUrl(row.reporter_avatar);
    var avatarFallback = initials(row.reporter_name);
    drawerReportId = Number(row.report_id);
    var needsPlanAction = !receiptConfirmed && window.DCVS_BARANGAY_RESPONSE_ENABLED;
    drawerFooter.hidden = !needsPlanAction;
    drawerPlanButton.textContent = row.planned_at && row.handover_method ? 'Edit Handover Plan' : 'Set Handover Plan';
    drawerBody.innerHTML = '<div class="turnover-drawer-summary">' +
      '<span class="turnover-drawer-avatar">' + (avatar
        ? '<img src="' + escapeHtml(avatar) + '" alt=""><span hidden>' + escapeHtml(avatarFallback) + '</span>'
        : escapeHtml(avatarFallback)) + '</span>' +
      '<span class="turnover-drawer-copy"><span class="turnover-drawer-label">Reported by</span>' +
      '<span class="turnover-drawer-name">' + escapeHtml(row.reporter_name || '—') + '</span></span>' +
      '<span class="turnover-drawer-status"><span class="' + (receiptConfirmed ? 'is-turned-over' : 'is-pending') + '">' +
      (receiptConfirmed ? 'Turned over' : 'Awaiting handover') + '</span></span></div>';
    lastDrawerTrigger = trigger || document.activeElement;
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    try {
      if (typeof window.responseWorkflowHandoverHtml !== 'function') {
        throw new Error('The shared turnover details renderer is unavailable.');
      }
      var handoverHtml = window.responseWorkflowHandoverHtml(report);
      drawerBody.insertAdjacentHTML('beforeend', handoverHtml);
      if (receiptConfirmed) {
        var handoverCard = drawerBody.querySelector('.handover-plan-card');
        if (handoverCard) {
          handoverCard.classList.add('is-received');
          var turnoverStatus = handoverCard.querySelector('.handover-plan-status');
          if (turnoverStatus) turnoverStatus.remove();
        }
      }
    } catch (error) {
      console.error('Could not render turnover details for report ' + row.report_id + '.', error);
      drawerBody.insertAdjacentHTML('beforeend',
        '<p class="turnover-error">Could not load turnover details. Please refresh and try again.</p>');
    }
    var avatarImage = drawerBody.querySelector('.turnover-drawer-avatar img');
    if (avatarImage) {
      avatarImage.addEventListener('error', function() {
        var fallback = this.nextElementSibling;
        if (fallback) fallback.hidden = false;
        this.remove();
      }, { once: true });
    }
    drawerCloseButton.focus();
  }
  function closeTurnoverDetails() {
    if (!drawer.classList.contains('is-open')) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastDrawerTrigger && typeof lastDrawerTrigger.focus === 'function') lastDrawerTrigger.focus();
  }
  function toggleDropdown(button) {
    var menu = button._actionMenu || button.parentElement.querySelector('.action-menu');
    if (!menu) return;
    var isOpen = menu.classList.contains('open');
    closeDropdowns();
    if (!isOpen) {
      var rect = button.getBoundingClientRect();
      menu._dropdownParent = menu.parentElement;
      menu._dropdownTrigger = button;
      button._actionMenu = menu;
      document.body.appendChild(menu);
      menu.style.position = 'fixed';
      menu.style.top = (rect.bottom + 4) + 'px';
      menu.style.left = Math.max(8, rect.right - 210) + 'px';
      menu.classList.add('open');
      button.setAttribute('aria-expanded', 'true');
    }
  }
  function closeDropdowns() {
    document.querySelectorAll('.turnover-action-menu.open').forEach(function(menu) {
      menu.classList.remove('open');
      var button = menu._dropdownTrigger;
      if (button) button.setAttribute('aria-expanded', 'false');
      var parent = menu._dropdownParent;
      if (parent && parent.isConnected) parent.appendChild(menu);
      else menu.remove();
      menu._dropdownParent = null;
      menu._dropdownTrigger = null;
    });
  }
  function startHandoverPlan(reportId) {
    closeDropdowns();
    if (!window.DCVS_BARANGAY_RESPONSE_ENABLED || typeof window.responseWorkflowAction !== 'function') {
      window.alert('Handover planning is currently unavailable. Refresh this page and try again.');
      return;
    }
    window.responseWorkflowAction(Number(reportId), 'set_handover_plan');
  }
  function openArchiveDialog(row) {
    archiveReport = row;
    archiveDescription.textContent = 'Archive ' + (row.reference_id || ('Report #' + row.report_id)) +
      '? This turned-over report will be removed from the active turnover list.';
    archiveConfirmButton.disabled = false;
    archiveCancelButton.disabled = false;
    archiveConfirmButton.textContent = 'Archive';
    archiveDialog.classList.add('is-open');
    archiveDialog.setAttribute('aria-hidden', 'false');
    archiveCancelButton.focus();
  }
  function closeArchiveDialog() {
    if (archiveConfirmButton.disabled) return;
    archiveDialog.classList.remove('is-open');
    archiveDialog.setAttribute('aria-hidden', 'true');
    archiveReport = null;
  }
  function confirmArchive() {
    if (!archiveReport || archiveReport.turnover_status !== 'receipt_confirmed') return;
    var reportId = Number(archiveReport.report_id);
    archiveConfirmButton.disabled = true;
    archiveCancelButton.disabled = true;
    archiveConfirmButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Archiving...';
    fetch('validate-report.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ report_id: reportId, action: 'archive' })
    }).then(function(response) {
      return response.json().then(function(payload) {
        if (!response.ok || !payload.success) {
          throw new Error(payload.message || 'The report could not be archived.');
        }
        return payload;
      });
    }).then(function() {
      window.location.reload();
    }).catch(function(error) {
      console.error('Could not archive turned-over report ' + reportId + '.', error);
      archiveDescription.textContent = error.message || 'The report could not be archived. Please try again.';
      archiveConfirmButton.disabled = false;
      archiveCancelButton.disabled = false;
      archiveConfirmButton.textContent = 'Try Again';
    });
  }
  function visibleRows() {
    var term = (searchInput.value || '').trim().toLowerCase();
    var method = methodFilter.value;
    return rows.filter(function(row) {
      var received = row.turnover_status === 'receipt_confirmed';
      if ((currentTab === 'received') !== received) return false;
      if (method && row.handover_method !== method) return false;
      if (!term) return true;
      return [row.reference_id, row.animal_type, row.animal_count, row.holding_location,
        row.handover_method, row.handler_name, row.pickup_location, row.receiver_name]
        .join(' ').toLowerCase().indexOf(term) !== -1;
    });
  }
  function render() {
    var isReceived = currentTab === 'received';
    tableHead.innerHTML = isReceived
      ? '<tr><th>Report</th><th>Animal</th><th>Number of Animals</th><th>Barangay</th><th>Handed over</th><th>Date turned over</th><th>Received by</th><th>Holding location</th><th>Status</th><th aria-label="Actions"></th></tr>'
      : '<tr><th>Report</th><th>Animal</th><th>Number of Animals</th><th>Holding location</th><th>Method</th><th>Planned</th><th>Due by</th><th>Status</th><th aria-label="Actions"></th></tr>';
    var filtered = visibleRows();
    var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    currentPage = Math.min(Math.max(currentPage, 1), totalPages);
    var start = (currentPage - 1) * pageSize;
    var pageRows = filtered.slice(start, start + pageSize);
    if (!pageRows.length) {
      tableBody.innerHTML = '<tr><td colspan="' + (isReceived ? 10 : 9) + '" class="turnover-empty"><i class="fas fa-exchange-alt" aria-hidden="true"></i>' +
        (filtered.length ? 'No turnover records on this page.' : (isReceived ? 'No animals have been received by DCVS yet.' : 'No animals are awaiting handover.')) +
        '</td></tr>';
    } else {
      tableBody.innerHTML = pageRows.map(function(row) {
        var reportCell = '<button type="button" class="turnover-report-link" data-turnover-detail="' + Number(row.report_id) + '">' +
          escapeHtml(row.reference_id || ('Report #' + row.report_id)) + '</button>';
        var archiveAction = '<button type="button" class="action-menu-item danger" data-archive-report="' + Number(row.report_id) +
          '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</button>';
        var planAction = '<button type="button" class="action-menu-item" data-plan-report="' + Number(row.report_id) +
          '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
          (row.planned_at && row.handover_method ? 'Edit Handover Plan' : 'Set Handover Plan') + '</button>';
        var menuAction = '<td class="sd-td-action"><div class="action-dropdown"><button type="button" class="sd-row-actions" aria-label="More options" aria-haspopup="true" aria-expanded="false">' +
          '<span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>' +
          '<div class="action-menu turnover-action-menu">' + (row.turnover_status === 'receipt_confirmed' ? archiveAction : planAction) + '</div></div></td>';
        var animalCell = escapeHtml(row.animal_type || 'Animal');
        var animalCountCell = escapeHtml(row.animal_count || '—');
        var holding = escapeHtml(row.holding_location || 'Not recorded');
        if (isReceived) {
          return '<tr><td>' + reportCell + '</td><td>' + animalCell + '</td><td>' + animalCountCell + '</td><td>' + escapeHtml(window.BRGY_NAME || '') +
            '</td><td>' + escapeHtml(methodLabel(row)) + '</td><td>' + escapeHtml(formatDate(row.received_at)) +
            '</td><td>' + escapeHtml(row.receiver_name || 'DCVS staff') + '</td><td>' + holding +
            '</td><td><span class="turnover-status is-received">Turned over</span></td>' + menuAction + '</tr>';
        }
        var planNeeded = !row.planned_at || !row.handover_method;
        var overdue = isOverdue(row);
        var statusClass = overdue ? 'is-overdue' : (planNeeded ? 'is-needed' : 'is-scheduled');
        var statusText = overdue ? 'Overdue' : (planNeeded ? 'Plan needed' : 'Plan set');
        var planned = row.planned_at ? formatDate(row.planned_at) : 'Not set';
        return '<tr><td>' + reportCell + '</td><td>' + animalCell + '</td><td>' + animalCountCell + '</td><td>' + holding +
          '</td><td>' + escapeHtml(methodLabel(row)) + '</td><td>' + escapeHtml(planned) +
          '</td><td>' + escapeHtml(formatDate(row.due_at)) + '</td><td><span class="turnover-status ' + statusClass + '">' + statusText +
          '</span></td>' + menuAction + '</tr>';
      }).join('');
    }
    pagination.innerHTML = '<div class="turnover-pagination-inner"><label class="turnover-rows"><span>Rows per page</span><select id="turnoverPageSize"><option value="10"' +
      (pageSize === 10 ? ' selected' : '') + '>10</option><option value="25"' + (pageSize === 25 ? ' selected' : '') +
      '>25</option><option value="50"' + (pageSize === 50 ? ' selected' : '') + '>50</option></select></label>' +
      '<span class="turnover-page-label">Page ' + currentPage + ' of ' + totalPages + '</span>' +
      '<div class="turnover-page-buttons"><button type="button" data-page="first" aria-label="First page"' + (currentPage === 1 ? ' disabled' : '') + '><i class="fas fa-angle-double-left"></i></button>' +
      '<button type="button" data-page="previous" aria-label="Previous page"' + (currentPage === 1 ? ' disabled' : '') + '><i class="fas fa-angle-left"></i></button>' +
      '<button type="button" data-page="next" aria-label="Next page"' + (currentPage === totalPages ? ' disabled' : '') + '><i class="fas fa-angle-right"></i></button>' +
      '<button type="button" data-page="last" aria-label="Last page"' + (currentPage === totalPages ? ' disabled' : '') + '><i class="fas fa-angle-double-right"></i></button></div></div>';
    pagination.querySelector('#turnoverPageSize').addEventListener('change', function() {
      pageSize = Number(this.value) || 10;
      currentPage = 1;
      render();
    });
  }
  document.querySelectorAll('.turnover-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
      currentTab = tab.getAttribute('data-tab');
      document.querySelectorAll('.turnover-tab').forEach(function(other) {
        other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
      });
      currentPage = 1;
      render();
    });
  });
  searchInput.addEventListener('input', function() { currentPage = 1; render(); });
  methodFilter.addEventListener('change', function() { currentPage = 1; render(); });
  tableBody.addEventListener('click', function(event) {
    var target = event.target instanceof Element ? event.target : event.target.parentElement;
    var detailButton = target && target.closest('[data-turnover-detail]');
    if (detailButton) {
      var reportRow = rows.find(function(row) {
        return Number(row.report_id) === Number(detailButton.getAttribute('data-turnover-detail'));
      });
      if (reportRow) openTurnoverDetails(reportRow, detailButton);
      return;
    }
    var actionDropdown = target && target.closest('.action-dropdown');
    if (actionDropdown) {
      var menuButton = target.closest('.sd-row-actions');
      var planButton = target.closest('[data-plan-report]');
      var archiveButton = target.closest('[data-archive-report]');
      if (menuButton) {
        toggleDropdown(menuButton);
      } else if (planButton) {
        startHandoverPlan(planButton.getAttribute('data-plan-report'));
      } else if (archiveButton) {
        closeDropdowns();
        var toArchive = rows.find(function(row) {
          return Number(row.report_id) === Number(archiveButton.getAttribute('data-archive-report'));
        });
        if (toArchive) openArchiveDialog(toArchive);
      }
      return;
    }
    if (target && target.closest('button')) return;
    if (target && target.closest('tr')) {
      var rowTrigger = target.closest('tr').querySelector('[data-turnover-detail]');
      if (!rowTrigger) return;
      var rowReportId = Number(rowTrigger.getAttribute('data-turnover-detail'));
      var detailRow = rows.find(function(row) { return Number(row.report_id) === rowReportId; });
      if (detailRow) openTurnoverDetails(detailRow, target.closest('tr'));
    }
  });
  drawerPlanButton.addEventListener('click', function() {
    if (drawerReportId !== null) startHandoverPlan(drawerReportId);
  });
  drawerCloseButton.addEventListener('click', closeTurnoverDetails);
  drawer.addEventListener('click', function(event) {
    if (event.target === drawer) closeTurnoverDetails();
  });
  archiveCancelButton.addEventListener('click', closeArchiveDialog);
  archiveConfirmButton.addEventListener('click', confirmArchive);
  archiveDialog.addEventListener('click', function(event) {
    if (event.target === archiveDialog) closeArchiveDialog();
  });
  document.addEventListener('click', function(event) {
    var target = event.target instanceof Element ? event.target : event.target.parentElement;
    var planButton = target && target.closest('.turnover-action-menu [data-plan-report]');
    var archiveButton = target && target.closest('.turnover-action-menu [data-archive-report]');
    if (planButton) {
      startHandoverPlan(planButton.getAttribute('data-plan-report'));
    } else if (archiveButton) {
      closeDropdowns();
      var toArchive = rows.find(function(row) {
        return Number(row.report_id) === Number(archiveButton.getAttribute('data-archive-report'));
      });
      if (toArchive) openArchiveDialog(toArchive);
    } else if (!target || (!target.closest('.action-dropdown') && !target.closest('.turnover-action-menu'))) {
      closeDropdowns();
    }
  });
  window.addEventListener('resize', closeDropdowns);
  window.addEventListener('scroll', closeDropdowns, true);
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      closeDropdowns();
      closeArchiveDialog();
      closeTurnoverDetails();
    }
  });
  pagination.addEventListener('click', function(event) {
    var button = event.target.closest('[data-page]');
    if (!button || button.disabled) return;
    var pages = Math.max(1, Math.ceil(visibleRows().length / pageSize));
    var action = button.getAttribute('data-page');
    if (action === 'first') currentPage = 1;
    else if (action === 'previous') currentPage--;
    else if (action === 'next') currentPage++;
    else if (action === 'last') currentPage = pages;
    render();
  });
  document.getElementById('exportTurnover').addEventListener('click', function() {
    var headers = currentTab === 'received'
      ? ['Report', 'Animal', 'Number of Animals', 'Barangay', 'Handed over', 'Date turned over', 'Received by', 'Holding location', 'Status']
      : ['Report', 'Animal', 'Number of Animals', 'Holding location', 'Method', 'Planned', 'Due by', 'Status'];
    var data = visibleRows().map(function(row) {
      if (currentTab === 'received') return [
        row.reference_id || ('Report #' + row.report_id), row.animal_type || 'Animal', row.animal_count || '—',
        <?php echo json_encode($barangayName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        methodLabel(row), formatDate(row.received_at), row.receiver_name || 'DCVS staff',
        row.holding_location || 'Not recorded', 'Turned over'
      ];
      return [
        row.reference_id || ('Report #' + row.report_id), row.animal_type || 'Animal', row.animal_count || '—',
        row.holding_location || 'Not recorded',
        methodLabel(row), formatDate(row.planned_at), formatDate(row.due_at),
        isOverdue(row) ? 'Overdue' : ((!row.planned_at || !row.handover_method) ? 'Plan needed' : 'Plan set')
      ];
    });
    var csv = [headers].concat(data).map(function(line) {
      return line.map(function(value) {
        var text = String(value == null ? '' : value);
        if (/^[=+\-@]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
      }).join(',');
    }).join('\r\n');
    var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = currentTab === 'received' ? 'barangay-turned-over-animals.csv' : 'barangay-turnover-pending.csv';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  });
  render();
})();
</script>
</body>
</html>
