<?php
require_once __DIR__ . '/../../includes/app-base.php';
$appBaseUrl = dcvsAppBaseUrl();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../env.php';
loadDcvsEnv();
$mapTilerApiKey = trim((string)env('DCVS_MAPTILER_API_KEY', ''));

$barangaySummary = [];
$bsq = $conn->query("SELECT b.barangay_name,
    COUNT(DISTINCT CASE WHEN cc.status = 'active'  THEN cc.case_id END) AS confirmed,
    COUNT(DISTINCT CASE WHEN r.status = 'pending'  THEN r.report_id END) AS pending,
    COUNT(DISTINCT CASE WHEN cc.status = 'resolved' THEN cc.case_id END) AS resolved
    FROM barangays b
    LEFT JOIN confirmed_cases cc ON b.barangay_id = cc.barangay_id AND cc.status IN ('active', 'resolved')
    LEFT JOIN reports r ON b.barangay_id = r.barangay_id AND r.archived_at IS NULL
    GROUP BY b.barangay_id
    ORDER BY confirmed DESC");
if ($bsq) {
    while ($row = $bsq->fetch_assoc()) $barangaySummary[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Geo-Map | Dasmariñas City Veterinary Services</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="stylesheet" href="dist/mapcn.css" />
  <link rel="stylesheet" href="geo-map/map.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/site-navbar.css', ENT_QUOTES, 'UTF-8'); ?>" />
  <style>
    :root {
      --bg-ice: #e9f3ff;
      --bg-cloud: #f3f8ff;
      --primary: #045E1FD9;
      --primary-dark: #045E1FD9;
      --ink: #045E1FD9;
      --muted: #4a5672;
      --line-soft: rgba(4, 94, 31, 0.85);
      --glass: rgba(233, 243, 255, 0.62);
      --white: #ffffff;
      --accent-blue: #045E1FD9;
      --shadow-soft: 0 20px 45px rgba(4, 94, 31, 0.85);
      --shadow-card: 0 18px 40px rgba(4, 94, 31, 0.85);
      --transition: cubic-bezier(0.25, 0.65, 0.35, 1);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      color: var(--ink);
      background: #ffffff;
      min-height: 100vh;
      line-height: 1.6;
    }
    img, svg { display: block; max-width: 100%; }
    a { text-decoration: none; color: inherit; }
    .container { width: min(1200px, 90vw); margin: 0 auto; }

    /* Same navbar styles as landing page */
    .site-header {
      position: fixed; top: 0; left: 0; right: 0; z-index: 1000; padding: 0.8rem 0;
    }
    .navbar {
      display: flex; align-items: center; gap: 2rem;
      width: min(1200px, 92vw); margin: 0 auto;
      padding: 0.85rem 1.5rem; border-radius: 999px;
      background: #ffffff;
      border: 1px solid rgba(0, 0, 0, 0.08);
      box-shadow: 0 24px 48px rgba(0, 0, 0, 0.06);
      position: relative;
      transition: background 0.3s var(--transition), box-shadow 0.3s var(--transition), border 0.3s var(--transition), transform 0.3s var(--transition);
    }
    .brand-logo { display: block; object-fit: contain; }
    .navbar::before, .navbar::after { display: none; }
    .navbar.nav-scrolled {
      background: #ffffff;
      border-color: rgba(0, 0, 0, 0.12);
      box-shadow: 0 26px 52px rgba(0, 0, 0, 0.1);
    }
    .nav-links {
      display: flex; align-items: center; justify-content: center;
      gap: 1.5rem; list-style: none; margin: 0; padding: 0; flex: 1;
    }
    .nav-links a {
      font-weight: 500; padding: 6px 16px; border-radius: 8px;
      color: #1a2a3a; transition: color 0.25s var(--transition), background 0.25s var(--transition);
    }
    .nav-links a::after { display: none; }
    .nav-links a:hover, .nav-links a:focus-visible { background: rgba(4, 94, 31, 0.06); color: #045e1f; }
    .nav-links a.active { background: rgba(4, 94, 31, 0.1); color: #045e1f; }
    .nav-item { position: relative; }
    .dropdown-toggle { cursor: pointer; display: flex; align-items: center; gap: 4px; padding: 6px 16px; border-radius: 8px; color: #1a2a3a; transition: background 0.2s, color 0.2s; }
    .dropdown-toggle:hover { background: rgba(4,94,31,0.06); color: #045e1f; }
    .dropdown-toggle .arrow { font-size: 10px; transition: transform 0.3s var(--transition); }
    .nav-item.open .dropdown-toggle .arrow { transform: rotate(180deg); }
    .dropdown-menu {
      position: absolute; top: calc(100% + 8px); left: 0;
      background: #ffffff; border-radius: 12px; padding: 6px 0; min-width: 190px;
      box-shadow: 0 20px 48px rgba(0,0,0,0.12);
      opacity: 0; visibility: hidden; transform: translateY(8px);
      transition: all 0.25s ease; z-index: 9999;
      border: 1px solid rgba(0,0,0,0.06);
    }
    .nav-item:hover .dropdown-menu, .nav-item.open .dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
    .dropdown-menu a { display: block; padding: 10px 20px; font-size: 14px; font-weight: 500; color: #1a2a3a; white-space: nowrap; }
    .dropdown-menu a::after { display: none; }
    .dropdown-menu a:hover { background: rgba(4,94,31,0.06); color: #045e1f; }
    .nav-toggle {
      display: none; flex-direction: column; gap: 5px;
      background: transparent; border: none; cursor: pointer; padding: 0;
    }
    .nav-toggle span { width: 22px; height: 2px; background: #1a2a3a; border-radius: 999px; transition: transform 0.3s var(--transition), opacity 0.3s var(--transition); }
    .nav-login-btn { font-weight: 600; font-size: 0.88rem; padding: 8px 22px; border-radius: 8px; background: #045e1f; color: #fff !important; white-space: nowrap; transition: background 0.2s; flex-shrink: 0; }

    /* Page content */
    .gm-page { padding-top: 100px; padding-bottom: 3rem; background: #ffffff; }
    .gm-hero { text-align: center; padding: 2rem 0 1.5rem; }
    .gm-hero h2 { font-size: 1.6rem; font-weight: 800; color: #1a2a3a; font-family: 'Montserrat', sans-serif; }
    .gm-hero p { max-width: 680px; margin: 0.6rem auto 0; color: #4a5672; font-size: 0.92rem; }

    /* Map */
    .gm-map-section {
      background: #ffffff; border-radius: 12px; overflow: hidden;
      border: 1px solid #e2e8f0; margin-bottom: 2rem;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .gm-map-section #geoMap { height: 520px; position: relative; }
    .gm-map-section .mapcn-loading {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      background: #f8fafc; color: #94a3b8; font-size: 0.9rem; z-index: 1;
    }
    .gm-map-section .mapcn-loading i { animation: spin 1s linear infinite; margin-right: 8px; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .gm-legend {
      display: flex; gap: 14px; align-items: center;
      padding: 0.75rem 1.25rem; border-top: 1px solid #e2e8f0;
      flex-wrap: wrap; font-size: 0.8rem;
    }
    .gm-legend-item { display: flex; align-items: center; gap: 5px; color: #475569; }
    .gm-legend-swatch { width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,0.06); }

    /* Info section */
    .gm-info-section { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }
    .gm-info-card {
      background: #f8fafc; border-radius: 14px; padding: 1.5rem;
      border: 1px solid rgba(0,0,0,0.05);
    }
    .gm-info-card h3 { font-size: 1rem; font-weight: 700; margin-bottom: 0.8rem; color: #1a2a3a; }
    .gm-info-card p, .gm-info-card li { font-size: 0.85rem; color: #4a5672; line-height: 1.7; }
    .gm-info-card ul { padding-left: 1.2rem; list-style: disc; }
    .gm-info-card li { margin-bottom: 0.3rem; }
    .advisory-mini {
      padding: 0.75rem; background: #ffffff; border-radius: 10px;
      margin-bottom: 0.6rem; border-left: 3px solid #045e1f;
    }
    .advisory-mini h4 { font-size: 0.85rem; font-weight: 700; margin-bottom: 2px; color: #1a2a3a; }
    .advisory-mini p { font-size: 0.78rem; color: #4a5672; }
    .advisory-mini a { font-size: 0.78rem; color: #045e1f; font-weight: 600; }

    /* Table with pagination */
    .gm-table-wrap {
      background: #f8fafc; border-radius: 14px;
      border: 1px solid rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 2rem;
    }
    .gm-table-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1rem 1.25rem; background: #ffffff; border-bottom: 1px solid #e2e8f0;
    }
    .gm-table-header h3 { font-size: 1rem; font-weight: 700; color: #1a2a3a; }
    .gm-table-search {
      padding: 6px 12px 6px 30px; border: 1px solid #e2e8f0;
      border-radius: 8px; font-size: 0.8rem; width: 200px; outline: none;
    }
    .gm-table-search:focus { border-color: #045e1f; box-shadow: 0 0 0 3px rgba(4,94,31,0.1); }
    .gm-table-wrap table { width: 100%; border-collapse: collapse; font-size: 0.82rem; background: #ffffff; }
    .gm-table-wrap th {
      text-align: left; padding: 0.7rem 1.25rem;
      background: #f8fafc; font-weight: 700; color: #475569;
      font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em;
      border-bottom: 1px solid #e2e8f0;
    }
    .gm-table-wrap td {
      padding: 0.6rem 1.25rem; border-bottom: 1px solid #f1f5f9;
    }
    .gm-table-wrap tbody tr:hover td { background: #f8fafc; }
    .risk-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; }
    .risk-dot.green { background: #22c55e; }
    .risk-dot.yellow { background: #eab308; }
    .risk-dot.orange { background: #f97316; }
    .risk-dot.red { background: #ef4444; }

    /* Pagination */
    .pagination {
      display: flex; align-items: center; justify-content: center;
      gap: 6px; padding: 1rem 1.25rem; border-top: 1px solid #e2e8f0; background: #ffffff;
    }
    .pagination button {
      padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px;
      background: #ffffff; color: #475569; font-size: 0.78rem; font-weight: 600;
      cursor: pointer; transition: all 0.2s;
    }
    .pagination button:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .pagination button.active { background: #045e1f; color: #ffffff; border-color: #045e1f; }
    .pagination button:disabled { opacity: 0.4; cursor: default; }
    .pagination .page-info { font-size: 0.78rem; color: #94a3b8; margin: 0 8px; }

    /* Footer styles — matching landing page */
    .site-footer {
      background: linear-gradient(180deg, #0b1a0f 30%, #045e1fd9 80%);
      color: #e8f1eb;
      padding: 4rem 3rem 2.5rem;
      margin-top: 0;
      border-top: 1px solid #0a3c14;
    }
    .footer-container {
      display: flex;
      gap: 4rem;
      max-width: 1400px;
      margin: 0 auto 3rem;
    }
    .footer-brand-column { flex: 0 0 280px; display: flex; flex-direction: column; gap: 1.5rem; }
    .footer-brand { font-size: 1.4rem; font-weight: 700; color: #ffffff; margin: 0; display: flex; gap: 0.6rem; align-items: baseline; }
    .footer-brand-tag { font-size: 0.75rem; font-weight: 500; color: #b8d9bf; letter-spacing: 0.05em; }
    .footer-description { font-size: 0.9rem; color: #c5d9cc; line-height: 1.6; margin: 0; }
    .footer-socials { display: flex; gap: 0.7rem; margin-top: 0.5rem; }
    .social-link {
      width: 32px; height: 32px; border-radius: 50%;
      background: rgba(255,255,255,0.1); color: #ffffff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 600; text-decoration: none;
      transition: all 0.3s ease; font-size: 0.8rem;
    }
    .social-link:hover { background: #4ade80; color: #000; transform: translateY(-2px); }
    .footer-link-group { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3rem; flex: 1; }
    .footer-column { display: flex; flex-direction: column; gap: 1rem; }
    .footer-section-title { font-size: 0.95rem; font-weight: 700; color: #ffffff; margin: 0; line-height: 1.4; }
    .footer-links { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.8rem; }
    .footer-links a { color: #c5d9cc; text-decoration: none; font-size: 0.9rem; transition: color 0.3s ease; line-height: 1.5; }
    .footer-links a:hover { color: #4ade80; }
    .footer-bottom { border-top: 1px solid #0a3c14; padding-top: 2rem; }
    .footer-bottom-content { display: flex; justify-content: space-between; align-items: center; max-width: 1400px; margin: 0 auto; }
    .footer-bottom-socials { display: flex; gap: 0.6rem; }
    .social-link-small {
      width: 28px; height: 28px; border-radius: 50%;
      background: rgba(255,255,255,0.1); color: #ffffff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 600; text-decoration: none;
      transition: all 0.3s ease; font-size: 0.75rem;
    }
    .social-link-small:hover { background: #4ade80; color: #000; transform: translateY(-2px); }
    .footer-bottom p { color: #9db4a0; font-size: 0.85rem; margin: 0; }

    @media (max-width: 1024px) {
      .footer-link-group { grid-template-columns: repeat(2, 1fr); gap: 2rem; }
      .footer-container { gap: 2.5rem; }
    }
    @media (max-width: 768px) {
      .footer-container { flex-direction: column; gap: 2.5rem; }
      .footer-link-group { grid-template-columns: 1fr; gap: 1.5rem; }
      .footer-bottom-content { flex-direction: column; gap: 1rem; }
      .footer-brand-column { flex: 1; }
    }

    /* Responsive */
    @media (max-width: 1100px) {
      .nav-links {
        position: fixed; inset: 90px 1.5rem auto 1.5rem;
        background: #ffffff; border-radius: 24px; padding: 1.8rem;
        flex-direction: column; align-items: flex-start;
        gap: 1rem; box-shadow: 0 24px 60px rgba(18, 39, 84, 0.25);
        transform: translateY(-20px); opacity: 0; pointer-events: none;
        transition: opacity 0.3s var(--transition), transform 0.3s var(--transition);
        max-height: calc(100vh - 110px); overflow-y: auto;
      }
      .nav-links.open { transform: translateY(0); opacity: 1; pointer-events: auto; }
      .nav-toggle { display: flex; }
      .nav-login-btn { display: inline-block; }
      .nav-toggle.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
      .nav-toggle.open span:nth-child(2) { opacity: 0; }
      .nav-toggle.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
      .dropdown-menu { position: static; box-shadow: none; padding: 0 0 0 1rem; border: none; opacity: 1; visibility: visible; transform: none; display: none; }
      .nav-item.open .dropdown-menu { display: block; }
      .gm-info-section { grid-template-columns: 1fr; }
      .gm-page { padding-top: 110px; }
    }
    @media (max-width: 768px) {
      .footer-container { grid-template-columns: 1fr; gap: 2rem; }
      .footer-link-group { grid-template-columns: 1fr; gap: 1.5rem; }
      .footer-bottom-content { flex-direction: column; text-align: center; }
      .gm-table-search { width: 140px; }
      .gm-hero h2 { font-size: 1.35rem; }
      .gm-hero p { font-size: 0.85rem; }
    }
    @media (max-width: 480px) {
    }

    /* Inter font (shadcn dialog style) */
    body {
      font-family: 'Inter', sans-serif !important;
    }

    .gm-hero h2,
    .gm-hero p,
    .gm-table-wrapper,
    .gm-table,
    .gm-table th,
    .gm-table td,
    .gm-sidebar h3,
    .gm-sidebar p,
    .gm-sidebar li,
    .gm-advisory-card,
    .gm-advisory-card h4,
    .gm-advisory-card p,
    .gm-search-box,
    .gm-legend-label,
    .gm-table-search {
      font-family: 'Inter', sans-serif !important;
    }

    .gm-hero h2 {
      letter-spacing: -0.025em !important;
      font-weight: 700 !important;
    }

    .gm-hero p {
      letter-spacing: -0.01em !important;
    }

    .gm-sidebar h3 {
      letter-spacing: -0.02em !important;
      font-weight: 700 !important;
    }

    .gm-advisory-card h4 {
      letter-spacing: -0.015em !important;
      font-weight: 600 !important;
    }
  </style>
  <link rel="stylesheet" href="assets/css/responsive.css" />
</head>
<body>

<header class="site-header">
  <nav class="navbar">
    <a href="landing-page.php" class="navbar-brand"><img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" class="brand-logo" style="height: 50px; width: auto;"><span class="navbar-brand-name">Dasmariñas City Veterinary Services</span></a>
    <button class="nav-toggle" aria-label="Toggle navigation">
      <span></span><span></span><span></span>
    </button>
    <ul class="nav-links">
      <li><a href="landing-page.php">Home</a></li>
      <li class="nav-item">
        <a href="javascript:void(0)" class="dropdown-toggle">Updates <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="announcements.php">Public Service Updates</a>
          <a href="geo-map-landing.php" class="active">Geo-Map</a>
        </div>
      </li>
      <li class="nav-item">
        <a href="about.php" class="dropdown-toggle">About <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="about.php">About Us</a>
          <a href="faq.php">FAQ</a>
          <a href="contact.php">Contact Us</a>
        </div>
      </li>
      <li><a href="adoption.php">Adoption</a></li>
    </ul>
    <a href="contact.php" class="nav-contact-btn">Contact Us</a>
    <a href="login.php" class="nav-login-btn">Login</a>
  </nav>
</header>

<div class="gm-page">
  <div class="container">

    <div class="gm-hero">
      <h2>Animal Welfare Case Map of Dasmariñas</h2>
      <p>Explore filed animal welfare reports across all 75 barangays. Report pins include cases at every status; barangay shading reflects confirmed case concentration.</p>
    </div>

    <!-- Map -->
    <div class="gm-map-section">
      <div id="geoMap" data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":true,"geojsonUrl":"geo-map/barangays-public.geojson.php","style":"https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json"}' data-map-basemap-toggle="true" data-city-boundary="true" data-map-risk-overlay="true" <?php if ($mapTilerApiKey !== ''): ?>data-maptiler-key="<?php echo htmlspecialchars($mapTilerApiKey, ENT_QUOTES, 'UTF-8'); ?>" data-preserve-map-view="true"<?php endif; ?> style="width:100%;height:520px;position:relative;">
        <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading map...</div>
        <div class="geo-basemap-status" data-basemap-status role="status" aria-live="polite"></div>
      </div>
      <!-- Hidden panel kept for mapcn bundle compatibility -->
      <div class="geo-info-panel" id="geoInfoPanel" style="display:none;"></div>
      <div class="gm-legend">
        <span style="font-weight:700;color:#0f172a;">Risk Level:</span>
        <div class="gm-legend-item"><span class="gm-legend-swatch" style="background:#22c55e;"></span> 0 cases</div>
        <div class="gm-legend-item"><span class="gm-legend-swatch" style="background:#eab308;"></span> 1–5 cases</div>
        <div class="gm-legend-item"><span class="gm-legend-swatch" style="background:#f97316;"></span> 6–15 cases</div>
        <div class="gm-legend-item"><span class="gm-legend-swatch" style="background:#ef4444;"></span> 16+ cases</div>
        <span style="font-weight:700;color:#0f172a;margin-left:12px;">Report pins:</span>
        <span class="gm-legend-item"><span class="gm-legend-swatch" style="background:#eab308;border-radius:50%;"></span> Pending</span>
        <span class="gm-legend-item"><span class="gm-legend-swatch" style="background:#3b82f6;border-radius:50%;"></span> Accepted / reviewing</span>
        <span class="gm-legend-item"><span class="gm-legend-swatch" style="background:#22c55e;border-radius:50%;"></span> Confirmed</span>
        <span class="gm-legend-item"><span class="gm-legend-swatch" style="background:#8b5cf6;border-radius:50%;"></span> Scheduled</span>
        <span class="gm-legend-item"><span class="gm-legend-swatch" style="background:#64748b;border-radius:50%;"></span> Resolved</span>
        <span class="gm-legend-item"><span class="gm-legend-swatch" style="background:#0f766e;border-radius:50%;"></span> Other status</span>
        <div style="margin-left:auto;font-size:0.72rem;color:#94a3b8;">
          Auto-refreshes every 30s
        </div>
      </div>
    </div>

    <!-- Info -->
    <div class="gm-info-section">
      <div class="gm-info-card">
        <h3>How to Read the Map</h3>
        <ul>
          <li>Each circle pin represents a filed report, regardless of status; clustered pins show multiple nearby reports</li>
          <li>Pin colors show report status; click a pin for its status, category, and barangay</li>
          <li>Barangay shading shows confirmed case concentration, not all filed report counts</li>
          <li><strong>Green</strong> — No confirmed cases reported</li>
          <li><strong>Yellow</strong> — 1 to 5 confirmed cases (low)</li>
          <li><strong>Orange</strong> — 6 to 15 confirmed cases (moderate)</li>
          <li><strong>Red</strong> — 16 or more confirmed cases (high)</li>
          <li>Barangay names are displayed on the map</li>
          <li>Use the search box to locate a specific barangay</li>
          <li>Data refreshes automatically every 30 seconds</li>
        </ul>
      </div>
    </div>

    <!-- Barangay Table with Pagination -->
    <div class="gm-table-wrap">
      <div class="gm-table-header">
        <h3>Barangay Case Summary</h3>
        <div style="position:relative;">
          <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.75rem;pointer-events:none;"></i>
          <input type="text" id="bgyTableSearch" class="gm-table-search" placeholder="Search barangay..." oninput="onTableSearch(this.value)">
        </div>
      </div>
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Barangay</th>
              <th>Status</th>
              <th>Confirmed</th>
              <th>Pending</th>
              <th>Resolved</th>
            </tr>
          </thead>
          <tbody id="bgyTableBody">
            <?php foreach ($barangaySummary as $b): 
              $c = (int)$b['confirmed'];
              if ($c > 15) $cls = 'red';
              elseif ($c >= 6) $cls = 'orange';
              elseif ($c >= 1) $cls = 'yellow';
              else $cls = 'green';
            ?>
            <tr>
              <td style="font-weight:600;"><?php echo htmlspecialchars($b['barangay_name']); ?></td>
              <td><span class="risk-dot <?php echo $cls; ?>"></span></td>
              <td><?php echo $c; ?></td>
              <td><?php echo (int)$b['pending']; ?></td>
              <td><?php echo (int)$b['resolved']; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="pagination" id="tablePagination"></div>
    </div>

  </div>
</div>

<!-- Footer -->
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-column footer-brand-column">
      <h4 class="footer-brand">Dasmariñas City Veterinary Services<span class="footer-brand-tag">DCVS</span></h4>
      <p class="footer-description">Contact for any help & support or business related query of vetdasma@yahoo.com</p>
      <div class="footer-socials">
        <a href="#" class="social-link">f</a>
        <a href="#" class="social-link">G</a>
        <a href="#" class="social-link">y</a>
      </div>
    </div>
    <div class="footer-link-group">
      <div class="footer-column">
        <h4 class="footer-section-title">Explore the System</h4>
        <ul class="footer-links">
          <li><a href="landing-page.php">Explore the Platform</a></li>
          <li><a href="landing-page.php#capabilities">System Capabilities</a></li>
          <li><a href="announcements.php">Public Service Updates</a></li>
          <li><a href="geo-map-landing.php">Geo-Mapping Dashboard</a></li>
        </ul>
      </div>
      <div class="footer-column">
        <h4 class="footer-section-title">About the Veterinary Office</h4>
        <ul class="footer-links">
          <li><a href="about.php">About DCVS</a></li>
          <li><a href="faq.php">Frequently Asked Questions</a></li>
          <li><a href="contact.php">Contact Us</a></li>
        </ul>
      </div>
      <div class="footer-column">
        <h4 class="footer-section-title">Services &amp; Insights</h4>
        <ul class="footer-links">
          <li><a href="adoption.php">Pet Adoption Services</a></li>
          <li><a href="Knowledge-Hub.php">Knowledge Hub</a></li>
          <li><a href="Philippine-Initiatives.php">Philippine Initiatives</a></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-bottom-content">
      <div class="footer-bottom-socials">
        <a href="#" class="social-link-small">f</a>
        <a href="#" class="social-link-small">G</a>
        <a href="#" class="social-link-small">y</a>
      </div>
      <p>&copy; 2025 Dasmariñas City Veterinary Services. All rights reserved. | Compliance matters here.</p>
    </div>
  </div>
</footer>

<script src="dist/mapcn.umd.cjs?v=<?php echo (int)filemtime(__DIR__ . '/../../dist/mapcn.umd.cjs'); ?>"></script>
<script src="assets/js/map-basemap-toggle.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/map-basemap-toggle.js'); ?>"></script>
<script src="assets/js/public-report-map-pins.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/public-report-map-pins.js'); ?>"></script>
<script>
// Mobile nav toggle
var navbar = document.querySelector('.navbar');
var navLinks = document.querySelector('.nav-links');
var navToggle = document.querySelector('.nav-toggle');

navToggle?.addEventListener('click', function() {
  navLinks?.classList.toggle('open');
  navToggle.classList.toggle('open');
  navToggle.setAttribute('aria-expanded', navLinks?.classList.contains('open') ? 'true' : 'false');
});

// Close nav on link click (mobile) — skip dropdown toggles
navLinks?.addEventListener('click', function(e) {
  if (e.target instanceof HTMLAnchorElement && !e.target.classList.contains('dropdown-toggle') && navLinks.classList.contains('open')) {
    navLinks.classList.remove('open');
    navToggle?.classList.remove('open');
    navToggle?.setAttribute('aria-expanded', 'false');
  }
});

// Dropdown toggle on mobile
document.querySelectorAll('.dropdown-toggle').forEach(function(t) {
  t.addEventListener('click', function(e) {
    var li = this.closest('.nav-item');
    if (li && window.innerWidth < 1100) {
      e.preventDefault();
      li.classList.toggle('open');
    }
  });
});
document.addEventListener('click', function(e) {
  if (!e.target.closest('.nav-item')) {
    document.querySelectorAll('.nav-item.open').forEach(function(i) { i.classList.remove('open'); });
  }
});

// Nav scroll state
var handleScrollState = function() {
  if (!navbar) return;
  navbar.classList.toggle('nav-scrolled', window.scrollY > 24);
};
window.addEventListener('scroll', handleScrollState, { passive: true });
handleScrollState();

// ── Table Pagination ──
(function() {
  var PER_PAGE = 25;
  var tbody = document.getElementById('bgyTableBody');
  var pagination = document.getElementById('tablePagination');
  if (!tbody || !pagination) return;
  var rows = Array.from(tbody.querySelectorAll('tr'));
  var totalRows = rows.length;
  var totalPages = Math.ceil(totalRows / PER_PAGE);
  var currentPage = 1;
  var filteredRows = rows;

  function renderTable() {
    var start = (currentPage - 1) * PER_PAGE;
    var end = start + PER_PAGE;
    rows.forEach(function(row) { row.style.display = 'none'; });
    filteredRows.slice(start, end).forEach(function(row) { row.style.display = ''; });
    renderPagination();
  }

  function renderPagination() {
    var total = Math.ceil(filteredRows.length / PER_PAGE);
    if (total <= 1) { pagination.innerHTML = ''; return; }
    var html = '';
    html += '<button onclick="window.pageGo(' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '>Prev</button>';
    html += '<span class="page-info">Page ' + currentPage + ' of ' + total + '</span>';
    html += '<button onclick="window.pageGo(' + (currentPage + 1) + ')"' + (currentPage >= total ? ' disabled' : '') + '>Next</button>';
    pagination.innerHTML = html;
  }

  window.pageGo = function(page) {
    var total = Math.ceil(filteredRows.length / PER_PAGE);
    if (page < 1 || page > total) return;
    currentPage = page;
    renderTable();
  };

  window.onTableSearch = function(val) {
    val = (val || '').toLowerCase().trim();
    filteredRows = val ? rows.filter(function(r) {
      return r.cells[0].textContent.toLowerCase().includes(val);
    }) : rows;
    currentPage = 1;
    renderTable();
  };

  renderTable();
})();

// ── Barangay Labels (same as dashboard-staff-geo) ──
document.addEventListener('DOMContentLoaded', function() {
  var mapEl = document.getElementById('geoMap');
  if (!mapEl) return;
  function addGeoLabels(map) {
    if (!map || !map.getSource('barangays')) return false;
    try {
      if (map.getLayer('barangay-label')) {
        map.setLayoutProperty('barangay-label', 'visibility', 'none');
      }
      if (!map.getLayer('geo-brgy-label')) {
        map.addLayer({
          id: 'geo-brgy-label', type: 'symbol', source: 'barangays',
          layout: {
            'text-field': ['get', 'name'],
            'text-size': 11, 'text-anchor': 'center', 'text-allow-overlap': false
          },
          paint: { 'text-color': '#1e293b', 'text-halo-color': '#ffffff', 'text-halo-width': 2 }
        });
      }
      return true;
    } catch (e) { return false; }
  }
  function tryStyle(map) {
    if (addGeoLabels(map)) {
      map.on('sourcedata', function() { addGeoLabels(map); });
      map.on('styledata', function() { addGeoLabels(map); });
      return true;
    }
    return false;
  }
  var tries = 0;
  var iv = setInterval(function() {
    if (tryStyle(window.MapCN && window.MapCN.map) || tries++ > 100) clearInterval(iv);
  }, 200);
  mapEl.addEventListener('mapcn:load', function(e) {
    var map = e.detail && e.detail.map;
    if (map && tryStyle(map)) clearInterval(iv);
  });
});
</script>
</body>
</html>
