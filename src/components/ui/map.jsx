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

  const clearLoadingOverlay = useCallback(() => {
    const parent = containerRef.current?.parentElement;
    if (!parent) return;

    const loader = parent.querySelector('.mapcn-loading');
    if (loader) loader.remove();
    parent.classList.add('mapcn-ready');
  }, []);

  useEffect(() => {
    if (!containerRef.current || mapRef.current) return;

    try {
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
        clearLoadingOverlay();
        if (onLoad) onLoad(map);
      });

      map.on('error', (e) => {
        console.error('MapCN error:', e);
        const parent = containerRef.current?.parentElement;
        if (parent) {
          const loader = parent.querySelector('.mapcn-loading');
          if (loader) {
            loader.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Map failed to load. Please refresh the page.';
            loader.style.display = 'flex';
          }
          parent.classList.add('mapcn-error');
        }
      });

      return () => {
        map.remove();
        mapRef.current = null;
        setLoaded(false);
      };
    } catch (error) {
      console.error('MapCN: Failed to create map instance:', error);
      const parent = containerRef.current?.parentElement;
      if (parent) {
        const loader = parent.querySelector('.mapcn-loading');
        if (loader) {
          loader.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Map failed to initialize. Please refresh the page.';
        }
        parent.classList.add('mapcn-error');
      }
      return undefined;
    }
  }, [center, clearLoadingOverlay, maxBounds, maxZoom, minZoom, onLoad, style, zoom]);

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
