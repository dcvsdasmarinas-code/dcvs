import React from 'react';
import { createRoot } from 'react-dom/client';
import { Map, MapControls } from '@/components/ui/map';
import { initBarangayOverlay } from '@/layers/barangay-overlay';

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

  const root = createRoot(container);
  container._mapcnRoot = root;

  const handleMapLoad = (map) => {
    window.MapCN = window.MapCN || {};
    window.MapCN.map = map;
    container.dispatchEvent(new CustomEvent('mapcn:load', { detail: { map } }));
    if (showOverlay) {
      initBarangayOverlay(map, { geojsonUrl: opts.geojsonUrl });
    }
  };

  root.render(
    <Map
      center={center}
      zoom={zoom}
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
}

// Auto-init on DOM ready for elements with data-mapcn attribute
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-mapcn]').forEach((el) => {
    try {
      const opts = JSON.parse(el.getAttribute('data-mapcn') || '{}');
      initMapCN(el.id, opts);
    } catch (e) {
      console.error('MapCN: Invalid data-mapcn JSON on', el.id, e);
    }
  });
});

// Export for programmatic use
window.MapCN = window.MapCN || {};
window.MapCN.init = initMapCN;
