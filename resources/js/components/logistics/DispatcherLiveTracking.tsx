import { useEffect, useState } from 'react';
import { logisticsApi } from '@/services/logisticsApi';
import LiveTrackingMap, { type LiveRiderLocation } from './LiveTrackingMap';

type Props = {
  enabled: boolean;
  pollIntervalSeconds?: number;
};

const formatTime = (value: string | null) => value
  ? new Intl.DateTimeFormat([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value))
  : 'Unknown';

const statusLabel = (value: string | null) => value
  ? value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase())
  : 'Active';

const formatDistance = (meters: number) => meters >= 1000
  ? `${(meters / 1000).toFixed(1)} km`
  : `${Math.round(meters)} m`;

export default function DispatcherLiveTracking({ enabled, pollIntervalSeconds = 5 }: Props) {
  const [locations, setLocations] = useState<LiveRiderLocation[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);

  useEffect(() => {
    if (!enabled) return undefined;
    let disposed = false;

    const load = async () => {
      if (disposed) return;
      setLoading(true);

      try {
        const response = await logisticsApi.liveLocations();
        if (disposed) return;
        setLocations(Array.isArray(response.data.locations) ? response.data.locations : []);
        setLastUpdated(response.data.server_time ?? new Date().toISOString());
        setError(null);
      } catch {
        if (!disposed) setError('Live rider locations are temporarily unavailable.');
      } finally {
        if (!disposed) setLoading(false);
      }
    };

    void load();
    const interval = window.setInterval(load, Math.max(5, pollIntervalSeconds) * 1000);

    return () => {
      disposed = true;
      window.clearInterval(interval);
    };
  }, [enabled, pollIntervalSeconds]);

  if (!enabled) return null;

  return (
    <section aria-labelledby="live-rider-tracking-heading" className="space-y-4 rounded-2xl border border-slate-300 bg-slate-100/80 p-4 dark:border-slate-700 dark:bg-slate-900">
      <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 id="live-rider-tracking-heading" className="text-lg font-bold text-gray-950 dark:text-white">Live rider tracking</h2>
          <p className="text-sm text-gray-600 dark:text-gray-300">Active shop-owned deliveries only.</p>
        </div>
        <p role="status" aria-live="polite" className="text-xs text-gray-600 dark:text-gray-300">
          {loading ? 'Refreshing...' : lastUpdated ? `Last sync ${formatTime(lastUpdated)}` : 'Waiting for first sync'}
        </p>
      </div>

      {error && <p role="alert" className="rounded-lg bg-slate-200 px-3 py-2 text-sm text-slate-950 dark:bg-slate-800 dark:text-white">{error}</p>}
      {locations.length > 0 && <LiveTrackingMap locations={locations} />}

      {locations.length === 0 ? (
        <p className="rounded-lg bg-white/80 px-3 py-4 text-sm text-gray-600 dark:bg-gray-900/50 dark:text-gray-300">
          {loading ? 'Loading active rider locations...' : 'No active rider locations are available.'}
        </p>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2" aria-label="Active rider locations">
          {locations.map((entry) => (
            <li key={entry.leg_id} className="rounded-xl bg-white p-3 text-sm dark:bg-gray-900">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-semibold text-gray-950 dark:text-white">{entry.rider.name ?? 'Rider'}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">{entry.shipment_reference ?? `Shipment #${entry.shipment_id ?? '-'}`}</p>
                </div>
                <span className="font-semibold text-slate-950 dark:text-white">
                  {entry.stale ? 'Stale location' : statusLabel(entry.status)}
                </span>
              </div>
              <p className="mt-2 text-gray-600 dark:text-gray-300">{entry.destination.address ?? entry.destination.name ?? 'Destination not provided'}</p>
              <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Last GPS update {formatTime(entry.location.recorded_at)}</p>
              {entry.route && entry.route.source !== 'direct' && (
                <p className="mt-1 text-xs font-semibold text-gray-700 dark:text-gray-300">
                  ETA {Math.max(1, Math.ceil(entry.route.duration_s / 60))} min - {formatDistance(entry.route.distance_m)} remaining
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
