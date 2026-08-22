/**
 * DCVS Real-Time Update Engine
 * Polls for changes every 7 seconds, updates UI components, and dispatches
 * a 'dcvs:realtime' custom event for dashboard-specific handlers.
 *
 * Usage:
 *   <body data-realtime-page="resident">  ← enables polling for this page
 *   <span data-realtime="notif-badge"/>   ← updates notification count
 *   <span id="kpi-reports"/>              ← updates KPI values from poll counts
 *   <span data-count="reports"/>          ← updates count badges
 *
 *   document.addEventListener('dcvs:realtime', function(e) {
 *       var data = e.detail;
 *       // custom DOM update logic here
 *   });
 */
(function () {
    'use strict';

    var POLL_INTERVAL = 7000;
    var lastPoll = new Date().toISOString().slice(0, 19).replace('T', ' ');
    var pollTimer = null;

    // Start only on pages that opt in
    if (!document.querySelector('[data-realtime-page]')) return;

    // Each portal uses its own session name — the poller must open the same one.
    var SESSION_SLUG = {
        staff: 'DCVS_STAFF_SESSION',
        barangay: 'DCVS_BARANGAY_SESSION',
        admin: 'DCVS_ADMIN_SESSION'
    };
    var sessName = SESSION_SLUG[(document.querySelector('[data-realtime-page]').getAttribute('data-realtime-page') || '').toLowerCase()] || 'PHPSESSID';

    // ── Helpers ──

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s || ''))); return d.innerHTML; }

    function numVal(el) { return parseInt((el.textContent || '').replace(/[^0-9]/g, ''), 10) || 0; }

    // ── Notification badge ──
    function updateNotifBadge(count) {
        var display = count > 9 ? '9+' : count;
        document.querySelectorAll('[data-realtime="notif-badge"]').forEach(function (el) {
            var cur = numVal(el);
            if (cur !== count) {
                el.textContent = display;
                el.style.display = count > 0 ? '' : 'none';
                if (count > cur) {
                    el.style.transform = 'scale(1.35)';
                    el.style.transition = 'transform 0.2s';
                    setTimeout(function () { el.style.transform = 'scale(1)'; }, 300);
                }
            }
        });
    }

    // ── KPI values ──
    function updateKPIs(counts) {
        Object.keys(counts).forEach(function (key) {
            var val = counts[key];
            // 1. Elements with id="kpi-{key}"
            var el = document.getElementById('kpi-' + key);
            if (el) {
                var cur = numVal(el);
                if (cur !== val) {
                    el.textContent = Number(val).toLocaleString();
                    el.style.transition = 'color 0.25s';
                    el.style.color = '#059669';
                    setTimeout(function () { el.style.color = ''; }, 500);
                }
            }
            // 2. Count badges with data-count="{key}"
            document.querySelectorAll('[data-count="' + key + '"]').forEach(function (el) {
                var cur = numVal(el);
                if (cur !== val) {
                    el.innerHTML = el.innerHTML.replace(/\d+/, val);
                    el.style.transition = 'background 0.3s';
                    el.style.background = '#d1fae5';
                    setTimeout(function () { el.style.background = ''; }, 600);
                }
            });
            // 3. Elements with data-kpi="{key}" (barangay style)
            document.querySelectorAll('[data-kpi="' + key + '"]').forEach(function (el) {
                var cur = numVal(el);
                if (cur !== val) {
                    el.textContent = Number(val).toLocaleString();
                    el.style.transition = 'color 0.25s';
                    el.style.color = '#059669';
                    setTimeout(function () { el.style.color = ''; }, 500);
                }
            });
        });
    }

    // ── Notification list ──
    function appendNotifList(notifs) {
        var list = document.getElementById('notificationList');
        if (!list) return;
        var empty = list.querySelector('.notif-empty');
        notifs.forEach(function (n) {
            if (list.querySelector('[data-nid="' + n.notification_id + '"]')) return;
            var div = document.createElement('div');
            div.setAttribute('data-nid', n.notification_id);
            div.className = 'notif-item' + (n.is_read ? '' : ' unread');
            var icons = { appointment: 'fa-calendar-check', report: 'fa-file-alt', message: 'fa-envelope', system: 'fa-bell' };
            div.innerHTML = '<div class="notif-icon staff_message"><i class="fas ' + (icons[n.type] || 'fa-bell') + '"></i></div>' +
                '<div class="notif-content"><div class="notif-title">' + esc(n.title) + '</div>' +
                '<div class="notif-time">' + (n.created_at || '') + '</div></div>';
            list.insertBefore(div, list.firstChild);
            if (empty) empty.style.display = 'none';
        });
    }

    // ── Activity rows (resident) ──
    function appendActivities(activities) {
        var tbody = document.querySelector('#activityTable tbody');
        if (!tbody || !activities.length) return;
        var noRow = document.getElementById('actNoResults');
        if (noRow) noRow.style.display = 'none';
        activities.forEach(function (act) {
            var id = 'act-' + act.log_id;
            if (document.getElementById(id)) return;
            var tr = document.createElement('tr');
            tr.id = id;
            tr.className = 'act-row';
            tr.setAttribute('data-log-id', act.log_id);
            var dt = new Date(act.created_at.replace(' ', 'T') + 'Z');
            var dts = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
                ' - ' + dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            tr.innerHTML = '<td><span class="provider-name">' + esc(act.action) + '</span>' +
                '<span class="provider-type">' + esc((act.details || '').substring(0, 60)) + '</span></td>' +
                '<td>Activity</td><td>' + dts + '</td>' +
                '<td><strong style="font-size:0.82rem;">Done</strong></td>';
            tr.style.opacity = '0';
            tbody.insertBefore(tr, tbody.firstChild);
            requestAnimationFrame(function () { tr.style.transition = 'opacity 0.4s'; tr.style.opacity = '1'; });
        });
    }

    // ── Poll ──
    function poll() {
        var url = 'includes/poll-updates.php?sess=' + encodeURIComponent(sessName) + '&since=' + encodeURIComponent(lastPoll);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) return;
                lastPoll = data.server_time;

                // Update notification badge
                if (typeof data.notif_unread_count !== 'undefined') {
                    updateNotifBadge(data.notif_unread_count);
                }

                // Append notifications
                if (data.notifications && data.notifications.length) {
                    appendNotifList(data.notifications);
                }

                // Update KPI counts
                if (data.counts) updateKPIs(data.counts);

                // Append new activities
                if (data.activities && data.activities.length) {
                    appendActivities(data.activities);
                }

                // Dispatch custom event for dashboard-specific handlers
                var evt = new CustomEvent('dcvs:realtime', { detail: data });
                document.dispatchEvent(evt);
            })
            .catch(function () { /* retry next interval */ });
    }

    // ── Start / Stop based on visibility ──
    function start() {
        poll();
        pollTimer = setInterval(poll, POLL_INTERVAL);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        } else {
            if (!pollTimer) { poll(); pollTimer = setInterval(poll, POLL_INTERVAL); }
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
