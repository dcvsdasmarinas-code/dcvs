<?php

function dcvsReportDuplicateSettings(mysqli $conn): array {
    $result = $conn->query("SELECT radius_meters, window_days FROM report_duplicate_settings WHERE setting_id = 1");
    if (!$result) {
        error_log('Could not load report duplicate settings: ' . $conn->error);
        throw new RuntimeException('Could not load duplicate-report settings.');
    }
    $settings = $result->fetch_assoc();
    $result->free();
    if (!$settings) {
        error_log('Report duplicate settings row 1 is missing.');
        throw new RuntimeException('Duplicate-report settings are not configured.');
    }

    $radius = (int)$settings['radius_meters'];
    $windowDays = (int)$settings['window_days'];
    if ($radius < 25 || $radius > 5000 || $windowDays < 1 || $windowDays > 90) {
        error_log('Report duplicate settings are outside the supported range.');
        throw new RuntimeException('Duplicate-report settings are invalid.');
    }
    return ['radius_meters' => $radius, 'window_days' => $windowDays];
}

function dcvsReportDuplicateDistanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float {
    $earthRadiusMeters = 6371000;
    $latitudeDelta = deg2rad($latitudeB - $latitudeA);
    $longitudeDelta = deg2rad($longitudeB - $longitudeA);
    $a = sin($latitudeDelta / 2) ** 2
        + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;
    return 2 * $earthRadiusMeters * asin(min(1, sqrt($a)));
}

function dcvsReportDuplicateCandidates(
    mysqli $conn,
    int $barangayId,
    string $reportType,
    float $latitude,
    float $longitude,
    ?int $excludeReportId = null
): array {
    if ($barangayId <= 0
        || !in_array($reportType, ['stray', 'rabies', 'injured', 'dead'], true)
        || !is_finite($latitude) || !is_finite($longitude)
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180) {
        throw new InvalidArgumentException('Invalid report location or type for duplicate detection.');
    }

    $settings = dcvsReportDuplicateSettings($conn);
    $radius = $settings['radius_meters'];
    $latitudePadding = $radius / 111320;
    $longitudePadding = min(180, $radius / (111320 * max(0.01, abs(cos(deg2rad($latitude))))));
    $latitudeMin = max(-90, $latitude - $latitudePadding);
    $latitudeMax = min(90, $latitude + $latitudePadding);
    $longitudeMin = max(-180, $longitude - $longitudePadding);
    $longitudeMax = min(180, $longitude + $longitudePadding);

    $query = $conn->prepare("
        SELECT r.report_id, r.report_type, r.status, r.created_at,
               r.coordinates_lat, r.coordinates_long,
               (
                   SELECT a.attachment_id
                   FROM report_attachments a
                   WHERE a.report_id = r.report_id
                     AND a.attachment_type = 'photo'
                     AND a.attachment_source = 'resident'
                   ORDER BY a.display_order ASC, a.attachment_id ASC
                   LIMIT 1
               ) AS photo_attachment_id
        FROM reports r
        INNER JOIN report_duplicate_statuses eligible_status
            ON eligible_status.report_status = r.status AND eligible_status.is_enabled = 1
        WHERE r.barangay_id = ?
          AND r.report_type = ?
          AND r.archived_at IS NULL
          AND r.parent_report_id IS NULL
          AND r.duplicate_status IN ('none', 'linked')
          AND r.coordinates_lat BETWEEN ? AND ?
          AND r.coordinates_long BETWEEN ? AND ?
          AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (? IS NULL OR r.report_id <> ?)
    ");
    if (!$query) {
        error_log('Could not prepare report duplicate candidates query: ' . $conn->error);
        throw new RuntimeException('Could not check for similar reports.');
    }
    $query->bind_param(
        'isddddiii',
        $barangayId,
        $reportType,
        $latitudeMin,
        $latitudeMax,
        $longitudeMin,
        $longitudeMax,
        $settings['window_days'],
        $excludeReportId,
        $excludeReportId
    );
    if (!$query->execute()) {
        error_log('Could not execute report duplicate candidates query: ' . $query->error);
        $query->close();
        throw new RuntimeException('Could not check for similar reports.');
    }
    $result = $query->get_result();
    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $distance = dcvsReportDuplicateDistanceMeters(
            $latitude,
            $longitude,
            (float)$row['coordinates_lat'],
            (float)$row['coordinates_long']
        );
        if ($distance > $radius) {
            continue;
        }
        $row['distance_meters'] = (int)round($distance);
        $row['photo_attachment_id'] = $row['photo_attachment_id'] !== null
            ? (int)$row['photo_attachment_id']
            : null;
        $candidates[] = $row;
    }
    $query->close();

    usort($candidates, static function (array $left, array $right): int {
        $distanceOrder = $left['distance_meters'] <=> $right['distance_meters'];
        return $distanceOrder !== 0
            ? $distanceOrder
            : strcmp((string)$right['created_at'], (string)$left['created_at']);
    });
    return array_slice($candidates, 0, 5);
}

function dcvsReportDuplicateLockCandidate(
    mysqli $conn,
    int $reportId,
    int $barangayId,
    string $reportType,
    float $latitude,
    float $longitude
): bool {
    $settings = dcvsReportDuplicateSettings($conn);
    $query = $conn->prepare("
        SELECT r.coordinates_lat, r.coordinates_long
        FROM reports r
        INNER JOIN report_duplicate_statuses eligible_status
            ON eligible_status.report_status = r.status AND eligible_status.is_enabled = 1
        WHERE r.report_id = ?
          AND r.barangay_id = ?
          AND r.report_type = ?
          AND r.archived_at IS NULL
          AND r.parent_report_id IS NULL
          AND r.duplicate_status IN ('none', 'linked')
          AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        FOR UPDATE
    ");
    if (!$query) {
        error_log('Could not prepare report duplicate candidate lock: ' . $conn->error);
        throw new RuntimeException('Could not validate the selected similar report.');
    }
    $query->bind_param('iisi', $reportId, $barangayId, $reportType, $settings['window_days']);
    if (!$query->execute()) {
        error_log('Could not lock report duplicate candidate: ' . $query->error);
        $query->close();
        throw new RuntimeException('Could not validate the selected similar report.');
    }
    $candidate = $query->get_result()->fetch_assoc();
    $query->close();
    if (!$candidate || $candidate['coordinates_lat'] === null || $candidate['coordinates_long'] === null) {
        return false;
    }
    return dcvsReportDuplicateDistanceMeters(
        $latitude,
        $longitude,
        (float)$candidate['coordinates_lat'],
        (float)$candidate['coordinates_long']
    ) <= $settings['radius_meters'];
}

function dcvsReportSubmissionFingerprint(array $fields): string {
    $normalized = [];
    foreach ([
        'reporter_id',
        'report_type',
        'report_datetime',
        'animal_type',
        'animal_breed',
        'animal_count',
        'animal_behavior',
        'barangay_id',
        'location_detail',
        'description',
        'latitude',
        'longitude',
    ] as $key) {
        $value = trim((string)($fields[$key] ?? ''));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $normalized[$key] = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

function dcvsNotifyMergedReporters(mysqli $conn, int $parentReportId, string $newStatus): void {
    $query = $conn->prepare("
        SELECT DISTINCT child.reporter_id, parent.reference_id
        FROM reports child
        INNER JOIN reports parent ON parent.report_id = child.parent_report_id
        WHERE child.parent_report_id = ?
          AND child.duplicate_status = 'merged'
          AND child.archived_at IS NULL
          AND child.reporter_id <> parent.reporter_id
    ");
    if (!$query) {
        error_log('Could not prepare supporting reporter notifications: ' . $conn->error);
        throw new RuntimeException('Could not notify residents who supported this report.');
    }
    $query->bind_param('i', $parentReportId);
    if (!$query->execute()) {
        error_log('Could not load supporting reporter notifications: ' . $query->error);
        $query->close();
        throw new RuntimeException('Could not notify residents who supported this report.');
    }
    $supporters = $query->get_result();
    $referenceId = 'RPT-' . $parentReportId;
    while ($supporter = $supporters->fetch_assoc()) {
        if (!empty($supporter['reference_id'])) {
            $referenceId = (string)$supporter['reference_id'];
        }
        createNotification(
            $conn,
            (int)$supporter['reporter_id'],
            'report_response_update',
            'Update on a Similar Report',
            'The status of the report you supported (' . $referenceId . ') changed to ' . ucfirst($newStatus) . '.',
            'report',
            $parentReportId
        );
    }
    $query->close();
}
