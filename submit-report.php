<?php
/**
 * Submit Report Handler
 * Saves stray/rabies reports to database
 */

ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once 'db.php';
require_once __DIR__ . '/includes/reports/location.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $readPostString = static function ($key) {
        $value = $_POST[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    };
    $officialBarangays = dcvsLoadApprovedBarangays($conn);
    $officialBarangaysByCode = [];
    foreach ($officialBarangays as $officialBarangay) {
        $officialBarangaysByCode[$officialBarangay['psgc_code']] = $officialBarangay;
    }
    $reportPinEnabled = false;
    $reportPinFeatures = [];
    loadDcvsEnv();
    if (env('DCVS_REPORT_PIN_FEATURE_ENABLED', '0') === '1') {
        $reportPinFeatures = dcvsLoadOfficialBarangayPolygons(
            __DIR__ . '/DCVS_Dasmariñas_75_Barangay_Crosswalk_Template.csv',
            __DIR__ . '/geo-map/barangays-boundary.json'
        );
        $reportPinEnabled = dcvsReportPinFeatureEnabled($conn, $reportPinFeatures);
    }

    $sec = isset($_POST['return_section']) && preg_match('/^[a-z0-9_-]+$/', $_POST['return_section']) ? '#' . $_POST['return_section'] : '';
    $reportTypeLabel = trim($_POST['report_type'] ?? '');
    $reportType = reportTypeKey($reportTypeLabel);
    $reportTypeName = $reportTypeLabel !== '' ? ucfirst($reportTypeLabel) : ucfirst($reportType);
    $reportDatetime = str_replace('T', ' ', trim($_POST['report_datetime'] ?? ''));
    $animalType = trim($_POST['animal_type'] ?? '');
    $animalBreed = trim($_POST['animal_breed'] ?? '');
    $animalCount = trim($_POST['animal_count'] ?? '');
    $animalBehavior = trim($_POST['animal_behavior'] ?? '');
    $legacyAnimalDesc = trim($_POST['animal_desc'] ?? '');
    $barangayCode = $readPostString('barangay_psgc_code');
    $locationDetail = $readPostString('location_detail');
    $description = trim($_POST['description'] ?? '');
    $urgency = $_POST['urgency'] ?? 'medium';
    $latitude = null;
    $longitude = null;
    $locationStatus = 'legacy_none';
    $unpinnedReason = null;
    $pinSource = null;

    // Server-side validation
    $errors = [];
    $selectedBarangay = $officialBarangaysByCode[$barangayCode] ?? null;
    if (empty($reportType)) {
        $errors[] = "Report type is required.";
    }
    $parsedReportDatetime = DateTime::createFromFormat('!Y-m-d', $reportDatetime);
    $hasValidDateOnlyValue = $parsedReportDatetime
        && $parsedReportDatetime->format('Y-m-d') === $reportDatetime;
    if (!$hasValidDateOnlyValue) {
        $parsedReportDatetime = DateTime::createFromFormat('!Y-m-d H:i', $reportDatetime);
        if (!$parsedReportDatetime || $parsedReportDatetime->format('Y-m-d H:i') !== $reportDatetime) {
            $parsedReportDatetime = DateTime::createFromFormat('!Y-m-d H:i:s', $reportDatetime);
        }
    }
    if (!$parsedReportDatetime || (!$hasValidDateOnlyValue
        && !in_array($parsedReportDatetime->format('Y-m-d H:i:s'), [$reportDatetime . ':00', $reportDatetime], true))) {
        $errors[] = "A valid incident date is required. Approximate time is optional.";
    } else {
        $reportDatetime = $parsedReportDatetime->format('Y-m-d H:i:s');
    }
    if (empty($animalType) && empty($legacyAnimalDesc)) {
        $errors[] = "Animal description is required.";
    }
    if ($locationDetail === '') {
        $errors[] = "Location is required.";
    }
    if (empty($description)) {
        $errors[] = "Detailed description is required.";
    }

    $rawLatitude = $_POST['latitude'] ?? '';
    $rawLongitude = $_POST['longitude'] ?? '';
    $pinCoordinatesAreScalar = is_scalar($rawLatitude) && is_scalar($rawLongitude);
    $postedLatitude = $readPostString('latitude');
    $postedLongitude = $readPostString('longitude');
    $postedPinSource = $readPostString('pin_source') ?: 'resident';
    if ($reportPinEnabled) {
        $hasLatitude = $postedLatitude !== '';
        $hasLongitude = $postedLongitude !== '';
        if (!$pinCoordinatesAreScalar) {
            $errors[] = 'The incident pin values are invalid. Please place the pin again.';
        } elseif ($hasLatitude !== $hasLongitude) {
            $errors[] = 'The incident pin is incomplete. Please place it again.';
        } elseif ($hasLatitude) {
            try {
                $resolvedBarangay = dcvsResolveOfficialBarangayPin(
                    $postedLongitude,
                    $postedLatitude,
                    $reportPinFeatures
                );
                if ($resolvedBarangay === null) {
                    $errors[] = 'The pin must be inside Dasmariñas. Move it inside the city boundary.';
                } else {
                    $barangayCode = $resolvedBarangay['psgc_code'];
                    $selectedBarangay = $officialBarangaysByCode[$barangayCode] ?? null;
                    if ($selectedBarangay === null) {
                        $errors[] = 'The incident barangay could not be verified. Please move the pin and try again.';
                    } else {
                        $latitude = (float)$postedLatitude;
                        $longitude = (float)$postedLongitude;
                        $locationStatus = 'pinned';
                        $pinSource = in_array($postedPinSource, ['resident', 'gps_assisted'], true)
                            ? $postedPinSource
                            : 'resident';
                    }
                }
            } catch (InvalidArgumentException | DomainException $exception) {
                $errors[] = $exception->getMessage();
            }
        } else {
            $locationStatus = 'unpinned_fallback';
        }
    } elseif ($postedLatitude !== '' || $postedLongitude !== '') {
        $errors[] = 'Incident pin submission is currently unavailable. Please reload the form and try again.';
    }

    if ($selectedBarangay === null) {
        $errors[] = 'Please select a valid incident barangay.';
    }
    if ($selectedBarangay !== null && $locationDetail !== '') {
        $location = $selectedBarangay['barangay_name'] . ', ' . $locationDetail;
    } elseif ($selectedBarangay !== null) {
        $location = $selectedBarangay['barangay_name'];
    } else {
        $location = '';
    }

    if (!empty($errors)) {
        $_SESSION['report_error'] = implode('<br>', $errors);
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    // Daily report limit check
    $today = date('Y-m-d');
    $dailyStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND DATE(created_at) = ?");
    $dailyStmt->bind_param("is", $_SESSION['user_id'], $today);
    $dailyStmt->execute();
    $userReportsToday = (int)$dailyStmt->get_result()->fetch_row()[0];
    $dailyStmt->close();
    $totalStmt = $conn->prepare("SELECT COUNT(*) FROM reports WHERE DATE(created_at) = ?");
    $totalStmt->bind_param("s", $today);
    $totalStmt->execute();
    $totalReportsToday = (int)$totalStmt->get_result()->fetch_row()[0];
    $totalStmt->close();
    if ($userReportsToday >= 5) {
        $_SESSION['report_error'] = 'You have reached the daily limit of 5 reports. Please try again tomorrow.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    if ($totalReportsToday >= 50) {
        $_SESSION['report_error'] = 'The system has reached the daily report capacity. Please try again tomorrow.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    // Save all uploaded report media while keeping the first item of each type
    // in the legacy columns for existing report-detail views.
    $maxAttachmentCount = 5;
    $maxAttachmentSize = 10 * 1024 * 1024;
    $allowedImageTypes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
    ];
    $allowedVideoTypes = [
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/ogg' => ['ogv', 'ogg'],
        'video/x-m4v' => ['m4v'],
        'video/quicktime' => ['mov'],
    ];
    $incomingAttachments = [];
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
        foreach ($_FILES['attachments']['name'] as $index => $name) {
            $incomingAttachments[] = [
                'name' => $name,
                'tmp_name' => $_FILES['attachments']['tmp_name'][$index] ?? '',
                'error' => $_FILES['attachments']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['attachments']['size'][$index] ?? 0,
            ];
        }
    }
    foreach (['photo' => 'photo', 'video' => 'video'] as $field => $legacyType) {
        if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
            $legacyFile = $_FILES[$field];
            if (($legacyFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $incomingAttachments[] = [
                    'name' => $legacyFile['name'] ?? '',
                    'tmp_name' => $legacyFile['tmp_name'] ?? '',
                    'error' => $legacyFile['error'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $legacyFile['size'] ?? 0,
                    'expected_type' => $legacyType,
                ];
            }
        }
    }

    $validatedAttachments = [];
    $attachmentErrors = [];
    if (count($incomingAttachments) > $maxAttachmentCount) {
        $attachmentErrors[] = 'A maximum of ' . $maxAttachmentCount . ' photos or videos may be attached.';
    }
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false && count($incomingAttachments) > 0) {
        $attachmentErrors[] = 'Uploaded files could not be validated. Please try again.';
        error_log('Could not initialize file MIME detection for report attachments.');
    }
    foreach (array_slice($incomingAttachments, 0, $maxAttachmentCount) as $attachment) {
        if (($attachment['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $attachmentErrors[] = 'A photo or video could not be uploaded. Please try again.';
            continue;
        }
        $extension = strtolower(pathinfo((string)$attachment['name'], PATHINFO_EXTENSION));
        $detectedType = $fileInfo !== false
            ? finfo_file($fileInfo, (string)$attachment['tmp_name'])
            : false;
        $mediaType = '';
        $allowedExtensions = [];
        if (is_string($detectedType) && isset($allowedImageTypes[$detectedType])) {
            $mediaType = 'photo';
            $allowedExtensions = $allowedImageTypes[$detectedType];
        } elseif (is_string($detectedType) && isset($allowedVideoTypes[$detectedType])) {
            $mediaType = 'video';
            $allowedExtensions = $allowedVideoTypes[$detectedType];
        }
        if ($mediaType === ''
            || !in_array($extension, $allowedExtensions, true)
            || (isset($attachment['expected_type']) && $attachment['expected_type'] !== $mediaType)) {
            $attachmentErrors[] = 'Unsupported attachment. Use JPG, PNG, GIF, WebP, MP4, WebM, or OGG files.';
            continue;
        }
        if ((int)$attachment['size'] <= 0 || (int)$attachment['size'] > $maxAttachmentSize) {
            $attachmentErrors[] = 'Each photo or video must be no larger than 10 MB.';
            continue;
        }
        $validatedAttachments[] = [
            'tmp_name' => (string)$attachment['tmp_name'],
            'extension' => $extension,
            'type' => $mediaType,
            'mime_type' => $detectedType,
        ];
    }
    if ($fileInfo !== false) {
        finfo_close($fileInfo);
    }
    if ($locationStatus === 'unpinned_fallback' && !$validatedAttachments) {
        $attachmentErrors[] = 'Attach at least one photo or video when submitting without an incident pin.';
    }
    if ($attachmentErrors) {
        $_SESSION['report_error'] = implode('<br>', array_unique($attachmentErrors));
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }

    $uploadDirectory = __DIR__ . '/uploads/reports/';
    if ($validatedAttachments && !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        error_log('Could not create report attachment directory: ' . $uploadDirectory);
        $_SESSION['report_error'] = 'Uploaded files could not be saved. Please try again.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $storedAttachments = [];
    foreach ($validatedAttachments as $index => $attachment) {
        $fileName = bin2hex(random_bytes(16)) . '.' . $attachment['extension'];
        $storedPath = 'uploads/reports/' . $fileName;
        if (!move_uploaded_file($attachment['tmp_name'], $uploadDirectory . $fileName)) {
            foreach ($storedAttachments as $storedAttachment) {
                $storedFile = __DIR__ . '/' . $storedAttachment['file_url'];
                if (is_file($storedFile) && !unlink($storedFile)) {
                    error_log('Could not remove a partially stored report attachment: ' . $storedFile);
                }
            }
            error_log('Could not store an uploaded report attachment.');
            $_SESSION['report_error'] = 'Uploaded files could not be saved. Please try again.';
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }
        $storedAttachments[] = [
            'type' => $attachment['type'],
            'file_url' => $storedPath,
            'mime_type' => $attachment['mime_type'],
            'display_order' => $index,
        ];
    }
    $photoUrl = null;
    $videoUrl = null;
    foreach ($storedAttachments as $storedAttachment) {
        if ($storedAttachment['type'] === 'photo' && $photoUrl === null) {
            $photoUrl = $storedAttachment['file_url'];
        }
        if ($storedAttachment['type'] === 'video' && $videoUrl === null) {
            $videoUrl = $storedAttachment['file_url'];
        }
    }

    $barangayId = (int)$selectedBarangay['barangay_id'];

    // Insert report into database
    error_log("Attempting to insert report into database");
    error_log("User ID: " . $_SESSION['user_id']);
    error_log("Report datetime: " . $reportDatetime);
    $isStructuredReport = $animalType !== '' && $animalBreed !== '' && $animalCount !== '' && $animalBehavior !== '';
    $storedDescription = $isStructuredReport ? $description : ($legacyAnimalDesc !== '' ? $legacyAnimalDesc . ' - ' . $description : $description);
    $refId = 'RPT-' . time() . '-' . $_SESSION['user_id'];
    $removeStoredAttachments = static function () use (&$storedAttachments) {
        foreach ($storedAttachments as $attachment) {
            $storedFile = __DIR__ . '/' . $attachment['file_url'];
            if (is_file($storedFile) && !unlink($storedFile)) {
                error_log('Could not remove an uncommitted report attachment: ' . $storedFile);
            }
        }
    };
    if (!$conn->begin_transaction()) {
        error_log('Could not begin report submission transaction: ' . $conn->error);
        $removeStoredAttachments();
        $_SESSION['report_error'] = 'Database error. Please try again.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO reports (reporter_id, report_type, animal_type, animal_breed, animal_count, animal_behavior, description, location_address, barangay_id, urgency_level, photo_url, video_url, status, reference_id, incident_at, coordinates_lat, coordinates_long, location_status, unpinned_reason, pin_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $conn->rollback();
        $removeStoredAttachments();
        $_SESSION['report_error'] = "Database error. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $stmt->bind_param("isssssssisssssddsss", $_SESSION['user_id'], $reportType, $animalType, $animalBreed, $animalCount, $animalBehavior, $storedDescription, $location, $barangayId, $urgency, $photoUrl, $videoUrl, $refId, $reportDatetime, $latitude, $longitude, $locationStatus, $unpinnedReason, $pinSource);

    if (!$stmt->execute()) {
        error_log('Report insert failed: ' . $stmt->error);
        $stmt->close();
        $conn->rollback();
        $removeStoredAttachments();
        $_SESSION['report_error'] = "Failed to submit report. Please try again.";
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    $reportId = $stmt->insert_id;
    $stmt->close();

    if ($storedAttachments) {
        $attachmentStatement = $conn->prepare(
            'INSERT INTO report_attachments (report_id, attachment_type, file_url, mime_type, display_order) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$attachmentStatement) {
            error_log('Report attachment insert prepare failed: ' . $conn->error);
            $conn->rollback();
            $removeStoredAttachments();
            $_SESSION['report_error'] = 'Uploaded files could not be saved. Please try again.';
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }
        $attachmentInsertSucceeded = true;
        foreach ($storedAttachments as $attachment) {
            $attachmentType = $attachment['type'];
            $attachmentFileUrl = $attachment['file_url'];
            $attachmentMimeType = $attachment['mime_type'];
            $attachmentDisplayOrder = $attachment['display_order'];
            $attachmentStatement->bind_param(
                'isssi',
                $reportId,
                $attachmentType,
                $attachmentFileUrl,
                $attachmentMimeType,
                $attachmentDisplayOrder
            );
            if (!$attachmentStatement->execute()) {
                error_log('Report attachment insert failed: ' . $attachmentStatement->error);
                $attachmentInsertSucceeded = false;
                break;
            }
        }
        $attachmentStatement->close();
        if (!$attachmentInsertSucceeded) {
            $conn->rollback();
            $removeStoredAttachments();
            $_SESSION['report_error'] = 'Uploaded files could not be saved. Please try again.';
            header('Location: dashboard-resident.php' . $sec);
            exit;
        }
    }

    if (!$conn->commit()) {
        error_log('Report submission transaction commit failed: ' . $conn->error);
        $conn->rollback();
        $removeStoredAttachments();
        $_SESSION['report_error'] = 'Failed to submit report. Please try again.';
        header('Location: dashboard-resident.php' . $sec);
        exit;
    }
    unset($_SESSION['report_error']);

    // Notify barangay officers
    if (!empty($barangayId)) {
        $barangayLabel = '';
            $bq = $conn->prepare("SELECT barangay_name FROM barangays WHERE barangay_id = ?");
            $bq->bind_param("i", $barangayId);
            $bq->execute();
            $br = $bq->get_result();
            if ($row = $br->fetch_assoc()) $barangayLabel = $row['barangay_name'];
            $bq->close();

            $notifyStmt = $conn->prepare("SELECT user_id FROM users WHERE barangay_id = ? AND role = 'barangay' AND status = 'active'");
            if ($notifyStmt) {
                $notifyStmt->bind_param("i", $barangayId);
                $notifyStmt->execute();
                $nr = $notifyStmt->get_result();
                while ($officer = $nr->fetch_assoc()) {
                    createNotification($conn, $officer['user_id'], 'new_report',
                        'New Report Submitted',
                    $_SESSION['full_name'] . ' submitted a ' . $reportTypeName . ' report for an incident in Brgy. ' . $barangayLabel . '.',
                        'report', $reportId);
                }
                $notifyStmt->close();
            }
    }

    // Also notify all active staff members
    $staffNotify = $conn->prepare("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
    if ($staffNotify) {
            $staffNotify->execute();
            $staffResult = $staffNotify->get_result();
            while ($staff = $staffResult->fetch_assoc()) {
                createNotification($conn, $staff['user_id'], 'new_report',
                    'New Report Submitted',
                        $_SESSION['full_name'] . ' submitted a ' . $reportTypeName . ' report for an incident in Brgy. ' . $barangayLabel . '.',
                    'report', $reportId);
            }
            $staffNotify->close();
    }

    // Log activity
    $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'submit_report', ?, ?)");
    if ($logStmt) {
            $detail = "Submitted a " . $reportTypeName . " report for " . ($_SESSION['full_name'] ?? 'a resident') . ".";
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $logStmt->bind_param("iss", $_SESSION['user_id'], $detail, $ip);
            $logStmt->execute();
            $logStmt->close();
    }

    $_SESSION['report_success'] = "Report submitted successfully! It will be validated by the incident barangay officer.";
    header('Location: dashboard-resident.php' . $sec);
    exit;
}

$conn->close();
?>
