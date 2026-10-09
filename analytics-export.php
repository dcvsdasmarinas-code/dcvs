<?php
session_name('DCVS_STAFF_SESSION');
session_start();
require_once 'db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    http_response_code(403);
    exit('Unauthorized');
}

$payload = json_decode($_POST['payload'] ?? '', true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid analytics payload');
}

function pdfText($value) {
    $value = (string)$value;
    if (function_exists('iconv')) {
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
}

final class SimpleReportPdf {
    private array $objects = [];
    private array $pages = [];
    private string $content = '';
    private float $y = 800;
    private float $left = 42;
    private float $right = 553;
    private float $bottom = 46;
    private int $pageNumber = 0;

    public function __construct() { $this->newPage(); }

    public function newPage(): void {
        if ($this->content !== '') $this->pages[] = $this->content;
        $this->content = '';
        $this->y = 800;
        $this->pageNumber++;
    }

    private function ensure(float $height): void {
        if ($this->y - $height < $this->bottom) $this->newPage();
    }

    private function command(string $value): void { $this->content .= $value . "\n"; }

    public function text(string $value, float $size = 9, bool $bold = false, float $x = 42, ?float $y = null): void {
        if ($y !== null) $this->y = $y;
        $font = $bold ? '/F2' : '/F1';
        $this->command("0 g 0 G BT {$font} {$size} Tf 1 0 0 1 {$x} {$this->y} Tm (" . pdfText($value) . ") Tj ET");
    }

    public function paragraph(string $value, float $size = 9, bool $bold = false, float $gap = 13): void {
        $this->ensure($gap);
        $this->text($value, $size, $bold);
        $this->y -= $gap;
    }

    public function space(float $amount): void { $this->y -= $amount; }

    public function rule(): void {
        $this->command("0.78 G 0.5 w {$this->left} {$this->y} m {$this->right} {$this->y} l S");
        $this->y -= 12;
    }

    private function wrap(string $value, float $width, float $size): array {
        $limit = max(8, (int)floor($width / ($size * 0.52)));
        $words = preg_split('/\s+/', trim($value)) ?: [''];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (strlen($candidate) > $limit && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        $lines[] = $line;
        return $lines;
    }

    public function table(array $headers, array $rows, array $widths): void {
        $this->ensure(34);
        $this->tableRow($headers, $widths, true);
        foreach ($rows as $row) {
            $this->tableRow($row, $widths, false);
        }
        $this->y -= 12;
    }

    private function tableRow(array $cells, array $widths, bool $header): void {
        $size = $header ? 8 : 8;
        $lineHeight = 10;
        $wrapped = [];
        $height = 10;
        foreach ($widths as $i => $width) {
            $lines = $this->wrap((string)($cells[$i] ?? ''), $width - 10, $size);
            $wrapped[] = $lines;
            $height = max($height, count($lines) * $lineHeight + 8);
        }
        if ($this->y - $height < $this->bottom) {
            $this->newPage();
            if (!$header) {
                $this->tableRow($this->currentHeaders ?? [], $widths, true);
            }
        }
        $top = $this->y;
        $x = $this->left;
        if ($header) $this->currentHeaders = $cells;
        if ($header) {
            $this->command("0.93 g {$this->left} " . ($top - $height) . " m {$this->right} " . ($top - $height) . " l {$this->right} {$top} l {$this->left} {$top} l h f");
        }
        $this->command("0.78 G 0.45 w {$this->left} " . ($top - $height) . " m {$this->right} " . ($top - $height) . " l S");
        $x = $this->left;
        foreach ($widths as $i => $width) {
            $this->command("0.86 G 0.35 w {$x} {$top} m {$x} " . ($top - $height) . " l S");
            foreach ($wrapped[$i] as $lineIndex => $line) {
                $this->text($line, $size, $header, $x + 5, $top - 12 - ($lineIndex * $lineHeight));
            }
            $x += $width;
        }
        $this->command("0.86 G 0.35 w {$x} {$top} m {$x} " . ($top - $height) . " l S");
        $this->y = $top - $height;
    }

    public function output(): string {
        if ($this->content !== '') $this->pages[] = $this->content;
        $font1 = count($this->objects) + 1; $this->objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $font2 = count($this->objects) + 1; $this->objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $pageIds = [];
        foreach ($this->pages as $content) {
            $streamId = count($this->objects) + 1;
            $this->objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $pageId = count($this->objects) + 1;
            $this->objects[] = "<< /Type /Page /Parent PAGES_ID 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$font1} 0 R /F2 {$font2} 0 R >> >> /Contents {$streamId} 0 R >>";
            $pageIds[] = $pageId;
        }
        $pagesId = count($this->objects) + 1;
        $kids = implode(' ', array_map(fn($id) => $id . ' 0 R', $pageIds));
        $this->objects[] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . " >>";
        $catalogId = count($this->objects) + 1;
        $this->objects[] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($this->objects as $index => $object) {
            $object = str_replace('PAGES_ID', (string)$pagesId, $object);
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($this->objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($this->objects) + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private array $currentHeaders = [];
}

if (($payload['reportType'] ?? '') === 'adoption') {
    $year = (int)($payload['year'] ?? date('Y'));
    $adopted = (int)($payload['adopted'] ?? 0);
    $rescued = (int)($payload['rescued'] ?? 0);
    $summaryRows = [
        ['Animals Rescued (' . $year . ')', number_format($rescued)],
        ['Animals Adopted (' . $year . ')', number_format($adopted)],
        ['Adoption Rate', $rescued > 0 ? number_format($adopted / $rescued * 100, 1) . '%' : '0.0%'],
        ['Currently Available', number_format((int)($payload['available'] ?? 0))],
        ['Published Listings', number_format((int)($payload['published'] ?? 0))]
    ];
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $monthlyRows = [];
    foreach ($months as $index => $month) {
        $monthlyRows[] = [
            $month,
            number_format((int)($payload['monthlyRescued'][$index] ?? 0)),
            number_format((int)($payload['monthlyAdopted'][$index] ?? 0))
        ];
    }
    $sourceRows = [];
    foreach (($payload['rescueSources'] ?? []) as $source) {
        if (!is_array($source)) continue;
        $total = (int)($source['total'] ?? 0);
        $sourceRows[] = [
            (string)($source['label'] ?? ''),
            number_format($total),
            number_format((int)($source['adopted'] ?? 0)),
            number_format((int)($source['available'] ?? 0)),
            number_format((int)($source['under_care'] ?? 0)),
            $total > 0 ? number_format((int)($source['adopted'] ?? 0) / $total * 100, 1) . '%' : '0.0%'
        ];
    }
    $activityRows = [];
    foreach (($payload['activity'] ?? []) as $activity) {
        if (!is_array($activity)) continue;
        $activityRows[] = [
            (string)($activity['title'] ?? ''),
            (string)($activity['detail'] ?? ''),
            (string)($activity['action'] ?? ''),
            (string)($activity['eventAt'] ?? '')
        ];
    }

    $pdf = new SimpleReportPdf();
    $pdf->text('DASMARIÑAS CITY VETERINARY SERVICES', 15, true); $pdf->space(19);
    $pdf->text('ANIMAL WELFARE REPORTING SYSTEM', 10, false); $pdf->space(18);
    $pdf->text('ADOPTION INSIGHTS REPORT', 14, true); $pdf->space(18);
    $pdf->rule();
    $pdf->paragraph('Reporting Year: ' . $year, 9, true);
    $pdf->paragraph('Date Generated: ' . (string)($payload['generatedAt'] ?? date('Y-m-d H:i:s')), 9, false);
    $pdf->paragraph('Generated By: ' . ((string)($payload['generatedBy'] ?? '') ?: 'DCVS Staff'), 9, false, 18);
    $pdf->paragraph('ADOPTION SUMMARY', 11, true, 16);
    $pdf->table(['Metric', 'Value'], $summaryRows, [350, 161]);
    $pdf->paragraph('MONTHLY RESCUED VS. ADOPTED', 11, true, 16);
    $pdf->table(['Month', 'Rescued', 'Adopted'], $monthlyRows, [230, 140, 141]);
    $pdf->paragraph('RESCUE SOURCE BREAKDOWN', 11, true, 16);
    $pdf->table(['Rescue Source', 'Total', 'Adopted', 'Available', 'Under Care', 'Adoption Rate'], $sourceRows, [130, 65, 70, 70, 75, 101]);
    $pdf->paragraph('RECENT ADOPTION & RESCUE ACTIVITY', 11, true, 16);
    $pdf->table(['Activity', 'Details', 'Action', 'Date'], $activityRows, [140, 190, 90, 91]);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="adoption-insights-' . $year . '-' . date('Ymd-His') . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf->output();
    exit;
}

if (($payload['reportType'] ?? '') === 'appointments') {
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $typeLabels = is_array($payload['typeLabels'] ?? null) ? $payload['typeLabels'] : [];
    $typeCounts = is_array($payload['typeCounts'] ?? null) ? $payload['typeCounts'] : [];
    $statuses = is_array($payload['statuses'] ?? null) ? $payload['statuses'] : [];
    $segments = is_array($payload['segments'] ?? null) ? $payload['segments'] : [];
    $locationTypes = is_array($payload['locationsByType'] ?? null) ? $payload['locationsByType'] : [];
    $selectedLocations = is_array($locationTypes[$payload['locationType'] ?? 'all'] ?? null)
        ? $locationTypes[$payload['locationType'] ?? 'all']
        : [];
    $appointmentTotal = array_sum(array_map('intval', $typeCounts));
    $typeRows = [];
    foreach ($typeLabels as $index => $label) {
        $count = (int)($typeCounts[$index] ?? 0);
        $typeRows[] = [
            (string)$label,
            number_format($count),
            $appointmentTotal > 0 ? number_format($count / $appointmentTotal * 100, 1) . '%' : '0.0%'
        ];
    }
    $summaryRows = [
        ['Total Appointments (30d)', number_format((int)($summary['total_30d'] ?? 0))],
        ['Upcoming Appointments', number_format((int)($summary['upcoming'] ?? 0))],
        ['Completed Appointments (30d)', number_format((int)($summary['completed_30d'] ?? 0))],
        ['No-Show Rate (30d)', (int)($summary['total_30d'] ?? 0) > 0
            ? number_format((int)($summary['no_show_30d'] ?? 0) / max(1, (int)($summary['total_30d'] ?? 0)) * 100, 1) . '%'
            : '0.0%']
    ];
    $statusRows = [];
    foreach ($statuses as $status) {
        $statusRows[] = [(string)($status['label'] ?? ''), number_format((int)($status['count'] ?? 0))];
    }
    $locationRows = [];
    foreach ($selectedLocations as $location) {
        if (is_array($location) && count($location) >= 2) {
            $locationRows[] = [(string)$location[0], number_format((int)$location[1])];
        }
    }
    $segmentRows = [];
    foreach ($segments as $segment) {
        $segmentRows[] = [
            (string)($segment['type'] ?? ''),
            number_format((int)($segment['total'] ?? 0)),
            number_format((int)($segment['pending'] ?? 0)),
            number_format((int)($segment['scheduled'] ?? 0)),
            number_format((int)($segment['in_progress'] ?? 0)),
            number_format((int)($segment['completed'] ?? 0)),
            number_format((int)($segment['closed'] ?? 0))
        ];
    }

    $pdf = new SimpleReportPdf();
    $pdf->text('DASMARIÑAS CITY VETERINARY SERVICES', 15, true); $pdf->space(19);
    $pdf->text('ANIMAL WELFARE REPORTING SYSTEM', 10, false); $pdf->space(18);
    $pdf->text('APPOINTMENT OVERVIEW REPORT', 14, true); $pdf->space(18);
    $pdf->rule();
    $pdf->paragraph('Report Scope: All appointment records; summary metrics marked (30d) cover the last 30 days.', 9, true);
    $pdf->paragraph('Barangay Chart Filter: ' . (string)($payload['locationTypeLabel'] ?? 'All Types'), 9, false);
    $pdf->paragraph('Date Generated: ' . (string)($payload['generatedAt'] ?? date('Y-m-d H:i:s')), 9, false);
    $pdf->paragraph('Generated By: ' . ((string)($payload['generatedBy'] ?? '') ?: 'DCVS Staff'), 9, false, 18);
    $pdf->paragraph('APPOINTMENT SUMMARY', 11, true, 16);
    $pdf->table(['Metric', 'Value'], $summaryRows, [350, 161]);
    $pdf->paragraph('APPOINTMENT TYPE DISTRIBUTION', 11, true, 16);
    $pdf->table(['Appointment Type', 'Total', '% of Total'], $typeRows, [280, 115, 116]);
    $pdf->paragraph('APPOINTMENT STATUS OVERVIEW', 11, true, 16);
    $pdf->table(['Status', 'Appointments'], $statusRows, [350, 161]);
    $pdf->paragraph('TOP BARANGAYS BY APPOINTMENT VOLUME', 11, true, 16);
    $pdf->table(['Barangay', 'Appointments'], $locationRows, [350, 161]);
    $pdf->paragraph('APPOINTMENT SEGMENTS', 11, true, 16);
    $pdf->table(['Appointment Type', 'Total', 'Pending', 'Scheduled', 'In Progress', 'Completed', 'Closed'], $segmentRows, [145, 61, 61, 61, 61, 61, 61]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="appointment-overview-' . date('Ymd-His') . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf->output();
    exit;
}

$overview = $payload['overview'] ?? [];
$source = $payload['barangayDistribution'] ?? [];
$selectedId = (string)($payload['barangayId'] ?? '0');
$selectedDistribution = $source[0] ?? ['name' => 'All Barangays', 'data' => []];
foreach ($source as $item) {
    if ((string)($item['id'] ?? '') === $selectedId) { $selectedDistribution = $item; break; }
}
$labels = $payload['typeLabels'] ?? [];
$typeRows = [];
$typeTotal = array_sum(array_map('intval', $selectedDistribution['data'] ?? []));
foreach ($labels as $i => $label) {
    $count = (int)($selectedDistribution['data'][$i] ?? 0);
    $typeRows[] = [$label, number_format($count), $typeTotal > 0 ? number_format($count / $typeTotal * 100, 1) . '%' : '0.0%'];
}
$overviewRows = [];
foreach ($overview as $metric) {
    $overviewRows[] = [(string)($metric['label'] ?? ''), number_format((float)($metric['value'] ?? 0), 1) . (string)($metric['suffix'] ?? '')];
}
$activityRows = [];
foreach ($overview as $metric) {
    $change = (float)($metric['change'] ?? 0);
    $activityRows[] = [(string)($metric['label'] ?? ''), number_format((float)($metric['value'] ?? 0), 1) . (string)($metric['suffix'] ?? ''), ($change >= 0 ? '+' : '') . number_format($change, 1) . '%'];
}
$barangayRows = [];
foreach (($payload['barangayBreakdown'] ?? []) as $row) {
    $barangayRows[] = [$row['name'] ?? '-', number_format((int)($row['filed'] ?? 0)), number_format((int)($row['resolved'] ?? 0)), number_format((float)($row['rate'] ?? 0), 1) . '%', number_format((int)($row['actions'] ?? 0)), number_format((float)($row['share'] ?? 0), 1) . '%'];
}

$pdf = new SimpleReportPdf();
$pdf->text('DASMARIÑAS CITY VETERINARY SERVICES', 15, true); $pdf->space(19);
$pdf->text('ANIMAL WELFARE REPORTING SYSTEM', 10, false); $pdf->space(18);
$pdf->text('ANALYTICS SUMMARY REPORT', 14, true); $pdf->space(18);
$pdf->rule();
$pdf->paragraph('Reporting Period: ' . ($payload['reportingPeriod'] ?? 'Last 30 Days'), 9, true);
$pdf->paragraph('Barangay: ' . ($payload['barangayName'] ?? 'All Barangays'), 9, false);
$pdf->paragraph('Date Generated: ' . ($payload['generatedAt'] ?? date('Y-m-d H:i:s')), 9, false);
$pdf->paragraph('Generated By: ' . (($payload['generatedBy'] ?? '') ?: 'DCVS Staff'), 9, false, 18);
$pdf->paragraph('REPORT OVERVIEW', 11, true, 16);
$pdf->table(['Metric', 'Value'], $overviewRows, [350, 161]);
$pdf->paragraph('REPORT ACTIVITY SUMMARY', 11, true, 16);
$pdf->table(['Metric', 'Value', 'Change'], $activityRows, [280, 120, 111]);
$pdf->paragraph('REPORT TYPE SUMMARY', 11, true, 16);
$pdf->table(['Report Type', 'Total Reports', '% of Total'], $typeRows, [280, 115, 116]);
$pdf->paragraph('BARANGAY BREAKDOWN', 11, true, 16);
$pdf->table(['Barangay', 'Reports Filed', 'Cases Resolved', 'Resolution Rate', 'Staff Actions', '% of Total'], $barangayRows, [145, 70, 80, 85, 75, 56]);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="analytics-summary-' . date('Ymd-His') . '.pdf"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf->output();
