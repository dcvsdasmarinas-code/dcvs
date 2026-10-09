(function() {
  'use strict';

  var PIN_FOCUS_ZOOM = 18;

  function safeMediaUrl(value) {
    var url = String(value || '').trim();
    if (!url || url.indexOf('//') === 0 || /^(?:javascript|data|vbscript):/i.test(url)) return '';
    if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^https?:/i.test(url)) return '';
    try {
      var parsedUrl = new URL(url, window.location.href);
      if (parsedUrl.origin !== window.location.origin) return '';
    } catch (error) {
      console.error('Could not validate report attachment URL:', error);
      return '';
    }
    return url;
  }

  function reportStatusLabel(status) {
    var labels = {
      pending: 'Pending Barangay review',
      validated: 'Pending DCVS review',
      confirmed: 'In progress',
      scheduled: 'In progress',
      resolved: 'Resolved',
      rejected: 'Rejected',
      active: 'Active'
    };
    return labels[status] || status || '—';
  }

  function responseStageLabel(properties) {
    var stages = {
      barangay_response_approved: 'Accepted by Barangay',
      barangay_responding: 'Barangay responding',
      animal_secured: 'Animal secured',
      awaiting_turnover: 'Resolved · turnover pending',
      resolved: properties.turnover_status === 'receipt_confirmed' ? 'Turnover completed' : 'Resolved',
      turned_over: 'Turnover completed',
      escalated_to_dcvs: 'Escalated to DCVS',
      unable_to_locate: 'Unable to locate',
      returned_to_barangay: 'Returned to Barangay',
      dcvs_dispatch: 'DCVS dispatch',
      dcvs_processing: 'DCVS processing',
      closed_not_actionable: 'Closed'
    };
    return stages[properties.response_state] || reportStatusLabel(properties.status);
  }

  function responseActionLabel(action) {
    var labels = {
      accept_barangay_assignment: 'Accepted',
      start_response: 'Response started',
      update_progress: 'Progress update',
      mark_secured: 'Animal secured',
      resolve_barangay: 'Incident resolved',
      confirm_turnover: 'Turnover completed',
      unable_to_secure: 'Escalated to DCVS',
      unable_to_locate: 'Unable to locate animal',
      staff_decide: 'DCVS dispatched'
    };
    return labels[action] || String(action || '').replace(/_/g, ' ');
  }

  function formatWorkflowDate(value) {
    var normalized = String(value || '').replace(' ', 'T');
    var date = normalized ? new Date(normalized) : null;
    return date && !isNaN(date.getTime())
      ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(date)
      : (value || '—');
  }

  function isBarangayTeamAssigned(properties) {
    return properties.response_mode === 'barangay_response'
      && ['barangay_response_approved', 'barangay_responding', 'animal_secured',
        'awaiting_turnover', 'resolved', 'returned_to_barangay'].indexOf(properties.response_state) !== -1;
  }

  function isDcvsTakeover(properties) {
    return ['escalated_to_dcvs', 'dcvs_dispatch', 'dcvs_processing'].indexOf(properties.response_state) !== -1;
  }

  function routeOriginKey(properties, isBarangayMap) {
    if (isBarangayTeamAssigned(properties)) return 'barangay:' + properties.barangay_id + ':assigned';
    if (isBarangayMap && !isDcvsTakeover(properties)) return 'barangay:' + properties.barangay_id + ':preview';
    return 'dcvs';
  }

  function reportTypeLabel(type) {
    var labels = {
      stray: 'Stray Animal',
      rabies: 'Rabies Case',
      injured: 'Injured Animal',
      dead: 'Dead Animal'
    };
    return labels[String(type || '').toLowerCase()] || type || 'Report';
  }

  function exactLocation(location, barangay) {
    var value = String(location || '').trim();
    var barangayName = String(barangay || '').trim();
    if (!value || !barangayName) return value;

    var prefix = new RegExp('^' + barangayName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*,\\s*', 'i');
    if (prefix.test(value)) return value.replace(prefix, '').trim();
    if (value.toLowerCase() === barangayName.toLowerCase()) return '';
    return value;
  }

  function formatIncidentDate(value) {
    var match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/.exec(String(value || ''));
    if (!match) return value || '—';

    var date = new Date(
      Number(match[1]),
      Number(match[2]) - 1,
      Number(match[3]),
      Number(match[4]),
      Number(match[5])
    );
    if (date.getFullYear() !== Number(match[1])
        || date.getMonth() !== Number(match[2]) - 1
        || date.getDate() !== Number(match[3])) return value;

    var dateText = new Intl.DateTimeFormat('en-US', {
      month: 'long',
      day: 'numeric',
      year: 'numeric'
    }).format(date);
    var timeText = new Intl.DateTimeFormat('en-US', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    }).format(date);
    return dateText + ', ' + timeText;
  }

  function addInformationRow(parent, label, value) {
    var row = document.createElement('div');
    row.className = 'dcvs-report-pin-info-row';
    var content = document.createElement('div');
    content.className = 'dcvs-report-pin-info-content';
    var heading = document.createElement('span');
    heading.className = 'dcvs-report-pin-info-label';
    heading.textContent = label;
    var text = document.createElement('span');
    text.className = 'dcvs-report-pin-info-value';
    text.textContent = value || '—';
    content.appendChild(heading);
    content.appendChild(text);
    row.appendChild(content);
    parent.appendChild(row);
  }

  function initializePins(mapElement) {
    var sessionName = mapElement.getAttribute('data-report-pins-session');
    if (!sessionName) return;

    var map = null;
    var drawer = null;
    var activePinSource = null;
    var pinFeatures = [];
    var pinInteractionsRegistered = false;
    var activeRouteReportId = null;
    var activeRouteData = null;
    var routeRequestToken = 0;
    var routeStatusElement = null;
    var pinsRefreshTimer = null;
    var pinsRefreshInFlight = false;
    var isStaffGeoMap = /(?:^|\/)dashboard-staff-geo\.php$/i.test(window.location.pathname);
    var requestedReportId = null;
    var requestedReportOpened = false;
    if (isStaffGeoMap || sessionName === 'DCVS_BARANGAY_SESSION') {
      var reportIdFromUrl = Number(new URLSearchParams(window.location.search).get('report_id'));
      if (Number.isSafeInteger(reportIdFromUrl) && reportIdFromUrl > 0) requestedReportId = reportIdFromUrl;
    }

    function closeDrawer() {
      if (drawer && drawer.parentNode) drawer.remove();
      drawer = null;
    }

    function clearRoute() {
      if (!map) return;
      ['report-route-guide-circles', 'report-route-guides', 'report-route-start-identity', 'report-route-start-point', 'report-route-line'].forEach(function(layerId) {
        if (map.getLayer(layerId)) map.removeLayer(layerId);
      });
      ['report-route-guides', 'report-route-start-point', 'report-route-line'].forEach(function(sourceId) {
        if (map.getSource(sourceId)) map.removeSource(sourceId);
      });
      activeRouteReportId = null;
      activeRouteData = null;
    }

    function addRouteToMap(routeData, reportId) {
      clearRoute();
      var coordinates = routeData.route.coordinates;
      var bounds = coordinates.reduce(function(result, coordinate) {
        result[0][0] = Math.min(result[0][0], coordinate[0]);
        result[0][1] = Math.min(result[0][1], coordinate[1]);
        result[1][0] = Math.max(result[1][0], coordinate[0]);
        result[1][1] = Math.max(result[1][1], coordinate[1]);
        return result;
      }, [[Infinity, Infinity], [-Infinity, -Infinity]]);
      map.addSource('report-route-line', {
        type: 'geojson',
        data: {
          type: 'Feature',
          properties: {},
          geometry: { type: 'LineString', coordinates: coordinates }
        }
      });
      var routeLineLayer = {
        id: 'report-route-line',
        type: 'line',
        source: 'report-route-line',
        paint: {
          'line-color': '#2563eb',
          'line-width': 5,
          'line-opacity': 0.9
        },
        layout: { 'line-cap': 'round', 'line-join': 'round' }
      };
      if (map.getLayer('report-pins-clusters')) {
        map.addLayer(routeLineLayer, 'report-pins-clusters');
      } else {
        map.addLayer(routeLineLayer);
      }

      var guideFeatures = (routeData.route.steps || [])
        .filter(function(step) { return step.is_maneuver; })
        .map(function(step, index) {
          return {
            type: 'Feature',
            properties: { number: String(index + 1), instruction: step.instruction },
            geometry: { type: 'Point', coordinates: step.coordinates }
          };
        });
      map.addSource('report-route-guides', {
        type: 'geojson',
        data: { type: 'FeatureCollection', features: guideFeatures }
      });
      map.addLayer({
        id: 'report-route-guide-circles',
        type: 'circle',
        source: 'report-route-guides',
        paint: {
          'circle-radius': 10,
          'circle-color': '#1d4ed8',
          'circle-stroke-color': '#ffffff',
          'circle-stroke-width': 2
        }
      });
      map.addLayer({
        id: 'report-route-guides',
        type: 'symbol',
        source: 'report-route-guides',
        layout: {
          'text-field': ['get', 'number'],
          'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
          'text-size': 11,
          'text-allow-overlap': true
        },
        paint: { 'text-color': '#ffffff' }
      });
      map.addSource('report-route-start-point', {
        type: 'geojson',
        data: {
          type: 'Feature',
          properties: {
            identity: routeData.origin.identity || 'dcvs',
            initials: routeData.origin.initials || 'DC',
            display_name: routeData.origin.display_name || routeData.origin.name || 'DCVS Team',
            assigned: Boolean(routeData.origin.assigned)
          },
          geometry: { type: 'Point', coordinates: routeData.origin.coordinates }
        }
      });
      map.addLayer({
        id: 'report-route-start-point',
        type: 'circle',
        source: 'report-route-start-point',
        paint: {
          'circle-radius': 16,
          'circle-color': ['match', ['get', 'identity'], 'barangay', '#15803d', '#1d4ed8'],
          'circle-stroke-color': '#ffffff',
          'circle-stroke-width': 3
        }
      });
      map.addLayer({
        id: 'report-route-start-identity',
        type: 'symbol',
        source: 'report-route-start-point',
        layout: {
          'text-field': ['get', 'initials'],
          'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
          'text-size': 10,
          'text-allow-overlap': true
        },
        paint: {
          'text-color': '#ffffff',
          'text-halo-color': ['match', ['get', 'identity'], 'barangay', '#15803d', '#1d4ed8'],
          'text-halo-width': 1
        }
      });
      map.fitBounds(bounds, { padding: 64, maxZoom: 16, duration: 700 });
      activeRouteData = routeData;
      activeRouteReportId = reportId;
    }

    function showRouteStatus(message, isError, includeAttribution) {
      var container = map.getContainer();
      if (!routeStatusElement || !routeStatusElement.isConnected) {
        routeStatusElement = document.createElement('div');
        routeStatusElement.className = 'dcvs-report-route-notice';
        routeStatusElement.setAttribute('role', 'status');
        routeStatusElement.setAttribute('aria-live', 'polite');
        container.appendChild(routeStatusElement);
      }
      routeStatusElement.classList.toggle('is-error', Boolean(isError));
      routeStatusElement.textContent = message;
      if (includeAttribution) {
        routeStatusElement.appendChild(document.createTextNode(' · '));
        var attribution = document.createElement('a');
        attribution.href = 'https://www.openstreetmap.org/copyright';
        attribution.target = '_blank';
        attribution.rel = 'noopener noreferrer';
        attribution.textContent = '© OpenStreetMap contributors';
        routeStatusElement.appendChild(attribution);
      }
    }

    function calculateRoute(properties) {
      var requestToken = ++routeRequestToken;
      if (mapElement.getAttribute('data-report-route-enabled') !== '1') {
        showRouteStatus('Route service is unavailable.', true, false);
        return;
      }

      showRouteStatus('Calculating route…', false, false);
      fetch('geo-map/report-route.php?sess=' + encodeURIComponent(sessionName)
          + '&report_id=' + encodeURIComponent(properties.report_id), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function(response) {
        return response.json().then(function(data) {
          if (!response.ok) throw new Error(data.error || 'Route request failed.');
          return data;
        });
      }).then(function(routeData) {
        if (requestToken !== routeRequestToken) return;
        if (!routeData.route || !Array.isArray(routeData.route.coordinates)
            || routeData.route.coordinates.length < 2
            || !Array.isArray(routeData.origin && routeData.origin.coordinates)) {
          throw new Error('The routing service returned an invalid route.');
        }
        addRouteToMap(routeData, Number(properties.report_id));
        var originName = routeData.origin.display_name || routeData.origin.name || 'office';
        var message = routeData.origin.assigned
          ? 'Assigned route from ' + originName + '.'
          : (routeData.origin.identity === 'barangay'
            ? 'Route preview from ' + originName + '; this report is not assigned to the barangay yet.'
            : 'Route from ' + originName + (routeData.origin.accuracy === 'approximate' ? '; verify the entrance.' : '.'));
        if (routeData.origin.accuracy === 'approximate') {
          message = message.replace(/\.$/, '') + ' (approximate coordinates).';
        }
        showRouteStatus(message, false, true);
      }).catch(function(error) {
        if (requestToken !== routeRequestToken) return;
        console.error('Could not calculate report route:', error);
        showRouteStatus(error.message || 'Could not calculate the route.', true, false);
      });
    }

    function announcePinLoad(error) {
      mapElement.dispatchEvent(new CustomEvent('dcvs:reportpinsready', {
        detail: error ? { error: error.message || String(error) } : {}
      }));
    }

    function openDrawer(feature) {
      var reportId = Number(feature && feature.properties && feature.properties.report_id);
      var completeFeature = pinFeatures.find(function(item) {
        return Number(item.properties && item.properties.report_id) === reportId;
      });
      if (completeFeature) feature = completeFeature;

      closeDrawer();
      routeRequestToken += 1;
      clearRoute();
      var properties = feature.properties || {};
      var coordinates = feature.geometry.coordinates.slice();
      var element = document.createElement('section');
      element.className = 'dcvs-report-pin-drawer';
      element.setAttribute('role', 'dialog');
      element.setAttribute('aria-label', 'Report pin details');

      var mediaItems = [];
      if (Array.isArray(properties.attachments)) {
        properties.attachments.forEach(function(attachment) {
          var type = attachment && attachment.type;
          var url = safeMediaUrl(attachment && attachment.url);
          if ((type === 'photo' || type === 'video') && url) {
            mediaItems.push({
              kind: type,
              url: url,
              label: type === 'photo' ? 'Report photo' : 'Report video'
            });
          }
        });
      }
      if (Array.isArray(properties.barangay_evidence)) {
        properties.barangay_evidence.forEach(function(attachment) {
          var type = attachment && attachment.type;
          var url = safeMediaUrl(attachment && attachment.url);
          if ((type === 'photo' || type === 'video') && url) {
            mediaItems.push({
              kind: type,
              url: url,
              label: type === 'photo' ? 'Barangay field evidence photo' : 'Barangay field evidence video'
            });
          }
        });
      }
      if (!mediaItems.length) {
        var photoUrl = safeMediaUrl(properties.photo);
        var videoUrl = safeMediaUrl(properties.video);
        if (photoUrl) mediaItems.push({ kind: 'photo', url: photoUrl, label: 'Report photo' });
        if (videoUrl) mediaItems.push({ kind: 'video', url: videoUrl, label: 'Report video' });
      }

      var hero = document.createElement('div');
      hero.className = 'dcvs-report-pin-hero';
      var heroPreview = document.createElement('div');
      heroPreview.className = 'dcvs-report-pin-hero-preview';
      var thumbnails = document.createElement('div');
      thumbnails.className = 'dcvs-report-pin-media-thumbnails';
      var slideControls = document.createElement('div');
      slideControls.className = 'dcvs-report-pin-slide-controls';
      var currentMediaIndex = 0;
      var activeMediaElement = null;

      function showHeroMedia(index) {
        if (activeMediaElement && activeMediaElement.tagName === 'VIDEO') {
          activeMediaElement.pause();
        }
        currentMediaIndex = index;
        activeMediaElement = null;
        var selected = mediaItems[index];
        heroPreview.textContent = '';
        if (slideControls.parentNode) slideControls.remove();
        if (!selected) {
          var placeholder = document.createElement('div');
          placeholder.className = 'dcvs-report-pin-hero-placeholder';
          placeholder.textContent = 'No report photo or video';
          heroPreview.appendChild(placeholder);
          return;
        }
        var media = document.createElement(selected.kind === 'photo' ? 'img' : 'video');
        activeMediaElement = media;
        media.src = selected.url;
        media.setAttribute('aria-label', selected.label);
        if (selected.kind === 'photo') {
          media.alt = selected.label;
          media.loading = 'eager';
          heroPreview.appendChild(media);
        } else {
          var videoWrap = document.createElement('div');
          videoWrap.className = 'dcvs-report-pin-hero-video';
          media.controls = true;
          media.playsInline = true;
          media.preload = 'metadata';
          videoWrap.appendChild(media);
          var playButton = document.createElement('button');
          playButton.type = 'button';
          playButton.className = 'dcvs-report-pin-hero-play';
          playButton.setAttribute('aria-label', 'Play report video');
          playButton.textContent = '▶';
          playButton.addEventListener('click', function() {
            var playResult = media.play();
            if (playResult && typeof playResult.catch === 'function') {
              playResult.catch(function(error) {
                console.error('Could not play report video:', error);
              });
            }
          });
          media.addEventListener('play', function() { playButton.hidden = true; });
          media.addEventListener('pause', function() { playButton.hidden = false; });
          videoWrap.appendChild(playButton);
          heroPreview.appendChild(videoWrap);
        }
        media.addEventListener('error', function() {
          var unavailable = document.createElement('div');
          unavailable.className = 'dcvs-report-pin-hero-placeholder';
          unavailable.textContent = selected.kind === 'photo' ? 'Photo unavailable' : 'Video unavailable';
          heroPreview.textContent = '';
          heroPreview.appendChild(unavailable);
        });
        Array.prototype.forEach.call(thumbnails.children, function(button, buttonIndex) {
          button.setAttribute('aria-pressed', buttonIndex === index ? 'true' : 'false');
        });
        if (mediaItems.length > 1) heroPreview.appendChild(slideControls);
      }

      if (mediaItems.length) {
        mediaItems.forEach(function(item, index) {
          var thumbnail = document.createElement('button');
          thumbnail.type = 'button';
          thumbnail.className = 'dcvs-report-pin-media-thumbnail';
          thumbnail.setAttribute('aria-label', 'Show ' + item.label.toLowerCase());
          thumbnail.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
          if (item.kind === 'photo') {
            var image = document.createElement('img');
            image.src = item.url;
            image.alt = '';
            image.loading = 'lazy';
            thumbnail.appendChild(image);
          } else {
            thumbnail.classList.add('dcvs-report-pin-media-video');
            var play = document.createElement('span');
            play.className = 'dcvs-report-pin-video-play';
            play.setAttribute('aria-hidden', 'true');
            play.textContent = '▶';
            thumbnail.appendChild(play);
            var videoLabel = document.createElement('span');
            videoLabel.className = 'dcvs-report-pin-video-label';
            videoLabel.textContent = 'VIDEO';
            thumbnail.appendChild(videoLabel);
          }
          thumbnail.addEventListener('click', function() { showHeroMedia(index); });
          thumbnails.appendChild(thumbnail);
        });
        showHeroMedia(0);
        if (mediaItems.length > 1) {
          var previousButton = document.createElement('button');
          previousButton.type = 'button';
          previousButton.className = 'dcvs-report-pin-slide-button dcvs-report-pin-slide-previous';
          previousButton.setAttribute('aria-label', 'Previous attachment');
          previousButton.textContent = '‹';
          previousButton.addEventListener('click', function() {
            showHeroMedia((currentMediaIndex - 1 + mediaItems.length) % mediaItems.length);
          });
          var nextButton = document.createElement('button');
          nextButton.type = 'button';
          nextButton.className = 'dcvs-report-pin-slide-button dcvs-report-pin-slide-next';
          nextButton.setAttribute('aria-label', 'Next attachment');
          nextButton.textContent = '›';
          nextButton.addEventListener('click', function() {
            showHeroMedia((currentMediaIndex + 1) % mediaItems.length);
          });
          slideControls.appendChild(previousButton);
          slideControls.appendChild(nextButton);
        }
      } else {
        showHeroMedia(-1);
      }
      hero.appendChild(heroPreview);
      if (mediaItems.length > 1) hero.appendChild(thumbnails);
      
      var title = document.createElement('h2');
      title.textContent = reportTypeLabel(properties.type);
      var statusBadge = document.createElement('span');
      var normalizedStatus = String(properties.status || 'unknown').toLowerCase().replace(/[^a-z0-9-]/g, '');
      statusBadge.className = 'dcvs-report-pin-status-badge dcvs-report-pin-status-' +
        normalizedStatus;
      statusBadge.setAttribute('data-live-status-badge', 'true');
      statusBadge.textContent = responseStageLabel(properties);
      var closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.setAttribute('aria-label', 'Close report pin details');
      closeButton.textContent = '×';
      closeButton.className = 'dcvs-report-pin-drawer-close';
      closeButton.addEventListener('click', closeDrawer);
      hero.appendChild(closeButton);
      element.appendChild(hero);

      var heading = document.createElement('div');
      heading.className = 'dcvs-report-pin-drawer-header';
      var titleRow = document.createElement('div');
      titleRow.className = 'dcvs-report-pin-drawer-title-row';
      titleRow.appendChild(title);
      titleRow.appendChild(statusBadge);
      heading.appendChild(titleRow);
      element.appendChild(heading);

      var body = document.createElement('div');
      body.className = 'dcvs-report-pin-drawer-body';

      var locationSection = document.createElement('section');
      locationSection.className = 'dcvs-report-pin-drawer-section';
      var locationHeading = document.createElement('h3');
      locationHeading.textContent = 'Location';
      locationSection.appendChild(locationHeading);
      addInformationRow(locationSection, 'Barangay', properties.barangay);
      addInformationRow(locationSection, 'Exact Location', exactLocation(properties.location, properties.barangay));
      body.appendChild(locationSection);

      var incidentSection = document.createElement('section');
      incidentSection.className = 'dcvs-report-pin-drawer-section';
      var incidentHeading = document.createElement('h3');
      incidentHeading.textContent = 'Incident information';
      incidentSection.appendChild(incidentHeading);
      addInformationRow(incidentSection, 'Incident Date', formatIncidentDate(properties.incident_at));
      addInformationRow(incidentSection, 'Priority', properties.priority);
      addInformationRow(incidentSection, 'Animal Type', properties.animal_type);
      addInformationRow(incidentSection, 'Estimated Number', properties.animal_count);
      addInformationRow(incidentSection, 'Behavior', properties.animal_behavior);
      addInformationRow(incidentSection, 'Description', properties.description);
      body.appendChild(incidentSection);

      var responseSection = document.createElement('section');
      responseSection.className = 'dcvs-report-pin-drawer-section dcvs-report-pin-live-section';
      responseSection.setAttribute('data-live-report-id', String(properties.report_id));
      var responseHeading = document.createElement('h3');
      responseHeading.textContent = 'Live case monitoring · syncs every 30 seconds';
      responseSection.appendChild(responseHeading);
      [
        ['Response stage', 'stage'],
        ['Responsible team', 'team'],
        ['Next target', 'deadline'],
        ['Latest update', 'latest']
      ].forEach(function(field) {
        var row = document.createElement('div');
        row.className = 'dcvs-report-pin-info-row dcvs-report-pin-live-row';
        var content = document.createElement('div');
        content.className = 'dcvs-report-pin-info-content';
        var label = document.createElement('span');
        label.className = 'dcvs-report-pin-info-label';
        label.textContent = field[0];
        var value = document.createElement('span');
        value.className = 'dcvs-report-pin-info-value';
        value.setAttribute('data-live-field', field[1]);
        content.appendChild(label);
        content.appendChild(value);
        row.appendChild(content);
        responseSection.appendChild(row);
      });
      body.appendChild(responseSection);
      updateLiveResponseDetails(responseSection, properties);

      element.appendChild(body);
      element.addEventListener('click', function(event) { event.stopPropagation(); });
      map.getContainer().appendChild(element);
      drawer = element;
      closeButton.focus();
      map.easeTo({
        center: coordinates,
        zoom: Math.max(map.getZoom(), PIN_FOCUS_ZOOM),
        offset: [element.offsetWidth / 2, 0],
        duration: 500
      });
      calculateRoute(properties);
    }

    function updateLiveResponseDetails(section, properties) {
      var deadline = properties.response_deadline || properties.progress_deadline
        || (properties.turnover_status === 'pending' ? properties.turnover_due_at : '');
      var deadlineDate = deadline ? new Date(String(deadline).replace(' ', 'T')) : null;
      var isOverdue = deadlineDate && !isNaN(deadlineDate.getTime())
        && deadlineDate.getTime() < Date.now()
        && (properties.status !== 'resolved' || properties.turnover_status === 'pending');
      var stage = isOverdue
        ? (properties.status === 'resolved' ? 'Turnover overdue'
          : (properties.response_state === 'barangay_response_approved' ? 'Initial response overdue' : 'Update overdue'))
        : (properties.response_state
        ? responseStageLabel(properties)
        : reportStatusLabel(properties.status));
      var team = isBarangayTeamAssigned(properties)
        ? 'Brgy. ' + properties.barangay + ' (assigned)'
        : (properties.status === 'pending' || properties.status === 'validated'
          ? (sessionName === 'DCVS_BARANGAY_SESSION' ? 'Barangay review · not assigned yet' : 'Awaiting assignment')
          : (isDcvsTakeover(properties) ? 'DCVS Team' : 'DCVS review'));
      var latest = properties.latest_response_action
        ? responseActionLabel(properties.latest_response_action)
          + (properties.latest_response_actor ? ' · ' + properties.latest_response_actor : '')
          + (properties.latest_response_reason ? ': ' + properties.latest_response_reason : '')
          + (properties.latest_response_at ? ' · ' + formatWorkflowDate(properties.latest_response_at) : '')
        : 'No response update recorded';
      var values = {
        stage: stage,
        team: team,
        deadline: deadline ? (isOverdue ? 'Overdue · ' : '') + formatWorkflowDate(deadline) : 'No deadline',
        latest: latest
      };
      Object.keys(values).forEach(function(key) {
        var field = section.querySelector('[data-live-field="' + key + '"]');
        if (field) field.textContent = values[key];
      });
    }

    function refreshPinData() {
      if (!activePinSource || pinsRefreshInFlight || document.hidden) return;
      pinsRefreshInFlight = true;
      var previousFeatures = pinFeatures;
      var pinsUrl = 'geo-map/report-pins.php?sess=' + encodeURIComponent(sessionName);
      if (requestedReportId !== null) pinsUrl += '&report_id=' + encodeURIComponent(String(requestedReportId));
      fetch(pinsUrl, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/geo+json' }
      }).then(function(response) {
        if (!response.ok) throw new Error('Report status refresh failed with HTTP ' + response.status + '.');
        return response.json();
      }).then(function(geojson) {
        if (!geojson || geojson.type !== 'FeatureCollection' || !Array.isArray(geojson.features)) {
          throw new Error('The refreshed report pins response is invalid.');
        }
        pinFeatures = geojson.features;
        activePinSource.setData(geojson);
        if (drawer) {
          var liveSection = drawer.querySelector('[data-live-report-id]');
          if (liveSection) {
            var reportId = Number(liveSection.getAttribute('data-live-report-id'));
            var updatedFeature = pinFeatures.find(function(feature) {
              return Number(feature.properties && feature.properties.report_id) === reportId;
            });
            if (updatedFeature) {
              var properties = updatedFeature.properties || {};
              var badge = drawer.querySelector('[data-live-status-badge]');
              if (badge) {
                badge.className = 'dcvs-report-pin-status-badge dcvs-report-pin-status-' +
                  String(properties.status || 'unknown').toLowerCase().replace(/[^a-z0-9-]/g, '');
                badge.textContent = responseStageLabel(properties);
              }
              updateLiveResponseDetails(liveSection, properties);
              var previous = previousFeatures.find(function(feature) {
                return Number(feature.properties && feature.properties.report_id) === reportId;
              });
              if (activeRouteReportId === reportId && previous
                  && routeOriginKey(previous.properties || {}, sessionName === 'DCVS_BARANGAY_SESSION')
                    !== routeOriginKey(properties, sessionName === 'DCVS_BARANGAY_SESSION')) {
                calculateRoute(properties);
              }
            }
          }
        }
      }).catch(function(error) {
        console.error('Could not refresh live report status:', error);
        announcePinLoad(error);
      }).then(function() {
        pinsRefreshInFlight = false;
      });
    }

    mapElement.addEventListener('dcvs:viewreportlocation', function(event) {
      var reportId = Number(event.detail && event.detail.reportId);
      if (!Number.isSafeInteger(reportId) || reportId <= 0) return;
      var feature = pinFeatures.find(function(item) {
        return Number(item.properties && item.properties.report_id) === reportId;
      });
      if (!feature) {
        showRouteStatus(
          isStaffGeoMap
            ? 'This report does not have an available pinned location.'
            : 'This report has no available pinned location in your barangay.',
          true,
          false
        );
        return;
      }
      openDrawer(feature);
    });

    function removePinLayers() {
      closeDrawer();
      ['report-pins-clusters', 'report-pins-cluster-count', 'report-pins-points'].forEach(function(layerId) {
        if (map.getLayer(layerId)) map.removeLayer(layerId);
      });
      if (map.getSource('report-pins')) map.removeSource('report-pins');
      activePinSource = null;
    }

    function enablePinLayers() {
      if (activePinSource) return Promise.resolve();
      var pinsUrl = 'geo-map/report-pins.php?sess=' + encodeURIComponent(sessionName);
      if (requestedReportId !== null) {
        pinsUrl += '&report_id=' + encodeURIComponent(String(requestedReportId));
      }
      return fetch(pinsUrl, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/geo+json' }
      }).then(function(response) {
        if (!response.ok) throw new Error('Report pins request failed with HTTP ' + response.status + '.');
        return response.json();
      }).then(function(geojson) {
        if (!geojson || geojson.type !== 'FeatureCollection' || !Array.isArray(geojson.features)) {
          throw new Error('The report pins response is invalid.');
        }
        pinFeatures = geojson.features;
        map.addSource('report-pins', {
          type: 'geojson',
          data: geojson,
          cluster: true,
          clusterRadius: 42,
          clusterMaxZoom: 14
        });
        activePinSource = map.getSource('report-pins');
        map.addLayer({
          id: 'report-pins-clusters',
          type: 'circle',
          source: 'report-pins',
          filter: ['has', 'point_count'],
          paint: {
            'circle-color': '#7f1d1d',
            'circle-radius': ['step', ['get', 'point_count'], 17, 10, 22, 50, 28],
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2
          }
        });
        map.addLayer({
          id: 'report-pins-cluster-count',
          type: 'symbol',
          source: 'report-pins',
          filter: ['has', 'point_count'],
          layout: {
            'text-field': ['get', 'point_count_abbreviated'],
            'text-size': 12,
            'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold']
          },
          paint: { 'text-color': '#ffffff' }
        });
        map.addLayer({
          id: 'report-pins-points',
          type: 'circle',
          source: 'report-pins',
          filter: ['!', ['has', 'point_count']],
          paint: {
            'circle-color': [
              'match', ['get', 'priority'],
              'critical', '#7f1d1d',
              'high', '#ef4444',
              'medium', '#f59e0b',
              'low', '#16a34a',
              '#ef4444'
            ],
            'circle-radius': 9,
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2
          }
        });
        if (!pinInteractionsRegistered) {
          pinInteractionsRegistered = true;
          map.on('click', 'report-pins-clusters', function(clickEvent) {
            var feature = clickEvent.features && clickEvent.features[0];
            if (!feature) return;
            var source = map.getSource('report-pins');
            source.getClusterExpansionZoom(feature.properties.cluster_id, function(error, zoom) {
              if (error) {
                console.error('Could not expand report pin cluster:', error);
                return;
              }
              map.easeTo({ center: feature.geometry.coordinates, zoom: zoom });
            });
          });
          map.on('click', 'report-pins-points', function(clickEvent) {
            var feature = clickEvent.features && clickEvent.features[0];
            if (!feature) return;
            openDrawer(feature);
          });
          map.on('mouseenter', 'report-pins-clusters', function() { map.getCanvas().style.cursor = 'pointer'; });
          map.on('mouseleave', 'report-pins-clusters', function() { map.getCanvas().style.cursor = ''; });
          map.on('mouseenter', 'report-pins-points', function() { map.getCanvas().style.cursor = 'pointer'; });
          map.on('mouseleave', 'report-pins-points', function() { map.getCanvas().style.cursor = ''; });
        }
        if (requestedReportId !== null && !requestedReportOpened) {
          requestedReportOpened = true;
          mapElement.dispatchEvent(new CustomEvent('dcvs:viewreportlocation', {
            detail: { reportId: requestedReportId }
          }));
        }
        if (!pinsRefreshTimer) pinsRefreshTimer = window.setInterval(refreshPinData, 30000);
        announcePinLoad();
      }).catch(function(error) {
        console.error('Could not load authorized report pins:', error);
        if (map.getSource('report-pins')) removePinLayers();
        announcePinLoad(error);
      });
    }

    mapElement.addEventListener('mapcn:load', function(event) {
      map = event.detail && event.detail.map;
      if (!map) return;
      map.on('style.load', function() {
        if (activeRouteData) addRouteToMap(activeRouteData, activeRouteReportId);
      });
      mapElement.addEventListener('dcvs:basemapstyleload', function() {
        activePinSource = map.getSource('report-pins');
        if (!activePinSource) enablePinLayers();
      });

      enablePinLayers();
    }, { once: true });

    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && drawer) closeDrawer();
    });
  }

  document.querySelectorAll('[data-report-pins-session]').forEach(initializePins);
})();
