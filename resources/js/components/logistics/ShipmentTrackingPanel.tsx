import React, { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import type { CustomerDeliveryProof, TrackingShipment } from '@/types/logistics';

const titleCase = (value: string) =>
  value.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const awaitingConfirmationLabel = 'Delivered \u2014 confirmation in progress';
const customerStatus = (status: string) => status === 'awaiting_proof_approval'
  ? awaitingConfirmationLabel
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

function DeliveryProofDialog({
  proof,
  onClose,
}: {
  proof: CustomerDeliveryProof;
  onClose: () => void;
}) {
  const closeButton = useRef<HTMLButtonElement>(null);
  const [zoom, setZoom] = useState(1);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    closeButton.current?.focus();
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', closeOnEscape);

    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="delivery-proof-title"
      className="fixed inset-0 z-[100] flex items-center justify-center bg-black/75 p-0 sm:p-6"
    >
      <div className="flex h-full w-full flex-col overflow-hidden bg-white shadow-2xl sm:h-auto sm:max-h-[90vh] sm:max-w-5xl sm:rounded-2xl">
        <button
          ref={closeButton}
          type="button"
          aria-label="Close proof viewer"
          onClick={onClose}
          className="m-3 inline-flex min-h-11 min-w-11 items-center justify-center self-end rounded-lg border border-gray-300 px-3 text-xl font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
        >
          <span aria-hidden="true">{'\u00D7'}</span>
        </button>

        <div className="grid min-h-0 flex-1 gap-4 overflow-auto px-4 pb-5 md:grid-cols-[minmax(0,1fr)_20rem] sm:px-6">
          <section className="min-w-0">
            <h2 id="delivery-proof-title" className="text-xl font-bold text-gray-950">Proof of delivery</h2>
            <div className="mt-3 flex flex-wrap gap-2" role="group" aria-label="Proof zoom">
              {[1, 1.5, 2].map((level) => (
                <button
                  key={level}
                  type="button"
                  aria-label={`Zoom to ${level * 100}%`}
                  aria-pressed={zoom === level}
                  disabled={failed}
                  onClick={() => setZoom(level)}
                  className="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b] disabled:opacity-50"
                >
                  {level * 100}%
                </button>
              ))}
            </div>

            <div className="relative mt-3 grid min-h-72 place-items-center overflow-auto rounded-xl bg-gray-950 p-4" aria-live="polite">
              {failed ? (
                <p className="font-semibold text-white">Proof unavailable</p>
              ) : (
                <>
                  {loading && <p className="absolute font-semibold text-white">Loading proof...</p>}
                  <img
                    src={proof.url!}
                    alt={`Proof of delivery for ${proof.tracking_number}`}
                    onLoad={() => setLoading(false)}
                    onError={() => {
                      setLoading(false);
                      setFailed(true);
                    }}
                    className="max-h-[65vh] max-w-full object-contain transition-transform motion-reduce:transition-none"
                    style={{ transform: `scale(${zoom})`, transformOrigin: 'center' }}
                  />
                </>
              )}
            </div>
          </section>

          <aside className="rounded-xl border border-gray-200 bg-gray-50 p-4">
            <p className="inline-flex rounded-full border border-green-300 bg-green-50 px-3 py-1 text-sm font-bold text-green-900">
              {'\u2713'} {proof.status}
            </p>
            <dl className="mt-4 space-y-4">
              <div>
                <dt className="text-xs font-semibold uppercase text-gray-500">Delivered</dt>
                <dd className="mt-1 text-sm font-medium text-gray-900">{formatDate(proof.delivered_at)}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-gray-500">Delivery location</dt>
                <dd className="mt-1 text-sm font-medium text-gray-900">{proof.location}</dd>
              </div>
              <div>
                <dt className="text-xs font-semibold uppercase text-gray-500">Tracking number</dt>
                <dd className="mt-1 text-sm font-medium text-gray-900">{proof.tracking_number}</dd>
              </div>
            </dl>
            {!failed && (
              <a
                href={`${proof.url}?download=1`}
                download
                className="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-gray-950 px-4 text-sm font-bold text-white transition-colors hover:bg-[#16233b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
                aria-label="Download proof of delivery"
              >
                Download proof
              </a>
            )}
          </aside>
        </div>
      </div>
    </div>
  );
}

export type ShipmentTrackingPanelProps = {
  shipment: TrackingShipment;
  compact?: boolean;
};

export default function ShipmentTrackingPanel({
  shipment,
  compact = false,
}: ShipmentTrackingPanelProps) {
  const [failedProofIds, setFailedProofIds] = useState<number[]>([]);
  const [selectedProof, setSelectedProof] = useState<CustomerDeliveryProof | null>(null);
  const proofOpener = useRef<HTMLButtonElement | null>(null);
  const currentLeg = shipment.legs[shipment.legs.length - 1];
  const isReturn = shipment.purpose === 'refund_return';
  const isRepair = shipment.source_type === 'repair_request';
  const itemLabel = isReturn ? 'Return' : isRepair ? 'Repair Delivery' : 'Shipment';
  const trackingNumber = isReturn ? `RET-${shipment.id}` : (currentLeg?.tracking_number || `SHP-${shipment.id}`);
  const trackingUrl = isReturn ? `/tracking/shipments/${shipment.id}` : currentLeg?.tracking_url;
  const awaitingConfirmation = currentLeg?.status === 'awaiting_proof_approval';
  const closeProof = () => {
    setSelectedProof(null);
    queueMicrotask(() => proofOpener.current?.focus());
  };

  return (
    <div className={`userside-tracking-panel ${compact ? 'space-y-4' : 'space-y-6'}`}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-gray-500">{itemLabel} #{shipment.id}</p>
          <h1 className="text-2xl font-bold tracking-tight text-[#16233b]">{titleCase(shipment.purpose)}</h1>
        </div>
        <span className="w-fit rounded-full border border-gray-300 bg-white px-3 py-1 text-sm font-semibold text-gray-800">
          {awaitingConfirmation ? customerStatus(currentLeg?.status ?? '') : titleCase(shipment.status)}
        </span>
      </div>

      {awaitingConfirmation && (
        <section className="rounded-2xl border border-blue-200 bg-blue-50 p-5">
          <p className="font-semibold text-blue-900">{awaitingConfirmationLabel}</p>
          <p className="mt-1 text-sm leading-6 text-blue-800">Your item was handed over and the delivery proof is being verified.</p>
        </section>
      )}

      <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-[0_18px_50px_-35px_rgba(15,23,42,0.35)] sm:p-6">
        <div className="grid gap-5 md:grid-cols-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Current Leg</p>
            <p className="mt-1 text-sm font-semibold text-gray-900">{currentLeg ? titleCase(currentLeg.leg_type) : '-'}</p>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Tracking Number</p>
            <p className="mt-1 text-sm font-semibold text-gray-900">{trackingNumber}</p>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">{trackingUrl ? 'Tracking Link' : 'Delivery Method'}</p>
            {trackingUrl ? (
              <a className="mt-1 inline-flex min-h-11 items-center text-sm font-semibold text-[#16233b] underline decoration-gray-300 underline-offset-4 hover:decoration-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]" href={trackingUrl}>
                {isReturn ? 'Open return tracking' : 'Open courier page'}
              </a>
            ) : (
              <p className="mt-1 text-sm font-semibold text-gray-900">SoleSpace Shop Logistics</p>
            )}
          </div>
        </div>
      </section>

      {shipment.source_summary && (
        <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-[0_18px_50px_-35px_rgba(15,23,42,0.25)] sm:p-6">
          <div className="grid gap-5 md:grid-cols-3">
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Repair Request</p>
              <p className="mt-1 text-sm font-semibold text-gray-900">{shipment.source_summary.request_number}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Customer</p>
              <p className="mt-1 text-sm font-semibold text-gray-900">{shipment.source_summary.customer_name}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Shoe</p>
              <p className="mt-1 text-sm font-semibold text-gray-900">{shipment.source_summary.shoe_summary}</p>
            </div>
          </div>
        </section>
      )}

      {currentLeg?.schedule_status === 'scheduled' && (
        <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-[0_18px_50px_-35px_rgba(15,23,42,0.25)] sm:p-6">
          <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Estimated delivery</p>
          <p className="mt-1 text-base font-semibold text-gray-900">
            {formatDeliveryDate(currentLeg.scheduled_delivery_date)} {'\u00B7'} {titleCase(currentLeg.delivery_window || '')}
          </p>
        </section>
      )}

      <div className="space-y-5">
        {shipment.legs.filter((leg) => leg.latest_failed_attempt).map((leg) => {
          const attempt = leg.latest_failed_attempt!;
          const isPickupFailure = attempt.attempt_type === 'pickup';
          const isActiveFailure = leg.id === currentLeg?.id
            && !['awaiting_proof_approval', 'delivered'].includes(leg.status);
          const proofUnavailable = !attempt.proof_url || failedProofIds.includes(attempt.id);

          return (
            <section
              key={`failed-attempt-${attempt.id}`}
              className={`rounded-2xl border p-5 ${isActiveFailure ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}
            >
              <p className={`font-semibold ${isActiveFailure ? 'text-amber-900' : 'text-gray-900'}`}>
                {isActiveFailure
                  ? (isPickupFailure ? 'Pickup attempt unsuccessful' : 'Delivery Attempt Failed')
                  : (isPickupFailure ? 'Previous pickup attempt' : 'Previous delivery attempt')}
              </p>
              <p className="mt-1 text-sm leading-6 text-gray-700">{attempt.reason}</p>
              <p className="mt-1 text-xs text-gray-500">{formatDate(attempt.attempted_at)}</p>
              {proofUnavailable ? (
                <p className="mt-3 text-sm text-gray-500">Attempt photo unavailable</p>
              ) : (
                <a href={attempt.proof_url!} target="_blank" rel="noreferrer" className="mt-3 inline-block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]">
                  <img
                    src={attempt.proof_url!}
                    alt={isPickupFailure ? 'Failed pickup proof' : 'Failed delivery attempt proof'}
                    className="max-h-64 rounded-lg border border-gray-200 object-cover"
                    onError={() => setFailedProofIds((ids) => ids.includes(attempt.id) ? ids : [...ids, attempt.id])}
                  />
                </a>
              )}
            </section>
          );
        })}

        <section className="userside-tracking-section rounded-2xl border border-gray-200 bg-white shadow-[0_18px_50px_-35px_rgba(15,23,42,0.25)]">
          <div className="border-b border-gray-200 px-5 py-4 sm:px-6">
            <h2 className="text-base font-bold tracking-tight text-[#16233b]">{itemLabel} Movement</h2>
          </div>
          <div className="divide-y divide-gray-100">
            {shipment.legs.map((leg) => (
              <div key={leg.id} className="grid gap-4 px-5 py-5 md:grid-cols-[120px_1fr_1fr_160px]">
                <div className="text-sm font-semibold text-gray-900">{titleCase(leg.leg_type)}</div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">From</p>
                  <p className="mt-1 text-sm leading-6 text-gray-900">{snapshotText(leg.origin_snapshot)}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">To</p>
                  <p className="mt-1 text-sm leading-6 text-gray-900">{snapshotText(leg.destination_snapshot)}</p>
                </div>
                <div>
                  <p className="text-sm font-semibold text-gray-800">{customerStatus(leg.status)}</p>
                  {leg.delivery_proof?.available && leg.delivery_proof.url ? (
                    <button
                      type="button"
                      onClick={(event) => {
                        proofOpener.current = event.currentTarget;
                        setSelectedProof(leg.delivery_proof!);
                      }}
                      className="mt-2 inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-900 px-3 text-sm font-bold text-gray-950 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]"
                    >
                      View proof of delivery
                    </button>
                  ) : leg.delivery_proof ? (
                    <span role="status" className="mt-2 block text-sm font-semibold text-gray-500">
                      Proof unavailable
                    </span>
                  ) : null}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="userside-tracking-section rounded-2xl border border-gray-200 bg-white shadow-[0_18px_50px_-35px_rgba(15,23,42,0.25)]">
          <div className="border-b border-gray-200 px-5 py-4 sm:px-6">
            <h2 className="text-base font-bold tracking-tight text-[#16233b]">Updates</h2>
          </div>
          <div className="divide-y divide-gray-100">
            {shipment.events.length === 0 ? (
              <p className="px-5 py-6 text-sm text-gray-500">No customer-visible updates yet.</p>
            ) : (
              shipment.events.map((event) => (
                <div key={event.id} className="px-5 py-5">
                  <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm font-semibold text-gray-900">{titleCase(event.event_type)}</p>
                    <p className="text-xs text-gray-500">{formatDate(event.created_at)}</p>
                  </div>
                  {event.message ? <p className="mt-1 text-sm leading-6 text-gray-600">{event.message}</p> : null}
                </div>
              ))
            )}
          </div>
        </section>
      </div>

      {!compact && (
        <div className="pt-1">
          <Link href={isRepair ? '/my-repairs' : '/my-orders'} className="inline-flex min-h-11 items-center text-sm font-semibold text-black underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16233b]">
            {isRepair ? 'Back to repairs' : 'Back to orders'}
          </Link>
        </div>
      )}

      {selectedProof ? <DeliveryProofDialog proof={selectedProof} onClose={closeProof} /> : null}
    </div>
  );
}
