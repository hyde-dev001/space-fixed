import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { logisticsApi } from '@/services/logisticsApi';
import type { LogisticsRiderOption, LogisticsSchedule, ParcelRecovery } from '@/types/logistics';
import { Modal } from '@/components/ui/modal';
import CustodyHandoffs from '@/Pages/ERP/Logistics/components/CustodyHandoffs';
import DeliveryPhotoUpload from '@/Pages/ERP/Logistics/components/DeliveryPhotoUpload';
import RetryResolutionDialog from '@/Pages/ERP/Logistics/components/RetryResolutionDialog';

type Item = { reference: string; quantity: number; condition: string };
type Stage = 'receipt' | 'inspection' | 'disposition' | 'close' | 'investigation';
type Props = { source: 'retail' | 'repair' | 'all'; sourceId?: number; shipmentId?: number; riders?: LogisticsRiderOption[]; schedule?: LogisticsSchedule; today?: string };

export default function ParcelIncidentRecovery({ source, sourceId, shipmentId, riders = [], schedule, today = '' }: Props) {
  const embedded = sourceId !== undefined || shipmentId !== undefined;
  const [rows, setRows] = useState<ParcelRecovery[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<ParcelRecovery | null>(null);
  const [stage, setStage] = useState<Stage>('receipt');
  const [action, setAction] = useState('');
  const [notes, setNotes] = useState('');
  const [photo, setPhoto] = useState<File | null>(null);
  const [items, setItems] = useState<Item[]>([]);
  const [outcome, setOutcome] = useState('');
  const [agreement, setAgreement] = useState('');
  const [pending, setPending] = useState(false);
  const key = useRef('');
  const busy = useRef(false);
  const alertRef = useRef<HTMLParagraphElement>(null);
  const refresh = useCallback(async () => {
    try {
      const response = await logisticsApi.parcelRecoveries();
      setRows((response.data.recoveries ?? []).filter(row => (source === 'all' || (source === 'repair' ? row.source_type === 'repair_request' : row.source_type !== 'repair_request'))
        && (sourceId === undefined || row.source_id === sourceId) && (shipmentId === undefined || row.shipment_id === shipmentId)));
    } catch { setError('Parcel recovery could not be loaded. Refresh to check pending work.'); }
  }, [source, sourceId, shipmentId]);
  useEffect(() => { void refresh(); }, [refresh]);
  const open = (row: ParcelRecovery, next: Stage, disposition = '') => {
    setSelected(row); setStage(next); setAction(disposition); setError(null); setNotes(''); setPhoto(null); setOutcome('');
    setAgreement('');
    setItems(row.expected_items.map(item => ({ reference: item.reference, quantity: 0, condition: 'unidentified' })));
    key.current = crypto.randomUUID();
  };
  const submit = async (extra: Record<string, unknown> = {}) => {
    if (!selected || busy.current || !(notes.trim() || extra.reason)) return false;
    busy.current = true; setPending(true); setError(null);
    const payload = new FormData();
    const facts = { ...extra, request_key: key.current, expected_revision: selected.revision, notes: notes.trim() || String(extra.reason),
      ...(stage === 'disposition' ? { action } : {}), ...(stage === 'close' ? { outcome } : {}) };
    Object.entries(facts).forEach(([field, value]) => { if (value != null) payload.append(field, typeof value === 'boolean' ? (value ? '1' : '0') : String(value)); });
    if (photo) payload.append('photo', photo);
    if (stage === 'receipt' && selected.return_proof_id) payload.append('proof_id', String(selected.return_proof_id));
    if (stage === 'inspection') items.forEach((item, index) => Object.entries(item).forEach(([field, value]) => payload.append(`items[${index}][${field}]`, String(value))));
    if (action === 'verify_readiness') {
      payload.append('findings[mitigation_plan]', notes.trim());
      if (agreement.trim()) payload.append('findings[customer_agreement]', agreement.trim());
    }
    try {
      if (action === 'reauthorize_retry') {
        await logisticsApi.resolveIncident(selected.incident_id, { ...extra, request_key: key.current,
          expected_revision: selected.revision, resolution: 'retry', note: String(extra.reason), findings: { ...selected.retry_findings, mitigation_plan: String(extra.reason) } });
      } else await logisticsApi.parcelRecoveryAction(selected.incident_id, stage, payload);
      setSelected(null); await refresh(); return true;
    } catch (caught) {
      const data = axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(caught) ? caught.response?.data : undefined;
      setError(Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? 'The recovery changed or could not be saved. Refresh and check its current state.');
      queueMicrotask(() => alertRef.current?.focus()); return false;
    } finally { busy.current = false; setPending(false); }
  };
  const field = 'mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white';
  if (embedded && rows?.length === 0 && !error) return null;
  return <section aria-label={embedded ? 'Return receipt and disposition' : 'Returned parcels requiring action'} className="my-4 space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-semibold">{embedded ? 'Return receipt and disposition' : 'Returned parcels requiring action'}</h2>
      <button type="button" onClick={() => { setError(null); void refresh(); }} className="min-h-11 rounded-lg border px-3">Refresh parcel recovery</button></div>
    <p className="text-sm">Receipt does not complete repair, refund or delivery. Operational closure and linked financial/source work are shown separately.</p>
    {error && !selected && <p role="alert">{error}</p>}
    {rows === null && !error && <p role="status">Loading parcel recovery…</p>}
    {rows?.length === 0 && <p>No parcels requiring action are currently visible to your account.</p>}
    {rows?.map(row => <article key={row.incident_id} className="space-y-2 rounded-lg border p-3 text-sm">
      <h3 className="font-semibold">Incident #{row.incident_id} · {row.source_type.replaceAll('_', ' ')} #{row.source_id} · Delivery #{row.delivery_id}</h3>
      <p>Custody: {row.holder_label ?? row.custody}. {row.operational_status === 'resolved' && row.custody === 'lost'
        ? 'Final missing parcel. Financial/source investigation is separate.'
        : <>{row.received ? 'Shop receipt confirmed.' : 'Awaiting physical receipt.'} {row.inspected ? 'Inspection recorded.' : 'Inspection pending.'}</>}</p>
      {row.shop_action_blocker && <p>{row.shop_action_blocker}</p>}
      {row.current_delivery_id && row.current_delivery_id !== row.delivery_id && <p>Current delivery #{row.current_delivery_id}: {row.movement_status?.replaceAll('_', ' ')}.</p>}
      <p role="status">{row.operational_status === 'resolved' ? 'Operationally closed — financial/source work pending' : 'Operational recovery pending'}</p>
      {row.quarantined && <p>Quarantined: verify discrepancies and safe disposition before outbound movement.</p>}
      <div className="flex flex-wrap gap-3">{row.evidence_urls?.map((url, index) => <a key={url} href={url} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center underline">Private parcel evidence {index + 1}</a>)}</div>
      {row.obligations.map(obligation => <p key={`${obligation.type}:${obligation.id}`}>{obligation.type} #{obligation.id}: {obligation.status ?? 'pending'} · Next: {obligation.next_actor.replaceAll('_', ' ')}{obligation.note && ` · ${obligation.note}`}</p>)}
      {row.source_outcome.refund_id && <a className="inline-flex min-h-11 items-center underline" href={source === 'all' ? '/finance?section=refund-approvals' : '/erp/staff/job-orders'}>Continue existing refund #{row.source_outcome.refund_id} review and inspection</a>}
      {row.transfers?.length > 0 && <CustodyHandoffs handoffs={row.transfers} onConfirmed={refresh} />}
      <div className="flex flex-wrap gap-2">
        {!row.received && row.return_proof_id && (row.operational_status !== 'resolved' || row.safekeeping_pending) && <button className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'receipt')}>Confirm actual shop receipt</button>}
        {row.can_inspect && <button className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'inspection')}>Record item inspection</button>}
        {row.actions.filter(option => option.allowed).map(option => <button key={option.action} className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'disposition', option.action)}>{option.label}</button>)}
        {row.can_reauthorize && <button className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'disposition', 'reauthorize_retry')}>Reauthorize unstarted recovery</button>}
        {row.can_close && <button className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'close')}>Verify operational closure</button>}
        {row.can_investigate && <button className="min-h-11 rounded-lg border px-3" onClick={() => open(row, 'investigation')}>Record investigation and evidence</button>}
      </div>
    </article>)}
    {selected && <Modal isOpen ariaLabel={`Parcel recovery incident ${selected.incident_id}`} onClose={() => { if (!pending) setSelected(null); }} size="lg">
      <div className="space-y-4 p-5"><h2 className="text-lg font-semibold">Parcel recovery · Incident #{selected.incident_id}</h2>
        {error && <p ref={alertRef} role="alert" tabIndex={-1}>{error}</p>}
        {['redeliver_original', 'reauthorize_retry'].includes(action) ? <RetryResolutionDialog embedded leg={selected.retry_context} riders={riders} schedule={schedule} today={today}
          error={null} onClose={() => setSelected(null)} onSubmit={submit} /> : <>
          <label className="block">Recovery notes<textarea className={field} maxLength={1000} disabled={pending} value={notes} onChange={event => setNotes(event.target.value)} /></label>
          {action === 'verify_readiness' && <fieldset disabled={pending} className="space-y-3"><legend>Dispatcher readiness verification</legend>
            <p>Recorded receipt and item inspection remain authoritative. Describe the resolved obstacle in the notes.</p>
            <label className="block">Customer agreement or resolved-dispute arrangement<textarea className={field} maxLength={1000} value={agreement} onChange={event => setAgreement(event.target.value)} /></label>
          </fieldset>}
          {stage === 'inspection' && items.map((item, index) => <fieldset key={item.reference} disabled={pending} className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2">
            <legend>{selected.expected_items[index]?.label} · {item.reference} · Expected quantity: {selected.expected_items[index]?.expected_quantity}</legend>
            <label>Actual quantity<input className={field} type="number" min={0} value={item.quantity} onChange={event => setItems(current => current.map((row, i) => i === index ? { ...row, quantity: Number(event.target.value) } : row))} /></label>
            <label>Actual condition<select className={field} value={item.condition} onChange={event => setItems(current => current.map((row, i) => i === index ? { ...row, condition: event.target.value } : row))}>
              {['unidentified', 'safe', 'damaged', 'missing', 'unsafe'].map(value => <option key={value}>{value}</option>)}</select></label>
          </fieldset>)}
          {stage === 'close' && <label className="block">Verified operational outcome<select className={field} value={outcome} onChange={event => setOutcome(event.target.value)}>
            <option value="">Select an evidenced outcome</option><option value="confirmed_loss">Investigated confirmed loss</option><option value="delivery_completed">Approved delivery completed</option><option value="shop_received">Shop receipt and source disposition verified</option><option value="safekeeping_received">Recovered property safely received; reconciliation remains separate</option></select></label>}
          {['receipt', 'inspection', 'investigation'].includes(stage) && <DeliveryPhotoUpload inputId="recovery-photo" inputLabel="Parcel recovery evidence" label="Parcel recovery evidence" file={photo}
            onChange={setPhoto} disabled={pending} required={stage === 'receipt'} hint="Photograph the actual parcel/condition. Private JPG, PNG or WEBP, up to 10 MB." />}
          <div className="flex flex-wrap justify-end gap-2"><button className="min-h-11 rounded-lg border px-4" disabled={pending} onClick={() => setSelected(null)}>Cancel</button>
            <button className="min-h-11 rounded-lg bg-gray-950 px-4 text-white disabled:opacity-50" disabled={pending || !notes.trim() || (stage === 'receipt' && !photo) || (stage === 'close' && !outcome)} onClick={() => void submit()}>{pending ? 'Saving…' : 'Save verified recovery step'}</button></div>
        </>}
      </div>
    </Modal>}
  </section>;
}
