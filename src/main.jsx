import React from 'react';
import { createRoot } from 'react-dom/client';
import { Map, MapControls } from '@/components/ui/map';
import { initBarangayOverlay } from '@/layers/barangay-overlay';

function setMapLoadingState(container, state, message = 'Loading map...') {
  if (!container) return;

  const loader = container.querySelector('.mapcn-loading') || document.createElement('div');
  if (!container.querySelector('.mapcn-loading')) {
    loader.className = 'mapcn-loading';
    container.appendChild(loader);
  }

  const icon = state === 'error' ? '<i class="fas fa-exclamation-triangle"></i>' : '<i class="fas fa-spinner"></i>';
  loader.innerHTML = `${icon} ${message}`;
  loader.style.display = 'flex';

  container.classList.toggle('mapcn-ready', state === 'ready');
  container.classList.toggle('mapcn-error', state === 'error');
}

function hideMapLoading(container) {
  if (!container) return;

  const loader = container.querySelector('.mapcn-loading');
  if (loader) loader.remove();
  container.classList.add('mapcn-ready');
}

function showMapError(container, message = 'Map unavailable. Please refresh the page.') {
  if (!container) return;
  setMapLoadingState(container, 'error', message);
}

function initMapCN(containerId, options) {
  const container = document.getElementById(containerId);
  if (!container) {
    console.error(`MapCN: Container #${containerId} not found`);
    return;
  }

  if (container._mapcnRoot) return;

  const opts = options || {};
  const center = opts.center || [120.968, 14.306];
  const zoom = opts.zoom || 12;
  const showOverlay = opts.barangayOverlay !== false;
  const maptilerKey = container.getAttribute('data-maptiler-key');
  const style = maptilerKey
    ? `https://api.maptiler.com/maps/hybrid/style.json?key=${encodeURIComponent(maptilerKey)}`
    : undefined;

  try {
    const root = createRoot(container);
    container._mapcnRoot = root;

    const handleMapLoad = (map) => {
      window.MapCN = window.MapCN || {};
      window.MapCN.map = map;
      hideMapLoading(container);
      container.dispatchEvent(new CustomEvent('mapcn:load', { detail: { map } }));
      if (showOverlay) {
        initBarangayOverlay(map, { geojsonUrl: opts.geojsonUrl });
      }
    };

    root.render(
      <Map
        center={center}
        zoom={zoom}
        style={style}
        onLoad={handleMapLoad}
      >
        <MapControls
          position={opts.controlsPosition || 'top-right'}
          showZoom={opts.showZoom !== false}
          showCompass={opts.showCompass === true}
          showLocate={opts.showLocate === true}
          showFullscreen={opts.showFullscreen === true}
        />
      </Map>
    );
  } catch (error) {
    console.error('MapCN: Failed to initialize map container', containerId, error);
    showMapError(container, 'Map failed to initialize. Please refresh the page.');
  }
}

// Auto-init on DOM ready for elements with data-mapcn attribute
document.addEventListener('DOMContentLoaded', () => {
  const mapEls = document.querySelectorAll('[data-mapcn]');
  if (!mapEls.length) return;

  if (!window.MapCN || typeof window.MapCN.init !== 'function') {
    mapEls.forEach((el) => {
      showMapError(el, 'Map library failed to load. Please refresh the page.');
    });
    return;
  }

  mapEls.forEach((el) => {
    try {
      const opts = JSON.parse(el.getAttribute('data-mapcn') || '{}');
      initMapCN(el.id, opts);
    } catch (e) {
      console.error('MapCN: Invalid data-mapcn JSON on', el.id, e);
      showMapError(el, 'Map configuration is invalid. Please refresh the page.');
    }
  });
});

// Export for programmatic use
window.MapCN = window.MapCN || {};
window.MapCN.init = initMapCN;
