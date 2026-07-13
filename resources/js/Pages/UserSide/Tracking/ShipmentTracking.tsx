import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import type { TrackingShipment } from '@/types/logistics';

const titleCase = (value: string) =>
  value.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const formatDate = (value?: string | null) => {
  if (!value) return '-';
  return new Date(value).toLocaleString();
};

const formatDeliveryDate = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-US', { dateStyle: 'long' }).format(new Date(`${value}T00:00:00`))
  : '-';

const snapshotText = (snapshot?: Record<string, unknown> | null) => {
  if (!snapshot) return '-';
  return [snapshot.name, snapshot.address].filter(Boolean).join(' - ') || '-';
};

export default function ShipmentTracking() {
  const { shipment } = usePage<{ shipment: TrackingShipment }>().props;
  const currentLeg = shipment.legs[shipment.legs.length - 1];
  const isReturn = shipment.purpose === 'refund_return';
  const itemLabel = isReturn ? 'Return' : 'Shipment';
  const trackingNumber = isReturn ? `RET-${shipment.id}` : (currentLeg?.tracking_number || '-');
  const trackingUrl = isReturn ? `/tracking/shipments/${shipment.id}` : currentLeg?.tracking_url;

  return (
    <div className="min-h-screen bg-gray-50">
      <Head title={`${itemLabel} Tracking #${shipment.id}`} />
      <Navigation />

      <main className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-sm font-medium text-gray-500">{itemLabel} #{shipment.id}</p>
            <h1 className="text-2xl font-bold text-gray-950">{titleCase(shipment.purpose)}</h1>
          </div>
          <span className="w-fit rounded-full border border-gray-300 bg-white px-3 py-1 text-sm font-semibold text-gray-800">
            {titleCase(shipment.status)}
          </span>
        </div>

        <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
          <div className="grid gap-4 md:grid-cols-3">
            <div>
              <p className="text-xs font-semibold uppercase text-gray-500">Current Leg</p>
              <p className="mt-1 text-sm font-medium text-gray-900">{currentLeg ? titleCase(currentLeg.leg_type) : '-'}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase text-gray-500">Tracking Number</p>
              <p className="mt-1 text-sm font-medium text-gray-900">{trackingNumber}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase text-gray-500">Tracking Link</p>
              {trackingUrl ? (
                <a className="mt-1 inline-block text-sm font-semibold text-black underline" href={trackingUrl}>
                  {isReturn ? 'Open return tracking' : 'Open courier page'}
                </a>
              ) : (
                <p className="mt-1 text-sm font-medium text-gray-900">-</p>
              )}
            </div>
          </div>
        </section>

        {currentLeg?.schedule_status === 'scheduled' && (
          <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase text-gray-500">Estimated delivery</p>
            <p className="mt-1 text-sm font-semibold text-gray-900">
              {formatDeliveryDate(currentLeg.scheduled_delivery_date)} · {titleCase(currentLeg.delivery_window || '')}
            </p>
          </section>
        )}

        <section className="mb-6 rounded-lg border border-gray-200 bg-white">
          <div className="border-b border-gray-200 px-5 py-4">
            <h2 className="text-base font-bold text-gray-950">{itemLabel} Movement</h2>
          </div>
          <div className="divide-y divide-gray-100">
            {shipment.legs.map((leg) => (
              <div key={leg.id} className="grid gap-4 px-5 py-4 md:grid-cols-[120px_1fr_1fr_120px]">
                <div className="text-sm font-semibold text-gray-900">{titleCase(leg.leg_type)}</div>
                <div>
                  <p className="text-xs uppercase text-gray-500">From</p>
                  <p className="text-sm text-gray-900">{snapshotText(leg.origin_snapshot)}</p>
                </div>
                <div>
                  <p className="text-xs uppercase text-gray-500">To</p>
                  <p className="text-sm text-gray-900">{snapshotText(leg.destination_snapshot)}</p>
                </div>
                <div className="text-sm font-semibold text-gray-800">{titleCase(leg.status)}</div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-lg border border-gray-200 bg-white">
          <div className="border-b border-gray-200 px-5 py-4">
            <h2 className="text-base font-bold text-gray-950">Updates</h2>
          </div>
          <div className="divide-y divide-gray-100">
            {shipment.events.length === 0 ? (
              <p className="px-5 py-6 text-sm text-gray-500">No customer-visible updates yet.</p>
            ) : (
              shipment.events.map((event) => (
                <div key={event.id} className="px-5 py-4">
                  <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm font-semibold text-gray-900">{titleCase(event.event_type)}</p>
                    <p className="text-xs text-gray-500">{formatDate(event.created_at)}</p>
                  </div>
                  {event.message && <p className="mt-1 text-sm text-gray-600">{event.message}</p>}
                </div>
              ))
            )}
          </div>
        </section>

        <div className="mt-6">
          <Link href="/my-orders" className="text-sm font-semibold text-black underline">
            Back to orders
          </Link>
        </div>
      </main>
    </div>
  );
}
