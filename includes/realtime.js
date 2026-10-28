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
    var scriptUrl = document.currentScript ? document.currentScript.src : '';

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
        if (typeof window.setNotifCount === 'function' && document.getElementById('notifPanel')) {
            window.setNotifCount('unread', count);
        }
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
    function formatNotifAge(value) {
        var created = new Date(String(value || '').replace(' ', 'T'));
        if (isNaN(created.getTime())) return String(value || '');
        var seconds = Math.max(0, Math.floor((Date.now() - created.getTime()) / 1000));
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
        if (seconds < 604800) {
            var days = Math.floor(seconds / 86400);
            return days + ' day' + (days === 1 ? '' : 's') + ' ago';
        }
        return created.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function notificationAppBaseUrl(panel) {
        if (panel.dataset.appBaseUrl) return panel.dataset.appBaseUrl;
        if (!panel.dataset.loadUrl) return '';
        var endpointPath = new URL(panel.dataset.loadUrl, window.location.href).pathname;
        return endpointPath.slice(0, endpointPath.lastIndexOf('/'));
    }

    function buildStaffNotif(n, panel) {
        var type = String(n.type || '');
        var row = document.createElement('article');
        row.className = 'notif-item' + (n.is_read ? '' : ' unread');
        row.setAttribute('data-notif-item', '');
        row.setAttribute('data-nid', n.notification_id);
        row.setAttribute('data-type', type);
        row.setAttribute('data-unread', n.is_read ? '0' : '1');
        row.setAttribute('data-archived', '0');

        var avatar = document.createElement('div');
        avatar.className = 'notif-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        var activityName = String(n.activity_user_name || '').trim();
        avatar.title = activityName;
        avatar.textContent = activityName.split(/\s+/).filter(Boolean).map(function (part) {
            return part.charAt(0).toUpperCase();
        }).join('').slice(0, 2);
        var avatarPath = String(n.activity_avatar_url || '').trim();
        if (avatarPath) {
            var appBase = notificationAppBaseUrl(panel);
            var avatarSrc = /^https?:\/\//i.test(avatarPath)
                ? avatarPath
                : (avatarPath.charAt(0) === '/' ? avatarPath : appBase + '/' + avatarPath.replace(/^\/+/, ''));
            var avatarImage = document.createElement('img');
            avatarImage.src = avatarSrc;
            avatarImage.alt = '';
            avatarImage.loading = 'lazy';
            avatarImage.onerror = function () { avatarImage.remove(); };
            avatar.appendChild(avatarImage);
        }

        var content = document.createElement('div');
        content.className = 'notif-content';
        var heading = document.createElement('div');
        heading.className = 'notif-row-heading';
        var copy = document.createElement('div');
        copy.className = 'notif-copy';
        var title = document.createElement('div');
        title.className = 'notif-title';
        title.textContent = String(n.title || '');
        copy.appendChild(title);
        if (n.message) {
            var message = document.createElement('div');
            message.className = 'notif-msg';
            message.textContent = String(n.message).replace(/\s+/g, ' ').trim();
            copy.appendChild(message);
        }
        var more = document.createElement('button');
        more.type = 'button';
        more.className = 'notif-more';
        more.setAttribute('aria-label', 'Notification options');
        more.setAttribute('aria-expanded', 'false');
        var moreIcon = document.createElement('i');
        moreIcon.className = 'fas fa-ellipsis-h';
        moreIcon.setAttribute('aria-hidden', 'true');
        more.appendChild(moreIcon);
        var dot = document.createElement('span');
        dot.className = 'notif-unread-dot';
        dot.setAttribute('aria-label', 'Unread');
        heading.appendChild(copy);
        heading.appendChild(more);
        heading.appendChild(dot);
        content.appendChild(heading);

        var meta = document.createElement('div');
        meta.className = 'notif-meta';
        var age = document.createElement('span');
        age.textContent = formatNotifAge(n.created_at);
        meta.appendChild(age);
        var context = String(n.reference_type || '').trim();
        if (context || n.reference_id) {
            var separator = document.createElement('span');
            separator.className = 'notif-meta-separator';
            separator.textContent = '·';
            var contextText = document.createElement('span');
            contextText.className = 'notif-context';
            contextText.textContent = (context ? context.charAt(0).toUpperCase() + context.slice(1) : '') +
                (n.reference_id ? (context ? ' · ' : '') + '#' + n.reference_id : '');
            meta.appendChild(separator);
            meta.appendChild(contextText);
        }
        content.appendChild(meta);

        var menu = document.createElement('div');
        menu.className = 'notif-row-menu';
        menu.setAttribute('role', 'menu');
        [
            ['read', 'Mark as read', 'fa-check'],
            ['archive', 'Archive', 'fa-inbox']
        ].forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'menuitem');
            button.setAttribute('data-notif-action', item[0]);
            var icon = document.createElement('i');
            icon.className = 'fas ' + item[2];
            icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon);
            button.appendChild(document.createTextNode(item[1]));
            menu.appendChild(button);
        });

        row.appendChild(avatar);
        row.appendChild(content);
        row.appendChild(menu);
        return row;
    }

    function appendNotifList(notifs) {
        var list = document.getElementById('notificationList');
        if (!list) return;
        var empty = list.querySelector('.notif-empty');
        notifs.forEach(function (n) {
            if (list.querySelector('[data-nid="' + n.notification_id + '"]')) return;
            if (list.closest('[data-notification-panel]')) {
                var panel = list.closest('[data-notification-panel]');
                if (panel.dataset.portal === 'resident' && n.type !== 'staff_message') return;
                var staffRow = buildStaffNotif(n, panel);
                list.insertBefore(staffRow, list.firstChild);
                if (empty) empty.hidden = true;
                return;
            }
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
        if (list.closest('[data-notification-panel]') && typeof window.refreshNotifView === 'function') {
            window.refreshNotifView();
        }
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
        var pollEndpoint = scriptUrl ? new URL('poll-updates.php', scriptUrl).href : 'includes/poll-updates.php';
        var url = pollEndpoint + '?sess=' + encodeURIComponent(sessName) + '&since=' + encodeURIComponent(lastPoll);
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
