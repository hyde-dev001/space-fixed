import { useRef, useState } from 'react';
import axios from 'axios';
import { logisticsApi } from '@/services/logisticsApi';
import type { LogisticsIncident } from '@/types/logistics';
import DeliveryPhotoUpload from '@/Pages/ERP/Logistics/components/DeliveryPhotoUpload';

const labels: Record<string, string> = { acknowledge: 'Acknowledge report', confirm: 'Confirm Lost', approve: 'Approve final loss',
  report_recovery: 'Report Recovery', return: 'Authorize return to shop', continue: 'Authorize continued delivery' };
const field = 'mt-1 min-h-11 w-full rounded-lg border border-slate-300 bg-transparent p-2 dark:border-slate-600';

export default function LostParcelActions({ incident, onChanged }: { incident: LogisticsIncident; onChanged: () => void }) {
  const [action, setAction] = useState('');
  const [notes, setNotes] = useState('');
  const [photo, setPhoto] = useState<File | null>(null);
  const [condition, setCondition] = useState('safe');
  const [verified, setVerified] = useState(false);
  const [date, setDate] = useState('');
  const [window, setWindow] = useState('morning');
  const [agreement, setAgreement] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const key = useRef('');
  const busy = useRef(false);
  const workflow = incident.loss;
  if (!workflow) return null;
  const recovery = action === 'report_recovery';
  const reviewing = action === 'return' || action === 'continue';
  const ready = notes.trim() && (!recovery || photo) && (!reviewing || date) && (action !== 'continue' || agreement.trim())
    && (!['confirm', 'approve', 'report_recovery', 'return', 'continue'].includes(action) || verified);
  const changed = () => { key.current = ''; };
  const submit = async () => {
    if (!ready || busy.current) return;
    busy.current = true; setPending(true); setError('');
    key.current ||= crypto.randomUUID();
    const form = new FormData();
    form.append('request_key', key.current);
    form.append('expected_revision', String(incident.revision ?? 0));
    form.append('notes', notes.trim());
    let endpoint: 'loss' | 'recoveries' | 'recovery-review' = 'loss';
    if (recovery) {
      endpoint = 'recoveries';
      form.append('custody_rider_profile_id', String(workflow.reporting_rider_profile_id));
      form.append('condition', condition);
      if (photo) form.append('photo_files[]', photo);
    } else {
      form.append('action', action);
      if (reviewing) {
        endpoint = 'recovery-review';
        form.append('safe_for_transport', '1');
        form.append('delivery_date', date);
        form.append('delivery_window', window);
        if (action === 'continue') form.append('customer_agreement', agreement.trim());
      }
    }
    try {
      await logisticsApi.incidentWorkflow(incident.id, endpoint, form);
      setAction(''); onChanged();
    } catch (failure) {
      setError(axios.isAxiosError(failure) && typeof failure.response?.data?.message === 'string'
        ? failure.response.data.message : failure instanceof Error ? failure.message : 'Action failed. Refresh the incident before retrying.');
    } finally { busy.current = false; setPending(false); }
  };
  return <section aria-label={`Loss incident ${incident.id}`} className="space-y-3 rounded-lg border p-3">
    <p role="status">{workflow.stage === 'confirmed' ? 'Confirmed Lost — Final. Financial processing is separate.'
      : workflow.parent_id ? 'Linked parcel recovery — Dispatcher verification and physical disposition are separate from the original loss.'
        : workflow.stage.replaceAll('_', ' ')}</p>
    <div className="flex flex-wrap gap-2">{workflow.actions.map(value => <button key={value} type="button" disabled={pending}
      className="min-h-11 rounded-lg border px-3" onClick={() => { setAction(value); setVerified(false); setError(''); changed(); }}>{labels[value] ?? value}</button>)}</div>
    {action && <div className="space-y-3">
      <label className="block">Reason or recovery details<textarea aria-label="Reason or recovery details" maxLength={1000} className={field}
        value={notes} onChange={event => { setNotes(event.target.value); changed(); }} /></label>
      {recovery && <>
        <label className="block">Actual custody<select aria-label="Actual custody" className={field} value="reporting_rider" disabled><option value="reporting_rider">I hold the recovered parcel</option></select></label>
        <label className="block">Parcel condition<select className={field} value={condition} onChange={event => { setCondition(event.target.value); changed(); }}>
          <option value="safe">Appears safe</option><option value="damaged">Damaged</option><option value="unsafe">Unsafe — safe transport review required</option><option value="partial">Partially recovered</option></select></label>
        <DeliveryPhotoUpload inputId={`recovery-photo-${incident.id}`} inputLabel="Recovery photograph" label="Recovery photograph" file={photo}
          onChange={file => { setPhoto(file); changed(); }} required accept="image/jpeg,image/png,image/webp" />
      </>}
      {reviewing && <>
        <label className="block">Operational date<input type="date" className={field} value={date} onChange={event => { setDate(event.target.value); changed(); }} /></label>
        <label className="block">Delivery window<select className={field} value={window} onChange={event => { setWindow(event.target.value); changed(); }}><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select></label>
        {action === 'continue' && <label className="block">Customer arrangement<textarea className={field} value={agreement} onChange={event => { setAgreement(event.target.value); changed(); }} /></label>}
      </>}
      {action !== 'acknowledge' && <label className="flex min-h-11 items-start gap-2"><input type="checkbox" checked={verified}
        onChange={event => { setVerified(event.target.checked); changed(); }} />
        {recovery ? 'I confirm that I physically hold the parcel and these details and photographs are accurate.'
          : reviewing ? 'I verified the recovery evidence, actual custody and safe transport. Existing operational rules still apply.'
            : 'I understand that final Dispatcher approval ends this loss incident and initiates an eligible refund request, without approving a payout.'}</label>}
      {error && <p role="alert">{error}</p>}
      <div className="flex gap-2"><button type="button" className="min-h-11 rounded-lg border px-4 disabled:opacity-50" disabled={!ready || pending} onClick={() => void submit()}>{pending ? 'Submitting…' : 'Submit'}</button>
        <button type="button" className="min-h-11 rounded-lg border px-4" disabled={pending} onClick={() => setAction('')}>Cancel</button></div>
    </div>}
  </section>;
}
