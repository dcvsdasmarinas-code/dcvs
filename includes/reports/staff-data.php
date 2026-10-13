<?php

function dcvsStaffValidatedReportsQuery($conn) {
    return $conn->query("SELECT r.*, u.full_name AS reporter_name, u.phone AS reporter_phone, u.address AS reporter_address, b.barangay_name
        FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
        WHERE r.status = 'validated' AND r.archived_at IS NULL
          AND u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY r.validated_at DESC");
}

function dcvsStaffBarangayReportsQuery($conn) {
    return $conn->query("SELECT r.*, u.full_name AS reporter_name, u.phone AS reporter_phone, b.barangay_name
        FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
        WHERE r.archived_at IS NULL
          AND u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY r.created_at ASC");
}

function dcvsStaffActiveCasesQuery($conn) {
    return $conn->query("SELECT cc.*, r.reference_id AS report_ref, r.report_type, r.location_address, b.barangay_name, u.full_name AS reporter_name
        FROM confirmed_cases cc
        JOIN reports r ON cc.report_id = r.report_id
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON cc.barangay_id = b.barangay_id
        WHERE r.status = 'confirmed' AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY r.confirmed_at ASC");
}

function dcvsStaffResolvedCasesQuery($conn) {
    return $conn->query("SELECT cc.*, r.reference_id AS report_ref, r.report_type, r.location_address, b.barangay_name, u.full_name AS reporter_name
        FROM confirmed_cases cc
        JOIN reports r ON cc.report_id = r.report_id
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON cc.barangay_id = b.barangay_id
        WHERE r.status = 'resolved' AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY cc.resolved_at DESC");
}

function dcvsStaffReportItemsQuery($conn) {
    $reportColumns = $conn->query("SHOW COLUMNS FROM reports");
    if (!$reportColumns) {
        error_log('Could not inspect report columns for staff reports: ' . $conn->error);
        return false;
    }
    $availableColumns = [];
    while ($column = $reportColumns->fetch_assoc()) {
        $availableColumns[$column['Field']] = true;
    }
    $validationStatusColumn = isset($availableColumns['barangay_validation_status'])
        ? 'r.barangay_validation_status'
        : 'NULL';
    $validationNotesColumn = isset($availableColumns['barangay_validation_notes'])
        ? 'r.barangay_validation_notes'
        : 'NULL';
    $turnoverColumnsResult = $conn->query("SHOW COLUMNS FROM report_turnover_tasks");
    if (!$turnoverColumnsResult) {
        error_log('Could not inspect turnover task fields for staff reports: ' . $conn->error);
        return false;
    }
    $turnoverColumns = [];
    while ($column = $turnoverColumnsResult->fetch_assoc()) {
        $turnoverColumns[$column['Field']] = true;
    }
    $turnoverColumnsResult->close();
    $turnoverColumn = static function (string $column, string $alias, string $fallback = 'NULL') use (&$turnoverColumns): string {
        return isset($turnoverColumns[$column]) ? 'tt.' . $column . ' AS ' . $alias : $fallback . ' AS ' . $alias;
    };

    return $conn->query("
        SELECT r.report_id, r.reference_id, r.report_type, r.barangay_id, r.animal_type, r.animal_breed, r.animal_count, r.animal_behavior, r.description, r.location_address,
               r.status, r.urgency_level, r.photo_url, r.video_url, r.incident_at, r.created_at, r.updated_at, r.scheduled_at,
               r.validated_at,
               r.barangay_notes, {$validationStatusColumn} AS barangay_validation_status,
               {$validationNotesColumn} AS barangay_validation_notes,
               r.dcvs_notes, r.rejection_reason,
               u.user_id AS reporter_id, u.reference_id AS reporter_ref, u.full_name AS reporter_name,
               u.phone AS reporter_phone, u.email AS reporter_email, u.avatar_url,
               CASE WHEN u.status = 'active' AND u.deleted_at IS NULL THEN 'active' ELSE 'inactive' END AS reporter_status,
               b.barangay_name,
               cc.case_id, cc.status AS case_status, cc.confirmed_at, cc.resolved_at,
               cc.notes AS case_notes,
               rw.response_mode, rw.state AS response_state, rw.barangay_proposed,
               rw.response_deadline, rw.progress_deadline, rw.last_progress_at, rw.urgent_escalated_at,
               rw.turnover_deadline, rw.secured_at, rw.holding_location, rw.animal_condition,
               rw.high_risk_override_by, rw.high_risk_override_reason, hov.full_name AS high_risk_override_staff,
               rw.dispatch_officer_id, dos.full_name AS dispatch_officer_name,
               rw.turnover_requested_at, rw.turnover_received_at,
               rr.resolution_details, rr.animal_disposition, rr.resolved_at AS response_resolved_at,
               rru.full_name AS resolution_actor,
               tt.turnover_task_id, tt.status AS turnover_status, tt.due_at AS turnover_due_at,
               {$turnoverColumn('handover_method', 'turnover_method')},
               {$turnoverColumn('planned_at', 'turnover_planned_at')},
               {$turnoverColumn('handler_name', 'turnover_handler_name')},
               {$turnoverColumn('contact_number', 'turnover_contact_number')},
               {$turnoverColumn('pickup_location', 'turnover_pickup_location')},
               {$turnoverColumn('safe_transport', 'turnover_safe_transport', '0')},
               tt.received_at AS turnover_receipt_at, ttu.full_name AS turnover_receiver,
               cs.full_name AS confirmed_staff, vs.full_name AS validated_staff
        FROM reports r
        JOIN users u ON r.reporter_id = u.user_id
        LEFT JOIN barangays b ON r.barangay_id = b.barangay_id
        LEFT JOIN confirmed_cases cc ON r.report_id = cc.report_id
        LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id
        LEFT JOIN users cs ON r.confirmed_by = cs.user_id
        LEFT JOIN users vs ON r.validated_by = vs.user_id
        LEFT JOIN users hov ON rw.high_risk_override_by = hov.user_id
        LEFT JOIN users dos ON rw.dispatch_officer_id = dos.user_id
        LEFT JOIN report_response_resolutions rr ON r.report_id = rr.report_id
        LEFT JOIN users rru ON rr.resolved_by = rru.user_id
        LEFT JOIN report_turnover_tasks tt ON r.report_id = tt.report_id
        LEFT JOIN users ttu ON tt.received_by = ttu.user_id
        WHERE r.archived_at IS NULL
        ORDER BY r.created_at DESC
    ");
}
