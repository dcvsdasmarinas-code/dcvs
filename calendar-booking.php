<?php
// Resident portal appointment calendar and detail panel.

$cbAppointments = [];
$cbPrograms = [];
if (isset($conn) && !$conn->connect_error && isset($_SESSION['user_id'])) {
    $cbStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.urgency_level,
        COALESCE(a.scheduled_date, a.preferred_date) AS preferred_date,
        COALESCE(a.scheduled_time, a.preferred_time) AS preferred_time,
        a.status, a.notes, a.created_at, COALESCE(NULLIF(a.pet_name,''), p.pet_name, 'N/A') AS pet_name,
        p.photo_url, rr.requested_date AS reschedule_requested_date,
        rr.requested_time AS reschedule_requested_time, rr.status AS reschedule_request_status
        FROM appointments a
        LEFT JOIN pets p ON p.pet_id = a.pet_id
        LEFT JOIN appointment_reschedule_requests rr
          ON rr.appointment_id = a.appointment_id AND rr.status IN ('pending','offered')
          AND a.status IN ('approved','scheduled','rescheduled')
        WHERE a.user_id = ? AND a.archived_at IS NULL
        ORDER BY COALESCE(a.scheduled_date, a.preferred_date), COALESCE(a.scheduled_time, a.preferred_time)");
    if ($cbStmt) {
        $cbStmt->bind_param("i", $_SESSION['user_id']);
        $cbStmt->execute();
        $cbRes = $cbStmt->get_result();
        while ($row = $cbRes->fetch_assoc()) { $cbAppointments[] = $row; }
        $cbStmt->close();
    }
    if ($cbAppointments) {
        $cbDates = array_column($cbAppointments, 'preferred_date');
        $cbFirstDate = min($cbDates);
        $cbLastDate = max($cbDates);
        $cbProgramStmt = $conn->prepare("SELECT p.program_name, p.program_type, p.description, p.start_date, p.end_date, p.program_start_time, p.program_end_time, p.operating_time, p.venue, p.location_mode, GROUP_CONCAT(DISTINCT b.barangay_name ORDER BY b.barangay_name SEPARATOR ', ') AS barangay_names FROM programs p LEFT JOIN program_barangays pb ON pb.program_id = p.program_id LEFT JOIN barangays b ON b.barangay_id = pb.barangay_id WHERE p.status IN ('scheduled','live') AND p.end_date >= ? AND p.start_date <= ? GROUP BY p.program_id ORDER BY p.start_date, p.program_name");
        if (!$cbProgramStmt) {
            throw new RuntimeException('Could not prepare resident calendar program details: ' . $conn->error);
        }
        $cbProgramStmt->bind_param('ss', $cbFirstDate, $cbLastDate);
        if (!$cbProgramStmt->execute()) {
            $cbProgramError = $cbProgramStmt->error;
            $cbProgramStmt->close();
            throw new RuntimeException('Could not load resident calendar program details: ' . $cbProgramError);
        }
        $cbProgramResult = $cbProgramStmt->get_result();
        while ($row = $cbProgramResult->fetch_assoc()) { $cbPrograms[] = $row; }
        $cbProgramStmt->close();
    }
}
?>
<style>
  .cb-app{
    --cb-primary:#5B4FE9; --cb-primary-soft:#EFEDFD; --cb-ink:#1B1B25; --cb-muted:#8A8A9E;
    --cb-line:#ECECF2; --cb-bg:#F5F5F8; --cb-card:#FFFFFF; --cb-radius:14px;
  }
  .cb-app *{margin:0;padding:0;box-sizing:border-box}
  .cb-app{
    font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
    background:var(--cb-card);
    color:var(--cb-ink);
    -webkit-font-smoothing:antialiased;
    display:flex;min-height:100vh;font-size:14px;
  }
  .cb-app .cb-calendar-pane{flex:1;padding:0 0 40px;border-right:1px solid var(--cb-line);min-width:0}
  .cb-app .cb-topbar{display:flex;align-items:center;justify-content:space-between;padding:18px 28px;gap:16px;}
  .cb-app .cb-search-wrap{position:relative;}
  .cb-app .cb-search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#71717a;pointer-events:none;}
  .cb-app .cb-search{height:36px;padding:0 12px 0 36px;width:280px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s,box-shadow .15s;}
  .cb-app .cb-search:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;background:#fff;}
  .cb-app .cb-search::placeholder{color:#a1a1aa;}
  .cb-app .cb-topbar-right{display:flex;align-items:center;gap:14px}
  .cb-app .cb-icon-btn{width:36px;height:36px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;display:grid;place-items:center;cursor:pointer;color:#71717a;transition:background .15s;box-shadow:0 1px 2px rgba(0,0,0,.05);}
  .cb-app .cb-icon-btn:hover{background:#F7F7FA}
  .cb-app .cb-user-chip{display:flex;align-items:center;gap:10px}
  .cb-app .cb-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#D8D8E4}
  .cb-app .cb-name{font-size:13px;font-weight:600}
  .cb-app .cb-mail{font-size:12px;font-weight:400;color:#71717a}
  .cb-app .cb-cal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 28px;flex-wrap:wrap;gap:12px;}
  .cb-app .cb-month-nav{display:flex;align-items:center;gap:12px}
  .cb-app .cb-month-nav h1{font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;letter-spacing:-.025em;line-height:1;width:180px;}
  .cb-app .cb-nav-arrow{width:36px;height:36px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;cursor:pointer;color:#18181b;font-size:14px;display:grid;place-items:center;box-shadow:0 1px 2px rgba(0,0,0,.05);}
  .cb-app .cb-nav-arrow:hover{background:#F7F7FA}
  .cb-app .cb-cal-actions{display:flex;align-items:center;gap:10px}
  .cb-app .cb-btn-primary{background:#18181b;color:#fafafa;border:none;border-radius:6px;height:36px;padding:0 16px;font-size:14px;font-weight:500;line-height:1;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 1px 2px 0 rgba(0,0,0,.05);transition:background .15s;font-family:'Inter','Segoe UI',sans-serif;}
  .cb-app .cb-btn-primary:hover{background:#27272a}
  .cb-app .cb-filter-wrap{position:relative;}
  .cb-app .cb-filter-dropdown{display:none;position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid #e4e4e7;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:50;width:360px;padding:16px;}
  .cb-app .cb-filter-dropdown.show{display:block;}
  .cb-app .cb-filter-cols{display:flex;gap:20px;margin-bottom:14px;}
  .cb-app .cb-filter-cols .cb-filter-section{flex:1;margin-bottom:0;}
  .cb-app .cb-filter-section{margin-bottom:14px;}
  .cb-app .cb-filter-label{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#18181b;margin:0 0 6px;}
  .cb-app .cb-filter-opt{display:flex;align-items:center;gap:8px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;padding:3px 0;cursor:pointer;}
  .cb-app .cb-filter-opt input[type="radio"]{margin:0;accent-color:#5B4FE9;}
  .cb-app .cb-filter-actions{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e4e4e7;padding-top:12px;}
  .cb-app .cb-filter-reset{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#71717a;background:transparent;border:1px solid #e4e4e7;border-radius:6px;padding:6px 14px;cursor:pointer;}
  .cb-app .cb-filter-reset:hover{background:#F4F4F8;}
  .cb-app .cb-filter-apply{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#fafafa;background:#18181b;border:none;border-radius:6px;padding:6px 14px;cursor:pointer;}
  .cb-app .cb-filter-apply:hover{background:#27272a;}
  .cb-app .cb-grid-wrap{padding:0 28px;overflow-x:auto}
  .cb-app .cb-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));border-left:1px solid var(--cb-line);border-top:1px solid var(--cb-line);min-width:880px;}
  .cb-app .cb-dow{padding:10px 12px;font-size:13px;font-weight:600;color:#18181b;border-right:1px solid var(--cb-line);border-bottom:1px solid var(--cb-line);background:#FBFBFD;}
  .cb-app .cb-day{min-height:110px;padding:8px;border-right:1px solid var(--cb-line);border-bottom:1px solid var(--cb-line);position:relative;cursor:pointer;transition:background .12s;}
  .cb-app .cb-day:hover{background:#FAFAFE}
  .cb-app .cb-day.cb-dim{color:#C3C3D2;background:#FCFCFE}
  .cb-app .cb-day.cb-selected{outline:2px solid #18181b;outline-offset:-2px;}
  .cb-app .cb-day.cb-today{background:#fefce8;}
  .cb-app .cb-day-num{font-size:14px;font-weight:500;margin-bottom:6px}
  .cb-app .cb-day.cb-dim .cb-day-num{font-weight:400}
  .cb-app .cb-chip{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:400;line-height:1.25;border-radius:0;padding:3px 2px;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:transparent;color:#18181b;border-bottom:1px solid #e4e4e7;}
  .cb-app .cb-chip .cb-dot{width:10px;min-width:10px;align-self:auto;border-radius:0;flex:none;color:#15803d;font-size:12px;line-height:1;text-align:center}
  .cb-app .cb-chip.cb-blue .cb-dot,.cb-app .cb-chip.cb-green .cb-dot{color:#15803d}
  .cb-app .cb-chip.cb-purple .cb-dot{color:#7c3aed}
  .cb-app .cb-chip.cb-orange .cb-dot{color:#d97706}
  .cb-app .cb-chip.cb-pink .cb-dot{color:#dc2626}
  .cb-app .cb-chip.cb-reschedule-requested{border:1px dashed #71717a;border-radius:4px;padding:2px 4px;color:#52525b;background:#fafafa}
  .cb-app .cb-chip.cb-reschedule-requested .cb-dot{color:#71717a}
  .cb-app .cb-chip.cb-pending .cb-dot{color:#d97706}
  .cb-app .cb-chip.cb-cancelled > span:last-child,.cb-app .cb-chip.cb-rejected > span:last-child{text-decoration:line-through;color:#71717a}
  .cb-app .cb-mobile-more{display:block;text-align:left;color:#71717a;font-size:11px;font-weight:400;line-height:1.4;padding:2px 2px 0}

  /* ============ RIGHT PANEL ============ */
  .cb-app .cb-event-pane{width:400px;flex:none;display:flex;flex-direction:column;background:#fff;border-left:1px solid #e4e4e7;}
  .cb-app .cb-panel-body{padding:28px 26px;overflow-y:auto;flex:1}
  .cb-app .cb-section{margin-bottom:18px}
  .cb-app .cb-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:8px;}
  .cb-app .cb-title-input{flex:1;min-width:0;border:none;outline:none;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;letter-spacing:-.025em;line-height:1.2;color:#18181b;padding:0;background:transparent;white-space:normal;}
  .cb-app .cb-title-input::placeholder{color:#C3C3D2}
  .cb-app .cb-link-row{display:flex;align-items:center;justify-content:space-between;margin-top:14px}
  .cb-app .cb-link-text{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;}
  .cb-app .cb-time-row{display:flex;align-items:center;justify-content:space-between;gap:22px;margin-top:8px}
  .cb-app .cb-time-block{display:flex;flex-direction:row;align-items:center;gap:8px;min-width:0}
  .cb-app .cb-time-block .cb-t{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;letter-spacing:0;color:#18181b}
  .cb-app .cb-time-block .cb-d{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;margin:0}
  .cb-app .cb-at-label{font-size:14px;color:#71717a}
  .cb-app .cb-date-marker{flex:0 0 8px;width:8px;height:8px;border:2px solid #15803d;border-radius:50%}
  .cb-app .cb-date-marker.is-confirmed{background:#15803d}
  .cb-app .cb-date-marker.is-completed,.cb-app .cb-date-marker.is-cancelled{width:auto;height:auto;flex:0 0 auto;border:0;font-size:14px;font-weight:600;line-height:1}
  .cb-app .cb-date-marker.is-completed{color:#15803d}
  .cb-app .cb-date-marker.is-cancelled{color:#71717a}
  .cb-app .cb-date-marker.is-pending{border-color:#d97706}
  .cb-app .cb-pet-avatar{width:60px;height:60px;border-radius:50%;object-fit:cover;border:1px solid #e4e4e7;background:#e8f0ea;flex-shrink:0;}
  .cb-app .cb-pet-initial{display:grid;place-items:center;color:#15803d;font-family:'Inter','Segoe UI',sans-serif;font-size:23px;font-weight:500}
  .cb-app .cb-people{margin-top:26px;border-top:1px solid #e4e4e7;padding-top:18px}
  .cb-app .cb-person{display:flex;align-items:center;gap:12px;padding:9px 0}
  .cb-app .cb-person .cb-avatar{width:38px;height:38px}
  .cb-app .cb-p-name{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b}
  .cb-app .cb-p-mail{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a}
  .cb-app .cb-role{margin-left:auto;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;color:#18181b}
  .cb-app .cb-role.cb-master{color:#18181b;font-weight:600}
  .cb-app .cb-help{margin-top:10px;padding:0 0 6px;}
  .cb-app .cb-help-txt{font-style:normal;font-size:13px;color:#71717a;line-height:1.6}
  .cb-app .cb-contact{color:#18181b;font-weight:600;text-decoration:underline;font-style:normal;}
  .cb-app .cb-contact:hover{text-decoration:underline;}
  .cb-app .cb-reminders{display:none}
  .cb-app .cb-reminders-head{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#18181b;margin:0 0 8px;}
  .cb-app .cb-reminders-list{margin:0;padding-left:18px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;line-height:1.6;}
  .cb-app .cb-reminders-list li{margin-bottom:4px;}
  .cb-app .cb-reminders-list li:last-child{margin-bottom:0;}
  .cb-app .cb-appt-details{border-top:1px solid #e4e4e7;padding-top:14px;margin-bottom:0;}
  .cb-app .cb-day-appt-picker{display:none;margin:0 0 18px;padding:10px 12px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;box-shadow:none;font-family:'Inter',sans-serif;color:#18181b;}
  .cb-app .cb-day-appt-picker-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;}
  .cb-app .cb-day-appt-count{font-family:'Inter',sans-serif;font-size:12px;font-weight:500;color:#18181b;}
  .cb-app .cb-day-appt-nav{display:flex;gap:4px;}
  .cb-app .cb-day-appt-nav button{width:28px;height:28px;display:grid;place-items:center;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter',sans-serif;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:background-color .15s,border-color .15s,color .15s;}
  .cb-app .cb-day-appt-nav button:hover:not(:disabled){background:#f4f4f5;}
  .cb-app .cb-day-appt-nav button:focus-visible,.cb-app .cb-day-appt-time:focus-visible{outline:none;box-shadow:0 0 0 2px #fff,0 0 0 4px #18181b;}
  .cb-app .cb-day-appt-nav button:disabled{color:#a1a1aa;cursor:not-allowed;opacity:.55;}
  .cb-app .cb-day-appt-times{display:flex;flex-wrap:wrap;gap:6px;}
  .cb-app .cb-day-appt-time{min-height:24px;padding:3px 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;line-height:1.2;cursor:pointer;transition:background-color .15s,border-color .15s,color .15s;}
  .cb-app .cb-day-appt-time:hover{background:#f4f4f5;}
  .cb-app .cb-day-appt-time[aria-pressed="true"]{background:#18181b;border-color:#18181b;color:#fafafa;}
  .cb-app .cb-detail-head{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#18181b;margin:0 0 8px;}
  .cb-app .cb-detail-row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:4px 0;}
  .cb-app .cb-detail-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;}
  .cb-app .cb-detail-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;color:#18181b;text-align:right;overflow-wrap:anywhere;}
  .cb-app .cb-note-head{display:flex;align-items:center;justify-content:space-between;margin-top:16px}
  .cb-app .cb-note-head span{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#18181b}
  .cb-app .cb-note-box{margin-top:8px;border-left:2px solid #e4e4e7;border-radius:0;padding:2px 0 2px 12px;font-size:14px;line-height:1.5;color:#18181b;}
  .cb-app .cb-note-box p{margin:0;}
  .cb-app .cb-note-pending,.cb-app .cb-note-scheduled{background:transparent;border:0;border-left:2px solid #e4e4e7;color:#18181b;}
  .cb-app .cb-st{display:inline-flex;align-items:center;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;padding:3px 10px;line-height:1.4;white-space:nowrap;border:1px solid;}
  .cb-app .cb-notes-section{padding-bottom:14px;border-bottom:1px solid #e4e4e7;margin-bottom:0}
  .cb-app .cb-program-card{margin-top:14px;}
  .cb-app .cb-program-hours{font-family:'Inter','Segoe UI',sans-serif;font-size:12px;color:#18181b;}
  .cb-app .cb-program-name{margin-top:2px;font-family:'Inter','Segoe UI',sans-serif;font-size:16px;font-weight:600;color:#18181b;}
  .cb-app .cb-program-location{margin-top:2px;font-size:13px;color:#71717a;}
  .cb-app .cb-program-description{margin-top:8px;border-left:2px solid #e4e4e7;padding-left:12px;font-size:14px;line-height:1.5;color:#18181b;}
  .cb-app .cb-appointment-link{display:inline-block;margin-top:14px;color:#0e8131;font-size:14px;font-weight:500;text-decoration:underline;text-underline-offset:2px;}
  .cb-app .cb-appointment-link:hover{color:#086526;}
  .cb-app .cb-st[hidden]{display:none}
  .cb-app .cb-empty-state{display:flex;align-items:center;justify-content:center;padding:80px 30px;text-align:center;}
  .cb-app .cb-empty-state p{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;}
  .cb-app .cb-event-backdrop{display:none;}
  .cb-app .cb-panel-close{display:none;}
  @media (max-width:1020px){
    .cb-app{flex-direction:column}
    .cb-app .cb-event-pane{width:100%;border-top:1px solid var(--cb-line)}
  }
  @media (max-width:768px){
    .cb-app{display:block;width:100%;min-height:0;overflow:visible}
    .cb-app .cb-calendar-pane{width:100%;padding-bottom:20px;border-right:0}
    .cb-app .cb-topbar{display:none}
    .cb-app .cb-cal-header{align-items:stretch;flex-direction:column;padding:12px 8px;gap:10px}
    .cb-app .cb-month-nav{justify-content:center;gap:8px}
    .cb-app .cb-month-nav h1{width:auto;min-width:0;flex:1;text-align:center;font-size:16px}
    .cb-app .cb-nav-arrow{width:32px;height:32px;flex:none}
    .cb-app .cb-cal-actions{justify-content:center;gap:8px}
    .cb-app .cb-btn-primary{height:34px;padding:0 10px;font-size:12px;gap:5px}
    .cb-app .cb-icon-btn{width:34px;height:34px;flex:none}
    .cb-app .cb-filter-dropdown{width:min(360px,calc(100vw - 24px));padding:12px}
    .cb-app .cb-filter-cols{gap:12px}
    .cb-app .cb-filter-opt{font-size:12px}
    .cb-app .cb-grid-wrap{padding:0 8px;overflow:visible}
    .cb-app .cb-cal-grid{width:100%;min-width:0;grid-template-columns:repeat(7,minmax(0,1fr))}
    .cb-app .cb-dow{padding:7px 1px;text-align:center;font-size:10px}
    .cb-app .cb-day{min-width:0;min-height:68px;height:68px;padding:4px 2px;overflow:hidden}
    .cb-app .cb-day-num{margin-bottom:4px;text-align:center;font-size:12px}
    .cb-app .cb-chip{height:auto;min-height:0;padding:1px 0;margin:0;border:0;border-bottom:1px solid #e4e4e7;border-radius:0;background:transparent;justify-content:flex-start;gap:3px;font-size:9px;font-weight:400;line-height:1.15}
    .cb-app .cb-chip > span:last-child{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis}
    .cb-app .cb-chip .cb-dot{width:8px;min-width:8px;min-height:0;align-self:auto;background:none!important;color:#15803d;font-size:9px;line-height:1}
    .cb-app .cb-chip.cb-pending .cb-dot,.cb-app .cb-chip.cb-no-show .cb-dot,.cb-app .cb-chip.cb-rejected .cb-dot{color:#71717a}
    .cb-app .cb-mobile-hidden{display:none}
    .cb-app .cb-mobile-more{font-size:9px;padding-top:2px}
    .cb-app .cb-event-backdrop{position:fixed;inset:0;z-index:1098;background:rgba(15,23,32,.5)}
    .cb-app.cb-dialog-open .cb-event-backdrop{display:block}
    .cb-app .cb-event-pane{position:fixed;inset:0;z-index:1099;width:100%;max-width:none;height:100vh;height:100dvh;max-height:none;border:0;border-radius:0;box-shadow:none;transform:translateY(12px);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,transform .18s ease,visibility .18s ease}
    .cb-app .cb-event-pane.open{transform:translateY(0);opacity:1;visibility:visible;pointer-events:auto}
    .cb-app .cb-panel-body{width:100%;height:100%;min-height:0;padding:20px;overflow-y:auto}
    .cb-app .cb-panel-close{position:sticky;top:0;z-index:1;width:32px;height:32px;margin:0 0 12px auto;border:0;border-radius:6px;background:#f4f4f5;color:#52525b;display:flex;align-items:center;justify-content:center;font-size:20px;line-height:1;cursor:pointer}
    .cb-app .cb-panel-close:hover{background:#e4e4e7;color:#18181b}
    .cb-app .cb-empty-state{padding:24px 12px}
    .cb-app .cb-time-block .cb-t{font-size:13px}
    body.cb-booking-dialog-open{overflow:hidden}
  }
  @media (max-width:380px){
    .cb-app .cb-cal-header{padding-right:6px;padding-left:6px}
    .cb-app .cb-grid-wrap{padding-right:6px;padding-left:6px}
    .cb-app .cb-day{min-height:62px;height:62px;padding:3px 1px}
    .cb-app .cb-dow{font-size:9px}
  }
</style>

<div class="cb-app">

  <!-- ================= CALENDAR ================= -->
  <section class="cb-calendar-pane">
    <div class="cb-topbar">
      <div class="cb-topbar-right" style="margin-left:auto;">
      </div>
    </div>

    <div class="cb-cal-header">
      <div class="cb-month-nav">
        <h1 id="cbMonthLabel">Month</h1>
        <button class="cb-nav-arrow" id="cbPrevMonth" type="button" aria-label="Previous month">&#8249;</button>
        <button class="cb-nav-arrow" id="cbNextMonth" type="button" aria-label="Next month">&#8250;</button>
      </div>
      <div class="cb-cal-actions">
        <button class="cb-btn-primary" type="button" onclick="if(typeof switchSection==='function')switchSection('book-appt')">&#65291; Book Appointment</button>
        <div class="cb-filter-wrap">
          <button class="cb-icon-btn" id="cbFilterBtn" type="button" title="Filter">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
          </button>
          <div class="cb-filter-dropdown" id="cbFilterDropdown">
            <div class="cb-filter-cols">
              <div class="cb-filter-section">
                <p class="cb-filter-label">Status</p>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFStatus" value="all" checked /> All</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFStatus" value="pending" /> Pending</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFStatus" value="scheduled" /> Scheduled</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFStatus" value="approved" /> Confirmed</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFStatus" value="completed" /> Completed</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFStatus" value="cancelled" /> Cancelled</label>
              </div>
              <div class="cb-filter-section">
                <p class="cb-filter-label">Service</p>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFService" value="all" checked /> All Services</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFService" value="kapon" /> Kapon / Neutering</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFService" value="adoption" /> Adoption</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFService" value="adoption_consultation" /> Adoption Consultation</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFService" value="vaccination" /> Vaccination</label>
                <label class="cb-filter-opt"><input type="checkbox" name="cbFService" value="consultation" /> Veterinary Consultation</label>
              </div>
            </div>
            <div class="cb-filter-section">
              <p class="cb-filter-label">Date</p>
              <label class="cb-filter-opt"><input type="checkbox" name="cbFDate" value="all" checked /> All Dates</label>
              <label class="cb-filter-opt"><input type="checkbox" name="cbFDate" value="today" /> Today</label>
              <label class="cb-filter-opt"><input type="checkbox" name="cbFDate" value="week" /> This Week</label>
              <label class="cb-filter-opt"><input type="checkbox" name="cbFDate" value="month" /> This Month</label>
            </div>
            <div class="cb-filter-actions">
              <button class="cb-filter-reset" id="cbFilterReset" type="button">Reset</button>
              <button class="cb-filter-apply" id="cbFilterApply" type="button">Apply</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="cb-grid-wrap">
      <div class="cb-cal-grid" id="cbCalGrid"></div>
    </div>
  </section>

  <!-- ================= BOOK APPOINTMENT ================= -->
  <div class="cb-event-backdrop" id="cbEventBackdrop"></div>
  <aside class="cb-event-pane" id="cbEventPane" role="dialog" aria-label="Appointment details" aria-modal="false">
    <div class="cb-panel-body" id="cbPanelBody">
      <button class="cb-panel-close" id="cbPanelClose" type="button" aria-label="Close appointment details">&times;</button>
      <div class="cb-empty-state" id="cbEmptyState">
        <p>No appointment schedule in this date.</p>
      </div>
      <div id="cbApptDetails" style="display:none;">
        <div class="cb-day-appt-picker" id="cbDayApptPicker" aria-label="Appointments on selected date">
          <div class="cb-day-appt-picker-head">
            <span class="cb-day-appt-count" id="cbDayApptCount"></span>
            <div class="cb-day-appt-nav">
              <button type="button" id="cbDayApptPrev" aria-label="Previous appointment" title="Previous appointment">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
              </button>
              <button type="button" id="cbDayApptNext" aria-label="Next appointment" title="Next appointment">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
              </button>
            </div>
          </div>
          <div class="cb-day-appt-times" id="cbDayApptTimes"></div>
        </div>
        <div class="cb-section">
          <div class="cb-title-row">
            <h2 class="cb-title-input" id="cbTitleInput"></h2>
            <img class="cb-pet-avatar" id="cbPetAvatar" src="" alt="Pet" style="display:none;" />
            <span class="cb-pet-avatar cb-pet-initial" id="cbPetInitial" aria-hidden="true" style="display:none;"></span>
          </div>
          <div class="cb-time-row">
            <div class="cb-time-block">
              <span class="cb-date-marker" id="cbDateMarker" aria-hidden="true"></span>
              <div class="cb-d" id="cbDateLabel">Today</div>
              <span class="cb-at-label">at</span>
              <div class="cb-t" id="cbStartTime">12:00 AM</div>
            </div>
          </div>
        </div>

        <div class="cb-appt-details" id="cbApptMeta">
          <p class="cb-detail-head">Appointment details</p>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Pet</span>
            <span class="cb-detail-value" id="cbDetailPet">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Service</span>
            <span class="cb-detail-value" id="cbDetailService">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Priority</span>
            <span class="cb-detail-value" id="cbDetailPriority">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Location</span>
            <span class="cb-detail-value">DCVS Veterinary Clinic</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Requested on</span>
            <span class="cb-detail-value" id="cbDetailRequested">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Reference</span>
            <span class="cb-detail-value" id="cbDetailReference">—</span>
          </div>
        </div>

        <div class="cb-section cb-notes-section">
          <div class="cb-note-head">
            <span>Notes</span>
          </div>
          <div class="cb-note-box" id="cbNoteBox">
            <p id="cbNoteMsg">Your appointment request is currently being reviewed by DCVS.</p>
          </div>
        </div>

        <div class="cb-program-card" id="cbProgramCard" style="display:none;">
          <div class="cb-program-hours" id="cbProgramHours"></div>
          <div class="cb-program-name" id="cbProgramName"></div>
          <div class="cb-program-location" id="cbProgramLocation"></div>
          <div class="cb-program-description" id="cbProgramDescription" style="display:none;"></div>
        </div>
        <div class="cb-people" id="cbPeopleList">
          <div class="cb-help">
            <p class="cb-help-txt">Questions? Call <a class="cb-contact" href="tel:+63464321234">(046) 432-1234</a> or email <a class="cb-contact" href="mailto:admin@dcvs.gov.ph">admin@dcvs.gov.ph</a>.</p>
          </div>
        </div>
      </div>
    </div>
  </aside>
</div>

<script>
(function(){
  var grid = document.getElementById('cbCalGrid');
  var monthLabel = document.getElementById('cbMonthLabel');
  var app = document.querySelector('.cb-app');
  var eventPane = document.getElementById('cbEventPane');
  var eventBackdrop = document.getElementById('cbEventBackdrop');
  var panelClose = document.getElementById('cbPanelClose');
  var mobileDialogQuery = window.matchMedia('(max-width: 768px)');
  var current = new Date(); current.setDate(1);
  var selectedKey = '';
  var selectedApptIndex = 0;
  var bodyOverflowBeforeDialog = '';

  function isMobileDialog(){
    return mobileDialogQuery.matches;
  }

  function openApptDialog(){
    if (!isMobileDialog()) return;
    bodyOverflowBeforeDialog = document.body.style.overflow;
    app.classList.add('cb-dialog-open');
    eventPane.classList.add('open');
    eventPane.setAttribute('aria-modal', 'true');
    document.body.classList.add('cb-booking-dialog-open');
    panelClose.focus();
  }

  function closeApptDialog(){
    app.classList.remove('cb-dialog-open');
    eventPane.classList.remove('open');
    eventPane.setAttribute('aria-modal', 'false');
    document.body.classList.remove('cb-booking-dialog-open');
    document.body.style.overflow = bodyOverflowBeforeDialog;
  }

  panelClose.addEventListener('click', closeApptDialog);
  eventBackdrop.addEventListener('click', closeApptDialog);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && eventPane.classList.contains('open')) closeApptDialog();
  });
  mobileDialogQuery.addEventListener('change', function(e){
    if (!e.matches) closeApptDialog();
  });

  /* ---- Real appointment data from PHP ---- */
  var rawAppts = <?php echo json_encode($cbAppointments, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var rawPrograms = <?php echo json_encode($cbPrograms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  var statusColor = {
    pending:'cb-orange', scheduled:'cb-green', approved:'cb-blue',
    rescheduled:'cb-purple', in_progress:'cb-blue', completed:'cb-blue',
    cancelled:'cb-pink', rejected:'cb-pink', no_show:'cb-orange'
  };

  var apptEvents = {};
  var requestedEvents = {};
  rawAppts.forEach(function(a){
    var dt = a.preferred_date ? new Date(a.preferred_date.replace(/-/g,'/')) : null;
    if (!dt) return;
    var key = dt.getFullYear() + '-' + dt.getMonth() + '-' + dt.getDate();
    if (!apptEvents[key]) apptEvents[key] = [];
    var chipClass = statusColor[a.status] || 'cb-orange';
    var timeStr = a.preferred_time ? fmtTime24(a.preferred_time) : '';
    var label = serviceLabel(a.appointment_type);
    apptEvents[key].push({
      chipClass: chipClass, time: timeStr, label: label,
      id: a.appointment_id, type: a.appointment_type, pet: a.pet_name || 'N/A',
      status: a.status, date: a.preferred_date, timeRaw: a.preferred_time,
      notes: a.notes || '', ref: a.reference_id || '', createdAt: a.created_at || '',
      photoUrl: a.photo_url || '', priority: a.urgency_level || 'routine'
    });
    if (a.reschedule_request_status && a.reschedule_requested_date) {
      var requestedDate = new Date(a.reschedule_requested_date.replace(/-/g,'/'));
      var requestedKey = requestedDate.getFullYear() + '-' + requestedDate.getMonth() + '-' + requestedDate.getDate();
      if (!requestedEvents[requestedKey]) requestedEvents[requestedKey] = [];
      requestedEvents[requestedKey].push({
        date: a.reschedule_requested_date,
        time: a.reschedule_requested_time ? fmtTime24(a.reschedule_requested_time) : '',
        status: a.reschedule_request_status
      });
    }
  });

  function serviceLabel(type){
    var labels = {kapon:'Kapon',vaccination:'Vaccination',checkup:'Check-up',deworming:'Deworming',adoption:'Adoption'};
    var key = String(type || '').toLowerCase();
    return labels[key] || (key ? key.replace(/[_-]/g,' ').replace(/\b\w/g,function(letter){return letter.toUpperCase();}) : 'Appointment');
  }

  function statusGlyph(status){
    if (status === 'completed' || status === 'approved' || status === 'scheduled' || status === 'rescheduled' || status === 'in_progress') return '✓';
    if (status === 'cancelled' || status === 'rejected' || status === 'no_show') return '—';
    return '·';
  }

  function getVisibleAppts(key){
    return (apptEvents[key] || []).filter(function(ev){
      return matchStatus(ev.status) && matchService(ev.type) && matchDate(ev.date);
    });
  }

  function fmtTime24(t){
    if (!t) return '';
    var p = t.split(':');
    var h = parseInt(p[0],10), m = p[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
  }

  var dows = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function render(){
    grid.innerHTML = '';
    dows.forEach(function(d){
      var el = document.createElement('div');
      el.className = 'cb-dow'; el.textContent = d;
      grid.appendChild(el);
    });
    monthLabel.textContent = monthNames[current.getMonth()] + ' ' + current.getFullYear();

    var first = new Date(current.getFullYear(), current.getMonth(), 1);
    var startOffset = (first.getDay() + 6) % 7;
    var start = new Date(first); start.setDate(first.getDate() - startOffset);

    for (var i = 0; i < 42; i++){
      var day = new Date(start); day.setDate(start.getDate() + i);
      var key = day.getFullYear() + '-' + day.getMonth() + '-' + day.getDate();
      var cell = document.createElement('div');
      cell.className = 'cb-day' + (day.getMonth() !== current.getMonth() ? ' cb-dim' : '');
      var todayCheck = new Date(); todayCheck.setHours(0,0,0,0);
      if (day.getTime() === todayCheck.getTime()) cell.classList.add('cb-today');
      if (key === selectedKey) cell.classList.add('cb-selected');

      var num = document.createElement('div');
      num.className = 'cb-day-num'; num.textContent = day.getDate();
      cell.appendChild(num);

      var visibleAppts = getVisibleAppts(key);
      visibleAppts.slice(0,2).forEach(function(ev){
        var chip = document.createElement('div');
        chip.className = 'cb-chip ' + ev.chipClass + ' cb-' + String(ev.status || 'pending').replace(/_/g,'-');
        chip.title = ev.label + (ev.status ? ' — ' + ev.status.replace(/_/g,' ') : '');
        chip.setAttribute('aria-label', chip.title);
        var marker = document.createElement('span');
        marker.className = 'cb-dot';
        marker.setAttribute('aria-hidden','true');
        marker.textContent = statusGlyph(ev.status);
        var label = document.createElement('span');
        label.textContent = ev.label;
        chip.appendChild(marker);
        chip.appendChild(label);
        cell.appendChild(chip);
      });
      (requestedEvents[key] || []).forEach(function(request) {
        var chip = document.createElement('div');
        chip.className = 'cb-chip cb-reschedule-requested';
        chip.title = 'Not reserved yet' + (request.time ? ' · ' + request.time : '');
        chip.setAttribute('aria-label', 'Requested time. Not reserved yet' + (request.time ? ', ' + request.time : ''));
        var marker = document.createElement('span');
        marker.className = 'cb-dot';
        marker.setAttribute('aria-hidden','true');
        marker.textContent = '↻';
        var label = document.createElement('span');
        label.textContent = 'Requested';
        chip.appendChild(marker);
        chip.appendChild(label);
        cell.appendChild(chip);
      });
      if (visibleAppts.length > 2) {
        var more = document.createElement('div');
        more.className = 'cb-mobile-more';
        more.textContent = '+' + (visibleAppts.length - 2);
        cell.appendChild(more);
      }

      var hasAppts = visibleAppts.length > 0;
      if (!hasAppts) cell.style.cursor = 'default';

      cell.addEventListener('click', function(k, dt, has){
        return function(){
          if (!has) return;
          selectedKey = k;
          selectedApptIndex = 0;
          var prev = grid.querySelector('.cb-day.cb-selected');
          if (prev) prev.classList.remove('cb-selected');
          this.classList.add('cb-selected');
          showDayAppt(k, dt);
        };
      }(key, new Date(day), hasAppts));

      grid.appendChild(cell);
    }
    if (selectedKey) showDayAppt(selectedKey, parseCalendarKey(selectedKey), selectedApptIndex);
  }

  function parseCalendarKey(key){
    var p = key.split('-');
    return new Date(Number(p[0]), Number(p[1]), Number(p[2]));
  }

  document.getElementById('cbPrevMonth').addEventListener('click', function(){
    current.setMonth(current.getMonth() - 1); render();
  });
  document.getElementById('cbNextMonth').addEventListener('click', function(){
    current.setMonth(current.getMonth() + 1); render();
  });

  /* ---------- Filter ---------- */
  var filterBtn = document.getElementById('cbFilterBtn');
  var filterDropdown = document.getElementById('cbFilterDropdown');
  var filterStatuses = ['all'];
  var filterServices = ['all'];
  var filterDates = ['all'];

  filterBtn.addEventListener('click', function(e){
    e.stopPropagation();
    filterDropdown.classList.toggle('show');
  });

  document.addEventListener('click', function(){
    filterDropdown.classList.remove('show');
  });

  filterDropdown.addEventListener('click', function(e){
    e.stopPropagation();
  });

  /* "All" checkbox logic: checking All unchecks others, checking any other unchecks All */
  function setupCheckAll(name){
    document.querySelectorAll('input[name="'+name+'"]').forEach(function(cb){
      cb.addEventListener('change', function(){
        if (this.value === 'all' && this.checked){
          document.querySelectorAll('input[name="'+name+'"]').forEach(function(r){ r.checked = r.value === 'all'; });
        } else if (this.value !== 'all' && this.checked){
          var allCb = document.querySelector('input[name="'+name+'"][value="all"]');
          if (allCb) allCb.checked = false;
        }
        /* if nothing checked, re-check All */
        var anyChecked = document.querySelectorAll('input[name="'+name+'"]:checked');
        if (anyChecked.length === 0){
          var allCb2 = document.querySelector('input[name="'+name+'"][value="all"]');
          if (allCb2) allCb2.checked = true;
        }
      });
    });
  }
  setupCheckAll('cbFStatus');
  setupCheckAll('cbFService');
  setupCheckAll('cbFDate');

  function getCheckedVals(name){
    var arr = [];
    document.querySelectorAll('input[name="'+name+'"]:checked').forEach(function(cb){ arr.push(cb.value); });
    return arr;
  }

  function matchStatus(s){
    if (filterStatuses.indexOf('all') !== -1) return true;
    return filterStatuses.indexOf(s) !== -1;
  }

  function matchService(t){
    if (filterServices.indexOf('all') !== -1) return true;
    return filterServices.indexOf(t.toLowerCase()) !== -1;
  }

  function matchDate(dateStr){
    if (filterDates.indexOf('all') !== -1) return true;
    var d = new Date(dateStr.replace(/-/g,'/'));
    var today = new Date(); today.setHours(0,0,0,0);
    var matched = false;
    filterDates.forEach(function(fd){
      if (fd === 'today' && d.getTime() === today.getTime()) matched = true;
      if (fd === 'week'){
        var ws = new Date(today); ws.setDate(today.getDate() - today.getDay() + 1);
        var we = new Date(ws); we.setDate(ws.getDate() + 6);
        if (d >= ws && d <= we) matched = true;
      }
      if (fd === 'month' && d.getMonth() === today.getMonth() && d.getFullYear() === today.getFullYear()) matched = true;
    });
    return matched;
  }

  document.getElementById('cbFilterApply').addEventListener('click', function(){
    filterStatuses = getCheckedVals('cbFStatus');
    filterServices = getCheckedVals('cbFService');
    filterDates = getCheckedVals('cbFDate');
    filterDropdown.classList.remove('show');
    render();
  });

  document.getElementById('cbFilterReset').addEventListener('click', function(){
    document.querySelectorAll('.cb-filter-dropdown input[type="checkbox"]').forEach(function(cb){
      cb.checked = cb.value === 'all';
    });
    filterStatuses = ['all']; filterServices = ['all']; filterDates = ['all'];
    filterDropdown.classList.remove('show');
    render();
  });

  /* ---------- Panel state ---------- */
  var titleInput   = document.getElementById('cbTitleInput');
  var startTime    = document.getElementById('cbStartTime');
  var dateLabel    = document.getElementById('cbDateLabel');
  var dateMarker   = document.getElementById('cbDateMarker');
  var noteBox      = document.getElementById('cbNoteBox');
  var noteMsg      = document.getElementById('cbNoteMsg');
  var detailReq    = document.getElementById('cbDetailRequested');
  var detailPet    = document.getElementById('cbDetailPet');
  var detailService = document.getElementById('cbDetailService');
  var detailPriority = document.getElementById('cbDetailPriority');
  var detailReference = document.getElementById('cbDetailReference');
  var emptyState   = document.getElementById('cbEmptyState');
  var apptDetails  = document.getElementById('cbApptDetails');
  var petAvatar    = document.getElementById('cbPetAvatar');
  var petInitial   = document.getElementById('cbPetInitial');
  var programCard  = document.getElementById('cbProgramCard');
  var programHours = document.getElementById('cbProgramHours');
  var programName  = document.getElementById('cbProgramName');
  var programLocation = document.getElementById('cbProgramLocation');
  var programDescription = document.getElementById('cbProgramDescription');
  var dayApptPicker = document.getElementById('cbDayApptPicker');
  var dayApptCount = document.getElementById('cbDayApptCount');
  var dayApptTimes = document.getElementById('cbDayApptTimes');
  var dayApptPrev = document.getElementById('cbDayApptPrev');
  var dayApptNext = document.getElementById('cbDayApptNext');

  var noteMessages = {
    pending:    'Your appointment request is currently being reviewed by DCVS.',
    scheduled:  'Your appointment has been scheduled by DCVS Veterinary Services.',
    approved:   'Your appointment has been approved by DCVS Veterinary Services.',
    rescheduled:'Your appointment has been rescheduled by DCVS Veterinary Services.',
    in_progress:'Your appointment is currently in progress.',
    completed:  'Your appointment has been completed.',
    cancelled:  'Your appointment has been cancelled.',
    rejected:   'Your appointment request has been rejected.',
    no_show:    'You did not attend this appointment.'
  };

  function showDayAppt(key, dt, index){
    var appts = getVisibleAppts(key);
    if (!appts || appts.length === 0){
      emptyState.style.display = '';
      apptDetails.style.display = 'none';
      dayApptPicker.style.display = 'none';
      petAvatar.style.display = 'none';
      petInitial.style.display = 'none';
      petAvatar.src = '';
      programCard.style.display = 'none';
      closeApptDialog();
      return;
    }
    selectedKey = key;
    selectedApptIndex = Math.max(0, Math.min(Number(index) || 0, appts.length - 1));
    var appt = appts[selectedApptIndex];
    if (appts.length > 1) {
      dayApptPicker.style.display = 'block';
      dayApptCount.textContent = (selectedApptIndex + 1) + ' of ' + appts.length + ' appointments';
      dayApptPrev.disabled = selectedApptIndex === 0;
      dayApptNext.disabled = selectedApptIndex === appts.length - 1;
      dayApptTimes.innerHTML = '';
      appts.forEach(function(dayAppt, apptIndex) {
        var timeButton = document.createElement('button');
        timeButton.type = 'button';
        timeButton.className = 'cb-day-appt-time';
        timeButton.textContent = dayAppt.time || 'Time not set';
        timeButton.setAttribute('aria-pressed', apptIndex === selectedApptIndex ? 'true' : 'false');
        timeButton.addEventListener('click', function() { showDayAppt(key, dt, apptIndex); });
        dayApptTimes.appendChild(timeButton);
      });
    } else {
      dayApptPicker.style.display = 'none';
    }
    var isPending = appt.status === 'pending';
    var service = serviceLabel(appt.type);
    var statusHeadings = {
      pending:'is under review', scheduled:'is confirmed', approved:'is confirmed',
      rescheduled:'was rescheduled', in_progress:'is in progress', completed:'was completed',
      cancelled:'was cancelled', rejected:'was declined', no_show:'was marked as a no-show'
    };
    var normalizedStatus = String(appt.status || 'pending').toLowerCase().replace(/[\s-]/g,'_');
    titleInput.textContent = 'Your ' + service + ' appointment for ' + appt.pet + ' ' + (statusHeadings[normalizedStatus] || 'is ' + normalizedStatus.replace(/_/g,' ')) + '.';
    startTime.textContent = appt.time || '12:00 AM';
    dateLabel.textContent = dt.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
    dateMarker.className = 'cb-date-marker';
    if (normalizedStatus === 'completed') {
      dateMarker.classList.add('is-completed');
      dateMarker.textContent = '✓';
    } else if (normalizedStatus === 'cancelled' || normalizedStatus === 'rejected' || normalizedStatus === 'no_show') {
      dateMarker.classList.add('is-cancelled');
      dateMarker.textContent = '—';
    } else {
      dateMarker.textContent = '';
      if (normalizedStatus === 'scheduled' || normalizedStatus === 'approved' || normalizedStatus === 'rescheduled' || normalizedStatus === 'in_progress') dateMarker.classList.add('is-confirmed');
      else if (normalizedStatus === 'pending') dateMarker.classList.add('is-pending');
    }
    var initial = String(appt.pet || '').trim().charAt(0).toUpperCase() || '?';
    petInitial.textContent = initial;
    if (appt.photoUrl){ petAvatar.src = appt.photoUrl; petAvatar.alt = appt.pet || 'Pet'; petAvatar.style.display = ''; petInitial.style.display = 'none'; }
    else { petAvatar.style.display = 'none'; petAvatar.src = ''; petInitial.style.display = 'grid'; }
    /* note box */
    noteMsg.textContent = appt.notes || noteMessages[normalizedStatus] || noteMessages.pending;
    noteBox.className = 'cb-note-box cb-note-' + (isPending ? 'pending' : 'scheduled');
    /* details */
    if (appt.createdAt) {
      var cd = new Date(appt.createdAt.replace(/-/g,'/'));
      detailReq.textContent = cd.toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'});
    } else {
      detailReq.textContent = '—';
    }
    detailPet.textContent = appt.pet || '—';
    detailService.textContent = service;
    detailPriority.textContent = String(appt.priority || 'routine').replace(/\b\w/g,function(letter){return letter.toUpperCase();});
    detailReference.textContent = appt.ref || '—';
    var matchingProgram = rawPrograms.filter(function(program){
      return program.start_date <= appt.date && program.end_date >= appt.date &&
        String(program.program_type || '').toLowerCase() === String(appt.type || '').toLowerCase();
    })[0];
    if (matchingProgram) {
      programHours.textContent = matchingProgram.operating_time ||
        [matchingProgram.program_start_time, matchingProgram.program_end_time].filter(Boolean).map(fmtTime24).join(' to ');
      programName.textContent = matchingProgram.program_name || 'Program';
      programLocation.textContent = matchingProgram.location_mode === 'multiple'
        ? (matchingProgram.barangay_names || matchingProgram.venue || 'Multiple locations')
        : (matchingProgram.venue || matchingProgram.barangay_names || 'Location not specified');
      programDescription.textContent = matchingProgram.description || '';
      programDescription.style.display = matchingProgram.description ? '' : 'none';
      programCard.style.display = '';
    } else {
      programCard.style.display = 'none';
    }
    emptyState.style.display = 'none';
    apptDetails.style.display = '';
    openApptDialog();
  }

  dayApptPrev.addEventListener('click', function(){
    if (selectedApptIndex > 0) showDayAppt(selectedKey, parseCalendarKey(selectedKey), selectedApptIndex - 1);
  });
  dayApptNext.addEventListener('click', function(){
    var appts = getVisibleAppts(selectedKey);
    if (selectedApptIndex < appts.length - 1) showDayAppt(selectedKey, parseCalendarKey(selectedKey), selectedApptIndex + 1);
  });

  render();
})();
</script>
