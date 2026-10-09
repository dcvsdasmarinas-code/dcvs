(function () {
  'use strict';

  function panelFor(target) {
    var dropdown = target.closest('.notif-dropdown');
    return dropdown ? dropdown.querySelector('[data-notification-panel]') : null;
  }

  function closeMenus(panel) {
    panel.querySelectorAll('.notif-row-menu.open').forEach(function (menu) {
      menu.classList.remove('open');
      var trigger = menu.closest('[data-notif-item]').querySelector('.notif-more');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
  }

  function feedback(panel, message) {
    var element = panel.querySelector('.notif-feedback');
    if (!element) return;
    element.textContent = message || '';
    element.classList.toggle('visible', !!message);
  }

  function setUnreadCount(panel, count) {
    count = Math.max(0, count);
    panel.dataset.unreadCount = count;
    var tabCount = panel.querySelector('[data-tab-count="inbox"]');
    if (tabCount) tabCount.textContent = count > 99 ? '99+' : (count ? String(count) : '');
    var badge = panel.closest('.notif-dropdown').querySelector('.notif-badge');
    if (badge) {
      badge.textContent = count > 9 ? '9+' : String(count);
      badge.style.display = count ? 'flex' : 'none';
    }
    var markAll = panel.querySelector('.notif-mark-all');
    if (markAll) markAll.disabled = count === 0;
  }

  function refresh(panel) {
    var active = panel.querySelector('.notif-tab.active');
    var tab = active ? active.dataset.notifTab : 'all';
    var visibleCount = 0;
    panel.querySelectorAll('[data-notif-item]').forEach(function (item) {
      var archived = item.dataset.archived === '1';
      var visible = tab === 'archived' ? archived
        : tab === 'inbox' ? !archived && item.dataset.unread === '1'
        : !archived;
      item.hidden = !visible;
      if (visible) visibleCount++;
    });
    var empty = panel.querySelector('.notif-empty');
    if (empty) {
      empty.hidden = visibleCount > 0;
      empty.textContent = tab === 'archived' ? 'No archived notifications.'
        : tab === 'inbox' ? 'You’re all caught up.'
        : 'No notifications yet.';
    }
  }

  function relativeTime(value) {
    var date = new Date(String(value || '').replace(' ', 'T'));
    if (isNaN(date.getTime())) return String(value || '');
    var seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
    if (seconds < 604800) {
      var days = Math.floor(seconds / 86400);
      return days + ' day' + (days === 1 ? '' : 's') + ' ago';
    }
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function appBaseUrl(panel) {
    if (panel.dataset.appBaseUrl) return panel.dataset.appBaseUrl;
    if (!panel.dataset.loadUrl) return '';
    var endpointPath = new URL(panel.dataset.loadUrl, window.location.href).pathname;
    return endpointPath.slice(0, endpointPath.lastIndexOf('/'));
  }

  function createRow(panel, notification) {
    var id = notification.notification_id || notification.id;
    var read = notification.is_read === true || notification.is_read === 1 || notification.read === true;
    var archived = notification.archived === true || notification.archived_at;
    var actorName = notification.actorName || notification.actor_name || notification.sender_name || '';
    var actorAvatar = notification.actorAvatar || notification.actor_avatar || notification.sender_avatar || '';
    var appBase = appBaseUrl(panel);
    var row = document.createElement('article');
    row.className = 'notif-item' + (read ? '' : ' unread');
    row.dataset.notifItem = '';
    row.dataset.nid = String(id || '');
    row.dataset.unread = read ? '0' : '1';
    row.dataset.archived = archived ? '1' : '0';
    row.dataset.refType = notification.refType || notification.reference_type || '';
    row.dataset.refId = notification.refId || notification.reference_id || '';
    row.dataset.openHref = notification.openHref || '';

    var avatar = document.createElement('div');
    avatar.className = 'notif-avatar';
    avatar.title = actorName;
    avatar.setAttribute('aria-hidden', 'true');
    actorName.split(/\s+/).filter(Boolean).slice(0, 2).forEach(function (part) {
      avatar.appendChild(document.createTextNode(part.charAt(0).toUpperCase()));
    });
    if (actorAvatar) {
      var image = document.createElement('img');
      image.alt = '';
      image.loading = 'lazy';
      image.src = /^https?:\/\//i.test(actorAvatar) || actorAvatar.charAt(0) === '/'
        ? actorAvatar : appBase + '/' + actorAvatar.replace(/^\/+/, '');
      image.onerror = function () { image.remove(); };
      avatar.appendChild(image);
    }

    var content = document.createElement('div');
    content.className = 'notif-content';
    var heading = document.createElement('div');
    heading.className = 'notif-row-heading';
    var copy = document.createElement('div');
    copy.className = 'notif-copy';
    var title = document.createElement('div');
    title.className = 'notif-title';
    title.textContent = notification.title || 'Notification';
    copy.appendChild(title);
    if (notification.message) {
      var message = document.createElement('div');
      message.className = 'notif-msg';
      message.textContent = notification.message;
      copy.appendChild(message);
    }
    var more = document.createElement('button');
    more.className = 'notif-more';
    more.type = 'button';
    more.setAttribute('aria-label', 'Notification options');
    more.setAttribute('aria-expanded', 'false');
    more.innerHTML = '<i class="fas fa-ellipsis-h" aria-hidden="true"></i>';
    var dot = document.createElement('span');
    dot.className = 'notif-unread-dot';
    dot.setAttribute('aria-label', 'Unread');
    heading.append(copy, more, dot);
    content.appendChild(heading);
    var meta = document.createElement('div');
    meta.className = 'notif-meta';
    var time = document.createElement('span');
    time.textContent = relativeTime(notification.createdAt || notification.created_at);
    meta.appendChild(time);
    content.appendChild(meta);

    var menu = document.createElement('div');
    menu.className = 'notif-row-menu';
    menu.setAttribute('role', 'menu');
    [
      [read ? 'unread' : 'read', read ? 'Mark as unread' : 'Mark as read', 'fa-check'],
      [archived ? 'unarchive' : 'archive', archived ? 'Move to inbox' : 'Archive', 'fa-inbox']
    ].forEach(function (action) {
      var button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('role', 'menuitem');
      button.dataset.notifAction = action[0];
      var icon = document.createElement('i');
      icon.className = 'fas ' + action[2];
      icon.setAttribute('aria-hidden', 'true');
      button.append(icon, document.createTextNode(action[1]));
      menu.appendChild(button);
    });
    row.append(avatar, content, menu);
    return row;
  }

  function renderNotifications(panel, active, archived, unreadCount) {
    var list = panel.querySelector('.notif-panel-body');
    list.querySelectorAll('[data-notif-item]').forEach(function (row) { row.remove(); });
    active.concat(archived).forEach(function (notification) {
      list.insertBefore(createRow(panel, notification), list.querySelector('.notif-empty'));
    });
    unreadCount = typeof unreadCount === 'number' ? unreadCount : active.filter(function (notification) {
      return !(notification.is_read === true || notification.is_read === 1 || notification.read === true);
    }).length;
    setUnreadCount(panel, unreadCount);
    panel.dataset.loaded = '1';
    refresh(panel);
  }

  function loadBarangayNotifications(panel) {
    if (!panel.dataset.loadUrl || panel.dataset.loading === '1') return;
    panel.dataset.loading = '1';
    var base = panel.dataset.loadUrl;
    Promise.all([
      fetch(base + '&archived=0', { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) throw new Error('Could not load notifications.');
        return r.json();
      }),
      fetch(base + '&archived=1', { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) throw new Error('Could not load archived notifications.');
        return r.json();
      })
    ]).then(function (results) {
      if (!results[0].success || !results[1].success) throw new Error('Could not load notifications.');
      renderNotifications(panel, results[0].notifications, results[1].notifications, Number(results[0].unread_count));
    }).catch(function (error) {
      feedback(panel, error.message);
    }).finally(function () {
      panel.dataset.loading = '0';
    });
  }

  function postAction(panel, action, id) {
    var body = new URLSearchParams({
      csrf_token: panel.dataset.csrf || '',
      action: action
    });
    if (id) body.set('notification_id', id);
    return fetch(panel.dataset.actionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (result) {
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not update notifications.');
        return result;
      });
    });
  }

  document.addEventListener('click', function (event) {
    var bell = event.target.closest('.notif-btn');
    if (bell) {
      var panel = panelFor(bell);
      if (panel) {
        event.preventDefault();
        event.stopPropagation();
        var opened = !panel.classList.contains('open');
        document.querySelectorAll('[data-notification-panel].open').forEach(function (other) {
          other.classList.remove('open');
          closeMenus(other);
        });
        panel.classList.toggle('open', opened);
        if (opened && panel.dataset.loadUrl) loadBarangayNotifications(panel);
        refresh(panel);
      }
      return;
    }

    var tab = event.target.closest('[data-notif-tab]');
    if (tab) {
      var tabPanel = tab.closest('[data-notification-panel]');
      tabPanel.querySelectorAll('[data-notif-tab]').forEach(function (button) {
        var selected = button === tab;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
      closeMenus(tabPanel);
      refresh(tabPanel);
      return;
    }

    var trigger = event.target.closest('.notif-more');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      var row = trigger.closest('[data-notif-item]');
      var rowPanel = trigger.closest('[data-notification-panel]');
      var menu = row.querySelector('.notif-row-menu');
      var open = !menu.classList.contains('open');
      closeMenus(rowPanel);
      if (open) {
        menu.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
        var rect = trigger.getBoundingClientRect();
        var panelRect = rowPanel.getBoundingClientRect();
        menu.style.top = Math.min(rect.bottom + 5, window.innerHeight - 120) + 'px';
        menu.style.left = Math.max(panelRect.left + 8, Math.min(rect.right - 184, window.innerWidth - 192)) + 'px';
      }
      return;
    }

    var actionButton = event.target.closest('[data-notif-action]');
    if (actionButton) {
      var actionRow = actionButton.closest('[data-notif-item]');
      var actionPanel = actionButton.closest('[data-notification-panel]');
      var action = actionButton.dataset.notifAction;
      var wasUnread = actionRow.dataset.unread === '1' && actionRow.dataset.archived === '0';
      actionButton.disabled = true;
      postAction(actionPanel, action, actionRow.dataset.nid).then(function () {
        if (action === 'read') actionRow.dataset.unread = '0';
        if (action === 'unread') actionRow.dataset.unread = '1';
        if (action === 'archive') actionRow.dataset.archived = '1';
        if (action === 'unarchive') actionRow.dataset.archived = '0';
        actionRow.classList.toggle('unread', actionRow.dataset.unread === '1');
        var nowUnread = actionRow.dataset.unread === '1' && actionRow.dataset.archived === '0';
        setUnreadCount(actionPanel, Number(actionPanel.dataset.unreadCount || 0) + Number(nowUnread) - Number(wasUnread));
        var replacement = action === 'read' ? ['unread', 'Mark as unread']
          : action === 'unread' ? ['read', 'Mark as read']
          : action === 'archive' ? ['unarchive', 'Move to inbox']
          : ['archive', 'Archive'];
        actionButton.dataset.notifAction = replacement[0];
        actionButton.lastChild.textContent = replacement[1];
        feedback(actionPanel, '');
        closeMenus(actionPanel);
        refresh(actionPanel);
      }).catch(function (error) {
        feedback(actionPanel, error.message);
      }).finally(function () {
        actionButton.disabled = false;
      });
      return;
    }

    var markAll = event.target.closest('.notif-mark-all');
    if (markAll) {
      var markPanel = markAll.closest('[data-notification-panel]');
      postAction(markPanel, 'mark_all_read').then(function () {
        markPanel.querySelectorAll('[data-notif-item][data-archived="0"]').forEach(function (item) {
          item.dataset.unread = '0';
          item.classList.remove('unread');
          var readButton = item.querySelector('[data-notif-action="read"]');
          if (readButton) {
            readButton.dataset.notifAction = 'unread';
            readButton.lastChild.textContent = 'Mark as unread';
          }
        });
        setUnreadCount(markPanel, 0);
        feedback(markPanel, '');
        refresh(markPanel);
      }).catch(function (error) {
        feedback(markPanel, error.message);
      });
      return;
    }

    var notificationRow = event.target.closest('[data-notif-item]');
    if (notificationRow && !event.target.closest('button')) {
      var notificationPanel = notificationRow.closest('[data-notification-panel]');
      var portal = notificationPanel.dataset.portal;
      if (portal === 'resident') {
        if (notificationRow.dataset.unread === '1') {
          var wasUnread = notificationRow.dataset.archived === '0';
          postAction(notificationPanel, 'read', notificationRow.dataset.nid).then(function () {
            notificationRow.dataset.unread = '0';
            notificationRow.classList.remove('unread');
            if (wasUnread) setUnreadCount(notificationPanel, Number(notificationPanel.dataset.unreadCount || 0) - 1);
            refresh(notificationPanel);
          }).catch(function (error) {
            feedback(notificationPanel, error.message);
          });
        }
        notificationPanel.classList.remove('open');
      } else if (portal === 'barangay' && notificationRow.dataset.refType === 'report' && notificationRow.dataset.refId) {
        var reportUrl = 'reports-barangay.php?view=' + encodeURIComponent(notificationRow.dataset.refId);
        if (notificationRow.dataset.unread === '1') {
          postAction(notificationPanel, 'read', notificationRow.dataset.nid)
            .then(function () { window.location.href = reportUrl; })
            .catch(function (error) { feedback(notificationPanel, error.message); });
        } else {
          window.location.href = reportUrl;
        }
      }
      return;
    }

    if (!event.target.closest('.notif-dropdown')) {
      document.querySelectorAll('[data-notification-panel].open').forEach(function (panel) {
        panel.classList.remove('open');
        closeMenus(panel);
      });
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      document.querySelectorAll('[data-notification-panel].open').forEach(function (panel) {
        panel.classList.remove('open');
        closeMenus(panel);
      });
    }
  });

  document.querySelectorAll('[data-notification-panel]').forEach(function (panel) {
    refresh(panel);
  });

  document.addEventListener('dcvs:realtime', function (event) {
    var count = event.detail && Number(event.detail.notif_unread_count);
    if (!Number.isFinite(count)) return;
    document.querySelectorAll('[data-notification-panel]').forEach(function (panel) {
      setUnreadCount(panel, count);
    });
  });
})();
