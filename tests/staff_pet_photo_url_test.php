<?php
require_once __DIR__ . '/../includes/staff-formatters.php';

function assertStaffPetPhotoUrl(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

assertStaffPetPhotoUrl(
    staffPetPhotoUrl('uploads/pets/bruno.jpg', '/dcvs') === '/dcvs/uploads/pets/bruno.jpg',
    'Stored relative pet photo paths should resolve against the app base URL.'
);
assertStaffPetPhotoUrl(
    staffPetPhotoUrl('https://cdn.example.test/bruno.jpg', '/dcvs') === 'https://cdn.example.test/bruno.jpg',
    'Absolute pet photo URLs should remain unchanged.'
);
assertStaffPetPhotoUrl(
    staffPetPhotoUrl('', '/dcvs') === '',
    'Missing pet photos should allow the placeholder avatar to display.'
);

$reviewPage = file_get_contents(__DIR__ . '/../pages/staff/pet-duplicate-reviews.php');
assertStaffPetPhotoUrl(
    is_string($reviewPage)
        && strpos($reviewPage, 'new_pet.photo_url AS new_pet_photo_url') !== false
        && strpos($reviewPage, 'candidate.photo_url AS candidate_photo_url') !== false
        && strpos($reviewPage, '$candidatePhotoUrl') !== false
        && strpos($reviewPage, '$newPetPhotoUrl') !== false
        && strpos($reviewPage, '<img src="<?php echo htmlspecialchars($candidatePhotoUrl') !== false
        && strpos($reviewPage, '<img src="<?php echo htmlspecialchars($newPetPhotoUrl') !== false,
    'The review comparison should load and render photos for both profiles.'
);

echo "Staff pet photo URL tests passed." . PHP_EOL;
