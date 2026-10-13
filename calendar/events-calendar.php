<?php
/**
 * DCVS Staff Schedule — annual view of appointments, programs, and reports.
 * Scoped under #dcvsEventsCalendar so it won't clash with the dashboard.
 */
require_once __DIR__ . '/../db.php';
$calYear = (int)date('Y');
$calAppointments = [];
if (isset($conn) && !$conn->connect_error) {
    $calStmt = $conn->prepare("
        SELECT a.appointment_id, a.reference_id, a.pet_id, a.preferred_date, a.preferred_time,
               a.status, a.appointment_type, a.notes, a.created_at,
               COALESCE(NULLIF(a.pet_name,''), p.pet_name) AS pet_name,
               p.pet_type, p.breed, p.photo_url AS pet_photo, p.reference_id AS pet_ref,
               u.full_name, u.phone, u.address, b.barangay_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE a.archived_at IS NULL
          AND a.status IN ('pending','approved','scheduled','rescheduled','in_progress')
          AND YEAR(a.preferred_date) = ?
        ORDER BY a.preferred_date, a.preferred_time
    ");
    if ($calStmt) {
        $calStmt->bind_param('i', $calYear);
        $calStmt->execute();
        $calRes = $calStmt->get_result();
        while ($row = $calRes->fetch_assoc()) { $calAppointments[] = $row; }
        $calStmt->close();
    }
    $calAnnouncements = [];
    $annRes = $conn->query("SELECT a.announcement_id, a.title, a.content, a.category, a.priority, a.status, a.publish_at, a.created_at, a.updated_at, a.expiry_date, a.photo_url, a.barangay_id, a.is_event, a.event_date, a.event_end_date, a.event_start_time, a.event_end_time, a.venue_name, b.barangay_name FROM announcements a LEFT JOIN barangays b ON a.barangay_id = b.barangay_id WHERE a.status IN ('published','scheduled') AND a.archived_at IS NULL AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE()) ORDER BY COALESCE(a.publish_at, a.created_at)");
    if ($annRes) { while ($row = $annRes->fetch_assoc()) { $calAnnouncements[] = $row; } }
    $calPrograms = [];
    $progStmt = $conn->prepare("SELECT p.program_id, p.program_name, p.program_type, p.description, p.status, p.start_date, p.end_date, p.operating_time, p.location_mode, p.venue, (SELECT MAX(a.announcement_id) FROM announcements a WHERE a.program_id = p.program_id) AS announcement_id, GROUP_CONCAT(DISTINCT b.barangay_name ORDER BY b.barangay_name SEPARATOR ', ') AS barangay_names FROM programs p LEFT JOIN program_barangays pb ON p.program_id = pb.program_id LEFT JOIN barangays b ON pb.barangay_id = b.barangay_id WHERE p.status IN ('scheduled','live','paused') AND p.end_date >= CURDATE() AND p.end_date >= ? AND p.start_date <= ? GROUP BY p.program_id ORDER BY p.start_date, p.program_name");
    if ($progStmt) {
      $yearStart = $calYear . '-01-01';
      $yearEnd = $calYear . '-12-31';
      $progStmt->bind_param('ss', $yearStart, $yearEnd);
      $progStmt->execute();
      $progRes = $progStmt->get_result();
      while ($row = $progRes->fetch_assoc()) { $calPrograms[] = $row; }
      $progStmt->close();
    }
    $calReports = [];
    $reportStmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.description, r.location_address, r.urgency_level, r.status, r.created_at, COALESCE(r.incident_at, r.created_at) AS incident_at, b.barangay_name, u.full_name AS reporter_name
        FROM reports r
        LEFT JOIN barangays b ON b.barangay_id = r.barangay_id
        LEFT JOIN users u ON u.user_id = r.reporter_id
      WHERE r.archived_at IS NULL AND r.status <> 'resolved' AND YEAR(COALESCE(r.incident_at, r.created_at)) = ?
      ORDER BY COALESCE(r.incident_at, r.created_at) DESC");
    if ($reportStmt) {
      $reportStmt->bind_param('i', $calYear);
      $reportStmt->execute();
      $reportRes = $reportStmt->get_result();
      while ($row = $reportRes->fetch_assoc()) { $calReports[] = $row; }
      $reportStmt->close();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar Schedule — DCVS Staff</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body style="margin:0;font-family:'Inter','Segoe UI',sans-serif;background:#f5f5f5;">
<div class="ec-root" id="dcvsEventsCalendar">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800&family=Geist+Mono:wght@200;300;400;500&display=swap');

#dcvsEventsCalendar{
  --ec-bg:#ffffff; --ec-fg:#18181b; --ec-primary:#27272a; --ec-primary-fg:#fafafa;
  --ec-muted:#f4f4f5; --ec-muted-fg:#71717a; --ec-accent:#f4f4f5; --ec-accent-fg:#18181b;
  --ec-border:#e4e4e7; --ec-input:#e4e4e7; --ec-ring:#a1a1aa; --ec-radius:0.625rem;
  background:var(--ec-bg); color:var(--ec-fg);
  font-family:'Inter','Segoe UI',sans-serif;
  min-height:100vh; box-sizing:border-box;
  margin:-1.75rem -2rem;
  position:relative;
}
#dcvsEventsCalendar h1, #dcvsEventsCalendar h2{ font-family:'Inter','Segoe UI',sans-serif; }
#dcvsEventsCalendar.ec-dark{
  --ec-bg:#18181b; --ec-fg:#fafafa; --ec-primary:#e4e4e7; --ec-primary-fg:#18181b;
  --ec-muted:#3f3f46; --ec-muted-fg:#a1a1aa; --ec-accent:#52525b; --ec-accent-fg:#fafafa;
  --ec-border:rgba(255,255,255,.1); --ec-input:rgba(255,255,255,.15); --ec-ring:#a1a1aa;
}
#dcvsEventsCalendar *{ box-sizing:border-box; }

/* Header */
#dcvsEventsCalendar .ec-header{
  position:sticky; top:64px; z-index:50;
  background:var(--ec-bg); border-bottom:1px solid var(--ec-border);
}
#dcvsEventsCalendar .ec-header-inner{ padding:16px 24px 0; }
#dcvsEventsCalendar .ec-top-row{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
#dcvsEventsCalendar .ec-title{ font-size:20px; font-weight:700; margin:0; line-height:28px; letter-spacing:-.01em; }
#dcvsEventsCalendar .ec-title-link{ color:inherit; text-decoration:none; font-weight:500; }
#dcvsEventsCalendar .ec-title-link:hover{ text-decoration:underline; }
#dcvsEventsCalendar .ec-subtitle{ font-size:14px; color:var(--ec-muted-fg); margin:0; }

#dcvsEventsCalendar .ec-dark-toggle{ display:flex; align-items:center; gap:8px; color:var(--ec-fg); }
#dcvsEventsCalendar .ec-dark-toggle svg{ width:16px; height:16px; }
#dcvsEventsCalendar .ec-switch{
  width:40px; height:22px; border-radius:9999px; background:var(--ec-input);
  border:none; position:relative; cursor:pointer; padding:0; transition:background .2s; flex-shrink:0;
}
#dcvsEventsCalendar .ec-switch .ec-thumb{
  display:block; width:16px; height:16px; border-radius:50%; background:#fff;
  position:absolute; top:3px; left:3px; box-shadow:0 1px 2px rgba(0,0,0,.2);
  transition:transform .2s;
}
#dcvsEventsCalendar .ec-switch.on{ background:var(--ec-primary); }
#dcvsEventsCalendar .ec-switch.on .ec-thumb{ transform:translateX(18px); }

/* Controls row */
#dcvsEventsCalendar .ec-controls-row{
  display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;
  margin-top:16px;
}
#dcvsEventsCalendar .ec-controls-left{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
#dcvsEventsCalendar .ec-controls-right{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; }

/* Buttons */
#dcvsEventsCalendar .ec-btn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  height:36px; padding:0 16px; border-radius:6px; border:1px solid var(--ec-input);
  background:transparent; color:var(--ec-fg); font-family:inherit; font-size:14px; font-weight:500;
  cursor:pointer; transition:background .15s, color .15s, border-color .15s; white-space:nowrap;
}
#dcvsEventsCalendar .ec-btn:hover{ background:var(--ec-accent); color:var(--ec-accent-fg); }
#dcvsEventsCalendar .ec-btn svg{ width:16px; height:16px; flex-shrink:0; }
#dcvsEventsCalendar .ec-btn-default{ background:var(--ec-primary); color:var(--ec-primary-fg); border-color:var(--ec-primary); }
#dcvsEventsCalendar .ec-btn-default:hover{ background:var(--ec-primary); color:var(--ec-primary-fg); opacity:.9; }
#dcvsEventsCalendar .ec-btn-icon{ width:36px; padding:0; }
#dcvsEventsCalendar .ec-mode-btn{ font-weight:700; background:var(--ec-accent); }

/* Search */
#dcvsEventsCalendar .ec-search-wrap{ position:relative; }
#dcvsEventsCalendar .ec-search-wrap svg{
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  width:16px; height:16px; color:var(--ec-muted-fg); pointer-events:none;
}
#dcvsEventsCalendar .ec-search{
  width:200px; height:36px; padding:0 12px 0 36px; border-radius:6px;
  border:1px solid var(--ec-input); background:transparent; color:var(--ec-fg);
  font-family:inherit; font-size:14px; outline:none; transition:box-shadow .15s, border-color .15s;
}
#dcvsEventsCalendar .ec-search::placeholder{ color:var(--ec-muted-fg); }
#dcvsEventsCalendar .ec-search:focus{ border-color:var(--ec-ring); box-shadow:0 0 0 3px var(--ec-ring); }

/* Dropdowns */
#dcvsEventsCalendar .ec-dropdown{ position:relative; }
#dcvsEventsCalendar .ec-menu{ max-height:360px; overflow-y:auto;
  position:absolute; top:calc(100% + 6px); left:0; min-width:180px; z-index:60;
  background:var(--ec-bg); border:1px solid var(--ec-border); border-radius:6px;
  box-shadow:0 4px 20px rgba(0,0,0,.12); padding:4px; display:none;
}
#dcvsEventsCalendar .ec-menu.open{ display:block; animation:ecFadeIn .12s ease; }
#dcvsEventsCalendar .ec-menu-item{
  display:block; width:100%; text-align:left; padding:8px 10px; border-radius:4px;
  font-family:inherit; font-size:14px; color:var(--ec-fg); background:transparent; border:none;
  cursor:pointer; white-space:nowrap;
}
#dcvsEventsCalendar .ec-menu-item:hover{ background:var(--ec-accent); }
#dcvsEventsCalendar .ec-menu-item.active{ font-weight:600; }

/* Weekday header row */
#dcvsEventsCalendar .ec-weekday-row{ display:flex; margin-top:16px; }
#dcvsEventsCalendar .ec-month-nav{ width:180px; flex-shrink:0; display:flex; align-items:center; gap:4px; }
#dcvsEventsCalendar .ec-nav-btn{
  width:28px; height:28px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
  border:1px solid var(--ec-input); background:transparent; color:var(--ec-fg); border-radius:6px;
  cursor:pointer; font-size:15px; line-height:1; transition:background .15s;
}
#dcvsEventsCalendar .ec-nav-btn:hover{ background:var(--ec-accent); }
#dcvsEventsCalendar .ec-nav-btn svg{ width:14px; height:14px; }
#dcvsEventsCalendar .ec-nav-label{
  flex:1; text-align:center; font-size:12px; font-weight:500; color:var(--ec-fg);
  white-space:nowrap; overflow:hidden;
}
#dcvsEventsCalendar .ec-weekdays{
  flex:1; display:grid; grid-template-columns:repeat(7,1fr);
  border-bottom:1px solid var(--ec-border);
}
#dcvsEventsCalendar .ec-weekday{ padding:8px; text-align:center; font-size:14px; font-weight:500; }
#dcvsEventsCalendar .ec-weekday-short{ display:none; }

/* Main / months */
#dcvsEventsCalendar .ec-main{ padding:32px 24px; }
#dcvsEventsCalendar .ec-month-row{ display:flex; gap:32px; margin-bottom:64px; }
#dcvsEventsCalendar .ec-month-label{
  width:180px; flex-shrink:0; position:sticky; top:220px; align-self:flex-start; height:fit-content;
}
#dcvsEventsCalendar .ec-month-label h2{ font-size:36px; font-weight:600; margin:0; letter-spacing:-.025em; }
#dcvsEventsCalendar .ec-grid{
  flex:1; display:grid; grid-template-columns:repeat(7,1fr); gap:1px;
  background:var(--ec-border); border:1px solid var(--ec-border);
}
#dcvsEventsCalendar .ec-day{
  background:var(--ec-bg); padding:8px; min-height:120px; transition:background .15s;
}
#dcvsEventsCalendar .ec-day:hover{ background:var(--ec-accent); }
#dcvsEventsCalendar .ec-day.today{ box-shadow:inset 0 0 0 2px var(--ec-primary); }
#dcvsEventsCalendar .ec-day.ec-day-capacity-full .ec-events{ filter:blur(1.5px); opacity:.72; transition:filter .15s,opacity .15s; }
#dcvsEventsCalendar .ec-day.ec-day-capacity-full:hover .ec-events,
#dcvsEventsCalendar .ec-day.ec-day-capacity-full:focus-within .ec-events{ filter:none; opacity:1; }
#dcvsEventsCalendar .ec-capacity-badge{ display:inline-block; margin:0 0 8px; padding:2px 6px; border-radius:4px; background:#fee2e2; color:#991b1b; font-size:10px; font-weight:700; }
#dcvsEventsCalendar .ec-day-num{
  font-family:'Geist Mono','SFMono-Regular',Consolas,monospace; font-weight:300;
  font-size:4.5rem; line-height:1; margin:0 0 8px;
}
#dcvsEventsCalendar .ec-day.today .ec-day-num{ color:var(--ec-primary); }

/* Event blocks */
#dcvsEventsCalendar .ec-events{ display:flex; flex-direction:column; gap:8px; }
#dcvsEventsCalendar .ec-event{
  position:relative; display:block; text-align:left; width:100%;
  font-size:12px; padding:8px; border:none; border-left:2px solid var(--ec-primary);
  background:color-mix(in srgb, var(--ec-muted) 50%, transparent);
  color:var(--ec-fg); cursor:pointer; transition:background .2s, padding-left .2s, border-color .2s;
  border-radius:0;
}
#dcvsEventsCalendar .ec-event:hover{ background:var(--ec-muted); padding-left:12px; }
#dcvsEventsCalendar .ec-event-name{ font-weight:500; line-height:1.25; }
#dcvsEventsCalendar .ec-event-edition{
  display:inline-block; margin-top:4px; font-size:10px; background:var(--ec-bg);
  padding:2px 4px; border-radius:4px;
}
/* Appointment status colors */
#dcvsEventsCalendar .ec-st-pending{ border-left-color:#f59e0b; }
#dcvsEventsCalendar .ec-st-published{ border-left-color:#10b981; }
#dcvsEventsCalendar .ec-st-scheduled{ border-left-color:#10b981; }
#dcvsEventsCalendar .ec-st-completed{ border-left-color:#64748b; }
#dcvsEventsCalendar .ec-st-cancelled{ border-left-color:#ef4444; }
#dcvsEventsCalendar .ec-st-rejected{ border-left-color:#ef4444; }
#dcvsEventsCalendar .ec-st-rescheduled{ border-left-color:#8b5cf6; }
#dcvsEventsCalendar .ec-st-no-show{ border-left-color:#94a3b8; }
#dcvsEventsCalendar .ec-event-edition.ec-st-pending{ background:#fffbeb; color:#b45309; }
#dcvsEventsCalendar .ec-event-edition.ec-st-published{ background:#ecfdf5; color:#047857; }
#dcvsEventsCalendar .ec-event-edition.ec-st-scheduled{ background:#ecfdf5; color:#047857; }
#dcvsEventsCalendar .ec-event-edition.ec-st-completed{ background:#f1f5f9; color:#475569; }
#dcvsEventsCalendar .ec-event-edition.ec-st-cancelled{ background:#fef2f2; color:#b91c1c; }
#dcvsEventsCalendar .ec-event-edition.ec-st-rejected{ background:#fef2f2; color:#b91c1c; }
#dcvsEventsCalendar .ec-event-edition.ec-st-rescheduled{ background:#f5f3ff; color:#6d28d9; }
#dcvsEventsCalendar .ec-event-edition.ec-st-no-show{ background:#f1f5f9; color:#475569; }
#dcvsEventsCalendar .ec-event-edition.ec-st-live{ background:#ecfdf5; color:#047857; }
#dcvsEventsCalendar .ec-event-edition.ec-st-paused{ background:#fffbeb; color:#92400e; }
#dcvsEventsCalendar .ec-dialog-ref{ font-size:13px; color:var(--ec-muted-fg); margin:0 0 8px; }

/* Calendar dialogs use the dashboard's shadcn (sd-dialog) design */
#ecEventDialog.modal{ position:fixed; inset:0; background:rgba(0,0,0,.5); padding:16px; z-index:999; display:flex; align-items:center; justify-content:center; opacity:0; visibility:hidden; transition:opacity .25s ease,visibility .25s ease; }
#ecEventDialog.modal.active{ opacity:1; visibility:visible; }
#ecEventDialog .sd-dialog{ width:100%; height:auto; display:flex; justify-content:center; }
#ecEventDialog .sd-dialog-content{ width:min(480px,94vw); max-width:min(480px,94vw); height:auto; max-height:calc(100vh - 32px); overflow-y:auto; box-sizing:border-box; border:1px solid #e4e4e7; border-radius:10px; box-shadow:0 20px 25px rgba(15,23,32,.18); animation:none; transform:none; transition:none; padding:24px; gap:16px; }
#ecEventDialog.active .sd-dialog-content{ transform:none; }
#ecEventDialog #ecEventBody{ display:flex; flex-direction:column; gap:20px; font-family:'Inter','Segoe UI',sans-serif; }
#ecEventDialog .sd-dialog-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; }
#ecEventDialog .sd-dialog-description{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; line-height:1.45; }
#ecEventDialog .ec-detail-rows,
#ecEventDialog .ec-info-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px 20px; }
#ecEventDialog .ec-info-grid > .ec-sec-title{ grid-column:1/-1; }
#ecEventDialog .ec-info-grid > .ec-notes-section{ grid-column:1/-1; min-width:0; }
#ecEventDialog .ec-detail-row,
#ecEventDialog .ec-field-row{ display:flex; flex-direction:column; align-items:flex-start; gap:4px; min-width:0; padding:0; border:0; }
#ecEventDialog .ec-detail-row .sd-label,
#ecEventDialog .ec-field-label{ margin:0; color:#71717a; font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; line-height:1.4; }
#ecEventDialog .ec-detail-row .sd-value,
#ecEventDialog .ec-field-value{ color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; line-height:1.4; text-align:left; overflow-wrap:anywhere; }
#ecEventDialog .ec-detail-row .ec-status-pill{ align-self:flex-start; }
#ecAuthDialog.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
#ecAuthDialog .sd-dialog-content{ max-width:440px; gap:20px; }
#ecEventDialog .ec-event-edition{ margin-top:0; font-size:11px; }
#ecAuthDialog .sd-input{ box-sizing:border-box; }
#dcvsEventsCalendar .ec-status-pill{ display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; align-self:flex-start; width:auto; }
#ecEventDialog .sd-dialog-footer{ margin-top:0; display:flex; flex-direction:row; gap:12px; justify-content:flex-end; }

/* Confirm dialog */
#ecConfirmDialog.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:1000; }
#ecConfirmDialog .sd-dialog-content{ gap:16px; }
#ecConfirmDialog .sd-dialog-header{ display:flex; flex-direction:column; gap:14px; text-align:left; align-items:flex-start; }
#ecConfirmDialog .sd-dialog-description strong{ color:#18181b; }
#ecConfirmDialog .ec-confirm-detail{ display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f4f4f5; font-size:14px; }
#ecConfirmDialog .ec-confirm-detail:last-child{ border-bottom:none; }
#ecConfirmDialog .ec-confirm-label{ color:#71717a; font-weight:400; }
#ecConfirmDialog .ec-confirm-value{ color:#18181b; font-weight:600; }
#ecConfirmDialog .ec-confirm-desc-box,
#ecEventDialog .ec-confirm-desc-box{ background:#f8fafc; border:1px solid #e4e4e7; border-radius:6px; padding:10px 12px; font-size:14px; color:#18181b; line-height:1.5; margin-top:4px; }

/* Appointment detail sections */
#ecEventDialog .ec-sec-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; margin:0 0 2px; }
#ecEventDialog .ec-pet-card{ display:flex; align-items:center; gap:12px; background:transparent; border:0; border-radius:0; padding:0; }
#ecEventDialog .ec-pet-card img{ width:72px; height:72px; border-radius:8px; object-fit:cover; flex-shrink:0; }
#ecEventDialog .ec-pet-card .ec-pet-placeholder{ width:72px; height:72px; border-radius:8px; background:#e4e4e7; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-weight:600; font-size:24px; color:#64748b; }
#ecEventDialog .ec-pet-card .ec-pet-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; margin-bottom:2px; }
#ecEventDialog .ec-pet-card .ec-pet-meta{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:#71717a; }
#ecEventDialog .ec-notes-box{ background:#f9fafb; border:1px solid #e4e4e7; border-radius:6px; padding:10px 12px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#18181b; line-height:1.5; }
@media(max-width:560px){
  #ecEventDialog .ec-detail-rows,
  #ecEventDialog .ec-info-grid{ grid-template-columns:minmax(0,1fr); }
}

/* Reschedule input dialog */
#ecReschedDialog.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:1000; }
#ecReschedDialog .sd-dialog-content{ gap:16px; }
#ecReschedDialog .ec-resched-field{ display:flex; flex-direction:column; gap:4px; }
#ecReschedDialog .ec-resched-field label{ font-size:14px; color:#71717a; font-weight:400; }
#ecReschedDialog .ec-resched-field input{ width:100%; border:1px solid #e4e4e7; border-radius:6px; padding:8px 12px; font-family:inherit; font-size:14px; box-sizing:border-box; }
#dcvsEventsCalendar .ec-status-pill.ec-st-pending{ background:#fffbeb; color:#b45309; }
#dcvsEventsCalendar .ec-status-pill.ec-st-scheduled{ background:#ecfdf5; color:#047857; }
#dcvsEventsCalendar .ec-status-pill.ec-st-completed{ background:#f1f5f9; color:#475569; }
#dcvsEventsCalendar .ec-status-pill.ec-st-cancelled{ background:#fef2f2; color:#b91c1c; }
#dcvsEventsCalendar .ec-status-pill.ec-st-rejected{ background:#fef2f2; color:#b91c1c; }
#dcvsEventsCalendar .ec-status-pill.ec-st-rescheduled{ background:#f5f3ff; color:#6d28d9; }
#dcvsEventsCalendar .ec-status-pill.ec-st-no-show{ background:#f1f5f9; color:#475569; }
#dcvsEventsCalendar .ec-event-time{ color:var(--ec-muted-fg); margin-top:4px; }
#dcvsEventsCalendar .ec-event-loc{ color:var(--ec-muted-fg); }
#dcvsEventsCalendar .ec-grid{ display:block; }
#dcvsEventsCalendar .ec-week-row{ display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:1px; position:relative; margin-bottom:1px; border:1px solid var(--ec-border); background:var(--ec-border); }
#dcvsEventsCalendar .ec-week-row .ec-day{ min-width:0; min-height:120px; }
#dcvsEventsCalendar .ec-day-num.ec-calendar-trigger{ display:block; width:auto; padding:0; border:0; background:transparent; color:inherit; text-align:left; cursor:pointer; }
#dcvsEventsCalendar .ec-week-row .ec-events{ gap:6px; }
#dcvsEventsCalendar .ec-week-row .ec-event{ display:block; padding:6px; border-left-width:3px; border-radius:4px; background:color-mix(in srgb,var(--event-color) 10%,#fff); }
#dcvsEventsCalendar .ec-week-row .ec-event.ec-type-kapon{ --event-color:#15803d; border-left-color:#15803d; }
#dcvsEventsCalendar .ec-week-row .ec-event.ec-type-checkup{ --event-color:#2563eb; border-left-color:#2563eb; }
#dcvsEventsCalendar .ec-week-row .ec-event.ec-type-rabies{ --event-color:#d97706; border-left-color:#d97706; }
#dcvsEventsCalendar .ec-week-row .ec-event.ec-type-seminar{ --event-color:#7c3aed; border-left-color:#7c3aed; }
#dcvsEventsCalendar .ec-week-row .ec-event.ec-type-other{ --event-color:#64748b; border-left-color:#64748b; }
#dcvsEventsCalendar .ec-event-card-head{ display:flex; align-items:center; justify-content:space-between; gap:4px; }
#dcvsEventsCalendar .ec-event-card-head .ec-event-name{ min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
#dcvsEventsCalendar .ec-event-card-head .ec-event-edition{ flex:0 0 auto; margin:0; text-transform:capitalize; }
#dcvsEventsCalendar .ec-cell-more{ display:block; width:100%; padding:2px 0; border:0; background:transparent; color:#0e8131; font:inherit; font-size:11px; font-weight:600; text-align:left; text-decoration:underline; cursor:pointer; }
#dcvsEventsCalendar .ec-week-row .ec-event-time,
#dcvsEventsCalendar .ec-week-row .ec-event-loc{ display:flex; align-items:center; gap:4px; margin-top:4px; overflow:hidden; font-size:10px; text-overflow:ellipsis; white-space:nowrap; }
#dcvsEventsCalendar .ec-week-row .ec-event-time svg,
#dcvsEventsCalendar .ec-week-row .ec-event-loc svg{ width:12px; height:12px; flex:0 0 12px; }
#dcvsEventsCalendar .ec-week-event{ position:absolute; z-index:2; display:flex; height:22px; align-items:center; gap:5px; overflow:hidden; padding:0 6px; border:0; border-left:3px solid var(--event-color); border-radius:3px; background:color-mix(in srgb,var(--event-color) 12%,#fff); color:var(--ec-fg); font-size:11px; text-align:left; white-space:nowrap; cursor:pointer; }
#dcvsEventsCalendar .ec-week-event.continues-before{ border-left-color:transparent; border-top-left-radius:0; border-bottom-left-radius:0; }
#dcvsEventsCalendar .ec-week-event.continues-after{ border-top-right-radius:0; border-bottom-right-radius:0; }
#dcvsEventsCalendar .ec-week-event:hover{ filter:brightness(.97); }
#dcvsEventsCalendar .ec-week-event.ec-type-kapon{ --event-color:#15803d; }
#dcvsEventsCalendar .ec-week-event.ec-type-checkup{ --event-color:#2563eb; }
#dcvsEventsCalendar .ec-week-event.ec-type-rabies{ --event-color:#d97706; }
#dcvsEventsCalendar .ec-week-event.ec-type-seminar{ --event-color:#7c3aed; }
#dcvsEventsCalendar .ec-week-event.ec-type-other{ --event-color:#64748b; }
#dcvsEventsCalendar .ec-event-dot{ width:7px; height:7px; flex:0 0 7px; border:1.5px solid var(--event-color); border-radius:50%; }
#dcvsEventsCalendar .ec-week-event[data-status="live"] .ec-event-dot{ background:var(--event-color); }
#dcvsEventsCalendar .ec-week-event-time{ flex:0 0 auto; color:#52525b; font-size:10px; }
#dcvsEventsCalendar .ec-week-event-name{ min-width:0; overflow:hidden; font-size:11px; font-weight:500; line-height:1; text-overflow:ellipsis; }
#dcvsEventsCalendar .ec-week-overflow{ position:absolute; z-index:3; padding:0 4px; border:0; background:transparent; color:#0e8131; font:inherit; font-size:11px; font-weight:600; white-space:nowrap; text-decoration:underline; cursor:pointer; }
#dcvsEventsCalendar .ec-day-popover{ position:fixed; z-index:1001; display:none; width:320px; max-width:calc(100vw - 16px); max-height:min(70vh,560px); flex-direction:column; overflow:hidden; border:1px solid #d4d4d8; border-radius:8px; background:#fff; box-shadow:0 12px 32px rgba(24,24,27,.2); color:#18181b; }
#dcvsEventsCalendar .ec-day-popover.open{ display:flex; }
#dcvsEventsCalendar .ec-day-popover-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:12px 14px; border-bottom:1px solid #e4e4e7; }
#dcvsEventsCalendar .ec-day-popover-date{ margin:0; font-size:14px; font-weight:700; }
#dcvsEventsCalendar .ec-day-popover-count{ margin:2px 0 0; color:#71717a; font-size:12px; }
#dcvsEventsCalendar .ec-day-popover-close{ display:inline-flex; width:30px; height:30px; flex:0 0 30px; align-items:center; justify-content:center; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#52525b; cursor:pointer; }
#dcvsEventsCalendar .ec-day-popover-close svg{ width:15px; height:15px; }
#dcvsEventsCalendar .ec-day-popover-list{ min-height:0; overflow-y:auto; padding:8px 12px 12px; }
#dcvsEventsCalendar .ec-day-popover-item{ padding:9px 10px; border:1px solid transparent; border-bottom-color:#e4e4e7; border-left:3px solid var(--event-color); border-radius:4px; }
#dcvsEventsCalendar .ec-day-popover-item.highlighted{ border-color:var(--event-color); background:color-mix(in srgb,var(--event-color) 8%,#fff); }
#dcvsEventsCalendar .ec-day-popover-item-head{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; }
#dcvsEventsCalendar .ec-day-popover-item-title{ min-width:0; margin:0; font-size:13px; font-weight:600; }
#dcvsEventsCalendar .ec-day-popover-status{ flex:0 0 auto; padding:2px 7px; border-radius:10px; background:#f4f4f5; color:#52525b; font-size:10px; font-weight:600; text-transform:capitalize; }
#dcvsEventsCalendar .ec-day-popover-status[data-status="live"]{ background:#dcfce7; color:#166534; }
#dcvsEventsCalendar .ec-day-popover-meta{ display:flex; align-items:flex-start; gap:6px; margin-top:4px; color:#52525b; font-size:12px; line-height:1.4; }
#dcvsEventsCalendar .ec-day-popover-meta svg{ width:14px; height:14px; flex:0 0 14px; margin-top:1px; color:#71717a; }
#dcvsEventsCalendar .ec-day-popover-empty{ margin:10px 2px; color:#71717a; font-size:13px; }
#dcvsEventsCalendar :focus-visible{ outline:2px solid #0e8131; outline-offset:2px; }
@media(max-width:1024px){
  #dcvsEventsCalendar{ margin:0; }
  #dcvsEventsCalendar .ec-header-inner{ padding:12px; }
  #dcvsEventsCalendar .ec-controls-row,
  #dcvsEventsCalendar .ec-controls-left,
  #dcvsEventsCalendar .ec-controls-right{ flex-wrap:wrap; }
  #dcvsEventsCalendar .ec-controls-row{ align-items:stretch; gap:10px; }
  #dcvsEventsCalendar .ec-controls-left,
  #dcvsEventsCalendar .ec-controls-right{ min-width:0; width:100%; gap:8px; }
  #dcvsEventsCalendar .ec-controls-right{ justify-content:flex-start; }
  #dcvsEventsCalendar .ec-search-wrap{ flex:1 1 100%; min-width:0; }
  #dcvsEventsCalendar .ec-search{ width:100%; max-width:100%; }
  #dcvsEventsCalendar .ec-dropdown{ min-width:0; max-width:100%; }
  #dcvsEventsCalendar .ec-btn{ max-width:100%; padding-inline:10px; }
  #dcvsEventsCalendar .ec-mode-btn{ white-space:normal; text-align:left; }
  #dcvsEventsCalendar .ec-month-nav{ width:auto; }
  #dcvsEventsCalendar .ec-weekday-row .ec-weekdays{ display:none; }
  #dcvsEventsCalendar .ec-main{ padding:14px 8px 24px; }
  #dcvsEventsCalendar .ec-month-row{ display:block; margin:0; }
  #dcvsEventsCalendar .ec-month-label{ position:static; width:auto; margin:0 0 10px; }
  #dcvsEventsCalendar .ec-grid{ display:block; overflow-x:auto; overscroll-behavior-x:contain; }
  #dcvsEventsCalendar .ec-week-row{ min-width:784px; grid-template-columns:repeat(7,minmax(112px,1fr)); }
  #dcvsEventsCalendar .ec-week-row .ec-day-num{ font-size:1.25rem; line-height:1.2; margin-bottom:6px; }
  #dcvsEventsCalendar .ec-week-row .ec-event{ padding:4px; font-size:10px; }
  #dcvsEventsCalendar .ec-week-row .ec-event-name{ font-size:10px; }
  #dcvsEventsCalendar .ec-week-row .ec-event-time,
  #dcvsEventsCalendar .ec-week-row .ec-event-loc{ font-size:9px; }
  #dcvsEventsCalendar .ec-day-popover{ inset:auto 0 0; width:100%; max-width:none; max-height:65vh; border-radius:12px 12px 0 0; }
  #dcvsEventsCalendar .ec-week-event{ gap:3px; padding:0 3px; }
  #dcvsEventsCalendar .ec-week-event-time{ font-size:9px; }
  #dcvsEventsCalendar .ec-week-event-name{ font-size:10px; }
}
@media(max-width:1024px){
  #dcvsEventsCalendar .ec-weekday-row{
    display:flex;
    flex-direction:column;
    gap:6px;
  }
  #dcvsEventsCalendar .ec-month-nav{
    justify-content:center;
    width:100%;
  }
  #dcvsEventsCalendar .ec-nav-label{ flex:0 0 auto; }
  #dcvsEventsCalendar .ec-weekday-row .ec-weekdays{
    display:grid;
    width:100%;
    grid-template-columns:repeat(7,minmax(0,1fr));
  }
  #dcvsEventsCalendar .ec-weekday{
    min-width:0;
    padding:5px 0;
    font-size:clamp(9px,1.2vw,12px);
    white-space:nowrap;
  }
  #dcvsEventsCalendar .ec-month-row{ display:block; }
  #dcvsEventsCalendar .ec-month-label{ margin-bottom:8px; }
  #dcvsEventsCalendar .ec-month-label h2{ font-size:24px; }
  #dcvsEventsCalendar .ec-grid{
    display:block;
    overflow-x:hidden;
  }
  #dcvsEventsCalendar .ec-week-row{
    min-width:0;
    grid-template-columns:repeat(7,minmax(0,1fr));
  }
  #dcvsEventsCalendar .ec-week-row .ec-day-num{
    font-size:clamp(16px,2vw,24px);
    line-height:1.2;
    margin-bottom:6px;
  }
}
@media(max-width:700px){
  #dcvsEventsCalendar .ec-weekday-full{ display:none; }
  #dcvsEventsCalendar .ec-weekday-short{ display:inline; }
  #dcvsEventsCalendar .ec-week-row .ec-day{
    min-width:0;
    min-height:78px;
    padding:5px 3px;
  }
  #dcvsEventsCalendar .ec-week-row .ec-day-num{
    font-size:1rem;
    line-height:1.2;
    margin-bottom:5px;
  }
  #dcvsEventsCalendar .ec-week-row .ec-events{
    flex-direction:row;
    flex-wrap:wrap;
    gap:3px;
  }
  #dcvsEventsCalendar .ec-week-row .ec-event{
    width:8px;
    height:8px;
    flex:0 0 8px;
    overflow:hidden;
    padding:0;
    border:0;
    border-radius:50%;
    background:var(--event-color,#64748b);
    font-size:0;
  }
  #dcvsEventsCalendar .ec-week-row .ec-event-card-head,
  #dcvsEventsCalendar .ec-week-row .ec-event-time,
  #dcvsEventsCalendar .ec-week-row .ec-event-loc{
    display:none;
  }
}
@media(max-width:640px){
  #ecEventDialog.modal{
    align-items:stretch;
    justify-content:stretch;
    padding:0;
  }
  #ecEventDialog .sd-dialog{
    width:100%;
    height:100%;
    max-width:none;
  }
  #ecEventDialog .sd-dialog-content{
    box-sizing:border-box;
    width:100%;
    max-width:100%;
    height:100dvh;
    max-height:100dvh;
    min-width:0;
    border:0;
    border-radius:0;
    padding:20px 16px calc(16px + env(safe-area-inset-bottom));
  }
}
@media(prefers-reduced-motion:reduce){
  #dcvsEventsCalendar .ec-week-event, #dcvsEventsCalendar .ec-day-popover{ transition:none; }
}
#dcvsEventsCalendar .ec-event-actions{
  position:absolute; top:4px; right:4px; display:flex; gap:4px;
  opacity:0; transition:opacity .15s;
}
#dcvsEventsCalendar .ec-event:hover .ec-event-actions{ opacity:1; }
#dcvsEventsCalendar .ec-event-action{
  display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px;
  padding:0; border:none; background:transparent; color:var(--ec-muted-fg); cursor:pointer; border-radius:4px;
}
#dcvsEventsCalendar .ec-event-action:hover{ background:var(--ec-bg); color:var(--ec-fg); }
#dcvsEventsCalendar .ec-event-action svg{ width:12px; height:12px; }
#dcvsEventsCalendar .ec-event-action.saved{ color:#ef4444; opacity:1; }

/* Dialogs */
#dcvsEventsCalendar .ec-overlay{
  position:fixed; inset:0; z-index:100; background:rgba(0,0,0,.5);
  display:flex; align-items:center; justify-content:center; padding:16px;
  opacity:0; visibility:hidden; transition:opacity .15s ease, visibility .15s ease;
}
#dcvsEventsCalendar .ec-overlay.open{ opacity:1; visibility:visible; }
#dcvsEventsCalendar .ec-dialog{
  position:relative; width:100%; max-width:550px; max-height:90vh; overflow-y:auto;
  background:var(--ec-bg); border:1px solid var(--ec-border); border-radius:var(--ec-radius);
  padding:24px; box-shadow:0 20px 40px rgba(0,0,0,.15);
  transform:scale(.95); opacity:0; transition:transform .15s ease, opacity .15s ease;
}
#dcvsEventsCalendar .ec-overlay.open .ec-dialog{ transform:scale(1); opacity:1; }
#dcvsEventsCalendar .ec-auth-dialog{ max-width:500px; }
#dcvsEventsCalendar .ec-dialog-close{
  position:absolute; top:12px; right:12px; width:28px; height:28px; border:none;
  background:transparent; color:var(--ec-muted-fg); font-size:18px; line-height:1; cursor:pointer;
  border-radius:6px; display:flex; align-items:center; justify-content:center;
}
#dcvsEventsCalendar .ec-dialog-close:hover{ background:var(--ec-accent); color:var(--ec-fg); }

#dcvsEventsCalendar .ec-dialog-title{ font-size:24px; font-weight:700; margin:0 0 8px; letter-spacing:-.02em; }
#dcvsEventsCalendar .ec-dialog-desc{ font-size:14px; color:var(--ec-muted-fg); margin:0 0 16px; }
#dcvsEventsCalendar .ec-badge{
  display:inline-block; font-size:12px; background:var(--ec-muted); padding:2px 8px; border-radius:4px; margin-top:4px;
}
#dcvsEventsCalendar .ec-type-badge{
  display:inline-block; font-size:12px; background:color-mix(in srgb, var(--ec-primary) 10%, transparent);
  color:var(--ec-primary); padding:4px 8px; border-radius:4px; text-transform:capitalize;
}
#dcvsEventsCalendar .ec-dialog-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
#dcvsEventsCalendar .ec-detail-label{ font-weight:500; margin:0; }
#dcvsEventsCalendar .ec-detail-sub{ color:var(--ec-muted-fg); margin:0; }
#dcvsEventsCalendar .ec-desc-box{ margin-top:16px; padding-top:16px; border-top:1px solid var(--ec-border); }
#dcvsEventsCalendar .ec-desc-box p{ font-size:14px; color:var(--ec-muted-fg); line-height:1.6; margin:0; }
#dcvsEventsCalendar .ec-dialog-footer{
  display:flex; flex-direction:column; gap:8px; margin-top:24px; padding-top:20px; border-top:1px solid var(--ec-border);
}
#dcvsEventsCalendar .ec-dialog-footer .ec-btn{ flex:1; background:transparent; }

/* Auth dialog form */
#dcvsEventsCalendar .ec-field{ margin-bottom:16px; }
#dcvsEventsCalendar .ec-label{ display:block; font-size:14px; font-weight:500; margin-bottom:6px; }
#dcvsEventsCalendar .ec-input{
  width:100%; height:36px; padding:0 12px; border-radius:6px; border:1px solid var(--ec-input);
  background:transparent; color:var(--ec-fg); font-family:inherit; font-size:14px; outline:none;
}
#dcvsEventsCalendar .ec-input:focus{ border-color:var(--ec-ring); box-shadow:0 0 0 3px var(--ec-ring); }
#dcvsEventsCalendar .ec-auth-switch{ text-align:center; font-size:14px; color:var(--ec-muted-fg); margin-top:12px; }
#dcvsEventsCalendar .ec-auth-switch button{ background:none; border:none; padding:0; color:inherit; text-decoration:underline; cursor:pointer; font-size:inherit; font-family:inherit; }
#dcvsEventsCalendar .ec-auth-switch button:hover{ color:var(--ec-fg); }
#dcvsEventsCalendar .ec-note{
  display:flex; align-items:flex-start; gap:8px; padding:12px; border-radius:6px;
  background:color-mix(in srgb, var(--ec-muted) 50%, transparent); font-size:14px; color:var(--ec-muted-fg);
}
#dcvsEventsCalendar .ec-note + .ec-note{ margin-top:8px; }
#dcvsEventsCalendar .ec-note svg{ width:16px; height:16px; flex-shrink:0; margin-top:2px; }
#dcvsEventsCalendar .ec-note p{ margin:0; }
#dcvsEventsCalendar .ec-auth-submit{ width:100%; margin-top:4px; }

/* Toast */
#dcvsEventsCalendar .ec-toast{
  position:fixed; bottom:24px; right:24px; z-index:200;
  background:var(--ec-fg); color:var(--ec-bg);
  padding:14px 16px; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.2);
  max-width:320px; transform:translateY(20px); opacity:0; pointer-events:none;
  transition:transform .25s ease, opacity .25s ease;
}
#dcvsEventsCalendar .ec-toast.show{ transform:translateY(0); opacity:1; }
#dcvsEventsCalendar .ec-toast-title{ font-weight:600; font-size:14px; margin-bottom:2px; }
#dcvsEventsCalendar .ec-toast-desc{ font-size:13px; opacity:.85; line-height:1.45; }

@keyframes ecFadeIn{ from{opacity:0} to{opacity:1} }

@media print{
  #dcvsEventsCalendar .ec-header{ position:static; }
  #dcvsEventsCalendar button, #dcvsEventsCalendar .ec-menu, #dcvsEventsCalendar .ec-overlay, #dcvsEventsCalendar .ec-toast{ display:none !important; }
}
</style>

<header class="ec-header">
  <div class="ec-header-inner">
    <div class="ec-controls-row">
      <div class="ec-controls-left">
        <div class="ec-search-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="text" id="ecSearch" class="ec-search" placeholder="Search events..." oninput="ecSetSearch(this.value)">
        </div>

        <div class="ec-dropdown">
          <button type="button" class="ec-btn" onclick="ecToggleMenu(this,'ecMonthMenu')">Choose a month<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
          <div class="ec-menu" id="ecMonthMenu"></div>
        </div>

        <div class="ec-dropdown">
          <button type="button" class="ec-btn" onclick="ecToggleMenu(this,'ecContinentMenu')"><span id="ecContinentLabel">Filter by Barangay</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
          <div class="ec-menu" id="ecContinentMenu"></div>
        </div>

        <div class="ec-dropdown">
          <button type="button" class="ec-btn" onclick="ecToggleMenu(this,'ecTypeMenu')"><span id="ecTypeLabel">Schedule Type</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
          <div class="ec-menu" id="ecTypeMenu"></div>
        </div>

      </div>

      <div class="ec-controls-right">
        <button type="button" class="ec-btn" id="ecMyEventsBtn" style="display:none;" onclick="ecToggleOnlySaved()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
          My Events (<span id="ecSavedCount">0</span>)
        </button>
        <div class="ec-dropdown">
          <button type="button" class="ec-btn ec-mode-btn" onclick="ecToggleMenu(this,'ecModeMenu')"><span id="ecModeLabel">Appointment Calendar</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
          <div class="ec-menu" id="ecModeMenu"></div>
        </div>
      </div>
    </div>

    <div class="ec-weekday-row">
      <div class="ec-month-nav">
        <button type="button" class="ec-nav-btn" onclick="ecPrevMonth()" aria-label="Previous month" title="Previous month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
        <span class="ec-nav-label" id="ecNavMonthLabel">2026</span>
        <button type="button" class="ec-nav-btn" onclick="ecNextMonth()" aria-label="Next month" title="Next month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
      </div>
      <div class="ec-weekdays">
        <div class="ec-weekday"><span class="ec-weekday-full">Monday</span><span class="ec-weekday-short">Mon</span></div>
        <div class="ec-weekday"><span class="ec-weekday-full">Tuesday</span><span class="ec-weekday-short">Tue</span></div>
        <div class="ec-weekday"><span class="ec-weekday-full">Wednesday</span><span class="ec-weekday-short">Wed</span></div>
        <div class="ec-weekday"><span class="ec-weekday-full">Thursday</span><span class="ec-weekday-short">Thu</span></div>
        <div class="ec-weekday"><span class="ec-weekday-full">Friday</span><span class="ec-weekday-short">Fri</span></div>
        <div class="ec-weekday"><span class="ec-weekday-full">Saturday</span><span class="ec-weekday-short">Sat</span></div>
        <div class="ec-weekday"><span class="ec-weekday-full">Sunday</span><span class="ec-weekday-short">Sun</span></div>
      </div>
    </div>
  </div>
</header>

<main class="ec-main" id="ecMonths"></main>

<div id="ecEventDialog" class="modal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('ecEventDialog')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div id="ecEventBody"></div>
    </div>
  </div>
</div>

<div id="ecAuthDialog" class="modal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('ecAuthDialog')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div id="ecAuthBody"></div>
    </div>
  </div>
</div>

<div id="ecConfirmDialog" class="modal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="ecConfirmTitle" aria-describedby="ecConfirmDesc" style="max-width:440px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('ecConfirmDialog')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header" style="text-align:left;align-items:flex-start;">
        <h2 class="sd-dialog-title" id="ecConfirmTitle">Confirm</h2>
        <p class="sd-dialog-description" id="ecConfirmDesc">Are you sure?</p>
      </div>
      <div id="ecConfirmBody"></div>
      <div class="sd-dialog-footer" style="display:flex;flex-direction:row;gap:8px;justify-content:flex-end;">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('ecConfirmDialog')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-default" id="ecConfirmOk">Confirm</button>
      </div>
    </div>
  </div>
</div>

<div id="ecReschedDialog" class="modal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:440px;">
      <button type="button" class="sd-dialog-close" onclick="closeModal('ecReschedDialog')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header" style="text-align:left;align-items:flex-start;">
        <h2 class="sd-dialog-title">Reschedule Appointment</h2>
        <p class="sd-dialog-description">Select a new date and time for this appointment.</p>
      </div>
      <div id="ecReschedBody"></div>
      <div class="sd-dialog-footer" style="display:flex;flex-direction:row;gap:8px;justify-content:flex-end;">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('ecReschedDialog')">Cancel</button>
        <button type="button" class="sd-btn sd-btn-default" id="ecReschedOk">Confirm Reschedule</button>
      </div>
    </div>
  </div>
</div>

<div class="ec-toast" id="ecToast"></div>

<script>
(function(){
'use strict';

var MONTHS = ["January","February","March","April","May","June","July","August","September","October","November","December"];
var CONTINENTS = ["Paliparan I","Paliparan II","Paliparan III","Sampaloc I","Sampaloc II","Sampaloc III","Sampaloc IV","Salawag","Burol","Burol I","Burol II","Burol III","San Jose","San Miguel","San Juan I","San Juan II","San Agustin I","San Agustin II","San Agustin III","San Isidro Labrador I","San Isidro Labrador II","San Andres I","San Andres II","Santa Cruz I","Santa Cruz II","Santa Fe I","Santa Fe II","Langkaan I","Langkaan II","Lumina I","Lumina II","Salitran I","Salitran II","Salitran III","Salitran IV","Magdalo","Maliksi I","Maliksi II","Maliksi III","Maunlad I","Maunlad II","Maunlad III","Fatima I","Fatima II","Fatima III","Pasong Camachile I","Pasong Camachile II","Pasong Kawayan I","Pasong Kawayan II","Sabang","Datu Esmael","Emmanuel Pelaez","H-95","San Lorenzo Ruiz I","San Lorenzo Ruiz II","Sto. Nino I","Sto. Nino II","Santo Cristo I","Santo Cristo II","Victoria I","Victoria II","Zone I (Pob.)","Zone II (Pob.)","Zone III (Pob.)","Zone IV (Pob.)"];
var EVENT_TYPES = ["kapon","vaccination","checkup","adoption"];
var PROGRAM_TYPES = ["kapon","vaccination","checkup","deworming","adoption","rabies_prevention","microchipping","other"];
var REPORT_TYPES = ["stray","rabies","injured","dead"];
var YEAR = <?php echo (int)$calYear; ?>;

var CAL_ANNOUNCEMENTS = <?php echo json_encode($calAnnouncements); ?>;
var CAL_PROGRAMS = <?php echo json_encode($calPrograms); ?>;
var CAL_REPORTS = <?php echo json_encode($calReports); ?>;

var SERVICE_FLAGS = { kapon:"\uD83D\uDC3E", vaccination:"\uD83D\uDC89", checkup:"\uD83E\uDE7A", adoption:"\uD83C\uDFE0" };
function calTime(t){
  if (!t) return '';
  var p = String(t).split(':');
  var h = parseInt(p[0],10)||0, m = parseInt(p[1],10)||0;
  var ap = h>=12?'PM':'AM'; h = h%12||12;
  return h + ':' + String(m).padStart(2,'0') + ' ' + ap;
}
var RAW_APPT = <?php echo json_encode($calAppointments); ?>;
var EVENTS = RAW_APPT.map(function(a){
  var d = new Date(String(a.preferred_date||'').replace(/-/g,'/')+' 00:00:00');
  var type = String(a.appointment_type||'checkup').toLowerCase();
  var pet = a.pet_name || 'Appointment Request';
  var brgy = a.barangay_name || '-';
  var st = String(a.status||'pending').replace(/_/g,' ');
  return {
    kind: 'appointment',
    name: pet,
    reference: a.reference_id || '',
    appointment_id: a.appointment_id || '',
    time: calTime(a.preferred_time),
    date: a.preferred_date || '',
    location: brgy,
    flag: SERVICE_FLAGS[type] || '',
    url: a.pet_id ? 'pet-profile.php?id='+a.pet_id : '',
    continent: brgy,
    month: MONTHS[d.getMonth()],
    startDay: d.getDate(),
    endDay: d.getDate(),
    eventType: type,
    status: st,
    description: a.notes || '',
    resident: a.full_name || '',
    contact: a.phone || '',
    address: a.address || '',
    petType: a.pet_type || '',
    breed: a.breed || '',
    petPhoto: a.pet_photo || '',
    petRef: a.pet_ref || '',
    requestedOn: a.created_at || '',
    venue: (a.full_name||'') + (a.address ? ', '+a.address : '')
  };
});
function calDateToTime(dt){
  if (!dt || isNaN(dt.getTime())) return '';
  var h = dt.getHours(), m = dt.getMinutes();
  var ap = h>=12?'PM':'AM'; h = h%12||12;
  return h + ':' + String(m).padStart(2,'0') + ' ' + ap;
}
var ANNOUNCEMENT_EVENTS = CAL_ANNOUNCEMENTS.filter(function(a){
  var date = a.is_event && a.event_date ? a.event_date : (a.publish_at || a.created_at);
  return new Date(String(date).replace(/-/g,'/')).getFullYear() === YEAR;
}).map(function(a){
  var date = a.is_event && a.event_date ? a.event_date : (a.publish_at || a.created_at);
  var dt = new Date(String(date).replace(/-/g,'/'));
  var endDate = a.is_event && a.event_end_date ? new Date(String(a.event_end_date).replace(/-/g,'/')) : dt;
  return {
    kind: 'announcement',
    name: a.title || 'Announcement',
    reference: '',
    time: a.is_event && a.event_start_time ? calTime(a.event_start_time) : calDateToTime(dt),
    location: a.venue_name || a.barangay_name || 'City-wide',
    flag: '',
    url: '',
    continent: a.priority || 'medium',
    month: MONTHS[dt.getMonth()],
    startDay: dt.getDate(),
    endMonth: MONTHS[endDate.getMonth()],
    endDay: endDate.getDate(),
    eventType: a.category || 'announcement',
    status: String(a.status||'published'),
    description: a.content || '',
    resident: '',
    contact: '',
    venue: '',
    category: a.category || '',
    priority: a.priority || '',
    publishAt: a.publish_at || '',
    eventDate: a.event_date || '',
    eventEndDate: a.event_end_date || '',
    updatedAt: a.updated_at || '',
    photoUrl: a.photo_url || '',
    announcementId: a.announcement_id || ''
  };
});
var PROGRAM_EVENTS = CAL_PROGRAMS.map(function(p){
  var start = new Date(String(p.start_date||'').replace(/-/g,'/')+' 00:00:00');
  var end = new Date(String(p.end_date||'').replace(/-/g,'/')+' 00:00:00');
  var location = p.location_mode === 'multiple' ? (p.barangay_names || p.venue || 'Multiple locations') : (p.venue || p.barangay_names || 'TBD');
  return {
    kind: 'program',
    name: p.program_name || 'Program',
    reference: 'Program #' + (p.program_id || ''),
    programId: p.program_id || '',
    time: p.operating_time || '',
    date: p.start_date || '',
    location: location,
    flag: '',
    url: 'dashboard-staff.php#analytics',
    continent: p.location_mode === 'multiple' ? '' : (p.barangay_names || ''),
    month: MONTHS[start.getMonth()],
    startDay: start.getDate(),
    endMonth: MONTHS[end.getMonth()],
    endDay: end.getDate(),
    eventType: p.program_type || 'other',
    status: p.status || 'scheduled',
    description: p.description || '',
    announcementId: p.announcement_id || '',
    venue: p.venue || '',
    barangays: p.barangay_names || '',
    programStart: p.start_date || '',
    programEnd: p.end_date || ''
  };
});
var REPORT_EVENTS = CAL_REPORTS.map(function(r){
  var dt = new Date(String(r.incident_at||r.created_at||'').replace(/-/g,'/'));
  var type = String(r.report_type||'report').toLowerCase();
  var barangay = r.barangay_name || '';
  return {
    kind: 'report',
    name: (r.reference_id ? r.reference_id + ' · ' : '') + cap(type) + ' Report',
    reference: r.reference_id || '',
    reportId: r.report_id || '',
    date: r.incident_at || r.created_at || '',
    submittedAt: r.created_at || '',
    time: calDateToTime(dt),
    location: barangay || r.location_address || 'Location not specified',
    address: r.location_address || '',
    continent: barangay,
    month: MONTHS[dt.getMonth()],
    startDay: dt.getDate(),
    endDay: dt.getDate(),
    eventType: type,
    status: String(r.status || 'pending'),
    urgency: String(r.urgency_level || 'medium'),
    description: r.description || '',
    resident: r.reporter_name || '',
    url: 'dashboard-staff.php#reports-pending'
  };
});
function getEvents(){
  if (state.mode === 'schedule') return EVENTS.concat(PROGRAM_EVENTS, REPORT_EVENTS);
  if (state.mode === 'event') return ANNOUNCEMENT_EVENTS;
  if (state.mode === 'program') return PROGRAM_EVENTS;
  if (state.mode === 'report') return REPORT_EVENTS;
  return EVENTS;
}
window.addEventListener('dcvs:calendar-appointments-updated', function(event){
  var detail = event.detail || {};
  var ids = (detail.ids || []).map(String);
  var closedStatuses = ['completed','cancelled','rejected','no_show'];
  EVENTS = EVENTS.filter(function(ev){
    if (ids.indexOf(String(ev.appointment_id)) === -1) return true;
    if (closedStatuses.indexOf(String(detail.status || '').toLowerCase()) !== -1) return false;
    ev.status = detail.status || ev.status;
    return true;
  });
  ecRender();
});
window.addEventListener('dcvs:calendar-appointments-rescheduled', function(event){
  var changes = (event.detail || {}).changes || [];
  changes.forEach(function(change){
    var ev = EVENTS.filter(function(item){ return String(item.appointment_id) === String(change.id); })[0];
    if (!ev) return;
    var parts = String(change.date || '').split('-');
    if (parts.length !== 3 || parseInt(parts[0], 10) !== YEAR) {
      EVENTS = EVENTS.filter(function(item){ return item !== ev; });
      return;
    }
    var monthIndex = parseInt(parts[1], 10) - 1;
    ev.date = change.date;
    ev.month = MONTHS[monthIndex];
    ev.startDay = parseInt(parts[2], 10);
    ev.endDay = ev.startDay;
    ev.time = calTime(change.time);
    ev.status = 'rescheduled';
  });
  ecRender();
});
var state = { mode:'appointment', continent:null, type:null, search:'', loggedIn:false, userEmail:'', saved:[], onlySaved:false, selected:null, authMode:'signin', month:MONTHS[(new Date()).getMonth()] };

function $(id){ return document.getElementById(id); }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function eventId(ev){ return (ev.programId ? 'program-' + ev.programId : ev.reportId ? 'report-' + ev.reportId : ev.appointment_id ? 'appointment-' + ev.appointment_id : ev.announcementId ? 'announcement-' + ev.announcementId : ev.month+'-'+ev.name+'-'+ev.startDay); }
function isSaved(ev){ return state.saved.indexOf(eventId(ev)) !== -1; }
function cap(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : s; }
function formatProgramDate(ev){
  var start = new Date(String(ev.programStart||'').replace(/-/g,'/')+' 00:00:00');
  var end = new Date(String(ev.programEnd||'').replace(/-/g,'/')+' 00:00:00');
  var options = { month:'short', day:'numeric', year:'numeric' };
  if (isNaN(start.getTime())) return ev.programStart || '-';
  if (isNaN(end.getTime()) || ev.programStart === ev.programEnd) return start.toLocaleDateString('en-US', options);
  return start.toLocaleDateString('en-US', options) + ' - ' + end.toLocaleDateString('en-US', options);
}
function eventOccursOn(ev, month, day){
  if (ev.programStart) {
    var cellDate = new Date(YEAR, MONTHS.indexOf(month), day);
    var startDate = new Date(String(ev.programStart).replace(/-/g,'/')+' 00:00:00');
    var endDate = new Date(String(ev.programEnd || ev.programStart).replace(/-/g,'/')+' 00:00:00');
    return cellDate >= startDate && cellDate <= endDate;
  }
  return ev.month === month && day >= ev.startDay && day <= ev.endDay;
}

function filteredEvents(){
  var q = state.search.toLowerCase();
  return getEvents().filter(function(ev){
    if (state.continent) {
      var eventLocations = ev.kind === 'program' ? (ev.barangays || '').split(', ') : [ev.continent || ''];
      if (state.mode === 'event' ? ev.continent !== state.continent : eventLocations.indexOf(state.continent) === -1) return false;
    }
    if (state.type && (state.mode === 'schedule' ? ev.kind !== state.type : ev.eventType !== state.type)) return false;
    if (q && ev.name.toLowerCase().indexOf(q) === -1 && ev.location.toLowerCase().indexOf(q) === -1 && (ev.resident||'').toLowerCase().indexOf(q) === -1 && (ev.status||'').toLowerCase().indexOf(q) === -1) return false;
    if (state.onlySaved && !isSaved(ev)) return false;
    return true;
  });
}

window.ecSetSearch = function(v){ state.search = v; ecRender(); };

window.ecToggleMenu = function(btn, menuId){
  var all = document.querySelectorAll('#dcvsEventsCalendar .ec-menu');
  all.forEach(function(m){ if (m.id !== menuId) m.classList.remove('open'); });
  var menu = $(menuId);
  var wasOpen = menu.classList.contains('open');
  all.forEach(function(m){ m.classList.remove('open'); });
  if (!wasOpen) menu.classList.add('open');
};

function closeMenus(){
  document.querySelectorAll('#dcvsEventsCalendar .ec-menu').forEach(function(m){ m.classList.remove('open'); });
}
document.addEventListener('click', function(e){
  if (!e.target.closest('#dcvsEventsCalendar .ec-dropdown')) closeMenus();
});

function buildMonthMenu(){
  var menu = $('ecMonthMenu');
  menu.innerHTML = MONTHS.map(function(m){
    return '<button type="button" class="ec-menu-item" onclick="ecGoToMonth(\''+m+'\')">'+m+'</button>';
  }).join('');
}
window.ecGoToMonth = function(month){
  state.month = month;
  closeMenus();
  ecRender();
};
function buildModeMenu(){
  var menu = $('ecModeMenu');
  if (!menu) return;
  menu.innerHTML =
    '<button type="button" class="ec-menu-item'+(state.mode==='appointment'?' active':'')+'" onclick="ecSetMode(\'appointment\')">Appointment Calendar</button>'+
    '<button type="button" class="ec-menu-item'+(state.mode==='event'?' active':'')+'" onclick="ecSetMode(\'event\')">Event Calendar</button>'+
    '<button type="button" class="ec-menu-item'+(state.mode==='program'?' active':'')+'" onclick="ecSetMode(\'program\')">Programs &amp; Services</button>'+
    '<button type="button" class="ec-menu-item'+(state.mode==='report'?' active':'')+'" onclick="ecSetMode(\'report\')">Reports Calendar</button>';
}
window.ecSetMode = function(mode){
  state.mode = mode;
  state.continent = null;
  state.type = null;
  closeMenus();
  $('ecModeLabel').textContent = mode === 'schedule' ? 'All Schedule' : (mode === 'event' ? 'Event Calendar' : (mode === 'program' ? 'Programs & Services' : (mode === 'report' ? 'Reports Calendar' : 'Appointment Calendar')));
  $('ecContinentLabel').textContent = mode === 'event' ? 'Filter by Priority' : 'Filter by Barangay';
  $('ecTypeLabel').textContent = mode === 'event' ? 'Category' : (mode === 'program' ? 'Program Type' : (mode === 'report' ? 'Report Type' : (mode === 'schedule' ? 'Schedule Type' : 'Service Type')));
  ecRender();
};
window.ecPrevMonth = function(){
  var idx = MONTHS.indexOf(state.month) - 1;
  state.month = MONTHS[(idx < 0 ? 11 : idx)];
  ecRender();
};
window.ecNextMonth = function(){
  var idx = MONTHS.indexOf(state.month) + 1;
  state.month = MONTHS[(idx > 11 ? 0 : idx)];
  ecRender();
};

function buildContinentMenu(){
  var menu = $('ecContinentMenu');
  var html;
  if (state.mode === 'event'){
    html = '<button type="button" class="ec-menu-item'+(state.continent===null?' active':'')+'" onclick="ecSetContinent(null)">All Priorities</button>';
    html += ['low','medium','high'].map(function(p){
      return '<button type="button" class="ec-menu-item'+(state.continent===p?' active':'')+'" onclick="ecSetContinent(\''+p+'\')">'+cap(p)+'</button>';
    }).join('');
  } else {
    html = '<button type="button" class="ec-menu-item'+(state.continent===null?' active':'')+'" onclick="ecSetContinent(null)">All Locations</button>';
    html += CONTINENTS.map(function(c){
      return '<button type="button" class="ec-menu-item'+(state.continent===c?' active':'')+'" onclick="ecSetContinent(\''+c+'\')">'+c+'</button>';
    }).join('');
  }
  menu.innerHTML = html;
}
window.ecSetContinent = function(c){ state.continent = c; closeMenus(); $('ecContinentLabel').textContent = c ? cap(c) : (state.mode === 'event' ? 'Filter by Priority' : 'Filter by Barangay'); buildContinentMenu(); ecRender(); };

function buildTypeMenu(){
  var menu = $('ecTypeMenu');
  var html;
  if (state.mode === 'event'){
    html = '<button type="button" class="ec-menu-item'+(state.type===null?' active':'')+'" onclick="ecSetType(null)">All Categories</button>';
    html += ['advisory','announcement'].map(function(c){
      return '<button type="button" class="ec-menu-item'+(state.type===c?' active':'')+'" onclick="ecSetType(\''+c+'\')">'+cap(c)+'</button>';
    }).join('');
  } else if (state.mode === 'program' || state.mode === 'report') {
    html = '<button type="button" class="ec-menu-item'+(state.type===null?' active':'')+'" onclick="ecSetType(null)">All Program Types</button>';
    html = state.mode === 'report'
      ? '<button type="button" class="ec-menu-item'+(state.type===null?' active':'')+'" onclick="ecSetType(null)">All Report Types</button>'
      : html;
    html += (state.mode === 'report' ? REPORT_TYPES : PROGRAM_TYPES).map(function(t){
      return '<button type="button" class="ec-menu-item'+(state.type===t?' active':'')+'" onclick="ecSetType(\''+t+'\')">'+cap(t.replace(/_/g,' '))+'</button>';
    }).join('');
  } else if (state.mode === 'schedule') {
    html = '<button type="button" class="ec-menu-item'+(state.type===null?' active':'')+'" onclick="ecSetType(null)">All Schedule Types</button>';
    html += [['appointment','Appointments'],['program','Programs & Services'],['report','Reports']].map(function(item){
      return '<button type="button" class="ec-menu-item'+(state.type===item[0]?' active':'')+'" onclick="ecSetType(\''+item[0]+'\')">'+item[1]+'</button>';
    }).join('');
  } else {
    html = '<button type="button" class="ec-menu-item'+(state.type===null?' active':'')+'" onclick="ecSetType(null)">All Services</button>';
    html += EVENT_TYPES.map(function(t){
      return '<button type="button" class="ec-menu-item'+(state.type===t?' active':'')+'" onclick="ecSetType(\''+t+'\')">'+cap(t)+'</button>';
    }).join('');
  }
  menu.innerHTML = html;
}
window.ecSetType = function(t){ state.type = t; closeMenus(); $('ecTypeLabel').textContent = t ? cap(t.replace(/_/g,' ')) : (state.mode === 'event' ? 'Category' : (state.mode === 'program' ? 'Program Type' : (state.mode === 'report' ? 'Report Type' : (state.mode === 'schedule' ? 'Schedule Type' : 'Service Type')))); buildTypeMenu(); ecRender(); };

function buildUserMenu(){
  var menu = $('ecUserMenu');
  if (!menu) return;
  var label = $('ecUserLabel');
  if (state.loggedIn){
    menu.innerHTML = '<button type="button" class="ec-menu-item" onclick="ecSignOut()">Sign Out</button>';
    if (label) label.innerHTML = '<svg style="width:16px;height:16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> '+esc(state.userEmail);
  } else {
    menu.innerHTML = '';
    if (label) label.innerHTML = '<svg style="width:16px;height:16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Sign In';
  }
}

function formatDateRange(ev){
  var mIdx = MONTHS.indexOf(ev.month);
  var start = new Date(YEAR, mIdx, ev.startDay);
  var end = new Date(YEAR, mIdx, ev.endDay);
  var opt = { weekday:'short', month:'short', day:'numeric' };
  var f = function(d){ return d.toLocaleDateString('en-US', opt); };
  if (ev.startDay === ev.endDay) return f(start);
  return f(start) + ' - ' + f(end);
}

function buildMonthGrid(month, events){
  var mIdx = MONTHS.indexOf(month);
  var firstDay = new Date(YEAR, mIdx, 1);
  var lastDay = new Date(YEAR, mIdx+1, 0);
  var daysInMonth = lastDay.getDate();
  var startDay = firstDay.getDay();
  startDay = startDay === 0 ? 6 : startDay - 1;
  var cells = [];
  for (var i=0;i<startDay;i++) cells.push({date:null, events:[]});
  for (var d=1;d<=daysInMonth;d++){
    var dayEvents = events.filter(function(ev){ return eventOccursOn(ev, month, d); });
    cells.push({date:d, events:dayEvents});
  }
  var rem = (7 - (cells.length % 7)) % 7;
  for (var k=0;k<rem;k++) cells.push({date:null, events:[]});
  return cells;
}

var today = new Date();
var currentMonth = MONTHS[today.getMonth()];
var currentDay = today.getDate();

function dateIso(date){ return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0'); }
function isoDate(value){ return new Date(String(value).slice(0,10)+'T00:00:00'); }
function dateOffset(start,end){ return Math.round((isoDate(end)-isoDate(start))/86400000); }
function eventBounds(ev){
  var startMonth = MONTHS.indexOf(ev.month);
  var endMonth = MONTHS.indexOf(ev.endMonth || ev.month);
  var start = ev.programStart || (YEAR+'-'+String(startMonth+1).padStart(2,'0')+'-'+String(ev.startDay).padStart(2,'0'));
  var end = ev.programEnd || (YEAR+'-'+String(endMonth+1).padStart(2,'0')+'-'+String(ev.endDay).padStart(2,'0'));
  return {start:String(start).slice(0,10),end:String(end).slice(0,10)};
}
function eventTypeClass(ev){
  var name = String(ev.name||'').toLowerCase();
  var type = String(ev.eventType||'').toLowerCase();
  if (name.indexOf('checkup') !== -1 || name.indexOf('check-up') !== -1 || name.indexOf('check up') !== -1) return 'ec-type-checkup';
  if (name.indexOf('rabies') !== -1) return 'ec-type-rabies';
  if (name.indexOf('seminar') !== -1 || name.indexOf('talk') !== -1) return 'ec-type-seminar';
  if (name.indexOf('kapon') !== -1 || type.indexOf('kapon') !== -1) return 'ec-type-kapon';
  if (type.indexOf('checkup') !== -1 || type.indexOf('check-up') !== -1 || type.indexOf('check up') !== -1) return 'ec-type-checkup';
  if (type.indexOf('rabies') !== -1 || type.indexOf('vaccin') !== -1) return 'ec-type-rabies';
  if (type.indexOf('seminar') !== -1 || type.indexOf('talk') !== -1) return 'ec-type-seminar';
  return 'ec-type-other';
}
function statusDot(ev){
  var status = String(ev.status||'').toLowerCase();
  return ['live','published','active','confirmed'].indexOf(status) !== -1 ? 'live' : 'scheduled';
}
function dayLabel(value){ return isoDate(value).toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}).replace(/,/g,''); }
function eventDateRange(ev){
  var bounds = eventBounds(ev);
  var opts = {month:'short',day:'numeric',year:'numeric'};
  var start = isoDate(bounds.start).toLocaleDateString('en-US',opts);
  if (bounds.start === bounds.end) return start;
  return start+' - '+isoDate(bounds.end).toLocaleDateString('en-US',opts);
}
function eventTooltip(ev){ return [ev.name,ev.time,ev.location,eventDateRange(ev)].filter(Boolean).join(' | '); }
function eventCategoryLabel(ev){
  if(ev.kind==='appointment') return 'Appointment';
  if(ev.kind==='report') return 'Report';
  if(ev.kind==='program') return 'Program';
  if(ev.kind==='announcement') return 'Event';
  return 'Schedule';
}
function eventPopoverTitle(ev){
  if(ev.kind==='report'){
    var reportLabels = {stray:'Stray Animal',rabies:'Rabies Case',injured:'Injured Animal',dead:'Dead Animal'};
    return 'Report - '+(reportLabels[String(ev.eventType||'').toLowerCase()]||cap(String(ev.eventType||'Report').replace(/_/g,' ')));
  }
  if(ev.kind==='appointment') return 'Appointment - '+cap(String(ev.eventType||'Appointment').replace(/_/g,' '))+ (ev.name ? ' · '+ev.name : '');
  return ev.name || eventCategoryLabel(ev);
}
function eventBlock(ev, date){
  var id = eventId(ev);
  var typeClass = eventTypeClass(ev);
  var status = statusDot(ev);
  var statusClass = 'ec-st-'+status;
  return '<button type="button" class="ec-event ec-calendar-trigger '+typeClass+'" data-event-id="'+esc(id)+'" data-date="'+date+'" data-status="'+status+'" title="'+esc(eventTooltip(ev))+'" aria-label="'+esc(ev.name+', '+eventDateRange(ev)+'. Open this day’s schedules.')+'">'+
    '<div class="ec-event-card-head"><div class="ec-event-name">'+esc(eventCategoryLabel(ev))+'</div><span class="ec-event-edition '+statusClass+'">'+esc(status)+'</span></div></button>';
}

var dayPopover = null;
var dayPopoverOpener = null;
var highlightedEventId = '';
var eventLaneMedia = window.matchMedia('(max-width:700px)');
function dayPopoverMarkup(ev){
  var bounds = eventBounds(ev);
  var typeClass = eventTypeClass(ev);
  var status = statusDot(ev);
  var range = bounds.start !== bounds.end ? '<div class="ec-day-popover-meta"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><span>'+esc(eventDateRange(ev))+'</span></div>' : '';
  var color = typeClass === 'ec-type-kapon' ? '#15803d' : typeClass === 'ec-type-checkup' ? '#2563eb' : typeClass === 'ec-type-rabies' ? '#d97706' : typeClass === 'ec-type-seminar' ? '#7c3aed' : '#64748b';
  return '<article class="ec-day-popover-item'+(eventId(ev)===highlightedEventId?' highlighted':'')+'" style="--event-color:'+color+'">'+
    '<div class="ec-day-popover-item-head"><h3 class="ec-day-popover-item-title">'+esc(eventPopoverTitle(ev))+'</h3><span class="ec-day-popover-status" data-status="'+status+'">'+esc(ev.status||'scheduled')+'</span></div>'+
    '<div class="ec-day-popover-meta"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>'+esc(ev.time||'Time not specified')+'</span></div>'+
    '<div class="ec-day-popover-meta"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>'+esc(ev.location||'Location not specified')+'</span></div>'+range+'</article>';
}
function ensureDayPopover(){
  if (dayPopover) return;
  dayPopover = document.createElement('div');
  dayPopover.className = 'ec-day-popover';
  dayPopover.setAttribute('role','dialog');
  dayPopover.setAttribute('aria-modal','false');
  dayPopover.setAttribute('aria-hidden','true');
  dayPopover.setAttribute('aria-label','Schedules for selected day');
  dayPopover.innerHTML = '<div class="ec-day-popover-head"><div><h2 class="ec-day-popover-date"></h2><p class="ec-day-popover-count"></p></div><button type="button" class="ec-day-popover-close" aria-label="Close schedules"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 6-12 12M6 6l12 12"/></svg></button></div><div class="ec-day-popover-list"></div>';
  document.getElementById('dcvsEventsCalendar').appendChild(dayPopover);
  dayPopover.querySelector('.ec-day-popover-close').addEventListener('click',function(){ closeDayPopover(true); });
}
function positionDayPopover(anchor){
  if (!dayPopover || eventLaneMedia.matches) return;
  var rect = anchor.getBoundingClientRect();
  var width = Math.min(320,window.innerWidth-16);
  dayPopover.style.width = width+'px';
  dayPopover.style.left = Math.max(8,Math.min(rect.left,window.innerWidth-width-8))+'px';
  dayPopover.style.top = '8px';
  var height = dayPopover.getBoundingClientRect().height;
  var top = rect.bottom+8;
  if (top+height > window.innerHeight-8) top = rect.top-height-8;
  dayPopover.style.top = Math.max(8,Math.min(top,window.innerHeight-height-8))+'px';
}
function closeDayPopover(restoreFocus){
  if (!dayPopover || !dayPopover.classList.contains('open')) return;
  dayPopover.classList.remove('open');
  dayPopover.setAttribute('aria-hidden','true');
  dayPopover.style.removeProperty('top');
  dayPopover.style.removeProperty('left');
  dayPopover.style.removeProperty('width');
  var opener = dayPopoverOpener;
  dayPopoverOpener = null;
  if (restoreFocus !== false && opener && opener.isConnected) opener.focus({preventScroll:true});
}
window.ecOpenDay = function(date, opener, highlightedId){
  ensureDayPopover();
  var events = filteredEvents().filter(function(ev){ var bounds=eventBounds(ev); return bounds.start<=date && bounds.end>=date; });
  events.sort(function(a,b){ var aa=eventBounds(a),bb=eventBounds(b); if(aa.start!==bb.start)return aa.start.localeCompare(bb.start); var duration=dateOffset(bb.start,bb.end)-dateOffset(aa.start,aa.end); return duration||a.name.localeCompare(b.name); });
  dayPopoverOpener = opener || document.activeElement;
  highlightedEventId = highlightedId || '';
  dayPopover.querySelector('.ec-day-popover-date').textContent = isoDate(date).toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
  dayPopover.querySelector('.ec-day-popover-count').textContent = events.length+' schedule'+(events.length===1?'':'s');
  dayPopover.querySelector('.ec-day-popover-list').innerHTML = events.length ? events.map(dayPopoverMarkup).join('') : '<p class="ec-day-popover-empty">No schedules for this day.</p>';
  dayPopover.classList.add('open');
  dayPopover.setAttribute('aria-hidden','false');
  positionDayPopover(dayPopoverOpener);
  dayPopover.querySelector('.ec-day-popover-close').focus({preventScroll:true});
};
document.addEventListener('click',function(event){
  if (!dayPopover || !dayPopover.classList.contains('open')) return;
  if (dayPopover.contains(event.target) || event.target.closest('#ecMonths .ec-calendar-trigger')) return;
  closeDayPopover(true);
});
document.addEventListener('keydown',function(event){ if(event.key==='Escape' && dayPopover && dayPopover.classList.contains('open')){ event.preventDefault(); closeDayPopover(true); } });
window.addEventListener('resize',function(){ if(dayPopover && dayPopover.classList.contains('open')) positionDayPopover(dayPopoverOpener); });
if (eventLaneMedia.addEventListener) eventLaneMedia.addEventListener('change',function(){ closeDayPopover(false); ecRender(); });
else if (eventLaneMedia.addListener) eventLaneMedia.addListener(function(){ closeDayPopover(false); ecRender(); });

function weekMarkup(weekCells, capacityEvents, month){
  var visibleLimit = 3;
  var cells = weekCells.map(function(cell,column){
    if(cell.date===null) return '<div class="ec-day"></div>';
    var date = dateIso(new Date(YEAR,MONTHS.indexOf(month),cell.date));
    var isToday = month===currentMonth && cell.date===currentDay;
    var activeStatuses = ['pending','approved','scheduled','rescheduled','in progress','completed','no show'];
    var apptCount = capacityEvents.filter(function(ev){return ev.kind==='appointment'&&eventOccursOn(ev,month,cell.date)&&activeStatuses.indexOf(ev.status)!==-1;}).length;
    var isFull = apptCount>=3;
    var dayEvents = cell.events||[];
    var evHtml = dayEvents.slice(0,visibleLimit).map(function(ev){return eventBlock(ev,date);}).join('');
    if(dayEvents.length>visibleLimit) evHtml += '<button type="button" class="ec-cell-more ec-calendar-trigger" data-date="'+date+'" aria-label="'+(dayEvents.length-visibleLimit)+' more events on '+dayLabel(date)+'">+'+(dayEvents.length-visibleLimit)+' more</button>';
    return '<div class="ec-day'+(isToday?' today':'')+(isFull?' ec-day-capacity-full':'')+'"><button type="button" class="ec-day-num ec-calendar-trigger" data-date="'+date+'" aria-label="Open schedules for '+dayLabel(date)+'">'+cell.date+'</button><div class="ec-events">'+evHtml+'</div></div>';
  }).join('');
  return '<div class="ec-week-row">'+cells+'</div>';
}

window.ecRender = function(){
  buildModeMenu();
  buildMonthMenu();
  buildContinentMenu();
  buildTypeMenu();
  buildUserMenu();

  var month = state.month;
  var events = filteredEvents();
  var capacityEvents = EVENTS.concat(PROGRAM_EVENTS, REPORT_EVENTS);
  var cells = buildMonthGrid(month, events);
  var weekHtml = '';
  var firstDay = new Date(YEAR,MONTHS.indexOf(month),1);
  var mondayOffset = (firstDay.getDay()+6)%7;
  for(var week=0;week<cells.length/7;week++){
    var weekCells = cells.slice(week*7,week*7+7);
    var weekStart = new Date(YEAR,MONTHS.indexOf(month),1-mondayOffset+week*7);
    weekHtml += weekMarkup(weekCells,capacityEvents,month);
  }
  $('ecMonths').innerHTML =
    '<div class="ec-month-row">'+
      '<div class="ec-month-label"><h2>'+month+'</h2></div>'+
      '<div class="ec-grid">'+weekHtml+'</div>'+
    '</div>';

  $('ecMonths').querySelectorAll('.ec-calendar-trigger').forEach(function(trigger){
    trigger.addEventListener('click',function(event){
      var date = trigger.getAttribute('data-date');
      var highlight = trigger.getAttribute('data-event-id')||'';
      ecOpenDay(date,trigger,highlight);
    });
  });

  var navLabel = $('ecNavMonthLabel');
  if (navLabel) navLabel.textContent = month + ' ' + YEAR;

  var myEventsBtn = $('ecMyEventsBtn');
  var savedCount = $('ecSavedCount');
  if (state.loggedIn){
    myEventsBtn.style.display = '';
    savedCount.textContent = state.saved.length;
    if (state.onlySaved) myEventsBtn.classList.add('ec-btn-default');
    else myEventsBtn.classList.remove('ec-btn-default');
  } else {
    myEventsBtn.style.display = 'none';
  }
};

window.ecCreateAnnouncement = function(programId){
  closeModal('ecEventDialog');
  window.location.href = 'staff-announcements.php?create_from_program=' + encodeURIComponent(programId);
};

window.ecOpenEvent = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev) return;
  state.selected = ev;
  var saved = isSaved(ev);
  var stCls = 'ec-st-' + String(ev.status||'pending').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  var body = $('ecEventBody');
  if (ev.programId){
    var programStatus = ev.status || 'scheduled';
    var programStatusColors = { scheduled:['#dbeafe','#1e40af','#bfdbfe'], live:['#dcfce7','#166534','#bbf7d0'], paused:['#FEF3C7','#92400e','#FDE68A'], completed:['#f1f5f9','#475569','#cbd5e1'] };
    var programSc = programStatusColors[programStatus] || programStatusColors.scheduled;
    var programStatusLabel = cap(programStatus);
    body.innerHTML =
      '<div class="sd-dialog-header">'+
        '<h2 class="sd-dialog-title">Program Details</h2>'+
        '<p class="sd-dialog-description">Review the scheduled Programs &amp; Services information.</p>'+
      '</div>'+
      '<div class="ec-info-grid">'+
          '<div class="ec-field-row"><span class="ec-field-label">Program / Service</span><span class="ec-field-value">'+esc(ev.name)+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Date</span><span class="ec-field-value">'+esc(formatProgramDate(ev))+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Time</span><span class="ec-field-value">'+esc(ev.time || '-')+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Status</span><span class="ec-field-value"><span class="ec-status-pill '+stCls+'" style="background:'+programSc[0]+';color:'+programSc[1]+';border-color:'+programSc[2]+';">'+esc(programStatusLabel)+'</span></span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Barangay / Location</span><span class="ec-field-value">'+esc(ev.location || '-')+'</span></div>'+
        (ev.description ? '<div class="ec-notes-section"><div class="ec-sec-title">Description / Notes</div><div class="ec-notes-box">'+esc(ev.description)+'</div></div>' : '')+
      '</div>'+
      '<div class="sd-dialog-footer">'+
        (ev.announcementId
          ? '<a class="sd-btn sd-btn-default" href="staff-announcements.php?view_announcement='+encodeURIComponent(ev.announcementId)+'">View announcement</a>'
          : (programStatus === 'scheduled' || programStatus === 'live'
            ? '<button type="button" class="sd-btn sd-btn-default" onclick="ecCreateAnnouncement('+Number(ev.programId)+')">Create Announcement</button>'
            : ''))+
        '<a class="sd-btn sd-btn-outline" href="dashboard-staff.php#analytics" target="_blank" rel="noopener noreferrer">View Program</a>'+
        '<button type="button" class="sd-btn sd-btn-outline" onclick="closeModal(\'ecEventDialog\')">Close</button>'+
      '</div>';
  } else if (ev.announcementId){
    var evStatus = ev.status || 'published';
    var evStatusColors = { published:['#dcfce7','#166534','#bbf7d0'], scheduled:['#FEF3C7','#92400e','#FDE68A'] };
    var evSc = evStatusColors[evStatus] || evStatusColors.published;
    var evStatusLabel = evStatus === 'scheduled' ? 'Draft' : evStatus.charAt(0).toUpperCase() + evStatus.slice(1);
    body.innerHTML =
      '<div class="sd-dialog-header">'+
        '<h2 class="sd-dialog-title">Event Details</h2>'+
        '<p class="sd-dialog-description">Review the event information, schedule, and publication details.</p>'+
      '</div>'+
      '<div class="ec-info-grid">'+
          '<div class="ec-field-row"><span class="ec-field-label">Event</span><span class="ec-field-value">'+esc(ev.name)+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Date</span><span class="ec-field-value">'+esc(formatDateRange(ev))+', '+YEAR+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Status</span><span class="ec-field-value"><span class="ec-status-pill '+stCls+'" style="background:'+evSc[0]+';color:'+evSc[1]+';border-color:'+evSc[2]+';">'+esc(evStatusLabel)+'</span></span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Category</span><span class="ec-field-value">'+esc(cap(ev.category||'-'))+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Time</span><span class="ec-field-value">'+esc(ev.time||'-')+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Published</span><span class="ec-field-value">'+esc(ev.publishAt ? new Date(String(ev.publishAt).replace(/-/g,'/')).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' \u00b7 '+calDateToTime(new Date(String(ev.publishAt).replace(/-/g,'/'))) : '—')+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Last updated</span><span class="ec-field-value">'+esc(ev.updatedAt ? new Date(String(ev.updatedAt).replace(/-/g,'/')).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' \u00b7 '+calDateToTime(new Date(String(ev.updatedAt).replace(/-/g,'/'))) : '—')+'</span></div>'+
        (ev.description ? '<div class="ec-notes-section"><div class="ec-sec-title">Description</div><div class="ec-notes-box">'+esc(ev.description)+'</div></div>' : '')+
        (ev.photoUrl ? '<div class="ec-notes-section"><div class="ec-sec-title">Attachment</div><div style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;"><img src="'+esc(ev.photoUrl)+'" alt="" style="width:100%;max-height:180px;display:block;object-fit:cover;"></div></div>' : '')+
      '</div>'+
      '<div class="sd-dialog-footer">'+
        '<a class="sd-btn sd-btn-outline" href="staff-announcements.php" target="_blank" rel="noopener noreferrer">'+(ev.category==='advisory'?'Preview Advisory':'Preview Announcement')+'</a>'+
        '<button type="button" class="sd-btn sd-btn-outline" onclick="closeModal(\'ecEventDialog\')">Close</button>'+
      '</div>';
  } else if (ev.reportId) {
    var reportStatus = String(ev.status || 'pending').replace(/_/g, ' ');
    var reportUrgency = cap(ev.urgency || 'medium');
    body.innerHTML =
      '<div class="sd-dialog-header">'+
        '<h2 class="sd-dialog-title">Report Details</h2>'+
        '<p class="sd-dialog-description">Incident reports are shown for staff awareness and triage.</p>'+
      '</div>'+
      '<div class="ec-detail-rows">'+
        '<div class="ec-detail-row"><span class="sd-label">Reference</span><span class="sd-value">'+esc(ev.reference || '-')+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Report type</span><span class="sd-value">'+esc(cap(ev.eventType))+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Incident date/time</span><span class="sd-value">'+esc(ev.date ? new Date(String(ev.date).replace(/-/g,'/')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}) : '-')+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Submitted</span><span class="sd-value">'+esc(ev.submittedAt ? new Date(String(ev.submittedAt).replace(/-/g,'/')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}) : '-')+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Urgency</span><span class="sd-value">'+esc(reportUrgency)+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Status</span><span class="sd-value">'+esc(reportStatus)+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Barangay / Location</span><span class="sd-value">'+esc(ev.location || '-')+'</span></div>'+
        '<div class="ec-detail-row"><span class="sd-label">Reporter</span><span class="sd-value">'+esc(ev.resident || '-')+'</span></div>'+
        (ev.address ? '<div class="ec-detail-row"><span class="sd-label">Incident address</span><span class="sd-value">'+esc(ev.address)+'</span></div>' : '')+
      '</div>'+
      (ev.description ? '<div><div class="ec-sec-title">Description</div><div class="ec-notes-box">'+esc(ev.description)+'</div></div>' : '')+
      '<div class="sd-dialog-footer"><a class="sd-btn sd-btn-outline" href="dashboard-staff.php#reports-pending" target="_blank" rel="noopener noreferrer">Open Reports</a><button type="button" class="sd-btn sd-btn-outline" onclick="closeModal(\'ecEventDialog\')">Close</button></div>';
  } else {
    var statusPillCls = 'ec-st-' + String(ev.status||'pending').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    var statusLabel = (ev.status||'pending').replace(/_/g,' ');
    var statusColors = { pending:['#FEF3C7','#92400e','#FDE68A'], scheduled:['#dbeafe','#1e40af','#bfdbfe'], active:['#dbeafe','#1e40af','#bfdbfe'], confirmed:['#dcfce7','#166534','#bbf7d0'], rescheduled:['#f3e8ff','#7e22ce','#e9d5ff'], rejected:['#fee2e2','#991b1b','#fecaca'], completed:['#dcfce7','#166534','#bbf7d0'] };
    var sc = statusColors[ev.status] || statusColors.pending;
    var petInitial = (ev.name||'P').charAt(0).toUpperCase();
    var petPhotoHtml = ev.petPhoto
      ? '<img src="'+esc(ev.petPhoto)+'" alt="">'
      : '<div class="ec-pet-placeholder">'+esc(petInitial)+'</div>';
    var petMeta = [ev.petType, ev.breed].filter(Boolean).join(' \u2022 ') || ev.petType || '';
    var reqDate = ev.requestedOn ? new Date(String(ev.requestedOn).replace(/-/g,'/')).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
    var apptDate = formatDateRange(ev) + ', ' + YEAR;

    body.innerHTML =
      '<div class="sd-dialog-header">'+
        '<h2 class="sd-dialog-title">Appointment Details</h2>'+
        '<p class="sd-dialog-description">View appointment information, resident details, and scheduling status.</p>'+
      '</div>'+
      '<div style="display:flex;flex-direction:column;gap:20px;">'+
        '<div>'+
          '<div class="ec-pet-card" style="margin-bottom:0;">'+
            petPhotoHtml+
            '<div>'+
              '<div class="ec-pet-name">'+esc(ev.name)+'</div>'+
              '<div class="ec-pet-meta">'+esc(ev.reference||'')+'</div>'+
              (petMeta ? '<div class="ec-pet-meta">'+esc(petMeta)+'</div>' : '')+
            '</div>'+
          '</div>'+
        '</div>'+
        '<div class="ec-info-grid">'+
          '<div class="ec-sec-title">Appointment Details</div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Service</span><span class="ec-field-value">'+esc(cap(ev.eventType))+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Date</span><span class="ec-field-value">'+esc(apptDate)+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Time</span><span class="ec-field-value">'+esc(ev.time||'-')+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Status</span><span class="ec-field-value"><span class="ec-status-pill '+statusPillCls+'" style="background:'+sc[0]+';color:'+sc[1]+';border-color:'+sc[2]+';">'+esc(statusLabel)+'</span></span></div>'+
        '</div>'+
        '<div class="ec-info-grid">'+
          '<div class="ec-sec-title">Resident Information</div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Resident</span><span class="ec-field-value">'+esc(ev.resident||'-')+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Contact</span><span class="ec-field-value">'+esc(ev.contact||'-')+'</span></div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Barangay</span><span class="ec-field-value">'+esc(ev.location)+'</span></div>'+
        '</div>'+
        '<div class="ec-info-grid">'+
          '<div class="ec-sec-title">Location</div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Appointment Location</span><span class="ec-field-value">DCVS Veterinary Clinic</span></div>'+
        '</div>'+
        '<div class="ec-info-grid">'+
          '<div class="ec-sec-title">Request Information</div>'+
          '<div class="ec-field-row"><span class="ec-field-label">Requested On</span><span class="ec-field-value">'+esc(reqDate)+'</span></div>'+
        '</div>'+
        (ev.description ? '<div><div class="ec-sec-title">Resident Notes</div><div class="ec-notes-box">'+esc(ev.description)+'</div></div>' : '')+
      '</div>';
  }
  openModal('ecEventDialog');
};window.ecCloseEvent = function(){ closeModal('ecEventDialog'); };

window.ecAddToCal = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev) return;
  var mIdx = MONTHS.indexOf(ev.month);
  var startDate = new Date(YEAR, mIdx, ev.startDay);
  var endDate = new Date(YEAR, mIdx, ev.endDay + 1);
  var fmt = function(d){ return d.toISOString().replace(/[-:]/g,'').split('.')[0]+'Z'; };
  var escIcs = function(t){ return String(t).replace(/[,;\\]/g,'\\$&').replace(/\n/g,'\\n'); };
  var uid = Date.now()+'-'+Math.random().toString(36).substr(2,9)+'@designevents.guide';
  var ics = 'BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//DCVS//EN\nBEGIN:VEVENT\nUID:'+uid+'\nDTSTAMP:'+fmt(new Date())+'\nDTSTART:'+fmt(startDate)+'\nDTEND:'+fmt(endDate)+'\nSUMMARY:'+escIcs(ev.name)+'\nLOCATION:'+escIcs(ev.location)+'\nURL:'+ev.url+'\nDESCRIPTION:'+escIcs(state.mode === 'event' ? (ev.description || ev.name) : (cap(ev.eventType)+' appointment - '+(ev.resident||'')+' ('+ev.status+')'))+'\nEND:VEVENT\nEND:VCALENDAR';
  var blob = new Blob([ics], {type:'text/calendar;charset=utf-8'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url;
  a.download = ev.name.replace(/[^a-z0-9]/gi,'-').toLowerCase()+'.ics';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
};

window.ecToggleSave = function(id){
  if (!state.loggedIn){ ecOpenAuth('signin'); return; }
  var idx = state.saved.indexOf(id);
  if (idx === -1) state.saved.push(id);
  else state.saved.splice(idx,1);
  localStorage.setItem('savedEvents', JSON.stringify(state.saved));
  ecRender();
  if (state.selected && eventId(state.selected) === id) ecOpenEvent(id);
};

window.ecApproveAppt = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev || !ev.appointment_id) return;
  closeModal('ecEventDialog');
  var dateStr = ev.date ? new Date(ev.date.replace(/-/g,'/')).toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'}) : '';
  $('ecConfirmTitle').textContent = 'Are you sure you want to approve this appointment?';
  $('ecConfirmDesc').innerHTML = 'This will confirm the appointment and add it to the confirmed appointment schedule for <strong>'+esc(ev.resident||'')+'</strong> from <strong>'+esc(ev.location||'')+'</strong> on <strong>'+dateStr+' at '+esc(ev.time||'')+'</strong>.';
  $('ecConfirmBody').innerHTML = '';
  var ok = $('ecConfirmOk');
  ok.textContent = 'Approve';
  ok.className = 'sd-btn sd-btn-default';
  ok.style.background = '';
  ok.style.color = '';
  ok.style.borderColor = '';
  ok.onclick = function(){
    closeModal('ecConfirmDialog');
    ecDoApprove(id);
  };
  openModal('ecConfirmDialog');
};

window.ecDoApprove = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev || !ev.appointment_id) return;
  var body = new URLSearchParams();
  body.append('action', 'approve');
  body.append('ids', ev.appointment_id);
  body.append('staff_message', '');
  fetch('update-appointment-status.php', { method:'POST', body:body })
    .then(function(r){ return r.json().catch(function(){ return {success:false,message:'Invalid response'}; }); })
    .then(function(res){
      if (res && res.success){
        ev.status = 'scheduled';
        ecToast('Appointment Approved', esc(ev.resident||'')+'\'s appointment has been approved and scheduled.');
        ecRender();
      } else {
        ecToast('Approval Failed', (res&&res.message)||'Could not approve this appointment.', true);
      }
    })
    .catch(function(){
      ecToast('Error', 'Network error. Please try again.', true);
    });
};

window.ecRejectAppt = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev || !ev.appointment_id) return;
  closeModal('ecEventDialog');
  var dateStr = ev.date ? new Date(ev.date.replace(/-/g,'/')).toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'}) : '';
  $('ecConfirmTitle').textContent = 'Are you sure you want to reject this appointment?';
  $('ecConfirmDesc').innerHTML = 'This will mark the appointment as rejected for <strong>'+esc(ev.resident||'')+'</strong> from <strong>'+esc(ev.location||'')+'</strong> on <strong>'+dateStr+' at '+esc(ev.time||'')+'</strong>.';
  $('ecConfirmBody').innerHTML = '';
  var ok = $('ecConfirmOk');
  ok.textContent = 'Reject';
  ok.className = 'sd-btn sd-btn-default';
  ok.style.background = '#dc2626';
  ok.style.color = '#fff';
  ok.style.borderColor = '#dc2626';
  ok.onclick = function(){
    ok.style.background = '';
    ok.style.color = '';
    ok.style.borderColor = '';
    closeModal('ecConfirmDialog');
    ecDoReject(id);
  };
  openModal('ecConfirmDialog');
};

window.ecDoReject = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev || !ev.appointment_id) return;
  var body = new URLSearchParams();
  body.append('action', 'reject');
  body.append('ids', ev.appointment_id);
  fetch('update-appointment-status.php', { method:'POST', body:body })
    .then(function(r){ return r.json().catch(function(){ return {success:false,message:'Invalid response'}; }); })
    .then(function(res){
      if (res && res.success){
        EVENTS = EVENTS.filter(function(item){ return item !== ev; });
        ecToast('Appointment Rejected', esc(ev.resident||'')+'\'s appointment has been rejected.');
        ecRender();
      } else {
        ecToast('Rejection Failed', (res&&res.message)||'Could not reject this appointment.', true);
      }
    })
    .catch(function(){
      ecToast('Error', 'Network error. Please try again.', true);
    });
};

window.ecRescheduleAppt = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev || !ev.appointment_id) return;
  closeModal('ecEventDialog');
  $('ecReschedBody').innerHTML =
    '<div style="display:flex;gap:8px;">'+
      '<div class="ec-resched-field" style="flex:1;"><label>New Date</label><input id="ecReschedDate" type="date" /></div>'+
      '<div class="ec-resched-field" style="flex:1;"><label>New Time</label><input id="ecReschedTime" type="time" /></div>'+
    '</div>';
  var ok = $('ecReschedOk');
  ok.onclick = function(){
    var date = ($('ecReschedDate')||{}).value;
    var time = ($('ecReschedTime')||{}).value;
    if (!date || !time){ ecToast('Missing Fields','Please select a new date and time.', true); return; }
    closeModal('ecReschedDialog');
    ecConfirmResched(id, date, time);
  };
  openModal('ecReschedDialog');
};

window.ecConfirmResched = function(id, date, time){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev) return;
  var newDateStr = new Date(date.replace(/-/g,'/')).toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
  var newTimeStr = calTime(time);
  $('ecConfirmTitle').textContent = 'Are you sure you want to reschedule this appointment?';
  $('ecConfirmDesc').innerHTML = 'This will update the schedule for <strong>'+esc(ev.resident||'')+'</strong> from <strong>'+esc(ev.location||'')+'</strong> to <strong>'+newDateStr+' at '+newTimeStr+'</strong>.';
  $('ecConfirmBody').innerHTML = '';
  var ok = $('ecConfirmOk');
  ok.textContent = 'Confirm Reschedule';
  ok.className = 'sd-btn sd-btn-default';
  ok.style.background = '';
  ok.style.color = '';
  ok.style.borderColor = '';
  ok.onclick = function(){
    closeModal('ecConfirmDialog');
    ecDoReschedule(id, date, time);
  };
  openModal('ecConfirmDialog');
};

window.ecDoReschedule = function(id, date, time){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev) return;
  var body = new URLSearchParams();
  body.append('action','reschedule');
  body.append('ids', ev.appointment_id);
  body.append('preferred_date', date);
  body.append('preferred_time', time);
  fetch('update-appointment-status.php', { method:'POST', body:body })
    .then(function(r){ return r.json().catch(function(){ return {success:false,message:'Invalid response'}; }); })
    .then(function(res){
      if (res && res.success){
        var parts = String(date).split('-');
        if (parts.length !== 3 || parseInt(parts[0], 10) !== YEAR) {
          EVENTS = EVENTS.filter(function(item){ return item !== ev; });
        } else {
          ev.status = 'rescheduled';
          ev.date = date;
          ev.month = MONTHS[parseInt(parts[1], 10) - 1];
          ev.startDay = parseInt(parts[2], 10);
          ev.endDay = ev.startDay;
          ev.time = calTime(time);
        }
        ecToast('Appointment Rescheduled', esc(ev.resident||'')+'\'s appointment has been rescheduled.');
        ecRender();
      } else {
        ecToast('Reschedule Failed', (res&&res.message)||'Could not reschedule this appointment.', true);
      }
    })
    .catch(function(){
      ecToast('Error', 'Network error. Please try again.', true);
    });
};

window.ecToggleOnlySaved = function(){
  state.onlySaved = !state.onlySaved;
  ecRender();
};

window.ecOpenAuth = function(mode){
  state.authMode = mode || 'signin';
  var body = $('ecAuthBody');
  var isSignin = state.authMode === 'signin';
  body.innerHTML =
    '<div class="sd-dialog-header">'+
      '<h2 class="sd-dialog-title">'+(isSignin?'Sign In':'Create Account')+'</h2>'+
      '<p class="sd-dialog-description">'+(isSignin?'Sign in to save events and access them across devices.':'Create an account to save events and access them across devices.')+'</p>'+
    '</div>'+
    '<form onsubmit="return ecAuthSubmit(event)">'+
      '<div class="sd-field-group">'+
        '<div class="sd-field"><span class="sd-label" for="ecAuthEmail">Email</span><input class="sd-input" id="ecAuthEmail" type="email" required placeholder="you@example.com"></div>'+
        '<div class="sd-field"><span class="sd-label" for="ecAuthPassword">Password</span><input class="sd-input" id="ecAuthPassword" type="password" required placeholder="Enter your password"></div>'+
      '</div>'+
      '<div class="sd-dialog-footer" style="margin-top:16px;">'+
        '<button type="submit" class="sd-btn sd-btn-default" style="width:100%;">'+(isSignin?'Sign In':'Create Account')+'</button>'+
      '</div>'+
    '</form>'+
    '<div class="ec-auth-switch">'+
      (isSignin ? 'Don\'t have an account? <button type="button" onclick="ecOpenAuth(\'signup\')">Sign up</button>' : 'Already have an account? <button type="button" onclick="ecOpenAuth(\'signin\')">Sign in</button>')+
    '</div>'+
    '<div style="margin-top:16px;">'+
      '<div class="ec-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><p>For demo purposes, you can still sign in with any email and password to test the saved events feature.</p></div>'+
      '<div class="ec-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg><p>To enable real authentication, connect a database (Supabase, Neon, etc.) with auth support.</p></div>'+
    '</div>';
  openModal('ecAuthDialog');
};
window.ecCloseAuth = function(){ closeModal('ecAuthDialog'); };

window.ecAuthSubmit = function(e){
  e.preventDefault();
  var email = $('ecAuthEmail').value;
  localStorage.setItem('demoUser', email);
  state.loggedIn = true;
  state.userEmail = email;
  closeModal('ecAuthDialog');
  ecRender();
  return false;
};

window.ecSignOut = function(){
  localStorage.removeItem('demoUser');
  state.loggedIn = false;
  state.userEmail = '';
  state.onlySaved = false;
  closeMenus();
  ecRender();
};

var ecToastTimer = null;
function ecToast(title, desc, isError){
  var t = $('ecToast');
  t.innerHTML = '<div class="ec-toast-title">'+esc(title)+'</div><div class="ec-toast-desc">'+esc(desc)+'</div>';
  t.style.background = isError ? '#dc2626' : '';
  t.classList.add('show');
  clearTimeout(ecToastTimer);
  ecToastTimer = setTimeout(function(){ t.classList.remove('show'); }, 3500);
}

function init(){
  var savedUser = localStorage.getItem('demoUser');
  if (savedUser){ state.loggedIn = true; state.userEmail = savedUser; }
  var savedIds = localStorage.getItem('savedEvents');
  if (savedIds){ try { state.saved = JSON.parse(savedIds); } catch(e){ state.saved = []; } }

  ecRender();

  var openRef = localStorage.getItem('ecOpenAppt');
  if (openRef){
    localStorage.removeItem('ecOpenAppt');
    var openEv = EVENTS.filter(function(e){ return e.reference === openRef; })[0];
    if (openEv){
      state.month = openEv.month;
      ecRender();
      ecOpenEvent(eventId(openEv));
    }
  }

  setTimeout(function(){
    ecToast('Welcome to the Event & Appointment Calendar','View and manage upcoming pet appointments and DCVS events.');
  }, 1500);

  var h = window.location.hash.replace('#','');
  if (h){
    setTimeout(function(){
      var el = document.getElementById(h);
      if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
    }, 100);
  }
}

window.addEventListener('hashchange', function(){
  var h = window.location.hash.replace('#','');
  if (h){
    setTimeout(function(){
      var el = document.getElementById(h);
      if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
    }, 100);
  }
});

document.addEventListener('DOMContentLoaded', init);
if (document.readyState === 'complete' || document.readyState === 'interactive') setTimeout(init, 0);
})();
</script>
</body>
</html>
</div>