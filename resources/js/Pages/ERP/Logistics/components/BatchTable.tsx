import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Activity, AlertTriangle, Eye, MoreHorizontal, Pencil, RotateCcw, Send } from 'lucide-react';
import { logisticsModuleLabel, type DeliveryBatch, type DeliveryBatchStatus } from '@/types/logistics';

const formatDate = (value: string) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' })
  .format(new Date(`${value.slice(0, 10)}T00:00:00Z`));
const formatRejectionTime = (value?: string | null) => {
  const date = value && new Date(value);
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : null;
};
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const primaryActions = {
  offered: { label: 'View offer', Icon: Send },
  in_progress: { label: 'View progress', Icon: Activity },
} as const;
type Props = {
  batches: DeliveryBatch[];
  variant?: 'active' | 'history';
  onOpen?: (batchId: number) => void;
  onDetails: (batchId: number, trigger: HTMLButtonElement) => void;
  onReview?: (batchId: number) => void;
  onCancel?: (batchId: number) => void;
  onRestore?: (batchId: number) => void;
};

const BatchStatus = ({ status }: { status: DeliveryBatchStatus }) => <span className="rounded-full bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{label(status)}</span>;

function SecondaryActions({ batch, onReview, onCancel, onRestore }: Pick<Props, 'onReview' | 'onCancel' | 'onRestore'> & { batch: DeliveryBatch }) {
  const active = !['completed', 'cancelled'].includes(batch.status);
  const hasActions = (batch.status === 'draft' && Boolean(onReview))
    || (['draft', 'offered', 'accepted'].includes(batch.status) && Boolean(onCancel));

  if (batch.status === 'cancelled' && onRestore) {
    return <button type="button" aria-label={`Restore batch ${batch.id}`} title="Restore to draft" onClick={() => onRestore(batch.id)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-gray-500 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"><RotateCcw aria-hidden="true" size={18} /></button>;
  }
  if (!active || !hasActions) return null;

  return <FloatingActions batch={batch} onReview={onReview} onCancel={onCancel} />;
}

function FloatingActions({ batch, onReview, onCancel }: Pick<Props, 'onReview' | 'onCancel'> & { batch: DeliveryBatch }) {
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });

  const close = () => setOpen(false);
  const toggle = () => {
    if (!triggerRef.current) return;
    const rect = triggerRef.current.getBoundingClientRect();
    setPosition({ top: rect.bottom + 8, left: Math.max(8, rect.right - 176) });
    setOpen((value) => !value);
  };

  useEffect(() => {
    if (!open) return;
    const handlePointerDown = (event: PointerEvent) => {
      const target = event.target as Node;
      if (!triggerRef.current?.contains(target) && !menuRef.current?.contains(target)) close();
    };
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close();
    };
    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);

  const menu = open && typeof document !== 'undefined' ? createPortal(
    <div ref={menuRef} role="menu" aria-label={`Actions for batch ${batch.id}`} className="fixed z-[100] w-44 rounded-lg border border-gray-200 bg-white p-1 shadow-xl dark:border-gray-700 dark:bg-gray-800" style={{ top: position.top, left: position.left }}>
      {batch.status === 'draft' && onReview && <button type="button" role="menuitem" onClick={() => { close(); onReview(batch.id); }} className="block w-full rounded px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Review &amp; Offer</button>}
      {['draft', 'offered', 'accepted'].includes(batch.status) && onCancel && <button type="button" role="menuitem" onClick={() => { close(); onCancel(batch.id); }} className="block w-full rounded px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30">Cancel batch</button>}
    </div>,
    document.body,
  ) : null;

  return <>
    <button ref={triggerRef} type="button" aria-label={`More actions for batch ${batch.id}`} aria-expanded={open} onClick={toggle} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-gray-500 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"><MoreHorizontal aria-hidden="true" size={18} /></button>
    {menu}
  </>;
}

function BatchSummary({ batch }: { batch: DeliveryBatch }) {
  const rejectedAt = formatRejectionTime(batch.rejected_at);

  return <>
    <div className="flex flex-wrap items-center gap-2">
      <span className="font-bold text-gray-950 dark:text-white">Batch #{batch.id}</span>
      <BatchStatus status={batch.status} />
      <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{logisticsModuleLabel(batch.module)}</span>
    </div>
    {batch.status === 'draft' && batch.rejection_reason && <div role="alert" className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
      <p className="flex items-center gap-2 font-semibold"><AlertTriangle size={17} />Rejected by rider</p>
      <p className="mt-1">{batch.rejection_reason}</p>
      {rejectedAt && <time className="mt-1 block text-xs" dateTime={batch.rejected_at!}>{rejectedAt}</time>}
    </div>}
  </>;
}

function BatchActions({ batch, onOpen, onDetails, onReview, onCancel, onRestore }: Pick<Props, 'onOpen' | 'onDetails' | 'onReview' | 'onCancel' | 'onRestore'> & { batch: DeliveryBatch }) {
  return <>
    {batch.status === 'draft' && onOpen && <button type="button" aria-label={`Edit batch ${batch.id}`} title="Edit batch" onClick={() => onOpen(batch.id)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-gray-500 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"><Pencil aria-hidden="true" size={18} /></button>}
    {primaryActions[batch.status as keyof typeof primaryActions] && onOpen && <button type="button" aria-label={`${primaryActions[batch.status as keyof typeof primaryActions].label} ${batch.id}`} title={primaryActions[batch.status as keyof typeof primaryActions].label} onClick={() => onOpen(batch.id)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-gray-500 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500">{React.createElement(primaryActions[batch.status as keyof typeof primaryActions].Icon, { 'aria-hidden': true, size: 18 })}</button>}
    <button type="button" aria-label={`View details for batch ${batch.id}`} title="View details" onClick={(event) => onDetails(batch.id, event.currentTarget)} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-gray-500 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500"><Eye aria-hidden="true" size={18} /></button>
    <SecondaryActions batch={batch} onReview={onReview} onCancel={onCancel} onRestore={onRestore} />
  </>;
}

export default function BatchTable({ batches, variant = 'active', onOpen, onDetails, onReview, onCancel, onRestore }: Props) {
  const history = variant === 'history';
  const legsFor = (batch: DeliveryBatch) => ['completed', 'cancelled'].includes(batch.status)
    ? (batch.stop_snapshot?.length ? batch.stop_snapshot : batch.cancelled_stops?.length ? batch.cancelled_stops : batch.legs)
    : batch.legs;

  return <div>
    <div data-testid="compact-batch-list" className="space-y-3 xl:hidden">
      {batches.map((batch) => {
        const stops = legsFor(batch);
        const stopCount = ['completed', 'cancelled'].includes(batch.status) ? stops.length : batch.assigned_stop_count;
        const urgentCount = stops.filter((leg) => leg.urgent_at).length;

        return <article key={batch.id} className="min-w-0 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-5">
          <div className="flex min-w-0 items-start justify-between gap-3">
            <div className="min-w-0 flex-1"><BatchSummary batch={batch} /></div>
            <span className="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">{stopCount}/{batch.capacity} stops</span>
          </div>
          <dl className="mt-4 grid min-w-0 grid-cols-1 gap-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-700 sm:grid-cols-2">
            <div className="min-w-0">
              <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500">Schedule</dt>
              <dd className="mt-1 break-words text-gray-700 dark:text-gray-200">{formatDate(batch.delivery_date)} Â· {label(batch.delivery_window)}</dd>
            </div>
            <div className="min-w-0">
              <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500">Rider</dt>
              <dd className="mt-1 break-words text-gray-700 dark:text-gray-200">{batch.rider_profile?.name || 'Not assigned'}</dd>
            </div>
          </dl>
          <div className="mt-4 flex min-w-0 flex-col gap-3 border-t border-gray-100 pt-3 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
            <span className="text-xs font-medium text-gray-500">{urgentCount} urgent stop{urgentCount === 1 ? '' : 's'}</span>
            <div className="flex flex-wrap justify-end gap-2 sm:shrink-0"><BatchActions batch={batch} onOpen={onOpen} onDetails={onDetails} onReview={onReview} onCancel={onCancel} onRestore={onRestore} /></div>
          </div>
        </article>;
      })}
    </div>
    <div data-testid="desktop-batch-table" className="hidden xl:block">
      <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
      <table aria-label={history ? 'Batch history' : 'Active batches'} className="w-full min-w-[760px] text-left text-sm">
        <caption className="sr-only">{history ? 'Completed and cancelled delivery batches' : 'Current delivery batches'}</caption>
        <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40">
          <tr><th scope="col" className="px-4 py-3">Batch</th><th scope="col" className="px-4 py-3">Schedule</th><th scope="col" className="px-4 py-3">Rider</th><th scope="col" className="px-4 py-3">Stops</th><th scope="col" className="px-4 py-3 text-right">Actions</th></tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
          {batches.map((batch) => <tr key={batch.id}>
            <td className="px-4 py-4 align-top"><BatchSummary batch={batch} /></td>
            <td className="px-4 py-4 align-top text-gray-600 dark:text-gray-300"><span>{formatDate(batch.delivery_date)} · {label(batch.delivery_window)}</span></td>
            <td className="px-4 py-4 align-top text-gray-700 dark:text-gray-200">{batch.rider_profile?.name || 'Not assigned'}</td>
            <td className="px-4 py-4 align-top text-gray-600 dark:text-gray-300">{['completed', 'cancelled'].includes(batch.status) ? legsFor(batch).length : batch.assigned_stop_count}/{batch.capacity}<span className="block text-xs text-gray-500">{legsFor(batch).filter((leg) => leg.urgent_at).length} urgent</span></td>
            <td className="px-4 py-4 align-top"><div className="flex flex-wrap justify-end gap-2"><BatchActions batch={batch} onOpen={onOpen} onDetails={onDetails} onReview={onReview} onCancel={onCancel} onRestore={onRestore} /></div></td>
          </tr>)}
        </tbody>
      </table>
    </div>
    </div>
  </div>;
}
