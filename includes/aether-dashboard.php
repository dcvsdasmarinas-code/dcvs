<?php
/* ─────────────────────────────────────────────────────────────
   DCVS Analytics Dashboard
   Faithful PHP reproduction of the Next.js template dashboard
   (Engine / Intel / Budget / Architect / Settings)
   Mock data for the standalone Aether dashboard.
   ───────────────────────────────────────────────────────────── */
if (!defined('DCVS_AETHER_INCLUDED')) {
    define('DCVS_AETHER_INCLUDED', true);
    require_once __DIR__ . '/waitlist.php';

    $aeth_programSuccess = $_SESSION['program_success'] ?? null;
    $aeth_programError   = $_SESSION['program_error'] ?? null;
    $aeth_programCreated = !empty($_SESSION['program_created']);
    unset($_SESSION['program_success'], $_SESSION['program_error']);
    unset($_SESSION['program_created']);

    function aeth_timeAgo($ts) {
        if (!$ts) return 'just now';
        $diff = time() - strtotime($ts);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
        return floor($diff / 86400) . ' days ago';
    }

    // ── Placeholder Programs (always show as seed data) ──
    $aeth_placeholderCampaigns = [];

    // ── Load Programs from Database ──
    $aeth_dbCampaigns = [];
    if (isset($conn)) {
        // Progress history per program (for View Progress dialog)
        $aeth_progLogsMap = [];
        $plQ = $conn->query("SELECT pp.program_id, pp.log_date, pp.dogs_served, pp.cats_served, pp.notes, pp.created_at, COALESCE(NULLIF(u.full_name, ''), CONCAT('User #', pp.logged_by)) AS logger FROM program_progress pp LEFT JOIN users u ON u.user_id = pp.logged_by ORDER BY pp.log_date ASC, pp.created_at ASC");
        if ($plQ) {
            while ($pl = $plQ->fetch_assoc()) {
                $aeth_progLogsMap[(int)$pl['program_id']][] = $pl;
            }
        }
        $progQ = $conn->query("SELECT p.*, locations.brgy_ids, locations.brgy_names, COALESCE(progress.dogs_completed, 0) AS dogs_completed, COALESCE(progress.cats_completed, 0) AS cats_completed FROM programs p LEFT JOIN (SELECT pb.program_id, GROUP_CONCAT(pb.barangay_id ORDER BY pb.barangay_id) AS brgy_ids, GROUP_CONCAT(b.barangay_name ORDER BY pb.barangay_id SEPARATOR ', ') AS brgy_names FROM program_barangays pb LEFT JOIN barangays b ON pb.barangay_id = b.barangay_id GROUP BY pb.program_id) locations ON locations.program_id = p.program_id LEFT JOIN (SELECT program_id, SUM(dogs_served) AS dogs_completed, SUM(cats_served) AS cats_completed FROM program_progress GROUP BY program_id) progress ON progress.program_id = p.program_id WHERE p.status <> 'archived' ORDER BY FIELD(p.status, 'live', 'scheduled', 'paused', 'completed', 'ended', 'cancelled'), p.start_date DESC");
        if ($progQ) {
            while ($row = $progQ->fetch_assoc()) {
                $brgyNames = trim((string)$row['brgy_names']);
                $brgyIds = trim((string)$row['brgy_ids']);
                if ($brgyNames !== '') {
                    $channel = ($row['location_mode'] === 'multiple') ? $brgyNames : 'Barangay ' . $brgyNames;
                } else {
                    $channel = $row['venue'] ?: 'TBD';
                }

                // Progress based on actual completion data
                $dogsCompleted = (int)($row['dogs_completed'] ?? 0);
                $catsCompleted = (int)($row['cats_completed'] ?? 0);
                $totalCompleted = $dogsCompleted + $catsCompleted;
                $targetReach = (int)($row['target_reach'] ?? 0);

                // Reconcile completion against unduplicated progress before treating it as final.
                $dbStatus = $row['status'];
                $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
                $completedBelowTarget = $dbStatus === 'completed' && $targetReach > 0 && $totalCompleted < $targetReach;
                if ($dbStatus !== 'cancelled' && $dbStatus !== 'paused' && $dbStatus !== 'archived' &&
                    ($dbStatus !== 'completed' || $completedBelowTarget)) {
                  if ($targetReach > 0 && $totalCompleted >= $targetReach) {
                    $dbStatus = 'completed';
                  } elseif ($today < $row['start_date']) {
                        $dbStatus = 'scheduled';
                    } elseif ($today >= $row['start_date'] && $today <= $row['end_date']) {
                        $dbStatus = 'live';
                    } elseif ($today > $row['end_date']) {
                        // A positive target must be met before a past program can be completed.
                        $dbStatus = ($targetReach <= 0 || $totalCompleted >= $targetReach) ? 'completed' : 'ended';
                    }
                    // Update DB if status changed
                    if ($dbStatus !== $row['status']) {
                        $statusAllowed = true;
                        $wasActive = in_array($row['status'], ['scheduled', 'live'], true);
                        $willBeActive = in_array($dbStatus, ['scheduled', 'live'], true);
                        if ($wasActive !== $willBeActive) {
                            try {
                                $capacityConflicts = dcvsProgramCapacityTransitionConflicts(
                                    $conn,
                                    (int)$row['program_id'],
                                    $row['start_date'],
                                    $row['end_date'],
                                    $willBeActive
                                );
                                if ($capacityConflicts) {
                                    $statusAllowed = false;
                                    $dbStatus = $row['status'];
                                    error_log('Program status change blocked by appointment capacity conflicts for program ' . (int)$row['program_id']);
                                }
                            } catch (Throwable $error) {
                                $statusAllowed = false;
                                $dbStatus = $row['status'];
                                error_log('Could not validate program status capacity transition for program ' . (int)$row['program_id'] . ': ' . $error->getMessage());
                            }
                        }
                        if ($statusAllowed && !$conn->query("UPDATE programs SET status = '" . $conn->real_escape_string($dbStatus) . "' WHERE program_id = " . (int)$row['program_id'])) {
                            error_log('Failed to update program ' . (int)$row['program_id'] . ' status to ' . $dbStatus . ': ' . $conn->error);
                        } elseif ($statusAllowed && $wasActive && !$willBeActive) {
                            dcvsNotifyWaitlistForDateRange($conn, $row['start_date'], $row['end_date']);
                        }
                    }
                }

                // Progress reflects actual reached count — never forced to 100%
                if ($targetReach > 0) {
                    $progress = min(100, (int)round($totalCompleted / $targetReach * 100));
                } else {
                    $progress = 0;
                }

                $aeth_dbCampaigns[] = [
                    'id'         => 'prog-' . str_pad($row['program_id'], 3, '0', STR_PAD_LEFT),
                    'program_id' => (int)$row['program_id'],
                    'name'       => $row['program_name'],
                    'status'     => $dbStatus,
                    'spend'      => 0,
                    'impressions'=> 0,
                    'channel'    => $channel,
                    'location'   => $channel,
                    'last'       => aeth_timeAgo($row['updated_at'] ?? $row['created_at']),
                    'start'      => $row['start_date'],
                    'end'        => $row['end_date'],
                    'progress'   => $progress,
                    'budget'     => 0,
                    'target'     => $targetReach,
                    'dogs'       => (int)($row['dogs_target'] ?? 0),
                    'cats'       => (int)($row['cats_target'] ?? 0),
                    'completed'  => $totalCompleted,
                    'program_type'   => $row['program_type'],
                    'description'    => $row['description'] ?? '',
                    'operating_time' => $row['operating_time'] ?? '',
                    'program_start_time' => $row['program_start_time'] ?? '',
                    'program_end_time' => $row['program_end_time'] ?? '',
                    'location_mode'  => $row['location_mode'],
                    'venue'          => $row['venue'] ?? '',
                    'barangay_ids'   => $brgyIds,
                    'barangay_names' => $brgyNames,
                    'progress_logs'  => $aeth_progLogsMap[(int)$row['program_id']] ?? [],
                ];
            }
        }
    }

    // ── Merge: DB programs first, then placeholders ──
    $aeth_campaigns = array_merge($aeth_dbCampaigns, $aeth_placeholderCampaigns);
    // ── Programs loaded from database above ──
    $aeth_allBarangays = [];
    if (isset($conn)) {
        $brgyQ = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
        if ($brgyQ) { while ($br = $brgyQ->fetch_assoc()) $aeth_allBarangays[] = $br; }
    }
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
    $aeth_txns = [];
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

    // Gantt timeline range — dynamic based on program dates
    $aeth_allDates = [];
    foreach ($aeth_campaigns as $c) {
        if (!empty($c['start'])) $aeth_allDates[] = strtotime($c['start']);
        if (!empty($c['end']))   $aeth_allDates[] = strtotime($c['end']);
    }
    if (!empty($aeth_allDates)) {
        $earliest = min($aeth_allDates);
        $latest   = max($aeth_allDates);
        // Pad 7 days before and after
        $aeth_gStart = date('Y-m-d', strtotime('-7 days', $earliest));
        $aeth_gEnd   = date('Y-m-d', strtotime('+7 days', $latest));
    } else {
        // Fallback: current month ± 2 months
        $aeth_gStart = date('Y-m-d', strtotime('first day of -2 months'));
        $aeth_gEnd   = date('Y-m-d', strtotime('last day of +2 months'));
    }
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

    // 1) Total cases by incident date, regardless of current or archived status.
    $aeth_kpiCases30 = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE incident_at >= NOW() - INTERVAL 30 DAY")->fetch_row()[0] ?? 0);
    $aeth_kpiCasesPrev = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE incident_at >= NOW() - INTERVAL 60 DAY AND incident_at < NOW() - INTERVAL 30 DAY")->fetch_row()[0] ?? 0);
    $aeth_kpiCasesChg = $aeth_kpiCasesPrev > 0 ? round(($aeth_kpiCases30 - $aeth_kpiCasesPrev) / $aeth_kpiCasesPrev * 100, 1) : 0;
    $aeth_kpiCasesTrend = [];
    $__r = $conn->query("SELECT DATE_FORMAT(incident_at,'%Y-%m') m, COUNT(*) c FROM reports WHERE incident_at IS NOT NULL GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = $__row['c']; $aeth_kpiCasesTrend = aeth_fillSeries($__map); }

    // 2) Avg. resolution time from incident date to resolution, including archived cases.
    $aeth_kpiResAvg = 0;
    $__r = $conn->query("SELECT AVG(TIMESTAMPDIFF(SECOND, r.incident_at, cc.resolved_at) / 86400) a FROM confirmed_cases cc JOIN reports r ON r.report_id = cc.report_id WHERE cc.status='resolved' AND cc.resolved_at IS NOT NULL AND r.incident_at IS NOT NULL AND cc.resolved_at >= r.incident_at");
    if ($__r && $__row = $__r->fetch_assoc()) $aeth_kpiResAvg = round((float)$__row['a'], 1);
    $aeth_kpiResTrend = [];
    $__r = $conn->query("SELECT DATE_FORMAT(r.incident_at,'%Y-%m') m, AVG(TIMESTAMPDIFF(SECOND, r.incident_at, cc.resolved_at) / 86400) a FROM confirmed_cases cc JOIN reports r ON r.report_id = cc.report_id WHERE cc.status='resolved' AND cc.resolved_at IS NOT NULL AND r.incident_at IS NOT NULL AND cc.resolved_at >= r.incident_at GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = round((float)$__row['a'], 1); $aeth_kpiResTrend = aeth_fillSeries($__map); }

    // 3) Report Verification Rate (% verified/legit of total), including resolved and archived reports.
    $aeth_kpiVerTot = (int)($conn->query("SELECT COUNT(*) FROM reports")->fetch_row()[0] ?? 0);
    $aeth_kpiVerDone = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE status IN ('validated','confirmed','scheduled','resolved')")->fetch_row()[0] ?? 0);
    $aeth_kpiVerRate = $aeth_kpiVerTot > 0 ? round($aeth_kpiVerDone / $aeth_kpiVerTot * 100, 1) : 0;
    $aeth_kpiVerTrend = [];
    $__r = $conn->query("SELECT m, SUM(t) t, SUM(v) v FROM (SELECT DATE_FORMAT(incident_at,'%Y-%m') m, 1 t, IF(status IN ('validated','confirmed','scheduled','resolved'),1,0) v FROM reports WHERE incident_at IS NOT NULL) x GROUP BY m");
    if ($__r) { $__map = []; while ($__row = $__r->fetch_assoc()) $__map[$__row['m']] = (int)$__row['t'] > 0 ? round((int)$__row['v'] / (int)$__row['t'] * 100, 1) : 0; $aeth_kpiVerTrend = aeth_fillSeries($__map); }

    // 4) Current pending report count and monthly trend by incident date.
    $aeth_kpiPending = (int)($conn->query("SELECT COUNT(*) FROM reports WHERE status IN ('pending','validated')")->fetch_row()[0] ?? 0);
    $__pendingMap = [];
    $__r = $conn->query("SELECT DATE_FORMAT(incident_at,'%Y-%m') m, COUNT(*) c FROM reports WHERE status IN ('pending','validated') AND incident_at IS NOT NULL GROUP BY m");
    if ($__r) { while ($__row = $__r->fetch_assoc()) $__pendingMap[$__row['m']] = (int)$__row['c']; }
    $aeth_kpiPendingTrend = aeth_fillSeries($__pendingMap);

    $aeth_glanceCards = [
      ['Reports by Incident Date (30d)', $aeth_kpiCases30, '', $aeth_kpiCasesChg, $aeth_kpiCasesTrend],
      ['Avg. Resolution Time', $aeth_kpiResAvg, 'd', aeth_pctChg($aeth_kpiResTrend), $aeth_kpiResTrend],
      ['Report Verification Rate', $aeth_kpiVerRate, '%', aeth_pctChg($aeth_kpiVerTrend), $aeth_kpiVerTrend],
      ['Pending Reports', $aeth_kpiPending, '', aeth_pctChg($aeth_kpiPendingTrend), $aeth_kpiPendingTrend],
    ];
    $aeth_glanceTrends = array_map(function($card) { return $card[4]; }, $aeth_glanceCards);

    // ── Priority Reports (validated reports awaiting staff confirmation) ──
    $aeth_reportTypeLabel = ['stray' => 'Stray Animal Report', 'rabies' => 'Rabies Case Report', 'injured' => 'Injured Animal Report', 'dead' => 'Dead Animal Report'];
    $aeth_urgencyOrder = ['high' => 0, 'critical' => 0, 'medium' => 1, 'low' => 2];
    $aeth_urgencyNote = [
        'high'    => 'Needs immediate attention',
        'critical'=> 'Needs immediate attention',
        'medium'  => 'Needs staff confirmation',
        'low'     => 'For staff review',
    ];

    $aeth_priorityReports = [];
    $aeth_highPriorityCount = 0;
    $__r = $conn->query("SELECT r.report_id, r.reference_id, r.report_type, r.urgency_level, r.status, r.created_at, r.validated_at, b.barangay_name, u.full_name AS reporter_name FROM reports r LEFT JOIN users u ON r.reporter_id = u.user_id LEFT JOIN barangays b ON r.barangay_id = b.barangay_id WHERE r.status = 'validated' AND r.archived_at IS NULL ORDER BY FIELD(r.urgency_level, 'critical','high','medium','low'), r.validated_at DESC");
    if ($__r) {
        while ($__row = $__r->fetch_assoc()) {
            $urgency = strtolower($__row['urgency_level'] ?: 'medium');
            $isHigh = ($urgency === 'high' || $urgency === 'critical');
            if ($isHigh) $aeth_highPriorityCount++;
            $aeth_priorityReports[] = [
                'id'        => (int)$__row['report_id'],
                'ref'       => $__row['reference_id'] ?? '',
                'type'      => $aeth_reportTypeLabel[$__row['report_type']] ?? ucfirst($__row['report_type']),
                'reportType'=> $__row['report_type'],
                'urgency'   => $urgency,
                'barangay'  => trim((string)$__row['barangay_name']) !== '' ? $__row['barangay_name'] : 'City-wide',
                'reporter'  => trim((string)$__row['reporter_name']) !== '' ? $__row['reporter_name'] : 'Unknown',
                'timeAgo'   => aeth_timeAgo($__row['validated_at'] ?: $__row['created_at']),
                'note'      => $aeth_urgencyNote[$urgency] ?? 'For staff review',
            ];
        }
    }
    $aeth_priorityTotal = count($aeth_priorityReports);

    // ── Engine: daily filed reports and resolution time over the last 30 days ──
    $aeth_engine30 = [];
    $__dailyMap = [];
    $__chartStart = date('Y-m-d', strtotime('-29 days'));
    $__r = $conn->query("SELECT DATE(COALESCE(r.incident_at, r.created_at)) d, COUNT(DISTINCT r.report_id) cases, AVG(CASE WHEN cc.resolved_at IS NOT NULL AND cc.resolved_at >= r.created_at THEN TIMESTAMPDIFF(SECOND, r.created_at, cc.resolved_at) / 86400 END) resTime FROM reports r LEFT JOIN confirmed_cases cc ON cc.report_id = r.report_id WHERE COALESCE(r.incident_at, r.created_at) >= '" . $conn->real_escape_string($__chartStart) . "' AND COALESCE(r.incident_at, r.created_at) < DATE_ADD(CURDATE(), INTERVAL 1 DAY) GROUP BY DATE(COALESCE(r.incident_at, r.created_at))");
    if ($__r) { while ($__row = $__r->fetch_assoc()) $__dailyMap[$__row['d']] = $__row; }
    for ($i = 29; $i >= 0; $i--) {
      $__date = date('Y-m-d', strtotime('-' . $i . ' days'));
      $__day = $__dailyMap[$__date] ?? [];
        $aeth_engine30[] = [
        'd' => date('M d', strtotime($__date)),
        'cases' => (int)($__day['cases'] ?? 0),
        'resTime' => isset($__day['resTime']) ? round((float)$__day['resTime'], 1) : 0,
        ];
    }

    // ── Engine: actual report type counts by barangay ──
    $aeth_radarLabels = ['Stray Animal', 'Rabies Report', 'Injured Animal', 'Dead Animal'];
    $aeth_radarTypeKeys = ['stray', 'rabies', 'injured', 'dead'];
    $__brgyList = [];
    $__r = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
    if ($__r) { while ($__row = $__r->fetch_assoc()) $__brgyList[] = ['id' => (int)$__row['barangay_id'], 'name' => $__row['barangay_name']]; }
    $__radarByBarangay = [0 => array_fill(0, count($aeth_radarTypeKeys), 0)];
    $__r = $conn->query("SELECT COALESCE(barangay_id, 0) AS barangay_id, report_type, COUNT(*) AS total FROM reports GROUP BY barangay_id, report_type");
    if ($__r) {
      while ($__row = $__r->fetch_assoc()) {
        $typeIndex = array_search($__row['report_type'], $aeth_radarTypeKeys, true);
        if ($typeIndex === false) continue;
        $barangayId = (int)$__row['barangay_id'];
        if (!isset($__radarByBarangay[$barangayId])) $__radarByBarangay[$barangayId] = array_fill(0, count($aeth_radarTypeKeys), 0);
        $__radarByBarangay[$barangayId][$typeIndex] = (int)$__row['total'];
        if ($barangayId !== 0) $__radarByBarangay[0][$typeIndex] += (int)$__row['total'];
      }
    }
    $aeth_radarData = $__radarByBarangay[0];
    $aeth_radarBrgy = [['id' => 0, 'name' => 'All Barangays', 'data' => $aeth_radarData]];
    foreach ($__brgyList as $__b) {
      $aeth_radarBrgy[] = [
        'id' => $__b['id'],
        'name' => $__b['name'],
        'data' => $__radarByBarangay[$__b['id']] ?? array_fill(0, count($aeth_radarTypeKeys), 0),
      ];
    }

    // ── Engine: per-year real report and resolution data ──
    $aeth_realYear = (int)date('Y');
    $aeth_realMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $aeth_engineYearData = [];
    for ($year = 2021; $year <= $aeth_realYear; $year++) {
      $aeth_engineYearData[$year] = [];
      for ($month = 1; $month <= 12; $month++) {
        $aeth_engineYearData[$year][] = ['d' => $aeth_realMonths[$month - 1], 'cases' => 0, 'resTime' => 0];
      }
    }
    $__r = $conn->query("SELECT YEAR(COALESCE(r.incident_at, r.created_at)) y, MONTH(COALESCE(r.incident_at, r.created_at)) m, COUNT(DISTINCT r.report_id) cases, AVG(CASE WHEN cc.resolved_at IS NOT NULL AND cc.resolved_at >= r.created_at THEN TIMESTAMPDIFF(SECOND, r.created_at, cc.resolved_at) / 86400 END) resTime FROM reports r LEFT JOIN confirmed_cases cc ON cc.report_id = r.report_id WHERE COALESCE(r.incident_at, r.created_at) IS NOT NULL AND YEAR(COALESCE(r.incident_at, r.created_at)) BETWEEN 2021 AND YEAR(CURDATE()) GROUP BY YEAR(COALESCE(r.incident_at, r.created_at)), MONTH(COALESCE(r.incident_at, r.created_at))");
    if ($__r) {
      while ($__row = $__r->fetch_assoc()) {
        $year = (int)$__row['y'];
        $monthIndex = (int)$__row['m'] - 1;
        if (!isset($aeth_engineYearData[$year][$monthIndex])) continue;
        $aeth_engineYearData[$year][$monthIndex]['cases'] = (int)$__row['cases'];
        $aeth_engineYearData[$year][$monthIndex]['resTime'] = isset($__row['resTime']) ? round((float)$__row['resTime'], 1) : 0;
      }
    }

    // ── Engine: Barangay Breakdown (real data) ─────────────────────
    $aeth_reportProgressTarget = 100;
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
                'progress' => $aeth_reportProgressTarget > 0 ? min(100, round($filed / $aeth_reportProgressTarget * 100)) : 0,
            ];
        }
    }

    // ── Intel: Appointment data ──
    $aeth_apptTypeNames = ['kapon' => 'Kapon', 'vaccination' => 'Vaccination', 'adoption' => 'Adoption', 'checkup' => 'Check-up', 'deworming' => 'Deworming'];
    $aeth_apptTypeColors = ['#1A1A1A', '#D4D4D4', '#0E8131', '#F59E0B', '#8B5CF6'];
    $aeth_apptTypeCounts = array_fill_keys(array_keys($aeth_apptTypeNames), 0);
    $__r = $conn->query("SELECT appointment_type, COUNT(*) AS total FROM appointments GROUP BY appointment_type");
    if ($__r) {
      while ($__row = $__r->fetch_assoc()) {
        if (isset($aeth_apptTypeCounts[$__row['appointment_type']])) $aeth_apptTypeCounts[$__row['appointment_type']] = (int)$__row['total'];
      }
    }
    $aeth_apptTypeLabels = array_values($aeth_apptTypeNames);
    $aeth_apptLoc = [];
    $aeth_apptLocByType = ['all' => []];
    foreach (array_keys($aeth_apptTypeNames) as $__type) $aeth_apptLocByType[$__type] = [];
    $aeth_apptLocTotals = [];
    $__r = $conn->query("SELECT b.barangay_name, a.appointment_type, COUNT(*) AS total FROM appointments a INNER JOIN users u ON u.user_id = a.user_id INNER JOIN barangays b ON b.barangay_id = u.barangay_id GROUP BY b.barangay_id, b.barangay_name, a.appointment_type ORDER BY total DESC, b.barangay_name ASC");
    if ($__r) {
      while ($__row = $__r->fetch_assoc()) {
        $entry = [$__row['barangay_name'], (int)$__row['total']];
        if (!isset($aeth_apptLocTotals[$__row['barangay_name']])) $aeth_apptLocTotals[$__row['barangay_name']] = 0;
        $aeth_apptLocTotals[$__row['barangay_name']] += (int)$__row['total'];
        if (isset($aeth_apptLocByType[$__row['appointment_type']]) && count($aeth_apptLocByType[$__row['appointment_type']]) < 8) $aeth_apptLocByType[$__row['appointment_type']][] = $entry;
      }
    }
    arsort($aeth_apptLocTotals);
    foreach (array_slice($aeth_apptLocTotals, 0, 8, true) as $__name => $__total) $aeth_apptLocByType['all'][] = [$__name, $__total];
    $aeth_apptLoc = $aeth_apptLocByType['all'];

    $aeth_apptSummary = ['total_30d' => 0, 'upcoming' => 0, 'completed_30d' => 0, 'no_show_30d' => 0];
    $__r = $conn->query("SELECT
      SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS total_30d,
      SUM(CASE WHEN archived_at IS NULL AND preferred_date >= CURDATE() AND status IN ('pending','approved','scheduled','rescheduled','action_needed') THEN 1 ELSE 0 END) AS upcoming,
      SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'completed' THEN 1 ELSE 0 END) AS completed_30d,
      SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'no_show' THEN 1 ELSE 0 END) AS no_show_30d
      FROM appointments");
    if ($__r && ($__row = $__r->fetch_assoc())) {
      foreach ($aeth_apptSummary as $__key => $__value) $aeth_apptSummary[$__key] = (int)($__row[$__key] ?? 0);
    }
    $aeth_apptStatuses = [
      ['label' => 'Pending', 'count' => 0, 'color' => '#D4D4D4'],
      ['label' => 'Scheduled', 'count' => 0, 'color' => '#0E8131'],
      ['label' => 'Completed', 'count' => 0, 'color' => '#1A1A1A'],
      ['label' => 'Cancelled / Rejected', 'count' => 0, 'color' => '#A1A1AA'],
      ['label' => 'No-Show', 'count' => 0, 'color' => '#EF4444'],
    ];
    $__r = $conn->query("SELECT
      SUM(status = 'pending') AS pending,
      SUM(status IN ('approved','scheduled','rescheduled')) AS scheduled,
      SUM(status = 'completed') AS completed,
      SUM(status IN ('cancelled','rejected')) AS closed,
      SUM(status = 'no_show') AS no_show
      FROM appointments");
    if ($__r && ($__row = $__r->fetch_assoc())) {
      $aeth_apptStatuses[0]['count'] = (int)$__row['pending'];
      $aeth_apptStatuses[1]['count'] = (int)$__row['scheduled'];
      $aeth_apptStatuses[2]['count'] = (int)$__row['completed'];
      $aeth_apptStatuses[3]['count'] = (int)$__row['closed'];
      $aeth_apptStatuses[4]['count'] = (int)$__row['no_show'];
    }

    // ── Intel: Appointment Segments ──
    $aeth_apptSegments = [];
    $__r = $conn->query("SELECT appointment_type, COUNT(*) AS total,
      SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status IN ('approved','scheduled','rescheduled') THEN 1 ELSE 0 END) AS scheduled,
      SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
      SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
      SUM(CASE WHEN status IN ('cancelled','rejected','no_show') THEN 1 ELSE 0 END) AS closed
      FROM appointments
      GROUP BY appointment_type ORDER BY total DESC, appointment_type ASC");
    if ($__r) {
      while ($__row = $__r->fetch_assoc()) {
        $type = trim(str_replace('_', ' ', (string)$__row['appointment_type']));
        $aeth_apptSegments[] = [
          'type' => $type !== '' ? ucwords($type) : 'Other',
          'total' => (int)$__row['total'],
          'pending' => (int)$__row['pending'],
          'scheduled' => (int)$__row['scheduled'],
                'in_progress' => (int)$__row['in_progress'],
          'completed' => (int)$__row['completed'],
          'closed' => (int)$__row['closed'],
        ];
      }
    }

    // ── Budget: Rescue data ──
    $aeth_rescueSources = [];
    $__r = $conn->query("SELECT ri.source, SUM(ri.animal_count) AS total,
      SUM(COALESCE(ps.adopted, 0)) AS adopted, SUM(COALESCE(ps.available, 0)) AS available
      FROM rescue_intakes ri LEFT JOIN (
        SELECT rescue_intake_id, SUM(status = 'adopted') AS adopted, SUM(status = 'available') AS available
        FROM pet_listings WHERE rescue_intake_id IS NOT NULL GROUP BY rescue_intake_id
      ) ps ON ps.rescue_intake_id = ri.intake_id
      GROUP BY ri.source ORDER BY total DESC, ri.source ASC");
    $aeth_rescueSourceNames = ['barangay_reported' => 'Barangay-Reported', 'resident_surrender' => 'Resident Surrender', 'stray_roundup' => 'Stray Roundup', 'shelter_transfer' => 'Shelter Transfer', 'confiscated' => 'Confiscated', 'found_lost' => 'Found/Lost Pet Unclaimed', 'field_operation' => 'Field Operation'];
    if ($__r) {
      while ($__row = $__r->fetch_assoc()) {
        $total = (int)$__row['total'];
        $adopted = (int)$__row['adopted'];
        $available = (int)$__row['available'];
        $aeth_rescueSources[] = ['label' => $aeth_rescueSourceNames[$__row['source']] ?? ucwords(str_replace('_', ' ', $__row['source'])), 'total' => $total, 'adopted' => $adopted, 'available' => $available, 'under_care' => max(0, $total - $adopted - $available)];
      }
    }
    $aeth_monthlyRescued = array_fill(1, 12, 0);
    $aeth_monthlyAdopted = array_fill(1, 12, 0);
    $yr = (int)date('Y');
    $__r = $conn->query("SELECT MONTH(rescue_date) AS month, SUM(animal_count) AS total FROM rescue_intakes WHERE YEAR(rescue_date) = $yr GROUP BY MONTH(rescue_date)");
    if ($__r) while ($__row = $__r->fetch_assoc()) $aeth_monthlyRescued[(int)$__row['month']] = (int)$__row['total'];
    $__r = $conn->query("SELECT MONTH(adopted_at) AS month, COUNT(*) AS total FROM pet_listings WHERE adopted_at IS NOT NULL AND YEAR(adopted_at) = $yr GROUP BY MONTH(adopted_at)");
    if ($__r) while ($__row = $__r->fetch_assoc()) $aeth_monthlyAdopted[(int)$__row['month']] = (int)$__row['total'];
    $__r = $conn->query("SELECT pet.name AS title, CONCAT(pet.species, ' · Adopted') AS detail, pet.adopted_at AS event_at, 'adoption' AS type, 'Adopted' AS action FROM pet_listings pet WHERE pet.adopted_at IS NOT NULL
      UNION ALL
      SELECT CONCAT('Rescue Intake #', ri.intake_id) AS title, CONCAT(COALESCE(b.barangay_name, 'Barangay unavailable'), ' · ', ri.animal_count, ' animals') AS detail, ri.created_at AS event_at, 'rescue' AS type, CONCAT('+', ri.animal_count, ' rescued') AS action FROM rescue_intakes ri LEFT JOIN barangays b ON b.barangay_id = ri.barangay_id
      ORDER BY event_at DESC LIMIT 6");
    if ($__r) while ($__row = $__r->fetch_assoc()) $aeth_txns[] = $__row;

    // ── Budget: Adoption Metrics (live from DB) ──
    $aeth_rescuedCount = 0;
    $aeth_availableCount = 0;
    $aeth_adoptedCount = 0;
    $aeth_publishedCount = 0;

    $yr = date('Y');
    $rQ = $conn->query("SELECT COALESCE(SUM(animal_count), 0) AS c FROM rescue_intakes WHERE YEAR(rescue_date) = $yr");
    if ($rQ && $rR = $rQ->fetch_assoc()) $aeth_rescuedCount = (int)$rR['c'];

    $aQ = $conn->query("SELECT COUNT(*) AS c FROM pet_listings WHERE status = 'available'");
    if ($aQ && $aR = $aQ->fetch_assoc()) $aeth_availableCount = (int)$aR['c'];

    $pQ = $conn->query("SELECT COUNT(*) AS c FROM pet_listings pl INNER JOIN adoption_listings al ON al.source_listing_id = pl.listing_id WHERE pl.status = 'available'");
    if ($pQ && $pR = $pQ->fetch_assoc()) $aeth_publishedCount = (int)$pR['c'];

    $adQ = $conn->query("SELECT COUNT(*) AS c FROM pet_listings WHERE adopted_at IS NOT NULL AND YEAR(adopted_at) = $yr");
    if ($adQ && $adR = $adQ->fetch_assoc()) $aeth_adoptedCount = (int)$adR['c'];
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
.ab-spark-wrap { display:block; }
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
.ab-pill-progress { color:#046b25; background:rgba(4,107,37,0.12); border-color:rgba(4,107,37,0.35); }
.ab-pill-resolved { color:#046b25; background:rgba(4,107,37,0.12); border-color:rgba(4,107,37,0.35); }
.ab-pill-cancelled { color:var(--ab-destructive); background:rgba(229,62,62,0.1); border-color:rgba(229,62,62,0.3); }
.ab-pill-urgency { min-width:52px; }
.ab-urgency-high { color:#991B1B; background:#FEF2F2; border-color:#FECACA; }
.ab-urgency-medium { color:#9A3412; background:#FFF7ED; border-color:#FED7AA; }
.ab-urgency-low { color:#166534; background:#F0FDF4; border-color:#BBF7D0; }
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
.ab-label { display:block; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; margin-bottom:6px; }
.ab-input, .ab-select { width:100%; height:40px; padding:0 0.8rem; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; background:var(--ab-bg); color:var(--ab-fg); border:1px solid var(--ab-border); outline:none; }
.ab-input:focus, .ab-select:focus { border-color:var(--ab-lime); box-shadow:0 0 0 1px var(--ab-lime); }
#abBrgyDropdown .ab-brgy-opt { display:flex; align-items:center; min-height:36px; line-height:20px; }
#abBrgyDropdown .ab-brgy-name { display:block; flex:1 1 auto; min-width:0; line-height:20px; }
.ab-modal-backdrop { position:fixed; inset:0; background:rgba(5,5,5,0.8); backdrop-filter:blur(4px); z-index:200; display:none; align-items:center; justify-content:center; padding:1rem; }
.ab-modal-backdrop.open { display:flex; }
.ab-modal { background:var(--ab-card); border:1px solid var(--ab-border); width:100%; max-width:460px; padding:1.5rem; max-height:90vh; overflow-y:auto; }
.ab-modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; }
.ab-modal-head h2 { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; letter-spacing:-0.025em; }
/* Keep shared shadcn dialogs (sd-dialog) looking correct inside the dashboard */
.aether-dash .sd-dialog { width:100%; display:flex; justify-content:center; }
.aether-dash .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:calc(100vh - 2rem); overflow-y:auto; letter-spacing:normal; font-family:'Inter','Segoe UI',sans-serif; }
.aether-dash .sd-dialog-content h3,
.aether-dash .sd-dialog-content p,
.aether-dash .sd-dialog-content span,
.aether-dash .sd-dialog-content label,
.aether-dash .sd-dialog-content input,
.aether-dash .sd-dialog-content select,
.aether-dash .sd-dialog-content textarea { font-family:'Inter','Segoe UI',sans-serif; letter-spacing:normal; }
.aether-dash .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
.aether-dash .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
.aether-dash .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; padding-right:32px; }
.aether-dash .sd-dialog-title { margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
.aether-dash .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
.aether-dash .sd-dialog-footer { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; }
    .aether-dash #abCampModal .sd-dialog-footer .sd-btn { padding:0 8px; }
    .aether-dash #abCampModal .sd-dialog-footer.sd-one-row { flex-wrap:nowrap; gap:8px; justify-content:center; padding-bottom:2px; }
    .aether-dash #abCampModal .sd-dialog-footer.sd-one-row .sd-btn { flex:0 0 auto; min-height:38px; padding:0 14px; font-size:14px; white-space:nowrap; }
    .aether-dash .ab-program-edit-icon { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; flex:0 0 30px; padding:0; border:1px solid #e4e4e7; border-radius:6px; background:#fff; cursor:pointer; }
    .aether-dash .ab-program-edit-icon img { display:block; width:16px; height:16px; object-fit:contain; }
    .aether-dash .ab-program-edit-icon:hover { border-color:#18181b; background:#f4f4f5; }
    .aether-dash .ab-program-log-icon { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; flex:0 0 30px; padding:0; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; cursor:pointer; }
    .aether-dash .ab-program-log-icon:hover { border-color:#18181b; background:#f4f4f5; }
.aether-dash #abCampModal .sd-dialog-content { height:min(720px, calc(100vh - 3rem)); }
.aether-dash #abCampModalBody { flex:1; min-height:0; overflow-y:auto; padding-right:4px; }
@media (max-width: 768px) {
  .aether-dash #abNewCampModal {
    padding: 12px;
  }
  .aether-dash #abNewCampModal .sd-dialog {
    width: 100%;
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
  }
  .aether-dash #abNewCampModal .sd-dialog-content {
    width: 100%;
    max-width: 560px;
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
    padding: 20px;
  }
  .aether-dash #abNewCampModal .sd-dialog-footer,
  .aether-dash #abCampModal .sd-dialog-footer,
  .aether-dash #abViewProgModal .sd-dialog-footer {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
  .aether-dash #abNewCampModal .sd-dialog-footer .sd-btn,
  .aether-dash #abCampModal .sd-dialog-footer .sd-btn,
  .aether-dash #abViewProgModal .sd-dialog-footer .sd-btn {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    min-height: 42px;
    flex: 0 0 auto;
    justify-content: center;
    white-space: normal;
  }
  .aether-dash #abCampModal,
  .aether-dash #abViewProgModal {
    align-items: stretch;
    justify-content: stretch;
    padding: 0;
  }
  .aether-dash #abCampModal > .sd-dialog,
  .aether-dash #abViewProgModal > .sd-dialog {
    width: 100%;
    height: 100vh;
    height: 100dvh;
    max-width: none;
  }
  .aether-dash #abCampModal .sd-dialog-content,
  .aether-dash #abViewProgModal .sd-dialog-content {
    box-sizing: border-box;
    width: 100%;
    height: 100vh;
    height: 100dvh;
    max-width: none !important;
    max-height: none;
    min-height: 0;
    padding: 20px 16px;
    border: 0;
    border-radius: 0;
    overflow: hidden;
  }
  .aether-dash #abCampModalBody,
  .aether-dash #abViewProgBody {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
  }
  .aether-dash #abCampModal .sd-dialog-footer,
  .aether-dash #abViewProgModal .sd-dialog-footer {
    flex: 0 0 auto;
    padding-top: 12px !important;
    border-top: 1px solid #e4e4e7;
  }
}
@media (max-width: 480px) {
  .aether-dash #abNewCampModal .sd-dialog-content {
    padding: 18px 16px;
  }
  .aether-dash #abNewCampModal .ab-create-program-field-grid {
    grid-template-columns: minmax(0, 1fr) !important;
    gap: 12px !important;
  }
  .aether-dash #abNewCampModal #abTargetReach,
  .aether-dash #abNewCampModal #abDogsTarget,
  .aether-dash #abNewCampModal #abCatsTarget {
    min-width: 0;
  }
  .aether-dash #abNewCampModal .ab-create-program-target-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
  }
  .aether-dash #abNewCampModal > .sd-dialog,
  .aether-dash #abNewCampModal .sd-dialog-content {
    max-width: none;
  }
  .aether-dash #abNewCampModal #abTimeStart,
  .aether-dash #abNewCampModal #abTimeEnd {
    width: auto !important;
    min-width: 0;
    flex: 1 1 0;
  }
  .aether-dash #abCampModalBody .ab-program-details-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 12px !important;
  }
}
/* City Drives — table + KPI cards matching All Reports (#reports-all) */
#ab-page-citydrives{ font-family:'Inter','Segoe UI',sans-serif; letter-spacing:normal; }
#ab-page-citydrives .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
#ab-page-citydrives table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; font-family:'Inter','Segoe UI',sans-serif; }
#ab-page-citydrives thead tr{ border-bottom:1px solid #e4e4e7; }
#ab-page-citydrives thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; border-bottom:none; font-family:'Inter','Segoe UI',sans-serif; }
#ab-page-citydrives tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; }
#ab-page-citydrives tbody tr:last-child{ border-bottom:none; }
#ab-page-citydrives tbody tr:hover{ background:#fafafa; }
#ab-page-citydrives tbody td{ padding:17px 8px; vertical-align:middle; font-size:14px; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; }
#ab-page-citydrives .cd-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
#ab-page-citydrives .cd-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
#ab-page-citydrives .cd-status{ display:inline-flex; align-items:center; padding:3px 10px; border:1px solid #e4e4e7; border-radius:4px; background:#f1f5f9; color:#64748b; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; line-height:1.4; white-space:nowrap; }
#ab-page-citydrives .cd-status.is-completed{ border-color:#0E8131; background:#0E8131; color:#fff; }
#ab-page-citydrives .cd-status.is-ended{ border-color:#BFDBFE; background:#EFF6FF; color:#1E40AF; }
#ab-page-citydrives .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
#ab-page-citydrives .kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:14px 16px; background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); transition:all .2s cubic-bezier(.4,0,.2,1); font-family:'Inter','Segoe UI',sans-serif; }
#ab-page-citydrives .kpi-card:hover{ border-color:#D1D5DB; box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03); }
#ab-page-citydrives .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:0.85rem; font-weight:500; color:#18181b; margin:0 0 6px; text-transform:uppercase; letter-spacing:0.03em; }
#ab-page-citydrives .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:1.75rem !important; font-weight:600; color:#18181b; letter-spacing:normal; line-height:1; margin:0 0 6px; }
#ab-page-citydrives .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
#ab-page-citydrives .dt-container{ min-height:var(--staff-table-min-height); }
#ab-page-citydrives .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; }
#ab-page-citydrives .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
#ab-page-citydrives .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
#ab-page-citydrives .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
#ab-page-citydrives .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
#ab-page-citydrives .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
#ab-page-citydrives .dt-page-btns{ display:flex; align-items:center; gap:4px; }
#ab-page-citydrives .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
#ab-page-citydrives .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
#ab-page-citydrives .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
.aether-dash .sd-dialog-content .ab-input:focus,
.aether-dash .sd-dialog-content .ab-select:focus { border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
.aether-dash .sd-btn-primary { background:#18181b; color:#fafafa; border-color:#18181b; }
.aether-dash .sd-btn-primary:hover { background:#27272a; }
.aether-dash .sd-btn-outline { background:#ffffff; border-color:#e4e4e7; color:#18181b; }
.aether-dash .sd-btn-outline:hover { background:#f4f4f5; }
.aether-dash .sd-label { font-size:14px; font-weight:500; }
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
  .ab-gantt-bar span { position:absolute; inset:0; display:flex; align-items:center; padding:0 0.5rem; font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; white-space:nowrap; }
  .ab-gantt-bar .ab-gantt-bar-label-inside { justify-content:flex-end; padding-right:2px; font-size:10px; }
  .ab-gantt-bar-label { position:absolute; top:50%; transform:translateY(-50%); font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:600; white-space:nowrap; color:var(--ab-muted-fg); pointer-events:none; }
.ab-gantt-bar-live { background:rgba(34,197,94,0.14); border-color:#22C55E; box-shadow:0 0 20px rgba(34,197,94,0.3); }
.ab-gantt-bar-live .ab-gantt-bar-progress { background:#22C55E; }
.ab-gantt-bar-scheduled { background:rgba(51,51,51,0.14); border-color:#333333; box-shadow:0 0 20px rgba(51,51,51,0.3); }
.ab-gantt-bar-scheduled .ab-gantt-bar-progress { background:rgba(255,255,255,0.1); }
.ab-gantt-bar-paused { background:rgba(234,179,8,0.14); border-color:#EAB308; box-shadow:0 0 20px rgba(234,179,8,0.3); }
.ab-gantt-bar-paused .ab-gantt-bar-progress { background:#EAB308; }
.ab-gantt-bar-ended { background:rgba(249,115,22,0.14); border-color:#F97316; box-shadow:none; }
.ab-gantt-bar-ended .ab-gantt-bar-progress { background:#F97316; }
.ab-gantt-bar-completed { background:rgba(14,129,49,0.14); border-color:#0E8131; box-shadow:none; }
.ab-gantt-bar-completed .ab-gantt-bar-progress { background:#0E8131; }
.ab-gantt-bar-cancelled { background:rgba(220,38,38,0.12); border-color:#dc2626; box-shadow:none; }
.ab-gantt-bar-cancelled .ab-gantt-bar-progress { background:#dc2626; }

/* Campaign cards */
.ab-camp-card { background:var(--ab-card); border:1px solid var(--ab-border); padding:1rem 1.25rem; cursor:pointer; transition:all 0.15s; }
.ab-camp-card:hover { background:var(--ab-surface-hover); }
.ab-camp-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem; }
.ab-camp-id { font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:500; color:var(--ab-muted-fg); text-transform:uppercase; }
.ab-status-dot { width:8px; height:8px; }
.ab-status-dot.live { background:#22C55E; animation:ab-pulse 2s ease-in-out infinite; }
.ab-status-dot.scheduled { background:#333333; }
.ab-status-dot.paused { background:#EAB308; }
.ab-status-dot.ended { background:#F97316; }
.ab-status-dot.completed { background:#0E8131; }
.ab-status-dot.cancelled { background:#dc2626; }
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
.ab-stat { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:14px 16px; background:#fff !important; border:1px solid #E5E7EB !important; border-radius:14px !important; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); }
.ab-stat-label { font-family:'Inter','Segoe UI',sans-serif !important; font-size:0.85rem !important; font-weight:500 !important; color:#18181b !important; text-transform:uppercase !important; letter-spacing:0.03em !important; margin:0 0 6px !important; }
.ab-stat-val { font-family:'Inter','Segoe UI',sans-serif !important; font-size:1.75rem !important; font-weight:600 !important; color:#18181b !important; letter-spacing:normal !important; line-height:1 !important; margin:0 0 6px !important; }
.ab-stat-trend { font-family:'Inter','Segoe UI',sans-serif !important; font-size:14px !important; color:#71717a !important; letter-spacing:normal !important; }
.ab-stat-trend.pos { color:#045e1f !important; }
.ab-stat-trend.neg { color:var(--ab-destructive) !important; }

@keyframes ab-pulse { 0%,100% { opacity:1; } 50% { opacity:0.35; } }
.ab-live-dot { width:8px; height:8px; background:var(--ab-lime); animation:ab-pulse 2s ease-in-out infinite; }

/* ── Animations (recharts + framer-motion look) ── */
@keyframes abPageIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
.ab-page.active { animation:abPageIn 0.2s ease-out; }
#ab-page-engine.active { animation:none; }
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
@media (max-width:640px) { .ab-grid-4 { grid-template-columns:repeat(2,minmax(0,1fr)); } .ab-grid-6 { grid-template-columns:repeat(2,minmax(0,1fr)); } .ab-page { padding:1rem; } }
@media (max-width:480px) { .ab-grid-4, .ab-grid-6 { grid-template-columns:repeat(2,minmax(0,1fr)); } }

.aether-analytics,
.aether-analytics .aether-dash,
.aether-analytics .ab-page,
.aether-analytics .ab-card,
.aether-analytics .ab-chart-card {
  min-width: 0;
}
.aether-analytics .ab-grid-4,
.aether-analytics .ab-grid-3,
.aether-analytics .ab-grid-2,
.aether-analytics .ab-grid-6 {
  min-width: 0;
}
.aether-analytics .ab-chart-canvas {
  min-width: 0;
}
.aether-analytics .ab-spark-wrap {
  width: 100%;
  min-width: 0;
}
.aether-analytics .ab-chart-canvas canvas {
  max-width: 100%;
}
.aether-analytics .ab-table-wrap {
  max-width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
@media (max-width:768px) {
  #ab-page-engine .ab-grid-3 {
    grid-template-columns: minmax(0, 1fr) !important;
  }
  .aether-analytics .ab-head {
    align-items: stretch;
  }
  .aether-analytics .ab-head > * {
    min-width: 0;
  }
  .aether-analytics .ab-grid-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .aether-analytics .ab-chart-head {
    flex-wrap: wrap;
  }
  .aether-analytics .ab-chart-head > * {
    min-width: 0;
    max-width: 100%;
  }
}
@media (max-width:480px) {
  .aether-analytics .ab-grid-4,
  .aether-analytics .ab-grid-6 {
    grid-template-columns: minmax(0, 1fr);
  }
  .aether-analytics .ab-page {
    padding: 0.75rem;
  }
  .aether-analytics .ab-chart-card {
    padding: 0.85rem;
  }
  .aether-analytics .ab-chart-canvas {
    height: 260px;
  }
  .aether-analytics .ab-range {
    max-width: 100%;
    flex-wrap: wrap;
  }
  .aether-analytics .ab-range-btn {
    padding-inline: 0.5rem;
  }
  .aether-analytics .ab-legend {
    flex-wrap: wrap;
    gap: 0.5rem;
  }
}

/* Appointment Overview responsive layout */
#ab-page-intel,
#ab-page-intel > *,
#ab-page-intel .ab-card,
#ab-page-intel .ab-chart-card,
#ab-page-intel .ab-table-card {
  min-width: 0;
  max-width: 100%;
}
#ab-page-intel > div:first-child {
  min-width: 0;
  flex-wrap: wrap;
}
#ab-page-intel .ab-chart-card h2 {
  overflow-wrap: normal;
  word-break: normal;
}
#ab-page-intel .ab-donut-canvas {
  height: clamp(12rem, 56vw, 16rem) !important;
}
#ab-page-intel .ab-chart-canvas canvas {
  display: block;
  width: 100% !important;
  height: 100% !important;
  max-width: 100%;
}
#ab-page-intel .ab-donut-canvas canvas {
  max-width: 100%;
  margin-inline: auto;
}
#ab-page-intel .ab-donut-legend {
  display: flex;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  min-width: 0;
}
#ab-page-intel .ab-donut-legend-item {
  flex: 1 1 8rem;
  min-width: 0;
  white-space: normal;
  overflow-wrap: anywhere;
}
#ab-page-intel .ab-bar-label-row {
  min-width: 0;
  gap: 0.75rem;
}
#ab-page-intel .ab-bar-label-row span:first-child {
  flex: 1 1 auto;
  min-width: 0;
  overflow-wrap: anywhere;
}
#ab-page-intel .ab-bar-label-row span:last-child {
  flex: 0 0 auto;
}
#ab-page-intel .ab-bar {
  width: 100%;
}
#ab-page-intel .ab-table-wrap {
  position: relative;
  width: 100%;
  max-width: 100%;
  contain: paint;
  overflow-x: auto;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;
}

@media (max-width:1024px) {
  #ab-page-intel .ab-grid-3 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width:768px) {
  #ab-page-engine > div:first-child {
    justify-content: flex-end !important;
  }
  #ab-page-engine > div:first-child .sd-btn {
    flex: 0 1 auto;
    width: auto;
    justify-content: center;
  }
  #ab-page-intel .ab-grid-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  #ab-page-intel .ab-grid-3 {
    grid-template-columns: minmax(0, 1fr);
  }
  #ab-page-intel .ab-stat {
    width: 100%;
  }
  #ab-page-intel > div:first-child {
    justify-content: stretch !important;
  }
  #ab-page-intel > div:first-child .sd-btn {
    flex: 1 1 100%;
    justify-content: center;
  }
  #ab-page-intel .ab-chart-card h2 {
    font-size: 0.9rem;
    line-height: 1.35;
  }
  #ab-page-intel .ab-chart-head {
    flex-direction: column;
    align-items: stretch;
  }
  #ab-page-intel #abApptLocType {
    width: 100%;
    max-width: 100%;
  }
  #ab-page-intel .ab-table {
    width: max-content;
    min-width: 100%;
  }
  #ab-page-intel .ab-table th,
  #ab-page-intel .ab-table td {
    padding: 0.6rem 0.7rem;
    font-size: 0.75rem;
  }
  #ab-page-intel .ab-table th:first-child,
  #ab-page-intel .ab-table td:first-child {
    position: sticky;
    left: 0;
    z-index: 1;
    background: var(--ab-card);
  }
  #ab-page-intel .ab-table th:first-child {
    z-index: 2;
  }
}
@media (max-width:480px) {
  #ab-page-intel .ab-grid-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  #ab-page-intel .ab-chart-card {
    padding: 0.85rem;
  }
  #ab-page-intel .ab-chart-canvas:not(.ab-donut-canvas) {
    height: 15rem !important;
  }
  #ab-page-intel .ab-donut-legend-item {
    flex-basis: 7rem;
  }
  #ab-page-intel .ab-table th,
  #ab-page-intel .ab-table td {
    padding: 0.5rem 0.6rem;
    font-size: 0.7rem;
  }
}

/* Programs & Services and City Drives responsive layout */
#ab-page-architect,
#ab-page-citydrives,
#ab-page-architect > *,
#ab-page-citydrives > *,
#ab-page-architect .ab-card,
#ab-page-citydrives .kpi-card {
  min-width: 0;
  max-width: 100%;
}
#ab-page-architect #abCampGrid {
  min-width: 0;
}
#ab-page-architect .ab-gantt {
  min-width: 0;
}
#ab-page-architect .ab-gantt-scroll {
  max-width: 100%;
  contain: paint;
  overscroll-behavior-x: contain;
  -webkit-overflow-scrolling: touch;
}
#ab-page-architect .ab-gantt-head > *,
#ab-page-architect .ab-gantt-legend,
#ab-page-architect .ab-camp-card,
#ab-page-architect .ab-camp-foot {
  min-width: 0;
}
#ab-page-citydrives .kpis {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
#ab-page-citydrives .dt-container {
  width: 100%;
  max-width: 100%;
  min-width: 0;
}

@media (max-width:1024px) {
  #ab-page-architect #abCampGrid {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
  }
  #ab-page-citydrives .kpis {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  #ab-page-citydrives .dt-container {
    overflow-x: auto;
    overflow-y: hidden;
    contain: paint;
    -webkit-overflow-scrolling: touch;
  }
  #ab-page-citydrives table {
    width: max-content;
    min-width: 100%;
  }
}
@media (max-width:768px) {
  #ab-page-architect #abCampGrid {
    grid-template-columns: minmax(0, 1fr) !important;
  }
  #ab-page-architect > div[style*="justify-content:flex-end"] {
    justify-content: stretch !important;
  }
  #ab-page-architect > div[style*="justify-content:flex-end"] .sd-btn {
    width: 100%;
    justify-content: center;
  }
  #ab-page-architect > div[style*="justify-content:space-between"] {
    align-items: flex-start !important;
    flex-direction: column;
    gap: 0.35rem;
  }
  #ab-page-architect .ab-gantt-head {
    align-items: flex-start;
    padding: 0.85rem;
  }
  #ab-page-architect .ab-gantt-legend {
    gap: 0.5rem 0.75rem;
  }
  #ab-page-architect .ab-camp-foot {
    align-items: flex-start;
    flex-direction: column;
  }
  #ab-page-architect .ab-camp-foot > div {
    flex-wrap: wrap;
  }
  #ab-page-citydrives .dt-pagination,
  #ab-page-citydrives .dt-pagination-right {
    justify-content: center;
    gap: 0.75rem;
  }
  #ab-page-citydrives .dt-pagination {
    padding-inline: 0;
  }
}
@media (max-width:480px) {
  #ab-page-citydrives .kpis {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
  }
  #ab-page-citydrives .kpi-card {
    width: 100%;
  }
  #ab-page-citydrives thead th,
  #ab-page-citydrives tbody td {
    padding: 0.65rem 0.7rem;
    font-size: 0.75rem;
  }
  #ab-page-citydrives .dt-rows {
    justify-content: center;
    flex-wrap: wrap;
  }
  #ab-page-citydrives .dt-page-label {
    width: auto;
  }
  #ab-page-architect .ab-camp-card {
    padding: 0.85rem;
  }
}
</style>

<!-- ═══════════ ENGINE ═══════════ -->
<div class="ab-page active" id="ab-page-engine">
  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
    <button type="button" class="sd-btn sd-btn-outline" onclick="aethExportPdf()"><i class="fas fa-file-pdf"></i> Export PDF</button>
  </div>
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
      <?php if (!$aeth_rescueSources): ?><p style="padding:0 1.25rem 1.25rem;color:var(--ab-muted-fg);font-size:13px;">No rescue intake records yet.</p><?php endif; ?>
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
          <tr><th>Barangay</th><th class="r">Reports Filed</th><th class="r">Cases Resolved</th><th class="r">Resolution Rate</th><th class="r">Staff Actions Taken</th><th class="r">Progress to 100 Reports</th></tr>
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
                    <div class="ab-share-track"><div class="ab-share-fill" style="width:<?php echo $bd['progress']; ?>%;"></div></div>
                    <span class="ab-mono" style="font-size:12px;color:var(--ab-muted-fg);width:34px;text-align:right;"><?php echo $bd['progress']; ?>%</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($aeth_highPriorityCount > 0): ?>
  <div style="display:flex;align-items:flex-start;gap:0.75rem;padding:0.85rem 1.15rem;border-radius:8px;background:#FEF2F2;border:1px solid #FECACA;margin-bottom:1rem;font-family:'Inter','Segoe UI',sans-serif;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#991B1B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div style="flex:1;">
      <p style="margin:0;font-size:14px;font-weight:600;color:#991B1B;"><?php echo $aeth_highPriorityCount; ?> high-priority report<?php echo $aeth_highPriorityCount !== 1 ? 's' : ''; ?> require immediate attention.</p>
      <p style="margin:0.15rem 0 0;font-size:13px;color:#991B1B;opacity:0.85;">Review these reports to confirm and assign the appropriate action.</p>
    </div>
    <a href="<?php echo htmlspecialchars($appBaseUrl . '/dashboard-staff.php#reports-pending', ENT_QUOTES, 'UTF-8'); ?>" style="flex-shrink:0;font-size:13px;font-weight:600;color:#991B1B;text-decoration:none;white-space:nowrap;">View High Priority Reports &rarr;</a>
  </div>
  <?php endif; ?>

  <div style="margin-top:1rem;">
    <div class="ab-card ab-feed">
      <div class="ab-feed-head">
        <div style="display:flex;align-items:center;gap:0.75rem;">
          <h2>Priority Reports</h2>
          <span style="font-size:12px;font-weight:500;padding:0.2rem 0.55rem;border-radius:4px;background:rgba(234,179,8,0.12);color:#92400e;border:1px solid rgba(234,179,8,0.4);font-family:'Inter','Segoe UI',sans-serif;"><?php echo $aeth_priorityTotal; ?> need attention</span>
        </div>
        <a href="<?php echo htmlspecialchars($appBaseUrl . '/dashboard-staff.php#reports-pending', ENT_QUOTES, 'UTF-8'); ?>" style="font-size:13px;font-weight:500;color:var(--ab-muted-fg);text-decoration:none;font-family:'Inter','Segoe UI',sans-serif;">View All &rarr;</a>
      </div>
      <div style="padding:0.6rem 1.25rem;border-bottom:1px solid var(--ab-border);font-size:13px;color:var(--ab-muted-fg);font-family:'Inter','Segoe UI',sans-serif;">Reports requiring staff review and confirmation</div>
      <div class="ab-feed-list">
        <?php if (empty($aeth_priorityReports)): ?>
          <div class="ab-feed-row" style="justify-content:center;cursor:default;"><p class="ab-feed-meta" style="padding:1.5rem 0;">No pending reports requiring staff review.</p></div>
        <?php else: ?>
          <?php foreach ($aeth_priorityReports as $rpt): ?>
            <?php $uCls = in_array($rpt['urgency'], ['high','critical']) ? 'high' : $rpt['urgency']; ?>
            <div class="ab-feed-row" style="align-items:flex-start;">
              <div style="display:flex;flex-direction:column;align-items:center;gap:0.35rem;flex-shrink:0;min-width:52px;">
                <span class="ab-pill ab-pill-urgency ab-urgency-<?php echo htmlspecialchars($uCls); ?>"><?php echo htmlspecialchars(strtoupper($rpt['urgency'])); ?></span>
              </div>
              <div class="ab-feed-info" style="flex:1;min-width:0;">
                <p class="ab-feed-name" style="white-space:normal;"><?php echo htmlspecialchars($rpt['type']); ?> &mdash; <?php echo htmlspecialchars($rpt['reporter']); ?></p>
                <p class="ab-feed-meta">Barangay <?php echo htmlspecialchars($rpt['barangay']); ?> &middot; <?php echo htmlspecialchars($rpt['reportType']); ?></p>
                <p class="ab-feed-meta" style="margin-top:0.25rem;display:flex;align-items:center;gap:0.35rem;">
                  <?php if (in_array($rpt['urgency'], ['high','critical'])): ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#991B1B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <?php endif; ?>
                  <?php echo htmlspecialchars($rpt['note']); ?> &middot; <?php echo htmlspecialchars($rpt['timeAgo']); ?>
                </p>
              </div>
              <a href="<?php echo htmlspecialchars($appBaseUrl . '/dashboard-staff.php#reports-pending', ENT_QUOTES, 'UTF-8'); ?>" style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.75rem;border-radius:6px;border:1px solid var(--ab-border);background:var(--ab-surface);color:var(--ab-fg);font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;text-decoration:none;white-space:nowrap;transition:background 0.12s;">Review</a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════ INTEL ═══════════ -->
<div class="ab-page" id="ab-page-intel">
  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
    <button type="button" class="sd-btn sd-btn-outline" onclick="aethExportAppointmentsPdf()"><i class="fas fa-file-pdf"></i> Export PDF</button>
  </div>
  <div class="ab-grid-4" style="margin-bottom:1.5rem;">
    <div class="ab-card ab-stat"><p class="ab-stat-label">Total Appointments (30d)</p><p class="ab-stat-val"><?php echo number_format($aeth_apptSummary['total_30d']); ?></p><p class="ab-stat-trend">From appointment records</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Upcoming Appointments</p><p class="ab-stat-val"><?php echo number_format($aeth_apptSummary['upcoming']); ?></p><p class="ab-stat-trend">Scheduled or awaiting confirmation</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">Completed Appointments (30d)</p><p class="ab-stat-val"><?php echo number_format($aeth_apptSummary['completed_30d']); ?></p><p class="ab-stat-trend"><?php echo $aeth_apptSummary['total_30d'] ? number_format($aeth_apptSummary['completed_30d'] / $aeth_apptSummary['total_30d'] * 100, 1) : '0.0'; ?>% of appointments created</p></div>
    <div class="ab-card ab-stat"><p class="ab-stat-label">No-Show Rate (30d)</p><p class="ab-stat-val"><?php echo $aeth_apptSummary['total_30d'] ? number_format($aeth_apptSummary['no_show_30d'] / $aeth_apptSummary['total_30d'] * 100, 1) : '0.0'; ?>%</p><p class="ab-stat-trend">From appointment records</p></div>
  </div>

  <div class="ab-grid-3" style="margin-bottom:1.5rem;">
    <div class="ab-card ab-chart-card">
      <h2>Appointment Type Distribution</h2>
      <div class="ab-chart-canvas ab-donut-canvas" style="height:256px;"><canvas id="abIntelAge"></canvas></div>
      <div class="ab-donut-legend">
        <?php foreach ($aeth_apptTypeLabels as $__i => $__label): ?>
          <div class="ab-donut-legend-item"><span class="ab-dot" style="background:<?php echo $aeth_apptTypeColors[$__i]; ?>;"></span><?php echo $__label; ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ab-card ab-chart-card">
      <h2>Appointment Status Overview</h2>
      <div style="margin-top:0.5rem;">
        <?php $apptStatusTotal = array_sum(array_column($aeth_apptStatuses, 'count')); ?>
        <?php foreach ($aeth_apptStatuses as $status): ?>
          <div class="ab-bar-row">
            <div class="ab-bar-label-row"><span><?php echo htmlspecialchars($status['label']); ?></span><span class="ab-mono"><?php echo number_format($status['count']); ?></span></div>
            <div class="ab-bar"><div class="ab-bar-fill" style="width:<?php echo $apptStatusTotal ? number_format($status['count'] / $apptStatusTotal * 100, 1) : '0'; ?>%;background:<?php echo htmlspecialchars($status['color']); ?>;"></div></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$apptStatusTotal): ?><p style="font-size:13px;color:#71717a;margin:1rem 0 0;">No appointment records yet.</p><?php endif; ?>
      </div>
    </div>
    <div class="ab-card ab-chart-card">
      <div class="ab-chart-head">
        <div><h2>Top Barangays by Appointment Volume</h2></div>
        <select id="abApptLocType" class="ab-range-select" onchange="aethSetApptLoc(this.value)">
          <option value="all">All Types</option>
          <?php foreach ($aeth_apptTypeNames as $__type => $__label): ?><option value="<?php echo htmlspecialchars($__type); ?>"><?php echo htmlspecialchars($__label); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="ab-chart-canvas" style="height:256px;"><canvas id="abIntelLoc"></canvas></div>
    </div>
  </div>

  <div class="ab-table-card" style="margin-top:1.5rem;">
    <div class="ab-feed-head"><h2>Appointment Segments</h2></div>
    <div class="ab-table-wrap">
      <table class="ab-table">
        <thead>
          <tr>
            <th>Appointment Type</th>
            <th class="r">Total Requests</th>
            <th class="r">Pending</th>
            <th class="r">Scheduled</th>
            <th class="r">In Progress</th>
            <th class="r">Completed</th>
            <th class="r">Closed</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($aeth_apptSegments): ?>
            <?php foreach ($aeth_apptSegments as $segment): ?>
              <tr>
                <td><?php echo htmlspecialchars($segment['type']); ?></td>
                <td class="r ab-mono"><?php echo number_format($segment['total']); ?></td>
                <td class="r ab-mono"><?php echo number_format($segment['pending']); ?></td>
                <td class="r ab-mono"><?php echo number_format($segment['scheduled']); ?></td>
                <td class="r ab-mono"><?php echo number_format($segment['in_progress']); ?></td>
                <td class="r ab-mono"><?php echo number_format($segment['completed']); ?></td>
                <td class="r ab-mono"><?php echo number_format($segment['closed']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="7" style="text-align:center;color:var(--ab-muted-fg);">No appointment requests yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════════ BUDGET ═══════════ -->
<div class="ab-page" id="ab-page-budget">
  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
    <button type="button" class="sd-btn sd-btn-outline" onclick="aethExportAdoptionPdf()"><i class="fas fa-file-pdf"></i> Export PDF</button>
  </div>

  <div class="ab-budget-overview" style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-bottom:1.5rem;">
    <div class="ab-card" style="padding:1.25rem;">
      <h2 style="font-size:14px;font-weight:600;margin-bottom:1rem;">Annual Adoption Progress</h2>
      <div style="display:flex;align-items:baseline;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
        <span class="ab-budget-big"><?php echo number_format($aeth_adoptedCount); ?> / <?php echo number_format($aeth_rescuedCount); ?> Adopted</span>
      </div>
      <div>
        <div class="ab-progress-label-row"><span style="color:var(--ab-muted-fg);">Adopted</span><span class="ab-mono"><?php echo $aeth_rescuedCount > 0 ? number_format($aeth_adoptedCount / $aeth_rescuedCount * 100, 1) : '0.0'; ?>%</span></div>
        <div class="ab-progress"><div class="ab-progress-fill" style="width:<?php echo $aeth_rescuedCount > 0 ? number_format($aeth_adoptedCount / $aeth_rescuedCount * 100, 1) : '0'; ?>%;background:#0E8131;"></div></div>
      </div>
    </div>
  </div>

  <div class="ab-card ab-chart-card" style="margin-bottom:1.5rem;">
    <h2>Monthly Rescued vs. Adopted</h2>
    <div class="ab-chart-canvas" style="height:256px;"><canvas id="abBudgetChart"></canvas></div>
  </div>

  <div class="ab-grid-2">
    <div class="ab-table-card">
      <div class="ab-feed-head"><h2>Rescue Source Breakdown</h2></div>
      <?php foreach ($aeth_rescueSources as $src): ?>
        <?php
        $srcPct = $src['total'] > 0 ? $src['adopted'] / $src['total'] * 100 : 0;
        $cls = $srcPct > 90 ? 'over' : ($srcPct > 70 ? 'track' : 'ok');
        ?>
        <div class="ab-channel-row">
          <div class="ab-channel-top">
            <div class="ab-channel-name">
              <span><?php echo htmlspecialchars($src['label']); ?></span>
              <?php if ($cls === 'over' || $cls === 'track'): ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg><?php else: ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0E8131" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg><?php endif; ?>
            </div>
            <span class="ab-mono" style="font-size:14px;font-weight:600;"><?php echo number_format($src['adopted']); ?> / <?php echo number_format($src['total']); ?> adopted</span>
          </div>
          <div class="ab-channel-bar"><div class="ab-channel-fill-<?php echo $cls; ?>" style="width:<?php echo min($srcPct, 100); ?>%;height:100%;"></div></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="ab-table-card">
      <div class="ab-feed-head"><h2>Recent Adoption & Rescue Activity</h2></div>
      <?php foreach ($aeth_txns as $t): ?>
        <?php
        $actType = $t['type'];
        $actClass = $actType === 'adoption' ? 'color:#0E8131;' : 'color:#18181b;';
        ?>
        <div class="ab-txn">
          <div>
            <p class="ab-txn-desc"><?php echo htmlspecialchars($t['title']); ?></p>
            <p class="ab-txn-date"><?php echo htmlspecialchars($t['detail']); ?></p>
          </div>
          <div style="text-align:right;">
            <p style="margin:0;font-size:14px;font-weight:600;<?php echo $actClass; ?>"><?php echo htmlspecialchars($t['action']); ?></p>
            <p style="margin:2px 0 0;font-size:11px;font-weight:500;color:#71717a;"><?php echo htmlspecialchars(aeth_timeAgo($t['event_at'])); ?></p>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$aeth_txns): ?><p style="padding:0 1.25rem 1.25rem;color:var(--ab-muted-fg);font-size:13px;">No adoption or rescue activity yet.</p><?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════ ADOPTION POOL ═══════════ -->
<div class="ab-page" id="ab-page-pool">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:1.5rem;">
    <button onclick="aethGo('budget')" style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--ab-border);background:var(--ab-surface);color:var(--ab-fg);cursor:pointer;transition:all .15s;flex-shrink:0;" onmouseover="this.style.background='var(--ab-surface-hover)'" onmouseout="this.style.background='var(--ab-surface)'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <div>
      <h1 style="font-size:1.35rem;font-weight:800;color:#0f172a;margin:0;font-family:'Inter',sans-serif;">Adoption Pool</h1>
      <p style="font-size:0.88rem;color:#64748b;margin:4px 0 0;">Manage rescued animals and promote them for adoption</p>
    </div>
  </div>
</div>

<!-- ═══════════ ARCHITECT ═══════════ -->
<div class="ab-page" id="ab-page-architect">
  <?php if ($aeth_programSuccess): ?>
    <script>document.addEventListener('DOMContentLoaded',function(){if(typeof showToast==='function'){showToast('Success',{type:'success',description:'<?php echo addslashes($aeth_programSuccess); ?>'});}});</script>
  <?php endif; ?>
  <?php if ($aeth_programError): ?>
    <script>document.addEventListener('DOMContentLoaded',function(){if(typeof showToast==='function'){showToast('Error',{type:'error',description:'<?php echo addslashes($aeth_programError); ?>'});}});</script>
  <?php endif; ?>

  <?php
    $aeth_serviceTypeLabels = [
      'vaccination' => 'VACCINATED',
      'kapon' => 'KAPON',
      'checkup' => 'CHECK-UP',
      'adoption' => 'ADOPTED',
    ];
    $aeth_serviceDescriptions = [
      'vaccination' => 'Animals vaccinated through programs',
      'kapon' => 'Animals sterilized through programs',
      'checkup' => 'Animals served through check-up programs',
      'adoption' => 'Animals adopted through programs',
    ];
    $aeth_serviceTotals = array_fill_keys(array_keys($aeth_serviceTypeLabels), 0);
    $__serviceQ = $conn->query("SELECT CASE WHEN p.program_type = 'rabies_prevention' THEN 'vaccination' ELSE p.program_type END AS service_type, SUM(COALESCE(pp.dogs_served, 0) + COALESCE(pp.cats_served, 0)) AS total FROM programs p INNER JOIN program_progress pp ON pp.program_id = p.program_id WHERE p.program_type IN ('vaccination','rabies_prevention','kapon','checkup','adoption') GROUP BY service_type");
    if ($__serviceQ) {
      while ($__serviceRow = $__serviceQ->fetch_assoc()) {
        if (isset($aeth_serviceTotals[$__serviceRow['service_type']])) $aeth_serviceTotals[$__serviceRow['service_type']] = (int)$__serviceRow['total'];
      }
    }
  ?>
  <div class="ab-grid-4" style="margin-bottom:1.5rem;">
    <?php foreach ($aeth_serviceTypeLabels as $__serviceType => $__serviceLabel): ?>
      <div class="ab-card ab-stat"><p class="ab-stat-label"><?php echo htmlspecialchars($__serviceLabel); ?></p><p class="ab-stat-val"><?php echo number_format($aeth_serviceTotals[$__serviceType]); ?></p><p class="ab-stat-trend"><?php echo htmlspecialchars($aeth_serviceDescriptions[$__serviceType]); ?></p></div>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;justify-content:flex-end;margin-bottom:1.5rem;">
    <button class="sd-btn sd-btn-primary" onclick="aethResetCreateDialog();aethOpenModal('abNewCampModal')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Create Program
    </button>
  </div>

  <!-- Gantt -->
  <div class="ab-gantt">
    <div class="ab-gantt-head">
      <h2>Campaign Timeline</h2>
      <div class="ab-gantt-legend">
        <?php foreach (['live', 'scheduled', 'paused', 'completed', 'ended', 'cancelled'] as $st): ?>
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
          <?php $abRight = round($left + $wdt, 2); ?>
          <div class="ab-gantt-bar ab-gantt-bar-<?php echo $c['status']; ?>" style="left:<?php echo $left; ?>%;width:<?php echo $wdt; ?>%;animation-delay:<?php echo round($gi * 0.05 + 0.2, 2); ?>s;">
            <div class="ab-gantt-bar-progress" style="width:<?php echo $c['progress']; ?>%;"></div>
          </div>
          <span class="ab-gantt-bar-label" style="<?php echo $abRight <= 88 ? 'left:' . $abRight . '%;margin-left:8px;' : 'right:' . round(100 - $left, 2) . '%;margin-right:8px;'; ?>"><?php echo $c['progress']; ?>%</span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if ($gi === 0): ?>
        <div style="text-align:center;padding:2.5rem 1rem;color:var(--ab-muted-fg);font-size:13px;">No programs in the timeline range. Create a program to see it here.</div>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-top:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <h2 style="font-size:18px;font-weight:600;letter-spacing:-0.025em;">Program Details</h2>
      <span style="font-size:12px;color:var(--ab-muted-fg);">Click a program to view details</span>
    </div>
    <?php if (empty($aeth_campaigns)): ?>
      <div style="text-align:center;padding:3rem 1.5rem;color:var(--ab-muted-fg);font-family:'Inter','Segoe UI',sans-serif;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 0.75rem;color:#a1a1aa;"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
        <p style="font-size:14px;font-weight:500;margin:0 0 0.25rem;">No programs yet</p>
        <p style="font-size:13px;margin:0;">Create your first program to get started.</p>
      </div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;" id="abCampGrid">
        <?php foreach ($aeth_campaigns as $c): ?>
          <?php $typeLabel = ucfirst(str_replace('_', ' ', $c['program_type'] ?? 'other')); ?>
          <?php
if ($c['status'] === 'paused') $abBarBg = '#EAB308';
elseif ($c['status'] === 'ended') $abBarBg = '#F97316';
elseif ($c['status'] === 'cancelled') $abBarBg = '#dc2626';
elseif ($c['status'] === 'completed') $abBarBg = '#0E8131';
elseif ($c['status'] === 'live') $abBarBg = ($c['progress'] >= 100) ? '#0E8131' : '#22C55E';
else $abBarBg = ($c['progress'] >= 100) ? '#0E8131' : '#D9A441';
          ?>
          <div class="ab-camp-card" data-camp="<?php echo $c['id']; ?>" onclick="aethShowCampaign(this)">
            <div class="ab-camp-top">
              <span class="ab-camp-id"><?php echo $c['id']; ?></span>
              <span class="ab-status-dot <?php echo $c['status']; ?>"></span>
            </div>
            <h3 class="ab-camp-name"><?php echo htmlspecialchars($c['name']); ?></h3>
            <p class="ab-camp-channel" style="display:flex;align-items:center;gap:0.35rem;">
              <span style="font-size:11px;font-weight:600;padding:0.15rem 0.45rem;border-radius:3px;background:var(--ab-surface);border:1px solid var(--ab-border);text-transform:uppercase;letter-spacing:0.03em;"><?php echo htmlspecialchars($typeLabel); ?></span>
            </p>
            <p class="ab-camp-dates"><?php echo date('M d', strtotime($c['start'])); ?> - <?php echo date('M d', strtotime($c['end'])); ?></p>
            <?php if (!empty($c['description'])): ?>
              <p style="font-size:12px;color:var(--ab-muted-fg);margin:0.25rem 0 0;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($c['description']); ?></p>
            <?php endif; ?>
            <div class="ab-camp-progress" style="margin-top:0.5rem;"><div style="width:<?php echo $c['progress']; ?>%;background:<?php echo $abBarBg; ?>;"></div></div>
            <div class="ab-camp-foot">
              <span class="ab-camp-status"><?php echo $c['status']; ?></span>
              <div style="display:flex;align-items:center;gap:4px;">
                <button class="ab-btn ab-btn-ghost" style="font-size:12px;padding:0.25rem 0.5rem;" onclick="event.stopPropagation();aethOpenViewProgress('<?php echo $c['id']; ?>')">View Progress</button>
                <?php if ($c['status'] === 'live' || $c['status'] === 'paused'): ?>
                  <button class="ab-btn ab-btn-ghost" style="font-size:12px;padding:0.25rem 0.5rem;" onclick="event.stopPropagation();aethToggleStatus('<?php echo $c['id']; ?>')"><?php echo $c['status'] === 'live' ? 'Pause' : 'Resume'; ?></button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ City Drives Page (successful/completed programs) ═══ -->
<div class="ab-page" id="ab-page-citydrives">
  <?php
    $aeth_cityDrives = [];
    if (isset($conn)) {
      $cdQ = $conn->query(
        "SELECT p.*, GROUP_CONCAT(DISTINCT b.barangay_name SEPARATOR ', ') AS brgy_names," .
        " (SELECT COALESCE(SUM(pp.dogs_served + pp.cats_served), 0) FROM program_progress pp WHERE pp.program_id = p.program_id) AS reached" .
        " FROM programs p" .
        " LEFT JOIN program_barangays pb ON p.program_id = pb.program_id" .
        " LEFT JOIN barangays b ON pb.barangay_id = b.barangay_id" .
        " WHERE p.status IN ('completed', 'ended')" .
        "    OR (p.status = 'archived' AND p.target_reach > 0" .
        "        AND (SELECT COALESCE(SUM(pp.dogs_served + pp.cats_served), 0) FROM program_progress pp WHERE pp.program_id = p.program_id) >= p.target_reach)" .
        " GROUP BY p.program_id" .
        " ORDER BY p.end_date DESC, p.program_id DESC"
      );
      if ($cdQ) { while ($cdRow = $cdQ->fetch_assoc()) $aeth_cityDrives[] = $cdRow; }
    }
    $cdCount = count($aeth_cityDrives);
    $cdSumReached = 0; $cdSumTarget = 0;
    foreach ($aeth_cityDrives as $cdR) { $cdSumReached += (int)$cdR['reached']; $cdSumTarget += (int)$cdR['target_reach']; }
    $cdAvgPct = $cdSumTarget > 0 ? round($cdSumReached / $cdSumTarget * 100) : 0;
  ?>
  <div class="kpis">
    <div class="kpi-card">
      <p class="kpi-label">Programs Included</p>
      <div class="kpi-value"><?php echo number_format($cdCount); ?></div>
      <p class="kpi-sub">Completed and ended city drives</p>
    </div>
    <div class="kpi-card">
      <p class="kpi-label">Animals Reached</p>
      <div class="kpi-value"><?php echo number_format($cdSumReached); ?></div>
      <p class="kpi-sub">Dogs and cats served</p>
    </div>
    <div class="kpi-card">
      <p class="kpi-label">Total Target</p>
      <div class="kpi-value"><?php echo number_format($cdSumTarget); ?></div>
      <p class="kpi-sub">Combined program targets</p>
    </div>
    <div class="kpi-card">
      <p class="kpi-label">Avg. Completion</p>
      <div class="kpi-value"><?php echo $cdAvgPct; ?>%</div>
      <p class="kpi-sub">Reached of overall target</p>
    </div>
  </div>
  <?php if (empty($aeth_cityDrives)): ?>
    <div style="text-align:center;padding:3rem 1.5rem;color:var(--ab-muted-fg);font-family:'Inter','Segoe UI',sans-serif;background:var(--ab-card);border:1px solid var(--ab-border);border-radius:8px;">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 0.75rem;color:#a1a1aa;"><path d="M5 12l5 5L20 7"/></svg>
      <p style="font-size:14px;font-weight:500;margin:0 0 0.25rem;">No successful programs yet</p>
      <p style="font-size:13px;margin:0;">Completed and ended programs will appear here with their recorded progress.</p>
    </div>
  <?php else: ?>
    <div class="dt-container">
      <table>
        <thead>
          <tr class="sd-tr">
            <th class="sd-th">Program Name</th>
            <th class="sd-th">Program Type</th>
            <th class="sd-th">Status</th>
            <th class="sd-th">Location</th>
            <th class="sd-th">Date</th>
            <th class="sd-th">Target</th>
            <th class="sd-th">Reached</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($aeth_cityDrives as $cd):
            $cdReached = (int)$cd['reached'];
            $cdTarget  = (int)$cd['target_reach'];
            $cdType    = ucwords(str_replace('_', ' ', (string)($cd['program_type'] ?: 'other')));
            $cdWhere   = trim((string)($cd['brgy_names'] ?? '')) !== '' ? $cd['brgy_names'] : ($cd['venue'] ?: 'City-wide');
            $cdStart   = strtotime($cd['start_date']);
            $cdEnd     = strtotime($cd['end_date']);
            if ($cdStart === $cdEnd) {
              $cdDates = date('M j, Y', $cdStart);
            } elseif (date('Y-m', $cdStart) === date('Y-m', $cdEnd)) {
              $cdDates = date('M j', $cdStart) . '–' . date('j, Y', $cdEnd);
            } else {
              $cdDates = date('M j', $cdStart) . '–' . date('M j, Y', $cdEnd);
            }
          ?>
            <tr class="sd-tr">
              <td class="sd-td"><span class="cd-name"><?php echo htmlspecialchars($cd['program_name']); ?></span></td>
              <td class="sd-td"><span class="cd-main"><?php echo htmlspecialchars($cdType); ?></span></td>
              <td class="sd-td"><span class="cd-status is-<?php echo htmlspecialchars($cd['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst((string)$cd['status'])); ?></span></td>
              <td class="sd-td"><span class="cd-name"><?php echo htmlspecialchars($cdWhere); ?></span></td>
              <td class="sd-td"><span class="cd-main"><?php echo $cdDates; ?></span></td>
              <td class="sd-td"><span class="cd-main"><?php echo $cdTarget > 0 ? $cdTarget : '—'; ?></span></td>
              <td class="sd-td"><span class="cd-main"><?php echo $cdReached; ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="dt-pagination">
      <div class="dt-pagination-right">
        <div class="dt-rows">
          <span>Rows per page</span>
          <select onchange="cdSetPerPage(this.value)">
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="30">30</option>
            <option value="40">40</option>
            <option value="50">50</option>
          </select>
        </div>
        <div class="dt-page-label" id="cdPageLabel">Page 1 of 1</div>
        <div class="dt-page-btns">
          <button class="dt-page-btn" type="button" id="cdPageFirst" title="Go to first page" onclick="cdPagGo('first')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
          </button>
          <button class="dt-page-btn" type="button" id="cdPagePrev" title="Go to previous page" onclick="cdPagGo('prev')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <button class="dt-page-btn" type="button" id="cdPageNext" title="Go to next page" onclick="cdPagGo('next')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <button class="dt-page-btn" type="button" id="cdPageLast" title="Go to last page" onclick="cdPagGo('last')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
          </button>
        </div>
      </div>
    </div>
  <?php endif; ?>
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

<!-- ═══ New Program Dialog ═══ -->
<div class="ab-modal-backdrop" id="abNewCampModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:560px;">
      <button type="button" class="sd-dialog-close" onclick="aethCancelCreateDialog()" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Create New Program</h2>
        <p class="sd-dialog-description">Set up a new city drive or veterinary program.</p>
      </div>
      <form id="createProgramForm" method="POST" action="<?php echo htmlspecialchars($appBaseUrl . '/dashboard-staff.php#analytics', ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;gap:16px;">
        <input type="hidden" name="create_program" value="1">
        <?php echo csrfField(); ?>
        <input type="hidden" name="reschedule_offers_json" id="abRescheduleOffersJson" value="[]">
        <div class="ab-field" style="margin-bottom:0;">
          <label class="ab-label">Program Name <span style="color:#e53e3e;">*</span></label>
          <input class="ab-input" name="program_name" placeholder="e.g. Free Kapon Drive" required>
        </div>
        <div class="ab-create-program-field-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="ab-field" style="margin-bottom:0;">
            <label class="ab-label">Program Type <span style="color:#e53e3e;">*</span></label>
            <select class="ab-select" name="program_type" required>
              <option value="kapon">Kapon</option>
              <option value="vaccination">Vaccination</option>
              <option value="checkup">Check-up</option>
              <option value="deworming">Deworming</option>
              <option value="adoption">Adoption</option>
              <option value="rabies_prevention">Rabies Prevention</option>
              <option value="microchipping">Microchipping</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>
        <div class="ab-field" style="margin-bottom:0;">
          <label class="ab-label">Description</label>
          <textarea class="ab-input" name="program_description" rows="3" placeholder="Describe the program objectives and services..." style="resize:vertical;min-height:70px;background:var(--ab-bg);padding-top:10px;"></textarea>
        </div>
        <div class="ab-create-program-field-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="ab-field" style="margin-bottom:0;">
            <label class="ab-label">Start Date <span style="color:#e53e3e;">*</span></label>
            <input class="ab-input" type="date" name="start_date" required>
          </div>
          <div class="ab-field" style="margin-bottom:0;">
            <label class="ab-label">End Date <span style="color:#e53e3e;">*</span></label>
            <input class="ab-input" type="date" name="end_date" required>
          </div>
        </div>
        <div id="abProgramConflictSummary" role="status" aria-live="polite" hidden style="padding:12px 14px;border:1px solid #e5e7eb;border-top:2px solid #b45309;border-radius:8px;font-size:13px;line-height:1.5;"></div>
        <div id="abProgramConflictChoices" hidden style="border-top:1px solid #e5e7eb;">
          <label style="display:flex;align-items:flex-start;gap:12px;padding:10px 4px;border-bottom:1px solid #e5e7eb;cursor:pointer;">
            <input type="radio" name="program_conflict_choice" value="reschedule" id="abRescheduleAppointmentsChoice" style="flex:0 0 auto;margin-top:3px;">
            <span style="display:block;font-size:13px;line-height:1.45;color:#334155;">
              <strong style="display:block;color:#18181b;font-size:14px;">Reschedule affected appointments</strong>
              <span>Set a new date and time for each affected appointment. Residents have 3 days to accept or decline.</span>
            </span>
          </label>
          <label style="display:flex;align-items:center;gap:12px;padding:10px 4px;border-bottom:1px solid #e5e7eb;cursor:pointer;">
            <input type="radio" name="program_conflict_choice" value="notify" id="abKeepAppointmentsChoice" style="flex:0 0 auto;">
            <span style="display:block;font-size:13px;line-height:1.45;color:#334155;">
              <strong style="display:block;color:#18181b;font-size:14px;">Keep appointments as they are</strong>
              <span>Keep existing dates and times. New bookings remain subject to the program-day limits.</span>
            </span>
          </label>
          <input type="checkbox" name="notify_affected" id="abNotifyAffectedInput" value="1" hidden>
        </div>
        <div class="ab-field" style="margin-bottom:0;">
          <label class="ab-label">Operating Time</label>
          <div style="display:flex;align-items:center;gap:8px;">
            <select class="ab-input" id="abTimeStart" style="width:130px;padding:7px 10px;font-size:13px;font-weight:500;appearance:auto;cursor:pointer;" onchange="aethSyncOpTime()"></select>
            <span style="font-size:14px;color:#71717a;">—</span>
            <select class="ab-input" id="abTimeEnd" style="width:130px;padding:7px 10px;font-size:13px;font-weight:500;appearance:auto;cursor:pointer;" onchange="aethSyncOpTime()"></select>
          </div>
          <input type="hidden" name="operating_time" id="abOpTimeHidden">
          <input type="hidden" name="program_start_time" id="abProgramStartTime">
          <input type="hidden" name="program_end_time" id="abProgramEndTime">
        </div>
        <div class="ab-field" style="margin-bottom:0;">
          <label class="ab-label">Coverage / Barangay <span style="color:#e53e3e;">*</span></label>
          <div style="display:flex;gap:12px;margin-bottom:8px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;">
              <input type="radio" name="location_mode" value="single" checked onchange="toggleBrgyMode('single')"> Single Barangay
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;">
              <input type="radio" name="location_mode" value="multiple" onchange="toggleBrgyMode('multiple')"> Multiple Barangays
            </label>
          </div>
          <div id="abBrgyChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;min-height:0;"></div>
          <div id="abBrgyList" hidden></div>
          <div id="abSingleBrgySelectWrap">
            <select class="ab-select" id="abSingleBrgySelect" required onchange="aethSelectSingleBrgy(this)" style="height:36px;padding:0 10px;appearance:auto;cursor:pointer;">
              <option value="">Select a barangay...</option>
              <?php foreach ($aeth_allBarangays as $br): ?>
                <option value="<?php echo (int)$br['barangay_id']; ?>"><?php echo htmlspecialchars($br['barangay_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="abMultiBrgySelectWrap" style="display:none;">
            <div style="position:relative;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#71717a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
              <input class="ab-input" id="abBrgySearch" type="search" autocomplete="off" placeholder="Search and add barangays..." aria-label="Search barangays" aria-controls="abBrgyDropdown" oninput="aethFilterBrgys(this.value)" onfocus="aethShowBrgyDropdown()" style="height:36px;padding-left:32px;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;">
            </div>
          </div>
          <div id="abBrgyDropdown" data-mode="multiple" role="listbox" aria-label="Barangays" style="display:none;max-height:160px;overflow-y:auto;border:1px solid #e4e4e7;border-radius:6px;padding:6px;margin-top:4px;background:#fff;">
            <?php foreach ($aeth_allBarangays as $br): ?>
              <div class="ab-brgy-opt" role="option" aria-selected="false" tabindex="0" data-id="<?php echo (int)$br['barangay_id']; ?>" data-name="<?php echo htmlspecialchars($br['barangay_name'], ENT_QUOTES, 'UTF-8'); ?>" onclick="aethToggleBrgy(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();aethToggleBrgy(this);}" style="display:flex;align-items:center;gap:8px;padding:6px 8px;font-size:14px;cursor:pointer;color:#18181b;font-family:'Inter','Segoe UI',sans-serif;border-radius:4px;transition:background 0.1s;">
                <span class="ab-brgy-check" style="width:16px;height:16px;border:1px solid #d4d4d8;border-radius:3px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.1s;"></span>
                <span class="ab-brgy-name"><?php echo htmlspecialchars($br['barangay_name']); ?></span>
              </div>
            <?php endforeach; ?>
            <div id="abBrgyNoMatch" style="display:none;padding:8px 0;font-size:13px;color:#71717a;font-family:'Inter','Segoe UI',sans-serif;text-align:center;">No barangays found</div>
          </div>
          <div id="singleBrgyWrap">
            <label class="ab-label" for="abVenueInput" style="margin-top:10px;">Venue / Specific Location <span style="color:#e53e3e;">*</span></label>
            <input class="ab-input" name="venue" id="abVenueInput" placeholder="e.g. Covered court or community center" style="height:36px;">
          </div>
        </div>
        <div class="ab-create-program-field-grid ab-create-program-target-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
          <div class="ab-field" style="margin-bottom:0;">
            <label class="ab-label">Target Reach</label>
            <input class="ab-input" type="number" name="target_reach" id="abTargetReach" min="0" placeholder="0" style="font-size:13px;">
          </div>
          <div class="ab-field" style="margin-bottom:0;">
            <label class="ab-label">Dogs</label>
            <input class="ab-input" type="number" name="dogs_target" id="abDogsTarget" min="0" placeholder="0" style="font-size:13px;">
          </div>
          <div class="ab-field" style="margin-bottom:0;">
            <label class="ab-label">Cats</label>
            <input class="ab-input" type="number" name="cats_target" id="abCatsTarget" min="0" placeholder="0" style="font-size:13px;">
          </div>
        </div>
        <div id="abTargetHint" style="display:none;font-size:12px;color:#dc2626;font-family:'Inter','Segoe UI',sans-serif;">Dogs + Cats cannot exceed Target Reach.</div>
        <div class="sd-dialog-footer" style="padding-top:16px;margin-top:4px;">
          <button type="button" class="sd-btn sd-btn-outline" onclick="aethCancelCreateDialog()">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-primary">Create Program</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ Program Detail Modal ═══ -->
<div class="ab-modal-backdrop" id="abCampModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:470px;gap:20px;">
      <button type="button" class="sd-dialog-close" onclick="aethCloseModal('abCampModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title" id="abCampModalTitle">Program Details</h2>
        <p class="sd-dialog-description">Review program details and manage progress</p>
      </div>
      <div id="abCampModalBody"></div>
      <div id="abCampModalFooter" class="sd-dialog-footer sd-one-row"></div>
    </div>
  </div>
</div>

<!-- ═══ Log Progress Dialog ═══ -->
<div class="ab-modal-backdrop" id="abLogProgModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:470px;gap:20px;">
      <button type="button" class="sd-dialog-close" onclick="aethCloseModal('abLogProgModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">Log Program Progress</h2>
        <p class="sd-dialog-description" id="abLogProgName">Record animals served during this program.</p>
      </div>
      <form method="POST" action="<?php echo htmlspecialchars($appBaseUrl . '/dashboard-staff.php#analytics', ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;gap:24px;">
        <input type="hidden" name="log_progress" value="1">
        <input type="hidden" name="program_id" id="abLogProgId">
        <div class="sd-field">
          <label class="sd-label">Date <span style="color:#dc2626;">*</span></label>
          <input class="sd-input" type="date" name="log_date" id="abLogProgDate" required>
        </div>

        <!-- Current Progress -->
        <div>
          <div style="font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;margin-bottom:10px;">Current Progress</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div class="info-row"><div><p class="info-row-label" style="font-size:13px;font-weight:400;color:#71717a;margin:0;">Target</p><p class="info-row-value" id="abLogTarget" style="font-size:14px;font-weight:500;color:#18181b;line-height:1.4;margin:0;">0 animals</p></div></div>
            <div class="info-row"><div><p class="info-row-label" style="font-size:13px;font-weight:400;color:#71717a;margin:0;">Served</p><p class="info-row-value" id="abLogServed" style="font-size:14px;font-weight:500;color:#0E8131;line-height:1.4;margin:0;">0 animals</p></div></div>
            <div class="info-row"><div><p class="info-row-label" style="font-size:13px;font-weight:400;color:#71717a;margin:0;">Remaining</p><p class="info-row-value" id="abLogRemaining" style="font-size:14px;font-weight:500;color:#18181b;line-height:1.4;margin:0;">0 animals</p></div></div>
          </div>
        </div>

        <div style="border-top:1px solid #e4e4e7;"></div>

        <!-- Add Progress Update -->
        <div>
          <div style="font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;margin-bottom:2px;">Add Progress Update</div>
          <p style="font-size:13px;color:#71717a;margin:0 0 12px;">Record the animals reached during this update.</p>
          <div class="sd-field-grid">
            <div class="sd-field">
              <label class="sd-label">Dogs Served</label>
              <input class="sd-input" type="number" name="dogs_served" id="abLogDogs" min="0" value="0" placeholder="0" oninput="aethUpdateLogTotal()">
            </div>
            <div class="sd-field">
              <label class="sd-label">Cats Served</label>
              <input class="sd-input" type="number" name="cats_served" id="abLogCats" min="0" value="0" placeholder="0" oninput="aethUpdateLogTotal()">
            </div>
          </div>
          <div id="abLogNewCount" style="font-size:13px;color:#71717a;margin-top:8px;">New animals reached <span style="font-weight:600;color:#18181b;">+0 animals</span></div>
        </div>

        <!-- After This Update -->
        <div id="abLogAfterWrap" style="display:none;">
          <div style="font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;margin-bottom:10px;">Progress After Update</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:10px;">
            <div class="info-row"><div><p class="info-row-label" style="font-size:13px;font-weight:400;color:#71717a;margin:0;">Served</p><p id="abLogAfterServed" style="font-size:24px;font-weight:700;color:#18181b;margin:0;">0</p></div></div>
            <div class="info-row"><div><p class="info-row-label" style="font-size:13px;font-weight:400;color:#71717a;margin:0;">Progress</p><p id="abLogAfterPct" style="font-size:24px;font-weight:700;color:#18181b;margin:0;">0%</p></div></div>
            <div class="info-row"><div><p class="info-row-label" style="font-size:13px;font-weight:400;color:#71717a;margin:0;">Remaining</p><p id="abLogAfterRemain" style="font-size:24px;font-weight:700;color:#18181b;margin:0;">0</p></div></div>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="font-size:13px;font-weight:400;color:#71717a;">Updated Progress</span><span id="abLogAfterTarget" style="font-size:13px;font-weight:400;color:#71717a;"></span></div>
          <div style="background:#e4e4e7;border-radius:4px;height:6px;overflow:hidden;">
            <div id="abLogAfterBar" style="height:100%;background:#18181b;border-radius:4px;width:0%;transition:width 0.25s ease;"></div>
          </div>
        </div>

        <!-- Notes -->
        <div class="sd-field">
          <label class="sd-label">Notes</label>
          <textarea class="sd-input" name="log_notes" rows="2" placeholder="Optional notes about this update..." style="resize:vertical;"></textarea>
        </div>

        <div class="sd-dialog-footer" style="padding-top:4px;">
          <button type="button" class="sd-btn sd-btn-outline" onclick="aethCloseModal('abLogProgModal')">Cancel</button>
          <button type="submit" class="sd-btn sd-btn-default">Save Progress</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ View Progress Dialog ═══ -->
<div class="ab-modal-backdrop" id="abViewProgModal">
  <div class="sd-dialog">
    <div class="sd-dialog-content" role="dialog" aria-modal="true" style="max-width:470px;gap:20px;">
      <button type="button" class="sd-dialog-close" onclick="aethCloseModal('abViewProgModal')" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
      </button>
      <div class="sd-dialog-header">
        <h2 class="sd-dialog-title">View Progress</h2>
        <p class="sd-dialog-description" id="abViewProgDesc">Program progress summary and update history.</p>
      </div>
      <div id="abViewProgBody" style="padding-right:2px;"></div>
      <div class="sd-dialog-footer" style="padding-top:4px;">
        <button type="button" class="sd-btn sd-btn-outline" onclick="aethCloseModal('abViewProgModal')">Close</button>
        <button type="button" class="sd-btn sd-btn-primary" id="abViewProgLogBtn">Log Progress Update</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Pause Program Confirm Dialog ═══ -->
<div id="pauseProgramModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
  <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
    <div style="margin-bottom:16px;">
      <h2 class="sd-dialog-title" id="pauseProgramTitle">Are you sure you want to pause?</h2>
      <p class="sd-dialog-description" style="margin-top:8px;">Pausing this program will temporarily stop its active progress tracking and mark it as Paused. You can resume the program later without losing its existing progress.</p>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-outline" onclick="closeModal('pauseProgramModal')">Cancel</button>
      <button class="sd-btn sd-btn-primary" id="pauseConfirmBtn" onclick="executePauseProgram()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Pause Program</button>
    </div>
  </div>
</div>

<!-- ═══ Resume Program Confirm Dialog ═══ -->
<div id="resumeProgramModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
  <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
    <div style="margin-bottom:16px;">
      <h2 class="sd-dialog-title" id="resumeProgramTitle">Are you sure you want to resume?</h2>
      <p class="sd-dialog-description" style="margin-top:8px;">Resuming this program will reactivate its progress tracking and mark it as Live. It will continue from where it was paused.</p>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-outline" onclick="closeModal('resumeProgramModal')">Cancel</button>
      <button class="sd-btn sd-btn-primary" id="resumeConfirmBtn" onclick="executeResumeProgram()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Resume Program</button>
    </div>
  </div>
</div>

<!-- ═══ Cancel Program Confirm Dialog ═══ -->
<div id="cancelProgramModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
  <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
    <div style="margin-bottom:16px;">
      <h2 class="sd-dialog-title" id="cancelProgramTitle">Are you sure you want to cancel?</h2>
      <p class="sd-dialog-description" style="margin-top:8px;">Cancelling this program will permanently mark it as Cancelled. This action cannot be undone and the program will no longer be active.</p>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-outline" onclick="closeModal('cancelProgramModal')">Keep Program</button>
      <button class="sd-btn sd-btn-primary" id="cancelConfirmBtn" onclick="executeCancelProgram()" style="background:#dc2626;color:#fff;border:1px solid #dc2626;">Cancel Program</button>
    </div>
  </div>
</div>

<!-- ═══ Mark as Completed Confirm Dialog ═══ -->
<div id="markCompletedProgramModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
  <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
    <div style="margin-bottom:16px;">
      <h2 class="sd-dialog-title" id="markCompletedProgramTitle">Mark this program as completed?</h2>
      <p class="sd-dialog-description" style="margin-top:8px;">The program has fully reached its target. Marking it as completed will finalize it as a success, and it will remain visible under City Drives.</p>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-outline" onclick="closeModal('markCompletedProgramModal')">Cancel</button>
      <button class="sd-btn sd-btn-primary" id="markCompletedConfirmBtn" onclick="executeMarkCompleted()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Mark as Completed</button>
    </div>
  </div>
</div>

<!-- ═══ Archive Program Confirm Dialog ═══ -->
<div id="archiveProgramModal" class="modal" style="background:rgba(0,0,0,0.8);padding:1rem;z-index:1050;">
  <div style="background:#fff;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);width:100%;max-width:420px;padding:24px;margin:auto;">
    <div style="margin-bottom:16px;">
      <h2 class="sd-dialog-title" id="archiveProgramTitle">Archive this program?</h2>
      <p class="sd-dialog-description" style="margin-top:8px;">Archiving will remove this cancelled program from the Programs &amp; Services list.</p>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-outline" onclick="closeModal('archiveProgramModal')">Keep in List</button>
      <button class="sd-btn sd-btn-primary" id="archiveConfirmBtn" onclick="executeArchiveProgram()" style="background:#18181b;color:#fff;border:1px solid #18181b;">Archive Program</button>
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
  var APPT_TYPES_COLORS = <?php echo json_encode(array_slice($aeth_apptTypeColors, 0, count($aeth_apptTypeNames))); ?>;
  var APPT_TYPES_COUNTS = <?php echo json_encode(array_values($aeth_apptTypeCounts)); ?>;
  var APPT_LOCS = <?php echo json_encode($aeth_apptLoc); ?>;
  var APPT_LOCS_BY_TYPE = <?php echo json_encode($aeth_apptLocByType); ?>;
  var KPI_TRENDS = <?php echo json_encode($aeth_glanceTrends); ?>;
  var ENGINE30 = <?php echo json_encode($aeth_engine30); ?>;
  var RADAR_LABELS = <?php echo json_encode($aeth_radarLabels); ?>;
  var RADAR_DATA = <?php echo json_encode($aeth_radarData); ?>;
  var RADAR_BRGY = <?php echo json_encode($aeth_radarBrgy); ?>;
  var ENGINE_YEAR_DATA = <?php echo json_encode($aeth_engineYearData); ?>;
  var AETHER_EXPORT_SOURCE = <?php echo json_encode([
      'overview' => array_map(function($card) { return ['label' => $card[0], 'value' => $card[1], 'suffix' => $card[2], 'change' => $card[3]]; }, $aeth_glanceCards),
      'typeLabels' => $aeth_radarLabels,
      'barangayDistribution' => $aeth_radarBrgy,
      'barangayBreakdown' => $aeth_brgyBreakdown,
      'generatedBy' => $fullName ?? ($_SESSION['full_name'] ?? ''),
      'generatedAt' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE); ?>;
  var APPOINTMENT_EXPORT_SOURCE = <?php echo json_encode([
      'summary' => $aeth_apptSummary,
      'typeLabels' => $aeth_apptTypeLabels,
      'typeCounts' => array_values($aeth_apptTypeCounts),
      'statuses' => array_map(function($status) { return ['label' => $status['label'], 'count' => $status['count']]; }, $aeth_apptStatuses),
      'locationsByType' => $aeth_apptLocByType,
      'segments' => $aeth_apptSegments,
      'generatedBy' => $fullName ?? ($_SESSION['full_name'] ?? ''),
      'generatedAt' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE); ?>;
  var ADOPTION_EXPORT_SOURCE = <?php echo json_encode([
      'year' => (int)date('Y'),
      'adopted' => $aeth_adoptedCount,
      'rescued' => $aeth_rescuedCount,
      'available' => $aeth_availableCount,
      'published' => $aeth_publishedCount,
      'monthlyRescued' => array_values($aeth_monthlyRescued),
      'monthlyAdopted' => array_values($aeth_monthlyAdopted),
      'rescueSources' => $aeth_rescueSources,
      'activity' => array_map(function($txn) {
          return ['title' => $txn['title'], 'detail' => $txn['detail'], 'eventAt' => $txn['event_at'], 'action' => $txn['action']];
      }, $aeth_txns),
      'generatedBy' => $fullName ?? ($_SESSION['full_name'] ?? ''),
      'generatedAt' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE); ?>;

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
      plugins: [],
      options: Object.assign(baseOpts(), { animation: false, scales: { x: { display: false }, y: { display: false } }, plugins: { legend: { display: false }, tooltip: { enabled: false } } })
    });
  }

  window.renderEngineChart = function(period) {
    var data, pct = document.getElementById('abEnginePerfSub');
    if (period === 'year') {
      var yearSel = document.getElementById('abRangeYear');
      var year = yearSel ? parseInt(yearSel.value, 10) : new Date().getFullYear();
      data = ENGINE_YEAR_DATA[String(year)] || ENGINE_YEAR_DATA[year] || [];
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
          animation: false,
          interaction: { mode: 'index', intersect: false },
          scales: {
            cases: { type: 'linear', position: 'left', beginAtZero: true, suggestedMax: 10, grid: { color: aethChartColors().grid }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 } } },
            res: { type: 'linear', position: 'right', beginAtZero: true, suggestedMax: 10, grid: { display: false }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 }, callback: function(v) { return v + 'd'; } } }
          }
        }),
        plugins: [budgetCrosshairPlugin]
      });
    }
    var rd = document.getElementById('abEngineRadar');
    if (rd) {
      charts['abEngineRadar'] = new Chart(rd, {
        type: 'radar',
        data: { labels: RADAR_LABELS, datasets: [{ data: RADAR_DATA, borderColor: '#18181b', backgroundColor: 'rgba(24,24,27,0.18)', borderWidth: 2, pointBackgroundColor: '#18181b', pointRadius: 3, pointHoverRadius: 5, hitRadius: 60 }] },
        options: Object.assign(baseOpts(), { animation: false, plugins: { legend: { display: false }, tooltip: tooltipStyle() }, interaction: { mode: 'nearest', intersect: false }, scales: { r: { beginAtZero: true, max: 10, grid: { color: aethChartColors().gridSoft }, angleLines: { color: aethChartColors().gridSoft }, pointLabels: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 } }, ticks: { display: false } } } }),
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
    chart.options.scales.r.max = 10;
    chart.update();
    if (sub) sub.textContent = item.name;
  };

  window.aethExportPdf = function() {
    var rangeButton = document.querySelector('.ab-range-btn.active');
    var range = rangeButton ? rangeButton.getAttribute('data-aethrange') : '30d';
    var period = range === 'year' ? (document.getElementById('abRangeYear')?.value || String(new Date().getFullYear())) : 'Last 30 Days';
    var barangaySelect = document.getElementById('abRadarBrgy');
    var barangayId = barangaySelect ? barangaySelect.value : '0';
    var barangayName = barangaySelect && barangaySelect.selectedOptions.length ? barangaySelect.selectedOptions[0].textContent : 'All Barangays';
    var source = JSON.parse(JSON.stringify(AETHER_EXPORT_SOURCE));
    source.reportingPeriod = period;
    source.barangayId = barangayId;
    source.barangayName = barangayName;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('analytics-export.php');
    form.target = '_blank';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'payload';
    input.value = JSON.stringify(source);
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    form.remove();
  };

  window.aethExportAppointmentsPdf = function() {
    var typeSelect = document.getElementById('abApptLocType');
    var type = typeSelect ? typeSelect.value : 'all';
    var source = JSON.parse(JSON.stringify(APPOINTMENT_EXPORT_SOURCE));
    source.reportType = 'appointments';
    source.locationType = type;
    source.locationTypeLabel = typeSelect && typeSelect.selectedOptions.length ? typeSelect.selectedOptions[0].textContent : 'All Types';
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('analytics-export.php');
    form.target = '_blank';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'payload';
    input.value = JSON.stringify(source);
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    form.remove();
  };

  window.aethExportAdoptionPdf = function() {
    var source = JSON.parse(JSON.stringify(ADOPTION_EXPORT_SOURCE));
    source.reportType = 'adoption';
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('analytics-export.php');
    form.target = '_blank';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'payload';
    input.value = JSON.stringify(source);
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    form.remove();
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
          layout: { padding: { left: 4, right: 4 } },
          plugins: {
            legend: { display: false },
            tooltip: Object.assign(tooltipStyle(), {
              callbacks: {
                title: function(items) { return items.length ? items[0].label : ''; }
              }
            })
          },
          scales: {
            x: { beginAtZero: true, grid: { color: aethChartColors().grid }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 }, precision: 0 } },
            y: {
              grid: { display: false },
              ticks: {
                color: aethChartColors().tick,
                font: { family: 'Inter', size: 11 },
                callback: function(value) {
                  var label = String(this.getLabelForValue(value));
                  var maxLength = window.innerWidth <= 768 ? 14 : (window.innerWidth <= 1024 ? 22 : 1000);
                  return label.length > maxLength ? label.slice(0, maxLength - 3) + '...' : label;
                }
              }
            }
          }
        })
      });
    }
  }
  var intelResizeFrame = 0;
  window.addEventListener('resize', function() {
    if (intelResizeFrame) cancelAnimationFrame(intelResizeFrame);
    intelResizeFrame = requestAnimationFrame(function() {
      intelResizeFrame = 0;
      var intelPage = document.getElementById('ab-page-intel');
      var analyticsSection = document.getElementById('analytics');
      if (intelPage.classList.contains('active') && analyticsSection.getClientRects().length) {
        if (charts['abIntelAge']) charts['abIntelAge'].resize();
        if (charts['abIntelLoc']) charts['abIntelLoc'].resize();
      }
    });
  });
  window.aethSetApptLoc = function(key) {
    var chart = charts['abIntelLoc'];
    if (!chart) return;
    var list = (APPT_LOCS_BY_TYPE && APPT_LOCS_BY_TYPE[key]) ? APPT_LOCS_BY_TYPE[key] : APPT_LOCS;
    chart.data.labels = list.map(function(l) { return l[0]; });
    chart.data.datasets[0].data = list.map(function(l) { return l[1]; });
    chart.update();
  };

  window.aethOpenRescueDialog = function() {
    if (typeof rrOpen === 'function') rrOpen();
  };
  window.aethOpenPoolDialog = function() {
    aethGo('pool');
  };

  function initBudget() {
    kill('abBudget');
    var bc = document.getElementById('abBudgetChart');
    if (!bc) return;
    var labels = <?php echo json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']); ?>;
    var rescuedData = <?php echo json_encode(array_values($aeth_monthlyRescued)); ?>;
    var adoptedData = <?php echo json_encode(array_values($aeth_monthlyAdopted)); ?>;
    charts['abBudgetChart'] = new Chart(bc, {
      type: 'line',
      data: { labels: labels, datasets: [
        { label: 'Rescued', data: rescuedData, borderColor: '#18181b', borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#18181b', pointHoverBorderColor: '#18181b', pointHoverBorderWidth: 2, tension: 0.35, fill: true, backgroundColor: function(c){ var rgb='24,24,27'; var ca=c.chart.chartArea; if(!ca) return 'rgba('+rgb+',0.18)'; var g=c.chart.ctx.createLinearGradient(0,ca.top,0,ca.bottom); g.addColorStop(0,'rgba('+rgb+',0.3)'); g.addColorStop(1,'rgba('+rgb+',0)'); return g; } },
        { label: 'Adopted', data: adoptedData, borderColor: '#0E8131', borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#0E8131', pointHoverBorderColor: '#0E8131', pointHoverBorderWidth: 2, tension: 0.35, fill: true, backgroundColor: function(c){ var rgb='14,129,49'; var ca=c.chart.chartArea; if(!ca) return 'rgba('+rgb+',0.18)'; var g=c.chart.ctx.createLinearGradient(0,ca.top,0,ca.bottom); g.addColorStop(0,'rgba('+rgb+',0.3)'); g.addColorStop(1,'rgba('+rgb+',0)'); return g; } }
      ]},
      options: Object.assign(baseOpts(), {
        animation: false,
        interaction: { mode: 'index', intersect: false },
        onHover: function(e, active) { if (!active.length) e.native.target.style.cursor = 'default'; else e.native.target.style.cursor = 'pointer'; },
        scales: { y: { beginAtZero: true, grid: { color: aethChartColors().grid }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 }, precision: 0 } }, x: { grid: { display: false }, ticks: { color: aethChartColors().tick, font: { family: 'Inter', size: 10 } } } },
        plugins: { legend: { display: true }, tooltip: Object.assign(tooltipStyle(), {
          intersect: false,
          mode: 'index',
          callbacks: {
            label: function(ctx) {
              return ctx.dataset.label + ': ' + Math.round(ctx.parsed.y).toLocaleString();
            }
          }
        }) }
      }),
      plugins: [budgetCrosshairPlugin]
    });
  }

  window.aethGo = function(page) {
    window.aethCurrentPage = page;
    try { localStorage.setItem('staffAnalyticsPage', page); } catch (e) {}
    var pageHashes = { engine:'cases-analytics', intel:'appointment-overview', architect:'programs-services', citydrives:'city-drives', budget:'adoption', pool:'pool' };
    var pageHash = pageHashes[page] || 'analytics';
    if (window.location.hash !== '#' + pageHash) {
      history.replaceState(null, '', window.location.pathname + window.location.search + '#' + pageHash);
    }
    document.querySelectorAll('.ab-page').forEach(function(p) { p.classList.remove('active'); });
    var el = document.getElementById('ab-page-' + page);
    if (el) el.classList.add('active');
    document.querySelectorAll('.ab-page-btn[data-aethpage]').forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-aethpage') === page); });
    if (page === 'engine') { initEngine(); initSparkAll(); aethRunCountUps(); }
    if (page === 'intel') { initIntel(); }
    if (page === 'budget') { initBudget(); }
    if (page === 'pool') { poolLoad(); }
    if (page === 'architect') { aethPlayGantt(); }
    var analyticsLabels = { engine:'Cases Analytics', intel:'Appointment Overview', architect:'Programs & Services', citydrives:'City Drives' };
    var breadcrumb = document.getElementById('breadcrumbCurrent');
    if (breadcrumb && analyticsLabels[page]) breadcrumb.textContent = analyticsLabels[page];
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
  function aethPageForHash(hash) {
    var hashMap = {
      'cases-analytics':'engine',
      'appointment-overview':'intel',
      'programs-services':'architect',
      'city-drives':'citydrives',
      engine:'engine',
      intel:'intel',
      architect:'architect',
      citydrives:'citydrives',
      adoption:'budget',
      pool:'pool'
    };
    if (hash && hash !== 'analytics') return hashMap[hash] || '';
    try {
      var savedPage = localStorage.getItem('staffAnalyticsPage');
      if (['engine', 'intel', 'architect', 'citydrives'].indexOf(savedPage) !== -1) return savedPage;
    } catch (e) {}
    return 'engine';
  }
  function initSparkAll() {
    kill('abSpark');
    var data = (typeof KPI_TRENDS !== 'undefined' && KPI_TRENDS.length)
      ? KPI_TRENDS
      : [[0,0,0,0,0,0,0,0,0,0,0,0],[0,0,0,0,0,0,0,0,0,0,0,0],[0,0,0,0,0,0,0,0,0,0,0,0],[0,0,0,0,0,0,0,0,0,0,0,0]];
    data.forEach(function(d, i) { initSpark('abSpark-' + i, d); });
  }
  window.addEventListener('resize', function() {
    Object.keys(charts).forEach(function(id) {
      if (id.indexOf('abSpark-') === 0 && charts[id]) charts[id].resize();
    });
  });
  // Render KPI values immediately, with commas and decimals preserved.
  function aethCompact(n) {
    if (Number.isInteger(n)) return n.toLocaleString('en-US');
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  }
  function aethRunCountUps() {
    document.querySelectorAll('.ab-glance-val[data-count]').forEach(function(el) {
      var end = parseFloat(el.getAttribute('data-count')) || 0;
      var suffix = el.getAttribute('data-suffix') || '';
      el.textContent = aethCompact(end) + suffix;
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
  function formatDateFull(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
  window.aethShowCampaign = function(el) {
    var id = el.getAttribute('data-camp');
    var camp = CAMPAIGNS.find(function(c) { return c.id === id; });
    if (!camp) return;
    if (camp.status === 'completed') {
      if (typeof window.portalToast === 'function') {
        window.portalToast('Program completed', 'Go to the City Drives page to view successful campaigns');
      }
      return;
    }
    var body = document.getElementById('abCampModalBody');
    var coverage = camp.location || camp.barangay_names || camp.venue || '';
    var descLine = camp.description ? '<p style="font-size:13px;color:#71717a;margin:6px 0 0;line-height:1.5;">' + camp.description + '</p>' : '';

    var remaining = camp.target > 0 ? Math.max(0, camp.target - (camp.completed || 0)) : 0;
    var progressLabel = camp.target > 0 ? camp.progress + '% of target reached' : 'Target not set';
    var progressWidth = camp.target > 0 ? camp.progress : 0;
    var progressSub = camp.target > 0 ? (camp.completed || 0) + ' of ' + camp.target + ' animals reached' : '';

    var targetReached = camp.target > 0 && (camp.completed || 0) >= camp.target;
    var canEditProgram = camp.status === 'scheduled' || camp.status === 'paused';
    var editProgramButton = canEditProgram
      ? '<button type="button" class="ab-program-edit-icon" onclick="aethEditProgram(\'' + camp.id + '\')" aria-label="Edit program" title="Edit program"><img src="' + window.dcvsStaffUrl('assets/images/edit%20icon.png') + '" alt=""></button>'
      : '';
    var logProgressButton = (camp.status === 'live' || camp.status === 'paused')
      ? '<button type="button" class="ab-program-log-icon" onclick="aethOpenLogProgress(\'' + camp.id + '\')" aria-label="Log progress" title="Log progress"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"></rect><path d="M9 3.5h6"></path><path d="M9 9h6"></path><path d="M9 13h6"></path><path d="M9 17h4"></path></svg></button>'
      : '';

    var footerHtml = '';
    if (camp.status === 'scheduled') {
      footerHtml =
        '<button class="sd-btn sd-btn-primary" onclick="aethCreateAnnouncement(' + Number(camp.program_id) + ')">Create Announcement</button>' +
        '<button class="sd-btn sd-btn-outline" style="color:#dc2626;border-color:#fecaca;" onclick="confirmCancelProgram(\'' + camp.id + '\')">Cancel</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="aethOpenViewProgress(\'' + camp.id + '\')">View Progress</button>';
    } else if (camp.status === 'live') {
      footerHtml =
        '<button class="sd-btn sd-btn-outline" onclick="confirmPauseProgram(\'' + camp.id + '\')">Pause</button>' +
        '<button class="sd-btn sd-btn-outline" style="color:#dc2626;border-color:#fecaca;" onclick="confirmCancelProgram(\'' + camp.id + '\')">Cancel</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="aethOpenViewProgress(\'' + camp.id + '\')">View Progress</button>' +
        (targetReached ? '<button class="sd-btn sd-btn-outline" onclick="confirmMarkCompleted(\'' + camp.id + '\')">Mark as Completed</button>' : '');
    } else if (camp.status === 'paused') {
      footerHtml =
        '<button class="sd-btn sd-btn-primary" onclick="confirmResumeProgram(\'' + camp.id + '\')">Resume</button>' +
        '<button class="sd-btn sd-btn-outline" style="color:#dc2626;border-color:#fecaca;" onclick="confirmCancelProgram(\'' + camp.id + '\')">Cancel</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="aethOpenViewProgress(\'' + camp.id + '\')">View Progress</button>';
    } else if (camp.status === 'completed') {
      footerHtml =
        '<button class="sd-btn sd-btn-outline" onclick="aethOpenViewProgress(\'' + camp.id + '\')">View Progress</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="confirmArchiveProgram(\'' + camp.id + '\')">Archive</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="aethCloseModal(\'abCampModal\');aethGo(\'citydrives\');">Successful Timeline</button>';
    } else if (camp.status === 'ended') {
      footerHtml =
        '<button class="sd-btn sd-btn-outline" onclick="aethOpenViewProgress(\'' + camp.id + '\')">View Progress</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="confirmArchiveProgram(\'' + camp.id + '\')">Archive</button>';
    } else if (camp.status === 'cancelled') {
      footerHtml =
        '<button class="sd-btn sd-btn-outline" onclick="confirmArchiveProgram(\'' + camp.id + '\')">Archive</button>' +
        '<button class="sd-btn sd-btn-outline" onclick="aethOpenViewProgress(\'' + camp.id + '\')">View Progress</button>';
    }

    var successBanner = '';
    if (camp.status === 'completed' && targetReached) {
      successBanner =
        '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:16px;">' +
          '<p style="font-size:14px;font-weight:500;color:#065f46;margin:0;line-height:1.5;">You\u2019ve successfully completed ' + camp.name + ' and reached ' + camp.target + (coverage ? ' at ' + coverage : '') + '.</p>' +
          '<p style="font-size:14px;font-weight:600;color:#18181b;margin:10px 0 0;">What else would you like to do?</p>' +
        '</div>';
    }

    var lbl = 'font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;';
    var val = 'font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.4;margin:0;word-break:break-word;';
    var valGreen = 'font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#0E8131;line-height:1.4;margin:0;';

    body.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:20px;">' +
        successBanner +

        /* ── Section 1: Program Identity ── */
        '<div style="background:#f9fafb;border:1px solid #e4e4e7;border-radius:8px;padding:16px;">' +
          '<div style="display:flex;align-items:center;gap:6px;"><span class="ab-status-dot ' + camp.status + '"></span><span class="sd-label" style="text-transform:uppercase;letter-spacing:0.06em;">' + camp.status + '</span></div>' +
          '<h3 style="font-size:16px;font-weight:600;color:#18181b;margin:6px 0 0;font-family:inherit;">' + camp.name + '</h3>' +
        '</div>' +

        /* ── Section 2: Location & Description ── */
        '<div>' +
          '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;"><div style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;">Location &amp; Description</div><div style="display:flex;align-items:center;gap:6px;">' + logProgressButton + editProgramButton + '</div></div>' +
          '<div class="ab-program-details-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
            '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">Location</p><p class="info-row-value" style="' + val + '">' + (coverage || '\u2014') + '</p></div></div>' +
            '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">Description</p><p class="info-row-value" style="' + val + '">' + (camp.description || '\u2014') + '</p></div></div>' +
          '</div>' +
        '</div>' +

        /* ── Section 3: Schedule ── */
        '<div>' +
          '<div style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;margin-bottom:10px;">Schedule</div>' +
          '<div class="ab-program-details-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">' +
            '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">Start Date</p><p class="info-row-value" style="' + val + '">' + formatDateFull(camp.start) + '</p></div></div>' +
            '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">End Date</p><p class="info-row-value" style="' + val + '">' + formatDateFull(camp.end) + '</p></div></div>' +
          '</div>' +
          (camp.operating_time ? '<div class="info-row" style="margin-top:12px;"><div><p class="info-row-label" style="' + lbl + '">Operating Hours</p><p class="info-row-value" style="' + val + '">' + camp.operating_time + '</p></div></div>' : '') +
        '</div>' +
        '<div style="border-top:1px solid #e4e4e7;"></div>' +

        /* ── Section 4: Progress ── */
        '<div>' +
          '<div style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;margin-bottom:10px;">Program Progress</div>' +
          (camp.target > 0 ?
            '<div class="ab-program-details-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">' +
              '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">Target Reach</p><p class="info-row-value" style="' + val + '">' + camp.target + ' animals</p></div></div>' +
              '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">Completed</p><p class="info-row-value" style="' + valGreen + '">' + (camp.completed || 0) + ' animals</p></div></div>' +
              '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">Remaining</p><p class="info-row-value" style="' + val + '">' + remaining + ' animals</p></div></div>' +
            '</div>'
          :
            '<div class="info-row" style="margin-bottom:14px;"><div><p class="info-row-label" style="' + lbl + '">Target Reach</p><p class="info-row-value" style="' + val + '">Target not set</p></div></div>'
          ) +
          '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="' + lbl + '">Progress</span><span style="' + val + '">' + camp.progress + '%</span></div>' +
          '<div class="ab-progress" style="margin-bottom:4px;"><div class="ab-progress-fill" style="width:' + progressWidth + '%;background:' + (camp.status === 'paused' ? '#EAB308' : (camp.status === 'ended' ? '#F97316' : (camp.status === 'cancelled' ? '#dc2626' : (camp.status === 'completed' ? '#0E8131' : (camp.status === 'live' ? (camp.progress >= 100 ? '#0E8131' : '#22C55E') : (camp.progress >= 100 ? '#0E8131' : '#D9A441')))))) + ';"></div></div>' +
          (progressSub ? '<div style="font-size:12px;color:#71717a;">' + progressSub + '</div>' : '') +
        '</div>' +
      '</div>';

    var footerEl = document.getElementById('abCampModalFooter');
    footerEl.innerHTML = footerHtml;
    footerEl.classList.toggle('sd-one-row', camp.status === 'scheduled');
    aethOpenModal('abCampModal');
  };

  /* Toggle barangay mode (single/multiple) in Create Program form */
  window.toggleBrgyMode = function(mode) {
    var singleWrap = document.getElementById('singleBrgyWrap');
    var chips = document.getElementById('abBrgyChips');
    var singleSelectWrap = document.getElementById('abSingleBrgySelectWrap');
    var multiSelectWrap = document.getElementById('abMultiBrgySelectWrap');
    var dropdown = document.getElementById('abBrgyDropdown');
    if (singleWrap) singleWrap.style.display = mode === 'single' ? '' : 'none';
    if (singleSelectWrap) singleSelectWrap.style.display = mode === 'single' ? '' : 'none';
    if (multiSelectWrap) multiSelectWrap.style.display = mode === 'multiple' ? '' : 'none';
    if (dropdown) dropdown.dataset.mode = mode;
    if (mode === 'single' && Object.keys(abSelectedBrgys).length > 1) {
      var firstId = Object.keys(abSelectedBrgys)[0];
      var firstName = abSelectedBrgys[firstId];
      abSelectedBrgys = {};
      abSelectedBrgys[firstId] = firstName;
    }
    if (chips) chips.style.display = mode === 'multiple' && Object.keys(abSelectedBrgys).length ? 'flex' : 'none';
    var singleSelect = document.getElementById('abSingleBrgySelect');
    if (singleSelect) {
      singleSelect.required = mode === 'single';
      singleSelect.value = mode === 'single' ? (Object.keys(abSelectedBrgys)[0] || '') : '';
    }
    aethRenderBrgyChips();
    aethUpdateBrgyChecks();
    aethSyncBrgyInputs();
  };

  var abSelectedBrgys = {};

  window.aethSelectSingleBrgy = function(select) {
    var id = select.value;
    var option = select.options[select.selectedIndex];
    abSelectedBrgys = {};
    if (id) abSelectedBrgys[id] = option.text;
    aethSyncBrgyInputs();
    aethSaveProgramDraft();
  };

  window.aethShowBrgyDropdown = function() {
    var dd = document.getElementById('abBrgyDropdown');
    if (dd) dd.style.display = '';
  };

  window.aethToggleBrgy = function(el) {
    var id = el.getAttribute('data-id');
    var name = el.getAttribute('data-name');
    var mode = document.querySelector('input[name="location_mode"]:checked');
    if (mode && mode.value === 'single') {
      abSelectedBrgys = {};
      abSelectedBrgys[id] = name;
    } else if (abSelectedBrgys[id]) {
      delete abSelectedBrgys[id];
    } else {
      abSelectedBrgys[id] = name;
    }
    aethRenderBrgyChips();
    aethUpdateBrgyChecks();
    aethSyncBrgyInputs();
    if (mode && mode.value === 'single') {
      var search = document.getElementById('abBrgySearch');
      var dropdown = document.getElementById('abBrgyDropdown');
      if (search) search.value = '';
      if (dropdown) dropdown.style.display = 'none';
      aethFilterBrgys('');
    }
    aethSaveProgramDraft();
  };

  window.aethRemoveBrgy = function(id) {
    delete abSelectedBrgys[id];
    aethRenderBrgyChips();
    aethUpdateBrgyChecks();
    aethSyncBrgyInputs();
    aethSaveProgramDraft();
  };

  function aethRenderBrgyChips() {
    var wrap = document.getElementById('abBrgyChips');
    if (!wrap) return;
    wrap.innerHTML = '';
    var keys = Object.keys(abSelectedBrgys);
    var mode = document.querySelector('input[name="location_mode"]:checked');
    wrap.style.display = mode && mode.value === 'multiple' && keys.length ? 'flex' : 'none';
    keys.forEach(function(id) {
      var chip = document.createElement('span');
      chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:3px 8px;background:#f4f4f5;border:1px solid #e4e4e7;border-radius:4px;font-size:13px;font-family:"Inter","Segoe UI",sans-serif;color:#18181b;';
      chip.textContent = abSelectedBrgys[id];
      var x = document.createElement('span');
      x.style.cssText = 'cursor:pointer;color:#71717a;font-size:14px;line-height:1;margin-left:2px;';
      x.innerHTML = '&times;';
      x.onclick = function() { aethRemoveBrgy(id); };
      chip.appendChild(x);
      wrap.appendChild(chip);
    });
  }

  function aethUpdateBrgyChecks() {
    var opts = document.querySelectorAll('#abBrgyDropdown .ab-brgy-opt');
    opts.forEach(function(el) {
      var id = el.getAttribute('data-id');
      var check = el.querySelector('.ab-brgy-check');
      if (abSelectedBrgys[id]) {
        el.setAttribute('aria-selected', 'true');
        el.style.background = '#f0fdf4';
        check.style.background = '#18181b';
        check.style.borderColor = '#18181b';
        check.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
      } else {
        el.setAttribute('aria-selected', 'false');
        el.style.background = '';
        check.style.background = '';
        check.style.borderColor = '#d4d4d8';
        check.innerHTML = '';
      }
    });
  }

  function aethSyncBrgyInputs() {
    var wrap = document.getElementById('abBrgyList');
    if (!wrap) return;
    wrap.querySelectorAll('input[name="barangay_ids[]"]').forEach(function(inp) { inp.remove(); });
    Object.keys(abSelectedBrgys).forEach(function(id) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'barangay_ids[]';
      inp.value = id;
      wrap.appendChild(inp);
    });
  }

  window.aethFilterBrgys = function(query) {
    var opts = document.querySelectorAll('#abBrgyDropdown .ab-brgy-opt');
    var noMatch = document.getElementById('abBrgyNoMatch');
    var q = query.toLowerCase().trim();
    var visible = 0;
    opts.forEach(function(el) {
      var name = el.getAttribute('data-name').toLowerCase();
      var show = q === '' || name.indexOf(q) !== -1;
      el.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
  };

  document.addEventListener('mousedown', function(e) {
    var dd = document.getElementById('abBrgyDropdown');
    var search = document.getElementById('abBrgySearch');
    if (dd && search && !dd.contains(e.target) && e.target !== search) {
      dd.style.display = 'none';
    }
  });

  function aethPopulateTimeSelects() {
    var startSel = document.getElementById('abTimeStart');
    var endSel = document.getElementById('abTimeEnd');
    if (!startSel || !endSel) return;
    for (var i = 0; i < 48; i++) {
      var h = Math.floor(i / 2);
      var m = (i % 2) * 30;
      var ap = h >= 12 ? 'PM' : 'AM';
      var hh12 = h % 12; if (hh12 === 0) hh12 = 12;
      var label = String(hh12).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ' ' + ap;
      var val = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
      var o1 = document.createElement('option'); o1.value = val; o1.textContent = label;
      var o2 = document.createElement('option'); o2.value = val; o2.textContent = label;
      startSel.appendChild(o1);
      endSel.appendChild(o2);
    }
    startSel.value = '08:00';
    endSel.value = '17:00';
    aethSyncOpTime();
  }

  window.aethConfirmAction = function(action, campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp || !camp.program_id) return;
    var label = action.charAt(0).toUpperCase() + action.slice(1);
    if (!confirm(label + ' this program?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('dashboard-staff.php') + '#analytics';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = action + '_program';
    input.value = '1';
    form.appendChild(input);
    var pid = document.createElement('input');
    pid.type = 'hidden';
    pid.name = 'program_id';
    pid.value = camp.program_id;
    form.appendChild(pid);
    document.body.appendChild(form);
    form.submit();
  };

  window.aethEditProgram = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    var dlg = document.getElementById('abNewCampModal');
    dlg.querySelector('.sd-dialog-title').textContent = 'Edit Program';
    dlg.querySelector('.sd-dialog-description').textContent = 'Update program details.';
    var form = dlg.querySelector('form');
    var hiddenCreate = form.querySelector('input[name="create_program"]');
    if (hiddenCreate) { hiddenCreate.name = 'edit_program'; hiddenCreate.value = '1'; }
    aethSetProgramDateLimits(false);
    var existingPid = form.querySelector('input[name="program_id"]');
    if (!existingPid) {
      var pid = document.createElement('input');
      pid.type = 'hidden'; pid.name = 'program_id';
      form.appendChild(pid);
    }
    form.querySelector('input[name="program_id"]').value = camp.program_id || '';
    form.querySelector('input[name="program_name"]').value = camp.name || '';
    form.querySelector('select[name="program_type"]').value = camp.program_type || 'kapon';
    form.querySelector('textarea[name="program_description"]').value = camp.description || '';
    form.querySelector('input[name="start_date"]').value = camp.start || '';
    form.querySelector('input[name="end_date"]').value = camp.end || '';
    if (camp.program_start_time) document.getElementById('abTimeStart').value = camp.program_start_time.slice(0, 5);
    if (camp.program_end_time) document.getElementById('abTimeEnd').value = camp.program_end_time.slice(0, 5);
    form.querySelector('#abTargetReach').value = camp.target || '';
    form.querySelector('#abDogsTarget').value = camp.dogs || '';
    form.querySelector('#abCatsTarget').value = camp.cats || '';
    if (camp.operating_time) {
      var parts = camp.operating_time.split('\u2014').map(function(s) { return s.trim(); });
      if (parts.length === 2) {
        var startParts = parts[0].match(/(\d+):(\d+)\s*(AM|PM)/i);
        var endParts = parts[1].match(/(\d+):(\d+)\s*(AM|PM)/i);
        if (startParts) {
          var sh = parseInt(startParts[1]); var sm = startParts[2];
          var sap = startParts[3].toUpperCase();
          if (sap === 'PM' && sh !== 12) sh += 12;
          if (sap === 'AM' && sh === 12) sh = 0;
          document.getElementById('abTimeStart').value = String(sh).padStart(2,'0') + ':' + sm;
        }
        if (endParts) {
          var eh = parseInt(endParts[1]); var em = endParts[2];
          var eap = endParts[3].toUpperCase();
          if (eap === 'PM' && eh !== 12) eh += 12;
          if (eap === 'AM' && eh === 12) eh = 0;
          document.getElementById('abTimeEnd').value = String(eh).padStart(2,'0') + ':' + em;
        }
        aethSyncOpTime();
      }
    }
    aethSyncOpTime();
    var mode = camp.location_mode || 'single';
    var radios = form.querySelectorAll('input[name="location_mode"]');
    radios.forEach(function(r) { r.checked = r.value === mode; });
    toggleBrgyMode(mode);
    if (mode === 'single') {
      document.getElementById('abVenueInput').value = camp.venue || '';
    }
    abSelectedBrgys = {};
    document.getElementById('abBrgyChips').innerHTML = '';
    var ids = (camp.barangay_ids || '').split(',').filter(Boolean);
    var names = (camp.barangay_names || '').split(',').map(function(n) { return n.trim(); });
    ids.forEach(function(bid, i) {
      abSelectedBrgys[bid] = names[i] || '';
    });
    aethRenderBrgyChips();
    aethUpdateBrgyChecks();
    aethSyncBrgyInputs();
    toggleBrgyMode(mode);
    document.getElementById('abBrgySearch').value = '';
    dlg.querySelector('.sd-btn-primary[type="submit"]').textContent = 'Save Changes';
    aethOpenModal('abNewCampModal');
    aethCheckProgramConflicts();
  };

  window.aethResetCreateDialog = function() {
    var dlg = document.getElementById('abNewCampModal');
    dlg.querySelector('.sd-dialog-title').textContent = 'Create New Program';
    dlg.querySelector('.sd-dialog-description').textContent = 'Set up a new city drive or veterinary program.';
    var form = dlg.querySelector('form');
    var hiddenEdit = form.querySelector('input[name="edit_program"]');
    if (hiddenEdit) { hiddenEdit.name = 'create_program'; hiddenEdit.value = '1'; }
    var pidField = form.querySelector('input[name="program_id"]');
    if (pidField) pidField.remove();
    form.reset();
    aethSetProgramDateLimits(true);
    aethCheckProgramConflicts();
    abSelectedBrgys = {};
    document.getElementById('abBrgyChips').innerHTML = '';
    toggleBrgyMode('single');
    aethUpdateBrgyChecks();
    document.getElementById('abTimeStart').value = '08:00';
    document.getElementById('abTimeEnd').value = '17:00';
    aethSyncOpTime();
    dlg.querySelector('.sd-btn-primary[type="submit"]').textContent = 'Create Program';
  };

  window.aethCancelCreateDialog = function() {
    try {
      localStorage.removeItem(programDraftKey);
    } catch (error) {
      console.error('Unable to discard the program form draft:', error);
    }
    aethResetCreateDialog();
    aethCloseModal('abNewCampModal');
  };

  window.confirmPauseProgram = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    document.getElementById('pauseProgramTitle').textContent = 'Are you sure you want to pause ' + camp.name + '?';
    document.getElementById('pauseConfirmBtn').dataset.id = camp.program_id;
    openModal('pauseProgramModal');
  };

  window.executePauseProgram = function() {
    var btn = document.getElementById('pauseConfirmBtn');
    var progId = btn.dataset.id;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Pausing...';
    btn.disabled = true;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('dashboard-staff.php') + '#analytics';
    var input = document.createElement('input');
    input.type = 'hidden'; input.name = 'pause_program'; input.value = '1';
    form.appendChild(input);
    var pid = document.createElement('input');
    pid.type = 'hidden'; pid.name = 'program_id'; pid.value = progId;
    form.appendChild(pid);
    document.body.appendChild(form);
    form.submit();
  };

  window.confirmResumeProgram = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    document.getElementById('resumeProgramTitle').textContent = 'Are you sure you want to resume ' + camp.name + '?';
    document.getElementById('resumeConfirmBtn').dataset.id = camp.program_id;
    openModal('resumeProgramModal');
  };

  window.executeResumeProgram = function() {
    var btn = document.getElementById('resumeConfirmBtn');
    var progId = btn.dataset.id;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resuming...';
    btn.disabled = true;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('dashboard-staff.php') + '#analytics';
    var input = document.createElement('input');
    input.type = 'hidden'; input.name = 'resume_program'; input.value = '1';
    form.appendChild(input);
    var pid = document.createElement('input');
    pid.type = 'hidden'; pid.name = 'program_id'; pid.value = progId;
    form.appendChild(pid);
    document.body.appendChild(form);
    form.submit();
  };

  window.confirmCancelProgram = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    document.getElementById('cancelProgramTitle').textContent = 'Are you sure you want to cancel ' + camp.name + '?';
    document.getElementById('cancelConfirmBtn').dataset.id = camp.program_id;
    openModal('cancelProgramModal');
  };

  window.executeCancelProgram = function() {
    var btn = document.getElementById('cancelConfirmBtn');
    var progId = btn.dataset.id;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
    btn.disabled = true;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('dashboard-staff.php') + '#analytics';
    var input = document.createElement('input');
    input.type = 'hidden'; input.name = 'cancel_program'; input.value = '1';
    form.appendChild(input);
    var pid = document.createElement('input');
    pid.type = 'hidden'; pid.name = 'program_id'; pid.value = progId;
    form.appendChild(pid);
    document.body.appendChild(form);
    form.submit();
  };

  window.confirmMarkCompleted = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    document.getElementById('markCompletedProgramTitle').textContent = 'Mark ' + camp.name + ' as completed?';
    document.getElementById('markCompletedConfirmBtn').dataset.id = camp.program_id;
    openModal('markCompletedProgramModal');
  };

  window.executeMarkCompleted = function() {
    var btn = document.getElementById('markCompletedConfirmBtn');
    var progId = btn.dataset.id;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    btn.disabled = true;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('dashboard-staff.php') + '#analytics';
    var input = document.createElement('input');
    input.type = 'hidden'; input.name = 'mark_completed'; input.value = '1';
    form.appendChild(input);
    var pid = document.createElement('input');
    pid.type = 'hidden'; pid.name = 'program_id'; pid.value = progId;
    form.appendChild(pid);
    document.body.appendChild(form);
    form.submit();
  };

  window.confirmArchiveProgram = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    document.getElementById('archiveProgramTitle').textContent = 'Archive ' + camp.name + '?';
    document.getElementById('archiveConfirmBtn').dataset.id = camp.program_id;
    openModal('archiveProgramModal');
  };

  window.executeArchiveProgram = function() {
    var btn = document.getElementById('archiveConfirmBtn');
    var progId = btn.dataset.id;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
    btn.disabled = true;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.dcvsStaffUrl('dashboard-staff.php') + '#analytics';
    var input = document.createElement('input');
    input.type = 'hidden'; input.name = 'archive_program'; input.value = '1';
    form.appendChild(input);
    var pid = document.createElement('input');
    pid.type = 'hidden'; pid.name = 'program_id'; pid.value = progId;
    form.appendChild(pid);
    document.body.appendChild(form);
    form.submit();
  };

  window.aethCreateAnnouncement = function(programId) {
    var id = Number(programId);
    if (!Number.isSafeInteger(id) || id < 1) return;
    var params = new URLSearchParams();
    params.set('create_from_program', String(id));
    try { localStorage.removeItem('sb2State'); } catch(e) {}
    window.location.href = window.dcvsStaffUrl('staff-announcements.php') + '?' + params.toString();
  };

  var abLogProgState = { target: 0, completed: 0 };

  window.aethOpenLogProgress = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    aethCloseModal('abViewProgModal');
    var target = camp.target || 0;
    var completed = camp.completed || 0;
    abLogProgState = { target: target, completed: completed };
    var remaining = Math.max(0, target - completed);
    var pct = target > 0 ? Math.round(completed / target * 100) : 0;
    document.getElementById('abLogProgId').value = camp.program_id || '';
    document.getElementById('abLogProgName').textContent = camp.name;
    document.getElementById('abLogProgDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('abLogDogs').value = 0;
    document.getElementById('abLogCats').value = 0;
    document.getElementById('abLogTarget').textContent = target > 0 ? target + ' animals' : '—';
    document.getElementById('abLogServed').textContent = completed + ' animals';
    document.getElementById('abLogRemaining').textContent = target > 0 ? remaining + ' animals' : '—';
    var notesField = document.querySelector('#abLogProgModal textarea[name="log_notes"]');
    if (notesField) notesField.value = '';
    aethUpdateLogTotal();
    aethOpenModal('abLogProgModal');
  };

  window.aethUpdateLogTotal = function() {
    var d = parseInt(document.getElementById('abLogDogs').value) || 0;
    var c = parseInt(document.getElementById('abLogCats').value) || 0;
    var newAnimals = d + c;
    var target = abLogProgState.target;
    var completed = abLogProgState.completed;
    document.getElementById('abLogNewCount').innerHTML = 'New animals reached <span style="font-weight:600;color:#18181b;">+' + newAnimals + ' animals</span>';
    var afterWrap = document.getElementById('abLogAfterWrap');
    if (newAnimals > 0) {
      var newTotal = completed + newAnimals;
      var newPct = target > 0 ? Math.min(100, Math.round(newTotal / target * 100)) : 0;
      var newRemaining = target > 0 ? Math.max(0, target - newTotal) : 0;
      afterWrap.style.display = '';
      document.getElementById('abLogAfterServed').textContent = newTotal;
      document.getElementById('abLogAfterPct').textContent = target > 0 ? newPct + '%' : '—';
      document.getElementById('abLogAfterRemain').textContent = target > 0 ? newRemaining : '—';
      document.getElementById('abLogAfterBar').style.width = (target > 0 ? newPct : 0) + '%';
      document.getElementById('abLogAfterTarget').textContent = target > 0 ? 'Target: ' + target + ' animals' : 'No target set';
    } else {
      afterWrap.style.display = 'none';
    }
  };

  function vpEsc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  window.aethOpenViewProgress = function(campId) {
    var camp = CAMPAIGNS.find(function(c) { return c.id === campId; });
    if (!camp) return;
    aethCloseModal('abCampModal');
    var target = camp.target || 0;
    var achieved = camp.completed || 0;
    var remaining = target > 0 ? Math.max(0, target - achieved) : 0;
    var pct = target > 0 ? Math.min(100, Math.round(achieved / target * 100)) : 0;
    var barColor = camp.status === 'paused' ? '#EAB308' : (camp.status === 'ended' ? '#F97316' : (camp.status === 'cancelled' ? '#dc2626' : (camp.status === 'completed' ? '#0E8131' : (camp.status === 'live' ? ((target > 0 && pct >= 100) ? '#0E8131' : '#22C55E') : ((target > 0 && pct >= 100) ? '#0E8131' : '#D9A441')))));
    var statusText = (camp.status || '').charAt(0).toUpperCase() + (camp.status || '').slice(1);

    var lbl = 'font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;';
    var val = 'font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.4;margin:0;word-break:break-word;';

    function field(label, value) {
      return '<div class="info-row"><div><p class="info-row-label" style="' + lbl + '">' + label + '</p><p class="info-row-value" style="' + val + '">' + value + '</p></div></div>';
    }

    var summary =
      '<div style="background:#f9fafb;border:1px solid #e4e4e7;border-radius:8px;padding:16px;margin-bottom:16px;">' +
        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
          '<div style="display:flex;align-items:center;gap:6px;min-width:0;"><span class="ab-status-dot ' + camp.status + '"></span><span style="font-size:15px;font-weight:600;color:#18181b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + vpEsc(camp.name) + '</span></div>' +
          '<span style="flex-shrink:0;font-size:12px;font-weight:600;color:#71717a;background:#fff;border:1px solid #e4e4e7;border-radius:999px;padding:3px 10px;text-transform:capitalize;">' + vpEsc(statusText) + '</span>' +
        '</div>' +
        '<p style="font-size:13px;color:#71717a;margin:6px 0 0;">Runs ' + formatDateFull(camp.start) + ' – ' + formatDateFull(camp.end) + '</p>' +
      '</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 16px;">' +
        field('Program Name', vpEsc(camp.name)) +
        field('Status', vpEsc(statusText)) +
        field('Start Date', formatDateFull(camp.start)) +
        field('End Date', formatDateFull(camp.end)) +
        field('Target', target > 0 ? target + ' animals' : 'Not set') +
        field('Achieved / Reached', achieved + ' animals') +
        field('Remaining Target', target > 0 ? remaining + ' animals' : '—') +
        field('Progress', target > 0 ? pct + '%' : '—') +
      '</div>' +
      '<div style="margin-top:14px;">' +
        '<div style="display:flex;justify-content:space-between;margin-bottom:6px;"><span style="' + lbl + '">Progress</span><span style="' + val + '">' + (target > 0 ? pct + '% of target reached' : 'No target set') + '</span></div>' +
        '<div class="ab-progress"><div class="ab-progress-fill" style="width:' + (target > 0 ? pct : 0) + '%;background:' + barColor + ';"></div></div>' +
        (target > 0 ? '<div style="font-size:12px;color:#71717a;margin-top:6px;">' + achieved + ' of ' + target + ' animals reached</div>' : '') +
      '</div>';

    var logs = camp.progress_logs || [];
    var historyHtml;
    if (!logs.length) {
      historyHtml = '<div style="text-align:center;padding:20px 8px;color:#a1a1aa;font-size:13px;">No progress updates yet.</div>';
    } else {
      var run = 0;
      var rows = logs.map(function(l) {
        var d = parseInt(l.dogs_served, 10) || 0;
        var c2 = parseInt(l.cats_served, 10) || 0;
        run += d + c2;
        var cpct = target > 0 ? Math.min(100, Math.round(run / target * 100)) : 0;
        return { log: l, dogs: d, cats: c2, total: d + c2, cum: run, pct: cpct };
      }).reverse();
      historyHtml = rows.map(function(r) {
        return '<div style="display:flex;gap:10px;padding:12px 0;border-top:1px solid #f0f0f1;">' +
          '<span style="width:8px;height:8px;border-radius:50%;background:' + (r.pct >= 100 ? '#0E8131' : (camp.status === 'ended' ? '#F97316' : (camp.status === 'cancelled' ? '#dc2626' : (camp.status === 'paused' ? '#EAB308' : (camp.status === 'completed' ? '#0E8131' : (camp.status === 'live' ? '#22C55E' : '#D9A441')))))) + ';margin-top:6px;flex-shrink:0;"></span>' +
          '<div style="flex:1;min-width:0;">' +
            '<div style="display:flex;justify-content:space-between;gap:8px;">' +
              '<span style="font-size:13px;font-weight:600;color:#18181b;">+' + r.dogs + ' dogs, +' + r.cats + ' cats (+' + r.total + ' animals)</span>' +
              '<span style="font-size:12px;color:#71717a;white-space:nowrap;">' + formatDateFull(r.log.log_date) + '</span>' +
            '</div>' +
            '<div style="font-size:12px;color:#71717a;margin-top:3px;">Cumulative: ' + r.cum + (target > 0 ? ' / ' + target + ' animals · ' + r.pct + '%' : ' animals') + '</div>' +
            (r.log.notes ? '<div style="font-size:12px;color:#71717a;margin-top:3px;">' + vpEsc(r.log.notes) + '</div>' : '') +
            '<div style="font-size:11px;color:#a1a1aa;margin-top:3px;">Logged by ' + vpEsc(r.log.logger || '—') + '</div>' +
          '</div>' +
        '</div>';
      }).join('');
    }

    var bodyHtml =
      summary +
      '<div style="margin-top:18px;">' +
        '<div style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;letter-spacing:0;color:#18181b;margin-bottom:2px;">Progress History</div>' +
        '<p style="font-size:12px;color:#71717a;margin:0 0 4px;">How the program progressed over time — most recent first.</p>' +
        historyHtml +
      '</div>';

    document.getElementById('abViewProgDesc').textContent = camp.name;
    document.getElementById('abViewProgBody').innerHTML = bodyHtml;
    document.getElementById('abViewProgLogBtn').onclick = function() { aethOpenLogProgress(camp.id); };
    aethOpenModal('abViewProgModal');
  };

  window.aethSyncOpTime = function() {
    var s = document.getElementById('abTimeStart');
    var e = document.getElementById('abTimeEnd');
    var h = document.getElementById('abOpTimeHidden');
    var startField = document.getElementById('abProgramStartTime');
    var endField = document.getElementById('abProgramEndTime');
    if (!s || !e || !h || !startField || !endField) return;
    h.value = s.options[s.selectedIndex].text + ' \u2014 ' + e.options[e.selectedIndex].text;
    startField.value = s.value;
    endField.value = e.value;
    aethCheckProgramConflicts();
  };

  var abConflictRequest = 0;
  var programDraftKey = 'dcvsStaffProgramDraft:' + <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
  var programForm = document.querySelector('#abNewCampModal form');

  function aethSetProgramDateLimits(isCreate) {
    if (!programForm) return;
    var startDate = programForm.querySelector('input[name="start_date"]');
    var endDate = programForm.querySelector('input[name="end_date"]');
    if (!startDate || !endDate) return;
    if (!isCreate) {
      startDate.removeAttribute('min');
      endDate.removeAttribute('min');
      return;
    }
    var today = new Date();
    var minDate = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    startDate.min = minDate;
    endDate.min = startDate.value && startDate.value > minDate ? startDate.value : minDate;
    if (endDate.value && endDate.value < endDate.min) endDate.value = '';
  }

  function aethHasProgramDraftDetails(fields, barangayIds) {
    function firstValue(name) {
      var values = fields[name];
      return Array.isArray(values) && values.length ? String(values[0]).trim() : '';
    }
    var detailFields = ['program_name', 'program_description', 'start_date', 'end_date', 'venue'];
    if (detailFields.some(function(name) { return firstValue(name) !== ''; })) return true;
    if (firstValue('operating_time') && firstValue('operating_time') !== '08:00 AM \u2014 05:00 PM') return true;
    if (['target_reach', 'dogs_target', 'cats_target'].some(function(name) {
      var value = firstValue(name);
      return value !== '' && value !== '0';
    })) return true;
    return Array.isArray(barangayIds) && barangayIds.length > 0;
  }

  function aethSaveProgramDraft() {
    if (!programForm || !programForm.querySelector('input[name="create_program"]')) return;
    var fields = {};
    Array.prototype.forEach.call(programForm.elements, function(field) {
      if (!field.name || field.name === 'create_program' || field.name === 'program_id' || field.name === 'barangay_ids[]' ||
          field.type === 'file' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return;
      if ((field.type === 'radio' || field.type === 'checkbox') && !field.checked) return;
      if (!fields[field.name]) fields[field.name] = [];
      fields[field.name].push(field.value);
    });
    if (!aethHasProgramDraftDetails(fields, Object.keys(abSelectedBrgys))) {
      try {
        localStorage.removeItem(programDraftKey);
      } catch (error) {
        console.error('Unable to clear an empty program form draft:', error);
      }
      return;
    }
    var draft = {
      fields: fields,
      timeStart: document.getElementById('abTimeStart').value,
      timeEnd: document.getElementById('abTimeEnd').value,
      barangayIds: Object.keys(abSelectedBrgys)
    };
    try {
      localStorage.setItem(programDraftKey, JSON.stringify(draft));
    } catch (error) {
      console.error('Unable to save the program form draft:', error);
    }
  }

  function aethRestoreProgramDraft() {
    if (!programForm || !programForm.querySelector('input[name="create_program"]')) return false;
    var draft;
    try {
      draft = JSON.parse(localStorage.getItem(programDraftKey) || 'null');
    } catch (error) {
      console.error('Unable to read the program form draft:', error);
      return false;
    }
    if (!draft || !draft.fields) return false;
    if (!aethHasProgramDraftDetails(draft.fields, draft.barangayIds)) {
      try {
        localStorage.removeItem(programDraftKey);
      } catch (error) {
        console.error('Unable to clear an empty saved program draft:', error);
      }
      return false;
    }

    Object.keys(draft.fields).forEach(function(name) {
      var values = draft.fields[name];
      var fields = Array.prototype.filter.call(programForm.elements, function(field) {
        return field.name === name;
      });
      fields.forEach(function(field) {
        if (field.type === 'radio' || field.type === 'checkbox') {
          field.checked = values.indexOf(field.value) !== -1;
        } else if (values.length) {
          field.value = values[0];
        }
      });
    });

    var mode = (draft.fields.location_mode || ['single'])[0];
    programForm.querySelectorAll('input[name="location_mode"]').forEach(function(radio) {
      radio.checked = radio.value === mode;
    });
    toggleBrgyMode(mode);
    abSelectedBrgys = {};
    (draft.barangayIds || []).forEach(function(id) {
      var option = Array.prototype.find.call(document.querySelectorAll('#abBrgyDropdown .ab-brgy-opt'), function(el) {
        return el.getAttribute('data-id') === String(id);
      });
      if (option) abSelectedBrgys[id] = option.getAttribute('data-name') || '';
    });
    aethRenderBrgyChips();
    aethUpdateBrgyChecks();
    aethSyncBrgyInputs();
    toggleBrgyMode(mode);
    if (draft.timeStart) document.getElementById('abTimeStart').value = draft.timeStart;
    if (draft.timeEnd) document.getElementById('abTimeEnd').value = draft.timeEnd;
    aethSyncOpTime();
    return true;
  }

  function aethCheckProgramConflicts() {
    var form = programForm;
    var summary = document.getElementById('abProgramConflictSummary');
    if (!form || !summary) return;
    var start = form.querySelector('input[name="start_date"]').value;
    var end = form.querySelector('input[name="end_date"]').value;
    var startTime = form.querySelector('input[name="program_start_time"]').value;
    var endTime = form.querySelector('input[name="program_end_time"]').value;
    var conflictChoices = document.getElementById('abProgramConflictChoices');
    var notifyInput = document.getElementById('abNotifyAffectedInput');
    var keepChoice = document.getElementById('abKeepAppointmentsChoice');
    var rescheduleChoice = document.getElementById('abRescheduleAppointmentsChoice');
    var offersInput = document.getElementById('abRescheduleOffersJson');
    if (!start || !end || !startTime || !endTime) {
      summary.hidden = true;
      if (conflictChoices) conflictChoices.hidden = true;
      if (notifyInput) notifyInput.checked = false;
      if (keepChoice) keepChoice.checked = false;
      if (rescheduleChoice) rescheduleChoice.checked = false;
      if (offersInput) offersInput.value = '[]';
      return;
    }

    var requestId = ++abConflictRequest;
    if (keepChoice) keepChoice.checked = false;
    if (rescheduleChoice) rescheduleChoice.checked = false;
    if (notifyInput) notifyInput.checked = false;
    if (offersInput) offersInput.value = '[]';
    summary.hidden = false;
    summary.style.background = '#f4f4f5';
    summary.style.color = '#52525b';
    summary.textContent = 'Checking appointments and reports for these dates...';
    var programId = form.querySelector('input[name="program_id"]');
    var params = new URLSearchParams({
      start_date: start,
      end_date: end,
      start_time: startTime,
      end_time: endTime
    });
    if (programId && programId.value) params.set('program_id', programId.value);
    var selectedBarangayIds = Object.keys(abSelectedBrgys);
    if (selectedBarangayIds.length) params.set('barangay_ids', selectedBarangayIds.join(','));

    fetch(window.dcvsStaffUrl('program-conflicts.php') + '?' + params.toString())
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (requestId !== abConflictRequest) return;
        if (!data.success) throw new Error(data.message || 'Could not check the selected dates.');
        summary.replaceChildren();
        var appointments = data.appointments || [];
        var reports = data.reports || [];
        if (appointments.length) {
          summary.style.background = '#fff';
          summary.style.color = '#1e293b';
          summary.style.borderColor = '#dbe3ec';
          var statusCounts = {};
          appointments.forEach(function(item) {
            var label = String(item.status || 'active').replace(/_/g, ' ');
            label = label.charAt(0).toUpperCase() + label.slice(1);
            statusCounts[label] = (statusCounts[label] || 0) + 1;
          });
          var heading = document.createElement('div');
          heading.style.cssText = 'display:flex;justify-content:space-between;gap:8px;font-weight:600;color:#18181b;';
          var headingText = document.createElement('span');
          headingText.textContent = appointments.length + ' appointment' + (appointments.length === 1 ? '' : 's') + ' affected';
          var headingCount = document.createElement('span');
          headingCount.style.cssText = 'font-size:12px;font-weight:400;color:#64748b;white-space:nowrap;';
          headingCount.textContent = Object.keys(statusCounts).map(function(label) {
            return statusCounts[label] + ' ' + label;
          }).join(' · ');
          heading.appendChild(headingText);
          heading.appendChild(headingCount);
          summary.appendChild(heading);

          var context = document.createElement('p');
          context.style.cssText = 'margin:4px 0 10px;color:#334155;';
          context.textContent = 'Program days allow up to 2 routine and 3 urgent appointments each. These bookings exceed a daily limit. Existing appointments stay booked unless you choose to reschedule them.';
          summary.appendChild(context);

          var grouped = {};
          appointments.forEach(function(item) {
            if (!grouped[item.schedule_date]) grouped[item.schedule_date] = [];
            grouped[item.schedule_date].push(item);
          });
          Object.keys(grouped).sort().forEach(function(date) {
            var items = grouped[date];
            var card = document.createElement('div');
            card.style.cssText = 'border:1px solid #e2e8f0;border-radius:7px;margin-top:7px;padding:8px 10px;background:#fff;';
            var dayHeader = document.createElement('div');
            dayHeader.style.cssText = 'display:flex;justify-content:space-between;gap:8px;font-weight:600;';
            var dayDate = document.createElement('span');
            dayDate.textContent = formatProgramDate(date);
            var dayCount = document.createElement('span');
            dayCount.style.cssText = 'font-weight:400;color:#64748b;';
            dayCount.textContent = items.length + ' appointment' + (items.length === 1 ? '' : 's');
            dayHeader.appendChild(dayDate);
            dayHeader.appendChild(dayCount);
            card.appendChild(dayHeader);

            items.forEach(function(item) {
              var row = document.createElement('div');
              row.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding-top:8px;margin-top:6px;border-top:1px solid #f1f5f9;';
              var time = document.createElement('span');
              time.style.cssText = 'flex:0 0 66px;color:#475569;white-space:nowrap;';
              time.textContent = formatProgramTime(item.schedule_time);
              var detail = document.createElement('div');
              detail.style.cssText = 'flex:1;min-width:0;';
              var primary = document.createElement('div');
              primary.style.cssText = 'color:#334155;';
              var service = document.createElement('span');
              var serviceName = String(item.appointment_type || 'Appointment').replace(/_/g, ' ');
              serviceName = serviceName.charAt(0).toUpperCase() + serviceName.slice(1);
              service.textContent = serviceName + (item.pet_name ? ' for ' + item.pet_name : '');
              primary.appendChild(service);
              detail.appendChild(primary);
              var resident = document.createElement('div');
              resident.style.cssText = 'display:flex;flex-wrap:wrap;gap:0 5px;font-size:12px;color:#64748b;';
              var residentName = document.createElement('span');
              residentName.textContent = item.full_name || 'Resident';
              var reference = document.createElement('span');
              reference.textContent = item.reference_id || 'Appointment';
              resident.appendChild(residentName);
              resident.appendChild(reference);
              detail.appendChild(resident);
              var badge = document.createElement('span');
              badge.style.cssText = 'flex-shrink:0;padding:2px 7px;border-radius:5px;background:#fef3c7;color:#92400e;font-size:11px;';
              badge.textContent = String(item.status || 'pending').replace(/_/g, ' ').replace(/\b\w/g, function(letter) { return letter.toUpperCase(); });
              row.appendChild(time);
              row.appendChild(detail);
              row.appendChild(badge);
              card.appendChild(row);

              var proposal = document.createElement('div');
              proposal.className = 'ab-reschedule-proposal';
              proposal.setAttribute('data-appointment-id', item.appointment_id);
              proposal.style.cssText = 'display:none;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0 2px 76px;';
              var proposedDate = document.createElement('input');
              proposedDate.type = 'date';
              proposedDate.required = false;
              proposedDate.setAttribute('aria-label', 'Proposed date for ' + (item.reference_id || 'appointment'));
              proposedDate.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
              proposedDate.style.cssText = 'width:100%;box-sizing:border-box;padding:7px 8px;border:1px solid #cbd5e1;border-radius:6px;font:13px Inter,sans-serif;';
              var proposedTime = document.createElement('select');
              proposedTime.required = false;
              proposedTime.setAttribute('aria-label', 'Proposed time for ' + (item.reference_id || 'appointment'));
              proposedTime.style.cssText = proposedDate.style.cssText;
              [
                ['', 'Choose time'],
                ['09:00', '9:00 AM'],
                ['10:00', '10:00 AM'],
                ['11:00', '11:00 AM'],
                ['14:00', '2:00 PM'],
                ['15:00', '3:00 PM']
              ].forEach(function(optionData) {
                var option = document.createElement('option');
                option.value = optionData[0];
                option.textContent = optionData[1];
                proposedTime.appendChild(option);
              });
              proposal.appendChild(proposedDate);
              proposal.appendChild(proposedTime);
              card.appendChild(proposal);
            });
            summary.appendChild(card);
          });
          if (conflictChoices) conflictChoices.hidden = false;
        } else {
          summary.style.background = reports.length ? '#fffbeb' : '#f0fdf4';
          summary.style.color = reports.length ? '#92400e' : '#166534';
          summary.style.borderColor = reports.length ? '#fde68a' : '#bbf7d0';
          var clear = document.createElement('div');
          clear.textContent = reports.length ? 'No appointments exceed the daily capacity limits.' : 'No appointments exceed daily capacity limits or reports fall within this date range.';
          summary.appendChild(clear);
          if (conflictChoices) conflictChoices.hidden = true;
          if (notifyInput) notifyInput.checked = false;
          if (keepChoice) keepChoice.checked = false;
          if (rescheduleChoice) rescheduleChoice.checked = false;
          if (offersInput) offersInput.value = '[]';
        }
        if (reports.length) {
          var reportWarning = document.createElement('div');
          reportWarning.style.marginTop = '6px';
          reportWarning.textContent = reports.length + ' report(s) have an incident date in this range. Reports do not block program creation.';
          summary.appendChild(reportWarning);
          var reportList = document.createElement('ul');
          reportList.style.cssText = 'margin:6px 0 0;padding-left:20px;';
          reports.slice(0, 5).forEach(function(item) {
            var li = document.createElement('li');
            li.textContent = (item.reference_id || 'Report') + ' · ' + item.report_type + ' · ' + item.urgency_level + ' · ' + item.incident_at;
            reportList.appendChild(li);
          });
          summary.appendChild(reportList);
        }
      })
      .catch(function(error) {
        if (requestId !== abConflictRequest) return;
        summary.style.background = '#fffbeb';
        summary.style.color = '#92400e';
        summary.textContent = error.message + ' The server will validate conflicts when saving.';
        if (conflictChoices) conflictChoices.hidden = true;
        if (notifyInput) notifyInput.checked = false;
      });
  }

  function formatProgramDate(value) {
    var parts = String(value || '').substring(0, 10).split('-');
    if (parts.length !== 3) return value || '';
    var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return isNaN(date.getTime()) ? value : date.toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
  }

  function formatProgramTime(value) {
    var parts = String(value || '').split(':');
    if (parts.length < 2) return value || '';
    var date = new Date();
    date.setHours(Number(parts[0]), Number(parts[1]), 0, 0);
    return isNaN(date.getTime()) ? value : date.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
  }

  function syncProgramRescheduleOffers() {
    var selected = document.getElementById('abRescheduleAppointmentsChoice');
    var notify = document.getElementById('abNotifyAffectedInput');
    var offersInput = document.getElementById('abRescheduleOffersJson');
    var proposals = Array.prototype.slice.call(document.querySelectorAll('.ab-reschedule-proposal'));
    var active = !!(selected && selected.checked);
    proposals.forEach(function(proposal) {
      proposal.style.display = active ? 'grid' : 'none';
      proposal.querySelectorAll('input, select').forEach(function(field) {
        field.required = active;
      });
    });
    var keepChoice = document.getElementById('abKeepAppointmentsChoice');
    if (notify) notify.checked = active || !!(keepChoice && keepChoice.checked);
    if (!offersInput) return;
    var offers = proposals.map(function(proposal) {
      var fields = proposal.querySelectorAll('input, select');
      return {
        appointment_id: Number(proposal.getAttribute('data-appointment-id')),
        date: fields[0].value,
        time: fields[1].value
      };
    });
    offersInput.value = JSON.stringify(offers);
  }

  if (programForm) {
    var keepAppointmentsChoice = document.getElementById('abKeepAppointmentsChoice');
    var rescheduleAppointmentsChoice = document.getElementById('abRescheduleAppointmentsChoice');
    var notifyAffectedInput = document.getElementById('abNotifyAffectedInput');
    if (keepAppointmentsChoice) {
      keepAppointmentsChoice.addEventListener('change', function() {
        syncProgramRescheduleOffers();
      });
    }
    if (rescheduleAppointmentsChoice) {
      rescheduleAppointmentsChoice.addEventListener('change', syncProgramRescheduleOffers);
    }
    programForm.addEventListener('input', aethSaveProgramDraft);
    programForm.addEventListener('change', aethSaveProgramDraft);
    programForm.addEventListener('submit', aethSaveProgramDraft);
    programForm.addEventListener('input', syncProgramRescheduleOffers);
    programForm.addEventListener('change', syncProgramRescheduleOffers);
    programForm.addEventListener('submit', function(event) {
      syncProgramRescheduleOffers();
      if (!rescheduleAppointmentsChoice || !rescheduleAppointmentsChoice.checked) return;
      var proposals = Array.prototype.slice.call(document.querySelectorAll('.ab-reschedule-proposal'));
      var incomplete = proposals.some(function(proposal) {
        var fields = proposal.querySelectorAll('input, select');
        return !fields[0].value || !fields[1].value;
      });
      if (!proposals.length || incomplete) {
        event.preventDefault();
        alert('Choose a proposed date and time for every affected appointment.');
      }
    });
    var startDateInput = programForm.querySelector('input[name="start_date"]');
    var endDateInput = programForm.querySelector('input[name="end_date"]');
    if (startDateInput) {
      startDateInput.addEventListener('input', function() { aethSetProgramDateLimits(!!programForm.querySelector('input[name="create_program"]')); });
      startDateInput.addEventListener('change', function() { aethSetProgramDateLimits(!!programForm.querySelector('input[name="create_program"]')); });
    }
    if (endDateInput) {
      endDateInput.addEventListener('focus', function() { aethSetProgramDateLimits(!!programForm.querySelector('input[name="create_program"]')); });
    }
    programForm.querySelectorAll('input[name="start_date"], input[name="end_date"], #abTimeStart, #abTimeEnd').forEach(function(input) {
      input.addEventListener('change', aethCheckProgramConflicts);
    });
  }

  /* City Drives table pagination (shadcn style) */
  var cdPag = { page: 1, perPage: 10 };
  function cdPagRows() {
    var tb = document.querySelector('#ab-page-citydrives tbody');
    return tb ? Array.prototype.slice.call(tb.querySelectorAll('tr')) : [];
  }
  function cdPagRender() {
    var rows = cdPagRows();
    if (!rows.length) return;
    var pages = Math.max(1, Math.ceil(rows.length / cdPag.perPage));
    if (cdPag.page > pages) cdPag.page = pages;
    if (cdPag.page < 1) cdPag.page = 1;
    var start = (cdPag.page - 1) * cdPag.perPage, end = start + cdPag.perPage;
    rows.forEach(function(tr, i) { tr.style.display = (i >= start && i < end) ? '' : 'none'; });
    var lbl = document.getElementById('cdPageLabel');
    if (lbl) lbl.textContent = 'Page ' + cdPag.page + ' of ' + pages;
    var states = { First: cdPag.page <= 1, Prev: cdPag.page <= 1, Next: cdPag.page >= pages, Last: cdPag.page >= pages };
    Object.keys(states).forEach(function(k) {
      var b = document.getElementById('cdPage' + k);
      if (b) b.disabled = states[k];
    });
  }
  window.cdSetPerPage = function(v) { cdPag.perPage = parseInt(v, 10) || 10; cdPag.page = 1; cdPagRender(); };
  window.cdPagGo = function(where) {
    var pages = Math.max(1, Math.ceil(cdPagRows().length / cdPag.perPage));
    if (where === 'first') cdPag.page = 1;
    else if (where === 'prev') cdPag.page = Math.max(1, cdPag.page - 1);
    else if (where === 'next') cdPag.page = Math.min(pages, cdPag.page + 1);
    else if (where === 'last') cdPag.page = pages;
    cdPagRender();
  };

  /* Init */
  aethInitTheme();
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart !== 'undefined') { initSparkAll(); aethRunCountUps(); }
    aethPopulateTimeSelects();
    aethSetProgramDateLimits(true);
    var programCreatedOnLoad = <?php echo $aeth_programCreated ? 'true' : 'false'; ?>;
    if (programCreatedOnLoad) {
      try {
        localStorage.removeItem(programDraftKey);
      } catch (error) {
        console.error('Unable to clear the submitted program form draft:', error);
      }
    } else if (aethRestoreProgramDraft()) {
      aethOpenModal('abNewCampModal');
    }
    cdPagRender();
    var h = window.location.hash.replace('#', '');
    var initialPage = aethPageForHash(h);
    if (initialPage) { aethGo(initialPage); }
  });
  window.addEventListener('hashchange', function() {
    var h = window.location.hash.replace('#', '');
    var targetPage = aethPageForHash(h);
    if (targetPage) {
      var analyticsLabels = { engine:'Cases Analytics', intel:'Appointment Overview', architect:'Programs & Services', citydrives:'City Drives' };
      if (h === 'analytics' && analyticsLabels[window.aethCurrentPage]) {
        var breadcrumb = document.getElementById('breadcrumbCurrent');
        if (breadcrumb) breadcrumb.textContent = analyticsLabels[window.aethCurrentPage];
        return;
      }
      setTimeout(function() { aethGo(targetPage); }, 80);
    }
  });
})();
</script>

<!-- Adoption Pool Drawer -->
<style>
  .ap-drawer{position:fixed;top:0;right:0;bottom:0;width:420px;max-width:92vw;background:#fff;z-index:1100;box-shadow:-1px 0 0 #e4e4e7;display:flex;flex-direction:column;font-family:'Inter','Segoe UI',sans-serif;transform:translateX(100%);transition:transform .3s cubic-bezier(.32,.72,.25,1);pointer-events:none;}
  .ap-drawer.open{transform:translateX(0);pointer-events:auto;}
  .ap-drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:1090;opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s;}
  .ap-drawer-overlay.open{opacity:1;visibility:visible;}
  .ap-drawer-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 0;}
  .ap-drawer-hd-text{flex:1;min-width:0;}
  .ap-drawer-hd-title{font-size:18px;font-weight:600;color:#18181b;line-height:1.25;letter-spacing:-0.02em;margin:0;}
  .ap-drawer-hd-sub{font-size:13px;font-weight:400;color:#71717a;margin:2px 0 0;}
  .ap-drawer-close{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#a1a1aa;cursor:pointer;transition:all .15s;flex-shrink:0;margin-top:-2px;}
  .ap-drawer-close:hover{background:#f4f4f5;color:#18181b;}
  .ap-drawer-body{flex:1;overflow-y:auto;padding:16px 24px 24px;}
  .ap-drawer-body::-webkit-scrollbar{width:4px;}
  .ap-drawer-body::-webkit-scrollbar-thumb{background:#d4d4d8;border-radius:4px;}
  .ap-search{position:relative;margin-bottom:12px;}
  .ap-search input{width:100%;height:36px;padding:0 12px 0 34px;border:1px solid #e4e4e7;border-radius:6px;font-family:inherit;font-size:13px;color:#18181b;background:#fff;outline:none;transition:border-color .15s;}
  .ap-search input:focus{border-color:#0E8131;}
  .ap-search input::placeholder{color:#a1a1aa;}
  .ap-search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#a1a1aa;font-size:13px;}
  .ap-filters{display:flex;gap:8px;margin-bottom:16px;}
  .ap-filter-select{flex:1;height:34px;padding:0 28px 0 10px;border:1px solid #e4e4e7;border-radius:6px;font-family:inherit;font-size:13px;color:#18181b;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 10px center;appearance:none;cursor:pointer;outline:none;}
  .ap-filter-select:focus{border-color:#0E8131;}
  .ap-count{font-size:12px;font-weight:500;color:#71717a;margin-bottom:12px;}
  .ap-count strong{color:#18181b;font-weight:600;}
  .ap-divider{border:none;border-top:1px solid #e4e4e7;margin:0;}
  .ap-animal{display:flex;align-items:center;gap:12px;padding:14px 0;}
  .ap-animal + .ap-animal{border-top:1px solid #f4f4f5;}
  .ap-animal-avatar{width:40px;height:40px;border-radius:8px;background:#f4f4f5;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;}
  .ap-animal-avatar img{width:100%;height:100%;object-fit:cover;}
  .ap-animal-avatar i{font-size:16px;color:#a1a1aa;}
  .ap-animal-info{flex:1;min-width:0;}
  .ap-animal-name{font-size:14px;font-weight:600;color:#18181b;margin:0;}
  .ap-animal-meta{font-size:12px;font-weight:400;color:#71717a;margin:1px 0 0;}
  .ap-animal-tags{display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;}
  .ap-tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:500;border:1px solid;}
  .ap-tag-available{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
  .ap-tag-adopted{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
  .ap-tag-withdrawn{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
  .ap-tag-listed{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
  .ap-tag-notlisted{background:#f9fafb;color:#6b7280;border-color:#e5e7eb;}
  .ap-animal-action{flex-shrink:0;}
  .ap-view-btn{height:30px;padding:0 12px;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px;}
  .ap-view-btn:hover{background:#f4f4f5;}
  .ap-promote-btn{height:30px;padding:0 12px;border-radius:6px;border:1px solid #0E8131;background:#0E8131;color:#fff;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px;}
  .ap-promote-btn:hover{background:#0d7629;}
  .ap-detail{position:absolute;inset:0;background:#fff;z-index:1;display:none;flex-direction:column;overflow:hidden;}
  .ap-detail.open{display:flex;}
  .ap-detail-hd{display:flex;align-items:center;gap:10px;padding:20px 24px 0;}
  .ap-detail-back{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#71717a;cursor:pointer;transition:all .15s;flex-shrink:0;}
  .ap-detail-back:hover{background:#f4f4f5;color:#18181b;}
  .ap-detail-title{font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.02em;flex:1;}
  .ap-detail-body{flex:1;overflow-y:auto;padding:16px 24px 24px;}
  .ap-detail-photo{width:100%;height:200px;border-radius:8px;background:#f4f4f5;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:16px;}
  .ap-detail-photo img{width:100%;height:100%;object-fit:cover;}
  .ap-detail-photo i{font-size:40px;color:#d4d4d8;}
  .ap-detail-meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
  .ap-detail-meta-item{padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;}
  .ap-detail-meta-label{font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;}
  .ap-detail-meta-val{font-size:14px;font-weight:600;color:#18181b;}
  .ap-detail-section{margin-top:16px;padding-top:16px;border-top:1px solid #f4f4f5;}
  .ap-detail-section-title{font-size:13px;font-weight:600;color:#71717a;margin-bottom:8px;}
  .ap-detail-desc{font-size:14px;color:#18181b;line-height:1.5;}
  .ap-detail-actions{padding:16px 24px;border-top:1px solid #e4e4e7;display:flex;gap:8px;}
  .ap-empty{text-align:center;padding:40px 0;color:#a1a1aa;font-size:13px;}
  .ap-empty i{font-size:24px;margin-bottom:8px;display:block;color:#d4d4d8;}

  /* Pool Page Cards */
  .pool-card{background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:16px;display:flex;align-items:center;gap:14px;transition:border-color .15s;}
  .pool-card:hover{border-color:#d4d4d8;}
  .pool-card-avatar{width:48px;height:48px;border-radius:8px;background:#f4f4f5;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;}
  .pool-card-avatar img{width:100%;height:100%;object-fit:cover;}
  .pool-card-avatar i{font-size:18px;color:#a1a1aa;}
  .pool-card-info{flex:1;min-width:0;}
  .pool-card-name{font-size:14px;font-weight:600;color:#18181b;margin:0;}
  .pool-card-meta{font-size:12px;color:#71717a;margin:2px 0 0;}
  .pool-card-tags{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;}
  .pool-card-actions{display:flex;gap:6px;flex-shrink:0;}
</style>

<div class="ap-drawer-overlay" id="apDrawerOverlay" onclick="apCloseDrawer()"></div>
<div id="apDrawer" class="ap-drawer">
  <div class="ap-drawer-hd">
    <div class="ap-drawer-hd-text">
      <h2 class="ap-drawer-hd-title">Adoption Pool</h2>
      <p class="ap-drawer-hd-sub"><span id="apRescued">0</span> rescued · <span id="apAvailable">0</span> available</p>
    </div>
    <button class="ap-drawer-close" onclick="apCloseDrawer()" aria-label="Close">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
  </div>
  <div class="ap-drawer-body">
    <div class="ap-search">
      <i class="fas fa-search ap-search-icon"></i>
      <input type="text" id="apSearchInput" placeholder="Search animals..." oninput="apFilterAnimals()">
    </div>
    <div class="ap-filters">
      <select class="ap-filter-select" id="apFilterStatus" onchange="apFilterAnimals()">
        <option value="">All Statuses</option>
        <option value="rescued">Rescued</option>
        <option value="available">Available</option>
        <option value="adopted">Adopted</option>
        <option value="withdrawn">Withdrawn</option>
      </select>
    </div>
    <div class="ap-count" id="apResultCount"></div>
    <div id="apAnimalList"></div>

    <!-- Animal Detail Sub-Panel -->
    <div class="ap-detail" id="apDetail">
      <div class="ap-detail-hd">
        <button class="ap-detail-back" onclick="apCloseDetail()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <span class="ap-detail-title" id="apDetailTitle">Animal</span>
      </div>
      <div class="ap-detail-body">
        <div class="ap-detail-photo" id="apDetailPhoto"><i class="fas fa-paw"></i></div>
        <div class="ap-detail-meta" id="apDetailMeta"></div>
        <div class="ap-detail-section">
          <div class="ap-detail-section-title">Description</div>
          <p class="ap-detail-desc" id="apDetailDesc">No description</p>
        </div>
      </div>
      <div class="ap-detail-actions" id="apDetailActions"></div>
    </div>
  </div>
</div>

<script>
(function() {
  var allAnimals = [];

  window.apOpenDrawer = function() {
    document.getElementById('apDrawerOverlay').classList.add('open');
    document.getElementById('apDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    apLoadAnimals();
  };

  window.apCloseDrawer = function() {
    document.getElementById('apDrawerOverlay').classList.remove('open');
    document.getElementById('apDrawer').classList.remove('open');
    document.body.style.overflow = '';
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('apDrawer').classList.contains('open')) {
      apCloseDrawer();
    }
  });

  function apLoadAnimals() {
    document.getElementById('apAnimalList').innerHTML = '<div class="ap-empty"><i class="fas fa-spinner fa-spin"></i>Loading...</div>';
    fetch(window.dcvsStaffUrl('geo-map/adoption-pool.php'))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        allAnimals = data.animals || [];
        document.getElementById('apRescued').textContent = data.rescued || 0;
        document.getElementById('apAvailable').textContent = data.available || 0;
        apRenderAnimals(allAnimals);
      })
      .catch(function() {
        document.getElementById('apAnimalList').innerHTML = '<div class="ap-empty"><i class="fas fa-exclamation-circle"></i>Failed to load data</div>';
      });
  }

  window.apFilterAnimals = function() {
    var q = (document.getElementById('apSearchInput').value || '').toLowerCase();
    var status = document.getElementById('apFilterStatus').value;
    var filtered = allAnimals.filter(function(a) {
      var matchQ = !q || a.name.toLowerCase().indexOf(q) !== -1 || a.species.toLowerCase().indexOf(q) !== -1 || a.breed.toLowerCase().indexOf(q) !== -1;
      var matchStatus = !status || a.status === status;
      return matchQ && matchStatus;
    });
    apRenderAnimals(filtered);
  };

  function apRenderAnimals(animals) {
    var el = document.getElementById('apAnimalList');
    document.getElementById('apResultCount').innerHTML = 'Showing <strong>' + animals.length + '</strong> of <strong>' + allAnimals.length + '</strong> animals';
    if (!animals.length) {
      el.innerHTML = '<div class="ap-empty"><i class="fas fa-paw"></i>No animals found</div>';
      return;
    }
    var html = '';
    animals.forEach(function(a) {
      var speciesIcon = a.species === 'dog' ? 'fa-dog' : a.species === 'cat' ? 'fa-cat' : 'fa-paw';
      var statusClass = 'ap-tag-' + (a.status || 'rescued');
      var statusLabel = (a.status || 'rescued').charAt(0).toUpperCase() + (a.status || 'rescued').slice(1);
      var photoHtml = a.photo
        ? '<img src="' + escH(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(a.photo) : a.photo) + '" alt="">'
        : '<i class="fas ' + speciesIcon + '"></i>';
      var actionHtml = '';
      if (a.status === 'rescued') {
        actionHtml = '<div class="ap-animal-action" style="display:flex;gap:6px;"><button class="ap-view-btn" onclick="apViewAnimal(' + a.id + ')">View</button><button class="ap-promote-btn" onclick="apPromoteAnimal(' + a.id + ')">Promote</button></div>';
      } else {
        actionHtml = '<div class="ap-animal-action"><button class="ap-view-btn" onclick="apViewAnimal(' + a.id + ')">View</button></div>';
      }
      html += '<div class="ap-animal">' +
        '<div class="ap-animal-avatar">' + photoHtml + '</div>' +
        '<div class="ap-animal-info">' +
          '<p class="ap-animal-name">' + escH(a.name) + '</p>' +
          '<p class="ap-animal-meta">' + escH(a.species) + ' &middot; ' + escH(a.gender) + ' &middot; ' + escH(a.age) + (a.age === '1' ? ' year' : ' years') + '</p>' +
          '<div class="ap-animal-tags">' +
            '<span class="ap-tag ' + statusClass + '">' + statusLabel + '</span>' +
          '</div>' +
        '</div>' +
        actionHtml +
      '</div>';
    });
    el.innerHTML = html;
  }

  window.apViewAnimal = function(id) {
    var a = allAnimals.find(function(x) { return x.id === id; });
    if (!a) return;
    document.getElementById('apDetailTitle').textContent = a.name;
    var speciesIcon = a.species === 'dog' ? 'fa-dog' : a.species === 'cat' ? 'fa-cat' : 'fa-paw';
    var photoEl = document.getElementById('apDetailPhoto');
    if (a.photo) {
      photoEl.innerHTML = '<img src="' + escH(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(a.photo) : a.photo) + '" alt="">';
    } else {
      photoEl.innerHTML = '<i class="fas ' + speciesIcon + '"></i>';
    }
    document.getElementById('apDetailMeta').innerHTML =
      '<div class="ap-detail-meta-item"><div class="ap-detail-meta-label">Species</div><div class="ap-detail-meta-val">' + escH(a.species) + '</div></div>' +
      '<div class="ap-detail-meta-item"><div class="ap-detail-meta-label">Breed</div><div class="ap-detail-meta-val">' + escH(a.breed) + '</div></div>' +
      '<div class="ap-detail-meta-item"><div class="ap-detail-meta-label">Gender</div><div class="ap-detail-meta-val">' + escH(a.gender) + '</div></div>' +
      '<div class="ap-detail-meta-item"><div class="ap-detail-meta-label">Age</div><div class="ap-detail-meta-val">' + escH(a.age) + (a.age === '1' ? ' year' : ' years') + '</div></div>' +
      '<div class="ap-detail-meta-item"><div class="ap-detail-meta-label">Weight</div><div class="ap-detail-meta-val">' + escH(a.weight || 'N/A') + '</div></div>' +
      '<div class="ap-detail-meta-item"><div class="ap-detail-meta-label">Color</div><div class="ap-detail-meta-val">' + escH(a.color || 'N/A') + '</div></div>';
    document.getElementById('apDetailDesc').textContent = a.description || 'No description provided.';

    var actionsHtml = '';
    if (a.status === 'rescued') {
      actionsHtml = '<button class="ap-promote-btn" onclick="apPromoteAnimal(' + a.id + ')" style="flex:1;height:36px;font-size:14px;">Promote to Available</button>';
    }
    actionsHtml += '<button class="ap-view-btn" onclick="apCloseDetail()" style="height:36px;font-size:14px;">Close</button>';
    document.getElementById('apDetailActions').innerHTML = actionsHtml;

    document.getElementById('apDetail').classList.add('open');
  };

  window.apCloseDetail = function() {
    document.getElementById('apDetail').classList.remove('open');
  };

  window.apPromoteAnimal = function(id) {
    if (!confirm('Promote this animal to Available for adoption?')) return;
    fetch(window.dcvsStaffUrl('promote-pet-handler.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pet_id: id, status: 'available' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Promoted', { type: 'success', description: data.name + ' is now available for adoption.' });
        apCloseDetail();
        apLoadAnimals();
      } else {
        showToast('Error', { type: 'error', description: data.error || 'Failed to promote animal' });
      }
    })
    .catch(function() {
      showToast('Error', { type: 'error', description: 'Network error. Please try again.' });
    });
  };

  function escH(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }
})();

/* ── Pool Page ───────────────────────────── */
(function() {
  var poolAnimals = [];

  window.poolLoad = function() {
    var el = document.getElementById('poolAnimalList');
    el.innerHTML = '<div style="text-align:center;padding:40px;color:#a1a1aa;font-size:13px;"><i class="fas fa-spinner fa-spin" style="font-size:20px;display:block;margin-bottom:8px;color:#d4d4d8;"></i>Loading...</div>';
    fetch(window.dcvsStaffUrl('geo-map/adoption-pool.php'))
      .then(function(r) { return r.json(); })
      .then(function(data) {
        poolAnimals = data.animals || [];
        document.getElementById('poolCntRescued').textContent = data.rescued || 0;
        document.getElementById('poolCntAvailable').textContent = data.available || 0;
        document.getElementById('poolCntAdopted').textContent = data.adopted || 0;
        document.getElementById('poolCntWithdrawn').textContent = data.withdrawn || 0;
        poolRender(poolAnimals);
      })
      .catch(function() {
        el.innerHTML = '<div style="text-align:center;padding:40px;color:#a1a1aa;font-size:13px;"><i class="fas fa-exclamation-circle" style="font-size:20px;display:block;margin-bottom:8px;color:#d4d4d8;"></i>Failed to load data</div>';
      });
  };

  window.poolFilter = function() {
    var q = (document.getElementById('poolSearchInput').value || '').toLowerCase();
    var status = document.getElementById('poolFilterStatus').value;
    var filtered = poolAnimals.filter(function(a) {
      var matchQ = !q || a.name.toLowerCase().indexOf(q) !== -1 || a.species.toLowerCase().indexOf(q) !== -1 || a.breed.toLowerCase().indexOf(q) !== -1;
      var matchStatus = !status || a.status === status;
      return matchQ && matchStatus;
    });
    poolRender(filtered);
  };

  function poolRender(animals) {
    var el = document.getElementById('poolAnimalList');
    document.getElementById('poolResultCount').innerHTML = 'Showing <strong>' + animals.length + '</strong> of <strong>' + poolAnimals.length + '</strong> animals';
    if (!animals.length) {
      el.innerHTML = '<div style="text-align:center;padding:48px;color:#a1a1aa;font-size:14px;"><i class="fas fa-paw" style="font-size:28px;display:block;margin-bottom:10px;color:#d4d4d8;"></i>No animals found</div>';
      return;
    }
    var html = '';
    animals.forEach(function(a) {
      var speciesIcon = a.species === 'dog' ? 'fa-dog' : a.species === 'cat' ? 'fa-cat' : 'fa-paw';
      var statusClass = 'ap-tag-' + (a.status || 'rescued');
      var statusLabel = (a.status || 'rescued').charAt(0).toUpperCase() + (a.status || 'rescued').slice(1);
      var photoHtml = a.photo
        ? '<img src="' + escP(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(a.photo) : a.photo) + '" alt="">'
        : '<i class="fas ' + speciesIcon + '"></i>';
      var actionHtml = '';
      if (a.status === 'rescued') {
        actionHtml = '<button class="ap-view-btn" onclick="poolView(' + a.id + ')">View</button><button class="ap-promote-btn" onclick="poolPromote(' + a.id + ')">Promote</button>';
      } else {
        actionHtml = '<button class="ap-view-btn" onclick="poolView(' + a.id + ')">View</button>';
      }
      html += '<div class="pool-card">' +
        '<div class="pool-card-avatar">' + photoHtml + '</div>' +
        '<div class="pool-card-info">' +
          '<p class="pool-card-name">' + escP(a.name) + '</p>' +
          '<p class="pool-card-meta">' + escP(a.species) + ' &middot; ' + escP(a.gender) + ' &middot; ' + escP(a.age) + (a.age === '1' ? ' year' : ' years') + '</p>' +
          '<div class="pool-card-tags"><span class="ap-tag ' + statusClass + '">' + statusLabel + '</span></div>' +
        '</div>' +
        '<div class="pool-card-actions">' + actionHtml + '</div>' +
      '</div>';
    });
    el.innerHTML = html;
  }

  window.poolView = function(id) {
    var a = poolAnimals.find(function(x) { return x.id === id; });
    if (!a) return;
    document.getElementById('poolDetailTitle').textContent = a.name;
    var speciesIcon = a.species === 'dog' ? 'fa-dog' : a.species === 'cat' ? 'fa-cat' : 'fa-paw';
    var photoEl = document.getElementById('poolDetailPhoto');
    if (a.photo) {
      photoEl.innerHTML = '<img src="' + escP(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(a.photo) : a.photo) + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
    } else {
      photoEl.innerHTML = '<i class="fas ' + speciesIcon + '" style="font-size:40px;color:#d4d4d8;"></i>';
    }
    document.getElementById('poolDetailMeta').innerHTML =
      '<div style="padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;"><div style="font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;">Species</div><div style="font-size:14px;font-weight:600;color:#18181b;">' + escP(a.species) + '</div></div>' +
      '<div style="padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;"><div style="font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;">Breed</div><div style="font-size:14px;font-weight:600;color:#18181b;">' + escP(a.breed) + '</div></div>' +
      '<div style="padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;"><div style="font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;">Gender</div><div style="font-size:14px;font-weight:600;color:#18181b;">' + escP(a.gender) + '</div></div>' +
      '<div style="padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;"><div style="font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;">Age</div><div style="font-size:14px;font-weight:600;color:#18181b;">' + escP(a.age) + (a.age === '1' ? ' year' : ' years') + '</div></div>' +
      '<div style="padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;"><div style="font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;">Weight</div><div style="font-size:14px;font-weight:600;color:#18181b;">' + escP(a.weight || 'N/A') + '</div></div>' +
      '<div style="padding:12px;border-radius:8px;background:#f9fafb;border:1px solid #f4f4f5;"><div style="font-size:12px;font-weight:500;color:#71717a;margin-bottom:2px;">Color</div><div style="font-size:14px;font-weight:600;color:#18181b;">' + escP(a.color || 'N/A') + '</div></div>';
    document.getElementById('poolDetailDesc').textContent = a.description || 'No description provided.';
    var actionsHtml = '';
    if (a.status === 'rescued') {
      actionsHtml = '<button class="ap-promote-btn" onclick="poolPromote(' + a.id + ')" style="flex:1;height:36px;font-size:14px;">Promote to Available</button>';
    }
    actionsHtml += '<button class="ap-view-btn" onclick="poolCloseDetail()" style="height:36px;font-size:14px;">Close</button>';
    document.getElementById('poolDetailActions').innerHTML = actionsHtml;
    document.getElementById('poolDetailOverlay').style.display = 'flex';
  };

  window.poolCloseDetail = function() {
    document.getElementById('poolDetailOverlay').style.display = 'none';
  };

  window.poolPromote = function(id) {
    if (!confirm('Promote this animal to Available for adoption?')) return;
    fetch(window.dcvsStaffUrl('promote-pet-handler.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pet_id: id, status: 'available' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Promoted', { type: 'success', description: data.name + ' is now available for adoption.' });
        poolCloseDetail();
        poolLoad();
      } else {
        showToast('Error', { type: 'error', description: data.error || 'Failed to promote animal' });
      }
    })
    .catch(function() {
      showToast('Error', { type: 'error', description: 'Network error. Please try again.' });
    });
  };

  function escP(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }
})();
</script>

<!-- Record Rescued Animals Dialog -->
<style>
  .rr-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1200;display:none;align-items:center;justify-content:center;padding:1rem;}
  .rr-overlay.open{display:flex;}
  .rr-dialog{background:#fff;border-radius:8px;box-shadow:0 16px 48px rgba(0,0,0,.15);width:100%;max-width:480px;max-height:90vh;overflow-y:auto;font-family:'Inter','Segoe UI',sans-serif;}
  .rr-dialog-hd{padding:20px 24px 0;}
  .rr-dialog-title{font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.02em;margin:0 0 4px;}
  .rr-dialog-desc{font-size:14px;color:#71717a;margin:0;}
  .rr-dialog-body{padding:20px 24px;}
  .rr-field{margin-bottom:16px;}
  .rr-field:last-child{margin-bottom:0;}
  .rr-label{display:block;font-size:14px;font-weight:500;color:#18181b;margin-bottom:6px;}
  .rr-label-req{color:#dc2626;margin-left:2px;}
  .rr-input,.rr-select,.rr-textarea{width:100%;height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;font-family:inherit;font-size:14px;color:#18181b;background:#fff;outline:none;transition:border-color .15s;box-sizing:border-box;}
  .rr-input:focus,.rr-select:focus,.rr-textarea:focus{border-color:#0E8131;}
  .rr-input::placeholder,.rr-textarea::placeholder{color:#a1a1aa;}
  .rr-select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;}
  .rr-textarea{height:80px;padding:10px 12px;resize:vertical;}
  .rr-dialog-footer{display:flex;justify-content:flex-end;gap:8px;padding:0 24px 20px;}
  .rr-btn{height:36px;padding:0 16px;border-radius:6px;font-family:inherit;font-size:14px;font-weight:500;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;justify-content:center;gap:6px;}
  .rr-btn-cancel{background:#fff;border:1px solid #e4e4e7;color:#18181b;}
  .rr-btn-cancel:hover{background:#f4f4f5;}
  .rr-btn-submit{background:#18181b;border:1px solid #18181b;color:#fff;}
  .rr-btn-submit:hover{background:#27272a;}
  .rr-btn-submit:disabled{opacity:.5;cursor:not-allowed;}
</style>

<div class="rr-overlay" id="rrOverlay">
  <div class="rr-dialog" onclick="event.stopPropagation()">
    <div class="rr-dialog-hd">
      <h2 class="rr-dialog-title">Record Rescued Animals</h2>
      <p class="rr-dialog-desc">Record the animals rescued by DCVS.</p>
    </div>
    <form id="rrForm" onsubmit="return rrSubmit(event)">
      <div class="rr-dialog-body">
        <div class="rr-field">
          <label class="rr-label">Record Type<span class="rr-label-req">*</span></label>
          <select class="rr-select" id="rrType" required>
            <option value="rescue_intake" selected>Rescue Intake</option>
            <option value="stray_roundup">Stray Roundup</option>
            <option value="confiscated">Confiscated/Abuse Case</option>
          </select>
        </div>
        <div class="rr-field">
          <label class="rr-label">Number of Animals<span class="rr-label-req">*</span></label>
          <input type="number" class="rr-input" id="rrCount" min="1" max="100" value="1" required>
        </div>
        <div class="rr-field">
          <label class="rr-label">Rescue Source<span class="rr-label-req">*</span></label>
          <select class="rr-select" id="rrSource" required>
            <option value="">Select source...</option>
            <option value="barangay_reported">Barangay-Reported</option>
            <option value="resident_surrender">Resident Surrender</option>
            <option value="stray_roundup">Stray Roundup</option>
            <option value="shelter_transfer">Shelter Transfer</option>
            <option value="confiscated">Confiscated/Abuse Case</option>
            <option value="found_lost">Found/Lost Pet Unclaimed</option>
          </select>
        </div>
        <div class="rr-field">
          <label class="rr-label">Rescue Date<span class="rr-label-req">*</span></label>
          <input type="date" class="rr-input" id="rrDate" required>
        </div>
        <div class="rr-field">
          <label class="rr-label">Barangay<span class="rr-label-req">*</span></label>
          <select class="rr-select" id="rrBarangay" required>
            <option value="">Select barangay...</option>
            <?php
            $brgyQ = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name");
            if ($brgyQ) {
                while ($brgyR = $brgyQ->fetch_assoc()) {
                    $bid = (int)$brgyR['barangay_id'];
                    $bname = htmlspecialchars($brgyR['barangay_name']);
                    echo "<option value=\"$bid\">$bname</option>";
                }
            }
            ?>
          </select>
        </div>
        <div class="rr-field">
          <label class="rr-label">Notes</label>
          <textarea class="rr-textarea" id="rrNotes" placeholder="Optional notes..."></textarea>
        </div>
      </div>
      <div class="rr-dialog-footer">
        <button type="button" class="rr-btn rr-btn-cancel" onclick="rrClose()">Cancel</button>
        <button type="submit" class="rr-btn rr-btn-submit" id="rrSubmitBtn">Save Record</button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  window.rrOpen = function() {
    document.getElementById('rrType').value = 'rescue_intake';
    document.getElementById('rrCount').value = '1';
    document.getElementById('rrSource').value = '';
    document.getElementById('rrDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('rrBarangay').value = '';
    document.getElementById('rrNotes').value = '';
    document.getElementById('rrSubmitBtn').disabled = false;
    document.getElementById('rrSubmitBtn').textContent = 'Save Record';
    document.getElementById('rrOverlay').classList.add('open');
  };

  window.rrClose = function() {
    document.getElementById('rrOverlay').classList.remove('open');
  };

  document.getElementById('rrOverlay').addEventListener('click', function(e) {
    if (e.target === this) rrClose();
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('rrOverlay').classList.contains('open')) rrClose();
  });

  window.rrSubmit = function(e) {
    e.preventDefault();
    var btn = document.getElementById('rrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    var payload = {
      record_type: document.getElementById('rrType').value,
      animal_count: parseInt(document.getElementById('rrCount').value),
      source: document.getElementById('rrSource').value,
      rescue_date: document.getElementById('rrDate').value,
      barangay_id: parseInt(document.getElementById('rrBarangay').value),
      notes: document.getElementById('rrNotes').value
    };

    fetch(window.dcvsStaffUrl('record-rescue-handler.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        rrClose();
        showToast('Record Saved', { type: 'success', description: data.message });
        setTimeout(function() { window.location.reload(); }, 1200);
      } else {
        showToast('Error', { type: 'error', description: data.error || 'Failed to save record' });
        btn.disabled = false;
        btn.textContent = 'Save Record';
      }
    })
    .catch(function() {
      showToast('Error', { type: 'error', description: 'Network error. Please try again.' });
      btn.disabled = false;
      btn.textContent = 'Save Record';
    });

    return false;
  };
})();
</script>
</div>