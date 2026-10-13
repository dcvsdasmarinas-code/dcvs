<?php

function loadBarangayAnnouncementsForPortal(mysqli $conn, int $barangayId, int $limit = 0): array
{
    $limit = max(0, $limit);
    $sql = "
        SELECT a.announcement_id, a.title, a.location, a.content, a.category, a.subject,
               a.link_url, a.photo_url, a.created_at, a.publish_at, a.expiry_date,
               a.is_event, a.event_date, a.event_end_date, a.event_start_time, a.event_end_time,
               a.venue_provider, a.venue_name, a.response_deadline,
               er.status AS event_response_status, er.response_note AS event_response_note,
               u.full_name AS creator_name, b.barangay_name
        FROM announcements a
        LEFT JOIN users u ON u.user_id = a.created_by
        INNER JOIN barangays b ON b.barangay_id = ?
        LEFT JOIN announcement_event_barangays et
          ON et.announcement_id = a.announcement_id AND et.barangay_id = b.barangay_id
        LEFT JOIN announcement_event_responses er
          ON er.announcement_id = a.announcement_id AND er.barangay_id = b.barangay_id
        WHERE a.is_active = 1
          AND a.archived_at IS NULL
          AND (a.status = 'published'
               OR (a.status = 'scheduled' AND a.publish_at IS NOT NULL AND a.publish_at <= NOW()))
          AND (a.publish_at IS NULL OR a.publish_at <= NOW())
          AND (a.expiry_date IS NULL OR a.expiry_date >= CURDATE())
          AND (
              (a.is_event = 1 AND et.barangay_id IS NOT NULL)
              OR (a.is_event = 0 AND a.location IS NOT NULL AND TRIM(a.location) <> '')
          )
        ORDER BY COALESCE(a.publish_at, a.created_at) DESC, a.announcement_id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare barangay announcements query: ' . $conn->error);
    }

    $stmt->bind_param('i', $barangayId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not load barangay announcements: ' . $error);
    }

    $result = $stmt->get_result();
    if (!$result) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not read barangay announcements: ' . $error);
    }

    $announcements = [];
    while ($row = $result->fetch_assoc()) {
        if (!(int)$row['is_event']
            && !barangayAnnouncementLocationMatches((string)$row['location'], (string)$row['barangay_name'])) {
            continue;
        }

        unset($row['barangay_name']);
        $announcements[] = $row;
        if ($limit > 0 && count($announcements) >= $limit) {
            break;
        }
    }
    $stmt->close();

    if ($announcements) {
        $eventIds = [];
        foreach ($announcements as $announcement) {
            if ((int)$announcement['is_event'] === 1) $eventIds[] = (int)$announcement['announcement_id'];
        }
        if ($eventIds) {
            $eventIdList = implode(',', array_unique($eventIds));
            $requirementsQuery = $conn->query(
                "SELECT q.announcement_id, q.requirement_id, q.requirement_label, q.quantity,
                        q.is_required, rr.is_ready
                 FROM announcement_event_requirements q
                 LEFT JOIN announcement_event_requirement_responses rr
                   ON rr.announcement_id = q.announcement_id
                  AND rr.requirement_id = q.requirement_id
                  AND rr.barangay_id = " . (int)$barangayId . "
                 WHERE q.announcement_id IN ($eventIdList)
                 ORDER BY q.announcement_id, q.sort_order, q.requirement_id"
            );
            if (!$requirementsQuery) {
                throw new RuntimeException('Could not load event preparedness requirements: ' . $conn->error);
            }
            $requirementsByEvent = [];
            while ($requirement = $requirementsQuery->fetch_assoc()) {
                $eventId = (int)$requirement['announcement_id'];
                $requirementsByEvent[$eventId][] = [
                    'requirement_id' => (int)$requirement['requirement_id'],
                    'requirement_label' => $requirement['requirement_label'],
                    'quantity' => $requirement['quantity'] === null ? null : (int)$requirement['quantity'],
                    'is_required' => (int)$requirement['is_required'],
                    'is_ready' => (int)($requirement['is_ready'] ?? 0) === 1
                ];
            }
            $requirementsQuery->free();
            foreach ($announcements as &$announcement) {
                if ((int)$announcement['is_event'] === 1) {
                    $announcement['event_requirements'] = $requirementsByEvent[(int)$announcement['announcement_id']] ?? [];
                }
            }
            unset($announcement);
        }
    }

    return $announcements;
}

function barangayAnnouncementLocationMatches(string $location, string $barangayName): bool
{
    $normalize = static function (string $value): string {
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $value = preg_replace('/\b(?:brgy|barangay)\b\.?/i', ' ', $value);
        $value = preg_replace_callback('/\b(iv|iii|ii|i)\b/i', static function (array $match): string {
            return (string)['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4][strtolower($match[1])];
        }, $value);
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    };

    $normalizedLocation = $normalize($location);
    $normalizedBarangay = $normalize($barangayName);

    return $normalizedLocation !== ''
        && $normalizedBarangay !== ''
        && strpos(' ' . $normalizedLocation . ' ', ' ' . $normalizedBarangay . ' ') !== false;
}
