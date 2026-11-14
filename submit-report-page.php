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
$residentBarangayCode = '';
$residentBarangayId = (int)($_SESSION['barangay_id'] ?? 0);
foreach ($officialBarangays as $approvedBarangay) {
    if ($residentBarangayId > 0 && (int)$approvedBarangay['barangay_id'] === $residentBarangayId) {
        $residentBarangayCode = $approvedBarangay['psgc_code'];
        break;
    }
}
$reportPinEnabled = false;
$reportPinFeatures = [];
loadDcvsEnv();
$mapTilerApiKey = trim((string)env('DCVS_MAPTILER_API_KEY', ''));
$reportSatelliteStyle = [
    'version' => 8,
    'glyphs' => 'https://tiles.basemaps.cartocdn.com/v/20261026-01/fonts/{fontstack}/{range}.pbf',
    'sources' => [
        'esri-world-imagery' => [
            'type' => 'raster',
            'tiles' => ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
            'tileSize' => 256,
            'attribution' => 'Esri, Maxar, Earthstar Geographics, and the GIS User Community',
        ],
    ],
    'layers' => [
        ['id' => 'esri-world-imagery', 'type' => 'raster', 'source' => 'esri-world-imagery'],
    ],
];
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
  <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo (int)filemtime(__DIR__ . '/assets/css/dashboard.css'); ?>" />
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo (int)filemtime(__DIR__ . '/assets/css/responsive.css'); ?>" />
  <?php if ($reportPinEnabled): ?>
  <link rel="stylesheet" href="assets/css/map-place-search.css?v=<?php echo (int)filemtime(__DIR__ . '/assets/css/map-place-search.css'); ?>" />
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
    .stepper-current-label { display: none; }
    .stepper-mobile-view { display: none; }
    .stepper-mobile-progress { display: none; }
    .stepper-mobile-segment { display: block; min-width: 0; height: 4px; border-radius: 2px; background: #CBD2CB; }
    .stepper-mobile-segment.completed { background: #166534; }
    .stepper-mobile-segment.current { background: #14201A; }
    .step-completed-label { display: none; }
    .stepper-step.completed .step-completed-label {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

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
      line-height: 1.3;
      letter-spacing: -0.025em;
      color: #14201A;
      margin-bottom: 4px;
    }

    .step-header p { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#5B675F; line-height:1.45; }

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
    .report-location-map .maplibregl-ctrl-top-right { top: auto; right: 12px; bottom: 52px; }
    .report-location-map .mapcn-loading { z-index: 2; }
    .report-location-map.is-expanded { position: fixed; inset: 0; height: 100vh; height: 100dvh; width: 100vw; z-index: 9999; border-radius: 0; }
    .report-location-picker .helper-text,
    .report-location-status,
    .report-location-fallback-link {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      line-height: 1.5;
    }
    .report-location-picker .helper-text,
    .report-location-status { color: #71717a; font-weight: 400; }
    .report-location-status { margin-top: 10px; }
    .report-location-status.is-error { color: #B42318; }
    .report-location-barangay-status { padding: 9px 11px; border: 1px solid #dcfce7; border-radius: 6px; background: #f0fdf4; color: #166534; }
    .report-location-barangay-status[hidden] { display: none; }
    .report-location-barangay-status.is-error { border-color: #fecaca; background: #fef2f2; color: #b42318; }
    .report-location-address { margin-top: 8px; padding: 9px 11px; border: 1px solid #e4e4e7; border-radius: 6px; background: #fff; color: #52525b; font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
    .report-location-coordinates[hidden] { display: none; }
    .report-location-fallback-link { margin-top: 10px; padding: 0; border: 0; background: transparent; color: #18181b; font-weight: 500; text-decoration: underline; cursor: pointer; }
    .report-location-fallback { display: flex; flex-direction: column; gap: 6px; margin-top: 12px; }
    .report-location-fallback[hidden] { display: none; }
    .report-location-map-pin { position: absolute; z-index: 5; display: flex; align-items: flex-start; justify-content: center; width: 48px; height: 60px; transform: translate(-50%, -100%); filter: drop-shadow(0 3px 4px rgba(0,0,0,.4)); }
    .report-location-map-pin.is-draggable { pointer-events: auto; cursor: grab; touch-action: none; }
    .report-location-map-pin.is-draggable.is-dragging { cursor: grabbing; }
    .report-location-map-pin svg { display: block; width: 48px; height: 60px; overflow: visible; }
    .report-location-map-pin[hidden],
    .report-location-map-hint[hidden] { display: none; }
    .report-location-map-hint { position: absolute; z-index: 5; top: 14px; left: 14px; max-width: calc(100% - 140px); padding: 8px 12px; border: 1px solid rgba(20,32,26,.12); border-radius: 999px; background: rgba(255,255,255,.96); color: #14201A; font: 600 13px/1.3 'Inter', sans-serif; text-align: center; box-shadow: 0 2px 8px rgba(15,23,42,.12); pointer-events: none; }
    .report-location-mobile-center-pin,
    .report-location-mobile-back-step,
    .report-location-mobile-details-header,
    .report-location-mobile-details-intro,
    .report-location-mobile-summary,
    .report-location-mobile-confirm,
    .report-location-mobile-confirm-reason { display: none; }
    .report-location-expand-close { display: none; }
    .report-location-map.is-expanded ~ .report-location-expand-close { display: inline-flex; position: fixed; top: 14px; right: 14px; z-index: 10000; }
    body.report-location-screen { overflow: hidden; }
    body.report-location-screen .main-content {
      height: calc(100vh - var(--topbar-h, 64px));
      min-height: 0;
      overflow: hidden;
      padding: 0 !important;
    }
    @supports (height: 100dvh) {
      body.report-location-screen .main-content { height: calc(100dvh - var(--topbar-h, 64px)); }
    }
    body.report-location-screen .wizard-page-container {
      width: 100%;
      max-width: none;
      height: 100%;
      margin: 0;
      padding: 0;
    }
    body.report-location-screen #residentReportWizard {
      position: relative;
      display: flex;
      flex-direction: column;
      width: 100%;
      max-width: none;
      height: 100%;
      min-height: 0;
      margin: 0;
      padding: 0;
    }
    .stepper-container {
      display: flex;
      align-items: center;
      flex: 0 0 56px;
      box-sizing: border-box;
      width: 100%;
      height: 56px;
      max-height: 56px;
      margin: 0 0 20px;
      padding: 0 14px;
      border-bottom: 1px solid #E2E6E1;
      background: #fff;
    }
    body.report-location-screen .stepper-container { margin-bottom: 0; }
    .stepper-track {
      display: flex;
      flex-wrap: nowrap;
      align-items: center;
      flex: 1 1 auto;
      width: auto;
      max-width: none;
      margin: 0;
      justify-content: center;
      padding: 0;
      list-style: none;
    }
    .stepper-step {
      display: flex;
      flex-direction: row;
      align-items: center;
      flex: 0 0 auto;
      position: relative;
    }
    .stepper-step-control {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 0;
      border: 0;
      background: transparent;
      color: #5B675F;
      font: inherit;
      cursor: pointer;
    }
    .stepper-step-control:focus-visible {
      outline: 2px solid #166534;
      outline-offset: 3px;
      border-radius: 3px;
    }
    .step-circle {
      box-sizing: border-box;
      width: 24px;
      height: 24px;
      border: 1.5px solid #8A948D;
      background: #fff;
      color: #5B675F;
      font-size: 14px;
      box-shadow: none;
    }
    .stepper-step .step-label {
      color: #5B675F;
      font-size: 14px;
      font-weight: 400;
    }
    .stepper-step.completed .step-circle {
      border-color: #166534;
      background: #166534;
      color: #fff;
    }
    .stepper-step.completed .step-circle svg { width: 14px; height: 14px; }
    .stepper-step.completed .step-label { color: #5B675F; font-weight: 400; }
    .stepper-step.active .step-circle {
      border-color: #14201A;
      background: #14201A;
      color: #fff;
      box-shadow: none;
      transform: none;
    }
    .stepper-step.active .step-label {
      color: #14201A;
      font-weight: 700;
    }
    .stepper-connector {
      display: block;
      flex: 0 0 36px;
      width: 36px;
      height: 2px;
      margin: 0 12px;
      background: #CBD2CB;
    }
    .stepper-connector .connector-fill { background: #166534; }
    .stepper-current-label { display: none; }
    .stepper-mobile-view { display: none; }
    body.report-location-screen .wizard-card {
      flex: 1 1 auto;
      width: 100%;
      height: auto;
      min-height: 0;
      border: 0;
      border-radius: 0;
      box-shadow: none;
      overflow: hidden;
    }
    body.report-location-screen .wizard-card-body { height: 100%; padding: 0; }
    body.report-location-screen .report-location-step.active { display: block; padding: 0; animation: none; }
    body.report-location-screen .wizard-card-footer {
      position: absolute;
      bottom: 0;
      left: 0;
      z-index: 20;
      box-sizing: border-box;
      width: clamp(440px, 32vw, 460px);
      margin: 0;
      padding: 6px 16px;
      border-top: 1px solid #e6ece6;
      background: #fff;
    }
    body.report-location-screen .wizard-card-footer {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas: "left next" "reason reason";
      align-items: center;
      gap: 4px 12px;
    }
    body.report-location-screen #footer-left { grid-area: left; justify-self: start; }
    body.report-location-screen #btn-next { grid-area: next; }
    .report-location-continue-reason { grid-area: reason; color: #5B675F; font: 13px/1.35 'Inter', sans-serif; }
    .report-location-continue-reason[hidden] { display: none; }
    body.report-location-screen #btn-next:disabled { background: #A7B0AA; border-color: #A7B0AA; cursor: not-allowed; }
    body.report-location-screen .wizard-card-footer .btn-back,
    body.report-location-screen .wizard-card-footer .btn-continue {
      min-height: 44px;
      padding: 8px 16px;
      font-size: 13px;
      line-height: 1.2;
    }
    .report-location-step { height: 100%; min-height: 0; }
    .report-location-layout {
      display: grid;
      grid-template-columns: clamp(440px, 32vw, 460px) minmax(0, 1fr);
      width: 100%;
      height: 100%;
      min-height: 0;
    }
    .report-location-sidebar {
      display: flex;
      min-width: 0;
      min-height: 0;
      flex-direction: column;
      gap: clamp(14px, 1.8vh, 18px);
      overflow-x: hidden;
      overflow-y: auto;
      padding: clamp(16px, 2vh, 20px) 16px 72px;
      border-right: 1px solid #e6ece6;
      background: #fff;
      scrollbar-width: thin;
    }
    .report-location-sidebar .step-header { flex: 0 0 auto; margin: 0; }
    .report-location-sidebar .helper-text { color: #3F4A43; font-size: 13px; line-height: 1.45; }
    .report-location-sidebar .step-header h2 { margin: 0 0 4px; font-size: clamp(18px, 2.4vh, 21px); line-height: 1.25; }
    .report-location-sidebar .step-header p { margin: 0; color: #3F4A43; font-size: 13px; line-height: 1.45; }
    .report-location-search-section,
    .report-location-pin-card,
    .report-location-sidebar .form-section { flex: 0 0 auto; margin: 0; }
    .report-location-search-label,
    .report-location-sidebar .form-group > label,
    .report-location-barangay-label { font-size: 13px; font-weight: 600; }
    .report-location-search-controls { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: end; gap: 5px; }
    .report-location-search-section .report-location-place-search { width: 100%; min-width: 0; margin: 0; }
    .report-location-place-search .report-location-search-label { margin-bottom: 3px; }
    .report-location-search-controls .report-location-input-wrap { min-width: 0; }
    .report-location-search-controls input[type="search"] {
      width: 100%;
      min-width: 0;
      height: 46px;
      min-height: 46px;
      padding-left: 28px;
      border-radius: 7px;
      font-size: 14px;
    }
    .report-location-search-controls .dcvs-place-search-icon { left: 9px; font-size: 11px; }
    .report-location-gps {
      display: inline-flex;
      height: 46px;
      min-height: 46px;
      align-items: center;
      justify-content: center;
      gap: 5px;
      padding: 0 8px;
      border: 1px solid #d8e0db;
      border-radius: 7px;
      background: #fff;
      color: #111827;
      font: 600 13px 'Inter', sans-serif;
      white-space: nowrap;
      cursor: pointer;
    }
    .report-location-gps svg { width: 13px; height: 13px; }
    .report-location-gps:disabled { opacity: .6; cursor: wait; }
    .report-location-search-section > .helper-text { display: block; margin-top: 5px; color: #3F4A43; font-size: 13px; line-height: 1.45; }
    .report-location-search-section .dcvs-place-status { margin-top: 4px; font-size: 13px; }
    .report-location-pin-card {
      display: grid;
      gap: 10px;
      padding: 12px;
      border: 1px solid #d7ddda;
      border-radius: 8px;
      background: #f4f6f5;
    }
    .report-location-pin-card.is-pinned { border-color: #b7dfc2; background: #f2f8f3; }
    .report-location-pin-state {
      color: #334155;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .07em;
      text-transform: uppercase;
    }
    .report-location-pin-state[hidden] { display: none; }
    .report-location-pin-card .report-location-address {
      margin: 0;
      padding: 9px 11px;
      border: 1px solid #d7ddda;
      border-radius: 6px;
      background: transparent;
      color: #3F4A43;
      font-size: 13px;
      line-height: 1.45;
    }
    .report-location-pin-card.is-pinned .report-location-address { border-color: #b7dfc2; background: #fff; }
    .report-location-barangay-detected { display: flex; align-items: center; justify-content: space-between; gap: 10px; color: #334155; font-size: 13px; }
    .report-location-barangay-detected[hidden] { display: none; }
    .report-location-barangay-detected button { min-height: 44px; padding: 0; border: 0; background: transparent; color: #166534; font: 600 13px 'Inter', sans-serif; text-decoration: underline; cursor: pointer; }
    .report-location-barangay-group { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 6px; }
    .report-location-barangay-group[hidden] { display: none; }
    .report-location-barangay-group label { margin: 0; font-size: 13px; }
    .report-location-barangay-group .form-select {
      height: 46px;
      min-height: 46px;
      padding: 4px 9px;
      border-radius: 6px;
      font-size: 14px;
    }
    .report-location-sidebar .report-location-status {
      margin: 0;
      font-size: 13px;
      line-height: 1.45;
    }
    .report-location-sidebar .report-location-barangay-status { padding: 9px 10px; }
    .report-location-sidebar .report-location-accuracy {
      color: #166534;
      font-size: 13px;
      line-height: 1.45;
    }
    .report-location-sidebar .report-location-fallback-link { min-height: 44px; margin: 0; font-size: 13px; text-align: left; }
    .report-location-sidebar .report-location-fallback { margin-top: 0; font-size: 13px; }
    .report-location-sidebar .report-location-coordinates { display: none; }
    .report-location-sidebar .form-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .report-location-sidebar .form-row.single { grid-template-columns: minmax(0, 1fr); }
    .report-location-sidebar .form-group { min-width: 0; margin: 0; }
    .report-location-sidebar .form-input {
      height: 46px;
      min-height: 46px;
      padding: 8px 10px;
      border-radius: 7px;
      font-size: 14px;
    }
    .report-location-sidebar .form-group .helper-text { display: block; margin-top: 5px; color: #3F4A43; font-size: 13px; line-height: 1.45; }
    .report-location-map-panel { position: relative; min-width: 0; min-height: 0; overflow: hidden; }
    .report-location-map-panel .report-location-map { width: 100%; height: 100%; min-height: 0; border-radius: 0; }
    .report-location-map .dcvs-landmark-controls { display: none !important; }
    .report-location-map-panel .report-location-expand-close { border-radius: 7px; }
    .report-location-map-panel #expand-report-map {
      position: absolute;
      bottom: 10px;
      left: 10px;
      z-index: 6;
      margin: 0;
      padding: 6px 9px;
      border: 1px solid rgba(255,255,255,.8);
      border-radius: 6px;
      background: rgba(255,255,255,.94);
      color: #172033;
      font-size: 10px;
      box-shadow: 0 1px 5px rgba(15,23,42,.16);
    }
    .report-location-map-panel .report-location-map.is-expanded ~ #expand-report-map { display: none; }
    @media (min-width: 701px) and (min-height: 680px) {
      .report-location-sidebar > .form-section:last-child { margin-top: auto; }
    }
    .review-pin-map { width: 100%; height: 190px; margin-top: 14px; border-radius: 10px; overflow: hidden; background: #EEF2F6; }
    @media (max-width: 768px) {
      .report-location-map { height: 420px; }
      .report-location-picker-actions { align-items: flex-start; flex-direction: column; }
      .report-location-map .maplibregl-ctrl-group button { width: 44px; height: 44px; }
      .review-pin-map { height: 220px; }
    }
    @media (max-width: 700px) {
      .report-location-layout { grid-template-columns: minmax(0, 1fr); grid-template-rows: minmax(0, 55fr) minmax(0, 45fr); }
      .report-location-sidebar { border-right: 0; border-bottom: 1px solid #e6ece6; padding: 12px 12px 72px; }
      .report-location-sidebar .step-header h2 { font-size: 17px; }
      body.report-location-screen .wizard-card-footer { bottom: 0; width: 100%; }
    }
    @media (max-width: 640px) {
      .stepper-container { padding: 0 14px; }
      .stepper-track { display: none; }
      .stepper-mobile-view { display: grid; width: 100%; gap: 8px; }
      .stepper-mobile-label { color: #14201A; font: 600 13px/1.2 'Inter', sans-serif; }
      .stepper-mobile-progress { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 4px; }
      .report-location-layout {
        --mobile-location-sheet-height: clamp(270px, 43vh, 340px);
        position: relative;
        display: block;
        overflow: hidden;
      }
      .report-location-sidebar { display: contents; }
      .report-location-sidebar > .step-header,
      .report-location-sidebar > .form-section { display: none; }
      .report-location-map-panel {
        position: absolute;
        inset: 0 0 var(--mobile-location-sheet-height);
        z-index: 1;
      }
      .report-location-map-panel .report-location-map { height: 100%; }
      .report-location-map-panel #expand-report-map { display: none; }
      .report-location-map .dcvs-basemap-toggle { top: 118px; right: 12px; }
      .report-location-map .dcvs-map-attribution { top: 160px; right: 12px; }
      .report-location-map .dcvs-landmark-controls { top: 208px; right: 12px; }
      .report-location-mobile-back-step {
        position: absolute;
        top: 10px;
        left: 12px;
        z-index: 30;
        display: inline-flex;
        width: 44px;
        height: 44px;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 1px solid #e2e8e4;
        border-radius: 50%;
        background: #fff;
        color: #14201A;
        box-shadow: 0 2px 8px rgba(15,23,42,.12);
      }
      .report-location-mobile-back-step svg { width: 18px; height: 18px; }
      .report-location-search-section {
        position: absolute;
        top: 10px;
        right: 12px;
        left: 64px;
        z-index: 30;
      }
      .report-location-search-label,
        .report-location-search-section > .helper-text { display: none !important; }
      .report-location-search-controls {
        grid-template-columns: minmax(0, 1fr) 44px;
        align-items: center;
        gap: 7px;
      }
      .report-location-search-controls input[type="search"] {
        height: 44px;
        min-height: 44px;
        border-radius: 22px;
        background: #fff;
        box-shadow: 0 2px 8px rgba(15,23,42,.12);
      }
      .report-location-search-controls .dcvs-place-search-icon { left: 12px; }
      .report-location-gps {
        width: 44px;
        height: 44px;
        min-height: 44px;
        padding: 0;
        border-radius: 50%;
        background: #fff;
        font-size: 0;
        box-shadow: 0 2px 8px rgba(15,23,42,.12);
      }
      .report-location-gps svg { width: 18px; height: 18px; }
      .report-location-search-section .dcvs-place-status { margin-top: 2px; font-size: 12px; }
      .report-location-map-hint {
        top: 62px;
        left: 50%;
        max-width: calc(100% - 32px);
        padding: 7px 12px;
        white-space: nowrap;
        transform: translateX(-50%);
      }
      .report-location-mobile-center-pin {
        position: absolute;
        top: calc(50% - 8px);
        left: 50%;
        z-index: 5;
        display: block;
        width: 48px;
        height: 60px;
        transform: translate(-50%, -50%);
        pointer-events: none;
      }
      .report-location-mobile-center-pin::before {
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 44px;
        height: 44px;
        border: 1px solid rgba(220,38,38,.5);
        border-radius: 50%;
        background: rgba(220,38,38,.1);
        content: "";
        transform: translateX(-50%);
      }
      .report-location-mobile-center-pin svg {
        position: relative;
        display: block;
        width: 30px;
        height: 38px;
        margin: 0 auto;
        overflow: visible;
      }
      .report-location-mobile-center-pin path { fill: #dc2626; }
      .report-location-map .report-location-map-pin { display: none !important; }
      .report-location-pin-card {
        position: absolute;
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 20;
        display: grid;
        height: var(--mobile-location-sheet-height);
        overflow-y: auto;
        gap: 8px;
        box-sizing: border-box;
        padding: 14px 16px calc(14px + env(safe-area-inset-bottom));
        border: 0;
        border-radius: 16px 16px 0 0;
        background: #fff;
        box-shadow: 0 -4px 18px rgba(15,23,42,.14);
      }
      .report-location-pin-card.is-pinned { background: #fff; }
      .report-location-pin-state { color: #166534; font-size: 10px; }
      .report-location-pin-card .report-location-address {
        padding: 0;
        border: 0;
        background: transparent;
        color: #14201A;
        font-size: 13px;
        font-weight: 600;
        line-height: 1.4;
      }
      .report-location-pin-card.is-pinned .report-location-address { border: 0; background: transparent; }
      .report-location-barangay-detected {
        min-height: 40px;
        padding: 0 9px;
        border: 1px solid #dfe7df;
        border-radius: 8px;
        background: #fff;
        color: #334155;
      }
      .report-location-barangay-detected button { min-height: 40px; }
      .report-location-sidebar .report-location-accuracy { font-size: 12px; }
      .report-location-sidebar .report-location-status {
        margin: 0;
        padding: 8px 9px;
        border-radius: 7px;
        background: #e7f4eb;
        color: #166534;
        font-size: 12px;
        line-height: 1.35;
      }
      .report-location-sidebar .report-location-status.is-error {
        background: #fef2f2;
        color: #b42318;
      }
      .report-location-sidebar .report-location-barangay-status { padding: 8px 9px; }
      .report-location-sidebar .report-location-fallback { font-size: 12px; }
      .report-location-sidebar .report-location-fallback-link {
        min-height: 32px;
        margin: 0;
        color: #166534;
        font-size: 12px;
        text-align: center;
      }
      .report-location-mobile-confirm-reason {
        display: block;
        color: #5B675F;
        font: 12px/1.35 'Inter', sans-serif;
      }
      .report-location-mobile-confirm-reason[hidden] { display: none; }
      .report-location-mobile-confirm {
        display: block;
        width: 100%;
        min-height: 44px;
        padding: 8px 16px;
        border: 1px solid transparent;
        border-radius: 6px;
        background: #18181b;
        color: #fff;
        font: 500 14px 'Inter', sans-serif;
      }
      .report-location-mobile-confirm:disabled {
        border-color: transparent;
        background: #a1a1aa;
        cursor: not-allowed;
      }
      .report-location-layout.is-fallback .report-location-mobile-center-pin { display: none; }
      .report-location-layout.is-details-view .report-location-mobile-back-step,
      .report-location-layout.is-details-view .report-location-search-section,
      .report-location-layout.is-details-view .report-location-pin-card,
      .report-location-layout.is-details-view .report-location-map-panel { display: none; }
      .report-location-layout.is-details-view .report-location-sidebar {
        position: absolute;
        inset: 0;
        z-index: 10;
        display: flex;
        min-height: 0;
        flex-direction: column;
        gap: 0;
        overflow-x: hidden;
        overflow-y: auto;
        padding: 0 0 90px;
        background: #fff;
      }
      .report-location-layout.is-details-view .report-location-mobile-details-header {
        display: flex;
        min-height: 52px;
        align-items: center;
        gap: 10px;
        padding: 0 16px;
        border-bottom: 1px solid #e6ece6;
      }
      .report-location-layout.is-details-view .report-location-mobile-details-intro {
        display: block;
        margin: 14px 16px 0;
      }
      .report-location-mobile-details-intro h2 {
        margin: 0 0 4px;
        color: #14201A;
        font-size: 15px;
        line-height: 1.3;
      }
      .report-location-mobile-details-intro p {
        margin: 0;
        color: #3F4A43;
        font-size: 10px;
        line-height: 1.35;
      }
      .report-location-mobile-details-header button {
        display: inline-flex;
        width: 28px;
        height: 36px;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 0;
        background: transparent;
        color: #166534;
      }
      .report-location-mobile-details-header svg { width: 18px; height: 18px; }
      .report-location-mobile-details-title { color: #14201A; font-size: 13px; font-weight: 700; }
      .report-location-mobile-details-step { display: none; }
      .report-location-layout.is-details-view .report-location-mobile-summary {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        margin: 12px 16px 0;
        padding: 10px;
        border: 1px solid #d7e8dc;
        border-radius: 9px;
        background: #f2f8f3;
      }
      .report-location-mobile-summary-copy { min-width: 0; }
      .report-location-mobile-summary-state {
        display: block;
        margin-bottom: 3px;
        color: #166534;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
      }
      .report-location-mobile-summary-address { color: #14201A; font-size: 11px; line-height: 1.35; }
      .report-location-mobile-summary button {
        flex: 0 0 auto;
        min-height: 32px;
        padding: 0;
        border: 0;
        background: transparent;
        color: #166534;
        font: 600 11px 'Inter', sans-serif;
        text-decoration: underline;
      }
      .report-location-layout.is-details-view .report-location-sidebar > .form-section {
        display: block;
        flex: 0 0 auto;
        margin: 0;
        padding: 14px 16px 0;
      }
      .report-location-layout.is-details-view .report-location-sidebar > .form-section:last-child { padding-bottom: 12px; }
      .report-location-layout.is-details-view .report-location-sidebar .form-row { gap: 8px; }
      body.report-location-screen .report-location-layout.is-details-view .report-location-sidebar .form-row:not(.single) {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
      .report-location-layout.is-details-view .report-location-sidebar .form-group > label { font-size: 11px; }
      .report-location-layout.is-details-view .report-location-sidebar .form-input {
        height: 44px;
        min-height: 44px;
        padding: 7px 9px;
        font-size: 12px;
      }
      .report-location-layout.is-details-view .report-location-sidebar .form-group .helper-text { font-size: 10px; line-height: 1.35; }
      body.report-location-screen .wizard-card-footer { display: none; }
      body.report-location-screen .wizard-card-footer {
        bottom: 0;
        z-index: 25;
        width: 100%;
        padding: 8px 16px calc(8px + env(safe-area-inset-bottom));
        border-top: 1px solid #e6ece6;
        background: #fff;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-template-areas: "left next" "reason reason";
        gap: 8px;
      }
      body.report-location-screen .wizard-page-container.report-location-mobile-details .wizard-card-footer { display: grid; }
      body.report-location-screen .wizard-card-footer #footer-left { display: block; justify-self: stretch; }
      body.report-location-screen .wizard-card-footer #footer-left .btn-back,
      body.report-location-screen .wizard-card-footer .btn-continue {
        width: 100%;
        min-height: 40px;
        justify-content: center;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 14px;
      }
    }
    <?php endif; ?>

    /* Responsive */
    @media (max-width: 900px) {
      .wizard-page-container { padding: 24px 16px 60px; }
      .wizard-card-body { padding: 28px 24px 24px; }
      .wizard-card-footer { padding: 16px 24px; }
      .review-detail-grid { padding-left: 0; grid-template-columns: 120px 1fr; }
      .review-photos-row { padding-left: 0; }
    }

    @media (max-width: 768px) {
      .report-type-grid { grid-template-columns: 1fr; }
      .form-row { grid-template-columns: 1fr; }
      .wizard-card-body { padding: 24px 18px 20px; }
      .wizard-card-footer { padding: 14px 18px; }
      .wizard-page-title { font-size: 22px; }
      .btn-continue, .btn-back { padding: 10px 18px; font-size: 13px; }
    }

    @media (max-width: 480px) {
      .wizard-page-title { font-size: 20px; }
      .step-header h2 { font-size: 20px; }
      .photo-previews { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
      .review-detail-grid { grid-template-columns: 1fr; gap: 4px 0; }
      .review-label { font-weight: 700; color: #4B5563; }
      .success-card { padding: 36px 24px; }
    }

    @media (max-width: 640px) {
      .stepper-container {
        position: sticky;
        top: 50px;
        z-index: 90;
      }
      .wizard-page-container:not(.report-location-screen) { padding-bottom: 90px; }
      .wizard-card-footer {
        position: fixed;
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 90;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        width: 100%;
        margin: 0;
        padding: 8px 16px calc(8px + env(safe-area-inset-bottom));
        border-top: 1px solid #e4e4e7;
        background: #fff;
      }
      .wizard-card-footer.is-first-step { grid-template-columns: minmax(0, 1fr); }
      .wizard-card-footer.is-first-step #footer-left { display: none; }
      .wizard-card-footer #footer-left,
      .wizard-card-footer #footer-left .btn-back,
      .wizard-card-footer .btn-continue,
      .wizard-card-footer .btn-submit-report {
        width: 100%;
      }
      .wizard-card-footer #footer-left .btn-back,
      .wizard-card-footer .btn-continue,
      .wizard-card-footer .btn-submit-report {
        min-height: 40px;
        justify-content: center;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 14px;
      }
    }

    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
  <link rel="stylesheet" href="assets/css/sb2.css?v=<?php echo (int)filemtime(__DIR__ . '/assets/css/sb2.css'); ?>" />
</head>
<body class="resident-portal sb2-active">

<?php include 'includes/resident-sidebar.php'; ?>

<main class="main-content" id="mainContent">
  <div class="wizard-page-container" id="residentReportWizard" data-draft-form="resident-report-wizard">
    <input type="hidden" id="residentReportDraftStep" data-draft-state value="1">
    <input type="hidden" id="residentReportDraftHighestStep" data-draft-state value="1">

    <nav class="stepper-container" aria-label="Progress">
      <ol class="stepper-track">
        <li class="stepper-step active clickable" data-step="1">
          <button type="button" class="stepper-step-control" onclick="goToStep(1)">
            <span class="step-circle">1</span>
            <span class="step-label">What?</span>
            <span class="step-completed-label">(completed)</span>
          </button>
          <span class="stepper-connector" id="connector-1-2" aria-hidden="true"><span class="connector-fill"></span></span>
        </li>
        <li class="stepper-step clickable" data-step="2">
          <button type="button" class="stepper-step-control" onclick="goToStep(2)">
            <span class="step-circle">2</span>
            <span class="step-label">Where?</span>
            <span class="step-completed-label">(completed)</span>
          </button>
          <span class="stepper-connector" id="connector-2-3" aria-hidden="true"><span class="connector-fill"></span></span>
        </li>
        <li class="stepper-step clickable" data-step="3">
          <button type="button" class="stepper-step-control" onclick="goToStep(3)">
            <span class="step-circle">3</span>
            <span class="step-label">Details</span>
            <span class="step-completed-label">(completed)</span>
          </button>
          <span class="stepper-connector" id="connector-3-4" aria-hidden="true"><span class="connector-fill"></span></span>
        </li>
        <li class="stepper-step clickable" data-step="4">
          <button type="button" class="stepper-step-control" onclick="goToStep(4)">
            <span class="step-circle">4</span>
            <span class="step-label">Photos</span>
            <span class="step-completed-label">(completed)</span>
          </button>
          <span class="stepper-connector" id="connector-4-5" aria-hidden="true"><span class="connector-fill"></span></span>
        </li>
        <li class="stepper-step clickable" data-step="5">
          <button type="button" class="stepper-step-control" onclick="goToStep(5)">
            <span class="step-circle">5</span>
            <span class="step-label">Review</span>
            <span class="step-completed-label">(completed)</span>
          </button>
        </li>
      </ol>
      <span class="stepper-current-label" id="stepper-current-label">Step 1 of 5</span>
      <div class="stepper-mobile-view">
        <span class="stepper-mobile-label" id="stepper-mobile-label">Step 1 of 5 · What?</span>
        <div class="stepper-mobile-progress" aria-hidden="true">
          <span class="stepper-mobile-segment"></span>
          <span class="stepper-mobile-segment"></span>
          <span class="stepper-mobile-segment"></span>
          <span class="stepper-mobile-segment"></span>
          <span class="stepper-mobile-segment"></span>
        </div>
      </div>
    </nav>

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
              <div class="report-type-card" id="type-stray" tabindex="0" role="radio" aria-checked="false" aria-label="Stray Animal">
                <input type="radio" name="reportType" value="Stray Animal" tabindex="-1" aria-hidden="true">
                <div class="type-icon-box stray">🐕</div>
                <div class="type-info">
                  <h4>Stray Animal</h4>
                  <p>An unattended or wandering animal without a visible owner</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </div>
              <div class="report-type-card" id="type-injured" tabindex="0" role="radio" aria-checked="false" aria-label="Injured Animal">
                <input type="radio" name="reportType" value="Injured Animal" tabindex="-1" aria-hidden="true">
                <div class="type-icon-box injured">🩹</div>
                <div class="type-info">
                  <h4>Injured Animal</h4>
                  <p>An animal that appears hurt, limping, or in visible distress</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </div>
              <div class="report-type-card" id="type-rabies" tabindex="0" role="radio" aria-checked="false" aria-label="Rabies concern">
                <input type="radio" name="reportType" value="Rabies Concern" tabindex="-1" aria-hidden="true">
                <div class="type-icon-box rabies">⚠️</div>
                <div class="type-info">
                  <h4>Rabies Concern</h4>
                  <p>Suspected rabid behavior such as aggression, foaming, or erratic movement</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </div>
              <div class="report-type-card" id="type-dead" tabindex="0" role="radio" aria-checked="false" aria-label="Dead Animal">
                <input type="radio" name="reportType" value="Dead Animal" tabindex="-1" aria-hidden="true">
                <div class="type-icon-box dead">🕊️</div>
                <div class="type-info">
                  <h4>Dead Animal</h4>
                  <p>A deceased animal found in a public area requiring removal</p>
                </div>
                <div class="type-check-indicator">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">
              <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              How urgent is this?
            </div>
            <div class="form-group">
              <select class="form-select" id="urgency-level" aria-label="Urgency level">
                <option value="" selected>Select urgency level</option>
                <option value="low">Low — Not immediately dangerous</option>
                <option value="medium">Medium — Needs attention soon</option>
                <option value="high">High — Requires immediate response</option>
              </select>
              <span class="helper-text">Select how urgent this report is. Choose “Select urgency level” again to clear it.</span>
            </div>
          </div>
        </div>

        <!-- STEP 2: WHERE? -->
        <div class="step-content<?php echo $reportPinEnabled ? ' report-location-step' : ''; ?>" id="step-2">
          <?php if ($reportPinEnabled): ?>
          <div class="report-location-layout" id="report-location-layout">
            <button type="button" class="report-location-mobile-back-step" id="report-location-mobile-back-step" aria-label="Back to report type" onclick="prevStep()">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <aside class="report-location-sidebar" aria-label="Incident location details">
              <div class="report-location-mobile-details-header">
                <button type="button" id="report-location-mobile-back" aria-label="Back to pin location">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <div>
                  <div class="report-location-mobile-details-title">Location details</div>
                  <div class="report-location-mobile-details-step">Step 2 of 5 · Where? (2 of 2)</div>
                </div>
              </div>
              <div class="report-location-mobile-details-intro">
                <h2>Where and when did it happen?</h2>
                <p>Provide the location and date/time of the incident so responders can locate it quickly.</p>
              </div>
              <div class="report-location-mobile-summary" id="report-location-mobile-summary" aria-live="polite">
                <div class="report-location-mobile-summary-copy">
                  <span class="report-location-mobile-summary-state" id="report-location-mobile-summary-state">Pin set</span>
                  <div class="report-location-mobile-summary-address" id="report-location-mobile-summary-address"></div>
                </div>
                <button type="button" id="report-location-mobile-edit-pin">Edit pin</button>
              </div>
              <div class="step-header">
                <div class="step-tag">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  Step 2 of 5
                </div>
                <h2>Where and when did it happen?</h2>
                <p>Provide the location and date/time of the incident so responders can locate it quickly.</p>
              </div>

              <div class="report-location-search-section">
                <div class="dcvs-place-search report-location-place-search"
                     data-map-target="reportLocationMap"
                     data-selection-mode="pin"
                     data-geocode-endpoint="api/shared/location-geocode.php"
                     data-portal="resident"
                     data-status-target="#report-location-search-status">
                  <label class="report-location-search-label" for="report-location-search">Search for a place or street</label>
                  <div class="report-location-search-controls">
                    <div class="report-location-input-wrap">
                      <i class="fas fa-search dcvs-place-search-icon" aria-hidden="true"></i>
                      <input type="search" id="report-location-search" autocomplete="off"
                             placeholder="Search for a place or street"
                             role="combobox" aria-autocomplete="list"
                             aria-controls="report-location-search-results" aria-expanded="false">
                    </div>
                    <button type="button" class="report-location-gps" id="use-report-gps">
                      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                      Use my location
                    </button>
                  </div>
                  <div class="dcvs-place-results" id="report-location-search-results"
                       role="listbox" aria-label="Place search results" hidden></div>
                </div>
                <div class="dcvs-place-status" id="report-location-search-status" role="status" aria-live="polite"></div>
                <div class="helper-text">Drag the red pin or click the map to place it. Pan the map to inspect nearby areas.</div>
              </div>

              <section class="report-location-pin-card" aria-label="Selected pin details">
                <div class="report-location-pin-state" id="report-location-pin-state" hidden>Pin set</div>
                <div class="report-location-address" id="report-location-address" aria-live="polite">Detected Address: Move the pin or choose a place to see its address.</div>
                <div class="report-location-barangay-detected" id="report-location-barangay-detected" hidden>
                  <span>Detected barangay: <strong id="report-location-barangay-name"></strong></span>
                  <button type="button" id="report-location-barangay-change">Change</button>
                </div>
                <div class="form-group report-location-barangay-group" id="report-location-barangay-group" hidden>
                  <label class="report-location-barangay-label" for="location-barangay">Incident Barangay <span class="required-star">*</span></label>
                  <select class="form-select" id="location-barangay">
                    <option value="" disabled selected>Select barangay</option>
                    <?php foreach ($officialBarangays as $barangay): ?>
                      <option value="<?php echo htmlspecialchars($barangay['psgc_code'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($barangay['barangay_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="report-location-accuracy" id="report-location-accuracy" hidden></div>
                <div class="report-location-status" id="report-location-status" role="status">Drag the red pin or click the map to place it.</div>
                <div class="report-location-status report-location-barangay-status" id="report-location-barangay-status" role="status" hidden></div>
                <div class="report-location-coordinates" id="report-location-coordinates" aria-hidden="true" hidden></div>
                <div class="report-location-mobile-confirm-reason" id="report-location-mobile-confirm-reason" role="status" aria-live="polite" hidden></div>
                <button type="button" class="report-location-mobile-confirm" id="report-location-mobile-confirm" onclick="confirmReportMobileLocation()">Confirm location</button>
                <button type="button" class="report-location-fallback-link" id="report-location-fallback-toggle">I can't pin the location</button>
                <div class="report-location-fallback" id="report-location-fallback" hidden>
                  <span class="helper-text">If you can't place a pin, describe where it happened below and add a photo or video in the next step.</span>
                </div>
              </section>

              <div class="form-section">
                <div class="form-row single">
                  <div class="form-group">
                    <label for="location-street">Street / Landmark / Specific Location <span class="required-star">*</span></label>
                    <input type="text" class="form-input" id="location-street" placeholder="Street, landmark, entrance, or directions">
                    <span class="helper-text">Add directions that can help responders find the exact spot.</span>
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
                  </div>
                </div>
              </div>
            </aside>

            <section class="report-location-map-panel" aria-label="Incident location map">
              <div class="report-location-map" id="reportLocationMap" aria-label="Incident location map" data-resident-barangay-code="<?php echo htmlspecialchars($residentBarangayCode, ENT_QUOTES, 'UTF-8'); ?>" data-satellite-style='<?php echo htmlspecialchars(json_encode($reportSatelliteStyle, JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8"); ?>' data-map-basemap-toggle="true" data-max-map-zoom="18" data-initial-basemap="street" data-use-mapcn-satellite-style="true" data-preserve-map-view="true" data-city-boundary="true" data-map-landmarks-session="resident"<?php if ($mapTilerApiKey !== ''): ?> data-maptiler-key="<?php echo htmlspecialchars($mapTilerApiKey, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading the map...</div>
                <div class="geo-basemap-status" data-basemap-status role="status" aria-live="polite"></div>
                <div class="report-location-map-hint">Move the map to place the pin</div>
                <div class="report-location-mobile-center-pin" id="report-location-mobile-center-pin" aria-hidden="true">
                  <svg viewBox="0 0 24 30" focusable="false"><path d="M12 29S2 19.8 2 11a10 10 0 1 1 20 0c0 8.8-10 18-10 18Z" fill="#dc2626" stroke="#fff" stroke-width="1.4"/><circle cx="12" cy="11" r="3.2" fill="#fff"/></svg>
                </div>
              </div>
              <button type="button" class="btn-back report-location-expand-close" id="close-expanded-report-map">Close expanded map</button>
              <button type="button" class="report-location-fallback-link" id="expand-report-map" aria-pressed="false">Expand map</button>
            </section>
          </div>
          <input type="hidden" id="report-latitude" data-draft-state value="">
          <input type="hidden" id="report-longitude" data-draft-state value="">
          <input type="hidden" id="report-pin-source" data-draft-state value="resident">
          <input type="hidden" id="report-location-fallback-state" data-draft-state value="0">
          <input type="hidden" id="report-location-street-touched" data-draft-state value="0">
          <?php else: ?>
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
                <span class="helper-text">Choose the barangay where the incident happened.</span>
              </div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-row single">
              <div class="form-group">
                <label for="location-street">Street / Landmark / Specific Location <span class="required-star">*</span></label>
                <input type="text" class="form-input" id="location-street" placeholder="e.g., Phase 3, near the covered court; entrance beside the blue gate">
                <span class="helper-text">Add directions responders can follow, such as the phase, sitio or purok, street, nearby landmark, entrance, or which side of the road.</span>
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
          <?php endif; ?>
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
            <p id="upload-requirement-note">Upload at least one photo or video of the animal or location to help responders assess the situation. This is required to submit your report.</p>
          </div>

          <div class="form-section">
            <div class="upload-dropzone" id="upload-dropzone" role="button" tabindex="0" aria-label="Upload photos or videos">
              <input type="file" id="photo-input" multiple required accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/ogg,video/x-m4v,video/quicktime" onchange="handleFileUpload(this.files)">
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
        <div class="report-location-continue-reason" id="report-location-continue-reason" role="status" aria-live="polite" hidden></div>
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
    <p>Your report has been successfully submitted. If a potential match was found, authorized personnel will review it before it appears in the Staff and Barangay report lists.</p>
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

<div class="duplicate-review-overlay" id="duplicate-review-overlay" hidden>
  <section class="duplicate-review-dialog" role="dialog" aria-modal="true" aria-labelledby="duplicate-review-title">
    <h2 id="duplicate-review-title">Related reports found</h2>
    <p>Select the closest existing report to help authorized personnel review your submission. Your report will remain separate unless the review team links it.</p>
    <div class="duplicate-candidate-list" id="duplicate-candidate-list"></div>
    <div class="duplicate-review-actions">
      <button type="button" class="btn-continue" id="duplicate-same">Submit for review</button>
    </div>
    <button type="button" class="duplicate-review-cancel" id="duplicate-cancel">Go back and review</button>
  </section>
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
  var reportReverseLookupRequestId = 0;
  var reportReverseLookupPending = false;
  var reportAutoLocationDetail = '';
  var reportAutoLocationCoordinates = null;
  var reportStreetInputTouched = false;
  var reportPinLocationLabel = '';
  var reportPinBarangayCode = '';
  var reportFallbackMode = false;
  var reportPendingBarangayCode = '';
  var reportMapPinDrag = null;
  var reportMobilePinMoveTimer = null;
  var reportPendingDraftPin = null;
  var reportReviewMapInstance = null;
  var reportMapPinElement = null;
  var reportMapCenterPinElement = null;
  var reportReviewPinElement = null;
  var pendingDuplicateCandidates = [];
  var duplicateChoiceResolver = null;
  var reportCsrfToken = <?php echo json_encode(csrfToken()); ?>;

  (function addDuplicateReviewStyles() {
    var style = document.createElement('style');
    style.textContent = '.duplicate-review-overlay{position:fixed;inset:0;z-index:10020;background:rgba(15,23,42,.58);display:flex;align-items:center;justify-content:center;padding:20px}.duplicate-review-overlay[hidden]{display:none}.duplicate-review-dialog{width:min(620px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 20px 60px rgba(15,23,42,.28)}.duplicate-review-dialog h2{margin:0 0 8px;color:#0f172a;font-size:21px}.duplicate-review-dialog>p{margin:0 0 18px;color:#64748b;font-size:14px}.duplicate-candidate-list{display:grid;gap:12px}.duplicate-candidate{display:grid;grid-template-columns:22px 84px 1fr;gap:12px;align-items:center;border:1px solid #e2e8f0;border-radius:12px;padding:10px}.duplicate-candidate input{margin:0}.duplicate-candidate img,.duplicate-photo-placeholder{width:84px;height:72px;object-fit:cover;border-radius:8px;background:#f1f5f9}.duplicate-candidate-meta{display:grid;gap:4px;color:#334155;font-size:13px}.duplicate-candidate-meta strong{color:#0f172a}.duplicate-review-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:20px}.duplicate-review-cancel{display:block;margin:14px auto 0;border:0;background:none;color:#64748b;text-decoration:underline;cursor:pointer;font:inherit}@media(max-width:520px){.duplicate-review-actions{flex-direction:column-reverse}.duplicate-review-actions button{width:100%}}';
    document.head.appendChild(style);
  })();

  function resolveDuplicateChoice(choice) {
    var overlay = document.getElementById('duplicate-review-overlay');
    overlay.hidden = true;
    if (duplicateChoiceResolver) {
      var resolve = duplicateChoiceResolver;
      duplicateChoiceResolver = null;
      resolve(choice);
    }
  }

  function showDuplicateReview(candidates) {
    pendingDuplicateCandidates = candidates;
    var list = document.getElementById('duplicate-candidate-list');
    list.textContent = '';
    candidates.forEach(function(candidate) {
      var card = document.createElement('article');
      card.className = 'duplicate-candidate';
      var parentChoice = document.createElement('input');
      parentChoice.type = 'radio';
      parentChoice.name = 'duplicate-parent-choice';
      parentChoice.value = String(candidate.report_id);
      parentChoice.checked = candidates[0].report_id === candidate.report_id;
      parentChoice.setAttribute('aria-label', 'Use report from ' + new Date(candidate.created_at.replace(' ', 'T')).toLocaleString() + ' as the suggested parent');
      card.appendChild(parentChoice);
      if (candidate.photo_attachment_id) {
        var image = document.createElement('img');
        image.alt = 'Photo from a similar report';
        image.src = 'api/shared/report-evidence-file.php?id='
          + encodeURIComponent(candidate.photo_attachment_id) + '&sess=PHPSESSID';
        card.appendChild(image);
      } else {
        var placeholder = document.createElement('div');
        placeholder.className = 'duplicate-photo-placeholder';
        placeholder.setAttribute('aria-label', 'No report photo');
        card.appendChild(placeholder);
      }
      var details = document.createElement('div');
      details.className = 'duplicate-candidate-meta';
      var type = document.createElement('strong');
      type.textContent = candidate.report_type.charAt(0).toUpperCase() + candidate.report_type.slice(1);
      var date = document.createElement('span');
      date.textContent = 'Reported ' + new Date(candidate.created_at.replace(' ', 'T')).toLocaleString();
      var status = document.createElement('span');
      status.textContent = 'Status: ' + candidate.status;
      var distance = document.createElement('span');
      distance.textContent = 'About ' + candidate.distance_meters + ' m away';
      details.append(type, date, status, distance);
      card.appendChild(details);
      list.appendChild(card);
    });
    document.getElementById('duplicate-review-overlay').hidden = false;
    return new Promise(function(resolve) { duplicateChoiceResolver = resolve; });
  }

  document.getElementById('duplicate-same').addEventListener('click', function() { resolveDuplicateChoice('same'); });
  document.getElementById('duplicate-cancel').addEventListener('click', function() { resolveDuplicateChoice('cancel'); });

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

  function reportPointOnRingSegment(longitude, latitude, start, end) {
    var epsilon = 1e-10;
    var dx = end[0] - start[0];
    var dy = end[1] - start[1];
    var px = longitude - start[0];
    var py = latitude - start[1];
    if (Math.abs(dx) <= epsilon && Math.abs(dy) <= epsilon) {
      return px * px + py * py <= epsilon * epsilon;
    }
    if (Math.abs(px * dy - py * dx) > epsilon) return false;
    var dot = px * dx + py * dy;
    return dot >= -epsilon && dot <= dx * dx + dy * dy + epsilon;
  }

  function reportPointInRing(longitude, latitude, ring) {
    if (!Array.isArray(ring) || ring.length < 4) return false;
    var inside = false;
    for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      var current = ring[i];
      var previous = ring[j];
      if (reportPointOnRingSegment(longitude, latitude, previous, current)) return true;
      var crossesLatitude = (current[1] > latitude) !== (previous[1] > latitude);
      if (crossesLatitude) {
        var intersection = (previous[0] - current[0]) * (latitude - current[1])
          / (previous[1] - current[1]) + current[0];
        if (longitude < intersection) inside = !inside;
      }
    }
    return inside;
  }

  function reportPointInPolygon(longitude, latitude, geometry) {
    var polygons = geometry.type === 'Polygon' ? [geometry.coordinates]
      : geometry.type === 'MultiPolygon' ? geometry.coordinates : [];
    return polygons.some(function(polygon) {
      if (!polygon.length || !reportPointInRing(longitude, latitude, polygon[0])) return false;
      for (var i = 1; i < polygon.length; i++) {
        if (reportPointInRing(longitude, latitude, polygon[i])) return false;
      }
      return true;
    });
  }

  function reportFeatureAtCoordinates(longitude, latitude) {
    if (!reportMapFeatures || !Array.isArray(reportMapFeatures.features)) return null;
    var matches = reportMapFeatures.features.filter(function(feature) {
      return feature.geometry && reportPointInPolygon(longitude, latitude, feature.geometry);
    });
    if (matches.length !== 1) return null;
    var name = String(matches[0].properties.name || '').trim();
    return name ? { name: name, code: reportBarangayCodeByName()[name.toLocaleLowerCase()] || '' } : null;
  }

  function isMobileReportLocation() {
    return window.matchMedia
      ? window.matchMedia('(max-width: 640px)').matches
      : window.innerWidth <= 640;
  }

  function updateReportMobileLocationUI() {
    var layout = document.getElementById('report-location-layout');
    var confirmButton = document.getElementById('report-location-mobile-confirm');
    var confirmReason = document.getElementById('report-location-mobile-confirm-reason');
    var summaryState = document.getElementById('report-location-mobile-summary-state');
    var summaryAddress = document.getElementById('report-location-mobile-summary-address');
    var barangaySelect = document.getElementById('location-barangay');
    if (!layout || !confirmButton || !barangaySelect) return;

    layout.classList.toggle('is-fallback', reportFallbackMode);
    var mapHint = document.querySelector('.report-location-map-hint');
    if (mapHint && !reportFallbackMode) {
      mapHint.textContent = 'Move the map to place the pin';
    }
    var canConfirm = reportFallbackMode
      ? !!barangaySelect.value
      : !!reportPinCoordinates && !!reportPinBarangayCode;
    confirmButton.disabled = !canConfirm;
    if (confirmReason) {
      confirmReason.textContent = canConfirm
        ? ''
        : (reportFallbackMode
          ? 'Select the incident barangay to continue.'
          : 'Move the map to place the pin inside a verified barangay.');
      confirmReason.hidden = canConfirm;
    }

    if (summaryState) {
      var selectedOption = barangaySelect.selectedOptions[0];
      var barangayName = selectedOption && selectedOption.value ? selectedOption.textContent : '';
      summaryState.textContent = reportFallbackMode
        ? (barangayName ? 'Location by barangay · ' + barangayName : 'No pin set')
        : (reportPinCoordinates && reportPinBarangayCode && barangayName
          ? 'Pin set · ' + barangayName : 'Pin location not confirmed');
    }
    if (summaryAddress) {
      var address = document.getElementById('report-location-address');
      var addressText = address ? address.textContent.replace(/^Detected Address:\s*/, '').trim() : '';
      summaryAddress.textContent = addressText || (reportFallbackMode
        ? 'Add a street, landmark, or directions for responders.'
        : 'Move the map to see the detected address.');
    }
  }

  function setReportMobileLocationDetailsView(showDetails) {
    var layout = document.getElementById('report-location-layout');
    var wizard = document.getElementById('residentReportWizard');
    if (!layout || !wizard) return;
    layout.classList.toggle('is-details-view', !!showDetails);
    wizard.classList.toggle('report-location-mobile-details', !!showDetails);
    var footerBackButton = document.querySelector('#footer-left .btn-back');
    if (footerBackButton) {
      footerBackButton.onclick = function() {
        if (isMobileReportLocation() && wizard.classList.contains('report-location-mobile-details')) {
          setReportMobileLocationDetailsView(false);
          return;
        }
        prevStep();
      };
    }
    if (reportMapInstance && !showDetails) {
      window.requestAnimationFrame(function() {
        reportMapInstance.resize();
      });
    }
  }

  function confirmReportMobileLocation() {
    if (!isMobileReportLocation()) return;
    updateReportMobileLocationUI();
    var confirmButton = document.getElementById('report-location-mobile-confirm');
    if (!confirmButton || confirmButton.disabled) return;
    setReportMobileLocationDetailsView(true);
  }

  function updateReportPinMarker() {
    if (!reportMapInstance || !reportMapPinElement) return;
    var center = reportMapInstance.getCenter();
    var coordinates = reportMapPinDrag
      ? reportMapPinDrag.coordinates
      : (reportPinCoordinates || [center.lng, center.lat]);
    var point = reportMapInstance.project(coordinates);
    reportMapPinElement.style.left = point.x + 'px';
    reportMapPinElement.style.top = point.y + 'px';
    reportMapPinElement.hidden = reportFallbackMode;
  }

  function updateReportLocationBarangayUI() {
    var card = document.querySelector('.report-location-pin-card');
    var detected = document.getElementById('report-location-barangay-detected');
    var detectedName = document.getElementById('report-location-barangay-name');
    var group = document.getElementById('report-location-barangay-group');
    var mapHint = document.querySelector('.report-location-map-hint');
    var select = document.getElementById('location-barangay');
    var hasDetectedBarangay = !!(reportPinCoordinates && reportPinBarangayCode && select);
    if (card) card.classList.toggle('is-pinned', !!reportPinCoordinates && !reportFallbackMode);
    if (detected) detected.hidden = reportFallbackMode || !hasDetectedBarangay;
    if (group) group.hidden = !reportFallbackMode;
    if (mapHint) mapHint.hidden = reportFallbackMode;
    if (reportMapPinElement) reportMapPinElement.hidden = reportFallbackMode;
    if (detectedName && hasDetectedBarangay) {
      var selectedOption = select.querySelector('option:checked');
      detectedName.textContent = selectedOption ? selectedOption.textContent : '';
    }
    updateLocationContinueState();
  }

  function moveReportMapTo(options) {
    if (!reportMapInstance) return;
    var currentCenter = reportMapInstance.getCenter();
    var targetCenter = options.center;
    if (targetCenter && Math.abs(currentCenter.lng - Number(targetCenter[0])) < 0.0000001
        && Math.abs(currentCenter.lat - Number(targetCenter[1])) < 0.0000001
        && Math.abs(reportMapInstance.getZoom() - Number(options.zoom || reportMapInstance.getZoom())) < 0.01) return;
    reportMapInstance.flyTo(options);
  }

  function focusReportBarangay(code) {
    if (!reportMapInstance) {
      reportPendingBarangayCode = code;
      return;
    }
    var feature = reportBoundaryFeatureByCode(code);
    var bounds = feature ? reportFeatureBounds(feature) : null;
    if (!bounds) return;
    reportPendingBarangayCode = '';
    reportMapInstance.fitBounds(bounds, { padding: 48, maxZoom: 15.5, duration: 500 });
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

  function setReportDraftField(id, value) {
    var field = document.getElementById(id);
    if (!field) return;
    field.value = String(value);
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function setReportBarangayStatus(message, isError) {
    var status = document.getElementById('report-location-barangay-status');
    if (!status) return;
    status.textContent = message;
    status.hidden = !message;
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

  function reverseGeocodeReportPin(longitude, latitude) {
    var requestId = ++reportReverseLookupRequestId;
    var locationInput = document.getElementById('location-street');
    var previousAutoDetail = reportAutoLocationDetail;
    var previousAutoCoordinates = reportAutoLocationCoordinates;
    var previousValueWasAuto = locationInput && locationInput.value.trim() === previousAutoDetail;
    var previousAutoLocationMoved = previousValueWasAuto && previousAutoCoordinates
      && (Math.abs(previousAutoCoordinates[0] - longitude) > 0.00001
        || Math.abs(previousAutoCoordinates[1] - latitude) > 0.00001);
    if (previousAutoLocationMoved && locationInput
        && locationInput.value.trim() === previousAutoDetail) {
      locationInput.value = '';
      reportAutoLocationDetail = '';
      reportAutoLocationCoordinates = null;
      reportStreetInputTouched = false;
      setReportDraftField('report-location-street-touched', '0');
      locationInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    reportReverseLookupPending = true;
    var addressStatus = document.getElementById('report-location-address');
    if (!addressStatus) return;
    addressStatus.classList.remove('is-error');
    addressStatus.textContent = 'Detected Address: checking this location...';

    var url = new URL('api/shared/location-geocode.php', window.location.href);
    url.searchParams.set('portal', 'resident');
    url.searchParams.set('latitude', String(latitude));
    url.searchParams.set('longitude', String(longitude));
    fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function(response) {
        return response.json().then(function(payload) {
          if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'The address could not be looked up.');
          }
          return payload;
        });
      })
      .then(function(payload) {
        if (requestId !== reportReverseLookupRequestId) return;
        reportReverseLookupPending = false;
        var result = Array.isArray(payload.results) ? payload.results[0] : null;
        if (!result || !result.label) {
          addressStatus.textContent = "Detected Address: We couldn't find a nearby address. Add a street or landmark below.";
          if (previousAutoLocationMoved && locationInput
              && locationInput.value.trim() === previousAutoDetail) {
            locationInput.value = '';
            reportAutoLocationDetail = '';
            reportAutoLocationCoordinates = null;
            reportStreetInputTouched = false;
          }
          updateLocationContinueState();
          return;
        }
        addressStatus.textContent = 'Detected Address: ' + result.label;
        if (!reportPinLocationLabel) {
          reportPinLocationLabel = result.label;
          setReportPinStatus('Your pin is currently placed near ' + result.label + '.', false);
        }
        if (locationInput) {
          var currentValue = locationInput.value.trim();
          if (!reportStreetInputTouched && (!currentValue
              || (currentValue === previousAutoDetail
                && (!previousAutoCoordinates || previousAutoLocationMoved)))) {
            locationInput.value = result.label;
            reportAutoLocationDetail = result.label;
            reportAutoLocationCoordinates = [longitude, latitude];
            reportStreetInputTouched = false;
            setReportDraftField('report-location-street-touched', '0');
            locationInput.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
        updateLocationContinueState();
      })
      .catch(function(error) {
        if (requestId !== reportReverseLookupRequestId) return;
        reportReverseLookupPending = false;
        console.error('Incident pin reverse geocoding failed:', error);
        addressStatus.textContent = "Detected Address: We couldn't look up this address right now. You can add the location details below.";
        addressStatus.classList.add('is-error');
        if (previousAutoLocationMoved && locationInput
            && locationInput.value.trim() === previousAutoDetail) {
          locationInput.value = '';
          reportAutoLocationDetail = '';
          reportAutoLocationCoordinates = null;
          reportStreetInputTouched = false;
        }
        updateLocationContinueState();
      });
  }

  function setReportPin(longitude, latitude, source, detected, finalize, accuracyMeters, locationLabel) {
    if (reportMobilePinMoveTimer) {
      window.clearTimeout(reportMobilePinMoveTimer);
      reportMobilePinMoveTimer = null;
    }
    reportPinCoordinates = [Number(longitude), Number(latitude)];
    reportPinAccuracyMeters = Number.isFinite(accuracyMeters) && accuracyMeters > 0 ? accuracyMeters : null;
    reportPinLocationLabel = typeof locationLabel === 'string' ? locationLabel.trim() : '';
    reportReverseLookupRequestId++;
    reportReverseLookupPending = false;
    var pinState = document.getElementById('report-location-pin-state');
    if (pinState) pinState.hidden = false;
    setReportDraftField('report-latitude', reportPinCoordinates[1]);
    setReportDraftField('report-longitude', reportPinCoordinates[0]);
    setReportDraftField('report-pin-source', source || 'resident');
    document.getElementById('report-location-coordinates').textContent =
      'Latitude: ' + String(reportPinCoordinates[1]) + ' | Longitude: ' + String(reportPinCoordinates[0]);
    var accuracyStatus = document.getElementById('report-location-accuracy');
    if (reportPinAccuracyMeters && accuracyStatus) {
      accuracyStatus.hidden = false;
      accuracyStatus.classList.remove('is-error');
      accuracyStatus.textContent = 'GPS accuracy: ±' + Math.round(reportPinAccuracyMeters) +
        ' m. Drag the pin or click the map to correct its position if needed.';
    } else if (accuracyStatus) {
      accuracyStatus.hidden = true;
      accuracyStatus.classList.remove('is-error');
      accuracyStatus.textContent = '';
    }
    reportFallbackMode = false;
    setReportDraftField('report-location-fallback-state', '0');
    document.getElementById('report-location-fallback').hidden = true;
    document.getElementById('report-location-fallback-toggle').textContent = "I can't pin the location";
    document.getElementById('upload-requirement-note').textContent =
      'Upload at least one photo or video of the animal or location to help responders assess the situation. This is required to submit your report.';

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
      setReportPinStatus("We couldn't confirm which barangay this location is in. Move the pin slightly or check that it is inside Dasmariñas.", true);
      setReportBarangayStatus('', false);
    } else {
      var barangaySelect = document.getElementById('location-barangay');
      barangaySelect.value = detected.code;
      barangaySelect.disabled = true;
      var nearbyLocation = reportPinLocationLabel || ('Barangay ' + detected.name);
      setReportPinStatus('Your pin is currently placed near ' + nearbyLocation + '.', false);
      setReportBarangayStatus('', false);
    }

    updateReportPinMarker();
    updateReportLocationBarangayUI();
    if (finalize !== false) {
      reverseGeocodeReportPin(reportPinCoordinates[0], reportPinCoordinates[1]);
    }
  }

  function setupReportLocationMap(map) {
    reportMapInstance = map;
    reportMapPinElement = document.createElement('span');
    reportMapPinElement.className = 'report-location-map-pin is-draggable';
    reportMapPinElement.setAttribute('aria-hidden', 'true');
    reportMapPinElement.innerHTML = '<svg viewBox="0 0 24 30" focusable="false"><path d="M12 29S2 19.8 2 11a10 10 0 1 1 20 0c0 8.8-10 18-10 18Z" fill="#e53935" stroke="#fff" stroke-width="1.4"/><circle cx="12" cy="11" r="3.2" fill="#fff"/></svg>';
    map.getContainer().appendChild(reportMapPinElement);
    reportMapCenterPinElement = map.getContainer().querySelector('#report-location-mobile-center-pin');
    if (!reportMapCenterPinElement) {
      reportMapCenterPinElement = document.createElement('span');
      reportMapCenterPinElement.className = 'report-location-mobile-center-pin';
      reportMapCenterPinElement.id = 'report-location-mobile-center-pin';
      reportMapCenterPinElement.setAttribute('aria-hidden', 'true');
      reportMapCenterPinElement.innerHTML = '<svg viewBox="0 0 24 30" focusable="false"><path d="M12 29S2 19.8 2 11a10 10 0 1 1 20 0c0 8.8-10 18-10 18Z" fill="#dc2626" stroke="#fff" stroke-width="1.4"/><circle cx="12" cy="11" r="3.2" fill="#fff"/></svg>';
      map.getContainer().appendChild(reportMapCenterPinElement);
    }
    reportMapPinElement.addEventListener('pointerdown', function(event) {
      if (event.button !== undefined && event.button !== 0) return;
      event.preventDefault();
      event.stopPropagation();
      var center = map.getCenter();
      reportMapPinDrag = {
        pointerId: event.pointerId,
        coordinates: reportPinCoordinates || [center.lng, center.lat]
      };
      reportMapPinElement.classList.add('is-dragging');
      reportMapPinElement.setPointerCapture(event.pointerId);
    });
    reportMapPinElement.addEventListener('pointermove', function(event) {
      if (!reportMapPinDrag || event.pointerId !== reportMapPinDrag.pointerId) return;
      event.preventDefault();
      var bounds = map.getContainer().getBoundingClientRect();
      var x = Math.max(0, Math.min(map.getContainer().clientWidth, event.clientX - bounds.left));
      var y = Math.max(0, Math.min(map.getContainer().clientHeight, event.clientY - bounds.top));
      var coordinates = map.unproject([x, y]);
      reportMapPinDrag.coordinates = [coordinates.lng, coordinates.lat];
      updateReportPinMarker();
    });
    function finishReportMapPinDrag(event) {
      if (!reportMapPinDrag || event.pointerId !== reportMapPinDrag.pointerId) return;
      var coordinates = reportMapPinDrag.coordinates;
      reportMapPinDrag = null;
      reportMapPinElement.classList.remove('is-dragging');
      if (reportMapPinElement.hasPointerCapture(event.pointerId)) {
        reportMapPinElement.releasePointerCapture(event.pointerId);
      }
      setReportPin(
        coordinates[0],
        coordinates[1],
        'resident',
        reportFeatureAtCoordinates(coordinates[0], coordinates[1]),
        true,
        null,
        ''
      );
    }
    reportMapPinElement.addEventListener('pointerup', finishReportMapPinDrag);
    reportMapPinElement.addEventListener('pointercancel', finishReportMapPinDrag);
    reportMapPinElement.addEventListener('click', function(event) { event.stopPropagation(); });
    if (!map.getContainer().querySelector('.report-location-map-hint')) {
      var mapHint = document.createElement('div');
      mapHint.className = 'report-location-map-hint';
      mapHint.textContent = 'Move the map to place the pin';
      map.getContainer().appendChild(mapHint);
    }
    updateReportPinMarker();

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
    map.on('render', updateReportPinMarker);
    map.on('moveend', function() {
      if (!isMobileReportLocation() || reportFallbackMode) return;
      if (reportMobilePinMoveTimer) window.clearTimeout(reportMobilePinMoveTimer);
      reportMobilePinMoveTimer = window.setTimeout(function() {
        reportMobilePinMoveTimer = null;
        if (!reportMapInstance || !isMobileReportLocation() || reportFallbackMode) return;
        var center = reportMapInstance.getCenter();
        if (reportPinCoordinates
            && Math.abs(reportPinCoordinates[0] - center.lng) < 0.00001
            && Math.abs(reportPinCoordinates[1] - center.lat) < 0.00001) return;
        setReportPin(
          center.lng,
          center.lat,
          'resident',
          reportFeatureAtCoordinates(center.lng, center.lat),
          true,
          null,
          ''
        );
      }, 300);
    });
    map.on('click', function(event) {
      if (!event || !event.lngLat) return;
      var longitude = Number(event.lngLat.lng);
      var latitude = Number(event.lngLat.lat);
      if (!Number.isFinite(longitude) || !Number.isFinite(latitude)) return;

      var searchInput = document.getElementById('report-location-search');
      if (searchInput && searchInput.value) {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (isMobileReportLocation()) {
        var mapCenter = map.getCenter();
        if (Math.abs(mapCenter.lng - longitude) < 0.000001
            && Math.abs(mapCenter.lat - latitude) < 0.000001) {
          if (reportMobilePinMoveTimer) window.clearTimeout(reportMobilePinMoveTimer);
          reportMobilePinMoveTimer = window.setTimeout(function() {
            reportMobilePinMoveTimer = null;
            setReportPin(
              longitude,
              latitude,
              'resident',
              reportFeatureAtCoordinates(longitude, latitude),
              true,
              null,
              ''
            );
          }, 300);
        } else {
          map.panTo([longitude, latitude], { duration: 250 });
        }
        return;
      }
      setReportPin(
        longitude,
        latitude,
        'resident',
        reportFeatureAtCoordinates(longitude, latitude),
        true,
        null,
        ''
      );
    });
    if (reportPendingDraftPin) {
      var restoredCoordinates = reportPendingDraftPin;
      reportPendingDraftPin = null;
      setReportPin(
        restoredCoordinates[0],
        restoredCoordinates[1],
        document.getElementById('report-pin-source').value || 'resident',
        reportFeatureAtCoordinates(restoredCoordinates[0], restoredCoordinates[1]),
        true,
        null,
        reportPinLocationLabel
      );
    }
    if (reportPinCoordinates) {
      moveReportMapTo({ center: reportPinCoordinates, zoom: 15, duration: 0 });
      map.jumpTo({ center: reportPinCoordinates, zoom: 15 });
      updateReportPinMarker();
    }
    if (reportPendingBarangayCode) focusReportBarangay(reportPendingBarangayCode);
  }

  function reportFeatureCameraCenter(feature) {
    var bounds = feature ? reportFeatureBounds(feature) : null;
    if (!bounds) return null;
    var center = [(bounds[0][0] + bounds[1][0]) / 2, (bounds[0][1] + bounds[1][1]) / 2];
    if (reportPointInPolygon(center[0], center[1], feature.geometry)) return center;
    var best = null;
    var bestDistance = Infinity;
    for (var row = 0; row < 20; row++) {
      for (var column = 0; column < 20; column++) {
        var candidate = [
          bounds[0][0] + (column + 0.5) * (bounds[1][0] - bounds[0][0]) / 20,
          bounds[0][1] + (row + 0.5) * (bounds[1][1] - bounds[0][1]) / 20
        ];
        if (!reportPointInPolygon(candidate[0], candidate[1], feature.geometry)) continue;
        var distance = Math.pow(candidate[0] - center[0], 2) + Math.pow(candidate[1] - center[1], 2);
        if (distance < bestDistance) {
          best = candidate;
          bestDistance = distance;
        }
      }
    }
    return best;
  }

  function initializeReportLocationMap() {
    if (!document.getElementById('reportLocationMap') || reportMapInstance) return;
    var container = document.getElementById('reportLocationMap');
    container.addEventListener('mapcn:load', function(event) {
      if (event.detail && event.detail.map) {
        setupReportLocationMap(event.detail.map);
        window.requestAnimationFrame(function() {
          if (typeof event.detail.map.resize === 'function') event.detail.map.resize();
        });
      }
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
        var residentCode = container.getAttribute('data-resident-barangay-code') || '';
        var residentFeature = reportBoundaryFeatureByCode(residentCode);
        var initialCenter = reportFeatureCameraCenter(residentFeature) || [120.968, 14.306];
        if (!residentFeature) {
          setReportPinStatus('Your assigned barangay boundary could not be matched. Verify the map location before continuing.', true);
        }
        window.MapCN.init('reportLocationMap', {
          center: initialCenter,
          zoom: 15.5,
          maxZoom: 18,
          barangayOverlay: false,
          style: 'https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json'
        });
      })
      .catch(function(error) {
        console.error('Report location map initialization failed:', error);
        setReportPinStatus("The map couldn't load. You can still enter the location details and attach a photo or video.", true);
      });
  }

  function initializeReviewPinMap() {
    var reviewSection = document.getElementById('review-pin-location');
    if (!reviewSection || !reportPinCoordinates) return;
    reviewSection.hidden = false;
    document.getElementById('reviewReportLocationMap').hidden = false;
    var selected = document.getElementById('location-barangay').selectedOptions[0];
    var summary = selected ? selected.textContent : 'Selected barangay';
    document.getElementById('review-pin-summary').textContent = 'Pin set in Barangay ' + summary + '.';

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
        zoom: 15,
        barangayOverlay: false,
        style: <?php echo json_encode($reportSatelliteStyle, JSON_UNESCAPED_SLASHES); ?>
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

    if (<?php echo $reportPinEnabled ? 'true' : 'false'; ?> && step === 2) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      var stepper = document.querySelector('.stepper-container');
      if (stepper) stepper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
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
    const currentStepLabel = document.getElementById('stepper-current-label');
    if (currentStepLabel) currentStepLabel.textContent = 'Step ' + currentStep + ' of ' + totalSteps;
    const mobileStepLabel = document.getElementById('stepper-mobile-label');
    const currentStepItem = document.querySelector('.stepper-step[data-step="' + currentStep + '"]');
    const currentStepName = currentStepItem && currentStepItem.querySelector('.step-label');
    if (mobileStepLabel) {
      mobileStepLabel.textContent = 'Step ' + currentStep + ' of ' + totalSteps
        + ' · ' + (currentStepName ? currentStepName.textContent.trim() : '');
    }

    steps.forEach((step, idx) => {
      const stepNum = idx + 1;
      const circle = step.querySelector('.step-circle');
      step.classList.remove('active', 'completed');
      step.removeAttribute('aria-current');

      if (stepNum === currentStep) {
        step.classList.add('active');
        circle.innerHTML = stepNum;
      } else if (stepNum < currentStep) {
        step.classList.add('completed');
        circle.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
      } else {
        circle.innerHTML = stepNum;
      }
      if (stepNum === currentStep) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });

    connectors.forEach((conn, idx) => {
      if (idx < currentStep - 1) {
        conn.classList.add('filled');
      } else {
        conn.classList.remove('filled');
      }
    });
    document.querySelectorAll('.stepper-mobile-segment').forEach((segment, idx) => {
      segment.classList.toggle('completed', idx + 1 < currentStep);
      segment.classList.toggle('current', idx + 1 === currentStep);
    });
  }

  function updateStepContent() {
    var showLocationScreen = <?php echo $reportPinEnabled ? 'true' : 'false'; ?> && currentStep === 2;
    document.body.classList.toggle('report-location-screen', showLocationScreen);
    document.getElementById('residentReportWizard').classList.toggle('report-location-screen', showLocationScreen);
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    const target = document.getElementById('step-' + currentStep);
    if (target) target.classList.add('active');
    if (currentStep === 2) {
      setReportMobileLocationDetailsView(false);
      initializeReportLocationMap();
      window.requestAnimationFrame(function() {
        if (reportMapInstance && typeof reportMapInstance.resize === 'function') reportMapInstance.resize();
      });
    }
  }

  // ==========================================================================
  // FOOTER BUTTONS
  // ==========================================================================
  function updateFooter() {
    const footerLeft = document.getElementById('footer-left');
    const btnNext = document.getElementById('btn-next');
    const wizardFooter = document.getElementById('wizard-footer');
    if (wizardFooter) wizardFooter.classList.toggle('is-first-step', currentStep === 1);

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
    updateLocationContinueState();
  }

  function updateLocationContinueState() {
    var reason = document.getElementById('report-location-continue-reason');
    var nextButton = document.getElementById('btn-next');
    if (!reason || !nextButton) {
      updateReportMobileLocationUI();
      return;
    }
    if (currentStep !== 2) {
      nextButton.disabled = false;
      reason.hidden = true;
      reason.textContent = '';
      updateReportMobileLocationUI();
      return;
    }
    var message = '';
    var street = document.getElementById('location-street');
    var date = document.getElementById('incident-date');
    var barangay = document.getElementById('location-barangay');
    if (reportFallbackMode) {
      if (!barangay || !barangay.value) message = 'Select the incident barangay to continue.';
    } else if (!reportPinCoordinates || !reportPinBarangayCode) {
      message = reportPinCoordinates
        ? 'Place the pin inside a verified Dasmariñas barangay, or use the fallback option.'
        : 'Drag the red pin, click the map, or use the fallback option to set the incident location.';
    }
    if (!message && (!street || !street.value.trim())) {
      message = 'Add a street, landmark, or directions for responders.';
    }
    if (!message && (!date || !date.value)) message = 'Select the incident date to continue.';
    nextButton.disabled = !!message;
    reason.textContent = message;
    reason.hidden = !message;
    updateReportMobileLocationUI();
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
            showToast("Mark the location on the map, or choose \"I can't pin the location\" to continue without a pin.");
            return false;
          } else if (!reportFallbackMode && reportPinBarangayCode !== barangay) {
            showToast('The barangay is based on your pin. Move the pin to the right place.');
            return false;
          }
          if (!reportFallbackMode && reportReverseLookupPending
              && street === reportAutoLocationDetail) {
            showToast('Wait a moment for the address to appear, or enter the location details below.');
            return false;
          }
        }
        if (!street) { showToast('Add a street, landmark, or directions that can help responders find the location.'); return false; }
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
        if (uploadedFiles.length === 0) {
          showToast('Upload at least one photo or video before continuing.');
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
    card.addEventListener('click', function (event) {
      event.preventDefault();
      const wasSelected = this.classList.contains('selected');
      document.querySelectorAll('.report-type-card').forEach(c => {
        c.classList.remove('selected');
        c.setAttribute('aria-checked', 'false');
        c.querySelector('input[type="radio"]').checked = false;
      });
      if (!wasSelected) {
        this.classList.add('selected');
        this.setAttribute('aria-checked', 'true');
        this.querySelector('input[type="radio"]').checked = true;
      }
      this.querySelector('input[type="radio"]').dispatchEvent(new Event('change', { bubbles: true }));
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
    var latitudeField = document.getElementById('report-latitude');
    var longitudeField = document.getElementById('report-longitude');
    if (latitudeField && longitudeField) {
      var latitudeValue = latitudeField.value.trim();
      var longitudeValue = longitudeField.value.trim();
      var latitude = Number(latitudeValue);
      var longitude = Number(longitudeValue);
      var streetInput = document.getElementById('location-street');
      var fallbackState = document.getElementById('report-location-fallback-state');
      var streetTouchedState = document.getElementById('report-location-street-touched');
      reportFallbackMode = !!(fallbackState && fallbackState.value === '1');
      reportStreetInputTouched = !!(streetTouchedState && streetTouchedState.value === '1');
      reportPendingDraftPin = null;
      reportPinLocationLabel = '';
      if (latitudeValue && longitudeValue
          && Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
          && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180) {
        reportFallbackMode = false;
        reportPendingDraftPin = [longitude, latitude];
        if (!reportStreetInputTouched && streetInput) {
          reportAutoLocationDetail = streetInput.value.trim();
          reportAutoLocationCoordinates = reportAutoLocationDetail ? [longitude, latitude] : null;
          reportPinLocationLabel = reportAutoLocationDetail;
        }
      } else if (!reportFallbackMode) {
        reportStreetInputTouched = false;
        reportAutoLocationDetail = '';
        reportAutoLocationCoordinates = null;
        if (streetInput) {
          streetInput.value = '';
          streetInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        setReportDraftField('report-location-street-touched', '0');
      }
      var fallback = document.getElementById('report-location-fallback');
      var fallbackToggle = document.getElementById('report-location-fallback-toggle');
      var barangaySelect = document.getElementById('location-barangay');
      if (fallback) fallback.hidden = !reportFallbackMode;
      if (fallbackToggle) {
        fallbackToggle.textContent = reportFallbackMode
          ? 'I can place a pin instead' : "I can't pin the location";
      }
      if (barangaySelect) barangaySelect.disabled = false;
      updateReportLocationBarangayUI();
    }
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
    if (uploadedFiles.length === 0) {
      showToast('Upload at least one photo or video before submitting your report.');
      resetSubmitButton();
      goToStep(4);
      return;
    }
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
    formData.append('csrf_token', reportCsrfToken);

    for (var fi = 0; fi < uploadedFiles.length; fi++) {
      formData.append('attachments[]', uploadedFiles[fi]);
    }

    var preflightData = new FormData();
    preflightData.append('csrf_token', reportCsrfToken);
    preflightData.append('report_type', reportType ? reportType.value : '');
    preflightData.append('barangay_psgc_code', barangayCode);
    if (document.getElementById('report-latitude')) {
      preflightData.append('latitude', document.getElementById('report-latitude').value);
      preflightData.append('longitude', document.getElementById('report-longitude').value);
    }

    fetch('report-duplicate-check.php', {
      method: 'POST',
      body: preflightData
    })
    .then(function(response) {
      return response.json().then(function(payload) {
        if (!response.ok || !payload.success) {
          throw new Error(payload.message || 'Similar reports could not be checked.');
        }
        return payload;
      });
    })
    .catch(function(error) {
      showToast((error && error.message ? error.message : 'Similar reports could not be checked.')
        + ' Your report can still be submitted normally.');
      return { success: true, candidates: [] };
    })
    .then(function(payload) {
      if (!payload.candidates || payload.candidates.length === 0) return 'none';
      return showDuplicateReview(payload.candidates);
    })
    .then(function(choice) {
      if (choice === 'cancel') {
        resetSubmitButton();
        return null;
      }
      if (choice === 'same' && pendingDuplicateCandidates.length) {
        var selectedParent = document.querySelector('input[name="duplicate-parent-choice"]:checked');
        formData.append('duplicate_parent_id', selectedParent ? selectedParent.value : String(pendingDuplicateCandidates[0].report_id));
        formData.append('duplicate_choice', 'same');
      }
      return fetch('submit-report.php', {
        method: 'POST',
        body: formData
      });
    })
    .then(function(response) {
      if (!response) return null;
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
    .catch(function(error) {
      showToast(error && error.message ? error.message : 'Network error. Please check your connection and try again.');
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
    document.getElementById('location-barangay').disabled = false;
    document.getElementById('location-street').value = '';
    reportPinCoordinates = null;
    reportPinAccuracyMeters = null;
    reportReverseLookupRequestId++;
    reportReverseLookupPending = false;
    reportAutoLocationDetail = '';
    reportAutoLocationCoordinates = null;
    reportPinLocationLabel = '';
    reportPinBarangayCode = '';
    reportStreetInputTouched = false;
    reportPendingDraftPin = null;
    if (reportReviewPinElement) reportReviewPinElement.hidden = true;
    reportFallbackMode = false;
    setReportDraftField('report-latitude', '');
    setReportDraftField('report-longitude', '');
    setReportDraftField('report-pin-source', 'resident');
    setReportDraftField('report-location-fallback-state', '0');
    setReportDraftField('report-location-street-touched', '0');
    if (document.getElementById('report-location-coordinates')) document.getElementById('report-location-coordinates').textContent = 'Latitude: — | Longitude: —';
    if (document.getElementById('report-location-address')) {
      document.getElementById('report-location-address').textContent = 'Detected Address: Move the pin or choose a place to see its address.';
      document.getElementById('report-location-address').classList.remove('is-error');
    }
    setReportBarangayStatus('', false);
    if (document.getElementById('report-location-accuracy')) {
      document.getElementById('report-location-accuracy').hidden = true;
      document.getElementById('report-location-accuracy').classList.remove('is-error');
    }
    var placeSearchInput = document.getElementById('report-location-search');
    if (placeSearchInput) {
      placeSearchInput.value = '';
      placeSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (document.getElementById('report-pin-source')) document.getElementById('report-pin-source').value = 'resident';
    if (document.getElementById('report-location-fallback')) document.getElementById('report-location-fallback').hidden = true;
    if (document.getElementById('report-location-fallback-toggle')) document.getElementById('report-location-fallback-toggle').textContent = "I can't pin the location";
    if (document.getElementById('report-location-pin-state')) document.getElementById('report-location-pin-state').hidden = true;
    if (document.getElementById('report-location-status')) setReportPinStatus('Drag the red pin or click the map to place it.', false);
    updateReportLocationBarangayUI();
    if (reportMapInstance && reportMapInstance.getSource('report-pin-accuracy')) {
      reportMapInstance.getSource('report-pin-accuracy').setData({ type: 'FeatureCollection', features: [] });
    }
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
    updateStepperUI();
    updateFooter();
    var reportLocationMapElement = document.getElementById('reportLocationMap');
    if (reportLocationMapElement) {
      reportLocationMapElement.addEventListener('dcvs:barangay-search', function(event) {
        var code = event.detail && event.detail.code;
        if (code) focusReportBarangay(code);
      });
      reportLocationMapElement.addEventListener('dcvs:place-selected', function(event) {
        var place = event.detail || {};
        var coordinates = place.coordinates;
        if (!Array.isArray(coordinates) || !Number.isFinite(Number(coordinates[0]))
            || !Number.isFinite(Number(coordinates[1]))
            || Number(coordinates[0]) < -180 || Number(coordinates[0]) > 180
            || Number(coordinates[1]) < -90 || Number(coordinates[1]) > 90) {
          setReportPinStatus("We couldn't place that location on the map. Try another result, drag the pin, or click the map.", true);
          return;
        }
        var longitude = Number(coordinates[0]);
        var latitude = Number(coordinates[1]);
        var detected = reportFeatureAtCoordinates(longitude, latitude);
        var locationInput = document.getElementById('location-street');
        var placeDetail = place.name || place.label || '';
        if (locationInput && placeDetail && (!reportStreetInputTouched || !locationInput.value.trim())) {
          locationInput.value = placeDetail;
          reportAutoLocationDetail = placeDetail;
          reportAutoLocationCoordinates = [longitude, latitude];
          reportStreetInputTouched = false;
          setReportDraftField('report-location-street-touched', '0');
          locationInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        setReportPin(longitude, latitude, 'resident', detected, true, null, place.name || place.label || '');
        if (place.approximate && detected && detected.code) {
          setReportPinStatus('This search result is approximate. Check the satellite imagery and drag the pin to the exact incident spot.', false);
        }
        moveReportMapTo({ center: [longitude, latitude], zoom: 18, duration: 700 });
      });
    }
    var locationStreetInput = document.getElementById('location-street');
    if (locationStreetInput) {
      locationStreetInput.addEventListener('input', function() {
        reportStreetInputTouched = !!this.value.trim() && this.value.trim() !== reportAutoLocationDetail;
        setReportDraftField('report-location-street-touched', reportStreetInputTouched ? '1' : '0');
        updateLocationContinueState();
      });
    }
    ['location-barangay', 'incident-date'].forEach(function(id) {
      var field = document.getElementById(id);
      if (field) {
        field.addEventListener('change', updateLocationContinueState);
        field.addEventListener('input', updateLocationContinueState);
      }
    });
    var changeBarangayButton = document.getElementById('report-location-barangay-change');
    if (changeBarangayButton) {
      changeBarangayButton.addEventListener('click', function() {
        var fallbackButton = document.getElementById('report-location-fallback-toggle');
        if (fallbackButton && !reportFallbackMode) fallbackButton.click();
      });
    }
    var mobileDetailsBack = document.getElementById('report-location-mobile-back');
    var mobileEditPin = document.getElementById('report-location-mobile-edit-pin');
    if (mobileDetailsBack) {
      mobileDetailsBack.addEventListener('click', function() {
        setReportMobileLocationDetailsView(false);
      });
    }
    if (mobileEditPin) {
      mobileEditPin.addEventListener('click', function() {
        setReportMobileLocationDetailsView(false);
      });
    }
    var fallbackToggle = document.getElementById('report-location-fallback-toggle');
    if (fallbackToggle) {
      fallbackToggle.addEventListener('click', function() {
        reportFallbackMode = !reportFallbackMode;
        setReportDraftField('report-location-fallback-state', reportFallbackMode ? '1' : '0');
        document.getElementById('report-location-fallback').hidden = !reportFallbackMode;
        this.textContent = reportFallbackMode ? 'I can place a pin instead' : "I can't pin the location";
        if (reportFallbackMode) {
          var pinState = document.getElementById('report-location-pin-state');
          if (pinState) pinState.hidden = true;
          document.getElementById('location-barangay').disabled = false;
          document.getElementById('location-barangay').value = '';
          var locationInput = document.getElementById('location-street');
          if (locationInput && locationInput.value.trim() === reportAutoLocationDetail) {
            locationInput.value = '';
            reportStreetInputTouched = false;
          } else if (locationInput && locationInput.value.trim()) {
            reportStreetInputTouched = true;
          }
          setReportDraftField('report-location-street-touched', reportStreetInputTouched ? '1' : '0');
          reportAutoLocationDetail = '';
          reportAutoLocationCoordinates = null;
          reportPinLocationLabel = '';
          reportPinCoordinates = null;
          reportPinBarangayCode = '';
          reportPinAccuracyMeters = null;
          reportReverseLookupRequestId++;
          reportReverseLookupPending = false;
          reportPendingDraftPin = null;
          setReportDraftField('report-latitude', '');
          setReportDraftField('report-longitude', '');
          setReportDraftField('report-pin-source', 'resident');
          document.getElementById('report-location-coordinates').textContent = 'Latitude: — | Longitude: —';
          document.getElementById('report-location-address').textContent = 'Detected Address: Move the pin or choose a place to see its address.';
          document.getElementById('report-location-address').classList.remove('is-error');
          document.getElementById('report-location-accuracy').hidden = true;
          setReportBarangayStatus('', false);
          if (reportMapInstance && reportMapInstance.getSource('report-pin-accuracy')) {
            reportMapInstance.getSource('report-pin-accuracy').setData({ type: 'FeatureCollection', features: [] });
          }
          document.getElementById('upload-requirement-note').textContent =
            'Upload at least one photo or video of the animal or location to help responders assess the situation. This is required to submit your report.';
          setReportPinStatus("No pin is selected. Describe where the incident happened below and add a photo or video in the next step.", false);
          updateReportLocationBarangayUI();
        } else {
          document.getElementById('location-barangay').disabled = false;
          document.getElementById('location-barangay').value = '';
          var locationInput = document.getElementById('location-street');
          if (locationInput) {
            locationInput.value = '';
            locationInput.dispatchEvent(new Event('change', { bubbles: true }));
          }
          reportStreetInputTouched = false;
          setReportDraftField('report-location-street-touched', '0');
          document.getElementById('upload-requirement-note').textContent =
            'Upload at least one photo or video of the animal or location to help responders assess the situation. This is required to submit your report.';
          setReportPinStatus('Drag the red pin or click the map to place it.', false);
          setReportBarangayStatus('', false);
          updateReportLocationBarangayUI();
        }
      });
    }
    var gpsButton = document.getElementById('use-report-gps');
    if (gpsButton) {
      gpsButton.addEventListener('click', function() {
        if (!navigator.geolocation) {
          showToast('Your browser cannot share your current location. Search for the place, drag the pin, or click the map instead.');
          return;
        }
        gpsButton.disabled = true;
        gpsButton.setAttribute('aria-busy', 'true');
        navigator.geolocation.getCurrentPosition(function(position) {
          if (!reportMapInstance) {
            showToast('The map is still loading. Please try again.');
            gpsButton.disabled = false;
            gpsButton.removeAttribute('aria-busy');
            return;
          }
          var latitude = Number(position.coords.latitude);
          var longitude = Number(position.coords.longitude);
          if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
              || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
            showToast("Your current location couldn't be read. Search for the place, drag the pin, or click the map instead.");
            gpsButton.disabled = false;
            gpsButton.removeAttribute('aria-busy');
            return;
          }
          var coords = [longitude, latitude];
          setReportPin(coords[0], coords[1], 'gps_assisted', reportFeatureAtCoordinates(coords[0], coords[1]), true, position.coords.accuracy);
          moveReportMapTo({ center: coords, zoom: 18, duration: 700 });
          gpsButton.disabled = false;
          gpsButton.removeAttribute('aria-busy');
        }, function(error) {
          console.warn('Incident location GPS request failed:', error.message);
          showToast("We couldn't get your current location. Check your browser's location permission, or search for the place, drag the pin, or click the map.");
          gpsButton.disabled = false;
          gpsButton.removeAttribute('aria-busy');
        }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
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
<script src="assets/js/map-place-search.js?v=<?php echo (int)filemtime(__DIR__ . '/assets/js/map-place-search.js'); ?>"></script>
<?php endif; ?>

<style>
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

</body>
</html>
