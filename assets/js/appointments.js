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
        scheduled:   { bg: '#f0fdf4', fg: '#045e1f' },
        rescheduled: { bg: '#ede9fe', fg: '#7c3aed' },
        completed:   { bg: '#f1f5f9', fg: '#475569' },
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

        function f(label, value) {
            return '<div class="sd-field"><span class="sd-label">' + label + '</span><span class="sd-value">' + value + '</span></div>';
        }

        var body = '<div class="sd-field-group">';
        body += f('Resident', esc(a.full_name || '—'));
        body += f('Status', '<span class="appt-status" style="background:' + st.bg + ';color:' + st.fg + '">' + esc(label(a.status)) + '</span>');
        body += f('Date', fmtDateLong(a.preferred_date));
        body += f('Time', fmtTime(a.preferred_time));
        body += f('Service', esc(label(a.appointment_type)));
        body += f('Pet', esc(a.pet_name || 'N/A'));
        body += f('Email', esc(a.email || '—'));
        body += f('Phone', esc(a.phone || '—'));
        body += f('Address', esc(a.address || a.barangay_name || '—'));
        body += f('Requested', fmtDate(a.created_at));
        if (a.notes) body += f('Notes', esc(a.notes));
        if (a.staff_message) body += f('DCVS Message', esc(a.staff_message));
        body += '</div>';
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
        if (a.status === 'pending') items.push(['Review', "APP.review(" + id + ")"]);
        if (a.status === 'pending' || a.status === 'scheduled' || a.status === 'rescheduled') items.push(['Reschedule', "APP.reschedSingle(" + id + ")"]);
        if (a.status !== 'completed' && a.status !== 'cancelled' && a.status !== 'rejected') items.push(['Mark as Completed', "APP.statusChange(" + id + ",'completed')"]);
        if (!items.length) { toast('No actions available for this appointment.', true); return; }
        items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = it[0];
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
        openAddAppointmentModal();
        var li = document.getElementById('lookupRequestId');
        if (li && typeof lookupAppointmentRequest === 'function') {
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
        api(ids, status, null).then(function (res) {
            if (res && res.success) {
                ids.forEach(function (id) {
                    var a = find(id);
                    if (a) a.status = status;
                    delete selected[id];
                });
                closeDrawer();
                render();
                toast(res.message || ids.length + ' appointment(s) updated.');
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
