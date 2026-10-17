<?php

const ADOPTION_AGREEMENT_VERSION = 'dcvs-adoption-v1';
const ADOPTION_RESERVATION_DAYS = 3;
const ADOPTION_DOCUMENT_MAX_BYTES = 10485760;

class AdoptionWorkflowException extends RuntimeException
{
}

function adoptionRequireStatement($statement)
{
    if (!$statement) {
        throw new RuntimeException('Adoption workflow database operation could not be prepared.');
    }
    return $statement;
}

function adoptionInputText($value)
{
    return is_string($value) ? trim($value) : '';
}

function adoptionIntegerValue($value)
{
    return is_scalar($value) && is_numeric($value) ? (int)$value : 0;
}

function adoptionCommit($conn)
{
    if (!$conn->commit()) {
        throw new RuntimeException('The adoption workflow transaction could not be committed.');
    }
}

function adoptionPrivateDirectory()
{
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($documentRoot === false) {
        throw new RuntimeException('Private adoption document storage is unavailable.');
    }

    $normalizedRoot = str_replace('\\', '/', $documentRoot);
    if (preg_match('#^(.*?)/htdocs(?:/|$)#i', $normalizedRoot, $matches)) {
        $storageRoot = str_replace('/', DIRECTORY_SEPARATOR, $matches[1]);
    } else {
        $storageRoot = dirname($documentRoot);
    }
    $directory = $storageRoot . DIRECTORY_SEPARATOR . 'dcvs-private' . DIRECTORY_SEPARATOR . 'adoption';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private adoption document storage could not be created.');
    }

    $realDirectory = realpath($directory);
    $realDocumentRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($realDirectory === false || strncasecmp($realDirectory . DIRECTORY_SEPARATOR, $realDocumentRoot, strlen($realDocumentRoot)) === 0) {
        throw new RuntimeException('Private adoption document storage must remain outside the web root.');
    }

    return $realDirectory;
}

function adoptionStorePrivateDocument($file, $purpose)
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new AdoptionWorkflowException('Please select a document to upload.');
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new AdoptionWorkflowException('The uploaded document could not be verified.');
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > ADOPTION_DOCUMENT_MAX_BYTES) {
        throw new AdoptionWorkflowException('Documents must be no larger than 10 MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('File type detection is unavailable.');
    }
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($extensions[$mime])) {
        throw new AdoptionWorkflowException('Upload a clear JPG, PNG, WebP, or PDF document.');
    }

    $directory = adoptionPrivateDirectory();
    $filename = $purpose . '_' . bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The private adoption document could not be saved.');
    }
    return $filename;
}

function adoptionRemovePrivateDocument($filename)
{
    if (!is_string($filename) || !preg_match('/^[a-z]+_[a-f0-9]{48}\.(jpg|png|webp|pdf)$/', $filename)) {
        return;
    }
    $path = adoptionPrivateDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path) && !unlink($path)) {
        error_log('Unable to remove orphaned private adoption document.');
    }
}

function adoptionAgeFromDate($date)
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return -1;
    }
    $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $today = new DateTimeImmutable('today');
    if (!$birthDate || $birthDate->format('Y-m-d') !== $date || $birthDate > $today) {
        return -1;
    }
    return $birthDate->diff($today)->y;
}

function adoptionBeginResidentPetRegistration($conn, $residentId)
{
    $conn->begin_transaction();
    try {
        $stmt = adoptionRequireStatement($conn->prepare(
            "SELECT status, role FROM users WHERE user_id = ? FOR UPDATE"
        ));
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$resident || $resident['role'] !== 'resident' || $resident['status'] !== 'active') {
            throw new AdoptionWorkflowException('An active resident account is required to register a pet.');
        }
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionResidentEligibility($conn, $residentId)
{
    $stmt = adoptionRequireStatement($conn->prepare(
        "SELECT users.status, users.role, users.barangay_id, barangays.district
         FROM users LEFT JOIN barangays ON barangays.barangay_id = users.barangay_id
         WHERE users.user_id = ?"
    ));
    $stmt->bind_param('i', $residentId);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    adoptionResidentEligibilityFromRow($resident);
    return $resident;
}

function adoptionLockPetListing($conn, $listingId)
{
    $stmt = adoptionRequireStatement($conn->prepare(
        'SELECT * FROM pet_listings WHERE listing_id = ? FOR UPDATE'
    ));
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $listing;
}

function adoptionExpireListingReservations($conn, $listingId)
{
    $stmt = adoptionRequireStatement($conn->prepare(
        "UPDATE adoption_applications
         SET status = 'expired', info_deadline = NULL
         WHERE pet_listing_id = ? AND status = 'needs_info'
           AND info_deadline IS NOT NULL AND info_deadline <= NOW()"
    ));
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $stmt->close();

    $stmt = adoptionRequireStatement($conn->prepare(
        "UPDATE adoption_applications
         SET status = 'declined', decline_reason = 'expired'
         WHERE pet_listing_id = ? AND resulting_pet_id IS NULL
           AND ((status = 'reserved' AND response_deadline IS NOT NULL AND response_deadline <= NOW())
             OR (status = 'confirmed' AND pickup_deadline IS NOT NULL AND pickup_deadline <= NOW())
             OR (status = 'approved' AND approved_pickup_at IS NOT NULL
                 AND approved_pickup_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)))"
    ));
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $stmt->close();

    $stmt = adoptionRequireStatement($conn->prepare(
        "SELECT COUNT(*) FROM adoption_applications
         WHERE pet_listing_id = ?
           AND (status IN ('pending','under_review','needs_info','reserved','confirmed')
                OR (status = 'approved' AND resulting_pet_id IS NULL))"
    ));
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $activeCount = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    if ($activeCount === 0) {
        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE pet_listings SET status = 'available', updated_at = NOW()
             WHERE listing_id = ? AND status = 'reserved'"
        ));
        $stmt->bind_param('i', $listingId);
        $stmt->execute();
        $stmt->close();
    }
}

function expireAdoptionReservations($db)
{
    $db->begin_transaction();
    try {
        $updates = [
            "UPDATE adoption_applications
             SET status = 'expired', info_deadline = NULL
             WHERE status = 'needs_info' AND info_deadline IS NOT NULL AND info_deadline <= NOW()",
            "UPDATE adoption_applications
             SET status = 'declined', decline_reason = 'expired'
             WHERE resulting_pet_id IS NULL
               AND ((status = 'reserved' AND response_deadline IS NOT NULL AND response_deadline <= NOW())
                 OR (status = 'confirmed' AND pickup_deadline IS NOT NULL AND pickup_deadline <= NOW())
                 OR (status = 'approved' AND approved_pickup_at IS NOT NULL
                     AND approved_pickup_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)))",
            "UPDATE pet_listings pl
             SET status = 'available', updated_at = NOW()
             WHERE pl.status = 'reserved'
               AND NOT EXISTS (
                   SELECT 1 FROM adoption_applications aa
                   WHERE aa.pet_listing_id = pl.listing_id
                     AND (aa.status IN ('pending','under_review','needs_info','reserved','confirmed')
                       OR (aa.status = 'approved' AND aa.resulting_pet_id IS NULL))
               )",
        ];
        foreach ($updates as $sql) {
            if (!$db->query($sql)) {
                throw new RuntimeException('Expired adoption reservations could not be processed: ' . $db->error);
            }
        }
        adoptionCommit($db);
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function adoptionReleaseExpiredReservations($conn)
{
    expireAdoptionReservations($conn);
}

function adoptionHasActiveApplicationForListing($conn, $listingId)
{
    $stmt = adoptionRequireStatement($conn->prepare(
        "SELECT COUNT(*) FROM adoption_applications
         WHERE pet_listing_id = ?
           AND (status IN ('pending','under_review','needs_info','reserved','confirmed')
                OR (status = 'approved' AND resulting_pet_id IS NULL))"
    ));
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $hasActive = (int)$stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $hasActive;
}

function adoptionConfirmApplication($conn, $residentId, $applicationId)
{
    $lookup = adoptionRequireStatement($conn->prepare(
        'SELECT pet_listing_id FROM adoption_applications WHERE application_id = ? AND applicant_id = ?'
    ));
    $lookup->bind_param('ii', $applicationId, $residentId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row || (int)$row['pet_listing_id'] <= 0) {
        throw new AdoptionWorkflowException('Adoption application not found.');
    }
    $listingId = (int)$row['pet_listing_id'];

    $conn->begin_transaction();
    try {
        adoptionLockPetListing($conn, $listingId);
        adoptionExpireListingReservations($conn, $listingId);
        adoptionLockResidentAfterPet($conn, $residentId);
        $application = adoptionLockApplication($conn, $applicationId);
        if (!$application || (int)$application['applicant_id'] !== $residentId
            || (int)$application['pet_listing_id'] !== $listingId) {
            throw new AdoptionWorkflowException('Adoption application not found.');
        }
        if ($application['status'] !== 'reserved') {
            if ($application['status'] === 'declined') {
                adoptionCommit($conn);
            }
            throw new AdoptionWorkflowException(
                $application['status'] === 'declined'
                    ? 'The response deadline passed and this reservation expired.'
                    : 'This application is no longer awaiting your confirmation.'
            );
        }
        if (empty($application['response_deadline'])) {
            throw new AdoptionWorkflowException('This reservation has no response deadline. Please contact DCVS staff.');
        }

        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE adoption_applications
             SET status = 'confirmed', confirmed_at = NOW(),
                 pickup_deadline = DATE_ADD(NOW(), INTERVAL 3 DAY)
             WHERE application_id = ? AND applicant_id = ? AND status = 'reserved'
               AND response_deadline > NOW()"
        ));
        $stmt->bind_param('ii', $applicationId, $residentId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            adoptionExpireListingReservations($conn, $listingId);
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The response deadline passed. This reservation has expired.');
        }
        $stmt->close();
        adoptionCommit($conn);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionNotifyStaff($conn, $title, $message, $referenceId, $referenceType = 'adoption_app')
{
    $staffResult = $conn->query("SELECT user_id FROM users WHERE role = 'staff' AND status = 'active'");
    if (!$staffResult) {
        throw new RuntimeException('Staff notifications could not be prepared.');
    }
    $stmt = adoptionRequireStatement($conn->prepare(
        "INSERT INTO notifications (user_id, type, title, message, message_type, reference_type, reference_id)
         VALUES (?, 'adoption_request', ?, ?, NULL, ?, ?)"
    ));
    $notified = 0;
    while ($staff = $staffResult->fetch_assoc()) {
        $staffId = (int)$staff['user_id'];
        $stmt->bind_param('isssi', $staffId, $title, $message, $referenceType, $referenceId);
        if (!$stmt->execute()) {
            $stmt->close();
            $staffResult->free();
            throw new RuntimeException('A staff adoption notification could not be saved.');
        }
        $notified++;
    }
    $stmt->close();
    $staffResult->free();
    if ($notified === 0) {
        throw new RuntimeException('There are no active staff accounts to review the adoption request.');
    }
}

function adoptionNotifyResident($conn, $residentId, $title, $message, $referenceId, $referenceType = 'adoption_app')
{
    $stmt = adoptionRequireStatement($conn->prepare(
        "INSERT INTO notifications (user_id, type, title, message, message_type, reference_type, reference_id)
         VALUES (?, 'adoption_update', ?, ?, NULL, ?, ?)"
    ));
    $stmt->bind_param('isssi', $residentId, $title, $message, $referenceType, $referenceId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('The resident adoption notification could not be saved.');
    }
    $stmt->close();
}

function adoptionSubmitIdentityVerification($conn, $residentId, $dateOfBirth, $privateFile)
{
    if (adoptionAgeFromDate($dateOfBirth) < 18) {
        throw new AdoptionWorkflowException('You must be at least 18 years old to apply for adoption.');
    }

    $conn->begin_transaction();
    try {
        $stmt = adoptionRequireStatement($conn->prepare(
            'SELECT status, role, government_id_verified_at FROM users WHERE user_id = ? FOR UPDATE'
        ));
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$resident || $resident['role'] !== 'resident' || $resident['status'] !== 'active') {
            throw new AdoptionWorkflowException('An active resident account is required.');
        }
        if (!empty($resident['government_id_verified_at'])) {
            throw new AdoptionWorkflowException('Your government ID has already been verified.');
        }

        $stmt = adoptionRequireStatement($conn->prepare(
            "SELECT verification_id FROM adoption_identity_verifications
             WHERE resident_id = ? AND status = 'pending' LIMIT 1"
        ));
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $pending = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($pending) {
            throw new AdoptionWorkflowException('Your government ID is already waiting for staff review.');
        }

        $stmt = adoptionRequireStatement($conn->prepare(
            "INSERT INTO adoption_identity_verifications
             (resident_id, government_id_file, date_of_birth, status)
             VALUES (?, ?, ?, 'pending')"
        ));
        $stmt->bind_param('iss', $residentId, $privateFile, $dateOfBirth);
        $stmt->execute();
        $verificationId = (int)$stmt->insert_id;
        $stmt->close();
        adoptionNotifyStaff($conn, 'Government ID Verification Request', 'A resident submitted a government ID for verification.', $verificationId, 'adoption_id');
        adoptionCommit($conn);
        return $verificationId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionSubmitApplication($conn, $residentId, $input, $proofFile)
{
    $listingId = adoptionIntegerValue($input['listing_id'] ?? 0);
    $dateOfBirth = adoptionInputText($input['date_of_birth'] ?? null);
    $housingType = adoptionInputText($input['housing_type'] ?? null);
    $landlordPermission = !empty($input['landlord_permission']) ? 1 : 0;
    $homeType = adoptionInputText($input['home_type'] ?? null);
    $householdMembers = adoptionIntegerValue($input['household_members'] ?? 0);
    $hasFencedYard = !empty($input['has_fenced_yard']) ? 1 : 0;
    $hasOtherPets = !empty($input['has_other_pets']) ? 1 : 0;
    $otherPetsDetails = adoptionInputText($input['other_pets_details'] ?? null);
    $experience = adoptionInputText($input['pet_care_experience'] ?? null);
    $reason = adoptionInputText($input['reason_for_adoption'] ?? null);

    if ($listingId <= 0 || adoptionAgeFromDate($dateOfBirth) < 18
        || !in_array($housingType, ['owned', 'rented'], true)
        || ($housingType === 'rented' && $landlordPermission !== 1)
        || $homeType === '' || strlen($homeType) > 100 || $householdMembers < 1 || $householdMembers > 50
        || $experience === '' || strlen($experience) > 5000 || $reason === '' || strlen($reason) > 5000
        || strlen($otherPetsDetails) > 5000
        || empty($input['agreement_vaccination']) || empty($input['agreement_spay_neuter'])
        || empty($input['agreement_no_transfer']) || empty($input['agreement_followup'])) {
        throw new AdoptionWorkflowException('Complete every required application field, upload proof of residency, and accept all four agreement commitments.');
    }
    if ($hasOtherPets && $otherPetsDetails === '') {
        throw new AdoptionWorkflowException('Please describe your other pets.');
    }

    $proofFilename = adoptionStorePrivateDocument($proofFile, 'residency');
    try {
        $conn->begin_transaction();
        $listing = adoptionLockPetListing($conn, $listingId);
        if (!$listing) {
            throw new AdoptionWorkflowException('This pet listing is no longer available.');
        }
        adoptionExpireListingReservations($conn, $listingId);
        $listing = adoptionLockPetListing($conn, $listingId);
        if (!$listing || $listing['status'] !== 'available') {
            throw new AdoptionWorkflowException('This pet has already been reserved or is no longer available.');
        }

        $stmt = adoptionRequireStatement($conn->prepare(
            "SELECT users.status, users.role, users.barangay_id, barangays.district
             FROM users LEFT JOIN barangays ON barangays.barangay_id = users.barangay_id
             WHERE users.user_id = ? FOR UPDATE"
        ));
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        adoptionResidentEligibilityFromRow($resident);

        if (adoptionHasActiveApplicationForListing($conn, $listingId)) {
            throw new AdoptionWorkflowException('This pet already has an active application.');
        }

        $stmt = adoptionRequireStatement($conn->prepare(
            "INSERT INTO adoption_applications
             (listing_id, pet_listing_id, applicant_id, date_of_birth, housing_type,
              landlord_permission, home_type, has_fenced_yard, household_members,
              has_other_pets, other_pets_details, pet_care_experience, reason_for_adoption,
              residency_proof_file, agreement_version, agreed_at, status)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')"
        ));
        $agreementVersion = ADOPTION_AGREEMENT_VERSION;
        $stmt->bind_param(
            'iissisiiisssss',
            $listingId,
            $residentId,
            $dateOfBirth,
            $housingType,
            $landlordPermission,
            $homeType,
            $hasFencedYard,
            $householdMembers,
            $hasOtherPets,
            $otherPetsDetails,
            $experience,
            $reason,
            $proofFilename,
            $agreementVersion
        );
        $stmt->execute();
        $applicationId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE pet_listings SET status = 'reserved', updated_at = NOW()
             WHERE listing_id = ? AND status = 'available'"
        ));
        $stmt->bind_param('i', $listingId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new AdoptionWorkflowException('This pet is no longer available.');
        }
        $stmt->close();

        adoptionNotifyStaff(
            $conn,
            'New Adoption Application',
            $residentId . ' submitted an application for ' . $listing['name'] . '.',
            $applicationId
        );
        adoptionCommit($conn);
        return $applicationId;
    } catch (Throwable $exception) {
        $conn->rollback();
        adoptionRemovePrivateDocument($proofFilename);
        throw $exception;
    }
}

function adoptionResidentEligibilityFromRow($resident)
{
    if (!$resident || $resident['role'] !== 'resident' || $resident['status'] !== 'active'
        || empty($resident['barangay_id']) || stripos((string)($resident['district'] ?? ''), 'Dasmar') !== 0) {
        throw new AdoptionWorkflowException('An active resident account with a Dasmariñas barangay profile is required.');
    }
}

function adoptionLockApplication($conn, $applicationId)
{
    $stmt = adoptionRequireStatement($conn->prepare(
    'SELECT * FROM adoption_applications WHERE application_id = ? FOR UPDATE'
    ));
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $application = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $application;
}

function adoptionLockResidentAfterPet($conn, $residentId)
{
    $stmt = adoptionRequireStatement($conn->prepare(
        "SELECT users.status, users.role, users.barangay_id, barangays.district,
                users.full_name, users.email, users.phone
         FROM users LEFT JOIN barangays ON barangays.barangay_id = users.barangay_id
         WHERE users.user_id = ? FOR UPDATE"
    ));
    $stmt->bind_param('i', $residentId);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $resident;
}

function adoptionReleasePetIfUnreserved($conn, $listingId)
{
    if (!adoptionHasActiveApplicationForListing($conn, $listingId)) {
        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE pet_listings SET status = 'available', updated_at = NOW()
             WHERE listing_id = ? AND status = 'reserved'"
        ));
        $stmt->bind_param('i', $listingId);
        $stmt->execute();
        $stmt->close();
    }
}

function adoptionResidentAction($conn, $residentId, $applicationId, $action, $response = '')
{
    $response = adoptionInputText($response);
    if (strlen($response) > 5000) {
        throw new AdoptionWorkflowException('Your response must be no longer than 5,000 characters.');
    }
    $lookup = adoptionRequireStatement($conn->prepare(
        'SELECT pet_listing_id FROM adoption_applications WHERE application_id = ? AND applicant_id = ?'
    ));
    $lookup->bind_param('ii', $applicationId, $residentId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row || (int)$row['pet_listing_id'] <= 0) {
        throw new AdoptionWorkflowException('Adoption application not found.');
    }
    $listingId = (int)$row['pet_listing_id'];

    $conn->begin_transaction();
    try {
        adoptionLockPetListing($conn, $listingId);
        adoptionExpireListingReservations($conn, $listingId);
        adoptionLockResidentAfterPet($conn, $residentId);
        $application = adoptionLockApplication($conn, $applicationId);
        if (!$application || (int)$application['applicant_id'] !== $residentId || (int)$application['pet_listing_id'] !== $listingId) {
            throw new AdoptionWorkflowException('Adoption application not found.');
        }
        if ($application['status'] === 'expired') {
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The seven-day response period expired and the pet reservation was released.');
        }
        if ($application['status'] === 'declined') {
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The allotted response or pickup time expired and the pet reservation was released.');
        }

        if ($action === 'respond') {
            if ($application['status'] !== 'needs_info' || $response === '') {
                throw new AdoptionWorkflowException('A response is only accepted for an open information request.');
            }
            $stmt = adoptionRequireStatement($conn->prepare(
                "UPDATE adoption_applications
                 SET resident_response = ?, resident_response_at = NOW(), status = 'under_review', info_deadline = NULL
                 WHERE application_id = ?"
            ));
            $stmt->bind_param('si', $response, $applicationId);
            $stmt->execute();
            $stmt->close();
            adoptionNotifyStaff($conn, 'Adoption Applicant Responded', 'An applicant responded to a request for more information.', $applicationId);
            adoptionCommit($conn);
            return;
        }

        if ($action !== 'withdraw' || !in_array($application['status'], ['pending', 'under_review', 'needs_info'], true)) {
            throw new AdoptionWorkflowException('This application can no longer be withdrawn.');
        }
        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE adoption_applications SET status = 'withdrawn', info_deadline = NULL
             WHERE application_id = ?"
        ));
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
        adoptionReleasePetIfUnreserved($conn, $listingId);
        adoptionNotifyStaff($conn, 'Adoption Application Withdrawn', 'An applicant withdrew an adoption application.', $applicationId);
        adoptionCommit($conn);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionVerifyIdentity($conn, $verificationId, $staffId, $decision, $notes)
{
    $notes = adoptionInputText($notes);
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new AdoptionWorkflowException('Choose whether to approve or reject the ID verification.');
    }
    if (strlen($notes) > 5000 || ($decision === 'rejected' && $notes === '')) {
        throw new AdoptionWorkflowException('Provide a reason when rejecting a government ID.');
    }

    $lookup = adoptionRequireStatement($conn->prepare(
        'SELECT resident_id FROM adoption_identity_verifications WHERE verification_id = ?'
    ));
    $lookup->bind_param('i', $verificationId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row) {
        throw new AdoptionWorkflowException('ID verification request not found.');
    }
    $residentId = (int)$row['resident_id'];

    $conn->begin_transaction();
    try {
        $stmt = adoptionRequireStatement($conn->prepare(
            'SELECT user_id FROM users WHERE user_id = ? FOR UPDATE'
        ));
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $stmt = adoptionRequireStatement($conn->prepare(
            'SELECT * FROM adoption_identity_verifications WHERE verification_id = ? FOR UPDATE'
        ));
        $stmt->bind_param('i', $verificationId);
        $stmt->execute();
        $verification = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$verification || $verification['status'] !== 'pending') {
            throw new AdoptionWorkflowException('This ID verification request has already been reviewed.');
        }
        if ($decision === 'approved' && adoptionAgeFromDate($verification['date_of_birth']) < 18) {
            throw new AdoptionWorkflowException('The resident is under 18 and cannot be approved for adoption.');
        }

        $stmt = adoptionRequireStatement($conn->prepare(
            'UPDATE adoption_identity_verifications
             SET status = ?, review_notes = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE verification_id = ?'
        ));
        $stmt->bind_param('ssii', $decision, $notes, $staffId, $verificationId);
        $stmt->execute();
        $stmt->close();

        if ($decision === 'approved') {
            $verifiedBirthDate = $verification['date_of_birth'];
            $stmt = adoptionRequireStatement($conn->prepare(
                'UPDATE users SET government_id_verified_at = NOW(), birthdate = ? WHERE user_id = ?'
            ));
            $stmt->bind_param('si', $verifiedBirthDate, $residentId);
            $stmt->execute();
            $stmt->close();
        }
        adoptionNotifyResident(
            $conn,
            $residentId,
            $decision === 'approved' ? 'Government ID Verified' : 'Government ID Review Update',
            $decision === 'approved' ? 'Your government ID has been verified. You may now submit an adoption application.' : 'Your government ID verification was not approved. Please review the staff note and resubmit.',
            $verificationId,
            'adoption_id'
        );
        adoptionCommit($conn);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionApproveApplication($conn, $applicationId, $staffId, $pickupAt, $interviewAt = '', $homeVisitAt = '', $homeVisitNotes = '')
{
    $pickup = null;
    if ($pickupAt !== '') {
        $pickup = DateTime::createFromFormat('!Y-m-d H:i:s', $pickupAt);
        if (!$pickup || $pickup->format('Y-m-d H:i:s') !== $pickupAt || $pickup <= new DateTime()) {
            throw new AdoptionWorkflowException('Choose a future date and time for the resident to visit DCVS.');
        }
    }
    $lookup = adoptionRequireStatement($conn->prepare(
        'SELECT pet_listing_id, applicant_id FROM adoption_applications WHERE application_id = ?'
    ));
    $lookup->bind_param('i', $applicationId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row || (int)$row['pet_listing_id'] <= 0) {
        throw new AdoptionWorkflowException('Adoption application not found.');
    }
    $listingId = (int)$row['pet_listing_id'];
    $residentId = (int)$row['applicant_id'];

    $conn->begin_transaction();
    try {
        $listing = adoptionLockPetListing($conn, $listingId);
        adoptionExpireListingReservations($conn, $listingId);
        $resident = adoptionLockResidentAfterPet($conn, $residentId);
        $application = adoptionLockApplication($conn, $applicationId);
        if ($application && $application['status'] === 'expired') {
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The reservation expired and the pet is available again.');
        }
        if (!$listing || !$application || (int)$application['applicant_id'] !== $residentId
            || (int)$application['pet_listing_id'] !== $listingId
            || !in_array($application['status'], ['pending', 'under_review'], true)
            || $listing['status'] !== 'reserved') {
            throw new AdoptionWorkflowException('The application or reservation is no longer eligible for approval.');
        }
        adoptionResidentEligibilityFromRow($resident);
        if (adoptionAgeFromDate($application['date_of_birth'] ?? '') < 18
            || empty($application['residency_proof_file'])) {
            throw new AdoptionWorkflowException('The application does not include valid age and proof of residency.');
        }
        $petName = (string)$listing['name'];
        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE adoption_applications
             SET status = 'reserved', reviewed_by = ?, reviewed_at = NOW(),
                 approved_pickup_at = NULLIF(?, ''), reserved_at = NOW(),
                 response_deadline = DATE_ADD(NOW(), INTERVAL 3 DAY),
                 finalized_by = NULL, finalized_at = NULL, resulting_pet_id = NULL,
                 interview_at = NULLIF(?, ''), home_visit_at = NULLIF(?, ''), home_visit_notes = ?
             WHERE application_id = ? AND status IN ('pending','under_review')"
        ));
        $stmt->bind_param('issssi', $staffId, $pickupAt, $interviewAt, $homeVisitAt, $homeVisitNotes, $applicationId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new AdoptionWorkflowException('The application status changed before approval could be saved.');
        }
        $stmt->close();

        $message = 'Your request to adopt ' . $petName . ' has been approved by Dasmariñas City Veterinary Services. Confirm within three days using Yes, I\'m In to begin your three-day pickup period.';
        if ($pickup) {
            $message .= ' Your scheduled DCVS visit is ' . $pickup->format('F j, Y') . ' at ' . $pickup->format('g:i A') . '.';
        }
        adoptionNotifyResident($conn, $residentId, 'Adoption Application Approved', $message, $applicationId);
        adoptionCommit($conn);

        $warnings = [];
        try {
            require_once __DIR__ . '/../mailer.php';
            require_once __DIR__ . '/../sms-sender.php';
        } catch (Throwable $notificationException) {
            error_log('Could not load adoption approval notification helpers: ' . $notificationException->getMessage());
            return ['The approval was saved, but email and SMS notifications could not be prepared.'];
        }
        $email = trim((string)($resident['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
                $html = dcvsEmailTemplate('Adoption application approved', '<p style="margin:0 0 16px;">' . $safeMessage . '</p>');
                if (enqueueDcvsEmail($conn, $email, (string)$resident['full_name'], 'Adoption application approved', $html, $staffId, 'resident', 'adoption_approval') === null) {
                    error_log('Failed to queue adoption approval email for resident ' . $residentId . ', application ' . $applicationId);
                    $warnings[] = 'The approval was saved, but the email could not be queued.';
                } elseif (!spawnEmailWorker()) {
                    error_log('Could not start email worker for adoption application ' . $applicationId);
                    $warnings[] = 'The approval email is queued but could not be sent automatically.';
                }
            } catch (Throwable $notificationException) {
                error_log('Could not queue adoption approval email for application ' . $applicationId . ': ' . $notificationException->getMessage());
                $warnings[] = 'The approval was saved, but the email could not be queued.';
            }
        } else {
            $warnings[] = 'The approval was saved, but the resident does not have a valid email address on file.';
        }
        $phone = trim((string)($resident['phone'] ?? ''));
        if ($phone === '') {
            $warnings[] = 'The approval was saved, but the resident does not have a phone number on file for SMS.';
        } else {
            try {
                if (!isSmsEnabled($conn)) {
                    $warnings[] = 'The approval was saved, but SMS notifications are disabled.';
                } elseif (!queueSms($conn, $phone, (string)$resident['full_name'], 'DCVS: ' . $message, $staffId, 'resident', 'adoption_approval')) {
                    error_log('Failed to queue adoption approval SMS for resident ' . $residentId . ', application ' . $applicationId);
                    $warnings[] = 'The approval was saved, but the SMS could not be queued.';
                }
            } catch (Throwable $notificationException) {
                error_log('Could not queue adoption approval SMS for application ' . $applicationId . ': ' . $notificationException->getMessage());
                $warnings[] = 'The approval was saved, but the SMS could not be queued.';
            }
        }
        return $warnings;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionFinalizeApplication($conn, $applicationId, $staffId)
{
    $lookup = adoptionRequireStatement($conn->prepare(
        'SELECT pet_listing_id, applicant_id FROM adoption_applications WHERE application_id = ?'
    ));
    $lookup->bind_param('i', $applicationId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row || (int)$row['pet_listing_id'] <= 0) {
        throw new AdoptionWorkflowException('Adoption application not found.');
    }
    $listingId = (int)$row['pet_listing_id'];
    $residentId = (int)$row['applicant_id'];

    $conn->begin_transaction();
    try {
        $listing = adoptionLockPetListing($conn, $listingId);
        $resident = adoptionLockResidentAfterPet($conn, $residentId);
        $application = adoptionLockApplication($conn, $applicationId);
        if (!$listing || !$application || (int)$application['applicant_id'] !== $residentId
            || (int)$application['pet_listing_id'] !== $listingId
            || $application['status'] !== 'confirmed'
            || $listing['status'] !== 'reserved'
            || empty($application['pickup_deadline'])) {
            throw new AdoptionWorkflowException('Only a confirmed reservation can be finalized as adopted.');
        }
        $deadlineStmt = adoptionRequireStatement($conn->prepare(
            'SELECT pickup_deadline > NOW() AS deadline_open FROM adoption_applications WHERE application_id = ?'
        ));
        $deadlineStmt->bind_param('i', $applicationId);
        $deadlineStmt->execute();
        $deadlineOpen = (int)$deadlineStmt->get_result()->fetch_assoc()['deadline_open'] === 1;
        $deadlineStmt->close();
        if (!$deadlineOpen) {
            adoptionExpireListingReservations($conn, $listingId);
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The pickup deadline passed. This reservation has expired and the pet is available again.');
        }
        adoptionResidentEligibilityFromRow($resident);

        $species = strtolower(trim((string)$listing['species']));
        $petType = in_array($species, ['dog', 'cat'], true) ? $species : 'other';
        preg_match('/\d+/', (string)$listing['age'], $ageMatch);
        $age = isset($ageMatch[0]) ? (int)$ageMatch[0] : null;
        $weightValue = preg_replace('/[^0-9.]/', '', (string)$listing['weight']);
        $weight = $weightValue !== '' && is_numeric($weightValue) ? (float)$weightValue : null;
        $gender = strtolower((string)$listing['gender']);
        if (!in_array($gender, ['male', 'female'], true)) {
            $gender = null;
        }
        $petName = (string)$listing['name'];
        $petBreed = (string)$listing['breed'];
        $petColor = (string)$listing['color'];
        $photo = (string)($listing['photo_url'] ?? '');
        $referenceId = generateReferenceId($conn, '#PET-', 'pets');
        $stmt = adoptionRequireStatement($conn->prepare(
            "INSERT INTO pets
             (owner_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, reference_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Status to be confirmed', ?, ?)"
        ));
        $stmt->bind_param('isssidssss', $residentId, $petName, $petType, $petBreed, $age, $weight, $gender, $petColor, $photo, $referenceId);
        $stmt->execute();
        $petId = (int)$stmt->insert_id;
        $stmt->close();

        $description = (string)$listing['description'];
        $stmt = adoptionRequireStatement($conn->prepare(
            "INSERT INTO adoption_listings (pet_id, listed_by, source_listing_id, title, description, status)
             VALUES (?, ?, ?, ?, ?, 'adopted')"
        ));
        $stmt->bind_param('iiiss', $petId, $residentId, $listingId, $petName, $description);
        $stmt->execute();
        $stmt->close();

        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE pet_listings SET status = 'adopted', adopted_at = NOW(), adopted_by = ?, updated_at = NOW()
             WHERE listing_id = ? AND status = 'reserved'"
        ));
        $stmt->bind_param('ii', $residentId, $listingId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new AdoptionWorkflowException('The pet reservation changed before finalization.');
        }
        $stmt->close();

        $stmt = adoptionRequireStatement($conn->prepare(
            "UPDATE adoption_applications
             SET status = 'adopted', resulting_pet_id = ?, finalized_by = ?, finalized_at = NOW()
             WHERE application_id = ? AND status = 'confirmed' AND pickup_deadline > NOW()"
        ));
        $stmt->bind_param('iii', $petId, $staffId, $applicationId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            $conn->rollback();
            $conn->begin_transaction();
            adoptionLockPetListing($conn, $listingId);
            adoptionExpireListingReservations($conn, $listingId);
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The application changed before finalization.');
        }
        $stmt->close();

        adoptionNotifyResident($conn, $residentId, 'Adoption Finalized', 'The adoption of ' . $petName . ' has been finalized. The pet is now registered to your profile.', $applicationId);
        adoptionCommit($conn);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function adoptionReviewApplication($conn, $applicationId, $staffId, $decision, $reason, $pickupAt = '', $interviewAt = '', $homeVisitAt = '', $homeVisitNotes = '')
{
    $reason = adoptionInputText($reason);
    $homeVisitNotes = adoptionInputText($homeVisitNotes);
    if ($decision === 'approved') {
        return adoptionApproveApplication($conn, $applicationId, $staffId, $pickupAt, $interviewAt, $homeVisitAt, $homeVisitNotes);
    }
    if (!in_array($decision, ['rejected', 'needs_info'], true) || $reason === ''
        || strlen($reason) > 5000 || strlen($homeVisitNotes) > 5000) {
        throw new AdoptionWorkflowException('Provide a reason for rejection or the additional information requested.');
    }

    $lookup = adoptionRequireStatement($conn->prepare(
        'SELECT pet_listing_id, applicant_id FROM adoption_applications WHERE application_id = ?'
    ));
    $lookup->bind_param('i', $applicationId);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row || (int)$row['pet_listing_id'] <= 0) {
        throw new AdoptionWorkflowException('Adoption application not found.');
    }
    $listingId = (int)$row['pet_listing_id'];
    $residentId = (int)$row['applicant_id'];

    $conn->begin_transaction();
    try {
        adoptionLockPetListing($conn, $listingId);
        adoptionExpireListingReservations($conn, $listingId);
        adoptionLockResidentAfterPet($conn, $residentId);
        $application = adoptionLockApplication($conn, $applicationId);
        if ($application && $application['status'] === 'expired') {
            adoptionCommit($conn);
            throw new AdoptionWorkflowException('The reservation expired and the pet is available again.');
        }
        if (!$application || (int)$application['applicant_id'] !== $residentId
            || !in_array($application['status'], ['pending', 'under_review', 'needs_info'], true)) {
            throw new AdoptionWorkflowException('This application has already been reviewed.');
        }

        if ($decision === 'needs_info') {
            $stmt = adoptionRequireStatement($conn->prepare(
                "UPDATE adoption_applications
                 SET status = 'needs_info', requested_info = ?, info_deadline = DATE_ADD(NOW(), INTERVAL 7 DAY),
                     reviewed_by = ?, reviewed_at = NOW(), interview_at = NULLIF(?, ''),
                     home_visit_at = NULLIF(?, ''), home_visit_notes = ?
                 WHERE application_id = ?"
            ));
            $stmt->bind_param('sisssi', $reason, $staffId, $interviewAt, $homeVisitAt, $homeVisitNotes, $applicationId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = adoptionRequireStatement($conn->prepare(
                "UPDATE adoption_applications
                 SET status = 'rejected', rejection_reason = ?, info_deadline = NULL,
                     reviewed_by = ?, reviewed_at = NOW(), interview_at = NULLIF(?, ''),
                     home_visit_at = NULLIF(?, ''), home_visit_notes = ?
                 WHERE application_id = ?"
            ));
            $stmt->bind_param('sisssi', $reason, $staffId, $interviewAt, $homeVisitAt, $homeVisitNotes, $applicationId);
            $stmt->execute();
            $stmt->close();
            adoptionReleasePetIfUnreserved($conn, $listingId);
        }
        adoptionNotifyResident(
            $conn,
            $residentId,
            $decision === 'needs_info' ? 'More Information Requested' : 'Adoption Application Rejected',
            $decision === 'needs_info' ? 'Staff requested more information. Respond within seven days to keep the reservation.' : 'Your adoption application was rejected. Staff reason: ' . $reason,
            $applicationId
        );
        adoptionCommit($conn);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
