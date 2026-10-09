<?php
session_name('DCVS_STAFF_SESSION');
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

require_once __DIR__ . '/../../db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$staffName = $_SESSION['full_name'] ?? 'Staff Member';
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$now = date('F j, Y \a\t g:i A');

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ KPI QUERIES ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
$totalResidents = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'active' AND deleted_at IS NULL")->fetch_row()[0] ?? 0);
$totalPets = (int)($conn->query("SELECT COUNT(*) FROM pets p JOIN users u ON p.owner_id = u.user_id WHERE p.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$totalReports = (int)($conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$activeRabies = (int)($conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE r.report_type='rabies' AND r.status IN ('pending','validated') AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$confirmedCasesTotal = (int)($conn->query("SELECT COUNT(*) FROM confirmed_cases cc JOIN reports r ON cc.report_id = r.report_id JOIN users u ON r.reporter_id = u.user_id WHERE u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$resolvedCases = (int)($conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE r.status='resolved' AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$pendingReports = (int)($conn->query("SELECT COUNT(*) FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE r.status='pending' AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$scheduledAppts = (int)($conn->query("SELECT COUNT(*) FROM appointments a JOIN users u ON a.user_id = u.user_id WHERE a.status IN ('approved','pending') AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$completedAppts = (int)($conn->query("SELECT COUNT(*) FROM appointments a JOIN users u ON a.user_id = u.user_id WHERE a.status='completed' AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$totalAppts = (int)($conn->query("SELECT COUNT(*) FROM appointments a JOIN users u ON a.user_id = u.user_id WHERE u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$adoptionListings = (int)($conn->query("SELECT COUNT(*) FROM adoption_listings al JOIN users u ON al.listed_by = u.user_id WHERE u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$adoptionApplications = (int)($conn->query("SELECT COUNT(*) FROM adoption_applications aa JOIN users u ON aa.applicant_id = u.user_id WHERE u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$totalBarangays = (int)($conn->query("SELECT COUNT(*) FROM barangays")->fetch_row()[0] ?? 0);
$vaccinatedPets = (int)($conn->query("SELECT COUNT(*) FROM pets p JOIN users u ON p.owner_id = u.user_id WHERE p.archived_at IS NULL AND p.vaccination_status IS NOT NULL AND p.vaccination_status != '' AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);
$completedVaxAppts = (int)($conn->query("SELECT COUNT(*) FROM appointments a JOIN users u ON a.user_id = u.user_id WHERE a.appointment_type='vaccination' AND a.status='completed' AND u.status = 'active' AND u.deleted_at IS NULL")->fetch_row()[0] ?? 0);

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ REPORTS BREAKDOWN ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
$reportsByTypeStatus = [];
$rbts = $conn->query("SELECT r.report_type, r.status, COUNT(*) as c FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL GROUP BY r.report_type, r.status ORDER BY r.report_type, r.status");
if ($rbts) {
    while ($r = $rbts->fetch_assoc()) {
        $type = $r['report_type'];
        $status = $r['status'];
        if (!isset($reportsByTypeStatus[$type])) $reportsByTypeStatus[$type] = ['total' => 0];
        if (!isset($reportsByTypeStatus[$type][$status])) $reportsByTypeStatus[$type][$status] = 0;
        $reportsByTypeStatus[$type][$status] += (int)$r['c'];
        $reportsByTypeStatus[$type]['total'] += (int)$r['c'];
    }
}

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ BARANGAY SUMMARY ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
$barangayData = [];
$bq = $conn->query("
    SELECT
      b.barangay_name,
      COUNT(DISTINCT r.report_id) as total_reports,
      COUNT(DISTINCT CASE WHEN r.report_type='rabies' AND r.status IN ('pending','validated') THEN r.report_id END) as active_rabies,
      COUNT(DISTINCT p.pet_id) as registered_pets,
      COUNT(DISTINCT CASE WHEN a.appointment_type='vaccination' AND a.status='completed' THEN a.appointment_id END) as vaccinations
    FROM barangays b
    LEFT JOIN users u ON u.barangay_id = b.barangay_id AND u.role='resident' AND u.status='active' AND u.deleted_at IS NULL
    LEFT JOIN reports r ON r.barangay_id = b.barangay_id AND r.reporter_id = u.user_id AND r.archived_at IS NULL
    LEFT JOIN pets p ON p.owner_id = u.user_id AND p.archived_at IS NULL
    LEFT JOIN appointments a ON a.user_id = u.user_id AND a.appointment_type='vaccination' AND a.status='completed'
    GROUP BY b.barangay_id, b.barangay_name
    ORDER BY total_reports DESC
");
if ($bq) {
    while ($r = $bq->fetch_assoc()) {
        $ar = (int)$r['active_rabies'];
        $tr = (int)$r['total_reports'];
        if ($ar >= 10 || ($tr >= 30 && $tr > 0 && $ar / $tr > 0.4)) {
            $risk = 'Critical';
        } elseif ($ar >= 5 || ($tr > 0 && $ar / $tr > 0.3)) {
            $risk = 'High';
        } elseif ($ar >= 1) {
            $risk = 'Medium';
        } else {
            $risk = 'Low';
        }
        $barangayData[] = [
            'name' => $r['barangay_name'],
            'total_reports' => $tr,
            'active_rabies' => $ar,
            'registered_pets' => (int)$r['registered_pets'],
            'vaccinations' => (int)$r['vaccinations'],
            'risk' => $risk,
        ];
    }
}

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ MONTHLY TRENDS ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
$monthlyReports = array_fill(1, 12, 0);
$monthlyRabies = array_fill(1, 12, 0);
$monthlyPets = array_fill(1, 12, 0);
$monthlyAppts = array_fill(1, 12, 0);

$mrq = $conn->query("SELECT MONTH(r.created_at) as m, COUNT(*) as c FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE YEAR(r.created_at)=$currentYear AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL GROUP BY MONTH(r.created_at)");
if ($mrq) { while ($r = $mrq->fetch_assoc()) { $monthlyReports[(int)$r['m']] = (int)$r['c']; } }

$mrb = $conn->query("SELECT MONTH(r.created_at) as m, COUNT(*) as c FROM reports r JOIN users u ON r.reporter_id = u.user_id WHERE r.report_type='rabies' AND YEAR(r.created_at)=$currentYear AND r.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL GROUP BY MONTH(r.created_at)");
if ($mrb) { while ($r = $mrb->fetch_assoc()) { $monthlyRabies[(int)$r['m']] = (int)$r['c']; } }

$mpq = $conn->query("SELECT MONTH(p.created_at) as m, COUNT(*) as c FROM pets p JOIN users u ON p.owner_id = u.user_id WHERE YEAR(p.created_at)=$currentYear AND p.archived_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL GROUP BY MONTH(p.created_at)");
if ($mpq) { while ($r = $mpq->fetch_assoc()) { $monthlyPets[(int)$r['m']] = (int)$r['c']; } }

$maq = $conn->query("SELECT MONTH(a.preferred_date) as m, COUNT(*) as c FROM appointments a JOIN users u ON a.user_id = u.user_id WHERE YEAR(a.preferred_date)=$currentYear AND u.status = 'active' AND u.deleted_at IS NULL GROUP BY MONTH(a.preferred_date)");
if ($maq) { while ($r = $maq->fetch_assoc()) { $monthlyAppts[(int)$r['m']] = (int)$r['c']; } }

// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ INSIGHTS ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
$reportTypeLabels = ['stray' => 'Stray', 'rabies' => 'Rabies', 'injured' => 'Injured', 'dead' => 'Dead'];
$maxCategory = 'N/A';
$maxCatCount = 0;
foreach ($reportsByTypeStatus as $type => $data) {
    if ($data['total'] > $maxCatCount) {
        $maxCatCount = $data['total'];
        $maxCategory = $reportTypeLabels[$type] ?? ucfirst($type);
    }
}

$topBarangay = $barangayData[0]['name'] ?? 'N/A';
$topBarangayReports = $barangayData[0]['total_reports'] ?? 0;
$topRabiesBarangay = 'N/A';
$topRabiesCount = 0;
foreach ($barangayData as $b) {
    if ($b['active_rabies'] > $topRabiesCount) {
        $topRabiesCount = $b['active_rabies'];
        $topRabiesBarangay = $b['name'];
    }
}

$peakMonth = 1;
$peakCount = 0;
$monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
foreach ($monthlyReports as $m => $c) {
    if ($c > $peakCount) { $peakCount = $c; $peakMonth = $m; }
}

$rabiesPct = $totalReports > 0 ? round(($reportsByTypeStatus['rabies']['total'] ?? 0) / $totalReports * 100, 1) : 0;
$completionRate = $totalReports > 0 ? round($resolvedCases / $totalReports * 100, 1) : 0;
$thisMonthCount = $monthlyReports[$currentMonth];
$lastMonthCount = $currentMonth > 1 ? $monthlyReports[$currentMonth - 1] : 0;
$changePct = $lastMonthCount > 0 ? round(($thisMonthCount - $lastMonthCount) / $lastMonthCount * 100, 1) : ($thisMonthCount > 0 ? 100 : 0);
$changeDir = $changePct > 0 ? 'increase' : ($changePct < 0 ? 'decrease' : 'no change');
$changePctAbs = abs($changePct);

$allStatuses = ['pending', 'validated', 'rejected', 'confirmed', 'scheduled', 'resolved'];
$statusLabels = ['pending' => 'Pending', 'validated' => 'Validated', 'rejected' => 'Rejected', 'confirmed' => 'Confirmed', 'scheduled' => 'Scheduled', 'resolved' => 'Resolved'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DCVS Citywide Veterinary Analytics Report <?php echo date('Y-m-d'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  @page { size: A4; margin: 18mm 20mm 20mm 20mm; }

  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Inter', 'Segoe UI', sans-serif;
    color: #1e293b;
    font-size: 9.5pt;
    line-height: 1.55;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .report-wrap { max-width: 100%; }
  .page-break { page-break-before: always; }

  /* HEADER */
  .report-header {
    display: flex; align-items: center; gap: 16px;
    padding-bottom: 14px; border-bottom: 3px solid #1e40af; margin-bottom: 20px;
  }
  .report-header img { height: 56px; width: auto; }
  .report-header h1 { font-size: 16pt; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.3px; font-family: 'Inter', sans-serif; }
  .report-header .subtitle { font-size: 8.5pt; color: #475569; margin-top: 2px; }
  .report-meta {
    margin-left: auto; text-align: right; font-size: 7.5pt; color: #64748b; line-height: 1.7;
  }
  .report-meta strong { color: #334155; }

  /* SECTION HEADINGS */
  h2 {
    font-size: 13pt; font-weight: 700; color: #1e40af;
    border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin: 22px 0 10px 0;
    font-family: 'Inter', sans-serif;
  }
  h3 { font-size: 10.5pt; font-weight: 700; color: #0f172a; margin: 14px 0 6px 0; font-family: 'Inter', sans-serif; }

  /* EXECUTIVE SUMMARY */
  .exec-summary { background: #f8fafc; border-left: 4px solid #1e40af; padding: 10px 14px; margin-bottom: 14px; border-radius: 0 4px 4px 0; font-size: 9pt; }
  .exec-summary p { margin-bottom: 4px; }
  .exec-highlights { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  .exec-highlights .hl { background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 4px 10px; font-size: 8pt; }
  .exec-highlights .hl strong { color: #1e40af; }

  /* TABLES */
  table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 8.5pt; }
  thead th {
    background: #1e40af; color: #fff; font-weight: 600; text-align: left;
    padding: 6px 8px; font-size: 8pt; letter-spacing: 0.3px;
  }
  tbody td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  tbody tr:nth-child(even) { background: #f8fafc; }
  tbody tr:hover { background: #eff6ff; }
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .fw-bold { font-weight: 700; }

  /* RISK BADGE */
  .risk-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 7.5pt; font-weight: 600; }
  .risk-Critical { background: #fef2f2; color: #dc2626; }
  .risk-High { background: #fff7ed; color: #ea580c; }
  .risk-Medium { background: #fffbeb; color: #d97706; }
  .risk-Low { background: #f0fdf4; color: #16a34a; }

  /* CHARTS */
  .chart-grid { display: flex; flex-direction: column; gap: 16px; margin: 10px 0 14px 0; }
  .chart-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; }
  .chart-box h4 { font-size: 9.5pt; font-weight: 700; color: #0f172a; margin-bottom: 6px; text-align: center; }
  .chart-box canvas { display: block; }
  @media screen { .chart-box canvas { max-width: 640px; } }
  @media print {
    .chart-box { break-inside: avoid; width: 100%; }
    .chart-box canvas { max-width: 100%; }
  }

  /* INSIGHTS */
  .insights-list { list-style: none; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 5px 16px; }
  .insights-list li { padding: 5px 10px; background: #f8fafc; border-left: 3px solid #1e40af; border-radius: 0 4px 4px 0; font-size: 8.5pt; }
  .insights-list li strong { color: #0f172a; }

  /* FOOTER */
  .report-footer {
    margin-top: 30px; padding-top: 10px; border-top: 2px solid #cbd5e1;
    font-size: 7pt; color: #64748b; text-align: center; line-height: 1.6;
  }

  /* PRINT */
  @media print {
    body { font-size: 9pt; }
    .page-break { page-break-before: always; }
    table { break-inside: auto; }
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
    .no-print { display: none !important; }
  }

  .no-print { text-align: center; margin: 20px 0; }
  .btn-print {
    display: inline-block; background: #1e40af; color: #fff; border: none;
    padding: 10px 28px; font-size: 10pt; font-weight: 600; border-radius: 6px;
    cursor: pointer; text-decoration: none;
  }
  .btn-print:hover { background: #1e3a8a; }
  .spinner { display: none; }
</style>
</head>
<body>

<div class="no-print" style="margin-bottom:10px; display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
  <button class="btn-print" onclick="window.print()">&#x1F4E9; Save as PDF (Print)</button>
  <button class="btn-print" id="downloadPdfBtn" onclick="downloadPDF()">&#x1F4E5; Download PDF</button>
  <button class="btn-print" style="background:#64748b;" onclick="window.close()">&#x2716; Close</button>
</div>
<div id="downloadStatus" class="no-print" style="text-align:center;font-size:9pt;color:#64748b;margin-bottom:8px;"></div>

<div class="report-wrap" id="reportWrap">

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 1. COVER / HEADER ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <div class="report-header">
    <img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" onerror="this.style.display='none'">
    <div>
      <h1>Citywide Veterinary Analytics Report</h1>
      <div class="subtitle">Dasmari&ntilde;as City Veterinary Services &mdash; Official Document</div>
    </div>
    <div class="report-meta">
      <div><strong>Report Period:</strong> January &ndash; <?php echo $monthNames[$currentMonth]; ?> <?php echo $currentYear; ?></div>
      <div><strong>Generated:</strong> <?php echo $now; ?></div>
      <div><strong>Prepared by:</strong> <?php echo htmlspecialchars($staffName); ?></div>
    </div>
  </div>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 2. EXECUTIVE SUMMARY ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>2. Executive Summary</h2>
  <div class="exec-summary">
    <p>
      This report provides a comprehensive overview of the veterinary activities and animal welfare initiatives
      of <strong>Dasmari&ntilde;as City Veterinary Services</strong> for the period of
      <strong>January through <?php echo $monthNames[$currentMonth]; ?> <?php echo $currentYear; ?></strong>.
      During this period, the city recorded <strong><?php echo number_format($totalReports); ?></strong> animal-related reports,
      with <strong><?php echo number_format($activeRabies); ?></strong> active rabies cases currently under monitoring.
      A total of <strong><?php echo number_format($totalPets); ?></strong> pets have been registered in the system,
      and <strong><?php echo number_format($totalResidents); ?></strong> residents are actively participating in the
      city's veterinary information system.
    </p>
    <p>
      The city's response network has completed <strong><?php echo number_format($resolvedCases); ?></strong> cases
      and facilitated <strong><?php echo number_format($completedAppts); ?></strong> completed appointments,
      including <strong><?php echo number_format($completedVaxAppts); ?></strong> completed vaccination appointments.
      The adoption program has registered <strong><?php echo number_format($adoptionListings); ?></strong> listings
      with <strong><?php echo number_format($adoptionApplications); ?></strong> applications received.
      These figures underscore the city's commitment to responsible pet ownership and public health safety across
      <strong><?php echo number_format($totalBarangays); ?></strong> barangays.
    </p>
    <div class="exec-highlights">
      <span class="hl"><strong><?php echo number_format($totalReports); ?></strong> Total Reports</span>
      <span class="hl"><strong><?php echo number_format($totalPets); ?></strong> Registered Pets</span>
      <span class="hl"><strong><?php echo number_format($activeRabies); ?></strong> Active Rabies Cases</span>
      <span class="hl"><strong><?php echo number_format($resolvedCases); ?></strong> Resolved Cases</span>
      <span class="hl"><strong><?php echo number_format($completedAppts); ?></strong> Completed Appointments</span>
      <span class="hl"><strong><?php echo number_format($totalResidents); ?></strong> Active Residents</span>
    </div>
  </div>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 3. KEY STATISTICS ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>3. Key Statistics</h2>
  <table>
    <thead>
      <tr><th style="width:60%;">Metric</th><th style="width:40%;" class="text-right">Value</th></tr>
    </thead>
    <tbody>
      <tr><td>Total Residents (Active)</td><td class="text-right fw-bold"><?php echo number_format($totalResidents); ?></td></tr>
      <tr><td>Registered Pets</td><td class="text-right fw-bold"><?php echo number_format($totalPets); ?></td></tr>
      <tr><td>Total Barangays Served</td><td class="text-right fw-bold"><?php echo number_format($totalBarangays); ?></td></tr>
      <tr><td>Total Reports Filed</td><td class="text-right fw-bold"><?php echo number_format($totalReports); ?></td></tr>
      <tr><td>Active Rabies Cases</td><td class="text-right fw-bold"><?php echo number_format($activeRabies); ?></td></tr>
      <tr><td>Resolved / Completed Cases</td><td class="text-right fw-bold"><?php echo number_format($resolvedCases); ?></td></tr>
      <tr><td>Pending Reports</td><td class="text-right fw-bold"><?php echo number_format($pendingReports); ?></td></tr>
      <tr><td>Confirmed Cases (All Time)</td><td class="text-right fw-bold"><?php echo number_format($confirmedCasesTotal); ?></td></tr>
      <tr><td>Scheduled Appointments</td><td class="text-right fw-bold"><?php echo number_format($scheduledAppts); ?></td></tr>
      <tr><td>Completed Appointments</td><td class="text-right fw-bold"><?php echo number_format($completedAppts); ?></td></tr>
      <tr><td>Vaccinated Pets</td><td class="text-right fw-bold"><?php echo number_format($vaccinatedPets); ?></td></tr>
      <tr><td>Adoption Listings</td><td class="text-right fw-bold"><?php echo number_format($adoptionListings); ?></td></tr>
      <tr><td>Adoption Applications</td><td class="text-right fw-bold"><?php echo number_format($adoptionApplications); ?></td></tr>
      <tr><td>Case Completion Rate</td><td class="text-right fw-bold"><?php echo $completionRate; ?>%</td></tr>
    </tbody>
  </table>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 4. REPORTS BREAKDOWN ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>4. Reports Breakdown</h2>
  <table>
    <thead>
      <tr>
        <th>Category</th>
        <th class="text-right">Total</th>
        <th class="text-right">% of Total</th>
        <th>Status Distribution</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $breakdownTypes = ['stray' => 'Stray Animal', 'rabies' => 'Rabies Suspect', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal'];
      foreach ($breakdownTypes as $btype => $blabel):
          $data = $reportsByTypeStatus[$btype] ?? ['total' => 0];
          $total = $data['total'];
          $pct = $totalReports > 0 ? round($total / $totalReports * 100, 1) : 0;
          $parts = [];
          foreach ($allStatuses as $st) {
              if (isset($data[$st]) && $data[$st] > 0) {
                  $parts[] = $statusLabels[$st] . ': ' . $data[$st];
              }
          }
      ?>
      <tr>
        <td><?php echo $blabel; ?></td>
        <td class="text-right fw-bold"><?php echo number_format($total); ?></td>
        <td class="text-right"><?php echo $pct; ?>%</td>
        <td style="font-size:8pt;"><?php echo !empty($parts) ? implode(', ', $parts) : 'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â'; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#eef2ff;border-top:2px solid #1e40af;">
        <td class="fw-bold">Total</td>
        <td class="text-right fw-bold"><?php echo number_format($totalReports); ?></td>
        <td class="text-right fw-bold">100%</td>
        <td></td>
      </tr>
    </tfoot>
  </table>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 5. BARANGAY SUMMARY ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>5. Barangay Summary</h2>
  <table>
    <thead>
      <tr>
        <th>Barangay</th>
        <th class="text-right">Reports</th>
        <th class="text-right">Active Rabies</th>
        <th class="text-right">Pets</th>
        <th class="text-right">Vaccinations</th>
        <th class="text-center">Risk Level</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($barangayData)): ?>
      <tr><td colspan="6" class="text-center" style="color:#94a3b8;padding:12px;">No barangay data available</td></tr>
      <?php else: ?>
      <?php foreach ($barangayData as $b): ?>
      <tr>
        <td><?php echo htmlspecialchars($b['name']); ?></td>
        <td class="text-right"><?php echo number_format($b['total_reports']); ?></td>
        <td class="text-right"><?php echo number_format($b['active_rabies']); ?></td>
        <td class="text-right"><?php echo number_format($b['registered_pets']); ?></td>
        <td class="text-right"><?php echo number_format($b['vaccinations']); ?></td>
        <td class="text-center"><span class="risk-badge risk-<?php echo $b['risk']; ?>"><?php echo $b['risk']; ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 6. MONTHLY TRENDS ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>6. Monthly Trends (<?php echo $currentYear; ?>)</h2>
  <table>
    <thead>
      <tr>
        <th>Month</th>
        <th class="text-right">Reports</th>
        <th class="text-right">Rabies Cases</th>
        <th class="text-right">Pet Registrations</th>
        <th class="text-right">Appointments</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($m = 1; $m <= $currentMonth; $m++): ?>
      <tr>
        <td><?php echo $monthNames[$m]; ?></td>
        <td class="text-right"><?php echo number_format($monthlyReports[$m]); ?></td>
        <td class="text-right"><?php echo number_format($monthlyRabies[$m]); ?></td>
        <td class="text-right"><?php echo number_format($monthlyPets[$m]); ?></td>
        <td class="text-right"><?php echo number_format($monthlyAppts[$m]); ?></td>
      </tr>
      <?php endfor; ?>
    </tbody>
    <tfoot>
      <tr style="background:#eef2ff;border-top:2px solid #1e40af;">
        <td class="fw-bold">Total</td>
        <td class="text-right fw-bold"><?php echo number_format(array_sum($monthlyReports)); ?></td>
        <td class="text-right fw-bold"><?php echo number_format(array_sum($monthlyRabies)); ?></td>
        <td class="text-right fw-bold"><?php echo number_format(array_sum($monthlyPets)); ?></td>
        <td class="text-right fw-bold"><?php echo number_format(array_sum($monthlyAppts)); ?></td>
      </tr>
    </tfoot>
  </table>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 7. CHARTS ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>7. Analytics Charts</h2>
  <div class="chart-grid">
    <div class="chart-box">
      <h4>Reports by Category</h4>
      <canvas id="chartCategory" width="640" height="260"></canvas>
    </div>
    <div class="chart-box">
      <h4>Monthly Activity (<?php echo $currentYear; ?>)</h4>
      <canvas id="chartMonthly" width="640" height="260"></canvas>
    </div>
    <div class="chart-box">
      <h4>Rabies Monitoring (<?php echo $currentYear; ?>)</h4>
      <canvas id="chartRabies" width="640" height="260"></canvas>
    </div>
  </div>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 8. INSIGHTS ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <h2>8. Data Insights</h2>
  <ul class="insights-list">
    <li><strong>Most Reported Category:</strong> <?php echo $maxCategory; ?> (<?php echo number_format($maxCatCount); ?> reports)</li>
    <li><strong>Top Barangay (Reports):</strong> <?php echo htmlspecialchars($topBarangay); ?> &mdash; <?php echo number_format($topBarangayReports); ?> report<?php echo $topBarangayReports !== 1 ? 's' : ''; ?></li>
    <li><strong>Highest Active Rabies:</strong> <?php echo htmlspecialchars($topRabiesBarangay); ?> &mdash; <?php echo number_format($topRabiesCount); ?> active case<?php echo $topRabiesCount !== 1 ? 's' : ''; ?></li>
    <li><strong>Peak Activity Month:</strong> <?php echo $monthNames[$peakMonth]; ?> <?php echo $currentYear; ?> &mdash; <?php echo number_format($peakCount); ?> report<?php echo $peakCount !== 1 ? 's' : ''; ?></li>
    <li><strong>Rabies Proportion:</strong> Rabies cases constitute <strong><?php echo $rabiesPct; ?>%</strong> of all reports</li>
    <li><strong>Case Resolution Rate:</strong> <strong><?php echo $completionRate; ?>%</strong> of all reported cases have been resolved</li>
    <li><strong>Current Month vs Previous:</strong> <?php echo $changeDir === 'no change' ? 'No change in report volume' : ($changeDir === 'increase' ? 'Reports increased by ' . $changePctAbs . '% compared to last month' : 'Reports decreased by ' . $changePctAbs . '% compared to last month'); ?></li>
    <li><strong>Pet Registration Ratio:</strong> Approximately <strong><?php echo $totalResidents > 0 ? round($totalPets / $totalResidents * 100, 1) : 0; ?>%</strong> of residents have registered pets</li>
  </ul>

  <!-- ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â 9. FOOTER ÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚ÂÃƒÂ¢Ã¢â‚¬Â¢Ã‚Â -->
  <div class="report-footer">
    <div>Dasmari&ntilde;as City Veterinary Services Information System</div>
    <div>Report generated on <?php echo $now; ?></div>
    <div style="margin-top:2px;color:#94a3b8;font-weight:600;">Confidential &mdash; For Official Use Only</div>
  </div>

</div><!-- /report-wrap -->

<script>
(function(){
  var charts = [];
  var DPR = window.devicePixelRatio || 2;
  var COLORS = {
    stray:    '#f97316',
    rabies:   '#ef4444',
    injured:  '#eab308',
    dead:     '#6b7280',
    reports:  '#1e40af',
    pets:     '#059669',
    appts:    '#8b5cf6',
  };

  function makeChart(id, config) {
    var el = document.getElementById(id);
    if (!el) return null;
    config.options = config.options || {};
    config.options.responsive = true;
    config.options.maintainAspectRatio = true;
    config.options.devicePixelRatio = DPR;
    config.options.animation = { duration: 0 };
    var c = new Chart(el, config);
    charts.push(c);
    return c;
  }

  // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ 1. Reports by Category (Donut) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
  makeChart('chartCategory', {
    type: 'doughnut',
    data: {
      labels: ['Stray Animal', 'Rabies Suspect', 'Injured Animal', 'Dead Animal'],
      datasets: [{
        data: [
          <?php echo $reportsByTypeStatus['stray']['total'] ?? 0; ?>,
          <?php echo $reportsByTypeStatus['rabies']['total'] ?? 0; ?>,
          <?php echo $reportsByTypeStatus['injured']['total'] ?? 0; ?>,
          <?php echo $reportsByTypeStatus['dead']['total'] ?? 0; ?>
        ],
        backgroundColor: [COLORS.stray, COLORS.rabies, COLORS.injured, COLORS.dead],
        borderWidth: 1, borderColor: '#fff',
      }]
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 9 } } },
      }
    }
  });

  // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ 2. Monthly Activity (Line) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
  makeChart('chartMonthly', {
    type: 'line',
    data: {
      labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
      datasets: [
        { label: 'Reports', data: [<?php echo implode(',', $monthlyReports); ?>], borderColor: COLORS.reports, backgroundColor: COLORS.reports+'22', fill: true, tension: 0.3, pointRadius: 2 },
        { label: 'Rabies', data: [<?php echo implode(',', $monthlyRabies); ?>], borderColor: COLORS.rabies, backgroundColor: COLORS.rabies+'22', fill: true, tension: 0.3, pointRadius: 2 },
        { label: 'Pet Registrations', data: [<?php echo implode(',', $monthlyPets); ?>], borderColor: COLORS.pets, backgroundColor: COLORS.pets+'22', fill: true, tension: 0.3, pointRadius: 2 },
        { label: 'Appointments', data: [<?php echo implode(',', $monthlyAppts); ?>], borderColor: COLORS.appts, backgroundColor: COLORS.appts+'22', fill: true, tension: 0.3, pointRadius: 2 },
      ]
    },
    options: {
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 6, font: { size: 8 } } } },
      scales: { y: { beginAtZero: true, ticks: { font: { size: 8 } } }, x: { ticks: { font: { size: 8 } } } },
    }
  });

  // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ 3. Rabies Monitoring (Line) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
  makeChart('chartRabies', {
    type: 'line',
    data: {
      labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
      datasets: [{
        label: 'Rabies Cases',
        data: [<?php echo implode(',', $monthlyRabies); ?>],
        borderColor: COLORS.rabies, backgroundColor: COLORS.rabies+'22',
        fill: true, tension: 0.3, pointRadius: 3, pointBackgroundColor: COLORS.rabies,
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { font: { size: 8 } } }, x: { ticks: { font: { size: 8 } } } },
    }
  });

  // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Resize all charts to their current container ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
  function resizeCharts() {
    for (var i = 0; i < charts.length; i++) {
      try { charts[i].resize(); } catch(e) {}
    }
  }

  // Resize before print
  if (window.matchMedia) {
    window.matchMedia('print').addListener(function(mql) {
      if (mql.matches) resizeCharts();
    });
  }

  // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Download PDF via html2pdf.js ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
  window.downloadPDF = function() {
    resizeCharts();
    var btn = document.getElementById('downloadPdfBtn');
    var status = document.getElementById('downloadStatus');
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Generating...';
    status.textContent = 'Generating PDF, please wait...';

    var opt = {
      margin: [10, 10, 10, 10],
      filename: 'DCVS_Analytics_<?php echo date('Y-m-d'); ?>.pdf',
      image: { type: 'jpeg', quality: 0.97 },
      html2canvas: { scale: 2, useCORS: true, logging: false },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    setTimeout(function() {
      html2pdf().set(opt).from(document.getElementById('reportWrap')).save().then(function() {
        btn.innerHTML = orig;
        btn.disabled = false;
        status.textContent = 'PDF downloaded successfully.';
        setTimeout(function() { status.textContent = ''; }, 3000);
      }).catch(function(err) {
        btn.innerHTML = orig;
        btn.disabled = false;
        status.textContent = 'Download failed. Try Save as PDF (Print) instead.';
      });
    }, 300);
  };
})();
</script>
</body>
</html>