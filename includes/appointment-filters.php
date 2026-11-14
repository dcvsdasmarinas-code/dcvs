<?php

function dcvsAppointmentFilterState(array $query): array
{
    $errors = [];
    $serviceValue = $query['appt_service'] ?? 'all';
    $service = is_scalar($serviceValue) ? strtolower(trim((string)$serviceValue)) : '';
    $allowedServices = ['all', 'kapon', 'adoption', 'checkup', 'deworming'];
    if (!in_array($service, $allowedServices, true)) {
        $service = 'all';
        $errors[] = 'The selected service filter was invalid and has been cleared.';
    }

    $statusValue = $query['appt_status'] ?? 'all';
    $status = is_scalar($statusValue) ? strtolower(trim((string)$statusValue)) : '';
    $allowedStatuses = [
        'all', 'pending', 'scheduled', 'completed', 'cancelled',
        'rescheduled', 'no_show', 'action_needed', 'rejected', 'waitlisted',
    ];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'all';
        $errors[] = 'The selected status filter was invalid and has been cleared.';
    }

    $fromValue = $query['appt_from'] ?? '';
    $toValue = $query['appt_to'] ?? '';
    $from = is_scalar($fromValue) ? trim((string)$fromValue) : '';
    $to = is_scalar($toValue) ? trim((string)$toValue) : '';
    if (!is_scalar($fromValue) || !is_scalar($toValue)) {
        $errors[] = 'An invalid date filter was ignored.';
    }
    $isValidDate = static function (string $date): bool {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
    };
    if ($from !== '' && !$isValidDate($from)) {
        $from = '';
        $errors[] = 'An invalid date filter was ignored.';
    }
    if ($to !== '' && !$isValidDate($to)) {
        $to = '';
        $errors[] = 'An invalid date filter was ignored.';
    }
    if ($from !== '' && $to !== '' && $from > $to) {
        $from = '';
        $to = '';
        $errors[] = 'The date range was invalid and has been cleared.';
    }

    $searchValue = $query['appt_search'] ?? '';
    $search = is_scalar($searchValue) ? trim((string)$searchValue) : '';
    if (!is_scalar($searchValue)) {
        $errors[] = 'The search filter was invalid and has been cleared.';
    }
    if (function_exists('mb_substr')) {
        $search = mb_substr($search, 0, 100, 'UTF-8');
    } else {
        $search = substr($search, 0, 100);
    }

    $rescheduleValue = $query['appt_reschedule_only'] ?? '';
    $rescheduleOnlyValue = is_scalar($rescheduleValue) ? strtolower(trim((string)$rescheduleValue)) : '';
    if (!is_scalar($rescheduleValue)) {
        $errors[] = 'The reschedule-request filter was invalid and has been cleared.';
    }
    if ($rescheduleOnlyValue !== '' && $rescheduleOnlyValue !== '1') {
        $errors[] = 'The reschedule-request filter was invalid and has been cleared.';
    }

    return [
        'service' => $service,
        'status' => $status,
        'from' => $from,
        'to' => $to,
        'search' => $search,
        'reschedule_only' => $rescheduleOnlyValue === '1',
        'errors' => $errors,
    ];
}

function dcvsAppointmentFilterSql(array $filters, bool $includeService = true): array
{
    $conditions = [
        'a.archived_at IS NULL',
        "u.status = 'active'",
        'u.deleted_at IS NULL',
    ];
    $types = '';
    $params = [];

    if ($includeService && $filters['service'] !== 'all') {
        $conditions[] = 'a.appointment_type = ?';
        $types .= 's';
        $params[] = $filters['service'];
    }

    $statusConditions = [
        'pending' => "a.status = 'pending'",
        'scheduled' => "a.status IN ('approved','scheduled','in_progress')",
        'completed' => "a.status = 'completed'",
        'cancelled' => "a.status = 'cancelled'",
        'rescheduled' => "a.status = 'rescheduled'",
        'no_show' => "a.status = 'no_show'",
        'action_needed' => "a.status = 'action_needed'",
        'rejected' => "a.status = 'rejected'",
        'waitlisted' => "a.status = 'waitlisted'",
    ];
    if ($filters['status'] !== 'all') {
        $conditions[] = $statusConditions[$filters['status']];
    }

    if ($filters['from'] !== '') {
        $conditions[] = 'COALESCE(a.scheduled_date, a.preferred_date) >= ?';
        $types .= 's';
        $params[] = $filters['from'];
    }
    if ($filters['to'] !== '') {
        $conditions[] = 'COALESCE(a.scheduled_date, a.preferred_date) <= ?';
        $types .= 's';
        $params[] = $filters['to'];
    }
    if ($filters['reschedule_only']) {
        $conditions[] = "EXISTS (
            SELECT 1 FROM appointment_reschedule_requests appt_filter_request
            WHERE appt_filter_request.appointment_id = a.appointment_id
              AND appt_filter_request.status = 'pending'
        )";
    }
    if ($filters['search'] !== '') {
        $conditions[] = "(CAST(a.appointment_id AS CHAR) LIKE ? ESCAPE '!'
            OR u.full_name LIKE ? ESCAPE '!'
            OR a.appointment_type LIKE ? ESCAPE '!'
            OR EXISTS (
                SELECT 1 FROM pets appt_filter_pet
                WHERE appt_filter_pet.pet_id = a.pet_id
                  AND appt_filter_pet.pet_name LIKE ? ESCAPE '!'
            ))";
        $escapedSearch = strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']);
        $searchTerm = '%' . $escapedSearch . '%';
        $types .= 'ssss';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    return [
        'where' => implode(' AND ', $conditions),
        'types' => $types,
        'params' => $params,
    ];
}

function dcvsAppointmentExecuteFilteredQuery(mysqli $connection, string $sql, array $filter): ?mysqli_stmt
{
    $statement = $connection->prepare($sql);
    if (!$statement) {
        error_log('Could not prepare appointment filter query: ' . $connection->error);
        return null;
    }

    if ($filter['types'] !== '') {
        $arguments = [$filter['types']];
        foreach ($filter['params'] as $index => $value) {
            $arguments[] = &$filter['params'][$index];
        }
        if (!call_user_func_array([$statement, 'bind_param'], $arguments)) {
            error_log('Could not bind appointment filter parameters: ' . $statement->error);
            $statement->close();
            return null;
        }
    }

    if (!$statement->execute()) {
        error_log('Could not execute appointment filter query: ' . $statement->error);
        $statement->close();
        return null;
    }

    return $statement;
}

function dcvsAppointmentFilterUrl(array $filters, string $service, string $section): string
{
    $query = [
        'appt_service' => $service,
        'appt_status' => $filters['status'],
    ];
    if ($filters['from'] !== '') $query['appt_from'] = $filters['from'];
    if ($filters['to'] !== '') $query['appt_to'] = $filters['to'];
    if ($filters['search'] !== '') $query['appt_search'] = $filters['search'];
    if ($filters['reschedule_only']) $query['appt_reschedule_only'] = '1';

    return 'dashboard-staff.php?' . http_build_query($query) . '#' . rawurlencode($section);
}
