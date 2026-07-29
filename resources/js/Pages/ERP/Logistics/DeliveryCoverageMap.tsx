import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';

type Props = {
  latitude: number;
  longitude: number;
  radiusKm: number;
};

export default function DeliveryCoverageMap({ latitude, longitude, radiusKm }: Props) {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!containerRef.current) return;
    let disposed = false;
    let map: import('leaflet').Map | undefined;

    void import('leaflet').then((L) => {
      if (disposed || !containerRef.current) return;

      map = L.map(containerRef.current, { dragging: true, scrollWheelZoom: false })
        .setView([latitude, longitude], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
      }).addTo(map);
      L.circleMarker([latitude, longitude], {
        radius: 7,
        color: '#1d4ed8',
        fillColor: '#2563eb',
        fillOpacity: 1,
      }).addTo(map).bindTooltip('Saved shop location');
      const circle = L.circle([latitude, longitude], {
        radius: Math.max(radiusKm, 0) * 1000,
        color: '#2563eb',
        fillColor: '#60a5fa',
        fillOpacity: 0.18,
      }).addTo(map);
      map.fitBounds(circle.getBounds(), { padding: [20, 20] });
    });

    return () => {
      disposed = true;
      map?.remove();
    };
  }, [latitude, longitude, radiusKm]);

  return <div ref={containerRef} className="h-72 w-full rounded-xl" aria-label="Delivery service area map" />;
}
