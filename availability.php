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

require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$pageTitle = 'Availability';
$activePage = 'availability';
$panel = $_GET['panel'] ?? '';
if (!in_array($panel, ['', 'overrides', 'bulk', 'preview'])) $panel = '';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Availability — Staff | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  <style>
    :root {
      --av-border: #e4e4e7;
      --av-bg: #ffffff;
      --av-muted: #f4f4f5;
      --av-muted-fg: #71717a;
      --av-fg: #18181b;
      --av-green: #0E8131;
      --av-green-hover: #0a6b28;
      --av-ring: rgba(14,129,49,0.2);
      --av-font: 'Inter','Segoe UI',sans-serif;
    }
    .main-content { background: #ffffff !important; }

    .av-page { max-width: 70rem; margin: 0 auto; padding: 1.5rem 2rem 4rem; font-family: var(--av-font); }

    /* Header */
    .av-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem; }
    .av-header-left h1 { font-size: 1.5rem; font-weight: 600; letter-spacing: -0.025em; color: var(--av-fg); margin: 0; }
    .av-header-left p { font-size: 0.875rem; color: var(--av-muted-fg); margin: 0.25rem 0 0; }
    .av-tz-btn {
      display: inline-flex; align-items: center; gap: 0.5rem;
      padding: 0.5rem 0.875rem; border-radius: 0.5rem;
      border: 1px solid var(--av-border); background: var(--av-bg);
      font-size: 0.8125rem; font-weight: 500; color: var(--av-fg);
      cursor: default; white-space: nowrap;
    }
    .av-tz-btn svg { flex-shrink: 0; }
    .av-separator { border: none; border-top: 1px solid var(--av-border); margin: 0 0 1.5rem; }

    /* Two-column layout */
    .av-columns { display: flex; gap: 1.5rem; align-items: flex-start; }
    .av-col-left { flex: 0 0 68%; max-width: 68%; }
    .av-col-right { flex: 0 0 32%; max-width: 32%; border-left: 1px solid var(--av-border); padding-left: 1.5rem; }

    /* Section titles */
    .av-section-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; }
    .av-section-head h2 { font-size: 1rem; font-weight: 600; color: var(--av-fg); margin: 0; }
    .av-section-desc { font-size: 0.8125rem; color: var(--av-muted-fg); margin: 0 0 1rem; }

    /* Info tooltip */
    .av-tip-wrap { position: relative; display: inline-flex; }
    .av-tip-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 1.125rem; height: 1.125rem; border-radius: 50%;
      border: none; background: transparent; cursor: default;
      color: var(--av-muted-fg); padding: 0; flex-shrink: 0;
    }
    .av-tip-btn svg { width: 16px; height: 16px; }
    .av-tip-box {
      position: absolute; left: 50%; bottom: calc(100% + 8px);
      transform: translateX(-50%);
      background: var(--av-fg); color: #fff;
      font-size: 0.75rem; font-weight: 500; line-height: 1.4;
      padding: 0.5rem 0.75rem; border-radius: 0.5rem;
      white-space: nowrap; pointer-events: none;
      opacity: 0; transition: opacity 0.15s ease;
      z-index: 50;
    }
    .av-tip-wrap:hover .av-tip-box { opacity: 1; }
    .av-tip-box::after {
      content: ''; position: absolute; top: 100%; left: 50%;
      transform: translateX(-50%);
      border: 5px solid transparent; border-top-color: var(--av-fg);
    }

    /* Day rows */
    .av-days { display: flex; flex-direction: column; }
    .av-day-row {
      display: flex; align-items: center; gap: 0.75rem;
      padding: 0.875rem 0;
      border-bottom: 1px solid var(--av-border);
    }
    .av-day-row:last-child { border-bottom: none; }
    .av-day-label {
      display: flex; align-items: center; gap: 0.625rem;
      width: 10rem; flex-shrink: 0; min-width: 10rem;
    }
    .av-day-label input[type="checkbox"] {
      width: 1rem; height: 1rem; accent-color: var(--av-green); cursor: pointer; flex-shrink: 0;
    }
    .av-day-label span { font-size: 0.875rem; font-weight: 500; color: var(--av-fg); }
    .av-unavailable-text { font-size: 0.8125rem; color: var(--av-muted-fg); font-style: italic; }

    /* Time blocks */
    .av-time-blocks { display: flex; flex-direction: column; gap: 0.5rem; flex: 1; }
    .av-time-row { display: flex; align-items: center; gap: 0.5rem; }
    .av-time-select {
      width: 132px; padding: 0.4375rem 0.625rem;
      border: 1px solid var(--av-border); border-radius: 0.375rem;
      font-size: 0.8125rem; font-weight: 500; color: var(--av-fg);
      background: var(--av-bg); font-family: var(--av-font);
      appearance: auto; cursor: pointer;
    }
    .av-time-select:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }
    .av-time-sep { font-size: 0.875rem; color: var(--av-muted-fg); }

    /* Icon buttons */
    .av-icon-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 1.75rem; height: 1.75rem; border-radius: 0.375rem;
      border: 1px solid var(--av-border); background: var(--av-bg);
      color: var(--av-muted-fg); padding: 0; cursor: pointer;
      transition: all 0.15s ease; flex-shrink: 0;
    }
    .av-icon-btn:hover { border-color: #18181b; color: #18181b; background: #f4f4f5; }
    .av-icon-btn.danger:hover { border-color: #dc2626; color: #dc2626; background: #fef2f2; }
    .av-icon-btn svg { width: 16px; height: 16px; }

    /* Copy popover */
    .av-copy-popover {
      position: absolute; top: calc(100% + 6px); left: 0;
      background: var(--av-bg); border: 1px solid var(--av-border);
      border-radius: 0.5rem; padding: 0.75rem;
      box-shadow: 0 4px 16px rgba(0,0,0,0.1); z-index: 40;
      min-width: 220px; display: none;
    }
    .av-copy-popover.open { display: block; }
    .av-copy-popover-title { font-size: 0.75rem; font-weight: 600; color: var(--av-fg); margin-bottom: 0.5rem; }
    .av-copy-checks { display: flex; flex-direction: column; gap: 0.375rem; margin-bottom: 0.75rem; }
    .av-copy-checks label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; color: var(--av-fg); cursor: pointer; }
    .av-copy-checks input[type="checkbox"] { width: 0.875rem; height: 0.875rem; accent-color: var(--av-green); }
    .av-copy-actions { display: flex; gap: 0.5rem; justify-content: flex-end; }
    .av-copy-cancel {
      padding: 0.375rem 0.75rem; border: 1px solid var(--av-border);
      border-radius: 0.375rem; background: var(--av-bg);
      font-size: 0.75rem; font-weight: 500; color: var(--av-muted-fg);
      cursor: pointer;
    }
    .av-copy-cancel:hover { background: var(--av-muted); }
    .av-copy-apply {
      padding: 0.375rem 0.75rem; border: none;
      border-radius: 0.375rem; background: #18181b;
      font-size: 0.75rem; font-weight: 500; color: #fff;
      cursor: pointer;
    }
    .av-copy-apply:hover { background: #27272a; }

    /* Date-specific sidebar */
    .av-date-section h2 { margin-top: 0; }
    .av-add-date-btn {
      display: flex; align-items: center; justify-content: center; gap: 0.5rem;
      width: 100%; padding: 0.625rem;
      border: 1px dashed var(--av-border); border-radius: 0.5rem;
      background: transparent; font-size: 0.8125rem; font-weight: 500;
      color: var(--av-muted-fg); cursor: pointer; margin-bottom: 1rem;
    }
    .av-add-date-btn:hover { border-color: #18181b; color: #18181b; }
    .av-add-date-btn svg { width: 16px; height: 16px; }
    .av-empty-state {
      border: 2px dashed var(--av-border); border-radius: 0.75rem;
      padding: 2rem 1rem; text-align: center; color: var(--av-muted-fg);
    }
    .av-empty-state svg { width: 48px; height: 48px; margin: 0 auto 0.75rem; opacity: 0.4; }
    .av-empty-state h3 { font-size: 0.875rem; font-weight: 600; color: var(--av-fg); margin: 0 0 0.25rem; }
    .av-empty-state p { font-size: 0.8125rem; margin: 0; }

    /* Toast – shadcn-inspired */
    .av-toast-container {
      position: fixed; bottom: 1.5rem; right: 1.5rem;
      display: flex; flex-direction: column-reverse; gap: 0.5rem;
      z-index: 200; pointer-events: none;
    }
    .av-toast {
      pointer-events: auto;
      display: flex; align-items: flex-start; gap: 0.75rem;
      min-width: 300px; max-width: 420px;
      padding: 1rem 1.25rem;
      border-radius: 0.625rem;
      font-size: 0.8125rem; line-height: 1.4; font-weight: 500;
      background: #fff; color: #18181b;
      border: 1px solid #e4e4e7;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 2px 6px rgba(0,0,0,0.06);
      transform: translateX(calc(100% + 1.5rem));
      opacity: 0;
      transition: transform 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.25s ease;
    }
    .av-toast.show { transform: translateX(0); opacity: 1; }
    .av-toast.hiding { transform: translateX(calc(100% + 1.5rem)); opacity: 0; }
    .av-toast-icon { flex-shrink: 0; width: 1.125rem; height: 1.125rem; margin-top: 1px; }
    .av-toast-body { flex: 1; min-width: 0; }
    .av-toast-title { font-weight: 600; color: #18181b; margin: 0; }
    .av-toast-desc { font-weight: 400; color: #71717a; margin: 0.125rem 0 0; font-size: 0.8125rem; }
    .av-toast-close {
      flex-shrink: 0; width: 1.25rem; height: 1.25rem;
      display: inline-flex; align-items: center; justify-content: center;
      border: none; background: transparent; color: #71717a; cursor: pointer;
      border-radius: 0.25rem; transition: background 0.12s, color 0.12s;
    }
    .av-toast-close:hover { background: #f4f4f5; color: #18181b; }
    .av-toast-action {
      flex-shrink: 0; height: 2rem; padding: 0 0.75rem;
      border: 1px solid #e4e4e7; border-radius: 0.375rem;
      background: #fff; color: #18181b; font-size: 0.75rem; font-weight: 600;
      cursor: pointer; transition: background 0.12s;
      display: inline-flex; align-items: center; white-space: nowrap;
    }
    .av-toast-action:hover { background: #f4f4f5; }
    .av-toast.success { border-color: #bbf7d0; background: #f0fdf4; }
    .av-toast.success .av-toast-icon { color: #16a34a; }
    .av-toast.success .av-toast-title { color: #065f46; }
    .av-toast.error { border-color: #fecaca; background: #fef2f2; }
    .av-toast.error .av-toast-icon { color: #dc2626; }
    .av-toast.error .av-toast-title { color: #991b1b; }
    .av-toast.info { border-color: #bae6fd; background: #f0f9ff; }
    .av-toast.info .av-toast-icon { color: #0284c7; }
    .av-toast.info .av-toast-title { color: #0c4a6e; }

    /* Sticky footer */
    .av-footer {
      position: fixed; bottom: 0; left: 0; right: 0;
      padding: 0.875rem 2rem;
      display: flex; align-items: center; justify-content: space-between;
      z-index: 100;
      transform: translateY(100%); transition: transform 0.25s ease;
    }
    .av-footer.visible { transform: translateY(0); }
    .av-footer-text { font-size: 0.8125rem; font-weight: 500; color: var(--av-muted-fg); }
    .av-footer-actions { display: flex; gap: 0.5rem; }
    .av-btn {
      padding: 0.5rem 1rem; border-radius: 0.375rem;
      font-size: 0.8125rem; font-weight: 500; cursor: pointer;
      transition: all 0.15s ease; font-family: var(--av-font);
    }
    .av-btn-ghost { border: 1px solid var(--av-border); background: var(--av-bg); color: var(--av-fg); }
    .av-btn-ghost:hover { background: var(--av-muted); color: var(--av-fg); }
    .av-btn-primary { border: none; background: #18181b; color: #fff; }
    .av-btn-primary:hover { background: #27272a; }
    .av-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    /* Date-specific overrides list */
    .av-override-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .av-override-item {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0.625rem 0.75rem; border: 1px solid var(--av-border);
      border-radius: 0.5rem; background: var(--av-bg);
    }
    .av-override-date { font-size: 0.8125rem; font-weight: 600; color: var(--av-fg); }
    .av-override-info { font-size: 0.75rem; color: var(--av-muted-fg); }
    .av-override-remove {
      display: inline-flex; align-items: center; justify-content: center;
      width: 1.5rem; height: 1.5rem; border-radius: 0.25rem;
      border: none; background: transparent; color: var(--av-muted-fg);
      cursor: pointer; padding: 0;
    }
    .av-override-remove:hover { color: #dc2626; background: #fef2f2; }
    .av-override-remove svg { width: 14px; height: 14px; }

    /* Date override modal */
    .av-modal-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.8); padding: 1rem;
      z-index: 300; display: none; align-items: center; justify-content: center;
    }
    .av-modal-overlay.open { display: flex; }
    .av-modal {
      position: relative;
      background: #ffffff; border: 1px solid #e4e4e7; border-radius: 8px;
      box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
      width: 100%; max-width: 440px; max-height: 90vh; overflow-y: auto;
      padding: 24px; display: flex; flex-direction: column; gap: 16px;
      animation: modalIn 0.2s ease;
    }
    .av-modal-close {
      position: absolute; top: 16px; right: 16px; width: 24px; height: 24px;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 4px; border: none; background: transparent;
      color: #71717a; cursor: pointer; transition: all 0.15s ease;
    }
    .av-modal-close:hover { background: #f4f4f5; color: #18181b; }
    .av-modal-head { display: flex; flex-direction: column; gap: 6px; }
    .av-modal h3 { font-size: 18px; font-weight: 600; color: #18181b; margin: 0; letter-spacing: -0.025em; }
    .av-modal-sub { font-size: 14px; color: #71717a; margin: 0; line-height: 1.5; }
    .av-modal-field { margin-bottom: 0; display: flex; flex-direction: column; gap: 6px; }
    .av-modal-field label { display: block; font-size: 14px; font-weight: 500; color: #18181b; margin: 0; line-height: 1; }
    .av-modal-field input[type="date"] {
      width: 100%; height: 36px; padding: 0 12px;
      border: 1px solid #e4e4e7; border-radius: 6px;
      font-size: 14px; font-family: var(--av-font); color: #18181b;
      background: transparent; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      outline: none; transition: box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .av-modal-field input[type="date"]:focus { border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }
    .av-modal-slots { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
    .av-modal-slot {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0.5rem 0.75rem; border: 1px solid var(--av-border);
      border-radius: 0.375rem;
    }
    .av-modal-slot-label { font-size: 0.8125rem; font-weight: 500; color: var(--av-fg); }
    .av-modal-slot-toggle {
      padding: 0.25rem 0.625rem; border-radius: 9999px;
      font-size: 0.75rem; font-weight: 600; cursor: pointer;
      border: 1px solid; transition: all 0.15s ease;
    }
    .av-modal-slot-toggle.on { background: #f0fdf4; color: #065f46; border-color: #bbf7d0; }
    .av-modal-slot-toggle.off { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .av-modal-actions { display: flex; gap: 8px; justify-content: flex-end; }
    .sd-btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
      font-family:var(--av-font); font-size:14px; font-weight:500; line-height:1;
      white-space:nowrap; cursor:pointer;
      box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
      transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .sd-btn-default{ background:#18181b; color:#fafafa; }
    .sd-btn-default:hover{ background:#27272a; }
    .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    .sd-btn-outline:hover{ background:#f4f4f5; }
    .sd-btn:disabled{ opacity:0.5; cursor:not-allowed; pointer-events:none; }

    /* Panel switching */
    .av-panel { display: none; }
    .av-panel.active { display: block; }

    /* Overrides full-page panel */
    .ov-layout { display: flex; gap: 2rem; margin-top: 1.5rem; }
    .ov-layout > .ov-cal-col { flex: 0 0 320px; }
    .ov-layout > .ov-editor-col { flex: 1; min-width: 0; }

    /* Calendar */
    .ov-cal { border: 1px solid var(--av-border); border-radius: 0.75rem; padding: 1rem; background: var(--av-bg); }
    .ov-cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .ov-cal-nav-btn { width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; border-radius: 0.375rem; cursor: pointer; color: var(--av-muted-fg); transition: background 0.15s, color 0.15s; }
    .ov-cal-nav-btn:hover { background: var(--av-muted); color: var(--av-fg); }
    .ov-cal-nav-btn svg { width: 1rem; height: 1rem; }
    .ov-cal-month { font-size: 0.875rem; font-weight: 600; color: var(--av-fg); }
    .ov-cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0; }
    .ov-cal-weekdays span { text-align: center; font-size: 0.6875rem; font-weight: 600; color: var(--av-muted-fg); padding: 0.375rem 0; text-transform: uppercase; letter-spacing: 0.05em; }
    .ov-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
    .ov-cal-day { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 2.25rem; border-radius: 0.375rem; font-size: 0.8125rem; font-weight: 500; cursor: pointer; transition: background 0.12s, color 0.12s; color: var(--av-fg); }
    .ov-cal-day:hover { background: var(--av-muted); }
    .ov-cal-day.today { font-weight: 700; }
    .ov-cal-day.selected { background: var(--av-green); color: #fff; }
    .ov-cal-day.selected:hover { background: var(--av-green-hover); }
    .ov-cal-day.other { color: var(--av-muted-fg); opacity: 0.5; pointer-events: none; }
    .ov-cal-day.has-override::after { content: ''; position: absolute; bottom: 3px; width: 4px; height: 4px; border-radius: 50%; background: var(--av-green); }
    .ov-cal-day.selected.has-override::after { background: #fff; }
    .ov-cal-legend { display: flex; gap: 1rem; margin-top: 0.75rem; font-size: 0.6875rem; color: var(--av-muted-fg); }
    .ov-cal-legend span { display: flex; align-items: center; gap: 0.25rem; }
    .ov-cal-legend .ov-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .ov-cal-legend .ov-dot.green { background: var(--av-green); }
    .ov-cal-legend .ov-dot.gray { background: var(--av-muted-fg); }

    /* Editor right side */
    .ov-editor { border: 1px solid var(--av-border); border-radius: 0.75rem; background: var(--av-bg); min-height: 20rem; }
    .ov-editor-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 20rem; color: var(--av-muted-fg); font-size: 0.875rem; }
    .ov-editor-empty svg { width: 2rem; height: 2rem; margin-bottom: 0.75rem; opacity: 0.5; }
    .ov-editor-head { padding: 1.25rem 1.5rem 0; }
    .ov-editor-date { font-size: 1rem; font-weight: 600; color: var(--av-fg); }
    .ov-editor-sub { font-size: 0.8125rem; color: var(--av-muted-fg); margin-top: 0.125rem; }
    .ov-editor-status { display: inline-flex; align-items: center; gap: 0.375rem; margin-top: 0.5rem; font-size: 0.75rem; font-weight: 500; }
    .ov-editor-status .ov-status-dot { width: 6px; height: 6px; border-radius: 50%; }
    .ov-editor-body { padding: 1rem 1.5rem 1.25rem; }
    .ov-editor-label { font-size: 0.8125rem; font-weight: 500; color: var(--av-fg); margin-bottom: 0.5rem; }
    .ov-hours-display { display: flex; flex-direction: column; gap: 0.375rem; }
    .ov-hours-range { font-size: 0.875rem; font-weight: 500; color: var(--av-fg); font-variant-numeric: tabular-nums; }
    .ov-hours-info { font-size: 0.75rem; color: var(--av-muted-fg); margin-top: 0.5rem; line-height: 1.5; }
    .ov-editor-actions { padding: 0 1.5rem 1.25rem; display: flex; gap: 0.5rem; }

    /* Editor form (edit/create mode) */
    .ov-mode-group { display: flex; flex-direction: column; gap: 0.625rem; margin-bottom: 1rem; }
    .ov-mode-option { display: flex; align-items: center; gap: 0.625rem; cursor: pointer; font-size: 0.875rem; font-weight: 500; color: var(--av-fg); }
    .ov-mode-option input[type="radio"] { accent-color: var(--av-green); width: 1rem; height: 1rem; cursor: pointer; }
    .ov-closed-msg { padding: 0.75rem 1rem; border: 1px solid var(--av-border); border-radius: 0.5rem; background: var(--av-muted); font-size: 0.8125rem; color: var(--av-muted-fg); line-height: 1.5; }
    .ov-time-ranges { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.75rem; }
    .ov-time-range-row { display: flex; align-items: center; gap: 0.5rem; }
    .ov-time-select { position: relative; }
    .ov-time-select select { appearance: none; -webkit-appearance: none; width: 130px; height: 2.25rem; padding: 0 2rem 0 0.625rem; border: 1px solid var(--av-border); border-radius: 0.375rem; background: var(--av-bg); font-size: 0.8125rem; font-weight: 500; font-family: inherit; color: var(--av-fg); cursor: pointer; }
    .ov-time-select select:focus { outline: none; border-color: #18181b; }
    .ov-time-select::after { content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 0.625rem; top: 50%; transform: translateY(-50%); font-size: 0.5rem; color: var(--av-muted-fg); pointer-events: none; }
    .ov-range-sep { font-size: 0.8125rem; color: var(--av-muted-fg); }
    .ov-range-actions { display: flex; gap: 2px; margin-left: 2px; }
    .ov-editor-foot { padding: 0.875rem 1.5rem; border-top: 1px solid var(--av-border); display: flex; align-items: center; justify-content: space-between; }
    .ov-editor-foot-text { font-size: 0.75rem; color: var(--av-muted-fg); }
    .ov-editor-foot-btns { display: flex; gap: 0.5rem; }

    /* Override list below calendar */
    .ov-override-list { margin-top: 1rem; }
    .ov-override-item { display: flex; align-items: center; justify-content: space-between; padding: 0.625rem 0; border-bottom: 1px solid var(--av-border); font-size: 0.8125rem; }
    .ov-override-item:last-child { border-bottom: none; }
    .ov-override-date { font-weight: 500; color: var(--av-fg); }
    .ov-override-info { font-size: 0.75rem; color: var(--av-muted-fg); }
    .ov-override-rm { width: 1.75rem; height: 1.75rem; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; border-radius: 0.25rem; color: var(--av-muted-fg); cursor: pointer; transition: background 0.12s, color 0.12s; }
    .ov-override-rm:hover { background: #fef2f2; color: #dc2626; }
    .ov-override-rm svg { width: 0.875rem; height: 0.875rem; }

    @media (max-width: 768px) {
      .ov-layout { flex-direction: column; }
      .ov-layout > .ov-cal-col { flex: 0 0 auto; }
    }

    /* Bulk panel */
    .bulk-layout { display: flex; gap: 2rem; margin-top: 1.5rem; }
    .bulk-config { flex: 1; }
    .bulk-summary { flex: 0 0 340px; }
    .bulk-card { border: 1px solid var(--av-border); border-radius: 0.75rem; background: var(--av-bg); padding: 1.5rem; }
    .bulk-section { margin-bottom: 1.75rem; }
    .bulk-section:last-child { margin-bottom: 0; }
    .bulk-section-title { font-size: 0.8125rem; font-weight: 600; color: var(--av-fg); margin-bottom: 0.5rem; }
    .bulk-section-desc { font-size: 0.75rem; color: var(--av-muted-fg); margin-bottom: 0.75rem; line-height: 1.5; }
    .bulk-date-row { display: flex; align-items: center; gap: 0.75rem; }
    .bulk-date-row input[type="date"] { width: 160px; height: 2.25rem; padding: 0 0.625rem; border: 1px solid var(--av-border); border-radius: 0.375rem; font-size: 0.8125rem; font-family: inherit; background: var(--av-bg); }
    .bulk-date-row input[type="date"]:focus { outline: none; border-color: #18181b; box-shadow: 0 0 0 1px #18181b; }
    .bulk-date-arrow { color: var(--av-muted-fg); font-size: 0.875rem; }
    .bulk-date-error { font-size: 0.75rem; color: #dc2626; margin-top: 0.375rem; display: none; }

    /* Day chips */
    .bulk-day-chips { display: flex; flex-wrap: wrap; gap: 0.375rem; }
    .bulk-day-chip { padding: 0.375rem 0.75rem; border: 1px solid var(--av-border); border-radius: 9999px; font-size: 0.8125rem; font-weight: 500; cursor: pointer; transition: all 0.15s; user-select: none; background: var(--av-bg); color: var(--av-fg); }
    .bulk-day-chip.selected { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
    .bulk-day-quick { display: flex; gap: 0.75rem; margin-bottom: 0.5rem; }
    .bulk-day-quick button { border: none; background: none; font-size: 0.75rem; font-weight: 500; color: #18181b; cursor: pointer; padding: 0; font-family: inherit; }
    .bulk-day-quick button:hover { text-decoration: underline; }

    /* Segmented control */
    .bulk-segmented { display: flex; border: 1px solid var(--av-border); border-radius: 0.5rem; overflow: hidden; }
    .bulk-segmented button { flex: 1; padding: 0.5rem 0.75rem; border: none; background: var(--av-bg); font-size: 0.8125rem; font-weight: 500; color: var(--av-fg); cursor: pointer; transition: all 0.15s; font-family: inherit; border-right: 1px solid var(--av-border); }
    .bulk-segmented button:last-child { border-right: none; }
    .bulk-segmented button.active { background: var(--av-fg); color: #fff; }
    .bulk-segmented button:hover:not(.active) { background: var(--av-muted); }
    .bulk-closed-msg { padding: 0.75rem 1rem; border: 1px solid var(--av-border); border-radius: 0.5rem; background: #fffbeb; color: #92400e; font-size: 0.8125rem; line-height: 1.5; margin-top: 0.75rem; display: flex; gap: 0.5rem; align-items: flex-start; }
    .bulk-closed-msg svg { flex-shrink: 0; margin-top: 1px; }
    .bulk-restore-msg { padding: 0.75rem 1rem; border: 1px solid var(--av-border); border-radius: 0.5rem; background: var(--av-muted); font-size: 0.8125rem; color: var(--av-muted-fg); line-height: 1.5; margin-top: 0.75rem; }

    /* Time ranges (same as overrides) */
    .bulk-time-ranges { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.75rem; }

    /* Summary card */
    .bulk-sum-card { border: 1px solid var(--av-border); border-radius: 0.75rem; background: var(--av-bg); padding: 1.5rem; position: sticky; top: 1rem; }
    .bulk-sum-title { font-size: 0.875rem; font-weight: 600; color: var(--av-fg); margin-bottom: 1rem; }
    .bulk-sum-count { font-size: 2rem; font-weight: 700; color: var(--av-fg); line-height: 1; }
    .bulk-sum-count-label { font-size: 0.8125rem; color: var(--av-muted-fg); margin-top: 0.25rem; }
    .bulk-sum-days { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.75rem; }
    .bulk-sum-day { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.6875rem; font-weight: 500; background: var(--av-muted); color: var(--av-muted-fg); }
    .bulk-sum-divider { height: 1px; background: var(--av-border); margin: 1rem 0; }
    .bulk-sum-label { font-size: 0.75rem; font-weight: 600; color: var(--av-muted-fg); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.375rem; }
    .bulk-sum-range { font-size: 0.8125rem; color: var(--av-fg); font-variant-numeric: tabular-nums; line-height: 1.6; }
    .bulk-sum-arrow { text-align: center; color: var(--av-muted-fg); font-size: 1.25rem; margin: 0.75rem 0; }
    .bulk-sum-impact { padding: 0.625rem 0.75rem; border: 1px solid var(--av-border); border-radius: 0.375rem; background: var(--av-muted); font-size: 0.75rem; color: var(--av-muted-fg); margin-top: 0.75rem; line-height: 1.5; }
    .bulk-sum-impact strong { color: var(--av-fg); font-weight: 600; }

    /* Footer */
    .bulk-footer { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--av-border); }

    /* Confirm modal */
    .bulk-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: none; align-items: center; justify-content: center; }
    .bulk-modal-overlay.open { display: flex; }
    .bulk-modal { background: var(--av-bg); border-radius: 0.75rem; box-shadow: 0 16px 48px rgba(0,0,0,0.15); width: 28rem; max-width: 90vw; max-height: 80vh; overflow-y: auto; }
    .bulk-modal-head { padding: 1.25rem 1.5rem 0; }
    .bulk-modal-head h3 { font-size: 1rem; font-weight: 600; color: var(--av-fg); }
    .bulk-modal-head p { font-size: 0.8125rem; color: var(--av-muted-fg); margin-top: 0.25rem; }
    .bulk-modal-body { padding: 1rem 1.5rem; }
    .bulk-modal-row { display: flex; justify-content: space-between; font-size: 0.8125rem; padding: 0.375rem 0; }
    .bulk-modal-row span:first-child { color: var(--av-muted-fg); }
    .bulk-modal-row span:last-child { font-weight: 500; color: var(--av-fg); }
    .bulk-modal-foot { padding: 0.875rem 1.5rem; border-top: 1px solid var(--av-border); display: flex; justify-content: flex-end; gap: 0.5rem; }

    /* Empty state */
    .bulk-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3rem 1rem; text-align: center; color: var(--av-muted-fg); }
    .bulk-empty svg { width: 2.5rem; height: 2.5rem; margin-bottom: 0.75rem; opacity: 0.4; }
    .bulk-empty p { font-size: 0.875rem; }

    @media (max-width: 900px) {
      .bulk-layout { flex-direction: column; }
      .bulk-summary { flex: 0 0 auto; }
    }

    /* Preview panel */
    .pv-layout { display: flex; gap: 2rem; margin-top: 1.5rem; }
    .pv-cal-col { flex: 0 0 340px; }
    .pv-detail-col { flex: 1; min-width: 0; }
    .pv-card { border: 1px solid var(--av-border); border-radius: 0.75rem; background: var(--av-bg); overflow: hidden; }

    /* Calendar */
    .pv-cal { padding: 1.25rem; }
    .pv-cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
    .pv-cal-nav-btn { width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; border-radius: 0.375rem; cursor: pointer; color: var(--av-muted-fg); transition: background 0.15s, color 0.15s; }
    .pv-cal-nav-btn:hover { background: var(--av-muted); color: var(--av-fg); }
    .pv-cal-nav-btn svg { width: 1rem; height: 1rem; }
    .pv-cal-month { font-size: 0.875rem; font-weight: 600; color: var(--av-fg); }
    .pv-cal-today { border: none; background: none; font-size: 0.75rem; font-weight: 500; color: #18181b; cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-family: inherit; }
    .pv-cal-today:hover { background: #f0fdf4; }
    .pv-cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); }
    .pv-cal-weekdays span { text-align: center; font-size: 0.6875rem; font-weight: 600; color: var(--av-muted-fg); padding: 0.375rem 0; text-transform: uppercase; letter-spacing: 0.05em; }
    .pv-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--av-border); border: 1px solid var(--av-border); border-radius: 0.375rem; overflow: hidden; }
    .pv-cal-day { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 2.5rem; font-size: 0.8125rem; font-weight: 500; cursor: pointer; transition: background 0.12s; background: var(--av-bg); color: var(--av-fg); position: relative; }
    .pv-cal-day:hover { background: var(--av-muted); }
    .pv-cal-day.other { color: var(--av-muted-fg); opacity: 0.4; pointer-events: none; }
    .pv-cal-day.today { font-weight: 700; }
    .pv-cal-day.selected { background: #f0fdf4; color: #166534; font-weight: 600; box-shadow: inset 0 0 0 2px var(--av-green); }
    .pv-cal-day.available { background: #f0fdf4; }
    .pv-cal-day.closed { color: var(--av-muted-fg); opacity: 0.6; }
    .pv-cal-day.has-override::after { content: ''; position: absolute; bottom: 4px; width: 4px; height: 4px; border-radius: 50%; background: var(--av-green); }
    .pv-cal-day.selected.has-override::after { background: #166534; }
    .pv-cal-legend { display: flex; gap: 1rem; padding: 0.75rem 1.25rem; border-top: 1px solid var(--av-border); font-size: 0.6875rem; color: var(--av-muted-fg); }
    .pv-cal-legend span { display: flex; align-items: center; gap: 0.25rem; }
    .pv-cal-legend .pv-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .pv-cal-legend .pv-dot.avail { background: #166534; }
    .pv-cal-legend .pv-dot.closed { background: var(--av-muted-fg); }
    .pv-cal-legend .pv-dot.custom { background: var(--av-green); }

    /* Detail panel */
    .pv-detail { min-height: 20rem; }
    .pv-detail-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 20rem; color: var(--av-muted-fg); font-size: 0.875rem; text-align: center; padding: 2rem; }
    .pv-detail-empty svg { width: 2.5rem; height: 2.5rem; margin-bottom: 0.75rem; opacity: 0.4; }
    .pv-detail-head { padding: 1.25rem 1.5rem 0; }
    .pv-detail-date { font-size: 1.125rem; font-weight: 600; color: var(--av-fg); }
    .pv-detail-badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.6875rem; font-weight: 500; margin-left: 0.5rem; vertical-align: middle; }
    .pv-detail-badge.weekly { background: var(--av-muted); color: var(--av-muted-fg); }
    .pv-detail-badge.custom { background: #f0fdf4; color: #166534; }
    .pv-detail-badge.closed-badge { background: #fef2f2; color: #991b1b; }
    .pv-detail-source { font-size: 0.75rem; color: var(--av-muted-fg); margin-top: 0.25rem; }
    .pv-detail-nav { display: flex; align-items: center; justify-content: space-between; margin-top: 0.75rem; padding: 0 1.5rem; }
    .pv-detail-nav button { border: none; background: none; font-size: 0.8125rem; font-weight: 500; color: var(--av-muted-fg); cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-family: inherit; display: flex; align-items: center; gap: 0.25rem; }
    .pv-detail-nav button:hover { background: var(--av-muted); color: var(--av-fg); }
    .pv-detail-nav button svg { width: 0.875rem; height: 0.875rem; }
    .pv-detail-body { padding: 1rem 1.5rem 1.5rem; }
    .pv-detail-label { font-size: 0.8125rem; font-weight: 600; color: var(--av-fg); margin-bottom: 0.75rem; }

    /* Slot list */
    .pv-slots { border: 1px solid var(--av-border); border-radius: 0.5rem; overflow: hidden; }
    .pv-slot { display: flex; align-items: center; justify-content: space-between; padding: 0.625rem 0.875rem; border-bottom: 1px solid var(--av-border); font-size: 0.8125rem; }
    .pv-slot:last-child { border-bottom: none; }
    .pv-slot-time { font-weight: 500; color: var(--av-fg); font-variant-numeric: tabular-nums; }
    .pv-slot-status { font-size: 0.75rem; font-weight: 500; }
    .pv-slot-status.open { color: #166534; }
    .pv-slot-status.closed { color: var(--av-muted-fg); }
    .pv-slot-count { font-size: 0.75rem; color: var(--av-muted-fg); margin-top: 0.75rem; text-align: right; }

    /* Closed state */
    .pv-closed { display: flex; flex-direction: column; align-items: center; padding: 2.5rem 1.5rem; text-align: center; }
    .pv-closed svg { width: 2.5rem; height: 2.5rem; color: var(--av-muted-fg); opacity: 0.5; margin-bottom: 0.75rem; }
    .pv-closed h4 { font-size: 0.9375rem; font-weight: 600; color: var(--av-fg); margin-bottom: 0.25rem; }
    .pv-closed p { font-size: 0.8125rem; color: var(--av-muted-fg); line-height: 1.5; }
    .pv-closed-status { margin-top: 1rem; font-size: 0.75rem; color: var(--av-muted-fg); }
    .pv-closed-status strong { font-weight: 600; color: var(--av-fg); }

    /* View toggle */
    .pv-view-toggle { display: flex; gap: 0.5rem; align-items: center; font-size: 0.8125rem; }
    .pv-view-toggle button { border: 1px solid var(--av-border); background: var(--av-bg); padding: 0.375rem 0.75rem; border-radius: 0.375rem; font-size: 0.8125rem; font-weight: 500; cursor: pointer; font-family: inherit; color: var(--av-fg); transition: all 0.15s; }
    .pv-view-toggle button.active { background: var(--av-fg); color: #fff; border-color: var(--av-fg); }
    .pv-view-toggle button:hover:not(.active) { background: var(--av-muted); }

    @media (max-width: 768px) {
      .pv-layout { flex-direction: column; }
      .pv-cal-col { flex: 0 0 auto; }
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .av-columns { flex-direction: column; }
      .av-col-left, .av-col-right { flex: 0 0 100%; max-width: 100%; border-left: none; padding-left: 0; }
    }
    @media (max-width: 640px) {
      .av-page { padding: 1rem; }
      .av-day-label { width: 8rem; min-width: 8rem; }
      .av-time-select { width: 110px; }
      .av-footer { padding: 0.75rem 1rem; }
    }
  </style>
</head>
<body><?php include 'includes/staff-sidebar.php'; ?>
<main class="main-content">
  <div class="av-page">

    <!-- Header -->
    <div class="av-header">
      <div class="av-header-left">
        <h1>Availability</h1>
        <p>Set and manage when appointments can be booked.</p>
      </div>
      <button class="av-tz-btn" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        GMT+08:00 Asia/Manila
      </button>
    </div>
    <hr class="av-separator" />

    <!-- PANEL: Weekly Schedule -->
    <div class="av-panel<?php echo $panel === '' ? ' active' : ''; ?>" id="panel-weekly">
    <!-- Two-column layout -->
    <div class="av-columns">

      <!-- LEFT: Weekly Availability -->
      <div class="av-col-left">
        <div class="av-section-head">
          <h2>Weekly Availability</h2>
          <span class="av-tip-wrap">
            <button class="av-tip-btn" type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </button>
            <span class="av-tip-box">Define recurring hours for each day of the week.</span>
          </span>
        </div>
        <p class="av-section-desc">Set your recurring hours for each day of the week.</p>
        <div class="av-days" id="weeklyDays"></div>
      </div>

      <!-- RIGHT: Date-Specific Availability -->
      <div class="av-col-right av-date-section">
        <div class="av-section-head">
          <h2>Date-Specific Availability</h2>
          <span class="av-tip-wrap">
            <button class="av-tip-btn" type="button">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </button>
            <span class="av-tip-box">Add custom availability for specific dates.</span>
          </span>
        </div>
        <p class="av-section-desc">Add custom availability for specific dates. Changes here override your regular weekly schedule.</p>
        <div style="font-size: 0.875rem; font-weight: 600; color: var(--av-fg); margin-bottom: 0.75rem;" id="dateMonthLabel"></div>
        <button class="av-add-date-btn" type="button" onclick="openDateModal()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add date-specific availability
        </button>
        <div class="av-override-list" id="overrideList"></div>
        <div class="av-empty-state" id="emptyOverrides">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><circle cx="12" cy="15" r="2"/><line x1="12" y1="13" x2="12" y2="17"/><line x1="10" y1="15" x2="14" y2="15"/></svg>
          <h3>No date-specific availability</h3>
          <p>Add specific dates to override your weekly schedule.</p>
        </div>
      </div>
    </div>
    </div><!-- /panel-weekly -->

    <!-- PANEL: Date Overrides -->
    <div class="av-panel<?php echo $panel === 'overrides' ? ' active' : ''; ?>" id="panel-overrides">
      <div class="av-section-head">
        <h2>Date Overrides</h2>
      </div>
      <p class="av-section-desc">Change the availability for specific dates without affecting your recurring weekly schedule.</p>

      <div class="ov-layout">
        <!-- Left: Calendar -->
        <div class="ov-cal-col">
          <div class="ov-cal">
            <div class="ov-cal-nav">
              <button class="ov-cal-nav-btn" type="button" id="ovCalPrev">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <span class="ov-cal-month" id="ovCalMonth"></span>
              <button class="ov-cal-nav-btn" type="button" id="ovCalNext">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
              </button>
            </div>
            <div class="ov-cal-weekdays">
              <span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span>
            </div>
            <div class="ov-cal-grid" id="ovCalGrid"></div>
            <div class="ov-cal-legend">
              <span><span class="ov-dot green"></span> Custom schedule</span>
              <span><span class="ov-dot gray"></span> Weekly schedule</span>
            </div>
          </div>

          <div class="ov-override-list" id="ovOverrideList"></div>
        </div>

        <!-- Right: Editor -->
        <div class="ov-editor-col">
          <div class="ov-editor" id="ovEditor">
            <div class="ov-editor-empty" id="ovEditorEmpty">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              Select a date to view or edit its availability
            </div>

            <!-- View mode (has override) -->
            <div id="ovEditorView" style="display:none;">
              <div class="ov-editor-head">
                <div class="ov-editor-date" id="ovViewDate"></div>
                <div class="ov-editor-sub" id="ovViewSub"></div>
                <div class="ov-editor-status" id="ovViewStatus"></div>
              </div>
              <div class="ov-editor-body">
                <div class="ov-editor-label">Date-specific availability</div>
                <div class="ov-hours-display" id="ovViewHours"></div>
                <div class="ov-hours-info">This date has a custom schedule that overrides the weekly schedule.</div>
              </div>
              <div class="ov-editor-actions">
                <button class="av-btn av-btn-ghost" type="button" id="ovEditBtn">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  Edit override
                </button>
                <button class="av-btn av-btn-ghost" type="button" style="color:var(--av-destructive,#dc2626);" id="ovRemoveBtn">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                  Remove override
                </button>
              </div>
            </div>

            <!-- View mode (no override) -->
            <div id="ovEditorNoOv" style="display:none;">
              <div class="ov-editor-head">
                <div class="ov-editor-date" id="ovNoOvDate"></div>
                <div class="ov-editor-sub">Using weekly schedule</div>
                <div class="ov-editor-status">
                  <span class="ov-status-dot" style="background:var(--av-muted-fg);"></span>
                  <span style="color:var(--av-muted-fg);">Weekly schedule</span>
                </div>
              </div>
              <div class="ov-editor-body">
                <div class="ov-editor-label">Current availability</div>
                <div class="ov-hours-display" id="ovNoOvHours"></div>
                <div class="ov-hours-info">This date currently follows the weekly schedule.</div>
              </div>
              <div class="ov-editor-actions">
                <button class="av-btn av-btn-primary" type="button" id="ovCreateBtn">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Create date override
                </button>
              </div>
            </div>

            <!-- Edit/Create mode -->
            <div id="ovEditorEdit" style="display:none;">
              <div class="ov-editor-head">
                <div class="ov-editor-date" id="ovEditDate"></div>
                <div class="ov-editor-sub" id="ovEditSub">Date-specific availability</div>
              </div>
              <div class="ov-editor-body">
                <div class="ov-mode-group">
                  <label class="ov-mode-option"><input type="radio" name="ovMode" value="weekly" /> Use weekly schedule</label>
                  <label class="ov-mode-option"><input type="radio" name="ovMode" value="custom" checked /> Custom schedule</label>
                  <label class="ov-mode-option"><input type="radio" name="ovMode" value="closed" /> Closed</label>
                </div>
                <div id="ovClosedMsg" style="display:none;">
                  <div class="ov-closed-msg">This date will have no available appointment times.</div>
                </div>
                <div id="ovCustomEditor" style="display:none;">
                  <div class="ov-editor-label">Available hours</div>
                  <div class="ov-time-ranges" id="ovTimeRanges"></div>
                  <button class="av-btn av-btn-ghost" type="button" id="ovAddRange" style="margin-top:0.5rem;padding:0.375rem 0.625rem;font-size:0.8125rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add time range
                  </button>
                </div>
              </div>
              <div class="ov-editor-foot">
                <span class="ov-editor-foot-text" id="ovEditFootText"></span>
                <div class="ov-editor-foot-btns">
                  <button class="av-btn av-btn-ghost" type="button" id="ovEditCancel">Cancel</button>
                  <button class="av-btn av-btn-primary" type="button" id="ovEditSave">Save override</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- PANEL: Bulk Schedule -->
    <div class="av-panel<?php echo $panel === 'bulk' ? ' active' : ''; ?>" id="panel-bulk">
      <div class="av-section-head">
        <h2>Bulk Schedule</h2>
      </div>
      <p class="av-section-desc">Apply availability changes to multiple dates at once without changing your recurring weekly schedule.</p>
      <p class="bulk-section-desc" style="margin-top:0.25rem;">Use this for temporary changes such as holidays, clinic closures, or special operating periods.</p>

      <div class="bulk-layout">
        <!-- Left: Configuration -->
        <div class="bulk-config">
          <div class="bulk-card">

            <!-- Section A: Date range -->
            <div class="bulk-section">
              <div class="bulk-section-title">Date range</div>
              <div class="bulk-date-row">
                <input type="date" id="bulkStart" />
                <span class="bulk-date-arrow">→</span>
                <input type="date" id="bulkEnd" />
              </div>
              <div class="bulk-date-error" id="bulkDateError">End date must be after the start date.</div>
            </div>

            <!-- Section B: Days of week -->
            <div class="bulk-section">
              <div class="bulk-section-title">Apply to these days</div>
              <div class="bulk-day-quick">
                <button type="button" id="bulkSelectWeekdays">Select weekdays</button>
                <button type="button" id="bulkSelectAll">Select all</button>
              </div>
              <div class="bulk-day-chips" id="bulkDays"></div>
            </div>

            <!-- Section C: Action -->
            <div class="bulk-section">
              <div class="bulk-section-title">Action</div>
              <div class="bulk-segmented" id="bulkAction">
                <button type="button" data-action="available" class="active">Available</button>
                <button type="button" data-action="closed">Closed</button>
                <button type="button" data-action="restore">Restore weekly</button>
              </div>
              <div class="bulk-closed-msg" id="bulkClosedMsg" style="display:none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                These dates will have no available appointment times.
              </div>
              <div class="bulk-restore-msg" id="bulkRestoreMsg" style="display:none;">
                This will remove date-specific overrides from the selected dates. The dates will follow their normal weekly schedule.
              </div>
            </div>

            <!-- Section D: Time availability -->
            <div class="bulk-section" id="bulkTimeSection">
              <div class="bulk-section-title">Available hours</div>
              <div class="bulk-time-ranges" id="bulkTimeRanges"></div>
              <button class="av-btn av-btn-ghost" type="button" id="bulkAddRange" style="margin-top:0.5rem;padding:0.375rem 0.625rem;font-size:0.8125rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add time range
              </button>
            </div>
          </div>

          <div class="bulk-footer">
            <button class="av-btn av-btn-ghost" type="button" id="bulkCancel">Cancel</button>
            <button class="av-btn av-btn-primary" type="button" id="bulkReview" disabled>Review changes</button>
          </div>
        </div>

        <!-- Right: Summary -->
        <div class="bulk-summary">
          <div class="bulk-sum-card" id="bulkSumCard">
            <div class="bulk-sum-title">Change Summary</div>
            <div id="bulkSumContent">
              <div class="bulk-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <p>Select a date range and days to begin configuring a bulk change.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Confirm modal -->
      <div class="bulk-modal-overlay" id="bulkModal">
        <div class="bulk-modal">
          <div class="bulk-modal-head">
            <h3>Review bulk changes</h3>
            <p>You are about to change availability for:</p>
          </div>
          <div class="bulk-modal-body" id="bulkModalBody"></div>
          <div class="bulk-modal-foot">
            <button class="av-btn av-btn-ghost" type="button" id="bulkModalBack">Back</button>
            <button class="av-btn av-btn-primary" type="button" id="bulkModalConfirm">Confirm changes</button>
          </div>
        </div>
      </div>
    </div>

    <!-- PANEL: Schedule Preview -->
    <div class="av-panel<?php echo $panel === 'preview' ? ' active' : ''; ?>" id="panel-preview">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <div>
          <div class="av-section-head"><h2>Schedule Preview</h2></div>
          <p class="av-section-desc">See exactly how your appointment availability will appear to residents when booking.</p>
        </div>
        <div style="display:flex;align-items:center;gap:1rem;">
          <div class="pv-view-toggle" id="pvViewToggle">
            <button type="button" class="active" data-view="resident">Resident</button>
            <button type="button" data-view="admin">Admin details</button>
          </div>
          <span style="font-size:0.75rem;color:var(--av-muted-fg);display:flex;align-items:center;gap:0.375rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Resident preview
          </span>
        </div>
      </div>

      <div class="pv-layout">
        <!-- Left: Calendar -->
        <div class="pv-cal-col">
          <div class="pv-card">
            <div class="pv-cal">
              <div class="pv-cal-nav">
                <button class="pv-cal-nav-btn" type="button" id="pvCalPrev">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <span class="pv-cal-month" id="pvCalMonth"></span>
                <div style="display:flex;gap:0.25rem;">
                  <button class="pv-cal-today" type="button" id="pvCalToday">Today</button>
                  <button class="pv-cal-nav-btn" type="button" id="pvCalNext">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>
              </div>
              <div class="pv-cal-weekdays">
                <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
              </div>
              <div class="pv-cal-grid" id="pvCalGrid"></div>
            </div>
            <div class="pv-cal-legend">
              <span><span class="pv-dot avail"></span> Available</span>
              <span><span class="pv-dot closed"></span> Closed</span>
              <span><span class="pv-dot custom"></span> Custom</span>
            </div>
          </div>
        </div>

        <!-- Right: Detail -->
        <div class="pv-detail-col">
          <div class="pv-card pv-detail" id="pvDetail">
            <div class="pv-detail-empty" id="pvDetailEmpty">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              Select a date to view available appointment times
            </div>
            <div id="pvDetailContent" style="display:none;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sticky Footer -->
  <div class="av-footer" id="avFooter">
    <span class="av-footer-text">You have unsaved changes.</span>
    <div class="av-footer-actions">
      <button type="button" class="sd-btn sd-btn-outline" onclick="avDiscard()">Discard</button>
      <button type="button" class="sd-btn sd-btn-default" onclick="avSave()">Save changes</button>
    </div>
  </div>

  <!-- Date Override Modal -->
  <div class="av-modal-overlay" id="dateModalOverlay" onclick="if(event.target===this)closeDateModal()">
    <div class="av-modal">
      <button type="button" class="av-modal-close" onclick="closeDateModal()" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="av-modal-head">
        <h3>Add Date-Specific Availability</h3>
        <p class="av-modal-sub">Choose a date and set which time slots are available.</p>
      </div>
      <div class="av-modal-field">
        <label for="modalDateInput">Date</label>
        <input type="date" id="modalDateInput" />
      </div>
      <div id="modalSlotsWrap" style="display:none;">
        <div style="font-size:0.8125rem; font-weight:500; color:var(--av-fg); margin-bottom:0.5rem;">Time Slots</div>
        <div class="av-modal-slots" id="modalSlots"></div>
      </div>
      <div class="av-modal-actions">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeDateModal()">Cancel</button>
        <button type="button" class="sd-btn sd-btn-default" id="modalSaveBtn" onclick="saveDateOverride()" disabled>Save</button>
      </div>
    </div>
  </div>

  <!-- Toast container -->
  <div class="av-toast-container" id="avToastContainer"></div>  <script>
  (function() {
    'use strict';

    var DAY_NAMES = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var DAY_SHORT = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var TIME_OPTIONS = generateTimeOptions();
    var AV_SLOTS = ['09:00','10:00','11:00','14:00','15:00'];

    var avState = [
      { day: 'Sunday',    enabled: false, blocks: [{ id: 1, start: '09:00 AM', end: '05:00 PM' }] },
      { day: 'Monday',    enabled: true,  blocks: [{ id: 2, start: '09:00 AM', end: '05:00 PM' }] },
      { day: 'Tuesday',   enabled: true,  blocks: [{ id: 3, start: '09:00 AM', end: '05:00 PM' }] },
      { day: 'Wednesday', enabled: true,  blocks: [{ id: 4, start: '09:00 AM', end: '05:00 PM' }] },
      { day: 'Thursday',  enabled: true,  blocks: [{ id: 5, start: '09:00 AM', end: '05:00 PM' }] },
      { day: 'Friday',    enabled: true,  blocks: [{ id: 6, start: '09:00 AM', end: '05:00 PM' }] },
      { day: 'Saturday',  enabled: false, blocks: [{ id: 7, start: '09:00 AM', end: '05:00 PM' }] }
    ];
    var dateOverrides = [];
    var nextBlockId = 100;
    var openCopyPopover = null;
    var serverAvState = null;
    var serverOverridesCount = 0;

    function generateTimeOptions() {
      var opts = [];
      for (var i = 0; i < 48; i++) {
        var h = Math.floor(i / 2);
        var m = (i % 2) * 30;
        var ap = h >= 12 ? 'PM' : 'AM';
        var hh12 = h % 12; if (hh12 === 0) hh12 = 12;
        var label = String(hh12).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ' ' + ap;
        var val = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        opts.push({ value: val, label: label });
      }
      return opts;
    }

    function timeValToLabel(v) {
      for (var i = 0; i < TIME_OPTIONS.length; i++) {
        if (TIME_OPTIONS[i].value === v) return TIME_OPTIONS[i].label;
      }
      return v;
    }

    function format12To24(t12) {
      var parts = t12.split(' ');
      var hm = parts[0].split(':');
      var h = parseInt(hm[0], 10);
      var m = hm[1];
      if (parts[1] === 'PM' && h !== 12) h += 12;
      if (parts[1] === 'AM' && h === 12) h = 0;
      return String(h).padStart(2, '0') + ':' + m;
    }

    function svgIcon(name) {
      var icons = {
        plus: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        minus: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        trash: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        copy: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        x: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
      };
      return icons[name] || '';
    }    function renderDays() {
      var container = document.getElementById('weeklyDays');
      container.innerHTML = '';
      for (var d = 0; d < 7; d++) {
        var day = avState[d];
        var row = document.createElement('div');
        row.className = 'av-day-row';

        var label = document.createElement('div');
        label.className = 'av-day-label';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = day.enabled;
        cb.dataset.day = d;
        cb.addEventListener('change', onDayToggle);
        var span = document.createElement('span');
        span.textContent = day.day;
        label.appendChild(cb);
        label.appendChild(span);
        row.appendChild(label);

        if (!day.enabled) {
          var unavail = document.createElement('span');
          unavail.className = 'av-unavailable-text';
          unavail.textContent = 'Unavailable';
          row.appendChild(unavail);
        } else {
          var blocksWrap = document.createElement('div');
          blocksWrap.className = 'av-time-blocks';
          for (var b = 0; b < day.blocks.length; b++) {
            var block = day.blocks[b];
            var timeRow = document.createElement('div');
            timeRow.className = 'av-time-row';
            timeRow.style.position = 'relative';

            var startSel = createTimeSelect(block.start, d, block.id, 'start');
            timeRow.appendChild(startSel);

            var sep = document.createElement('span');
            sep.className = 'av-time-sep';
            sep.textContent = '\u2014';
            timeRow.appendChild(sep);

            var endSel = createTimeSelect(block.end, d, block.id, 'end');
            timeRow.appendChild(endSel);

            var addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'av-icon-btn';
            addBtn.innerHTML = svgIcon('plus');
            addBtn.title = 'Add time block';
            addBtn.dataset.day = d;
            addBtn.addEventListener('click', addBlock);
            timeRow.appendChild(addBtn);

            var rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.className = 'av-icon-btn' + (day.blocks.length === 1 ? ' danger' : '');
            rmBtn.innerHTML = day.blocks.length === 1 ? svgIcon('trash') : svgIcon('minus');
            rmBtn.title = day.blocks.length === 1 ? 'Remove and disable day' : 'Remove time block';
            rmBtn.dataset.day = d;
            rmBtn.dataset.blockId = block.id;
            rmBtn.addEventListener('click', removeBlock);
            timeRow.appendChild(rmBtn);

            if (b === 0) {
              var copyBtn = document.createElement('button');
              copyBtn.type = 'button';
              copyBtn.className = 'av-icon-btn';
              copyBtn.innerHTML = svgIcon('copy');
              copyBtn.title = 'Copy to other days';
              copyBtn.dataset.day = d;
              copyBtn.addEventListener('click', toggleCopyPopover);
              timeRow.appendChild(copyBtn);

              var popover = document.createElement('div');
              popover.className = 'av-copy-popover';
              popover.dataset.day = d;
              var pTitle = document.createElement('div');
              pTitle.className = 'av-copy-popover-title';
              pTitle.textContent = 'Copy to days:';
              popover.appendChild(pTitle);
              var checks = document.createElement('div');
              checks.className = 'av-copy-checks';
              for (var td = 0; td < 7; td++) {
                if (td === d) continue;
                var cl = document.createElement('label');
                var ccb = document.createElement('input');
                ccb.type = 'checkbox';
                ccb.dataset.target = td;
                cl.appendChild(ccb);
                cl.appendChild(document.createTextNode(avState[td].day));
                checks.appendChild(cl);
              }
              popover.appendChild(checks);
              var pActions = document.createElement('div');
              pActions.className = 'av-copy-actions';
              var cancelBtn = document.createElement('button');
              cancelBtn.type = 'button';
              cancelBtn.className = 'av-copy-cancel';
              cancelBtn.textContent = 'Cancel';
              cancelBtn.addEventListener('click', function() { closeCopyPopovers(); });
              pActions.appendChild(cancelBtn);
              var applyBtn = document.createElement('button');
              applyBtn.type = 'button';
              applyBtn.className = 'av-copy-apply';
              applyBtn.textContent = 'Apply';
              applyBtn.dataset.day = d;
              applyBtn.addEventListener('click', applyCopy);
              pActions.appendChild(applyBtn);
              popover.appendChild(pActions);
              timeRow.appendChild(popover);
            }

            blocksWrap.appendChild(timeRow);
          }
          row.appendChild(blocksWrap);
        }
        container.appendChild(row);
      }
      updateFooter();
    }

    function createTimeSelect(selectedLabel, dayIdx, blockId, field) {
      var sel = document.createElement('select');
      sel.className = 'av-time-select';
      for (var i = 0; i < TIME_OPTIONS.length; i++) {
        var opt = document.createElement('option');
        opt.value = TIME_OPTIONS[i].label;
        opt.textContent = TIME_OPTIONS[i].label;
        if (TIME_OPTIONS[i].label === selectedLabel) opt.selected = true;
        sel.appendChild(opt);
      }
      sel.dataset.day = dayIdx;
      sel.dataset.blockId = blockId;
      sel.dataset.field = field;
      sel.addEventListener('change', onTimeChange);
      return sel;
    }    function onDayToggle(e) {
      var d = parseInt(e.target.dataset.day, 10);
      avState[d].enabled = e.target.checked;
      renderDays();
      updateFooter();
    }

    function onTimeChange(e) {
      var d = parseInt(e.target.dataset.day, 10);
      var bid = parseInt(e.target.dataset.blockId, 10);
      var field = e.target.dataset.field;
      var day = avState[d];
      for (var b = 0; b < day.blocks.length; b++) {
        if (day.blocks[b].id === bid) {
          day.blocks[b][field] = e.target.value;
          break;
        }
      }
      updateFooter();
    }

    function addBlock(e) {
      var btn = e.target.closest('button');
      var d = parseInt(btn.dataset.day, 10);
      avState[d].blocks.push({
        id: ++nextBlockId,
        start: '01:00 PM',
        end: '05:00 PM'
      });
      renderDays();
    }

    function removeBlock(e) {
      var btn = e.target.closest('button');
      var d = parseInt(btn.dataset.day, 10);
      var bid = parseInt(btn.dataset.blockId, 10);
      var day = avState[d];
      if (day.blocks.length === 1) {
        day.enabled = false;
        day.blocks = [{ id: ++nextBlockId, start: '09:00 AM', end: '05:00 PM' }];
      } else {
        day.blocks = day.blocks.filter(function(b) { return b.id !== bid; });
      }
      renderDays();
    }

    function toggleCopyPopover(e) {
      var btn = e.target.closest('button');
      var d = parseInt(btn.dataset.day, 10);
      var wasOpen = openCopyPopover === d;
      closeCopyPopovers();
      if (!wasOpen) {
        var pop = document.querySelector('.av-copy-popover[data-day="' + d + '"]');
        if (pop) { pop.classList.add('open'); openCopyPopover = d; }
      }
    }

    function closeCopyPopovers() {
      document.querySelectorAll('.av-copy-popover').forEach(function(p) { p.classList.remove('open'); });
      openCopyPopover = null;
    }

    function applyCopy(e) {
      var d = parseInt(e.target.dataset.day, 10);
      var srcDay = avState[d];
      var pop = document.querySelector('.av-copy-popover[data-day="' + d + '"]');
      var targets = pop.querySelectorAll('input[type="checkbox"]:checked');
      var count = 0;
      targets.forEach(function(cb) {
        var td = parseInt(cb.dataset.target, 10);
        avState[td].enabled = true;
        avState[td].blocks = srcDay.blocks.map(function(b) {
          return { id: ++nextBlockId, start: b.start, end: b.end };
        });
        count++;
      });
      closeCopyPopovers();
      renderDays();
      if (count > 0) toast('Schedule copied', { description: 'Applied to ' + count + ' day(s).' });
    }    function hasUnsavedChanges() {
      if (serverAvState) {
        for (var d = 0; d < 7; d++) {
          if (avState[d].enabled !== serverAvState[d].enabled) return true;
          if (avState[d].blocks.length !== serverAvState[d].blocks.length) return true;
          for (var b = 0; b < avState[d].blocks.length; b++) {
            if (avState[d].blocks[b].start !== serverAvState[d].blocks[b].start) return true;
            if (avState[d].blocks[b].end !== serverAvState[d].blocks[b].end) return true;
          }
        }
      }
      if (dateOverrides.length !== serverOverridesCount) return true;
      return false;
    }

    function updateFooter() {
      var footer = document.getElementById('avFooter');
      if (hasUnsavedChanges()) {
        footer.classList.add('visible');
      } else {
        footer.classList.remove('visible');
      }
    }

    function avDiscard() {
      avState = [
        { day: 'Sunday',    enabled: false, blocks: [{ id: 1, start: '09:00 AM', end: '05:00 PM' }] },
        { day: 'Monday',    enabled: true,  blocks: [{ id: 2, start: '09:00 AM', end: '05:00 PM' }] },
        { day: 'Tuesday',   enabled: true,  blocks: [{ id: 3, start: '09:00 AM', end: '05:00 PM' }] },
        { day: 'Wednesday', enabled: true,  blocks: [{ id: 4, start: '09:00 AM', end: '05:00 PM' }] },
        { day: 'Thursday',  enabled: true,  blocks: [{ id: 5, start: '09:00 AM', end: '05:00 PM' }] },
        { day: 'Friday',    enabled: true,  blocks: [{ id: 6, start: '09:00 AM', end: '05:00 PM' }] },
        { day: 'Saturday',  enabled: false, blocks: [{ id: 7, start: '09:00 AM', end: '05:00 PM' }] }
      ];
      dateOverrides = [];
      nextBlockId = 100;
      renderDays();
      renderOverrides();
      ovRenderCal(); ovRenderEditor(); ovRenderOverrideList();
      toast('Changes discarded', { type: 'info', description: 'All unsaved changes have been reverted.' });
    }

    function avSave() {
      var payload = {};
      for (var d = 0; d < 7; d++) {
        var dayNum = d === 0 ? 7 : d;
        var closed = [];
        if (avState[d].enabled) {
          for (var b = 0; b < avState[d].blocks.length; b++) {
            var block = avState[d].blocks[b];
            var startVal = format12To24(block.start);
            var endVal = format12To24(block.end);
            for (var t = 0; t < AV_SLOTS.length; t++) {
              var slotH = parseInt(AV_SLOTS[t].split(':')[0], 10);
              var startH = parseInt(startVal.split(':')[0], 10);
              var endH = parseInt(endVal.split(':')[0], 10);
              if (slotH < startH || slotH >= endH) {
                if (closed.indexOf(AV_SLOTS[t]) === -1) closed.push(AV_SLOTS[t]);
              }
            }
          }
        } else {
          closed = AV_SLOTS.slice();
        }
        payload[dayNum] = closed;
      }

      var body = new URLSearchParams();
      body.append('mode', 'weekly');
      body.append('weekly', JSON.stringify(payload));

      fetch('availability-save.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.ok) {
            toast('Availability saved', { description: 'Weekly schedule updated successfully.' });
            loadFromServer();
          } else {
            toast(d.error || 'Failed to save.', { type: 'error' });
          }
        })
        .catch(function() {
          toast('Network error', { type: 'error', description: 'Could not save changes. Please try again.' });
        });
    }    function renderOverrides() {
      var list = document.getElementById('overrideList');
      var empty = document.getElementById('emptyOverrides');
      var monthLabel = document.getElementById('dateMonthLabel');
      var now = new Date();
      monthLabel.textContent = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

      list.innerHTML = '';
      if (dateOverrides.length === 0) {
        empty.style.display = '';
        return;
      }
      empty.style.display = 'none';
      for (var i = 0; i < dateOverrides.length; i++) {
        (function(idx) {
          var ov = dateOverrides[idx];
          var item = document.createElement('div');
          item.className = 'av-override-item';
          var left = document.createElement('div');
          var dateObj = new Date(ov.date + 'T00:00:00');
          var dateStr = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
          var dateEl = document.createElement('div');
          dateEl.className = 'av-override-date';
          dateEl.textContent = dateStr;
          var infoEl = document.createElement('div');
          infoEl.className = 'av-override-info';
          var availCount = 0;
          for (var s = 0; s < AV_SLOTS.length; s++) {
            if (ov.slots[AV_SLOTS[s]] !== false) availCount++;
          }
          infoEl.textContent = availCount + ' of ' + AV_SLOTS.length + ' slots available';
          left.appendChild(dateEl);
          left.appendChild(infoEl);
          item.appendChild(left);
          var rmBtn = document.createElement('button');
          rmBtn.className = 'av-override-remove';
          rmBtn.innerHTML = svgIcon('x');
          rmBtn.title = 'Remove override';
          rmBtn.addEventListener('click', function() {
            var removedDate = dateOverrides[idx].date;
            dateOverrides.splice(idx, 1);
            renderOverrides();
            updateFooter();
            var body = new URLSearchParams();
            body.append('mode', 'date');
            body.append('date', removedDate);
            body.append('slots', '{}');
            fetch('availability-save.php', { method: 'POST', body: body })
              .then(function(r) { return r.json(); })
              .then(function(d) {
                if (!d.ok) toast(d.error || 'Failed to remove override.', { type: 'error' });
            })
            .catch(function() { toast('Network error', { type: 'error' }); });
          });
          item.appendChild(rmBtn);
          list.appendChild(item);
        })(i);
      }
    }

    function openDateModal() {
      var overlay = document.getElementById('dateModalOverlay');
      var dateInput = document.getElementById('modalDateInput');
      var slotsWrap = document.getElementById('modalSlotsWrap');
      var slotsContainer = document.getElementById('modalSlots');
      var saveBtn = document.getElementById('modalSaveBtn');
      var now = new Date();
      dateInput.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      dateInput.min = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      slotsWrap.style.display = 'none';
      saveBtn.disabled = true;
      slotsContainer.innerHTML = '';
      overlay.classList.add('open');
    }

    function closeDateModal() {
      document.getElementById('dateModalOverlay').classList.remove('open');
    }

    function toast(title, opts) {
      opts = opts || {};
      var type = opts.type || 'success';
      var desc = opts.description || '';
      var action = opts.action || null;
      var duration = opts.duration != null ? opts.duration : 4000;

      var container = document.getElementById('avToastContainer');
      var el = document.createElement('div');
      el.className = 'av-toast ' + type;

      var icons = {
        success: '<svg class="av-toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error: '<svg class="av-toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        info: '<svg class="av-toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
      };

      var html = icons[type] || icons.success;
      html += '<div class="av-toast-body"><p class="av-toast-title">' + title + '</p>';
      if (desc) html += '<p class="av-toast-desc">' + desc + '</p>';
      html += '</div>';
      if (action) {
        html += '<button class="av-toast-action">' + action.label + '</button>';
      }
      html += '<button class="av-toast-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
      el.innerHTML = html;

      container.appendChild(el);
      requestAnimationFrame(function() {
        requestAnimationFrame(function() { el.classList.add('show'); });
      });

      var closeBtn = el.querySelector('.av-toast-close');
      closeBtn.addEventListener('click', function() { dismissToast(el); });

      if (action) {
        el.querySelector('.av-toast-action').addEventListener('click', function() {
          dismissToast(el);
          if (action.onClick) action.onClick();
        });
      }

      var timer = setTimeout(function() { dismissToast(el); }, duration);
      el._timer = timer;

      el.addEventListener('mouseenter', function() { clearTimeout(el._timer); });
      el.addEventListener('mouseleave', function() {
        el._timer = setTimeout(function() { dismissToast(el); }, 2000);
      });

      return el;
    }

    function dismissToast(el) {
      if (el._dismissed) return;
      el._dismissed = true;
      clearTimeout(el._timer);
      el.classList.remove('show');
      el.classList.add('hiding');
      setTimeout(function() {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 300);
    }

    function loadFromServer() {
      var now = new Date();
      var y = now.getFullYear();
      var m = now.getMonth() + 1;
      fetch('appointment-availability.php?sess=DCVS_STAFF_SESSION&year=' + y + '&month=' + m)
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data.ok) return;
          if (data.weekly) {
            for (var d = 1; d <= 7; d++) {
              var dayIdx = d === 7 ? 0 : d;
              var dayData = data.weekly[d];
              var allAvail = true;
              var closedSlots = [];
              if (dayData && typeof dayData === 'object') {
                for (var t = 0; t < AV_SLOTS.length; t++) {
                  var slot = AV_SLOTS[t];
                  if (dayData[slot] === false || dayData[slot] === 0) {
                    allAvail = false;
                    closedSlots.push(slot);
                  }
                }
              }
              if (!allAvail && closedSlots.length > 0 && closedSlots.length < AV_SLOTS.length) {
                avState[dayIdx].enabled = true;
                var blocks = [];
                var availRanges = getAvailRanges(closedSlots);
                for (var r2 = 0; r2 < availRanges.length; r2++) {
                  blocks.push({ id: ++nextBlockId, start: timeValToLabel(availRanges[r2].start), end: timeValToLabel(availRanges[r2].end) });
                }
                if (blocks.length > 0) {
                  avState[dayIdx].blocks = blocks;
                } else {
                  avState[dayIdx].enabled = false;
                }
              } else if (closedSlots.length === AV_SLOTS.length) {
                avState[dayIdx].enabled = false;
              } else {
                avState[dayIdx].enabled = true;
                avState[dayIdx].blocks = [{ id: ++nextBlockId, start: '09:00 AM', end: '05:00 PM' }];
              }
            }
          }
          renderDays();
          if (data.overrides) {
            dateOverrides = [];
            for (var od in data.overrides) {
              if (data.overrides.hasOwnProperty(od)) {
                dateOverrides.push({ date: od, slots: data.overrides[od] });
              }
            }
          }
          renderOverrides();
          ovRenderCal(); ovRenderEditor(); ovRenderOverrideList();
          serverAvState = JSON.parse(JSON.stringify(avState));
          serverOverridesCount = dateOverrides.length;
          updateFooter();
          initPanel();
        })
        .catch(function() {});
    }

    function getAvailRanges(closedSlots) {
      var allSlots = AV_SLOTS.slice();
      var ranges = [];
      var currentStart = null;
      for (var i = 0; i <= allSlots.length; i++) {
        var slot = allSlots[i];
        var isOpen = slot && closedSlots.indexOf(slot) === -1;
        if (isOpen && currentStart === null) {
          currentStart = slot;
        } else if (!isOpen && currentStart !== null) {
          var endH = (slot ? parseInt(slot.split(':')[0], 10) : 17) + 1;
          var endSlot = String(endH).padStart(2, '0') + ':00';
          ranges.push({ start: currentStart, end: endSlot });
          currentStart = null;
        }
      }
      return ranges;
    }    /* ===== DATE OVERRIDES PANEL ===== */
    var ovCalYear, ovCalMonth, ovSelectedDate = null, ovEditing = false;

    function ovGetMonthDays(y, m) { return new Date(y, m + 1, 0).getDate(); }
    function ovGetFirstDow(y, m) { var d = new Date(y, m, 1).getDay(); return d === 0 ? 6 : d - 1; }
    function ovDateStr(y, m, d) { return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0'); }
    function ovGetDayIdx(dateStr) {
      var p = dateStr.split('-');
      var dow = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2])).getDay();
      return dow === 0 ? 6 : dow - 1;
    }
    function ovGetWeeklyRanges(dayIdx) {
      var day = avState[dayIdx];
      if (!day || !day.enabled) return [];
      return day.blocks.map(function(b) { return { start: b.start, end: b.end }; });
    }
    function ovFindOverride(dateStr) {
      for (var i = 0; i < dateOverrides.length; i++) {
        if (dateOverrides[i].date === dateStr) return dateOverrides[i];
      }
      return null;
    }
    function ovIsClosed(ov) {
      if (!ov || !ov.slots) return false;
      for (var s = 0; s < AV_SLOTS.length; s++) {
        if (ov.slots[AV_SLOTS[s]] !== false) return false;
      }
      return true;
    }
    function ovSlotsToRanges(slots) {
      var ranges = [];
      var cur = null;
      for (var i = 0; i < AV_SLOTS.length; i++) {
        var isOpen = slots[AV_SLOTS[i]] !== false;
        if (isOpen && !cur) { cur = AV_SLOTS[i]; }
        if (!isOpen && cur) {
          var endH = parseInt(AV_SLOTS[i].split(':')[0], 10) + 1;
          ranges.push({ start: cur, end: String(endH).padStart(2, '0') + ':00' });
          cur = null;
        }
      }
      if (cur) {
        ranges.push({ start: cur, end: '17:00' });
      }
      return ranges;
    }
    function ovRangesToSlots(ranges) {
      var slots = {};
      AV_SLOTS.forEach(function(s) { slots[s] = false; });
      ranges.forEach(function(r) {
        var sH = parseInt(r.start.split(':')[0], 10);
        var eH = parseInt(r.end.split(':')[0], 10);
        AV_SLOTS.forEach(function(s) {
          var h = parseInt(s.split(':')[0], 10);
          if (h >= sH && h < eH) slots[s] = true;
        });
      });
      return slots;
    }
    function ovRenderCal() {
      var grid = document.getElementById('ovCalGrid');
      var label = document.getElementById('ovCalMonth');
      if (!grid || !label) return;
      var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      label.textContent = months[ovCalMonth] + ' ' + ovCalYear;
      grid.innerHTML = '';
      var first = ovGetFirstDow(ovCalYear, ovCalMonth);
      var total = ovGetMonthDays(ovCalYear, ovCalMonth);
      var prevDays = ovGetMonthDays(ovCalYear, ovCalMonth === 0 ? 11 : ovCalMonth - 1);
      var today = new Date();
      var todayStr = ovDateStr(today.getFullYear(), today.getMonth(), today.getDate());
      for (var p = 0; p < first; p++) {
        var pd = document.createElement('div');
        pd.className = 'ov-cal-day other';
        pd.textContent = prevDays - first + p + 1;
        grid.appendChild(pd);
      }
      for (var d = 1; d <= total; d++) {
        var ds = ovDateStr(ovCalYear, ovCalMonth, d);
        var el = document.createElement('div');
        el.className = 'ov-cal-day';
        el.textContent = d;
        if (ds === todayStr) el.classList.add('today');
        if (ds === ovSelectedDate) el.classList.add('selected');
        var ov = ovFindOverride(ds);
        if (ov) el.classList.add('has-override');
        el.dataset.date = ds;
        el.addEventListener('click', function() { ovSelectDate(this.dataset.date); });
        grid.appendChild(el);
      }
      var remaining = (first + total) % 7;
      if (remaining > 0) {
        for (var r = 1; r <= 7 - remaining; r++) {
          var rd = document.createElement('div');
          rd.className = 'ov-cal-day other';
          rd.textContent = r;
          grid.appendChild(rd);
        }
      }
    }

    function ovSelectDate(dateStr) {
      ovSelectedDate = dateStr;
      ovEditing = false;
      ovRenderCal();
      ovRenderEditor();
    }

    function ovRenderEditor() {
      var empty = document.getElementById('ovEditorEmpty');
      var viewOv = document.getElementById('ovEditorView');
      var viewNoOv = document.getElementById('ovEditorNoOv');
      var editEl = document.getElementById('ovEditorEdit');
      if (!ovSelectedDate) {
        empty.style.display = '';
        viewOv.style.display = 'none';
        viewNoOv.style.display = 'none';
        editEl.style.display = 'none';
        return;
      }
      empty.style.display = 'none';
      var ov = ovFindOverride(ovSelectedDate);
      var p = ovSelectedDate.split('-');
      var dateObj = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]));
      var dateLabel = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
      var dayIdx = ovGetDayIdx(ovSelectedDate);
      var weeklyRanges = ovGetWeeklyRanges(dayIdx);

      if (ovEditing) {
        viewOv.style.display = 'none';
        viewNoOv.style.display = 'none';
        editEl.style.display = '';
        document.getElementById('ovEditDate').textContent = dateLabel;
        var radios = document.querySelectorAll('input[name="ovMode"]');
        if (ov) {
          if (ovIsClosed(ov)) {
            radios[2].checked = true;
          } else {
            radios[1].checked = true;
          }
        } else {
          radios[1].checked = true;
        }
        ovUpdateEditForm();
        document.getElementById('ovEditFootText').textContent = 'Changes apply only to ' + dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) + '.';
        return;
      }

      if (ov) {
        viewNoOv.style.display = 'none';
        viewOv.style.display = '';
        document.getElementById('ovViewDate').textContent = dateLabel;
        document.getElementById('ovViewSub').textContent = 'Date-specific availability';
        var statusEl = document.getElementById('ovViewStatus');
        if (ovIsClosed(ov)) {
          statusEl.innerHTML = '<span class="ov-status-dot" style="background:#dc2626;"></span><span style="color:#dc2626;">Closed</span>';
          document.getElementById('ovViewHours').innerHTML = '<div class="ov-hours-range" style="color:var(--av-muted-fg);">No available times</div>';
        } else {
          statusEl.innerHTML = '<span class="ov-status-dot" style="background:var(--av-green);"></span><span style="color:var(--av-green);">Custom schedule</span>';
          var ranges = ovSlotsToRanges(ov.slots);
          var html = '';
          ranges.forEach(function(r) {
            html += '<div class="ov-hours-range">' + timeValToLabel(r.start) + ' – ' + timeValToLabel(r.end) + '</div>';
          });
          document.getElementById('ovViewHours').innerHTML = html;
        }
        document.getElementById('ovEditBtn').onclick = function() { ovEditing = true; ovRenderEditor(); };
        document.getElementById('ovRemoveBtn').onclick = function() {
          var removedDate = ovSelectedDate;
          for (var i = 0; i < dateOverrides.length; i++) {
            if (dateOverrides[i].date === removedDate) { dateOverrides.splice(i, 1); break; }
          }
          ovEditing = false;
          ovRenderCal();
          ovRenderEditor();
          ovRenderOverrideList();
          updateFooter();
          toast('Override removed', { type: 'info', description: 'Date will follow the weekly schedule.' });
          var body = new URLSearchParams();
          body.append('mode', 'date');
          body.append('date', removedDate);
          body.append('slots', '{}');
          fetch('availability-save.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (!d.ok) toast(d.error || 'Failed to remove override.', { type: 'error' });
            })
            .catch(function() { toast('Network error', { type: 'error' }); });
        };
      } else {
        viewOv.style.display = 'none';
        viewNoOv.style.display = '';
        document.getElementById('ovNoOvDate').textContent = dateLabel;
        var hoursEl = document.getElementById('ovNoOvHours');
        if (weeklyRanges.length === 0) {
          hoursEl.innerHTML = '<div class="ov-hours-range" style="color:var(--av-muted-fg);">Unavailable all day</div>';
        } else {
          var html = '';
          weeklyRanges.forEach(function(r) {
            html += '<div class="ov-hours-range">' + timeValToLabel(r.start) + ' – ' + timeValToLabel(r.end) + '</div>';
          });
          hoursEl.innerHTML = html;
        }
        document.getElementById('ovCreateBtn').onclick = function() { ovEditing = true; ovRenderEditor(); };
      }
    }

    function ovUpdateEditForm() {
      var mode = document.querySelector('input[name="ovMode"]:checked').value;
      var closedMsg = document.getElementById('ovClosedMsg');
      var customEd = document.getElementById('ovCustomEditor');
      closedMsg.style.display = mode === 'closed' ? '' : 'none';
      customEd.style.display = mode === 'custom' ? '' : 'none';
      if (mode === 'custom') {
        var ov = ovFindOverride(ovSelectedDate);
        var ranges;
        if (ov && !ovIsClosed(ov)) {
          ranges = ovSlotsToRanges(ov.slots);
        } else {
          var dayIdx = ovGetDayIdx(ovSelectedDate);
          ranges = ovGetWeeklyRanges(dayIdx);
          if (ranges.length === 0) ranges = [{ start: '09:00', end: '17:00' }];
        }
        ovRenderTimeRanges(ranges);
      }
    }

    function ovRenderTimeRanges(ranges) {
      var container = document.getElementById('ovTimeRanges');
      container.innerHTML = '';
      ranges.forEach(function(r, i) {
        var row = document.createElement('div');
        row.className = 'ov-time-range-row';
        var startWrap = document.createElement('div');
        startWrap.className = 'ov-time-select';
        var startSel = document.createElement('select');
        TIME_OPTIONS.forEach(function(t) {
          var opt = document.createElement('option');
          opt.value = t.value; opt.textContent = t.label;
          if (t.value === r.start) opt.selected = true;
          startSel.appendChild(opt);
        });
        startWrap.appendChild(startSel);
        row.appendChild(startWrap);
        var sep = document.createElement('span');
        sep.className = 'ov-range-sep';
        sep.textContent = 'To';
        row.appendChild(sep);
        var endWrap = document.createElement('div');
        endWrap.className = 'ov-time-select';
        var endSel = document.createElement('select');
        TIME_OPTIONS.forEach(function(t) {
          var opt = document.createElement('option');
          opt.value = t.value; opt.textContent = t.label;
          if (t.value === r.end) opt.selected = true;
          endSel.appendChild(opt);
        });
        endWrap.appendChild(endSel);
        row.appendChild(endWrap);
        var actions = document.createElement('div');
        actions.className = 'ov-range-actions';
        if (i === 0) {
          var addBtn = document.createElement('button');
          addBtn.type = 'button';
          addBtn.className = 'av-icon-btn';
          addBtn.innerHTML = svgIcon('plus');
          addBtn.title = 'Add time range';
          addBtn.addEventListener('click', function() {
            ranges.push({ start: '17:00', end: '19:00' });
            ovRenderTimeRanges(ranges);
          });
          actions.appendChild(addBtn);
        }
        if (ranges.length > 1) {
          var rmBtn = document.createElement('button');
          rmBtn.type = 'button';
          rmBtn.className = 'av-icon-btn danger';
          rmBtn.innerHTML = svgIcon('trash');
          rmBtn.title = 'Remove time range';
          rmBtn.addEventListener('click', function() {
            ranges.splice(i, 1);
            ovRenderTimeRanges(ranges);
          });
          actions.appendChild(rmBtn);
        }
        row.appendChild(actions);
        startSel.addEventListener('change', function() { ranges[i].start = this.value; });
        endSel.addEventListener('change', function() { ranges[i].end = this.value; });
        container.appendChild(row);
      });
    }

    function ovRenderOverrideList() {
      var list = document.getElementById('ovOverrideList');
      if (!list) return;
      list.innerHTML = '';
      if (dateOverrides.length === 0) return;
      var title = document.createElement('div');
      title.style.cssText = 'font-size:0.75rem;font-weight:600;color:var(--av-muted-fg);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;';
      title.textContent = 'All overrides';
      list.appendChild(title);
      var sorted = dateOverrides.slice().sort(function(a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : 0; });
      sorted.forEach(function(ov) {
        var item = document.createElement('div');
        item.className = 'ov-override-item';
        var left = document.createElement('div');
        var dateObj = new Date(ov.date + 'T00:00:00');
        var dateEl = document.createElement('div');
        dateEl.className = 'ov-override-date';
        dateEl.textContent = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        var infoEl = document.createElement('div');
        infoEl.className = 'ov-override-info';
        infoEl.textContent = ovIsClosed(ov) ? 'Closed' : 'Custom hours';
        left.appendChild(dateEl);
        left.appendChild(infoEl);
        item.appendChild(left);
        var rmBtn = document.createElement('button');
        rmBtn.className = 'ov-override-rm';
        rmBtn.innerHTML = svgIcon('x');
        rmBtn.title = 'Remove override';
        rmBtn.addEventListener('click', function() {
          var removedDate = ov.date;
          for (var i = 0; i < dateOverrides.length; i++) {
            if (dateOverrides[i].date === removedDate) { dateOverrides.splice(i, 1); break; }
          }
          ovRenderCal();
          ovRenderEditor();
          ovRenderOverrideList();
          updateFooter();
          toast('Override removed', { type: 'info', description: 'Date will follow the weekly schedule.' });
          var body = new URLSearchParams();
          body.append('mode', 'date');
          body.append('date', removedDate);
          body.append('slots', '{}');
          fetch('availability-save.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (!d.ok) toast(d.error || 'Failed to remove override.', { type: 'error' });
            })
            .catch(function() { toast('Network error', { type: 'error' }); });
        });
        item.appendChild(rmBtn);
        list.appendChild(item);
      });
    }

    /* ===== BULK SCHEDULE PANEL ===== */
    var bulkAction = 'available';
    var bulkTimeRanges = [{ start: '09:00', end: '12:00' }, { start: '14:00', end: '16:00' }];

    function bulkGetAffectedDates() {
      var s = document.getElementById('bulkStart').value;
      var e = document.getElementById('bulkEnd').value;
      if (!s || !e) return [];
      var chips = document.querySelectorAll('#bulkDays .bulk-day-chip.selected');
      var days = [];
      chips.forEach(function(c) { days.push(parseInt(c.dataset.day, 10)); });
      if (days.length === 0) return [];
      var start = new Date(s + 'T00:00:00');
      var end = new Date(e + 'T00:00:00');
      var dates = [];
      var d = new Date(start);
      while (d <= end) {
        var dow = d.getDay();
        var adjusted = dow === 0 ? 6 : dow - 1;
        if (days.indexOf(adjusted) !== -1) {
          dates.push(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'));
        }
        d.setDate(d.getDate() + 1);
      }
      return dates;
    }

    function bulkIsWeekend(dow) { return dow === 0 || dow === 6; }

    function bulkRenderSummary() {
      var content = document.getElementById('bulkSumContent');
      var reviewBtn = document.getElementById('bulkReview');
      var dates = bulkGetAffectedDates();
      var s = document.getElementById('bulkStart').value;
      var e = document.getElementById('bulkEnd').value;
      if (dates.length === 0 || !s || !e) {
        content.innerHTML = '<div class="bulk-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><p>Select a date range and days to begin configuring a bulk change.</p></div>';
        reviewBtn.disabled = true;
        return;
      }
      var chips = document.querySelectorAll('#bulkDays .bulk-day-chip.selected');
      var dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      var selNames = [];
      chips.forEach(function(c) { selNames.push(dayNames[parseInt(c.dataset.day, 10)]); });
      var startDate = new Date(s + 'T00:00:00');
      var endDate = new Date(e + 'T00:00:00');
      var dateRangeStr = startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      var existingCount = 0;
      dates.forEach(function(ds) { if (ovFindOverride(ds)) existingCount++; });
      var html = '';
      html += '<div class="bulk-sum-count">' + dates.length + '</div>';
      html += '<div class="bulk-sum-count-label">date' + (dates.length !== 1 ? 's' : '') + ' selected</div>';
      html += '<div class="bulk-sum-days">';
      selNames.forEach(function(n) { html += '<span class="bulk-sum-day">' + n + '</span>'; });
      html += '</div>';
      html += '<div class="bulk-sum-divider"></div>';
      html += '<div class="bulk-sum-label">Date range</div>';
      html += '<div class="bulk-sum-range">' + dateRangeStr + '</div>';
      html += '<div class="bulk-sum-divider"></div>';
      if (bulkAction === 'restore') {
        html += '<div class="bulk-sum-label">Action</div>';
        html += '<div class="bulk-sum-range" style="color:var(--av-green);">Restore weekly schedule</div>';
        html += '<div class="bulk-sum-divider"></div>';
        html += '<div class="bulk-sum-impact">This will remove date-specific overrides from <strong>' + dates.length + ' date(s)</strong>.</div>';
      } else if (bulkAction === 'closed') {
        html += '<div class="bulk-sum-label">Action</div>';
        html += '<div class="bulk-sum-range" style="color:#dc2626;">Closed</div>';
        html += '<div class="bulk-sum-divider"></div>';
        html += '<div class="bulk-sum-impact"><strong>' + dates.length + ' date(s)</strong> will have no available appointment times.</div>';
      } else {
        html += '<div class="bulk-sum-label">New availability</div>';
        html += '<div class="bulk-sum-range">';
        bulkTimeRanges.forEach(function(r) {
          html += timeValToLabel(r.start) + ' – ' + timeValToLabel(r.end) + '<br>';
        });
        html += '</div>';
        html += '<div class="bulk-sum-divider"></div>';
        var totalSlots = dates.length * AV_SLOTS.length;
        html += '<div class="bulk-sum-impact"><strong>' + dates.length + ' date(s)</strong> will be updated.</div>';
      }
      if (existingCount > 0) {
        html += '<div class="bulk-sum-impact" style="background:#fffbeb;color:#92400e;border-color:#fde68a;margin-top:0.5rem;">';
        html += '<strong>' + existingCount + '</strong> selected date(s) already have custom schedules. Applying this change will replace those overrides.';
        html += '</div>';
      }
      content.innerHTML = html;
      reviewBtn.disabled = false;
    }

    function bulkRenderTimeRanges() {
      var container = document.getElementById('bulkTimeRanges');
      container.innerHTML = '';
      bulkTimeRanges.forEach(function(r, i) {
        var row = document.createElement('div');
        row.className = 'ov-time-range-row';
        var startWrap = document.createElement('div');
        startWrap.className = 'ov-time-select';
        var startSel = document.createElement('select');
        TIME_OPTIONS.forEach(function(t) {
          var opt = document.createElement('option');
          opt.value = t.value; opt.textContent = t.label;
          if (t.value === r.start) opt.selected = true;
          startSel.appendChild(opt);
        });
        startWrap.appendChild(startSel);
        row.appendChild(startWrap);
        var sep = document.createElement('span');
        sep.className = 'ov-range-sep';
        sep.textContent = 'To';
        row.appendChild(sep);
        var endWrap = document.createElement('div');
        endWrap.className = 'ov-time-select';
        var endSel = document.createElement('select');
        TIME_OPTIONS.forEach(function(t) {
          var opt = document.createElement('option');
          opt.value = t.value; opt.textContent = t.label;
          if (t.value === r.end) opt.selected = true;
          endSel.appendChild(opt);
        });
        endWrap.appendChild(endSel);
        row.appendChild(endWrap);
        var actions = document.createElement('div');
        actions.className = 'ov-range-actions';
        if (i === 0) {
          var addBtn = document.createElement('button');
          addBtn.type = 'button';
          addBtn.className = 'av-icon-btn';
          addBtn.innerHTML = svgIcon('plus');
          addBtn.title = 'Add time range';
          addBtn.addEventListener('click', function() {
            bulkTimeRanges.push({ start: '17:00', end: '19:00' });
            bulkRenderTimeRanges();
            bulkRenderSummary();
          });
          actions.appendChild(addBtn);
        }
        if (bulkTimeRanges.length > 1) {
          var rmBtn = document.createElement('button');
          rmBtn.type = 'button';
          rmBtn.className = 'av-icon-btn danger';
          rmBtn.innerHTML = svgIcon('trash');
          rmBtn.title = 'Remove time range';
          rmBtn.addEventListener('click', function() {
            bulkTimeRanges.splice(i, 1);
            bulkRenderTimeRanges();
            bulkRenderSummary();
          });
          actions.appendChild(rmBtn);
        }
        row.appendChild(actions);
        startSel.addEventListener('change', function() { bulkTimeRanges[i].start = this.value; bulkRenderSummary(); });
        endSel.addEventListener('change', function() { bulkTimeRanges[i].end = this.value; bulkRenderSummary(); });
        container.appendChild(row);
      });
    }

    function bulkRenderPanel() {
      var dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      var daysWrap = document.getElementById('bulkDays');
      daysWrap.innerHTML = '';
      dayNames.forEach(function(name, i) {
        var chip = document.createElement('div');
        chip.className = 'bulk-day-chip' + (i < 5 ? ' selected' : '');
        chip.textContent = name;
        chip.dataset.day = i;
        chip.addEventListener('click', function() {
          chip.classList.toggle('selected');
          bulkRenderSummary();
        });
        daysWrap.appendChild(chip);
      });
      var now = new Date();
      var y = now.getFullYear();
      var m = String(now.getMonth() + 1).padStart(2, '0');
      var d1 = String(now.getDate()).padStart(2, '0');
      var lastDay = new Date(y, now.getMonth() + 1, 0).getDate();
      document.getElementById('bulkStart').value = y + '-' + m + '-' + d1;
      document.getElementById('bulkStart').min = y + '-' + m + '-' + d1;
      document.getElementById('bulkEnd').value = y + '-' + m + '-' + String(lastDay).padStart(2, '0');
      bulkRenderTimeRanges();
      bulkRenderSummary();
    }

    function bulkShowModal() {
      var dates = bulkGetAffectedDates();
      if (dates.length === 0) return;
      var s = document.getElementById('bulkStart').value;
      var e = document.getElementById('bulkEnd').value;
      var startDate = new Date(s + 'T00:00:00');
      var endDate = new Date(e + 'T00:00:00');
      var chips = document.querySelectorAll('#bulkDays .bulk-day-chip.selected');
      var dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      var selNames = [];
      chips.forEach(function(c) { selNames.push(dayNames[parseInt(c.dataset.day, 10)]); });
      var body = document.getElementById('bulkModalBody');
      var html = '';
      html += '<div class="bulk-modal-row"><span>Dates</span><span>' + startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' – ' + endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + '</span></div>';
      html += '<div class="bulk-modal-row"><span>Days</span><span>' + selNames.join(', ') + '</span></div>';
      html += '<div class="bulk-modal-row"><span>Count</span><span>' + dates.length + ' date(s)</span></div>';
      html += '<div style="height:1px;background:var(--av-border);margin:0.5rem 0;"></div>';
      if (bulkAction === 'restore') {
        html += '<div class="bulk-modal-row"><span>Action</span><span style="color:var(--av-green);">Restore weekly schedule</span></div>';
      } else if (bulkAction === 'closed') {
        html += '<div class="bulk-modal-row"><span>Action</span><span style="color:#dc2626;">Closed</span></div>';
      } else {
        html += '<div class="bulk-modal-row"><span>Action</span><span>Custom hours</span></div>';
        html += '<div style="margin-top:0.5rem;">';
        bulkTimeRanges.forEach(function(r) {
          html += '<div style="font-size:0.8125rem;font-variant-numeric:tabular-nums;">' + timeValToLabel(r.start) + ' – ' + timeValToLabel(r.end) + '</div>';
        });
        html += '</div>';
      }
      html += '<div style="height:1px;background:var(--av-border);margin:0.75rem 0;"></div>';
      html += '<div style="font-size:0.8125rem;color:var(--av-muted-fg);line-height:1.5;">These changes will override the weekly schedule for the selected dates.</div>';
      body.innerHTML = html;
      document.getElementById('bulkModal').classList.add('open');
    }

    function bulkConfirm() {
      var dates = bulkGetAffectedDates();
      if (dates.length === 0) return;
      var count = 0;
      var promises = [];
      dates.forEach(function(dateStr) {
        for (var i = 0; i < dateOverrides.length; i++) {
          if (dateOverrides[i].date === dateStr) { dateOverrides.splice(i, 1); break; }
        }
        if (bulkAction === 'restore') {
          var body = new URLSearchParams();
          body.append('mode', 'date');
          body.append('date', dateStr);
          body.append('slots', '{}');
          promises.push(fetch('availability-save.php', { method: 'POST', body: body }).then(function(r) { return r.json(); }));
          count++;
        } else if (bulkAction === 'closed') {
          var slots = {};
          AV_SLOTS.forEach(function(s) { slots[s] = false; });
          dateOverrides.push({ date: dateStr, slots: slots });
          var body = new URLSearchParams();
          body.append('mode', 'date');
          body.append('date', dateStr);
          body.append('slots', JSON.stringify(slots));
          promises.push(fetch('availability-save.php', { method: 'POST', body: body }).then(function(r) { return r.json(); }));
          count++;
        } else {
          var slots = ovRangesToSlots(bulkTimeRanges);
          dateOverrides.push({ date: dateStr, slots: slots });
          var body = new URLSearchParams();
          body.append('mode', 'date');
          body.append('date', dateStr);
          body.append('slots', JSON.stringify(slots));
          promises.push(fetch('availability-save.php', { method: 'POST', body: body }).then(function(r) { return r.json(); }));
          count++;
        }
      });
      document.getElementById('bulkModal').classList.remove('open');
      Promise.all(promises).then(function(results) {
        var failed = results.filter(function(d) { return !d.ok; });
        if (failed.length > 0) {
          toast(failed[0].error || 'Some dates failed to save.', { type: 'error' });
        } else {
          toast('Bulk schedule updated', { description: count + ' date(s) affected.' });
        }
        loadFromServer();
      }).catch(function() {
        toast('Network error', { type: 'error', description: 'Could not save bulk changes.' });
        loadFromServer();
      });
    }

    function bulkInit() {
      var now = new Date();
      var y = now.getFullYear();
      var m = String(now.getMonth() + 1).padStart(2, '0');
      var d1 = String(now.getDate()).padStart(2, '0');
      var lastDay = new Date(y, now.getMonth() + 1, 0).getDate();
      var bulkStart = document.getElementById('bulkStart');
      var bulkEnd = document.getElementById('bulkEnd');
      if (bulkStart) {
        bulkStart.value = y + '-' + m + '-' + d1;
        bulkStart.min = y + '-' + m + '-' + d1;
        bulkStart.addEventListener('change', function() {
          var err = document.getElementById('bulkDateError');
          if (bulkEnd.value && bulkStart.value > bulkEnd.value) {
            err.style.display = '';
          } else {
            err.style.display = 'none';
          }
          bulkRenderSummary();
        });
      }
      if (bulkEnd) {
        bulkEnd.value = y + '-' + m + '-' + String(lastDay).padStart(2, '0');
        bulkEnd.addEventListener('change', function() {
          var err = document.getElementById('bulkDateError');
          if (bulkStart.value && bulkEnd.value < bulkStart.value) {
            err.style.display = '';
          } else {
            err.style.display = 'none';
          }
          bulkRenderSummary();
        });
      }
      var dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      var daysWrap = document.getElementById('bulkDays');
      daysWrap.innerHTML = '';
      dayNames.forEach(function(name, i) {
        var chip = document.createElement('div');
        chip.className = 'bulk-day-chip' + (i < 5 ? ' selected' : '');
        chip.textContent = name;
        chip.dataset.day = i;
        chip.addEventListener('click', function() {
          chip.classList.toggle('selected');
          bulkRenderSummary();
        });
        daysWrap.appendChild(chip);
      });
      document.getElementById('bulkSelectWeekdays').addEventListener('click', function() {
        daysWrap.querySelectorAll('.bulk-day-chip').forEach(function(c, i) {
          c.classList.toggle('selected', i < 5);
        });
        bulkRenderSummary();
      });
      document.getElementById('bulkSelectAll').addEventListener('click', function() {
        daysWrap.querySelectorAll('.bulk-day-chip').forEach(function(c) { c.classList.add('selected'); });
        bulkRenderSummary();
      });
      document.querySelectorAll('#bulkAction button').forEach(function(btn) {
        btn.addEventListener('click', function() {
          document.querySelectorAll('#bulkAction button').forEach(function(b) { b.classList.remove('active'); });
          btn.classList.add('active');
          bulkAction = btn.dataset.action;
          document.getElementById('bulkTimeSection').style.display = bulkAction === 'available' ? '' : 'none';
          document.getElementById('bulkClosedMsg').style.display = bulkAction === 'closed' ? '' : 'none';
          document.getElementById('bulkRestoreMsg').style.display = bulkAction === 'restore' ? '' : 'none';
          bulkRenderSummary();
        });
      });
      bulkRenderTimeRanges();
      bulkRenderSummary();
      document.getElementById('bulkAddRange').addEventListener('click', function() {
        bulkTimeRanges.push({ start: '17:00', end: '19:00' });
        bulkRenderTimeRanges();
        bulkRenderSummary();
      });
      document.getElementById('bulkReview').addEventListener('click', bulkShowModal);
      document.getElementById('bulkModalBack').addEventListener('click', function() {
        document.getElementById('bulkModal').classList.remove('open');
      });
      document.getElementById('bulkModalConfirm').addEventListener('click', bulkConfirm);
      document.getElementById('bulkModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
      });
      document.getElementById('bulkCancel').addEventListener('click', function() {
        bulkTimeRanges = [{ start: '09:00', end: '12:00' }, { start: '14:00', end: '16:00' }];
        bulkAction = 'available';
        document.querySelectorAll('#bulkAction button').forEach(function(b) { b.classList.remove('active'); });
        document.querySelector('#bulkAction button[data-action="available"]').classList.add('active');
        document.getElementById('bulkTimeSection').style.display = '';
        document.getElementById('bulkClosedMsg').style.display = 'none';
        document.getElementById('bulkRestoreMsg').style.display = 'none';
        daysWrap.querySelectorAll('.bulk-day-chip').forEach(function(c, i) {
          c.classList.toggle('selected', i < 5);
        });
        bulkRenderTimeRanges();
        bulkRenderSummary();
        toast('Form reset', { type: 'info' });
      });
    }

    /* ===== SCHEDULE PREVIEW PANEL ===== */
    var pvCalYear, pvCalMonth, pvSelectedDate = null, pvViewMode = 'resident';

    function pvDateStr(y, m, d) { return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0'); }
    function pvGetMonthDays(y, m) { return new Date(y, m + 1, 0).getDate(); }
    function pvGetFirstDow(y, m) { var d = new Date(y, m, 1).getDay(); return d; }
    function pvFindOverride(ds) {
      for (var i = 0; i < dateOverrides.length; i++) {
        if (dateOverrides[i].date === ds) return dateOverrides[i];
      }
      return null;
    }
    function pvIsClosed(ov) {
      if (!ov || !ov.slots) return false;
      for (var s = 0; s < AV_SLOTS.length; s++) {
        if (ov.slots[AV_SLOTS[s]] !== false) return false;
      }
      return true;
    }
    function pvGetDayState(dateStr) {
      var p = dateStr.split('-');
      var dow = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2])).getDay();
      var ov = pvFindOverride(dateStr);
      if (ov) {
        return { type: 'override', closed: pvIsClosed(ov), slots: ov.slots, dayIdx: dow };
      }
      var day = avState[dow];
      if (!day || !day.enabled) {
        return { type: 'weekly', closed: true, slots: null, dayIdx: dow };
      }
      var slots = {};
      AV_SLOTS.forEach(function(s) {
        var h = parseInt(s.split(':')[0], 10);
        var covered = false;
        for (var b = 0; b < day.blocks.length; b++) {
          var sH = parseInt(day.blocks[b].start.split(':')[0], 10);
          var eH = parseInt(day.blocks[b].end.split(':')[0], 10);
          if (h >= sH && h < eH) { covered = true; break; }
        }
        slots[s] = covered;
      });
      return { type: 'weekly', closed: false, slots: slots, dayIdx: dow };
    }
    function pvGetAvailableSlots(slots) {
      if (!slots) return [];
      var result = [];
      AV_SLOTS.forEach(function(s) {
        if (slots[s] !== false) result.push(s);
      });
      return result;
    }
    function pvRenderCal() {
      var grid = document.getElementById('pvCalGrid');
      var label = document.getElementById('pvCalMonth');
      if (!grid || !label) return;
      var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      label.textContent = months[pvCalMonth] + ' ' + pvCalYear;
      grid.innerHTML = '';
      var first = pvGetFirstDow(pvCalYear, pvCalMonth);
      var total = pvGetMonthDays(pvCalYear, pvCalMonth);
      var prevDays = pvGetMonthDays(pvCalYear, pvCalMonth === 0 ? 11 : pvCalMonth - 1);
      var today = new Date();
      var todayStr = pvDateStr(today.getFullYear(), today.getMonth(), today.getDate());
      for (var p = 0; p < first; p++) {
        var pd = document.createElement('div');
        pd.className = 'pv-cal-day other';
        pd.textContent = prevDays - first + p + 1;
        grid.appendChild(pd);
      }
      for (var d = 1; d <= total; d++) {
        var ds = pvDateStr(pvCalYear, pvCalMonth, d);
        var state = pvGetDayState(ds);
        var el = document.createElement('div');
        el.className = 'pv-cal-day';
        el.textContent = d;
        if (ds === todayStr) el.classList.add('today');
        if (ds === pvSelectedDate) el.classList.add('selected');
        if (!state.closed && state.type === 'override') el.classList.add('has-override');
        if (state.closed) el.classList.add('closed');
        else if (state.type === 'override') el.classList.add('available');
        el.dataset.date = ds;
        el.addEventListener('click', function() { pvSelectDate(this.dataset.date); });
        grid.appendChild(el);
      }
      var totalCells = first + total;
      var remaining = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
      for (var r = 1; r <= remaining; r++) {
        var rd = document.createElement('div');
        rd.className = 'pv-cal-day other';
        rd.textContent = r;
        grid.appendChild(rd);
      }
    }

    function pvSelectDate(dateStr) {
      pvSelectedDate = dateStr;
      pvRenderCal();
      pvRenderDetail();
    }

    function pvNavDay(dir) {
      if (!pvSelectedDate) return;
      var p = pvSelectedDate.split('-');
      var d = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]));
      d.setDate(d.getDate() + dir);
      var ds = pvDateStr(d.getFullYear(), d.getMonth(), d.getDate());
      if (d.getMonth() !== pvCalMonth || d.getFullYear() !== pvCalYear) {
        pvCalMonth = d.getMonth();
        pvCalYear = d.getFullYear();
      }
      pvSelectDate(ds);
    }

    function pvRenderDetail() {
      var empty = document.getElementById('pvDetailEmpty');
      var content = document.getElementById('pvDetailContent');
      if (!pvSelectedDate) {
        empty.style.display = '';
        content.style.display = 'none';
        return;
      }
      empty.style.display = 'none';
      content.style.display = '';
      var p = pvSelectedDate.split('-');
      var dateObj = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]));
      var dateLabel = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
      var state = pvGetDayState(pvSelectedDate);
      var availSlots = pvGetAvailableSlots(state.slots);
      var isClosed = state.closed || availSlots.length === 0;

      var html = '';
      html += '<div class="pv-detail-head">';
      html += '<div class="pv-detail-date">' + dateLabel;
      if (state.type === 'override') {
        html += '<span class="pv-detail-badge custom">Custom</span>';
      } else if (isClosed) {
        html += '<span class="pv-detail-badge closed-badge">Closed</span>';
      } else {
        html += '<span class="pv-detail-badge weekly">Weekly</span>';
      }
      html += '</div>';
      html += '<div class="pv-detail-source">';
      if (state.type === 'override') {
        html += 'Custom schedule &middot; Source: Date override';
      } else if (isClosed) {
        html += 'Source: Weekly schedule';
      } else {
        html += 'Source: Weekly schedule';
      }
      html += '</div>';
      html += '</div>';

      html += '<div class="pv-detail-nav">';
      html += '<button type="button" id="pvPrevDay"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg> Previous day</button>';
      html += '<button type="button" id="pvNextDay">Next day <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>';
      html += '</div>';

      html += '<div class="pv-detail-body">';
      if (isClosed) {
        html += '<div class="pv-closed">';
        html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        html += '<h4>No appointments available</h4>';
        html += '<p>There are no available appointment times for this date.</p>';
        html += '<div class="pv-closed-status">Date status: <strong>Closed</strong></div>';
        html += '</div>';
      } else if (pvViewMode === 'resident') {
        html += '<div class="pv-detail-label">Available appointment times</div>';
        html += '<div class="pv-slots">';
        availSlots.forEach(function(s) {
          html += '<div class="pv-slot"><span class="pv-slot-time">' + timeValToLabel(s) + '</span><span class="pv-slot-status open">Available</span></div>';
        });
        html += '</div>';
        html += '<div class="pv-slot-count">' + availSlots.length + ' available slot' + (availSlots.length !== 1 ? 's' : '') + '</div>';
      } else {
        html += '<div class="pv-detail-label">All appointment slots</div>';
        html += '<div class="pv-slots">';
        AV_SLOTS.forEach(function(s) {
          var isOpen = state.slots && state.slots[s] !== false;
          html += '<div class="pv-slot"><span class="pv-slot-time">' + timeValToLabel(s) + '</span><span class="pv-slot-status ' + (isOpen ? 'open' : 'closed') + '">' + (isOpen ? 'Available' : 'Closed') + '</span></div>';
        });
        html += '</div>';
        html += '<div class="pv-slot-count">' + availSlots.length + ' of ' + AV_SLOTS.length + ' slots available</div>';
      }
      html += '</div>';
      content.innerHTML = html;

      var prevBtn = document.getElementById('pvPrevDay');
      var nextBtn = document.getElementById('pvNextDay');
      if (prevBtn) prevBtn.addEventListener('click', function() { pvNavDay(-1); });
      if (nextBtn) nextBtn.addEventListener('click', function() { pvNavDay(1); });
    }

    function initPanel() {
      var panel = new URLSearchParams(window.location.search).get('panel') || '';
      if (panel === 'overrides') {
        var now = new Date();
        ovCalYear = now.getFullYear();
        ovCalMonth = now.getMonth();
        ovRenderCal();
        ovRenderEditor();
        ovRenderOverrideList();
        document.getElementById('ovCalPrev').addEventListener('click', function() {
          ovCalMonth--;
          if (ovCalMonth < 0) { ovCalMonth = 11; ovCalYear--; }
          ovRenderCal();
        });
        document.getElementById('ovCalNext').addEventListener('click', function() {
          ovCalMonth++;
          if (ovCalMonth > 11) { ovCalMonth = 0; ovCalYear++; }
          ovRenderCal();
        });
        var radios = document.querySelectorAll('input[name="ovMode"]');
        radios.forEach(function(r) { r.addEventListener('change', ovUpdateEditForm); });
        document.getElementById('ovAddRange').addEventListener('click', function() {
          var container = document.getElementById('ovTimeRanges');
          var rows = container.querySelectorAll('.ov-time-range-row');
          var lastRow = rows[rows.length - 1];
          var endSel = lastRow ? lastRow.querySelectorAll('select')[1] : null;
          var startVal = endSel ? endSel.value : '17:00';
          var ranges = [];
          container.querySelectorAll('.ov-time-range-row').forEach(function(row) {
            var sels = row.querySelectorAll('select');
            ranges.push({ start: sels[0].value, end: sels[1].value });
          });
          ranges.push({ start: startVal, end: '19:00' });
          ovRenderTimeRanges(ranges);
        });
        document.getElementById('ovEditCancel').addEventListener('click', function() {
          ovEditing = false;
          ovRenderEditor();
        });
        document.getElementById('ovEditSave').addEventListener('click', function() {
          var mode = document.querySelector('input[name="ovMode"]:checked').value;
          var dateStr = ovSelectedDate;
          if (!dateStr) return;
          for (var i = 0; i < dateOverrides.length; i++) {
            if (dateOverrides[i].date === dateStr) { dateOverrides.splice(i, 1); break; }
          }
          var slots = {};
          if (mode === 'weekly') {
            // Revert to weekly: delete the override row
            var body = new URLSearchParams();
            body.append('mode', 'date');
            body.append('date', dateStr);
            body.append('slots', '{}');
            fetch('availability-save.php', { method: 'POST', body: body })
              .then(function(r) { return r.json(); })
              .then(function(d) {
                if (d.ok) { toast('Reverted to weekly schedule', { type: 'info' }); loadFromServer(); }
                else { toast(d.error || 'Failed to save.', { type: 'error' }); }
              })
              .catch(function() { toast('Network error', { type: 'error' }); });
            return;
          } else if (mode === 'closed') {
            AV_SLOTS.forEach(function(s) { slots[s] = false; });
            dateOverrides.push({ date: dateStr, slots: slots });
          } else {
            var container = document.getElementById('ovTimeRanges');
            var ranges = [];
            container.querySelectorAll('.ov-time-range-row').forEach(function(row) {
              var sels = row.querySelectorAll('select');
              ranges.push({ start: sels[0].value, end: sels[1].value });
            });
            slots = ovRangesToSlots(ranges);
            dateOverrides.push({ date: dateStr, slots: slots });
          }
          var body = new URLSearchParams();
          body.append('mode', 'date');
          body.append('date', dateStr);
          body.append('slots', JSON.stringify(slots));
          fetch('availability-save.php', { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) {
              if (d.ok) {
                toast(mode === 'closed' ? 'Date closed' : 'Override saved', { description: 'Schedule updated for this date.' });
                loadFromServer();
              } else {
            toast(d.error || 'Failed to save.', { type: 'error' });
              }
            })
            .catch(function() { toast('Network error', { type: 'error' }); });
        });
      } else if (panel === 'bulk') {
        bulkInit();
      } else if (panel === 'preview') {
        var now = new Date();
        pvCalYear = now.getFullYear();
        pvCalMonth = now.getMonth();
        pvRenderCal();
        document.getElementById('pvCalPrev').addEventListener('click', function() {
          pvCalMonth--;
          if (pvCalMonth < 0) { pvCalMonth = 11; pvCalYear--; }
          pvRenderCal();
        });
        document.getElementById('pvCalNext').addEventListener('click', function() {
          pvCalMonth++;
          if (pvCalMonth > 11) { pvCalMonth = 0; pvCalYear++; }
          pvRenderCal();
        });
        document.getElementById('pvCalToday').addEventListener('click', function() {
          var now = new Date();
          pvCalYear = now.getFullYear();
          pvCalMonth = now.getMonth();
          pvSelectedDate = pvDateStr(pvCalYear, pvCalMonth, now.getDate());
          pvRenderCal();
          pvRenderDetail();
        });
        document.querySelectorAll('#pvViewToggle button').forEach(function(btn) {
          btn.addEventListener('click', function() {
            document.querySelectorAll('#pvViewToggle button').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            pvViewMode = btn.dataset.view;
            pvRenderDetail();
          });
        });
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      renderDays();
      renderOverrides();
      loadFromServer();
      initPanel();

      var dateInput = document.getElementById('modalDateInput');
      dateInput.addEventListener('change', function() {
        var slotsWrap = document.getElementById('modalSlotsWrap');
        var slotsContainer = document.getElementById('modalSlots');
        var saveBtn = document.getElementById('modalSaveBtn');
        if (!dateInput.value) { slotsWrap.style.display = 'none'; saveBtn.disabled = true; return; }
        slotsWrap.style.display = '';
        saveBtn.disabled = false;
        slotsContainer.innerHTML = '';
        for (var i = 0; i < AV_SLOTS.length; i++) {
          (function(slot) {
            var row = document.createElement('div');
            row.className = 'av-modal-slot';
            var lbl = document.createElement('span');
            lbl.className = 'av-modal-slot-label';
            lbl.textContent = timeValToLabel(slot);
            row.appendChild(lbl);
            var tog = document.createElement('button');
            tog.type = 'button';
            tog.className = 'av-modal-slot-toggle on';
            tog.textContent = 'Available';
            tog.dataset.slot = slot;
            tog.dataset.state = '1';
            tog.addEventListener('click', function() {
              var isOn = tog.dataset.state === '1';
              tog.dataset.state = isOn ? '0' : '1';
              tog.className = 'av-modal-slot-toggle ' + (isOn ? 'off' : 'on');
              tog.textContent = isOn ? 'Closed' : 'Available';
            });
            row.appendChild(tog);
            slotsContainer.appendChild(row);
          })(AV_SLOTS[i]);
        }
      });
    });

    function saveDateOverride() {
      var dateInput = document.getElementById('modalDateInput');
      var dateStr = dateInput.value;
      if (!dateStr) return;
      var toggles = document.querySelectorAll('#modalSlots .av-modal-slot-toggle');
      var slots = {};
      toggles.forEach(function(tog) {
        slots[tog.dataset.slot] = tog.dataset.state === '1';
      });
      for (var i = 0; i < dateOverrides.length; i++) {
        if (dateOverrides[i].date === dateStr) { dateOverrides.splice(i, 1); break; }
      }
      dateOverrides.push({ date: dateStr, slots: slots });
      closeDateModal();
      renderOverrides();
      ovRenderCal(); ovRenderEditor(); ovRenderOverrideList();
      updateFooter();
      var body = new URLSearchParams();
      body.append('mode', 'date');
      body.append('date', dateStr);
      body.append('slots', JSON.stringify(slots));
      fetch('availability-save.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.ok) {
            toast('Override saved', { description: 'Custom schedule set for ' + dateStr + '.' });
            loadFromServer();
          } else {
            toast(d.error || 'Failed to save.', { type: 'error' });
          }
        })
        .catch(function() { toast('Network error', { type: 'error' }); });
    }

    window.avDiscard = avDiscard;
    window.avSave = avSave;
    window.openDateModal = openDateModal;
    window.closeDateModal = closeDateModal;
    window.saveDateOverride = saveDateOverride;

    document.addEventListener('click', function(e) {
      if (!e.target.closest('.av-copy-popover') && !e.target.closest('.av-icon-btn')) {
        closeCopyPopovers();
      }
    });

  })();
  </script>
</body>
</html>