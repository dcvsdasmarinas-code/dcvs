<?php
require_once __DIR__ . '/../includes/appointment-filters.php';

function expectAppointmentFilterCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$filters = dcvsAppointmentFilterState([
    'appt_service' => 'KAPON',
    'appt_status' => 'scheduled',
    'appt_from' => '2026-03-01',
    'appt_to' => '2026-03-31',
    'appt_search' => 'Fido_50%',
    'appt_reschedule_only' => '1',
]);
$filteredSql = dcvsAppointmentFilterSql($filters);
expectAppointmentFilterCondition(
    strpos($filteredSql['where'], 'a.appointment_type = ?') !== false,
    'Service filtering must use exact appointment_type equality.'
);
expectAppointmentFilterCondition(
    strpos($filteredSql['where'], "a.status IN ('approved','scheduled','in_progress')") !== false,
    'Scheduled status filtering must include its supported scheduled statuses.'
);
expectAppointmentFilterCondition(
    strpos($filteredSql['where'], 'COALESCE(a.scheduled_date, a.preferred_date) >= ?') !== false
        && strpos($filteredSql['where'], 'COALESCE(a.scheduled_date, a.preferred_date) <= ?') !== false,
    'The requested date range must be applied to the effective appointment date.'
);
expectAppointmentFilterCondition(
    strpos($filteredSql['where'], "appt_filter_request.status = 'pending'") !== false,
    'The reschedule filter must be part of the server-side query.'
);
expectAppointmentFilterCondition(
    $filteredSql['types'] === 'sssssss',
    'Prepared parameters must have the expected types.'
);
expectAppointmentFilterCondition(
    $filteredSql['params'] === [
        'kapon',
        '2026-03-01',
        '2026-03-31',
        '%Fido!_50!%%',
        '%Fido!_50!%%',
        '%Fido!_50!%%',
        '%Fido!_50!%%',
    ],
    'Prepared parameters must retain validated filters and escape LIKE wildcards.'
);

$serviceCountSql = dcvsAppointmentFilterSql($filters, false);
expectAppointmentFilterCondition(
    strpos($serviceCountSql['where'], 'a.appointment_type = ?') === false
        && count($serviceCountSql['params']) === 6,
    'Service-option counts must retain other filters while excluding the selected service.'
);

$invalidFilters = dcvsAppointmentFilterState([
    'appt_service' => 'other',
    'appt_status' => 'unknown',
    'appt_from' => '2026-04-15',
    'appt_to' => '2026-04-01',
]);
expectAppointmentFilterCondition(
    $invalidFilters['service'] === 'all'
        && $invalidFilters['status'] === 'all'
        && $invalidFilters['from'] === ''
        && $invalidFilters['to'] === ''
        && count($invalidFilters['errors']) === 3,
    'Invalid service, status, and reversed dates must be cleared with errors.'
);

$malformedFilters = dcvsAppointmentFilterState([
    'appt_service' => ['kapon'],
    'appt_search' => ['pet'],
    'appt_reschedule_only' => ['1'],
]);
expectAppointmentFilterCondition(
    $malformedFilters['service'] === 'all'
        && $malformedFilters['search'] === ''
        && !$malformedFilters['reschedule_only']
        && count($malformedFilters['errors']) === 3,
    'Non-scalar query values must be rejected and reported.'
);

$url = dcvsAppointmentFilterUrl($filters, 'adoption', 'appointment-overview');
parse_str((string)parse_url($url, PHP_URL_QUERY), $urlFilters);
expectAppointmentFilterCondition(
    ($urlFilters['appt_service'] ?? '') === 'adoption'
        && ($urlFilters['appt_status'] ?? '') === 'scheduled'
        && ($urlFilters['appt_reschedule_only'] ?? '') === '1'
        && substr($url, -strlen('#appointment-overview')) === '#appointment-overview',
    'Filter links must preserve state when switching service or Analytics views.'
);

echo "Appointment filter state, SQL, counts, and links passed.\n";
