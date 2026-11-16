<?php
require_once __DIR__ . '/../includes/pet-duplicates.php';

function assertPetDuplicate(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$base = [
    'pet_name' => 'Milo',
    'pet_type' => 'dog',
    'breed' => 'Aspin',
    'gender' => 'male',
    'age' => 3,
    'color' => 'Brown with white chest',
    'date_of_birth' => '',
    'microchip_id' => '',
];

$candidate = $base;
$candidate['pet_id'] = 1;
$candidate['owner_id'] = 10;
$candidate['microchip_id'] = 'MC-001';

$sameChip = $base;
$sameChip['pet_name'] = 'Different name';
$sameChip['pet_type'] = 'cat';
$sameChip['microchip_id'] = 'mc-001';
assertPetDuplicate(
    petDuplicateMatchFields($sameChip, $candidate) === ['microchip_id'],
    'An exact microchip match should be a strong candidate even if profile details differ.'
);

$nameOnly = $base;
$nameOnly['breed'] = '';
$nameOnly['gender'] = '';
$nameOnly['age'] = null;
$nameOnly['color'] = '';
assertPetDuplicate(
    petDuplicateMatchFields($nameOnly, $candidate) === null,
    'A pet name and species alone must not create a duplicate candidate.'
);

$sameNameDifferentProfile = $base;
$sameNameDifferentProfile['breed'] = 'Beagle';
$sameNameDifferentProfile['gender'] = 'female';
$sameNameDifferentProfile['age'] = 8;
$sameNameDifferentProfile['color'] = 'white';
assertPetDuplicate(
    petDuplicateMatchFields($sameNameDifferentProfile, $candidate) === null,
    'Pets with the same name and species but different profile details must not be flagged as duplicates.'
);

$supportedProfile = $base;
$supportedProfile['pet_name'] = '  MILO ';
$supportedProfile['breed'] = 'aspin';
$supportedProfile['gender'] = 'Male';
$supportedProfile['age'] = null;
$supportedProfile['color'] = '';
assertPetDuplicate(
    petDuplicateMatchFields($supportedProfile, $candidate) === ['breed', 'gender'],
    'A matching name/species plus two supporting identity fields should create a candidate.'
);

$oneSupport = $base;
$oneSupport['breed'] = 'Aspin';
$oneSupport['gender'] = '';
$oneSupport['age'] = null;
$oneSupport['color'] = '';
assertPetDuplicate(
    petDuplicateMatchFields($oneSupport, $candidate) === null,
    'A matching name/species plus only one supporting field must not create a candidate.'
);

$ageAndColor = $base;
$ageAndColor['gender'] = '';
$ageAndColor['breed'] = '';
$ageAndColor['color'] = 'brown with white chest';
$ageAndColor['age'] = 4;
assertPetDuplicate(
    petDuplicateMatchFields($ageAndColor, $candidate) === ['color', 'age'],
    'Color and an age within one year should count as supporting identity details.'
);

$identity = petDuplicateIdentityFromInput([
    'pet_name' => ['invalid'],
    'species' => 'dog',
    'gender' => 'male',
    'age' => '2.5',
]);
assertPetDuplicate(
    $identity['pet_name'] === ''
        && in_array('Age must be a whole number between 0 and 100.', petDuplicateIdentityErrors($identity, ['age' => '2.5']), true),
    'Malformed identity values should be rejected without being treated as valid pet details.'
);

echo "Pet duplicate logic tests passed." . PHP_EOL;
