import { useRef, useState } from 'react';
import axios from 'axios';
import { Modal } from '@/components/ui/modal';
import DeliveryPhotoUpload from '@/Pages/ERP/Logistics/components/DeliveryPhotoUpload';
import CustodyHandoffs from '@/Pages/ERP/Logistics/components/CustodyHandoffs';
import { logisticsApi } from '@/services/logisticsApi';
import type { ParcelRecovery } from '@/types/logistics';

type Selection = { row: ParcelRecovery; stage: 'receipt' | 'inspection' | 'disposition'; action?: string; label: string };
const field = 'mt-1 min-h-11 w-full rounded-lg border border-gray-300 bg-transparent p-2';

export default function OrderReturnActions({ returns, onChanged }: { returns: ParcelRecovery[]; onChanged: () => Promise<void> }) {
  const [selection, setSelection] = useState<Selection | null>(null);
  const [notes, setNotes] = useState('');
  const [photo, setPhoto] = useState<File | null>(null);
  const [items, setItems] = useState<Array<{ reference: string; quantity: number; condition: string }>>([]);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const key = useRef('');
  const busy = useRef(false);
  const open = (row: ParcelRecovery, stage: Selection['stage'], label: string, action?: string) => {
    setSelection({ row, stage, label, action }); setNotes(''); setPhoto(null); setError(''); key.current = '';
    setItems(row.expected_items.map(item => ({ reference: item.reference, quantity: 0, condition: 'unidentified' })));
  };
  const changed = () => { key.current = ''; };
  const save = async () => {
    if (!selection || busy.current || !notes.trim()) return;
    busy.current = true; setPending(true); setError(''); key.current ||= crypto.randomUUID();
    const form = new FormData();
    form.append('request_key', key.current); form.append('expected_revision', String(selection.row.revision));
    form.append('notes', notes.trim());
    if (selection.stage === 'receipt' && selection.row.return_proof_id) form.append('proof_id', String(selection.row.return_proof_id));
    if (photo) form.append('photo', photo);
    if (selection.action) form.append('action', selection.action);
    if (selection.stage === 'inspection') items.forEach((item, index) => Object.entries(item).forEach(([name, value]) => form.append(`items[${index}][${name}]`, String(value))));
    try {
      await logisticsApi.parcelRecoveryAction(selection.row.incident_id, selection.stage, form);
      await onChanged(); setSelection(null);
    } catch (failure) {
      const data = axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(failure) ? failure.response?.data : undefined;
      setError(Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? 'The return changed. Refresh the order and try again.');
    } finally { busy.current = false; setPending(false); }
  };
  return <>
    {returns.map(row => <div key={row.incident_id} className="flex flex-wrap items-center gap-2 text-sm">
      <span role="status">{row.operational_status === 'resolved' ? 'Physical return handled; linked work remains' : row.received ? (row.inspected ? 'Returned items inspected' : 'Returned parcel received') : 'Return to shop pending'}</span>
      {!row.receipt_recorded && row.can_receive && <button type="button" className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'receipt', 'Confirm Parcel Received')}>Confirm Parcel Received</button>}
      {row.can_inspect && <button type="button" className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'inspection', 'Inspect Returned Items')}>Inspect Returned Items</button>}
      {row.actions.filter(action => action.allowed && ['inventory_disposition', 'refund_review', 'ready_for_retry', 'repair_recovery', 'escalate'].includes(action.action)).map(action => <button key={action.action} type="button" className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'disposition', action.label, action.action)}>{action.label}</button>)}
      {!row.received && !row.can_receive && <span>{row.shop_action_blocker}</span>}
      {row.transfers.length > 0 && <CustodyHandoffs handoffs={row.transfers} onConfirmed={onChanged} />}
    </div>)}
    {selection && <Modal isOpen ariaLabel={selection.label} onClose={() => { if (!pending) setSelection(null); }} size="lg">
      <div className="space-y-4 p-5">
        <h2 className="text-lg font-semibold">{selection.label}</h2>
        <p>Record only the parcel and quantities actually received. This does not approve or pay a refund.</p>
        {error && <p role="alert">{error}</p>}
        <label className="block">Handling notes<textarea className={field} maxLength={1000} disabled={pending} value={notes} onChange={event => { setNotes(event.target.value); changed(); }} /></label>
        {selection.stage === 'inspection' && items.map((item, index) => <fieldset key={item.reference} disabled={pending} className="space-y-2 rounded-lg border p-3">
          <legend>{selection.row.expected_items[index].label} - Expected: {selection.row.expected_items[index].expected_quantity}</legend>
          <label>Actual quantity<input type="number" min={0} className={field} value={item.quantity} onChange={event => { changed(); setItems(current => current.map((row, i) => i === index ? { ...row, quantity: Number(event.target.value) } : row)); }} /></label>
          <label>Item condition<select aria-label="Item condition" className={field} value={item.condition} onChange={event => { changed(); setItems(current => current.map((row, i) => i === index ? { ...row, condition: event.target.value } : row)); }}>
            <option value="unidentified">Select actual condition</option><option value="safe">Resellable / safe</option><option value="damaged">Damaged</option><option value="missing">Missing</option><option value="unsafe">Unsafe</option>
          </select></label>
        </fieldset>)}
        {selection.stage !== 'disposition' && <DeliveryPhotoUpload inputId="order-return-photo" inputLabel="Return evidence" label="Return evidence" file={photo} onChange={file => { setPhoto(file); changed(); }} required={selection.stage === 'receipt'} disabled={pending} />}
        <div className="flex justify-end gap-2"><button type="button" className="min-h-11 rounded-lg border px-4" disabled={pending} onClick={() => setSelection(null)}>Cancel</button>
          <button type="button" className="min-h-11 rounded-lg bg-gray-950 px-4 text-white disabled:opacity-50" disabled={pending || !notes.trim() || (selection.stage === 'receipt' && !photo)} onClick={() => void save()}>{pending ? 'Saving...' : 'Save'}</button></div>
      </div>
    </Modal>}
  </>;
}
