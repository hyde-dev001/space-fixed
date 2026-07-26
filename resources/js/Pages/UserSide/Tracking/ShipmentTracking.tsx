import React, { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import type { TrackingShipment } from '@/types/logistics';

const titleCase = (value: string) =>
  value.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const customerStatus = (status: string) => status === 'awaiting_proof_approval'
  ? 'Delivered — confirmation in progress'
  : titleCase(status);

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
  const [failedProofIds, setFailedProofIds] = useState<number[]>([]);
  const currentLeg = shipment.legs[shipment.legs.length - 1];
  const isReturn = shipment.purpose === 'refund_return';
  const isRepair = shipment.source_type === 'repair_request';
  const itemLabel = isReturn ? 'Return' : isRepair ? 'Repair Delivery' : 'Shipment';
  const internalTrackingUrl = `/tracking/shipments/${shipment.id}`;
  const trackingNumber = isReturn ? `RET-${shipment.id}` : (currentLeg?.tracking_number || `SHP-${shipment.id}`);
  const trackingUrl = isReturn ? internalTrackingUrl : currentLeg?.tracking_url;
  const awaitingConfirmation = currentLeg?.status === 'awaiting_proof_approval';

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
            {awaitingConfirmation ? customerStatus(currentLeg.status) : titleCase(shipment.status)}
          </span>
        </div>

        {awaitingConfirmation && (
          <section className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-5">
            <p className="font-semibold text-blue-900">Delivered — confirmation in progress</p>
            <p className="mt-1 text-sm text-blue-800">Your item was handed over and the delivery proof is being verified.</p>
          </section>
        )}

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
              <p className="text-xs font-semibold uppercase text-gray-500">{trackingUrl ? 'Tracking Link' : 'Delivery Method'}</p>
              {trackingUrl ? (
                <a className="mt-1 inline-block text-sm font-semibold text-black underline" href={trackingUrl}>
                  {isReturn ? 'Open return tracking' : 'Open courier page'}
                </a>
              ) : (
                <p className="mt-1 text-sm font-medium text-gray-900">SoleSpace Shop Logistics</p>
              )}
            </div>
          </div>
        </section>

        {shipment.source_summary && (
          <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
            <div className="grid gap-4 md:grid-cols-3">
              <div>
                <p className="text-xs font-semibold uppercase text-gray-500">Repair Request</p>
                <p className="mt-1 text-sm font-medium text-gray-900">{shipment.source_summary.request_number}</p>
              </div>
              <div>
                <p className="text-xs font-semibold uppercase text-gray-500">Customer</p>
                <p className="mt-1 text-sm font-medium text-gray-900">{shipment.source_summary.customer_name}</p>
              </div>
              <div>
                <p className="text-xs font-semibold uppercase text-gray-500">Shoe</p>
                <p className="mt-1 text-sm font-medium text-gray-900">{shipment.source_summary.shoe_summary}</p>
              </div>
            </div>
          </section>
        )}

        {currentLeg?.schedule_status === 'scheduled' && (
          <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase text-gray-500">Estimated delivery</p>
            <p className="mt-1 text-sm font-semibold text-gray-900">
              {formatDeliveryDate(currentLeg.scheduled_delivery_date)} · {titleCase(currentLeg.delivery_window || '')}
            </p>
          </section>
        )}

        {shipment.legs.filter((leg) => leg.latest_failed_attempt).map((leg) => {
          const attempt = leg.latest_failed_attempt!;
          const isActiveFailure = leg.id === currentLeg?.id
            && !['awaiting_proof_approval', 'delivered'].includes(leg.status);
          const proofUnavailable = !attempt.proof_url || failedProofIds.includes(attempt.id);

          return (
            <section
              key={`failed-attempt-${attempt.id}`}
              className={`mb-6 rounded-lg border p-5 ${isActiveFailure ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}
            >
              <p className={`font-semibold ${isActiveFailure ? 'text-amber-900' : 'text-gray-900'}`}>
                {isActiveFailure ? 'Delivery Attempt Failed' : 'Previous delivery attempt'}
              </p>
              <p className="mt-1 text-sm text-gray-700">{attempt.reason}</p>
              <p className="mt-1 text-xs text-gray-500">{formatDate(attempt.attempted_at)}</p>
              {proofUnavailable ? (
                <p className="mt-3 text-sm text-gray-500">Attempt photo unavailable</p>
              ) : (
                <a href={attempt.proof_url!} target="_blank" rel="noreferrer" className="mt-3 inline-block">
                  <img
                    src={attempt.proof_url!}
                    alt="Failed delivery attempt proof"
                    className="max-h-64 rounded-lg border border-gray-200 object-cover"
                    onError={() => setFailedProofIds((ids) => ids.includes(attempt.id) ? ids : [...ids, attempt.id])}
                  />
                </a>
              )}
            </section>
          );
        })}

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
                <div className="text-sm font-semibold text-gray-800">{customerStatus(leg.status)}</div>
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
          <Link href={isRepair ? '/my-repairs' : '/my-orders'} className="text-sm font-semibold text-black underline">
            {isRepair ? 'Back to repairs' : 'Back to orders'}
          </Link>
        </div>
      </main>
    </div>
  );
}
