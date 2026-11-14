<?php
/** Return active mapped landmarks to authenticated resident, staff, or barangay maps. */
$sessionRoles = [
    'DCVS_STAFF_SESSION' => 'staff',
    'DCVS_BARANGAY_SESSION' => 'barangay',
    'resident' => 'resident',
];
$sessionKey = trim(is_string($_GET['sess'] ?? null) ? $_GET['sess'] : '');
if (!isset($sessionRoles[$sessionKey])) {
    http_response_code(400);
    header('Content-Type: application/geo+json; charset=utf-8');
    echo json_encode(['error' => 'Invalid session type.']);
    exit;
}

if ($sessionKey !== 'resident') {
    session_name($sessionKey);
}
session_start();
require_once __DIR__ . '/../db.php';

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Vary: Cookie');

$expectedRole = $sessionRoles[$sessionKey];
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $expectedRole) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if ($expectedRole === 'barangay' && $barangayId <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Barangay scope is missing.']);
    exit;
}

$sql = "SELECT l.landmark_id, l.name, l.address, l.category, l.barangay_id,
            l.coordinates_long, l.coordinates_lat, l.location_accuracy,
            b.barangay_name
        FROM landmarks l
        LEFT JOIN barangays b ON b.barangay_id = l.barangay_id
        WHERE l.is_active = 1
          AND l.location_accuracy IN ('approximate', 'verified')
          AND l.coordinates_long BETWEEN 120.78 AND 121.10
          AND l.coordinates_lat BETWEEN 14.17 AND 14.49";
if ($expectedRole === 'barangay') {
    $sql .= ' AND l.barangay_id = ?';
}
$sql .= ' ORDER BY l.category, l.name, l.landmark_id';

$statement = $conn->prepare($sql);
if (!$statement) {
    error_log('Landmark map query prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => 'Could not load mapped landmarks.']);
    exit;
}
if ($expectedRole === 'barangay') {
    $statement->bind_param('i', $barangayId);
}
if (!$statement->execute()) {
    error_log('Landmark map query failed: ' . $statement->error);
    $statement->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not load mapped landmarks.']);
    exit;
}

$features = [];
$localFeatureCount = 0;
$localCategories = [
    'barangay_hall' => ['government', 'Barangay hall'],
    'school' => ['education', 'School'],
    'chapel' => ['worship', 'Chapel'],
    'market' => ['shopping', 'Market'],
    'subdivision' => ['community', 'Subdivision'],
    'hospital' => ['healthcare', 'Hospital'],
    'park' => ['recreation', 'Park'],
    'other' => ['other', 'Other place'],
];
$result = $statement->get_result();
while ($row = $result->fetch_assoc()) {
    $originalCategory = (string)$row['category'];
    $categoryInfo = $localCategories[$originalCategory] ?? ['other', 'Other place'];
    $isDcvsOffice = $row['name'] === 'Dasmariñas City Veterinary Services Office';
    if ($isDcvsOffice) $categoryInfo = ['government', 'DCVS Office'];
    $isProminent = $isDcvsOffice || in_array($originalCategory, ['barangay_hall', 'hospital'], true);
    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$row['coordinates_long'], (float)$row['coordinates_lat']],
        ],
        'properties' => [
            'id' => (int)$row['landmark_id'],
            'name' => $row['name'],
            'address' => $row['address'] ?? '',
            'category' => $categoryInfo[0],
            'category_label' => $categoryInfo[1],
            'type_label' => $categoryInfo[1],
            'prominent' => $isProminent,
            'source' => 'DCVS',
            'is_dcvs_office' => $isDcvsOffice,
            'is_own_barangay_hall' => $expectedRole === 'barangay'
                && $row['category'] === 'barangay_hall'
                && (int)$row['barangay_id'] === $barangayId,
            'barangay' => $row['barangay_name'] ?? '',
            'accuracy' => $row['location_accuracy'],
        ],
    ];
    $localFeatureCount++;
}
$statement->close();

$snapshotPath = __DIR__ . '/osm-pois.geojson';
if (!is_file($snapshotPath) || !is_readable($snapshotPath)) {
    error_log('The local OpenStreetMap POI snapshot is unavailable.');
    http_response_code(500);
    echo json_encode(['error' => 'Mapped place data is temporarily unavailable.']);
    exit;
}
$snapshot = json_decode(file_get_contents($snapshotPath), true);
if (!is_array($snapshot) || ($snapshot['type'] ?? '') !== 'FeatureCollection'
    || !is_array($snapshot['features'] ?? null) || !is_array($snapshot['metadata'] ?? null)) {
    error_log('The local OpenStreetMap POI snapshot is invalid: ' . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['error' => 'Mapped place data is temporarily unavailable.']);
    exit;
}

$scopeBarangayKey = null;
if ($expectedRole === 'barangay') {
    $barangayLookup = $conn->prepare('SELECT barangay_name FROM barangays WHERE barangay_id = ?');
    if (!$barangayLookup) {
        error_log('Barangay name lookup prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Could not load mapped places for this barangay.']);
        exit;
    }
    $barangayLookup->bind_param('i', $barangayId);
    if (!$barangayLookup->execute()) {
        error_log('Barangay name lookup failed: ' . $barangayLookup->error);
        $barangayLookup->close();
        http_response_code(500);
        echo json_encode(['error' => 'Could not load mapped places for this barangay.']);
        exit;
    }
    $barangayRow = $barangayLookup->get_result()->fetch_assoc();
    $barangayLookup->close();
    if (!$barangayRow) {
        http_response_code(403);
        echo json_encode(['error' => 'Barangay scope could not be verified.']);
        exit;
    }
    $scopeBarangayKey = strtolower(trim((string)$barangayRow['barangay_name']));
}

$distanceMeters = function($first, $second) {
    $latitude = deg2rad(((float)$first[1] + (float)$second[1]) / 2);
    $x = ((float)$first[0] - (float)$second[0]) * 111320 * cos($latitude);
    $y = ((float)$first[1] - (float)$second[1]) * 110540;
    return sqrt(($x * $x) + ($y * $y));
};
$normalizeCourtName = function($name) {
    $name = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)$name), 'UTF-8')
        : strtolower(trim((string)$name));
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
    if (!is_string($name)) return '';
    $ignoredWords = [
        'barangay', 'brgy', 'covered', 'basketball', 'court', 'courts',
        'multi', 'multipurpose', 'purpose', 'gymnasium', 'gym', 'village',
    ];
    $tokens = preg_split('/\s+/', trim($name));
    $tokens = array_values(array_filter($tokens, function($token) use ($ignoredWords) {
        return $token !== '' && !in_array($token, $ignoredWords, true);
    }));
    return implode(' ', $tokens);
};
$normalizeMajorName = function($name) {
    $name = preg_replace('/\([^)]*\)/u', ' ', (string)$name);
    $name = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)$name), 'UTF-8')
        : strtolower(trim((string)$name));
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
    if (!is_string($name)) return [];
    $ignoredWords = [
        'the', 'of', 'and', 'city', 'dasmarinas', 'dasma', 'cavite', 'philippines',
        'place', 'branch', 'inc', 'corporation', 'corp', 'covered', 'court', 'courts', 'hall',
        'mall', 'commercial', 'center', 'centre', 'medical', 'hospital', 'health',
        'university', 'college', 'school', 'parish', 'church', 'fast', 'food',
        'pharmacy', 'drugstore', 'office', 'station',
    ];
    $tokens = preg_split('/\s+/', trim($name));
    $tokens = array_values(array_filter($tokens, function($token) use ($ignoredWords) {
        return $token !== '' && !in_array($token, $ignoredWords, true);
    }));
    return $tokens;
};

$coveredCourtPath = __DIR__ . '/covered-courts.geojson';
if (!is_file($coveredCourtPath) || !is_readable($coveredCourtPath)) {
    error_log('The user-provided covered-court coordinate data is unavailable.');
    http_response_code(500);
    echo json_encode(['error' => 'Covered-court location data is temporarily unavailable.']);
    exit;
}
$coveredCourtSnapshot = json_decode(file_get_contents($coveredCourtPath), true);
if (!is_array($coveredCourtSnapshot) || ($coveredCourtSnapshot['type'] ?? '') !== 'FeatureCollection'
    || !is_array($coveredCourtSnapshot['features'] ?? null)
    || !is_array($coveredCourtSnapshot['metadata'] ?? null)
    || count($coveredCourtSnapshot['features']) !== (int)($coveredCourtSnapshot['metadata']['feature_count'] ?? -1)) {
    error_log('The user-provided covered-court coordinate data is invalid: ' . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['error' => 'Covered-court location data is temporarily unavailable.']);
    exit;
}
$coveredCourtFeatures = [];
$coveredCourtCoordinatesByName = [];
foreach ($coveredCourtSnapshot['features'] as $courtFeature) {
    $courtProperties = $courtFeature['properties'] ?? [];
    $courtCoordinates = $courtFeature['geometry']['coordinates'] ?? null;
    if (($courtFeature['type'] ?? '') !== 'Feature'
        || ($courtFeature['geometry']['type'] ?? '') !== 'Point'
        || !is_array($courtCoordinates) || count($courtCoordinates) !== 2
        || !is_numeric($courtCoordinates[0]) || !is_numeric($courtCoordinates[1])
        || ($courtProperties['category'] ?? '') !== 'sports'
        || ($courtProperties['subcategory'] ?? '') !== 'covered_court') {
        error_log('The user-provided covered-court coordinate data contains an invalid feature.');
        http_response_code(500);
        echo json_encode(['error' => 'Covered-court location data is temporarily unavailable.']);
        exit;
    }
    if ($scopeBarangayKey !== null
        && strtolower(trim((string)($courtProperties['barangay'] ?? ''))) !== $scopeBarangayKey) {
        continue;
    }
    $courtFeature['properties']['prominent'] = true;
    $coveredCourtFeatures[] = $courtFeature;
    $courtNameKey = $normalizeCourtName($courtProperties['name'] ?? '');
    if ($courtNameKey !== '') {
        $coveredCourtCoordinatesByName[$courtNameKey][] = $courtCoordinates;
    }
}

$majorLandmarkPath = __DIR__ . '/major-landmarks.geojson';
if (!is_file($majorLandmarkPath) || !is_readable($majorLandmarkPath)) {
    error_log('The user-provided major-landmark coordinate data is unavailable.');
    http_response_code(500);
    echo json_encode(['error' => 'Major-landmark location data is temporarily unavailable.']);
    exit;
}
$majorLandmarkSnapshot = json_decode(file_get_contents($majorLandmarkPath), true);
if (!is_array($majorLandmarkSnapshot) || ($majorLandmarkSnapshot['type'] ?? '') !== 'FeatureCollection'
    || !is_array($majorLandmarkSnapshot['features'] ?? null)
    || !is_array($majorLandmarkSnapshot['metadata'] ?? null)
    || count($majorLandmarkSnapshot['features']) !== (int)($majorLandmarkSnapshot['metadata']['feature_count'] ?? -1)) {
    error_log('The user-provided major-landmark coordinate data is invalid: ' . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['error' => 'Major-landmark location data is temporarily unavailable.']);
    exit;
}
$majorLandmarkFeatures = [];
foreach ($majorLandmarkSnapshot['features'] as $majorFeature) {
    $majorProperties = $majorFeature['properties'] ?? [];
    $majorCoordinates = $majorFeature['geometry']['coordinates'] ?? null;
    if (($majorFeature['type'] ?? '') !== 'Feature'
        || ($majorFeature['geometry']['type'] ?? '') !== 'Point'
        || !is_array($majorCoordinates) || count($majorCoordinates) !== 2
        || !is_numeric($majorCoordinates[0]) || !is_numeric($majorCoordinates[1])
        || !is_string($majorProperties['name'] ?? null)
        || !in_array($majorProperties['category'] ?? '', [
            'shopping', 'education', 'healthcare', 'worship', 'government', 'recreation', 'food',
        ], true)) {
        error_log('The user-provided major-landmark data contains an invalid feature.');
        http_response_code(500);
        echo json_encode(['error' => 'Major-landmark location data is temporarily unavailable.']);
        exit;
    }
    if ($scopeBarangayKey !== null
        && strtolower(trim((string)($majorProperties['barangay'] ?? ''))) !== $scopeBarangayKey) {
        continue;
    }
    $majorFeature['properties']['prominent'] = true;
    $majorLandmarkFeatures[] = $majorFeature;
}

$majorNameMatches = function($firstName, $secondName) use ($normalizeMajorName) {
    $first = $normalizeMajorName($firstName);
    $second = $normalizeMajorName($secondName);
    if (!$first || !$second) return 0;
    $firstSet = array_fill_keys($first, true);
    $secondSet = array_fill_keys($second, true);
    $shared = count(array_intersect_key($firstSet, $secondSet));
    $shorterCount = min(count($firstSet), count($secondSet));
    if ($shared === count($firstSet) && $shared === count($secondSet)) return 100 + $shared;
    if ($shared === $shorterCount && $shorterCount > 1) return $shorterCount;
    if ($shared === 1) {
        $sharedToken = array_key_first(array_intersect_key($firstSet, $secondSet));
        if (strlen((string)$sharedToken) >= 5) return 1;
    }
    return 0;
};

$replaceWithMajorLocation = function(&$existingFeatures, $majorFeature) use ($distanceMeters, $majorNameMatches) {
    $majorProperties = $majorFeature['properties'];
    $majorCoordinates = $majorFeature['geometry']['coordinates'];
    foreach ($existingFeatures as $index => $existingFeature) {
        $existingProperties = $existingFeature['properties'] ?? [];
        if (($existingProperties['category'] ?? '') !== $majorProperties['category']) continue;
        $existingCoordinates = $existingFeature['geometry']['coordinates'] ?? null;
        if (!is_array($existingCoordinates) || count($existingCoordinates) !== 2) continue;
        $sharedNameTokens = $majorNameMatches($majorProperties['name'], $existingProperties['name'] ?? '');
        if ($sharedNameTokens === 0) continue;
        $maximumDistance = $sharedNameTokens === 1 ? 35 : 350;
        if ($distanceMeters($majorCoordinates, $existingCoordinates) <= $maximumDistance) {
            unset($existingFeatures[$index]);
        }
    }
};

$localPlacesByName = [];
foreach ($features as $feature) {
    $properties = $feature['properties'];
    $key = strtolower(trim((string)$properties['name'])) . '|' . $properties['category'];
    $localPlacesByName[$key][] = $feature['geometry']['coordinates'];
}

foreach ($snapshot['features'] as $feature) {
    $properties = $feature['properties'] ?? [];
    $coordinates = $feature['geometry']['coordinates'] ?? null;
    if (($feature['type'] ?? '') !== 'Feature'
        || ($feature['geometry']['type'] ?? '') !== 'Point'
        || !is_array($coordinates) || count($coordinates) !== 2
        || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])) {
        continue;
    }
    if ($scopeBarangayKey !== null
        && strtolower(trim((string)($properties['barangay'] ?? ''))) !== $scopeBarangayKey) {
        continue;
    }
    $category = (string)($properties['category'] ?? 'other');
    $subcategory = strtolower((string)($properties['subcategory'] ?? ''));
    $feature['properties']['prominent'] = $category === 'government'
        || $category === 'worship'
        || ($category === 'healthcare' && $subcategory === 'hospital')
        || ($category === 'education' && in_array($subcategory, ['university', 'college'], true))
        || ($category === 'shopping' && $subcategory === 'mall')
        || ($category === 'sports' && $subcategory === 'covered_court')
        || ($category === 'recreation' && in_array(
            $subcategory,
            ['park', 'sports_centre', 'stadium', 'attraction'],
            true
        ));
    $key = strtolower(trim((string)($properties['name'] ?? ''))) . '|' . (string)($properties['category'] ?? 'other');
    $isDuplicate = false;
    foreach ($localPlacesByName[$key] ?? [] as $localCoordinates) {
        if ($distanceMeters($coordinates, $localCoordinates) <= 12) {
            $isDuplicate = true;
            break;
        }
    }
    $courtNameKey = $normalizeCourtName($properties['name'] ?? '');
    if (!$isDuplicate && $courtNameKey !== '') {
        foreach ($coveredCourtCoordinatesByName[$courtNameKey] ?? [] as $courtCoordinates) {
            if ($distanceMeters($coordinates, $courtCoordinates) <= 250) {
                $isDuplicate = true;
                break;
            }
        }
    }
    if (!$isDuplicate) {
        foreach ($majorLandmarkFeatures as $majorFeature) {
            $majorProperties = $majorFeature['properties'];
            if ($category !== ($majorProperties['category'] ?? '')) continue;
            $sharedNameTokens = $majorNameMatches($properties['name'] ?? '', $majorProperties['name'] ?? '');
            if ($sharedNameTokens === 0) continue;
            $maximumDistance = $sharedNameTokens === 1 ? 35 : 350;
            if ($distanceMeters($coordinates, $majorFeature['geometry']['coordinates']) <= $maximumDistance) {
                $isDuplicate = true;
                break;
            }
        }
    }
    if (!$isDuplicate) $features[] = $feature;
}
foreach ($coveredCourtFeatures as $courtFeature) {
    $features[] = $courtFeature;
}
foreach ($majorLandmarkFeatures as $majorFeature) {
    $replaceWithMajorLocation($features, $majorFeature);
    $features[] = $majorFeature;
}
$features = array_values($features);

echo json_encode(
    [
        'type' => 'FeatureCollection',
        'metadata' => [
            'local_landmark_count' => $localFeatureCount,
            'osm' => $snapshot['metadata'],
            'covered_courts' => $coveredCourtSnapshot['metadata'],
            'major_landmarks' => $majorLandmarkSnapshot['metadata'],
        ],
        'features' => $features,
    ],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
