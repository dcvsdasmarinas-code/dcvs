<?php
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/staff-app-base.php';
require_once __DIR__ . '/../../includes/staff-formatters.php';

$appBaseUrl = dcvsStaffAppBaseUrl();
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: ' . $appBaseUrl . '/login-staff.php');
    exit;
}

$pageTitle = 'Pet Duplicate Reviews';
$activePage = 'pet-duplicate-reviews';
$reviews = [];
$loadError = '';
$notice = $_SESSION['pet_duplicate_review_notice'] ?? '';
$noticeType = $_SESSION['pet_duplicate_review_notice_type'] ?? 'success';
unset($_SESSION['pet_duplicate_review_notice'], $_SESSION['pet_duplicate_review_notice_type']);

$stmt = $conn->prepare("
    SELECT r.review_id, r.pet_id, r.candidate_pet_id, r.match_type, r.matched_fields, r.created_at,
           new_pet.pet_name AS new_pet_name, new_pet.pet_type AS new_pet_type,
           new_pet.breed AS new_pet_breed, new_pet.age AS new_pet_age, new_pet.gender AS new_pet_gender,
           new_pet.color AS new_pet_color, new_pet.photo_url AS new_pet_photo_url,
           new_pet.date_of_birth AS new_pet_dob,
           new_pet.microchip_id AS new_pet_microchip, new_pet.owner_id AS new_owner_id,
           new_pet.created_at AS new_pet_created_at,
           new_owner.full_name AS new_owner_name, new_owner.email AS new_owner_email,
           candidate.pet_name AS candidate_name, candidate.pet_type AS candidate_type,
           candidate.breed AS candidate_breed, candidate.age AS candidate_age,
           candidate.gender AS candidate_gender, candidate.color AS candidate_color,
           candidate.photo_url AS candidate_photo_url,
           candidate.date_of_birth AS candidate_dob, candidate.microchip_id AS candidate_microchip,
           candidate.owner_id AS candidate_owner_id, candidate.created_at AS candidate_created_at,
           candidate_owner.full_name AS candidate_owner_name, candidate_owner.email AS candidate_owner_email
    FROM pet_duplicate_reviews r
    JOIN pets new_pet ON new_pet.pet_id = r.pet_id
    JOIN users new_owner ON new_owner.user_id = new_pet.owner_id
    JOIN pets candidate ON candidate.pet_id = r.candidate_pet_id
    JOIN users candidate_owner ON candidate_owner.user_id = candidate.owner_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC, r.review_id ASC
");

if (!$stmt) {
    error_log('Could not prepare pending pet duplicate review query: ' . $conn->error);
    $loadError = 'Possible duplicate profiles could not be loaded. Please contact support.';
} elseif (!$stmt->execute()) {
    error_log('Could not execute pending pet duplicate review query: ' . $stmt->error);
    $loadError = 'Possible duplicate profiles could not be loaded. Please contact support.';
    $stmt->close();
} else {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $decodedFields = json_decode((string)$row['matched_fields'], true);
        $row['matched_fields'] = is_array($decodedFields) ? $decodedFields : [];
        $reviews[] = $row;
    }
    $stmt->close();
}

function petDuplicateReviewValue($value): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : 'Not provided';
}

function petDuplicateReviewDate($value): string
{
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? petDuplicateReviewValue($value) : date('M j, Y', $timestamp);
}

function petDuplicateReviewFieldLabel(string $field): string
{
    $labels = [
        'microchip_id' => 'Microchip number',
        'pet_name' => 'Name',
        'pet_type' => 'Species',
        'breed' => 'Breed',
        'gender' => 'Gender',
        'color' => 'Color / markings',
        'date_of_birth' => 'Date of birth',
        'age' => 'Age',
    ];

    return $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
}

function petDuplicateReviewNaturalList(array $items): string
{
    $count = count($items);
    if ($count < 2) {
        return $items[0] ?? '';
    }

    $last = array_pop($items);
    return implode(', ', $items) . ($count > 2 ? ', and ' : ' and ') . $last;
}

function petDuplicateReviewCompareStatus(string $field, $newValue, $candidateValue, array $matchedFields): string
{
    if (in_array($field, $matchedFields, true)) {
        return 'match';
    }

    $newValue = trim((string)$newValue);
    $candidateValue = trim((string)$candidateValue);
    if ($newValue === '' || $candidateValue === '') {
        return 'incomplete';
    }

    return strcasecmp($newValue, $candidateValue) === 0 ? 'match' : 'different';
}

function petDuplicateReviewCaseLabel($reviewId): string
{
    return 'PD-' . str_pad((string)(int)$reviewId, 4, '0', STR_PAD_LEFT);
}

function petDuplicateReviewUrl(string $appBaseUrl, int $reviewId): string
{
    return $appBaseUrl . '/pet-duplicate-reviews.php?review_id=' . $reviewId . '#activeReview';
}

$selectedReviewId = filter_var($_GET['review_id'] ?? null, FILTER_VALIDATE_INT);
$selectedIndex = 0;
if ($selectedReviewId) {
    foreach ($reviews as $index => $review) {
        if ((int)$review['review_id'] === $selectedReviewId) {
            $selectedIndex = $index;
            break;
        }
    }
}
$selectedReview = $reviews[$selectedIndex] ?? null;
$nextReview = count($reviews) > 1 ? $reviews[($selectedIndex + 1) % count($reviews)] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> — Staff | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .pet-duplicate-page {
      --pdr-border: #e4e4e7;
      --pdr-foreground: #09090b;
      --pdr-muted: #f4f4f5;
      --pdr-muted-foreground: #71717a;
      min-height: calc(100vh - var(--topbar-h, 64px));
      margin: -1.75rem -2rem;
      padding: 24px;
      background: #fff;
      color: var(--pdr-foreground);
      font: 400 14px/1.5 Inter, "Segoe UI", ui-sans-serif, system-ui, sans-serif;
    }
    .pet-duplicate-page * { box-sizing: border-box; }
    .pdr-header,.pdr-layout,.pdr-alert { width: 100%; max-width: 1600px; margin-right: auto; margin-left: auto; }
    .pdr-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
    .pdr-header h1 { margin: 0 0 6px; color: #09090b; font-size: 24px; font-weight: 600; line-height: 1.25; letter-spacing: -.025em; }
    .pdr-header p { max-width: 760px; margin: 0; color: #71717a; font-size: 14px; line-height: 1.5; }
    .pdr-count,.pdr-match-badge,.pdr-profile-label,.pdr-strength {
      display: inline-flex; width: max-content; align-items: center; white-space: nowrap;
      border: 1px solid var(--pdr-border); border-radius: 999px;
      background: #fff; color: #3f3f46; font-size: 12px; font-weight: 500; line-height: 1.3;
    }
    .pdr-count { padding: 5px 10px; font-variant-numeric: tabular-nums; }
    .pdr-alert { margin-bottom: 16px; padding: 12px 14px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #166534; font-size: 14px; }
    .pdr-alert.error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .pdr-layout { display: grid; grid-template-columns: minmax(260px, 300px) minmax(0, 1fr); align-items: start; gap: 16px; }
    .pdr-panel { min-width: 0; border: 1px solid var(--pdr-border); border-radius: 8px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .pdr-queue { overflow: hidden; }
    .pdr-queue-head { padding: 16px; border-bottom: 1px solid var(--pdr-border); }
    .pdr-queue-head h2 { margin: 0 0 12px; color: #09090b; font-size: 16px; font-weight: 600; line-height: 1.4; }
    .pdr-filter-label { display: block; margin-bottom: 6px; color: #52525b; font-size: 13px; font-weight: 500; }
    .pdr-filter { width: 100%; height: 36px; padding: 0 11px; border: 1px solid var(--pdr-border); border-radius: 6px; background: #fff; color: #18181b; font: 400 14px Inter, "Segoe UI", sans-serif; }
    .pdr-filter:focus-visible { outline: none; border-color: #a1a1aa; box-shadow: 0 0 0 3px rgba(24,24,27,.12); }
    .pdr-queue-list { display: grid; max-height: calc(100vh - 280px); overflow: auto; }
    .pdr-queue-item { display: grid; gap: 5px; padding: 13px 14px; border: 0; border-bottom: 1px solid #f1f1f2; background: #fff; color: #18181b; text-align: left; text-decoration: none; transition: background .15s; }
    .pdr-queue-item:last-child { border-bottom: 0; }
    .pdr-queue-item:hover { background: #fafafa; }
    .pdr-queue-item[aria-current="true"] { border-left: 3px solid #18181b; background: #fff; padding-left: 11px; }
    .pdr-queue-item[hidden] { display: none; }
    .pdr-queue-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 14px; font-weight: 600; }
    .pdr-queue-subtitle,.pdr-queue-meta { color: #52525b; font-size: 12px; line-height: 1.45; }
    .pdr-queue-meta { color: #71717a; }
    .pdr-queue-tag { display: inline-flex; align-items: center; border: 1px solid #bfdbfe; border-radius: 4px; background: #dbeafe; color: #1e40af; padding: 3px 10px; font-size: 12px; font-weight: 500; line-height: 1.4; white-space: nowrap; }
    .pdr-queue-tag.microchip { border-color: #bbf7d0; background: #dcebe0; color: #045e1f; }
    .pdr-queue-tag.profile { border-color: #fed7aa; background: #ffedd5; color: #9a3412; }
    .pdr-queue-empty { padding: 18px 14px; color: #71717a; font-size: 13px; text-align: center; }
    .pdr-main { display: grid; gap: 16px; min-width: 0; }
    .pdr-compare { overflow: hidden; }
    .pdr-compare-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 18px 20px 16px; border-bottom: 1px solid var(--pdr-border); }
    .pdr-case-title { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin: 0; color: #09090b; font-size: 18px; font-weight: 600; line-height: 1.4; letter-spacing: -.02em; }
    .pdr-status { display: inline-flex; align-items: center; border: 1px solid #fde68a; border-radius: 4px; background: #fef3c7; color: #92400e; padding: 3px 10px; font-size: 12px; font-weight: 500; line-height: 1.4; letter-spacing: 0; }
    .pdr-case-subtitle { margin: 5px 0 0; color: #71717a; font-size: 13px; }
    .pdr-strength-wrap { flex: 0 0 auto; text-align: right; }
    .pdr-strength { border-color: #bbf7d0; border-radius: 4px; background: #dcebe0; color: #045e1f; padding: 3px 10px; line-height: 1.4; }
    .pdr-strength.profile { border-color: #fed7aa; background: #ffedd5; color: #9a3412; }
    .pdr-strength-caption { display: block; margin-top: 5px; color: #71717a; font-size: 12px; }
    .pdr-reasons { padding: 12px 20px; border-bottom: 1px solid #f1f1f2; }
    .pdr-reasons-summary { margin: 0; color: #52525b; font-size: 13px; line-height: 1.5; }
    .pdr-profile-head { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 12px; padding: 16px 20px 14px 132px; background: #fff; }
    .pdr-profile-head section { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .pdr-avatar { position: relative; display: grid; width: 76px; height: 76px; flex: 0 0 76px; place-items: center; overflow: hidden; border: 1px solid #d4d4d8; border-radius: 50%; background: #f4f4f5; color: #52525b; box-shadow: 0 0 0 4px #fafafa; }
    .pdr-avatar svg { width: 28px; height: 28px; }
    .pdr-avatar img { position: absolute; z-index: 1; inset: 0; display: block; width: 100%; height: 100%; border-radius: inherit; object-fit: cover; }
    .pdr-profile-head h3 { overflow: hidden; margin: 5px 0 0; color: #18181b; font-size: 15px; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
    .pdr-profile-head p { margin: 2px 0 0; color: #71717a; font-size: 12px; }
    .pdr-profile-label { border-color: var(--pdr-border); background: #fff; color: #52525b; padding: 3px 8px; font-size: 11px; }
    .pdr-profile-label.new { background: #f4f4f5; color: #18181b; }
    .pdr-fields { display: grid; }
    .pdr-field { display: grid; grid-template-columns: 132px minmax(0,1fr) minmax(0,1fr); gap: 12px; align-items: center; min-height: 44px; padding: 8px 20px; border-top: 1px solid #f1f1f2; }
    .pdr-field.match { background: #fafafa; }
    .pdr-field.different { background: #fefce8; }
    .pdr-field.incomplete { background: #fafafa; }
    .pdr-field-label { color: #52525b; font-size: 13px; font-weight: 500; }
    .pdr-field-status { display: table; max-width: 100%; margin-top: 4px; padding: 3px 10px; border: 1px solid #bfdbfe; border-radius: 4px; background: #dbeafe; color: #1e40af; font-size: 12px; font-weight: 500; line-height: 1.4; white-space: normal; }
    .pdr-field.different .pdr-field-status { border-color: #fed7aa; background: #ffedd5; color: #9a3412; }
    .pdr-field.incomplete .pdr-field-status { border-color: #e2e8f0; background: #f1f5f9; color: #475569; }
    .pdr-field-value { min-width: 0; overflow-wrap: anywhere; color: #18181b; font-size: 14px; line-height: 1.45; }
    .pdr-bottom { display: grid; grid-template-columns: minmax(0,1.3fr) minmax(240px,.7fr); gap: 16px; }
    .pdr-decision,.pdr-safety { padding: 18px; }
    .pdr-section-title { margin: 0 0 14px; color: #09090b; font-size: 16px; font-weight: 600; line-height: 1.4; }
    .pdr-choice-list { display: grid; gap: 9px; }
    .pdr-choice { position: relative; display: grid; grid-template-columns: 18px minmax(0,1fr); align-items: start; gap: 10px; padding: 12px; border: 1px solid var(--pdr-border); border-radius: 8px; background: #fff; cursor: pointer; transition: border-color .15s,background .15s,box-shadow .15s; }
    .pdr-choice:hover { border-color: #a1a1aa; background: #fafafa; }
    .pdr-choice:has(input:checked) { border-color: #18181b; background: #fafafa; box-shadow: 0 0 0 1px rgba(24,24,27,.08); }
    .pdr-choice input { width: 16px; height: 16px; margin: 2px 0 0; accent-color: #18181b; }
    .pdr-choice strong { display: block; color: #18181b; font-size: 14px; font-weight: 600; line-height: 1.4; }
    .pdr-choice span span { display: block; margin-top: 3px; color: #71717a; font-size: 13px; line-height: 1.45; }
    .pdr-notes-label { display: block; margin: 14px 0 6px; color: #3f3f46; font-size: 13px; font-weight: 500; }
    .pdr-notes { display: block; width: 100%; min-height: 76px; resize: vertical; padding: 9px 11px; border: 1px solid var(--pdr-border); border-radius: 6px; background: #fff; color: #18181b; font: 400 14px/1.5 Inter, "Segoe UI", sans-serif; transition: border-color .15s,box-shadow .15s; }
    .pdr-notes::placeholder { color: #a1a1aa; }
    .pdr-notes:focus { border-color: #18181b; outline: none; box-shadow: 0 0 0 2px rgba(24,24,27,.12); }
    .pdr-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .pdr-button { display: inline-flex; align-items: center; justify-content: center; min-height: 36px; padding: 0 16px; border: 1px solid #18181b; border-radius: 6px; background: #18181b; color: #fafafa; font: 500 14px Inter, "Segoe UI", sans-serif; text-decoration: none; cursor: pointer; transition: background .15s,border-color .15s,box-shadow .15s; }
    .pdr-button:hover { border-color: #27272a; background: #27272a; }
    .pdr-button:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(24,24,27,.18); }
    .pdr-button.secondary { border-color: var(--pdr-border); background: #fff; color: #18181b; }
    .pdr-button.secondary:hover { background: #f4f4f5; }
    .pdr-button:disabled { cursor: not-allowed; opacity: .5; }
    .pdr-skip-help { margin: 8px 0 0; color: #71717a; font-size: 12px; }
    .pdr-safety h2 { margin: 0 0 8px; color: #09090b; font-size: 16px; font-weight: 600; }
    .pdr-safety p { margin: 0; color: #52525b; font-size: 14px; line-height: 1.55; }
    .pdr-safety-note { margin-top: 12px; padding: 10px 12px; border: 1px solid #e4e4e7; border-radius: 6px; background: #fafafa; color: #52525b; font-size: 13px; line-height: 1.5; }
    .pdr-empty { padding: 40px 24px; border: 1px solid var(--pdr-border); border-radius: 8px; background: #fff; color: #71717a; text-align: center; }
    .pdr-empty h2 { margin: 0 0 6px; color: #18181b; font-size: 16px; font-weight: 600; }
    .pdr-empty p { margin: 0; font-size: 14px; }
    .pdr-dialog { position: fixed; inset: 0; margin: auto; width: min(440px,calc(100vw - 32px)); max-width: none; max-height: calc(100vh - 32px); overflow-y: auto; padding: 0; border: 1px solid var(--pdr-border); border-radius: 8px; background: #fff; color: #18181b; box-shadow: 0 24px 48px rgba(0,0,0,.2); font: 400 14px/1.5 Inter, "Segoe UI", sans-serif; }
    .pdr-dialog::backdrop { background: rgba(15,23,42,.48); }
    .pdr-dialog-content { padding: 22px; }
    .pdr-dialog-icon { display: grid; width: 38px; height: 38px; place-items: center; margin-bottom: 14px; border: 1px solid var(--pdr-border); border-radius: 8px; background: #f4f4f5; color: #52525b; }
    .pdr-dialog-icon svg { width: 20px; height: 20px; }
    .pdr-dialog h2 { margin: 0 0 7px; color: #09090b; font-size: 18px; font-weight: 600; letter-spacing: -.015em; }
    .pdr-dialog p { margin: 0; color: #71717a; font-size: 14px; line-height: 1.5; }
    .pdr-dialog-decision { margin-top: 10px!important; color: #18181b!important; font-weight: 500; }
    .pdr-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; }
    @media(max-width:1000px) {
      .pdr-layout { grid-template-columns: minmax(230px,280px) minmax(0,1fr); }
      .pdr-profile-head { padding-left: 20px; }
      .pdr-field { grid-template-columns: 116px minmax(0,1fr) minmax(0,1fr); }
    }
    @media(max-width:760px) {
      .pdr-layout,.pdr-bottom { grid-template-columns: 1fr; }
      .pdr-queue-list { max-height: 280px; }
      .pdr-safety { order: 2; }
    }
    @media(max-width:560px) {
      .pet-duplicate-page { margin: -1.75rem 0; padding: 16px 12px; }
      .pdr-header { flex-direction: column; gap: 12px; }
      .pdr-header h1 { font-size: 22px; }
      .pdr-compare-head { padding: 14px; }
      .pdr-strength-wrap { max-width: 120px; }
      .pdr-profile-head { gap: 8px; padding: 12px; }
      .pdr-profile-head section { gap: 10px; }
      .pdr-avatar { width: 60px; height: 60px; flex-basis: 60px; }
      .pdr-field { grid-template-columns: 92px minmax(0,1fr) minmax(0,1fr); gap: 7px; padding: 8px 10px; }
      .pdr-field-value { font-size: 12px; }
      .pdr-field-label { font-size: 12px; }
      .pdr-field-status { font-size: 11px; }
      .pdr-decision,.pdr-safety { padding: 14px; }
    }
    @media(prefers-reduced-motion:reduce) {
      .pet-duplicate-page * { scroll-behavior: auto!important; transition: none!important; }
    }
  </style>
</head>
<body class="staff-theme" data-portal="staff">
<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>
<main class="main-content">
  <section class="pet-duplicate-page" aria-label="Possible duplicate pet profiles">
    <header class="pdr-header">
      <div>
        <h1>Possible Duplicate Pet Profiles</h1>
        <p>Review potential matches flagged during pet registration. Your decision is recorded for staff reference; profiles and their histories are never merged or deleted here.</p>
      </div>
      <span class="pdr-count"><?php echo number_format(count($reviews)); ?> pending review<?php echo count($reviews) === 1 ? '' : 's'; ?></span>
    </header>
    <?php if ($notice !== ''): ?>
      <div class="pdr-alert<?php echo $noticeType === 'error' ? ' error' : ''; ?>" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($loadError !== ''): ?>
      <div class="pdr-alert error" role="alert"><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!$reviews): ?>
      <div class="pdr-panel pdr-empty">
        <h2>Review queue is clear</h2>
        <p>There are no possible duplicate pet profiles awaiting review.</p>
      </div>
    <?php else: ?>
      <div class="pdr-layout">
        <aside class="pdr-panel pdr-queue" aria-label="Duplicate profile review queue" id="reviewQueue">
          <div class="pdr-queue-head">
            <h2>Review queue</h2>
            <label class="pdr-filter-label" for="matchTypeFilter">Filter by match type</label>
            <select class="pdr-filter" id="matchTypeFilter">
              <option value="all">All match types</option>
              <option value="microchip">Microchip matches</option>
              <option value="profile">Profile matches</option>
            </select>
          </div>
          <nav class="pdr-queue-list" aria-label="Pending duplicate profile reviews">
            <?php foreach ($reviews as $index => $review):
                $isSelected = $index === $selectedIndex;
                $matchType = (string)$review['match_type'];
                $queueName = petDuplicateReviewValue($review['candidate_name']);
                $createdLabel = petDuplicateReviewDate($review['created_at']);
            ?>
              <a class="pdr-queue-item" href="<?php echo htmlspecialchars(petDuplicateReviewUrl($appBaseUrl, (int)$review['review_id']), ENT_QUOTES, 'UTF-8'); ?>"
                 data-match-type="<?php echo htmlspecialchars($matchType, ENT_QUOTES, 'UTF-8'); ?>"
                 aria-current="<?php echo $isSelected ? 'true' : 'false'; ?>">
                <span class="pdr-queue-title">
                  <span><?php echo htmlspecialchars($queueName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="pdr-queue-tag <?php echo htmlspecialchars($matchType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($matchType === 'microchip' ? 'Microchip match' : 'Profile match', ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <span class="pdr-queue-subtitle"><?php echo htmlspecialchars(petDuplicateReviewValue($review['candidate_type']) . ' · ' . petDuplicateReviewValue($review['candidate_breed']) . ' · ' . petDuplicateReviewValue($review['candidate_gender']), ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pdr-queue-meta"><?php echo htmlspecialchars(petDuplicateReviewCaseLabel($review['review_id']), ENT_QUOTES, 'UTF-8'); ?> · Flagged <?php echo htmlspecialchars($createdLabel, ENT_QUOTES, 'UTF-8'); ?></span>
              </a>
            <?php endforeach; ?>
            <div class="pdr-queue-empty" id="queueFilterEmpty" hidden>No reviews match this filter.</div>
          </nav>
        </aside>

        <?php if ($selectedReview !== null):
            $matchedFields = $selectedReview['matched_fields'];
            $matchType = (string)$selectedReview['match_type'];
            $isMicrochipMatch = $matchType === 'microchip';
            $candidatePhotoUrl = staffPetPhotoUrl($selectedReview['candidate_photo_url'], $appBaseUrl);
            $newPetPhotoUrl = staffPetPhotoUrl($selectedReview['new_pet_photo_url'], $appBaseUrl);
            $similarityFields = [];
            $similarAge = false;
            if ($isMicrochipMatch) {
                $similaritySummary = 'These profiles look similar because the pets share the same microchip number.';
            } else {
                $similaritySummary = 'These profiles look similar because the pets share the same name and species';
                $similarityFieldLabels = [
                    'breed' => 'breed',
                    'gender' => 'gender',
                    'color' => 'color/markings',
                    'date_of_birth' => 'date of birth',
                ];
                foreach ($matchedFields as $field) {
                    if ($field === 'age') {
                        $similarAge = true;
                    } elseif (isset($similarityFieldLabels[$field])) {
                        $similarityFields[] = $similarityFieldLabels[$field];
                    }
                }
                if ($similarityFields) {
                    $similaritySummary .= ', match on ' . petDuplicateReviewNaturalList($similarityFields);
                }
                if ($similarAge) {
                    $similaritySummary .= ', and are close in age';
                }
                $similaritySummary .= '.';
            }
            $fieldPairs = [
                'microchip_id' => ['Microchip number', $selectedReview['new_pet_microchip'], $selectedReview['candidate_microchip']],
                'pet_name' => ['Name', $selectedReview['new_pet_name'], $selectedReview['candidate_name']],
                'pet_type' => ['Species', $selectedReview['new_pet_type'], $selectedReview['candidate_type']],
                'breed' => ['Breed', $selectedReview['new_pet_breed'], $selectedReview['candidate_breed']],
                'gender' => ['Gender', $selectedReview['new_pet_gender'], $selectedReview['candidate_gender']],
                'color' => ['Color / markings', $selectedReview['new_pet_color'], $selectedReview['candidate_color']],
                'date_of_birth' => ['Date of birth', $selectedReview['new_pet_dob'], $selectedReview['candidate_dob']],
                'age' => ['Age', $selectedReview['new_pet_age'], $selectedReview['candidate_age']],
            ];
        ?>
          <div class="pdr-main" id="activeReview">
            <article class="pdr-panel pdr-compare" aria-labelledby="reviewTitle">
              <header class="pdr-compare-head">
                <div>
                  <h2 class="pdr-case-title" id="reviewTitle">
                    Case <?php echo htmlspecialchars(petDuplicateReviewCaseLabel($selectedReview['review_id']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['candidate_name']), ENT_QUOTES, 'UTF-8'); ?>
                    <span class="pdr-status">Pending review</span>
                  </h2>
                  <p class="pdr-case-subtitle">Flagged <?php echo htmlspecialchars(petDuplicateReviewDate($selectedReview['created_at']), ENT_QUOTES, 'UTF-8'); ?> when a new pet profile was submitted.</p>
                </div>
                <div class="pdr-strength-wrap">
                  <span class="pdr-strength<?php echo $isMicrochipMatch ? '' : ' profile'; ?>"><?php echo $isMicrochipMatch ? 'Strong match' : 'Potential match'; ?></span>
                  <span class="pdr-strength-caption"><?php echo $isMicrochipMatch ? 'Exact microchip match' : 'Requires staff validation'; ?></span>
                </div>
              </header>
              <div class="pdr-reasons">
                <p class="pdr-reasons-summary"><?php echo htmlspecialchars($similaritySummary, ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
              <div class="pdr-profile-head">
                <section aria-label="Existing profile">
                  <span class="pdr-avatar" aria-hidden="true">
                    <?php if ($candidatePhotoUrl !== ''): ?>
                      <img src="<?php echo htmlspecialchars($candidatePhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="eager" onerror="this.hidden=true">
                    <?php endif; ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12.5c-2.7 0-5.3 1.4-5.3 3.7 0 1.4 1.1 2.3 2.5 2.3h5.6c1.4 0 2.5-.9 2.5-2.3 0-2.3-2.6-3.7-5.3-3.7Z"/><path d="M6.2 10.1c1 .2 1.5-.7 1.3-1.7-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.7.7 1 1.4 1.1ZM10.1 8.7c1 .1 1.5-.8 1.3-1.8-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.7.7 1.1 1.4 1.2ZM14 8.7c1-.1 1.5-1 1.3-2-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.8.7 1.3 1.4 1.4ZM17.8 10.1c1-.2 1.5-1.1 1.3-2.1-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.9.7 1.3 1.4 1.5Z"/></svg>
                  </span>
                  <div><span class="pdr-profile-label">Existing profile</span><h3><?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['candidate_name']), ENT_QUOTES, 'UTF-8'); ?></h3><p>Registered <?php echo htmlspecialchars(petDuplicateReviewDate($selectedReview['candidate_created_at']), ENT_QUOTES, 'UTF-8'); ?></p></div>
                </section>
                <section aria-label="New submission">
                  <span class="pdr-avatar" aria-hidden="true">
                    <?php if ($newPetPhotoUrl !== ''): ?>
                      <img src="<?php echo htmlspecialchars($newPetPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="eager" onerror="this.hidden=true">
                    <?php endif; ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12.5c-2.7 0-5.3 1.4-5.3 3.7 0 1.4 1.1 2.3 2.5 2.3h5.6c1.4 0 2.5-.9 2.5-2.3 0-2.3-2.6-3.7-5.3-3.7Z"/><path d="M6.2 10.1c1 .2 1.5-.7 1.3-1.7-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.7.7 1 1.4 1.1ZM10.1 8.7c1 .1 1.5-.8 1.3-1.8-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.7.7 1.1 1.4 1.2ZM14 8.7c1-.1 1.5-1 1.3-2-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.8.7 1.3 1.4 1.4ZM17.8 10.1c1-.2 1.5-1.1 1.3-2.1-.2-1.1-.9-1.8-1.8-1.6-.9.2-1.1 1.3-.9 2.2.2.9.7 1.3 1.4 1.5Z"/></svg>
                  </span>
                  <div><span class="pdr-profile-label new">New submission</span><h3><?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['new_pet_name']), ENT_QUOTES, 'UTF-8'); ?></h3><p>Submitted <?php echo htmlspecialchars(petDuplicateReviewDate($selectedReview['new_pet_created_at']), ENT_QUOTES, 'UTF-8'); ?></p></div>
                </section>
              </div>
              <div class="pdr-fields">
                <?php foreach ($fieldPairs as $field => [$label, $newValue, $candidateValue]):
                    $status = petDuplicateReviewCompareStatus($field, $newValue, $candidateValue, $matchedFields);
                    $statusLabel = $status === 'match' ? 'Match' : ($status === 'different' ? 'Differs' : 'Incomplete');
                ?>
                  <div class="pdr-field <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="pdr-field-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?><span class="pdr-field-status"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="pdr-field-value"><?php echo htmlspecialchars(petDuplicateReviewValue($candidateValue), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="pdr-field-value"><?php echo htmlspecialchars(petDuplicateReviewValue($newValue), ENT_QUOTES, 'UTF-8'); ?></div>
                  </div>
                <?php endforeach; ?>
                <?php
                    $ownerSame = (int)$selectedReview['candidate_owner_id'] === (int)$selectedReview['new_owner_id'];
                    $ownerStatus = $ownerSame ? 'match' : 'different';
                ?>
                <div class="pdr-field <?php echo $ownerStatus; ?>">
                  <div class="pdr-field-label">Owner<span class="pdr-field-status"><?php echo $ownerSame ? 'Same resident' : 'Different residents'; ?></span></div>
                  <div class="pdr-field-value"><?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['candidate_owner_name']), ENT_QUOTES, 'UTF-8'); ?><br><?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['candidate_owner_email']), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="pdr-field-value"><?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['new_owner_name']), ENT_QUOTES, 'UTF-8'); ?><br><?php echo htmlspecialchars(petDuplicateReviewValue($selectedReview['new_owner_email']), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              </div>
            </article>

            <div class="pdr-bottom">
              <section class="pdr-panel pdr-decision" aria-labelledby="decisionTitle">
                <h2 class="pdr-section-title" id="decisionTitle">Record your decision</h2>
                <form class="pdr-review-form" id="reviewDecisionForm" method="post" action="<?php echo htmlspecialchars($appBaseUrl . '/api/staff/pet-duplicate-review-action.php', ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="review_id" value="<?php echo (int)$selectedReview['review_id']; ?>">
                  <input type="hidden" name="action" id="reviewAction" value="">
                  <div class="pdr-choice-list">
                    <label class="pdr-choice">
                      <input type="radio" name="decision_choice" value="not_duplicate" required>
                      <span><strong>Not a duplicate</strong><span>Keep both pet profiles separate. This pair will not be flagged again as the same review.</span></span>
                    </label>
                    <label class="pdr-choice">
                      <input type="radio" name="decision_choice" value="linked" required>
                      <span><strong>Same animal — link for reference</strong><span>Record that these profiles refer to the same animal. This does not merge profiles or change ownership.</span></span>
                    </label>
                  </div>
                  <label class="pdr-notes-label" for="reviewNotes">Remarks (optional)</label>
                  <textarea class="pdr-notes" id="reviewNotes" name="notes" maxlength="1000" placeholder="Add a short reason for your decision"></textarea>
                  <div class="pdr-actions">
                    <button class="pdr-button" type="button" id="saveReviewDecision">Save decision</button>
                    <?php if ($nextReview !== null): ?>
                      <a class="pdr-button secondary" href="<?php echo htmlspecialchars(petDuplicateReviewUrl($appBaseUrl, (int)$nextReview['review_id']), ENT_QUOTES, 'UTF-8'); ?>">Skip for now</a>
                    <?php else: ?>
                      <button class="pdr-button secondary" type="button" disabled title="There are no other pending reviews to skip to">Skip for now</button>
                    <?php endif; ?>
                  </div>
                  <?php if ($nextReview === null): ?><p class="pdr-skip-help">No other pending reviews are available to skip to.</p><?php endif; ?>
                </form>
              </section>
              <aside class="pdr-panel pdr-safety" aria-label="How review decisions work">
                <h2>What happens when you save?</h2>
                <p>Your decision and remarks are saved to the review record and audit log. A “same animal” decision links the review for reference only; it does not merge pet profiles or move their records.</p>
                <div class="pdr-safety-note">The resident’s ownership and each profile’s history remain unchanged. Review decisions can only be submitted by authorized staff.</div>
              </aside>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
<dialog class="pdr-dialog" id="reviewConfirmDialog" role="alertdialog" aria-modal="true" aria-labelledby="reviewConfirmTitle" aria-describedby="reviewConfirmDescription">
  <div class="pdr-dialog-content">
    <span class="pdr-dialog-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 2.7 17a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3l-7.6-13.1a2 2 0 0 0-3.4 0Z"/></svg>
    </span>
    <h2 id="reviewConfirmTitle">Confirm review decision</h2>
    <p id="reviewConfirmDescription">Once you confirm, this decision and any remarks you added will be recorded in the review history.</p>
    <p class="pdr-dialog-decision" id="reviewConfirmDecision"></p>
    <div class="pdr-dialog-actions">
      <button class="pdr-button secondary" type="button" id="cancelReviewConfirm">Cancel</button>
      <button class="pdr-button" type="button" id="confirmReviewDecision">Confirm decision</button>
    </div>
  </div>
</dialog>
<script>
  (function () {
    var matchTypeFilter = document.getElementById('matchTypeFilter');
    var queueItems = Array.prototype.slice.call(document.querySelectorAll('.pdr-queue-item'));
    var queueFilterEmpty = document.getElementById('queueFilterEmpty');

    if (matchTypeFilter) {
      matchTypeFilter.addEventListener('change', function () {
        var filter = matchTypeFilter.value;
        var visibleCount = 0;
        queueItems.forEach(function (item) {
          var visible = filter === 'all' || item.getAttribute('data-match-type') === filter;
          item.hidden = !visible;
          if (visible) visibleCount++;
        });
        if (queueFilterEmpty) queueFilterEmpty.hidden = visibleCount > 0;
      });
    }

    var reviewForm = document.getElementById('reviewDecisionForm');
    var saveButton = document.getElementById('saveReviewDecision');
    var actionInput = document.getElementById('reviewAction');
    var dialog = document.getElementById('reviewConfirmDialog');
    var decisionSummary = document.getElementById('reviewConfirmDecision');
    var cancelButton = document.getElementById('cancelReviewConfirm');
    var confirmButton = document.getElementById('confirmReviewDecision');
    if (!reviewForm || !saveButton || !dialog || !actionInput) return;

    saveButton.addEventListener('click', function () {
      if (!reviewForm.reportValidity()) return;
      var selected = reviewForm.querySelector('input[name="decision_choice"]:checked');
      if (!selected) return;

      actionInput.value = selected.value;
      decisionSummary.textContent = selected.value === 'linked'
        ? "You're confirming these profiles refer to the same animal. This will be noted for reference only; nothing will be merged, and ownership and history will stay unchanged."
        : "You're confirming these are different animals. Both profiles will stay separate and unchanged.";
      dialog.showModal();
    });

    cancelButton.addEventListener('click', function () {
      dialog.close();
      saveButton.focus();
    });

    confirmButton.addEventListener('click', function () {
      if (typeof reviewForm.requestSubmit === 'function') {
        reviewForm.requestSubmit();
      } else {
        reviewForm.submit();
      }
    });
  })();
</script>
</body>
</html>
