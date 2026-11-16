(function() {
  'use strict';

  var appBaseUrl = typeof window.DCVS_APP_BASE_URL === 'string' ? window.DCVS_APP_BASE_URL : '';
  if (typeof window.DCVS_APP_BASE_URL !== 'string' && document.currentScript && document.currentScript.src) {
    appBaseUrl = new URL('../../', document.currentScript.src).pathname.replace(/\/$/, '');
  }
  function appUrl(path) {
    return appBaseUrl + '/' + path.replace(/^\/+/, '');
  }

  var SOURCE_ID = 'dcvs-landmarks';
  var PROMINENT_LAYER_ID = 'dcvs-landmark-prominent-labels';
  var LABELS_LAYER_ID = 'dcvs-landmark-labels';
  var CLUSTERS_LAYER_ID = 'dcvs-landmark-clusters';
  var CLUSTER_COUNT_LAYER_ID = 'dcvs-landmark-cluster-count';
  var OFFICE_LABEL_LAYER_ID = 'dcvs-office-label';
  var HOME_HALL_LABEL_LAYER_ID = 'dcvs-home-hall-label';
  var MAP_LABEL_MAX_LENGTH = 32;
  var CATEGORY_META = {
    food: { color: '#ea580c', label: 'Food & dining' },
    education: { color: '#7c3aed', label: 'Schools & universities' },
    healthcare: { color: '#dc2626', label: 'Healthcare & pharmacies' },
    shopping: { color: '#c2410c', label: 'Shopping & markets' },
    worship: { color: '#9333ea', label: 'Churches & worship' },
    government: { color: '#2563eb', label: 'Government & barangay halls' },
    recreation: { color: '#16a34a', label: 'Parks & recreation' },
    sports: { color: '#0284c7', label: 'Sports & covered courts' },
    community: { color: '#0891b2', label: 'Community places' },
    other: { color: '#475569', label: 'Other places' }
  };
  var CATEGORY_ICON_MARKUP = {
    food: '<path d="M6 3v7m-3-7v4a3 3 0 0 0 6 0V3M6 7v14m11-18c-2.5 2-3 5-3 8h4V3m-2 8v10"/>',
    education: '<path d="m2 9 10-6 10 6-10 6L2 9Zm4 3v5c4 3 8 3 12 0v-5m-6 3v6m-2 0h4"/>',
    healthcare: '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6V3Z"/>',
    shopping: '<path d="M4 8h16l-1 13H5L4 8Zm4 0V6a4 4 0 0 1 8 0v2"/>',
    worship: '<path d="M12 2v5m-2-3h4M3 11l9-6 9 6M5 10v11h14V10m-9 11v-7h4v7"/>',
    government: '<path d="m3 9 9-6 9 6H3Zm2 0v11m4-11v11m6-11v11m4-11v11M3 21h18"/>',
    recreation: '<path d="m12 2-7 9h4l-3 6h12l-3-6h4l-7-9Zm0 15v5m-4 0h8"/>',
    sports: '<path d="M3 4h18v16H3zM12 4v16M3 12h18M3 4c4 0 6 3.6 6 8s-2 8-6 8m18-16c-4 0-6 3.6-6 8s2 8 6 8"/>',
    community: '<circle cx="12" cy="8" r="3"/><circle cx="5" cy="10" r="2"/><circle cx="19" cy="10" r="2"/><path d="M6 21v-2a6 6 0 0 1 12 0v2M1 20v-1a4 4 0 0 1 5-4m17 5v-1a4 4 0 0 0-5-4"/>',
    other: '<path d="m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1 3-6Z"/>'
  };
  var CATEGORY_ICON_IMAGES = {};
  var CATEGORY_ICON_PROMISES = {};

  function categoryIconSvg(category) {
    var meta = CATEGORY_META[category] || CATEGORY_META.other;
    var markup = CATEGORY_ICON_MARKUP[category] || CATEGORY_ICON_MARKUP.other;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24">'
      + '<g fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">' + markup + '</g>'
      + '<g fill="none" stroke="' + meta.color + '" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + markup + '</g>'
      + '</svg>';
  }

  function categoryIconDataUrl(category) {
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(categoryIconSvg(category));
  }

  function loadCategoryIcon(category) {
    if (!CATEGORY_ICON_PROMISES[category]) {
      CATEGORY_ICON_PROMISES[category] = new Promise(function(resolve, reject) {
        var image = new window.Image();
        image.onload = function() {
          CATEGORY_ICON_IMAGES[category] = image;
          resolve(image);
        };
        image.onerror = function() {
          reject(new Error('Could not load the ' + category + ' map icon.'));
        };
        image.src = categoryIconDataUrl(category);
      });
    }
    return CATEGORY_ICON_PROMISES[category];
  }

  function registerCategoryIcons(map) {
    var pending = [];
    Object.keys(CATEGORY_META).forEach(function(category) {
      var imageId = 'dcvs-poi-icon-' + category;
      if (map.hasImage(imageId)) return;
      var cachedImage = CATEGORY_ICON_IMAGES[category];
      if (cachedImage && cachedImage.complete && cachedImage.naturalWidth) {
        map.addImage(imageId, cachedImage, { pixelRatio: 2 });
        return;
      }
      pending.push(loadCategoryIcon(category).then(function(image) {
        if (!map.hasImage(imageId)) map.addImage(imageId, image, { pixelRatio: 2 });
      }));
    });
    return Promise.all(pending);
  }

  var CATEGORY_ICONS = ['match', ['get', 'category']];
  Object.keys(CATEGORY_META).forEach(function(category) {
    CATEGORY_ICONS.push(category, 'dcvs-poi-icon-' + category);
  });
  CATEGORY_ICONS.push('dcvs-poi-icon-other');

  function normalizeSearchText(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
  }

  function formatMapLabel(value) {
    var label = String(value || '').trim();
    if (label.length <= MAP_LABEL_MAX_LENGTH) return label;
    var shortened = label.slice(0, MAP_LABEL_MAX_LENGTH - 1);
    var wordBoundary = shortened.lastIndexOf(' ');
    if (wordBoundary >= MAP_LABEL_MAX_LENGTH / 2) {
      shortened = shortened.slice(0, wordBoundary);
    }
    return shortened.trim() + '…';
  }

  function getPlaceGroup(properties) {
    var labels = normalizeSearchText([
      properties.name,
      properties.category_label,
      properties.type_label
    ].join(' '));
    if (properties.is_own_barangay_hall
        || /\b(barangay|brgy)\b.*\bhall\b/.test(labels)) return 'halls';
    if (properties.category === 'healthcare' || properties.category === 'education') {
      return 'health-schools';
    }
    if (properties.category === 'food' || properties.category === 'shopping') return 'shops-food';
    return 'other';
  }

  function setPlacePriority(features) {
    features.forEach(function(feature) {
      feature.properties = feature.properties || {};
      var properties = feature.properties;
      var group = getPlaceGroup(properties);
      properties.map_label = formatMapLabel(properties.name);
      var rank = group === 'halls' ? 1 : properties.prominent === true ? 2 : 3;
      properties.place_group = group;
      properties.rank = rank;
      properties.minzoom = rank === 1 ? 12 : rank === 2 ? 13 : 14;
    });
  }

  function setLandmarkLabelPalette(map, style) {
    var satellite = style === 'satellite';
    [
      PROMINENT_LAYER_ID,
      LABELS_LAYER_ID,
      OFFICE_LABEL_LAYER_ID,
      HOME_HALL_LABEL_LAYER_ID
    ].forEach(function(layerId) {
      if (!map.getLayer(layerId)) return;
      map.setPaintProperty(layerId, 'text-color', satellite ? '#ffffff' : '#26352d');
      map.setPaintProperty(layerId, 'text-halo-color', satellite ? 'rgba(15, 23, 42, 0.9)' : '#ffffff');
    });
  }

  function getPlaceSuggestions(query, limit) {
    var normalizedQuery = normalizeSearchText(query);
    if (normalizedQuery.length < 3 || !window.DCVSMapLandmarks) return [];
    var terms = normalizedQuery.split(/\s+/);
    var matches = [];
    window.DCVSMapLandmarks.features.forEach(function(feature) {
      var properties = feature.properties || {};
      var name = String(properties.name || '');
      var category = CATEGORY_META[properties.category] || CATEGORY_META.other;
      var address = String(properties.address || '');
      var barangay = String(properties.barangay || '');
      var searchable = normalizeSearchText([
        name, address, barangay, properties.category_label, properties.type_label
      ].join(' '));
      if (terms.some(function(term) { return searchable.indexOf(term) === -1; })) return;

      var normalizedName = normalizeSearchText(name);
      var rank = normalizedName.indexOf(normalizedQuery) === 0
        ? 0
        : (normalizedName.indexOf(normalizedQuery) !== -1 ? 1 : 2);
      var coordinates = feature.geometry && feature.geometry.coordinates;
      if (!Array.isArray(coordinates) || coordinates.length !== 2) return;
      matches.push({
        rank: rank,
        name: name,
        value: {
          kind: 'landmark',
          label: name,
          name: name,
          coordinates: coordinates.slice(),
          barangay: barangay,
          address: address,
          context: address || ('Barangay ' + barangay),
          detail: [address, barangay ? 'Barangay ' + barangay : '', category.label]
            .filter(Boolean).join(' · '),
          type_label: properties.type_label || category.label,
          category_label: properties.category_label || category.label,
          coordinate_source: properties.coordinate_source || '',
          plus_code: properties.plus_code || '',
          boundary_status: properties.boundary_status || '',
          approximate: properties.accuracy !== 'verified'
            && properties.accuracy !== 'mapped-point'
            && properties.accuracy !== 'user-provided',
          source: properties.source || '',
          osm_url: properties.osm_url || ''
        }
      });
    });
    matches.sort(function(first, second) {
      return first.rank - second.rank || first.name.localeCompare(second.name);
    });
    return matches.slice(0, limit || 5).map(function(match) { return match.value; });
  }

  window.DCVSMapLandmarks = {
    features: [],
    suggest: getPlaceSuggestions,
    formatMapLabel: formatMapLabel,
    iconDataUrl: categoryIconDataUrl
  };

  function addAttribution(container) {
    var attribution = document.createElement('div');
    attribution.className = 'dcvs-poi-attribution';
    var contributors = document.createElement('a');
    contributors.href = 'https://www.openstreetmap.org/copyright';
    contributors.target = '_blank';
    contributors.rel = 'noopener noreferrer';
    contributors.textContent = '© OpenStreetMap contributors';
    attribution.appendChild(contributors);
    attribution.appendChild(document.createTextNode(' ('));
    var license = document.createElement('a');
    license.href = 'https://opendatacommons.org/licenses/odbl/1-0/';
    license.target = '_blank';
    license.rel = 'noopener noreferrer';
    license.textContent = 'ODbL';
    attribution.appendChild(license);
    attribution.appendChild(document.createTextNode(')'));
    container.appendChild(attribution);
  }

  function appendPopupRow(popup, label, value) {
    if (!value) return;
    var row = document.createElement('div');
    row.className = 'dcvs-landmark-popup-row';
    var rowLabel = document.createElement('span');
    rowLabel.className = 'dcvs-landmark-popup-label';
    rowLabel.textContent = label;
    var rowValue = document.createElement('span');
    rowValue.className = 'dcvs-landmark-popup-value';
    rowValue.textContent = value;
    row.appendChild(rowLabel);
    row.appendChild(rowValue);
    popup.appendChild(row);
  }

  function buildFilterControls(container, map, features, isStaffMap, isBarangayMap, isReportMap) {
    var counts = {};
    features.forEach(function(feature) {
      var category = feature.properties && feature.properties.category;
      if (CATEGORY_META[category]) counts[category] = (counts[category] || 0) + 1;
    });
    var categories = Object.keys(CATEGORY_META).filter(function(category) {
      return counts[category] > 0;
    });
    var selected = {};
    categories.forEach(function(category) { selected[category] = true; });
    var placeGroups = [
      { key: 'halls', label: 'Barangay halls', checked: true },
      { key: 'health-schools', label: 'Health and schools', checked: true },
      { key: 'shops-food', label: 'Shops and food', checked: true }
    ];
    var selectedGroups = {};
    placeGroups.forEach(function(group) { selectedGroups[group.key] = group.checked; });
    var zoomHint = null;
    if (isReportMap) {
      zoomHint = document.createElement('div');
      zoomHint.className = 'dcvs-landmark-zoom-hint';
      zoomHint.textContent = 'Zoom in to level 13 to see place names.';
      zoomHint.hidden = true;
    }

    var control = document.createElement('div');
    control.className = isReportMap
      ? 'dcvs-landmark-controls'
      : 'dcvs-landmark-controls dcvs-landmark-controls--panel';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = isReportMap ? 'dcvs-landmark-layer-toggle' : 'dcvs-landmark-filter-toggle';
    if (isReportMap) toggle.setAttribute('aria-pressed', 'false');
    else toggle.setAttribute('aria-expanded', 'true');
    toggle.textContent = 'Places';
    var filterToggle = toggle;
    if (isReportMap) {
      filterToggle = document.createElement('button');
      filterToggle.type = 'button';
      filterToggle.className = 'dcvs-landmark-filter-toggle';
      filterToggle.setAttribute('aria-expanded', 'false');
      filterToggle.setAttribute('aria-controls', 'dcvs-landmark-filter-menu');
      filterToggle.textContent = 'Filters';
    } else {
      toggle.setAttribute('aria-controls', 'dcvs-landmark-filter-menu');
    }
    var menu = document.createElement('div');
    menu.className = 'dcvs-landmark-filter-menu';
    menu.id = 'dcvs-landmark-filter-menu';
    menu.hidden = isReportMap;
    var heading = document.createElement('div');
    heading.className = 'dcvs-landmark-filter-heading';
    heading.textContent = isReportMap ? 'Show place categories' : 'Show on map';
    menu.appendChild(heading);

    function categoryFilter() {
      if (!isReportMap) {
        var visibleGroups = ['other'];
        placeGroups.forEach(function(group) {
          if (selectedGroups[group.key]) visibleGroups.push(group.key);
        });
        return ['match', ['get', 'place_group'], visibleGroups, true, false];
      }
      var visible = categories.filter(function(category) { return selected[category]; });
      return visible.length
        ? ['match', ['get', 'category'], visible, true, false]
        : ['==', ['get', 'category'], '__hidden__'];
    }

    function applyFilters() {
      var filter = categoryFilter();
      var scopeFilters = [];
      var zoomRankFilters = isReportMap ? [] : [[
        '<=',
        ['get', 'rank'],
        ['step', ['zoom'], 0, 11, 1, 12, 2, 14, 3]
      ], ['<=', ['get', 'minzoom'], ['zoom']]];
      if (isStaffMap) scopeFilters.push(['!=', ['get', 'is_dcvs_office'], true]);
      if (isBarangayMap) scopeFilters.push(['!=', ['get', 'is_own_barangay_hall'], true]);
      var layerFilters = [
        [PROMINENT_LAYER_ID, ['==', ['get', 'prominent'], true]],
        [LABELS_LAYER_ID, ['!=', ['get', 'prominent'], true]]
      ];
      layerFilters.forEach(function(entry) {
        var combined = ['all', filter].concat(zoomRankFilters, [entry[1]], scopeFilters);
        if (map.getLayer(entry[0])) map.setFilter(entry[0], combined);
      });
      if (isStaffMap && map.getLayer(OFFICE_LABEL_LAYER_ID)) {
        map.setFilter(OFFICE_LABEL_LAYER_ID, ['all', filter].concat(
          zoomRankFilters,
          [['==', ['get', 'is_dcvs_office'], true]]
        ));
      }
      if (isBarangayMap && map.getLayer(HOME_HALL_LABEL_LAYER_ID)) {
        map.setFilter(HOME_HALL_LABEL_LAYER_ID, ['all', filter].concat(
          zoomRankFilters,
          [['==', ['get', 'is_own_barangay_hall'], true]]
        ));
      }
      if (isReportMap) updatePlacesVisibility(placesVisible);
    }

    var placesVisible = isReportMap;
    function updatePlacesVisibility(visible) {
      placesVisible = !!visible;
      var selectedCount = categories.filter(function(category) { return selected[category]; }).length;
      [
        PROMINENT_LAYER_ID,
        LABELS_LAYER_ID,
        CLUSTERS_LAYER_ID,
        CLUSTER_COUNT_LAYER_ID
      ].forEach(function(layerId) {
        if (!map.getLayer(layerId)) return;
        var showLayer = placesVisible
          && (layerId !== CLUSTERS_LAYER_ID && layerId !== CLUSTER_COUNT_LAYER_ID
            || selectedCount === categories.length);
        map.setLayoutProperty(layerId, 'visibility', showLayer ? 'visible' : 'none');
      });
      toggle.setAttribute('aria-pressed', placesVisible ? 'true' : 'false');
      updateZoomHint();
    }

    function updateZoomHint() {
      if (zoomHint) zoomHint.hidden = !placesVisible || map.getZoom() >= 13;
    }

    if (isStaffMap) {
      applyFilters();
      return;
    }

    function appendFilterOption(value, labelText, checked, iconCategory, count) {
      var label = document.createElement('label');
      label.className = 'dcvs-landmark-filter-option';
      var checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.checked = checked;
      checkbox.value = value;
      checkbox.id = 'dcvs-landmark-category-' + value;
      checkbox.addEventListener('change', function() {
        if (isReportMap) selected[value] = checkbox.checked;
        else selectedGroups[value] = checkbox.checked;
        applyFilters();
      });
      var name = document.createElement('span');
      name.className = 'dcvs-landmark-filter-name';
      name.textContent = labelText;
      label.appendChild(checkbox);
      label.appendChild(name);
      if (iconCategory) {
        var marker = document.createElement('img');
        marker.className = 'dcvs-landmark-category-icon';
        marker.src = categoryIconDataUrl(iconCategory);
        marker.alt = '';
        marker.setAttribute('aria-hidden', 'true');
        label.insertBefore(marker, name);
      }
      if (count !== undefined) {
        var countElement = document.createElement('span');
        countElement.className = 'dcvs-landmark-filter-count';
        countElement.textContent = String(count);
        label.appendChild(countElement);
      }
      menu.appendChild(label);
    }

    if (isReportMap) {
      categories.forEach(function(category) {
        appendFilterOption(category, CATEGORY_META[category].label, true, category, counts[category]);
      });
      var sourceNote = document.createElement('p');
      sourceNote.className = 'dcvs-landmark-source-note';
      sourceNote.textContent = 'OpenStreetMap entries are community-mapped and are not independently verified by DCVS.';
      menu.appendChild(sourceNote);
    } else {
      placeGroups.forEach(function(group) {
        appendFilterOption(group.key, group.label, group.checked);
      });
    }

    filterToggle.addEventListener('click', function() {
      menu.hidden = !menu.hidden;
      filterToggle.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    if (isReportMap) {
      toggle.addEventListener('click', function() {
        updatePlacesVisibility(!placesVisible);
      });
      map.on('zoomend', updateZoomHint);
    }
    container.appendChild(control);
    control.appendChild(toggle);
    if (isReportMap) control.appendChild(filterToggle);
    if (zoomHint) control.appendChild(zoomHint);
    control.appendChild(menu);
    applyFilters();
  }

  function showFeaturePopup(map, feature, event) {
    if (!feature) return;
    if (!window.maplibregl || !window.maplibregl.Popup) {
      console.error('MapLibre popup support is unavailable; place details could not be shown.');
      return;
    }
    var properties = feature.properties || {};
    var category = CATEGORY_META[properties.category] || CATEGORY_META.other;
    var popup = document.createElement('div');
    popup.className = 'dcvs-landmark-popup';
    var title = document.createElement('h3');
    title.className = 'dcvs-landmark-popup-title';
    title.textContent = properties.name || 'Mapped place';
    popup.appendChild(title);
    var badge = document.createElement('span');
    badge.className = 'dcvs-landmark-popup-category';
    var badgeIcon = document.createElement('img');
    badgeIcon.src = categoryIconDataUrl(properties.category);
    badgeIcon.alt = '';
    badgeIcon.setAttribute('aria-hidden', 'true');
    var badgeLabel = document.createElement('span');
    badgeLabel.textContent = properties.category_label || category.label;
    badge.appendChild(badgeIcon);
    badge.appendChild(badgeLabel);
    popup.appendChild(badge);
    appendPopupRow(popup, 'Type', properties.type_label);
    appendPopupRow(popup, 'Address', properties.address || 'No street address mapped');
    appendPopupRow(popup, 'Barangay', properties.barangay);
    appendPopupRow(popup, 'Area / Cluster', properties.area_cluster);
    appendPopupRow(popup, 'Plus Code', properties.plus_code);
    if (properties.boundary_status === 'outside_official_boundary') {
      appendPopupRow(popup, 'Boundary', 'Outside the official 75-barangay boundary set');
    }
    appendPopupRow(popup, 'Phone', properties.phone);
    appendPopupRow(popup, 'Hours', properties.opening_hours);
    appendPopupRow(
      popup,
      'Location',
      properties.boundary_status === 'outside_official_boundary'
        ? 'User-provided pin outside the official boundary'
        : (properties.coordinate_source
          ? 'User-provided pin · ' + properties.coordinate_source + ' · not independently verified by DCVS'
          : (properties.accuracy === 'feature-center'
            ? 'Approximate center of the mapped feature'
            : (properties.source === 'OpenStreetMap'
              ? (properties.accuracy === 'mapped-point'
                ? 'OpenStreetMap mapped point · not independently verified by DCVS'
                : 'Approximate OpenStreetMap feature center')
              : (properties.accuracy === 'verified'
                ? 'Verified mapped location'
                : (properties.accuracy === 'user-provided'
                  ? 'User-provided point · not independently verified by DCVS'
                  : 'Approximate mapped location · verify against the map imagery'))))
      )
    );

    var website = String(properties.website || '');
    if (/^https?:\/\//i.test(website)) {
      var websiteLink = document.createElement('a');
      websiteLink.className = 'dcvs-landmark-popup-link';
      websiteLink.href = website;
      websiteLink.target = '_blank';
      websiteLink.rel = 'noopener noreferrer';
      websiteLink.textContent = 'Visit website';
      popup.appendChild(websiteLink);
    }
    var sourceUrl = String(properties.osm_url || '');
    if (/^https:\/\/www\.openstreetmap\.org\/(node|way|relation)\/\d+$/.test(sourceUrl)) {
      var sourceLink = document.createElement('a');
      sourceLink.className = 'dcvs-landmark-popup-link';
      sourceLink.href = sourceUrl;
      sourceLink.target = '_blank';
      sourceLink.rel = 'noopener noreferrer';
      sourceLink.textContent = 'View this place on OpenStreetMap';
      popup.appendChild(sourceLink);
    } else {
      appendPopupRow(popup, 'Source', properties.source || 'DCVS map records');
    }
    var coordinates = feature.geometry && feature.geometry.coordinates;
    if (!coordinates || coordinates.length !== 2) return;
    appendPopupRow(
      popup,
      'Coordinates',
      Number(coordinates[1]).toFixed(6) + ', ' + Number(coordinates[0]).toFixed(6)
    );
    if (properties.coordinate_source) {
      var googleMapsLink = document.createElement('a');
      googleMapsLink.className = 'dcvs-landmark-popup-link';
      googleMapsLink.href = 'https://www.google.com/maps/search/?api=1&query='
        + encodeURIComponent(coordinates[1] + ',' + coordinates[0]);
      googleMapsLink.target = '_blank';
      googleMapsLink.rel = 'noopener noreferrer';
      googleMapsLink.textContent = 'Open supplied pin in Google Maps';
      popup.appendChild(googleMapsLink);
    }
    new window.maplibregl.Popup({ closeButton: true, closeOnClick: true, maxWidth: '320px' })
      .setLngLat(coordinates)
      .setDOMContent(popup)
      .addTo(map);
    if (event && event.originalEvent) event.originalEvent.stopPropagation();
  }

  function announceReady(container, error) {
    container.dispatchEvent(new CustomEvent('dcvs:landmarksready', {
      detail: { error: error || null }
    }));
  }

  function initialize(container) {
    var session = container.getAttribute('data-map-landmarks-session');
    var isStaffMap = session === 'DCVS_STAFF_SESSION';
    var isBarangayMap = container.getAttribute('data-map-scope') === 'barangay';
    var isReportMap = container.id === 'reportLocationMap';
    var map;

    function addLandmarkLayers() {
      if (!map.getSource(SOURCE_ID)) return;
      var beforeLayer = map.getLayer('report-pins-clusters') ? 'report-pins-clusters' : undefined;
      if (isReportMap && !map.getLayer(CLUSTERS_LAYER_ID)) {
        map.addLayer({
          id: CLUSTERS_LAYER_ID,
          type: 'circle',
          source: SOURCE_ID,
          minzoom: 9.5,
          filter: ['has', 'point_count'],
          layout: { visibility: 'none' },
          paint: {
            'circle-color': '#166534',
            'circle-radius': ['step', ['get', 'point_count'], 15, 20, 19, 50, 23],
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2
          }
        }, beforeLayer);
      }
      if (isReportMap && !map.getLayer(CLUSTER_COUNT_LAYER_ID)) {
        map.addLayer({
          id: CLUSTER_COUNT_LAYER_ID,
          type: 'symbol',
          source: SOURCE_ID,
          minzoom: 9.5,
          filter: ['has', 'point_count'],
          layout: {
            visibility: 'none',
            'text-field': ['get', 'point_count_abbreviated'],
            'text-font': ['Open Sans Bold'],
            'text-size': 11,
            'text-allow-overlap': false
          },
          paint: { 'text-color': '#ffffff' }
        }, beforeLayer);
      }
      var scopeFilters = [];
      if (isStaffMap) scopeFilters.push(['!=', ['get', 'is_dcvs_office'], true]);
      if (isBarangayMap) scopeFilters.push(['!=', ['get', 'is_own_barangay_hall'], true]);
      var commonLayout = {
        'icon-image': CATEGORY_ICONS,
        'icon-size': ['interpolate', ['linear'], ['zoom'], 14, 0.6, 17, 0.9],
        'icon-allow-overlap': false,
        'icon-optional': true,
        'icon-padding': 4,
        'text-field': ['get', 'map_label'],
        'text-size': ['interpolate', ['linear'], ['zoom'], 14, 11, 17, 13],
        'text-font': ['Open Sans Regular'],
        'text-allow-overlap': false,
        'text-variable-anchor': ['top', 'bottom', 'left', 'right'],
        'text-radial-offset': 0.8,
        'text-max-width': 8,
        'text-optional': true,
        'text-padding': 4,
        'symbol-placement': 'point',
        'symbol-sort-key': ['get', 'rank']
      };
      var commonPaint = {
        'text-color': '#26352d',
        'text-halo-color': '#ffffff',
        'text-halo-width': 1.75
      };
      var landmarkMinZoom = 12;
      [
        {
          id: PROMINENT_LAYER_ID,
          minzoom: landmarkMinZoom,
          filter: ['==', ['get', 'prominent'], true]
        },
        {
          id: LABELS_LAYER_ID,
          minzoom: isReportMap ? 13 : landmarkMinZoom,
          filter: ['!=', ['get', 'prominent'], true]
        }
      ].forEach(function(layerConfig) {
        if (map.getLayer(layerConfig.id)) return;
        var pointFilter = isReportMap ? ['!', ['has', 'point_count']] : ['has', 'name'];
        map.addLayer({
          id: layerConfig.id,
          type: 'symbol',
          source: SOURCE_ID,
          minzoom: layerConfig.minzoom,
          filter: ['all', pointFilter, layerConfig.filter].concat(scopeFilters),
          layout: Object.assign({}, commonLayout, { visibility: isReportMap ? 'none' : 'visible' }),
          paint: commonPaint
        }, beforeLayer);
      });

      if (isStaffMap) {
        var officeFilter = ['==', ['get', 'is_dcvs_office'], true];
        if (!map.getLayer(OFFICE_LABEL_LAYER_ID)) {
          map.addLayer({
            id: OFFICE_LABEL_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            filter: officeFilter,
            minzoom: 16,
            layout: Object.assign({}, commonLayout, {
              'icon-image': 'dcvs-poi-icon-government',
              'text-field': ['concat', ['get', 'map_label'], ' · You are here'],
              'text-font': ['Open Sans Bold'],
            }),
            paint: commonPaint
          });
        }
      }
      if (isBarangayMap) {
        var homeHallFilter = ['==', ['get', 'is_own_barangay_hall'], true];
        if (!map.getLayer(HOME_HALL_LABEL_LAYER_ID)) {
          map.addLayer({
            id: HOME_HALL_LABEL_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            filter: homeHallFilter,
            minzoom: 14,
            layout: Object.assign({}, commonLayout, {
              'icon-image': 'dcvs-poi-icon-government',
              'text-field': ['get', 'map_label'],
              'text-font': ['Open Sans Bold'],
            }),
            paint: commonPaint
          });
        }
      }
    }

    function bindMapInteractions() {
      var clickableLayers = [PROMINENT_LAYER_ID, LABELS_LAYER_ID];
      if (isStaffMap) clickableLayers.push(OFFICE_LABEL_LAYER_ID);
      if (isBarangayMap) clickableLayers.push(HOME_HALL_LABEL_LAYER_ID);
      clickableLayers.forEach(function(layerId) {
        map.on('click', layerId, function(event) {
          if (event.features && event.features.length) {
            showFeaturePopup(map, event.features[0], event);
          }
        });
        map.on('mouseenter', layerId, function() {
          map.getCanvas().style.cursor = 'pointer';
        });
        map.on('mouseleave', layerId, function() {
          map.getCanvas().style.cursor = '';
        });
      });
      if (isReportMap) {
        map.on('click', CLUSTERS_LAYER_ID, function(event) {
          var feature = event.features && event.features[0];
          var source = map.getSource(SOURCE_ID);
          if (!feature || !source || typeof source.getClusterExpansionZoom !== 'function') return;
          source.getClusterExpansionZoom(feature.properties.cluster_id, function(error, zoom) {
            if (error) {
              console.error('Could not expand the place cluster:', error);
              return;
            }
            map.easeTo({ center: feature.geometry.coordinates, zoom: zoom });
          });
        });
        map.on('mouseenter', CLUSTERS_LAYER_ID, function() { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', CLUSTERS_LAYER_ID, function() { map.getCanvas().style.cursor = ''; });
      }
    }

    container.addEventListener('mapcn:load', function(event) {
      map = event.detail && event.detail.map;
      if (!map) {
        announceReady(container, new Error('Map instance was not available.'));
        return;
      }

      map.on('style.load', function() {
        registerCategoryIcons(map).catch(function(error) {
          console.error('Could not restore POI category icons after changing basemap:', error);
        });
      });
      container.addEventListener('dcvs:basemapstyleload', function(event) {
        setLandmarkLabelPalette(map, event.detail && event.detail.style);
      });
      map.on('styleimagemissing', function(event) {
        var prefix = 'dcvs-poi-icon-';
        if (event.id.indexOf(prefix) !== 0) return;
        var category = event.id.slice(prefix.length);
        if (!CATEGORY_META[category]) return;
        loadCategoryIcon(category).then(function(image) {
          if (!map.hasImage(event.id)) map.addImage(event.id, image, { pixelRatio: 2 });
        }).catch(function(error) {
          console.error('Could not load a POI category icon:', error);
        });
      });

      registerCategoryIcons(map).then(function() {
        return fetch(appUrl('geo-map/landmarks.php?sess=' + encodeURIComponent(session)), {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/geo+json' }
        });
      }).then(function(response) {
        if (!response.ok) throw new Error('Landmarks request failed with HTTP ' + response.status + '.');
        return response.json();
      }).then(function(geojson) {
        if (!geojson || geojson.type !== 'FeatureCollection' || !Array.isArray(geojson.features)) {
          throw new Error('The landmarks response is invalid.');
        }
        setPlacePriority(geojson.features);
        if (!map.getSource(SOURCE_ID)) {
          var sourceOptions = { type: 'geojson', data: geojson };
          if (isReportMap) {
            sourceOptions.cluster = true;
            sourceOptions.clusterRadius = 50;
            sourceOptions.clusterMaxZoom = 12;
          }
          map.addSource(SOURCE_ID, sourceOptions);
        }
        addLandmarkLayers();
        setLandmarkLabelPalette(map, container.getAttribute('data-initial-basemap') || 'street');
        window.DCVSMapLandmarks.features = geojson.features;
        buildFilterControls(container, map, geojson.features, isStaffMap, isBarangayMap, isReportMap);
        bindMapInteractions();
        if (isReportMap) addAttribution(container);
        announceReady(container);
      }).catch(function(error) {
        console.error('Could not load authorized map landmarks:', error);
        var status = container.querySelector('[data-basemap-status]');
        if (status) status.textContent = 'Landmark locations could not be loaded. The map is still available.';
        announceReady(container, error);
      });
    }, { once: true });
  }

  document.querySelectorAll('[data-map-landmarks-session]').forEach(initialize);
})();
