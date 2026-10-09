<?php

function cpdInitials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $init = '';
    foreach ($parts as $p) { if ($p !== '') $init .= strtoupper(mb_substr($p, 0, 1)); }
    return $init !== '' ? $init : '?';
}

function cpdTypeLabel($type) {
    $t = strtolower((string)$type);
    if ($t === 'dog') return 'Dog';
    if ($t === 'cat') return 'Cat';
    return 'Other';
}

function cpdGenderLabel($gender) {
    $g = strtolower((string)$gender);
    if ($g === 'male') return 'Male';
    if ($g === 'female') return 'Female';
    return 'Unknown';
}

function cpdAgeLabel($age) {
    if ($age === null || $age === '') return 'Unknown';
    $a = (int)$age;
    if ($a <= 0) return 'Unknown';
    return $a === 1 ? '1 year' : $a . ' years';
}

function cpdVaxOk($vax) {
    $v = strtolower((string)$vax);
    return $v !== '' && strpos($v, 'vaccinated') !== false && strpos($v, 'not') === false;
}

function rptTypeLabel($t) {
    $map = ['stray' => 'Stray Animal', 'rabies' => 'Rabies', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal', 'rescue' => 'Rescue'];
    return $map[strtolower((string)$t)] ?? ucfirst($t);
}

function rptReportStatus($s, $scheduledAt) {
    $s = strtolower((string)$s);
    if ($s === 'resolved') return 'resolved';
    if ($s === 'confirmed' || $s === 'scheduled') return 'active';
    return 'pending';
}

function rptStatusLabel($s) {
    $s = strtolower((string)$s);
    if ($s === 'resolved') return 'Resolved';
    if ($s === 'active') return 'Active';
    return 'Pending Validation';
}

function rptUrgencyLabel($u) { return ucfirst((string)($u ?: 'medium')); }