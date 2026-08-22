<?php
require_once 'db.php';

// Fetch a rejected report
$stmt = $conn->prepare("SELECT * FROM reports WHERE rejection_reason IS NOT NULL AND rejection_reason != '' LIMIT 1");
if (!$stmt) { echo "Prepare failed: " . $conn->error; exit; }
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) { echo "No rejected reports found.\n"; exit; }

echo "Report ID: {$report['report_id']}\n";
echo "Status: {$report['status']}\n";
echo "Rejection reason: " . substr($report['rejection_reason'], 0, 100) . "...\n";
echo "Description length: " . strlen($report['description']) . "\n";

echo "\n--- Checking description for problematic characters ---\n";
$desc = $report['description'];
echo "Contains \\n (literal backslash+n): " . (strpos($desc, '\\n') !== false ? "YES\n" : "NO\n");
echo "Contains backtick: " . (strpos($desc, '`') !== false ? "YES\n" : "NO\n");
echo "Contains \$ sign: " . (strpos($desc, '$') !== false ? "YES\n" : "NO\n");
echo "Contains single quote: " . (strpos($desc, "'") !== false ? "YES\n" : "NO\n");

$json = json_encode([
    'ref_id' => $report['reference_id'] ?? '#RPT-' . str_pad($report['report_id'], 4, '0', STR_PAD_LEFT),
    'report_type' => ucfirst($report['report_type']),
    'created_at' => date('M d, Y', strtotime($report['created_at'])),
    'barangay_name' => 'N/A',
    'location_address' => $report['location_address'],
    'description' => $report['description'],
    'photo_url' => $report['photo_url'],
    'urgency_level' => $report['urgency_level'] ?? 'low',
    'rejection_reason' => $report['rejection_reason'],
    'rejected_by' => 'DCVS Staff',
    'rejected_at' => 'N/A'
], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

echo "\nJSON length: " . strlen($json) . "\n";
echo "JSON valid: " . (json_decode($json) !== null ? "YES\n" : "NO - " . json_last_error_msg() . "\n");
echo "\nJSON content (first 200 chars):\n";
echo substr($json, 0, 200) . "...\n";

// Check for special characters in the JSON
echo "\n--- Checking JSON for problematic characters ---\n";
echo "Contains Unicode: " . (preg_match('/\\\\u[0-9a-f]{4}/i', $json) ? "YES\n" : "NO\n");
echo "Contains HTML-unsafe & (not \\u0026): " . (preg_match('/(?<!\\\\u002)&(?!amp;)/', $json) ? "YES\n" : "NO\n");
echo "Contains raw backtick: " . (strpos($json, '`') !== false ? "YES (` found)\n" : "NO\n");
echo "Contains raw single quote: " . (strpos($json, "'") !== false ? "YES (' found)\n" : "NO\n");

$conn->close();
?>
