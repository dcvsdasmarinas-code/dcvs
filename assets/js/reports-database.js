/* Reports Database (All Reports) — scoped with rpt* to avoid clashing with client-database.js */
(function () {
  if (typeof RPT_ITEMS === 'undefined') return;
  var ALL = RPT_ITEMS || [];

  var state = {
    group: 'all',
    quick: '',
    search: '',
    type: '',
    brgy: '',
    status: '',
    dateFrom: '',
    dateTo: '',
    sort: 'newest',
    view: 'table',
    page: 1,
    perPage: 10,
    sortCol: '',
    sortDir: ''
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function initials(n) {
    return String(n || '?').split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase() || '?';
  }

  function typeClass(t) {
    return 'type-' + String(t || 'stray');
  }

  function filtered() {
    var rows = ALL.slice();
    if (state.group !== 'all') rows = rows.filter(function (r) { return r.status === state.group; });
    if (state.search) {
      var q = state.search.toLowerCase();
      rows = rows.filter(function (r) {
        return (r.ref || '').toLowerCase().indexOf(q) >= 0 ||
          (r.reporter || '').toLowerCase().indexOf(q) >= 0 ||
          (r.desc || '').toLowerCase().indexOf(q) >= 0 ||
          (r.typeLabel || '').toLowerCase().indexOf(q) >= 0;
      });
    }
    if (state.type) rows = rows.filter(function (r) { return r.type === state.type; });
    if (state.brgy) rows = rows.filter(function (r) { return r.brgy === state.brgy; });
    if (state.status) rows = rows.filter(function (r) { return r.status === state.status; });
    if (state.dateFrom) rows = rows.filter(function (r) { return r.ts >= new Date(state.dateFrom).getTime(); });
    if (state.dateTo) rows = rows.filter(function (r) { return r.ts <= new Date(state.dateTo + 'T23:59:59').getTime(); });
    rows.sort(function (a, b) {
      if (state.sort === 'oldest') return a.ts - b.ts;
      if (state.sort === 'type') return a.typeLabel.localeCompare(b.typeLabel);
      if (state.sort === 'brgy') return a.brgy.localeCompare(b.brgy);
      return b.ts - a.ts;
    });
    if (state.sortCol) {
      var dir = state.sortDir === 'desc' ? -1 : 1;
      var col = state.sortCol;
      rows.sort(function (a, b) {
        var va = colVal(a, col), vb = colVal(b, col);
        if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
        return va < vb ? -1 * dir : (va > vb ? 1 * dir : 0);
      });
    }
    return rows;
  }

  function colVal(x, col) {
    if (col === 'type') return String(x.typeLabel || x.type || '').toLowerCase();
    if (col === 'date') return x.ts || 0;
    return String(x[col] != null ? x[col] : '').toLowerCase();
  }

  function sortHeaderHtml(col, label) {
    var active = state.sortCol === col;
    var icon;
    if (active && state.sortDir === 'asc') icon = '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>';
    else if (active && state.sortDir === 'desc') icon = '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
    else icon = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
    return '<button type="button" class="th-sort' + (active ? ' th-sorted' : '') + '" data-col="' + col + '" onclick="window.rptSortBy(\'' + col + '\')">' + label + '<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + icon + '</svg></button>';
  }

  function statusPill(s) {
    return '<span class="rpt-status ' + esc(s) + '">' + esc(s.charAt(0).toUpperCase() + s.slice(1)) + '</span>';
  }

  function typeBadge(t, label) {
    return '<span class="type-badge ' + typeClass(t) + '"><span class="type-dot"></span>' + esc(label) + '</span>';
  }

  function avatarHtml(r) {
    return r.avatar ? '<img src="' + esc(r.avatar) + '" alt="">' : esc(initials(r.reporter));
  }

  function renderTable(rows) {
    var thead = document.getElementById('rptTableHead');
    var tbody = document.getElementById('rptRows');
    if (!thead || !tbody) return;
    thead.innerHTML =
      '<tr class="sd-tr">' +
      '<th class="sd-th">' + sortHeaderHtml('report', 'Report') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('reporter', 'Reporter') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('contact', 'Contact') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('type', 'Type') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('brgy', 'Barangay') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('status', 'Status') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('date', 'Date') + '</th>' +
      '<th class="sd-th sd-th-action"></th>' +
      '</tr>';
    tbody.innerHTML = rows.length
      ? rows.map(function (r) {
        return '<tr class="sd-tr" data-id="' + r.id + '" onclick="rptSelect(' + r.id + ')" style="cursor:pointer;">' +
          '<td class="sd-td"><div class="client-name">' + esc(r.ref) + '</div></td>' +
          '<td class="sd-td"><div class="client-cell"><div class="avatar">' + avatarHtml(r) + '</div><div><div class="client-name">' + esc(r.reporter) + '</div></div></div></td>' +
          '<td class="sd-td"><div class="contact-main">' + esc(r.phone || '-') + '</div></td>' +
          '<td class="sd-td">' + typeCell(r) + '</td>' +
          '<td class="sd-td"><div class="client-name">' + esc(r.brgy || '-') + '</div></td>' +
          '<td class="sd-td">' + statusPill(r.status) + '</td>' +
          '<td class="sd-td"><div class="contact-main">' + esc(r.date) + '</div></td>' +
          '<td class="sd-td sd-td-action"><button class="sd-row-actions" onclick="event.stopPropagation();rptActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>' +
          '</tr>';
      }).join('')
      : '<tr class="sd-tr"><td class="sd-td" colspan="8"><div class="no-results"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>No results.</p></div></td></tr>';
  }

  function typeCell(r) {
    return '<span class="type-badge ' + typeClass(r.type) + '"><span class="type-dot"></span>' + esc(r.typeShort || r.typeLabel) + '</span>';
  }

  function renderPagination(total) {
    var pages = Math.max(1, Math.ceil(total / state.perPage));
    if (state.page > pages) state.page = pages;
    var prev = document.getElementById('rptPagePrev');
    var next = document.getElementById('rptPageNext');
    var first = document.getElementById('rptPageFirst');
    var last = document.getElementById('rptPageLast');
    var label = document.getElementById('rptPageLabel');
    if (prev) prev.disabled = state.page <= 1;
    if (next) next.disabled = state.page >= pages;
    if (first) first.disabled = state.page <= 1;
    if (last) last.disabled = state.page >= pages;
    if (label) label.textContent = 'Page ' + state.page + ' of ' + pages;
    if (first) first.onclick = function () { state.page = 1; render(); };
    if (prev) prev.onclick = function () { state.page = Math.max(1, state.page - 1); render(); };
    if (next) next.onclick = function () { state.page = Math.min(pages, state.page + 1); render(); };
    if (last) last.onclick = function () { state.page = pages; render(); };
  }

  function render() {
    var rows = filtered();
    var total = rows.length;
    var pageRows = rows.slice((state.page - 1) * state.perPage, state.page * state.perPage);
    renderTable(pageRows);
    renderPagination(total);
  }

  // Expose to global scope (used by inline onclick)
  window.rptSetGroup = function (v) { state.group = v; state.page = 1; toggleGroupBtns(); render(); };
  window.rptSetSearch = function (v) {
    state.search = v;
    state.page = 1;
    var s2 = document.getElementById('rptSearch');
    var s1 = document.getElementById('rptMainSearch');
    if (s2 && s2.value !== v) s2.value = v;
    if (s1 && s1.value !== v) s1.value = v;
    render();
  };
  window.rptSetDate = function () {
    state.dateFrom = document.getElementById('rptDateFrom') ? document.getElementById('rptDateFrom').value : '';
    state.dateTo = document.getElementById('rptDateTo') ? document.getElementById('rptDateTo').value : '';
    state.page = 1;
    render();
  };
  window.rptSetType = function (v) { state.type = v; state.page = 1; render(); };
  window.rptSetBrgy = function (v) { state.brgy = v; state.page = 1; render(); };
  window.rptSetStatus = function (v) { state.status = v; state.page = 1; render(); };
  window.rptSetSort = function (v) { state.sort = v; state.page = 1; render(); };
  window.rptSetView = function (v) {
    state.view = v;
    state.page = 1;
    document.querySelectorAll('#rptViewSeg .seg-btn').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-view') === v);
    });
    render();
    if (v === 'map') rptEnsureMap();
  };
  window.rptGoPage = function (p) {
    if (p === 'prev') p = state.page - 1;
    if (p === 'next') p = state.page + 1;
    if (p < 1) return;
    state.page = p;
    render();
  };

  window.rptSortBy = function (col) {
    if (state.sortCol === col) { state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; }
    else { state.sortCol = col; state.sortDir = 'asc'; }
    state.page = 1;
    render();
  };

  window.setPerPage = function (v) {
    state.perPage = parseInt(v, 10);
    state.page = 1;
    render();
  };

  function toggleGroupBtns() {
    document.querySelectorAll('#reports-all .filter-item[data-filter="group"]').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-value') === state.group);
    });
  }

  // Row actions popup
  var actionPop = null;
  function closeActions() {
    if (actionPop) { actionPop.remove(); actionPop = null; }
    document.removeEventListener('click', closeActions);
  }
  window.rptActions = function (ev, id) {
    ev.stopPropagation();
    closeActions();
    var r = ALL.find(function (x) { return x.id === id; }) || {};
    var pop = document.createElement('div');
    pop.className = 'cpd row-menu-pop open';
    pop.style.position = 'fixed';
    pop.innerHTML =
      '<button class="row-menu-item" onclick="rptSelect(' + id + ');closeActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Report</button>' +
      (r.photo ? '<button class="row-menu-item" onclick="rptViewPhoto(\'' + esc(r.photo) + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>View Attachments</button>' : '') +
      '<button class="row-menu-item" onclick="rptViewLocation(\'' + esc(r.brgy) + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>View Location</button>' +
      '<button class="row-menu-item" onclick="rptSelect(' + id + ');closeActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>View History</button>';
    var rect = ev.currentTarget.getBoundingClientRect();
    pop.style.top = (rect.bottom + 6) + 'px';
    pop.style.left = Math.min(rect.left, window.innerWidth - 220) + 'px';
    document.body.appendChild(pop);
    actionPop = pop;
    setTimeout(function () { document.addEventListener('click', closeActions); }, 0);
  };
  window.closeActions = closeActions;

  window.rptViewPhoto = function (url) {
    window.open(url, '_blank');
  };
  window.rptViewLocation = function (brgy) {
    window.location.href = 'dashboard-staff-geo.php?brgy=' + encodeURIComponent(brgy || '');
  };

  // Detail drawer with tabs
  var rptDetailTab = 'details';
  window.rptSelect = function (id) {
    var r = ALL.find(function (x) { return x.id === id; });
    if (!r) return;
    rptDetailTab = 'details';
    document.querySelectorAll('#rptRows tr').forEach(function (tr) {
      tr.classList.toggle('selected', tr.getAttribute('data-id') == String(id));
    });
    var box = document.getElementById('rptDetailContent');
    if (!box) return;

    var history = [];
    history.push({ t: 'Report submitted', d: r.date });
    if (r.statusRaw === 'validated') history.push({ t: 'Verified by Barangay', d: r.date });
    if (r.confirmed) history.push({ t: 'Confirmed by DCVS Staff', d: r.confirmed });
    if (r.scheduled) history.push({ t: 'Scheduled for action', d: r.scheduled });
    if (r.resolved) history.push({ t: 'Resolved by DCVS Staff', d: r.resolved });

    var photoHtml = r.photo
      ? '<div class="rpt-photo-box"><div class="rpt-photo-thumb" onclick="rptViewPhoto(\'' + esc(r.photo) + '\')"><img src="' + esc(r.photo) + '" alt="Report attachment"></div></div>'
      : '<p class="rpt-photo-none">No attachments.</p>';

    var resolvedDate = r.status === 'resolved' ? (r.resolved || r.date) : '-';

    box.innerHTML =
      '<div class="rpt-detail-header">' +
      '<div style="min-width:0;">' +
      '<div class="rpt-detail-ref">' + esc(r.ref) + '</div>' +
      '<div class="rpt-detail-type">' + typeBadge(r.type, r.typeLabel) + '</div>' +
      '</div>' +
      statusPill(r.status) +
      '</div>' +

      '<div class="rpt-tabs">' +
      '<button class="rpt-tab" data-tab="details" onclick="rptDetailGo(\'details\')">Details</button>' +
      '<button class="rpt-tab" data-tab="attachments" onclick="rptDetailGo(\'attachments\')">Attachments</button>' +
      '<button class="rpt-tab" data-tab="history" onclick="rptDetailGo(\'history\')">History</button>' +
      '</div>' +

      '<div class="rpt-detail-body" data-tab="details">' +

      '<div class="rpt-detail-grid">' +

      '<div class="rpt-col">' +
      '<div class="rpt-section-title">Reporter</div>' +
      '<div class="info-rows">' +
      avatarInfoRow('Name', r.reporter, avatarHtml(r)) +
      infoRow('idcard', 'Resident ID', r.reporterRef || '-') +
      infoRow('phone', 'Mobile Number', r.phone || '-') +
      infoRow('home', 'Barangay', r.brgy || '-') +
      '</div>' +
      '</div>' +

      '<div class="rpt-col">' +
      '<div class="rpt-section-title">Report Information</div>' +
      '<div class="info-rows">' +
      infoRow('tag', 'Type', r.typeLabel) +
      infoRow('flag', 'Priority', r.urgency) +
      infoRow('tag2', 'Status', r.statusLabel) +
      infoRow('user2', 'Assigned Staff', r.assigned || '-') +
      infoRow('cal', 'Reported', r.reported || r.date) +
      infoRow('check', 'Resolved', resolvedDate) +
      '</div>' +
      '</div>' +

      '<div class="rpt-col">' +
      '<div class="rpt-section-title">Description</div>' +
      '<div class="rpt-desc-box" id="rptDescBox">' + (r.desc ? esc(r.desc) : 'No description provided.') + '</div>' +
      '</div>' +

      (r.dcvsNotes ? '<div class="rpt-col">' +
      '<div class="rpt-section-title">DCVS Notes</div>' +
      '<div class="rpt-notes-box">' + esc(r.dcvsNotes) + '</div>' +
      '</div>' : '') +

      (r.brgyNotes ? '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title">Barangay Notes</div>' +
      '<div class="rpt-notes-box">' + esc(r.brgyNotes) + '</div>' +
      '</div>' : '') +

      '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title">Location</div>' +
      '<div class="info-rows" style="margin-bottom:22px;">' +
      infoRow('home2', 'Address', r.location || '-') +
      '</div>' +
      '</div>' +

      '</div>' +
      '</div>' +

      '<div class="rpt-detail-body" data-tab="attachments" style="display:none;">' +
      '<div class="rpt-section-title">Attachments</div>' + photoHtml +
      '</div>' +

      '<div class="rpt-detail-body" data-tab="history" style="display:none;">' +
      '<div class="rpt-section-title">Action History</div>' +
      '<ul class="rpt-history">' + history.map(function (h) {
        return '<li>' + esc(h.t) + '<span class="h-date">' + esc(h.d) + '</span></li>';
      }).join('') + '</ul>' +
      '</div>';

    rptDetailRefreshTabs();
  };

  window.rptDetailGo = function (t) {
    rptDetailTab = t;
    rptDetailRefreshTabs();
  };

  function rptDetailRefreshTabs() {
    document.querySelectorAll('#rptDetailContent .rpt-tab').forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === rptDetailTab);
    });
    document.querySelectorAll('#rptDetailContent .rpt-detail-body').forEach(function (b) {
      b.style.display = b.getAttribute('data-tab') === rptDetailTab ? '' : 'none';
    });
  }

  window.rptViewProfile = function (userId) {
    if (userId && typeof loadClient === 'function') {
      loadClient(userId, 'profile');
      if (typeof switchSection === 'function') switchSection('clients');
    }
  };

  window.rptClearFilters = function () {
    state.group = 'all';
    state.search = '';
    state.type = '';
    state.brgy = '';
    state.status = '';
    state.dateFrom = '';
    state.dateTo = '';
    state.page = 1;
    var i;
    ['rptMainSearch', 'rptSearch', 'rptType', 'rptBrgy'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.value = '';
    });
    var df = document.getElementById('rptDateFrom');
    var dt = document.getElementById('rptDateTo');
    if (df) df.value = '';
    if (dt) dt.value = '';
    var st = document.getElementById('rptStatus');
    if (st) st.value = '';
    toggleGroupBtns();
    render();
  };

  window.rptExportCSV = function () {
    var rows = filtered();
    var csv = [['Report ID', 'Reporter', 'Mobile Number', 'Type', 'Barangay', 'Status', 'Date']];
    rows.forEach(function (r) {
      csv.push([r.ref, r.reporter, r.phone || '', r.typeLabel, r.brgy || '', r.statusLabel || r.status, r.date]);
    });
    var text = csv.map(function (r) {
      return r.map(function (c) { return '"' + String(c == null ? '' : c).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\n');
    var a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('\uFEFF' + text);
    a.download = 'all-reports.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  };

  // Navigate to the geo map with the barangays currently visible in the list highlighted
  window.rptViewMap = function () {
    var rows = filtered();
    var brgys = [];
    rows.forEach(function (r) {
      if (r.brgy && brgys.indexOf(r.brgy) === -1) brgys.push(r.brgy);
    });
    var q = brgys.length ? '?brgy=' + encodeURIComponent(brgys.join(',')) : '';
    window.location.href = 'dashboard-staff-geo.php' + q;
  };

  function infoRow(icon, label, value) {
    var icons = {
      user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
      user2: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3h4v4"/><line x1="20" y1="3" x2="14" y2="9"/>',
      idcard: '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="7" y1="9" x2="14" y2="9"/><line x1="7" y1="13" x2="14" y2="13"/><line x1="7" y1="17" x2="10" y2="17"/><circle cx="17" cy="11" r="2"/><line x1="17" y1="13" x2="17" y2="15"/>',
      phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
      home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
      home2: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
      cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
      flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
      check: '<polyline points="20 6 9 17 4 12"/>',
      tag: '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
      tag2: '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/><circle cx="12" cy="12" r="2"/>'
    };
    return '<div class="info-row"><div class="info-row-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (icons[icon] || '') + '</svg></div><div><p class="info-row-label">' + esc(label) + '</p><p class="info-row-value">' + esc(value) + '</p></div></div>';
  }

  function avatarInfoRow(label, value, avatarHtml) {
    return '<div class="info-row"><div class="info-row-icon info-row-avatar">' + avatarHtml + '</div><div><p class="info-row-label">' + esc(label) + '</p><p class="info-row-value">' + esc(value) + '</p></div></div>';
  }

  // Map view (MapCN)
  var rptMapInited = false;
  var rptMapInstance = null;
  var rptMapFeatures = [];

  function rptEnsureMap() {
    if (rptMapInited) {
      if (rptMapInstance && typeof rptMapInstance.resize === 'function') rptMapInstance.resize();
      return;
    }
    var el = document.getElementById('rptGeoMap');
    if (!el || typeof MapCN === 'undefined' || typeof MapCN.init !== 'function') return;
    rptMapInited = true;
    el.addEventListener('mapcn:load', function (e) {
      rptMapInstance = e.detail && e.detail.map;
      rptMapLoadData();
    });
    MapCN.init('rptGeoMap', { center: [120.968, 14.306], zoom: 12, barangayOverlay: false });
  }

  function rptMapDataUrl() {
    var url = 'geo-map/barangays-public.geojson.php';
    var t = document.getElementById('rptMapType');
    var s = document.getElementById('rptMapStatus');
    var params = [];
    if (t && t.value) params.push('type=' + encodeURIComponent(t.value));
    if (s && s.value) params.push('status=' + encodeURIComponent(s.value));
    return params.length ? url + '?' + params.join('&') : url;
  }

  function rptMapLoadData() {
    var map = rptMapInstance;
    if (!map) return;
    fetch(rptMapDataUrl())
      .then(function (res) { return res.json(); })
      .then(function (geo) {
        if (!geo || !geo.features) return;
        rptMapFeatures = geo.features || [];
        var fill = map.getSource('rpt-barangays');
        if (fill) fill.setData(geo);
        else {
          map.addSource('rpt-barangays', { type: 'geojson', data: geo });
          map.addLayer({
            id: 'rpt-barangay-fill', type: 'fill', source: 'rpt-barangays',
            paint: {
              'fill-color': ['match', ['get', 'risk'],
                'Green', '#22c55e', 'Yellow', '#eab308', 'Orange', '#f97316', 'Red', '#ef4444', '#22c55e'],
              'fill-opacity': 0.55
            }
          });
          map.addLayer({
            id: 'rpt-barangay-outline', type: 'line', source: 'rpt-barangays',
            paint: { 'line-color': '#ffffff', 'line-width': 1.2, 'line-opacity': 0.85 }
          });
          map.addLayer({
            id: 'rpt-barangay-label', type: 'symbol', source: 'rpt-barangays',
            layout: {
              'text-field': ['get', 'name'],
              'text-size': 11, 'text-anchor': 'center', 'text-allow-overlap': false
            },
            paint: { 'text-color': '#1e293b', 'text-halo-color': '#ffffff', 'text-halo-width': 2 }
          });
          map.on('click', 'rpt-barangay-fill', function (ev) {
            if (ev.features && ev.features.length) rptMapOpenPanel(ev.features[0].properties);
          });
          map.on('mouseenter', 'rpt-barangay-fill', function () { map.getCanvas().style.cursor = 'pointer'; });
          map.on('mouseleave', 'rpt-barangay-fill', function () { map.getCanvas().style.cursor = ''; });
        }
        var lb = map.getSource('rpt-brgy-labels');
        if (lb) lb.setData(geo);
      })
      .catch(function () {});
  }

  window.rptMapFilter = function () {
    rptMapLoadData();
  };

  window.rptMapSearch = function (q) {
    var map = rptMapInstance;
    if (!map || !rptMapFeatures.length) return;
    q = String(q || '').toLowerCase().trim();
    if (!q) return;
    var hit = null;
    for (var i = 0; i < rptMapFeatures.length; i++) {
      if ((rptMapFeatures[i].properties.name || '').toLowerCase().indexOf(q) >= 0) { hit = rptMapFeatures[i]; break; }
    }
    if (hit) {
      map.fitBounds(bbFromFeature(hit), { padding: 40, maxZoom: 13, duration: 800 });
      rptMapOpenPanel(hit.properties);
    }
  };

  function bbFromFeature(f) {
    var coords = (f.geometry && f.geometry.coordinates) || [];
    if (!coords.length) return [[120.95, 14.28], [120.99, 14.33]];
    if (f.geometry.type === 'Point') {
      var c = coords;
      return [[c[0] - 0.01, c[1] - 0.01], [c[0] + 0.01, c[1] + 0.01]];
    }
    var minLng = Infinity, minLat = Infinity, maxLng = -Infinity, maxLat = -Infinity;
    function walk(poly) {
      if (poly.length && typeof poly[0] === 'number') {
        if (poly[0] < minLng) minLng = poly[0];
        if (poly[0] > maxLng) maxLng = poly[0];
        if (poly[1] < minLat) minLat = poly[1];
        if (poly[1] > maxLat) maxLat = poly[1];
        return;
      }
      poly.forEach(walk);
    }
    walk(coords);
    return [[minLng, minLat], [maxLng, maxLat]];
  }

  function rptMapOpenPanel(p) {
    var name = p.name || 'Unknown';
    document.getElementById('rptGeoPanelName').textContent = name;
    document.getElementById('rptGeoPanelConfirmed').textContent = p.confirmed || 0;
    document.getElementById('rptGeoPanelPending').textContent = p.pending || 0;
    document.getElementById('rptGeoPanelResolved').textContent = p.resolved || 0;
    var risk = p.risk || 'Green';
    var badge = document.getElementById('rptGeoPanelRisk');
    badge.textContent = risk;
    badge.style.background = risk === 'Red' ? '#fee2e2' : risk === 'Orange' ? '#ffedd5' : risk === 'Yellow' ? '#fef9c3' : '#dcfce7';
    badge.style.color = risk === 'Red' ? '#991b1b' : risk === 'Orange' ? '#9a3412' : risk === 'Yellow' ? '#854d0e' : '#166534';
    document.getElementById('rptGeoInfoPanel').classList.add('open');
  }

  window.rptMapClosePanel = function () {
    var p = document.getElementById('rptGeoInfoPanel');
    if (p) p.classList.remove('open');
  };

  window.rptMapViewReports = function () {
    var name = document.getElementById('rptGeoPanelName').textContent;
    var brgySel = document.getElementById('rptBrgy');
    if (brgySel) {
      var found = false;
      for (var i = 0; i < brgySel.options.length; i++) {
        if (brgySel.options[i].value === name) { brgySel.selectedIndex = i; found = true; break; }
      }
      if (!found) {
        var opt = document.createElement('option');
        opt.value = name; opt.textContent = name;
        brgySel.appendChild(opt); brgySel.value = name;
      }
    }
    rptMapClosePanel();
    rptSetBrgy(name);
    rptSetView('table');
    var sec = document.getElementById('reports-all');
    if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  // Resize map when the reports section becomes visible again
  var _section = document.getElementById('reports-all');
  if (_section) {
    new MutationObserver(function () {
      if (_section.style.display !== 'none' && rptMapInstance && typeof rptMapInstance.resize === 'function') {
        rptMapInstance.resize();
      }
    }).observe(_section, { attributes: true, attributeFilter: ['style'] });
  }
  window.addEventListener('resize', function () {
    if (rptMapInstance && typeof rptMapInstance.resize === 'function' &&
        document.getElementById('reports-all') && document.getElementById('reports-all').style.display !== 'none') {
      rptMapInstance.resize();
    }
  });

  // Init
  render();
})();