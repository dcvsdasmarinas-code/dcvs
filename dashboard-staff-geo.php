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

$roleCheck = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$roleCheck->bind_param("i", $_SESSION['user_id']);
$roleCheck->execute();
$roleResult = $roleCheck->get_result();
if ($roleRow = $roleResult->fetch_assoc()) {
    if ($roleRow['role'] !== $_SESSION['role']) {
        $_SESSION = array();
        session_destroy();
        header('Location: login-staff.php');
        exit;
    }
}
$roleCheck->close();

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Staff';
$staffEmail = $_SESSION['email'] ?? '';
$staffPhone = $_SESSION['phone'] ?? '';
$staffAvatar = $_SESSION['avatar_url'] ?? '';

$pageTitle = 'Geo Mapping';
$activePage = 'geo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Staff | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />

  <!-- MapCN bundle -->
  <link rel="stylesheet" href="dist/mapcn.css" />
  <link rel="stylesheet" href="geo-map/map.css">
  <script src="dist/mapcn.umd.js"></script>

  <style>
    :root {
      --bg-body: #f8fafc;
      --bg-card: #ffffff;
      --border: #e2e8f0;
      --text: #0f172a;
      --text-2: #475569;
      --text-3: #94a3b8;
      --shadow: 0 4px 24px rgba(0,0,0,0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--bg-body); color: var(--text); min-height: 100vh; display: flex; }
    .staff-layout { display: flex; width: 100%; min-height: 100vh; }
    .staff-main { flex: 1; margin-left: 80px; margin-top: var(--topbar-h, 64px); padding: 1.75rem 2rem; min-height: calc(100vh - var(--topbar-h, 64px)); }

    .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.75rem; }
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; display:flex; align-items:center; gap:10px; }
    .page-title i { color:#0E8131; }
    .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }

    .geo-full-page { margin: -1.75rem -2rem; }
    .geo-full-page .geo-map-section { height: calc(100vh - var(--topbar-h, 64px)); margin-bottom: 0; }
    .geo-full-page .geo-map-card { border-radius: 0; height: 100%; display: flex; flex-direction: column; }
    .geo-full-page #geoMap { height: auto !important; flex: 1; min-height: 0; }

    @media (max-width: 768px) {
      .staff-main { margin-left: 0; padding: 1rem; }
      .geo-full-page { margin: -1rem; }
    }
  </style>
</head>
<body class="staff-theme">

<?php include 'includes/staff-sidebar.php'; ?>

<div class="staff-main">
  <div class="geo-full-page">
    <!-- ===== Geo-Mapping Module ===== -->
    <div class="geo-map-section">
      <div class="geo-map-card">
        <!-- Summary bar -->
        <div class="geo-summary-bar">
          <span class="geo-summary-item"><i class="fas fa-certificate" style="color:#0E8131;"></i> Confirmed: <strong id="geoTotalConfirmed">0</strong></span>
          <span class="geo-summary-item"><i class="fas fa-map-marker-alt" style="color:#6266ea;"></i> Affected: <strong id="geoAffectedBgy">0 / 75</strong></span>
          <span class="geo-summary-item"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Highest Risk: <strong id="geoHighestRisk">--</strong></span>
          <span class="geo-summary-item"><i class="fas fa-sync-alt" style="color:#94a3b8;"></i> Updated: <strong id="geoLastRefresh">--</strong></span>
          <span class="geo-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="geoSearch" placeholder="Search barangay..." oninput="MapCN.barangayOverlay.search(this.value)">
          </span>
        </div>
        <!-- Map container -->
        <div id="geoMap" data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":true,"geojsonUrl":"geo-map/barangays-public.geojson.php"}' style="width:100%;position:relative;">
          <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading map...</div>
        </div>
        <!-- Slide-in Info Panel -->
        <div class="geo-info-panel" id="geoInfoPanel">
          <div class="geo-panel-header">
            <h3><span id="geoPanelName">Barangay</span></h3>
            <button class="geo-panel-close" onclick="MapCN.barangayOverlay.closePanel()">&times;</button>
          </div>
          <div class="geo-panel-body">
            <div style="margin-bottom:1rem;">
              <span class="geo-panel-risk-badge" id="geoPanelRisk" style="background:#dcfce7;color:#166534;">Low</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Confirmed Cases</span>
              <span class="geo-panel-stat-value" id="geoPanelConfirmed">0</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Pending Reports</span>
              <span class="geo-panel-stat-value" id="geoPanelPending">0</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Resolved</span>
              <span class="geo-panel-stat-value" id="geoPanelResolved">0</span>
            </div>
            <div class="geo-panel-stat">
              <span class="geo-panel-stat-label">Last Updated</span>
              <span class="geo-panel-stat-value" id="geoPanelUpdated">--</span>
            </div>
          </div>
        </div>
        <!-- Legend -->
        <div class="geo-legend">
          <span style="font-weight:600;color:#18181b;font-size:12px;">Risk Level:</span>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#22c55e;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">0 cases</span></div>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#eab308;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">1–5 cases</span></div>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#f97316;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">6–15 cases</span></div>
          <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#ef4444;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">16+ cases</span></div>
          <div style="margin-left:auto;">
            <button class="action-btn-sm blue" onclick="MapCN.barangayOverlay.refresh()"><i class="fas fa-sync-alt"></i> Refresh</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
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

document.addEventListener('DOMContentLoaded', function() {
  var q = new URLSearchParams(window.location.search).get('brgy');
  if (!q) return;
  var names = q.split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
  if (!names.length) return;
  var tries = 0;
  var iv = setInterval(function() {
    var map = window.MapCN && window.MapCN.map;
    if (map && map.getSource && map.getSource('barangays')) {
      clearInterval(iv);
      var data = map.getSource('barangays')._data;
      var feats = (data && data.features) || [];
      var ids = [];
      var first = null;
      feats.forEach(function (f) {
        var nm = ((f.properties && f.properties.name) || '').toLowerCase();
        if (names.indexOf(nm) !== -1) {
          ids.push(f.properties.id);
          if (!first) first = f;
        }
      });
      if (!ids.length) return;
      var inIds = ['match', ['get', 'id'], ids, true, false];
      map.setPaintProperty('barangay-fill', 'fill-opacity', ['case', inIds, 0.7, 0.2]);
      map.setPaintProperty('barangay-outline', 'line-width', ['case', inIds, ['interpolate', ['linear'], ['zoom'], 10, 2.5, 12, 4, 15, 7], ['interpolate', ['linear'], ['zoom'], 10, 0.5, 12, 1, 15, 2]]);
      map.setPaintProperty('barangay-outline', 'line-opacity', ['case', inIds, 1, 0.3]);
      if (first && first.geometry) {
        var bounds = bbFromFeature(first);
        map.fitBounds(bounds, { padding: 60, maxZoom: 13, duration: 800 });
      }
    } else if (tries++ > 150) {
      clearInterval(iv);
    }
  }, 200);

  function bbFromFeature(f) {
    var coords = (f.geometry && f.geometry.coordinates) || [];
    if (!coords.length) return [[120.95, 14.28], [120.99, 14.33]];
    if (f.geometry.type === 'Point') {
      var c = coords;
      return [[c[0] - 0.01, c[1] - 0.01], [c[0] + 0.01, c[1] + 0.01]];
    }
    var minLng = Infinity, minLat = Infinity, maxLng = -Infinity, maxLat = -Infinity;
    function walk(poly) {
      if (poly.length && typeof poly[0] === 'number') {
        if (poly[0] < minLng) minLng = poly[0];
        if (poly[0] > maxLng) maxLng = poly[0];
        if (poly[1] < minLat) minLat = poly[1];
        if (poly[1] > maxLat) maxLat = poly[1];
        return;
      }
      poly.forEach(walk);
    }
    walk(coords);
    return [[minLng, minLat], [maxLng, maxLat]];
  }
});
</script>
</body>
</html>
