import React from 'react';
import { AlertTriangle, PackageCheck } from 'lucide-react';
import { DndProvider } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import { logisticsSourceLabel, type BatchSuggestion, type DeliveryBatch, type LogisticsRider, type TrackingShipmentLeg } from '@/types/logistics';
import BatchStopRow from './BatchStopRow';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const formatRejectionTime = (value?: string | null) => {
  const date = value && new Date(value);
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : null;
};

function SuggestionPreview({
  suggestions,
  suggestionRows,
  suggestionRiders,
  loading,
  error,
  onApply,
}: {
  suggestions: BatchSuggestion[];
  suggestionRows: TrackingShipmentLeg[];
  suggestionRiders: LogisticsRider[];
  loading: boolean;
  error: string;
  onApply?: (legIds: number[]) => void;
}) {
  const rowsById = new Map(suggestionRows.map((leg) => [leg.id, leg]));
  const cards = suggestions.map((suggestion) => ({
    ...suggestion,
    legIds: suggestion.leg_ids.filter((legId) => rowsById.has(legId)),
  })).filter((suggestion) => suggestion.legIds.length >= 2);

  return (
    <section
      aria-labelledby="nearest-stop-suggestions-heading"
      className="border-b border-blue-100 bg-blue-50/60 p-4 dark:border-blue-900/60 dark:bg-blue-950/20 sm:p-5 xl:p-4"
    >
      <div>
        <h3 id="nearest-stop-suggestions-heading" className="font-bold text-gray-950 dark:text-white">
          Nearest-stop suggestions
        </h3>
        <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
          Generated from the shop location and current rider workload. Review and apply one before saving.
        </p>
      </div>

      {loading ? (
        <p role="status" className="mt-3 text-sm font-semibold text-blue-800 dark:text-blue-200">
          Generating nearest-stop suggestions...
        </p>
      ) : error ? (
        <p role="alert" className="mt-3 text-sm font-semibold text-red-700 dark:text-red-300">{error}</p>
      ) : cards.length ? (
        <div className="mt-3 space-y-3">
          {cards.map((suggestion) => {
            const rider = suggestionRiders.find(({ id }) => id === suggestion.rider_profile_id);
            const riderName = rider?.name || 'Rider #' + suggestion.rider_profile_id;

            return (
              <article key={suggestion.rider_profile_id + ':' + suggestion.module} className="rounded-xl border border-blue-200 bg-white p-3 dark:border-blue-900 dark:bg-gray-800">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p className="font-semibold text-gray-950 dark:text-white">{riderName}</p>
                    <p className="text-xs text-gray-600 dark:text-gray-300">
                      {suggestion.legIds.length} stops in nearest-stop order
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => onApply?.(suggestion.legIds)}
                    aria-label={'Use nearest-stop suggestion for ' + riderName}
                    className="min-h-11 w-full rounded-xl border border-blue-600 px-3 text-sm font-bold text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 sm:w-auto dark:text-blue-300 dark:hover:bg-blue-950/40"
                  >
                    Use suggestion
                  </button>
                </div>
                <ol className="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                  {suggestion.legIds.map((legId, index) => {
                    const leg = rowsById.get(legId);
                    const destination = leg?.destination_snapshot;

                    return (
                      <li key={legId} className="flex min-w-0 gap-2">
                        <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-blue-100 text-xs font-bold text-blue-800 dark:bg-blue-900 dark:text-blue-100">
                          {index + 1}
                        </span>
                        <span className="min-w-0">
                          <span className="block font-semibold">{destination?.name || (leg ? logisticsSourceLabel(leg.shipment) : 'Stop #' + legId)}</span>
                          <span className="block truncate text-xs text-gray-500 dark:text-gray-400">{destination?.address || 'Address unavailable'}</span>
                        </span>
                      </li>
                    );
                  })}
                </ol>
              </article>
            );
          })}
        </div>
      ) : (
        <p role="status" className="mt-3 text-sm text-gray-600 dark:text-gray-300">
          No complete suggestion is available for this date and window yet. You can arrange stops manually.
        </p>
      )}
    </section>
  );
}

type Props = {
  batch?: DeliveryBatch;
  selectedLegs: TrackingShipmentLeg[];
  date: string;
  window: string;
  dailyRiderCapacity: number;
  overrideReason: string;
  submitting: boolean;
  busyLegId?: number;
  onOverrideReasonChange: (value: string) => void;
  onMove: (from: number, to: number) => void;
  onRemove: (leg: TrackingShipmentLeg, index: number) => void;
  onSave: () => void;
  onReview: () => void;
  suggestions?: BatchSuggestion[];
  suggestionRows?: TrackingShipmentLeg[];
  suggestionRiders?: LogisticsRider[];
  suggestionsLoading?: boolean;
  suggestionsError?: string;
  onApplySuggestion?: (legIds: number[]) => void;
};

export default function BatchWorkspace({
  batch, selectedLegs, date, window, dailyRiderCapacity, overrideReason, submitting, busyLegId,
  onOverrideReasonChange, onMove, onRemove, onSave, onReview, suggestions = [], suggestionRows = [],
  suggestionRiders = [], suggestionsLoading = false, suggestionsError = '', onApplySuggestion,
}: Props) {
  const history = batch && ['completed', 'cancelled'].includes(batch.status);
  const legs = !batch ? selectedLegs : history
    ? batch.stop_snapshot?.length ? batch.stop_snapshot
      : batch.cancelled_stops?.length ? batch.cancelled_stops : batch.legs
    : batch.legs;
  const capacity = batch?.capacity ?? dailyRiderCapacity;
  const deliveryDate = date || batch?.delivery_date;
  const rejectedAt = formatRejectionTime(batch?.rejected_at);
  const cancellationReason = batch?.cancellation_reason || batch?.dispatcher_override_reason;
  const overCapacity = legs.length > capacity;
  const canSave = !batch && Boolean(date) && legs.length >= 2 && !submitting && (!overCapacity || Boolean(overrideReason.trim()));

  return <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div className="border-b border-gray-100 p-4 dark:border-gray-700 sm:p-5 xl:p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-bold text-gray-950 dark:text-white">{batch ? `Batch #${batch.id}` : 'New batch'}</h2>
          <p className="mt-1 text-sm text-gray-500">{deliveryDate ? formatDate(deliveryDate) : 'Choose a date'} · {(batch?.delivery_window || window) === 'morning' ? 'Morning' : 'Afternoon'}</p>
        </div>
        <span className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">{legs.length}/{capacity} stops</span>
      </div>
      <div className="mt-3 flex items-center gap-2 rounded-xl bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
        <PackageCheck size={18} />{batch?.status === 'cancelled' ? 'Cancelled batch summary.' : 'Rider will be selected during Review & Offer.'}
      </div>
      {batch?.status === 'draft' && batch.rejection_reason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Rejected by rider</p>
        <p className="mt-1">{batch.rejection_reason}</p>
        {rejectedAt && <time className="mt-1 block text-xs" dateTime={batch.rejected_at!}>{rejectedAt}</time>}
      </div>}
      {batch?.status === 'cancelled' && cancellationReason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Cancellation reason</p>
        <p className="mt-1">{cancellationReason}</p>
      </div>}
      {overCapacity && <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">
        <p className="flex items-center gap-2 text-sm font-semibold"><AlertTriangle size={17} />This batch exceeds the daily rider capacity of {capacity} {capacity === 1 ? 'stop' : 'stops'}.</p>
        {!batch && <label className="mt-3 block text-sm font-medium">Capacity override reason
          <textarea aria-label="Capacity override reason" value={overrideReason} onChange={(event) => onOverrideReasonChange(event.target.value)} rows={3} className="mt-1 w-full rounded-xl border border-amber-300 bg-white p-3" placeholder="Explain why this batch can exceed capacity" />
        </label>}
      </div>}
    </div>
    {!batch && <SuggestionPreview
      suggestions={suggestions}
      suggestionRows={suggestionRows}
      suggestionRiders={suggestionRiders}
      loading={suggestionsLoading}
      error={suggestionsError}
      onApply={onApplySuggestion}
    />}
    <DndProvider backend={HTML5Backend}>
      <div className="min-h-48 space-y-4 bg-gray-50 p-4 dark:bg-gray-900/40 sm:p-5 xl:space-y-3 xl:p-4">
        {legs.map((leg, index) => <BatchStopRow key={leg.id} leg={leg} index={index} total={legs.length} editable={!batch || batch.status === 'draft'} busy={submitting || busyLegId === leg.id} onMove={onMove} onRemove={onRemove} />)}
        {!legs.length && <p className="grid min-h-40 place-items-center text-center text-sm text-gray-500">{history ? 'Historical stop details unavailable' : 'Select deliveries from the left to build the route.'}</p>}
      </div>
    </DndProvider>
    <div className="sticky bottom-0 flex min-h-16 flex-col items-stretch justify-end gap-3 border-t bg-white p-4 dark:border-gray-700 dark:bg-gray-800 sm:flex-row sm:items-center sm:gap-2 xl:flex-wrap">
      {batch?.status === 'draft' && <button type="button" onClick={onReview} className="min-h-11 w-full rounded-xl border border-blue-600 px-4 text-sm font-semibold text-blue-700 sm:w-auto">Review &amp; Offer</button>}
      {batch && batch.status !== 'draft' && <span className="mr-auto text-sm font-medium text-gray-500">This route is read-only at the {batch.status.replaceAll('_', ' ')} stage.</span>}
      {!batch && legs.length < 2 && <span className="mr-auto text-sm font-semibold text-amber-700">Select at least 2 deliveries</span>}
      {!batch && <button type="button" disabled={!canSave} onClick={onSave} className="min-h-11 w-full rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-40 sm:w-auto">{submitting ? 'Saving Draft...' : 'Save Draft'}</button>}
    </div>
  </section>;
}
