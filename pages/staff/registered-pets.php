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
require_once __DIR__ . '/../../includes/staff-app-base.php';
require_once __DIR__ . '/../../includes/staff-formatters.php';
$appBaseUrl = dcvsStaffAppBaseUrl();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: ' . $appBaseUrl . '/login-staff.php');
    exit;
}

$pageTitle = 'Registered Pets';
$activePage = 'registered-pets';
$pets = [];
$barangays = [];
$loadError = '';
$counts = ['total' => 0, 'dog' => 0, 'cat' => 0, 'other' => 0];

$petQuery = $conn->query("
    SELECT p.pet_id, p.reference_id, p.pet_name, p.pet_type, p.breed, p.age, p.gender,
           p.vaccination_status, p.photo_url, p.created_at, p.owner_id,
           u.full_name AS owner_name, u.reference_id AS owner_ref, u.email AS owner_email,
           b.barangay_name
    FROM pets p
    JOIN users u ON p.owner_id = u.user_id
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    WHERE p.archived_at IS NULL AND u.archived_at IS NULL
      AND u.role = 'resident' AND u.status = 'active' AND u.deleted_at IS NULL
    ORDER BY p.created_at DESC, p.pet_id DESC
");

if ($petQuery) {
    while ($pet = $petQuery->fetch_assoc()) {
        $pets[] = $pet;
        $barangayName = trim((string)($pet['barangay_name'] ?? ''));
        if ($barangayName !== '') $barangays[$barangayName] = $barangayName;
        $type = strtolower((string)$pet['pet_type']);
        $filterType = in_array($type, ['dog', 'cat'], true) ? $type : 'other';
        $counts['total']++;
        if (isset($counts[$type])) $counts[$type]++;
        else $counts['other']++;
    }
    $petQuery->free();
} else {
    error_log('Could not load staff registered pets: ' . $conn->error);
    $loadError = 'Registered pets could not be loaded. Please refresh the page or contact support.';
}
natcasesort($barangays);

function registeredPetPhotoUrl($photoUrl, $baseUrl) {
    $photoUrl = trim((string)$photoUrl);
    if ($photoUrl === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $photoUrl)) return $photoUrl;
    return rtrim($baseUrl, '/') . '/' . ltrim($photoUrl, '/');
}

function registeredPetVaccination($status) {
    $value = strtolower(trim((string)$status));
    if ($value === '') return ['Not recorded', 'vacc-unknown'];
    if (strpos($value, 'vaccinated') !== false && strpos($value, 'not') === false) return ['Vaccinated', 'vacc-ok'];
    return ['Not vaccinated', 'vacc-no'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> — Staff | DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/dashboard.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/responsive.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/responsive.css'), ENT_QUOTES, 'UTF-8'); ?>" />
  <style>
    .registered-pets-page{min-height:calc(100vh - var(--topbar-h,64px));margin:-1.75rem -2rem;padding:24px;background:#fff;color:#18181b;font-family:Inter,'Segoe UI',sans-serif}
    .rp-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
    .rp-kpi{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:14px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03);transition:all .2s cubic-bezier(.4,0,.2,1)}
    .rp-kpi:hover{border-color:#d1d5db;box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03)}
    .rp-kpi-label{margin:0 0 6px;font-family:Inter,'Segoe UI',sans-serif;font-size:14px;font-weight:500;color:#18181b;text-transform:uppercase}
    .rp-kpi-value{margin:0 0 6px;font-family:Inter,'Segoe UI',sans-serif;font-size:26px;font-weight:600;line-height:1;color:#18181b;letter-spacing:-.02em}
    .rp-kpi-sub{margin:0;font-family:Inter,'Segoe UI',sans-serif;font-size:14px;color:#71717a}
    .rp-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
    .rp-search{position:relative;flex:0 1 auto;width:380px;min-width:180px}
    .rp-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#71717a;font-size:13px}
    .rp-search input,.rp-toolbar select,.rp-rows select{box-sizing:border-box;height:36px;padding:0 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px Inter,'Segoe UI',sans-serif;box-shadow:0 1px 2px rgba(0,0,0,.05)}
    .rp-search input{width:100%;padding-left:36px;font-weight:400;outline:none}
    .rp-search input:focus,.rp-toolbar select:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b}
    .rp-search input::placeholder{color:#a1a1aa}
    .rp-filters{display:flex;align-items:center;gap:8px}
    .rp-filters select{min-width:120px;max-width:150px;padding-right:26px;cursor:pointer}
    .rp-table-wrap{overflow:auto;border:1px solid #e4e4e7;border-radius:8px;background:#fff}
    .rp-table{width:100%;min-width:980px;border-collapse:collapse;font-size:14px}
    .rp-table th{height:42px;padding:0 12px;background:#fafafa;border-bottom:1px solid #e4e4e7;text-align:left;color:#71717a;font-size:13px;font-weight:500;white-space:nowrap}
    .rp-table td{height:58px;padding:8px 12px;border-bottom:1px solid #f1f1f2;color:#3f3f46;white-space:nowrap}
    .rp-table tbody tr:last-child td{border-bottom:0}
    .rp-table tbody tr:hover{background:#fafafa}
    .rp-sort{display:inline-flex;align-items:center;gap:6px;padding:0;border:0;background:none;color:inherit;font:inherit;cursor:pointer}
    .rp-sort i{font-size:11px;color:#a1a1aa}
    .rp-pet{display:flex;align-items:center;gap:10px;min-width:150px}
    .rp-avatar{width:36px;height:36px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;border-radius:50%;background:#eef6f0;color:#046b25;font-size:13px;font-weight:700}
    .rp-avatar img{width:100%;height:100%;object-fit:cover}
    .rp-name{font-weight:600;color:#18181b}
    .rp-id{color:#52525b}
    .rp-vax{display:inline-flex;padding:3px 9px;border:1px solid #e4e4e7;border-radius:4px;font-size:12px;white-space:nowrap}
    .rp-vax.vacc-ok{background:#ecfdf3;border-color:#d1fadf;color:#027a48}
    .rp-vax.vacc-no{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .rp-vax.vacc-unknown{background:#f4f4f5;color:#71717a}
    .rp-pagination{display:flex;justify-content:flex-end;align-items:center;gap:20px;padding:12px 4px;color:#71717a;font-size:13px}
    .rp-page-controls{display:flex;align-items:center;gap:5px}
    .rp-page-controls button{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;cursor:pointer}
    .rp-page-controls button:disabled{opacity:.45;cursor:not-allowed}
    .rp-empty{padding:42px 16px!important;text-align:center;color:#71717a!important}
    @media(max-width:900px){.rp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-toolbar{align-items:stretch}.rp-search,.rp-filters{width:100%;max-width:100%;min-width:0}.rp-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.rp-filters select{width:100%;min-width:0;max-width:none}}
    @media(max-width:768px){.registered-pets-page{width:100%;margin:-1.75rem 0;padding:18px 14px}.rp-toolbar{flex-direction:column}.rp-search{max-width:none}.rp-pagination{justify-content:space-between;gap:10px}.rp-rows{display:flex;align-items:center;gap:7px}}
    @media(max-width:480px){.rp-kpis{gap:10px}.rp-kpi{padding:13px}.rp-kpi-value{font-size:23px}}
  </style>
</head>
<body class="staff-theme" data-portal="staff">
<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>
<main class="main-content">
  <section class="registered-pets-page" aria-label="Registered pets">
    <div class="rp-kpis" aria-label="Registered pets summary">
      <div class="rp-kpi"><p class="rp-kpi-label">Total Pets</p><div class="rp-kpi-value"><?php echo number_format($counts['total']); ?></div><p class="rp-kpi-sub">Across active residents</p></div>
      <div class="rp-kpi"><p class="rp-kpi-label">Dogs</p><div class="rp-kpi-value"><?php echo number_format($counts['dog']); ?></div><p class="rp-kpi-sub">Registered dogs</p></div>
      <div class="rp-kpi"><p class="rp-kpi-label">Cats</p><div class="rp-kpi-value"><?php echo number_format($counts['cat']); ?></div><p class="rp-kpi-sub">Registered cats</p></div>
      <div class="rp-kpi"><p class="rp-kpi-label">Other Pets</p><div class="rp-kpi-value"><?php echo number_format($counts['other']); ?></div><p class="rp-kpi-sub">Other registered species</p></div>
    </div>
    <div class="rp-toolbar staff-mobile-toolbar staff-toolbar-search-only">
      <div class="staff-toolbar-search-row staff-toolbar-no-filter">
      <label class="rp-search staff-toolbar-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" id="registeredPetsSearch" placeholder="Search pets..." aria-label="Search registered pets" />
      </label>
      </div>
      <div class="rp-filters staff-toolbar-filters">
      <select class="staff-toolbar-select" id="registeredPetsType" aria-label="Filter by type">
        <option value="">All Types</option>
        <option value="dog">Dog</option>
        <option value="cat">Cat</option>
        <option value="other">Other</option>
      </select>
      <select class="staff-toolbar-select" id="registeredPetsQuickFilter" aria-label="Additional filters">
        <option value="">All</option>
        <option value="recent">Recently Added</option>
        <option value="vaccinated">Vaccinated Pets</option>
      </select>
      <select class="staff-toolbar-select staff-toolbar-last-filter" id="registeredPetsBarangay" aria-label="Filter by barangay">
        <option value="">All Barangays</option>
        <?php foreach ($barangays as $barangay): ?>
          <option value="<?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
      </div>
    </div>
    <div class="rp-table-wrap">
      <table class="rp-table">
        <thead><tr>
          <th><button class="rp-sort" data-sort="name">Pet Name <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="ref">Pet ID <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="owner">Owner Name <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="ownerRef">Owner ID <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="type">Species <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="gender">Sex <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="age">Age <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th>Vaccination</th>
          <th><button class="rp-sort" data-sort="barangay">Barangay <i class="fas fa-sort" aria-hidden="true"></i></button></th>
          <th><button class="rp-sort" data-sort="date">Registered on <i class="fas fa-sort" aria-hidden="true"></i></button></th>
        </tr></thead>
        <tbody id="registeredPetsRows">
        <?php foreach ($pets as $pet):
          $type = strtolower((string)$pet['pet_type']);
          $barangayName = trim((string)($pet['barangay_name'] ?? ''));
          $ref = $pet['reference_id'] ?: '#PET-' . fallbackRefId('#PET-', (int)$pet['pet_id']);
          $date = !empty($pet['created_at']) ? date('M d, Y', strtotime($pet['created_at'])) : '—';
          [$vaccinationLabel, $vaccinationClass] = registeredPetVaccination($pet['vaccination_status']);
          $photoUrl = registeredPetPhotoUrl($pet['photo_url'], $appBaseUrl);
          $searchText = implode(' ', [$pet['pet_name'], $ref, $pet['owner_name'], $pet['owner_ref'], $pet['owner_email'], cpdTypeLabel($type), $pet['breed'], $pet['barangay_name']]);
        ?>
          <tr data-species="<?php echo htmlspecialchars($filterType, ENT_QUOTES, 'UTF-8'); ?>" data-barangay="<?php echo htmlspecialchars($barangayName, ENT_QUOTES, 'UTF-8'); ?>" data-recent="<?php echo !empty($pet['created_at']) && strtotime((string)$pet['created_at']) >= strtotime('-30 days') ? '1' : '0'; ?>" data-vaccinated="<?php echo $vaccinationClass === 'vacc-ok' ? '1' : '0'; ?>" data-search="<?php echo htmlspecialchars(strtolower($searchText), ENT_QUOTES, 'UTF-8'); ?>">
            <td><div class="rp-pet"><span class="rp-avatar"><?php if ($photoUrl !== ''): ?><img src="<?php echo htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: echo htmlspecialchars(cpdInitials($pet['pet_name']), ENT_QUOTES, 'UTF-8'); endif; ?></span><span class="rp-name"><?php echo htmlspecialchars($pet['pet_name'], ENT_QUOTES, 'UTF-8'); ?></span></div></td>
            <td class="rp-id" data-sort-value="<?php echo htmlspecialchars($ref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ref, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($pet['owner_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="rp-id"><?php echo htmlspecialchars($pet['owner_ref'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(cpdTypeLabel($type), ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(cpdGenderLabel($pet['gender']), ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-age="<?php echo (int)$pet['age']; ?>"><?php echo htmlspecialchars(cpdAgeLabel($pet['age']), ENT_QUOTES, 'UTF-8'); ?></td>
            <td><span class="rp-vax <?php echo $vaccinationClass; ?>"><?php echo htmlspecialchars($vaccinationLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td><?php echo htmlspecialchars($pet['barangay_name'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
            <td data-date="<?php echo htmlspecialchars($pet['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="rp-pagination">
      <div class="rp-rows"><label for="registeredPetsPerPage">Rows per page</label>
        <select id="registeredPetsPerPage"><option>10</option><option>20</option><option>30</option><option>50</option></select>
      </div>
      <span id="registeredPetsPageLabel">Page 1 of 1</span>
      <div class="rp-page-controls">
        <button type="button" id="registeredPetsPrev" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>
        <button type="button" id="registeredPetsNext" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
      </div>
    </div>
    <?php if ($loadError !== ''): ?>
      <p role="alert" style="padding:24px;text-align:center;color:#b91c1c;"><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif (!$pets): ?>
      <p style="padding:24px;text-align:center;color:#71717a;">No registered pets found.</p>
    <?php endif; ?>
  </section>
</main>
<script>
(function(){
  var body = document.getElementById('registeredPetsRows');
  var originalRows = Array.prototype.slice.call(body.querySelectorAll('tr'));
  var search = document.getElementById('registeredPetsSearch');
  var type = document.getElementById('registeredPetsType');
  var quickFilter = document.getElementById('registeredPetsQuickFilter');
  var barangay = document.getElementById('registeredPetsBarangay');
  var perPage = document.getElementById('registeredPetsPerPage');
  var pageLabel = document.getElementById('registeredPetsPageLabel');
  var page = 1;
  var sort = '';
  var direction = 1;

  function visibleRows(){
    var query = search.value.trim().toLowerCase();
    return originalRows.filter(function(row){
      return (!query || row.dataset.search.indexOf(query) !== -1) &&
        (!type.value || row.dataset.species === type.value) &&
        (!quickFilter.value || (quickFilter.value === 'recent' && row.dataset.recent === '1') ||
          (quickFilter.value === 'vaccinated' && row.dataset.vaccinated === '1')) &&
        (!barangay.value || row.dataset.barangay === barangay.value);
    });
  }
  function render(){
    var rows = visibleRows();
    var count = Math.max(1, Math.ceil(rows.length / Number(perPage.value)));
    page = Math.min(Math.max(1, page), count);
    var start = (page - 1) * Number(perPage.value);
    originalRows.forEach(function(row){ row.hidden = true; });
    rows.slice(start, start + Number(perPage.value)).forEach(function(row){ row.hidden = false; });
    var empty = document.getElementById('registeredPetsEmpty');
    if (!rows.length && originalRows.length && !empty) {
      var tr = document.createElement('tr');
      tr.id = 'registeredPetsEmpty';
      tr.innerHTML = '<td class="rp-empty" colspan="10">No registered pets match your search.</td>';
      body.appendChild(tr);
    } else if (rows.length && empty) empty.remove();
    pageLabel.textContent = 'Page ' + page + ' of ' + count;
    document.getElementById('registeredPetsPrev').disabled = page <= 1;
    document.getElementById('registeredPetsNext').disabled = page >= count;
  }
  search.addEventListener('input', function(){ page = 1; render(); });
  type.addEventListener('change', function(){ page = 1; render(); });
  quickFilter.addEventListener('change', function(){ page = 1; render(); });
  barangay.addEventListener('change', function(){ page = 1; render(); });
  perPage.addEventListener('change', function(){ page = 1; render(); });
  document.getElementById('registeredPetsPrev').addEventListener('click', function(){ page--; render(); });
  document.getElementById('registeredPetsNext').addEventListener('click', function(){ page++; render(); });
  document.querySelectorAll('.rp-sort').forEach(function(button){
    button.addEventListener('click', function(){
      var key = button.dataset.sort;
      direction = sort === key ? direction * -1 : 1;
      sort = key;
      originalRows.sort(function(a,b){
        var value;
        if (key === 'age') value = Number(a.cells[6].dataset.age) - Number(b.cells[6].dataset.age);
        else if (key === 'date') value = a.cells[9].dataset.date.localeCompare(b.cells[9].dataset.date);
        else {
          var cellMap = {name:0,ref:1,owner:2,ownerRef:3,type:4,gender:5,barangay:8};
          value = a.cells[cellMap[key]].textContent.trim().localeCompare(b.cells[cellMap[key]].textContent.trim(), undefined, {numeric:true,sensitivity:'base'});
        }
        return value * direction;
      });
      originalRows.forEach(function(row){ body.appendChild(row); });
      page = 1;
      render();
    });
  });
  render();
})();
</script>
</body>
</html>
