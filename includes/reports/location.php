<?php

require_once dirname(__DIR__, 2) . '/env.php';

function dcvsLocationNameKey($name) {
    $name = trim((string)$name);
    return function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);
}

function dcvsLoadOfficialBarangayPolygons($crosswalkPath, $boundaryPath) {
    if (!is_file($crosswalkPath) || !is_readable($crosswalkPath)) {
        throw new RuntimeException('The official PSGC crosswalk is unavailable.');
    }
    if (!is_file($boundaryPath) || !is_readable($boundaryPath)) {
        throw new RuntimeException('The barangay boundary data is unavailable.');
    }

    $crosswalk = fopen($crosswalkPath, 'r');
    if ($crosswalk === false) {
        throw new RuntimeException('Could not open the official PSGC crosswalk.');
    }

    try {
        $headers = fgetcsv($crosswalk);
        if (!is_array($headers)) {
            throw new RuntimeException('The official PSGC crosswalk is empty.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $requiredHeaders = ['PSGC_10_Digit_Code', 'Official_Barangay_Name'];
        if (array_diff($requiredHeaders, $headers)) {
            throw new RuntimeException('The official PSGC crosswalk is missing required columns.');
        }

        $codeIndex = array_search('PSGC_10_Digit_Code', $headers, true);
        $nameIndex = array_search('Official_Barangay_Name', $headers, true);
        $official = [];
        $codes = [];
        $names = [];
        while (($row = fgetcsv($crosswalk)) !== false) {
            if (count($row) <= max($codeIndex, $nameIndex)) {
                throw new RuntimeException('The official PSGC crosswalk contains an incomplete row.');
            }
            $code = trim((string)$row[$codeIndex]);
            $name = trim((string)$row[$nameIndex]);
            $key = dcvsLocationNameKey($name);
            if (!preg_match('/^\d{10}$/', $code) || $name === ''
                || isset($codes[$code]) || isset($names[$key])) {
                throw new RuntimeException('The official PSGC crosswalk contains an invalid or duplicate code/name.');
            }
            $official[$key] = ['psgc_code' => $code, 'barangay_name' => $name];
            $codes[$code] = true;
            $names[$key] = true;
        }
    } finally {
        fclose($crosswalk);
    }

    if (count($official) !== 75) {
        throw new RuntimeException('The official PSGC crosswalk must contain exactly 75 barangays.');
    }

    $geojson = json_decode(file_get_contents($boundaryPath), true);
    if (!is_array($geojson) || !isset($geojson['features']) || !is_array($geojson['features'])) {
        throw new RuntimeException('The barangay boundary data is not a valid GeoJSON feature collection.');
    }
    if (count($geojson['features']) !== count($official)) {
        throw new RuntimeException('The boundary data does not contain exactly one polygon per official barangay.');
    }

    $matched = [];
    foreach ($geojson['features'] as $feature) {
        $properties = $feature['properties'] ?? [];
        $key = dcvsLocationNameKey($properties['name'] ?? '');
        if (!isset($official[$key]) || isset($matched[$key])) {
            throw new RuntimeException('A boundary name is missing from or duplicated in the official PSGC crosswalk.');
        }
        if (!isset($feature['geometry']) || !in_array($feature['geometry']['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
            throw new RuntimeException('A barangay boundary has an unsupported geometry type.');
        }

        $matched[$key] = [
            'type' => 'Feature',
            'properties' => [
                'psgc_code' => $official[$key]['psgc_code'],
                'barangay_name' => $official[$key]['barangay_name'],
                'polygon_name' => (string)$properties['name'],
            ],
            'geometry' => $feature['geometry'],
        ];
    }

    if (count($matched) !== 75) {
        throw new RuntimeException('Not all official PSGC barangays have a matching boundary polygon.');
    }

    return array_values($matched);
}

function dcvsPointOnRingSegment($longitude, $latitude, $start, $end) {
    $epsilon = 1.0e-10;
    $dx = (float)$end[0] - (float)$start[0];
    $dy = (float)$end[1] - (float)$start[1];
    $px = $longitude - (float)$start[0];
    $py = $latitude - (float)$start[1];
    if (abs($dx) <= $epsilon && abs($dy) <= $epsilon) {
        return ($px * $px) + ($py * $py) <= ($epsilon * $epsilon);
    }
    $cross = ($px * $dy) - ($py * $dx);
    if (abs($cross) > $epsilon) return false;

    $dot = ($px * $dx) + ($py * $dy);
    if ($dot < -$epsilon) return false;
    return $dot <= (($dx * $dx) + ($dy * $dy) + $epsilon);
}

function dcvsPointInRing($longitude, $latitude, $ring) {
    $inside = false;
    $count = count($ring);
    if ($count < 4) return false;

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $current = $ring[$i];
        $previous = $ring[$j];
        if (dcvsPointOnRingSegment($longitude, $latitude, $previous, $current)) return true;

        $crossesLatitude = ((float)$current[1] > $latitude) !== ((float)$previous[1] > $latitude);
        if ($crossesLatitude) {
            $intersection = ((float)$previous[0] - (float)$current[0])
                * ($latitude - (float)$current[1])
                / ((float)$previous[1] - (float)$current[1])
                + (float)$current[0];
            if ($longitude < $intersection) $inside = !$inside;
        }
    }

    return $inside;
}

function dcvsPointInPolygon($longitude, $latitude, $geometry) {
    $type = $geometry['type'] ?? '';
    $coordinates = $geometry['coordinates'] ?? [];
    $polygons = $type === 'Polygon' ? [$coordinates] : ($type === 'MultiPolygon' ? $coordinates : []);

    foreach ($polygons as $polygon) {
        if (!isset($polygon[0]) || !dcvsPointInRing($longitude, $latitude, $polygon[0])) continue;
        $insideHole = false;
        for ($i = 1; $i < count($polygon); $i++) {
            if (dcvsPointInRing($longitude, $latitude, $polygon[$i])) {
                $insideHole = true;
                break;
            }
        }
        if (!$insideHole) return true;
    }

    return false;
}

function dcvsResolveOfficialBarangayPin($longitude, $latitude, $features) {
    if (!is_numeric($longitude) || !is_numeric($latitude)) {
        throw new InvalidArgumentException('Pin coordinates must be numeric.');
    }
    $longitude = (float)$longitude;
    $latitude = (float)$latitude;
    if (!is_finite($longitude) || !is_finite($latitude)
        || $longitude < -180 || $longitude > 180
        || $latitude < -90 || $latitude > 90) {
        throw new InvalidArgumentException('Pin coordinates are outside the valid geographic range.');
    }

    $matches = [];
    foreach ($features as $feature) {
        if (dcvsPointInPolygon($longitude, $latitude, $feature['geometry'] ?? [])) {
            $matches[] = $feature['properties'];
        }
    }

    if (count($matches) > 1) {
        throw new DomainException('The pin is on an ambiguous barangay boundary. Move it slightly inside the intended barangay.');
    }
    return $matches[0] ?? null;
}

function dcvsApprovedBarangayPsgcMap($conn, $officialFeatures) {
    $result = $conn->query("SHOW COLUMNS FROM barangays LIKE 'psgc_code'");
    if (!$result) {
        throw new RuntimeException('Could not inspect barangay PSGC mapping readiness: ' . $conn->error);
    }
    $hasPsgcCode = $result->num_rows === 1;
    $result->free();

    $result = $conn->query("SHOW COLUMNS FROM barangays LIKE 'psgc_mapping_approved'");
    if (!$result) {
        throw new RuntimeException('Could not inspect barangay mapping approval readiness: ' . $conn->error);
    }
    $hasApproval = $result->num_rows === 1;
    $result->free();
    if (!$hasPsgcCode || !$hasApproval) return [];

    $result = $conn->query("SELECT barangay_id, barangay_name, psgc_code FROM barangays WHERE psgc_mapping_approved = 1 AND psgc_code IS NOT NULL");
    if (!$result) {
        throw new RuntimeException('Could not load approved barangay PSGC mappings: ' . $conn->error);
    }

    $approved = [];
    $ids = [];
    $officialNames = [];
    foreach ($officialFeatures as $feature) {
        $officialNames[$feature['properties']['psgc_code']] = $feature['properties']['barangay_name'];
    }
    while ($row = $result->fetch_assoc()) {
        $code = trim((string)$row['psgc_code']);
        $id = (int)$row['barangay_id'];
        if ($code === '' || !isset($officialNames[$code])
            || dcvsLocationNameKey($row['barangay_name']) !== dcvsLocationNameKey($officialNames[$code])
            || isset($approved[$code]) || isset($ids[$id])) {
            $result->free();
            return [];
        }
        $approved[$code] = $id;
        $ids[$id] = true;
    }
    $result->free();

    $officialCodes = array_fill_keys(array_keys($officialNames), true);
    if (count($approved) !== count($officialCodes)
        || array_diff_key($officialCodes, $approved)
        || array_diff_key($approved, $officialCodes)) {
        return [];
    }

    return $approved;
}

function dcvsLoadApprovedBarangays($conn) {
    $result = $conn->query(
        "SELECT barangay_id, barangay_name, psgc_code
         FROM barangays
         WHERE psgc_mapping_approved = 1 AND psgc_code IS NOT NULL
         ORDER BY barangay_name"
    );
    if (!$result) {
        throw new RuntimeException('Could not load approved PSGC barangays: ' . $conn->error);
    }

    $barangays = [];
    $codes = [];
    while ($row = $result->fetch_assoc()) {
        $code = trim((string)$row['psgc_code']);
        if (!preg_match('/^\d{10}$/', $code) || isset($codes[$code])) {
            $result->free();
            throw new RuntimeException('Approved PSGC barangays contain an invalid or duplicate code.');
        }
        $row['barangay_id'] = (int)$row['barangay_id'];
        $row['psgc_code'] = $code;
        $codes[$code] = true;
        $barangays[] = $row;
    }
    $result->free();

    if (count($barangays) !== 75) {
        throw new RuntimeException('Exactly 75 approved PSGC barangays are required.');
    }

    return $barangays;
}

function dcvsReportPinFeatureEnabled($conn, $officialFeatures) {
    loadDcvsEnv();
    if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') !== '1') return false;
    return count(dcvsApprovedBarangayPsgcMap($conn, $officialFeatures)) === 75;
}
