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
let pendingSearchQuery = null;

function normalizeBarangayName(value) {
  return (value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

function editDistance(left, right) {
  const previous = Array.from({ length: right.length + 1 }, (_, index) => index);
  for (let row = 1; row <= left.length; row++) {
    let diagonal = previous[0];
    previous[0] = row;
    for (let column = 1; column <= right.length; column++) {
      const above = previous[column];
      previous[column] = Math.min(
        previous[column] + 1,
        previous[column - 1] + 1,
        diagonal + (left[row - 1] === right[column - 1] ? 0 : 1)
      );
      diagonal = above;
    }
  }
  return previous[right.length];
}

function rankBarangays(query) {
  const normalizedQuery = normalizeBarangayName(query);
  if (!normalizedQuery) return [];

  return cachedFeatures.map((feature) => {
    const name = feature.properties && feature.properties.name || '';
    const normalizedName = normalizeBarangayName(name);
    const exact = normalizedName === normalizedQuery;
    const prefix = normalizedName.startsWith(normalizedQuery);
    const contains = normalizedName.includes(normalizedQuery);
    const distance = editDistance(normalizedQuery, normalizedName);
    const threshold = Math.max(2, Math.floor(normalizedQuery.length * 0.35));
    if (!exact && !prefix && !contains && distance > threshold) return null;
    return {
      feature,
      exact,
      prefix,
      contains,
      distance,
      rank: exact ? 0 : prefix ? 1 : contains ? 2 : 3,
      name,
    };
  }).filter(Boolean).sort((left, right) =>
    left.rank - right.rank ||
    left.distance - right.distance ||
    left.name.localeCompare(right.name)
  );
}

function getRiskBadgeColor(risk) {
  switch (risk) {
    case 'Red': return { bg: '#fee2e2', text: '#991b1b' };
    case 'Orange': return { bg: '#ffedd5', text: '#9a3412' };
    case 'Yellow': return { bg: '#fef9c3', text: '#854d0e' };
    default: return { bg: '#dcfce7', text: '#166534' };
  }
}

function setTextById(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function updateSummary(metadata) {
  if (!metadata) return;
  const affected = Number(metadata.affectedBarangays) || 0;
  const total = Number(metadata.totalBarangays) || 75;
  const affectedPercent = total > 0 ? ((affected / total) * 100).toFixed(1) : '0.0';
  setTextById('geoTotalConfirmed', Number(metadata.totalConfirmed) || 0);
  setTextById('geoAffectedBgy', `${affected} of ${total}`);
  setTextById('geoAffectedPercent', `${affectedPercent}% of all barangays`);
  setTextById('geoHighestRiskName', metadata.highestRiskBarangay || 'None');

  const highestRiskFeature = cachedFeatures.find((feature) =>
    normalizeBarangayName(feature.properties?.name) === normalizeBarangayName(metadata.highestRiskBarangay)
  );
  const riskChip = document.getElementById('geoHighestRiskChip');
  if (riskChip) {
    const confirmed = Number(highestRiskFeature?.properties?.confirmed) || 0;
    const risk = highestRiskFeature?.properties?.risk || 'Green';
    const riskLabel = confirmed === 0 ? '0 cases'
      : confirmed <= 5 ? '1–5 cases'
        : confirmed <= 15 ? '6–15 cases' : '16+ cases';
    riskChip.textContent = riskLabel;
    riskChip.className = `geo-risk-chip geo-risk-chip-${risk.toLowerCase()}`;
  }

  const lastUpdated = document.getElementById('geoLastRefresh');
  if (lastUpdated) {
    lastUpdated.textContent = metadata.lastUpdated
      ? new Date(metadata.lastUpdated).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
      : '--';
  }
}

function openInfoPanel(props) {
  const panel = document.getElementById('geoInfoPanel');
  if (!panel) return;

  const rc = getRiskBadgeColor(props.risk);
  const lastUpdated = props.lastUpdated ? new Date(props.lastUpdated).toLocaleString() : 'N/A';

  const nameEl = document.getElementById('geoPanelName');
  const riskEl = document.getElementById('geoPanelRisk');

  if (nameEl) nameEl.textContent = props.name || 'Unknown';
  if (riskEl) {
    riskEl.textContent = props.risk || 'Green';
    riskEl.style.background = rc.bg;
    riskEl.style.color = rc.text;
  }

  setTextById('geoPanelConfirmed', props.confirmed || 0);
  setTextById('geoPanelPending', props.pending || 0);
  setTextById('geoPanelResolved', props.resolved || 0);
  setTextById('geoPanelUpdated', lastUpdated);

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

function ringArea(ring) {
  let area = 0;
  for (let index = 0; index < ring.length; index++) {
    const current = ring[index];
    const next = ring[(index + 1) % ring.length];
    area += current[0] * next[1] - next[0] * current[1];
  }
  return area / 2;
}

function getProjectedArea(ring) {
  if (!Array.isArray(ring) || !ring.length) return 0;
  const meanLatitude = ring.reduce((total, point) => total + Number(point[1]), 0) / ring.length;
  const longitudeScale = 111320 * Math.cos(meanLatitude * Math.PI / 180);
  return Math.abs(ringArea(ring.map((point) => [
    Number(point[0]) * longitudeScale,
    Number(point[1]) * 110540,
  ])));
}

function isPointInRing(point, ring) {
  let inside = false;
  for (let index = 0, previous = ring.length - 1; index < ring.length; previous = index++) {
    const currentPoint = ring[index];
    const previousPoint = ring[previous];
    const crosses = (currentPoint[1] > point[1]) !== (previousPoint[1] > point[1])
      && point[0] < ((previousPoint[0] - currentPoint[0]) * (point[1] - currentPoint[1]))
        / (previousPoint[1] - currentPoint[1]) + currentPoint[0];
    if (crosses) inside = !inside;
  }
  return inside;
}

function distanceToSegment(point, start, end) {
  const dx = end[0] - start[0];
  const dy = end[1] - start[1];
  const lengthSquared = dx * dx + dy * dy;
  const projection = lengthSquared === 0
    ? 0
    : Math.max(0, Math.min(1, ((point[0] - start[0]) * dx + (point[1] - start[1]) * dy) / lengthSquared));
  const x = start[0] + projection * dx;
  const y = start[1] + projection * dy;
  return Math.hypot(point[0] - x, point[1] - y);
}

function getSignedDistance(point, rings) {
  let minDistance = Infinity;
  let inside = isPointInRing(point, rings[0]);
  for (let ringIndex = 0; ringIndex < rings.length; ringIndex++) {
    const ring = rings[ringIndex];
    if (ringIndex > 0 && isPointInRing(point, ring)) inside = false;
    for (let index = 0; index < ring.length; index++) {
      minDistance = Math.min(
        minDistance,
        distanceToSegment(point, ring[index], ring[(index + 1) % ring.length])
      );
    }
  }
  return inside ? minDistance : -minDistance;
}

function pushCell(heap, cell) {
  let index = heap.length;
  heap.push(cell);
  while (index > 0) {
    const parentIndex = (index - 1) >> 1;
    if (heap[parentIndex].max >= cell.max) break;
    heap[index] = heap[parentIndex];
    index = parentIndex;
  }
  heap[index] = cell;
}

function popCell(heap) {
  const first = heap[0];
  const last = heap.pop();
  if (heap.length && last) {
    let index = 0;
    while (true) {
      const left = index * 2 + 1;
      const right = left + 1;
      let largest = index;
      if (left < heap.length && heap[left].max > heap[largest].max) largest = left;
      if (right < heap.length && heap[right].max > heap[largest].max) largest = right;
      if (largest === index) break;
      heap[index] = heap[largest];
      index = largest;
    }
    heap[index] = last;
  }
  return first;
}

function computePolygonInteriorPoint(polygon) {
  if (!Array.isArray(polygon) || !Array.isArray(polygon[0]) || polygon[0].length < 4) return null;

  let minLongitude = Infinity;
  let maxLongitude = -Infinity;
  let minLatitude = Infinity;
  let maxLatitude = -Infinity;
  for (const ring of polygon) {
    for (const point of ring) {
      if (!Array.isArray(point) || !Number.isFinite(Number(point[0])) || !Number.isFinite(Number(point[1]))) continue;
      minLongitude = Math.min(minLongitude, Number(point[0]));
      maxLongitude = Math.max(maxLongitude, Number(point[0]));
      minLatitude = Math.min(minLatitude, Number(point[1]));
      maxLatitude = Math.max(maxLatitude, Number(point[1]));
    }
  }
  if (!Number.isFinite(minLongitude) || !Number.isFinite(minLatitude)) return null;
  const originLongitude = (minLongitude + maxLongitude) / 2;
  const originLatitude = (minLatitude + maxLatitude) / 2;
  const longitudeScale = 111320 * Math.cos(originLatitude * Math.PI / 180);
  const latitudeScale = 110540;
  const rings = polygon.map((ring) => ring.map((point) => [
    (Number(point[0]) - originLongitude) * longitudeScale,
    (Number(point[1]) - originLatitude) * latitudeScale,
  ]));
  if (rings.some((ring) => ring.length < 4)) return null;

  let area = Math.abs(ringArea(rings[0]));
  for (let index = 1; index < rings.length; index++) area -= Math.abs(ringArea(rings[index]));
  if (area <= 0) return null;

  let minX = Infinity;
  let maxX = -Infinity;
  let minY = Infinity;
  let maxY = -Infinity;
  for (const point of rings[0]) {
    minX = Math.min(minX, point[0]);
    maxX = Math.max(maxX, point[0]);
    minY = Math.min(minY, point[1]);
    maxY = Math.max(maxY, point[1]);
  }
  const width = maxX - minX;
  const height = maxY - minY;
  const cellSize = Math.min(width, height);
  if (cellSize <= 0) return null;

  let bestPoint = [(minX + maxX) / 2, (minY + maxY) / 2];
  let bestDistance = getSignedDistance(bestPoint, rings);
  const heap = [];
  const makeCell = (x, y, halfSize) => {
    const distance = getSignedDistance([x, y], rings);
    return { x, y, halfSize, distance, max: distance + halfSize * Math.SQRT2 };
  };

  for (let x = minX; x < maxX; x += cellSize) {
    for (let y = minY; y < maxY; y += cellSize) {
      pushCell(heap, makeCell(x + cellSize / 2, y + cellSize / 2, cellSize / 2));
    }
  }

  let iterations = 0;
  while (heap.length && iterations++ < 20000) {
    const cell = popCell(heap);
    if (cell.distance > bestDistance) {
      bestPoint = [cell.x, cell.y];
      bestDistance = cell.distance;
    }
    if (cell.max - bestDistance <= 15 || cell.halfSize <= 7.5) continue;

    const halfSize = cell.halfSize / 2;
    pushCell(heap, makeCell(cell.x - halfSize, cell.y - halfSize, halfSize));
    pushCell(heap, makeCell(cell.x + halfSize, cell.y - halfSize, halfSize));
    pushCell(heap, makeCell(cell.x - halfSize, cell.y + halfSize, halfSize));
    pushCell(heap, makeCell(cell.x + halfSize, cell.y + halfSize, halfSize));
  }

  if (bestDistance <= 0) return null;
  return [
    originLongitude + bestPoint[0] / longitudeScale,
    originLatitude + bestPoint[1] / latitudeScale,
  ];
}

export function computeInteriorLabelPoint(geometry) {
  if (!geometry || !Array.isArray(geometry.coordinates)) return null;
  const polygons = geometry.type === 'Polygon'
    ? [geometry.coordinates]
    : geometry.type === 'MultiPolygon' ? geometry.coordinates : [];
  let largestArea = -1;
  let labelPoint = null;
  for (const polygon of polygons) {
    if (!Array.isArray(polygon) || !Array.isArray(polygon[0])) continue;
    const candidatePoint = computePolygonInteriorPoint(polygon);
    if (!candidatePoint) continue;
    const outerArea = getProjectedArea(polygon[0]);
    if (outerArea > largestArea) {
      largestArea = outerArea;
      labelPoint = candidatePoint;
    }
  }
  return labelPoint;
}

function getLabelMinZoom(area) {
  if (area >= 1000000) return 12;
  if (area >= 500000) return 12.5;
  if (area >= 250000) return 13;
  return 14;
}

function setLabelPalette(map, style) {
  if (!map.getLayer('barangay-label')) return;
  const satellite = style === 'satellite';
  map.setPaintProperty('barangay-label', 'text-color', satellite ? '#ffffff' : '#4B5A50');
  map.setPaintProperty('barangay-label', 'text-halo-color', satellite ? 'rgba(15, 23, 42, 0.9)' : '#ffffff');
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

  // Place labels at interior points so narrow or concave barangays remain identifiable.
  const labelPoints = [];
  for (const f of (geojson.features || [])) {
    if (!f.geometry || !f.properties) continue;
    const coordinates = computeInteriorLabelPoint(f.geometry);
    if (!coordinates) continue;
    const outerRing = f.geometry.type === 'Polygon'
      ? f.geometry.coordinates[0]
      : (f.geometry.coordinates || []).reduce((largest, polygon) =>
        getProjectedArea(polygon[0] || []) > getProjectedArea(largest) ? polygon[0] || [] : largest, []);
    const approximateArea = getProjectedArea(outerRing);
    labelPoints.push({
      type: 'Feature',
      properties: {
        id: f.properties.id,
        name: f.properties.name,
        rank: 1,
        minzoom: getLabelMinZoom(approximateArea),
      },
      geometry: { type: 'Point', coordinates },
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

  // Collision-aware labels allow MapLibre to spread barangay names as the map zooms in.
  map.addLayer({
    id: 'barangay-label',
    type: 'symbol',
    source: 'brgy-labels',
    filter: ['<=', ['get', 'minzoom'], ['zoom']],
    layout: {
      'text-field': ['upcase', ['get', 'name']],
      'text-font': ['Open Sans Regular', 'Arial Unicode MS Regular'],
      'text-size': [
        'interpolate', ['linear'], ['zoom'],
        12, 12,
        14, 13,
        16, 14,
      ],
      'text-letter-spacing': 0.08,
      'text-variable-anchor': ['top', 'bottom', 'left', 'right'],
      'text-radial-offset': 0.8,
      'text-padding': 4,
      'text-max-width': 8,
      'text-allow-overlap': false,
      'icon-allow-overlap': false,
      'text-optional': true,
      'symbol-sort-key': ['get', 'rank'],
    },
    paint: {
      'text-color': '#4B5A50',
      'text-halo-color': '#ffffff',
      'text-halo-width': 1.75,
      'text-opacity': 0.95,
    },
  });
  const mapElement = map.getContainer()?.closest('[data-map-basemap-toggle]');
  setLabelPalette(map, mapElement?.getAttribute('data-initial-basemap'));
  if (mapElement && !mapElement.__dcvsBarangayLabelPaletteBound) {
    mapElement.__dcvsBarangayLabelPaletteBound = true;
    mapElement.addEventListener('dcvs:basemapstyleload', (event) => {
      setLabelPalette(map, event.detail?.style);
    });
  }

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

function resetSearchStyle(map) {
  map.setPaintProperty('barangay-fill', 'fill-color', [
    'match', ['get', 'risk'],
    'Green', RISK_COLORS.Green,
    'Yellow', RISK_COLORS.Yellow,
    'Orange', RISK_COLORS.Orange,
    'Red', RISK_COLORS.Red,
    RISK_COLORS.Green,
  ]);
  map.setPaintProperty('barangay-fill', 'fill-opacity', 0.5);
  map.setPaintProperty('barangay-outline', 'line-color', '#ffffff');
  map.setPaintProperty('barangay-outline', 'line-width', [
    'interpolate', ['linear'], ['zoom'],
    10, 1,
    12, 1.5,
    15, 3,
  ]);
  map.setPaintProperty('barangay-outline', 'line-opacity', 0.7);
}

function dispatchSearchState(map, id) {
  const mapElement = map.getContainer().closest('#geoMap');
  if (mapElement) {
    mapElement.dispatchEvent(new CustomEvent('dcvs:barangaysearch', {
      detail: { id: id === null ? null : Number(id), active: id !== null },
    }));
  }
}

function searchBarangay(map, query) {
  if (!map || !map.getSource('barangays') || !map.getLayer('barangay-fill')) return null;
  query = normalizeBarangayName(query);

  if (!query) {
    resetSearchStyle(map);
    dispatchSearchState(map, null);
    return false;
  }

  const match = rankBarangays(query)[0];
  const feature = match && match.feature;

  if (feature) {
    const matchId = feature.properties.id;
    const isMatch = ['==', ['to-number', ['get', 'id']], matchId];
    const highlightedRiskColor = [
      'match', ['get', 'risk'],
      'Green', RISK_COLORS.Green,
      'Yellow', RISK_COLORS.Yellow,
      'Orange', RISK_COLORS.Orange,
      'Red', RISK_COLORS.Red,
      RISK_COLORS.Green,
    ];
    map.setPaintProperty('barangay-fill', 'fill-color', [
      'case',
      isMatch,
      highlightedRiskColor,
      '#cbd5e1',
    ]);
    map.setPaintProperty('barangay-fill', 'fill-opacity', [
      'case', isMatch, 0.72, 0.42,
    ]);
    map.setPaintProperty('barangay-outline', 'line-color', [
      'case', isMatch, '#0e8131', '#ffffff',
    ]);
    map.setPaintProperty('barangay-outline', 'line-width', [
      'interpolate', ['linear'], ['zoom'],
      10, ['case', isMatch, 2.5, 0.5],
      12, ['case', isMatch, 4, 1],
      15, ['case', isMatch, 7, 2],
    ]);
    map.setPaintProperty('barangay-outline', 'line-opacity', [
      'case', isMatch, 1, 0.55,
    ]);
    if (feature.geometry) {
      map.fitBounds(computeBounds([feature]), {
        padding: 64,
        maxZoom: 14,
        duration: 800,
      });
    }
    dispatchSearchState(map, matchId);
    return true;
  } else {
    resetSearchStyle(map);
    dispatchSearchState(map, null);
    return false;
  }
}

export function initBarangayOverlay(map, opts) {
  if (opts && opts.geojsonUrl) GEOJSON_URL = opts.geojsonUrl;
  const root = map?.getContainer?.()?.parentElement;

  fetchBarangayData()
    .then((data) => {
      addLayers(map, data);
      updateSummary(data.metadata);
      setupInteractions(map);
      startAutoRefresh(map);
      if (pendingSearchQuery !== null) {
        searchBarangay(map, pendingSearchQuery);
        pendingSearchQuery = null;
      }
      document.dispatchEvent(new CustomEvent('dcvs:barangaysready'));
      // Stop auto-refresh when map is removed
      map.on('remove', stopAutoRefresh);
    })
    .catch((err) => {
      console.error('Barangay overlay init failed:', err);
      if (root) {
        const loader = root.querySelector('.mapcn-loading');
        if (loader) {
          loader.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed to load barangay map data. Please refresh the page.';
        }
        root.classList.add('mapcn-error');
      }
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
    if (!map || !map.getSource('barangays') || !map.getLayer('barangay-fill')) {
      pendingSearchQuery = (query || '').trim();
      return null;
    }
    pendingSearchQuery = null;
    return searchBarangay(map, query);
  },
  suggest: (query, limit = 5) => rankBarangays(query)
    .slice(0, limit)
    .map((match) => ({
      id: match.feature.properties.id,
      name: match.name,
    })),
  cancelPendingSearch: () => {
    pendingSearchQuery = null;
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
    }).catch((error) => {
      console.error('Barangay overlay refresh failed:', error);
      const mapElement = map.getContainer()?.closest('[data-map-basemap-toggle]');
      const status = mapElement?.querySelector('[data-basemap-status]');
      if (status) status.textContent = 'Map data could not be refreshed. Please try again.';
    });
  },
  closePanel: () => document.getElementById('geoInfoPanel')?.classList.remove('open'),
};
