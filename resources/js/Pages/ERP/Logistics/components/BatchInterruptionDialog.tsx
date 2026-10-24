import DeliveryDatePicker from './DeliveryDatePicker';
import DeliveryPhotoUpload from './DeliveryPhotoUpload';
import { logisticsWindowLabel } from '@/types/logistics';
import type { LogisticsSchedule, LogisticsRiderOption } from '@/types/logistics';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Modal } from '@/components/ui/modal';
import { logisticsApi } from '@/services/logisticsApi';
import type { BatchInterruption } from '@/types/logistics';

type Props = {
  batchId: number;
  incident?: BatchInterruption | null;
  canReport?: boolean;
  canResolve?: boolean;
  canAssign?: boolean;
  disabled?: boolean;
  today: string;
  riders?: LogisticsRiderOption[];
  onResolve?: () => void;
};

export default function BatchInterruptionControls({ batchId, incident, canReport = false, canResolve = false,
  canAssign = false, disabled = false, today, riders = [], onResolve }: Props) {
  const [mode, setMode] = useState<'report' | 'resolve' | null>(null);
  const active = incident && incident.status !== 'resolved';
  if (!incident && !canReport) return null;
  return <section aria-label={`Batch ${batchId} vehicle recovery`} className="my-4 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-gray-900 dark:border-amber-800 dark:bg-amber-950/20 dark:text-white">
    {incident && <>
      <h3 className="font-semibold">{active ? 'Batch interrupted' : 'Vehicle interruption resolved'}</h3>
      {active && <p className="text-sm">{incident.resolution === 'reassign' ? 'Recovery is authorized. Check Package handoffs for your own release or receipt; delivery waits for every required parcel.' : 'Delivery is paused. The dispatcher must authorize the next step. Keep parcels secure until then.'}</p>}
      <p className="text-sm">{incident.confirmed_parcels} of {incident.required_parcels} required parcels confirmed with the assigned rider.</p>
      {incident.notes && <p className="break-words text-sm">Reported: {incident.notes}</p>}
      {incident.resolution_note && <p className="break-words text-sm">Dispatcher decision: {incident.resolution_note}</p>}
      {incident.recovery_batch_id && <p className="text-sm">Recovery batch #{incident.recovery_batch_id} ({incident.recovery_batch_status?.replaceAll('_', ' ')})</p>}
      {incident.stops.length > 0 && <details><summary className="min-h-11 cursor-pointer py-2 text-sm">Affected stops and parcel custody</summary>
        <ol className="space-y-2 text-sm">{incident.stops.map(stop => <li key={stop.shipment_leg_id}>
          {stop.sequence}. {stop.delivery_reference}: {stop.status.replaceAll('_', ' ')} · {stop.custody_state.replaceAll('_', ' ')} custody
          {stop.transfer_status && ` · handoff ${stop.transfer_status}`}
          {stop.customer_appointment_date && <span className="block">Customer appointment: {stop.customer_appointment_date} {stop.customer_appointment_window}</span>}
        </li>)}</ol></details>}
      {incident.evidence_urls?.map((url, index) => <a key={url} href={url} target="_blank" rel="noopener noreferrer" className="mr-3 inline-block min-h-11 py-2 text-sm underline">View private evidence {index + 1}</a>)}
      {canResolve && incident.recovery_batch_id && <a className="inline-block min-h-11 py-2 text-sm underline" href={`/erp/logistics/batches?batch=${incident.recovery_batch_id}&incident=${incident.id}`}>Open recovery batch #{incident.recovery_batch_id}</a>}
    </>}
    {canReport && !active && <button type="button" disabled={disabled} onClick={() => setMode('report')} className="min-h-11 rounded-lg border border-amber-700 px-4 py-2 font-semibold disabled:opacity-50">Report vehicle breakdown</button>}
    {canResolve && active && <button type="button" disabled={disabled} onClick={() => onResolve ? onResolve() : setMode('resolve')} className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 font-semibold text-white disabled:opacity-50">Resolve interruption</button>}
    {mode && <BatchInterruptionDialog key={`${mode}:${incident?.revision ?? 0}`} batchId={batchId} incident={mode === 'resolve' ? incident : undefined}
      canAssign={canAssign} today={today} riders={riders} onClose={() => setMode(null)} />}
  </section>;
}

export function BatchInterruptionDialog({ batchId, incident, canAssign, today, riders, onClose, schedule }: {
  batchId: number; incident?: BatchInterruption | null; canAssign: boolean; today: string;
  riders: LogisticsRiderOption[]; onClose: () => void; schedule?: LogisticsSchedule;
}) {
  const [choice, setChoice] = useState(incident?.recovery_batch_id ? 'authorize_replacement' : 'resume');
  const [note, setNote] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [rider, setRider] = useState('');
  const [date, setDate] = useState(today);
  const [window, setWindow] = useState('morning');
  const [route, setRoute] = useState('direct');
  const [agreements, setAgreements] = useState<Record<number, string>>({});
  const [override, setOverride] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const inFlight = useRef(false);
  const requestKey = useRef(crypto.randomUUID());
  const resetIdentity = () => { requestKey.current = crypto.randomUUID(); };
  useEffect(() => {
    const trigger = document.activeElement;
    formRef.current?.querySelector<HTMLElement>('select,textarea,input')?.focus();
    return () => { if (trigger instanceof HTMLElement) trigger.focus(); };
  }, []);
  const replacing = Boolean(incident && choice === 'reassign');
  const valid = Boolean(note.trim() && (incident || file) && (!replacing || (Number(rider) > 0 && date)));
  const close = () => { if (!inFlight.current) onClose(); };
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!valid || inFlight.current) return;
    inFlight.current = true;
    setPending(true);
    setError(null);
    try {
      if (incident) {
        const appointmentAgreements = Object.fromEntries(Object.entries(agreements).filter(([, value]) => value.trim()).map(([id, value]) => [id, value.trim()]));
        await logisticsApi.resolveIncident(incident.id, { resolution: choice, note: note.trim(), expected_revision: incident.revision, decision_key: requestKey.current,
          ...(replacing ? { rider_profile_id: Number(rider), delivery_date: date, delivery_window: window, handoff_route: route,
            appointment_agreements: appointmentAgreements, capacity_override_reason: override.trim() || null } : {}) });
      } else if (file) {
        const payload = new FormData();
        payload.append('request_key', requestKey.current);
        payload.append('notes', note.trim());
        payload.append('photo_files[]', file);
        await logisticsApi.reportBatchInterruption(batchId, payload);
      }
      router.reload({ only: ['batches', 'deliveryData', 'custodyHandoffs', 'shipments'], onFinish: () => { inFlight.current = false; setPending(false); onClose(); } });
    } catch (caught) {
      const data = axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(caught) ? caught.response?.data : undefined;
      setError(Object.values(data?.errors ?? {})[0]?.[0] || data?.message || 'The batch changed or the request could not be confirmed. Refresh and try again.');
      inFlight.current = false;
      setPending(false);
    }
  };
  const keyboard = (event: KeyboardEvent) => {
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
    if (event.key !== 'Tab') return;
    const items = Array.from(formRef.current?.querySelectorAll<HTMLElement>('input:not(:disabled):not([tabindex="-1"]),select:not(:disabled),textarea:not(:disabled),button:not(:disabled),summary') ?? []);
    const first = items[0], last = items.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  };
  const input = 'mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white';
  const title = incident ? 'Resolve batch interruption' : 'Report vehicle breakdown';
  return <Modal isOpen onClose={close} showCloseButton={false} size="xl">
    <form ref={formRef} role="dialog" aria-modal="true" aria-labelledby="batch-interruption-title" aria-busy={pending} onKeyDown={keyboard} onChange={resetIdentity}
      onSubmit={event => void submit(event)} className="max-h-[90dvh] space-y-4 overflow-y-auto p-5 text-gray-900 dark:text-white">
      <h2 id="batch-interruption-title" className="text-xl font-semibold">{title}</h2>
      <p className="text-sm">Batch #{batchId}. Completed deliveries and existing attempt records stay unchanged. Assignment does not transfer parcel custody.</p>
      {error && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{error}</p>}
      {incident && <label className="block">Recovery decision<select aria-label="Recovery decision" className={input} value={choice} onChange={event => setChoice(event.target.value)} disabled={pending}>
        {!incident.recovery_batch_id && <option value="resume">Resume with the same rider</option>}
        {canAssign && (!incident.recovery_batch_id || incident.recovery_batch_status === 'draft') && <option value="reassign">Assign a replacement rider</option>}
        {!incident.recovery_batch_id && <option value="return_to_shop">Return to shop for safekeeping</option>}
        {incident.recovery_batch_id && <option value="authorize_replacement">Authorize replacement continuation</option>}
        <option value="escalate">Keep held and escalate for investigation</option>
      </select></label>}
      {replacing && <>
        <p className="text-sm">{riders.length ? 'Candidates are active and available. Attendance, work days and capacity are rechecked on save.' : 'No active available riders. Ask a rider manager to check availability, then refresh.'} Times use {schedule?.timezone ?? 'shop local time'}.</p>
        <ul className="space-y-1 text-sm">{incident?.stops.map(stop => <li key={stop.shipment_leg_id}>{stop.delivery_reference}: current custody {stop.custody_state}{stop.custody_rider_profile_id ? ` - ${riders.find(item => item.id === stop.custody_rider_profile_id)?.name ?? `Rider #${stop.custody_rider_profile_id}`}` : ''}.</li>)}</ul>
        <label className="block">Replacement rider<select aria-label="Replacement rider" className={input} required value={rider} onChange={event => setRider(event.target.value)} disabled={pending}>
          <option value="">Choose an eligible rider</option>{riders.map(item => <option key={item.id} value={item.id}>{item.name}{item.daily_capacity != null ? ` (capacity ${item.daily_capacity}/day)` : ''}</option>)}
        </select></label>
        <div className="grid gap-3 sm:grid-cols-2"><div><p className="mb-1">Recovery date</p><DeliveryDatePicker label="Recovery date" value={date} minDate={today} onChange={value => { setDate(value); resetIdentity(); }} disabled={pending} operatingDays={schedule?.operating_days} blackoutDates={schedule?.blackout_dates} insideModal /></div>
          <label>Recovery window<select aria-label="Recovery window" className={input} value={window} onChange={event => setWindow(event.target.value)} disabled={pending}><option value="morning">{logisticsWindowLabel('morning', schedule)}</option><option value="afternoon">{logisticsWindowLabel('afternoon', schedule)}</option></select></label></div>
        <label className="block">Parcel handoff route<select aria-label="Parcel handoff route" className={input} value={route} onChange={event => setRoute(event.target.value)} disabled={pending}><option value="direct">Direct rider-to-rider</option><option value="via_shop">Through the shop</option></select></label>
        {incident?.stops.filter(stop => stop.customer_appointment_date).map(stop => <label key={stop.shipment_leg_id} className="block text-sm">
          Customer agreement for {stop.delivery_reference} (currently {stop.customer_appointment_date} {stop.customer_appointment_window})
          <textarea className={input} maxLength={1000} disabled={pending} value={agreements[stop.shipment_leg_id] ?? ''} onChange={event => setAgreements(current => ({ ...current, [stop.shipment_leg_id]: event.target.value }))} />
        </label>)}
        <p className="text-sm">Record each customer's agreement when changing a confirmed appointment. Internal planning dates do not override appointments.</p>
        <details><summary className="min-h-11 cursor-pointer py-2 text-sm">Authorized capacity override</summary><label>Capacity override reason<textarea className={input} maxLength={1000} value={override} onChange={event => setOverride(event.target.value)} disabled={pending} /></label></details>
      </>}
      <label className="block">{incident ? 'Decision reason' : 'Breakdown details'}<textarea className={input} required maxLength={1000} value={note} onChange={event => setNote(event.target.value)} disabled={pending} /></label>
      {!incident && <DeliveryPhotoUpload inputId={`batch-evidence-${batchId}`} inputLabel="Vehicle evidence" label="Vehicle evidence" file={file} onChange={setFile} required disabled={pending} accept="image/jpeg,image/png,image/webp" hint="JPG, PNG or WEBP, up to 10 MB. Add a clear photo of the interruption." />}
      <p className="text-sm">Parcels already with a rider need confirmed private release and receipt before another rider can continue. Safekeeping does not cancel orders or approve refunds.</p>
      <div className="flex flex-wrap justify-end gap-3"><button type="button" disabled={pending} onClick={close} className="min-h-11 rounded-lg border px-4 py-2">Cancel</button>
        <button type="submit" disabled={!valid || pending} className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 font-semibold text-white disabled:opacity-50">{pending ? 'Saving…' : incident ? 'Save recovery decision' : 'Hold remaining stops'}</button></div>
    </form>
  </Modal>;
}
