import 'leaflet/dist/leaflet.css';
import { useEffect, useRef, useState } from 'react';
import type { LiveTrackingRoute } from '@/types/logistics';

export type LiveRiderLocation = {
  leg_id: number;
  shipment_id: number | null;
  shipment_reference: string | null;
  rider: { id: number | null; name: string | null };
  status: string | null;
  destination: {
    type?: string | null;
    name?: string | null;
    address?: string | null;
    latitude?: number | null;
    longitude?: number | null;
  };
  location: {
    latitude: number;
    longitude: number;
    accuracy_m: number | null;
    speed_mps: number | null;
    heading_deg: number | null;
    recorded_at: string | null;
    received_at: string | null;
  };
  stale: boolean;
  route?: LiveTrackingRoute | null;
};

type Props = {
  locations: LiveRiderLocation[];
  label?: string;
  followLocation?: boolean;
  viewer?: 'rider' | 'customer';
};

const validLocations = (locations: LiveRiderLocation[]) => locations.filter(({ location }) => (
  Number.isFinite(location.latitude)
  && Number.isFinite(location.longitude)
  && location.latitude >= -90
  && location.latitude <= 90
  && location.longitude >= -180
  && location.longitude <= 180
));

const destinationPoint = (entry: LiveRiderLocation): [number, number] | null => {
  const latitude = entry.destination.latitude;
  const longitude = entry.destination.longitude;

  return typeof latitude === 'number'
    && typeof longitude === 'number'
    && Number.isFinite(latitude)
    && Number.isFinite(longitude)
    && latitude >= -90
    && latitude <= 90
    && longitude >= -180
    && longitude <= 180
    ? [latitude, longitude]
    : null;
};

const formatDistance = (meters: number): string => meters >= 1000
  ? `${(meters / 1000).toFixed(1)} km`
  : `${Math.round(meters)} m`;

const formatEta = (seconds: number): string => `${Math.max(1, Math.ceil(seconds / 60))} min`;

export default function LiveTrackingMap({ locations, label = 'Live rider map', followLocation = false, viewer = 'customer' }: Props) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<import('leaflet').Map | null>(null);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const markersRef = useRef(new Map<number, import('leaflet').CircleMarker>());
  const destinationMarkersRef = useRef(new Map<number, import('leaflet').CircleMarker>());
  const routesRef = useRef(new Map<number, import('leaflet').Polyline>());
  const [mapReady, setMapReady] = useState(false);
  const hasFittedRef = useRef(false);

  useEffect(() => {
    if (!containerRef.current) return;
    let disposed = false;
    let map: import('leaflet').Map | null = null;
    let resizeObserver: ResizeObserver | null = null;

    void import('leaflet').then((L) => {
      if (disposed || !containerRef.current) return;

      const container = containerRef.current;
      map = L.map(container, { scrollWheelZoom: false }).setView([14.5995, 120.9842], 12);
      const tileLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
      });
      tileLayer.addTo(map);
      leafletRef.current = L;
      mapRef.current = map;
      if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
          map?.invalidateSize({ pan: false, debounceMoveend: true });
        });
        resizeObserver.observe(container);
      }
      setMapReady(true);
      window.setTimeout(() => {
        map?.invalidateSize({ pan: false, debounceMoveend: true });
      }, 0);
    });

    return () => {
      disposed = true;
      markersRef.current.clear();
      destinationMarkersRef.current.clear();
      routesRef.current.clear();
      resizeObserver?.disconnect();
      resizeObserver = null;
      map?.remove();
      mapRef.current = null;
      leafletRef.current = null;
    };
  }, []);


  useEffect(() => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!mapReady || !L || !map) return;

    const visible = validLocations(locations);
    if (visible.length === 0) hasFittedRef.current = false;
    const visibleIds = new Set(visible.map(({ leg_id }) => leg_id));

    markersRef.current.forEach((marker, legId) => {
      if (!visibleIds.has(legId)) {
        marker.removeFrom(map);
        markersRef.current.delete(legId);
      }
    });
    destinationMarkersRef.current.forEach((marker, legId) => {
      if (!visibleIds.has(legId)) {
        marker.removeFrom(map);
        destinationMarkersRef.current.delete(legId);
      }
    });
    routesRef.current.forEach((route, legId) => {
      if (!visibleIds.has(legId)) {
        route.removeFrom(map);
        routesRef.current.delete(legId);
      }
    });

    visible.forEach((entry) => {
      const point: [number, number] = [entry.location.latitude, entry.location.longitude];
      const tooltip = `${entry.rider.name ?? 'Delivery'}${entry.stale ? ' - Stale location' : ''}`;
      const existing = markersRef.current.get(entry.leg_id);

      if (existing) {
        existing
          .setLatLng(point)
          .setStyle({
            color: entry.stale ? '#64748b' : '#ffffff',
            fillColor: entry.stale ? '#cbd5e1' : '#1677e8',
            weight: 3,
          })
          .unbindTooltip()
          .bindTooltip(tooltip);
      } else {
        const marker = L.circleMarker(point, {
          radius: 8,
          color: entry.stale ? '#64748b' : '#ffffff',
          fillColor: entry.stale ? '#cbd5e1' : '#1677e8',
          weight: 3,
          fillOpacity: 0.9,
        }).addTo(map).bindTooltip(tooltip);
        markersRef.current.set(entry.leg_id, marker);
      }

      const destination = destinationPoint(entry);
      const existingDestination = destinationMarkersRef.current.get(entry.leg_id);
      if (destination) {
        const destinationLabel = `Destination${entry.destination.address ? `: ${entry.destination.address}` : ''}`;
        if (existingDestination) {
          existingDestination
            .setLatLng(destination)
            .setStyle({
              color: '#ffffff',
              fillColor: '#075985',
              weight: 3,
            })
            .unbindTooltip()
            .bindTooltip(destinationLabel);
        } else {
          const marker = L.circleMarker(destination, {
            radius: 7,
            color: '#ffffff',
            fillColor: '#075985',
            weight: 3,
            fillOpacity: 0.85,
          }).addTo(map).bindTooltip(destinationLabel);
          destinationMarkersRef.current.set(entry.leg_id, marker);
        }
      } else if (existingDestination) {
        existingDestination.removeFrom(map);
        destinationMarkersRef.current.delete(entry.leg_id);
      }

      const geometry = entry.route?.source === 'direct' ? [] : entry.route?.geometry ?? [];
      const existingRoute = routesRef.current.get(entry.leg_id);
      if (geometry.length >= 2) {
        if (existingRoute) {
          existingRoute.setLatLngs(geometry).setStyle({
            color: entry.stale ? '#94a3b8' : '#1677e8',
          });
        } else {
          routesRef.current.set(entry.leg_id, L.polyline(geometry, {
            color: entry.stale ? '#94a3b8' : '#1677e8',
            weight: 5,
            opacity: 0.9,
          }).addTo(map));
        }
      } else if (existingRoute) {
        existingRoute.removeFrom(map);
        routesRef.current.delete(entry.leg_id);
      }
    });

    if (followLocation && hasFittedRef.current && visible.length > 0) {
      const first = visible[0];
      map.panTo([first.location.latitude, first.location.longitude], { animate: true, duration: 0.25 });
    }

    if (visible.length > 0 && !hasFittedRef.current) {
      const boundsPoints = visible.flatMap((entry) => {
        const current: [number, number] = [entry.location.latitude, entry.location.longitude];
        const destination = destinationPoint(entry);
        return destination ? [current, destination] : [current];
      });
      map.fitBounds(L.latLngBounds(boundsPoints), { padding: [24, 24], maxZoom: 15 });
      hasFittedRef.current = true;
    }
  }, [followLocation, locations, mapReady]);

  const visibleLocations = validLocations(locations);
  const primaryLocation = visibleLocations[0] ?? null;
  const primaryRoute = primaryLocation?.route ?? null;
  const primaryDestination = primaryLocation?.destination.address
    ?? primaryLocation?.destination.name
    ?? 'Delivery destination';
  const primaryViewerLabel = viewer === 'rider' ? 'You' : primaryLocation?.rider.name ?? 'Delivery rider';
  const primaryViewerDetail = viewer === 'rider'
    ? primaryLocation?.location.accuracy_m !== null && primaryLocation?.location.accuracy_m !== undefined
      ? `Current GPS location · ${formatDistance(primaryLocation.location.accuracy_m)} accuracy`
      : 'Current GPS location'
    : primaryDestination;
  return (
    <div className="relative w-full">
      <div ref={containerRef} className="h-72 w-full isolate overflow-hidden rounded-xl bg-gray-200 [&_.leaflet-control-zoom_a]:!h-11 [&_.leaflet-control-zoom_a]:!w-11 sm:h-96 dark:bg-slate-800" aria-label={label} />
    </div>
  );
}
