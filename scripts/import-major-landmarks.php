<?php
/**
 * Validate and encode the major-landmark CSV, using mapped OSM centers for selected places.
 * Run from the repository root with: C:\xampp\php\php.exe scripts\import-major-landmarks.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/reports/location.php';

function dcvsMajorLandmarkCategory($csvCategory, $name) {
    $name = strtolower(trim((string)$name));
    switch (trim((string)$csvCategory)) {
        case 'Malls & Commercial':
            return ['shopping', 'Shopping & markets', 'Mall or commercial center'];
        case 'Universities & Schools':
            return ['education', 'Schools & universities', 'School or university'];
        case 'Hospitals & Medical':
            if (strpos($name, 'health office') !== false) {
                return ['healthcare', 'Healthcare & pharmacies', 'City health office'];
            }
            return ['healthcare', 'Healthcare & pharmacies', 'Hospital or medical center'];
        case 'Churches & Religious':
            return ['worship', 'Churches & worship', 'Church or religious place'];
        case 'Government & Civic':
            if (strpos($name, 'arena') !== false
                || strpos($name, 'track and field') !== false
                || strpos($name, 'promenade') !== false
                || strpos($name, 'butterfly garden') !== false
                || strpos($name, 'park') !== false) {
                return ['recreation', 'Parks & recreation', 'Civic or recreation place'];
            }
            if (strpos($name, 'police') !== false) {
                return ['government', 'Government & barangay halls', 'Police station'];
            }
            if (strpos($name, 'fire') !== false || strpos($name, 'bfp') !== false) {
                return ['government', 'Government & barangay halls', 'Fire station'];
            }
            if (strpos($name, 'city hall') !== false) {
                return ['government', 'Government & barangay halls', 'City hall'];
            }
            return ['government', 'Government & barangay halls', 'Government or civic office'];
        case 'Fast Food & Chains':
            return ['food', 'Food & dining', 'Fast food'];
        case 'Pharmacies & Drugstores':
            return ['healthcare', 'Healthcare & pharmacies', 'Pharmacy'];
    }
    return null;
}

try {
    $root = dirname(__DIR__);
    $inputPath = $root . '/dasmarinas_major_landmarks_coordinates.csv';
    if (!is_file($inputPath) || !is_readable($inputPath)) {
        throw new RuntimeException('The major-landmark coordinates CSV is unavailable.');
    }

    $input = fopen($inputPath, 'rb');
    if ($input === false) throw new RuntimeException('Could not open the major-landmark coordinates CSV.');

    $headers = fgetcsv($input, null, ',', '"', '\\');
    if (!is_array($headers)) throw new RuntimeException('The major-landmark coordinates CSV is empty.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $requiredHeaders = [
        'Category',
        'Landmark / Establishment',
        'Google Maps Address',
        'Plus Code',
        'Latitude',
        'Longitude',
    ];
    if ($headers !== $requiredHeaders) {
        fclose($input);
        throw new RuntimeException('The major-landmark CSV headers do not match the expected format.');
    }

    $officialBarangays = dcvsLoadOfficialBarangayPolygons(
        $root . '/DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        $root . '/geo-map/barangays-boundary.json'
    );
    $kldName = 'Kolehiyo ng Lungsod ng Dasmariñas (KLD)';
    $kldOsmId = 'osm:relation:9467534';
    $osmLocationIds = [
        $kldName => $kldOsmId,
        'Dasmariñas Arena' => 'osm:way:832189771',
        'Pagamutan ng Dasmariñas' => 'osm:relation:8541125',
    ];
    $osmSnapshot = json_decode(
        file_get_contents($root . '/geo-map/osm-pois.geojson'),
        true
    );
    $osmLocationFeatures = [];
    foreach (($osmSnapshot['features'] ?? []) as $osmFeature) {
        $osmId = $osmFeature['properties']['id'] ?? '';
        if (in_array($osmId, $osmLocationIds, true)) {
            $osmLocationFeatures[$osmId] = $osmFeature;
        }
    }
    $kldOsmFeature = $osmLocationFeatures[$kldOsmId] ?? null;
    $kldOsmCoordinates = $kldOsmFeature['geometry']['coordinates'] ?? null;
    if (($kldOsmFeature['geometry']['type'] ?? '') !== 'Point'
        || !is_array($kldOsmCoordinates) || count($kldOsmCoordinates) !== 2
        || !is_numeric($kldOsmCoordinates[0]) || !is_numeric($kldOsmCoordinates[1])
        || ($kldOsmFeature['properties']['accuracy'] ?? '') !== 'feature-center') {
        throw new RuntimeException('The mapped KLD campus feature is missing or invalid.');
    }
    $kldBarangay = dcvsResolveOfficialBarangayPin(
        (float)$kldOsmCoordinates[0],
        (float)$kldOsmCoordinates[1],
        $officialBarangays
    );
    if (!$kldBarangay || $kldBarangay['barangay_name'] !== 'Burol') {
        throw new RuntimeException('The mapped KLD campus feature does not fall within official Barangay Burol.');
    }
    foreach ($osmLocationIds as $locationName => $osmId) {
        $osmFeature = $osmLocationFeatures[$osmId] ?? null;
        $coordinates = $osmFeature['geometry']['coordinates'] ?? null;
        if (($osmFeature['geometry']['type'] ?? '') !== 'Point'
            || !is_array($coordinates) || count($coordinates) !== 2
            || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])
            || ($osmFeature['properties']['accuracy'] ?? '') !== 'feature-center'
            || !is_string($osmFeature['properties']['osm_url'] ?? null)
            || $osmFeature['properties']['osm_url'] === '') {
            throw new RuntimeException('The mapped OpenStreetMap feature is missing or invalid for ' . $locationName . '.');
        }
    }
    $features = [];
    $seenNames = [];
    $categoryCounts = [];
    $outsideBoundaryRows = [];
    $allowUnassignedNames = [
        'The District Dasmariñas' => true,
        'Central Mall Dasmariñas' => true,
        'LTO Dasmariñas District Office' => true,
        'SSS Dasmariñas Branch' => true,
        'Jollibee Salitran' => true,
        "McDonald's Salitran" => true,
        'Mercury Drug Salitran' => true,
    ];
    $unassignedFeatureCount = 0;
    $lineNumber = 1;
    while (($row = fgetcsv($input, null, ',', '"', '\\')) !== false) {
        $lineNumber++;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        if (count($row) !== count($headers)) {
            fclose($input);
            throw new RuntimeException('CSV row ' . $lineNumber . ' has an unexpected number of columns.');
        }

        $record = array_combine($headers, $row);
        $name = trim((string)$record['Landmark / Establishment']);
        $latitudeText = trim((string)$record['Latitude']);
        $longitudeText = trim((string)$record['Longitude']);
        $category = dcvsMajorLandmarkCategory($record['Category'], $name);
        if ($name === '' || $category === null
            || !is_numeric($latitudeText) || !is_numeric($longitudeText)) {
            fclose($input);
            throw new RuntimeException('CSV row ' . $lineNumber . ' has a missing name/category or invalid coordinates.');
        }

        $latitude = (float)$latitudeText;
        $longitude = (float)$longitudeText;
        $osmOverrideId = $osmLocationIds[$name] ?? null;
        $osmOverride = $osmOverrideId !== null ? $osmLocationFeatures[$osmOverrideId] : null;
        $isMappedOsmLocation = is_array($osmOverride);
        $isKld = $name === $kldName;
        if ($isMappedOsmLocation) {
            $osmCoordinates = $osmOverride['geometry']['coordinates'];
            $longitude = (float)$osmCoordinates[0];
            $latitude = (float)$osmCoordinates[1];
        }
        try {
            $barangay = dcvsResolveOfficialBarangayPin($longitude, $latitude, $officialBarangays);
        } catch (DomainException $error) {
            fclose($input);
            throw new RuntimeException('CSV row ' . $lineNumber . ' (' . $name . ') falls on an ambiguous barangay boundary.');
        } catch (InvalidArgumentException $error) {
            fclose($input);
            throw new RuntimeException('CSV row ' . $lineNumber . ' (' . $name . ') has invalid coordinates: ' . $error->getMessage());
        }
        if (!$barangay) {
            if (!isset($allowUnassignedNames[$name])
                || $longitude < 120.78 || $longitude > 121.10
                || $latitude < 14.17 || $latitude > 14.49) {
                $outsideBoundaryRows[] = 'row ' . $lineNumber . ' ' . $name
                    . ' (' . $latitude . ', ' . $longitude . ')';
                continue;
            }
            $unassignedFeatureCount++;
        }

        $nameKey = function_exists('mb_strtolower')
            ? mb_strtolower(trim($name), 'UTF-8')
            : strtolower(trim($name));
        $nameKey = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $nameKey);
        if (!is_string($nameKey)) {
            fclose($input);
            throw new RuntimeException('CSV row ' . $lineNumber . ' contains an invalid landmark name.');
        }
        $nameKey = trim(preg_replace('/\s+/', ' ', $nameKey));
        if (isset($seenNames[$nameKey])) {
            fclose($input);
            throw new RuntimeException('CSV rows ' . $seenNames[$nameKey] . ' and ' . $lineNumber . ' repeat the same landmark name.');
        }
        $seenNames[$nameKey] = $lineNumber;

        $address = trim((string)$record['Google Maps Address']);
        $plusCode = trim((string)$record['Plus Code']);
        $properties = [
            'id' => 'user:major-landmark:' . $lineNumber,
            'csv_row' => $lineNumber,
            'name' => $name,
            'category' => $category[0],
            'category_label' => $category[1],
            'type_label' => $category[2],
            'address' => $address,
            'source' => $isMappedOsmLocation ? 'OpenStreetMap' : 'User-provided major landmarks coordinates CSV',
            'accuracy' => $isMappedOsmLocation ? 'feature-center' : 'user-provided',
            'barangay' => $barangay['barangay_name'] ?? '',
            'prominent' => true,
        ];
        if ($isMappedOsmLocation) {
            $properties['osm_url'] = $osmOverride['properties']['osm_url'];
        } else {
            $properties['coordinate_source'] = 'Coordinates supplied in major landmarks CSV';
        }
        if (!$barangay) $properties['boundary_status'] = 'outside_official_boundary';
        if ($plusCode !== '' && !$isMappedOsmLocation) $properties['plus_code'] = $plusCode;
        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'properties' => $properties,
        ];
        $categoryCounts[$category[0]] = ($categoryCounts[$category[0]] ?? 0) + 1;
    }
    fclose($input);

    if ($outsideBoundaryRows) {
        throw new RuntimeException(
            'These CSV pins fall outside the official Dasmariñas barangay boundaries and need review: '
            . implode('; ', $outsideBoundaryRows)
        );
    }
    if (!$features) throw new RuntimeException('The major-landmark CSV has no data rows.');
    $snapshot = [
        'type' => 'FeatureCollection',
        'metadata' => [
            'source' => 'User-provided major landmarks coordinates CSV',
            'source_file' => basename($inputPath),
            'updated_at' => gmdate('c'),
            'feature_count' => count($features),
            'unassigned_feature_count' => $unassignedFeatureCount,
            'category_counts' => $categoryCounts,
        ],
        'features' => $features,
    ];
    $json = json_encode(
        $snapshot,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) throw new RuntimeException('Could not encode the major-landmark data: ' . json_last_error_msg());

    $outputPath = $root . '/geo-map/major-landmarks.geojson';
    $temporaryPath = tempnam(dirname($outputPath), '.major-landmarks-');
    if ($temporaryPath === false) throw new RuntimeException('Could not create temporary major-landmark data.');
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        unlink($temporaryPath);
        throw new RuntimeException('Could not write temporary major-landmark data.');
    }
    if (!rename($temporaryPath, $outputPath)) {
        if (is_file($temporaryPath)) unlink($temporaryPath);
        throw new RuntimeException('Could not install the validated major-landmark data.');
    }

    fwrite(STDOUT, 'Saved ' . count($features) . ' validated major landmarks to geo-map/major-landmarks.geojson.' . PHP_EOL);
    fwrite(STDOUT, 'Categories: ' . json_encode($categoryCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Major-landmark import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
