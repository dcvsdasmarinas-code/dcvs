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
  var POINTS_LAYER_ID = 'dcvs-landmark-points';
  var CODES_LAYER_ID = 'dcvs-landmark-codes';
  var LABELS_LAYER_ID = 'dcvs-landmark-labels';
  var OFFICE_HALO_LAYER_ID = 'dcvs-office-halo';
  var OFFICE_MARKER_LAYER_ID = 'dcvs-office-marker';
  var OFFICE_CODE_LAYER_ID = 'dcvs-office-code';
  var OFFICE_LABEL_LAYER_ID = 'dcvs-office-label';
  var HOME_HALL_HALO_LAYER_ID = 'dcvs-home-hall-halo';
  var HOME_HALL_MARKER_LAYER_ID = 'dcvs-home-hall-marker';
  var HOME_HALL_CODE_LAYER_ID = 'dcvs-home-hall-code';
  var HOME_HALL_LABEL_LAYER_ID = 'dcvs-home-hall-label';
  var CATEGORY_META = {
    barangay_hall: { color: '#2563eb', label: 'Barangay hall', code: 'H' },
    school: { color: '#7c3aed', label: 'School', code: 'S' },
    chapel: { color: '#a855f7', label: 'Chapel', code: 'C' },
    market: { color: '#ea580c', label: 'Market', code: 'M' },
    subdivision: { color: '#0891b2', label: 'Subdivision', code: 'D' },
    hospital: { color: '#dc2626', label: 'Hospital', code: '+' },
    park: { color: '#16a34a', label: 'Park', code: 'P' },
    other: { color: '#475569', label: 'Other', code: 'O' }
  };
  var CATEGORY_COLORS = [
    'match', ['get', 'category'],
    'barangay_hall', '#2563eb',
    'school', '#7c3aed',
    'chapel', '#a855f7',
    'market', '#ea580c',
    'subdivision', '#0891b2',
    'hospital', '#dc2626',
    'park', '#16a34a',
    'other', '#475569',
    '#475569'
  ];
  var CATEGORY_CODES = [
    'match', ['get', 'category'],
    'barangay_hall', 'H',
    'school', 'S',
    'chapel', 'C',
    'market', 'M',
    'subdivision', 'D',
    'hospital', '+',
    'park', 'P',
    'other', 'O',
    'O'
  ];

  function addLegend(container, features, showOffice, showHomeHall) {
    var categories = {};
    features.forEach(function(feature) {
      var category = feature.properties && feature.properties.category;
      if (CATEGORY_META[category]) categories[category] = true;
    });

    var legend = document.createElement('div');
    legend.className = 'dcvs-landmark-legend';
    legend.setAttribute('role', 'note');
    legend.setAttribute('aria-label', 'Landmark categories shown on the map');
    Object.keys(CATEGORY_META).forEach(function(category) {
      if (!categories[category]) return;
      var item = document.createElement('span');
      item.className = 'dcvs-landmark-legend-item';
      var marker = document.createElement('span');
      marker.className = 'dcvs-landmark-legend-marker';
      marker.style.backgroundColor = CATEGORY_META[category].color;
      marker.textContent = CATEGORY_META[category].code;
      var label = document.createElement('span');
      label.textContent = CATEGORY_META[category].label;
      item.appendChild(marker);
      item.appendChild(label);
      legend.appendChild(item);
    });
    if (showOffice) {
      var officeItem = document.createElement('span');
      officeItem.className = 'dcvs-landmark-legend-item';
      var officeMarker = document.createElement('span');
      officeMarker.className = 'dcvs-landmark-legend-marker dcvs-landmark-legend-marker--office';
      officeMarker.textContent = 'D';
      var officeLabel = document.createElement('span');
      officeLabel.textContent = 'DCVS Office (approximate)';
      officeItem.appendChild(officeMarker);
      officeItem.appendChild(officeLabel);
      legend.appendChild(officeItem);
    }
    if (showHomeHall) {
      var homeHallItem = document.createElement('span');
      homeHallItem.className = 'dcvs-landmark-legend-item';
      var homeHallMarker = document.createElement('span');
      homeHallMarker.className = 'dcvs-landmark-legend-marker dcvs-landmark-legend-marker--home';
      homeHallMarker.textContent = 'H';
      var homeHallLabel = document.createElement('span');
      homeHallLabel.textContent = 'Your Barangay Hall';
      homeHallItem.appendChild(homeHallMarker);
      homeHallItem.appendChild(homeHallLabel);
      legend.appendChild(homeHallItem);
    }
    if (legend.childNodes.length) container.appendChild(legend);
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
    var map;

    function addLandmarkLayers() {
      if (!map.getSource(SOURCE_ID)) return;
      if (!map.getLayer(POINTS_LAYER_ID)) {
        var beforeLayer = map.getLayer('report-pins-clusters') ? 'report-pins-clusters' : undefined;
        var pointsLayer = {
          id: POINTS_LAYER_ID,
          type: 'circle',
          source: SOURCE_ID,
          paint: {
            'circle-color': CATEGORY_COLORS,
            'circle-radius': ['interpolate', ['linear'], ['zoom'], 8, 3, 15, 7],
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 1.5
          }
        };
        if (isStaffMap) pointsLayer.filter = ['!=', ['get', 'is_dcvs_office'], true];
        if (isBarangayMap) pointsLayer.filter = ['!=', ['get', 'is_own_barangay_hall'], true];
        map.addLayer(pointsLayer, beforeLayer);
      }
      if (!map.getLayer(CODES_LAYER_ID)) {
        var codeBeforeLayer = map.getLayer('report-pins-clusters') ? 'report-pins-clusters' : undefined;
        var codesLayer = {
          id: CODES_LAYER_ID,
          type: 'symbol',
          source: SOURCE_ID,
          layout: {
            'text-field': CATEGORY_CODES,
            'text-size': 9,
            'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
            'text-allow-overlap': true
          },
          paint: { 'text-color': '#ffffff' }
        };
        if (isStaffMap) codesLayer.filter = ['!=', ['get', 'is_dcvs_office'], true];
        if (isBarangayMap) codesLayer.filter = ['!=', ['get', 'is_own_barangay_hall'], true];
        map.addLayer(codesLayer, codeBeforeLayer);
      }
      if (!map.getLayer(LABELS_LAYER_ID)) {
        var labelBeforeLayer = map.getLayer('report-pins-clusters') ? 'report-pins-clusters' : undefined;
        var labelsLayer = {
          id: LABELS_LAYER_ID,
          type: 'symbol',
          source: SOURCE_ID,
          minzoom: 13,
          layout: {
            'text-field': ['get', 'name'],
            'text-size': ['interpolate', ['linear'], ['zoom'], 13, 10, 16, 12],
            'text-font': ['Open Sans Semibold', 'Arial Unicode MS Regular'],
            'text-anchor': 'left',
            'text-offset': [0.8, 0],
            'text-max-width': 14,
            'text-optional': true,
            'symbol-placement': 'point'
          },
          paint: {
            'text-color': '#172033',
            'text-halo-color': '#ffffff',
            'text-halo-width': 1.5
          }
        };
        if (isStaffMap) labelsLayer.filter = ['!=', ['get', 'is_dcvs_office'], true];
        if (isBarangayMap) labelsLayer.filter = ['!=', ['get', 'is_own_barangay_hall'], true];
        map.addLayer(labelsLayer, labelBeforeLayer);
      }
      if (isStaffMap) {
        var officeFilter = ['==', ['get', 'is_dcvs_office'], true];
        if (!map.getLayer(OFFICE_HALO_LAYER_ID)) {
          map.addLayer({
            id: OFFICE_HALO_LAYER_ID,
            type: 'circle',
            source: SOURCE_ID,
            filter: officeFilter,
            paint: {
              'circle-radius': 20,
              'circle-color': '#0E8131',
              'circle-opacity': 0.2
            }
          });
        }
        if (!map.getLayer(OFFICE_MARKER_LAYER_ID)) {
          map.addLayer({
            id: OFFICE_MARKER_LAYER_ID,
            type: 'circle',
            source: SOURCE_ID,
            filter: officeFilter,
            paint: {
              'circle-radius': 13,
              'circle-color': '#0E8131',
              'circle-stroke-color': '#ffffff',
              'circle-stroke-width': 3
            }
          });
        }
        if (!map.getLayer(OFFICE_CODE_LAYER_ID)) {
          map.addLayer({
            id: OFFICE_CODE_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            filter: officeFilter,
            layout: {
              'text-field': 'D',
              'text-size': 11,
              'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
              'text-allow-overlap': true
            },
            paint: { 'text-color': '#ffffff' }
          });
        }
        if (!map.getLayer(OFFICE_LABEL_LAYER_ID)) {
          map.addLayer({
            id: OFFICE_LABEL_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            filter: officeFilter,
            layout: {
              'text-field': 'DCVS Office · You are here',
              'text-size': 12,
              'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
              'text-anchor': 'left',
              'text-offset': [1.5, 0],
              'text-allow-overlap': true
            },
            paint: {
              'text-color': '#166534',
              'text-halo-color': '#ffffff',
              'text-halo-width': 2
            }
          });
        }
      }
      if (isBarangayMap) {
        var homeHallFilter = ['==', ['get', 'is_own_barangay_hall'], true];
        if (!map.getLayer(HOME_HALL_HALO_LAYER_ID)) {
          map.addLayer({
            id: HOME_HALL_HALO_LAYER_ID,
            type: 'circle',
            source: SOURCE_ID,
            filter: homeHallFilter,
            paint: {
              'circle-radius': 20,
              'circle-color': '#2563eb',
              'circle-opacity': 0.2
            }
          });
        }
        if (!map.getLayer(HOME_HALL_MARKER_LAYER_ID)) {
          map.addLayer({
            id: HOME_HALL_MARKER_LAYER_ID,
            type: 'circle',
            source: SOURCE_ID,
            filter: homeHallFilter,
            paint: {
              'circle-radius': 13,
              'circle-color': '#2563eb',
              'circle-stroke-color': '#ffffff',
              'circle-stroke-width': 3
            }
          });
        }
        if (!map.getLayer(HOME_HALL_CODE_LAYER_ID)) {
          map.addLayer({
            id: HOME_HALL_CODE_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            filter: homeHallFilter,
            layout: {
              'text-field': 'H',
              'text-size': 11,
              'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
              'text-allow-overlap': true
            },
            paint: { 'text-color': '#ffffff' }
          });
        }
        if (!map.getLayer(HOME_HALL_LABEL_LAYER_ID)) {
          map.addLayer({
            id: HOME_HALL_LABEL_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            filter: homeHallFilter,
            layout: {
              'text-field': ['concat', ['get', 'barangay'], ' Hall · You are here'],
              'text-size': 12,
              'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
              'text-anchor': 'left',
              'text-offset': [1.5, 0],
              'text-allow-overlap': true
            },
            paint: {
              'text-color': '#1d4ed8',
              'text-halo-color': '#ffffff',
              'text-halo-width': 2
            }
          });
        }
      }
    }

    container.addEventListener('mapcn:load', function(event) {
      map = event.detail && event.detail.map;
      if (!map) {
        announceReady(container, new Error('Map instance was not available.'));
        return;
      }

      fetch(appUrl('geo-map/landmarks.php?sess=' + encodeURIComponent(session)), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/geo+json' }
      }).then(function(response) {
        if (!response.ok) throw new Error('Landmarks request failed with HTTP ' + response.status + '.');
        return response.json();
      }).then(function(geojson) {
        if (!geojson || geojson.type !== 'FeatureCollection' || !Array.isArray(geojson.features)) {
          throw new Error('The landmarks response is invalid.');
        }
        if (!map.getSource(SOURCE_ID)) {
          map.addSource(SOURCE_ID, { type: 'geojson', data: geojson });
        }
        addLandmarkLayers();
        var hasOffice = geojson.features.some(function(feature) {
          return feature.properties && feature.properties.is_dcvs_office === true;
        });
        var hasHomeHall = geojson.features.some(function(feature) {
          return feature.properties && feature.properties.is_own_barangay_hall === true;
        });
        addLegend(container, geojson.features, isStaffMap && hasOffice, isBarangayMap && hasHomeHall);
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
