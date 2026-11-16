(function() {
  'use strict';

  var mapElement = document.getElementById('geoMap');
  if (!mapElement) return;

  var map = null;
  var refreshTimer = null;
  var detailCard = null;
  var latestPinData = null;
  var interactionsRegistered = false;
  var STATUS_COLORS = [
    'match', ['get', 'status'],
    'pending', '#eab308',
    'validated', '#3b82f6',
    'confirmed', '#22c55e',
    'scheduled', '#8b5cf6',
    'resolved', '#64748b',
    '#0f766e'
  ];

  function closeDetail() {
    if (detailCard && detailCard.parentNode) detailCard.remove();
    detailCard = null;
  }

  function addLayers() {
    if (!map || !map.getSource('public-report-pins')) return false;
    if (!map.getLayer('public-report-pins-clusters')) {
      map.addLayer({
        id: 'public-report-pins-clusters',
        type: 'circle',
        source: 'public-report-pins',
        filter: ['has', 'point_count'],
        paint: {
          'circle-color': '#166534',
          'circle-radius': ['step', ['get', 'point_count'], 17, 10, 21, 50, 26],
          'circle-stroke-color': '#ffffff',
          'circle-stroke-width': 2
        }
      });
    }
    if (!map.getLayer('public-report-pins-cluster-count')) {
      map.addLayer({
        id: 'public-report-pins-cluster-count',
        type: 'symbol',
        source: 'public-report-pins',
        filter: ['has', 'point_count'],
        layout: {
          'text-field': ['get', 'point_count_abbreviated'],
          'text-size': 12,
          'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold']
        },
        paint: { 'text-color': '#ffffff' }
      });
    }
    if (!map.getLayer('public-report-pins-points')) {
      map.addLayer({
        id: 'public-report-pins-points',
        type: 'circle',
        source: 'public-report-pins',
        filter: ['!', ['has', 'point_count']],
        paint: {
          'circle-radius': ['interpolate', ['linear'], ['zoom'], 8, 5, 15, 8],
          'circle-color': STATUS_COLORS,
          'circle-stroke-color': '#ffffff',
          'circle-stroke-width': 2
        }
      });
    }
    registerInteractions();
    return true;
  }

  function setSourceData(data) {
    latestPinData = data;
    installSourceData(true);
  }

  function installSourceData(updateExistingSource) {
    if (!map || !latestPinData || !map.isStyleLoaded()) return;
    var source = map && map.getSource('public-report-pins');
    if (source) {
      if (updateExistingSource) source.setData(latestPinData);
    } else {
      map.addSource('public-report-pins', {
        type: 'geojson',
        data: latestPinData,
        cluster: true,
        clusterRadius: 48,
        clusterMaxZoom: 14
      });
    }
    addLayers();
  }

  function loadPins() {
    return fetch('geo-map/report-pins-public.php', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/geo+json' }
    }).then(function(response) {
      return response.json().then(function(data) {
        if (!response.ok) throw new Error(data.error || 'Public report pins request failed.');
        if (!data || data.type !== 'FeatureCollection' || !Array.isArray(data.features)) {
          throw new Error('Public report pins response is invalid.');
        }
        setSourceData(data);
      });
    }).catch(function(error) {
      console.error('Could not load public report pins:', error);
    });
  }

  function showDetail(feature, point) {
    closeDetail();
    var properties = feature.properties || {};
    var card = document.createElement('section');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', 'Public report summary');
    card.style.cssText = 'position:absolute;z-index:20;max-width:260px;padding:13px 15px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.18);font:13px/1.5 Inter,Segoe UI,sans-serif;color:#0f172a;';
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close report summary');
    close.textContent = '×';
    close.style.cssText = 'float:right;border:0;background:transparent;color:#64748b;font-size:20px;line-height:1;cursor:pointer;';
    close.addEventListener('click', closeDetail);
    var title = document.createElement('strong');
    title.textContent = 'Filed report';
    var status = document.createElement('div');
    status.textContent = 'Status: ' + formatLabel(properties.status);
    var category = document.createElement('div');
    category.textContent = 'Category: ' + formatLabel(properties.type);
    var barangay = document.createElement('div');
    barangay.textContent = 'Barangay: ' + String(properties.barangay || 'Unknown');
    card.appendChild(close);
    card.appendChild(title);
    card.appendChild(status);
    card.appendChild(category);
    card.appendChild(barangay);
    mapElement.appendChild(card);
    var containerRect = mapElement.getBoundingClientRect();
    var projected = map.project(point);
    card.style.left = Math.max(8, Math.min(projected.x + 12, containerRect.width - 270)) + 'px';
    card.style.top = Math.max(8, Math.min(projected.y + 12, containerRect.height - 125)) + 'px';
    detailCard = card;
  }

  function formatLabel(value) {
    return String(value || 'Unknown').replace(/[_-]+/g, ' ')
      .replace(/\b\w/g, function(character) { return character.toUpperCase(); });
  }

  function registerInteractions() {
    if (interactionsRegistered || !map.getLayer('public-report-pins-points')) return;
    interactionsRegistered = true;
    map.on('click', 'public-report-pins-clusters', function(event) {
      var feature = event.features && event.features[0];
      if (!feature) return;
      var source = map.getSource('public-report-pins');
      source.getClusterExpansionZoom(feature.properties.cluster_id, function(error, zoom) {
        if (error) {
          console.error('Could not expand public report pin cluster:', error);
          return;
        }
        map.easeTo({ center: feature.geometry.coordinates, zoom: zoom });
      });
    });
    map.on('click', 'public-report-pins-points', function(event) {
      var feature = event.features && event.features[0];
      if (feature) showDetail(feature, event.lngLat);
    });
    ['public-report-pins-clusters', 'public-report-pins-points'].forEach(function(layerId) {
      map.on('mouseenter', layerId, function() { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', layerId, function() { map.getCanvas().style.cursor = ''; });
    });
    map.on('movestart', closeDetail);
  }

  function initialize(eventMap) {
    if (!eventMap || map) return;
    map = eventMap;
    function start() {
      loadPins();
      refreshTimer = window.setInterval(loadPins, 30000);
      map.on('styledata', function() {
        if (map.isStyleLoaded()) {
          installSourceData(false);
          addLayers();
        }
      });
    }
    if (map.loaded()) start();
    else map.once('load', start);
    return true;
  }

  mapElement.addEventListener('mapcn:load', function(event) {
    initialize(event.detail && event.detail.map);
  });
  var attempts = 0;
  var readyTimer = window.setInterval(function() {
    if (initialize(window.MapCN && window.MapCN.map) || attempts++ > 100) {
      window.clearInterval(readyTimer);
    }
  }, 200);
  window.addEventListener('beforeunload', function() {
    if (refreshTimer) window.clearInterval(refreshTimer);
    window.clearInterval(readyTimer);
  });
})();
