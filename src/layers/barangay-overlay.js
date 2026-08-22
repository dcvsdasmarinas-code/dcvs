const DEFAULT_GEOJSON_URL = 'geo-map/barangays.geojson.php';
const REFRESH_INTERVAL = 30000;

let GEOJSON_URL = DEFAULT_GEOJSON_URL;

const RISK_COLORS = {
  Green: '#22c55e',
  Yellow: '#eab308',
  Orange: '#f97316',
  Red: '#ef4444',
};

let refreshTimer = null;
let cachedFeatures = [];

function getRiskBadgeColor(risk) {
  switch (risk) {
    case 'Red': return { bg: '#fee2e2', text: '#991b1b' };
    case 'Orange': return { bg: '#ffedd5', text: '#9a3412' };
    case 'Yellow': return { bg: '#fef9c3', text: '#854d0e' };
    default: return { bg: '#dcfce7', text: '#166534' };
  }
}

function updateSummary(metadata) {
  if (!metadata) return;
  const e1 = document.getElementById('geoTotalConfirmed');
  const e2 = document.getElementById('geoAffectedBgy');
  const e3 = document.getElementById('geoHighestRisk');
  const e4 = document.getElementById('geoLastRefresh');
  if (e1) e1.textContent = metadata.totalConfirmed || 0;
  if (e2) e2.textContent = (metadata.affectedBarangays || 0) + ' / ' + (metadata.totalBarangays || 75);
  if (e3) e3.textContent = metadata.highestRiskBarangay || 'None';
  if (e4) e4.textContent = metadata.lastUpdated ? new Date(metadata.lastUpdated).toLocaleTimeString() : '--';
}

function openInfoPanel(props) {
  const panel = document.getElementById('geoInfoPanel');
  if (!panel) return;

  const rc = getRiskBadgeColor(props.risk);
  const lastUpdated = props.lastUpdated ? new Date(props.lastUpdated).toLocaleString() : 'N/A';

  document.getElementById('geoPanelName').textContent = props.name || 'Unknown';
  const riskEl = document.getElementById('geoPanelRisk');
  riskEl.textContent = props.risk || 'Green';
  riskEl.style.background = rc.bg;
  riskEl.style.color = rc.text;
  document.getElementById('geoPanelConfirmed').textContent = props.confirmed || 0;
  document.getElementById('geoPanelPending').textContent = props.pending || 0;
  document.getElementById('geoPanelResolved').textContent = props.resolved || 0;
  document.getElementById('geoPanelUpdated').textContent = lastUpdated;

  panel.classList.add('open');
}

function fetchBarangayData() {
  return fetch(GEOJSON_URL, {
    credentials: 'same-origin',
    headers: { Accept: 'application/geo+json' },
  }).then((r) => {
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  });
}

function computeCentroid(coords) {
  let lon = 0, lat = 0, n = 0;
  if (Array.isArray(coords[0][0])) {
    // MultiPolygon
    for (const poly of coords) {
      for (const ring of poly) {
        for (const pt of ring) { lon += pt[0]; lat += pt[1]; n++; }
      }
    }
  } else {
    // Polygon (single ring, first element)
    for (const pt of coords[0]) { lon += pt[0]; lat += pt[1]; n++; }
  }
  return [lon / n, lat / n];
}

function computeBounds(features) {
  let minLon = 180, maxLon = -180, minLat = 90, maxLat = -90;
  for (const f of features) {
    const geom = f.geometry || {};
    if (geom.type === 'Polygon') {
      for (const ring of geom.coordinates) {
        for (const pt of ring) {
          if (pt[0] < minLon) minLon = pt[0];
          if (pt[0] > maxLon) maxLon = pt[0];
          if (pt[1] < minLat) minLat = pt[1];
          if (pt[1] > maxLat) maxLat = pt[1];
        }
      }
    } else if (geom.type === 'MultiPolygon') {
      for (const poly of geom.coordinates) {
        for (const ring of poly) {
          for (const pt of ring) {
            if (pt[0] < minLon) minLon = pt[0];
            if (pt[0] > maxLon) maxLon = pt[0];
            if (pt[1] < minLat) minLat = pt[1];
            if (pt[1] > maxLat) maxLat = pt[1];
          }
        }
      }
    }
  }
  return [
    [minLon, minLat],
    [maxLon, maxLat],
  ];
}

function addLayers(map, geojson) {
  cachedFeatures = geojson.features || [];

  // Remove existing layers/sources
  ['barangay-fill', 'barangay-outline', 'barangay-label'].forEach((id) => {
    if (map.getLayer(id)) map.removeLayer(id);
  });
  if (map.getSource('barangays')) map.removeSource('barangays');
  if (map.getSource('brgy-labels')) map.removeSource('brgy-labels');

  // Build label points (centroids)
  const labelPoints = [];
  for (const f of (geojson.features || [])) {
    if (!f.geometry) continue;
    const centroid = computeCentroid(f.geometry.coordinates);
    labelPoints.push({
      type: 'Feature',
      properties: { name: f.properties.name },
      geometry: { type: 'Point', coordinates: centroid },
    });
  }

  map.addSource('barangays', { type: 'geojson', data: geojson, promoteId: 'id' });
  map.addSource('brgy-labels', { type: 'geojson', data: { type: 'FeatureCollection', features: labelPoints } });

  // Fill layer (semi-transparent, risk-colored)
  map.addLayer({
    id: 'barangay-fill',
    type: 'fill',
    source: 'barangays',
    paint: {
      'fill-color': [
        'match', ['get', 'risk'],
        'Green', RISK_COLORS.Green,
        'Yellow', RISK_COLORS.Yellow,
        'Orange', RISK_COLORS.Orange,
        'Red', RISK_COLORS.Red,
        RISK_COLORS.Green,
      ],
      'fill-opacity': 0.5,
    },
  });

  // Outline layer (visible border between barangays)
  map.addLayer({
    id: 'barangay-outline',
    type: 'line',
    source: 'barangays',
    paint: {
      'line-color': '#ffffff',
      'line-width': [
        'interpolate', ['linear'], ['zoom'],
        10, 1,
        12, 1.5,
        15, 3,
      ],
      'line-opacity': 0.7,
    },
  });

  // Label layer (always visible)
  map.addLayer({
    id: 'barangay-label',
    type: 'symbol',
    source: 'brgy-labels',
    layout: {
      'text-field': ['get', 'name'],
      'text-font': ['Open Sans Regular', 'Arial Unicode MS Regular'],
      'text-size': [
        'interpolate', ['linear'], ['zoom'],
        10, 11,
        12, 13,
        14, 16,
        16, 20,
      ],
      'text-offset': [0, 0],
      'text-anchor': 'center',
      'text-allow-overlap': true,
      'text-ignore-placement': true,
    },
    paint: {
      'text-color': '#ffffff',
      'text-halo-color': '#1e293b',
      'text-halo-width': 3,
      'text-halo-blur': 1,
      'text-opacity': 0.95,
    },
  });

  // Landscape camera viewport — heavy horizontal padding forces the
  // camera to zoom out laterally, revealing more left/right area
  const bounds = computeBounds(geojson.features);
  if (bounds[0][0] < bounds[1][0] && bounds[0][1] < bounds[1][1]) {
    const w = map.getContainer().clientWidth || 800;
    const h = map.getContainer().clientHeight || 500;
    map.fitBounds(bounds, {
      padding: {
        left: Math.round(w * 0.20),
        right: Math.round(w * 0.20),
        top: Math.round(h * 0.04),
        bottom: Math.round(h * 0.04),
      },
      maxZoom: 13,
      duration: 1000,
    });
  }
}

function setupInteractions(map) {
  map.on('click', 'barangay-fill', (e) => {
    if (!e.features || !e.features.length) return;
    openInfoPanel(e.features[0].properties);
  });

  map.on('mouseenter', 'barangay-fill', () => {
    map.getCanvas().style.cursor = 'pointer';
  });

  map.on('mouseleave', 'barangay-fill', () => {
    map.getCanvas().style.cursor = '';
  });
}

function startAutoRefresh(map) {
  if (refreshTimer) clearInterval(refreshTimer);
  refreshTimer = setInterval(() => {
    fetchBarangayData()
      .then((data) => {
        if (map && map.getSource('barangays')) {
          cachedFeatures = data.features || [];
          map.getSource('barangays').setData(data);
          updateSummary(data.metadata);
        }
      })
      .catch((err) => console.error('Barangay overlay auto-refresh failed:', err));
  }, REFRESH_INTERVAL);
}

function stopAutoRefresh() {
  if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
}

function searchBarangay(map, query) {
  if (!map || !map.getSource('barangays')) return;
  query = (query || '').toLowerCase().trim();

  if (!query) {
    map.setPaintProperty('barangay-fill', 'fill-opacity', 0.5);
    map.setPaintProperty('barangay-outline', 'line-width', [
      'interpolate', ['linear'], ['zoom'],
      10, 1,
      12, 1.5,
      15, 3,
    ]);
    map.setPaintProperty('barangay-outline', 'line-opacity', 0.7);
    return;
  }

  const matchIdx = cachedFeatures.findIndex((f) =>
    (f.properties.name || '').toLowerCase().includes(query)
  );

  if (matchIdx !== -1) {
    const feature = cachedFeatures[matchIdx];
    const matchId = feature.properties.id;
    map.setPaintProperty('barangay-fill', 'fill-opacity', [
      'case', ['==', ['get', 'id'], matchId], 0.7, 0.2,
    ]);
    map.setPaintProperty('barangay-outline', 'line-width', [
      'case', ['==', ['get', 'id'], matchId],
      ['interpolate', ['linear'], ['zoom'], 10, 2.5, 12, 4, 15, 7],
      ['interpolate', ['linear'], ['zoom'], 10, 0.5, 12, 1, 15, 2],
    ]);
    map.setPaintProperty('barangay-outline', 'line-opacity', [
      'case', ['==', ['get', 'id'], matchId], 1, 0.3,
    ]);
    if (feature.geometry) {
      const center = computeCentroid(feature.geometry.coordinates);
      map.flyTo({ center, zoom: 14, duration: 800 });
    }
  } else {
    map.setPaintProperty('barangay-fill', 'fill-opacity', 0.5);
    map.setPaintProperty('barangay-outline', 'line-width', [
      'interpolate', ['linear'], ['zoom'],
      10, 1,
      12, 1.5,
      15, 3,
    ]);
    map.setPaintProperty('barangay-outline', 'line-opacity', 0.7);
  }
}

export function initBarangayOverlay(map, opts) {
  if (opts && opts.geojsonUrl) GEOJSON_URL = opts.geojsonUrl;
  fetchBarangayData()
    .then((data) => {
      addLayers(map, data);
      updateSummary(data.metadata);
      setupInteractions(map);
      startAutoRefresh(map);
      // Stop auto-refresh when map is removed
      map.on('remove', stopAutoRefresh);
    })
    .catch((err) => {
      console.error('Barangay overlay init failed:', err);
    });
}

export function destroyBarangayOverlay() {
  stopAutoRefresh();
}

window.MapCN = window.MapCN || {};
window.MapCN.barangayOverlay = {
  init: initBarangayOverlay,
  destroy: destroyBarangayOverlay,
  search: (query) => {
    const map = window.MapCN && window.MapCN.map;
    if (map) searchBarangay(map, query);
  },
  refresh: () => {
    const map = window.MapCN && window.MapCN.map;
    if (!map) return;
    fetchBarangayData().then((data) => {
      if (map.getSource('barangays')) {
        cachedFeatures = data.features || [];
        map.getSource('barangays').setData(data);
        updateSummary(data.metadata);
      }
    });
  },
  closePanel: () => document.getElementById('geoInfoPanel')?.classList.remove('open'),
};
