import React, { createContext, useContext, useEffect, useRef, useState, useCallback } from 'react';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import './map.css';

const MapContext = createContext(null);

export function useMap() {
  return useContext(MapContext);
}

export function Map({ children, center, zoom, minZoom = 10, maxZoom = 16, maxBounds, style, onLoad }) {
  const containerRef = useRef(null);
  const mapRef = useRef(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!containerRef.current || mapRef.current) return;

    const map = new maplibregl.Map({
      container: containerRef.current,
      style: style || 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json',
      center: center || [120.9370, 14.3270],
      zoom: zoom || 12,
      minZoom: minZoom,
      maxZoom: maxZoom,
      maxBounds: maxBounds || [[120.78, 14.17], [121.10, 14.49]],
      attributionControl: false,
    });

    map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-left');

    map.on('load', () => {
      mapRef.current = map;
      setLoaded(true);
      if (onLoad) onLoad(map);
    });

    map.on('error', (e) => console.error('MapCN error:', e));

    return () => {
      map.remove();
      mapRef.current = null;
      setLoaded(false);
    };
  }, []);

  return (
    <MapContext.Provider value={mapRef}>
      <div ref={containerRef} className="mapcn-container" style={{ width: '100%', height: '100%' }}>
        {loaded && children}
      </div>
    </MapContext.Provider>
  );
}

export function MapControls({ position = 'top-right', showZoom = true, showCompass = false, showLocate = false, showFullscreen = false }) {
  const mapRef = useMap();
  const controlsAdded = useRef(false);

  useEffect(() => {
    const map = mapRef?.current;
    if (!map || controlsAdded.current) return;

    const controls = [];
    if (showZoom || showCompass) {
      const ctrl = new maplibregl.NavigationControl({ showCompass, showZoom });
      map.addControl(ctrl, position);
      controls.push(ctrl);
    }
    if (showLocate) {
      const ctrl = new maplibregl.GeolocateControl({
        positionOptions: { enableHighAccuracy: true },
        trackUserLocation: true,
      });
      map.addControl(ctrl, position);
      controls.push(ctrl);
    }
    if (showFullscreen) {
      const ctrl = new maplibregl.FullscreenControl();
      map.addControl(ctrl, position);
      controls.push(ctrl);
    }

    controlsAdded.current = true;

    return () => {
      controls.forEach(c => map.removeControl(c));
    };
  }, [mapRef, position, showZoom, showCompass, showLocate, showFullscreen]);

  return null;
}
