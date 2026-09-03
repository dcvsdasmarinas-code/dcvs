<?php
/**
 * DCVS Appointment Calendar — annual view of the city's appointment schedule.
 * Renders real appointments (pending/scheduled/etc.) from the database.
 * Scoped under #dcvsEventsCalendar so it won't clash with the dashboard.
 */
require_once __DIR__ . '/../db.php';
$calYear = (int)date('Y');
$calAppointments = [];
if (isset($conn) && !$conn->connect_error) {
    $calStmt = $conn->prepare("
        SELECT a.appointment_id, a.reference_id, a.pet_id, a.preferred_date, a.preferred_time,
               a.status, a.appointment_type, a.notes,
               COALESCE(NULLIF(a.pet_name,''), p.pet_name) AS pet_name,
               u.full_name, u.phone, u.address, b.barangay_name
        FROM appointments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN pets p ON a.pet_id = p.pet_id
        LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
        WHERE a.archived_at IS NULL AND YEAR(a.preferred_date) = ?
        ORDER BY a.preferred_date, a.preferred_time
    ");
    if ($calStmt) {
        $calStmt->bind_param('i', $calYear);
        $calStmt->execute();
        $calRes = $calStmt->get_result();
        while ($row = $calRes->fetch_assoc()) { $calAppointments[] = $row; }
        $calStmt->close();
    }
    $calResidents = [];
    $calPets = [];
    $resRes = $conn->query("SELECT user_id, full_name, reference_id FROM users WHERE role='resident' AND status='active' ORDER BY full_name");
    if ($resRes) { while ($row = $resRes->fetch_assoc()) { $calResidents[] = $row; } }
    $petsRes = $conn->query("SELECT pet_id, owner_id, pet_name, pet_type FROM pets WHERE archived_at IS NULL ORDER BY pet_name");
    if ($petsRes) { while ($row = $petsRes->fetch_assoc()) { $calPets[] = $row; } }
    $calAnnouncements = [];
    $annRes = $conn->query("SELECT a.announcement_id, a.title, a.content, a.category, a.priority, a.status, a.publish_at, a.barangay_id, b.barangay_name FROM announcements a LEFT JOIN barangays b ON a.barangay_id = b.barangay_id WHERE a.status IN ('published','scheduled') AND a.archived_at IS NULL ORDER BY a.publish_at");
    if ($annRes) { while ($row = $annRes->fetch_assoc()) { $calAnnouncements[] = $row; } }
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
#dcvsEventsCalendar .ec-dialog-ref{ font-size:13px; color:var(--ec-muted-fg); margin:0 0 8px; }

/* Calendar dialogs use the dashboard's shadcn (sd-dialog) design */
#ecEventDialog.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
#ecEventDialog .sd-dialog-content{ max-width:420px; gap:20px; }
#ecEventDialog #ecEventBody{ display:flex; flex-direction:column; gap:20px; }
#ecEventDialog .sd-field-group{ gap:20px; }
#ecEventDialog .ec-detail-rows{ display:flex; flex-direction:column; gap:16px; }
#ecEventDialog .ec-detail-row{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; }
#ecEventDialog .ec-detail-row .sd-label{ margin:0; }
#ecEventDialog .ec-detail-row .sd-value{ text-align:right; }
#ecEventDialog .ec-detail-row .ec-status-pill{ align-self:baseline; }
#ecAuthDialog.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
#ecAuthDialog .sd-dialog-content{ max-width:440px; gap:20px; }
#ecAddApptDialog.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
#ecAddApptDialog .sd-dialog-content{ max-width:520px; gap:16px; }
#ecAddApptDialog .sd-field-group{ gap:16px; }
#ecAddApptDialog .ec-detail-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px 20px; }
#ecAddApptDialog .ec-detail-grid .sd-field{ gap:6px; }
#ecAddApptDialog .sd-input{ box-sizing:border-box; }
#ecAddApptDialog .sd-input[readonly]{ background:#f8fafc; color:#94a3b8; }
#ecEventDialog .ec-event-edition{ margin-top:0; font-size:11px; }
#ecAuthDialog .sd-input{ box-sizing:border-box; }
#dcvsEventsCalendar .ec-status-pill{ display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; align-self:flex-start; width:auto; }
#ecEventDialog .sd-dialog-footer{ margin-top:0; }
#dcvsEventsCalendar .ec-status-pill.ec-st-pending{ background:#fffbeb; color:#b45309; }
#dcvsEventsCalendar .ec-status-pill.ec-st-scheduled{ background:#ecfdf5; color:#047857; }
#dcvsEventsCalendar .ec-status-pill.ec-st-completed{ background:#f1f5f9; color:#475569; }
#dcvsEventsCalendar .ec-status-pill.ec-st-cancelled{ background:#fef2f2; color:#b91c1c; }
#dcvsEventsCalendar .ec-status-pill.ec-st-rejected{ background:#fef2f2; color:#b91c1c; }
#dcvsEventsCalendar .ec-status-pill.ec-st-rescheduled{ background:#f5f3ff; color:#6d28d9; }
#dcvsEventsCalendar .ec-status-pill.ec-st-no-show{ background:#f1f5f9; color:#475569; }
#dcvsEventsCalendar .ec-event-time{ color:var(--ec-muted-fg); margin-top:4px; }
#dcvsEventsCalendar .ec-event-loc{ color:var(--ec-muted-fg); }
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
          <button type="button" class="ec-btn" onclick="ecToggleMenu(this,'ecTypeMenu')"><span id="ecTypeLabel">Service Type</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
          <div class="ec-menu" id="ecTypeMenu"></div>
        </div>

        <button type="button" class="ec-btn ec-btn-default" id="ecAddApptBtn" onclick="ecOpenAddAppointment()"><i class="fas fa-plus"></i> Add Appointment</button>
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
        <div class="ec-weekday">Monday</div>
        <div class="ec-weekday">Tuesday</div>
        <div class="ec-weekday">Wednesday</div>
        <div class="ec-weekday">Thursday</div>
        <div class="ec-weekday">Friday</div>
        <div class="ec-weekday">Saturday</div>
        <div class="ec-weekday">Sunday</div>
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

<div id="ecAddApptDialog" class="modal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true">
      <button type="button" class="sd-dialog-close" onclick="closeModal('ecAddApptDialog')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Add Appointment</h2>
        <p class="sd-dialog-description">Schedule a new appointment for a resident.</p>
      </div>
      <form id="ecAddApptForm" onsubmit="return ecAddApptSubmit(event)">
        <div class="sd-field-group">
          <div class="ec-detail-grid">
            <div class="sd-field">
              <span class="sd-label">Client / Resident</span>
              <select class="sd-input" id="ecApptClient" onchange="ecApptClientChanged()" required></select>
            </div>
            <div class="sd-field">
              <span class="sd-label">Pet</span>
              <select class="sd-input" id="ecApptPet"></select>
            </div>
            <div class="sd-field">
              <span class="sd-label">Service Type</span>
              <select class="sd-input" id="ecApptType" required>
                <option value="kapon">Kapon</option>
                <option value="vaccination">Vaccination</option>
                <option value="checkup">Checkup</option>
                <option value="adoption">Adoption</option>
              </select>
            </div>
            <div class="sd-field">
              <span class="sd-label">Appointment Date</span>
              <input class="sd-input" type="date" id="ecApptDate" required>
            </div>
            <div class="sd-field">
              <span class="sd-label">Preferred Time</span>
              <input class="sd-input" type="time" id="ecApptTime" required>
            </div>
            <div class="sd-field">
              <span class="sd-label">Status</span>
              <select class="sd-input" id="ecApptStatus" required>
                <option value="pending">Pending</option>
                <option value="scheduled" selected>Scheduled</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="rescheduled">Rescheduled</option>
                <option value="rejected">Rejected</option>
                <option value="no_show">No Show</option>
              </select>
            </div>
          </div>
          <div class="sd-field">
            <span class="sd-label">Notes</span>
            <textarea class="sd-input" id="ecApptNotes" rows="3" placeholder="Additional notes..."></textarea>
          </div>
        </div>
        <div class="sd-dialog-footer" style="margin-top:20px;">
          <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('ecAddApptDialog')">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-default" id="ecAddApptSubmitBtn">Add Appointment</button>
        </div>
      </form>
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
var YEAR = <?php echo (int)$calYear; ?>;

var CAL_RESIDENTS = <?php echo json_encode($calResidents); ?>;
var CAL_PETS = <?php echo json_encode($calPets); ?>;
var CAL_ANNOUNCEMENTS = <?php echo json_encode($calAnnouncements); ?>;

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
    name: pet,
    reference: a.reference_id || '',
    time: calTime(a.preferred_time),
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
  return new Date(String(a.publish_at||a.created_at).replace(/-/g,'/')).getFullYear() === YEAR;
}).map(function(a){
  var dt = new Date(String(a.publish_at||a.created_at).replace(/-/g,'/'));
  return {
    name: a.title || 'Announcement',
    reference: '',
    time: calDateToTime(dt),
    location: a.barangay_name || 'City-wide',
    flag: '',
    url: '',
    continent: a.priority || 'medium',
    month: MONTHS[dt.getMonth()],
    startDay: dt.getDate(),
    endDay: dt.getDate(),
    eventType: a.category || 'announcement',
    status: String(a.status||'published'),
    description: a.content || '',
    resident: '',
    contact: '',
    venue: '',
    category: a.category || '',
    priority: a.priority || ''
  };
});
function getEvents(){ return state.mode === 'event' ? ANNOUNCEMENT_EVENTS : EVENTS; }
var state = { mode:'appointment', continent:null, type:null, search:'', loggedIn:false, userEmail:'', saved:[], onlySaved:false, selected:null, authMode:'signin', month:MONTHS[(new Date()).getMonth()] };

function $(id){ return document.getElementById(id); }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function eventId(ev){ return ev.month+'-'+ev.name+'-'+ev.startDay; }
function isSaved(ev){ return state.saved.indexOf(eventId(ev)) !== -1; }
function cap(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : s; }

function filteredEvents(){
  var q = state.search.toLowerCase();
  return getEvents().filter(function(ev){
    if (state.continent && ev.continent !== state.continent) return false;
    if (state.type && ev.eventType !== state.type) return false;
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
    '<button type="button" class="ec-menu-item'+(state.mode==='event'?' active':'')+'" onclick="ecSetMode(\'event\')">Event Calendar</button>';
}
window.ecSetMode = function(mode){
  state.mode = mode;
  state.continent = null;
  state.type = null;
  closeMenus();
  $('ecModeLabel').textContent = mode === 'event' ? 'Event Calendar' : 'Appointment Calendar';
  $('ecContinentLabel').textContent = mode === 'event' ? 'Filter by Priority' : 'Filter by Barangay';
  $('ecTypeLabel').textContent = mode === 'event' ? 'Category' : 'Service Type';
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
    html = '<button type="button" class="ec-menu-item'+(state.continent===null?' active':'')+'" onclick="ecSetContinent(null)">All Barangays</button>';
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
  } else {
    html = '<button type="button" class="ec-menu-item'+(state.type===null?' active':'')+'" onclick="ecSetType(null)">All Services</button>';
    html += EVENT_TYPES.map(function(t){
      return '<button type="button" class="ec-menu-item'+(state.type===t?' active':'')+'" onclick="ecSetType(\''+t+'\')">'+cap(t)+'</button>';
    }).join('');
  }
  menu.innerHTML = html;
}
window.ecSetType = function(t){ state.type = t; closeMenus(); $('ecTypeLabel').textContent = t ? cap(t) : (state.mode === 'event' ? 'Category' : 'Service Type'); buildTypeMenu(); ecRender(); };

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
    var dayEvents = events.filter(function(ev){ return ev.month === month && d >= ev.startDay && d <= ev.endDay; });
    cells.push({date:d, events:dayEvents});
  }
  var rem = (7 - (cells.length % 7)) % 7;
  for (var k=0;k<rem;k++) cells.push({date:null, events:[]});
  return cells;
}

var today = new Date();
var currentMonth = MONTHS[today.getMonth()];
var currentDay = today.getDate();

function eventBlock(ev){
  var id = eventId(ev);
  var stCls = 'ec-st-' + String(ev.status||'pending').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  return '<div class="ec-event '+stCls+'" onclick="ecOpenEvent(\''+id+'\')">'+
    '<div class="ec-event-name">'+esc(ev.name)+'</div>'+
    '<span class="ec-event-edition '+stCls+'">'+esc(ev.status)+'</span>'+
    '<div class="ec-event-time">'+esc(ev.time)+'</div>'+
    '<div class="ec-event-loc">'+esc(ev.location)+'</div>'+
  '</div>';
}

window.ecRender = function(){
  buildModeMenu();
  buildMonthMenu();
  buildContinentMenu();
  buildTypeMenu();
  buildUserMenu();

  var month = state.month;
  var events = filteredEvents();
  var cells = buildMonthGrid(month, events);
  var dayHtml = cells.map(function(cell, idx){
    if (cell.date === null) return '<div class="ec-day"></div>';
    var isT = (month === currentMonth && cell.date === currentDay);
    var evHtml = cell.events.map(eventBlock).join('');
    return '<div class="ec-day'+(isT?' today':'')+'">'+
      '<h3 class="ec-day-num">'+cell.date+'</h3>'+
      '<div class="ec-events">'+evHtml+'</div>'+
    '</div>';
  }).join('');
  $('ecMonths').innerHTML =
    '<div class="ec-month-row">'+
      '<div class="ec-month-label"><h2>'+month+'</h2></div>'+
      '<div class="ec-grid">'+dayHtml+'</div>'+
    '</div>';

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
  var addBtn = $('ecAddApptBtn');
  if (addBtn) addBtn.style.display = state.mode === 'event' ? 'none' : '';
};

window.ecOpenEvent = function(id){
  var ev = getEvents().filter(function(e){ return eventId(e) === id; })[0];
  if (!ev) return;
  state.selected = ev;
  var saved = isSaved(ev);
  var stCls = 'ec-st-' + String(ev.status||'pending').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  var body = $('ecEventBody');
  if (state.mode === 'event'){
    body.innerHTML =
      '<div class="sd-dialog-header">'+
        '<h2 class="sd-dialog-title">'+esc(ev.name)+'</h2>'+
        '<p class="sd-dialog-description">'+esc(cap(ev.category||''))+' &middot; '+esc(cap(ev.priority||''))+' priority</p>'+
      '</div>'+
      '<div class="sd-field-group">'+
        '<div class="ec-detail-rows">'+
          '<div class="ec-detail-row"><span class="sd-label">Date</span><span class="sd-value">'+esc(formatDateRange(ev))+', '+YEAR+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Status</span><span class="ec-status-pill '+stCls+'">'+esc(ev.status)+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Category</span><span class="sd-value">'+esc(cap(ev.category||'-'))+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Priority</span><span class="sd-value">'+esc(cap(ev.priority||'-'))+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Audience</span><span class="sd-value">'+esc(ev.location)+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Time</span><span class="sd-value">'+esc(ev.time||'-')+'</span></div>'+
        '</div>'+
        (ev.description ? '<div class="sd-field"><span class="sd-label">Details</span><div class="sd-value" style="background:#f8fafc;border:1px solid #e4e4e7;border-radius:6px;padding:10px 12px;">'+esc(ev.description)+'</div></div>' : '')+
      '</div>'+
      '<div class="sd-dialog-footer">'+
        '<button type="button" class="sd-btn sd-btn-outline" onclick="ecAddToCal(\''+id+'\')">Add to Calendar</button>'+
        '<button type="button" class="sd-btn sd-btn-default" onclick="closeModal(\'ecEventDialog\')">Close</button>'+
      '</div>';
  } else {
    body.innerHTML =
      '<div class="sd-dialog-header">'+
        '<h2 class="sd-dialog-title">'+esc(ev.name)+'</h2>'+
        '<p class="sd-dialog-description">'+esc(ev.reference||'')+'</p>'+
      '</div>'+
      '<div class="sd-field-group">'+
        '<div class="sd-field">'+
          '<span class="sd-label">Resident</span>'+
          '<span class="sd-value" style="font-size:15px;font-weight:600;">'+esc(ev.resident||'-')+'</span>'+
        '</div>'+
        '<div class="ec-detail-rows">'+
          '<div class="ec-detail-row"><span class="sd-label">Service</span><span class="sd-value">'+esc(cap(ev.eventType))+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Date</span><span class="sd-value">'+esc(formatDateRange(ev))+', '+YEAR+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Status</span><span class="ec-status-pill '+stCls+'">'+esc(ev.status)+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Time</span><span class="sd-value">'+esc(ev.time||'-')+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Contact</span><span class="sd-value">'+esc(ev.contact||'-')+'</span></div>'+
          '<div class="ec-detail-row"><span class="sd-label">Barangay</span><span class="sd-value">'+esc(ev.location)+'</span></div>'+
        '</div>'+
        (ev.description ? '<div class="sd-field"><span class="sd-label">Notes</span><div class="sd-value" style="background:#f8fafc;border:1px solid #e4e4e7;border-radius:6px;padding:10px 12px;">'+esc(ev.description)+'</div></div>' : '')+
      '</div>'+
      '<div class="sd-dialog-footer">'+
        '<button type="button" class="sd-btn sd-btn-outline" onclick="ecAddToCal(\''+id+'\')">Add to Calendar</button>'+
        (state.loggedIn ? '<button type="button" class="sd-btn sd-btn-outline" onclick="ecToggleSave(\''+id+'\')"><i class="fas fa-heart" style="color:'+(saved?'#ef4444':'')+'"></i> '+(saved?'Saved':'Save Event')+'</button>' : '')+
        (ev.url ? '<a class="sd-btn sd-btn-outline" href="'+esc(ev.url)+'" target="_blank" rel="noopener noreferrer">View Pet</a>' : '')+
        '<button type="button" class="sd-btn sd-btn-default" onclick="closeModal(\'ecEventDialog\')">Close</button>'+
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

// ── Add Appointment ──
function ecPopulateResidents(){
  var sel = $('ecApptClient');
  if (!sel) return;
  var html = '<option value="">Select a resident...</option>';
  CAL_RESIDENTS.forEach(function(r){
    html += '<option value="'+r.user_id+'">'+esc(r.full_name)+(r.reference_id ? ' ('+esc(r.reference_id)+')' : '')+'</option>';
  });
  sel.innerHTML = html;
  var petSel = $('ecApptPet');
  if (petSel) petSel.innerHTML = '<option value="">No pet / none</option>';
}
window.ecApptClientChanged = function(){
  var uid = $('ecApptClient').value;
  var petSel = $('ecApptPet');
  var html = '<option value="">No pet / none</option>';
  if (uid){
    CAL_PETS.forEach(function(p){
      if (String(p.owner_id) === String(uid)){
        html += '<option value="'+p.pet_id+'" data-name="'+esc(p.pet_name)+'">'+esc(p.pet_name)+' ('+esc(p.pet_type||'')+')</option>';
      }
    });
  }
  petSel.innerHTML = html;
}
window.ecOpenAddAppointment = function(){
  var form = $('ecAddApptForm');
  if (form) form.reset();
  ecPopulateResidents();
  var d = new Date();
  var dateInput = $('ecApptDate');
  if (dateInput) dateInput.value = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  openModal('ecAddApptDialog');
};
window.ecAddApptSubmit = function(e){
  e.preventDefault();
  var uid = $('ecApptClient').value;
  var petSel = $('ecApptPet');
  var petId = petSel.value;
  var petName = petSel.options[petSel.selectedIndex] ? petSel.options[petSel.selectedIndex].getAttribute('data-name') : '';
  var type = $('ecApptType').value;
  var date = $('ecApptDate').value;
  var time = $('ecApptTime').value;
  var status = $('ecApptStatus').value;
  var notes = $('ecApptNotes').value;

  if (!uid || !type || !date || !time){
    ecToast('Missing fields','Please select client, service type, date and time.', true);
    return false;
  }

  var btn = $('ecAddApptSubmitBtn');
  btn.disabled = true; btn.textContent = 'Adding...';

  var fd = new FormData();
  fd.append('user_id', uid);
  fd.append('pet_id', petId || '');
  fd.append('pet_name', petName || '');
  fd.append('appointment_type', type);
  fd.append('preferred_date', date);
  fd.append('preferred_time', time);
  fd.append('status', status);
  fd.append('notes', notes);

  fetch('calendar/add-appointment-handler.php', { method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Add Appointment';
      if (d.success){
        closeModal('ecAddApptDialog');
        if (d.reference_id) localStorage.setItem('ecOpenAppt', d.reference_id);
        ecToast('Appointment added', d.message || 'New appointment scheduled.');
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        ecToast('Error', d.message || 'Failed to add appointment.', true);
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Add Appointment';
      ecToast('Error', 'Network error adding appointment.', true);
    });
  return false;
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

  ecPopulateResidents();
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

  if (!state.loggedIn){
    setTimeout(function(){
      ecToast('Save appointments to your calendar','Sign in to save appointments and access your personalized "My Events" view.');
    }, 1500);
  }

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