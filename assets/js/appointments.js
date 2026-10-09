/* ─────────────────────────────────────────────
   Appointments Management (staff) — client-side
   toolbar / table / pagination / bulk / drawer
   ───────────────────────────────────────────── */
(function () {
    var items = (window.APPT_ITEMS || []).slice();
    var state = { page: 1, perPage: 10, search: '', service: '', status: '', from: '', to: '' };
    var selected = {};
    var moreOpen = false;
    var drawerId = null;
    var reschedIds = [];

    var STATUS = {
        pending:     { bg: '#fef3c7', fg: '#92400e' },
        scheduled:   { bg: '#0E8131', fg: '#ffffff' },
        rescheduled: { bg: '#ede9fe', fg: '#7c3aed' },
        completed:   { bg: '#dbeafe', fg: '#1e40af' },
        cancelled:   { bg: '#fee2e2', fg: '#dc2626' },
        no_show:     { bg: '#fef3c7', fg: '#92400e' },
        rejected:    { bg: '#fee2e2', fg: '#dc2626' },
        in_progress: { bg: '#dbeafe', fg: '#1e40af' }
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function pad(n) { n = String(n); while (n.length < 4) n = '0' + n; return n; }
    function ref(a) { return a.reference_id || ('#APT-' + pad(a.appointment_id)); }
    function resRef(a) { return a.user_ref || ('#RES-' + pad(a.user_id || 0)); }
    function label(s) { s = String(s || '').replace(/_/g, ' '); return s.charAt(0).toUpperCase() + s.slice(1); }
    function fmtDate(d) {
        if (!d) return '-';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function fmtDateLong(d) {
        if (!d) return '-';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString('en-US', { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' });
    }
    function fmtTime(t) {
        if (!t) return '-';
        var p = String(t).split(':');
        var h = parseInt(p[0], 10), m = p[1] || '00';
        var ampm = 'AM';
        if (h >= 12) { ampm = 'PM'; if (h > 12) h -= 12; }
        if (h === 0) h = 12;
        return h + ':' + m + ' ' + ampm;
    }
    function toast(msg, isErr) {
        var t = document.createElement('div');
        t.className = 'appt-toast' + (isErr ? ' err' : '');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('show'); }, 10);
        setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2600);
    }

    function find(id) {
        id = String(id);
        for (var i = 0; i < items.length; i++) if (String(items[i].appointment_id) === id) return items[i];
        return null;
    }

    /* ── Filtering ── */
    function getFiltered() {
        var q = state.search.toLowerCase().trim();
        return items.filter(function (a) {
            if (state.service && a.appointment_type !== state.service) return false;
            if (state.status && a.status !== state.status) return false;
            if (state.from && String(a.preferred_date) < state.from) return false;
            if (state.to && String(a.preferred_date) > state.to) return false;
            if (q) {
                var hay = (ref(a) + ' ' + (a.full_name || '') + ' ' + (a.pet_name || '') + ' ' + (a.appointment_type || '') + ' ' + (a.email || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        }).sort(function (x, y) {
            var d = String(x.preferred_date).localeCompare(String(y.preferred_date));
            if (d !== 0) return d;
            return String(x.preferred_time).localeCompare(String(y.preferred_time));
        });
    }

    /* ── Rendering ── */
    function renderHead() {
        var h = '<tr>'
            + '<th class="sd-th">Request ID</th>'
            + '<th class="sd-th">Resident / Pet</th>'
            + '<th class="sd-th">Service</th>'
            + '<th class="sd-th">Date &amp; Time</th>'
            + '<th class="sd-th">Status</th>'
            + '<th class="sd-th">Actions</th></tr>';
        document.getElementById('apptTableHead').innerHTML = h;
    }

    function rowHtml(a) {
        var st = STATUS[a.status] || { bg: '#f1f5f9', fg: '#64748b' };
        var initial = (a.full_name || '?').trim().charAt(0).toUpperCase();
        var avatar = a.avatar_url
            ? '<img class="appt-avatar" src="' + esc(a.avatar_url) + '" alt="">'
            : '<span class="appt-avatar">' + esc(initial) + '</span>';
        return '<tr class="sd-tr' + (selected[a.appointment_id] ? ' selected' : '') + '" data-id="' + a.appointment_id + '">'
            + '<td><div class="appt-id">' + esc(ref(a)) + '</div><div class="appt-sub">Submitted ' + fmtDate(a.created_at) + '</div></td>'
            + '<td><div class="appt-resident">' + avatar + '<div><div class="appt-resident-name">' + esc(a.full_name) + '</div><div class="appt-pet">Pet: ' + esc(a.pet_name || 'N/A') + '</div></div></div></td>'
            + '<td class="appt-service">' + esc(label(a.appointment_type)) + '</td>'
            + '<td><div class="appt-dt">' + fmtDate(a.preferred_date) + '<div class="appt-dt-time">' + fmtTime(a.preferred_time) + '</div></div></td>'
            + '<td><span class="appt-status" style="background:' + st.bg + ';color:' + st.fg + '">' + esc(label(a.status)) + '</span></td>'
            + '<td><div class="appt-actions">'
            + '<button class="appt-action-btn" title="View" onclick="APP.view(' + a.appointment_id + ')"><i class="fas fa-eye"></i></button>'
            + '<button class="appt-action-btn" title="More" onclick="APP.more(event,' + a.appointment_id + ')"><i class="fas fa-ellipsis-h"></i></button>'
            + '</div></td></tr>';
    }

    function render() {
        var filtered = getFiltered();
        var total = filtered.length;
        var pages = Math.max(1, Math.ceil(total / state.perPage));
        if (state.page > pages) state.page = pages;
        if (state.page < 1) state.page = 1;
        var start = (state.page - 1) * state.perPage;
        var slice = filtered.slice(start, start + state.perPage);

        var tb = document.getElementById('apptRows');
        if (!slice.length) {
            tb.innerHTML = '<tr class="sd-tr"><td colspan="6" style="text-align:center;color:#94a3b8;padding:2rem;"><i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:.4;"></i>No appointments match this filter.</td></tr>';
        } else {
            tb.innerHTML = slice.map(rowHtml).join('');
            var fill = state.perPage - slice.length;
            if (fill > 0) {
                var rowH = tb.querySelector('tr') ? tb.querySelector('tr').offsetHeight : 0;
                var extra = '';
                for (var i = 0; i < fill; i++) {
                    extra += '<tr class="appt-filler" style="height:' + rowH + 'px;"><td colspan="6">&nbsp;</td></tr>';
                }
                tb.insertAdjacentHTML('beforeend', extra);
            }
        }

        var info = document.getElementById('apptPagInfo');
        info.textContent = total === 0
            ? 'Showing 0 of 0 records'
            : 'Showing ' + (start + 1) + '\u2013' + Math.min(start + state.perPage, total) + ' of ' + total + ' records';

        var prev = document.getElementById('apptPagePrev');
        var next = document.getElementById('apptPageNext');
        prev.disabled = state.page <= 1;
        next.disabled = state.page >= pages;

        var nums = document.getElementById('apptPageNums');
        var lo = Math.max(1, state.page - 2), hi = Math.min(pages, state.page + 2);
        var html = '';
        for (var i = lo; i <= hi; i++) {
            html += '<button class="appt-page-num' + (i === state.page ? ' current' : '') + '" onclick="APP.goTo(' + i + ')">' + i + '</button>';
        }
        nums.innerHTML = html;

        renderBulk();
    }

    function renderBulk() {
        var n = Object.keys(selected).length;
        var bar = document.getElementById('apptBulkBar');
        if (bar) bar.hidden = n === 0;
        var cnt = document.getElementById('apptBulkCount');
        if (cnt) cnt.textContent = n + ' selected';
        var all = document.getElementById('apptCheckAll');
        if (all) all.checked = n > 0 && n === getFiltered().length;
    }

    /* ── Filters (exposed) ── */
    function setStatus(v) {
        state.status = v; state.page = 1;
        var sel = document.getElementById('apptStatus');
        if (sel && sel.value !== v) sel.value = v;
        render();
    }
    function setSearch(v) { state.search = v; state.page = 1; render(); }
    function setService(v) { state.service = v; state.page = 1; render(); }
    function setRange() {
        state.from = document.getElementById('apptDateFrom').value;
        state.to = document.getElementById('apptDateTo').value;
        state.page = 1;
        updateDateBtnLabel();
        render();
    }
    function fmtShort(d) {
        if (!d) return '';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function updateDateBtnLabel() {
        var lbl = document.getElementById('apptDateBtnLabel');
        if (!lbl) return;
        var btn = document.getElementById('apptDateBtn');
        if (state.from || state.to) {
            lbl.textContent = state.from && state.to
                ? fmtShort(state.from) + ' - ' + fmtShort(state.to)
                : (state.from ? 'From ' + fmtShort(state.from) : 'Until ' + fmtShort(state.to));
            if (btn) btn.classList.add('active');
        } else {
            lbl.textContent = 'Filter by date';
            if (btn) btn.classList.remove('active');
        }
    }
    function toggleDatePop(e) {
        if (e) e.stopPropagation();
        var pop = document.getElementById('apptDatePop');
        if (!pop) return;
        if (!pop.hidden) { closeDatePop(); return; }
        var btn = document.getElementById('apptDateBtn');
        if (btn) {
            var r = btn.getBoundingClientRect();
            pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 304)) + 'px';
            pop.style.top = (r.bottom + 6) + 'px';
        }
        pop.hidden = false;
    }
    function closeDatePop() {
        var pop = document.getElementById('apptDatePop');
        if (pop) pop.hidden = true;
    }
    function applyDateRange() { setRange(); closeDatePop(); }
    function clearDateRange() {
        var f = document.getElementById('apptDateFrom');
        var t = document.getElementById('apptDateTo');
        if (f) f.value = '';
        if (t) t.value = '';
        state.from = ''; state.to = ''; state.page = 1;
        updateDateBtnLabel();
        closeDatePop();
        render();
    }
    function resetFilters() {
        state.search = ''; state.service = ''; state.status = ''; state.from = ''; state.to = ''; state.page = 1;
        ['apptSearch', 'apptService', 'apptStatus', 'apptDateFrom', 'apptDateTo'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        closeDatePop();
        updateDateBtnLabel();
        render();
    }
    function setPerPage(v) { state.perPage = +v; state.page = 1; render(); }
    function go(dir) {
        if (dir === 'prev' && state.page > 1) state.page--;
        if (dir === 'next') state.page++;
        render();
    }
    function goTo(p) { state.page = p; render(); }

    /* ── Selection ── */
    function toggleSel(id, cb) {
        if (cb.checked) selected[id] = true; else delete selected[id];
        var tr = document.querySelector('#apptRows tr[data-id="' + id + '"]');
        if (tr) tr.classList.toggle('selected', !!cb.checked);
        renderBulk();
    }
    function toggleAll(cb) {
        if (cb.checked) {
            getFiltered().forEach(function (a) { selected[a.appointment_id] = true; });
        } else {
            selected = {};
        }
        render();
    }
    function clearSelection() { selected = {}; render(); }

    /* ── Details modal ── */
    function openDrawer() {
        openModal('apptViewModal');
    }
    function closeDrawer() {
        closeModal('apptViewModal');
        drawerId = null;
    }

    function view(id) {
        var a = find(id);
        if (!a) return;
        drawerId = id;
        var st = STATUS[a.status] || { bg: '#f1f5f9', fg: '#64748b' };
        var stColor = st.fg;
        var stBg = st.bg;
        var stBorder = st.fg === '#92400e' ? '#fde68a' : st.fg === '#045e1f' ? '#D1FADF' : st.fg === '#dc2626' ? '#FECACA' : '#E2E8F0';

        var body = '';

        body += '<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">';
        body += '<div class="avatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + (a.full_name ? esc(a.full_name.split(' ').map(function(w){return w.charAt(0)}).join('').substring(0,2)) : '?') + '</div>';
        body += '<div style="display:flex;flex-direction:column;gap:2px;min-width:0;">';
        body += '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Requested by</span>';
        body += '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(a.full_name || '—') + '</span>';
        body += '</div>';
        body += '<span style="margin-left:auto;font-size:13px;font-weight:500;color:' + stColor + ';background:' + stBg + ';border:1px solid ' + stBorder + ';padding:4px 12px;border-radius:6px;font-family:Inter,sans-serif;white-space:nowrap;">' + esc(label(a.status)) + '</span>';
        body += '</div>';

        body += '<div style="margin-bottom:16px;display:flex;align-items:center;gap:6px;">';
        body += '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:500;color:#64748b;">Service</span>';
        body += '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:500;color:#18181b;">' + esc(label(a.appointment_type)) + '</span>';
        body += '</div>';

        body += '<div class="rpt-detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">';

        body += '<div class="rpt-col">';
        body += '<div class="rpt-section-title" style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#71717a;margin:18px 0 10px;">General Information</div>';
        body += '<div class="info-rows">';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Resident ID</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(resRef(a)) + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Mobile Number</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(a.phone || '-') + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Email</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(a.email || '-') + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Barangay</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(a.barangay_name || '-') + '</p></div></div>';
        body += '</div></div>';

        body += '<div class="rpt-col">';
        body += '<div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:transparent;margin:18px 0 10px;">General Information</div>';
        body += '<div class="info-rows">';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Pet</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(a.pet_name || 'N/A') + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Date</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + fmtDateLong(a.preferred_date) + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Time</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + fmtTime(a.preferred_time) + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Requested</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + fmtDate(a.created_at) + '</p></div></div>';
        body += '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">Address</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(a.address || '-') + '</p></div></div>';
        body += '</div></div>';

        body += '</div>';

        if (a.notes) {
            body += '<div style="margin-top:8px;">';
            body += '<div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#71717a;margin:18px 0 10px;">Notes</div>';
            body += '<div style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">' + esc(a.notes) + '</div>';
            body += '</div>';
        }
        if (a.staff_message) {
            body += '<div style="margin-top:8px;">';
            body += '<div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#71717a;margin:18px 0 10px;">DCVS Message</div>';
            body += '<div style="background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;padding:12px 14px;font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;line-height:1.5;">' + esc(a.staff_message) + '</div>';
            body += '</div>';
        }

        document.getElementById('apptViewBody').innerHTML = body;
        document.getElementById('apptViewRef').textContent = ref(a);
        var hint = document.getElementById('apptViewHint');
        if (hint) {
            var HINT = {
                pending: 'Use this ID to look up the request in Review Requests to approve or reject it.',
                completed: 'This appointment has been completed.',
                cancelled: 'This appointment has been cancelled.',
                rejected: 'This request has been rejected.',
                no_show: 'This appointment was marked as no-show.',
                approved: 'This appointment is approved.'
            };
            if (a.status === 'scheduled' || a.status === 'rescheduled') {
                hint.style.display = 'none';
            } else {
                hint.style.display = '';
                hint.textContent = HINT[a.status] || 'This request has already been processed.';
            }
        }
        var copyBtn = document.getElementById('apptViewCopyBtn');
        if (copyBtn) {
            var alreadyScheduled = a.status === 'scheduled' || a.status === 'rescheduled';
            copyBtn.style.display = alreadyScheduled ? 'none' : '';
            if (!alreadyScheduled) { copyBtn.onclick = function () { copyRef(id); }; }
        }
        openDrawer();
    }

    /* ── Copy request ID ── */
    function copyRef(id) {
        var a = find(id);
        if (!a) return;
        var text = ref(a);
        var btn = document.getElementById('apptViewCopyBtn');
        function done() {
            if (btn) {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy ID'; }, 1500);
            }
            toast('Request ID copied: ' + text);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(text);
                done();
            });
        } else {
            fallbackCopy(text);
            done();
        }
    }
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    /* ── More menu ── */
    function more(e, id) {
        e.stopPropagation();
        closeMore();
        var a = find(id);
        if (!a) return;
        var menu = document.createElement('div');
        menu.className = 'appt-more-menu';
        var items = [];
        var eyeIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var calendarIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
        var checkIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        var archiveIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>';
        if (a.status === 'pending') items.push(['Review', "APP.review(" + id + ")", eyeIcon]);
        if (a.status === 'pending' || a.status === 'scheduled' || a.status === 'rescheduled') items.push(['Reschedule', "APP.reschedSingle(" + id + ")", calendarIcon]);
        if (a.status !== 'pending' && a.status !== 'completed' && a.status !== 'cancelled' && a.status !== 'rejected') items.push(['Mark as Completed', "APP.statusChange(" + id + ",'completed')", checkIcon]);
        if (a.status === 'scheduled' || a.status === 'rescheduled') items.push(['Archive', "APP.archive(" + id + ")", archiveIcon]);
        if (!items.length) { toast('No actions available for this appointment.', true); return; }
        items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = it[2] + it[0];
            b.onclick = function () { closeMore(); eval(it[1]); };
            menu.appendChild(b);
        });
        var rect = e.target.getBoundingClientRect();
        menu.style.top = (rect.bottom + 6) + 'px';
        menu.style.right = (window.innerWidth - rect.right) + 'px';
        document.body.appendChild(menu);
        moreOpen = menu;
        setTimeout(function () {
            document.addEventListener('click', closeMore, { once: true });
        }, 0);
    }
    function closeMore() {
        if (moreOpen && moreOpen.parentNode) moreOpen.parentNode.removeChild(moreOpen);
        moreOpen = false;
    }

    /* ── Review (opens existing review modal + lookup) ── */
    function review(id) {
        var a = find(id);
        if (!a) return;
        closeDrawer();
        if (window.APP && typeof window.APP.reviewIds === 'function') {
            window.APP.reviewIds([String(id)], 0);
            return;
        }
        openAddAppointmentModal();
        var li = document.getElementById('lookupRequestId');
        if (li && window.APP && typeof window.APP.reviewLookup === 'function') {
            li.value = ref(a);
            window.APP.reviewLookup();
        } else if (li && typeof lookupAppointmentRequest === 'function') {
            li.value = ref(a);
            lookupAppointmentRequest();
        }
    }

    /* ── Reschedule ── */
    function reschedSingle(id) {
        var a = find(id);
        if (!a) return;
        reschedIds = [id];
        showResched(a);
    }
    function showResched(a) {
        document.getElementById('apptRsRef').textContent = reschedIds.length > 1 ? (reschedIds.length + ' appointments selected') : ref(a);
        document.getElementById('apptRsDate').value = a ? a.preferred_date : '';
        document.getElementById('apptRsTime').value = a ? String(a.preferred_time).slice(0, 5) : '';
        openModal('apptReschedModal');
    }

    /* ── Confirm dialog ── */
    function confirmDialog(title, message, onConfirm) {
        document.getElementById('apptConfirmTitle').textContent = title;
        document.getElementById('apptConfirmMsg').textContent = message;
        var ok = document.getElementById('apptConfirmOk');
        ok.onclick = function () {
            closeModal('apptConfirmModal');
            onConfirm();
        };
        openModal('apptConfirmModal');
    }
    function applyStatus(ids, status) {
        var action = status === 'completed' ? 'complete' : status;
        api(ids, action, null).then(function (res) {
            if (res && res.success) {
                ids.forEach(function (id) {
                    var a = find(id);
                    if (a) a.status = status;
                    delete selected[id];
                });
                closeDrawer();
                render();
                toast(res.message || ids.length + ' appointment(s) updated.');
                window.dispatchEvent(new CustomEvent('dcvs:calendar-appointments-updated', {
                    detail: { ids: ids.map(String), status: status }
                }));
                if (typeof window.onApptStatusChanged === 'function') window.onApptStatusChanged();
            } else {
                toast((res && res.message) || 'Update failed.', true);
            }
        });
    }

    /* ── Status updates (single + bulk) ── */
    function statusChange(id, status) {
        if (status === 'cancelled') {
            confirmDialog('Cancel Appointment', 'Are you sure you want to cancel this appointment?', function () { applyStatus([id], 'cancelled'); });
            return;
        }
        if (status === 'completed') {
            confirmDialog('Mark as Completed', 'Are you sure you want to mark this appointment as completed?', function () { applyStatus([id], 'completed'); });
            return;
        }
        applyStatus([id], status);
    }
    function bulk(kind) {
        var ids = Object.keys(selected).map(Number);
        if (!ids.length) { toast('Select appointments first.', true); return; }
        if (kind === 'reschedule') {
            reschedIds = ids;
            showResched(null);
            return;
        }
        if (kind === 'completed' || kind === 'cancelled') {
            var title = kind === 'cancelled' ? 'Cancel Appointments' : 'Mark as Completed';
            var msg = kind === 'cancelled'
                ? 'Cancel ' + ids.length + ' selected appointment(s)?'
                : 'Mark ' + ids.length + ' selected appointment(s) as completed?';
            confirmDialog(title, msg, function () { applyStatus(ids, kind); });
        }
    }
    function doReschedule() {
        var date = document.getElementById('apptRsDate').value;
        var time = document.getElementById('apptRsTime').value;
        if (!date || !time) { toast('Please choose a date and time.', true); return; }
        api(reschedIds, 'reschedule', { preferred_date: date, preferred_time: time }).then(function (res) {
            if (res && res.success) {
                reschedIds.forEach(function (id) {
                    var a = find(id);
                    if (a) { a.preferred_date = date; a.preferred_time = time; a.status = 'rescheduled'; }
                    delete selected[id];
                });
                closeModal('apptReschedModal');
                closeDrawer();
                render();
                toast(res.message || 'Appointment rescheduled.');
                window.dispatchEvent(new CustomEvent('dcvs:calendar-appointments-rescheduled', {
                    detail: {
                        changes: reschedIds.map(function (id) {
                            return { id: String(id), date: date, time: String(time).slice(0, 5) };
                        })
                    }
                }));
                if (typeof window.onApptStatusChanged === 'function') window.onApptStatusChanged();
            } else {
                toast((res && res.message) || 'Reschedule failed.', true);
            }
        });
    }

    function api(ids, action, extra) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('ids', ids.join(','));
        if (extra) for (var k in extra) body.append(k, extra[k]);
        return fetch('update-appointment-status.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .catch(function () { return { success: false, message: 'Network error.' }; });
    }

    /* ── Export CSV ── */
    function bulkExport() {
        var ids = Object.keys(selected);
        var list = items.filter(function (a) {
            if (ids.length) return ids.indexOf(String(a.appointment_id)) > -1;
            return getFiltered().indexOf(a) > -1;
        });
        if (!list.length) { toast('Nothing to export.', true); return; }
        var rows = [['Request ID', 'Resident', 'Pet', 'Service', 'Date', 'Time', 'Status', 'Email', 'Phone']];
        list.forEach(function (a) {
            rows.push([ref(a), a.full_name, a.pet_name || '', label(a.appointment_type), a.preferred_date, fmtTime(a.preferred_time), label(a.status), a.email || '', a.phone || '']);
        });
        var csv = rows.map(function (r) {
            return r.map(function (c) { return '"' + String(c == null ? '' : c).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\r\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'appointments.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    /* ── Realtime hook ── */
    function pushNew(appt) {
        if (!appt || find(appt.appointment_id)) return;
        items.unshift(appt);
        render();
    }
    function getItems() { return items; }

    /* ── Row Selection (new UI) ── */
    function toggleSelect(id) {
        var checkbox = document.querySelector('.appt-row-check[data-id="' + id + '"]');
        if (checkbox) {
            if (checkbox.checked) {
                selected[id] = true;
                var row = document.querySelector('.appt-row[data-id="' + id + '"]');
                if (row) row.classList.add('selected');
            } else {
                delete selected[id];
                var row = document.querySelector('.appt-row[data-id="' + id + '"]');
                if (row) row.classList.remove('selected');
            }
        }
        updateBulkUI();
        updateSelectAllCheckbox();
    }

    function updateBulkUI() {
        var n = Object.keys(selected).length;
        var bulkActions = document.getElementById('apptBulkActions');
        var selectedCount = document.getElementById('apptSelectedCount');
        if (bulkActions) {
            bulkActions.style.display = n > 0 ? 'flex' : 'none';
        }
        if (selectedCount) {
            selectedCount.textContent = n;
        }
    }

    function updateSelectAllCheckbox() {
        var selectAll = document.getElementById('apptSelectAll');
        if (!selectAll) return;
        var filtered = getFiltered();
        var allSelected = filtered.length > 0 && filtered.every(function (a) { return selected[a.appointment_id]; });
        selectAll.checked = allSelected;
    }

    function handleSelectAllChange(cb) {
        var filtered = getFiltered();
        if (cb.checked) {
            filtered.forEach(function (a) {
                selected[a.appointment_id] = true;
                var row = document.querySelector('.appt-row[data-id="' + a.appointment_id + '"]');
                if (row) row.classList.add('selected');
                var chk = document.querySelector('.appt-row-check[data-id="' + a.appointment_id + '"]');
                if (chk) chk.checked = true;
            });
        } else {
            Object.keys(selected).forEach(function (id) {
                delete selected[id];
                var row = document.querySelector('.appt-row[data-id="' + id + '"]');
                if (row) row.classList.remove('selected');
                var chk = document.querySelector('.appt-row-check[data-id="' + id + '"]');
                if (chk) chk.checked = false;
            });
        }
        updateBulkUI();
    }

    function bulkStatusChange(status) {
        var ids = Object.keys(selected).map(Number);
        if (!ids.length) { toast('Select appointments first.', true); return; }
        var title = status === 'cancelled' ? 'Cancel Appointments' : 'Mark as Completed';
        var msg = status === 'cancelled'
            ? 'Cancel ' + ids.length + ' selected appointment(s)?'
            : 'Mark ' + ids.length + ' selected appointment(s) as completed?';
        confirmDialog(title, msg, function () { applyStatus(ids, status); });
    }

    function bulkDelete() {
        var ids = Object.keys(selected).map(Number);
        if (!ids.length) { toast('Select appointments first.', true); return; }
        confirmDialog('Delete Appointments', 'Permanently delete ' + ids.length + ' selected appointment(s)? This cannot be undone.', function () {
            api(ids, 'delete', null).then(function (res) {
                if (res && res.success) {
                    ids.forEach(function (id) {
                        var idx = items.findIndex(function (a) { return String(a.appointment_id) === String(id); });
                        if (idx > -1) items.splice(idx, 1);
                        delete selected[id];
                    });
                    render();
                    updateBulkUI();
                    toast(res.message || ids.length + ' appointment(s) deleted.');
                    if (typeof window.onApptStatusChanged === 'function') window.onApptStatusChanged();
                } else {
                    toast((res && res.message) || 'Delete failed.', true);
                }
            });
        });
    }
    function archive(id) {
        var a = find(id);
        if (typeof window.archiveAppt === 'function') {
            window.archiveAppt(id, a && a.pet_name, a && a.full_name);
            return;
        }
        confirmDialog('Archive Appointment', 'Are you sure you want to archive this appointment?', function () {
            api([id], 'archive', null).then(function (res) {
                if (res && res.success) { items = items.filter(function (item) { return String(item.appointment_id) !== String(id); }); render(); toast(res.message || 'Appointment archived.'); }
                else toast((res && res.message) || 'Archive failed.', true);
            });
        });
    }

    /* ── Init ── */
    window.APP = {
        setStatus: setStatus,
        setSearch: setSearch,
        setService: setService,
        setRange: setRange,
        toggleDatePop: toggleDatePop,
        applyDateRange: applyDateRange,
        clearDateRange: clearDateRange,
        resetFilters: resetFilters,
        setPerPage: setPerPage,
        go: go,
        goTo: goTo,
        toggleSel: toggleSel,
        toggleAll: toggleAll,
        clearSelection: clearSelection,
        view: view,
        closeDrawer: closeDrawer,
        more: more,
        review: review,
        reschedSingle: reschedSingle,
        statusChange: statusChange,
        bulk: bulk,
        bulkExport: bulkExport,
        doReschedule: doReschedule,
        copyRef: copyRef,
        pushNew: pushNew,
        getItems: getItems,
        toggleSelect: toggleSelect,
        bulkStatusChange: bulkStatusChange,
        bulkDelete: bulkDelete
        ,archive: archive
    };

    document.addEventListener('DOMContentLoaded', function () {
        renderHead();
        render();
        updateDateBtnLabel();
        var selectAllCheckbox = document.getElementById('apptSelectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function () { handleSelectAllChange(this); });
        }
        document.addEventListener('click', function (e) {
            var pop = document.getElementById('apptDatePop');
            if (pop && !pop.hidden && !e.target.closest('.appt-date-filter')) closeDatePop();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDatePop();
        });
    });
})();
