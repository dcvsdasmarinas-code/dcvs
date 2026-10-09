<?php
// Calendar Booking interface — resident portal > Appointments > Calendar Booking
// Original layout preserved. Text content replaced with DCVS veterinary context.

$cbAppointments = [];
$cbStaffAvatar = '';
if (isset($conn) && !$conn->connect_error && isset($_SESSION['user_id'])) {
    $cbStmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.notes, a.created_at, COALESCE(NULLIF(a.pet_name,''), p.pet_name, 'N/A') AS pet_name, p.photo_url FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NULL ORDER BY a.preferred_date ASC, a.preferred_time ASC");
    if ($cbStmt) {
        $cbStmt->bind_param("i", $_SESSION['user_id']);
        $cbStmt->execute();
        $cbRes = $cbStmt->get_result();
        while ($row = $cbRes->fetch_assoc()) { $cbAppointments[] = $row; }
        $cbStmt->close();
    }
    $cbSv = $conn->query("SELECT avatar_url FROM users WHERE role = 'staff' AND status = 'active' AND avatar_url IS NOT NULL AND avatar_url != '' ORDER BY user_id ASC LIMIT 1");
    if ($cbSv && $cbSv->num_rows > 0) { $cbStaffAvatar = $cbSv->fetch_assoc()['avatar_url']; }
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
  .cb-app .cb-chip{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:500;line-height:1.25;border-radius:5px;padding:3px 6px;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#F4F4F8;color:#18181b;}
  .cb-app .cb-chip .cb-dot{width:3px;align-self:stretch;border-radius:2px;flex:none}
  .cb-app .cb-chip.cb-blue .cb-dot{background:#4F8CFF}
  .cb-app .cb-chip.cb-green .cb-dot{background:#0E8131}
  .cb-app .cb-chip.cb-purple .cb-dot{background:#8B5CF6}
  .cb-app .cb-chip.cb-orange .cb-dot{background:#FF9F43}
  .cb-app .cb-chip.cb-pink .cb-dot{background:#F15BB5}

  /* ============ RIGHT PANEL ============ */
  .cb-app .cb-event-pane{width:460px;flex:none;display:flex;flex-direction:column;background:#fff;border-left:1px solid #e4e4e7;}
  .cb-app .cb-panel-body{padding:22px 26px;overflow-y:auto;flex:1}
  .cb-app .cb-section{margin-bottom:22px}
  .cb-app .cb-title-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px;}
  .cb-app .cb-title-input{flex:1;min-width:0;border:none;outline:none;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;letter-spacing:-.025em;line-height:1;color:#18181b;padding:4px 0;background:transparent;}
  .cb-app .cb-title-input::placeholder{color:#C3C3D2}
  .cb-app .cb-link-row{display:flex;align-items:center;justify-content:space-between;margin-top:14px}
  .cb-app .cb-link-text{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;}
  .cb-app .cb-time-row{display:flex;align-items:center;justify-content:space-between;gap:22px;margin-top:20px}
  .cb-app .cb-time-block{display:flex;flex-direction:column}
  .cb-app .cb-time-block .cb-t{font-family:'Inter','Segoe UI',sans-serif;font-size:30px;font-weight:700;letter-spacing:-.02em;color:#18181b}
  .cb-app .cb-time-block .cb-d{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;margin-top:4px}
  .cb-app .cb-pet-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:1px solid #e4e4e7;background:#f4f4f8;flex-shrink:0;}
  .cb-app .cb-people{margin-top:22px;border-top:1px solid #e4e4e7;padding-top:18px}
  .cb-app .cb-person{display:flex;align-items:center;gap:12px;padding:9px 0}
  .cb-app .cb-person .cb-avatar{width:38px;height:38px}
  .cb-app .cb-p-name{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b}
  .cb-app .cb-p-mail{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a}
  .cb-app .cb-role{margin-left:auto;font-family:'Inter','Segoe UI',sans-serif;font-size:13px;color:#18181b}
  .cb-app .cb-role.cb-master{color:#18181b;font-weight:600}
  .cb-app .cb-help{margin-top:10px;padding:0 0 6px;}
  .cb-app .cb-help-txt{font-style:italic;font-size:13px;color:#71717a;}
  .cb-app .cb-contact{color:#18181b;font-weight:600;text-decoration:none;font-style:normal;}
  .cb-app .cb-contact:hover{text-decoration:underline;}
  .cb-app .cb-reminders{border-top:1px solid #e4e4e7;padding-top:16px;margin-top:12px;}
  .cb-app .cb-reminders-head{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#18181b;margin:0 0 8px;}
  .cb-app .cb-reminders-list{margin:0;padding-left:18px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;line-height:1.6;}
  .cb-app .cb-reminders-list li{margin-bottom:4px;}
  .cb-app .cb-reminders-list li:last-child{margin-bottom:0;}
  .cb-app .cb-appt-details{border-top:1px solid #e4e4e7;padding-top:14px;margin-bottom:22px;}
  .cb-app .cb-day-appt-picker{display:none;margin:0 0 18px;padding:12px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05);font-family:'Inter',sans-serif;color:#18181b;}
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
  .cb-app .cb-detail-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;}
  .cb-app .cb-detail-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;}
  .cb-app .cb-detail-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;text-align:right;}
  .cb-app .cb-note-head{display:flex;align-items:center;justify-content:space-between;margin-top:20px}
  .cb-app .cb-note-head span{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#18181b}
  .cb-app .cb-note-box{margin-top:12px;border-radius:6px;padding:14px;font-size:14px;line-height:1.5;}
  .cb-app .cb-note-box p{margin:0;}
  .cb-app .cb-note-pending{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
  .cb-app .cb-note-scheduled{background:#dcebe0;border:1px solid #a7f3d0;color:#065f46;}
  .cb-app .cb-st{display:inline-flex;align-items:center;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;padding:3px 10px;line-height:1.4;white-space:nowrap;border:1px solid;}
  .cb-app .cb-empty-state{display:flex;align-items:center;justify-content:center;padding:80px 30px;text-align:center;}
  .cb-app .cb-empty-state p{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;color:#71717a;}
  .cb-app .cb-event-backdrop{display:none;}
  .cb-app .cb-panel-close{display:none;}
  .cb-app .cb-mobile-more{display:none;}
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
    .cb-app .cb-chip{height:4px;min-height:0;padding:0;margin:0 0 3px;justify-content:center;border-radius:3px}
    .cb-app .cb-chip > span:last-child{display:none}
    .cb-app .cb-chip .cb-dot{width:100%;min-height:4px}
    .cb-app .cb-mobile-hidden{display:none}
    .cb-app .cb-mobile-more{display:block;text-align:center;color:#52525b;font-size:9px;font-weight:600;line-height:1.2}
    .cb-app .cb-event-backdrop{position:fixed;inset:0;z-index:1098;background:rgba(15,23,32,.5)}
    .cb-app.cb-dialog-open .cb-event-backdrop{display:block}
    .cb-app .cb-event-pane{position:fixed;top:50%;left:50%;z-index:1099;width:calc(100vw - 32px);max-width:480px;max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);border:1px solid #e4e4e7;border-radius:12px;box-shadow:0 16px 48px rgba(15,23,32,.24);transform:translate(-50%,-48%) scale(.98);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,transform .18s ease,visibility .18s ease}
    .cb-app .cb-event-pane.open{transform:translate(-50%,-50%) scale(1);opacity:1;visibility:visible;pointer-events:auto}
    .cb-app .cb-panel-body{min-height:0;padding:20px;overflow-y:auto}
    .cb-app .cb-panel-close{width:28px;height:28px;margin:0 0 12px auto;border:0;border-radius:6px;background:#f4f4f5;color:#52525b;display:flex;align-items:center;justify-content:center;font-size:20px;line-height:1;cursor:pointer}
    .cb-app .cb-panel-close:hover{background:#e4e4e7;color:#18181b}
    .cb-app .cb-empty-state{padding:24px 12px}
    .cb-app .cb-time-block .cb-t{font-size:26px}
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
        <button class="cb-icon-btn" type="button" title="View">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 4v16"/></svg>
        </button>
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
            <input class="cb-title-input" id="cbTitleInput" type="text" value="" placeholder="Add appointment title" readonly />
            <span class="cb-st cb-st-pending" id="cbStatusPill">Pending</span>
          </div>
           <div class="cb-link-row" id="cbRefRow">
            <span class="cb-link-text" id="cbRefText">#APT-000000</span>
          </div>
          <div class="cb-time-row">
            <div class="cb-time-block">
              <div class="cb-t" id="cbStartTime">12:00 AM</div>
              <div class="cb-d" id="cbDateLabel">Today</div>
            </div>
            <img class="cb-pet-avatar" id="cbPetAvatar" src="" alt="Pet" style="display:none;" />
          </div>
        </div>

        <div class="cb-appt-details" id="cbApptMeta">
          <p class="cb-detail-head">Appointment Summary Details</p>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Pet Name</span>
            <span class="cb-detail-value" id="cbDetailPet">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Service Type</span>
            <span class="cb-detail-value" id="cbDetailService">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Requested on</span>
            <span class="cb-detail-value" id="cbDetailRequested">—</span>
          </div>
          <div class="cb-detail-row" id="cbScheduledRow" style="display:none;">
            <span class="cb-detail-label">Scheduled on</span>
            <span class="cb-detail-value" id="cbDetailScheduled">—</span>
          </div>
          <div class="cb-detail-row">
            <span class="cb-detail-label">Location</span>
            <span class="cb-detail-value">DCVS Veterinary Clinic</span>
          </div>
        </div>

        <div class="cb-section">
          <div class="cb-note-head">
            <span>Appointment Notes</span>
          </div>
          <div class="cb-note-box" id="cbNoteBox">
            <p id="cbNoteMsg">Your appointment request is currently being reviewed by DCVS.</p>
          </div>
        </div>

        <div class="cb-people" id="cbPeopleList">
          <div class="cb-person">
            <?php if ($cbStaffAvatar): ?>
              <img class="cb-avatar" src="<?php echo htmlspecialchars($cbStaffAvatar); ?>" alt="DCVS Staff" />
            <?php else: ?>
              <span class="cb-avatar" style="display:flex;align-items:center;justify-content:center;background:#EFEDFD;color:#5B4FE9;font-size:16px;flex:none;"><i class="fas fa-user-md"></i></span>
            <?php endif; ?>
            <div><div class="cb-p-name">Dasmari&#241;as City Veterinary Services</div><div class="cb-p-mail">admin@dcvs.gov.ph</div></div>
            <span class="cb-role cb-master">Admin</span>
          </div>
          <div class="cb-help">
            <p class="cb-help-txt">Need assistance regarding this appointment? <a class="cb-contact" href="contact.php">Contact us</a></p>
          </div>
        </div>

        <div class="cb-reminders" id="cbReminders">
          <p class="cb-reminders-head">Make sure to:</p>
          <ul class="cb-reminders-list">
            <li>Arrive at the DCVS office on your scheduled date and time.</li>
            <li>Bring your pet and required documents.</li>
            <li>Your pet will undergo the necessary pre-service assessment.</li>
            <li>Wait for the service completion/update.</li>
          </ul>
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

  var statusColor = {
    pending:'cb-orange', scheduled:'cb-green', approved:'cb-blue',
    rescheduled:'cb-purple', in_progress:'cb-blue', completed:'cb-blue',
    cancelled:'cb-pink', rejected:'cb-pink', no_show:'cb-orange'
  };

  var apptEvents = {};
  rawAppts.forEach(function(a){
    var dt = a.preferred_date ? new Date(a.preferred_date.replace(/-/g,'/')) : null;
    if (!dt) return;
    var key = dt.getFullYear() + '-' + dt.getMonth() + '-' + dt.getDate();
    if (!apptEvents[key]) apptEvents[key] = [];
    var chipClass = statusColor[a.status] || 'cb-orange';
    var timeStr = a.preferred_time ? fmtTime24(a.preferred_time) : '';
    var label = a.appointment_type ? a.appointment_type.charAt(0).toUpperCase() + a.appointment_type.slice(1) : 'Appointment';
    apptEvents[key].push({
      chipClass: chipClass, time: timeStr, label: label,
      id: a.appointment_id, type: a.appointment_type, pet: a.pet_name || 'N/A',
      status: a.status, date: a.preferred_date, timeRaw: a.preferred_time,
      notes: a.notes || '', ref: a.reference_id || '', createdAt: a.created_at || '',
      photoUrl: a.photo_url || ''
    });
  });

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
      visibleAppts.forEach(function(ev, apptIndex){
        var chip = document.createElement('div');
        chip.className = 'cb-chip ' + ev.chipClass + (apptIndex >= 2 ? ' cb-mobile-hidden' : '');
        chip.innerHTML = '<span class="cb-dot"></span><span><b>' + ev.time + '</b> &#183; ' + ev.label + '</span>';
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
  var statusPill   = document.getElementById('cbStatusPill');
  var refText      = document.getElementById('cbRefText');
  var refRow       = document.getElementById('cbRefRow');
  var noteBox      = document.getElementById('cbNoteBox');
  var noteMsg      = document.getElementById('cbNoteMsg');
  var detailReq    = document.getElementById('cbDetailRequested');
  var detailPet    = document.getElementById('cbDetailPet');
  var detailService = document.getElementById('cbDetailService');
  var detailScheduled = document.getElementById('cbDetailScheduled');
  var scheduledRow = document.getElementById('cbScheduledRow');
  var reminders    = document.getElementById('cbReminders');
  var emptyState   = document.getElementById('cbEmptyState');
  var apptDetails  = document.getElementById('cbApptDetails');
  var petAvatar    = document.getElementById('cbPetAvatar');
  var dayApptPicker = document.getElementById('cbDayApptPicker');
  var dayApptCount = document.getElementById('cbDayApptCount');
  var dayApptTimes = document.getElementById('cbDayApptTimes');
  var dayApptPrev = document.getElementById('cbDayApptPrev');
  var dayApptNext = document.getElementById('cbDayApptNext');

  var pillColors = {
    pending:     { bg:'#fef3c7', color:'#92400e', border:'#fde68a' },
    scheduled:   { bg:'#0E8131', color:'#ffffff', border:'#0E8131' },
    approved:    { bg:'#dcebe0', color:'#045e1f', border:'#bbf7d0' },
    rescheduled: { bg:'#ede9fe', color:'#6d28d9', border:'#ddd6fe' },
    in_progress: { bg:'#dcebe0', color:'#045e1f', border:'#bbf7d0' },
    completed:   { bg:'#dbeafe', color:'#1e40af', border:'#bfdbfe' },
    cancelled:   { bg:'#fee2e2', color:'#991b1b', border:'#fecaca' },
    rejected:    { bg:'#fee2e2', color:'#991b1b', border:'#fecaca' },
    no_show:     { bg:'#fef3c7', color:'#92400e', border:'#fde68a' }
  };

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
      statusPill.className = 'cb-st';
      statusPill.textContent = '';
      petAvatar.style.display = 'none';
      petAvatar.src = '';
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
    titleInput.value = appt.type.charAt(0).toUpperCase() + appt.type.slice(1) + ' service for ' + appt.pet;
    startTime.textContent = appt.time || '12:00 AM';
    dateLabel.textContent = dt.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
    if (appt.photoUrl){ petAvatar.src = appt.photoUrl; petAvatar.style.display = ''; }
    else { petAvatar.style.display = 'none'; petAvatar.src = ''; }
    var c = pillColors[appt.status] || pillColors.pending;
    statusPill.className = 'cb-st';
    statusPill.style.background = c.bg;
    statusPill.style.color = c.color;
    statusPill.style.borderColor = c.border;
    statusPill.textContent = appt.status.charAt(0).toUpperCase() + appt.status.slice(1).replace('_',' ');
    /* ref row */
    refRow.innerHTML = '<span class="cb-link-text" id="cbRefText">' + (appt.ref || '#APT-000000') + '</span>';
    /* note box */
    noteMsg.textContent = noteMessages[appt.status] || noteMessages.pending;
    noteBox.className = 'cb-note-box cb-note-' + (isPending ? 'pending' : 'scheduled');
    /* details */
    if (appt.createdAt) {
      var cd = new Date(appt.createdAt.replace(/-/g,'/'));
      var ms = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      detailReq.textContent = ms[cd.getMonth()] + ' ' + cd.getDate() + ', ' + cd.getFullYear();
    } else {
      detailReq.textContent = '—';
    }
    detailPet.textContent = appt.pet || '—';
    detailService.textContent = appt.type.charAt(0).toUpperCase() + appt.type.slice(1);
    /* scheduled date - show if status is scheduled */
    if (appt.status === 'scheduled' || appt.status === 'approved') {
      scheduledRow.style.display = '';
      if (appt.date) {
        var sd = new Date(appt.date.replace(/-/g,'/'));
        detailScheduled.textContent = ms[sd.getMonth()] + ' ' + sd.getDate() + ', ' + sd.getFullYear();
      } else {
        detailScheduled.textContent = '—';
      }
    } else {
      scheduledRow.style.display = 'none';
    }
    /* reminders: only for pending/scheduled */
    reminders.style.display = (appt.status === 'pending' || appt.status === 'scheduled') ? '' : 'none';
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
