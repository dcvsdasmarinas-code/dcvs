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

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/staff-app-base.php';
$appBaseUrl = dcvsStaffAppBaseUrl();
require_once __DIR__ . '/../../includes/reports/location.php';

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

$reportPinsEnabled = false;
loadDcvsEnv();
$reportRoutingEnabled = trim((string)env('DCVS_ROUTING_API_URL', '')) !== '';
$mapTilerApiKey = trim((string)env('DCVS_MAPTILER_API_KEY', ''));
if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') === '1') {
    $reportPinFeatures = dcvsLoadOfficialBarangayPolygons(
        __DIR__ . '/../../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        __DIR__ . '/../../geo-map/barangays-boundary.json'
    );
    $reportPinsEnabled = dcvsReportPinFeatureEnabled($conn, $reportPinFeatures);
}

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
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>" />

  <!-- MapCN bundle -->
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/dist/mapcn.css', ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/geo-map/map.css?v=' . (int)filemtime(__DIR__ . '/../../geo-map/map.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/geo-drawer.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/geo-drawer.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/dist/mapcn.umd.cjs?v=' . (int)filemtime(__DIR__ . '/../../dist/mapcn.umd.cjs'), ENT_QUOTES, 'UTF-8'); ?>"></script>

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
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Inter',sans-serif; display:flex; align-items:center; gap:10px; }
    .page-title i { color:#0E8131; }
    .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }

    .geo-full-page { margin: -1.75rem -2rem; }
    .geo-full-page .geo-map-section { height: calc(100vh - var(--topbar-h, 64px)); margin-bottom: 0; }
    .geo-full-page .geo-map-card { border-radius: 0; height: 100%; display: flex; flex-direction: column; }
    .geo-full-page #geoMap { height: auto !important; flex: 1; min-height: 0; }

    @media (max-width: 768px) {
      body.staff-theme.sb2-active .staff-main { box-sizing: border-box; width: 100%; min-width: 0; margin-left: 0; padding-inline: 0 !important; }
      .geo-full-page { box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0; margin: 0; }
    }

    /* Buttons */
    .sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 16px;border-radius:6px;border:1px solid transparent;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,.05);transition:background-color .15s ease,border-color .15s ease,color .15s ease;}
    .sd-btn:hover{background:#f4f4f5;}
    .sd-btn-primary{background:#18181b;color:#fafafa;border-color:#18181b;}
    .sd-btn-primary:hover{background:#27272a;}
    .sd-btn-outline{background:#ffffff;border-color:#e4e4e7;color:#18181b;}
    .sd-btn-outline:hover{background:#f4f4f5;}
    .geo-search-status{flex-basis:100%;min-height:0;color:#64748b;font-size:12px;}
    .geo-search-status:empty{display:none;}
    .geo-search-wrap{position:relative;z-index:90;}
    .geo-search-results{position:absolute;top:calc(100% + 4px);right:0;left:0;z-index:100;max-height:260px;overflow-y:auto;border:1px solid #e4e4e7;border-radius:6px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.14);}
    .geo-search-results[hidden]{display:none;}
    .geo-search-option{display:block;width:100%;padding:9px 12px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;color:#18181b;text-align:left;font:400 13px Inter,'Segoe UI',sans-serif;cursor:pointer;}
    .geo-search-option:last-child{border-bottom:0;}
    .geo-search-option:hover,.geo-search-option[aria-selected="true"]{background:#ecfdf3;color:#166534;}
  </style>
</head>
<body class="staff-theme">

<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>

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
            <input type="search" id="geoSearch" placeholder="Search barangay..." role="combobox" aria-autocomplete="list" aria-controls="geoSearchResults" aria-expanded="false" aria-label="Search barangays and press Enter">
            <div class="geo-search-results" id="geoSearchResults" role="listbox" aria-label="Barangay search results" hidden></div>
          </span>
          <span id="geoSearchStatus" class="geo-search-status" role="status" aria-live="polite"></span>
        </div>
        <!-- Map container -->
        <div id="geoMap" data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":true,"geojsonUrl":"geo-map/barangays-public.geojson.php","style":"https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json"}' data-map-basemap-toggle="true" data-city-boundary="true" data-map-risk-overlay="true" data-map-landmarks-session="DCVS_STAFF_SESSION" data-report-route-enabled="<?php echo $reportRoutingEnabled ? '1' : '0'; ?>" <?php if ($reportPinsEnabled): ?>data-report-pins-session="DCVS_STAFF_SESSION"<?php endif; ?> <?php if ($mapTilerApiKey !== ''): ?>data-maptiler-key="<?php echo htmlspecialchars($mapTilerApiKey, ENT_QUOTES, 'UTF-8'); ?>" data-preserve-map-view="true"<?php endif; ?> style="width:100%;position:relative;">
          <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading map...</div>
          <div class="geo-basemap-status" data-basemap-status role="status" aria-live="polite"></div>
        </div>
        <!-- Hidden panel kept for mapcn bundle compatibility -->
        <div class="geo-info-panel" id="geoInfoPanel" style="display:none !important;">
          <div class="geo-panel-header">
            <h3><span id="geoPanelName">Barangay</span></h3>
            <button class="geo-panel-close" onclick="MapCN.barangayOverlay.closePanel()">&times;</button>
          </div>
          <div class="geo-panel-body">
            <span class="geo-panel-risk-badge" id="geoPanelRisk">Low</span>
            <div class="geo-panel-stat"><span class="geo-panel-stat-label">Confirmed</span><span class="geo-panel-stat-value" id="geoPanelConfirmed">0</span></div>
            <div class="geo-panel-stat"><span class="geo-panel-stat-label">Pending</span><span class="geo-panel-stat-value" id="geoPanelPending">0</span></div>
            <div class="geo-panel-stat"><span class="geo-panel-stat-label">Resolved</span><span class="geo-panel-stat-value" id="geoPanelResolved">0</span></div>
            <div class="geo-panel-stat"><span class="geo-panel-stat-label">Updated</span><span class="geo-panel-stat-value" id="geoPanelUpdated">--</span></div>
          </div>
        </div>
        <?php include __DIR__ . '/../../includes/geo-drawer.php'; ?>
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
  var geoSearch = document.getElementById('geoSearch');
  var geoSearchStatus = document.getElementById('geoSearchStatus');
  var geoSearchResults = document.getElementById('geoSearchResults');
  var searchSuggestions = [];
  var activeSuggestionIndex = -1;
  var pendingSearchName = null;

  function closeSearchSuggestions() {
    geoSearchResults.hidden = true;
    geoSearchResults.replaceChildren();
    geoSearch.setAttribute('aria-expanded', 'false');
    activeSuggestionIndex = -1;
  }

  function selectBarangay(name) {
    geoSearch.value = name;
    searchSuggestions = [];
    activeSuggestionIndex = -1;
    var result = MapCN.barangayOverlay.search(name);
    closeSearchSuggestions();
    if (result === null) {
      pendingSearchName = name;
      geoSearchStatus.textContent = 'Map data is loading. Your search will run when it is ready.';
    } else if (result) {
      pendingSearchName = null;
      geoSearchStatus.textContent = 'Showing ' + name + ' on the map.';
    } else {
      pendingSearchName = null;
      geoSearchStatus.textContent = 'No barangay found matching "' + name + '".';
    }
  }

  function renderSearchSuggestions() {
    var query = geoSearch.value.trim();
    geoSearchStatus.textContent = '';
    geoSearchResults.replaceChildren();
    activeSuggestionIndex = -1;

    if (!query) {
      searchSuggestions = [];
      closeSearchSuggestions();
      MapCN.barangayOverlay.search('');
      return;
    }

    searchSuggestions = MapCN.barangayOverlay.suggest(query);
    if (searchSuggestions === null) {
      geoSearchStatus.textContent = 'Loading barangay list...';
      closeSearchSuggestions();
      return;
    }

    if (!searchSuggestions.length) {
      geoSearchStatus.textContent = 'No close barangay match found.';
      closeSearchSuggestions();
      return;
    }

    searchSuggestions.forEach(function(suggestion, index) {
      var option = document.createElement('button');
      option.type = 'button';
      option.className = 'geo-search-option';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      option.textContent = suggestion.name;
      option.addEventListener('click', function() {
        selectBarangay(suggestion.name);
      });
      geoSearchResults.appendChild(option);
    });
    geoSearchResults.hidden = false;
    geoSearch.setAttribute('aria-expanded', 'true');
  }

  if (geoSearch) {
    geoSearch.addEventListener('input', function() {
      pendingSearchName = null;
      MapCN.barangayOverlay.cancelPendingSearch();
      renderSearchSuggestions();
    });
    geoSearch.addEventListener('keydown', function(event) {
      var options = geoSearchResults.querySelectorAll('.geo-search-option');
      if (event.key === 'ArrowDown' && options.length) {
        event.preventDefault();
        activeSuggestionIndex = (activeSuggestionIndex + 1) % options.length;
      } else if (event.key === 'ArrowUp' && options.length) {
        event.preventDefault();
        activeSuggestionIndex = (activeSuggestionIndex - 1 + options.length) % options.length;
      } else if (event.key === 'Escape') {
        closeSearchSuggestions();
        return;
      } else if (event.key === 'Enter') {
        event.preventDefault();
        var query = geoSearch.value.trim();
        if (!query) {
          MapCN.barangayOverlay.search('');
          geoSearchStatus.textContent = 'Enter a barangay name to search.';
          closeSearchSuggestions();
          return;
        }
        if (!searchSuggestions || !searchSuggestions.length) renderSearchSuggestions();
        if (searchSuggestions && searchSuggestions.length) {
          var selected = searchSuggestions[Math.max(activeSuggestionIndex, 0)];
          selectBarangay(selected.name);
        } else {
          selectBarangay(query);
        }
        return;
      } else {
        return;
      }
      Array.prototype.forEach.call(options, function(option, index) {
        option.setAttribute('aria-selected', index === activeSuggestionIndex ? 'true' : 'false');
      });
    });
    document.addEventListener('click', function(event) {
      if (!event.target.closest('.geo-search-wrap')) closeSearchSuggestions();
    });
    document.addEventListener('dcvs:barangaysready', function() {
      renderSearchSuggestions();
      if (pendingSearchName) selectBarangay(pendingSearchName);
    });
  }

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
        map.fitBounds(bounds, { padding: 60, maxZoom: 16, duration: 800 });
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

/* ── Geo Drawer: intercept barangay clicks & populate right drawer ── */
(function() {
  var currentBrgyId = null;

  var riskConfig = {
    Green:  { dot: '#22c55e', text: '#166534', label: 'LOW RISK', desc: 'No confirmed cases in this barangay' },
    Yellow: { dot: '#eab308', text: '#854d0e', label: 'MEDIUM RISK', desc: 'Active cases detected in this barangay' },
    Orange: { dot: '#f97316', text: '#9a3412', label: 'HIGH RISK', desc: 'Multiple active cases require attention' },
    Red:    { dot: '#ef4444', text: '#991b1b', label: 'CRITICAL RISK', desc: 'Significant outbreak — immediate action needed' }
  };

  var typeLabels = {
    rabies: 'Rabies Reports',
    rescue: 'Rescue Reports',
    stray: 'Stray Reports',
    injured: 'Injured Reports',
    dead: 'Dead Reports'
  };

  var typeIcons = {
    rabies: '#dc2626',
    rescue: '#2563eb',
    stray: '#ea580c',
    injured: '#d97706',
    dead: '#6b7280'
  };

  function formatDate(dateStr) {
    if (!dateStr) return '--';
    var d = new Date(dateStr.replace(' ', 'T') + 'Z');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getUTCMonth()] + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear();
  }

  function formatDateTime(dateStr) {
    if (!dateStr) return '--';
    var d = new Date(dateStr.replace(' ', 'T') + 'Z');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var h = d.getUTCHours(), m = d.getUTCMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return months[d.getUTCMonth()] + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear() + ' · ' + h + ':' + String(m).padStart(2,'0') + ' ' + ampm;
  }

  window.openGeoDrawer = function(barangayId, props) {
    currentBrgyId = barangayId;
    var name = (props && props.name) || 'Unknown';
    var risk = (props && props.risk) || 'Green';
    var confirmed = (props && props.confirmed) || 0;
    var pending = (props && props.pending) || 0;
    var resolved = (props && props.resolved) || 0;
    var total = confirmed + pending + resolved;
    var rc = riskConfig[risk] || riskConfig.Green;

    document.getElementById('geoDrawerName').textContent = name;

    var dot = document.getElementById('geoDrawerRiskDot');
    dot.style.background = rc.dot;
    var rtxt = document.getElementById('geoDrawerRiskText');
    rtxt.textContent = rc.label;
    rtxt.style.color = rc.text;
    document.getElementById('geoDrawerRiskDesc').textContent = rc.desc;

    document.getElementById('geoDrawerConfirmed').textContent = confirmed;
    document.getElementById('geoDrawerPending').textContent = pending;
    document.getElementById('geoDrawerResolved').textContent = resolved;
    document.getElementById('geoDrawerTotal').textContent = total;

    document.getElementById('geoDrawerViewAll').href = 'dashboard-staff.php#reports-all';
    document.getElementById('geoDrawerUpdated').textContent = formatDateTime(props && props.lastUpdated);

    document.getElementById('geoDrawerBreakdown').innerHTML = '<div style="font-size:13px;color:#a1a1aa;padding:8px 0;">Loading...</div>';
    document.getElementById('geoDrawerRecentWrap').style.display = 'none';
    document.getElementById('geoDrawerNoRecent').style.display = 'none';

    document.getElementById('geoDrawerOverlay').classList.add('open');
    document.getElementById('geoDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('geo-map/barangay-detail.php?barangay_id=' + barangayId + '&sess=DCVS_STAFF_SESSION')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var bkHtml = '';
        if (data.breakdown && data.breakdown.length) {
          data.breakdown.forEach(function(b) {
            var lbl = typeLabels[b.type] || (b.type.charAt(0).toUpperCase() + b.type.slice(1) + ' Reports');
            var color = typeIcons[b.type] || '#71717a';
            bkHtml += '<div class="geo-activity-row"><span class="geo-activity-name"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' + color + ';margin-right:8px;vertical-align:middle;"></span>' + lbl + '</span><span class="geo-activity-count">' + b.count + '</span></div>';
          });
        } else {
          bkHtml = '<div style="font-size:13px;color:#a1a1aa;padding:8px 0;">No reports</div>';
        }
        document.getElementById('geoDrawerBreakdown').innerHTML = bkHtml;

        if (data.recent_case) {
          var rc2 = data.recent_case;
          document.getElementById('geoDrawerRecentWrap').style.display = 'block';
          document.getElementById('geoDrawerNoRecent').style.display = 'none';
          var typeLabel = rc2.type === 'rabies' ? 'Suspected Rabies' : rc2.type.charAt(0).toUpperCase() + rc2.type.slice(1);
          var urg = rc2.urgency || 'medium';
          var urgStyles = {
            critical: { bg: '#fef2f2', color: '#991b1b', border: '#fecaca' },
            high:     { bg: '#fff7ed', color: '#9a3412', border: '#fed7aa' },
            medium:   { bg: '#eff6ff', color: '#1e40af', border: '#bfdbfe' },
            low:      { bg: '#f9fafb', color: '#374151', border: '#e5e7eb' }
          };
          var us = urgStyles[urg] || urgStyles.medium;
          var urgLabel = urg.charAt(0).toUpperCase() + urg.slice(1) + ' Risk';
          document.getElementById('geoDrawerRecent').innerHTML =
            '<div class="geo-recent-id">' + rc2.ref_code + '</div>' +
            '<div class="geo-recent-type">' + typeLabel + '</div>' +
            '<div class="geo-recent-meta">' +
              '<span class="geo-recent-urgency" style="background:' + us.bg + ';color:' + us.color + ';border:1px solid ' + us.border + ';">' + urgLabel + '</span>' +
              '<span class="geo-recent-date">' + formatDate(rc2.date) + '</span>' +
            '</div>' +
            '<a class="geo-recent-action" href="dashboard-staff.php#reports-all">View Report <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>';
        } else {
          document.getElementById('geoDrawerRecentWrap').style.display = 'none';
          document.getElementById('geoDrawerNoRecent').style.display = 'block';
        }
      })
      .catch(function(err) {
        console.error('Barangay detail fetch error:', err);
        document.getElementById('geoDrawerBreakdown').innerHTML = '<div style="font-size:13px;color:#dc2626;padding:8px 0;">Failed to load data</div>';
      });
  };

  window.closeGeoDrawer = function() {
    document.getElementById('geoDrawerOverlay').classList.remove('open');
    document.getElementById('geoDrawer').classList.remove('open');
    document.body.style.overflow = '';
    currentBrgyId = null;
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('geoDrawer').classList.contains('open')) {
      closeGeoDrawer();
    }
  });

  /* Keep the legacy panel hidden; barangay selection opens the right drawer directly. */
  function setupGeoIntercept() {
    var panel = document.getElementById('geoInfoPanel');
    if (!panel) return false;

    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(m) {
        if (m.attributeName === 'class' && panel.classList.contains('open')) {
          panel.classList.remove('open');
        }
      });
    });
    observer.observe(panel, { attributes: true, attributeFilter: ['class'] });
    return true;
  }

  function setupBarangayDrawer(map) {
    if (!map || map.__dcvsBarangayDrawerClickBound) return;
    map.__dcvsBarangayDrawerClickBound = true;
    map.on('click', function(clickEvent) {
      if (!map.getLayer('barangay-fill')) return;
      var pinLayers = ['report-pins-points', 'report-pins-clusters'].filter(function(layerId) {
        return !!map.getLayer(layerId);
      });
      if (pinLayers.length && map.queryRenderedFeatures(clickEvent.point, { layers: pinLayers }).length) return;
      var features = map.queryRenderedFeatures(clickEvent.point, { layers: ['barangay-fill'] });
      var feature = features[0];
      if (feature && feature.properties) {
        openGeoDrawer(feature.properties.id, feature.properties);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function() {
    var mapElement = document.getElementById('geoMap');
    if (mapElement) {
      mapElement.addEventListener('mapcn:load', function(event) {
        setupBarangayDrawer(event.detail && event.detail.map);
      });
    }
    setupBarangayDrawer(window.MapCN && window.MapCN.map);
    if (!setupGeoIntercept()) {
      var tries = 0;
      var iv = setInterval(function() {
        if (setupGeoIntercept() || tries++ > 50) clearInterval(iv);
      }, 200);
    }
  });
})();
</script>
<?php if ($reportPinsEnabled): ?>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/report-map-pins.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/report-map-pins.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/map-landmarks.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/map-landmarks.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/map-basemap-toggle.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/map-basemap-toggle.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
