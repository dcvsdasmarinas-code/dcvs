<?php

function petDuplicateNormalize($value): string
{
    $value = trim((string)$value);
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function petDuplicateIdentityFromInput(array $input): array
{
    $read = static function (string $key) use ($input): string {
        $value = $input[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    };

    $species = $read('species');
    if ($species === 'other' && $read('species_custom') !== '') {
        $species = $read('species_custom');
    }

    $ageInput = $read('age');
    $age = $ageInput !== '' && is_numeric($ageInput) ? (int)$ageInput : null;

    return [
        'pet_name' => $read('pet_name'),
        'pet_type' => $species,
        'breed' => $read('breed'),
        'gender' => $read('gender'),
        'age' => $age,
        'color' => $read('color'),
        'date_of_birth' => $read('date_of_birth'),
        'microchip_id' => strtoupper($read('microchip_id')),
    ];
}

function petDuplicateIdentityErrors(array $identity, array $input): array
{
    $errors = [];
    $ageValue = $input['age'] ?? '';
    $ageInput = is_scalar($ageValue) ? trim((string)$ageValue) : '';

    if ($identity['pet_name'] === '') {
        $errors[] = 'Pet name is required.';
    } elseif (strlen($identity['pet_name']) > 50) {
        $errors[] = 'Pet name must be 50 characters or fewer.';
    }
    if ($identity['pet_type'] === '') {
        $errors[] = 'Species is required.';
    }
    if ($identity['gender'] === '') {
        $errors[] = 'Gender is required.';
    }
    if ($ageInput !== '' && (!ctype_digit($ageInput) || (int)$ageInput > 100)) {
        $errors[] = 'Age must be a whole number between 0 and 100.';
    }
    if (strlen($identity['breed']) > 50) {
        $errors[] = 'Breed must be 50 characters or fewer.';
    }
    if (strlen($identity['color']) > 50) {
        $errors[] = 'Color / markings must be 50 characters or fewer.';
    }
    if (strlen($identity['microchip_id']) > 50) {
        $errors[] = 'Microchip number must be 50 characters or fewer.';
    }

    $dateOfBirth = $identity['date_of_birth'];
    if ($dateOfBirth !== '') {
        $today = date('Y-m-d');
        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth) === 1
            && checkdate((int)substr($dateOfBirth, 5, 2), (int)substr($dateOfBirth, 8, 2), (int)substr($dateOfBirth, 0, 4))
            && $dateOfBirth <= $today;
        if (!$validDate) {
            $errors[] = 'Enter a valid date of birth that is not in the future.';
        }
    }

    return $errors;
}

function petDuplicateMatchFields(array $identity, array $candidate): ?array
{
    $microchipId = petDuplicateNormalize($identity['microchip_id'] ?? '');
    $candidateMicrochipId = petDuplicateNormalize($candidate['microchip_id'] ?? '');
    if ($microchipId !== '' && $candidateMicrochipId !== '' && $microchipId === $candidateMicrochipId) {
        return ['microchip_id'];
    }

    if (petDuplicateNormalize($identity['pet_name'] ?? '') === ''
        || petDuplicateNormalize($identity['pet_type'] ?? '') === ''
        || petDuplicateNormalize($identity['pet_name'] ?? '') !== petDuplicateNormalize($candidate['pet_name'] ?? '')
        || petDuplicateNormalize($identity['pet_type'] ?? '') !== petDuplicateNormalize($candidate['pet_type'] ?? '')) {
        return null;
    }

    $matchedFields = [];
    foreach (['breed', 'gender', 'color', 'date_of_birth'] as $field) {
        $submittedValue = petDuplicateNormalize($identity[$field] ?? '');
        $candidateValue = petDuplicateNormalize($candidate[$field] ?? '');
        if ($submittedValue !== '' && $candidateValue !== '' && $submittedValue === $candidateValue) {
            $matchedFields[] = $field;
        }
    }

    $submittedAge = $identity['age'] ?? null;
    $candidateAge = $candidate['age'] ?? null;
    if ($submittedAge !== null && $candidateAge !== null && abs((int)$submittedAge - (int)$candidateAge) <= 1) {
        $matchedFields[] = 'age';
    }

    return count($matchedFields) >= 2 ? $matchedFields : null;
}

function petDuplicateFindCandidates(mysqli $conn, int $ownerId, array $identity): array
{
    $stmt = $conn->prepare("
        SELECT p.pet_id, p.owner_id, p.pet_name, p.pet_type, p.breed, p.age, p.gender,
               p.color, p.date_of_birth, p.microchip_id
        FROM pets p
        JOIN users u ON u.user_id = p.owner_id
        WHERE p.archived_at IS NULL
          AND u.role = 'resident' AND u.status = 'active'
          AND u.archived_at IS NULL AND u.deleted_at IS NULL
          AND (
              (? <> '' AND p.microchip_id = ?)
              OR (p.pet_name = ? AND p.pet_type = ?)
          )
        ORDER BY p.pet_id DESC
    ");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare pet duplicate lookup: ' . $conn->error);
    }

    $name = trim((string)$identity['pet_name']);
    $species = trim((string)$identity['pet_type']);
    $microchipId = strtoupper(trim((string)$identity['microchip_id']));
    $stmt->bind_param('ssss', $microchipId, $microchipId, $name, $species);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not execute pet duplicate lookup: ' . $error);
    }

    $result = $stmt->get_result();
    $matches = [];
    while ($candidate = $result->fetch_assoc()) {
        $matchedFields = petDuplicateMatchFields($identity, $candidate);
        if ($matchedFields === null) {
            continue;
        }
        $candidate['matched_fields'] = $matchedFields;
        $candidate['match_type'] = in_array('microchip_id', $matchedFields, true) ? 'microchip' : 'profile';
        $matches[] = $candidate;
    }
    $stmt->close();

    return $matches;
}

function petDuplicateCreateReviewRows(mysqli $conn, int $petId, array $matches): void
{
    if (!$matches) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO pet_duplicate_reviews
            (pet_id, candidate_pet_id, match_type, matched_fields)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Could not prepare pet duplicate review records: ' . $conn->error);
    }

    foreach ($matches as $match) {
        $candidatePetId = (int)$match['pet_id'];
        $matchType = (string)$match['match_type'];
        $matchedFields = json_encode($match['matched_fields'], JSON_UNESCAPED_UNICODE);
        if ($matchedFields === false) {
            $stmt->close();
            throw new RuntimeException('Could not encode pet duplicate match details.');
        }
        $stmt->bind_param('iiss', $petId, $candidatePetId, $matchType, $matchedFields);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not save pet duplicate review record: ' . $error);
        }
    }
    $stmt->close();
}
