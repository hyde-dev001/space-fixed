import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type {
  RiderDeliveryIssue,
  RiderDeliveryPageData,
  RiderDeliveryTab,
  RiderDeliveryWorkItem,
  TrackingShipmentLeg,
} from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useEffect, useRef, useState } from 'react';
import {
  arrivalStatusText,
  completedProgress,
  deliveryContact,
  deliveryStatusLabel,
  nextActionableDelivery,
  orderedDeliveries,
  riderResolutionInstruction,
} from './riderDeliveryPresentation';

type ActionConfirmation = Parameters<typeof workflowFeedback.confirm>[0];
type ActionRunner = (
  key: string,
  action: () => Promise<unknown>,
  confirmation?: ActionConfirmation,
  onError?: (error: unknown) => boolean,
) => void;

const arrivalReasons = [
  ['gps_inaccurate', 'GPS location is inaccurate'],
  ['pin_incorrect', 'Shop or customer pin is incorrect'],
  ['alternate_meeting_point', 'Met at another location'],
  ['access_restriction', 'Road or access restriction'],
  ['safety_concern', 'Safety concern'],
  ['other', 'Other'],
] as const;

const photoIssueReasons = new Set([
  'recipient_unavailable',
  'wrong_or_incomplete_address',
  'recipient_refused',
  'item_damaged',
]);

const noteIssueReasons = new Set([
  'unsafe_location',
  'vehicle_or_delivery_problem',
  'other',
]);

const currentPosition = () => new Promise<GeolocationPosition>((resolve, reject) => {
  if (!navigator.geolocation) {
    reject(new Error('Location is unavailable on this device.'));
    return;
  }
  navigator.geolocation.getCurrentPosition(resolve, reject, {
    enableHighAccuracy: true,
    timeout: 10_000,
    maximumAge: 0,
  });
});

const needsArrivalReason = (error: unknown) => {
  const response = (error as {
    response?: { status?: number; data?: { errors?: Record<string, string[]> } };
  })?.response;

  return Boolean(
    (response?.status === 422 && response.data?.errors?.exception_reason) ||
    (typeof error === 'object' && error !== null && 'code' in error) ||
    (error instanceof Error && error.message === 'Location is unavailable on this device.'),
  );
};

const tabLabels: Record<RiderDeliveryTab, string> = {
  upcoming: 'Upcoming',
  history: 'History',
  issues: 'Issues',
  all: 'All',
};

const itemTitle = (item: RiderDeliveryWorkItem) =>
  item.kind === 'batch' ? `Batch #${item.id}` : `Single delivery #${item.id}`;

const deliveryCount = (item: RiderDeliveryWorkItem) =>
  `${item.deliveries.length} ${item.deliveries.length === 1 ? 'delivery' : 'deliveries'}`;

const scheduleText = (item: RiderDeliveryWorkItem) => {
  const date = item.delivery_date ? String(item.delivery_date).split('T')[0] : 'Not scheduled';
  const window = item.delivery_window
    ? item.delivery_window.replace(/\b\w/g, (letter) => letter.toUpperCase())
    : null;

  return window ? `${date} · ${window}` : date;
};

function StatusChip({ status }: { status: string }) {
  const symbol = ['delivered', 'completed'].includes(status)
    ? '✓'
    : ['cancelled', 'declined', 'delivery_attempted'].includes(status)
      ? '!'
      : '•';

  return (
    <span className="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100">
      <span aria-hidden="true">{symbol}</span>
      {deliveryStatusLabel(status)}
    </span>
  );
}

function ResolutionNotice({ delivery }: { delivery: TrackingShipmentLeg }) {
  const instruction = riderResolutionInstruction(delivery);
  if (!instruction) return null;

  return (
    <p
      role="status"
      className="mt-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
    >
      <span aria-hidden="true">!</span> {instruction}
    </p>
  );
}

function DeliveryContact({ delivery }: { delivery: TrackingShipmentLeg }) {
  const contact = deliveryContact(delivery);

  return (
    <div className="space-y-3">
      <div>
        <p className="text-base font-bold text-slate-950 dark:text-white">
          {contact.name || 'Customer details unavailable'}
        </p>
        <p className="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300">
          {contact.address || 'Address not provided'}
        </p>
        {contact.instructions && (
          <p className="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
            <strong>Instruction:</strong> {contact.instructions}
          </p>
        )}
      </div>

      <div className="grid grid-cols-2 gap-2">
        {contact.phone ? (
          <a
            href={`tel:${contact.phone}`}
            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-3 text-sm font-semibold text-slate-800 dark:border-slate-600 dark:text-slate-100"
          >
            Call
          </a>
        ) : (
          <span className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 px-3 text-sm text-slate-400">
            No phone
          </span>
        )}
        {contact.address ? (
          <a
            href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(contact.address)}`}
            target="_blank"
            rel="noreferrer"
            className="inline-flex min-h-11 items-center justify-center rounded-xl border border-blue-300 px-3 text-sm font-semibold text-blue-700 dark:border-blue-700 dark:text-blue-300"
          >
            Directions
          </a>
        ) : (
          <span className="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 px-3 text-sm text-slate-400">
            No address
          </span>
        )}
      </div>
    </div>
  );
}

function DeliverySequence({ item }: { item: RiderDeliveryWorkItem }) {
  return (
    <ol data-testid="delivery-sequence" className="mt-4 space-y-2 border-t border-slate-200 pt-4 dark:border-slate-700">
      {orderedDeliveries(item.deliveries).map((delivery, index) => {
        const contact = deliveryContact(delivery);
        const sequence = delivery.stop_sequence ?? index + 1;
        const symbol = delivery.status === 'delivered' ? '✓' : delivery.status === 'delivery_attempted' ? '!' : sequence;

        return (
          <li key={delivery.id} className="flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white text-sm font-bold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700">
              {symbol}
            </span>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-slate-950 dark:text-white">
                  Delivery #{delivery.id} · Stop {sequence}
                </p>
                <StatusChip status={delivery.status} />
              </div>
              <ResolutionNotice delivery={delivery} />
              <p className="mt-1 truncate text-sm text-slate-600 dark:text-slate-300">
                {contact.name || 'Customer'} · {contact.address || 'Address unavailable'}
              </p>
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function DeliveryActions({
  item,
  delivery,
  locked,
  online,
  pendingAction,
  canRecordProof,
  runAction,
}: {
  item: RiderDeliveryWorkItem;
  delivery?: TrackingShipmentLeg;
  locked: boolean;
  online: boolean;
  pendingAction: string | null;
  canRecordProof: boolean;
  runAction: ActionRunner;
}) {
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [showIssue, setShowIssue] = useState(false);
  const [issueReason, setIssueReason] = useState('');
  const [issueNotes, setIssueNotes] = useState('');
  const [issueFile, setIssueFile] = useState<File | null>(null);
  const [showArrivalReason, setShowArrivalReason] = useState(false);
  const [arrivalReason, setArrivalReason] = useState('');
  const [arrivalNotes, setArrivalNotes] = useState('');
  const requiresIssuePhoto = photoIssueReasons.has(issueReason);
  const requiresIssueNotes = noteIssueReasons.has(issueReason);
  const arrivalEvidence = useRef<Record<string, unknown> | null>(null);
  const mutationDisabled = locked || !online || pendingAction !== null;
  const buttonClass =
    'min-h-11 w-full rounded-xl bg-blue-600 px-4 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50';

  if (item.kind === 'batch' && item.status === 'accepted') {
    const key = `batch-start:${item.id}`;

    return (
      <button
        type="button"
        disabled={mutationDisabled || pendingAction === key}
        onClick={() =>
          runAction(key, () => logisticsApi.startBatch(item.id), {
            title: `Start batch #${item.id}?`,
            text: 'This will begin the batch and make its first stop active.',
            confirmButtonText: 'Start batch',
          })
        }
        className={buttonClass}
      >
        Start batch
      </button>
    );
  }

  if (!delivery) return null;
  const deliveryReference =
    item.kind === 'batch'
      ? `stop ${delivery.stop_sequence ?? delivery.id} in batch #${item.id}`
      : `delivery #${delivery.id}`;
  const arrivalPhase = ['assigned', 'pickup_scheduled'].includes(delivery.status)
    ? 'pickup'
    : delivery.status === 'in_transit'
      ? 'dropoff'
      : null;
  const arrival = arrivalPhase ? delivery.arrivals?.[arrivalPhase] : undefined;
  const arrivalKey = `arrival:${delivery.id}`;
  const recordArrival = () => {
    if (!arrivalPhase) return;
    runAction(
      arrivalKey,
      async () => {
        const position = await currentPosition();
        const payload = {
          arrival_type: arrivalPhase,
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy_m: position.coords.accuracy,
          captured_at: new Date(position.timestamp).toISOString(),
        };
        arrivalEvidence.current = payload;

        return logisticsApi.arrive(delivery.id, payload);
      },
      undefined,
      (error) => {
        if (!needsArrivalReason(error)) return false;
        setShowArrivalReason(true);
        return true;
      },
    );
  };
  const submitArrivalReason = () => {
    if (!arrivalPhase || !arrivalReason || (arrivalReason === 'other' && !arrivalNotes.trim())) {
      return;
    }
    runAction(arrivalKey, () => logisticsApi.arrive(delivery.id, {
      ...(arrivalEvidence.current ?? {
        arrival_type: arrivalPhase,
        latitude: null,
        longitude: null,
        accuracy_m: null,
        captured_at: null,
      }),
      exception_reason: arrivalReason,
      exception_notes: arrivalNotes.trim() || null,
    }));
  };
  const arrivalControl = arrivalPhase && !arrival ? (
    <div className="space-y-3">
      <button
        type="button"
        disabled={mutationDisabled || pendingAction === arrivalKey}
        onClick={recordArrival}
        className={buttonClass}
      >
        I've arrived
      </button>
      {!online && (
        <p role="status" className="text-center text-sm font-semibold text-amber-700 dark:text-amber-300">
          Retry after reconnect
        </p>
      )}
      {showArrivalReason && (
        <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
          <p className="text-sm text-amber-950 dark:text-amber-100">
            Location could not be verified. Choose a reason to continue.
          </p>
          <label className="block text-sm font-semibold">
            Arrival reason
            <select
              aria-label="Arrival reason"
              value={arrivalReason}
              onChange={(event) => setArrivalReason(event.target.value)}
              className="mt-1 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 dark:bg-slate-900"
            >
              <option value="">Choose a reason</option>
              {arrivalReasons.map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </label>
          <label className="block text-sm font-semibold">
            Arrival notes {arrivalReason === 'other' ? '(required)' : '(optional)'}
            <textarea
              aria-label="Arrival notes"
              value={arrivalNotes}
              onChange={(event) => setArrivalNotes(event.target.value)}
              className="mt-1 min-h-20 w-full rounded-xl border border-amber-300 bg-white p-3 dark:bg-slate-900"
            />
          </label>
          <button
            type="button"
            disabled={
              mutationDisabled ||
              !arrivalReason ||
              (arrivalReason === 'other' && !arrivalNotes.trim())
            }
            onClick={submitArrivalReason}
            className={buttonClass}
          >
            Continue with reason
          </button>
        </div>
      )}
    </div>
  ) : null;
  const arrivalSummary = arrival ? (
    <p
      role="status"
      aria-live="polite"
      className="rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-800 dark:bg-slate-800 dark:text-slate-100"
    >
      <span aria-hidden="true">{arrival.result === 'verified' ? '✓' : '!'}</span>{' '}
      {arrivalStatusText(arrival)}
    </p>
  ) : null;

  if (['assigned', 'pickup_scheduled'].includes(delivery.status)) {
    if (!arrival) return arrivalControl;
    const key = `pickup:${delivery.id}`;
    const pickupProof = delivery.proofs
      ?.filter(({ handoff_type }) => handoff_type === 'pickup')
      .at(-1);

    return (
      <div className="space-y-3">
        {arrivalSummary}
        <button
          type="button"
          disabled={mutationDisabled || pendingAction === key}
          onClick={() =>
            runAction(
              key,
              () =>
                pickupProof
                  ? logisticsApi.confirmPickup(delivery.id, pickupProof.id)
                  : logisticsApi.markPickedUp(delivery.id),
              {
                title: `Confirm pickup for ${deliveryReference}?`,
                text: 'This confirms that the parcel is now in your custody.',
                confirmButtonText: 'Confirm pickup',
              },
            )
          }
          className={buttonClass}
        >
          Confirm pickup
        </button>
      </div>
    );
  }

  if (delivery.status === 'picked_up') {
    const key = `delivery-start:${delivery.id}`;

    return (
      <button
        type="button"
        disabled={mutationDisabled || pendingAction === key}
        onClick={() =>
          runAction(
            key,
            () =>
              item.kind === 'batch'
                ? logisticsApi.outForDelivery(delivery.id)
                : logisticsApi.markInTransit(delivery.id),
            {
              title: `Start ${deliveryReference}?`,
              text: 'This marks the parcel as on the way to its destination.',
              confirmButtonText: 'Start delivery',
            },
          )
        }
        className={buttonClass}
      >
        Start delivery
      </button>
    );
  }

  if (delivery.status !== 'in_transit') return null;
  if (!arrival) return arrivalControl;

  const proofKey = `proof:${delivery.id}`;
  const issueKey = `issue:${delivery.id}`;
  const assignment = delivery.assignments?.find(({ status }) =>
    ['assigned', 'accepted'].includes(status),
  );

  const submitProof = () => {
    if (!proofFile) return;
    const form = new FormData();
    form.append('handoff_type', 'delivery');
    form.append('proof_type', 'photo');
    form.append('proof_file', proofFile);
    runAction(
      proofKey,
      () =>
        axios.post(`/api/logistics/legs/${delivery.id}/proof`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        }),
      {
        title: `Submit proof for ${deliveryReference}?`,
        text: `${proofFile.name} will be attached to this delivery.`,
        confirmButtonText: 'Submit proof',
      },
    );
  };

  const submitIssue = () => {
    if (
      !issueReason ||
      !assignment ||
      (requiresIssuePhoto && !issueFile) ||
      (requiresIssueNotes && !issueNotes.trim())
    ) return;
    const form = new FormData();
    form.append('delivery_assignment_id', String(assignment.id));
    form.append('reason_code', issueReason);
    if (issueNotes.trim()) form.append('notes', issueNotes.trim());
    if (issueFile) form.append('proof_file', issueFile);
    runAction(
      issueKey,
      () =>
        axios.post(`/api/logistics/legs/${delivery.id}/report-issue`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        }),
      {
        title: `Submit issue for ${deliveryReference}?`,
        text: 'This records a failed delivery attempt for dispatcher review.',
        confirmButtonText: 'Submit issue',
      },
    );
  };

  return (
    <div className="space-y-3">
      {arrivalSummary}
      {canRecordProof && (
        <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
          <label className="block text-sm font-semibold text-slate-700 dark:text-slate-200">
            Delivery proof
            <input
              type="file"
              accept="image/*"
              aria-label="Delivery proof"
              onChange={(event) => setProofFile(event.target.files?.[0] ?? null)}
              className="mt-2 block w-full text-sm"
            />
          </label>
          <button
            type="button"
            disabled={mutationDisabled || !proofFile || pendingAction === proofKey}
            onClick={submitProof}
            className={buttonClass}
          >
            Submit delivery proof
          </button>
        </div>
      )}

      <button
        type="button"
        disabled={mutationDisabled}
        onClick={() => setShowIssue((current) => !current)}
        className="min-h-11 w-full rounded-xl border border-amber-400 px-4 text-sm font-bold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50 dark:text-amber-200"
      >
        Report issue
      </button>

      {showIssue && (
        <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
          <label className="block text-sm font-semibold">
            Issue reason
            <select
              aria-label="Issue reason"
              value={issueReason}
              onChange={(event) => setIssueReason(event.target.value)}
              className="mt-1 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 dark:bg-slate-900"
            >
              <option value="">Choose a reason</option>
              <option value="recipient_unavailable">Recipient unavailable</option>
              <option value="wrong_or_incomplete_address">Wrong or incomplete address</option>
              <option value="recipient_refused">Recipient refused</option>
              <option value="item_damaged">Item damaged</option>
              <option value="unsafe_location">Unsafe location</option>
              <option value="vehicle_or_delivery_problem">Vehicle or delivery problem</option>
              <option value="other">Other</option>
            </select>
          </label>
          <label className="block text-sm font-semibold">
            Notes {requiresIssueNotes ? '(required)' : '(optional)'}
            <textarea
              aria-label="Issue notes"
              required={requiresIssueNotes}
              value={issueNotes}
              onChange={(event) => setIssueNotes(event.target.value)}
              className="mt-1 min-h-20 w-full rounded-xl border border-amber-300 bg-white p-3 dark:bg-slate-900"
            />
          </label>
          <label className="block text-sm font-semibold">
            Issue photo {requiresIssuePhoto ? '(required)' : '(optional)'}
            <input
              type="file"
              required={requiresIssuePhoto}
              accept="image/*"
              aria-label="Issue photo"
              onChange={(event) => setIssueFile(event.target.files?.[0] ?? null)}
              className="mt-2 block w-full text-sm"
            />
          </label>
          <button
            type="button"
            disabled={
              mutationDisabled ||
              !assignment ||
              !issueReason ||
              (requiresIssuePhoto && !issueFile) ||
              (requiresIssueNotes && !issueNotes.trim()) ||
              pendingAction === issueKey
            }
            onClick={submitIssue}
            className={buttonClass}
          >
            Submit issue
          </button>
        </div>
      )}
    </div>
  );
}

function CurrentDeliveryCard({
  item,
  showSequence,
  onToggleSequence,
  locked,
  online,
  pendingAction,
  canRecordProof,
  runAction,
}: {
  item: RiderDeliveryWorkItem | null;
  showSequence: boolean;
  onToggleSequence: () => void;
  locked: boolean;
  online: boolean;
  pendingAction: string | null;
  canRecordProof: boolean;
  runAction: ActionRunner;
}) {
  if (!item) {
    return (
      <section aria-labelledby="current-delivery-heading">
        <h2 id="current-delivery-heading" className="mb-3 text-lg font-bold text-slate-950 dark:text-white">
          Current delivery
        </h2>
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-center dark:border-slate-700 dark:bg-slate-900">
          <p className="font-semibold text-slate-800 dark:text-slate-100">No delivery in progress</p>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Your next scheduled assignment appears below.</p>
        </div>
      </section>
    );
  }

  const progress = completedProgress(item.deliveries);
  const actionable = nextActionableDelivery(item.deliveries);

  return (
    <section aria-labelledby="current-delivery-heading">
      <h2 id="current-delivery-heading" className="mb-3 text-lg font-bold text-slate-950 dark:text-white">
        Current delivery
      </h2>
      <article className="overflow-hidden rounded-2xl border-2 border-blue-400 bg-white shadow-sm dark:bg-slate-900">
        <header className="space-y-4 border-b border-blue-100 p-4 dark:border-blue-900">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">
                {item.business_label}
              </p>
              <h3 className="mt-1 text-xl font-extrabold text-slate-950 dark:text-white">{itemTitle(item)}</h3>
            </div>
            <StatusChip status={item.status} />
          </div>

          <div>
            <div className="mb-2 flex items-center justify-between gap-3 text-sm">
              <span className="font-semibold text-slate-700 dark:text-slate-200">
                {progress.completed} of {progress.total} completed
              </span>
              <span className="text-slate-500 dark:text-slate-400">{progress.percent}%</span>
            </div>
            <div
              role="progressbar"
              aria-label={`${item.kind === 'batch' ? 'Batch' : 'Delivery'} progress: ${progress.completed} of ${progress.total} delivered`}
              aria-valuenow={progress.percent}
              aria-valuemin={0}
              aria-valuemax={100}
              className="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
            >
              <div className="h-full rounded-full bg-blue-600" style={{ width: `${progress.percent}%` }} />
            </div>
          </div>
        </header>

        <div className="p-4">
          {actionable ? (
            <>
              <p className="mb-3 text-sm font-bold text-blue-700 dark:text-blue-300">
                Current delivery · {actionable.stop_sequence ?? 1} of {item.deliveries.length}
              </p>
              <ResolutionNotice delivery={actionable} />
              <DeliveryContact delivery={actionable} />
              <div className="mt-4">
                <DeliveryActions
                  key={actionable.id}
                  item={item}
                  delivery={actionable}
                  locked={locked}
                  online={online}
                  pendingAction={pendingAction}
                  canRecordProof={canRecordProof}
                  runAction={runAction}
                />
              </div>
            </>
          ) : (
            <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800">
              <p className="font-semibold text-slate-900 dark:text-white">
                {item.deliveries.some(({ status }) => status === 'awaiting_proof_approval')
                  ? 'Waiting for proof approval'
                  : 'No rider action needed right now'}
              </p>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                This page will update when the delivery is ready to continue.
              </p>
            </div>
          )}

          {item.kind === 'batch' && item.deliveries.length > 0 && (
            <>
              <button
                type="button"
                onClick={onToggleSequence}
                className="mt-4 min-h-11 w-full rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-800 dark:border-slate-600 dark:text-slate-100"
              >
                {showSequence ? 'Hide delivery sequence' : `View all ${item.deliveries.length} deliveries`}
              </button>
              {showSequence && <DeliverySequence item={item} />}
            </>
          )}
        </div>
      </article>
    </section>
  );
}

function UpNextCard({
  item,
  locked,
  online,
  pendingAction,
  canRecordProof,
  runAction,
}: {
  item: RiderDeliveryWorkItem | null;
  locked: boolean;
  online: boolean;
  pendingAction: string | null;
  canRecordProof: boolean;
  runAction: ActionRunner;
}) {
  const [showDetails, setShowDetails] = useState(false);
  if (!item) return null;
  const actionable = nextActionableDelivery(item.deliveries);

  return (
    <section aria-label="Up next">
      <h2 className="mb-3 text-lg font-bold text-slate-950 dark:text-white">Up next</h2>
      <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{item.business_label}</p>
            <h3 className="mt-1 font-bold text-slate-950 dark:text-white">{itemTitle(item)}</h3>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
              {scheduleText(item)} · {deliveryCount(item)}
            </p>
          </div>
          <StatusChip status={item.status} />
        </div>
        <div className="mt-4">
          <DeliveryActions
            key={`${item.key}:${actionable?.id ?? 'none'}`}
            item={item}
            delivery={actionable}
            locked={locked}
            online={online}
            pendingAction={pendingAction}
            canRecordProof={canRecordProof}
            runAction={runAction}
          />
        </div>
        <button
          type="button"
          onClick={() => setShowDetails((current) => !current)}
          className="mt-4 min-h-11 w-full rounded-xl border border-blue-300 px-4 text-sm font-bold text-blue-700 dark:border-blue-700 dark:text-blue-300"
        >
          {showDetails ? 'Hide details' : 'View details'}
        </button>
        {showDetails && actionable && (
          <div className="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
            <DeliveryContact delivery={actionable} />
          </div>
        )}
      </article>
    </section>
  );
}

function OfferCard({
  item,
  online,
  pendingAction,
  runAction,
}: {
  item: RiderDeliveryWorkItem;
  online: boolean;
  pendingAction: string | null;
  runAction: ActionRunner;
}) {
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const acceptKey = `offer-accept:${item.id}`;
  const declineKey = `offer-decline:${item.id}`;

  return (
    <article className="rounded-2xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">New assignment</p>
          <h3 className="mt-1 font-bold text-slate-950 dark:text-white">{itemTitle(item)}</h3>
          <p className="mt-1 text-sm text-slate-700 dark:text-slate-200">
            {scheduleText(item)} · {deliveryCount(item)}
          </p>
        </div>
        <StatusChip status={item.status} />
      </div>
      <div className="mt-4 grid grid-cols-2 gap-2">
        <button
          type="button"
          disabled={!online || pendingAction !== null}
          onClick={() =>
            runAction(acceptKey, () => logisticsApi.acceptBatch(item.id), {
              title: `Accept batch #${item.id}?`,
              text: 'This assignment will be added to your delivery work.',
              confirmButtonText: 'Accept batch',
            })
          }
          className="min-h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white disabled:opacity-50"
        >
          Accept batch
        </button>
        <button
          type="button"
          disabled={!online}
          onClick={() => setDeclining((current) => !current)}
          className="min-h-11 rounded-xl border border-amber-500 px-4 text-sm font-bold text-amber-900 disabled:opacity-50 dark:text-amber-100"
        >
          Decline batch
        </button>
      </div>
      {declining && (
        <div className="mt-3 space-y-2">
          <label className="block text-sm font-semibold text-amber-950 dark:text-amber-100">
            Decline reason
            <input
              type="text"
              aria-label="Decline reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              className="mt-1 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 dark:bg-slate-900"
            />
          </label>
          <button
            type="button"
            disabled={!online || !reason.trim() || pendingAction !== null}
            onClick={() =>
              runAction(declineKey, () => logisticsApi.rejectBatch(item.id, reason.trim()))
            }
            className="min-h-11 w-full rounded-xl bg-amber-700 px-4 text-sm font-bold text-white disabled:opacity-50"
          >
            Confirm decline
          </button>
        </div>
      )}
    </article>
  );
}

function OfferRegion({
  offers,
  online,
  pendingAction,
  runAction,
}: {
  offers: RiderDeliveryWorkItem[];
  online: boolean;
  pendingAction: string | null;
  runAction: ActionRunner;
}) {
  if (!offers.length) return null;

  return (
    <section aria-label="New assignment offers" className="space-y-3">
      <OfferCard item={offers[0]} online={online} pendingAction={pendingAction} runAction={runAction} />
      {offers.length > 1 && (
        <details className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
          <summary className="min-h-11 cursor-pointer py-2 text-sm font-bold text-slate-800 dark:text-slate-100">
            {offers.length - 1} more {offers.length === 2 ? 'offer' : 'offers'}
          </summary>
          <div className="mt-3 space-y-3">
            {offers.slice(1).map((offer) => (
              <OfferCard
                key={offer.key}
                item={offer}
                online={online}
                pendingAction={pendingAction}
                runAction={runAction}
              />
            ))}
          </div>
        </details>
      )}
    </section>
  );
}

function CompactListItem({ item }: { item: RiderDeliveryWorkItem | RiderDeliveryIssue }) {
  if (item.item_type === 'issue') {
    return (
      <article className="rounded-xl border border-amber-300 bg-white p-4 dark:border-amber-800 dark:bg-slate-900">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="font-bold text-slate-950 dark:text-white">Issue · Delivery #{item.delivery_id}</p>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{item.parent_key.replace(':', ' #')}</p>
          </div>
          <StatusChip status="delivery_attempted" />
        </div>
      </article>
    );
  }

  return (
    <details className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
      <summary className="min-h-11 cursor-pointer list-none">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="font-bold text-slate-950 dark:text-white">{itemTitle(item)}</p>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
              {item.business_label} · {scheduleText(item)} · {deliveryCount(item)}
            </p>
          </div>
          <StatusChip status={item.status} />
        </div>
      </summary>
      <div className="mt-3 border-t border-slate-200 pt-3 dark:border-slate-700">
        <DeliverySequence item={item} />
      </div>
    </details>
  );
}

function DeliveryLists({
  deliveryData,
  navigate,
}: {
  deliveryData: RiderDeliveryPageData;
  navigate: (patch: Partial<RiderDeliveryPageData['filters']>, page?: number) => void;
}) {
  const [search, setSearch] = useState(deliveryData.filters.search);
  useEffect(() => setSearch(deliveryData.filters.search), [deliveryData.filters.search]);

  const emptyMessage = {
    upcoming: 'No upcoming deliveries',
    history: 'No delivery history',
    issues: 'No unresolved delivery issues',
    all: 'No deliveries found',
  }[deliveryData.filters.tab];

  return (
    <section aria-labelledby="delivery-list-heading" className="space-y-4">
      <h2 id="delivery-list-heading" className="text-lg font-bold text-slate-950 dark:text-white">
        Deliveries
      </h2>
      <div className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="Delivery lists">
        {(Object.keys(tabLabels) as RiderDeliveryTab[]).map((tab) => (
          <button
            key={tab}
            type="button"
            aria-current={deliveryData.filters.tab === tab ? 'page' : undefined}
            onClick={() => navigate({ tab })}
            className={`min-h-11 shrink-0 rounded-xl px-4 text-sm font-bold ${
              deliveryData.filters.tab === tab
                ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700'
            }`}
          >
            {tabLabels[tab]}
          </button>
        ))}
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
          Business type
          <select
            aria-label="Business type"
            value={deliveryData.filters.business}
            onChange={(event) =>
              navigate({
                business: event.target.value as RiderDeliveryPageData['filters']['business'],
              })
            }
            className="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
          >
            <option value="all">All businesses</option>
            <option value="retail">Retail</option>
            <option value="repair">Repair</option>
          </select>
        </label>
        <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
          Time
          <select
            aria-label="Delivery time"
            value={deliveryData.filters.window}
            onChange={(event) =>
              navigate({
                window: event.target.value as RiderDeliveryPageData['filters']['window'],
              })
            }
            className="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
          >
            <option value="all">All time</option>
            <option value="today">Today</option>
            <option value="week">This week</option>
          </select>
        </label>
        <form
          role="search"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            navigate({ search });
          }}
          className="space-y-2"
        >
          <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
            Search
            <input
              type="search"
              aria-label="Search deliveries"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Delivery, customer, address"
              className="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
            />
          </label>
          <button type="submit" className="sr-only">Search deliveries</button>
        </form>
      </div>

      <button
        type="button"
        onClick={() =>
          navigate({ tab: 'upcoming', business: 'all', window: 'all', search: '' })
        }
        className="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200"
      >
        Clear filters
      </button>

      <div className="space-y-3">
        {deliveryData.list.data.length ? (
          deliveryData.list.data.map((item) => <CompactListItem key={item.key} item={item} />)
        ) : (
          <div className="rounded-xl border border-dashed border-slate-300 bg-white p-5 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            {emptyMessage}
          </div>
        )}
      </div>

      {deliveryData.list.links.some(({ url }) => url) && (
        <nav aria-label="Delivery pages" className="flex flex-wrap justify-center gap-2">
          {deliveryData.list.links.map((link) => {
            if (!link.url) return null;
            const label = link.label.replace(/&laquo;|&raquo;/g, '').trim();
            const page = Number(new URL(link.url, window.location.origin).searchParams.get('page') ?? 1);

            return (
              <button
                key={`${link.label}:${page}`}
                type="button"
                aria-label={`Page ${label}`}
                aria-current={link.active ? 'page' : undefined}
                onClick={() => navigate({}, page)}
                className="min-h-11 min-w-11 rounded-xl border border-slate-300 px-3 text-sm font-semibold dark:border-slate-700"
              >
                {label}
              </button>
            );
          })}
        </nav>
      )}
    </section>
  );
}

export default function MyDeliveries() {
  const { deliveryData, canRecordProof } = usePage<{
    deliveryData: RiderDeliveryPageData;
    canRecordProof: boolean;
    maxDeliveryAttempts: number;
  }>().props;
  const [showSequence, setShowSequence] = useState(false);
  const [online, setOnline] = useState(() => typeof navigator === 'undefined' || navigator.onLine);
  const [pendingAction, setPendingAction] = useState<string | null>(null);
  const actionInFlight = useRef(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [lastSynced] = useState(() =>
    new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
  );

  useEffect(() => {
    const connected = () => setOnline(true);
    const disconnected = () => setOnline(false);
    window.addEventListener('online', connected);
    window.addEventListener('offline', disconnected);

    return () => {
      window.removeEventListener('online', connected);
      window.removeEventListener('offline', disconnected);
    };
  }, []);

  const runAction: ActionRunner = (key, action, confirmation, onError) => {
    if (!online || actionInFlight.current) return;
    actionInFlight.current = true;
    setPendingAction(key);
    setActionError(null);

    const finish = () => {
      actionInFlight.current = false;
      setPendingAction(null);
    };

    void (async () => {
      try {
        if (confirmation && !(await workflowFeedback.confirm(confirmation)).isConfirmed) {
          finish();
          return;
        }

        await action();
        router.reload({ only: ['deliveryData'], onFinish: finish });
      } catch (error: any) {
        if (onError?.(error)) {
          finish();
          return;
        }
        const errors = error.response?.data?.errors;
        setActionError(
          error.response?.data?.message ??
            (errors ? Object.values(errors).flat().join(' ') : 'Unable to update this delivery.'),
        );
        finish();
      }
    })();
  };

  const navigate = (
    patch: Partial<RiderDeliveryPageData['filters']>,
    page = 1,
  ) => {
    router.get(
      '/erp/logistics/deliveries',
      { ...deliveryData.filters, ...patch, page },
      { preserveScroll: true, preserveState: true },
    );
  };

  return (
    <AppLayoutERP>
      <Head title="My Deliveries" />
      <div className="mx-auto max-w-3xl space-y-6 pb-10">
        <header>
          <h1 className="text-2xl font-extrabold text-slate-950 dark:text-white">My Deliveries</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            See what needs your attention now.
          </p>
          <p
            role="status"
            aria-live="polite"
            className={`mt-2 text-xs font-semibold ${
              online ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'
            }`}
          >
            <span aria-hidden="true">{online ? '●' : '!'}</span>{' '}
            {online ? 'Online' : 'Offline'} · Last sync {lastSynced}
          </p>
        </header>

        {deliveryData.has_active_conflict && (
          <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">
            <strong>More than one active delivery was found.</strong> Contact dispatch before continuing either assignment.
          </div>
        )}

        <CurrentDeliveryCard
          item={deliveryData.current}
          showSequence={showSequence}
          onToggleSequence={() => setShowSequence((current) => !current)}
          locked={deliveryData.has_active_conflict}
          online={online}
          pendingAction={pendingAction}
          canRecordProof={canRecordProof}
          runAction={runAction}
        />
        <OfferRegion
          offers={deliveryData.offers}
          online={online}
          pendingAction={pendingAction}
          runAction={runAction}
        />
        <UpNextCard
          item={deliveryData.up_next}
          locked={deliveryData.has_active_conflict || Boolean(deliveryData.current)}
          online={online}
          pendingAction={pendingAction}
          canRecordProof={canRecordProof}
          runAction={runAction}
        />
        {actionError && (
          <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            {actionError}
          </div>
        )}
        <DeliveryLists deliveryData={deliveryData} navigate={navigate} />
      </div>
    </AppLayoutERP>
  );
}
