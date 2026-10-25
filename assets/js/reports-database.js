/* Reports Database (All Reports) — scoped with rpt* to avoid clashing with client-database.js */
(function () {
  if (typeof RPT_ITEMS === 'undefined') return;
  var ALL = RPT_ITEMS || [];

  // Post-reload flash toast (set by bulk action summaries before location.reload())
  try {
    var _rptFlash = JSON.parse(sessionStorage.getItem('rptBulkToast') || 'null');
    if (_rptFlash) {
      sessionStorage.removeItem('rptBulkToast');
      setTimeout(function () { rptToast(_rptFlash.t, _rptFlash.d); }, 400);
    }
  } catch (e) {}

  var state = {
    group: 'all',
    responseQueue: false,
    quick: '',
    search: '',
    type: '',
    brgy: '',
    status: '',
    residentStatus: 'active',
    dateFrom: '',
    dateTo: '',
    sort: 'newest',
    view: 'table',
    page: 1,
    perPage: 10,
    sortCol: '',
    sortDir: ''
  };
  var rptSelected = Object.create(null);

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function isTurnoverPending(report) {
    return String(report.statusRaw || '').toLowerCase() === 'resolved'
      && String(report.turnoverStatus || '').toLowerCase() === 'pending';
  }

  function turnoverFooterHtml(report) {
    if (!isTurnoverPending(report)) return '';
    var planned = !!report.turnoverMethod && !!report.turnoverPlannedAtRaw;
    var reportId = Number(report.id);
    var confirm = planned
      ? '<button type="button" class="sd-btn rpt-turnover-confirm" onclick="responseWorkflowAction(' + reportId + ',\'confirm_turnover\')">Confirm Turnover Received</button>'
      : '<button type="button" class="sd-btn rpt-turnover-confirm" disabled title="Barangay must set the handover plan first">Awaiting Handover Plan</button>';
    return confirm + '<button type="button" class="sd-btn sd-btn-outline rpt-turnover-archive" disabled title="Archive is available after DCVS confirms receipt">Archive (turnover pending)</button>';
  }

  function ensureTurnoverQueueButton() {
    if (!window.DCVS_BARANGAY_RESPONSE_ENABLED) return;
    var reportsToolbar = document.querySelector('#reports-all .db-toolbar');
    if (!reportsToolbar || document.getElementById('rptAwaitingTurnoverFilter')) return;
    var turnoverButton = document.createElement('button');
    turnoverButton.id = 'rptAwaitingTurnoverFilter';
    turnoverButton.type = 'button';
    turnoverButton.className = 'sd-btn sd-btn-outline';
    turnoverButton.textContent = 'Awaiting Turnover (' + ALL.filter(function (r) { return r.responseState === 'awaiting_turnover'; }).length + ')';
    turnoverButton.setAttribute('aria-pressed', 'false');
    turnoverButton.addEventListener('click', window.rptToggleTurnoverQueue);
    reportsToolbar.appendChild(turnoverButton);
  }

  function parseDateCol(d) {
    if (!d || d === '—') return { day: '—', month: '' };
    var m = new Date(d);
    if (isNaN(m.getTime())) return { day: '—', month: '' };
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return { day: String(m.getDate()), month: months[m.getMonth()] };
  }

  function initials(n) {
    return String(n || '?').split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase() || '?';
  }

  function typeClass(t) {
    return 'type-' + String(t || 'stray');
  }

  function filtered() {
    var rows = ALL.slice();
    rows = rows.filter(function (r) { return String(r.reporterStatus || 'active') === state.residentStatus; });
    if (state.group !== 'all') rows = rows.filter(function (r) { return r.status === state.group; });
    if (state.responseQueue) rows = rows.filter(function (r) { return r.responseState === 'awaiting_turnover'; });
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
    if (state.dateFrom) rows = rows.filter(function (r) { return r.ts >= new Date(state.dateFrom + 'T00:00:00').getTime(); });
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

  function updateKpis() {
    var rows = ALL.filter(function (r) { return String(r.reporterStatus || 'active') === state.residentStatus; });
    var statusCounts = { active: 0, pending: 0, resolved: 0 };
    rows.forEach(function (r) {
      if (Object.prototype.hasOwnProperty.call(statusCounts, r.status)) statusCounts[r.status]++;
    });
    var values = document.querySelectorAll('#reports-all .kpi-value');
    if (values[0]) values[0].textContent = rows.length;
    if (values[1]) values[1].textContent = statusCounts.active;
    if (values[2]) values[2].textContent = statusCounts.pending;
    if (values[3]) values[3].textContent = statusCounts.resolved;
  }

  function colVal(x, col) {
    if (col === 'type') return String(x.typeLabel || x.type || '').toLowerCase();
    if (col === 'date') return x.ts || 0;
    if (col === 'submitted') return x.submittedTs || 0;
    if (col === 'incident') return x.ts || 0;
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

  function statusPill(s, label) {
    s = String(s || '');
    return '<span class="rpt-status ' + esc(s) + '">' + esc(label || (s.charAt(0).toUpperCase() + s.slice(1))) + '</span>';
  }
  function statusClassForReport(report) {
    var rawStatus = String(report.statusRaw || '').toLowerCase();
    var statusLabel = String(report.statusLabel || '').toLowerCase();
    if (/^pending(?:\s|$)/.test(statusLabel)) return 'pending';
    if (rawStatus === 'validated') return 'validated';
    if (rawStatus === 'confirmed' || rawStatus === 'scheduled') return 'active';
    return String(report.status || 'pending').toLowerCase();
  }
  function statusBadgeStyle(report) {
    var colors = {
      pending: ['#FEF3C7', '#92400E', '#FDE68A'],
      validated: ['#dcebe0', '#045e1f', '#bbf7d0'],
      active: ['#EFF6FF', '#1E40AF', '#BFDBFE'],
      resolved: ['#0E8131', '#ffffff', '#0E8131'],
      rejected: ['#FEE2E2', '#991B1B', '#FECACA']
    };
    var color = colors[statusClassForReport(report)] || colors.pending;
    return 'color:' + color[1] + ';background:' + color[0] + ';border:1px solid ' + color[2] + ';';
  }

  function priorityPill(p) {
    var v = (p || '').toLowerCase();
    var colors = {
      high:   { bg: '#FEF2F2', color: '#991B1B', border: '#FECACA' },
      medium: { bg: '#FFF7ED', color: '#9A3412', border: '#FED7AA' },
      low:    { bg: '#EFF6FF', color: '#1E40AF', border: '#BFDBFE' }
    };
    var c = colors[v] || { bg: '#F1F5F9', color: '#64748B', border: '#E2E8F0' };
    return '<span class="rpt-status" style="font-family:Inter,sans-serif;font-size:12px;font-weight:500;padding:3px 10px;border-radius:4px;border:1px solid ' + c.border + ';background:' + c.bg + ';color:' + c.color + ';">' + esc(p || '-') + '</span>';
  }

  function typeBadge(t, label) {
    return '<span class="type-badge ' + typeClass(t) + '"><span class="type-dot"></span>' + esc(label) + '</span>';
  }

  function avatarHtml(r) {
    return r.avatar ? '<img src="' + esc(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(r.avatar) : r.avatar) + '" alt="">' : esc(initials(r.reporter));
  }

  function renderTable(rows) {
    var thead = document.getElementById('rptTableHead');
    var tbody = document.getElementById('rptRows');
    if (!thead || !tbody) return;
    thead.innerHTML =
      '<tr class="sd-tr">' +
      '<th class="sd-th" style="width:40px;"><input type="checkbox" class="sd-table-cb" id="rptSelectAll" onclick="rptToggleSelectAll(this)" /></th>' +
      '<th class="sd-th">' + sortHeaderHtml('report', 'Report') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('reporter', 'Reporter') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('contact', 'Contact') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('type', 'Type') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('urgency', 'Priority') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('brgy', 'Barangay') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('status', 'Status') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('submitted', 'Date Submitted') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('incident', 'Incident Date') + '</th>' +
      '<th class="sd-th">' + sortHeaderHtml('resolved', 'Date Resolved') + '</th>' +
      '<th class="sd-th sd-th-action"></th>' +
      '</tr>';
    tbody.innerHTML = rows.length
      ? rows.map(function (r) {
        return '<tr class="sd-tr" data-id="' + r.id + '" onclick="rptSelect(' + r.id + ')" style="cursor:pointer;">' +
          '<td class="sd-td"><input type="checkbox" class="sd-table-cb row-cb" value="' + r.id + '"' + (rptSelected[String(r.id)] ? ' checked' : '') + ' onclick="event.stopPropagation();rptToggleRow(this)" /></td>' +
          '<td class="sd-td"><div class="client-name" style="font-weight:400;">' + esc(r.ref) + '</div></td>' +
          '<td class="sd-td"><div class="client-cell"><div class="avatar">' + avatarHtml(r) + '</div><div><div class="client-name">' + esc(r.reporter) + '</div></div></div></td>' +
          '<td class="sd-td"><div class="contact-main">' + esc(r.phone || '-') + '</div><div class="contact-sub">' + esc(r.email || '-') + '</div></td>' +
          '<td class="sd-td">' + typeCell(r) + '</td>' +
          '<td class="sd-td">' + priorityPill(r.urgency) + '</td>' +
          '<td class="sd-td"><div class="client-name">' + esc(r.brgy || '-') + '</div></td>' +
          '<td class="sd-td">' + statusPill(statusClassForReport(r), r.statusLabel) + '</td>' +
          '<td class="sd-td"><div class="contact-main">' + esc(r.submitted || '-') + '</div></td>' +
          '<td class="sd-td"><div class="contact-main">' + esc(r.incidentDate || r.date || '-') + '</div></td>' +
          '<td class="sd-td"><div class="contact-main">' + esc(r.resolved) + '</div></td>' +
          '<td class="sd-td sd-td-action"><button class="sd-row-actions" title="More options" aria-label="More options" onclick="event.stopPropagation();rptActions(event,' + r.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>' +
          '</tr>';
      }).join('')
      : '<tr class="sd-tr"><td class="sd-td" colspan="12"><div class="no-results"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>No results.</p></div></td></tr>';
    rptUpdateBulkBar();
  }

  function typeCell(r) {
    return '<div class="client-name">' + esc(r.typeLabel || r.type || '-') + '</div>';
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

  /* ── Checkbox bulk selection + quick actions (same pattern as All Reports > Barangay Portal) ── */
  var rptBulk = null;
  var rptArchiveOrig = null;
  var rptResolveSaved = null;

  function rptSelectedIds() {
    return Object.keys(rptSelected);
  }

  function rptSelectedItems() {
    return ALL.filter(function (r) { return !!rptSelected[String(r.id)]; });
  }

  window.rptToggleRow = function (cb) {
    var id = String(cb.value);
    if (cb.checked) rptSelected[id] = true;
    else delete rptSelected[id];
    rptUpdateBulkBar();
  };

  window.rptToggleSelectAll = function (cb) {
    document.querySelectorAll('#rptRows .row-cb').forEach(function (b) {
      b.checked = cb.checked;
      var id = String(b.value);
      if (cb.checked) rptSelected[id] = true;
      else delete rptSelected[id];
    });
    rptUpdateBulkBar();
  };

  function rptUpdateBulkBar() {
    var count = Object.keys(rptSelected).length;
    var countEl = document.getElementById('rptBulkCount');
    if (countEl) countEl.textContent = count;
    var bar = document.getElementById('rptBulkBar');
    if (bar) bar.classList.toggle('active', count > 0);
    var all = document.querySelectorAll('#rptRows .row-cb');
    var sel = document.getElementById('rptSelectAll');
    if (sel) {
      var checkedVisible = document.querySelectorAll('#rptRows .row-cb:checked').length;
      sel.checked = all.length > 0 && checkedVisible === all.length;
      sel.indeterminate = checkedVisible > 0 && checkedVisible < all.length;
    }
  }
  window.rptUpdateBulkBar = rptUpdateBulkBar;

  function rptToast(t, d) {
    if (typeof portalToast === 'function') portalToast(t, d);
    else if (typeof cpdToast === 'function') cpdToast(d ? (t + ': ' + d) : t);
  }

  function rptFlashAndReload(t, d) {
    try { sessionStorage.setItem('rptBulkToast', JSON.stringify({ t: t, d: d })); } catch (e) {}
    setTimeout(function () { window.location.reload(); }, 800);
  }

  function rptRemoveItems(ids) {
    ids.forEach(function (id) {
      delete rptSelected[String(id)];
      for (var i = 0; i < ALL.length; i++) {
        if (String(ALL[i].id) === String(id)) { ALL.splice(i, 1); break; }
      }
    });
    render();
    rptUpdateBulkBar();
  }

  /* View Location — unique barangays of selected reports → geo map highlight */
  window.rptBulkViewLocation = function () {
    var items = rptSelectedItems();
    if (!items.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    var brgys = [];
    items.forEach(function (r) {
      var b = String(r.brgy || '').trim();
      if (b && brgys.indexOf(b) === -1) brgys.push(b);
    });
    if (!brgys.length) { rptToast('No Location', 'The selected reports have no barangay location.'); return; }
    window.location.href = 'dashboard-staff-geo.php?brgy=' + encodeURIComponent(brgys.join(','));
  };

  /* Mark as Resolved — only reports with an ACTIVE confirmed case (sequential shadcn alert dialog) */
  window.rptBulkResolve = function () {
    var items = rptSelectedItems();
    if (!items.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    var eligible = items.filter(function (r) {
      return r.caseId && String(r.caseStatus || '').toLowerCase() === 'active';
    });
    if (!eligible.length) {
      rptToast('Nothing to Resolve', 'Only reports with an active confirmed case can be marked as resolved. Other selected reports are ignored.');
      return;
    }
    rptBulk = { type: 'resolve', queue: eligible.slice(), total: eligible.length, skipped: items.length - eligible.length, applied: [] };
    showRptResolveStep();
  };

  function restoreResolveDialog() {
    if (!rptResolveSaved) return;
    var description = document.querySelector('#rptDetailsModal .sd-dialog-description');
    var footer = document.getElementById('rptDetailFooter');
    if (description) description.textContent = rptResolveSaved.description;
    if (footer) footer.innerHTML = rptResolveSaved.footer;
    rptResolveSaved = null;
  }

  function showRptResolveStep() {
    if (!rptBulk || !rptBulk.queue.length) { finishRptResolve(); return; }
    var r = rptBulk.queue[0];
    var idx = rptBulk.total - rptBulk.queue.length + 1;
    var description = document.querySelector('#rptDetailsModal .sd-dialog-description');
    var footer = document.getElementById('rptDetailFooter');
    if (!rptResolveSaved) {
      rptResolveSaved = {
        description: description ? description.textContent : 'Review report information and case details.',
        footer: footer ? footer.innerHTML : ''
      };
    }
    window.rptSelect(r.id);
    description = document.querySelector('#rptDetailsModal .sd-dialog-description');
    footer = document.getElementById('rptDetailFooter');
    var msg = 'Review this active case before marking it resolved.';
    if (rptBulk.total > 1) msg += ' Progress: ' + idx + ' of ' + rptBulk.total + ' active cases.';
    if (rptBulk.skipped && idx === 1) msg += ' ' + rptBulk.skipped + ' selected report(s) will be skipped (no active case).';
    if (description) description.textContent = msg;
    if (!footer) return;
    footer.innerHTML = '<button type="button" class="sd-btn sd-btn-outline" id="rptBulkResolveCancel">Cancel</button>' +
      '<button type="button" class="sd-btn sd-btn-default" id="rptBulkResolveConfirm">Mark as Resolved</button>';
    var cancel = document.getElementById('rptBulkResolveCancel');
    var confirm = document.getElementById('rptBulkResolveConfirm');
    cancel.onclick = function () { closeModal('rptDetailsModal'); rptAbort(); };
    confirm.onclick = function () {
      var seq = rptBulk;
      if (!seq || !seq.queue.length) return;
      var item = seq.queue[0];
      if (!item.caseId) {
        rptToast('Cannot Resolve', 'This report does not have an active case.');
        return;
      }
      openResolveModal(item.caseId, item.typeLabel || item.type || 'Report', item.reporter || 'Resident');
    };
  }

  window.rptHandleResolveForm = function (form) {
    var drawerSequence = !rptBulk && rptDrawerBulk && rptDrawerBulk.type === 'resolve' ? rptDrawerBulk : null;
    if ((!rptBulk || rptBulk.type !== 'resolve') && !drawerSequence) return false;
    var seq = drawerSequence || rptBulk;
    var item = seq.queue[0];
    if (!item) return true;

    var button = form.querySelector('button[type="submit"]');
    var originalText = button ? button.innerHTML : '';
    if (drawerSequence) seq.processing = true;
    if (button) {
      button.disabled = true;
      button.textContent = 'Resolving...';
    }
    var formData = new FormData(form);
    formData.set('ajax', '1');
    fetch(form.action, { method: 'POST', body: formData })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data || !data.success) {
            throw new Error(data && data.message ? data.message : 'Could not resolve this case.');
          }
          return data;
        });
      })
      .then(function () {
        if (drawerSequence) {
          if (rptDrawerBulk !== seq) return;
          seq.applied.push(item);
          seq.queue.shift();
          seq.processing = false;
          closeModal('resolveModal');
          if (seq.queue.length) showRptDrawerBulkStep();
          else finishRptDrawerBulk();
          return;
        }
        if (rptBulk !== seq) return;
        seq.queue.shift();
        seq.applied.push(item);
        closeModal('resolveModal');
        if (seq.queue.length) showRptResolveStep();
        else finishRptResolve();
      })
      .catch(function (error) {
        if (drawerSequence && rptDrawerBulk === seq) seq.processing = false;
        console.error('Could not resolve selected case:', error);
        if (window.rptToast) rptToast('Could Not Resolve', error.message || 'Please try again.');
      })
      .then(function () {
        if (button && document.body.contains(button)) {
          button.disabled = false;
          button.innerHTML = originalText;
        }
      });
    return true;
  };

  function finishRptResolve() {
    if (!rptBulk) return;
    var seq = rptBulk;
    rptBulk = null;
    restoreResolveDialog();
    closeModal('rptDetailsModal');
    var d = seq.applied.length + ' of ' + seq.total + ' case(s) marked as resolved.' +
      (seq.skipped ? ' ' + seq.skipped + ' skipped (no active case).' : '');
    rptFlashAndReload('Cases Resolved', d);
  }

  /* Archive — pending reports blocked (must be validated first by the barangay);
     reuses the existing archiveReportModal alert dialog, one step per eligible report */
  window.rptBulkArchive = function () {
    var items = rptSelectedItems();
    if (!items.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    var pending = items.filter(function (r) { return r.status === 'pending'; });
    var turnoverPending = items.filter(function (r) { return isTurnoverPending(r); });
    var eligible = items.filter(function (r) { return r.status !== 'pending' && !isTurnoverPending(r); });
    if (!eligible.length) {
      rptToast('Cannot Archive', turnoverPending.length
        ? 'Pending turnover reports cannot be archived until DCVS confirms receipt.'
        : 'This report must be validated first by the barangay before it can be archived.');
      return;
    }
    rptBulk = { type: 'archive', queue: eligible.slice(), total: eligible.length, skipped: pending.length + turnoverPending.length, applied: [], failed: 0 };
    showRptArchiveStep();
  };

  function showRptArchiveStep() {
    if (!rptBulk || !rptBulk.queue.length) { finishRptArchive(); return; }
    var root = document.getElementById('archiveReportModal');
    var descEl = root ? root.querySelector('.sd-dialog-description') : null;
    var btnOk = document.getElementById('archiveReportConfirmBtn');
    var btnCancel = root ? root.querySelector('.sd-btn-default') : null;
    if (!rptArchiveOrig && descEl && btnOk && btnCancel) {
      rptArchiveOrig = { descHtml: descEl.innerHTML, ok: btnOk.onclick, cancel: btnCancel.onclick };
    }
    var r = rptBulk.queue[0];
    var idx = rptBulk.total - rptBulk.queue.length + 1;
    var selectedCount = rptBulk.total + rptBulk.skipped;
    var txt = 'Archive the report from <strong style="color:#18181b;">' + esc(r.reporter || 'Unknown resident') +
      '</strong> in <strong style="color:#18181b;">' + esc(r.brgy || 'Unknown barangay') +
      '</strong>? Current status: <strong style="color:#18181b;">' + esc(r.statusLabel || r.status || 'Unknown') + '</strong>.';
    txt += ' ' + selectedCount + (selectedCount === 1 ? ' report selected.' : ' reports selected.');
    if (rptBulk.total > 1) txt += ' Reviewing ' + idx + ' of ' + rptBulk.total + ' eligible.';
    if (rptBulk.skipped && idx === 1) txt += ' ' + rptBulk.skipped + ' pending report(s) will be skipped \u2014 they must be validated first by the barangay.';
    if (descEl) descEl.innerHTML = txt;
    if (btnOk) {
      btnOk.disabled = false;
      btnOk.onclick = function () {
        var seq = rptBulk;
        if (!seq || !seq.queue.length) return;
        var item = seq.queue.shift();
        btnOk.disabled = true;
        if (btnCancel) btnCancel.disabled = true;
        var fd = new FormData();
        fd.append('action', 'archive_report');
        fd.append('report_id', item.id);
        fetch('client-database-handler.php', { method: 'POST', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) { if (data && data.success) seq.applied.push(item); else seq.failed++; })
          .catch(function () { seq.failed++; })
          .then(function () {
            if (btnCancel) btnCancel.disabled = false;
            if (rptBulk !== seq) return;
            if (seq.queue.length) showRptArchiveStep();
            else { closeModal('archiveReportModal'); finishRptArchive(); }
          });
      };
    }
    if (btnCancel) btnCancel.onclick = function () { closeModal('archiveReportModal'); rptAbort(); };
    openModal('archiveReportModal');
  }

  function restoreArchiveModal() {
    if (!rptArchiveOrig) return;
    var root = document.getElementById('archiveReportModal');
    var descEl = root ? root.querySelector('.sd-dialog-description') : null;
    var btnOk = document.getElementById('archiveReportConfirmBtn');
    var btnCancel = root ? root.querySelector('.sd-btn-default') : null;
    if (descEl) descEl.innerHTML = rptArchiveOrig.descHtml;
    if (btnOk) { btnOk.onclick = rptArchiveOrig.ok; btnOk.disabled = false; }
    if (btnCancel) { btnCancel.onclick = rptArchiveOrig.cancel; btnCancel.disabled = false; }
    rptArchiveOrig = null;
  }

  function finishRptArchive() {
    if (!rptBulk) return;
    var seq = rptBulk;
    rptBulk = null;
    restoreArchiveModal();
    var d = seq.applied.length + ' of ' + seq.total + ' selected report(s) archived.' +
      (seq.skipped ? ' ' + seq.skipped + ' pending report(s) skipped \u2014 validate them first.' : '') +
      (seq.failed ? ' ' + seq.failed + ' failed.' : '');
    rptFlashAndReload('Reports Archived', d);
  }

  /* Cancel/abort a running bulk sequence (Cancel button, backdrop click, or Escape) */
  function rptAbort() {
    if (!rptBulk) return;
    var seq = rptBulk;
    rptBulk = null;
    if (seq.type === 'archive') restoreArchiveModal();
    if (seq.type === 'resolve') {
      restoreResolveDialog();
      closeModal('rptDetailsModal');
    }
    if (seq.applied && seq.applied.length) {
      if (seq.type === 'archive') {
        rptRemoveItems(seq.applied.map(function (r) { return r.id; }));
        rptToast('Archive Stopped', seq.applied.length + ' report(s) archived before cancelling.' +
          (seq.skipped ? ' ' + seq.skipped + ' pending report(s) skipped \u2014 validate them first.' : ''));
      } else {
        seq.applied.forEach(function (r) {
          r.status = 'resolved';
          r.statusRaw = 'resolved';
          r.statusLabel = 'Resolved';
          r.caseStatus = 'resolved';
        });
        render();
        rptUpdateBulkBar();
        rptToast('Resolve Stopped', seq.applied.length + ' case(s) marked as resolved before cancelling.' +
          (seq.skipped ? ' ' + seq.skipped + ' skipped.' : ''));
      }
    }
  }

  // Treat external closes (backdrop click / Escape) as sequence cancellation
  document.addEventListener('click', function (e) {
    if (!rptBulk || !e.target) return;
    if (rptBulk.type === 'archive' && e.target.id === 'archiveReportModal') rptAbort();
    else if (rptBulk.type === 'resolve' && (e.target.id === 'rptDetailsModal' || e.target.closest('#rptDetailsModal .sd-dialog-close'))) rptAbort();
  }, true);
  document.addEventListener('keydown', function (e) {
    if (!rptBulk || e.key !== 'Escape') return;
    if (rptBulk.type === 'archive') { closeModal('archiveReportModal'); rptAbort(); }
    else if (rptBulk.type === 'resolve') { closeModal('rptDetailsModal'); rptAbort(); }
  }, true);


  /* All Reports bulk review uses the existing report-details drawer. */
  var rptDrawerBulk = null;
  var rptDrawerSaved = null;

  function startRptDrawerBulk(type, selected, eligible) {
    if (!eligible.length) {
      var emptyMessages = {
        resolve:'Only reports with an active confirmed case can be marked as resolved.',
        archive:'Only reports that have passed barangay validation can be archived.',
        confirm:'Select reports with Pending DCVS Review status to confirm.',
        reject:'Select reports with Pending DCVS Review status to reject.'
      };
      rptToast('No eligible reports', emptyMessages[type]);
      return;
    }
    rptDrawerBulk = { type:type, queue:eligible.slice(), total:eligible.length, skipped:selected.length - eligible.length, applied:[], failed:0, processing:false };
    showRptDrawerBulkStep();
  }

  window.rptBulkResolve = function () {
    var selected = rptSelectedItems();
    if (!selected.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    startRptDrawerBulk('resolve', selected, selected.filter(function (r) {
      return r.caseId && String(r.caseStatus || '').toLowerCase() === 'active'
        && (!window.DCVS_BARANGAY_RESPONSE_ENABLED || ['dcvs_dispatch', 'dcvs_processing'].indexOf(r.responseState) !== -1);
    }));
  };
  window.rptBulkArchive = function () {
    var selected = rptSelectedItems();
    if (!selected.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    startRptDrawerBulk('archive', selected, selected.filter(function (r) { return String(r.statusRaw || '').toLowerCase() !== 'pending'; }));
  };
  window.rptBulkConfirm = function () {
    var selected = rptSelectedItems();
    if (!selected.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    startRptDrawerBulk('confirm', selected, selected.filter(function (r) { return r.statusRaw === 'validated'; }));
  };
  window.rptBulkReject = function () {
    var selected = rptSelectedItems();
    if (!selected.length) { rptToast('Select Reports', 'Select at least one report first.'); return; }
    startRptDrawerBulk('reject', selected, selected.filter(function (r) { return r.statusRaw === 'validated'; }));
  };

  function rptDrawerActionLabel(type) {
    return { resolve:'Mark this case as resolved', archive:'Archive this report', confirm:'Dispatch DCVS Team', reject:'Reject this report' }[type] || 'Continue';
  }

  function restoreRptDrawer() {
    if (!rptDrawerSaved) return;
    var description = document.querySelector('#rptDetailsModal .sd-dialog-description');
    var footer = document.getElementById('rptDetailFooter');
    if (description) description.textContent = rptDrawerSaved.description;
    if (footer) footer.innerHTML = rptDrawerSaved.footer;
    rptDrawerSaved = null;
  }

  function showRptDrawerBulkStep() {
    if (!rptDrawerBulk || !rptDrawerBulk.queue.length) { finishRptDrawerBulk(); return; }
    var sequence = rptDrawerBulk;
    var report = sequence.queue[0];
    var description = document.querySelector('#rptDetailsModal .sd-dialog-description');
    var footer = document.getElementById('rptDetailFooter');
    if (!rptDrawerSaved) {
      rptDrawerSaved = { description: description ? description.textContent : 'Review report information and case details.', footer: footer ? footer.innerHTML : '' };
    }
    var detailsModal = document.getElementById('rptDetailsModal');
    if (detailsModal && sequence.type !== 'resolve') detailsModal.classList.add('rpt-bulk-dialog');
    window.rptSelect(report.id);
    description = document.querySelector('#rptDetailsModal .sd-dialog-description');
    footer = document.getElementById('rptDetailFooter');
    var messages = {
      resolve:'Review the selected reports before marking them as resolved.',
      archive:'Review the selected reports before archiving them.',
      confirm:'Review the selected reports before confirming them for DCVS action.',
      reject:'Review the selected reports before rejecting them.'
    };
    var selectedCount = sequence.total + sequence.skipped;
    var selectedText = selectedCount === 1 ? '1 report is selected.' : selectedCount + ' reports are selected.';
    var message = messages[sequence.type] + ' ' + selectedText + ' Reports that are not eligible will be skipped automatically.';
    if (description) description.textContent = message;
    if (!footer) return;

    if (sequence.type === 'reject') {
      var detailContent = document.getElementById('rptDetailContent');
      if (detailContent) {
        var reasonField = document.createElement('div');
        reasonField.className = 'rpt-col rpt-full-col';
        reasonField.style.marginTop = '16px';
        reasonField.innerHTML = '<label for="rptBulkRejectReason" style="display:block;font-size:13px;font-weight:600;color:#71717a;margin-bottom:6px;">Rejection Reason <span style="color:#dc2626;">*</span></label><textarea id="rptBulkRejectReason" class="sd-input" rows="3" placeholder="Explain why this report is being rejected..." style="width:100%;min-height:76px;resize:vertical;box-sizing:border-box;"></textarea>';
        detailContent.appendChild(reasonField);
      }
    }

    var actionStyle = sequence.type === 'reject' || sequence.type === 'archive' ? ' style="background:#dc2626;border-color:#dc2626;color:#fff;"' : '';
    footer.innerHTML = '<button type="button" class="sd-btn sd-btn-outline" id="rptDrawerBulkCancel">Cancel</button>' +
      '<button type="button" class="sd-btn sd-btn-default" id="rptDrawerBulkConfirm"' + actionStyle + '>' + rptDrawerActionLabel(sequence.type) + '</button>';
    var cancel = document.getElementById('rptDrawerBulkCancel');
    var confirm = document.getElementById('rptDrawerBulkConfirm');
    cancel.onclick = function () { closeModal('rptDetailsModal'); abortRptDrawerBulk(); };
    confirm.onclick = function () {
      var reason = (document.getElementById('rptBulkRejectReason') || {}).value || '';
      if (sequence.type === 'reject' && !reason.trim()) { rptToast('Rejection reason required', 'Enter a reason before rejecting this report.'); return; }
      if (sequence.type === 'resolve') {
        if (!report.caseId) { rptToast('Cannot Resolve', 'This report does not have an active case.'); return; }
        openResolveModal(report.caseId, report.typeLabel || report.type || 'Report', report.reporter || 'Resident');
        return;
      }
      submitRptDrawerBulkItem(sequence, report, confirm, cancel, reason.trim());
    };
  }

  function submitRptDrawerBulkItem(sequence, report, confirm, cancel, reason) {
    if (rptDrawerBulk !== sequence || sequence.processing) return;
    sequence.processing = true;
    confirm.disabled = true;
    cancel.disabled = true;
    confirm.textContent = sequence.type === 'resolve' ? 'Resolving...' : (sequence.type === 'archive' ? 'Archiving...' : (sequence.type === 'confirm' ? 'Confirming...' : 'Rejecting...'));
    var endpoint, formData = new FormData();
    if (sequence.type === 'archive') {
      endpoint = 'client-database-handler.php';
      formData.set('action', 'archive_report'); formData.set('report_id', report.id);
    } else if (sequence.type === 'confirm') {
      endpoint = window.DCVS_BARANGAY_RESPONSE_ENABLED ? 'response-workflow.php' : 'confirm-schedule-report.php';
      if (window.DCVS_BARANGAY_RESPONSE_ENABLED) {
        formData.set('action', 'staff_decide'); formData.set('response_mode', 'dcvs_dispatch');
        formData.set('report_id', report.id); formData.set('csrf_token', window.DCVS_RESPONSE_CSRF || '');
      } else {
        formData.set('ajax', '1'); formData.set('report_id', report.id); formData.set('scheduled_at', ''); formData.set('dcvs_notes', '');
      }
    } else if (sequence.type === 'reject' && window.DCVS_BARANGAY_RESPONSE_ENABLED) {
      endpoint = 'response-workflow.php';
      formData.set('action', 'reject_report'); formData.set('report_id', report.id);
      formData.set('csrf_token', window.DCVS_RESPONSE_CSRF || ''); formData.set('reason', reason);
    } else {
      endpoint = 'reject-validated-report.php';
      formData.set('ajax', '1'); formData.set('report_id', report.id); formData.set('reason', reason);
    }
    fetch(endpoint, { method:'POST', body:formData })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (!data || !data.success) throw new Error((data && data.message) || 'The action could not be completed.');
        sequence.applied.push(report);
      })
      .catch(function(error) { sequence.failed++; rptToast('Bulk action failed', error.message || 'Please try again.'); })
      .then(function() {
        if (rptDrawerBulk !== sequence) return;
        sequence.processing = false;
        sequence.queue.shift();
        if (sequence.queue.length) showRptDrawerBulkStep();
        else finishRptDrawerBulk();
      });
  }

  function finishRptDrawerBulk() {
    if (!rptDrawerBulk) return;
    var sequence = rptDrawerBulk;
    rptDrawerBulk = null;
    restoreRptDrawer();
    closeModal('rptDetailsModal');
    var detailsModal = document.getElementById('rptDetailsModal');
    if (detailsModal) detailsModal.classList.remove('rpt-bulk-dialog');
    var titles = { resolve:'Cases Resolved', archive:'Reports Archived', confirm:'Reports Confirmed', reject:'Reports Rejected' };
    var labels = { resolve:'case(s) marked resolved', archive:'report(s) archived', confirm:'report(s) confirmed', reject:'report(s) rejected' };
    var message = sequence.applied.length + ' of ' + sequence.total + ' selected ' + labels[sequence.type] + '.';
    if (sequence.skipped) message += ' ' + sequence.skipped + ' skipped (not eligible for this action).';
    if (sequence.failed) message += ' ' + sequence.failed + ' failed.';
    if (sequence.applied.length) rptFlashAndReload(titles[sequence.type], message);
    else rptToast(titles[sequence.type], message);
  }

  function abortRptDrawerBulk() {
    if (!rptDrawerBulk || rptDrawerBulk.processing) return;
    var sequence = rptDrawerBulk;
    rptDrawerBulk = null;
    restoreRptDrawer();
    closeModal('rptDetailsModal');
    var detailsModal = document.getElementById('rptDetailsModal');
    if (detailsModal) detailsModal.classList.remove('rpt-bulk-dialog');
    if (sequence.applied.length) rptFlashAndReload('Bulk Action Stopped', sequence.applied.length + ' report(s) were processed before cancelling.');
  }

  document.addEventListener('click', function(event) {
    if (!rptDrawerBulk || !event.target) return;
    var closeButton = event.target.closest && event.target.closest('#rptDetailsModal .sd-dialog-close');
    if (rptDrawerBulk.processing && (event.target.id === 'rptDetailsModal' || closeButton)) {
      event.preventDefault(); event.stopPropagation(); return;
    }
    if (event.target.id === 'rptDetailsModal' || closeButton) abortRptDrawerBulk();
  }, true);
  document.addEventListener('keydown', function(event) {
    if (!rptDrawerBulk || event.key !== 'Escape') return;
    if (rptDrawerBulk.processing) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    closeModal('rptDetailsModal');
    abortRptDrawerBulk();
  }, true);

  // Expose to global scope (used by inline onclick)
  window.rptSetGroup = function (v) {
    state.group = v;
    state.responseQueue = false;
    var turnoverButton = document.getElementById('rptAwaitingTurnoverFilter');
    if (turnoverButton) {
      turnoverButton.classList.remove('active');
      turnoverButton.setAttribute('aria-pressed', 'false');
    }
    state.page = 1;
    toggleGroupBtns();
    render();
  };
  window.rptToggleTurnoverQueue = function () {
    state.responseQueue = !state.responseQueue;
    if (state.responseQueue) {
      state.group = 'all';
      state.status = '';
    }
    var button = document.getElementById('rptAwaitingTurnoverFilter');
    if (button) {
      button.classList.toggle('active', state.responseQueue);
      button.setAttribute('aria-pressed', state.responseQueue ? 'true' : 'false');
    }
    state.page = 1;
    toggleGroupBtns();
    render();
  };
  window.rptSetSearch = function (v) {
    state.search = v;
    state.page = 1;
    var s2 = document.getElementById('rptSearch');
    var s1 = document.getElementById('rptMainSearch');
    if (s2 && s2.value !== v) s2.value = v;
    if (s1 && s1.value !== v) s1.value = v;
    ensureTurnoverQueueButton();
    ensureTurnoverQueueButton();
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
  window.rptSetResidentStatus = function (v) {
    state.residentStatus = v === 'inactive' ? 'inactive' : 'active';
    state.page = 1;
    updateKpis();
    render();
  };
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

  window.rptUpdateItem = function (id, changes) {
    var item = ALL.find(function (r) { return String(r.id) === String(id); });
    if (!item) return null;
    Object.assign(item, changes || {});
    updateKpis();
    render();
    return item;
  };
  window.rptAddItem = function (item) {
    if (!item || item.id == null) return null;
    var existing = ALL.find(function (r) { return String(r.id) === String(item.id); });
    if (existing) Object.assign(existing, item);
    else ALL.unshift(item);
    updateKpis();
    render();
    return existing || item;
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
      (r.photo || r.video ? '<button class="row-menu-item" onclick="rptViewPhoto(\'' + esc(r.photo || r.video) + '\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>View Attachments</button>' : '') +
      '<button class="row-menu-item" onclick="rptViewLocation(\'' + esc(r.brgy) + '\',' + Number(r.id) + ')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13-s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>View Location</button>' +
      '<button class="row-menu-item" onclick="rptSelect(' + id + ');closeActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>View History</button>' +
      (isTurnoverPending(r)
        ? '<button class="row-menu-item danger" disabled title="Archive is available after DCVS confirms receipt">Archive Report — turnover pending</button>'
        : '<button class="row-menu-item danger" onclick="archiveReport(' + id + ');closeActions()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive Report</button>');
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
  window.rptViewLocation = function (brgy, reportId) {
    var params = new URLSearchParams();
    if (brgy) params.set('brgy', brgy);
    if (Number.isSafeInteger(Number(reportId)) && Number(reportId) > 0) {
      params.set('report_id', String(Number(reportId)));
    }
    window.location.href = 'dashboard-staff-geo.php' + (params.toString() ? '?' + params.toString() : '');
  };

  // Detail drawer with tabs
  var rptDetailTab = 'details';
  function rptHighResponseRiskReasons(r) {
    var reasons = [];
    var count = String(r.animalCount || '').trim();
    var countIsHigh = /^(?:6\+|[6-9]\d*|\d{2,})$/.test(count);
    var type = String(r.type || '').toLowerCase();
    var urgency = String(r.urgencyRaw || r.urgency || '').toLowerCase();
    var behavior = String(r.animalBehavior || '');
    var description = String(r.desc || '');

    if (type === 'rabies') reasons.push('Report type is identified as a rabies concern.');
    if (/^(?:high|critical)$/.test(urgency)) reasons.push('The submitted priority is ' + String(r.urgency || r.urgencyRaw) + '.');
    if (/bit(e|ten)|aggress|sever|malubha|critical/i.test(behavior)) {
      reasons.push('Reported animal behavior: ' + behavior + '.');
    }
    if (/bit(e|ten)/i.test(description)) reasons.push('The description reports a bite or biting.');
    if (/aggress/i.test(description)) reasons.push('The description reports aggressive behavior.');
    if (/rabies/i.test(description)) reasons.push('The description mentions rabies.');
    if (/sever|malubha|critical/i.test(description)) reasons.push('The description indicates a severe or critical condition.');
    if (countIsHigh) reasons.push('The reported number of animals is ' + count + '.');
    return reasons;
  }

  function rptHasHighResponseRisk(r) {
    return rptHighResponseRiskReasons(r).length > 0;
  }

  function rptResponseRiskExplanation(r) {
    var priority = String(r.urgency || r.urgencyRaw || 'not specified');
    var type = String(r.type || '').toLowerCase();
    var behavior = String(r.animalBehavior || '').trim();
    var description = String(r.desc || '');
    var count = String(r.animalCount || '').trim();
    var details = [];

    if (type === 'rabies') details.push('The report is categorized as a rabies concern.');
    if (/bit(e|ten)/i.test(behavior)) details.push('The animal was reported to have bitten someone.');
    else if (/aggress/i.test(behavior)) details.push('The animal was reported to be aggressive.');
    else if (/sever|malubha|critical/i.test(behavior)) details.push('The animal was reported to be in a severe or critical condition.');
    if (/bit(e|ten)/i.test(description) && !/bit(e|ten)/i.test(behavior)) details.push('The report description mentions a bite.');
    if (/aggress/i.test(description) && !/aggress/i.test(behavior)) details.push('The report description mentions aggressive behavior.');
    if (/rabies/i.test(description) && type !== 'rabies') details.push('The report description mentions rabies.');
    if (/sever|malubha|critical/i.test(description) && !/sever|malubha|critical/i.test(behavior)) details.push('The report description indicates a severe or critical condition.');
    if (/^(?:6\+|[6-9]\d*|\d{2,})$/.test(count)) details.push('Six or more animals were reported.');

    var opening = 'The report was submitted with ' + priority + ' priority';
    if (details.length) {
      opening += ', but the details also help us assess the case. ';
      if (details.length === 1) {
        return opening + details[0].slice(0, -1) + ', so please keep this in mind when reviewing it.';
      }
      return opening + details.join(' ') + ' Please keep these details in mind when reviewing the case.';
    }
    return opening + ', so please keep this in mind when reviewing the case.';
  }

  function rptResponseRiskPanel(r, previewOnly) {
    if (r.responseState !== 'awaiting_dcvs_decision') return '';
    var reasons = rptHighResponseRiskReasons(r);
    var highRisk = reasons.length > 0;
    return '<section class="rpt-response-risk ' + (highRisk ? 'is-high-risk' : 'is-low-risk') + '" aria-label="DCVS response coordination check">' +
      '<div class="rpt-response-risk-title"><span aria-hidden="true">' + (highRisk ? '&#9888;' : '&#10003;') + '</span> Barangay response check</div>' +
      '<p class="rpt-response-risk-explanation">' + (highRisk
        ? esc(rptResponseRiskExplanation(r))
        : 'No high-risk indicators detected. This forwarded report still needs a DCVS handling decision.') + '</p>' +
      (highRisk ? '<p class="rpt-response-risk-explanation">This is just a coordination note. The barangay can still accept the case while reviewing the details.</p>' : '') +
      '</section>';
  }

  function rptResponseWorkflowDetails(r) {
    if (!window.DCVS_BARANGAY_RESPONSE_ENABLED || !r.responseState || r.responseState === 'awaiting_dcvs_decision') return '';
    var labels = {
      dcvs_dispatch: 'DCVS dispatch',
      barangay_response_approved: 'Barangay response approved',
      barangay_responding: 'Barangay responding',
      animal_secured: 'Animal secured by barangay',
      awaiting_turnover: 'Awaiting DCVS turnover',
      turned_over: 'Turned over to DCVS',
      dcvs_processing: 'DCVS processing',
      unable_to_locate: 'Unable to locate',
      escalated_to_dcvs: 'Escalated to DCVS',
      returned_to_barangay: 'Returned to barangay',
      closed_not_actionable: 'Closed — not actionable',
      resolved: 'Resolved'
    };
    var status = labels[r.responseState] || r.responseState.replace(/_/g, ' ');
    var mode = r.responseMode === 'barangay_response' ? 'Barangay Response'
      : (r.responseMode === 'dcvs_dispatch' ? 'DCVS Dispatch' : 'Undecided');
    var deadline = r.responseDeadline || r.progressDeadline || '';
    var overdue = deadline && new Date(deadline.replace(' ', 'T')).getTime() < Date.now();
    return '<section class="rpt-col rpt-full-col rpt-response-custody-section"><div class="rpt-section-title">Response &amp; Custody</div>' +
      '<div class="info-rows info-rows-2col rpt-response-custody-grid">' +
      infoRow('activity', 'Response Status', status) +
      infoRow('activity', 'Response Mode', mode) +
      (deadline && r.statusRaw !== 'resolved' ? infoRow('cal', overdue ? 'Overdue Response Target' : 'Next Response Target', deadline) : '') +
      (r.urgentEscalatedAt ? infoRow('activity', 'Urgent DCVS Coordination', 'Notified on ' + r.urgentEscalatedAt) : '') +
      (r.lastProgressAt ? infoRow('cal', 'Last Progress Update', r.lastProgressAt) : '') +
      (r.holdingLocation ? infoRow('home', 'Holding Location', r.holdingLocation) : '') +
      (r.animalCondition ? infoRow('activity', 'Animal Condition', r.animalCondition) : '') +
      (r.resolutionDetails ? infoRow('activity', 'Resolution Details', r.resolutionDetails) : '') +
      (r.animalDisposition ? infoRow('activity', 'Animal Disposition', r.animalDisposition.replace(/_/g, ' ')) : '') +
      '</div>' +
      '</section>';
  }

  function rptResponseDecisionFooter(r) {
    var reportId = Number(r.id);
    var moreMenu = '<div class="rpt-response-more-wrap"><button type="button" class="sd-btn sd-btn-outline rpt-response-more" aria-label="More actions" title="More actions" aria-expanded="false" onclick="rptToggleDecisionMore(this)">&#8230;</button>' +
      '<div class="rpt-response-more-menu" hidden><button type="button" onclick="responseWorkflowAction(' + reportId + ',\'return_for_validation\');rptCloseDecisionMore()">Return to Barangay</button>' +
      '<button type="button" class="is-danger" onclick="responseWorkflowAction(' + reportId + ',\'reject_report\');rptCloseDecisionMore()">Reject Report</button></div></div>';
    var dispatch = '<button type="button" class="sd-btn rpt-response-primary" onclick="responseWorkflowAction(' + reportId + ',\'staff_decide\',{response_mode:\'dcvs_dispatch\'})">Dispatch DCVS Team</button>';
    return '<div class="rpt-response-decision-footer"><div class="rpt-response-decision-actions">' + moreMenu + dispatch + '</div></div>';
  }

  window.rptResponseDecisionFooter = rptResponseDecisionFooter;
  window.rptResponseRiskPanel = rptResponseRiskPanel;

  window.rptToggleDecisionMore = function (button) {
    var menu = button.nextElementSibling;
    if (!menu) return;
    document.querySelectorAll('.rpt-response-more-menu').forEach(function (other) {
      if (other !== menu) other.hidden = true;
    });
    menu.hidden = !menu.hidden;
    button.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
  };
  window.rptCloseDecisionMore = function () {
    document.querySelectorAll('.rpt-response-more-menu').forEach(function (menu) { menu.hidden = true; });
    document.querySelectorAll('.rpt-response-more[aria-expanded="true"]').forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
  };

  function rptInitResponsePreview() {
    if (!window.DCVS_BARANGAY_RESPONSE_ENABLED
        || new URLSearchParams(window.location.search).get('response_preview') !== '1') return;
    var samples = [
      { id: -1, name: 'Rabies, High priority', type: 'rabies', typeLabel: 'Rabies concern', urgency: 'High', urgencyRaw: 'high', animalCount: '1', animalBehavior: 'Aggressive', barangayProposed: true, responseState: 'awaiting_dcvs_decision' },
      { id: -2, name: 'Stray, calm animal', type: 'stray', typeLabel: 'Stray Animal', urgency: 'Medium', urgencyRaw: 'medium', animalCount: '1', animalBehavior: 'Calm / Stray', barangayProposed: true, responseState: 'awaiting_dcvs_decision' }
    ];
    var panel = document.createElement('aside');
    panel.className = 'rpt-response-preview-panel';
    panel.setAttribute('aria-label', 'Response workflow sample preview');
    panel.innerHTML = '<strong>Preview case</strong><div class="rpt-response-preview-options"></div><p>Same drawer rules, different risk result. These examples are not real reports.</p><div class="rpt-response-preview-result" aria-live="polite"></div>';
    document.body.appendChild(panel);
    var options = panel.querySelector('.rpt-response-preview-options');
    var result = panel.querySelector('.rpt-response-preview-result');
    samples.forEach(function (sample, index) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = sample.name;
      button.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
      button.addEventListener('click', function () {
        options.querySelectorAll('button').forEach(function (item) {
          item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
        });
        result.innerHTML = '<div class="rpt-response-preview-proposal">Barangay is requesting to respond. This report was forwarded to DCVS for review and assignment.</div>' +
          rptResponseRiskPanel(sample, true);
      });
      options.appendChild(button);
      if (index === 0) button.click();
    });
  }

  window.rptSelect = function (id) {
    var r = ALL.find(function (x) { return x.id === id; });
    if (!r) return;
    rptDetailTab = 'details';
    document.querySelectorAll('#rptRows tr').forEach(function (tr) {
      tr.classList.toggle('selected', tr.getAttribute('data-id') == String(id));
    });
    var box = document.getElementById('rptDetailContent');
    if (!box) return;

    var typeTag = r.typeLabel || r.type || 'Report';
    var statusStyle = statusBadgeStyle(r);
    var drawerStatus = document.getElementById('rptDrawerStatus');
    if (drawerStatus) {
      drawerStatus.textContent = (r.statusLabel || r.status || '-') + (!r.structured && r.status === 'resolved' ? ' (Resident)' : '');
      drawerStatus.setAttribute('style', statusStyle + 'border-radius:6px;font-family:Inter,sans-serif;font-size:12px;font-weight:500;padding:4px 10px;');
      drawerStatus.hidden = false;
    }
    if (r.structured) {
      box.innerHTML = '<div class="rpt-tabs">' +
        '<button class="rpt-tab" data-tab="details" onclick="rptDetailGo(\'details\')">Details</button>' +
        '<button class="rpt-tab" data-tab="updates" onclick="rptDetailGo(\'updates\')">Updates</button>' +
        '</div>' +
        '<div class="rpt-detail-body" data-tab="details">' +

        '<div style="margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">' +
        '<div class="avatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + (r.avatar ? '<img src="' + esc(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(r.avatar) : r.avatar) + '" alt="" style="width:100%;height:100%;object-fit:cover;">' : esc(initials(r.reporter))) + '</div>' +
        '<div style="display:flex;flex:1 1 150px;flex-direction:column;gap:2px;min-width:0;">' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Reported by</span>' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:normal;overflow-wrap:anywhere;">' + esc(r.reporter || '\u2014') + '</span>' +
        '</div>' +
        '</div>' +

        '<div style="margin-bottom:16px;display:flex;align-items:center;gap:6px;">' +
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;">Priority</span>' +
        priorityPill(r.urgency) +
        '</div>' +

        rptResponseRiskPanel(r) +
        rptResponseWorkflowDetails(r) +

        '<div class="rpt-detail-grid">' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title rpt-section-title-action"><span>Report Information</span>' + ((r.status === 'pending') ? '<button type="button" class="cpd-edit-icon" onclick="openEditReport(' + r.id + ')" aria-label="Edit report" title="Edit report"><img src="' + esc(window.dcvsStaffUrl('assets/images/edit%20icon.png')) + '" alt=""></button>' : '') + '</div><div class="info-rows info-rows-2col">' +
        infoRow('cal', 'Date Submitted', r.submitted || r.date || '-') +
        infoRow('cal', 'Incident Date', r.incidentDate || r.date || '-') +
        infoRow('home2', 'Location', r.location || '-') +
        infoRow('tag', 'Report Type', r.typeLabel || '-') +
        '</div></div>' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Animal Information</div><div class="info-rows info-rows-2col">' +
        infoRow('paw', 'Animal Type', r.animalType || '-') +
        infoRow('tag', 'Breed / Appearance', r.animalBreed || '-') +
        infoRow('list', 'Estimated Number of Animals', r.animalCount || '-') +
        infoRow('activity', 'Animal Behavior', r.animalBehavior || '-') +
        '</div></div>' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">General Information</div><div class="info-rows info-rows-2col">' +
        infoRow('idcard', 'Resident ID', r.reporterRef || '-') +
        infoRow('phone', 'Mobile Number', r.phone || '-') +
        infoRow('phone', 'Email', r.email || '-') +
        infoRow('home', 'Barangay', r.brgy || '-') +
        infoRow('check', 'Status', r.statusLabel || r.status || '-') +
        infoRow('user2', 'Assigned Staff', r.dispatchOfficerName || r.assigned || '-') +
        infoRow('check', 'Resolved', r.resolved || '-') +
        '</div></div>' +
        '<div class="rpt-col rpt-full-col"><div class="rpt-section-title">Incident Details</div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0 0 6px;">Description</p><div class="rpt-desc-box" style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">' + (r.desc ? esc(r.desc) : 'No description provided.') + '</div></div>' +
        '</div></div>' +
        '<div class="rpt-detail-body" data-tab="updates" style="display:none;">' +
        (typeof window.casReportCaseUpdatesHtml === 'function' ? window.casReportCaseUpdatesHtml(r) : '') +
        '</div>';
      rptDetailRefreshTabs();
      var structuredFooter = document.getElementById('rptDetailFooter');
      if (structuredFooter) {
        var structuredFooterHtml = '';
        if (window.DCVS_BARANGAY_RESPONSE_ENABLED && r.statusRaw === 'validated'
            && r.responseState === 'awaiting_dcvs_decision') {
          structuredFooterHtml = rptResponseDecisionFooter(r);
        } else if (r.status === 'pending') {
          structuredFooterHtml += '<button class="sd-btn sd-btn-outline" onclick="rptViewLocation(\'' + esc(r.brgy) + '\',' + Number(r.id) + ')">View Location</button>';
          structuredFooterHtml += '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="archiveReport(' + r.id + ')">Archive</button>';
        } else {
          structuredFooterHtml += turnoverFooterHtml(r);
          if (!isTurnoverPending(r)) structuredFooterHtml += '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="archiveReport(' + r.id + ')">Archive</button>';
        }
        structuredFooter.innerHTML = structuredFooterHtml;
      }
      openModal('rptDetailsModal');
      return;
    }

    var resolvedDate = r.status === 'resolved' ? (r.resolved || r.date) : '-';

    box.innerHTML =
      '<div class="rpt-tabs">' +
      '<button class="rpt-tab" data-tab="details" onclick="rptDetailGo(\'details\')">Details</button>' +
      '<button class="rpt-tab" data-tab="updates" onclick="rptDetailGo(\'updates\')">Updates</button>' +
      '</div>' +

      '<div class="rpt-detail-body" data-tab="details">' +

      '<div style="margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">' +
      '<div class="avatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + (r.avatar ? '<img src="' + esc(window.dcvsStaffMediaUrl ? window.dcvsStaffMediaUrl(r.avatar) : r.avatar) + '" alt="" style="width:100%;height:100%;object-fit:cover;">' : esc(initials(r.reporter))) + '</div>' +
      '<div style="display:flex;flex:1 1 150px;flex-direction:column;gap:2px;min-width:0;">' +
      '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Reported by</span>' +
      '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:normal;overflow-wrap:anywhere;">' + esc(r.reporter || '—') + '</span>' +
      '</div>' +
      '</div>' +

      '<div style="margin-bottom:16px;display:flex;align-items:center;gap:6px;">' +
      '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;">Priority</span>' +
      priorityPill(r.urgency) +
      '</div>' +

      rptResponseRiskPanel(r) +
      rptResponseWorkflowDetails(r) +

      '<div class="rpt-detail-grid">' +

      '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title rpt-section-title-action"><span>Report Information</span>' + ((r.status === 'pending') ? '<button type="button" class="cpd-edit-icon" onclick="openEditReport(' + r.id + ')" aria-label="Edit report" title="Edit report"><img src="' + esc(window.dcvsStaffUrl('assets/images/edit%20icon.png')) + '" alt=""></button>' : '') + '</div>' +
      '<div class="info-rows info-rows-2col">' +
      infoRow('cal', 'Date Submitted', r.submitted || r.reported || r.date || '-') +
      infoRow('cal', 'Incident Date', r.incidentDate || r.date || '-') +
      infoRow('home2', 'Location', r.location || '-') +
      infoRow('tag', 'Report Type', r.typeLabel || '-') +
      '</div>' +
      '</div>' +

      ((r.structured || r.animalType || r.animalBreed || r.animalCount || r.animalBehavior)
        ? '<div class="rpt-col rpt-full-col">' +
          '<div class="rpt-section-title">Animal Information</div>' +
          '<div class="info-rows info-rows-2col">' +
          infoRow('paw', 'Animal Type', r.animalType || '-') +
          infoRow('tag', 'Breed / Appearance', r.animalBreed || '-') +
          infoRow('list', 'Estimated Number of Animals', r.animalCount || '-') +
          infoRow('activity', 'Animal Behavior', r.animalBehavior || '-') +
          '</div>' +
          '</div>'
        : '') +

      '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title">General Information</div>' +
      '<div class="info-rows info-rows-2col">' +
      infoRow('idcard', 'Resident ID', r.reporterRef || '-') +
      infoRow('phone', 'Mobile Number', r.phone || '-') +
      infoRow('phone', 'Email', r.email || '-') +
      infoRow('home', 'Barangay', r.brgy || '-') +
      infoRow('check', 'Status', r.statusLabel || r.status || '-') +
      infoRow('user2', 'Assigned Staff', r.dispatchOfficerName || r.assigned || '-') +
      infoRow('check', 'Resolved', resolvedDate) +
      '</div>' +
      '</div>' +

      '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title">Incident Details</div>' +
      '<p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0 0 6px;">Description</p>' +
      '<div class="rpt-desc-box" id="rptDescBox" style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">' + (r.desc ? esc(r.desc) : 'No description provided.') + '</div>' +
      '</div>' +

      (r.dcvsNotes ? '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title">DCVS Notes</div>' +
      '<div class="rpt-notes-box">' + esc(r.dcvsNotes) + '</div>' +
      '</div>' : '') +

      (r.brgyNotes ? '<div class="rpt-col rpt-full-col">' +
      '<div class="rpt-section-title">Barangay Notes</div>' +
      '<div class="rpt-notes-box">' + esc(r.brgyNotes) + '</div>' +
      '</div>' : '') +

      '</div>' +
      '</div>' +

      '<div class="rpt-detail-body" data-tab="updates" style="display:none;">' +
      (typeof window.casReportCaseUpdatesHtml === 'function' ? window.casReportCaseUpdatesHtml(r) : '') +
      '</div>';

    rptDetailRefreshTabs();

    var footer = document.getElementById('rptDetailFooter');
    if (footer) {
      var footerHtml = '';
      if (window.DCVS_BARANGAY_RESPONSE_ENABLED && r.statusRaw === 'validated'
          && r.responseState === 'awaiting_dcvs_decision') {
        footerHtml = rptResponseDecisionFooter(r);
      } else if (r.status === 'pending') {
        footerHtml += '<button class="sd-btn sd-btn-outline" onclick="rptViewLocation(\'' + esc(r.brgy) + '\',' + Number(r.id) + ')">View Location</button>';
        footerHtml += '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="archiveReport(' + r.id + ')">Archive</button>';
      } else {
        footerHtml += turnoverFooterHtml(r);
        if (!isTurnoverPending(r)) footerHtml += '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="archiveReport(' + r.id + ')">Archive</button>';
      }
      footer.innerHTML = footerHtml;
    }

    openModal('rptDetailsModal');
  };

  window.closeReportDrawer = function () {
    closeModal('rptDetailsModal');
  };
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeReportDrawer();
  });

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
    var dateButton = document.querySelector('[data-date-filter="rpt"] .staff-date-filter-button');
    if (dateButton) dateButton.classList.remove('has-date');
    var st = document.getElementById('rptStatus');
    if (st) st.value = '';
    var residentStatus = document.getElementById('rptResidentStatus');
    if (residentStatus) residentStatus.value = 'active';
    state.residentStatus = 'active';
    toggleGroupBtns();
    render();
  };

  window.rptExportCSV = function () {
    var rows = filtered();
    var csv = [['Report ID', 'Reporter', 'Mobile Number', 'Type', 'Barangay', 'Status', 'Date Submitted', 'Incident Date', 'Date Resolved']];
    rows.forEach(function (r) {
      csv.push([r.ref, r.reporter, r.phone || '', r.typeLabel, r.brgy || '', r.statusLabel || r.status, r.submitted || '', r.incidentDate || r.date || '', r.resolved || '']);
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
    return '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">' + esc(label) + '</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">' + esc(value) + '</p></div></div>';
  }

  function avatarInfoRow(label, value, avatarHtml) {
    return '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">' + esc(label) + '</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">' + esc(value) + '</p></div></div>';
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

  function rptSetSelect(sel, val) {
    if (!sel) return;
    val = (val === undefined || val === null) ? '' : String(val);
    if (val === '') { sel.value = ''; return; }
    sel.value = val;
    if (sel.value !== val) {
      var opt = document.createElement('option');
      opt.value = val;
      opt.textContent = val;
      sel.appendChild(opt);
      sel.value = val;
    }
  }

  window.openEditReport = function (id) {
    var items = (typeof RPT_ITEMS !== 'undefined') ? RPT_ITEMS : [];
    var r = items.find(function(x){ return x.id == id; });
    if (!r) return;
    document.getElementById('editReportId').value = r.id;
    var dateInput = document.getElementById('editReportDate');
    var rawDate = String(r.createdAt || '').replace(' ', 'T').slice(0, 16);
    if (dateInput) dateInput.value = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(rawDate) ? rawDate : '';
    document.getElementById('editReportLocation').value = r.location || '';
    rptSetSelect(document.getElementById('editReportType'), r.type || '');
    rptSetSelect(document.getElementById('editReportUrgency'), r.urgencyRaw || 'medium');
    rptSetSelect(document.getElementById('editReportBarangay'), r.brgyId ? String(r.brgyId) : '');
    rptSetSelect(document.getElementById('editReportAnimalType'), r.animalType || '');
    document.getElementById('editReportAnimalBreed').value = r.animalBreed || '';
    rptSetSelect(document.getElementById('editReportAnimalCount'), r.animalCount || '');
    rptSetSelect(document.getElementById('editReportAnimalBehavior'), r.animalBehavior || '');
    document.getElementById('editReportDesc').value = r.desc || '';
    var correctionReason = document.getElementById('editReportCorrectionReason');
    if (correctionReason) correctionReason.value = '';
    openModal('editReportModal');
  };

  window.archiveReport = function (id) {
    var items = (typeof RPT_ITEMS !== 'undefined') ? RPT_ITEMS : [];
    var r = items.find(function(x){ return x.id == id; });
    if (!r) { openModal('archiveReportModal'); return; }
    document.getElementById('archiveReportReporter').textContent = r.reporter || 'Unknown';
    document.getElementById('archiveReportBrgy').textContent = r.brgy || 'Unknown';
    document.getElementById('archiveReportConfirmBtn').dataset.id = id;
    openModal('archiveReportModal');
  };

  window.executeArchiveReport = function () {
    var btn = document.getElementById('archiveReportConfirmBtn');
    var id = btn.dataset.id;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'archive_report');
    fd.append('report_id', id);
    fetch('client-database-handler.php', { method: 'POST', body: fd })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if (data.success) {
          closeModal('archiveReportModal');
          closeModal('rptDetailsModal');
          if (typeof cpdToast === 'function') cpdToast('Report archived');
          setTimeout(function(){ location.reload(); }, 600);
        } else {
          alert(data.message || 'Archive failed');
          btn.innerHTML = 'Continue';
          btn.disabled = false;
        }
      })
      .catch(function(){
        alert('Network error');
        btn.innerHTML = 'Continue';
        btn.disabled = false;
      });
  };

  document.getElementById('editReportForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('action', 'update_report');
    var btn = this.querySelector('button[type="submit"]');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
    fetch('client-database-handler.php', { method: 'POST', body: fd })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if (data.success){
          closeModal('editReportModal');
          closeModal('rptDetailsModal');
          if (typeof cpdToast === 'function') cpdToast('Report updated');
          setTimeout(function(){ location.reload(); }, 600);
        } else {
          alert(data.message || 'Update failed');
          btn.innerHTML = 'Save changes';
          btn.disabled = false;
        }
      })
      .catch(function(){
        alert('Network error');
        btn.innerHTML = 'Save changes';
        btn.disabled = false;
      });
  });

  // Init
  render();
  rptInitResponsePreview();
})();