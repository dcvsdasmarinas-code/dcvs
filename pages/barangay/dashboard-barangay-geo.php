<?php
session_name('DCVS_BARANGAY_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reports/location.php';
require_once __DIR__ . '/../../includes/barangay-app-base.php';
$barangayAppBaseUrl = dcvsBarangayAppBaseUrl();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'barangay') {
    header('Location: login-barangay.php');
    exit;
}

$roleCheck = $conn->prepare('SELECT role FROM users WHERE user_id = ?');
$roleCheck->bind_param('i', $_SESSION['user_id']);
$roleCheck->execute();
$roleRow = $roleCheck->get_result()->fetch_assoc();
$roleCheck->close();
if (!$roleRow || $roleRow['role'] !== 'barangay') {
    $_SESSION = [];
    session_destroy();
    header('Location: login-barangay.php');
    exit;
}

$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
if ($barangayId <= 0) {
    http_response_code(403);
    exit('Your account is not assigned to a barangay.');
}

$barangayName = 'Barangay';
$barangayQuery = $conn->prepare('SELECT barangay_name FROM barangays WHERE barangay_id = ?');
$barangayQuery->bind_param('i', $barangayId);
$barangayQuery->execute();
$barangayRow = $barangayQuery->get_result()->fetch_assoc();
$barangayQuery->close();
if ($barangayRow) $barangayName = $barangayRow['barangay_name'];

$avatarUrl = $_SESSION['avatar_url'] ?? '';
$unreadNotifs = 0;
$notificationQuery = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND archived_at IS NULL");
$notificationQuery->bind_param('i', $userId);
$notificationQuery->execute();
$unreadNotifs = (int)$notificationQuery->get_result()->fetch_row()[0];
$notificationQuery->close();

loadDcvsEnv();
$mapTilerApiKey = trim((string)env('DCVS_MAPTILER_API_KEY', ''));
$reportRoutingEnabled = trim((string)env('DCVS_ROUTING_API_URL', '')) !== '';
$reportPinsEnabled = false;
if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') === '1') {
    $reportPinFeatures = dcvsLoadOfficialBarangayPolygons(
        __DIR__ . '/../../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        __DIR__ . '/../../geo-map/barangays-boundary.json'
    );
    $reportPinsEnabled = dcvsReportPinFeatureEnabled($conn, $reportPinFeatures);
}

$fullName = $_SESSION['full_name'] ?? 'Barangay Officer';
$activePage = 'geo';
$barangaySidebarStylesheetInHead = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Geo-Mapping — Barangay <?php echo htmlspecialchars($barangayName, ENT_QUOTES, 'UTF-8'); ?> | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/css/notification.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/notification.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/dist/mapcn.css', ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/geo-map/map.css?v=' . (int)filemtime(__DIR__ . '/../../geo-map/map.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/css/geo-drawer.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/geo-drawer.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/css/sb2.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/sb2.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/dist/mapcn.umd.cjs?v=' . (int)filemtime(__DIR__ . '/../../dist/mapcn.umd.cjs'), ENT_QUOTES, 'UTF-8'); ?>"></script>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: #f8fafc; color: #0f172a; font-family: Inter, "Segoe UI", sans-serif; }
    .app-wrapper { min-height: 100vh; min-width: 0; display: flex; flex-direction: column; background: #fff; }
    body.sb2-active .app-wrapper { padding-left: var(--sidebar-w); transition: padding-left .2s ease; }
    .topbar { position: sticky; top: 0; z-index: 40; display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 70px; padding: 0 24px; border-bottom: 1px solid #e4e4e7; background: #fff; }
    body.sb2-active .app-wrapper .topbar { left: auto !important; }
    .topbar-left, .topbar-right, .topbar-breadcrumb, .user-profile { display: flex; align-items: center; }
    .topbar-left { gap: 16px; min-width: 0; }
    .topbar-right { flex: 0 0 auto; gap: 14px; }
    .topbar-breadcrumb { gap: 8px; min-width: 0; color: #94a3b8; font-size: 13px; }
    .breadcrumb-root, .breadcrumb-current { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .breadcrumb-root { color: #0b4d26; font-weight: 600; }
    .breadcrumb-current { color: #0f172a; font-weight: 600; }
    .breadcrumb-sep { font-size: 10px; }
    .topbar-toggle { display: none; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid #e4e4e7; border-radius: 6px; background: #fff; color: #18181b; cursor: pointer; flex-shrink: 0; }
    .notif-dropdown { position: relative; }
    .notif-btn { position: relative; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 0; border-radius: 6px; background: transparent; color: #475569; cursor: pointer; }
    .notif-badge { position: absolute; top: 0; right: 0; min-width: 18px; height: 18px; padding: 0 4px; display: flex; align-items: center; justify-content: center; border-radius: 9px; background: #dc2626; color: #fff; font-size: 10px; font-weight: 700; }
    .user-profile { gap: 10px; }
    .user-avatar { width: 34px; height: 34px; display: grid; place-items: center; overflow: hidden; border: 1px solid #b8dec9; border-radius: 50%; background: #d5ece0; color: #0e8131; }
    .user-name { display: block; color: #18181b; font-size: 13px; font-weight: 600; line-height: 1.3; }
    .user-role { display: block; color: #71717a; font-size: 12px; }
    .geo-page { flex: 1; min-height: 0; min-width: 0; }
    .geo-map-stage { height: calc(100vh - 70px); min-height: 420px; display: flex; flex-direction: column; }
    #geoMap { position: relative; flex: 1; width: 100%; height: auto; min-height: 0; }
    .geo-map-error { position: absolute; z-index: 10; bottom: 14px; left: 50%; max-width: calc(100% - 28px); padding: 9px 12px; border: 1px solid #fecaca; border-radius: 7px; background: #fff; color: #991b1b; font-size: 13px; transform: translateX(-50%); }
    .geo-map-error[hidden] { display: none; }
    .geo-map-stage .geo-summary-bar { flex: 0 0 auto; }
    .geo-map-stage .geo-legend { flex: 0 0 auto; }
    .geo-map-refresh { min-height: 32px; padding: 0 11px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #334155; font: 500 12px Inter, "Segoe UI", sans-serif; cursor: pointer; }
    .geo-map-refresh:hover { background: #f8fafc; }
    @media (max-width: 768px) {
      body.sb2-active .app-wrapper { padding-left: 0; }
      .topbar-toggle { display: inline-flex; }
      .geo-map-stage { height: calc(100dvh - 70px); min-height: 320px; }
    }
    @media (max-width: 640px) {
      .topbar { height: 60px; padding: 0 14px; }
      .topbar-right { gap: 6px; }
      .user-meta { display: none; }
      .notif-panel { position: fixed; top: 60px; right: 8px; width: min(380px, calc(100vw - 16px)); }
      .geo-map-stage { height: calc(100dvh - 60px); }
    }
    @media (max-width: 480px) {
      .topbar { gap: 8px; padding: 0 10px; }
      .topbar-left { gap: 8px; }
      .topbar-breadcrumb { gap: 6px; font-size: 12px; }
    }
  </style>
</head>
<body class="sb2-active">
<?php include __DIR__ . '/../../includes/barangay-sidebar.php'; ?>
<div class="app-wrapper">
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Barangay Portal</span>
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
        <span class="breadcrumb-current">Geo-Mapping</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="notif-dropdown" id="notifDropdown">
        <button class="notif-btn" id="notif-btn" type="button" title="<?php echo $unreadNotifs; ?> unread notifications" aria-label="<?php echo $unreadNotifs; ?> unread notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span class="notif-badge" id="notifBadge" data-realtime="notif-badge" style="<?php echo $unreadNotifs > 0 ? '' : 'display:none;'; ?>"><?php echo $unreadNotifs > 9 ? '9+' : $unreadNotifs; ?></span>
        </button>
        <div class="notif-panel" id="notifPanel" data-notification-panel data-portal="barangay"
          data-csrf="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
          data-action-url="includes/staff-notification-action.php?portal=barangay"
          data-load-url="get-notifications.php?limit=30" data-app-base-url="">
          <div class="notif-panel-header">
            <h4>Notifications</h4>
            <button class="notif-mark-all" type="button" <?php echo $unreadNotifs === 0 ? 'disabled' : ''; ?>><i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read</button>
          </div>
          <div class="notif-tabs" role="tablist" aria-label="Notification filters">
            <button class="notif-tab active" type="button" role="tab" aria-selected="true" data-notif-tab="all">All</button>
            <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="inbox">Inbox<span class="notif-tab-count" data-tab-count="inbox"><?php echo $unreadNotifs > 0 ? ($unreadNotifs > 99 ? '99+' : $unreadNotifs) : ''; ?></span></button>
            <button class="notif-tab" type="button" role="tab" aria-selected="false" data-notif-tab="archived">Archived</button>
          </div>
          <div class="notif-feedback" role="status" aria-live="polite"></div>
          <div class="notif-panel-body" id="notifList"><div class="notif-empty">Loading notifications…</div></div>
        </div>
      </div>
      <div class="user-profile">
        <div class="user-avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <i class="fas fa-user" aria-hidden="true"></i>
          <?php endif; ?>
        </div>
        <div class="user-meta"><span class="user-name"><?php echo htmlspecialchars($barangayName, ENT_QUOTES, 'UTF-8'); ?></span><span class="user-role">Barangay Officer</span></div>
      </div>
    </div>
  </header>
  <main class="geo-page">
  <section class="geo-map-stage" aria-label="Barangay incident map">
    <div class="geo-summary-bar">
      <span class="geo-summary-item"><i class="fas fa-certificate" style="color:#0E8131;"></i> Confirmed: <strong id="geoBarangayConfirmed">0</strong></span>
      <span class="geo-summary-item"><i class="fas fa-clock" style="color:#eab308;"></i> Pending: <strong id="geoBarangayPending">0</strong></span>
      <span class="geo-summary-item"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Risk: <strong id="geoBarangayRisk">--</strong></span>
      <span class="geo-summary-item"><i class="fas fa-sync-alt" style="color:#94a3b8;"></i> Updated: <strong id="geoBarangayUpdated">--</strong></span>
    </div>
    <div id="geoMap" data-map-landmarks-session="DCVS_BARANGAY_SESSION" data-map-risk-overlay="true"
         data-mapcn='<?php echo htmlspecialchars(json_encode(["center" => [120.968, 14.306], "zoom" => 12, "barangayOverlay" => true, "geojsonUrl" => $barangayAppBaseUrl . "/geo-map/barangays-public.geojson.php", "style" => "https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json"], JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8"); ?>'
         data-map-scope="barangay"
         data-map-barangay-id="<?php echo $barangayId; ?>"
         data-map-basemap-toggle="true"
         data-report-route-enabled="<?php echo $reportRoutingEnabled ? '1' : '0'; ?>"
         <?php if ($reportPinsEnabled): ?>data-report-pins-session="DCVS_BARANGAY_SESSION"<?php endif; ?>
         <?php if ($mapTilerApiKey !== ''): ?>data-maptiler-key="<?php echo htmlspecialchars($mapTilerApiKey, ENT_QUOTES, 'UTF-8'); ?>" data-preserve-map-view="true"<?php endif; ?>>
      <div class="mapcn-loading"><i class="fas fa-spinner"></i> Loading map...</div>
      <div class="geo-basemap-status" data-basemap-status role="status" aria-live="polite"></div>
      <div class="geo-map-error" id="geo-map-error" role="status" <?php if (!isset($_GET['report_id']) || $reportPinsEnabled): ?>hidden<?php endif; ?>>
        <?php if (isset($_GET['report_id']) && !$reportPinsEnabled): ?>Report locations are currently unavailable.<?php endif; ?>
      </div>
    </div>
    <div class="geo-legend">
      <span style="font-weight:600;color:#18181b;font-size:12px;">Risk Level:</span>
      <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#22c55e;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">0 cases</span></div>
      <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#eab308;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">1–5 cases</span></div>
      <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#f97316;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">6–15 cases</span></div>
      <div class="geo-legend-item"><span class="geo-legend-swatch" style="background:#ef4444;border:1px solid rgba(255,255,255,0.8);"></span><span class="geo-legend-label">16+ cases</span></div>
      <div style="margin-left:auto;">
        <button class="geo-map-refresh" id="geoMapRefresh" type="button"><i class="fas fa-sync-alt"></i> Refresh</button>
      </div>
    </div>
  </section>
</main>
</div>
<?php include __DIR__ . '/../../includes/geo-drawer.php'; ?>
<script>
(function() {
  var mapElement = document.getElementById('geoMap');
  var errorElement = document.getElementById('geo-map-error');
  var geoJsonUrl = <?php echo json_encode($barangayAppBaseUrl . '/geo-map/barangays-public.geojson.php', JSON_UNESCAPED_SLASHES); ?>;
  var refreshButton = document.getElementById('geoMapRefresh');
  var activeMap = null;

  function updateBarangaySummary(properties) {
    document.getElementById('geoBarangayConfirmed').textContent = properties.confirmed || 0;
    document.getElementById('geoBarangayPending').textContent = properties.pending || 0;
    document.getElementById('geoBarangayRisk').textContent = properties.risk || '--';
    document.getElementById('geoBarangayUpdated').textContent = properties.lastUpdated || '--';
  }

  function loadAssignedBarangaySummary() {
    return fetch(geoJsonUrl, { credentials: 'same-origin' })
      .then(function(response) {
        if (!response.ok) throw new Error('Barangay map request failed with HTTP ' + response.status + '.');
        return response.json();
      })
      .then(function(data) {
        var features = data && Array.isArray(data.features) ? data.features : [];
        var targetId = Number(mapElement.getAttribute('data-map-barangay-id'));
        var feature = features.find(function(item) {
          return Number(item.properties && item.properties.id) === targetId;
        });
        if (!feature) throw new Error('The assigned barangay boundary was not found.');
        updateBarangaySummary(feature.properties || {});
        return feature;
      });
  }

  function applyAssignedBoundary(map, feature) {
    var source = map.getSource('barangays');
    if (source) source.setData({ type: 'FeatureCollection', features: [feature] });
    var targetId = Number(mapElement.getAttribute('data-map-barangay-id'));
    ['barangay-fill', 'barangay-outline', 'geo-brgy-label', 'barangay-label'].forEach(function(layerId) {
      if (map.getLayer(layerId)) {
        map.setFilter(layerId, ['==', ['to-number', ['get', 'id']], targetId]);
      }
    });
    mapElement.dispatchEvent(new CustomEvent('dcvs:barangaymapscope', { detail: { feature: feature } }));
  }

  mapElement.addEventListener('mapcn:load', function(event) {
    var map = event.detail && event.detail.map;
    if (!map) return;
    activeMap = map;
    loadAssignedBarangaySummary()
      .then(function(feature) {
        applyAssignedBoundary(map, feature);
        errorElement.hidden = true;
      })
      .catch(function(error) {
        console.error('Could not load assigned barangay map:', error);
        errorElement.textContent = 'Could not load your barangay boundary. Please refresh the page.';
        errorElement.hidden = false;
      });
  }, { once: true });

  refreshButton.addEventListener('click', function() {
    refreshButton.disabled = true;
    loadAssignedBarangaySummary()
      .then(function(feature) {
        if (activeMap) applyAssignedBoundary(activeMap, feature);
        errorElement.hidden = true;
      })
      .catch(function(error) {
        console.error('Could not refresh assigned barangay map:', error);
        errorElement.textContent = 'Could not refresh your barangay data. Please try again.';
        errorElement.hidden = false;
      })
      .finally(function() {
        refreshButton.disabled = false;
      });
  });

  mapElement.addEventListener('dcvs:reportpinsready', function(event) {
    var reportId = new URLSearchParams(window.location.search).get('report_id');
    if (!reportId) return;
    if (event.detail && event.detail.error) {
      errorElement.textContent = 'Could not load report locations. Please refresh the page.';
      errorElement.hidden = false;
      return;
    }
    mapElement.dispatchEvent(new CustomEvent('dcvs:viewreportlocation', {
      detail: { reportId: reportId }
    }));
  }, { once: true });
})();
</script>
<script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/js/barangay-geo-drawer.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/barangay-geo-drawer.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($reportPinsEnabled): ?>
<script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/js/report-map-pins.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/report-map-pins.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
<script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/js/map-landmarks.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/map-landmarks.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/js/map-basemap-toggle.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/map-basemap-toggle.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($barangayAppBaseUrl . '/assets/js/notification-panel.js?v=' . (int)filemtime(__DIR__ . '/../../assets/js/notification-panel.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
