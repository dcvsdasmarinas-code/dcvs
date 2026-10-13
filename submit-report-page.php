<?php
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
require_once __DIR__ . '/includes/reports/location.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
if ($_SESSION['role'] !== 'resident') {
    header('Location: login.php');
    exit;
}

$officialBarangays = dcvsLoadApprovedBarangays($conn);
$reportPinEnabled = false;
$reportPinFeatures = [];
loadDcvsEnv();
$mapTilerApiKey = trim((string)env('DCVS_MAPTILER_API_KEY', ''));
if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') === '1') {
    $crosswalkPath = __DIR__ . '/DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv';
    $boundaryPath = __DIR__ . '/geo-map/barangays-boundary.json';
    $reportPinFeatures = dcvsLoadOfficialBarangayPolygons($crosswalkPath, $boundaryPath);
    $reportPinEnabled = dcvsReportPinFeatureEnabled($conn, $reportPinFeatures);
}

$activePage = 'submit-report';
$pageTitle = 'Submit Report';
?>
<!DOCTYPE html>
<html lang="en" class="resident-portal">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Submit a Report — DCVS Resident Portal</title>
  <meta name="description" content="Submit a stray animal, injured animal, rabies concern, or dead animal report to the Dasmariñas City Veterinary Services through your Resident Portal.">

  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
  <?php if ($reportPinEnabled): ?>
  <link rel="stylesheet" href="dist/mapcn.css" />
  <link rel="stylesheet" href="geo-map/map.css?v=<?php echo (int)filemtime(__DIR__ . '/geo-map/map.css'); ?>" />
  <?php endif; ?>

  <style>
    body { background: #ffffff !important; }
    .main-content { background: #ffffff !important; }
    /* Wizard-only page styles — all layout/global styles come from dashboard.css + sb2.css */
    .wizard-page-container {
      max-width: 860px;
      width: 100%;
      margin: 0 auto;
      padding: 12px 24px 80px;
    }

    .wizard-page-header {
      margin-bottom: 32px;
    }

    .wizard-page-title {
      font-size: 28px;
      font-weight: 800;
      color: #0F172A;
      letter-spacing: -0.02em;
      line-height: 1.2;
      margin-bottom: 6px;
    }

    .wizard-page-subtitle {
      font-size: 14.5px;
      color: #4B5563;
      line-height: 1.5;
    }

    /* Stepper */
    .stepper-container {
      padding: 20px 0;
      margin-bottom: 20px;
    }

    .stepper-track {
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      max-width: 540px;
      margin: 0 auto;
    }

    .stepper-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      position: relative;
      z-index: 2;
      flex: 0 0 auto;
      cursor: default;
    }

    .stepper-step.clickable { cursor: pointer; }

    .step-circle {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      font-weight: 700;
      border: 2px solid #E6ECE6;
      background: #FFFFFF;
      color: #9CA3AF;
      transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .stepper-step.active .step-circle {
      border-color: #18181b;
      background: #18181b;
      color: #fafafa;
      box-shadow: 0 0 0 3px rgba(24,24,27,0.12);
      transform: scale(1.03);
    }

    .stepper-step.completed .step-circle {
      border-color: #18181b;
      background: #f4f4f5;
      color: #18181b;
    }

    .step-label {
      font-size: 11.5px;
      font-weight: 600;
      color: #9CA3AF;
      text-align: center;
      white-space: nowrap;
      transition: color 0.3s ease;
    }

    .stepper-step.active .step-label { color: #18181b; font-weight: 700; }
    .stepper-step.completed .step-label { color: #18181b; font-weight: 600; }

    .stepper-connector {
      flex: 1;
      height: 2px;
      background: #E6ECE6;
      border-radius: 2px;
      margin: 0 -4px;
      margin-bottom: 26px;
      position: relative;
      z-index: 1;
      overflow: hidden;
    }

    .stepper-connector .connector-fill {
      height: 100%;
      width: 0%;
      background: #18181b;
      border-radius: 2px;
      transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .stepper-connector.filled .connector-fill { width: 100%; }

    /* Wizard Card */
    .wizard-card {
      background: #FFFFFF;
      border: 1px solid #E6ECE6;
      border-radius: 16px;
      box-shadow: 0 1px 3px 0 rgba(16,24,40,0.05), 0 1px 2px -1px rgba(16,24,40,0.04);
      overflow: hidden;
    }

    .wizard-card-body { padding: 28px 44px 32px; }

    .step-content {
      display: none;
      animation: rptFadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .step-content.active { display: block; }

    @keyframes rptFadeIn {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .step-header { margin-bottom: 28px; }

    .step-header .step-tag { display: none; }

    .step-header h2 {
      font-family: 'Inter','Segoe UI',sans-serif;
      font-size: 20px;
      font-weight: 600;
      line-height: 1;
      letter-spacing: -0.025em;
      color: #18181b;
      margin-bottom: 6px;
    }

    .step-header p { font-size: 15px; color: #71717a; line-height: 1.5; }

    /* Report Type Grid */
    .report-type-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
      margin-bottom: 28px;
    }

    .report-type-card {
      position: relative;
      background: #FFFFFF;
      border: 2px solid #E6ECE6;
      border-radius: 12px;
      padding: 20px;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: flex-start;
      gap: 14px;
    }

    .report-type-card:hover { border-color: #D4DDD4; background: #F9FBF9; }

    .report-type-card.selected {
      border-color: #0E8131;
      background: #EBF7EE;
      box-shadow: 0 0 0 3px rgba(14,129,49,0.1);
    }

    .report-type-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }

    .type-icon-box { display: none; }

    .type-info h4 { font-size: 15.5px; font-weight: 700; color: #111827; margin-bottom: 3px; }
    .type-info p { font-size: 13.5px; color: #6B7280; line-height: 1.4; }

    .type-check-indicator {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      border: 2px solid #E6ECE6;
      background: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }

    .report-type-card.selected .type-check-indicator { border-color: #0E8131; background: #0E8131; }
    .type-check-indicator svg { opacity: 0; transition: opacity 0.2s ease; }
    .report-type-card.selected .type-check-indicator svg { opacity: 1; }

    /* Form Elements */
    .form-section { margin-bottom: 24px; }

    .form-section-title {
      font-size: 15px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .form-section-title .section-icon { display: none; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-row.single { grid-template-columns: 1fr; }

    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group label { font-size: 14px; font-weight: 600; color: #4B5563; }
    .form-group label .required-star { color: #EF4444; margin-left: 2px; }
    .form-group .helper-text { font-size: 13px; color: #9CA3AF; margin-top: 2px; }

    .form-input, .form-select, .form-textarea {
      width: 100%;
      height: 48px;
      background: #FFFFFF;
      border: 1.5px solid #E6ECE6;
      border-radius: 10px;
      padding: 0 16px;
      font-size: 15px;
      font-family: inherit;
      color: #111827;
      outline: none;
      transition: all 0.15s ease;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
      border-color: #0E8131;
      box-shadow: 0 0 0 3px rgba(14,129,49,0.1);
    }

    .form-input::placeholder, .form-textarea::placeholder { color: #9CA3AF; }

    .form-select {
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2.5' xmlns='http://www.w3.org/2000/svg'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      padding-right: 36px;
      cursor: pointer;
    }

    .form-textarea { height: auto; min-height: 130px; padding: 14px 16px; resize: vertical; line-height: 1.5; }

    .char-count {
      font-size: 11.5px;
      color: #9CA3AF;
      text-align: right;
      margin-top: 4px;
      font-family: 'IBM Plex Mono', monospace;
    }

    /* Photo Upload */
    .upload-dropzone {
      border: 2px dashed #E6ECE6;
      border-radius: 12px;
      padding: 48px 28px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s ease;
      background: #F9FBF9;
      position: relative;
    }

    .upload-dropzone:hover, .upload-dropzone.drag-over {
      border-color: #0E8131;
      background: #EBF7EE;
    }

    .upload-dropzone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

    .upload-icon-circle {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: #EBF7EE;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
    }

    .upload-icon-circle svg { color: #0E8131; }

    .upload-dropzone h4 { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 4px; }
    .upload-dropzone h4 span { color: #0E8131; text-decoration: underline; text-underline-offset: 2px; }
    .upload-dropzone p { font-size: 13px; color: #6B7280; }

    .photo-previews {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 12px;
      margin-top: 20px;
    }

    .photo-preview-item {
      position: relative;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #E6ECE6;
      aspect-ratio: 1;
      background: #F3F4F6;
      animation: rptFadeIn 0.3s ease;
    }

    .photo-preview-item img,
    .photo-preview-item video { width: 100%; height: 100%; object-fit: cover; display: block; }

    .photo-preview-item.is-video { aspect-ratio: 16 / 9; background: #000; }

    .photo-preview-item.is-video .photo-name-label {
      top: 0;
      bottom: auto;
      background: linear-gradient(rgba(0,0,0,0.65), transparent);
    }

    .photo-remove-btn {
      position: absolute;
      top: 6px;
      right: 6px;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: rgba(0,0,0,0.6);
      color: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.15s ease;
      border: none;
    }

    .photo-remove-btn:hover { background: rgba(239,68,68,0.9); transform: scale(1.1); }

    .photo-name-label {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      padding: 6px 8px;
      background: linear-gradient(transparent, rgba(0,0,0,0.65));
      font-size: 11px;
      color: #FFFFFF;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Review Summary */
    .review-section { margin-bottom: 20px; }

    .review-section-header {
      display: flex;
      align-items: center;
      gap: 8px;
      padding-bottom: 10px;
      border-bottom: 1px solid #F0F4F0;
      margin-bottom: 14px;
    }

    .review-section-header .review-icon { display: none; }

    .review-section-header h4 { font-size: 14.5px; font-weight: 700; color: #111827; }

    .review-detail-grid {
      display: grid;
      grid-template-columns: 140px 1fr;
      gap: 10px 16px;
      padding-left: 40px;
    }

    .review-label { font-size: 13px; font-weight: 600; color: #6B7280; }
    .review-value { font-size: 13.5px; font-weight: 500; color: #111827; }

    .review-value .urgency-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 12px;
      font-weight: 700;
      padding: 3px 10px;
      border-radius: 9999px;
    }

    .urgency-badge.low, .urgency-badge.medium, .urgency-badge.high {
      background: transparent;
      color: #18181b;
      border: 1px solid #e4e4e7;
    }

    .review-photos-row { display: flex; gap: 10px; flex-wrap: wrap; padding-left: 40px; margin-top: 4px; }

    .review-photo-thumb {
      width: 72px;
      height: 72px;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #E6ECE6;
    }

    .review-photo-thumb img,
    .review-photo-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; }

    .review-photo-thumb.is-video { width: 160px; height: auto; aspect-ratio: 16 / 9; background: #000; }

    .review-edit-link {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      font-weight: 600;
      color: #0E8131;
      cursor: pointer;
      margin-left: auto;
      transition: opacity 0.15s ease;
    }

    .review-edit-link:hover { opacity: 0.7; }

    /* Info Notice */
    .info-notice {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 0;
      background: transparent;
      border: none;
      margin-top: 40px;
    }

    .info-notice .notice-icon { display: none; }
    .info-notice p { font-size: 13px; color: #71717a; line-height: 1.5; }
    .info-notice p strong { font-weight: 600; color: #18181b; }

    /* Wizard Footer */
    .wizard-card-footer {
      padding: 20px 0;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      background: transparent;
    }

    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      padding: 8px 16px;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-back:hover { background: #f4f4f5; }

    .btn-continue {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #18181b;
      border: 1px solid transparent;
      border-radius: 6px;
      padding: 8px 16px;
      font-size: 14px;
      font-weight: 500;
      color: #fafafa;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-continue:hover { background: #27272a; }
    .btn-continue:disabled { background: #a1a1aa; cursor: not-allowed; }

    .btn-submit-report {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #18181b;
      border: 1px solid transparent;
      border-radius: 6px;
      padding: 8px 16px;
      font-size: 14px;
      font-weight: 500;
      color: #fafafa;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-submit-report:hover { background: #27272a; }

    .btn-placeholder { visibility: hidden; width: 100px; }

    /* Success Overlay */
    .success-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(8px);
      z-index: 100;
      align-items: center;
      justify-content: center;
    }

    .success-overlay.visible { display: flex; animation: fadeIn 0.3s ease; }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .success-card {
      background: #FFFFFF;
      border: 1px solid #E6ECE6;
      border-radius: 20px;
      padding: 48px 44px;
      text-align: center;
      max-width: 480px;
      width: 90%;
      box-shadow: 0 12px 28px -4px rgba(15,23,42,0.12), 0 4px 10px -2px rgba(15,23,42,0.05);
      animation: scaleUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes scaleUp { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

    .success-check-circle {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: #EBF7EE;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
    }

    .success-check-circle svg { color: #0E8131; }

    .success-card h2 { font-size: 24px; font-weight: 800; color: #0F172A; margin-bottom: 8px; }
    .success-card p { font-size: 14px; color: #4B5563; margin-bottom: 6px; line-height: 1.5; }

    .success-ref-code {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: 'IBM Plex Mono', monospace;
      font-size: 14px;
      font-weight: 600;
      color: #0B4D26;
      background: #EBF7EE;
      border: 1px solid #D1F0DA;
      border-radius: 8px;
      padding: 8px 16px;
      margin: 16px 0 24px;
    }

    .success-actions { display: flex; gap: 12px; justify-content: center; }
    .success-actions .btn-back { font-size: 13.5px; }
    .success-actions .btn-continue { font-size: 13.5px; }

    /* Toast */
    .toast-notification {
      position: fixed;
      bottom: 28px;
      left: 50%;
      transform: translateX(-50%) translateY(80px);
      background: #18181b;
      color: #fafafa;
      font-size: 13.5px;
      font-weight: 600;
      padding: 12px 24px;
      border-radius: 6px;
      box-shadow: 0 12px 28px -4px rgba(15,23,42,0.12);
      z-index: 200;
      display: flex;
      align-items: center;
      gap: 8px;
      opacity: 0;
      transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
      pointer-events: none;
    }

    .toast-notification.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    .toast-notification svg { flex-shrink: 0; }

    <?php if ($reportPinEnabled): ?>
    .report-location-picker { border: 1px solid #E6ECE6; border-radius: 12px; padding: 14px; background: #F9FBF9; }
    .report-location-picker-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
    .report-location-picker-actions svg { width: 18px; height: 18px; vertical-align: middle; }
    .report-location-map { height: 340px; position: relative; overflow: hidden; border-radius: 10px; background: #EEF2F6; touch-action: pan-x pan-y; }
    .report-location-map .mapcn-container { width: 100%; height: 100%; }
    .report-location-map .mapcn-loading { z-index: 2; }
    .report-location-map.is-expanded { position: fixed; inset: 0; height: 100vh; height: 100dvh; width: 100vw; z-index: 9999; border-radius: 0; }
    .report-location-status { margin-top: 10px; color: #475569; font-size: 13px; }
    .report-location-status.is-error { color: #B42318; }
    .report-location-fallback-link { margin-top: 10px; padding: 0; border: 0; background: transparent; color: #166534; text-decoration: underline; font: inherit; cursor: pointer; }
    .report-location-fallback { display: flex; flex-direction: column; gap: 6px; margin-top: 12px; }
    .report-location-fallback[hidden] { display: none; }
    .report-location-map-pin { position: absolute; z-index: 5; display: flex; align-items: flex-start; justify-content: center; width: 48px; height: 60px; padding: 0; border: 0; background: transparent; cursor: grab; touch-action: none; transform: translate(-50%, -100%); filter: drop-shadow(0 3px 4px rgba(0,0,0,.4)); }
    .report-location-map-pin svg { display: block; width: 48px; height: 60px; overflow: visible; }
    .report-location-map-pin:active { cursor: grabbing; }
    .report-location-map-pin.is-preview { cursor: default; pointer-events: none; }
    .report-location-map-pin[hidden] { display: none; }
    .report-location-expand-close { display: none; }
    .report-location-map.is-expanded ~ .report-location-expand-close { display: inline-flex; position: fixed; top: 14px; right: 14px; z-index: 10000; }
    .review-pin-map { width: 100%; height: 190px; margin-top: 14px; border-radius: 10px; overflow: hidden; background: #EEF2F6; }
    @media (max-width: 768px) {
      .report-location-map { height: 420px; }
      .report-location-picker-actions { align-items: flex-start; flex-direction: column; }
      .report-location-map .maplibregl-ctrl-group button { width: 44px; height: 44px; }
      .review-pin-map { height: 220px; }
    }
    <?php endif; ?>

    /* Responsive */
    @media (max-width: 900px) {
      .wizard-page-container { padding: 24px 16px 60px; }
      .stepper-container { padding: 20px; }
      .wizard-card-body { padding: 28px 24px 24px; }
      .wizard-card-footer { padding: 16px 24px; }
      .review-detail-grid { padding-left: 0; grid-template-columns: 120px 1fr; }
      .review-photos-row { padding-left: 0; }
    }

    @media (max-width: 768px) {
      .stepper-container { padding: 18px 12px; overflow-x: auto; }
      .stepper-track { min-width: 520px; }
      .report-type-grid { grid-template-columns: 1fr; }
      .form-row { grid-template-columns: 1fr; }
      .wizard-card-body { padding: 24px 18px 20px; }
      .wizard-card-footer { padding: 14px 18px; }
      .wizard-page-title { font-size: 22px; }
      .btn-continue, .btn-back { padding: 10px 18px; font-size: 13px; }
    }

    @media (max-width: 480px) {
      .wizard-page-title { font-size: 20px; }
      .step-header h2 { font-size: 18px; }
      .photo-previews { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
      .review-detail-grid { grid-template-columns: 1fr; gap: 4px 0; }
      .review-label { font-weight: 700; color: #4B5563; }
      .success-card { padding: 36px 24px; }
    }

    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body class="resident-portal">

<?php include 'includes/resident-sidebar.php'; ?>

<main class="main-content" id="mainContent">
  <div class="wizard-page-container" id="residentReportWizard" data-draft-form="resident-report-wizard">
    <input type="hidden" id="residentReportDraftStep" data-draft-state value="1">
    <input type="hidden" id="residentReportDraftHighestStep" data-draft-state value="1">

    <!-- Horizontal Progress Stepper -->
    <div class="stepper-container" role="navigation" aria-label="Form progress">
      <div class="stepper-track">
        <div class="stepper-step active clickable" data-step="1" onclick="goToStep(1)" aria-current="step">
          <div class="step-circle">1</div>
          <span class="step-label">What?</span>
        </div>
        <div class="stepper-connector" id="connector-1-2"><div class="connector-fill"></div></div>
        <div class="stepper-step clickable" data-step="2" onclick="goToStep(2)">
          <div class="step-circle">2</div>
          <span class="step-label">Where?</span>
        </div>
        <div class="stepper-connector" id="connector-2-3"><div class="connector-fill"></div></div>
        <div class="stepper-step clickable" data-step="3" onclick="goToStep(3)">
          <div class="step-circle">3</div>
          <span class="step-label">Details</span>
        </div>
        <div class="stepper-connector" id="connector-3-4"><div class="connector-fill"></div></div>
        <div class="stepper-step clickable" data-step="4" onclick="goToStep(4)">
          <div class="step-circle">4</div>
          <span class="step-label">Photos</span>
        </div>
        <div class="stepper-connector" id="connector-4-5"><div class="connector-fill"></div></div>
        <div class="stepper-step clickable" data-step="5" onclick="goToStep(5)">
          <div class="step-circle">5</div>
          <span class="step-label">Review</span>
        </div>
      </div>
    </div>

    <!-- Wizard Card -->
    <div class="wizard-card">
      <div class="wizard-card-body">

        <!-- STEP 1: WHAT? -->
        <div class="step-content active" id="step-1">
          <div class="step-header">
            <div class="step-tag">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r=".5"/></svg>
              Step 1 of 5
            </div>
            <h2>What are you reporting?</h2>
            <p>Select the type of animal-related incident you want to report in your community.</p>
          </div>

          <div class="form-section">
            <div class="form-section-title">
              <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Report type
            </div>
            <div class="report-type-grid" role="radiogroup" aria-label="Report type">
              <label class="report-type-card" id="type-stray" tabindex="0" role="radio" aria-checked="false">
                <input type="radio" name="reportType" value="Stray Animal">
                <div class="type-icon-box stray">🐕</div>
                <div class="type-info">
                  <h4>Stray Animal</h4>
                  <p>An unattended or wandering animal without a visible owner</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </label>
              <label class="report-type-card" id="type-injured" tabindex="0" role="radio" aria-checked="false">
                <input type="radio" name="reportType" value="Injured Animal">
                <div class="type-icon-box injured">🩹</div>
                <div class="type-info">
                  <h4>Injured Animal</h4>
                  <p>An animal that appears hurt, limping, or in visible distress</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </label>
              <label class="report-type-card" id="type-rabies" tabindex="0" role="radio" aria-checked="false">
                <input type="radio" name="reportType" value="Rabies Concern">
                <div class="type-icon-box rabies">⚠️</div>
                <div class="type-info">
                  <h4>Rabies Concern</h4>
                  <p>Suspected rabid behavior such as aggression, foaming, or erratic movement</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </label>
              <label class="report-type-card" id="type-dead" tabindex="0" role="radio" aria-checked="false">
                <input type="radio" name="reportType" value="Dead Animal">
                <div class="type-icon-box dead">🕊️</div>
                <div class="type-info">
                  <h4>Dead Animal</h4>
                  <p>A deceased animal found in a public area requiring removal</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">
              <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              How urgent is this?
            </div>
            <div class="form-group">
              <select class="form-select" id="urgency-level" aria-label="Urgency level">
                <option value="" disabled selected>Select urgency level</option>
                <option value="low">Low — Not immediately dangerous</option>
                <option value="medium">Medium — Needs attention soon</option>
                <option value="high">High — Requires immediate response</option>
              </select>
              <span class="helper-text">Select how urgent this report is so responders can prioritize accordingly.</span>
            </div>
          </div>
        </div>

        <!-- STEP 2: WHERE? -->
        <div class="step-content" id="step-2">
          <div class="step-header">
            <div class="step-tag">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              Step 2 of 5
            </div>
            <h2>Where and when did it happen?</h2>
            <p>Provide the location and date/time of the incident so responders can locate it quickly.</p>
          </div>

          <div class="form-section">
            <div class="form-row single">
              <div class="form-group">
                <label for="location-barangay">Incident Barangay <span class="required-star">*</span></label>
                <select class="form-select" id="location-barangay">
                  <option value="" disabled selected>Select the incident barangay</option>
                  <?php foreach ($officialBarangays as $barangay): ?>
                    <option value="<?php echo htmlspecialchars($barangay['psgc_code'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($barangay['barangay_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="helper-text"><?php echo $reportPinEnabled ? 'For pinned reports, this is automatically set from the incident pin, not your registered barangay. Select it manually only if you cannot place a pin.' : 'Select the barangay where the incident happened.'; ?></span>
              </div>
            </div>
          </div>

          <?php if ($reportPinEnabled): ?>
          <div class="form-section">
            <div class="form-section-title">Pin the incident location</div>
            <div class="report-location-picker">
              <div class="report-location-picker-actions">
                <span class="helper-text">Tap the map to place a pin, then drag it to the exact spot.</span>
                <button type="button" class="btn-back" id="use-report-gps">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                  Use my location
                </button>
              </div>
              <div class="report-location-map" id="reportLocationMap" aria-label="Incident location map" data-map-basemap-toggle="true" data-city-boundary="true" data-map-landmarks-session="resident"<?php if ($mapTilerApiKey !== ''): ?> data-maptiler-key="<?php echo htmlspecialchars($mapTilerApiKey, ENT_QUOTES, 'UTF-8'); ?>" data-preserve-map-view="true"<?php endif; ?>>
                <div class="mapcn-loading"><i class="fas fa-spinner"></i> The map loads when this step is opened.</div>
                <div class="geo-basemap-status" data-basemap-status role="status" aria-live="polite"></div>
              </div>
              <button type="button" class="btn-back report-location-expand-close" id="close-expanded-report-map">Close expanded map</button>
              <button type="button" class="report-location-fallback-link" id="expand-report-map" aria-pressed="false">Expand map</button>
              <div class="report-location-status" id="report-location-status" role="status">Place a pin inside Dasmariñas.</div>
              <button type="button" class="report-location-fallback-link" id="report-location-fallback-toggle">I can't pin the location</button>
              <div class="report-location-fallback" id="report-location-fallback" hidden>
                <span class="helper-text">Describe the incident location in Street / Landmark / Specific Location, and attach at least one photo or video in the next step.</span>
              </div>
            </div>
            <input type="hidden" id="report-latitude" value="">
            <input type="hidden" id="report-longitude" value="">
            <input type="hidden" id="report-pin-source" value="resident">
          </div>
          <?php endif; ?>

          <div class="form-section">
            <div class="form-row single">
              <div class="form-group">
                <label for="location-street">Street / Landmark / Specific Location <span class="required-star">*</span></label>
                <input type="text" class="form-input" id="location-street" placeholder="e.g., Phase 3, near the covered court; entrance beside the blue gate">
                <span class="helper-text"><?php echo $reportPinEnabled ? 'Map details may be unclear when zoomed in. Add directions responders can follow: phase, sitio or purok, street, nearby landmark, entrance, or which side of the road. The map pin and your written directions work together.' : 'Add directions responders can follow, such as the phase, sitio or purok, street, nearby landmark, entrance, or which side of the road.'; ?></span>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-row">
              <div class="form-group">
                <label for="incident-date">Date of Incident <span class="required-star">*</span></label>
                <input type="date" class="form-input" id="incident-date">
              </div>
              <div class="form-group">
                <label for="incident-time">Approximate Time</label>
                <input type="time" class="form-input" id="incident-time">
                <span class="helper-text">Optional — provide if you can recall.</span>
              </div>
            </div>
          </div>
        </div>

        <!-- STEP 3: DETAILS -->
        <div class="step-content" id="step-3">
          <div class="step-header">
            <div class="step-tag">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Step 3 of 5
            </div>
            <h2>Provide more details</h2>
            <p>Describe the incident and the animal involved. The more information you provide, the better responders can prepare.</p>
          </div>

          <div class="form-section">
            <div class="form-section-title">
              <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="4" cy="8" r="2"/><path d="M12 18c3 0 6-2 6-6 0-3-2-5-6-5s-6 2-6 5c0 4 3 6 6 6z"/></svg>
              Animal information
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="animal-type">Animal Type <span class="required-star">*</span></label>
                <select class="form-select" id="animal-type">
                  <option value="" disabled selected>Select type</option>
                  <option value="Dog">Dog</option>
                  <option value="Cat">Cat</option>
                  <option value="Bird">Bird</option>
                  <option value="Horse">Horse</option>
                  <option value="Goat">Goat</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="form-group">
                <label for="animal-breed">Breed / Appearance <span class="required-star">*</span></label>
                <input type="text" class="form-input" id="animal-breed" placeholder="e.g., Aspin, brown fur, medium size">
              </div>
            </div>
            <div class="form-row" style="margin-top: 16px;">
              <div class="form-group">
                <label for="animal-count">Estimated Number of Animals <span class="required-star">*</span></label>
                <select class="form-select" id="animal-count">
                  <option value="" disabled selected>How many?</option>
                  <option value="1">1</option>
                  <option value="2-3">2–3</option>
                  <option value="4-5">4–5</option>
                  <option value="6+">6 or more</option>
                </select>
              </div>
              <div class="form-group">
                <label for="animal-behavior">Animal Behavior <span class="required-star">*</span></label>
                <select class="form-select" id="animal-behavior">
                  <option value="" disabled selected>Select behavior</option>
                  <option value="Calm / Resting">Calm / Resting</option>
                  <option value="Fearful / Hiding">Fearful / Hiding</option>
                  <option value="Aggressive">Aggressive</option>
                  <option value="Injured / Limping">Injured / Limping</option>
                  <option value="Erratic / Disoriented">Erratic / Disoriented</option>
                  <option value="Deceased">Deceased</option>
                </select>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">
              <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              Incident description
            </div>
            <div class="form-group">
              <label for="incident-description">Describe what you observed <span class="required-star">*</span></label>
              <textarea class="form-textarea" id="incident-description" placeholder="e.g., I noticed a group of stray dogs near the covered court around 6 AM. They were barking aggressively at passing residents and appeared to be malnourished..." maxlength="1000" oninput="updateCharCount(this, 'desc-count')"></textarea>
              <div class="char-count" id="desc-count">0 / 1,000</div>
            </div>
          </div>
        </div>

        <!-- STEP 4: PHOTOS -->
        <div class="step-content" id="step-4">
          <div class="step-header">
            <div class="step-tag">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              Step 4 of 5
            </div>
            <h2>Upload supporting photos or videos</h2>
            <p id="upload-requirement-note"><?php echo $reportPinEnabled ? 'Attach photos or a short video of the animal or location to help responders assess the situation. Photos or videos are required if you cannot place an incident pin.' : 'Attach photos or a short video of the animal or location to help responders assess the situation. This step is optional but highly encouraged.'; ?></p>
          </div>

          <div class="form-section">
            <div class="upload-dropzone" id="upload-dropzone" role="button" tabindex="0" aria-label="Upload photos or videos">
              <input type="file" id="photo-input" multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/ogg,video/x-m4v,video/quicktime" onchange="handleFileUpload(this.files)">
              <div class="upload-icon-circle">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="17 8 12 3 7 8"/>
                  <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
              </div>
              <h4>Drag and drop photos or videos here, or <span>browse files</span></h4>
              <p>PNG, JPG, WEBP, MP4 or WebM — Up to 5 files, max 10 MB each</p>
            </div>
            <div class="photo-previews" id="photo-previews"></div>
          </div>
        </div>

        <!-- STEP 5: REVIEW & SUBMIT -->
        <div class="step-content" id="step-5">
          <div class="step-header">
            <div class="step-tag">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
              Step 5 of 5
            </div>
            <h2>Review your report</h2>
            <p>Please review all the information below before submitting. You can go back to any step to make changes.</p>
          </div>

          <div class="review-section">
            <div class="review-section-header">
              <div class="review-icon green-bg">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              </div>
              <h4>Report Type & Urgency</h4>
              <a class="review-edit-link" onclick="goToStep(1)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
              </a>
            </div>
            <div class="review-detail-grid">
              <span class="review-label">Report Type</span>
              <span class="review-value" id="review-type">—</span>
              <span class="review-label">Urgency</span>
              <span class="review-value" id="review-urgency">—</span>
            </div>
          </div>

          <div class="review-section">
            <div class="review-section-header">
              <div class="review-icon blue-bg">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              </div>
              <h4>Location & Time</h4>
              <a class="review-edit-link" onclick="goToStep(2)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
              </a>
            </div>
            <div class="review-detail-grid">
              <span class="review-label">Barangay</span>
              <span class="review-value" id="review-barangay">—</span>
              <span class="review-label">Location</span>
              <span class="review-value" id="review-location">—</span>
              <span class="review-label">Date</span>
              <span class="review-value" id="review-date">—</span>
              <span class="review-label">Time</span>
              <span class="review-value" id="review-time">—</span>
            </div>
            <?php if ($reportPinEnabled): ?>
            <div id="review-pin-location" hidden>
              <div class="review-pin-map" id="reviewReportLocationMap" aria-label="Incident location preview"></div>
              <span class="review-value" id="review-pin-summary"></span>
            </div>
            <?php endif; ?>
          </div>

          <div class="review-section">
            <div class="review-section-header">
              <div class="review-icon amber-bg">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              </div>
              <h4>Incident Details</h4>
              <a class="review-edit-link" onclick="goToStep(3)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
              </a>
            </div>
            <div class="review-detail-grid">
              <span class="review-label">Animal Type</span>
              <span class="review-value" id="review-animal-type">—</span>
              <span class="review-label">Breed / Look</span>
              <span class="review-value" id="review-breed">—</span>
              <span class="review-label">Count</span>
              <span class="review-value" id="review-count">—</span>
              <span class="review-label">Behavior</span>
              <span class="review-value" id="review-behavior">—</span>
              <span class="review-label">Description</span>
              <span class="review-value" id="review-description">—</span>
            </div>
          </div>

          <div class="review-section">
            <div class="review-section-header">
              <div class="review-icon green-bg">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              </div>
              <h4>Uploaded Photos & Videos</h4>
              <a class="review-edit-link" onclick="goToStep(4)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
              </a>
            </div>
            <div class="review-photos-row" id="review-photos">
              <span class="review-value" style="padding-left:0;">No photos or videos attached</span>
            </div>
          </div>

          <div class="info-notice">
            <svg class="notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <p><strong>What happens next?</strong> Your report will first be sent to your assigned <strong>barangay officer</strong> for initial validation and assessment. Once verified, it will be forwarded to <strong>DCVS (Dasmariñas City Veterinary Services)</strong> for proper action and response. You can track the status of your report anytime from your dashboard.</p>
          </div>
        </div>

      </div>

    </div><!-- /wizard-card -->

      <div class="wizard-card-footer" id="wizard-footer">
        <div id="footer-left">
          <div class="btn-placeholder"></div>
        </div>
        <button class="btn-continue" id="btn-next" onclick="nextStep()">
          <span>Continue</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
          </svg>
        </button>
      </div>

  </div>
</main>

<!-- Success Overlay -->
<div class="success-overlay" id="success-overlay">
  <div class="success-card">
    <div class="success-check-circle">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    </div>
    <h2>Report Submitted!</h2>
    <p>Your report has been successfully submitted and is now pending validation by your barangay officer.</p>
    <div class="success-ref-code" id="success-ref-code">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <span id="ref-code-text">—</span>
    </div>
    <p style="font-size: 13px; color: #6B7280;">You can track your report status anytime from your Resident Dashboard.</p>
    <div class="success-actions" style="margin-top: 20px;">
      <a href="dashboard-resident.php#my-reports" class="btn-back">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
        Go to Dashboard
      </a>
      <button class="btn-continue" onclick="resetWizard()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Submit Another Report
      </button>
    </div>
  </div>
</div>

<!-- Toast Notification -->
<div class="toast-notification" id="toast-notification">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
  <span id="toast-message"></span>
</div>

<script>
  // ==========================================================================
  // STATE MANAGEMENT
  // ==========================================================================
  let currentStep = 1;
  const totalSteps = 5;
  let uploadedFiles = [];
  let highestVisitedStep = 1;
  var reportMapLoadPromise = null;
  var reportMapInstance = null;
  var reportMapFeatures = null;
  var reportPinCoordinates = null;
  var reportPinAccuracyMeters = null;
  var reportPinBarangayCode = '';
  var reportFallbackMode = false;
  var reportReviewMapInstance = null;
  var reportMapPinElement = null;
  var reportReviewPinElement = null;

  function loadReportMapBundle() {
    if (window.MapCN && typeof window.MapCN.init === 'function') return Promise.resolve();
    if (reportMapLoadPromise) return reportMapLoadPromise;

    reportMapLoadPromise = new Promise(function(resolve, reject) {
      var script = document.createElement('script');
      script.src = 'dist/mapcn.umd.cjs?v=<?php echo (int)filemtime(__DIR__ . '/dist/mapcn.umd.cjs'); ?>';
      script.onload = function() {
        if (window.MapCN && typeof window.MapCN.init === 'function') resolve();
        else reject(new Error('The report map library did not initialize.'));
      };
      script.onerror = function() { reject(new Error('The report map library could not be loaded.')); };
      document.head.appendChild(script);
    });
    return reportMapLoadPromise;
  }

  function reportBarangayCodeByName() {
    var result = {};
    document.querySelectorAll('#location-barangay option[value]').forEach(function(option) {
      if (option.value) result[option.textContent.trim().toLocaleLowerCase()] = option.value;
    });
    return result;
  }

  function reportBoundaryFeatureByCode(code) {
    if (!reportMapFeatures || !code) return null;
    var names = reportBarangayCodeByName();
    return reportMapFeatures.features.find(function(feature) {
      return names[String(feature.properties.name || '').trim().toLocaleLowerCase()] === code;
    }) || null;
  }

  function reportFeatureBounds(feature) {
    var bounds = [[Infinity, Infinity], [-Infinity, -Infinity]];
    function visit(value) {
      if (!Array.isArray(value)) return;
      if (value.length >= 2 && typeof value[0] === 'number' && typeof value[1] === 'number') {
        bounds[0][0] = Math.min(bounds[0][0], value[0]);
        bounds[0][1] = Math.min(bounds[0][1], value[1]);
        bounds[1][0] = Math.max(bounds[1][0], value[0]);
        bounds[1][1] = Math.max(bounds[1][1], value[1]);
        return;
      }
      value.forEach(visit);
    }
    visit(feature.geometry.coordinates);
    return Number.isFinite(bounds[0][0]) ? bounds : null;
  }

  function reportFeatureAtPoint(point) {
    if (!reportMapInstance || !reportMapInstance.getLayer('barangay-fill')) return null;
    var features = reportMapInstance.queryRenderedFeatures(point, { layers: ['barangay-fill'] });
    var names = {};
    features.forEach(function(feature) {
      var name = String(feature.properties.name || '').trim();
      if (name) names[name.toLocaleLowerCase()] = name;
    });
    var matchedNames = Object.keys(names);
    if (matchedNames.length !== 1) return null;
    var name = names[matchedNames[0]];
    return { name: name, code: reportBarangayCodeByName()[name.toLocaleLowerCase()] || '' };
  }

  function updateReportPinMarker() {
    if (!reportMapInstance || !reportMapPinElement || !reportPinCoordinates) return;
    var point = reportMapInstance.project(reportPinCoordinates);
    reportMapPinElement.style.left = point.x + 'px';
    reportMapPinElement.style.top = point.y + 'px';
    reportMapPinElement.hidden = false;
  }

  function updateReportReviewPinMarker() {
    if (!reportReviewMapInstance || !reportReviewPinElement || !reportPinCoordinates) return;
    var point = reportReviewMapInstance.project(reportPinCoordinates);
    reportReviewPinElement.style.left = point.x + 'px';
    reportReviewPinElement.style.top = point.y + 'px';
    reportReviewPinElement.hidden = false;
  }

  function setReportPinStatus(message, isError) {
    var status = document.getElementById('report-location-status');
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-error', !!isError);
  }

  function reportAccuracyCircle(longitude, latitude, radiusMeters) {
    var coordinates = [];
    var earthRadius = 6371008.8;
    var angularDistance = radiusMeters / earthRadius;
    var lat1 = latitude * Math.PI / 180;
    var lon1 = longitude * Math.PI / 180;
    for (var index = 0; index <= 64; index++) {
      var bearing = index * 2 * Math.PI / 64;
      var lat2 = Math.asin(Math.sin(lat1) * Math.cos(angularDistance)
        + Math.cos(lat1) * Math.sin(angularDistance) * Math.cos(bearing));
      var lon2 = lon1 + Math.atan2(
        Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(lat1),
        Math.cos(angularDistance) - Math.sin(lat1) * Math.sin(lat2)
      );
      coordinates.push([lon2 * 180 / Math.PI, lat2 * 180 / Math.PI]);
    }
    return { type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [coordinates] } };
  }

  function setReportPin(longitude, latitude, source, detected, finalize, accuracyMeters) {
    reportPinCoordinates = [Number(longitude), Number(latitude)];
    reportPinAccuracyMeters = Number.isFinite(accuracyMeters) && accuracyMeters > 0 ? accuracyMeters : null;
    document.getElementById('report-latitude').value = String(reportPinCoordinates[1]);
    document.getElementById('report-longitude').value = String(reportPinCoordinates[0]);
    document.getElementById('report-pin-source').value = source || 'resident';
    reportFallbackMode = false;
    document.getElementById('report-location-fallback').hidden = true;
    document.getElementById('report-location-fallback-toggle').textContent = "I can't pin the location";
    document.getElementById('upload-requirement-note').textContent =
      'Attach photos or a short video of the animal or location to help responders assess the situation. Photos or videos are required if you cannot place an incident pin.';

    reportPinBarangayCode = detected && detected.code ? detected.code : '';
    if (reportMapInstance && reportMapInstance.getSource('report-pin-accuracy')) {
      reportMapInstance.getSource('report-pin-accuracy').setData(
        reportPinAccuracyMeters
          ? reportAccuracyCircle(reportPinCoordinates[0], reportPinCoordinates[1], reportPinAccuracyMeters)
          : { type: 'FeatureCollection', features: [] }
      );
    }
    updateReportReviewPinMarker();
    if (!detected || !detected.code) {
      var barangaySelect = document.getElementById('location-barangay');
      barangaySelect.value = '';
      barangaySelect.disabled = false;
      setReportPinStatus('This pin is outside Dasmariñas or on an ambiguous barangay boundary. Move it inside one barangay.', true);
    } else if (finalize !== false) {
      var barangaySelect = document.getElementById('location-barangay');
      barangaySelect.value = detected.code;
      barangaySelect.disabled = true;
      setReportPinStatus('Incident location detected in ' + detected.name + '. The report will go to that barangay.', false);
    } else {
      setReportPinStatus('Pin is in ' + detected.name + '.', false);
    }

    updateReportPinMarker();
  }

  function setupReportLocationMap(map) {
    reportMapInstance = map;
    reportMapPinElement = document.createElement('button');
    reportMapPinElement.type = 'button';
    reportMapPinElement.className = 'report-location-map-pin';
    reportMapPinElement.setAttribute('aria-label', 'Drag to adjust incident pin');
    reportMapPinElement.innerHTML = '<svg viewBox="0 0 24 30" aria-hidden="true" focusable="false"><path d="M12 29S2 19.8 2 11a10 10 0 1 1 20 0c0 8.8-10 18-10 18Z" fill="#e53935" stroke="#fff" stroke-width="1.4"/><circle cx="12" cy="11" r="3.2" fill="#fff"/></svg>';
    reportMapPinElement.hidden = true;
    reportMapPinElement.addEventListener('click', function(event) { event.stopPropagation(); });
    map.getContainer().appendChild(reportMapPinElement);

    map.addSource('report-pin-accuracy', {
      type: 'geojson',
      data: { type: 'FeatureCollection', features: [] }
    });
    map.addLayer({
      id: 'report-pin-accuracy-fill',
      type: 'fill',
      source: 'report-pin-accuracy',
      paint: { 'fill-color': '#2563EB', 'fill-opacity': 0.14 }
    });
    map.addLayer({
      id: 'report-pin-accuracy-outline',
      type: 'line',
      source: 'report-pin-accuracy',
      paint: { 'line-color': '#1D4ED8', 'line-width': 2, 'line-opacity': 0.75 }
    });
    map.addSource('barangays', { type: 'geojson', data: reportMapFeatures });
    map.addLayer({
      id: 'barangay-fill',
      type: 'fill',
      source: 'barangays',
      paint: { 'fill-color': '#ffffff', 'fill-opacity': 0 }
    });
    map.addLayer({
      id: 'barangay-outline',
      type: 'line',
      source: 'barangays',
      paint: { 'line-color': '#64748B', 'line-width': 1.25, 'line-opacity': 0.8 }
    });
    map.addLayer({
      id: 'geo-brgy-label',
      type: 'symbol',
      source: 'barangays',
      layout: {
        'text-field': ['get', 'name'],
        'text-size': 11,
        'text-anchor': 'center',
        'text-allow-overlap': false
      },
      paint: { 'text-color': '#1e293b', 'text-halo-color': '#ffffff', 'text-halo-width': 2 }
    });
    document.getElementById('reportLocationMap').addEventListener('dcvs:basemapstyleload', function() {
      var accuracySource = map.getSource('report-pin-accuracy');
      if (accuracySource) {
        accuracySource.setData(reportPinCoordinates && reportPinAccuracyMeters
          ? reportAccuracyCircle(reportPinCoordinates[0], reportPinCoordinates[1], reportPinAccuracyMeters)
          : { type: 'FeatureCollection', features: [] });
      }
    });
    map.on('click', function(event) {
      var detected = reportFeatureAtPoint(event.point);
      setReportPin(event.lngLat.lng, event.lngLat.lat, 'resident', detected);
    });
    map.on('render', updateReportPinMarker);

    reportMapPinElement.addEventListener('pointerdown', function(event) {
      event.preventDefault();
      event.stopPropagation();
      reportMapPinElement.setPointerCapture(event.pointerId);
      map.dragPan.disable();
    });
    reportMapPinElement.addEventListener('pointermove', function(event) {
      if (!reportMapPinElement.hasPointerCapture(event.pointerId)) return;
      event.preventDefault();
      var rect = map.getContainer().getBoundingClientRect();
      var point = map.unproject([event.clientX - rect.left, event.clientY - rect.top]);
      setReportPin(point.lng, point.lat, document.getElementById('report-pin-source').value, reportFeatureAtPoint(map.project([point.lng, point.lat])), false);
    });
    reportMapPinElement.addEventListener('pointerup', function(event) {
      if (reportPinCoordinates) {
        setReportPin(reportPinCoordinates[0], reportPinCoordinates[1],
          document.getElementById('report-pin-source').value,
          reportFeatureAtPoint(map.project(reportPinCoordinates)));
      }
      map.dragPan.enable();
      if (reportMapPinElement.hasPointerCapture(event.pointerId)) reportMapPinElement.releasePointerCapture(event.pointerId);
    });
    ['pointercancel', 'lostpointercapture'].forEach(function(eventName) {
      reportMapPinElement.addEventListener(eventName, function() { map.dragPan.enable(); });
    });

    document.getElementById('location-barangay').addEventListener('change', function() {
      var feature = reportBoundaryFeatureByCode(this.value);
      var bounds = feature ? reportFeatureBounds(feature) : null;
      if (bounds) map.fitBounds(bounds, { padding: 48, maxZoom: 17, duration: 500 });
    });

    var selectedFeature = reportBoundaryFeatureByCode(document.getElementById('location-barangay').value);
    var selectedBounds = selectedFeature ? reportFeatureBounds(selectedFeature) : null;
    if (selectedBounds) map.fitBounds(selectedBounds, { padding: 48, maxZoom: 17, duration: 0 });

    if (reportPinCoordinates) updateReportPinMarker();
  }

  function initializeReportLocationMap() {
    if (!document.getElementById('reportLocationMap') || reportMapInstance) return;
    var container = document.getElementById('reportLocationMap');
    container.addEventListener('mapcn:load', function(event) {
      if (event.detail && event.detail.map) setupReportLocationMap(event.detail.map);
    }, { once: true });

    loadReportMapBundle()
      .then(function() {
        return fetch('geo-map/barangays-boundary.json', { credentials: 'same-origin' });
      })
      .then(function(response) {
        if (!response.ok) throw new Error('Boundary data request failed with HTTP ' + response.status + '.');
        return response.json();
      })
      .then(function(geojson) {
        if (!geojson || !Array.isArray(geojson.features) || geojson.features.length !== 75) {
          throw new Error('The official barangay boundary set is incomplete.');
        }
        reportMapFeatures = geojson;
        window.MapCN.init('reportLocationMap', {
          center: [120.968, 14.306],
          zoom: 15,
          maxZoom: 20,
          barangayOverlay: false,
          style: 'https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json'
        });
      })
      .catch(function(error) {
        console.error('Report location map initialization failed:', error);
        setReportPinStatus('The location map could not load. Use the written location details and attach a photo or video.', true);
      });
  }

  function initializeReviewPinMap() {
    var reviewSection = document.getElementById('review-pin-location');
    if (!reviewSection || !reportPinCoordinates) return;
    reviewSection.hidden = false;
    document.getElementById('reviewReportLocationMap').hidden = false;
    var selected = document.getElementById('location-barangay').selectedOptions[0];
    var summary = selected ? selected.textContent : 'Selected barangay';
    document.getElementById('review-pin-summary').textContent =
      summary + ' · ' + reportPinCoordinates[1].toFixed(6) + ', ' + reportPinCoordinates[0].toFixed(6);

    loadReportMapBundle().then(function() {
      if (reportReviewMapInstance) {
        reportReviewMapInstance.setCenter(reportPinCoordinates);
        updateReportReviewPinMarker();
        return;
      }
      var reviewMapElement = document.getElementById('reviewReportLocationMap');
      reviewMapElement.addEventListener('mapcn:load', function(event) {
        reportReviewMapInstance = event.detail.map;
        reportReviewPinElement = document.createElement('span');
        reportReviewPinElement.className = 'report-location-map-pin is-preview';
        reportReviewPinElement.setAttribute('aria-hidden', 'true');
        reportReviewPinElement.innerHTML = '<svg viewBox="0 0 24 30" focusable="false"><path d="M12 29S2 19.8 2 11a10 10 0 1 1 20 0c0 8.8-10 18-10 18Z" fill="#e53935" stroke="#fff" stroke-width="1.4"/><circle cx="12" cy="11" r="3.2" fill="#fff"/></svg>';
        reportReviewPinElement.hidden = true;
        reportReviewMapInstance.getContainer().appendChild(reportReviewPinElement);
        reportReviewMapInstance.on('render', updateReportReviewPinMarker);
        updateReportReviewPinMarker();
      }, { once: true });
      window.MapCN.init('reviewReportLocationMap', {
        center: reportPinCoordinates,
        zoom: 14,
        barangayOverlay: false,
        style: 'https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json'
      });
    }).catch(function(error) {
      console.error('Report review map initialization failed:', error);
    });
  }

  // ==========================================================================
  // STEP NAVIGATION
  // ==========================================================================
  function goToStep(step) {
    if (step > highestVisitedStep + 1 || step < 1 || step > totalSteps) return;
    if (step > currentStep && !validateStep(currentStep)) return;

    currentStep = step;
    if (step > highestVisitedStep) highestVisitedStep = step;
    document.getElementById('residentReportDraftStep').value = String(currentStep);
    document.getElementById('residentReportDraftHighestStep').value = String(highestVisitedStep);

    updateStepperUI();
    updateStepContent();
    updateFooter();

    if (step === 5) populateReview();

    document.querySelector('.stepper-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function nextStep() {
    if (currentStep < totalSteps) {
      goToStep(currentStep + 1);
    } else {
      submitReport();
    }
  }

  function prevStep() {
    if (currentStep > 1) goToStep(currentStep - 1);
  }

  // ==========================================================================
  // STEPPER UI UPDATE
  // ==========================================================================
  function updateStepperUI() {
    const steps = document.querySelectorAll('.stepper-step');
    const connectors = document.querySelectorAll('.stepper-connector');

    steps.forEach((step, idx) => {
      const stepNum = idx + 1;
      const circle = step.querySelector('.step-circle');
      step.classList.remove('active', 'completed');
      step.removeAttribute('aria-current');

      if (stepNum === currentStep) {
        step.classList.add('active');
        step.setAttribute('aria-current', 'step');
        circle.innerHTML = stepNum;
      } else if (stepNum < currentStep) {
        step.classList.add('completed');
        circle.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
      } else {
        circle.innerHTML = stepNum;
      }
    });

    connectors.forEach((conn, idx) => {
      if (idx < currentStep - 1) {
        conn.classList.add('filled');
      } else {
        conn.classList.remove('filled');
      }
    });
  }

  function updateStepContent() {
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    const target = document.getElementById('step-' + currentStep);
    if (target) target.classList.add('active');
    if (currentStep === 2) initializeReportLocationMap();
  }

  // ==========================================================================
  // FOOTER BUTTONS
  // ==========================================================================
  function updateFooter() {
    const footerLeft = document.getElementById('footer-left');
    const btnNext = document.getElementById('btn-next');

    if (currentStep === 1) {
      footerLeft.innerHTML = '<div class="btn-placeholder"></div>';
    } else {
      footerLeft.innerHTML =
        '<button class="btn-back" onclick="prevStep()">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
            '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>' +
          '</svg>' +
          '<span>Back</span>' +
        '</button>';
    }

    if (currentStep === totalSteps) {
      btnNext.outerHTML =
        '<button class="btn-submit-report" id="btn-next" onclick="nextStep()">' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
            '<polyline points="20 6 9 17 4 12"/>' +
          '</svg>' +
          '<span>Submit Report</span>' +
        '</button>';
    } else {
      const existing = document.getElementById('btn-next');
      if (!existing || existing.classList.contains('btn-submit-report')) {
        const footer = document.getElementById('wizard-footer');
        const oldBtn = document.getElementById('btn-next');
        if (oldBtn) oldBtn.remove();
        const newBtn = document.createElement('button');
        newBtn.className = 'btn-continue';
        newBtn.id = 'btn-next';
        newBtn.onclick = nextStep;
        newBtn.innerHTML =
          '<span>Continue</span>' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
            '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>' +
          '</svg>';
        footer.appendChild(newBtn);
      }
    }
  }

  // ==========================================================================
  // VALIDATION
  // ==========================================================================
  function validateStep(step) {
    switch (step) {
      case 1: {
        const reportType = document.querySelector('input[name="reportType"]:checked');
        const urgency = document.getElementById('urgency-level').value;
        if (!reportType) { showToast('Please select a report type to continue.'); return false; }
        if (!urgency) { showToast('Please select an urgency level.'); return false; }
        return true;
      }
      case 2: {
        const barangay = document.getElementById('location-barangay').value;
        const street = document.getElementById('location-street').value.trim();
        const date = document.getElementById('incident-date').value;
        if (!barangay) { showToast('Please select the incident barangay.'); return false; }
        if (document.getElementById('report-location-fallback')) {
          if (!reportFallbackMode && (!reportPinCoordinates || !reportPinBarangayCode)) {
            showToast('Place a pin inside Dasmariñas or use the unpinned fallback.');
            return false;
          } else if (!reportFallbackMode && reportPinBarangayCode !== barangay) {
            showToast('The incident barangay is determined by the pin. Place it inside the correct barangay.');
            return false;
          }
        }
        if (!street) { showToast('Please provide a specific location or landmark.'); return false; }
        if (!date) { showToast('Please provide the date of the incident.'); return false; }
        return true;
      }
      case 3: {
        const animalType = document.getElementById('animal-type').value;
        const description = document.getElementById('incident-description').value.trim();
        if (!animalType) { showToast('Please select the animal type.'); return false; }
        if (!description) { showToast('Please describe the incident.'); return false; }
        return true;
      }
      case 4: {
        if (reportFallbackMode && uploadedFiles.length === 0) {
          showToast('Attach at least one photo or video when submitting without an incident pin.');
          return false;
        }
        return true;
      }
      case 5: return true;
      default: return true;
    }
  }

  // ==========================================================================
  // REPORT TYPE SELECTION
  // ==========================================================================
  document.querySelectorAll('.report-type-card').forEach(card => {
    card.addEventListener('click', function () {
      document.querySelectorAll('.report-type-card').forEach(c => {
        c.classList.remove('selected');
        c.setAttribute('aria-checked', 'false');
      });
      this.classList.add('selected');
      this.setAttribute('aria-checked', 'true');
      this.querySelector('input[type="radio"]').checked = true;
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.click();
      }
    });
  });

  // ==========================================================================
  // CHARACTER COUNT
  // ==========================================================================
  function updateCharCount(textarea, countId) {
    const count = textarea.value.length;
    const max = textarea.maxLength;
    document.getElementById(countId).textContent = count.toLocaleString() + ' / ' + max.toLocaleString();
  }

  document.addEventListener('dcvs:draft-restored', function(event) {
    if (!event.detail || event.detail.root.id !== 'residentReportWizard') return;
    const selectedType = document.querySelector('input[name="reportType"]:checked');
    document.querySelectorAll('.report-type-card').forEach(card => {
      const selected = selectedType && card.querySelector('input[type="radio"]') === selectedType;
      card.classList.toggle('selected', !!selected);
      card.setAttribute('aria-checked', selected ? 'true' : 'false');
    });
    const restoredStep = parseInt(document.getElementById('residentReportDraftStep').value, 10);
    const restoredHighestStep = parseInt(document.getElementById('residentReportDraftHighestStep').value, 10);
    currentStep = Math.min(totalSteps, Math.max(1, restoredStep || 1));
    highestVisitedStep = Math.min(totalSteps, Math.max(currentStep, restoredHighestStep || currentStep));
    updateStepperUI();
    updateStepContent();
    updateFooter();
    if (currentStep === totalSteps) populateReview();
    updateCharCount(document.getElementById('incident-description'), 'desc-count');
  });

  // ==========================================================================
  // PHOTO UPLOAD
  // ==========================================================================
  const dropzone = document.getElementById('upload-dropzone');

  dropzone.addEventListener('dragover', function(e) {
    e.preventDefault();
    dropzone.classList.add('drag-over');
  });

  dropzone.addEventListener('dragleave', function() {
    dropzone.classList.remove('drag-over');
  });

  dropzone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropzone.classList.remove('drag-over');
    handleFileUpload(e.dataTransfer.files);
  });

  function handleFileUpload(files) {
    const maxFiles = 5;
    const maxSize = 10 * 1024 * 1024;
    const allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const allowedVideoTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/x-m4v', 'video/quicktime'];

    for (const file of files) {
      const isImage = allowedImageTypes.indexOf(file.type) !== -1;
      const isVideo = allowedVideoTypes.indexOf(file.type) !== -1;
      if (uploadedFiles.length >= maxFiles) {
        showToast('Maximum ' + maxFiles + ' files allowed.');
        break;
      }
      if (!isImage && !isVideo) {
        showToast('"' + file.name + '" is not an image or video file.');
        continue;
      }
      if (file.size > maxSize) {
        showToast('"' + file.name + '" exceeds the 10 MB limit.');
        continue;
      }
      uploadedFiles.push(file);
    }

    renderPhotoPreviews();
    document.getElementById('photo-input').value = '';
  }

  function renderPhotoPreviews() {
    const container = document.getElementById('photo-previews');
    container.innerHTML = '';

    uploadedFiles.forEach(function(file, index) {
      const isVideo = file.type.startsWith('video/');
      const reader = new FileReader();
      reader.onload = function(e) {
        const item = document.createElement('div');
        item.className = 'photo-preview-item' + (isVideo ? ' is-video' : '');
        const media = isVideo
          ? '<video src="' + e.target.result + '" controls playsinline preload="metadata"></video>'
          : '<img src="' + e.target.result + '" alt="' + file.name + '">';
        item.innerHTML =
          media +
          '<button class="photo-remove-btn" onclick="removePhoto(' + index + ')" title="Remove ' + (isVideo ? 'video' : 'photo') + '" aria-label="Remove ' + file.name + '">' +
            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">' +
              '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' +
            '</svg>' +
          '</button>' +
          '<div class="photo-name-label" title="' + file.name + '">' + file.name + '</div>';
        container.appendChild(item);
      };
      reader.readAsDataURL(file);
    });
  }

  function removePhoto(index) {
    uploadedFiles.splice(index, 1);
    renderPhotoPreviews();
  }

  // ==========================================================================
  // REVIEW POPULATION
  // ==========================================================================
  function populateReview() {
    var reportType = document.querySelector('input[name="reportType"]:checked');
    document.getElementById('review-type').textContent = reportType ? reportType.value : '—';

    var urgency = document.getElementById('urgency-level').value;
    var urgencyEl = document.getElementById('review-urgency');
    if (urgency) {
      var cls = urgency.toLowerCase();
      urgencyEl.innerHTML = '<span class="urgency-badge ' + cls + '">● ' + urgency.charAt(0).toUpperCase() + urgency.slice(1) + '</span>';
    } else {
      urgencyEl.textContent = '—';
    }

    var selectedBarangay = document.getElementById('location-barangay').selectedOptions[0];
    document.getElementById('review-barangay').textContent = selectedBarangay && selectedBarangay.value ? selectedBarangay.textContent : '—';
    document.getElementById('review-location').textContent = document.getElementById('location-street').value || '—';
    initializeReviewPinMap();
    if (document.getElementById('review-pin-location') && !reportPinCoordinates) {
      document.getElementById('review-pin-location').hidden = true;
      document.getElementById('review-pin-summary').textContent = reportFallbackMode
        ? 'No exact pin — see the written location details and attached evidence.'
        : 'No exact pin provided.';
      document.getElementById('review-pin-location').hidden = false;
      document.getElementById('reviewReportLocationMap').hidden = true;
    }

    var dateVal = document.getElementById('incident-date').value;
    if (dateVal) {
      var d = new Date(dateVal + 'T00:00:00');
      document.getElementById('review-date').textContent = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    } else {
      document.getElementById('review-date').textContent = '—';
    }

    var timeVal = document.getElementById('incident-time').value;
    if (timeVal) {
      var parts = timeVal.split(':');
      var td = new Date();
      td.setHours(parseInt(parts[0]), parseInt(parts[1]));
      document.getElementById('review-time').textContent = td.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    } else {
      document.getElementById('review-time').textContent = 'Not specified';
    }

    document.getElementById('review-animal-type').textContent = document.getElementById('animal-type').value || '—';
    document.getElementById('review-breed').textContent = document.getElementById('animal-breed').value || 'Not specified';
    document.getElementById('review-count').textContent = document.getElementById('animal-count').value || 'Not specified';
    document.getElementById('review-behavior').textContent = document.getElementById('animal-behavior').value || 'Not specified';

    var desc = document.getElementById('incident-description').value.trim();
    document.getElementById('review-description').textContent = desc || '—';

    var photosContainer = document.getElementById('review-photos');
    if (uploadedFiles.length === 0) {
      photosContainer.innerHTML = '<span class="review-value" style="padding-left:0;">No photos or videos attached</span>';
    } else {
      photosContainer.innerHTML = '';
      uploadedFiles.forEach(function(file) {
        var isVideo = file.type.startsWith('video/');
        var reader = new FileReader();
        reader.onload = function(e) {
          var thumb = document.createElement('div');
          thumb.className = 'review-photo-thumb' + (isVideo ? ' is-video' : '');
          thumb.innerHTML = isVideo
            ? '<video src="' + e.target.result + '" controls playsinline preload="metadata"></video>'
            : '<img src="' + e.target.result + '" alt="' + file.name + '">';
          photosContainer.appendChild(thumb);
        };
        reader.readAsDataURL(file);
      });
    }
  }

  // ==========================================================================
  // SUBMIT REPORT (AJAX)
  // ==========================================================================
  function submitReport() {
    var btn = document.getElementById('btn-next');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML =
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 1s linear infinite;">' +
          '<circle cx="12" cy="12" r="10" stroke-dasharray="31.4 31.4"/>' +
        '</svg>' +
        '<span>Submitting...</span>';
    }

    var reportType = document.querySelector('input[name="reportType"]:checked');
    var dateVal = document.getElementById('incident-date').value || '';
    var timeVal = document.getElementById('incident-time').value || '';
    var reportDatetime = timeVal ? dateVal + ' ' + timeVal : dateVal;

    var animalType = document.getElementById('animal-type').value || '';
    var animalBreed = document.getElementById('animal-breed').value || '';
    var animalCount = document.getElementById('animal-count').value || '';
    var animalBehavior = document.getElementById('animal-behavior').value || '';

    var barangayCode = document.getElementById('location-barangay').value || '';
    var street = document.getElementById('location-street').value || '';

    var description = document.getElementById('incident-description').value.trim();
    var urgency = document.getElementById('urgency-level').value.toLowerCase();

    var formData = new FormData();
    formData.append('report_type', reportType ? reportType.value : '');
    formData.append('report_datetime', reportDatetime);
    formData.append('animal_type', animalType);
    formData.append('animal_breed', animalBreed);
    formData.append('animal_count', animalCount);
    formData.append('animal_behavior', animalBehavior);
    formData.append('barangay_psgc_code', barangayCode);
    formData.append('location_detail', street);
    if (document.getElementById('report-latitude')) {
      formData.append('latitude', document.getElementById('report-latitude').value);
      formData.append('longitude', document.getElementById('report-longitude').value);
      formData.append('pin_source', document.getElementById('report-pin-source').value);
    }
    formData.append('description', description);
    formData.append('urgency', urgency);
    formData.append('return_section', 'my-reports');

    for (var fi = 0; fi < uploadedFiles.length; fi++) {
      formData.append('attachments[]', uploadedFiles[fi]);
    }

    fetch('submit-report.php', {
      method: 'POST',
      body: formData
    })
    .then(function(response) {
      return response.text().then(function(html) {
        if (!response.redirected) return { html: html, redirected: false };
        var redirectedPage = new DOMParser().parseFromString(html, 'text/html');
        var serverError = redirectedPage.querySelector('#report-submit-error');
        if (serverError) {
          showToast(serverError.textContent.trim());
          resetSubmitButton();
          return null;
        }
        if (response.url.indexOf('dashboard-resident.php') !== -1) {
          showSuccess('RPT-' + Date.now());
          return null;
        }
        showToast('The report could not be submitted. Please sign in again and retry.');
        resetSubmitButton();
        return null;
      });
    })
    .then(function(result) {
      if (!result || result.redirected) return;
      if (result.html.includes('report_error') || result.html.includes('error')) {
        showToast('Failed to submit report. Please review the form and try again.');
        resetSubmitButton();
      } else {
        var refId = 'RPT-' + Date.now();
        showSuccess(refId);
      }
    })
    .catch(function() {
      showToast('Network error. Please check your connection and try again.');
      resetSubmitButton();
    });
  }

  function showSuccess(refId) {
    var draftRoot = document.getElementById('residentReportWizard');
    draftRoot.dataset.draftSubmitted = 'true';
    if (typeof window.DCVSClearDraft === 'function') window.DCVSClearDraft(draftRoot);
    document.getElementById('ref-code-text').textContent = refId;
    document.getElementById('success-overlay').classList.add('visible');
    document.body.style.overflow = 'hidden';
    resetSubmitButton();
  }

  function resetSubmitButton() {
    var btn = document.getElementById('btn-next');
    if (btn && btn.classList.contains('btn-submit-report')) {
      btn.disabled = false;
      btn.innerHTML =
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
          '<polyline points="20 6 9 17 4 12"/>' +
        '</svg>' +
        '<span>Submit Report</span>';
    }
  }

  // ==========================================================================
  // RESET WIZARD
  // ==========================================================================
  function resetWizard() {
    document.getElementById('residentReportWizard').removeAttribute('data-draft-submitted');
    document.getElementById('success-overlay').classList.remove('visible');
    document.body.style.overflow = '';

    currentStep = 1;
    highestVisitedStep = 1;
    document.getElementById('residentReportDraftStep').value = '1';
    document.getElementById('residentReportDraftHighestStep').value = '1';
    uploadedFiles = [];

    document.querySelectorAll('.report-type-card').forEach(function(c) {
      c.classList.remove('selected');
      c.setAttribute('aria-checked', 'false');
    });
    document.querySelectorAll('input[type="radio"]').forEach(function(r) { r.checked = false; });
    document.getElementById('urgency-level').selectedIndex = 0;
    document.getElementById('location-barangay').selectedIndex = 0;
    document.getElementById('location-street').value = '';
    reportPinCoordinates = null;
    reportPinBarangayCode = '';
    if (reportReviewPinElement) reportReviewPinElement.hidden = true;
    reportFallbackMode = false;
    if (document.getElementById('report-latitude')) document.getElementById('report-latitude').value = '';
    if (document.getElementById('report-longitude')) document.getElementById('report-longitude').value = '';
    if (document.getElementById('report-pin-source')) document.getElementById('report-pin-source').value = 'resident';
    if (document.getElementById('report-location-fallback')) document.getElementById('report-location-fallback').hidden = true;
    if (document.getElementById('report-location-fallback-toggle')) document.getElementById('report-location-fallback-toggle').textContent = "I can't pin the location";
    if (document.getElementById('report-location-status')) setReportPinStatus('Place a pin inside Dasmariñas.', false);
    if (reportMapPinElement) reportMapPinElement.hidden = true;
    document.getElementById('incident-date').value = '';
    document.getElementById('incident-time').value = '';
    document.getElementById('animal-type').selectedIndex = 0;
    document.getElementById('animal-breed').value = '';
    document.getElementById('animal-count').selectedIndex = 0;
    document.getElementById('animal-behavior').selectedIndex = 0;
    document.getElementById('incident-description').value = '';
    document.getElementById('desc-count').textContent = '0 / 1,000';
    document.getElementById('photo-previews').innerHTML = '';

    updateStepperUI();
    updateStepContent();
    updateFooter();

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  async function restoreResubmitReport() {
    var raw;
    try { raw = sessionStorage.getItem('dcvsResubmitReport'); } catch (e) { return; }
    if (!raw) return;

    var report;
    try {
      report = JSON.parse(raw);
      sessionStorage.removeItem('dcvsResubmitReport');
    } catch (e) { return; }
    if (!report) return;

    var typeMap = {
      'stray': 'Stray Animal',
      'stray animal': 'Stray Animal',
      'injured': 'Injured Animal',
      'injured animal': 'Injured Animal',
      'rabies': 'Rabies Concern',
      'rabies concern': 'Rabies Concern',
      'dead': 'Dead Animal',
      'dead animal': 'Dead Animal'
    };
    var reportType = typeMap[(report.typeKey || '').toLowerCase()];
    var typeInput = reportType ? document.querySelector('input[name="reportType"][value="' + reportType + '"]') : null;
    if (typeInput) {
      typeInput.checked = true;
      var typeCard = typeInput.closest('.report-type-card');
      if (typeCard) {
        typeCard.classList.add('selected');
        typeCard.setAttribute('aria-checked', 'true');
      }
    }

    var urgency = document.getElementById('urgency-level');
    if (urgency && report.urgency) urgency.value = report.urgency.toLowerCase();

    var location = (report.location || '').trim();
    var barangay = document.getElementById('location-barangay');
    var street = document.getElementById('location-street');
    if (barangay && location) {
      var comma = location.indexOf(',');
      var barangayValue = comma === -1 ? location : location.slice(0, comma).trim();
      var streetValue = comma === -1 ? '' : location.slice(comma + 1).trim();
      var option = Array.prototype.slice.call(barangay.options).find(function(item) {
        return item.textContent.trim().toLowerCase() === barangayValue.toLowerCase();
      });
      if (option) {
        barangay.value = option.value;
        street.value = streetValue;
      } else {
        street.value = location;
      }
    }

    var reportIncidentDate = report.incidentRaw || report.createdRaw;
    if (reportIncidentDate) {
      var created = reportIncidentDate.split('T');
      var incidentDate = document.getElementById('incident-date');
      var incidentTime = document.getElementById('incident-time');
      if (incidentDate) incidentDate.value = created[0] || '';
      if (incidentTime) incidentTime.value = (created[1] || '').slice(0, 5);
    }

    var description = report.description || '';
    var parts = (description || '').split(/\s*-\s*/).filter(Boolean);
    var animalType = document.getElementById('animal-type');
    if (animalType && parts.length && ['dog','cat','bird','horse','goat','other'].indexOf(parts[0].trim().toLowerCase()) !== -1) {
      var animalOption = Array.prototype.slice.call(animalType.options).find(function(item) {
        return item.value.toLowerCase() === parts[0].trim().toLowerCase();
      });
      if (animalOption) animalType.value = animalOption.value;
    }
    var incidentDescription = document.getElementById('incident-description');
    if (incidentDescription) {
      var incidentOnly = description;
      if (parts.length && ['dog','cat','bird','horse','goat','other'].indexOf(parts[0].trim().toLowerCase()) !== -1) {
        incidentOnly = parts.slice(4).join(' - ');
        if (!incidentOnly) incidentOnly = parts.slice(1).join(' - ');
      }
      incidentDescription.value = incidentOnly || description;
      updateCharCount(incidentDescription, 'desc-count');
    }

    var restoredFiles = [];
    var photo = report.photo || '';
    if (photo) {
      try {
        var photoResponse = await fetch(photo);
        if (photoResponse.ok) {
          var photoBlob = await photoResponse.blob();
          var photoName = photo.split('/').pop().split('?')[0] || 'report-photo';
          restoredFiles.push(new File([photoBlob], photoName, { type: photoBlob.type || 'image/jpeg' }));
        }
      } catch (e) {}
    }
    var video = report.video || '';
    if (video) {
      try {
        var videoResponse = await fetch(video);
        if (videoResponse.ok) {
          var videoBlob = await videoResponse.blob();
          var videoName = video.split('/').pop().split('?')[0] || 'report-video';
          restoredFiles.push(new File([videoBlob], videoName, { type: videoBlob.type || 'video/mp4' }));
        }
      } catch (e) {}
    }
    if (restoredFiles.length > 0) {
      uploadedFiles = restoredFiles;
      renderPhotoPreviews();
    }

    highestVisitedStep = 4;
    goToStep(5);
  }

  // ==========================================================================
  // TOAST NOTIFICATIONS
  // ==========================================================================
  var toastTimeout;
  function showToast(message) {
    clearTimeout(toastTimeout);
    var toast = document.getElementById('toast-notification');
    document.getElementById('toast-message').textContent = message;
    toast.classList.add('show');
    toastTimeout = setTimeout(function() { toast.classList.remove('show'); }, 3500);
  }

  // ==========================================================================
  // INIT
  // ==========================================================================
  document.addEventListener('DOMContentLoaded', function() {
    var today = new Date();
    var yyyy = today.getFullYear();
    var mm = String(today.getMonth() + 1).padStart(2, '0');
    var dd = String(today.getDate()).padStart(2, '0');
    document.getElementById('incident-date').value = yyyy + '-' + mm + '-' + dd;
    var fallbackToggle = document.getElementById('report-location-fallback-toggle');
    if (fallbackToggle) {
      fallbackToggle.addEventListener('click', function() {
        reportFallbackMode = !reportFallbackMode;
        document.getElementById('report-location-fallback').hidden = !reportFallbackMode;
        this.textContent = reportFallbackMode ? 'I can place a pin instead' : "I can't pin the location";
        if (reportFallbackMode) {
          document.getElementById('location-barangay').disabled = false;
          reportPinCoordinates = null;
          reportPinBarangayCode = '';
          document.getElementById('report-latitude').value = '';
          document.getElementById('report-longitude').value = '';
          if (reportMapPinElement) reportMapPinElement.hidden = true;
          document.getElementById('upload-requirement-note').textContent =
            'Photos or videos are required because you are continuing without an incident pin. Describe the location in the Street / Landmark / Specific Location field.';
          setReportPinStatus('No pin selected. Describe the incident location and attach photo or video evidence.', false);
        } else {
          document.getElementById('location-barangay').disabled = false;
          document.getElementById('upload-requirement-note').textContent =
            'Attach photos or a short video of the animal or location to help responders assess the situation. Photos or videos are required if you cannot place an incident pin.';
          setReportPinStatus('Place a pin inside Dasmariñas.', false);
        }
      });
    }
    var gpsButton = document.getElementById('use-report-gps');
    if (gpsButton) {
      gpsButton.addEventListener('click', function() {
        if (!navigator.geolocation) {
          showToast('Location services are not available in this browser.');
          return;
        }
        navigator.geolocation.getCurrentPosition(function(position) {
          if (!reportMapInstance) {
            showToast('The map is still loading. Please try again.');
            return;
          }
          var coords = [position.coords.longitude, position.coords.latitude];
          var point = reportMapInstance.project(coords);
          setReportPin(coords[0], coords[1], 'gps_assisted', reportFeatureAtPoint(point), true, position.coords.accuracy);
          reportMapInstance.flyTo({ center: coords, zoom: 15 });
        }, function(error) {
          console.warn('Incident location GPS request failed:', error.message);
          showToast('Unable to use your location. Check browser permission or place the pin manually.');
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
      });
    }
    var expandButton = document.getElementById('expand-report-map');
    if (expandButton) {
      function setReportMapExpanded(expanded) {
        var mapElement = document.getElementById('reportLocationMap');
        mapElement.classList.toggle('is-expanded', expanded);
        expandButton.setAttribute('aria-pressed', expanded ? 'true' : 'false');
        expandButton.textContent = expanded ? 'Close expanded map' : 'Expand map';
        if (reportMapInstance) setTimeout(function() { reportMapInstance.resize(); }, 50);
      }
      expandButton.addEventListener('click', function() { setReportMapExpanded(true); });
      document.getElementById('close-expanded-report-map').addEventListener('click', function() {
        setReportMapExpanded(false);
      });
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && document.getElementById('reportLocationMap').classList.contains('is-expanded')) {
          setReportMapExpanded(false);
        }
      });
    }
    restoreResubmitReport();
  });
</script>

<?php if ($reportPinEnabled): ?>
<script src="assets/js/map-landmarks.js?v=<?php echo (int)filemtime(__DIR__ . '/assets/js/map-landmarks.js'); ?>"></script>
<script src="assets/js/map-basemap-toggle.js?v=<?php echo (int)filemtime(__DIR__ . '/assets/js/map-basemap-toggle.js'); ?>"></script>
<?php endif; ?>

<style>
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

</body>
</html>
