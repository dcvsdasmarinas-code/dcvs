<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');

$validStatuses = ['rescued', 'available', 'adopted', 'withdrawn'];
$wheres = ["pl.listing_id IS NOT NULL"];
if ($search !== '') {
    $esc = $conn->real_escape_string($search);
    $wheres[] = "(pl.name LIKE '%$esc%' OR pl.breed LIKE '%$esc%' OR pl.species LIKE '%$esc%')";
}
if ($status !== '' && in_array($status, $validStatuses, true)) {
    $wheres[] = "pl.status = '" . $conn->real_escape_string($status) . "'";
}

$whereSQL = 'WHERE ' . implode(' AND ', $wheres);

$query = "SELECT pl.listing_id, pl.name, pl.species, pl.breed, pl.age, pl.gender, pl.weight, pl.color,
    pl.status, pl.photo_url, pl.rescued_date, pl.description, pl.rescue_intake_id, pl.created_at, pl.updated_at,
    al.listing_id AS adoption_id, al.status AS adoption_status
    FROM pet_listings pl
    LEFT JOIN adoption_listings al ON al.source_listing_id = pl.listing_id
    $whereSQL
    ORDER BY pl.created_at DESC";

$result = $conn->query($query);
$animals = [];
while ($row = $result->fetch_assoc()) {
    $animals[] = [
        'id' => (int)$row['listing_id'],
        'name' => $row['name'],
        'species' => $row['species'],
        'breed' => $row['breed'],
        'age' => $row['age'],
        'gender' => $row['gender'],
        'weight' => $row['weight'],
        'color' => $row['color'],
        'status' => $row['status'],
        'photo' => $row['photo_url'],
        'rescued_date' => $row['rescued_date'],
        'description' => $row['description'],
        'rescue_intake_id' => $row['rescue_intake_id'] ? (int)$row['rescue_intake_id'] : null,
        'adoption_id' => $row['adoption_id'] ? (int)$row['adoption_id'] : null,
        'adoption_status' => $row['adoption_status'],
        'created_at' => $row['created_at'],
    ];
}

$total = count($animals);
$counts = ['rescued' => 0, 'available' => 0, 'adopted' => 0, 'withdrawn' => 0];
$cntQ = $conn->query("SELECT status, COUNT(*) AS c FROM pet_listings GROUP BY status");
if ($cntQ) {
    while ($cntR = $cntQ->fetch_assoc()) {
        if (isset($counts[$cntR['status']])) {
            $counts[$cntR['status']] = (int)$cntR['c'];
        }
    }
}

echo json_encode([
    'animals' => $animals,
    'total' => $total,
    'rescued' => $counts['rescued'],
    'available' => $counts['available'],
    'adopted' => $counts['adopted'],
    'withdrawn' => $counts['withdrawn'],
]);
