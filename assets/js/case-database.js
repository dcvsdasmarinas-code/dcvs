/* Case Database (Pending / Active / Resolved) — generic factory driven by initCaseDB configs.
   Instance functions are prefixed (e.g. penSetGroup) to avoid clashing with
   client-database.js (cpd*) and reports-database.js (rpt*). */
(function () {
  var casePop = null;
  var SVG_ICONS = {
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    home2: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    tag: '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    check: '<polyline points="20 6 9 17 4 12"/>',
    x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'
  };
  function svg(icon, size) {
    size = size || 15;
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (SVG_ICONS[icon] || '') + '</svg>';
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function initials(n) {
    return String(n || '?').split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase() || '?';
  }
  function typeClass(t) {
    return 'type-' + String(t || 'stray');
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
  function infoRow(icon, label, value) {
    return '<div class="info-row"><div class="info-row-icon">' + svg(icon) + '</div><div><p class="info-row-label">' + esc(label) + '</p><p class="info-row-value">' + esc(value) + '</p></div></div>';
  }
  function avatarInfoRow(label, value, avatarHtml) {
    return '<div class="info-row"><div class="info-row-icon info-row-avatar">' + avatarHtml + '</div><div><p class="info-row-label">' + esc(label) + '</p><p class="info-row-value">' + esc(value) + '</p></div></div>';
  }
  function caseId(r) {
    if (r.caseId) return '#CASE-' + String(r.caseId);
    return '#CASE-' + String(r.id || 0);
  }

  // Expose helpers globally (cas* prefix) for per-page config functions in the HTML
  window.casEsc = esc;
  window.casInitials = initials;
  window.casTypeClass = typeClass;
  window.casStatusPill = statusPill;
  window.casTypeBadge = typeBadge;
  window.casAvatar = avatarHtml;
  window.casInfoRow = infoRow;
  window.casAvatarInfoRow = avatarInfoRow;
  window.casCaseId = caseId;

  function init(cfg) {
    var p = cfg.p;                 // id prefix (pen / act / res)
    var sectionId = cfg.section;   // #reports-pending / active / resolved
    var ALL = cfg.data || [];
    var detailTab = 'details';
    var state = {
      group: 'all',
      search: '',
      type: '',
      brgy: '',
      dateFrom: '',
      dateTo: '',
      sort: cfg.sortDefault || 'newest',
      view: 'table',
      page: 1,
      perPage: 10,
      sortCol: '',
      sortDir: ''
    };

    function colVal(x, col) {
      if (col === 'typeLabel') return String(x.typeLabel || x.type || '').toLowerCase();
      if (col === 'validated' || col === 'confirmed' || col === 'resolved' || col === 'date') return x.ts || 0;
      if (col === 'id') return x.caseId || x.id || 0;
      return String(x[col] != null ? x[col] : '').toLowerCase();
    }

    function sortHeaderHtml(col, label) {
      var active = state.sortCol === col;
      var icon;
      if (active && state.sortDir === 'asc') icon = '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>';
      else if (active && state.sortDir === 'desc') icon = '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
      else icon = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
      return '<button type="button" class="th-sort' + (active ? ' th-sorted' : '') + '" onclick="window.' + p + 'SortBy(\'' + col + '\')">' + label + '<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + icon + '</svg></button>';
    }

    function el(id) { return document.getElementById(id); }

    function filtered() {
      var rows = ALL.slice();
      if (state.group !== 'all' && cfg.groupMatch) {
        rows = rows.filter(function (r) { return cfg.groupMatch(r, state.group); });
      }
      if (state.search) {
        var q = state.search.toLowerCase();
        rows = rows.filter(function (r) {
          return (r.ref || '').toLowerCase().indexOf(q) >= 0 ||
            (r.reporter || '').toLowerCase().indexOf(q) >= 0 ||
            (r.desc || '').toLowerCase().indexOf(q) >= 0 ||
            (r.typeLabel || '').toLowerCase().indexOf(q) >= 0 ||
            (r.brgy || '').toLowerCase().indexOf(q) >= 0;
        });
      }
      if (state.type) rows = rows.filter(function (r) { return r.type === state.type; });
      if (state.brgy) rows = rows.filter(function (r) { return r.brgy === state.brgy; });
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

    function renderTable(rows) {
      var thead = el(p + 'TableHead');
      var tbody = el(p + 'Rows');
      if (!thead || !tbody) return;
      var n = cfg.columns.length;
      thead.innerHTML = '<tr class="sd-tr">' + cfg.columns.map(function (c, i) {
        var cd = typeof c === 'object' ? c : { label: c };
        if (i === n - 1) return '<th class="sd-th sd-th-action"></th>';
        return '<th class="sd-th">' + (cd.key ? sortHeaderHtml(cd.key, cd.label) : esc(cd.label)) + '</th>';
      }).join('') + '</tr>';
      tbody.innerHTML = rows.length
        ? rows.map(function (r) {
          return '<tr class="sd-tr" data-id="' + r.id + '" onclick="' + p + 'Select(' + r.id + ')" style="cursor:pointer;">' + cfg.rowHtml(r) + '</tr>';
        }).join('')
        : '<tr class="sd-tr"><td class="sd-td" colspan="' + n + '"><div class="no-results"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>' + esc(cfg.emptyText) + '</p></div></td></tr>';
    }

    function renderCards(rows) {
      var wrap = el(p + 'CardsWrap');
      if (!wrap) return;
      wrap.classList.add('rpt-cards');
      wrap.innerHTML = rows.length
        ? rows.map(function (r) {
          return '<div class="client-card" data-id="' + r.id + '" onclick="' + p + 'Select(' + r.id + ')">' + cfg.cardHtml(r) + '</div>';
        }).join('')
        : '<div class="no-results" style="grid-column:1/-1;"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>' + esc(cfg.emptyText) + '</p></div>';
    }

    function renderMap(rows) {
      var wrap = el(p + 'MapWrap');
      if (!wrap) return;
      wrap.innerHTML =
        '<div class="map-placeholder">' +
        '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>' +
        '<p>Map view is coming soon. Geo-mapping will show ' + rows.length + ' case(s) distributed across Dasmariñas.</p>' +
        '</div>';
    }

    function renderPagination(total) {
      var pages = Math.max(1, Math.ceil(total / state.perPage));
      if (state.page > pages) state.page = pages;
      var prev = el(p + 'PagePrev');
      var next = el(p + 'PageNext');
      if (prev) prev.disabled = state.page <= 1;
      if (next) next.disabled = state.page >= pages;
    }

    function render() {
      var rows = filtered();
      var total = rows.length;
      var pageRows = rows.slice((state.page - 1) * state.perPage, state.page * state.perPage);
      var tw = el(p + 'TableWrap');
      var cw = el(p + 'CardsWrap');
      var mw = el(p + 'MapWrap');
      if (tw) tw.style.display = state.view === 'table' ? '' : 'none';
      if (cw) cw.style.display = state.view === 'cards' ? '' : 'none';
      if (mw) mw.style.display = state.view === 'map' ? '' : 'none';
      if (state.view === 'table') renderTable(pageRows);
      else if (state.view === 'cards') renderCards(pageRows);
      else renderMap(rows);
      renderPagination(total);
    }

    function toggleGroup() {
      document.querySelectorAll('#' + sectionId + ' .filter-item[data-filter="group"]').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-value') === state.group);
      });
    }
    function syncSearch() {
      var s2 = el(p + 'Search');
      var s1 = el(p + 'MainSearch');
      if (s2 && s2.value !== state.search) s2.value = state.search;
      if (s1 && s1.value !== state.search) s1.value = state.search;
    }

    // Public per-instance API (used by inline handlers)
    window[p + 'SetGroup'] = function (v) { state.group = v; state.page = 1; toggleGroup(); render(); };    window[p + 'SetSearch'] = function (v) { state.search = v; state.page = 1; syncSearch(); render(); };
    window[p + 'SetDate'] = function () {
      state.dateFrom = el(p + 'DateFrom') ? el(p + 'DateFrom').value : '';
      state.dateTo = el(p + 'DateTo') ? el(p + 'DateTo').value : '';
      state.page = 1;
      render();
    };
    window[p + 'SetType'] = function (v) { state.type = v; state.page = 1; render(); };
    window[p + 'SetBrgy'] = function (v) { state.brgy = v; state.page = 1; render(); };
    window[p + 'SetSort'] = function (v) { state.sort = v; state.page = 1; render(); };
    window[p + 'SortBy'] = function (col) {
      if (state.sortCol === col) { state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; }
      else { state.sortCol = col; state.sortDir = 'asc'; }
      state.page = 1;
      render();
    };
    window[p + 'SetView'] = function (v) {
      state.view = v;
      state.page = 1;
      var seg = el(p + 'ViewSeg');
      if (seg) seg.querySelectorAll('.seg-btn').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-view') === v);
      });
      render();
    };
    window[p + 'GoPage'] = function (pg) {
      if (pg === 'prev') pg = state.page - 1;
      if (pg === 'next') pg = state.page + 1;
      if (pg < 1) return;
      state.page = pg;
      render();
    };
    window[p + 'Select'] = function (id) {
      var r = ALL.find(function (x) { return x.id === id; });
      if (!r) return;
      document.querySelectorAll('#' + sectionId + ' #' + p + 'Rows tr').forEach(function (tr) {
        tr.classList.toggle('selected', tr.getAttribute('data-id') == String(id));
      });
      document.querySelectorAll('#' + sectionId + ' #' + p + 'CardsWrap .client-card').forEach(function (c) {
        c.classList.toggle('selected', c.getAttribute('data-id') == String(id));
      });
      var box = el(p + 'DetailContent');
      if (box) {
        detailTab = 'details';
        try {
          box.innerHTML = cfg.drawerHtml(r);
        } catch (e) {
          box.innerHTML = '<p style="padding:1rem;color:#71717a;">Unable to load case details.</p>';
        }
        refreshDetailTabs();
      }
      var footer = el(p + 'DrawerFooter');
      if (footer) {
        footer.innerHTML = (typeof cfg.drawerFooter === 'function') ? cfg.drawerFooter(r) : '';
      }
      if (cfg.modalId) {
        var m = document.getElementById(cfg.modalId);
        if (m) {
          m.classList.add('active');
          m.style.display = 'flex';
          document.body.style.overflow = 'hidden';
        }
      }
    };
    window[p + 'DetailGo'] = function (t) {
      detailTab = t;
      refreshDetailTabs();
    };

    function refreshDetailTabs() {
      var box = el(p + 'DetailContent');
      if (!box) return;
      box.querySelectorAll('.rpt-tab').forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-tab') === detailTab);
      });
      box.querySelectorAll('.rpt-detail-body').forEach(function (b) {
        b.style.display = b.getAttribute('data-tab') === detailTab ? '' : 'none';
      });
    }
    window[p + 'Actions'] = function (ev, id) {
      ev.stopPropagation();
      caseCloseActions();
      var r = ALL.find(function (x) { return x.id === id; }) || {};
      var pop = document.createElement('div');
      pop.className = 'cpd row-menu-pop open';
      pop.style.position = 'fixed';
      pop.innerHTML = cfg.menuHtml(r);
      var rect = ev.currentTarget.getBoundingClientRect();
      pop.style.top = (rect.bottom + 6) + 'px';
      pop.style.left = Math.min(rect.left, window.innerWidth - 220) + 'px';
      document.body.appendChild(pop);
      casePop = pop;
      setTimeout(function () { document.addEventListener('click', caseCloseActions); }, 0);
    };
    window[p + 'ViewPhoto'] = function (url) { window.open(url, '_blank'); };
    window[p + 'ViewGeo'] = function (brgy) {
      var q = encodeURIComponent(brgy || '');
      window.location.href = 'dashboard-staff-geo.php' + (q ? '?brgy=' + q : '');
    };
    window[p + 'ViewLocation'] = function (loc) {
      var q = encodeURIComponent(loc || 'Dasmariñas, Cavite');
      window.open('https://www.google.com/maps/search/?api=1&query=' + q, '_blank');
    };

    render();
  }

  function caseCloseActions() {
    if (casePop) { casePop.remove(); casePop = null; }
    document.removeEventListener('click', caseCloseActions);
  }
  window.caseCloseActions = caseCloseActions;

  window.initCaseDB = init;
})();