<?php

require_once __DIR__ . '/../../includes/reports/location.php';

function dcvsLocationGeocodeReply($status, array $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dcvsLocationGeocodeBounds(array $features) {
    $bounds = [INF, INF, -INF, -INF];
    $visit = function ($value) use (&$visit, &$bounds) {
        if (!is_array($value)) return;
        if (isset($value[0], $value[1]) && is_numeric($value[0]) && is_numeric($value[1])) {
            $longitude = (float)$value[0];
            $latitude = (float)$value[1];
            $bounds[0] = min($bounds[0], $longitude);
            $bounds[1] = min($bounds[1], $latitude);
            $bounds[2] = max($bounds[2], $longitude);
            $bounds[3] = max($bounds[3], $latitude);
            return;
        }
        foreach ($value as $child) $visit($child);
    };

    foreach ($features as $feature) {
        $visit($feature['geometry']['coordinates'] ?? []);
    }
    if (!is_finite($bounds[0]) || !is_finite($bounds[1])
        || !is_finite($bounds[2]) || !is_finite($bounds[3])) {
        throw new RuntimeException('Barangay boundaries do not contain usable coordinates.');
    }
    return $bounds;
}

function dcvsLocationGeocodeFeatureContext(array $feature, $barangayName = '') {
    $context = $feature['context'] ?? [];
    if (!is_array($context)) return '';

    $streets = [];
    $areas = [];
    $seen = [];
    $barangayKey = dcvsLocationNameKey((string)$barangayName);

    foreach ($context as $item) {
        if (!is_array($item)) continue;
        $text = trim((string)($item['text'] ?? ''));
        if ($text === '') continue;

        $key = dcvsLocationNameKey($text);
        if ($key === '' || $key === $barangayKey || isset($seen[$key])) continue;
        $seen[$key] = true;

        $kind = strtolower(trim((string)($item['kind'] ?? '')));
        $designation = strtolower(trim((string)($item['place_designation'] ?? '')));
        if (in_array($kind, ['street', 'road'], true)) {
            $streets[] = $text;
        } elseif (in_array($designation, ['neighbourhood', 'quarter', 'suburb', 'locality', 'village', 'hamlet'], true)) {
            $areas[] = $text;
        }
    }

    return implode(' · ', array_slice(array_merge($streets, $areas), 0, 3));
}

function dcvsLocationGeocodeFeatureSupportsPinSearch(array $feature) {
    $placeTypes = is_array($feature['place_type'] ?? null) ? $feature['place_type'] : [];
    $placeType = trim((string)($placeTypes[0] ?? ''));
    if (!in_array($placeType, ['place', 'locality'], true)) return true;

    $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $designation = strtolower(trim((string)($properties['place_designation'] ?? '')));
    return in_array($designation, ['neighbourhood', 'quarter', 'suburb', 'locality', 'village', 'hamlet'], true);
}

function dcvsLocationGeocodeFeatureResult(array $feature, array $boundaries) {
    $geometry = $feature['geometry'] ?? [];
    $coordinates = $geometry['coordinates'] ?? [];
    if (($geometry['type'] ?? '') !== 'Point'
        || !is_array($coordinates) || !isset($coordinates[0], $coordinates[1])
        || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])) {
        return null;
    }
    $longitude = (float)$coordinates[0];
    $latitude = (float)$coordinates[1];
    if (!is_finite($longitude) || !is_finite($latitude)
        || $longitude < -180 || $longitude > 180
        || $latitude < -90 || $latitude > 90) {
        return null;
    }

    try {
        $barangay = dcvsResolveOfficialBarangayPin($longitude, $latitude, $boundaries);
    } catch (DomainException $exception) {
        return null;
    }
    if (!$barangay) return null;

    $label = trim((string)($feature['place_name'] ?? $feature['text'] ?? ''));
    if ($label === '') return null;
    $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $featureTags = is_array($properties['feature_tags'] ?? null) ? $properties['feature_tags'] : [];
    $categories = is_array($properties['categories'] ?? null) ? $properties['categories'] : [];
    $placeTypes = is_array($feature['place_type'] ?? null) ? $feature['place_type'] : [];
    $placeType = trim((string)($placeTypes[0] ?? ''));
    $sourceKind = strtolower(trim((string)($properties['kind'] ?? '')));
    $context = dcvsLocationGeocodeFeatureContext($feature, (string)$barangay['barangay_name']);
    if ($context === '') {
        $context = trim((string)($featureTags['addr:housename'] ?? ''));
    }
    if ($placeType === 'poi') {
        $category = trim(str_replace(['_', '-'], ' ', (string)($categories[0] ?? '')));
        $typeLabel = $category !== '' ? ucwords($category) : 'POI';
    } elseif ($placeType === 'road' || ($placeType === 'address' && $sourceKind === 'street')) {
        $typeLabel = 'Street';
    } elseif (in_array($placeType, ['place', 'locality'], true)) {
        $typeLabel = 'Area';
    } elseif ($placeType === 'address') {
        $typeLabel = 'Address';
    } else {
        $typeLabel = 'Place';
    }

    return [
        'label' => $label,
        'name' => trim((string)($feature['text'] ?? '')),
        'coordinates' => [$longitude, $latitude],
        'barangay' => (string)$barangay['barangay_name'],
        'psgc_code' => (string)$barangay['psgc_code'],
        'type_label' => $typeLabel,
        // The provider does not guarantee that a result is placed on a building or entrance.
        'approximate' => true,
        'context' => $context,
    ];
}

function dcvsLocationGeocodeSearchTokens($value) {
    $value = dcvsLocationNameKey($value);
    if (function_exists('iconv')) {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) $value = $transliterated;
    }

    $tokens = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($tokens)) return [];
    $ignored = ['and', 'at', 'in', 'ng', 'of', 'the'];
    return array_values(array_unique(array_filter($tokens, function ($token) use ($ignored) {
        return !in_array($token, $ignored, true);
    })));
}

function dcvsLocationGeocodeFeatureMatchesQuery($query, array $feature) {
    $queryTokens = dcvsLocationGeocodeSearchTokens($query);
    if (!$queryTokens) return false;

    $candidate = trim(
        (string)($feature['text'] ?? '') . ' '
        . (string)($feature['place_name'] ?? '') . ' '
        . dcvsLocationGeocodeFeatureContext($feature)
    );
    $candidateTokens = dcvsLocationGeocodeSearchTokens($candidate);
    foreach ($queryTokens as $queryToken) {
        $matched = false;
        foreach ($candidateTokens as $candidateToken) {
            if ($candidateToken === $queryToken
                || (strlen($queryToken) >= 3 && strpos($candidateToken, $queryToken) === 0)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) return false;
    }
    return true;
}

$portal = isset($_GET['portal']) && is_scalar($_GET['portal'])
    ? trim((string)$_GET['portal'])
    : '';
$portals = [
    'resident' => ['session' => null, 'role' => 'resident'],
    'staff' => ['session' => 'DCVS_STAFF_SESSION', 'role' => 'staff'],
    'barangay' => ['session' => 'DCVS_BARANGAY_SESSION', 'role' => 'barangay'],
];
if (!isset($portals[$portal])) {
    dcvsLocationGeocodeReply(400, ['success' => false, 'message' => 'The requesting portal is invalid.']);
}
if ($portals[$portal]['session'] !== null) {
    session_name($portals[$portal]['session']);
}
session_start();

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $portals[$portal]['role']) {
    dcvsLocationGeocodeReply(401, ['success' => false, 'message' => 'Sign in to search locations.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    dcvsLocationGeocodeReply(405, ['success' => false, 'message' => 'Use the location search form to look up places.']);
}

loadDcvsEnv();
$apiKey = trim((string)env('DCVS_MAPTILER_API_KEY', ''));
if ($apiKey === '') {
    dcvsLocationGeocodeReply(503, ['success' => false, 'message' => 'Place search is unavailable because its map service is not configured.']);
}
if (!function_exists('curl_init')) {
    dcvsLocationGeocodeReply(503, ['success' => false, 'message' => 'Place search is unavailable on this server.']);
}

$query = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string)$_GET['q']) : '';
$searchMode = isset($_GET['mode']) && is_scalar($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$pinSearch = $portal === 'resident' || $searchMode === 'pin';
$isReverse = $query === '' && isset($_GET['latitude'], $_GET['longitude']);
if (!$isReverse && ($query === '' || strlen($query) > 160 || strlen($query) < 3)) {
    dcvsLocationGeocodeReply(400, ['success' => false, 'message' => 'Enter at least 3 characters to search for a place.']);
}

$latitude = null;
$longitude = null;
if ($isReverse) {
    if (!is_scalar($_GET['latitude']) || !is_scalar($_GET['longitude'])
        || !is_numeric($_GET['latitude']) || !is_numeric($_GET['longitude'])) {
        dcvsLocationGeocodeReply(400, ['success' => false, 'message' => 'The pin coordinates are invalid.']);
    }
    $latitude = (float)$_GET['latitude'];
    $longitude = (float)$_GET['longitude'];
    if (!is_finite($latitude) || !is_finite($longitude)
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180) {
        dcvsLocationGeocodeReply(400, ['success' => false, 'message' => 'The pin coordinates are outside the valid geographic range.']);
    }
}

$now = microtime(true);
$lastRequestAt = (float)($_SESSION['location_geocode_last_request_at'] ?? 0);
if ($now - $lastRequestAt < ($isReverse ? 0.1 : 0.35)) {
    dcvsLocationGeocodeReply(429, ['success' => false, 'message' => 'Please wait a moment before searching again.']);
}
$_SESSION['location_geocode_last_request_at'] = $now;

try {
    $boundaries = dcvsLoadOfficialBarangayPolygons(
        __DIR__ . '/../../DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        __DIR__ . '/../../geo-map/barangays-boundary.json'
    );
    $bounds = dcvsLocationGeocodeBounds($boundaries);
} catch (RuntimeException $exception) {
    error_log('Location geocoding could not load official barangay boundaries: ' . $exception->getMessage());
    dcvsLocationGeocodeReply(503, ['success' => false, 'message' => 'Verified Dasmariñas boundaries are unavailable. Please try again later.']);
}

$reverseBarangay = null;
if ($isReverse) {
    try {
        $reverseBarangay = dcvsResolveOfficialBarangayPin($longitude, $latitude, $boundaries);
    } catch (DomainException $exception) {
        $reverseBarangay = null;
    }
    if (!$reverseBarangay) {
        dcvsLocationGeocodeReply(200, ['success' => true, 'results' => []]);
    }
}

$path = $isReverse
    ? number_format($longitude, 7, '.', '') . ',' . number_format($latitude, 7, '.', '')
    : rawurlencode($query);
$parameters = [
    'key' => $apiKey,
    'limit' => $isReverse ? 1 : 10,
    'language' => 'en',
];
if (!$isReverse) {
    $parameters['bbox'] = implode(',', $bounds);
    $parameters['country'] = 'ph';
    $parameters['autocomplete'] = 'true';
    $parameters['types'] = 'poi,address,road,place,locality';
}
$url = 'https://api.maptiler.com/geocoding/' . $path . '.json?' . http_build_query($parameters);
session_write_close();
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$body = curl_exec($curl);
$curlError = curl_error($curl);
$httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($body === false) {
    error_log('MapTiler geocoding request failed: ' . $curlError);
    dcvsLocationGeocodeReply(502, ['success' => false, 'message' => 'Place search could not reach the map service. Please try again.']);
}
if ($httpStatus !== 200) {
    error_log('MapTiler geocoding returned HTTP ' . $httpStatus . '.');
    dcvsLocationGeocodeReply($httpStatus === 429 ? 429 : 502, [
        'success' => false,
        'message' => $httpStatus === 429
            ? 'Place search is temporarily busy. Please wait a moment and try again.'
            : 'Place search could not be completed. Please try again.',
    ]);
}

$payload = json_decode($body, true);
if (!is_array($payload) || !isset($payload['features']) || !is_array($payload['features'])) {
    error_log('MapTiler geocoding returned an invalid response.');
    dcvsLocationGeocodeReply(502, ['success' => false, 'message' => 'Place search returned invalid data. Please try again.']);
}

$results = [];
if ($isReverse) {
    $feature = $payload['features'][0] ?? null;
    if (is_array($feature)) {
        $label = trim((string)($feature['place_name'] ?? $feature['text'] ?? ''));
        if ($label !== '') {
            $results[] = [
                'label' => $label,
                'name' => trim((string)($feature['text'] ?? '')),
                'coordinates' => [$longitude, $latitude],
                'barangay' => (string)$reverseBarangay['barangay_name'],
                'psgc_code' => (string)$reverseBarangay['psgc_code'],
            ];
        }
    }
} else {
    foreach ($payload['features'] as $feature) {
        if (!is_array($feature)) continue;
        if (!dcvsLocationGeocodeFeatureMatchesQuery($query, $feature)) continue;
        if ($pinSearch && !dcvsLocationGeocodeFeatureSupportsPinSearch($feature)) continue;
        $result = dcvsLocationGeocodeFeatureResult($feature, $boundaries);
        if ($result !== null) $results[] = $result;
    }
}
dcvsLocationGeocodeReply(200, ['success' => true, 'results' => $results]);
