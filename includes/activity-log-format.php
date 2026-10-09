<?php

function dcvsActivityLogEntityMaps($conn, $activityRows) {
    $entityIds = ['reports' => [], 'cases' => [], 'pets' => [], 'users' => []];
    $appointmentRefs = [];
    foreach ($activityRows as $activity) {
        $details = (string)($activity['details'] ?? '');
        foreach ([
            'reports' => '/\breport\s+#(\d+)\b/i',
            'cases' => '/\bcase\s+#(\d+)\b/i',
            'pets' => '/\bpet\s+ID:\s*(\d+)\b/i',
            'users' => '/\buser\s+ID:\s*(\d+)\b/i',
        ] as $entity => $pattern) {
            if (preg_match_all($pattern, $details, $matches)) {
                foreach ($matches[1] as $id) $entityIds[$entity][(int)$id] = (int)$id;
            }
        }
        if (preg_match_all('/#?(APT-[A-Z0-9-]+)/i', $details, $matches)) {
            foreach ($matches[1] as $reference) $appointmentRefs[$reference] = '#' . $reference;
        }
    }

    $maps = ['reports' => [], 'cases' => [], 'pets' => [], 'users' => [], 'appointments' => []];
    $queries = [
        'reports' => ["SELECT r.report_id, r.report_type, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON u.user_id = r.reporter_id WHERE r.report_id IN (%s)", 'report_id'],
        'cases' => ["SELECT cc.case_id, r.report_type, u.full_name AS reporter_name FROM confirmed_cases cc JOIN reports r ON r.report_id = cc.report_id LEFT JOIN users u ON u.user_id = r.reporter_id WHERE cc.case_id IN (%s)", 'case_id'],
        'pets' => ["SELECT p.pet_id, p.pet_name, u.full_name AS owner_name FROM pets p LEFT JOIN users u ON u.user_id = p.owner_id WHERE p.pet_id IN (%s)", 'pet_id'],
        'users' => ["SELECT user_id, full_name FROM users WHERE user_id IN (%s)", 'user_id'],
    ];
    foreach ($queries as $entity => $query) {
        if (!$entityIds[$entity]) continue;
        $result = $conn->query(sprintf($query[0], implode(',', $entityIds[$entity])));
        if (!$result) {
            error_log('Failed to load activity log ' . $entity . ' names: ' . $conn->error);
            continue;
        }
        while ($row = $result->fetch_assoc()) {
            $key = (int)$row[$query[1]];
            $maps[$entity][$key] = $entity === 'users' ? $row['full_name'] : $row;
        }
        $result->free();
    }

    if ($appointmentRefs) {
        $references = array_values($appointmentRefs);
        $placeholders = implode(',', array_fill(0, count($references), '?'));
        $stmt = $conn->prepare("SELECT a.reference_id, a.appointment_type, u.full_name AS resident_name, p.pet_name FROM appointments a LEFT JOIN users u ON u.user_id = a.user_id LEFT JOIN pets p ON p.pet_id = a.pet_id WHERE a.reference_id IN ($placeholders)");
        if (!$stmt) {
            error_log('Failed to prepare activity log appointment lookup: ' . $conn->error);
        } else {
            $stmt->bind_param(str_repeat('s', count($references)), ...$references);
            if (!$stmt->execute()) {
                error_log('Failed to query activity log appointment names: ' . $stmt->error);
            } else {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) $maps['appointments'][ltrim($row['reference_id'], '#')] = $row;
            }
            $stmt->close();
        }
    }
    return $maps;
}

function dcvsActivityLogPersonLabel($residentName, $petName = '') {
    $label = $residentName ?: 'a resident';
    return $petName ? $label . ' (pet: ' . $petName . ')' : $label;
}

function dcvsActivityLogArticle($label) {
    return preg_match('/^[aeiou]/i', trim((string)$label)) ? 'an' : 'a';
}

function dcvsFormatActivityLogDetails($details, $maps) {
    $details = (string)$details;
    if (preg_match('/^Submitted\s+(.+?)\s+report\s+#(\d+)$/i', $details, $match)) {
        $report = $maps['reports'][(int)$match[2]] ?? null;
        $type = $report['report_type'] ?? $match[1];
        $reporter = $report['reporter_name'] ?? '';
        return 'Submitted ' . dcvsActivityLogArticle($type) . ' ' . ucfirst($type) . ' report' . ($reporter ? ' from ' . $reporter : '') . '.';
    }
    if (preg_match('/^(Approved|Rejected) appointment\s+#?(APT-[A-Z0-9-]+)\s+for\s+(.+)$/i', $details, $match)) {
        $appointment = $maps['appointments'][strtoupper($match[2])] ?? null;
        if ($appointment) {
            $suffix = $match[3];
            $suffix = preg_replace('/^' . preg_quote($appointment['appointment_type'], '/') . '\b/i', '', $suffix);
            if (!empty($appointment['resident_name'])) {
                $suffix = preg_replace('/^' . preg_quote($appointment['resident_name'], '/') . '\b/i', '', $suffix);
            }
            return $match[1] . ' ' . ucfirst($appointment['appointment_type']) . ' appointment for '
                . dcvsActivityLogPersonLabel($appointment['resident_name'], $appointment['pet_name']) . $suffix;
        }
    }
    if (preg_match('/^Scheduled\s+([a-z_-]+)\s+for\s+(.+?)\s+on\s+(\d{4}-\d{2}-\d{2})(?:\s+\(ID:\s*#?(APT-[A-Z0-9-]+)\))?(\s+at\s+[\d:]+\s+[AP]M)?\.\s*$/i', $details, $match)) {
        $appointment = !empty($match[4]) ? ($maps['appointments'][strtoupper($match[4])] ?? null) : null;
        if ($appointment) {
            $person = dcvsActivityLogPersonLabel($appointment['resident_name'], $appointment['pet_name']);
        } elseif (preg_match('/^User ID:\s*(\d+)$/i', $match[2], $userMatch)) {
            $person = $maps['users'][(int)$userMatch[1]] ?? 'a resident';
        } else {
            $person = $match[2];
        }
        $type = $appointment['appointment_type'] ?? $match[1];
        return 'Scheduled ' . ucfirst($type) . ' appointment for ' . $person . ' on ' . $match[3] . ($match[5] ?? '') . '.';
    }
    if (preg_match('/^Case\s+#(\d+)\s+resolved successfully\.?$/i', $details, $match)) {
        $case = $maps['cases'][(int)$match[1]] ?? null;
        if ($case) return 'Resolved the ' . ucfirst($case['report_type']) . ' case reported by ' . ($case['reporter_name'] ?: 'a resident') . '.';
        return 'Resolved the case.';
    }

    $details = preg_replace('/\s*\(ID:\s*#?APT-[A-Z0-9-]+\)/i', '', $details);
    $details = preg_replace_callback('/\breport\s+#(\d+)\b/i', function($match) use ($maps) {
        $report = $maps['reports'][(int)$match[1]] ?? null;
        return $report ? ucfirst($report['report_type']) . ' report from ' . ($report['reporter_name'] ?: 'a resident') : 'report';
    }, $details);
    $details = preg_replace_callback('/\bcase\s+#(\d+)\b/i', function($match) use ($maps) {
        $case = $maps['cases'][(int)$match[1]] ?? null;
        return $case ? ucfirst($case['report_type']) . ' case from ' . ($case['reporter_name'] ?: 'a resident') : 'case';
    }, $details);
    $details = preg_replace_callback('/\bpet\s+ID:\s*(\d+)\b/i', function($match) use ($maps) {
        $pet = $maps['pets'][(int)$match[1]] ?? null;
        return $pet ? $pet['pet_name'] : 'the selected pet';
    }, $details);
    $details = preg_replace_callback('/\buser\s+ID:\s*(\d+)\b/i', function($match) use ($maps) {
        return $maps['users'][(int)$match[1]] ?? 'a resident';
    }, $details);
    $details = preg_replace_callback('/\(#?APT-[A-Z0-9-]+\)/i', function($match) use ($maps) {
        if (!preg_match('/APT-[A-Z0-9-]+/i', $match[0], $reference)) return '';
        $appointment = $maps['appointments'][$reference[0]] ?? null;
        return $appointment ? '(' . ucfirst($appointment['appointment_type']) . ' appointment for ' . dcvsActivityLogPersonLabel($appointment['resident_name'], $appointment['pet_name']) . ')' : '';
    }, $details);
    $details = preg_replace_callback('/\bappointment\s+#?(#?APT-[A-Z0-9-]+)/i', function($match) use ($maps) {
        $reference = ltrim(strtoupper($match[1]), '#');
        $appointment = $maps['appointments'][$reference] ?? null;
        return $appointment
            ? ucfirst($appointment['appointment_type']) . ' appointment for ' . dcvsActivityLogPersonLabel($appointment['resident_name'], $appointment['pet_name'])
            : 'appointment';
    }, $details);
    $details = preg_replace_callback('/\buser\s+ID:\s*(\d+)\b/i', function($match) use ($maps) {
        return $maps['users'][(int)$match[1]] ?? 'a resident';
    }, $details);
    $details = preg_replace('/\bpet\s+ID:\s*\d+\b/i', 'the selected pet', $details);
    $details = preg_replace('/#?APT-[A-Z0-9-]+/i', '', $details);
    return trim(preg_replace('/\s{2,}/', ' ', $details));
}
