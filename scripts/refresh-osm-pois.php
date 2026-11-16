<?php
/**
 * Refresh the local Dasmariñas OpenStreetMap POI snapshot.
 * Run from the repository root with: C:\xampp\php\php.exe scripts\refresh-osm-pois.php
 * Run deliberately: public Overpass is fair-use, rate-limited, and has no service guarantee.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/reports/location.php';

function dcvsOsmPoiCategory($tags, $name = '') {
    $amenity = strtolower(trim((string)($tags['amenity'] ?? '')));
    $healthcare = strtolower(trim((string)($tags['healthcare'] ?? '')));
    $shop = strtolower(trim((string)($tags['shop'] ?? '')));
    $leisure = strtolower(trim((string)($tags['leisure'] ?? '')));
    $tourism = strtolower(trim((string)($tags['tourism'] ?? '')));
    $office = strtolower(trim((string)($tags['office'] ?? '')));
    $sport = strtolower(trim((string)($tags['sport'] ?? '')));
    $historic = strtolower(trim((string)($tags['historic'] ?? '')));
    $covered = strtolower(trim((string)($tags['covered'] ?? '')));
    $isCoveredCourt = preg_match('/\bcovered[\s_-]+court\b/i', (string)$name) === 1;
    if (!$isCoveredCourt && $covered === 'yes') {
        $isCoveredCourt = preg_match('/\bcourt\b/i', (string)$name) === 1
            || in_array($sport, ['basketball', 'volleyball', 'badminton', 'futsal', 'tennis', 'multi'], true);
    }

    if ($isCoveredCourt) {
        return ['sports', 'Sports & covered courts', 'covered_court'];
    }

    if (in_array($amenity, ['restaurant', 'fast_food', 'cafe', 'food_court', 'bar', 'pub', 'ice_cream'], true)) {
        return ['food', 'Food & dining', $amenity];
    }
    if (in_array($amenity, ['school', 'college', 'university', 'kindergarten'], true)
        || in_array(strtolower(trim((string)($tags['education'] ?? ''))), ['school', 'college', 'university', 'kindergarten'], true)) {
        $type = $amenity !== '' ? $amenity : strtolower(trim((string)$tags['education']));
        return ['education', 'Schools & universities', $type];
    }
    if ($healthcare !== '' || in_array($amenity, ['hospital', 'clinic', 'doctors', 'dentist', 'pharmacy', 'veterinary'], true)) {
        $type = $healthcare !== '' ? $healthcare : $amenity;
        return ['healthcare', 'Healthcare & pharmacies', $type];
    }
    if ($amenity === 'place_of_worship') {
        return ['worship', 'Churches & worship', $amenity];
    }
    if ($amenity === 'marketplace' || $shop !== '') {
        return ['shopping', 'Shopping & markets', $shop !== '' ? $shop : $amenity];
    }
    if ($amenity === 'townhall' || in_array($amenity, ['police', 'fire_station', 'post_office'], true)
        || in_array($office, ['government', 'administrative', 'politician'], true)
        || isset($tags['government'])) {
        $type = $office !== '' ? $office : ($amenity !== '' ? $amenity : 'government office');
        return ['government', 'Government & barangay halls', $type];
    }
    if ($leisure !== '' || $sport !== '' || $tourism !== '' || $historic !== ''
        || in_array($amenity, ['cinema', 'theatre', 'museum', 'arts_centre'], true)) {
        $type = $leisure !== '' ? $leisure : ($sport !== '' ? $sport : ($tourism !== '' ? $tourism : ($historic !== '' ? $historic : $amenity)));
        return ['recreation', 'Parks & recreation', $type];
    }
    if (in_array($amenity, ['community_centre', 'library', 'social_facility'], true)) {
        return ['community', 'Community places', $amenity];
    }
    if (in_array($amenity, ['bank', 'fuel'], true)) {
        return ['other', 'Other places', $amenity];
    }

    return null;
}

function dcvsOsmPoiAddress($tags) {
    $fullAddress = trim((string)($tags['addr:full'] ?? ''));
    if ($fullAddress !== '') return $fullAddress;

    $street = trim((string)($tags['addr:street'] ?? ''));
    $number = trim((string)($tags['addr:housenumber'] ?? ''));
    $parts = [];
    if ($number !== '' || $street !== '') $parts[] = trim($number . ' ' . $street);
    foreach (['addr:suburb', 'addr:postcode'] as $key) {
        $value = trim((string)($tags[$key] ?? ''));
        if ($value !== '') $parts[] = $value;
    }
    return implode(', ', $parts);
}

function dcvsOsmPoiDistanceMeters($first, $second) {
    $latitude = deg2rad(((float)$first[1] + (float)$second[1]) / 2);
    $x = ((float)$first[0] - (float)$second[0]) * 111320 * cos($latitude);
    $y = ((float)$first[1] - (float)$second[1]) * 110540;
    return sqrt(($x * $x) + ($y * $y));
}

function dcvsOsmPoiNameKey($name) {
    $name = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)$name), 'UTF-8')
        : strtolower(trim((string)$name));
    return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
}

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required to refresh the OSM snapshot.');
    }

    $root = dirname(__DIR__);
    $officialBarangays = dcvsLoadOfficialBarangayPolygons(
        $root . '/DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
        $root . '/geo-map/barangays-boundary.json'
    );
    $query = <<<'OVERPASS'
[out:json][timeout:120];
area["name"="Dasmariñas"]["boundary"="administrative"]["admin_level"="6"]->.city;
(
  nwr(area.city)["name"]["amenity"~"^(restaurant|fast_food|cafe|food_court|bar|pub|ice_cream|school|college|university|kindergarten|hospital|clinic|doctors|dentist|pharmacy|veterinary|place_of_worship|townhall|community_centre|library|marketplace|bank|police|fire_station|post_office|fuel|cinema|theatre|museum|arts_centre|social_facility)$"];
  nwr(area.city)["name"]["healthcare"];
  nwr(area.city)["name"]["education"];
  nwr(area.city)["name"]["shop"];
  nwr(area.city)["name"]["leisure"];
  nwr(area.city)["name"]["sport"];
  nwr(area.city)["name"~"covered[ _-]+court",i];
  nwr(area.city)["name"]["tourism"];
  nwr(area.city)["name"]["historic"];
  nwr(area.city)["name"]["office"~"^(government|administrative|politician)$"];
  nwr(area.city)["name"]["government"];
);
out center tags;
OVERPASS;

    $curl = curl_init('https://overpass-api.de/api/interpreter');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'data=' . rawurlencode($query),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: DCVS-GeoMap-OSM-Snapshot/1.0 (https://github.com/dcvsdasmarinas-code/dcvs)',
        ],
    ]);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($responseBody === false) {
        throw new RuntimeException('Overpass request failed: ' . $curlError);
    }
    if ($httpStatus !== 200) {
        throw new RuntimeException('Overpass returned HTTP ' . $httpStatus . ': ' . substr((string)$responseBody, 0, 300));
    }

    $response = json_decode($responseBody, true);
    if (!is_array($response) || !isset($response['elements']) || !is_array($response['elements'])) {
        throw new RuntimeException('Overpass returned an invalid response: ' . json_last_error_msg());
    }
    if (!$response['elements']) {
        throw new RuntimeException('Overpass returned no named POIs; the existing snapshot was left unchanged.');
    }

    $features = [];
    $seen = [];
    $excluded = 0;
    $duplicates = 0;
    $categoryCounts = [];
    foreach ($response['elements'] as $element) {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $name = trim((string)($tags['name'] ?? ''));
        $category = dcvsOsmPoiCategory($tags, $name);
        $type = (string)($element['type'] ?? '');
        $id = (int)($element['id'] ?? 0);
        $latitude = $element['lat'] ?? ($element['center']['lat'] ?? null);
        $longitude = $element['lon'] ?? ($element['center']['lon'] ?? null);
        if ($name === '' || $category === null || !in_array($type, ['node', 'way', 'relation'], true)
            || $id <= 0 || !is_numeric($latitude) || !is_numeric($longitude)) {
            $excluded++;
            continue;
        }

        try {
            $barangay = dcvsResolveOfficialBarangayPin($longitude, $latitude, $officialBarangays);
        } catch (DomainException $error) {
            $excluded++;
            continue;
        } catch (InvalidArgumentException $error) {
            $excluded++;
            continue;
        }
        if (!$barangay) {
            $excluded++;
            continue;
        }

        $coordinates = [(float)$longitude, (float)$latitude];
        $dedupeKey = dcvsOsmPoiNameKey($name) . '|' . $category[0];
        $duplicate = false;
        foreach ($seen[$dedupeKey] ?? [] as $existingCoordinates) {
            if (dcvsOsmPoiDistanceMeters($coordinates, $existingCoordinates) <= 12) {
                $duplicate = true;
                break;
            }
        }
        if ($duplicate) {
            $duplicates++;
            continue;
        }
        $seen[$dedupeKey][] = $coordinates;

        $website = trim((string)($tags['contact:website'] ?? ($tags['website'] ?? '')));
        if ($website !== '' && !preg_match('~^https?://~i', $website)) $website = '';
        $phone = trim((string)($tags['contact:phone'] ?? ($tags['phone'] ?? '')));
        $openingHours = trim((string)($tags['opening_hours'] ?? ''));
        $address = dcvsOsmPoiAddress($tags);
        $osmUrl = 'https://www.openstreetmap.org/' . $type . '/' . $id;

        $properties = [
            'id' => 'osm:' . $type . ':' . $id,
            'name' => $name,
            'category' => $category[0],
            'category_label' => $category[1],
            'type_label' => ucfirst(str_replace('_', ' ', $category[2])),
            'subcategory' => $category[2],
            'address' => $address,
            'barangay' => $barangay['barangay_name'],
            'accuracy' => $type === 'node' ? 'mapped-point' : 'feature-center',
            'source' => 'OpenStreetMap',
            'osm_type' => $type,
            'osm_id' => $id,
            'osm_url' => $osmUrl,
        ];
        if ($website !== '') $properties['website'] = $website;
        if ($phone !== '') $properties['phone'] = $phone;
        if ($openingHours !== '') $properties['opening_hours'] = $openingHours;

        $features[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
            'properties' => $properties,
        ];
        $categoryCounts[$category[0]] = ($categoryCounts[$category[0]] ?? 0) + 1;
    }

    if (!$features) {
        throw new RuntimeException('No POIs passed the official barangay-boundary checks; the existing snapshot was left unchanged.');
    }
    usort($features, function($first, $second) {
        $barangayOrder = strcasecmp($first['properties']['barangay'], $second['properties']['barangay']);
        return $barangayOrder !== 0
            ? $barangayOrder
            : strcasecmp($first['properties']['name'], $second['properties']['name']);
    });

    $snapshot = [
        'type' => 'FeatureCollection',
        'metadata' => [
            'source' => 'OpenStreetMap contributors',
            'license' => 'Open Database License (ODbL) 1.0',
            'license_url' => 'https://opendatacommons.org/licenses/odbl/1-0/',
            'attribution_url' => 'https://www.openstreetmap.org/copyright',
            'city' => 'Dasmariñas, Cavite, Philippines',
            'updated_at' => gmdate('c'),
            'feature_count' => count($features),
            'category_counts' => $categoryCounts,
        ],
        'features' => $features,
    ];
    $json = json_encode(
        $snapshot,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) throw new RuntimeException('Could not encode the POI snapshot: ' . json_last_error_msg());

    $outputPath = $root . '/geo-map/osm-pois.geojson';
    $temporaryPath = tempnam(dirname($outputPath), '.osm-pois-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Could not write the temporary OSM snapshot.');
    }
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        unlink($temporaryPath);
        throw new RuntimeException('Could not write the temporary OSM snapshot.');
    }

    $backupPath = null;
    if (is_file($outputPath)) {
        $backupPath = $temporaryPath . '.previous';
        if (!rename($outputPath, $backupPath)) {
            unlink($temporaryPath);
            throw new RuntimeException('Could not safely replace the existing OSM snapshot.');
        }
    }
    if (!rename($temporaryPath, $outputPath)) {
        $restoreFailed = $backupPath !== null && !rename($backupPath, $outputPath);
        if (is_file($temporaryPath)) unlink($temporaryPath);
        throw new RuntimeException(
            'Could not install the new OSM snapshot.'
            . ($restoreFailed ? ' The previous snapshot remains at ' . $backupPath . '.' : '')
        );
    }
    if ($backupPath !== null && is_file($backupPath) && !unlink($backupPath)) {
        fwrite(STDERR, 'Warning: the updated snapshot is active, but the prior copy remains at ' . $backupPath . '.' . PHP_EOL);
    }

    fwrite(STDOUT, 'Saved ' . count($features) . ' boundary-checked OSM POIs to geo-map/osm-pois.geojson.' . PHP_EOL);
    fwrite(STDOUT, 'Excluded ' . $excluded . ' unclassified, invalid, ambiguous, or out-of-bound objects; merged ' . $duplicates . ' near-duplicate representations.' . PHP_EOL);
    fwrite(STDOUT, 'Categories: ' . json_encode($categoryCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    fwrite(STDOUT, 'Refresh manually; the map does not query the public Overpass service at runtime.' . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'OSM POI refresh failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
