import DeliveryDatePicker from './DeliveryDatePicker';
import { logisticsWindowLabel } from '@/types/logistics';
import type { LogisticsSchedule, LogisticsRiderOption } from '@/types/logistics';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { Modal } from '@/components/ui/modal';
import type { TrackingShipmentLeg } from '@/types/logistics';

type Props = {
  leg: TrackingShipmentLeg;
  riders: LogisticsRiderOption[];
  schedule?: LogisticsSchedule;
  today: string;
  error: string | null;
  onClose: () => void;
  onSubmit: (payload: Record<string, unknown>) => Promise<boolean>;
};

export default function RetryResolutionDialog({ leg, riders, today, error, onClose, onSubmit, schedule }: Props) {
  const [date, setDate] = useState(today);
  const [window, setWindow] = useState('morning');
  const [rider, setRider] = useState('');
  const [reason, setReason] = useState('');
  const [route, setRoute] = useState('direct');
  const [appointment, setAppointment] = useState(Boolean(leg.customer_appointment_date));
  const [agreement, setAgreement] = useState('');
  const [override, setOverride] = useState('');
  const [pending, setPending] = useState(false);
  const [requestKey] = useState(() => crypto.randomUUID());
  const formRef = useRef<HTMLFormElement>(null);
  useEffect(() => {
    const trigger = document.activeElement;
    formRef.current?.querySelector('button')?.focus();
    return () => { if (trigger instanceof HTMLElement) trigger.focus(); };
  }, []);
  const assignmentId = Math.max(0, ...(leg.assignments ?? []).map(item => item.id));
  const valid = Boolean(date && Number(rider) > 0 && reason.trim() && (!appointment || agreement.trim()));
  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (pending || !valid) return;
    setPending(true);
    const saved = await onSubmit({ reason: reason.trim(), delivery_date: date, delivery_window: window,
      rider_profile_id: Number(rider), expected_assignment_id: assignmentId, request_key: requestKey, handoff_route: route,
      appointment_confirmed: appointment, appointment_agreement_note: agreement.trim() || null,
      capacity_override_reason: override.trim() || null });
    if (!saved) setPending(false);
  };
  const trapFocus = (event: KeyboardEvent) => {
    if (event.key !== 'Tab') return;
    const items = Array.from(formRef.current?.querySelectorAll<HTMLElement>('input:not(:disabled):not([tabindex="-1"]),select:not(:disabled),textarea:not(:disabled),button:not(:disabled),summary') ?? []);
    const first = items[0], last = items.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  };
  const inputClass = 'mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white';
  return <Modal isOpen onClose={() => { if (!pending) onClose(); }} showCloseButton={false} size="xl">
    <form ref={formRef} role="dialog" aria-modal="true" aria-labelledby="retry-resolution-title" onKeyDown={trapFocus}
      onSubmit={event => void submit(event)} className="max-h-[90dvh] space-y-4 overflow-y-auto p-5 text-gray-900 dark:text-white">
      <h2 id="retry-resolution-title" className="text-xl font-semibold">Arrange another visit</h2>
      <p className="text-sm text-gray-600 dark:text-gray-300">Choose the schedule and rider. Parcel possession changes only after confirmed release and receipt.</p>
      {error && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{error}</p>}
      <div className="grid gap-3 sm:grid-cols-2">
        <div><p className="mb-1">Retry date</p><DeliveryDatePicker label="Retry date" value={date} minDate={today} onChange={setDate} disabled={pending} operatingDays={schedule?.operating_days} blackoutDates={schedule?.blackout_dates} insideModal /></div>
        <label>Retry window<select aria-label="Retry window" className={inputClass} value={window} onChange={event => setWindow(event.target.value)} disabled={pending}><option value="morning">{logisticsWindowLabel('morning', schedule)}</option><option value="afternoon">{logisticsWindowLabel('afternoon', schedule)}</option></select></label>
      </div>
      <p className="text-sm">Current parcel holder: {leg.custody_state === 'rider' ? (riders.find(item => item.id === leg.custody_rider_profile_id)?.name ?? `Rider #${leg.custody_rider_profile_id ?? 'unknown'}`) : leg.custody_state === 'shop' ? 'Shop' : 'Unconfirmed - follow dispatcher investigation'}. The chosen retry rider is a separate decision.</p>
      <p className="text-sm">{riders.length ? 'Candidates are active and available. Attendance, work days and capacity are rechecked on save.' : 'No active available riders. Ask a rider manager to check availability, then refresh.'} Times use {schedule?.timezone ?? 'shop local time'}.</p>
      <label className="block">Retry rider<select aria-label="Retry rider" className={inputClass} required value={rider} onChange={event => setRider(event.target.value)} disabled={pending}>
        <option value="">Choose an eligible rider</option>{riders.map(item => <option key={item.id} value={item.id}>{item.name}{item.daily_capacity != null ? ` (capacity ${item.daily_capacity}/day)` : ''}</option>)}
      </select></label>
      <label className="block">Handoff route<select aria-label="Handoff route" className={inputClass} value={route} onChange={event => setRoute(event.target.value)} disabled={pending}><option value="direct">Direct to assigned rider / same rider keeps parcel</option><option value="via_shop">Return to shop, then release to assigned rider</option></select></label>
      <label className="block">Resolution reason<textarea className={inputClass} required maxLength={1000} value={reason} onChange={event => setReason(event.target.value)} disabled={pending} /></label>
      <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={appointment} onChange={event => setAppointment(event.target.checked)} disabled={pending} />Customer confirmed this date and window</label>
      {appointment && <label className="block">Customer agreement note<textarea className={inputClass} required maxLength={1000} value={agreement} onChange={event => setAgreement(event.target.value)} disabled={pending} /></label>}
      <p className="text-sm text-gray-600 dark:text-gray-300">Internal planning dates allow eligible early starts. Existing customer appointments stay binding until an agreed reschedule is recorded. Additional visits beyond the attempt limit require this explicit authorization.</p>
      <details><summary className="cursor-pointer text-sm">Capacity override, when authorized</summary><label className="mt-2 block">Capacity override reason<textarea className={inputClass} maxLength={1000} value={override} onChange={event => setOverride(event.target.value)} disabled={pending} /></label></details>
      <div className="flex flex-wrap justify-end gap-3">
        <button type="button" onClick={onClose} disabled={pending} className="min-h-11 rounded-lg border px-4 py-2">Cancel</button>
        <button type="submit" disabled={!valid || pending} className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 font-semibold text-white disabled:opacity-50">{pending ? 'Authorizing…' : 'Authorize retry'}</button>
      </div>
    </form>
  </Modal>;
}
