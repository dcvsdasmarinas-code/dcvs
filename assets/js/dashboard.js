

// ── Section Switching ──
function switchSection(sectionId) {
  document.querySelectorAll('.content-section').forEach(function(s) { s.style.display = 'none'; });
  var target = document.getElementById(sectionId);
  if (target) target.style.display = 'block';
  document.body.classList.toggle('white-page', sectionId === 'calendar-schedule' || sectionId === 'appointments' || sectionId === 'account-settings');
  document.querySelectorAll('.nav-link').forEach(function(l) { l.classList.remove('active'); });
  var nav = document.querySelector('.nav-link[data-section="' + sectionId + '"]');
  if (nav) nav.classList.add('active');
  var labels = {
    'analytics':'Citywide Analytics','overview':'Analytics','account-settings':'Account Settings','my-pets':'My Pets','my-reports':'My Reports',
    'appointments':'Appointments','book-appt':'Schedule Appointment','calendar-schedule':'Calendar Schedule','archive':'Archive','adoption':'Adoption Listings',
    'users':'Users','activity':'Activity Logs',
    'settings':'Settings','client-pets':'Client & Pet DB',
    'barangay':'Barangay Validation','geomapping':'Geo-Mapping','pet-listings':'Pet Listings',
    'reports-all':'All Reports','reports-pending':'Pending Validation','reports-active':'Active Cases','reports-resolved':'Resolved Cases'
  };
  var bc = document.getElementById('breadcrumbCurrent');
  if (bc) bc.textContent = labels[sectionId] || sectionId;
  localStorage.setItem('activeSection', sectionId);
  window.location.hash = sectionId;
  window.scrollTo({ top: 0, behavior: 'smooth' });

  // Sync the new two-level sidebar (SB2) with the active section
  if (window.SB2 && typeof window.SB2.activateSection === 'function') {
    window.SB2.activateSection(sectionId);
  }
}

// ── Navigation Links ──
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.nav-link[data-section]').forEach(function(link) {
    link.addEventListener('click', function(e) {
      var section = this.getAttribute('data-section');
      if (section) { e.preventDefault(); switchSection(section); }
    });
  });

  // Restore last active section from localStorage (fallback when no URL hash)
  var stored = localStorage.getItem('activeSection');
  var hash = window.location.hash.replace('#', '');
  if (hash && document.getElementById(hash)) {
    switchSection(hash);
  } else if (stored && document.getElementById(stored)) {
    switchSection(stored);
  }
});

// Handle hash changes (back/forward navigation)
window.addEventListener('hashchange', function() {
  var hash = window.location.hash.replace('#', '');
  if (hash && document.getElementById(hash)) {
    switchSection(hash);
  }
});

// ── Filter Functions ──
function filterPets() {
  var q = (document.getElementById('petSearch')?.value || '').toLowerCase();
  var t = document.getElementById('petTypeFilter')?.value || '';
  var s = document.getElementById('petStatusFilter')?.value || '';
  document.querySelectorAll('#my-pets .data-table tbody tr').forEach(function(r) {
    var name = (r.cells[1]?.textContent || '').toLowerCase();
    var type = (r.cells[3]?.textContent || '').toLowerCase();
    var vax = (r.cells[7]?.textContent || '').toLowerCase();
    var matchName = !q || name.includes(q) || (r.cells[4]?.textContent || '').toLowerCase().includes(q);
    var matchType = !t || type === t.toLowerCase();
    var matchVax = !s || vax.includes(s.toLowerCase());
    r.style.display = matchName && matchType && matchVax ? '' : 'none';
  });
}

function filterReports() {
  var q = (document.getElementById('reportSearch')?.value || '').toLowerCase();
  var t = document.getElementById('reportTypeFilter')?.value || '';
  var s = document.getElementById('reportStatusFilter')?.value || '';
  document.querySelectorAll('#my-reports .data-table tbody tr').forEach(function(r) {
    var id = (r.cells[0]?.textContent || '').toLowerCase();
    var type = (r.cells[1]?.textContent || '').toLowerCase();
    var loc = (r.cells[2]?.textContent || '').toLowerCase();
    var status = (r.cells[4]?.textContent || '').toLowerCase();
    var matchQ = !q || id.includes(q) || type.includes(q) || loc.includes(q);
    var matchT = !t || type === t.toLowerCase();
    var matchS = !s || status === s.toLowerCase();
    r.style.display = matchQ && matchT && matchS ? '' : 'none';
  });
}

function filterArchive() {
  var q = (document.getElementById('archiveSearch')?.value || '').toLowerCase();
  var t = document.getElementById('archiveTypeFilter')?.value || '';
  var counts = {
    pet: (document.querySelector('#archive .archive-section[data-type="pet"]')?.getAttribute('data-count')) || 0,
    report: (document.querySelector('#archive .archive-section[data-type="report"]')?.getAttribute('data-count')) || 0,
    appointment: (document.querySelector('#archive .archive-section[data-type="appointment"]')?.getAttribute('data-count')) || 0
  };
  var titles = { pet: 'Archived Pets', report: 'Archived Reports', appointment: 'Archived Appointments' };
  var title = document.getElementById('archiveSectionTitle');
  // Show/hide the section blocks based on the selected type
  document.querySelectorAll('#archive .archive-section').forEach(function(s) {
    var st = s.getAttribute('data-type');
    s.style.display = (!t || st === t) ? '' : 'none';
  });
  // Update the heading title to reflect the active type (or all)
  if (title) {
    if (t && titles[t]) {
      title.textContent = titles[t] + ' (' + counts[t] + ')';
    } else {
      title.textContent = 'Archived Items';
    }
  }
  document.querySelectorAll('#archive .appt-table tbody tr').forEach(function(r) {
    var hay = (r.textContent || '').toLowerCase();
    r.style.display = (!q || hay.includes(q)) ? '' : 'none';
  });
}

function setArchiveType(type) {
  var sel = document.getElementById('archiveTypeFilter');
  if (sel) sel.value = type || '';
  var q = document.getElementById('archiveSearch');
  if (q) q.value = '';
  var counts = {
    pet: (document.querySelector('#archive .archive-section[data-type="pet"]')?.getAttribute('data-count')) || 0,
    report: (document.querySelector('#archive .archive-section[data-type="report"]')?.getAttribute('data-count')) || 0,
    appointment: (document.querySelector('#archive .archive-section[data-type="appointment"]')?.getAttribute('data-count')) || 0
  };
  var titles = { pet: 'Archived Pets', report: 'Archived Reports', appointment: 'Archived Appointments' };
  var title = document.getElementById('archiveSectionTitle');
  if (title) {
    title.textContent = (type && titles[type]) ? titles[type] + ' (' + counts[type] + ')' : 'Archived Items';
  }
  document.querySelectorAll('#archive .archive-section').forEach(function(s) {
    var match = !type || s.getAttribute('data-type') === type;
    s.style.display = match ? '' : 'none';
  });
  if (typeof filterArchive === 'function') filterArchive();
}

// ── Pet Functions ──

function viewPetImage(url) {
  var img = document.getElementById('petImagePreview');
  if (img) img.src = url;
  openModal('petImageModal');
}

function previewPetImage(input, previewId) {
  var preview = document.getElementById(previewId);
  if (!preview) return;
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.style.display = 'block';
      preview.querySelector('img').src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// ── Report Functions ──
function viewReportImage(url) {
  var img = document.getElementById('reportImagePreview');
  if (img) img.src = url;
  openModal('reportImageModal');
}

function previewReportImage(input, previewId) {
  var preview = document.getElementById(previewId);
  if (!preview) return;
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.style.display = 'block';
      preview.querySelector('img').src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// ── Appointment Functions ──

// ── Avatar / Profile ──
function confirmRemoveAvatar() {
  var form = document.createElement('form');
  form.method = 'POST'; form.action = 'remove-photo.php';
  document.body.appendChild(form); form.submit();
}

// ── Toast ──
function showToast(msg, type) {
  var toast = document.getElementById('toast');
  var toastMsg = document.getElementById('toastMsg');
  if (!toast || !toastMsg) return;
  toastMsg.textContent = msg || 'Success!';
  if (type === 'error') toast.style.background = '#dc2626';
  else toast.style.background = '#045e1f';
  toast.classList.add('show');
  setTimeout(function() { toast.classList.remove('show'); }, 3000);
}

// ── Tab Switching (Appointments) ──
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tabId = this.getAttribute('data-tab');
      if (!tabId) return;
      document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
      var tab = document.getElementById(tabId);
      if (tab) tab.classList.add('active');
    });
  });
});

// ── Client-side Pagination ──
window.paginateTable = function(wrapperId, page) {
  var wrapper = document.getElementById(wrapperId);
  if (!wrapper) return;
  var perPage = parseInt(wrapper.dataset.perPage) || 15;
  var tbody = wrapper.querySelector('tbody');
  if (!tbody) return;
  var allRows = Array.from(tbody.querySelectorAll('tr'));
  var visibleRows = allRows.filter(function(r) { return r.style.display !== 'none'; });
  var totalVisible = visibleRows.length;
  var totalPages = Math.ceil(totalVisible / perPage) || 1;
  if (page > totalPages) page = totalPages;
  if (page < 1) page = 1;
  allRows.forEach(function(r) { r.style.display = 'none'; });
  var start = (page - 1) * perPage;
  var end = Math.min(start + perPage, totalVisible);
  for (var i = start; i < end; i++) { visibleRows[i].style.display = ''; }
  var pagBar = wrapper.querySelector('.pagination-bar');
  if (!pagBar) return;
  if (totalPages <= 1) { pagBar.innerHTML = ''; return; }
  var html = '<span class="pag-info">Page ' + page + ' of ' + totalPages + ' &nbsp;·&nbsp; ' + totalVisible + ' total</span><div class="pag-btns">';
  if (page > 1) html += '<a href="#" onclick="paginateTable(\'' + wrapperId + '\', ' + (page-1) + '); return false;" class="pag-btn"><i class="fas fa-chevron-left"></i></a>';
  var sP = Math.max(1, page - 2);
  var eP = Math.min(totalPages, page + 2);
  for (var p = sP; p <= eP; p++) html += '<a href="#" onclick="paginateTable(\'' + wrapperId + '\', ' + p + '); return false;" class="pag-btn' + (p === page ? ' pag-active' : '') + '">' + p + '</a>';
  if (page < totalPages) html += '<a href="#" onclick="paginateTable(\'' + wrapperId + '\', ' + (page+1) + '); return false;" class="pag-btn"><i class="fas fa-chevron-right"></i></a>';
  html += '</div>';
  pagBar.innerHTML = html;
  wrapper.dataset.currentPage = page;
};

// ── Staff-specific functions (stubs for missing handlers) ──
function openAddAppointmentModal() { if (typeof openReviewSheet === 'function') { openReviewSheet(); } else { openModal('addAppointmentModal'); } }
function toggleStaffNotif() { document.getElementById('staffNotifPanel').classList.toggle('open'); }
function markStaffNotifRead(e) { if(e)e.preventDefault(); fetch('mark-notifications-read.php?type=staff').then(function(){location.reload();}); }
function markPetAdopted(listingId, apptId) { if(confirm('Mark this pet as adopted?')){location.href='staff-pets-handler.php?action=adopt&listing_id='+listingId+'&appt_id='+apptId;} }
function viewValidatedReport(id) {
  fetch('staff-get-history.php?report_id=' + id).then(function(r){return r.json();}).then(function(data){
    if(data.success){openModal('validatedReportModal');document.getElementById('validatedReportBody').innerHTML='<pre>'+JSON.stringify(data.report,null,2)+'</pre>';}
    else{alert('Report not found.');}
  }).catch(function(){alert('Error loading report.');});
}
function viewOwnerProfile(userId) {
  window.location.href = 'dashboard-staff.php?view_owner=' + userId + '#client-pets';
}
