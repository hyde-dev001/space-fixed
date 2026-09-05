import React from 'react';
import { CheckCircle2, MapPinOff, Satellite, WifiOff } from 'lucide-react';
import type { DeliveryArrival } from '@/types/logistics';

const resultDetails = {
  verified: { label: 'Verified arrival', Icon: CheckCircle2, className: 'text-emerald-700 dark:text-emerald-300' },
  outside_geofence: { label: 'Outside geofence', Icon: MapPinOff, className: 'text-amber-700 dark:text-amber-300' },
  low_accuracy: { label: 'Low GPS accuracy', Icon: Satellite, className: 'text-amber-700 dark:text-amber-300' },
  location_unavailable: { label: 'Location unavailable', Icon: WifiOff, className: 'text-red-700 dark:text-red-300' },
  recorded: { label: 'Pickup recorded', Icon: CheckCircle2, className: 'text-gray-700 dark:text-gray-300' },
};

const label = (value?: string | null) => {
  const words = value?.replaceAll('_', ' ');
  return words ? words[0].toUpperCase() + words.slice(1) : null;
};

const timestamp = (value: string) =>
  new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));

function ArrivalRow({ type, arrival }: { type: 'pickup' | 'dropoff'; arrival: DeliveryArrival }) {
  const { label: resultLabel, Icon, className } = resultDetails[arrival.result];
  const place = type === 'pickup' ? 'pickup' : 'customer';

  return <div className="grid gap-1 rounded-lg bg-gray-50 p-3 sm:grid-cols-[8rem_1fr] dark:bg-gray-900/50">
    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
      {type === 'pickup' ? 'Pickup arrival' : 'Customer arrival'}
    </p>
    <div className="min-w-0 text-xs text-gray-600 dark:text-gray-300">
      <p className={`inline-flex items-center gap-1 font-semibold ${className}`}><Icon aria-hidden="true" size={15} />{resultLabel}</p>
      <div className="mt-1 flex flex-wrap gap-x-2 gap-y-1">
        {arrival.distance_m != null && <span>{Math.round(arrival.distance_m)} m from {place}</span>}
        <span>{timestamp(arrival.recorded_at)}</span>
        {arrival.exception_reason && <span>{label(arrival.exception_reason)}</span>}
      </div>
      {arrival.exception_notes && <p className="mt-1">{arrival.exception_notes}</p>}
    </div>
  </div>;
}

export default function ArrivalSummary({ arrivals }: { arrivals?: Partial<Record<'pickup' | 'dropoff', DeliveryArrival>> }) {
  if (!arrivals?.pickup && !arrivals?.dropoff) return null;

  return <div className="mt-3 space-y-2" aria-label="Arrival checks">
    {arrivals.pickup && <ArrivalRow type="pickup" arrival={arrivals.pickup} />}
    {arrivals.dropoff && <ArrivalRow type="dropoff" arrival={arrivals.dropoff} />}
  </div>;
}
