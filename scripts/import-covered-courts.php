<?php
/**
 * Validate and encode the user-provided covered-court coordinate CSV.
 * Run from the repository root with: C:\xampp\php\php.exe scripts\import-covered-courts.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/reports/location.php';

function dcvsCoveredCourtTypeLabel($name) {
    if (preg_match('/covered[\s_-]+basketball[\s_-]+court/i', $name)) return 'Covered basketball court';
    if (preg_match('/covered[\s_-]+court/i', $name)) return 'Covered court';
    if (preg_match('/basketball[\s_-]+court/i', $name)) return 'Basketball court';
    if (preg_match('/\bgymnasium\b/i', $name)) return 'Gymnasium';
    if (preg_match('/\bgym\b/i', $name)) return 'Gym';
    if (preg_match('/sports[\s_-]+center/i', $name)) return 'Sports center';
    if (preg_match('/leisure[\s_-]+hub/i', $name)) return 'Leisure hub';
    if (preg_match('/clubhouse/i', $name)) return 'Clubhouse & court';
    if (preg_match('/\bcourt\b/i', $name)) return 'Court';
    return 'Sports facility';
}

try {
    $root = dirname(__DIR__);
    $inputPath = $root . '/dasmarinas_covered_courts_coordinates.csv';
    if (!is_file($inputPath) || !is_readable($inputPath)) {
        throw new RuntimeException('The covered-court coordinates CSV is unavailable.');
    }

    $input = fopen($inputPath, 'rb');
    if ($input === false) throw new RuntimeException('Could not open the covered-court coordinates CSV.');

    $headers = fgetcsv($input, null, ',', '"', '\\');
    if (!is_array($headers)) throw new RuntimeException('The covered-court coordinates CSV is empty.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $requiredHeaders = [
        'Area / Cluster',
        'Covered Court',
        'Google Maps Address / Reference',
        'Plus Code',
        'Latitude',
        'Longitude',
        'Coordinate Source',
    ];
    if ($headers !== $requiredHeaders) {
        fclose($input);
        throw new RuntimeException('The covered-court CSV headers do not match the expected format.');
    }

    $officialBarangays = dcvsLoadOfficialBarangayPolygons(
        $root . '/DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        $root . '/geo-map/barangays-boundary.json'
    );
    $features = [];
    $seenNames = [];
    $coordinateSources = [];
    $invalidRows = [];
    $allowUnassignedNames = ['Diamond Village Covered Court' => true];
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
        $name = trim((string)$record['Covered Court']);
        $latitudeText = trim((string)$record['Latitude']);
        $longitudeText = trim((string)$record['Longitude']);
        $coordinateSource = trim((string)$record['Coordinate Source']);
        if ($name === '' || !is_numeric($latitudeText) || !is_numeric($longitudeText) || $coordinateSource === '') {
            $invalidRows[] = 'row ' . $lineNumber . ' is missing a name, numeric coordinates, or source';
            continue;
        }

        $latitude = (float)$latitudeText;
        $longitude = (float)$longitudeText;
        if (!is_finite($latitude) || !is_finite($longitude)) {
            $invalidRows[] = 'row ' . $lineNumber . ' has non-finite coordinates';
            continue;
        }
        try {
            $barangay = dcvsResolveOfficialBarangayPin($longitude, $latitude, $officialBarangays);
        } catch (DomainException $error) {
            $invalidRows[] = 'row ' . $lineNumber . ' (' . $name . ') could not be assigned: ' . $error->getMessage();
            continue;
        } catch (InvalidArgumentException $error) {
            $invalidRows[] = 'row ' . $lineNumber . ' (' . $name . ') has invalid coordinates: ' . $error->getMessage();
            continue;
        }
        $outsideOfficialBoundary = false;
        if (!$barangay) {
            if (!isset($allowUnassignedNames[$name])) {
                $invalidRows[] = 'row ' . $lineNumber . ' (' . $name . ') falls outside the official Dasmariñas boundaries';
                continue;
            }
            $outsideOfficialBoundary = true;
            $unassignedFeatureCount++;
        }

        $nameKey = function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
        $nameKey = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $nameKey);
        $nameKey = trim(preg_replace('/\s+/', ' ', $nameKey));
        if (isset($seenNames[$nameKey])) {
            fclose($input);
            throw new RuntimeException('CSV rows ' . $seenNames[$nameKey] . ' and ' . $lineNumber . ' repeat the same court name.');
        }
        $seenNames[$nameKey] = $lineNumber;

        $properties = [
            'id' => 'user:covered-court:' . $lineNumber,
            'csv_row' => $lineNumber,
            'name' => $name,
            'category' => 'sports',
            'category_label' => 'Sports & covered courts',
            'type_label' => dcvsCoveredCourtTypeLabel($name),
            'subcategory' => 'covered_court',
            'address' => trim((string)$record['Google Maps Address / Reference']),
            'area_cluster' => trim((string)$record['Area / Cluster']),
            'barangay' => $barangay['barangay_name'] ?? '',
            'source' => 'User-provided covered-court coordinates',
            'coordinate_source' => $coordinateSource,
            'accuracy' => 'user-provided',
        ];
        if ($outsideOfficialBoundary) $properties['boundary_status'] = 'outside_official_boundary';
        $plusCode = trim((string)$record['Plus Code']);
        if ($plusCode !== '') $properties['plus_code'] = $plusCode;
        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'properties' => $properties,
        ];
        $coordinateSources[$coordinateSource] = true;
    }
    fclose($input);

    if ($invalidRows) {
        throw new RuntimeException('Some CSV locations did not pass validation: ' . implode('; ', $invalidRows));
    }
    if (!$features) throw new RuntimeException('The covered-court CSV has no data rows.');
    $snapshot = [
        'type' => 'FeatureCollection',
        'metadata' => [
            'source' => 'User-provided covered-court coordinates CSV',
            'source_file' => basename($inputPath),
            'updated_at' => gmdate('c'),
            'feature_count' => count($features),
            'unassigned_feature_count' => $unassignedFeatureCount,
            'coordinate_sources' => array_keys($coordinateSources),
        ],
        'features' => $features,
    ];
    $json = json_encode(
        $snapshot,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) throw new RuntimeException('Could not encode the covered-court data: ' . json_last_error_msg());

    $outputPath = $root . '/geo-map/covered-courts.geojson';
    $temporaryPath = tempnam(dirname($outputPath), '.covered-courts-');
    if ($temporaryPath === false) throw new RuntimeException('Could not create a temporary covered-court data file.');
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        unlink($temporaryPath);
        throw new RuntimeException('Could not write temporary covered-court data.');
    }
    if (!rename($temporaryPath, $outputPath)) {
        if (is_file($temporaryPath)) unlink($temporaryPath);
        throw new RuntimeException('Could not install the validated covered-court data.');
    }

    fwrite(STDOUT, 'Saved ' . count($features) . ' validated covered-court locations to geo-map/covered-courts.geojson.' . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Covered-court import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
