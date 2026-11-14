<?php
$requestedPortal = trim((string)($_POST['portal'] ?? $_GET['portal'] ?? ''));
$portalSessions = [
    'staff' => ['session' => 'DCVS_STAFF_SESSION', 'role' => 'staff'],
    'barangay' => ['session' => 'DCVS_BARANGAY_SESSION', 'role' => 'barangay'],
];
if (!isset($portalSessions[$requestedPortal])) {
    http_response_code(400);
    exit('Invalid portal.');
}
session_name($portalSessions[$requestedPortal]['session']);
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reports/duplicates.php';

$portal = $portalSessions[$requestedPortal];
$queueUrl = 'possible-duplicates.php?portal=' . rawurlencode($requestedPortal);
$fail = static function (string $message) use ($queueUrl): void {
    $_SESSION['report_duplicate_error'] = $message;
    header('Location: ' . $queueUrl, true, 303);
    exit;
};

if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== $portal['role']) {
    http_response_code(401);
    exit('Unauthorized.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Use the Similar Reports page to record a review action.');
}
if (!validateCsrf($_POST['csrf_token'] ?? null)) {
    $fail('Your session could not be verified. Refresh and try again.');
}

$reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
$parentReportId = filter_var($_POST['parent_report_id'] ?? null, FILTER_VALIDATE_INT);
$action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
$notes = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';
$notesLength = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
if (!$reportId || $reportId <= 0 || !$parentReportId || $parentReportId <= 0 || $reportId === $parentReportId) {
    $fail('Select a valid pair of reports.');
}
if (!in_array($action, ['link', 'merge', 'not_duplicate'], true) || $notesLength > 1000) {
    $fail('Choose a valid action and keep the note under 1,000 characters.');
}
$userId = (int)($_SESSION['user_id'] ?? 0);
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
if ($userId <= 0 || ($portal['role'] === 'barangay' && $barangayId <= 0)) {
    $fail('Your account scope is missing. Sign in again.');
}

if (!$conn->begin_transaction()) {
    error_log('Could not begin duplicate review transaction: ' . $conn->error);
    $fail('Could not start the report review. Please try again.');
}
try {
    $childQuery = $conn->prepare("
        SELECT report_id, reporter_id, report_type, status, duplicate_status,
               parent_report_id, barangay_id, archived_at
        FROM reports
        WHERE report_id = ? AND archived_at IS NULL
        FOR UPDATE
    ");
    if (!$childQuery) throw new RuntimeException('Could not prepare report review lookup.');
    $childQuery->bind_param('i', $reportId);
    if (!$childQuery->execute()) throw new RuntimeException('Could not load the report for review.');
    $child = $childQuery->get_result()->fetch_assoc();
    $childQuery->close();
    if (!$child
        || ($portal['role'] === 'barangay' && (int)$child['barangay_id'] !== $barangayId)) {
        throw new DomainException('The report is not available in your review scope.');
    }

    $parentQuery = $conn->prepare("
        SELECT report_id, reporter_id, report_type, status, duplicate_status,
               parent_report_id, barangay_id, archived_at
        FROM reports
        WHERE report_id = ? AND archived_at IS NULL
        FOR UPDATE
    ");
    if (!$parentQuery) throw new RuntimeException('Could not prepare suggested parent lookup.');
    $parentQuery->bind_param('i', $parentReportId);
    if (!$parentQuery->execute()) throw new RuntimeException('Could not load the suggested primary report.');
    $parent = $parentQuery->get_result()->fetch_assoc();
    $parentQuery->close();
    if (!$parent
        || (int)$child['parent_report_id'] !== $parentReportId
        || (int)$child['barangay_id'] !== (int)$parent['barangay_id']
        || $child['report_type'] !== $parent['report_type']
        || $parent['parent_report_id'] !== null
        || $parent['duplicate_status'] === 'merged') {
        throw new DomainException('The suggested report pair is no longer eligible for review.');
    }
    if ($action !== 'not_duplicate') {
        if (!in_array($child['duplicate_status'], ['suspected', 'linked', 'merged'], true)) {
            throw new DomainException('This report pair has already been processed.');
        }
        if ($child['duplicate_status'] !== 'suspected') {
            throw new DomainException('Only a resident-suggested pair can be newly linked or merged.');
        }
    } elseif (!in_array($child['duplicate_status'], ['suspected', 'linked', 'merged'], true)) {
        throw new DomainException('This report pair has already been processed.');
    }

    if ($action !== 'not_duplicate') {
        $eligibleStatuses = $conn->prepare("
            SELECT
                EXISTS(SELECT 1 FROM report_duplicate_statuses WHERE report_status = ? AND is_enabled = 1) AS child_eligible,
                EXISTS(SELECT 1 FROM report_duplicate_statuses WHERE report_status = ? AND is_enabled = 1) AS parent_eligible
        ");
        if (!$eligibleStatuses) throw new RuntimeException('Could not verify duplicate review status eligibility.');
        $childStatus = (string)$child['status'];
        $parentStatus = (string)$parent['status'];
        $eligibleStatuses->bind_param('ss', $childStatus, $parentStatus);
        if (!$eligibleStatuses->execute()) throw new RuntimeException('Could not verify duplicate review status eligibility.');
        $eligibility = $eligibleStatuses->get_result()->fetch_assoc();
        $eligibleStatuses->close();
        if (empty($eligibility['child_eligible']) || empty($eligibility['parent_eligible'])) {
            throw new DomainException('One of these reports is no longer eligible for this review.');
        }
    }
    if ($action === 'merge') {
        if ($child['status'] !== 'pending') {
            throw new DomainException('A report with progressed status cannot be linked as one incident.');
        }
        $caseQuery = $conn->prepare("SELECT case_id FROM confirmed_cases WHERE report_id = ? LIMIT 1 FOR UPDATE");
        if (!$caseQuery) throw new RuntimeException('Could not verify the report case state.');
        $caseQuery->bind_param('i', $reportId);
        if (!$caseQuery->execute()) throw new RuntimeException('Could not verify the report case state.');
        $hasCase = (bool)$caseQuery->get_result()->fetch_assoc();
        $caseQuery->close();
        $workflowQuery = $conn->prepare("SELECT report_id FROM report_response_workflow WHERE report_id = ? LIMIT 1 FOR UPDATE");
        if (!$workflowQuery) throw new RuntimeException('Could not verify the report response workflow.');
        $workflowQuery->bind_param('i', $reportId);
        if (!$workflowQuery->execute()) throw new RuntimeException('Could not verify the report response workflow.');
        $hasWorkflow = (bool)$workflowQuery->get_result()->fetch_assoc();
        $workflowQuery->close();
        if ($hasCase || $hasWorkflow) {
            throw new DomainException('This report has case workflow history and cannot be linked as one incident.');
        }
    }
    if ($action === 'not_duplicate' && $child['duplicate_status'] === 'merged') {
        if ($child['status'] !== 'pending') {
            throw new DomainException('A merged report with progressed status cannot be separated automatically. Contact an administrator.');
        }
        $caseQuery = $conn->prepare("SELECT case_id FROM confirmed_cases WHERE report_id = ? LIMIT 1 FOR UPDATE");
        if (!$caseQuery) throw new RuntimeException('Could not verify the report case state.');
        $caseQuery->bind_param('i', $reportId);
        if (!$caseQuery->execute()) throw new RuntimeException('Could not verify the report case state.');
        $hasCase = (bool)$caseQuery->get_result()->fetch_assoc();
        $caseQuery->close();
        if ($hasCase) throw new DomainException('A merged report with a confirmed case cannot be separated automatically.');
    }

    if ($action === 'link') {
        $update = $conn->prepare("UPDATE reports SET duplicate_status = 'linked', merged_by = NULL, merged_at = NULL WHERE report_id = ? AND parent_report_id = ?");
        $auditAction = 'link';
    } elseif ($action === 'merge') {
        $update = $conn->prepare("UPDATE reports SET duplicate_status = 'merged', merged_by = ?, merged_at = NOW() WHERE report_id = ? AND parent_report_id = ?");
        $auditAction = 'merge';
    } else {
        $update = $conn->prepare("UPDATE reports SET duplicate_status = 'none', parent_report_id = NULL, merged_by = NULL, merged_at = NULL WHERE report_id = ? AND parent_report_id = ?");
        $auditAction = 'not_duplicate';
    }
    if (!$update) throw new RuntimeException('Could not prepare the duplicate decision.');
    if ($action === 'merge') {
        $update->bind_param('iii', $userId, $reportId, $parentReportId);
    } else {
        $update->bind_param('ii', $reportId, $parentReportId);
    }
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The duplicate decision could not be saved.');
    }
    $update->close();

    if ($parent['duplicate_status'] === 'suspected') {
        $releasePrimary = $conn->prepare("
            UPDATE reports
            SET duplicate_status = 'none'
            WHERE report_id = ? AND duplicate_status = 'suspected' AND parent_report_id IS NULL
        ");
        if (!$releasePrimary) throw new RuntimeException('Could not prepare primary report approval.');
        $releasePrimary->bind_param('i', $parentReportId);
        if (!$releasePrimary->execute() || $releasePrimary->affected_rows !== 1) {
            $releasePrimary->close();
            throw new RuntimeException('Could not approve the primary report after review.');
        }
        $releasePrimary->close();
    }

    if ($action === 'merge') {
        $touchPrimary = $conn->prepare("UPDATE reports SET updated_at = CURRENT_TIMESTAMP WHERE report_id = ? AND archived_at IS NULL");
        if (!$touchPrimary) throw new RuntimeException('Could not prepare primary report refresh.');
        $touchPrimary->bind_param('i', $parentReportId);
        if (!$touchPrimary->execute()) {
            $touchPrimary->close();
            throw new RuntimeException('Could not refresh the primary report after linking.');
        }
        $touchPrimary->close();
    }

    $role = $portal['role'];
    $audit = $conn->prepare("INSERT INTO report_duplicate_log (report_id, parent_report_id, action, performed_by, performed_role, notes) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$audit) throw new RuntimeException('Could not prepare the duplicate decision audit entry.');
    $audit->bind_param('iisiis', $reportId, $parentReportId, $auditAction, $userId, $role, $notes);
    if (!$audit->execute()) {
        $audit->close();
        throw new RuntimeException('Could not save the duplicate decision audit entry.');
    }
    $audit->close();

    if ((int)$child['reporter_id'] !== (int)$parent['reporter_id']) {
        $notice = $action === 'link'
            ? 'The review team linked your report to a related report. It remains a separate case.'
            : ($action === 'merge'
                ? 'The review team verified your report as supporting information for the same incident.'
                : 'The review team determined your report describes a separate incident.');
        createNotification(
            $conn,
            (int)$child['reporter_id'],
            'report_response_update',
            $action === 'merge' ? 'Report Added as Supporting Information' : 'Similar Report Review Completed',
            $notice,
            'report',
            $reportId
        );
    }
    if (!$conn->commit()) throw new RuntimeException('Could not commit the duplicate review decision.');
    $_SESSION['report_duplicate_success'] = $action === 'not_duplicate'
        ? 'The report is approved as a separate incident.'
        : ($action === 'merge' ? 'The report is linked to the primary incident.' : 'The reports were linked and remain separate incidents.');
} catch (DomainException $exception) {
    $conn->rollback();
    $fail($exception->getMessage());
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Duplicate review action failed: ' . $exception->getMessage());
    $fail('The report review action could not be saved. Please try again.');
}
header('Location: ' . $queueUrl);
exit;
