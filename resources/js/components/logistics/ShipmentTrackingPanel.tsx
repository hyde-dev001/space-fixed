import React, { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import LiveTrackingMap, { type LiveRiderLocation } from './LiveTrackingMap';
import type { CustomerDeliveryProof, TrackingShipment } from '@/types/logistics';

const titleCase = (value: string) =>
  value.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const awaitingConfirmationLabel = 'Delivered \u2014 confirmation in progress';
const customerStatus = (status: string) => ['awaiting_proof_approval', 'proof_correction_required'].includes(status)
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

const customerTrackingPollMs = 5000;
const trackableStatuses = ['in_transit'];
const formatDistance = (meters: number) => meters >= 1000
  ? `${(meters / 1000).toFixed(1)} km`
  : `${Math.round(meters)} m`;

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
          className="m-3 inline-flex min-h-11 min-w-11 items-center justify-center self-end rounded-lg border border-gray-300 px-3 text-xl font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white"
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
                  className="min-h-11 rounded-lg border border-gray-300 px-4 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white disabled:opacity-50"
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
            <p className="inline-flex rounded-full border border-slate-300 bg-slate-100 px-3 py-1 text-sm font-bold text-slate-950">
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
                className="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-gray-950 px-4 text-sm font-bold text-white transition-colors hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white"
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
  const [currentShipment, setCurrentShipment] = useState(shipment);
  const [pollError, setPollError] = useState(false);
  const proofOpener = useRef<HTMLButtonElement | null>(null);

  useEffect(() => setCurrentShipment(shipment), [shipment]);

  const currentLeg = currentShipment.legs[currentShipment.legs.length - 1];
  const liveTracking = currentLeg?.live_tracking ?? null;
  const shouldPoll = currentShipment.live_tracking_enabled === true
    && currentShipment.status === 'active'
    && trackableStatuses.includes(currentLeg?.status ?? '')
    && currentLeg?.destination_snapshot?.type === 'customer';

  useEffect(() => {
    if (!shouldPoll) {
      setPollError(false);
      return;
    }

    let disposed = false;
    const poll = async () => {
      try {
        const response = await axios.get<{ shipment?: typeof shipment }>(`/tracking/shipments/${shipment.id}`);
        if (disposed || !response.data.shipment) return;
        setCurrentShipment(response.data.shipment);
        setPollError(false);
      } catch {
        if (!disposed) setPollError(true);
      }
    };

    void poll();
    const timer = window.setInterval(poll, customerTrackingPollMs);
    return () => {
      disposed = true;
      window.clearInterval(timer);
    };
  }, [shipment.id, shouldPoll]);

  const isReturn = currentShipment.purpose === 'refund_return';
  const isRepair = currentShipment.source_type === 'repair_request';
  const shipmentNumber = currentShipment.shipment_number ?? currentShipment.id;
  const itemLabel = isReturn ? 'Return' : isRepair ? 'Repair Delivery' : 'Shipment';
  const trackingNumber = isReturn ? `RET-${currentShipment.id}` : (currentLeg?.tracking_number || `SHP-${currentShipment.id}`);
  const trackingUrl = isReturn ? `/tracking/shipments/${currentShipment.id}` : currentLeg?.tracking_url;
  const awaitingConfirmation = ['awaiting_proof_approval', 'proof_correction_required'].includes(currentLeg?.status ?? '');
  const roadRoute = liveTracking?.route?.source === 'direct' ? null : liveTracking?.route ?? null;
  const customerMapLocations: LiveRiderLocation[] = liveTracking ? [{
    leg_id: liveTracking.leg_id,
    shipment_id: currentShipment.id,
    shipment_number: shipmentNumber,
    shipment_reference: `Shipment #${shipmentNumber}`,
    rider: { id: null, name: 'Delivery' },
    status: liveTracking.status,
    destination: liveTracking.destination ?? {},
    location: liveTracking.location,
    stale: liveTracking.stale,
    route: roadRoute,
  }] : [];
  const closeProof = () => {
    setSelectedProof(null);
    queueMicrotask(() => proofOpener.current?.focus());
  };

  return (
    <div className={`userside-tracking-panel ${compact ? 'space-y-4' : 'space-y-6'}`}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-gray-500">{itemLabel} #{shipment.id}</p>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950">{titleCase(currentShipment.purpose)}</h1>
        </div>
        <span className="w-fit rounded-full border border-gray-300 bg-white px-3 py-1 text-sm font-semibold text-gray-800">
          {awaitingConfirmation ? customerStatus(currentLeg?.status ?? '') : titleCase(currentShipment.status)}
        </span>
      </div>

      {awaitingConfirmation && (
        <section className="rounded-2xl border border-slate-300 bg-slate-100 p-5">
          <p className="font-semibold text-slate-950">{awaitingConfirmationLabel}</p>
          <p className="mt-1 text-sm leading-6 text-slate-700">Your item was handed over and the delivery proof is being verified.</p>
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
              <a className="mt-1 inline-flex min-h-11 items-center text-sm font-semibold text-slate-950 underline decoration-gray-300 underline-offset-4 hover:decoration-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white" href={trackingUrl}>
                {isReturn ? 'Open return tracking' : 'Open courier page'}
              </a>
            ) : (
              <p className="mt-1 text-sm font-semibold text-gray-900">Shop rider delivery</p>
            )}
          </div>
        </div>
      </section>

      {currentShipment.source_summary && (
        <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-[0_18px_50px_-35px_rgba(15,23,42,0.25)] sm:p-6">
          <div className="grid gap-5 md:grid-cols-3">
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Repair Request</p>
              <p className="mt-1 text-sm font-semibold text-gray-900">{currentShipment.source_summary.request_number}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Customer</p>
              <p className="mt-1 text-sm font-semibold text-gray-900">{currentShipment.source_summary.customer_name}</p>
            </div>
            <div>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Shoe</p>
              <p className="mt-1 text-sm font-semibold text-gray-900">{currentShipment.source_summary.shoe_summary}</p>
            </div>
          </div>
        </section>
      )}

      {(liveTracking || shouldPoll) && (
        <section className="rounded-2xl border border-slate-300 bg-white p-5 shadow-[0_18px_50px_-35px_rgba(15,23,42,0.25)] sm:p-6" aria-live="polite">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h2 className="text-base font-bold tracking-tight text-slate-950">Live delivery location</h2>
              <p className="mt-1 text-sm text-gray-600">The map shows the latest available delivery position.</p>
            </div>
            {liveTracking?.stale && (
              <span className="w-fit rounded-full border border-slate-300 bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-950">
                Location may be out of date
              </span>
            )}
          </div>

          {liveTracking ? (
            <>
              <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Last update</p>
                  <p className="mt-1 text-sm font-semibold text-gray-900">{formatDate(liveTracking.location.recorded_at)}</p>
                </div>
                {roadRoute && (
                  <>
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">ETA</p>
                      <p className="mt-1 text-sm font-semibold text-gray-900">ETA {Math.max(1, Math.ceil(liveTracking.route.duration_s / 60))} min</p>
                    </div>
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Remaining distance</p>
                      <p className="mt-1 text-sm font-semibold text-gray-900">{formatDistance(liveTracking.route.distance_m)} remaining</p>
                    </div>
                  </>
                )}
              </div>
              <div className="mt-4 overflow-hidden rounded-xl border border-gray-200">
                <LiveTrackingMap locations={customerMapLocations} label="Customer delivery map" followLocation viewer="customer" />
              {!roadRoute && (
                <p role="status" className="mt-3 text-sm font-semibold text-gray-700">
                  Road route is temporarily unavailable. The rider location will continue updating.
                </p>
              )}
              </div>
            </>
          ) : (
            <p role="status" className="mt-4 text-sm font-semibold text-gray-700">
              Waiting for the rider's first GPS update.
            </p>
          )}

          {pollError && <p role="alert" className="mt-3 text-sm font-semibold text-slate-700">Live location is temporarily unavailable. Retrying...</p>}
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
            && !['awaiting_proof_approval', 'proof_correction_required', 'delivered'].includes(leg.status);
          const proofUnavailable = !attempt.proof_url || failedProofIds.includes(attempt.id);

          return (
            <section
              key={`failed-attempt-${attempt.id}`}
              className={`rounded-2xl border p-5 ${isActiveFailure ? 'border-slate-400 bg-slate-100' : 'border-gray-200 bg-white'}`}
            >
              <p className={`font-semibold ${isActiveFailure ? 'text-slate-950' : 'text-gray-900'}`}>
                {isActiveFailure
                  ? (isPickupFailure ? 'Pickup attempt unsuccessful' : 'Delivery Attempt Failed')
                  : (isPickupFailure ? 'Previous pickup attempt' : 'Previous delivery attempt')}
              </p>
              <p className="mt-1 text-sm leading-6 text-gray-700">{attempt.reason}</p>
              <p className="mt-1 text-xs text-gray-500">{formatDate(attempt.attempted_at)}</p>
              {proofUnavailable ? (
                <p className="mt-3 text-sm text-gray-500">Attempt photo unavailable</p>
              ) : (
                <a href={attempt.proof_url!} target="_blank" rel="noreferrer" className="mt-3 inline-block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white">
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
            <h2 className="text-base font-bold tracking-tight text-slate-950">{itemLabel} Movement</h2>
          </div>
          <div className="divide-y divide-gray-100">
            {currentShipment.legs.map((leg) => (
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
                      className="mt-2 inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-900 px-3 text-sm font-bold text-gray-950 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white"
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
            <h2 className="text-base font-bold tracking-tight text-slate-950">Updates</h2>
          </div>
          <div className="divide-y divide-gray-100">
            {currentShipment.events.length === 0 ? (
              <p className="px-5 py-6 text-sm text-gray-500">No customer-visible updates yet.</p>
            ) : (
              currentShipment.events.map((event) => (
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
          <Link href={isRepair ? '/my-repairs' : '/my-orders'} className="inline-flex min-h-11 items-center text-sm font-semibold text-black underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-950 dark:focus-visible:ring-white">
            {isRepair ? 'Back to repairs' : 'Back to orders'}
          </Link>
        </div>
      )}

      {selectedProof ? <DeliveryProofDialog proof={selectedProof} onClose={closeProof} /> : null}
    </div>
  );
}
