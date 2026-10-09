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
  <link rel="stylesheet" href="dist/mapcn.css">
  <link rel="stylesheet" href="geo-map/map.css?v=<?php echo (int)filemtime(__DIR__ . '/../../geo-map/map.css'); ?>">
  <link rel="stylesheet" href="assets/css/geo-drawer.css?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/css/geo-drawer.css'); ?>">
  <script src="dist/mapcn.umd.cjs"></script>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: #f8fafc; color: #0f172a; font-family: Inter, "Segoe UI", sans-serif; }
    .geo-page { min-height: 100vh; margin-left: var(--sidebar-w, 256px); transition: margin-left .2s ease; }
    .geo-topbar { position: sticky; top: 0; z-index: 30; display: flex; align-items: center; justify-content: space-between; height: 64px; padding: 0 24px; border-bottom: 1px solid #e2e8f0; background: #fff; }
    .geo-topbar-left { display: flex; align-items: center; gap: 14px; }
    .geo-topbar-toggle { display: none; width: 36px; height: 36px; align-items: center; justify-content: center; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #334155; }
    .geo-breadcrumb { display: flex; align-items: center; gap: 9px; font-size: 14px; }
    .geo-breadcrumb a { color: #166534; text-decoration: none; font-weight: 600; }
    .geo-breadcrumb span { color: #64748b; }
    .geo-topbar-user { color: #475569; font-size: 13px; font-weight: 600; }
    .geo-map-stage { height: calc(100vh - 64px); min-height: 420px; display: flex; flex-direction: column; }
    .geo-map-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #fff; }
    .geo-map-heading h1 { margin: 0; font-size: 17px; font-weight: 700; }
    .geo-map-heading p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
    .geo-map-back { color: #166534; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; }
    #geoMap { position: relative; flex: 1; width: 100%; min-height: 0; }
    .geo-map-error { position: absolute; z-index: 10; bottom: 14px; left: 50%; max-width: calc(100% - 28px); padding: 9px 12px; border: 1px solid #fecaca; border-radius: 7px; background: #fff; color: #991b1b; font-size: 13px; transform: translateX(-50%); }
    .geo-map-error[hidden] { display: none; }
    @media (max-width: 768px) {
      .geo-page { margin-left: 0; }
      .geo-topbar { padding: 0 14px; }
      .geo-topbar-toggle { display: inline-flex; }
      .geo-map-heading { padding: 12px 14px; }
      .geo-map-stage { height: calc(100dvh - 64px); }
      .geo-topbar-user { display: none; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../../includes/barangay-sidebar.php'; ?>
<main class="geo-page">
  <header class="geo-topbar">
    <div class="geo-topbar-left">
      <button class="geo-topbar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
      <div class="geo-breadcrumb">
        <a href="dashboard-barangay.php">Barangay Portal</a>
        <span aria-hidden="true">›</span>
        <span>Geo-Mapping</span>
      </div>
    </div>
    <span class="geo-topbar-user"><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> · Brgy. <?php echo htmlspecialchars($barangayName, ENT_QUOTES, 'UTF-8'); ?></span>
  </header>
  <section class="geo-map-stage" aria-label="Barangay incident map">
    <div class="geo-map-heading">
      <div>
        <h1>Geo-Mapping · <?php echo htmlspecialchars($barangayName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>View incident locations and select a report pin to see its details and route.</p>
      </div>
      <a class="geo-map-back" href="dashboard-barangay.php">Back to Overview</a>
    </div>
    <div id="geoMap" data-map-landmarks-session="DCVS_BARANGAY_SESSION" data-map-risk-overlay="true"
         data-mapcn='{"center":[120.968,14.306],"zoom":12,"barangayOverlay":true,"geojsonUrl":"geo-map/barangays-public.geojson.php","style":"https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json"}'
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
  </section>
</main>
<?php include __DIR__ . '/../../includes/geo-drawer.php'; ?>
<script>
(function() {
  var mapElement = document.getElementById('geoMap');
  var errorElement = document.getElementById('geo-map-error');
  mapElement.addEventListener('mapcn:load', function(event) {
    var map = event.detail && event.detail.map;
    if (!map) return;
    fetch('geo-map/barangays-public.geojson.php', { credentials: 'same-origin' })
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
        var source = map.getSource('barangays');
        if (source) source.setData({ type: 'FeatureCollection', features: [feature] });
        ['barangay-fill', 'barangay-outline', 'geo-brgy-label', 'barangay-label'].forEach(function(layerId) {
          if (map.getLayer(layerId)) {
            map.setFilter(layerId, ['==', ['to-number', ['get', 'id']], targetId]);
          }
        });
        mapElement.dispatchEvent(new CustomEvent('dcvs:barangaymapscope', { detail: { feature: feature } }));
        errorElement.hidden = true;
      })
      .catch(function(error) {
        console.error('Could not load assigned barangay map:', error);
        errorElement.textContent = 'Could not load your barangay boundary. Please refresh the page.';
        errorElement.hidden = false;
      });
  }, { once: true });

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
<script src="assets/js/barangay-geo-drawer.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/barangay-geo-drawer.js'); ?>"></script>
<?php if ($reportPinsEnabled): ?>
<script src="assets/js/report-map-pins.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/report-map-pins.js'); ?>"></script>
<?php endif; ?>
<script src="assets/js/map-landmarks.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/map-landmarks.js'); ?>"></script>
<script src="assets/js/map-basemap-toggle.js?v=<?php echo (int)filemtime(__DIR__ . '/../../assets/js/map-basemap-toggle.js'); ?>"></script>
</body>
</html>
