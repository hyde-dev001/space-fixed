import AppLayoutERP from '@/layout/AppLayout_ERP';
import type {
  RiderDeliveryIssue,
  RiderDeliveryPageData,
  RiderDeliveryTab,
  RiderDeliveryWorkItem,
  TrackingShipmentLeg,
} from '@/types/logistics';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
  completedProgress,
  deliveryContact,
  deliveryStatusLabel,
  nextActionableDelivery,
  orderedDeliveries,
} from './riderDeliveryPresentation';

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

function CurrentDeliveryCard({
  item,
  showSequence,
  onToggleSequence,
}: {
  item: RiderDeliveryWorkItem | null;
  showSequence: boolean;
  onToggleSequence: () => void;
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
              aria-label={`Batch progress: ${progress.completed} of ${progress.total} delivered`}
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
              <DeliveryContact delivery={actionable} />
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

function UpNextCard({ item }: { item: RiderDeliveryWorkItem | null }) {
  if (!item) return null;

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
        <button
          type="button"
          className="mt-4 min-h-11 w-full rounded-xl border border-blue-300 px-4 text-sm font-bold text-blue-700 dark:border-blue-700 dark:text-blue-300"
        >
          View details
        </button>
      </article>
    </section>
  );
}

function OfferCard({ item }: { item: RiderDeliveryWorkItem }) {
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
    </article>
  );
}

function OfferRegion({ offers }: { offers: RiderDeliveryWorkItem[] }) {
  if (!offers.length) return null;

  return (
    <section aria-label="New assignment offers" className="space-y-3">
      <OfferCard item={offers[0]} />
      {offers.length > 1 && (
        <details className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
          <summary className="min-h-11 cursor-pointer py-2 text-sm font-bold text-slate-800 dark:text-slate-100">
            {offers.length - 1} more {offers.length === 2 ? 'offer' : 'offers'}
          </summary>
          <div className="mt-3 space-y-3">
            {offers.slice(1).map((offer) => <OfferCard key={offer.key} item={offer} />)}
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

function DeliveryLists({ deliveryData }: { deliveryData: RiderDeliveryPageData }) {
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
            readOnly
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
            readOnly
            className="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
          >
            <option value="all">All time</option>
            <option value="today">Today</option>
            <option value="week">This week</option>
          </select>
        </label>
        <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
          Search
          <input
            type="search"
            aria-label="Search deliveries"
            value={deliveryData.filters.search}
            readOnly
            placeholder="Delivery, customer, address"
            className="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"
          />
        </label>
      </div>

      <div className="space-y-3">
        {deliveryData.list.data.length ? (
          deliveryData.list.data.map((item) => <CompactListItem key={item.key} item={item} />)
        ) : (
          <div className="rounded-xl border border-dashed border-slate-300 bg-white p-5 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            {emptyMessage}
          </div>
        )}
      </div>
    </section>
  );
}

export default function MyDeliveries() {
  const { deliveryData } = usePage<{
    deliveryData: RiderDeliveryPageData;
    canRecordProof: boolean;
    maxDeliveryAttempts: number;
  }>().props;
  const [showSequence, setShowSequence] = useState(false);

  return (
    <AppLayoutERP>
      <Head title="My Deliveries" />
      <div className="mx-auto max-w-3xl space-y-6 pb-10">
        <header>
          <h1 className="text-2xl font-extrabold text-slate-950 dark:text-white">My Deliveries</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            See what needs your attention now.
          </p>
          <p role="status" className="mt-2 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
            <span aria-hidden="true">●</span> Online · Updated just now
          </p>
        </header>

        <OfferRegion offers={deliveryData.offers} />

        {deliveryData.has_active_conflict && (
          <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">
            <strong>More than one active delivery was found.</strong> Contact dispatch before continuing either assignment.
          </div>
        )}

        <CurrentDeliveryCard
          item={deliveryData.current}
          showSequence={showSequence}
          onToggleSequence={() => setShowSequence((current) => !current)}
        />
        <UpNextCard item={deliveryData.up_next} />
        <DeliveryLists deliveryData={deliveryData} />
      </div>
    </AppLayoutERP>
  );
}
