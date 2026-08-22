/**
 * MapCN — Vanilla JS version
 * Provides the same Map / MapControls API without React.
 *
 * Usage:
 *   var map = MapCN.Map({ container: 'mapEl', center: [lng, lat], zoom: 12 });
 *   MapCN.MapControls({ map: map, position: 'top-right', showZoom: true });
 */

(function (global) {
  'use strict';

  var DEFAULT_STYLE = 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';

  function Map(options) {
    if (typeof maplibregl === 'undefined') {
      console.error('MapCN: MapLibre GL JS is required. Load maplibre-gl.js first.');
      return null;
    }

    var container = typeof options.container === 'string'
      ? document.getElementById(options.container)
      : options.container;

    if (!container) {
      console.error('MapCN: Container not found:', options.container);
      return null;
    }

    var map = new maplibregl.Map({
      container: container,
      style: options.style || DEFAULT_STYLE,
      center: options.center || [120.9370, 14.3270],
      zoom: options.zoom || 12,
      minZoom: options.minZoom != null ? options.minZoom : 11,
      maxZoom: options.maxZoom != null ? options.maxZoom : 16,
      maxBounds: options.maxBounds || [[120.84, 14.23], [121.04, 14.43]],
      attributionControl: false,
    });

    map.addControl(new maplibregl.NavigationControl({
      showCompass: false,
      showZoom: true,
    }), 'bottom-right');

    map.addControl(new maplibregl.ScaleControl({
      maxWidth: 120,
      unit: 'metric',
    }), 'bottom-left');

    if (options.onLoad) {
      map.on('load', function () { options.onLoad(map); });
    }

    map.on('error', function (e) { console.error('MapCN error:', e); });

    return map;
  }

  function MapControls(options) {
    var map = options.map;
    if (!map) { console.error('MapCN.MapControls: map instance required'); return; }

    var position = options.position || 'top-right';

    if (options.showZoom !== false || options.showCompass) {
      map.addControl(new maplibregl.NavigationControl({
        showCompass: options.showCompass === true,
        showZoom: options.showZoom !== false,
      }), position);
    }

    if (options.showLocate) {
      map.addControl(new maplibregl.GeolocateControl({
        positionOptions: { enableHighAccuracy: true },
        trackUserLocation: true,
      }), position);
    }

    if (options.showFullscreen) {
      map.addControl(new maplibregl.FullscreenControl(), position);
    }
  }

  global.MapCN = global.MapCN || {};
  global.MapCN.vanilla = {
    Map: Map,
    MapControls: MapControls,
  };

})(window);
