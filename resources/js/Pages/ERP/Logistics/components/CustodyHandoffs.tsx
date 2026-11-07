import { useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { logisticsApi } from '@/services/logisticsApi';
import type { CustodyHandoff } from '@/types/logistics';
import DeliveryPhotoUpload from './DeliveryPhotoUpload';

export default function CustodyHandoffs({ handoffs, onConfirmed }: { handoffs: CustodyHandoff[]; onConfirmed?: () => Promise<void> }) {
  const [files, setFiles] = useState<Record<number, File | undefined>>({});
  const [reasons, setReasons] = useState<Record<number, string>>({});
  const [pending, setPending] = useState<number | null>(null);
  const [error, setError] = useState<{ id: number; message: string } | null>(null);
  const [notice, setNotice] = useState('');
  const inFlight = useRef(false);
  if (!handoffs.length && !notice) return null;

  const confirm = async (handoff: CustodyHandoff, action: 'handoff' | 'cancel' = 'handoff') => {
    const photo = files[handoff.id];
    const reason = reasons[handoff.id]?.trim();
    if (inFlight.current) return;
    if (action === 'cancel' ? !handoff.can_cancel || !reason : !photo || (!handoff.can_release && !handoff.can_receive)) return;
    setPending(handoff.id);
    inFlight.current = true;
    setError(null);
    const payload = new FormData();
    if (photo) payload.append('photo', photo);
    try {
      if (action === 'cancel' && reason) await logisticsApi.cancelCustody(handoff.id, reason);
      else if (handoff.can_release) await logisticsApi.releaseCustody(handoff.id, payload);
      else await logisticsApi.receiveCustody(handoff.id, payload);
      setNotice(`${handoff.delivery_reference}: ${action === 'cancel' ? 'handoff cancelled' : handoff.can_release ? 'release confirmed; waiting for the recipient to confirm receipt' : 'receipt confirmed'}.`);
      setFiles(current => ({ ...current, [handoff.id]: undefined }));
      await onConfirmed?.();
      router.reload({ only: ['custodyHandoffs', 'deliveryData', 'shipments', 'batches'], onFinish: () => { inFlight.current = false; setPending(null); } });
    } catch (caught) {
      const data = axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(caught) ? caught.response?.data : undefined;
      const message = Object.values(data?.errors ?? {}).flat().find(value => value.trim()) || data?.message?.trim() || 'The handoff changed or could not be confirmed. Refresh and check this parcel.';
      setError({ id: handoff.id, message });
      inFlight.current = false;
      setPending(null);
      queueMicrotask(() => document.getElementById(`handoff-error-${handoff.id}`)?.focus());
    }
  };

  return <section id="package-handoffs" aria-label="Package handoffs" className="mb-6 scroll-mt-24 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/20">
    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Package handoffs</h2>
    <p className="text-sm text-gray-700 dark:text-gray-200">Assignment does not transfer possession. Confirm only when the parcel is physically handed over.</p>
    <p role="status" aria-live="polite" className="text-sm font-semibold">{notice}</p>
    {handoffs.map(handoff => <div key={handoff.id} className="space-y-2 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
      <p className="font-medium text-gray-900 dark:text-white">{handoff.delivery_reference}: {handoff.from_label} → {handoff.to_label}</p>
      {handoff.source_reference && <p className="text-sm font-semibold">{handoff.source_reference}</p>}
      {handoff.shipment_reference && <p className="text-sm">{handoff.shipment_reference} · {handoff.stage}</p>}
      {handoff.details_url && <a href={handoff.details_url} className="inline-flex min-h-11 items-center text-sm underline focus-visible:outline focus-visible:outline-2">View parcel details</a>}
      <p className="text-sm text-gray-600 dark:text-gray-300" aria-live="polite">{handoff.status === 'requested' ? 'Awaiting release' : 'Released — awaiting receipt'}</p>
      {!handoff.can_release && !handoff.can_receive && <p className="text-sm">{handoff.status === 'requested' ? `Waiting for ${handoff.from_label} to release this parcel.` : `Waiting for ${handoff.to_label} to confirm receipt.`}</p>}
      {error?.id === handoff.id && <p id={`handoff-error-${handoff.id}`} tabIndex={-1} role="alert" className="text-sm font-semibold text-red-700 dark:text-red-300">{error.message}</p>}
      {(handoff.can_release || handoff.can_receive) && <>
        <DeliveryPhotoUpload inputId={`handoff-photo-${handoff.id}`} inputLabel="Handoff photo" label="Handoff photo"
          file={files[handoff.id] ?? null} disabled={pending !== null} required accept="image/jpeg,image/png,image/webp"
          hint="JPG, PNG or WEBP, up to 10 MB. Photograph this parcel at the actual handoff."
          onChange={photo => setFiles(current => ({ ...current, [handoff.id]: photo ?? undefined }))} />
        <button type="button" disabled={!files[handoff.id] || pending !== null} onClick={() => void confirm(handoff)}
          className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">
          {pending === handoff.id ? 'Confirming…' : handoff.can_release ? 'Confirm release' : 'Confirm receipt'}
        </button>
      </>}
      {handoff.can_cancel && <details>
        <summary className="cursor-pointer text-sm">Cancel unreleased handoff and retry offer</summary>
        <label className="mt-2 block text-sm">Cancellation reason
          <textarea maxLength={1000} disabled={pending !== null} value={reasons[handoff.id] ?? ''}
            onChange={event => setReasons(current => ({ ...current, [handoff.id]: event.target.value }))}
            className="mt-1 block w-full rounded-lg border p-2 dark:bg-gray-800" />
        </label>
        <button type="button" disabled={!reasons[handoff.id]?.trim() || pending !== null} onClick={() => void confirm(handoff, 'cancel')}
          className="mt-2 min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-50">Cancel handoff</button>
      </details>}
    </div>)}
  </section>;
}
