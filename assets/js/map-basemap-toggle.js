(function() {
  'use strict';

  var appBaseUrl = typeof window.DCVS_APP_BASE_URL === 'string' ? window.DCVS_APP_BASE_URL : '';
  if (typeof window.DCVS_APP_BASE_URL !== 'string' && document.currentScript && document.currentScript.src) {
    appBaseUrl = new URL('../../', document.currentScript.src).pathname.replace(/\/$/, '');
  }
  function appUrl(path) {
    return appBaseUrl + '/' + path.replace(/^\/+/, '');
  }

  var STREET_STYLE = 'https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json';
  var SATELLITE_STYLE = 'https://api.maptiler.com/maps/hybrid/style.json?key=';
  var CUSTOM_SOURCE_IDS = [
    'barangays',
    'brgy-labels',
    'report-pins',
    'report-pin-accuracy',
    'public-report-pins',
    'dcvs-landmarks',
    'dcvs-city-perimeter',
    'dcvs-city-mask',
    'dcvs-city-boundary',
    'dcvs-barangay-borders',
    'dcvs-city-interior-masks'
  ];
  var CUSTOM_LAYER_IDS = [
    'barangay-fill',
    'barangay-outline',
    'barangay-label',
    'geo-brgy-label',
    'report-pin-accuracy-fill',
    'report-pin-accuracy-outline',
    'dcvs-city-perimeter-casing',
    'dcvs-city-perimeter-outline',
    'dcvs-city-context-mask',
    'dcvs-city-interior-mask',
    'dcvs-barangay-boundary-casing',
    'dcvs-barangay-boundary-highlight',
    'report-pins-clusters',
    'report-pins-cluster-count',
    'report-pins-points',
    'public-report-pins-clusters',
    'public-report-pins-cluster-count',
    'public-report-pins-points',
    'dcvs-landmark-points',
    'dcvs-landmark-codes',
    'dcvs-landmark-labels',
    'dcvs-office-halo',
    'dcvs-office-marker',
    'dcvs-office-code',
    'dcvs-office-label',
    'dcvs-home-hall-halo',
    'dcvs-home-hall-marker',
    'dcvs-home-hall-code',
    'dcvs-home-hall-label'
  ];
  var SATELLITE_ONLY_SOURCE_IDS = [
    'dcvs-city-mask',
    'dcvs-city-boundary',
    'dcvs-barangay-borders',
    'dcvs-city-interior-masks'
  ];
  var SATELLITE_ONLY_LAYER_IDS = [
    'dcvs-city-context-mask',
    'dcvs-city-interior-mask',
    'dcvs-barangay-boundary-casing',
    'dcvs-barangay-boundary-highlight'
  ];
  var DEFAULT_MAP_BOUNDS = [[120.78, 14.17], [121.10, 14.49]];
  var CITY_MASK_EXTENT = [[90, -60], [150, 60]];
  var MIN_MAP_ZOOM = 7;
  var MAX_MAP_ZOOM = 20;
  var PRIMARY_GREEN = '#0E8131';

  function initialize(mapElement) {
    var key = mapElement.getAttribute('data-maptiler-key');

    var map = null;
    var status = mapElement.querySelector('[data-basemap-status]');
    var selectedStyle = 'street';
    var isChangingStyle = false;
    var reportPinsReady = !mapElement.hasAttribute('data-report-pins-session');
    var landmarksReady = !mapElement.hasAttribute('data-map-landmarks-session');
    var overlaysReady = false;
    var baseLayerDefinitions = {};
    var isBarangayMap = mapElement.getAttribute('data-map-scope') === 'barangay';
    var hasRiskOverlay = mapElement.getAttribute('data-map-risk-overlay') === 'true';
    var hasCityBoundary = mapElement.getAttribute('data-city-boundary') === 'true';
    var scopedBarangayFeature = null;
    var searchedBarangayId = null;
    var initialMaxBounds = null;
    var streetButton = null;
    var satelliteButton = null;
    var attribution = null;

    function setStatus(message) {
      if (status) status.textContent = message;
    }

    function updateControls() {
      if (streetButton) streetButton.setAttribute('aria-pressed', selectedStyle === 'street' ? 'true' : 'false');
      if (satelliteButton) {
        satelliteButton.setAttribute('aria-pressed', selectedStyle === 'satellite' ? 'true' : 'false');
        satelliteButton.disabled = !key || !overlaysReady || isChangingStyle;
      }
      if (streetButton) streetButton.disabled = isChangingStyle || selectedStyle === 'street';
      if (attribution) {
        attribution.textContent = '';
        var links = selectedStyle === 'satellite'
          ? [
              { label: '© MapTiler', href: 'https://www.maptiler.com/copyright/' },
              { label: '© OpenStreetMap contributors', href: 'https://www.openstreetmap.org/copyright' }
            ]
          : [
              { label: '© OpenStreetMap contributors', href: 'https://www.openstreetmap.org/copyright' },
              { label: '© CARTO', href: 'https://carto.com/attributions' }
            ];
        links.forEach(function(item, index) {
          if (index) attribution.appendChild(document.createTextNode(' · '));
          var link = document.createElement('a');
          link.href = item.href;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.textContent = item.label;
          attribution.appendChild(link);
        });
      }
    }

    function createControls() {
      var controls = document.createElement('div');
      controls.className = 'dcvs-basemap-toggle';
      controls.setAttribute('role', 'group');
      controls.setAttribute('aria-label', 'Map style');
      streetButton = document.createElement('button');
      streetButton.type = 'button';
      streetButton.textContent = 'Street map';
      streetButton.addEventListener('click', loadStreetStyle);
      controls.appendChild(streetButton);
      satelliteButton = document.createElement('button');
      satelliteButton.type = 'button';
      satelliteButton.textContent = 'Satellite';
      satelliteButton.title = key ? 'Show satellite imagery' : 'Satellite imagery requires a MapTiler key';
      satelliteButton.addEventListener('click', loadSatelliteStyle);
      controls.appendChild(satelliteButton);
      attribution = document.createElement('div');
      attribution.className = 'dcvs-map-attribution';
      attribution.setAttribute('aria-label', 'Map attribution');
      mapElement.appendChild(controls);
      mapElement.appendChild(attribution);
      updateControls();
    }

    function captureOverlays() {
      var currentStyle = map.getStyle();
      var sources = {};
      var layers = [];
      CUSTOM_SOURCE_IDS.forEach(function(id) {
        if (SATELLITE_ONLY_SOURCE_IDS.indexOf(id) === -1
            && currentStyle.sources && currentStyle.sources[id]) sources[id] = currentStyle.sources[id];
      });
      (currentStyle.layers || []).forEach(function(layer) {
        if (CUSTOM_LAYER_IDS.indexOf(layer.id) === -1
            || SATELLITE_ONLY_LAYER_IDS.indexOf(layer.id) !== -1) return;
        layers.push(baseLayerDefinitions[layer.id] || layer);
      });
      return { sources: sources, layers: layers };
    }

    function restoreOverlays(snapshot) {
      CUSTOM_SOURCE_IDS.forEach(function(id) {
        if (!snapshot.sources[id] || map.getSource(id)) return;
        map.addSource(id, snapshot.sources[id]);
      });
      snapshot.layers.forEach(function(layer) {
        if (!map.getLayer(layer.id)) map.addLayer(layer);
      });
    }

    function setPaintPropertyIfChanged(layerId, property, value) {
      if (!map.getLayer(layerId)) return;
      if (JSON.stringify(map.getPaintProperty(layerId, property)) !== JSON.stringify(value)) {
        map.setPaintProperty(layerId, property, value);
      }
    }

    function applyRiskMapStyle() {
      if (!map) return;
      if (hasRiskOverlay && map.getLayer('barangay-fill')) {
        var riskFillColor = [
          'match', ['get', 'risk'],
          'Green', '#22c55e',
          'Yellow', '#eab308',
          'Orange', '#f97316',
          'Red', '#ef4444',
          '#22c55e'
        ];
        var fillColor = searchedBarangayId === null
          ? riskFillColor
          : [
              'case',
              ['==', ['to-number', ['get', 'id']], searchedBarangayId],
              riskFillColor,
              '#cbd5e1'
            ];
        var fillOpacity = searchedBarangayId === null
          ? (selectedStyle === 'satellite' ? 0.3 : 0.4)
          : [
              'case',
              ['==', ['to-number', ['get', 'id']], searchedBarangayId],
              selectedStyle === 'satellite' ? 0.48 : 0.72,
              0.42
            ];
        setPaintPropertyIfChanged('barangay-fill', 'fill-color', fillColor);
        setPaintPropertyIfChanged('barangay-fill', 'fill-opacity', fillOpacity);
      }
      if (map.getLayer('barangay-outline')) {
        var lineColor = selectedStyle === 'satellite' ? '#ffffff' : PRIMARY_GREEN;
        var lineWidth = 1.5;
        var lineOpacity = 0.95;
        if (searchedBarangayId !== null) {
          var isSearchedFeature = ['==', ['to-number', ['get', 'id']], searchedBarangayId];
          lineColor = ['case', isSearchedFeature, PRIMARY_GREEN, '#ffffff'];
          lineWidth = ['case', isSearchedFeature, 4, 1];
          lineOpacity = ['case', isSearchedFeature, 1, 0.55];
        }
        setPaintPropertyIfChanged('barangay-outline', 'line-color', lineColor);
        setPaintPropertyIfChanged('barangay-outline', 'line-width', lineWidth);
        setPaintPropertyIfChanged('barangay-outline', 'line-opacity', lineOpacity);
      }
    }

    function applyScopedBarangay() {
      if (!isBarangayMap || !scopedBarangayFeature || !map) return;
      var source = map.getSource('barangays');
      if (source) {
        source.setData({ type: 'FeatureCollection', features: [scopedBarangayFeature] });
      }
      applyRiskMapStyle();
      if (selectedStyle !== 'satellite' || !map.getSource('dcvs-city-mask')) {
        var bounds = featureBounds(scopedBarangayFeature);
        var widthPadding = Math.max((bounds[1][0] - bounds[0][0]) * 0.15, 0.008);
        var heightPadding = Math.max((bounds[1][1] - bounds[0][1]) * 0.15, 0.008);
        map.setMaxBounds([
          [bounds[0][0] - widthPadding, bounds[0][1] - heightPadding],
          [bounds[1][0] + widthPadding, bounds[1][1] + heightPadding]
        ]);
      }
    }

    function ensureCityBoundaryOutline(boundaries) {
      if (!hasCityBoundary || !map) return;
      if (!map.getSource('dcvs-city-perimeter')) {
        var cityBoundary = createCityBoundary(boundaries);
        map.addSource('dcvs-city-perimeter', {
          type: 'geojson',
          data: {
            type: 'Feature',
            properties: {},
            geometry: { type: 'MultiLineString', coordinates: cityBoundary.rings }
          }
        });
      }
      addLayerIfMissing({
        id: 'dcvs-city-perimeter-casing',
        type: 'line',
        source: 'dcvs-city-perimeter',
        layout: { 'line-join': 'round' },
        paint: { 'line-color': '#ffffff', 'line-width': 5, 'line-opacity': 0.9 }
      });
      addLayerIfMissing({
        id: 'dcvs-city-perimeter-outline',
        type: 'line',
        source: 'dcvs-city-perimeter',
        layout: { 'line-join': 'round' },
        paint: { 'line-color': PRIMARY_GREEN, 'line-width': 3, 'line-opacity': 1 }
      });
    }

    function loadCityBoundaryOutline() {
      if (!hasCityBoundary) return Promise.resolve();
      return fetch(appUrl('geo-map/barangays-boundary.json'), { credentials: 'same-origin' })
        .then(function(response) {
          if (!response.ok) throw new Error('Barangay boundary request failed with HTTP ' + response.status + '.');
          return response.json();
        })
        .then(function(boundaries) {
          ensureCityBoundaryOutline(boundaries);
        });
    }

    function coordinateKey(point) {
      return Number(point[0]).toFixed(7) + ',' + Number(point[1]).toFixed(7);
    }

    function signedArea(ring) {
      var area = 0;
      for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        area += (ring[j][0] * ring[i][1]) - (ring[i][0] * ring[j][1]);
      }
      return area / 2;
    }

    function createCityBoundary(geojson) {
      if (!geojson || geojson.type !== 'FeatureCollection'
          || !Array.isArray(geojson.features) || geojson.features.length !== 75) {
        throw new Error('The official set of 75 barangay boundaries is unavailable.');
      }

      var edges = new Map();
      geojson.features.forEach(function(feature) {
        var geometry = feature && feature.geometry;
        if (!geometry || geometry.type !== 'Polygon' || !Array.isArray(geometry.coordinates)) {
          throw new Error('A barangay boundary has an unsupported geometry.');
        }
        var outerRing = geometry.coordinates[0];
        if (!Array.isArray(outerRing) || outerRing.length < 4) {
          throw new Error('A barangay boundary is incomplete.');
        }
        for (var i = 1; i < outerRing.length; i++) {
          var start = outerRing[i - 1];
          var end = outerRing[i];
          var startKey = coordinateKey(start);
          var endKey = coordinateKey(end);
          var edgeKey = startKey < endKey ? startKey + '|' + endKey : endKey + '|' + startKey;
          var edge = edges.get(edgeKey);
          if (edge) {
            edge.count++;
          } else {
            edges.set(edgeKey, { count: 1, start: start, end: end });
          }
        }
      });

      var exterior = [];
      edges.forEach(function(edge) {
        if (edge.count === 1) exterior.push(edge);
      });
      var adjacency = new Map();
      exterior.forEach(function(edge, index) {
        [edge.start, edge.end].forEach(function(point) {
          var key = coordinateKey(point);
          if (!adjacency.has(key)) adjacency.set(key, []);
          adjacency.get(key).push(index);
        });
      });
      adjacency.forEach(function(incidentEdges) {
        if (incidentEdges.length !== 2) {
          throw new Error('The barangay boundaries do not form a continuous city perimeter.');
        }
      });

      var rings = [];
      var used = new Set();
      for (var startEdgeIndex = 0; startEdgeIndex < exterior.length; startEdgeIndex++) {
        if (used.has(startEdgeIndex)) continue;
        var firstEdge = exterior[startEdgeIndex];
        var ring = [firstEdge.start, firstEdge.end];
        var currentPoint = firstEdge.end;
        var currentKey = coordinateKey(currentPoint);
        var previousEdgeIndex = startEdgeIndex;
        used.add(startEdgeIndex);

        while (currentKey !== coordinateKey(ring[0])) {
          var incident = adjacency.get(currentKey) || [];
          var nextEdgeIndex = incident[0] === previousEdgeIndex ? incident[1] : incident[0];
          if (used.has(nextEdgeIndex)) throw new Error('A Dasmarinas city perimeter is not a closed ring.');
          used.add(nextEdgeIndex);
          previousEdgeIndex = nextEdgeIndex;
          var nextEdge = exterior[nextEdgeIndex];
          currentPoint = coordinateKey(nextEdge.start) === currentKey ? nextEdge.end : nextEdge.start;
          ring.push(currentPoint);
          currentKey = coordinateKey(currentPoint);
          if (used.size > exterior.length) throw new Error('The Dasmarinas city perimeter could not be closed.');
        }

        ring[ring.length - 1] = ring[0].slice();
        if (signedArea(ring) > 0) ring.reverse();
        rings.push(ring);
      }

      if (!rings.length || used.size !== exterior.length) {
        throw new Error('The Dasmarinas city perimeter could not be built.');
      }
      rings.sort(function(a, b) { return Math.abs(signedArea(b)) - Math.abs(signedArea(a)); });
      var outerRing = rings[0];
      var west = Infinity;
      var south = Infinity;
      var east = -Infinity;
      var north = -Infinity;
      outerRing.forEach(function(point) {
        west = Math.min(west, point[0]);
        south = Math.min(south, point[1]);
        east = Math.max(east, point[0]);
        north = Math.max(north, point[1]);
      });
      return {
        rings: rings,
        bounds: [[west, south], [east, north]]
      };
    }

    function addLayerIfMissing(layer, beforeId) {
      if (map.getLayer(layer.id)) return;
      if (beforeId && map.getLayer(beforeId)) map.addLayer(layer, beforeId);
      else map.addLayer(layer);
    }

    function featureBounds(feature) {
      var bounds = [[Infinity, Infinity], [-Infinity, -Infinity]];
      function visitCoordinates(value) {
        if (!Array.isArray(value)) return;
        if (value.length >= 2 && typeof value[0] === 'number' && typeof value[1] === 'number') {
          bounds[0][0] = Math.min(bounds[0][0], value[0]);
          bounds[0][1] = Math.min(bounds[0][1], value[1]);
          bounds[1][0] = Math.max(bounds[1][0], value[0]);
          bounds[1][1] = Math.max(bounds[1][1], value[1]);
          return;
        }
        value.forEach(visitCoordinates);
      }
      if (feature && feature.geometry) visitCoordinates(feature.geometry.coordinates);
      if (!Number.isFinite(bounds[0][0]) || !Number.isFinite(bounds[0][1])
          || !Number.isFinite(bounds[1][0]) || !Number.isFinite(bounds[1][1])) {
        throw new Error('The assigned barangay boundary has no valid coordinates.');
      }
      return bounds;
    }

    function addBarangaySatelliteContext(feature) {
      if (!feature || !feature.geometry || !feature.geometry.coordinates) {
        throw new Error('The assigned barangay boundary is unavailable.');
      }
      var geometry = feature.geometry;
      var polygons = geometry.type === 'Polygon'
        ? [geometry.coordinates]
        : (geometry.type === 'MultiPolygon' ? geometry.coordinates : []);
      if (!polygons.length) throw new Error('The assigned barangay boundary has unsupported geometry.');

      var outerRings = [];
      polygons.forEach(function(polygon) {
        var outerRing = polygon[0];
        if (!Array.isArray(outerRing) || outerRing.length < 4) {
          throw new Error('The assigned barangay boundary is incomplete.');
        }
        var holeRing = outerRing.slice();
        if (signedArea(holeRing) > 0) holeRing.reverse();
        outerRings.push(holeRing);
      });

      var bounds = featureBounds(feature);
      var widthPadding = Math.max((bounds[1][0] - bounds[0][0]) * 0.15, 0.008);
      var heightPadding = Math.max((bounds[1][1] - bounds[0][1]) * 0.15, 0.008);
      var west = CITY_MASK_EXTENT[0][0];
      var south = CITY_MASK_EXTENT[0][1];
      var east = CITY_MASK_EXTENT[1][0];
      var north = CITY_MASK_EXTENT[1][1];
      var frameRing = [[west, south], [east, south], [east, north], [west, north], [west, south]];
      var mask = {
        type: 'Feature',
        properties: {},
        geometry: { type: 'Polygon', coordinates: [frameRing].concat(outerRings) }
      };
      var boundaryFeature = {
        type: 'Feature',
        properties: { name: feature.properties && feature.properties.name || '' },
        geometry: geometry
      };

      map.addSource('dcvs-city-mask', { type: 'geojson', data: mask });
      map.addSource('dcvs-barangay-borders', {
        type: 'geojson',
        data: { type: 'FeatureCollection', features: [boundaryFeature] }
      });
      addLayerIfMissing({
        id: 'dcvs-city-context-mask',
        type: 'fill',
        source: 'dcvs-city-mask',
        paint: { 'fill-color': '#334155', 'fill-opacity': 0.32 }
      }, 'barangay-fill');
      addLayerIfMissing({
        id: 'dcvs-barangay-boundary-casing',
        type: 'line',
        source: 'dcvs-barangay-borders',
        layout: { 'line-join': 'round' },
        paint: { 'line-color': '#111827', 'line-width': 3, 'line-opacity': 0.72 }
      }, 'barangay-outline');
      addLayerIfMissing({
        id: 'dcvs-barangay-boundary-highlight',
        type: 'line',
        source: 'dcvs-barangay-borders',
        layout: { 'line-join': 'round' },
        paint: { 'line-color': '#ffffff', 'line-width': 1.6, 'line-opacity': 1 }
      }, 'barangay-outline');

      if (map.getLayer('barangay-fill')) map.setPaintProperty('barangay-fill', 'fill-opacity', 0);
      if (map.getLayer('barangay-outline')) {
        map.setPaintProperty('barangay-outline', 'line-color', '#ffffff');
        map.setPaintProperty('barangay-outline', 'line-width', 1.5);
        map.setPaintProperty('barangay-outline', 'line-opacity', 1);
      }
      if (map.getLayer('geo-brgy-label')) {
        map.setPaintProperty('geo-brgy-label', 'text-color', '#1e293b');
        map.setPaintProperty('geo-brgy-label', 'text-halo-color', '#ffffff');
        map.setPaintProperty('geo-brgy-label', 'text-halo-width', 2);
      }

      map.setMaxBounds([
        [bounds[0][0] - widthPadding, bounds[0][1] - heightPadding],
        [bounds[1][0] + widthPadding, bounds[1][1] + heightPadding]
      ]);
      map.setMinZoom(MIN_MAP_ZOOM);
      map.setMaxZoom(MAX_MAP_ZOOM);
      map.fitBounds(bounds, { padding: 64, maxZoom: 17, duration: 650 });
      mapElement.classList.add('geo-map-satellite-focused');
      return Promise.resolve();
    }

    function addSatelliteContext() {
      if (isBarangayMap) return addBarangaySatelliteContext(scopedBarangayFeature);

      return fetch(appUrl('geo-map/barangays-boundary.json'), { credentials: 'same-origin' })
        .then(function(response) {
          if (!response.ok) throw new Error('Barangay boundary request failed with HTTP ' + response.status + '.');
          return response.json();
        })
        .then(function(boundaries) {
          ensureCityBoundaryOutline(boundaries);
          var cityBoundary = createCityBoundary(boundaries);
          var cityRings = cityBoundary.rings;
          var cityBounds = cityBoundary.bounds;
          var west = CITY_MASK_EXTENT[0][0];
          var south = CITY_MASK_EXTENT[0][1];
          var east = CITY_MASK_EXTENT[1][0];
          var north = CITY_MASK_EXTENT[1][1];
          var maskRing = [[west, south], [east, south], [east, north], [west, north], [west, south]];
          var cityMask = {
            type: 'Feature',
            properties: {},
            geometry: { type: 'Polygon', coordinates: [maskRing, cityRings[0]] }
          };
          var interiorMasks = {
            type: 'FeatureCollection',
            features: cityRings.slice(1).map(function(ring) {
              return {
                type: 'Feature',
                properties: {},
                geometry: { type: 'Polygon', coordinates: [ring] }
              };
            })
          };

          map.addSource('dcvs-city-mask', { type: 'geojson', data: cityMask });
          map.addSource('dcvs-city-boundary', {
            type: 'geojson',
            data: {
              type: 'Feature',
              properties: {},
              geometry: { type: 'MultiLineString', coordinates: cityRings }
            }
          });
          map.addSource('dcvs-city-interior-masks', {
            type: 'geojson',
            data: interiorMasks
          });
          map.addSource('dcvs-barangay-borders', {
            type: 'geojson',
            data: {
              type: 'FeatureCollection',
              features: boundaries.features.map(function(feature) {
                return {
                  type: 'Feature',
                  properties: { name: feature.properties && feature.properties.name || '' },
                  geometry: feature.geometry
                };
              })
            }
          });

          addLayerIfMissing({
            id: 'dcvs-city-context-mask',
            type: 'fill',
            source: 'dcvs-city-mask',
            paint: { 'fill-color': '#334155', 'fill-opacity': 0.32 }
          }, 'barangay-fill');
          addLayerIfMissing({
            id: 'dcvs-city-interior-mask',
            type: 'fill',
            source: 'dcvs-city-interior-masks',
            paint: { 'fill-color': '#334155', 'fill-opacity': 0.32 }
          }, 'barangay-fill');
          addLayerIfMissing({
            id: 'dcvs-barangay-boundary-casing',
            type: 'line',
            source: 'dcvs-barangay-borders',
            layout: { 'line-join': 'round' },
            paint: {
              'line-color': '#111827',
              'line-width': 3,
              'line-opacity': 0.72
            }
          }, 'barangay-outline');
          addLayerIfMissing({
            id: 'dcvs-barangay-boundary-highlight',
            type: 'line',
            source: 'dcvs-barangay-borders',
            layout: { 'line-join': 'round' },
            paint: {
              'line-color': '#ffffff',
              'line-width': 1.35,
              'line-opacity': 0.98
            }
          }, 'barangay-outline');
          if (map.getLayer('barangay-fill')) {
            map.setPaintProperty('barangay-fill', 'fill-opacity', 0);
          }
          if (map.getLayer('barangay-outline')) {
            map.setPaintProperty('barangay-outline', 'line-color', '#ffffff');
            map.setPaintProperty('barangay-outline', 'line-width', 1.5);
            map.setPaintProperty('barangay-outline', 'line-opacity', 1);
          }
          if (map.getLayer('geo-brgy-label')) {
            map.setPaintProperty('geo-brgy-label', 'text-color', '#1e293b');
            map.setPaintProperty('geo-brgy-label', 'text-halo-color', '#ffffff');
            map.setPaintProperty('geo-brgy-label', 'text-halo-width', 2);
          }
          map.setMaxBounds(DEFAULT_MAP_BOUNDS);
          map.setMinZoom(MIN_MAP_ZOOM);
          map.setMaxZoom(MAX_MAP_ZOOM);
          if (!mapElement.hasAttribute('data-preserve-map-view')) {
            map.fitBounds(cityBounds, { padding: 84, maxZoom: 10.5, duration: 650 });
          }
          mapElement.classList.add('geo-map-satellite-focused');
        });
    }

    function loadSatelliteStyle() {
      if (!map || !key || !overlaysReady || isChangingStyle || selectedStyle === 'satellite') return;
      var previousStyle = selectedStyle;
      isChangingStyle = true;
      selectedStyle = 'satellite';
      setStatus('Loading satellite imagery…');
      updateControls();
      var overlays = captureOverlays();
      var view = {
        center: map.getCenter(),
        zoom: map.getZoom(),
        bearing: map.getBearing(),
        pitch: map.getPitch()
      };

      map.once('style.load', function() {
        selectedStyle = 'satellite';
        updateControls();
        Promise.resolve().then(function() {
          restoreOverlays(overlays);
          return addSatelliteContext();
        }).then(function() {
          applyScopedBarangay();
          applyRiskMapStyle();
          map.jumpTo(view);
          selectedStyle = 'satellite';
          isChangingStyle = false;
          setStatus('');
          updateControls();
          mapElement.dispatchEvent(new CustomEvent('dcvs:basemapstyleload', {
            detail: { style: 'satellite' }
          }));
        }).catch(function(error) {
          console.error('Could not restore map overlays after changing basemap:', error);
          isChangingStyle = false;
          updateControls();
          setStatus('Satellite imagery is active, but map overlays could not be restored: ' + error.message);
        });
      });

      try {
        map.setStyle(SATELLITE_STYLE + encodeURIComponent(key), { diff: false });
      } catch (error) {
        console.error('Could not load satellite map style:', error);
        isChangingStyle = false;
        selectedStyle = previousStyle;
        updateControls();
        setStatus('Map style could not load. Please try again.');
      }
    }

    function loadStreetStyle() {
      if (!map || isChangingStyle || selectedStyle === 'street') return;
      var previousStyle = selectedStyle;
      isChangingStyle = true;
      selectedStyle = 'street';
      setStatus('Loading street map…');
      updateControls();
      var overlays = captureOverlays();
      var view = {
        center: map.getCenter(),
        zoom: map.getZoom(),
        bearing: map.getBearing(),
        pitch: map.getPitch()
      };

      map.once('style.load', function() {
        selectedStyle = 'street';
        updateControls();
        try {
          restoreOverlays(overlays);
          applyScopedBarangay();
          applyRiskMapStyle();
          map.setMaxBounds(initialMaxBounds);
          if (isBarangayMap && scopedBarangayFeature) {
            var scopedBounds = featureBounds(scopedBarangayFeature);
            var scopedWidthPadding = Math.max((scopedBounds[1][0] - scopedBounds[0][0]) * 0.15, 0.008);
            var scopedHeightPadding = Math.max((scopedBounds[1][1] - scopedBounds[0][1]) * 0.15, 0.008);
            map.setMaxBounds([
              [scopedBounds[0][0] - scopedWidthPadding, scopedBounds[0][1] - scopedHeightPadding],
              [scopedBounds[1][0] + scopedWidthPadding, scopedBounds[1][1] + scopedHeightPadding]
            ]);
          }
          map.jumpTo(view);
          selectedStyle = 'street';
          isChangingStyle = false;
          setStatus('');
          updateControls();
          mapElement.dispatchEvent(new CustomEvent('dcvs:basemapstyleload', {
            detail: { style: 'street' }
          }));
        } catch (error) {
          console.error('Could not restore map overlays on the street basemap:', error);
          isChangingStyle = false;
          updateControls();
          setStatus('Map overlays could not be restored: ' + error.message);
        }
      });

      try {
        map.setStyle(STREET_STYLE, { diff: false });
      } catch (error) {
        console.error('Could not load the street map style:', error);
        isChangingStyle = false;
        selectedStyle = previousStyle;
        updateControls();
        setStatus('Street map could not load. Please try again.');
      }
    }

    function updateSatelliteAvailability() {
      if (!map || !reportPinsReady || (isBarangayMap && !scopedBarangayFeature)) return;
      var attempts = 0;
      var waitForLayers = window.setInterval(function() {
        if (map.getSource('barangays') && map.getLayer('barangay-fill')) {
          applyRiskMapStyle();
        }
        if (map.getSource('barangays') && map.getLayer('barangay-fill')
            && reportPinsReady && landmarksReady) {
          window.clearInterval(waitForLayers);
          overlaysReady = true;
          updateControls();
          return;
        }
        if (++attempts >= 100) {
          window.clearInterval(waitForLayers);
          if (key) setStatus('Satellite imagery is unavailable until required map overlays load.');
        }
      }, 100);
    }

    mapElement.addEventListener('mapcn:load', function(event) {
      map = event.detail && event.detail.map;
      if (!map) return;
      initialMaxBounds = map.getMaxBounds();
      map.setMinZoom(MIN_MAP_ZOOM);
      map.setMaxZoom(MAX_MAP_ZOOM);
      createControls();
      mapElement.addEventListener('dcvs:reportpinsready', function() {
        reportPinsReady = true;
        updateSatelliteAvailability();
      });
      mapElement.addEventListener('dcvs:landmarksready', function() {
        landmarksReady = true;
        updateSatelliteAvailability();
      });
      mapElement.addEventListener('dcvs:barangaymapscope', function(event) {
        var feature = event.detail && event.detail.feature;
        var assignedBarangayId = Number(mapElement.getAttribute('data-map-barangay-id'));
        var featureBarangayId = Number(feature && feature.properties && feature.properties.id);
        if (!feature || featureBarangayId !== assignedBarangayId) {
          setStatus('The assigned barangay map boundary could not be verified.');
          return;
        }
        scopedBarangayFeature = feature;
        applyScopedBarangay();
        if (feature.geometry) {
          map.fitBounds(featureBounds(feature), { padding: 64, maxZoom: 17, duration: 650 });
        }
        updateSatelliteAvailability();
      });
      mapElement.addEventListener('dcvs:barangaysearch', function(event) {
        var detail = event.detail || {};
        searchedBarangayId = detail.active ? Number(detail.id) : null;
        applyRiskMapStyle();
      });

      var initialStyle = map.getStyle();
      (initialStyle.layers || []).forEach(function(layer) {
        if (CUSTOM_LAYER_IDS.indexOf(layer.id) !== -1
            && SATELLITE_ONLY_LAYER_IDS.indexOf(layer.id) === -1) {
          baseLayerDefinitions[layer.id] = layer;
        }
      });
      map.on('styledata', applyRiskMapStyle);
      map.on('sourcedata', function(event) {
        if (event.sourceId === 'barangays') applyRiskMapStyle();
      });
      loadCityBoundaryOutline().catch(function(error) {
        console.error('Could not load the Dasmarinas city boundary:', error);
        setStatus('Dasmarinas city boundary could not load. Please refresh the map.');
      });
      map.on('error', function(event) {
        console.error('Map basemap request failed:', event.error || event);
        if (selectedStyle === 'satellite' || isChangingStyle) {
          setStatus(selectedStyle === 'satellite'
            ? 'Satellite imagery could not load. Check the MapTiler key and try again.'
            : 'The selected map style could not load. Check the network connection.');
        } else {
          setStatus('Street map tiles could not load. Check the network connection.');
        }
      });
      updateControls();
      updateSatelliteAvailability();
    }, { once: true });
  }

  document.querySelectorAll('[data-map-basemap-toggle]').forEach(initialize);
})();
