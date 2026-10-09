(function() {
  var mapElement = document.getElementById('geoMap');
  if (!mapElement) return;

  var drawerRequestId = 0;
  var riskStyles = {
    Green: { dot: '#22c55e', text: '#166534', label: 'LOW RISK', description: 'No confirmed cases in this barangay' },
    Yellow: { dot: '#eab308', text: '#854d0e', label: 'MEDIUM RISK', description: 'Active cases detected in this barangay' },
    Orange: { dot: '#f97316', text: '#9a3412', label: 'HIGH RISK', description: 'Multiple active cases require attention' },
    Red: { dot: '#ef4444', text: '#991b1b', label: 'CRITICAL RISK', description: 'Significant outbreak — immediate action needed' }
  };
  var reportTypeLabels = {
    rabies: 'Rabies Reports',
    rescue: 'Rescue Reports',
    stray: 'Stray Reports',
    injured: 'Injured Reports',
    dead: 'Dead Reports'
  };
  var reportTypeColors = {
    rabies: '#dc2626',
    rescue: '#2563eb',
    stray: '#ea580c',
    injured: '#d97706',
    dead: '#6b7280'
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(character) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[character];
    });
  }

  function formatDate(value, includeTime) {
    if (!value) return '--';
    var date = new Date(String(value).replace(' ', 'T'));
    if (isNaN(date.getTime())) return '--';
    return includeTime
      ? date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
      : date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function setText(id, value) {
    var element = document.getElementById(id);
    if (element) element.textContent = value == null ? '' : value;
  }

  function openGeoDrawer(properties) {
    var requestId = ++drawerRequestId;
    var name = properties.name || 'Barangay';
    var risk = riskStyles[properties.risk] || riskStyles.Green;
    setText('geoDrawerName', name);
    document.getElementById('geoDrawerRiskDot').style.background = risk.dot;
    var riskText = document.getElementById('geoDrawerRiskText');
    riskText.textContent = risk.label;
    riskText.style.color = risk.text;
    setText('geoDrawerRiskDesc', risk.description);
    setText('geoDrawerConfirmed', properties.confirmed || 0);
    setText('geoDrawerPending', properties.pending || 0);
    setText('geoDrawerResolved', properties.resolved || 0);
    setText('geoDrawerTotal', Number(properties.confirmed || 0)
      + Number(properties.pending || 0)
      + Number(properties.resolved || 0));
    setText('geoDrawerUpdated', formatDate(properties.lastUpdated, true));
    document.getElementById('geoDrawerViewAll').href = 'dashboard-barangay.php#reports';
    document.getElementById('geoDrawerBreakdown').textContent = 'Loading...';
    document.getElementById('geoDrawerRecentWrap').style.display = 'none';
    document.getElementById('geoDrawerNoRecent').style.display = 'none';
    document.getElementById('geoDrawerOverlay').classList.add('open');
    document.getElementById('geoDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('geo-map/barangay-detail.php?sess=DCVS_BARANGAY_SESSION', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function(response) {
      if (!response.ok) throw new Error('Barangay details request failed with HTTP ' + response.status + '.');
      return response.json();
    }).then(function(data) {
      if (requestId !== drawerRequestId || !document.getElementById('geoDrawer').classList.contains('open')) return;
      if (data.error) throw new Error(data.error);
      setText('geoDrawerName', data.name || name);
      setText('geoDrawerConfirmed', data.confirmed || 0);
      setText('geoDrawerPending', data.pending || 0);
      setText('geoDrawerResolved', data.resolved || 0);
      setText('geoDrawerTotal', data.total || 0);
      setText('geoDrawerUpdated', formatDate(data.last_updated, true));

      var breakdown = (data.breakdown || []).map(function(item) {
        var type = String(item.type || 'other');
        var label = reportTypeLabels[type] || (type.charAt(0).toUpperCase() + type.slice(1) + ' Reports');
        var color = reportTypeColors[type] || '#71717a';
        return '<div class="geo-activity-row"><span class="geo-activity-name"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' +
          color + ';margin-right:8px;vertical-align:middle;"></span>' + escapeHtml(label) +
          '</span><span class="geo-activity-count">' + Number(item.count || 0) + '</span></div>';
      }).join('');
      document.getElementById('geoDrawerBreakdown').innerHTML = breakdown
        || '<div style="font-size:13px;color:#a1a1aa;padding:8px 0;">No reports</div>';

      if (data.recent_case) {
        var recent = data.recent_case;
        var urgency = String(recent.urgency || 'medium').toLowerCase();
        var urgencyStyles = {
          critical: { background: '#fef2f2', color: '#991b1b', border: '#fecaca' },
          high: { background: '#fff7ed', color: '#9a3412', border: '#fed7aa' },
          medium: { background: '#eff6ff', color: '#1e40af', border: '#bfdbfe' },
          low: { background: '#f9fafb', color: '#374151', border: '#e5e7eb' }
        };
        var urgencyStyle = urgencyStyles[urgency] || urgencyStyles.medium;
        var type = String(recent.type || 'report');
        var typeLabel = type === 'rabies' ? 'Suspected Rabies' : type.charAt(0).toUpperCase() + type.slice(1);
        document.getElementById('geoDrawerRecent').innerHTML =
          '<div class="geo-recent-id">' + escapeHtml(recent.ref_code || '') + '</div>' +
          '<div class="geo-recent-type">' + escapeHtml(typeLabel) + '</div>' +
          '<div class="geo-recent-meta"><span class="geo-recent-urgency" style="background:' + urgencyStyle.background +
          ';color:' + urgencyStyle.color + ';border:1px solid ' + urgencyStyle.border + ';">' +
          escapeHtml(urgency.charAt(0).toUpperCase() + urgency.slice(1) + ' Risk') +
          '</span><span class="geo-recent-date">' + escapeHtml(formatDate(recent.date, false)) + '</span></div>' +
          '<a class="geo-recent-action" href="reports-barangay.php?view=' + encodeURIComponent(recent.id) +
          '">View Report <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></a>';
        document.getElementById('geoDrawerRecentWrap').style.display = 'block';
      } else {
        document.getElementById('geoDrawerNoRecent').style.display = 'block';
      }
    }).catch(function(error) {
      if (requestId !== drawerRequestId) return;
      console.error('Could not load barangay drawer details:', error);
      document.getElementById('geoDrawerBreakdown').innerHTML =
        '<div style="font-size:13px;color:#dc2626;padding:8px 0;">Failed to load report details</div>';
    });
  }

  window.closeGeoDrawer = function() {
    drawerRequestId++;
    document.getElementById('geoDrawerOverlay').classList.remove('open');
    document.getElementById('geoDrawer').classList.remove('open');
    document.body.style.overflow = '';
  };

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('geoDrawer').classList.contains('open')) {
      window.closeGeoDrawer();
    }
  });

  mapElement.addEventListener('mapcn:load', function(event) {
    var map = event.detail && event.detail.map;
    if (!map) return;

    map.on('click', function(clickEvent) {
      var reportPinLayers = ['report-pins-points', 'report-pins-clusters'].filter(function(layerId) {
        return !!map.getLayer(layerId);
      });
      if (reportPinLayers.length
          && map.queryRenderedFeatures(clickEvent.point, { layers: reportPinLayers }).length) return;
      if (!map.getLayer('barangay-fill')) return;
      var features = map.queryRenderedFeatures(clickEvent.point, { layers: ['barangay-fill'] });
      var feature = features[0];
      if (feature && feature.properties) openGeoDrawer(feature.properties);
    });

    map.on('mousemove', function(pointerEvent) {
      var isOverBarangay = map.getLayer('barangay-fill')
        && map.queryRenderedFeatures(pointerEvent.point, { layers: ['barangay-fill'] }).length > 0;
      map.getCanvas().style.cursor = isOverBarangay ? 'pointer' : '';
    });
    map.on('mouseleave', function() {
      map.getCanvas().style.cursor = '';
    });
  }, { once: true });
})();
