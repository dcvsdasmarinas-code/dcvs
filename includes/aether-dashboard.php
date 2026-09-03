<?php
/* ─────────────────────────────────────────────────────────────
   DCVS Analytics Dashboard
   Faithful PHP reproduction of the Next.js template dashboard
   (Engine / Intel / Budget / Architect / Settings)
   Mock data matches the template's dashboard-context.tsx exactly.
   ───────────────────────────────────────────────────────────── */
if (!defined('DCVS_AETHER_INCLUDED')) {
    define('DCVS_AETHER_INCLUDED', true);

    $aeth_campaigns = [
        ['id' => 'prog-001', 'name' => 'Vaccination Drive — Zone I',    'status' => 'live',      'spend' => 12450, 'impressions' => 892000,  'channel' => 'Barangay Paliparan I', 'last' => '2m ago',  'start' => '2026-01-01', 'end' => '2026-02-15', 'progress' => 72, 'budget' => 50000, 'target' => 200, 'dogs' => 145, 'cats' => 55],
        ['id' => 'prog-002', 'name' => 'Kapon Program — Paliparan',      'status' => 'live',      'spend' => 8920,  'impressions' => 456000,  'channel' => 'Barangay San Jose',   'last' => '5m ago',  'start' => '2026-01-10', 'end' => '2026-02-28', 'progress' => 45, 'budget' => 75000, 'target' => 150, 'dogs' => 95,  'cats' => 55],
        ['id' => 'prog-003', 'name' => 'Rabies Awareness Drive',         'status' => 'live',      'spend' => 3240,  'impressions' => 234000,  'channel' => 'Barangay Zone II',    'last' => '8m ago',  'start' => '2026-01-05', 'end' => '2026-03-31', 'progress' => 28, 'budget' => 25000, 'target' => 250, 'dogs' => 200, 'cats' => 50],
        ['id' => 'prog-004', 'name' => 'Adoption Drive — Citywide',      'status' => 'scheduled', 'spend' => 0,     'impressions' => 0,       'channel' => 'Multiple Barangays',  'last' => '1h ago',  'start' => '2026-02-15', 'end' => '2026-03-15', 'progress' => 0,  'budget' => 40000, 'target' => 100, 'dogs' => 60,  'cats' => 40],
        ['id' => 'prog-005', 'name' => 'Deworming Campaign — West',      'status' => 'paused',    'spend' => 5670,  'impressions' => 312000,  'channel' => 'Barangay Salitran III','last' => '3h ago',  'start' => '2026-01-15', 'end' => '2026-02-28', 'progress' => 35, 'budget' => 60000, 'target' => 180, 'dogs' => 130, 'cats' => 50],
        ['id' => 'prog-006', 'name' => 'Free Check-up Day',              'status' => 'ended',     'spend' => 45000, 'impressions' => 2100000, 'channel' => 'Barangay San Lorenzo Ruiz I', 'last' => '2d ago', 'start' => '2025-11-01', 'end' => '2025-12-31', 'progress' => 100, 'budget' => 45000, 'target' => 120, 'dogs' => 80,  'cats' => 40],
        ['id' => 'prog-007', 'name' => 'Microchipping Drive',            'status' => 'live',      'spend' => 2340,  'impressions' => 156000,  'channel' => 'Barangay Sampaloc I', 'last' => '15m ago', 'start' => '2026-01-01', 'end' => '2026-04-30', 'progress' => 22, 'budget' => 15000, 'target' => 160, 'dogs' => 110, 'cats' => 50],
    ];
    $aeth_metrics   = ['totalImpressions' => 2847593, 'activeROAS' => 4.2, 'conversionRate' => 3.8, 'totalClicks' => 127432];
    $aeth_totalBudget = 365000;
    $aeth_dailyBudget = 35000;
    $aeth_totalSpent  = 219100;
    $aeth_channels    = [
        ['Meta Ads', 120000, 78500],
        ['Google Ads', 95000, 62300],
        ['TikTok Ads', 45000, 12800],
        ['YouTube', 60000, 38200],
        ['Email', 15000, 8900],
        ['Display', 30000, 18400],
    ];
    $aeth_txns = [
        ['adopt-001', 'Kalabaw (Dog) Adopted — Maria Santos', 'Today', '-1', ''],
        ['adopt-002', 'Barangay-Reported Intake — Barangay Zone II', 'Today', '+3 animals', 'pos'],
        ['adopt-003', 'Kulit (Cat) Adopted — John Reyes', 'Yesterday', '-1', ''],
        ['adopt-004', 'Resident Surrender — 2 pets', 'Yesterday', '+2 animals', 'pos'],
        ['adopt-005', 'Brownie (Dog) Adopted — Ana Cruz', 'Jan 20', '-1', ''],
        ['adopt-006', 'Stray Roundup — City streets', 'Jan 18', '+4 animals', 'pos'],
    ];
    $aeth_channelBreakdown = [
        ['Meta Ads', 125000, 487500, 3.9, 4250],
        ['Google Ads', 98000, 441000, 4.5, 3680],
        ['TikTok Ads', 45000, 162000, 3.6, 1850],
        ['YouTube', 62000, 192200, 3.1, 1420],
        ['Email', 8500, 59500, 7.0, 2100],
    ];
    $aeth_attribution = [['Meta Ads', 85], ['Google Ads', 72], ['TikTok', 58], ['YouTube', 45], ['Email', 68], ['Organic', 52]];
    $aeth_ageData   = [['18-24', 15, '#CCFF00'], ['25-34', 35, '#00ffcc'], ['35-44', 28, '#00ccff'], ['45-54', 15, '#ff00cc'], ['55+', 7, '#ffcc00']];
    $aeth_gender    = [['Female', 58], ['Male', 38], ['Other', 4]];
    $aeth_locations = [['United States', 45000], ['United Kingdom', 12000], ['Canada', 8500], ['Germany', 6200], ['Australia', 5800], ['France', 4300]];
    $aeth_segments  = [
        ['High-Value Customers', 12500, '$450', 'High'],
        ['Active Browsers', 45000, '$120', 'Medium'],
        ['Cart Abandoners', 8200, '$85', 'Low'],
        ['New Visitors', 67000, '$45', 'Medium'],
        ['Loyal Repeat Buyers', 5600, '$680', 'High'],
    ];

    // Engine performance chart (30 days, deterministic mock matching template shape)
    $aeth_perf = [];
    for ($i = 0; $i < 30; $i++) {
        $aeth_perf[] = [
            'd'   => date('M d', strtotime('-' . (29 - $i) . ' days')),
            'roas' => round(3.2 + (($i % 7) / 7) * 1.5 + $i * 0.02, 2),
            'spend' => round(8000 + (($i * 37) % 4000) + $i * 100),
        ];
    }

    // Gantt timeline range + weeks (Jan 1 - Apr 30 2026)
    $aeth_gStart = '2026-01-01';
    $aeth_gEnd   = '2026-04-30';
    $aeth_gTotalDays = (int)((strtotime($aeth_gEnd) - strtotime($aeth_gStart)) / 86400);
    $aeth_weeks = [];
    $wk = strtotime($aeth_gStart);
    while ($wk <= strtotime($aeth_gEnd)) {
        $aeth_weeks[] = date('M d', $wk);
        $wk = strtotime('+7 days', $wk);
    }
    function aeth_pos($date, $gStart, $totalDays) {
        $d = (int)((strtotime($date) - strtotime($gStart)) / 86400);
        $d = max(0, min($totalDays, $d));
        return round($d / $totalDays * 100, 2);
    }
    function aeth_width($s, $e, $gStart, $gEnd, $totalDays) {
        $s2 = max(strtotime($gStart), strtotime($s));
        $e2 = min(strtotime($gEnd), strtotime($e));
        if ($s2 >= $e2) return 0;
        return round((($e2 - $s2) / 86400) / $totalDays * 100, 2);
    }
    // ── Pulse KPI cards (real DCVS data) ─────────────────────────
    function aeth_monthLabels() {
        $l = [];
        for ($i = 11; $i >= 0; $i--) $l[] = date('Y-m', strtotime("-$i months"));
        return $l;
    }
    function aeth_fillSeries($map) {
        $out = [];
        foreach (aeth_monthLabels() as $m) $out[] = isset($map[$m]) ? round((float)$map[$m], 1) : 0;
        return $out;
    }
    function aeth_pctChg($s) {
        $n = count($s); if ($n < 2) return 0;
        $cur = (float)$s[$n-1]; $prev = (float)$s[$n-2];
        if ($prev <= 0) return 0;
        return round(($cur - $prev) / $prev * 100, 1);
    }

    // 1) Total Cases Handled (last 30 days) + trend (reports per month)
    $aeth_kpiCases30 = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE created_at >= NOW() - INTERVAL 30 DAY AND archived_at IS NULL")->fetch_row()[0] ?? 0);
    $aeth_kpiCasesPrev = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE created_at >= NOW() - INTERVAL 60 DAY AND created_at < NOW() - INTERVAL 30 DAY AND archived_at IS NULL")->fetch_row()[0] ?? 0);
    $aeth_kpiCasesChg = $aeth_kpiCasesPrev > 0 ? round(($aeth_kpiCases30 - $aeth_kpiCasesPrev) / $aeth_kpiCasesPrev * 100, 1) : 0;
    $aeth_kpiCasesTrend = [];
    $__r = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) c FROM reports WHERE archived_at IS NULL AND created_at IS NOT NULL GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = $__row['c']; $aeth_kpiCasesTrend = aeth_fillSeries($__map); }

    // 2) Avg. Resolution Time (days, resolved reports) + trend (monthly avg days)
    $aeth_kpiResAvg = 0;
    $__r = $conn->query("SELECT AVG(DATEDIFF(updated_at, created_at)) a FROM reports WHERE status='resolved' AND archived_at IS NULL");
    if ($__r && $__row = $__r->fetch_assoc()) $aeth_kpiResAvg = round((float)$__row['a'], 1);
    $aeth_kpiResTrend = [];
    $__r = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') m, AVG(DATEDIFF(updated_at, created_at)) a FROM reports WHERE status='resolved' AND archived_at IS NULL AND created_at IS NOT NULL GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = round((float)$__row['a'], 1); $aeth_kpiResTrend = aeth_fillSeries($__map); }

    // 3) Report Verification Rate (% verified/legit of total) + trend
    $aeth_kpiVerTot = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL")->fetch_row()[0] ?? 0);
    $aeth_kpiVerDone = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE status IN ('validated','confirmed','scheduled','resolved') AND archived_at IS NULL")->fetch_row()[0] ?? 0);
    $aeth_kpiVerRate = $aeth_kpiVerTot > 0 ? round($aeth_kpiVerDone / $aeth_kpiVerTot * 100, 1) : 0;
    $aeth_kpiVerTrend = [];
    $__r = $conn->query("SELECT m, SUM(t) t, SUM(v) v FROM (SELECT DATE_FORMAT(created_at,'%Y-%m') m, 1 t, IF(status IN ('validated','confirmed','scheduled','resolved'),1,0) v FROM reports WHERE archived_at IS NULL AND created_at IS NOT NULL) x GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = (int)$__row['t'] > 0 ? round((int)$__row['v'] / (int)$__row['t'] * 100, 1) : 0; $aeth_kpiVerTrend = aeth_fillSeries($__map); }

    // 4) Repeat Reporter Rate (% reporters with >1 report) + trend
    $aeth_kpiRepDistinct = (int)($conn->query("SELECT COUNT(DISTINCT reporter_id) FROM reports WHERE archived_at IS NULL AND reporter_id IS NOT NULL")->fetch_row()[0] ?? 0);
    $aeth_kpiRepRepeat = (int)($conn->query("SELECT COUNT(*) FROM (SELECT reporter_id FROM reports WHERE archived_at IS NULL AND reporter_id IS NOT NULL GROUP BY reporter_id HAVING COUNT(*) > 1) t")->fetch_row()[0] ?? 0);
    $aeth_kpiRepRate = $aeth_kpiRepDistinct > 0 ? round($aeth_kpiRepRepeat / $aeth_kpiRepDistinct * 100, 1) : 0;
    $aeth_kpiRepTrend = [];
    $__r = $conn->query("SELECT m, COUNT(*) t, SUM(is_repeat) v FROM (SELECT DATE_FORMAT(created_at,'%Y-%m') m, IF(reporter_id IN (SELECT reporter_id FROM reports WHERE archived_at IS NULL GROUP BY reporter_id HAVING COUNT(*) > 1), 1, 0) is_repeat FROM reports WHERE archived_at IS NULL AND created_at IS NOT NULL) x GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = (int)$__row['t'] > 0 ? round((int)$__row['v'] / (int)$__row['t'] * 100, 1) : 0; $aeth_kpiRepTrend = aeth_fillSeries($__map); }

    $aeth_glanceCards = [
        ['Total Cases Handled (30d)', 1240, '', 18.3, [520, 610, 580, 690, 740, 820, 780, 910, 960, 890, 1050, 1240]],
        ['Avg. Resolution Time', 2.4, 'd', -12.5, [6.8, 6.2, 5.7, 5.1, 4.6, 4.2, 3.8, 3.4, 3.1, 2.9, 2.6, 2.4]],
        ['Report Verification Rate', 92.5, '%', 4.1, [84, 85, 85, 86, 87, 88, 88, 89, 90, 91, 91, 92.5]],
        ['Repeat Reporter Rate', 34.8, '%', 6.2, [28, 28, 29, 30, 30, 31, 31, 32, 32, 33, 34, 34.8]],
    ];
    $aeth_glanceTrends = [
        [520, 610, 580, 690, 740, 820, 780, 910, 960, 890, 1050, 1240],
        [6.8, 6.2, 5.7, 5.1, 4.6, 4.2, 3.8, 3.4, 3.1, 2.9, 2.6, 2.4],
        [84, 85, 85, 86, 87, 88, 88, 89, 90, 91, 91, 92.5],
        [28, 28, 29, 30, 30, 31, 31, 32, 32, 33, 34, 34.8],
    ];

    // ── Recent Veterinary Activities (reports + appointments) ─────
    function aeth_timeAgo($ts) {
        if (!$ts) return 'just now';
        $diff = time() - strtotime($ts);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
        return floor($diff / 86400) . ' days ago';
    }
    $aeth_reportTypeLabel = ['stray' => 'Stray Animal Report', 'rabies' => 'Rabies Case Report', 'injured' => 'Injured Animal Report', 'dead' => 'Dead Animal Report'];
    $aeth_apptTypeLabel   = ['adoption' => 'Adoption Appointment', 'kapon' => 'Kapon Appointment', 'vaccination' => 'Vaccination Appointment', 'checkup' => 'Checkup Appointment'];
    $aeth_statusLabel = [
        'pending' => 'PENDING', 'validated' => 'UNDER REVIEW', 'confirmed' => 'CONFIRMED',
        'scheduled' => 'IN PROGRESS', 'resolved' => 'RESOLVED', 'rejected' => 'CANCELLED',
        'approved' => 'CONFIRMED', 'completed' => 'RESOLVED', 'cancelled' => 'CANCELLED',
    ];
    $aeth_statusClass = [
        'PENDING' => 'pending', 'UNDER REVIEW' => 'review', 'CONFIRMED' => 'confirmed',
        'IN PROGRESS' => 'progress', 'RESOLVED' => 'resolved', 'CANCELLED' => 'cancelled',
    ];

    $aeth_activities = [];
    $__r = $conn->query("SELECT r.report_type, r.status, r.created_at, b.barangay_name, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON r.reporter_id = u.user_id LEFT JOIN barangays b ON r.barangay_id = b.barangay_id WHERE r.archived_at IS NULL ORDER BY r.created_at DESC LIMIT 6");
    if ($__r) {
        while ($__row = $__r->fetch_assoc()) {
            $type = $__row['report_type'];
            $rep  = trim((string)$__row['reporter_name']);
            $subj = $rep !== '' ? $rep : 'Report';
            $st   = $aeth_statusLabel[$__row['status']] ?? strtoupper($__row['status']);
            $aeth_activities[] = [
                'name'  => ($aeth_reportTypeLabel[$type] ?? 'Report') . ' — ' . $subj,
                'meta'  => (trim((string)$__row['barangay_name']) !== '' ? 'Barangay ' . $__row['barangay_name'] : 'City-wide') . ' · ' . aeth_timeAgo($__row['created_at']),
                'status'=> $st,
                'cls'   => $aeth_statusClass[$st] ?? 'pending',
                'sort'  => strtotime($__row['created_at']),
            ];
        }
    }
    $__r = $conn->query("SELECT a.appointment_type, a.status, a.created_at, b.barangay_name, p.pet_name, u.full_name AS owner_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN barangays b ON u.barangay_id = b.barangay_id WHERE a.archived_at IS NULL ORDER BY a.created_at DESC LIMIT 6");
    if ($__r) {
        while ($__row = $__r->fetch_assoc()) {
            $type = $__row['appointment_type'];
            $pet  = trim((string)$__row['pet_name']);
            $owner = trim((string)$__row['owner_name']);
            $who  = $pet !== '' ? $pet : ($owner !== '' ? $owner : 'Appointment');
            $subj = $pet !== '' && $owner !== '' ? $pet . ' (' . $owner . ')' : $who;
            $st   = $aeth_statusLabel[$__row['status']] ?? strtoupper($__row['status']);
            $aeth_activities[] = [
                'name'  => ($aeth_apptTypeLabel[$type] ?? 'Appointment') . ' — ' . $subj,
                'meta'  => (trim((string)$__row['barangay_name']) !== '' ? 'Barangay ' . $__row['barangay_name'] : 'City-wide') . ' · ' . aeth_timeAgo($__row['created_at']),
                'status'=> $st,
                'cls'   => $aeth_statusClass[$st] ?? 'pending',
                'sort'  => strtotime($__row['created_at']),
            ];
        }
    }
    usort($aeth_activities, function ($a, $b) { return $b['sort'] - $a['sort']; });
    $aeth_activities = array_slice($aeth_activities, 0, 8);

    // ── Today's Service Requests ────────────────────────────────────
    $aeth_todayRequests = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE DATE(created_at) = CURDATE() AND archived_at IS NULL")->fetch_row()[0] ?? 0);
    $aeth_todayCapacity = 60;
    $aeth_todayPct = $aeth_todayCapacity > 0 ? round($aeth_todayRequests / $aeth_todayCapacity * 100, 1) : 0;

    // ── Today's Highlights ──────────────────────────────────────────
    // Most reported area (barangay with the most non-archived reports)
    $aeth_hlArea = '—';
    $aeth_hlAreaCount = 0;
    $__r = $conn->query("SELECT b.barangay_name, COUNT(*) c FROM reports r LEFT JOIN barangays b ON r.barangay_id = b.barangay_id WHERE r.archived_at IS NULL GROUP BY r.barangay_id ORDER BY c DESC LIMIT 1");
    if ($__r && $__row = $__r->fetch_assoc()) {
        $aeth_hlArea = trim((string)$__row['barangay_name']) !== '' ? $__row['barangay_name'] : 'City-wide';
        $aeth_hlAreaCount = (int)$__row['c'];
    }
    // Active rabies cases
    $aeth_hlRabies = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE report_type='rabies' AND status IN ('validated','confirmed','scheduled') AND archived_at IS NULL")->fetch_row()[0] ?? 0);
    // New reports today
    $aeth_hlNewToday = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE DATE(created_at) = CURDATE() AND archived_at IS NULL")->fetch_row()[0] ?? 0);

    // ── Engine: Cases Filed vs. Resolution Time (placeholders for visuals) ─
    $aeth_engine30 = [];
    $__casePattern = [12, 15, 9, 18, 22, 14, 20, 16, 24, 19, 27, 15, 21, 26, 18, 23, 30, 22, 25, 19, 28, 24, 31, 26, 33, 27, 35, 29, 38, 34];
    $__resPattern = [8.5, 8.0, 7.6, 7.2, 6.9, 6.5, 6.1, 5.8, 5.4, 5.0, 4.8, 4.5, 4.2, 4.0, 3.8, 3.6, 3.5, 3.3, 3.1, 3.0, 2.9, 2.8, 2.7, 2.6, 2.5, 2.4, 2.4, 2.3, 2.3, 2.4];
    for ($i = 0; $i < 30; $i++) {
        $aeth_engine30[] = [
            'd' => date('M d', strtotime(($i - 29) . ' days')),
            'cases' => $__casePattern[$i],
            'resTime' => $__resPattern[$i],
        ];
    }

    // ── Engine: Report Type Distribution (per barangay, placeholders for visuals) ──
    $aeth_radarLabels = ['Stray Animal', 'Animal Abuse', 'Bite Incident', 'Rabies Case', 'Adoption Request', 'Lost/Found Pet'];
    $__brgyList = [];
    $__r = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
    if ($__r) { while ($__row = $__r->fetch_assoc()) $__brgyList[] = ['id' => (int)$__row['barangay_id'], 'name' => $__row['barangay_name']]; }

    $aeth_radarBrgy = [];
    $aeth_radarBrgy[] = ['id' => 0, 'name' => 'All Barangays'];
    $__seed = 7;
    foreach ($__brgyList as $__b) {
        $__seed = ($__seed * 31 + $__b['id']) % 997;
        $aeth_radarBrgy[] = [
            'id' => $__b['id'],
            'name' => $__b['name'],
            'data' => [
                ($__seed % 9) + 3,          // Stray Animal
                ($__seed * 3 % 6) + 1,      // Animal Abuse
                ($__seed * 5 % 8) + 2,      // Bite Incident
                ($__seed * 7 % 5) + 1,      // Rabies Case
                ($__seed * 11 % 6) + 1,     // Adoption Request
                ($__seed * 13 % 4),          // Lost/Found Pet
            ],
        ];
    }
    // Aggregate "All Barangays"
    $aeth_radarData = [0, 0, 0, 0, 0, 0];
    for ($i = 1; $i < count($aeth_radarBrgy); $i++) {
        for ($j = 0; $j < 6; $j++) $aeth_radarData[$j] += $aeth_radarBrgy[$i]['data'][$j];
    }
    $aeth_radarBrgy[0]['data'] = $aeth_radarData;

    // ── Engine: Per Year — real data for current year, placeholders for prior years ──
    $aeth_realYear = (int)date('Y');
    $aeth_realMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $__mCases = []; $__mRes = [];
    $__r = $conn->query("SELECT MONTH(created_at) m, COUNT(*) c FROM reports WHERE archived_at IS NULL AND YEAR(created_at) = YEAR(CURDATE()) GROUP BY MONTH(created_at)");
    if ($__r) { while ($__row = $__r->fetch_assoc()) $__mCases[(int)$__row['m']] = (int)$__row['c']; }
    $__r = $conn->query("SELECT MONTH(created_at) m, AVG(DATEDIFF(updated_at, created_at)) a FROM reports WHERE status='resolved' AND archived_at IS NULL AND YEAR(created_at) = YEAR(CURDATE()) GROUP BY MONTH(created_at)");
    if ($__r) { while ($__row = $__r->fetch_assoc()) $__mRes[(int)$__row['m']] = round((float)$__row['a'], 1); }
    $aeth_realData = [];
    for ($i = 1; $i <= 12; $i++) {
        $aeth_realData[] = [
            'd' => $aeth_realMonths[$i - 1],
            'cases' => $__mCases[$i] ?? 0,
            'resTime' => $__mRes[$i] ?? 0,
        ];
    }

    // ── Engine: Barangay Breakdown (real data) ─────────────────────
    $aeth_brgyTotal = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE archived_at IS NULL")->fetch_row()[0] ?? 0);
    $aeth_brgyBreakdown = [];
    $__r = $conn->query("SELECT b.barangay_name, COUNT(r.report_id) filed, SUM(CASE WHEN r.status='resolved' THEN 1 ELSE 0 END) resolved, SUM(CASE WHEN r.validated_by IS NOT NULL OR r.confirmed_by IS NOT NULL THEN 1 ELSE 0 END) actions FROM barangays b LEFT JOIN reports r ON r.barangay_id = b.barangay_id AND r.archived_at IS NULL GROUP BY b.barangay_id, b.barangay_name HAVING filed > 0 ORDER BY filed DESC LIMIT 8");
    if ($__r) {
        while ($__row = $__r->fetch_assoc()) {
            $filed = (int)$__row['filed'];
            $resolved = (int)$__row['resolved'];
            $aeth_brgyBreakdown[] = [
                'name' => $__row['barangay_name'],
                'filed' => $filed,
                'resolved' => $resolved,
                'rate' => $filed > 0 ? round($resolved / $filed * 100, 1) : 0,
                'actions' => (int)$__row['actions'],
                'share' => $aeth_brgyTotal > 0 ? round($filed / $aeth_brgyTotal * 100) : 0,
            ];
        }
    }

    // ── Intel: Appointment Type Distribution (placeholders for visuals) ──
    $aeth_apptTypeLabels = ['Kapon', 'Vaccination', 'Adoption', 'Check-up', 'Deworming'];
    $aeth_apptTypeColors = ['#1A1A1A', '#D4D4D4', '#0E8131', '#F59E0B', '#FACC15'];
    $aeth_apptTypeCounts = [42, 28, 19, 15, 8];

    // ── Intel: Appointment Source Split (placeholders for visuals) ──
    $aeth_apptResidentPct = 72.4;
    $aeth_apptNonResidentPct = 27.6;

    // ── Intel: Top Barangays by Appointment Volume (placeholders for visuals) ──
    $aeth_apptLoc = [
        ['Barangay Paliparan I', 86],
        ['Barangay San Jose', 64],
        ['Barangay Zone II', 51],
        ['Barangay Salitran III', 43],
        ['Barangay San Lorenzo Ruiz I', 38],
        ['Barangay Sampaloc I', 29],
        ['Barangay Burol III', 22],
        ['Barangay Fatima III', 15],
    ];
    $aeth_apptLocByType = [
        'all' => $aeth_apptLoc,
        'kapon' => [
            ['Barangay Paliparan I', 42], ['Barangay San Jose', 31], ['Barangay Zone II', 24], ['Barangay Salitran III', 19],
            ['Barangay San Lorenzo Ruiz I', 14], ['Barangay Sampaloc I', 11], ['Barangay Burol III', 8], ['Barangay Fatima III', 5],
        ],
        'vaccination' => [
            ['Barangay Paliparan I', 28], ['Barangay San Jose', 22], ['Barangay Zone II', 18], ['Barangay Salitran III', 15],
            ['Barangay San Lorenzo Ruiz I', 12], ['Barangay Sampaloc I', 9], ['Barangay Burol III', 7], ['Barangay Fatima III', 4],
        ],
        'adoption' => [
            ['Barangay Paliparan I', 16], ['Barangay San Jose', 12], ['Barangay Zone II', 10], ['Barangay Salitran III', 8],
            ['Barangay San Lorenzo Ruiz I', 6], ['Barangay Sampaloc I', 5], ['Barangay Burol III', 3], ['Barangay Fatima III', 2],
        ],
        'checkup' => [
            ['Barangay Paliparan I', 12], ['Barangay San Jose', 10], ['Barangay Zone II', 8], ['Barangay Salitran III', 7],
            ['Barangay San Lorenzo Ruiz I', 5], ['Barangay Sampaloc I', 4], ['Barangay Burol III', 3], ['Barangay Fatima III', 2],
        ],
        'deworming' => [
            ['Barangay Paliparan I', 8], ['Barangay San Jose', 6], ['Barangay Zone II', 5], ['Barangay Salitran III', 4],
            ['Barangay San Lorenzo Ruiz I', 3], ['Barangay Sampaloc I', 2], ['Barangay Burol III', 2], ['Barangay Fatima III', 1],
        ],
    ];

    // ── Intel: Appointment Segments table (placeholders for visuals) ──
    $aeth_apptSegments = [
        ['Kapon Appointment', 42, '1.5h', 'High'],
        ['Vaccination Appointment', 28, '45m', 'High'],
        ['Adoption Appointment', 19, '2.0h', 'Medium'],
        ['Check-up Appointment', 15, '1.2h', 'Medium'],
        ['Deworming Appointment', 8, '30m', 'Low'],
    ];

    // ── Budget: Rescue Source Breakdown (placeholders for visuals) ──
    $aeth_rescueSources = [
        ['Barangay-Reported', 120, 79],
        ['Resident Surrender', 95, 62],
        ['Stray Roundup', 45, 13],
        ['Shelter Transfer', 60, 38],
        ['Confiscated/Abuse Case', 15, 9],
        ['Found/Lost Pet Unclaimed', 30, 18],
    ];
}
?>
<div class="aether-dash" id="aetherDash">
<style>
.aether-dash {
  --ab-bg:#050505; --ab-fg:#ffffff; --ab-card:#0a0a0a; --ab-muted:#1a1a1a;
  --ab-muted-fg:#737373; --ab-lime:#CCFF00; --ab-border:rgba(255,255,255,0.06);
  --ab-surface:rgba(255,255,255,0.02); --ab-surface-hover:rgba(255,255,255,0.05);
  --ab-destructive:#ff4444; --ab-blue:#60a5fa; --ab-yellow:#eab308;
  --ab-track:rgba(255,255,255,0.3);
  background:var(--ab-bg); color:var(--ab-fg);
  letter-spacing:-0.03em; font-family:'Inter','Segoe UI',sans-serif;
  min-height:400px;
}
.aether-dash.ab-light {
  --ab-bg:#ffffff; --ab-fg:#050505; --ab-card:#ffffff; --ab-muted:#f0f0f0;
  --ab-muted-fg:#737373; --ab-lime:#99cc00; --ab-border:rgba(0,0,0,0.08);
  --ab-surface:rgba(0,0,0,0.02); --ab-surface-hover:rgba(0,0,0,0.05);
  --ab-destructive:#e53e3e; --ab-blue:#2563eb; --ab-yellow:#d97706;
  --ab-track:rgba(0,0,0,0.12);
}
.aether-dash.ab-light .ab-logo span,
.aether-dash.ab-light .ab-btn,
.aether-dash.ab-light .ab-btn-lime,
.aether-dash.ab-light .ab-preset.active,
.aether-dash.ab-light .ab-page-btn.active { color:#ffffff; }
.aether-dash.ab-light .ab-dd-menu { box-shadow:0 12px 40px rgba(0,0,0,0.12); }
/* Attach the dashboard to the page: white full-bleed, no gray gutter / no centering */
.aether-analytics { background:#ffffff; margin:-1.75rem -2rem; width:calc(100% + 4rem); max-width:none; min-height:calc(100vh - var(--topbar-h)); }
.aether-analytics .aether-dash { background:transparent; }
.aether-dash *, .aether-dash *::before, .aether-dash *::after { box-sizing:border-box; border-radius:0; }
.aether-dash .ab-mono { font-family:'Inter','Segoe UI',sans-serif; font-variant-numeric:tabular-nums; }
.aether-dash .ab-kicker { font-size:12px; font-family:'Inter','Segoe UI',sans-serif; font-weight:500; color:var(--ab-muted-fg); text-transform:uppercase; letter-spacing:0.12em; }
.aether-dash h1, .aether-dash h2, .aether-dash h3, .aether-dash p { margin:0; }
.aether-dash .ab-card { background:var(--ab-card); border:1px solid var(--ab-border); }

/* Page switcher */
.ab-nav { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; padding:1rem 1.5rem; border-bottom:1px solid var(--ab-border); }
.ab-brand { display:flex; align-items:center; gap:0.75rem; }
.ab-logo { width:32px; height:32px; background:var(--ab-lime); display:flex; align-items:center; justify-content:center; }
.ab-logo span { color:#050505; font-family:'Inter','Segoe UI',sans-serif; font-weight:700; font-size:14px; }
.ab-brand-name { font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:600; line-height:1.1; }
.ab-brand-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); }
.ab-nav-right { display:flex; align-items:center; gap:0.6rem; }
.ab-page-btn { padding:0.45rem 1rem; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; cursor:pointer; border:1px solid var(--ab-border); background:var(--ab-surface); color:var(--ab-fg); transition:all 0.15s; }
.ab-page-btn:hover { background:var(--ab-surface-hover); }
.ab-page-btn.active { background:var(--ab-lime); color:#050505; border-color:var(--ab-lime); }
.ab-dd-menu { position:absolute; top:calc(100% + 4px); right:0; min-width:200px; background:var(--ab-card); border:1px solid var(--ab-border); box-shadow:0 12px 40px rgba(0,0,0,0.5); display:none; z-index:60; }
.ab-dd.open .ab-dd-menu { display:block; }
.ab-dd-item { display:flex; align-items:center; gap:0.6rem; width:100%; padding:0.6rem 0.9rem; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; text-align:left; background:none; border:none; color:var(--ab-fg); cursor:pointer; transition:background 0.12s; }
.ab-dd-item:hover { background:var(--ab-surface-hover); }
.ab-dd-item.active { color:var(--ab-lime); }
.ab-dd-item small { display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:var(--ab-muted-fg); }

/* Pages */
.ab-page { display:none; padding:1.5rem; }
.ab-page.active { display:block; }
.ab-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.ab-head-title { font-family:'Inter','Segoe UI',sans-serif; font-size:24px; font-weight:600; letter-spacing:-0.025em; line-height:1.15; }
.ab-head-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:var(--ab-muted-fg); margin-top:0.35rem; }
.ab-kicker-row { display:flex; align-items:center; gap:0.6rem; margin-bottom:0.5rem; }
.ab-kicker-row svg, .ab-kicker-row i { color:var(--ab-lime); font-size:14px; }
.ab-btn { display:inline-flex; align-items:center; gap:0.45rem; padding:0.5rem 0.9rem; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; cursor:pointer; border:1px solid transparent; background:var(--ab-fg); color:#050505; transition:all 0.15s; white-space:nowrap; }
.ab-btn:hover { opacity:0.88; }
.ab-btn-outline { background:transparent; color:var(--ab-fg); border-color:var(--ab-border); }
.ab-btn-outline:hover { background:var(--ab-surface-hover); }
.ab-btn-lime { background:var(--ab-lime); color:#050505; }
.ab-btn-lime:hover { background:rgba(204,255,0,0.88); }
.ab-btn-ghost { background:transparent; color:var(--ab-fg); border-color:transparent; }
.ab-btn-ghost:hover { background:var(--ab-surface-hover); }
.ab-btn-danger { background:transparent; color:var(--ab-destructive); border-color:rgba(255,68,68,0.3); }
.ab-btn-danger:hover { background:rgba(255,68,68,0.1); }

/* Grids */
.ab-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
.ab-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1.25rem; }
.ab-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
.ab-grid-6 { display:grid; grid-template-columns:repeat(6,1fr); gap:1rem; }
.ab-sp-1 { grid-column:span 1; }
.ab-sp-2 { grid-column:span 2; }

/* Glance card (Pulse) */
.ab-glance { padding:1.1rem 1.25rem; position:relative; overflow:hidden; }
.ab-glance-title { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:var(--ab-muted-fg); margin-bottom:0.4rem; }
.ab-glance-val-row { display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.6rem; flex-wrap:wrap; }
.ab-glance-val { font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; letter-spacing:-0.02em; line-height:1; }
.ab-glance-chg { font-size:14px; font-family:'Inter','Segoe UI',sans-serif; display:inline-flex; align-items:center; gap:0.15rem; }
.ab-chg-up { color:#0E8131; }
.ab-chg-down { color:var(--ab-destructive); }
.ab-spark-wrap { height:48px; position:relative; }
.ab-spark-wrap canvas { width:100% !important; height:100% !important; }

/* Feed (Pulse) */
.ab-feed { display:flex; flex-direction:column; }
.ab-feed-head { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--ab-border); }
.ab-feed-head h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-feed-count { font-size:12px; color:var(--ab-muted-fg); font-family:'Inter','Segoe UI',sans-serif; }
.ab-feed-list { max-height:420px; overflow-y:auto; }
.ab-feed-row { display:flex; align-items:center; gap:1rem; padding:0.8rem 1.25rem; border-bottom:1px solid var(--ab-border); cursor:pointer; transition:background 0.12s; }
.ab-feed-row:last-child { border-bottom:none; }
.ab-feed-row:hover { background:var(--ab-surface-hover); }
.ab-pill { text-align:center; padding:0.28rem 0.6rem; font-size:11px; font-weight:600; font-family:'Inter','Segoe UI',sans-serif; border:1px solid; flex-shrink:0; text-transform:uppercase; letter-spacing:0.03em; white-space:nowrap; }
.ab-pill-pending { color:var(--ab-yellow); background:rgba(234,179,8,0.12); border-color:rgba(234,179,8,0.4); }
.ab-pill-review { color:var(--ab-blue); background:rgba(37,99,235,0.1); border-color:rgba(37,99,235,0.35); }
.ab-pill-confirmed { color:#046b25; background:rgba(4,107,37,0.12); border-color:rgba(4,107,37,0.35); }
.ab-pill-progress { color:var(--ab-yellow); background:rgba(234,179,8,0.12); border-color:rgba(234,179,8,0.4); }
.ab-pill-resolved { color:#046b25; background:rgba(4,107,37,0.12); border-color:rgba(4,107,37,0.35); }
.ab-pill-cancelled { color:var(--ab-destructive); background:rgba(229,62,62,0.1); border-color:rgba(229,62,62,0.3); }
.ab-feed-info { flex:1; min-width:0; }
.ab-feed-name { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ab-feed-meta { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); margin-top:0.15rem; }
.ab-feed-stat { text-align:right; flex-shrink:0; }
.ab-feed-stat b { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-feed-stat span { display:block; font-size:12px; color:var(--ab-muted-fg); margin-top:0.15rem; font-family:'Inter','Segoe UI',sans-serif; }

/* Side column (Pulse) */
.ab-side { display:flex; flex-direction:column; gap:1.25rem; }
.ab-spend { padding:1.25rem; }
.ab-spend-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.9rem; }
.ab-spend-head h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-spend-pct { font-size:12px; color:var(--ab-muted-fg); font-family:'Inter','Segoe UI',sans-serif; }
.ab-spend-big { font-family:'Inter','Segoe UI',sans-serif; font-size:30px; font-weight:600; letter-spacing:-0.02em; line-height:1; }
.ab-spend-sub { color:var(--ab-muted-fg); font-size:14px; margin-left:0.4rem; font-family:'Inter','Segoe UI',sans-serif; }
.ab-progress { height:4px; background:var(--ab-muted); overflow:hidden; }
.ab-progress-fill { height:100%; background:var(--ab-lime); transition:width 1s ease-out; }
.ab-spend .ab-progress-fill { background:#046b25; }
.ab-highlights { padding:1.25rem; flex:1; }
.ab-highlights h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; margin-bottom:1rem; }
.ab-hl-row { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; }
.ab-hl-row:last-child { margin-bottom:0; }
.ab-hl-icon { width:32px; height:32px; background:rgba(4,107,37,0.1); display:flex; align-items:center; justify-content:center; color:#046b25; font-size:0.8rem; flex-shrink:0; }
.ab-hl-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; flex:1; }
.ab-hl-value { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; text-align:right; max-width:45%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Metric cards */
.ab-metric { padding:1rem 1.1rem; }
.ab-metric-title { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); margin-bottom:0.5rem; }
.ab-metric-row { display:flex; align-items:baseline; gap:0.5rem; margin-bottom:0.3rem; flex-wrap:wrap; }
.ab-metric-val { font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; letter-spacing:-0.02em; line-height:1; }
.ab-metric-chg { font-size:14px; font-family:'Inter','Segoe UI',sans-serif; }
.ab-metric-detail { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:var(--ab-muted-fg); }

/* Charts */
.ab-chart-card { padding:1.25rem; }
.ab-chart-card h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-chart-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); margin-top:0.25rem; }
.ab-chart-head { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:1.25rem; gap:1rem; }
.ab-legend { display:flex; align-items:center; gap:1.2rem; }
.ab-legend-item { display:flex; align-items:center; gap:0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); }
.ab-legend-swatch { width:12px; height:12px; }
.ab-range { display:flex; align-items:center; border:1px solid var(--ab-border); background:var(--ab-surface); }
.ab-range-btn { padding:0.35rem 0.8rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; background:none; border:none; color:var(--ab-muted-fg); cursor:pointer; transition:all 0.12s; }
.ab-range-btn:hover { background:var(--ab-surface-hover); }
.ab-range-btn.active { background:var(--ab-fg); color:#ffffff; }
.ab-range-extra { display:flex; align-items:center; gap:0.35rem; }
.ab-range-select { height:30px; padding:0 6px; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; background:var(--ab-surface); color:var(--ab-fg); border:1px solid var(--ab-border); outline:none; cursor:pointer; }
.ab-chart-canvas { position:relative; height:320px; }
.ab-donut-legend { display:grid; grid-template-columns:repeat(3,1fr); gap:0.5rem; margin-top:1rem; }
.ab-dot { width:8px; height:8px; flex-shrink:0; }
.ab-donut-legend-item { display:flex; align-items:center; gap:0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); }

/* Bar rows (Intel gender) */
.ab-bar-row { margin-bottom:1.25rem; }
.ab-bar-row:last-child { margin-bottom:0; }
.ab-bar-label-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.4rem; }
.ab-bar-label-row span { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; }
.ab-bar-label-row .ab-mono { font-size:14px; font-weight:600; }
.ab-bar { height:8px; background:var(--ab-muted); overflow:hidden; }
.ab-bar-fill { height:100%; background:var(--ab-lime); }

/* Tables */
.ab-table-card { background:var(--ab-card); border:1px solid var(--ab-border); }
.ab-table-wrap { overflow-x:auto; }
.ab-table { width:100%; border-collapse:collapse; }
.ab-table th { text-align:left; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); text-transform:uppercase; letter-spacing:0.06em; padding:0.9rem 1.25rem; border-bottom:1px solid var(--ab-border); white-space:nowrap; }
.ab-table th.r, .ab-table td.r { text-align:right; }
.ab-table td { padding:0.85rem 1.25rem; border-bottom:1px solid var(--ab-border); font-family:'Inter','Segoe UI',sans-serif; font-size:14px; white-space:nowrap; }
.ab-table tbody tr { transition:background 0.12s; }
.ab-table tbody tr:hover { background:var(--ab-surface-hover); }
.ab-avatar { width:32px; height:32px; background:rgba(204,255,0,0.1); display:inline-flex; align-items:center; justify-content:center; color:var(--ab-lime); font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-badge { display:inline-block; padding:0.2rem 0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; }
.ab-badge-high { background:rgba(4,107,37,0.12); color:#046b25; }
.ab-badge-medium { background:rgba(234,179,8,0.16); color:var(--ab-yellow); }
.ab-badge-low { background:var(--ab-muted); color:var(--ab-muted-fg); }
.ab-share-bar { display:flex; align-items:center; justify-content:flex-end; gap:0.5rem; }
.ab-share-track { width:64px; height:6px; background:var(--ab-muted); overflow:hidden; }
.ab-share-fill { height:100%; background:#046b25; }

/* Budget */
.ab-budget-big { font-family:'Inter','Segoe UI',sans-serif; font-size:28px; font-weight:600; letter-spacing:-0.02em; line-height:1; }
.ab-progress-label-row { display:flex; align-items:center; justify-content:space-between; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; margin-bottom:0.35rem; }
.ab-progress-label-row .ab-mono { font-size:12px; font-weight:600; }
.ab-channel-row { padding:1rem 1.25rem; border-bottom:1px solid var(--ab-border); cursor:pointer; transition:background 0.12s; }
.ab-channel-row:last-child { border-bottom:none; }
.ab-channel-row:hover { background:var(--ab-surface-hover); }
.ab-channel-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.5rem; }
.ab-channel-name { display:flex; align-items:center; gap:0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; }
.ab-channel-bar { height:6px; background:var(--ab-muted); overflow:hidden; }
    .ab-channel-fill-over { background:#046b25; }
    .ab-channel-fill-track { background:#046b25; }
    .ab-channel-fill-ok { background:#046b25; }
.ab-txn { padding:1rem 1.25rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--ab-border); transition:background 0.12s; }
.ab-txn:last-child { border-bottom:none; }
.ab-txn:hover { background:var(--ab-surface-hover); }
.ab-txn-desc { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; }
.ab-txn-date { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); margin-top:0.1rem; }
.ab-txn-amt { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-txn-amt.pos { color:#0E8131; }

/* Inputs / modal */
.ab-label { display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; margin-bottom:0.4rem; }
.ab-input, .ab-select { width:100%; height:40px; padding:0 0.8rem; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; background:var(--ab-bg); color:var(--ab-fg); border:1px solid var(--ab-border); outline:none; }
.ab-input:focus, .ab-select:focus { border-color:var(--ab-lime); box-shadow:0 0 0 1px var(--ab-lime); }
.ab-modal-backdrop { position:fixed; inset:0; background:rgba(5,5,5,0.8); backdrop-filter:blur(4px); z-index:200; display:none; align-items:center; justify-content:center; padding:1rem; }
.ab-modal-backdrop.open { display:flex; }
.ab-modal { background:var(--ab-card); border:1px solid var(--ab-border); width:100%; max-width:460px; padding:1.5rem; max-height:90vh; overflow-y:auto; }
.ab-modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; }
.ab-modal-head h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; letter-spacing:-0.025em; }
/* Keep shared shadcn dialogs (sd-dialog) looking correct inside the dashboard */
.aether-dash .sd-dialog-content { border-radius:8px; letter-spacing:normal; font-family:'Inter','Segoe UI',sans-serif; }
.aether-dash .sd-dialog-content h3,
.aether-dash .sd-dialog-content p,
.aether-dash .sd-dialog-content span { font-family:'Inter','Segoe UI',sans-serif; letter-spacing:normal; }
.aether-dash .sd-dialog-title { font-size:18px; font-weight:600; letter-spacing:-0.025em; }
.aether-dash .sd-dialog-description { font-size:14px; font-weight:400; }
.aether-dash .sd-label { font-size:14px; font-weight:500; }
.aether-dash .sd-dialog-close { border-radius:4px; }
.aether-dash .sd-btn { border-radius:6px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; }
.aether-dash .sd-btn-green-text:hover { color:#0E8131; border-color:#0E8131; background:#f0fdf4; }
.ab-field { margin-bottom:1rem; }
.ab-preset-row { display:flex; gap:0.5rem; }
.ab-preset { flex:1; padding:0.5rem 0; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; background:transparent; color:var(--ab-fg); border:1px solid var(--ab-border); cursor:pointer; transition:all 0.12s; }
.ab-preset:hover { background:var(--ab-surface-hover); }
.ab-preset.active { background:var(--ab-lime); color:#050505; border-color:var(--ab-lime); }
.ab-modal-actions { display:flex; gap:0.75rem; padding-top:1rem; }
.ab-modal-actions .ab-btn { flex:1; justify-content:center; }

/* Settings */
.ab-settings-wrap { max-width:820px; }
.ab-set-section { background:var(--ab-card); border:1px solid var(--ab-border); margin-bottom:1.5rem; }
.ab-set-head { display:flex; align-items:center; gap:0.75rem; padding:1rem 1.25rem; border-bottom:1px solid var(--ab-border); }
.ab-set-head h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-set-head p { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); }
.ab-set-row { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--ab-border); }
.ab-set-row:last-child { border-bottom:none; }
.ab-set-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; }
.ab-set-value { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-toggle { width:40px; height:20px; background:rgba(204,255,0,0.2); position:relative; border:none; cursor:pointer; }
.ab-toggle .knob { position:absolute; top:2px; width:16px; height:16px; transition:all 0.15s; }
.ab-toggle.on .knob { right:2px; background:var(--ab-lime); }
.ab-toggle.off .knob { left:2px; background:var(--ab-muted-fg); }

/* Gantt */
.ab-gantt { background:var(--ab-card); border:1px solid var(--ab-border); overflow:hidden; }
.ab-gantt-head { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--ab-border); flex-wrap:wrap; gap:0.75rem; }
.ab-gantt-head h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-gantt-legend { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.ab-gantt-legend-item { display:flex; align-items:center; gap:0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); text-transform:capitalize; }
.ab-gantt-scroll { max-height:400px; overflow:auto; }
.ab-gantt-headrow { position:sticky; top:0; z-index:5; background:var(--ab-card); border-bottom:1px solid var(--ab-border); }
.ab-gantt-grid { display:flex; min-width:820px; }
.ab-gantt-label-col { width:220px; flex-shrink:0; border-right:1px solid var(--ab-border); }
.ab-gantt-time-col { flex:1; display:flex; }
.ab-gantt-week { flex:1; padding:0.75rem; text-align:center; border-right:1px solid var(--ab-border); }
.ab-gantt-week:last-child { border-right:none; }
.ab-gantt-week span { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); }
.ab-gantt-row { display:flex; min-width:820px; border-bottom:1px solid var(--ab-border); cursor:pointer; transition:background 0.12s; }
.ab-gantt-row:hover { background:var(--ab-surface-hover); }
.ab-gantt-row:last-child { border-bottom:none; }
.ab-gantt-row-label { width:220px; flex-shrink:0; border-right:1px solid var(--ab-border); padding:0.75rem 1rem; display:flex; align-items:center; gap:0.6rem; }
.ab-gantt-row-label .ab-mono { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; }
.ab-gantt-row-label small { display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); }
.ab-gantt-track { flex:1; position:relative; padding:0.9rem 0.25rem; }
.ab-gantt-track-bg { position:absolute; inset:0; display:flex; }
.ab-gantt-track-bg div { flex:1; border-right:1px solid rgba(255,255,255,0.04); }
.ab-gantt-track-bg div:last-child { border-right:none; }
.ab-gantt-bar { position:absolute; top:50%; transform:translateY(-50%); height:32px; border:1px solid; overflow:hidden; }
.ab-gantt-bar-progress { position:absolute; inset:0; opacity:0.5; }
.ab-gantt-bar span { position:absolute; inset:0; display:flex; align-items:center; padding:0 0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; }
.ab-gantt-bar-live { background:rgba(14,129,49,0.14); border-color:#0E8131; box-shadow:0 0 20px rgba(14,129,49,0.3); }
.ab-gantt-bar-live .ab-gantt-bar-progress { background:#0E8131; }
.ab-gantt-bar-scheduled { background:rgba(51,51,51,0.14); border-color:#333333; box-shadow:0 0 20px rgba(51,51,51,0.3); }
.ab-gantt-bar-scheduled .ab-gantt-bar-progress { background:rgba(255,255,255,0.1); }
.ab-gantt-bar-paused { background:rgba(217,164,65,0.14); border-color:#D9A441; box-shadow:0 0 20px rgba(217,164,65,0.3); }
.ab-gantt-bar-paused .ab-gantt-bar-progress { background:rgba(255,255,255,0.1); }
.ab-gantt-bar-ended { background:rgba(234,179,8,0.14); border-color:#EAB308; box-shadow:none; }
.ab-gantt-bar-ended .ab-gantt-bar-progress { background:rgba(255,255,255,0.1); }

/* Campaign cards */
.ab-camp-card { background:var(--ab-card); border:1px solid var(--ab-border); padding:1rem 1.25rem; cursor:pointer; transition:all 0.15s; }
.ab-camp-card:hover { background:var(--ab-surface-hover); }
.ab-camp-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem; }
.ab-camp-id { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); text-transform:uppercase; }
.ab-status-dot { width:8px; height:8px; }
.ab-status-dot.live { background:#0E8131; animation:ab-pulse 2s ease-in-out infinite; }
.ab-status-dot.scheduled { background:#333333; }
.ab-status-dot.paused { background:#D9A441; }
.ab-status-dot.ended { background:#EAB308; }
.ab-camp-name { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; margin-bottom:0.25rem; transition:color 0.15s; }
.ab-camp-card:hover .ab-camp-name { color:#0E8131; }
.ab-camp-channel { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); margin-bottom:0.5rem; }
.ab-camp-dates { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; color:var(--ab-muted-fg); margin-bottom:0.5rem; }
.ab-camp-budget-row { display:flex; align-items:center; justify-content:space-between; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; }
.ab-camp-budget-row span { color:var(--ab-muted-fg); }
.ab-camp-progress { height:4px; background:var(--ab-muted); margin-top:0.75rem; overflow:hidden; }
.ab-camp-progress div { height:100%; background:#0E8131; }
.ab-camp-foot { display:flex; align-items:center; justify-content:space-between; margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid var(--ab-border); }
.ab-camp-status { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); text-transform:capitalize; }

/* Stat card (generic small) */
.ab-stat { padding:1rem 1.25rem; }
.ab-stat-label { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); margin-bottom:0.35rem; }
.ab-stat-val { font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; letter-spacing:-0.02em; line-height:1; }
.ab-stat-trend { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; margin-top:0.35rem; }
.ab-stat-trend.pos { color:#0E8131; }
.ab-stat-trend.neg { color:var(--ab-destructive); }

@keyframes ab-pulse { 0%,100% { opacity:1; } 50% { opacity:0.35; } }
.ab-live-dot { width:8px; height:8px; background:var(--ab-lime); animation:ab-pulse 2s ease-in-out infinite; }

/* ── Animations (recharts + framer-motion look) ── */
@keyframes abPageIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
.ab-page.active { animation:abPageIn 0.2s ease-out; }
@keyframes abRowIn { from { opacity:0; transform:translateX(-20px); } to { opacity:1; transform:none; } }
.ab-gantt-row { animation:abRowIn 0.4s ease forwards; }
@keyframes abBarIn { from { transform:translateY(-50%) scaleX(0); } to { transform:translateY(-50%) scaleX(1); } }
.ab-gantt-bar { transform-origin:left center; animation:abBarIn 0.4s ease forwards; }
@keyframes abModalIn { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
.ab-modal-backdrop.open .ab-modal { animation:abModalIn 0.2s ease-out; }
@keyframes abBackdropIn { from { opacity:0; } to { opacity:1; } }
.ab-modal-backdrop.open { animation:abBackdropIn 0.15s ease-out; }

@media (max-width:1200px) { .ab-grid-4 { grid-template-columns:repeat(2,1fr); } .ab-grid-6 { grid-template-columns:repeat(3,1fr); } }
@media (max-width:1024px) { .ab-grid-3 { grid-template-columns:1fr; } .ab-grid-2 { grid-template-columns:1fr; } }
@media (max-width:640px) { .ab-grid-4 { grid-template-columns:1fr; } .ab-grid-6 { grid-template-columns:repeat(2,1fr); } .ab-page { padding:1rem; } }
</style>

<!-- ═══════════ ENGINE ═══════════ -->
<div class="ab-page active" id="ab-page-engine">
  <div class="ab-grid-4" style="margin-bottom:1.5rem;">
    <?php foreach ($aeth_glanceCards as $gi => $gc): ?>
      <div class="ab-card ab-glance">
        <p class="ab-glance-title"><?php echo $gc[0]; ?></p>
        <div class="ab-glance-val-row">
          <span class="ab-glance-val" data-count="<?php echo $gc[1]; ?>" data-suffix="<?php echo htmlspecialchars($gc[2]); ?>">0<?php echo htmlspecialchars($gc[2]); ?></span>
          <span class="ab-glance-chg <?php echo $gc[3] >= 0 ? 'ab-chg-up' : 'ab-chg-down'; ?>">
            <?php echo $gc[3] >= 0 ? '&#9650;' : '&#9660;'; ?> <?php echo number_format(abs($gc[3]), 1); ?>%
          </span>
        </div>
        <div class="ab-spark-wrap"><canvas id="abSpark-<?php echo $gi; ?>"></canvas></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ab-grid-3" style="margin-bottom:1.5rem;grid-template-columns:2fr 1fr;">
    <div class="ab-card ab-chart-card">
      <div class="ab-chart-head">
        <div><h2>Cases Filed vs. Resolution Time</h2><p class="ab-chart-sub" id="abEnginePerfSub">Last 30 days</p></div>
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
          <div class="ab-range">
            <button class="ab-range-btn active" data-aethrange="30d" onclick="aethRange('30d', this)">Last 30 Days</button>
            <button class="ab-range-btn" data-aethrange="year" onclick="aethRange('year', this)">Per Year</button>
          </div>
          <div class="ab-range-extra" id="abRangeYearWrap" style="display:none;">
            <select id="abRangeYear" class="ab-range-select" onchange="renderEngineChart('year')">
              <?php for ($y = date('Y'); $y >= 2021; $y--): ?><option value="<?php echo $y; ?>"><?php echo $y; ?></option><?php endfor; ?>
            </select>
          </div>
          <div class="ab-legend">
            <span class="ab-legend-item"><span class="ab-legend-swatch" style="background:#18181b;"></span> Cases Filed</span>
            <span class="ab-legend-item"><span class="ab-legend-swatch" style="background:var(--ab-track);"></span> Resolution Time</span>
          </div>
        </div>
      </div>
      <div class="ab-chart-canvas"><canvas id="abEnginePerf"></canvas></div>
    </div>
    <div class="ab-card ab-chart-card">
      <div class="ab-chart-head">
        <div><h2>Report Type Distribution</h2><p class="ab-chart-sub" id="abRadarSub">All Barangays</p></div>
        <select id="abRadarBrgy" class="ab-range-select" onchange="aethSetRadarBrgy(this.value)">
          <?php foreach ($aeth_radarBrgy as $__b): ?>
            <option value="<?php echo $__b['id']; ?>"><?php echo htmlspecialchars($__b['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ab-chart-canvas"><canvas id="abEngineRadar"></canvas></div>
    </div>
  </div>

  <div class="ab-table-card">
    <div class="ab-feed-head"><h2>Barangay Breakdown</h2></div>
    <div class="ab-table-wrap">
      <table class="ab-table">
        <thead>
          <tr><th>Barangay</th><th class="r">Reports Filed</th><th class="r">Cases Resolved</th><th class="r">Resolution Rate</th><th class="r">Staff Actions Taken</th><th class="r">% of Total Reports</th></tr>
        </thead>
        <tbody>
          <?php if (empty($aeth_brgyBreakdown)): ?>
            <tr><td colspan="6" class="r" style="text-align:center;">No barangay data yet.</td></tr>
          <?php else: ?>
            <?php foreach ($aeth_brgyBreakdown as $bd): ?>
              <tr>
                <td style="font-weight:500;"><?php echo htmlspecialchars($bd['name']); ?></td>
                <td class="r ab-mono"><?php echo number_format($bd['filed']); ?></td>
                <td class="r ab-mono"><?php echo number_format($bd['resolved']); ?></td>
                <td class="r ab-mono" style="<?php echo $bd['rate'] >= 80 ? 'color:#046b25;' : ''; ?>"><?php echo number_format($bd['rate'], 1); ?>%</td>
                <td class="r ab-mono"><?php echo number_format($bd['actions']); ?></td>
                <td class="r">
                  <div class="ab-share-bar">
                    <div class="ab-share-track"><div class="ab-share-fill" style="width:<?php echo $bd['share']; ?>%;"></div></div>
                    <span class="ab-mono" style="font-size:12px;color:var(--ab-muted-fg);width:34px;text-align:right;"><?php echo $bd['share']; ?>%</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;align-items:stretch;margin-top:1.5rem;">
    <div class="ab-card ab-feed">
      <div class="ab-feed-head">
        <h2>Recent Veterinary Activities</h2>
        <span class="ab-feed-count"><?php echo count($aeth_activities); ?> recent</span>
      </div>
      <div class="ab-feed-list">
        <?php if (empty($aeth_activities)): ?>
          <div class="ab-feed-row"><p class="ab-feed-meta">No recent activities yet.</p></div>
        <?php else: ?>
          <?php foreach ($aeth_activities as $act): ?>
            <div class="ab-feed-row">
              <span class="ab-pill ab-pill-<?php echo $act['cls']; ?>"><?php echo $act['status']; ?></span>
              <div class="ab-feed-info">
                <p class="ab-feed-name"><?php echo htmlspecialchars($act['name']); ?></p>
                <p class="ab-feed-meta"><?php echo $act['meta']; ?></p>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="ab-side">
      <div class="ab-card ab-spend">
        <div class="ab-spend-head">
          <h2>Today&rsquo;s Service Requests</h2>
          <span class="ab-spend-pct"><?php echo number_format($aeth_todayPct, 1); ?>% of daily capacity</span>
        </div>
        <div>
          <span class="ab-spend-big"><?php echo number_format($aeth_todayRequests); ?></span>
          <span class="ab-spend-sub">/ <?php echo number_format($aeth_todayCapacity); ?></span>
        </div>
        <div class="ab-progress" style="margin-top:1rem;"><div class="ab-progress-fill" style="width:<?php echo min($aeth_todayPct, 100); ?>%;"></div></div>
      </div>

      <div class="ab-card ab-highlights">
        <h2>Today&rsquo;s Highlights</h2>
        <div class="ab-hl-row">
          <div class="ab-hl-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21a9 9 0 1 0-9-9"/><path d="M12 12 6 6"/><path d="M3 21l4-4"/></svg></div>
          <span class="ab-hl-label">Most Reported Area</span>
          <span class="ab-hl-value" title="<?php echo htmlspecialchars($aeth_hlArea); ?>"><?php echo htmlspecialchars($aeth_hlArea); ?><?php echo $aeth_hlAreaCount > 0 ? ' &middot; ' . number_format($aeth_hlAreaCount) : ''; ?></span>
        </div>
        <div class="ab-hl-row">
          <div class="ab-hl-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h6l-2 8 10-12h-6l2-8Z"/></svg></div>
          <span class="ab-hl-label">Active Rabies Cases</span>
          <span class="ab-hl-value"><?php echo number_format($aeth_hlRabies); ?></span>
        </div>
        <div class="ab-hl-row">
          <div class="ab-hl-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg></div>
          <span class="ab-hl-label">New Reports Today</span>
          <span class="ab-hl-value"><?php echo number_format($aeth_hlNewToday); ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════ INTEL ═══════════ -->
<div class="ab-page" id="ab-page-intel">
  <div class="ab-grid-4" style="margin-bottom:1.5rem;">
    <div class="ab-card ab-stat"><p class="ab-stat-label">Total Appointments (30d)</p><p class="ab-stat-val">1,240</p><p class="ab-stat-trend pos">+12.4% vs last period</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Upcoming Appointments</p><p class="ab-stat-val">86</p><p class="ab-stat-trend pos">Scheduled</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Avg. Appointment Duration</p><p class="ab-stat-val">32 min</p><p class="ab-stat-trend">vs. 35 min last month</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">No-Show Rate</p><p class="ab-stat-val">8.2%</p><p class="ab-stat-trend pos">-0.4% (down is good)</p></div>
  </div>

  <div class="ab-grid-3" style="margin-bottom:1.5rem;">
    <div class="ab-card ab-chart-card">
      <h2>Appointment Type Distribution</h2>
      <div class="ab-chart-canvas" style="height:256px;"><canvas id="abIntelAge"></canvas></div>
      <div class="ab-donut-legend">
        <?php foreach ($aeth_apptTypeLabels as $__i => $__label): ?>
          <div class="ab-donut-legend-item"><span class="ab-dot" style="background:<?php echo $aeth_apptTypeColors[$__i]; ?>;"></span><?php echo $__label; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ab-card ab-chart-card">
      <h2>Appointment Source Split</h2>
      <div style="margin-top:2rem;">
        <div class="ab-bar-row">
          <div class="ab-bar-label-row"><span>Resident-Filed</span><span class="ab-mono"><?php echo number_format($aeth_apptResidentPct, 1); ?>%</span></div>
          <div class="ab-bar"><div class="ab-bar-fill" style="width:<?php echo $aeth_apptResidentPct; ?>%;background:#0E8131;"></div></div>
        </div>
        <div class="ab-bar-row">
          <div class="ab-bar-label-row"><span>Barangay / Staff-Filed</span><span class="ab-mono"><?php echo number_format($aeth_apptNonResidentPct, 1); ?>%</span></div>
          <div class="ab-bar"><div class="ab-bar-fill" style="width:<?php echo $aeth_apptNonResidentPct; ?>%;background:#1A1A1A;"></div></div>
        </div>
      </div>
    </div>
    <div class="ab-card ab-chart-card">
      <div class="ab-chart-head">
        <div><h2>Top Barangays by Appointment Volume</h2></div>
        <select id="abApptLocType" class="ab-range-select" onchange="aethSetApptLoc(this.value)">
          <option value="all">All Types</option>
          <option value="kapon">Kapon</option>
          <option value="vaccination">Vaccination</option>
          <option value="adoption">Adoption</option>
          <option value="checkup">Check-up</option>
          <option value="deworming">Deworming</option>
        </select>
      </div>
      <div class="ab-chart-canvas" style="height:256px;"><canvas id="abIntelLoc"></canvas></div>
    </div>
  </div>

  <div class="ab-table-card">
    <div class="ab-feed-head"><h2>Appointment Segments</h2><button class="ab-btn ab-btn-outline" style="padding:0.35rem 0.7rem;">Manage Segments</button></div>
    <div class="ab-table-wrap">
      <table class="ab-table">
        <thead><tr><th>Appointment Type</th><th class="r">Total Booked</th><th class="r">Avg. Completion Time</th><th class="r">Demand Level</th><th class="r">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($aeth_apptSegments as $s): ?>
            <tr>
              <td><span class="ab-avatar" style="background:transparent;color:#0E8131;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>            <span style="font-size:14px;font-weight:500;margin-left:0.5rem;"><?php echo $s[0]; ?></span></td>
              <td class="r ab-mono"><?php echo number_format($s[1]); ?></td>
              <td class="r ab-mono"><?php echo $s[2]; ?></td>
              <td class="r"><span class="ab-badge ab-badge-<?php echo strtolower($s[3]); ?>"><?php echo $s[3]; ?></span></td>
              <td class="r"><button class="ab-btn" style="padding:0.35rem 0.7rem;background:var(--ab-muted);color:#050505;border-color:transparent;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/></svg> Manage</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════════ BUDGET ═══════════ -->
<div class="ab-page" id="ab-page-budget">
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
    <div class="ab-card" style="grid-column:span 2;padding:1.25rem;">
      <h2 style="font-size:14px;font-weight:600;margin-bottom:1rem;">Annual Adoption Progress</h2>
      <div style="display:flex;align-items:baseline;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
        <span class="ab-budget-big">219</span>
        <span style="color:var(--ab-muted-fg);">/ 365 Adopted</span>
      </div>
      <div>
        <div class="ab-progress-label-row"><span style="color:var(--ab-muted-fg);">Adopted</span><span class="ab-mono">60.0%</span></div>
        <div class="ab-progress"><div class="ab-progress-fill" style="width:60%;background:#0E8131;"></div></div>
        <div class="ab-progress-label-row" style="margin-top:0.9rem;"><span style="color:var(--ab-muted-fg);">Projected Adoptions</span><span class="ab-mono">97.0%</span></div>
        <div class="ab-progress"><div style="height:100%;background:#1A1A1A;width:97%;"></div></div>
      </div>
    </div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Remaining Capacity</p><p class="ab-stat-val" style="color:#0E8131;">146</p><p class="ab-stat-trend" style="color:var(--ab-muted-fg);">Rescued animals still awaiting adoption</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Monthly Avg. Adoptions</p><p class="ab-stat-val">27</p><p class="ab-stat-trend pos">5% under target</p></div>
  </div>

  <div class="ab-card ab-chart-card" style="margin-bottom:1.5rem;">
    <h2>Monthly Adoptions vs. Target</h2>
    <div class="ab-chart-canvas" style="height:256px;"><canvas id="abBudgetChart"></canvas></div>
  </div>

  <div class="ab-grid-2">
    <div class="ab-table-card">
      <div class="ab-feed-head"><h2>Rescue Source Breakdown</h2></div>
      <?php foreach ($aeth_rescueSources as $ch): ?>
        <?php
        $cp = $ch[1] > 0 ? $ch[2] / $ch[1] * 100 : 0;
        $cls = $cp > 90 ? 'over' : ($cp > 70 ? 'track' : 'ok');
        ?>
        <div class="ab-channel-row">
          <div class="ab-channel-top">
            <div class="ab-channel-name">
              <span><?php echo $ch[0]; ?></span>
              <?php if ($cls === 'over' || $cls === 'track'): ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg><?php else: ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0E8131" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg><?php endif; ?>
            </div>
            <span class="ab-mono" style="font-size:14px;font-weight:600;"><?php echo number_format($ch[2]); ?> / <?php echo number_format($ch[1]); ?> adopted</span>
          </div>
          <div class="ab-channel-bar"><div class="ab-channel-fill-<?php echo $cls; ?>" style="width:<?php echo min($cp, 100); ?>%;height:100%;"></div></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="ab-table-card">
      <div class="ab-feed-head"><h2>Recent Adoptions</h2><button class="ab-btn ab-btn-ghost" style="font-size:12px;">View All</button></div>
      <?php foreach ($aeth_txns as $t): ?>
        <div class="ab-txn">
          <div><p class="ab-txn-desc"><?php echo $t[1]; ?></p><p class="ab-txn-date"><?php echo $t[2]; ?></p></div>
          <span class="ab-txn-amt <?php echo $t[4]; ?>"><?php echo $t[3]; ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══════════ ARCHITECT ═══════════ -->
<div class="ab-page" id="ab-page-architect">
  <div class="ab-grid-4" style="margin-bottom:1.5rem;">
    <div class="ab-card ab-stat"><p class="ab-stat-label">Total Programs</p><p class="ab-stat-val"><?php echo count($aeth_campaigns); ?></p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Ongoing Now</p><p class="ab-stat-val" style="color:#0E8131;"><?php echo count(array_filter($aeth_campaigns, fn($c) => $c['status'] === 'live')); ?></p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Upcoming</p><p class="ab-stat-val" style="color:#333333;"><?php echo count(array_filter($aeth_campaigns, fn($c) => $c['status'] === 'scheduled')); ?></p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Total Target Reach</p><p class="ab-stat-val">3,100 animals</p></div>
  </div>

  <!-- Gantt -->
  <div class="ab-gantt">
    <div class="ab-gantt-head">
      <h2>Campaign Timeline</h2>
      <div class="ab-gantt-legend">
        <?php foreach (['live', 'scheduled', 'paused', 'ended'] as $st): ?>
          <div class="ab-gantt-legend-item"><span class="ab-status-dot <?php echo $st; ?>" style="width:12px;height:12px;border:1px solid;"></span> <?php echo $st; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ab-gantt-scroll">
      <div class="ab-gantt-headrow">
        <div class="ab-gantt-grid">
          <div class="ab-gantt-label-col"><div style="padding:0.75rem 1rem;"><span style="font-size:12px;font-weight:500;color:var(--ab-muted-fg);">Program</span></div></div>
          <div class="ab-gantt-time-col">
            <?php foreach ($aeth_weeks as $w): ?><div class="ab-gantt-week"><span><?php echo $w; ?></span></div><?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php $gi = 0; foreach ($aeth_campaigns as $c):
          $sT = strtotime($c['start']); $eT = strtotime($c['end']);
          if ($eT < strtotime($aeth_gStart) || $sT > strtotime($aeth_gEnd)) continue;
          $left = aeth_pos($c['start'], $aeth_gStart, $aeth_gTotalDays);
          $wdt  = aeth_width($c['start'], $c['end'], $aeth_gStart, $aeth_gEnd, $aeth_gTotalDays);
          if ($wdt <= 0) $wdt = 1;
          $gi++;
      ?>
      <div class="ab-gantt-row" data-camp="<?php echo $c['id']; ?>" style="animation-delay:<?php echo round($gi * 0.05, 2); ?>s;" onclick="aethShowCampaign(this)">
        <div class="ab-gantt-row-label">
          <span class="ab-status-dot <?php echo $c['status']; ?>"></span>
          <div style="min-width:0;">
            <p class="ab-mono" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($c['name']); ?></p>
            <small><?php echo $c['channel']; ?></small>
          </div>
        </div>
        <div class="ab-gantt-track">
          <div class="ab-gantt-track-bg"><?php for ($i = 0; $i < count($aeth_weeks); $i++): ?><div></div><?php endfor; ?></div>
          <div class="ab-gantt-bar ab-gantt-bar-<?php echo $c['status']; ?>" style="left:<?php echo $left; ?>%;width:<?php echo $wdt; ?>%;animation-delay:<?php echo round($gi * 0.05 + 0.2, 2); ?>s;">
            <div class="ab-gantt-bar-progress" style="width:<?php echo $c['progress']; ?>%;"></div>
            <span><?php echo $c['progress']; ?>%</span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="margin-top:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <h2 style="font-size:18px;font-weight:600;letter-spacing:-0.025em;">Program Details</h2>
      <span style="font-size:12px;color:var(--ab-muted-fg);">Click a program to view details</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;" id="abCampGrid">
      <?php foreach ($aeth_campaigns as $c): ?>
        <div class="ab-camp-card" data-camp="<?php echo $c['id']; ?>" onclick="aethShowCampaign(this)">
          <div class="ab-camp-top">
            <span class="ab-camp-id"><?php echo $c['id']; ?></span>
            <span class="ab-status-dot <?php echo $c['status']; ?>"></span>
          </div>
          <h3 class="ab-camp-name"><?php echo htmlspecialchars($c['name']); ?></h3>
          <p class="ab-camp-channel"><?php echo $c['channel']; ?></p>
          <p class="ab-camp-dates"><?php echo date('M d', strtotime($c['start'])); ?> - <?php echo date('M d', strtotime($c['end'])); ?></p>
          <div class="ab-camp-budget-row"><span>Budget</span><span class="ab-mono">$<?php echo number_format($c['budget'] / 1000); ?>K</span></div>
          <div class="ab-camp-progress"><div style="width:<?php echo $c['progress']; ?>%;"></div></div>
          <div class="ab-camp-foot">
            <span class="ab-camp-status"><?php echo $c['status']; ?></span>
            <?php if ($c['status'] === 'live' || $c['status'] === 'paused'): ?>
              <button class="ab-btn ab-btn-ghost" style="font-size:12px;padding:0.25rem 0.5rem;" onclick="event.stopPropagation();aethToggleStatus('<?php echo $c['id']; ?>')"><?php echo $c['status'] === 'live' ? 'Pause' : 'Resume'; ?></button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ Add Funds Modal ═══ -->
<div class="ab-modal-backdrop" id="abAddFundsModal">
  <div class="ab-modal">
    <div class="ab-modal-head"><h2>Add Funds</h2><button class="ab-btn ab-btn-ghost" onclick="aethCloseModal('abAddFundsModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button></div>
    <div>
      <div class="ab-field">
        <label class="ab-label">Amount ($)</label>
        <input class="ab-input" type="number" id="abAddAmount" value="10000">
      </div>
      <div class="ab-field">
        <div class="ab-preset-row">
          <button class="ab-preset" onclick="aethSetAmount(5000,this)">$5K</button>
          <button class="ab-preset active" onclick="aethSetAmount(10000,this)">$10K</button>
          <button class="ab-preset" onclick="aethSetAmount(25000,this)">$25K</button>
          <button class="ab-preset" onclick="aethSetAmount(50000,this)">$50K</button>
        </div>
      </div>
      <div class="ab-modal-actions">
        <button class="ab-btn ab-btn-outline" onclick="aethCloseModal('abAddFundsModal')">Cancel</button>
        <button class="ab-btn ab-btn-lime" onclick="aethCloseModal('abAddFundsModal')">Add <span id="abAddFundsLabel">$10,000</span></button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ New Campaign Modal ═══ -->
<div class="ab-modal-backdrop" id="abNewCampModal">
  <div class="ab-modal">
    <div class="ab-modal-head"><h2>Create New Program</h2><button class="ab-btn ab-btn-ghost" onclick="aethCloseModal('abNewCampModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button></div>
    <div>
      <div class="ab-field"><label class="ab-label">Campaign Name</label><input class="ab-input" id="abNewName" placeholder="Enter campaign name"></div>
      <div class="ab-field"><label class="ab-label">Channel</label>
        <select class="ab-select" id="abNewChannel">
          <option>Meta Ads</option><option>Google Ads</option><option>TikTok Ads</option><option>YouTube</option><option>Email</option><option>Multi-channel</option>
        </select>
      </div>
      <div class="ab-field"><label class="ab-label">Budget ($)</label><input class="ab-input" type="number" id="abNewBudget" value="10000"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="ab-field"><label class="ab-label">Start Date</label><input class="ab-input" type="date" id="abNewStart"></div>
        <div class="ab-field"><label class="ab-label">End Date</label><input class="ab-input" type="date" id="abNewEnd"></div>
      </div>
      <div class="ab-modal-actions">
        <button class="ab-btn ab-btn-outline" onclick="aethCloseModal('abNewCampModal')">Cancel</button>
        <button class="ab-btn ab-btn-lime" onclick="aethCloseModal('abNewCampModal')">Create Campaign</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Program Detail Modal ═══ -->
<div class="ab-modal-backdrop" id="abCampModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:560px;">
      <button type="button" class="sd-dialog-close" onclick="aethCloseModal('abCampModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="abCampModalTitle">Program Details</h2>
        <p class="sd-dialog-description">Review program details and manage progress</p>
      </div>
      <div id="abCampModalBody"></div>
    </div>
  </div>
</div>

<script>
(function() {
  var CAMPAIGNS = <?php echo json_encode($aeth_campaigns); ?>;
  var PERF = <?php echo json_encode($aeth_perf); ?>;
  var ATTR = <?php echo json_encode($aeth_attribution); ?>;
  var AGE = <?php echo json_encode($aeth_ageData); ?>;
  var LOCS = <?php echo json_encode($aeth_locations); ?>;
  var APPT_TYPES_LABELS = <?php echo json_encode($aeth_apptTypeLabels); ?>;
  var APPT_TYPES_COLORS = <?php echo json_encode($aeth_apptTypeColors); ?>;
  var APPT_TYPES_COUNTS = <?php echo json_encode($aeth_apptTypeCounts); ?>;
  var APPT_LOCS = <?php echo json_encode($aeth_apptLoc); ?>;
  var APPT_LOCS_BY_TYPE = <?php echo json_encode($aeth_apptLocByType); ?>;
  var KPI_TRENDS = <?php echo json_encode($aeth_glanceTrends); ?>;
  var ENGINE30 = <?php echo json_encode($aeth_engine30); ?>;
  var RADAR_LABELS = <?php echo json_encode($aeth_radarLabels); ?>;
  var RADAR_DATA = <?php echo json_encode($aeth_radarData); ?>;
  var RADAR_BRGY = <?php echo json_encode($aeth_radarBrgy); ?>;
  var ENGINE_REAL_YEAR = <?php echo json_encode($aeth_realYear); ?>;
  var ENGINE_REAL_DATA = <?php echo json_encode($aeth_realData); ?>;

  if (typeof Chart !== 'undefined') {
    Chart.defaults.animation.duration = 1500;
    Chart.defaults.animation.easing = 'easeOutQuart';
  }

  var charts = {};

  var dashEl = document.getElementById('aetherDash');
  function aethIsLight() { return dashEl && dashEl.classList.contains('ab-light'); }
  function abCss(name) {
    if (!dashEl) return '';
    return getComputedStyle(dashEl).getPropertyValue(name).trim();
  }
  function aethHexToRgb(hex) {
    var h = (hex || '').replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    if (isNaN(n)) return '204,255,0';
    return ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255);
  }
  function aethChartColors() {
    return {
      tick: abCss('--ab-muted-fg') || '#737373',
      grid: aethIsLight() ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.05)',
      gridSoft: aethIsLight() ? 'rgba(0,0,0,0.08)' : 'rgba(255,255,255,0.07)',
      cross: aethIsLight() ? 'rgba(0,0,0,0.3)' : 'rgba(255,255,255,0.25)',
      tooltipBg: aethIsLight() ? '#ffffff' : '#0a0a0a',
      tooltipBorder: aethIsLight() ? 'rgba(0,0,0,0.1)' : 'rgba(255,255,255,0.1)',
      tooltipTitle: aethIsLight() ? '#050505' : '#ffffff',
      tooltipBody: aethIsLight() ? '#1a1a1a' : '#f0f0f0',
      barTrack: aethIsLight() ? 'rgba(0,0,0,0.08)' : 'rgba(255,255,255,0.05)'
    };
  }
  function aethInitTheme() {
    if (!dashEl) return;
    dashEl.classList.add('ab-light');
  }

  /* Progressive "draw from left" reveal — approximates recharts' mount animation */
  var lineDrawPlugin = {
    id: 'aethLineDraw',
    beforeDatasetsDraw: function(chart) {
      if (chart.config.type !== 'line') return;
      if (chart.$aethProg === undefined && !chart.$aethRevealed) chart.$aethProg = 0;
      if (chart.$aethRevealed) return;
      var p = chart.$aethProg;
      if (p === undefined || p >= 1) { chart.$aethRevealed = true; return; }
      var a = chart.chartArea, ctx = chart.ctx;
      var x = a.left + p * (a.right - a.left);
      ctx.save();
      ctx.beginPath();
      ctx.rect(a.left, a.top, Math.max(0, x - a.left), a.bottom - a.top);
      ctx.clip();
      chart.$aethClipped = true;
    },
    afterDatasetsDraw: function(chart) {
      if (chart.$aethClipped) {
        chart.ctx.restore();
        chart.$aethClipped = false;
      }
    }
  };
  function animOpts() {
    return {
      duration: 1500,
      easing: 'easeOutQuart',
      onProgress: function(a) {
        a.chart.$aethProg = a.currentStep / a.numSteps;
        if (a.chart.$aethProg >= 1) { a.chart.$aethProg = 1; a.chart.$aethClipped = false; a.chart.$aethRevealed = true; }
      },
      onComplete: function(a) { a.chart.$aethProg = 1; a.chart.$aethClipped = false; a.chart.$aethRevealed = true; }
    };
  }
  /* Vertical guide line (crosshair) that follows the hovered data point */
  var budgetCrosshairPlugin = {
    id: 'aethBudgetCrosshair',
    beforeDatasetsDraw: function(chart, args, options) {
      var active = chart.getActiveElements();
      if (!active.length) return;
      var x = chart.scales.x.getPixelForValue(active[0].index);
      var a = chart.chartArea;
      var ctx = chart.ctx;
      ctx.save();
      ctx.strokeStyle = aethChartColors().cross;
      ctx.lineWidth = 1;
      ctx.setLineDash([]);
      ctx.beginPath();
      ctx.moveTo(x + 0.5, a.top);
      ctx.lineTo(x + 0.5, a.bottom);
      ctx.stroke();
      ctx.restore();
    }
  };
  function kill(prefix) {
    for (var k in charts) { if (charts.hasOwnProperty(k) && k.indexOf(prefix) === 0 && charts[k]) { charts[k].destroy(); delete charts[k]; } }
  }
  function tooltipStyle() {
    var c = aethChartColors();
    return { backgroundColor: c.tooltipBg, borderColor: c.tooltipBorder, borderWidth: 1, titleColor: c.tooltipTitle, bodyColor: c.tooltipBody, titleFont: { family: 'Inter', size: 11 }, bodyFont: { family: 'Inter', size: 12, weight: '700' }, displayColors: false, padding: 10 };
  }
  function baseOpts() {
    return { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: tooltipStyle() } };
  }

  function initSpark(id, data) {
    var ctx = document.getElementById(id);
    if (!ctx) return;
    var lime = '#18181b';
    var rgb = aethHexToRgb(lime);
    charts[id] = new Chart(ctx, {
      type: 'line',
      data: { labels: data.map(function(_, i) { return i; }), datasets: [{
        data: data, borderColor: lime, borderWidth: 2, pointRadius: 0, fill: true, tension: 0.4,
        backgroundColor: function(c) {
          var ca = c.chart.chartArea;
          if (!ca) return 'rgba(' + rgb + ',0.15)';
          var g = c.chart.ctx.createLinearGradient(0, ca.top, 0, ca.bottom);
          g.addColorStop(0, 'rgba(' + rgb + ',0.35)'); g.addColorStop(1, 'rgba(' + rgb + ',0)');
          return g;
        }
      }]},
      plugins: [lineDrawPlugin],
      options: Object.assign(baseOpts(), { animation: animOpts(), scales: { x: { display: false }, y: { display: false } }, plugins: { legend: { display: false }, tooltip: { enabled: false } } })
    });
  }

  function aethGenYear(year) {
    var out = [], months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var base = Math.abs((year || 2026) - 2021) % 5;
    for (var i = 0; i < 12; i++) {
      out.push({ d: months[i], cases: 250 + base * 260 + i * 65 + (i % 3) * 25, resTime: Math.round((12.5 - base * 1.1 - i * 0.6) * 10) / 10 });
    }
    return out;
  }
  window.renderEngineChart = function(period) {
    var data, pct = document.getElementById('abEnginePerfSub');
    if (period === 'year') {
      var yearSel = document.getElementById('abRangeYear');
      var year = yearSel ? parseInt(yearSel.value, 10) : new Date().getFullYear();
      data = (year === ENGINE_REAL_YEAR) ? ENGINE_REAL_DATA : aethGenYear(year);
      if (pct) pct.textContent = year;
    } else {
      data = ENGINE30;
      if (pct) pct.textContent = 'Last 30 days';
    }
    if (!charts['abEnginePerf']) return;
    charts['abEnginePerf'].data.labels = data.map(function(p) { return p.d; });
    charts['abEnginePerf'].data.datasets[0].data = data.map(function(p) { return p.resTime; });
    charts['abEnginePerf'].data.datasets[1].data = data.map(function(p) { return p.cases; });
    charts['abEnginePerf'].update();
  }
  window.aethRange = function(mode, btn) {
    var wrap = btn.closest('.ab-range');
    if (wrap) wrap.querySelectorAll('.ab-range-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    var yearWrap = document.getElementById('abRangeYearWrap');
    if (yearWrap) yearWrap.style.display = mode === 'year' ? '' : 'none';
    renderEngineChart(mode);
  };
  function initEngine() {
    kill('abSpark'); kill('abEngine');
    var pf = document.getElementById('abEnginePerf');
    if (pf) {
      charts['abEnginePerf'] = new Chart(pf, {
        type: 'line',
        data: {
          labels: ENGINE30.map(function(p) { return p.d; }),
          datasets: [
            { label: 'Resolution Time', data: ENGINE30.map(function(p) { return p.resTime; }), yAxisID: 'res', borderColor: '#737373', borderWidth: 1, borderDash: [4,4], pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#737373', pointHoverBorderColor: '#737373', pointHoverBorderWidth: 2, tension: 0.35, fill: false },
            { label: 'Cases Filed', data: ENGINE30.map(function(p) { return p.cases; }), yAxisID: 'cases', borderColor: '#18181b', borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#18181b', pointHoverBorderColor: '#18181b', pointHoverBorderWidth: 2, tension: 0.35, fill: true,
              backgroundColor: function(c){ var rgb='24,24,27'; var ca=c.chart.chartArea; if(!ca) return 'rgba('+rgb+',0.18)'; var g=c.chart.ctx.createLinearGradient(0,ca.top,0,ca.bottom); g.addColorStop(0,'rgba('+rgb+',0.3)'); g.addColorStop(1,'rgba('+rgb+',0)'); return g; } }
          ]
        },
        options: Object.assign(baseOpts(), {
          animation: animOpts(),
          interaction: { mode: 'index', intersect: false },
          scales: {
            cases: { type: 'linear', position: 'left', beginAtZero: true, grid: { color: aethChartColors().grid }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 } } },
            res: { type: 'linear', position: 'right', beginAtZero: true, grid: { display: false }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 }, callback: function(v) { return v + 'd'; } } }
          }
        }),
        plugins: [lineDrawPlugin, budgetCrosshairPlugin]
      });
    }
    var rd = document.getElementById('abEngineRadar');
    if (rd) {
      charts['abEngineRadar'] = new Chart(rd, {
        type: 'radar',
        data: { labels: RADAR_LABELS, datasets: [{ data: RADAR_DATA, borderColor: '#18181b', backgroundColor: 'rgba(24,24,27,0.18)', borderWidth: 2, pointBackgroundColor: '#18181b', pointRadius: 3, pointHoverRadius: 5, hitRadius: 60 }] },
        options: Object.assign(baseOpts(), { plugins: { legend: { display: false }, tooltip: tooltipStyle() }, interaction: { mode: 'nearest', intersect: false }, scales: { r: { beginAtZero: true, suggestedMax: Math.max(1, Math.max.apply(null, RADAR_DATA)) * 1.2, grid: { color: aethChartColors().gridSoft }, angleLines: { color: aethChartColors().gridSoft }, pointLabels: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 } }, ticks: { display: false } } } }),
        plugins: []
      });
    }
  }
  window.aethSetRadarBrgy = function(id) {
    var chart = charts['abEngineRadar'];
    var sel = document.getElementById('abRadarBrgy');
    var sub = document.getElementById('abRadarSub');
    if (!chart) return;
    var item = null;
    for (var i = 0; i < RADAR_BRGY.length; i++) {
      if (String(RADAR_BRGY[i].id) === String(id)) { item = RADAR_BRGY[i]; break; }
    }
    if (!item) return;
    var data = item.data || RADAR_DATA;
    chart.data.datasets[0].data = data;
    var mx = Math.max(1, Math.max.apply(null, data)) * 1.2;
    chart.options.scales.r.suggestedMax = mx;
    chart.update();
    if (sub) sub.textContent = item.name;
  };

  function initIntel() {
    kill('abIntel');
    var age = document.getElementById('abIntelAge');
    if (age) {
      charts['abIntelAge'] = new Chart(age, {
        type: 'doughnut',
        data: { labels: APPT_TYPES_LABELS, datasets: [{ data: APPT_TYPES_COUNTS, backgroundColor: APPT_TYPES_COLORS, borderWidth: 0, hoverOffset: 4 }] },
        options: Object.assign(baseOpts(), { cutout: '58%', plugins: { legend: { display: false }, tooltip: tooltipStyle() } })
      });
    }
    var loc = document.getElementById('abIntelLoc');
    if (loc) {
      charts['abIntelLoc'] = new Chart(loc, {
        type: 'bar',
        data: { labels: APPT_LOCS.map(function(l) { return l[0]; }), datasets: [{ data: APPT_LOCS.map(function(l) { return l[1]; }), backgroundColor: '#18181b', borderRadius: 0, barThickness: 14 }] },
        options: Object.assign(baseOpts(), {
          indexAxis: 'y',
          scales: { x: { beginAtZero: true, grid: { color: aethChartColors().grid }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 }, precision: 0 } }, y: { grid: { display: false }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 11 } } } }
        })
      });
    }
  }
  window.aethSetApptLoc = function(key) {
    var chart = charts['abIntelLoc'];
    if (!chart) return;
    var list = (APPT_LOCS_BY_TYPE && APPT_LOCS_BY_TYPE[key]) ? APPT_LOCS_BY_TYPE[key] : APPT_LOCS;
    chart.data.labels = list.map(function(l) { return l[0]; });
    chart.data.datasets[0].data = list.map(function(l) { return l[1]; });
    chart.update();
  };

  function initBudget() {
    kill('abBudget');
    var bc = document.getElementById('abBudgetChart');
    if (!bc) return;
    var labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var target = [28, 28, 30, 30, 32, 32, 33, 33, 35, 35, 36, 36];
    var actual = [22, 25, 24, 27, 29, 28, 30, 32, 31, 34, 33, 35];
    charts['abBudgetChart'] = new Chart(bc, {
      type: 'line',
      data: { labels: labels, datasets: [
        { label: 'Target', data: target, borderColor: '#737373', borderWidth: 1, borderDash: [4,4], pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#737373', pointHoverBorderColor: '#737373', pointHoverBorderWidth: 2, tension: 0.35, fill: false },
        { label: 'Actual Adoptions', data: actual, borderColor: '#18181b', borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#18181b', pointHoverBorderColor: '#18181b', pointHoverBorderWidth: 2, tension: 0.35, fill: true, backgroundColor: function(c){ var rgb='24,24,27'; var ca=c.chart.chartArea; if(!ca) return 'rgba('+rgb+',0.18)'; var g=c.chart.ctx.createLinearGradient(0,ca.top,0,ca.bottom); g.addColorStop(0,'rgba('+rgb+',0.3)'); g.addColorStop(1,'rgba('+rgb+',0)'); return g; } }
      ]},
      options: Object.assign(baseOpts(), {
        animation: animOpts(),
        interaction: { mode: 'index', intersect: false },
        onHover: function(e, active) { if (!active.length) e.native.target.style.cursor = 'default'; else e.native.target.style.cursor = 'pointer'; },
        scales: { y: { beginAtZero: true, grid: { color: aethChartColors().grid }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 }, precision: 0 } }, x: { grid: { display: false }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 } } } },
        plugins: { legend: { display: false }, tooltip: Object.assign(tooltipStyle(), {
          intersect: false,
          mode: 'index',
          callbacks: {
            label: function(ctx) {
              return ctx.dataset.label + ': ' + Math.round(ctx.parsed.y).toLocaleString();
            }
          }
        }) }
      }),
      plugins: [lineDrawPlugin, budgetCrosshairPlugin]
    });
  }

  window.aethGo = function(page) {
    document.querySelectorAll('.ab-page').forEach(function(p) { p.classList.remove('active'); });
    var el = document.getElementById('ab-page-' + page);
    if (el) el.classList.add('active');
    document.querySelectorAll('.ab-page-btn[data-aethpage]').forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-aethpage') === page); });
    if (page === 'engine') { initEngine(); initSparkAll(); aethRunCountUps(); }
    if (page === 'intel') { initIntel(); }
    if (page === 'budget') { initBudget(); }
    if (page === 'architect') { aethPlayGantt(); }
    window.scrollTo({ top: 0 });
  };
  /* Re-trigger the Campaign Timeline row + bar animations when the page opens */
  function aethPlayGantt() {
    var rows = document.querySelectorAll('.ab-gantt-row');
    rows.forEach(function(row) {
      var bar = row.querySelector('.ab-gantt-bar');
      var d = row.style.animationDelay || '0s';
      var bd = bar ? (bar.style.animationDelay || '0.2s') : '0.2s';
      row.style.animation = 'none';
      if (bar) bar.style.animation = 'none';
      void row.offsetWidth;
      row.style.animation = '';
      row.style.animationDelay = d;
      if (bar) { bar.style.animation = ''; bar.style.animationDelay = bd; }
    });
  }
  function initSparkAll() {
    kill('abSpark');
    var data = (typeof KPI_TRENDS !== 'undefined' && KPI_TRENDS.length)
      ? KPI_TRENDS
      : [[0,0,0,0,0,0,0,0,0,0,0,0],[0,0,0,0,0,0,0,0,0,0,0,0],[0,0,0,0,0,0,0,0,0,0,0,0],[0,0,0,0,0,0,0,0,0,0,0,0]];
    data.forEach(function(d, i) { initSpark('abSpark-' + i, d); });
  }
  // KPI count-up (2000ms, easeOutQuart) — full numbers with commas, decimals kept
  function aethCompact(n) {
    if (Number.isInteger(n)) return n.toLocaleString('en-US');
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  }
  function aethRunCountUps() {
    document.querySelectorAll('.ab-glance-val[data-count]').forEach(function(el) {
      var end = parseFloat(el.getAttribute('data-count')) || 0;
      var suffix = el.getAttribute('data-suffix') || '';
      var duration = 2000, startTime = null;
      el.textContent = '0' + suffix;
      function frame(t) {
        if (startTime === null) startTime = t;
        var p = Math.min((t - startTime) / duration, 1);
        var eased = 1 - Math.pow(1 - p, 4);
        el.textContent = aethCompact(end * eased) + suffix;
        if (p < 1) requestAnimationFrame(frame);
      }
      requestAnimationFrame(frame);
    });
  }
  window.aethRefresh = function() { location.reload(); };

  /* Modals */
  window.aethOpenModal = function(id) { document.getElementById(id).classList.add('open'); };
  window.aethCloseModal = function(id) { document.getElementById(id).classList.remove('open'); };
  window.aethSetAmount = function(v, btn) {
    document.getElementById('abAddAmount').value = v;
    document.getElementById('abAddFundsLabel').textContent = '$' + v.toLocaleString();
    document.querySelectorAll('#abAddFundsModal .ab-preset').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
  };

  /* Architect */
  window.aethFilterCampaigns = function(status) {
    document.querySelectorAll('#abCampGrid .ab-camp-card').forEach(function(card) {
      var camp = CAMPAIGNS.find(function(c) { return c.id === card.getAttribute('data-camp'); });
      card.style.display = (!camp || status === 'all' || camp.status === status) ? '' : 'none';
    });
  };
  window.aethToggleStatus = function(id) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === id; });
    if (!camp) return;
    camp.status = camp.status === 'live' ? 'paused' : 'live';
    location.reload();
  };
  function formatDate(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }
  window.aethShowCampaign = function(el) {
    var id = el.getAttribute('data-camp');
    var camp = CAMPAIGNS.find(function(c) { return c.id === id; });
    if (!camp) return;
    var body = document.getElementById('abCampModalBody');
    body.innerHTML =
      '<div class="sd-field-group">' +
        '<div class="sd-field">' +
          '<div style="display:flex;align-items:center;gap:0.6rem;"><span class="ab-status-dot ' + camp.status + '"></span><span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--ab-muted-fg);">' + camp.status + '</span></div>' +
          '<h3 style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:18px;font-weight:600;letter-spacing:-0.025em;color:#18181b;margin:0;">' + camp.name + '</h3>' +
          '<p style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:400;color:#71717a;margin:0;">' + camp.channel + '</p>' +
          '<p style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin:0;">Dogs: ' + (camp.dogs || 0) + ' &middot; Cats: ' + (camp.cats || 0) + '</p>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
          '<div class="sd-field"><span class="sd-label">Target Reach</span><span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;color:#18181b;">' + (camp.target || 0) + ' animals</span></div>' +
          '<div class="sd-field"><span class="sd-label">Progress</span><span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;color:#18181b;">' + camp.progress + '% of target reached</span></div>' +
          '<div class="sd-field"><span class="sd-label">Start Date</span><span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;color:#18181b;">' + formatDate(camp.start) + '</span></div>' +
          '<div class="sd-field"><span class="sd-label">End Date</span><span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;color:#18181b;">' + formatDate(camp.end) + '</span></div>' +
        '</div>' +
        '<div class="sd-field">' +
          '<div style="display:flex;justify-content:space-between;font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin-bottom:0.35rem;"><span>Progress</span><span>' + camp.progress + '% of target reached</span></div>' +
          '<div class="ab-progress"><div class="ab-progress-fill" style="width:' + camp.progress + '%;background:#0E8131;"></div></div>' +
        '</div>' +
      '</div>' +
      '<div class="sd-dialog-footer" style="padding-top:1.25rem;border-top:1px solid #e4e4e7;margin-top:0.5rem;">' +
        '<button class="sd-btn sd-btn-outline sd-btn-green-text" style="flex:1;">Edit Program</button>' +
        '<button class="sd-btn sd-btn-outline" style="flex:1;">Log Progress</button>' +
        '<button class="sd-btn sd-btn-outline" style="flex:1;color:#dc2626;border-color:#fecaca;">Cancel Program</button>' +
      '</div>';
    aethOpenModal('abCampModal');
  };

  /* Init dates for new campaign modal */
  function localDate(offsetDays) {
    var d = new Date();
    d.setDate(d.getDate() + (offsetDays || 0));
    var m = String(d.getMonth() + 1).padStart(2, '0'), da = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + da;
  }
  document.getElementById('abNewStart').value = localDate(0);
  document.getElementById('abNewEnd').value = localDate(30);

  /* Init */
  aethInitTheme();
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') { initSparkAll(); aethRunCountUps(); }
  });
  window.addEventListener('hashchange', function() {
    if (window.location.hash.replace('#', '') === 'analytics' && typeof Chart !== 'undefined') {
      setTimeout(function() {
        var activePage = document.querySelector('.ab-page.active');
        if (!activePage) return;
        var id = activePage.id.replace('ab-page-', '');
        window.aethGo(id);
      }, 80);
    }
  });
})();
</script>
</div>