import { useRef, useState } from 'react';
import type { LogisticsIncident, LogisticsRiderOption, LogisticsSchedule, TrackingShipmentLeg } from '@/types/logistics';
import RetryResolutionDialog from './RetryResolutionDialog';

type Props = {
  leg: TrackingShipmentLeg;
  incident?: LogisticsIncident;
  riders: LogisticsRiderOption[];
  schedule?: LogisticsSchedule;
  today: string;
  error: string | null;
  onRetry: (payload: Record<string, unknown>) => Promise<boolean>;
  onResolve: (payload: FormData) => Promise<boolean>;
  onPendingChange: (pending: boolean) => void;
};

export default function DeliveryIssueResolution({ leg, incident, riders, schedule, today, error, onRetry, onResolve, onPendingChange }: Props) {
  const [action, setAction] = useState('');
  const [note, setNote] = useState('');
  const [responsibility, setResponsibility] = useState('undetermined');
  const [evidence, setEvidence] = useState<File | null>(null);
  const [pending, setPending] = useState(false);
  const [saved, setSaved] = useState(false);
  const [requestKey] = useState(() => crypto.randomUUID());
  const [agreement, setAgreement] = useState('');
  const [mitigation, setMitigation] = useState('');
  const [transportNotes, setTransportNotes] = useState('');
  const submitting = useRef(false);
  const canRecover = leg.status === 'needs_resolution';
  const legacyActions = [
    ...(incident ? [['dismissed', 'Issue resolved — continue delivery']] : []),
    ...(canRecover ? [['retry', 'Arrange another delivery attempt'], ['return_required', 'Return parcel to shop']] : []),
    ...(incident?.type === 'lost' ? [['loss_confirmed', 'Confirm parcel lost']] : []),
  ];
  const actions = incident?.eligibility
    ? Object.entries(incident.eligibility).filter(([, rule]) => rule.allowed || rule.conditional).map(([value, rule]) => [value, rule.label])
    : legacyActions;
  const decisionFindings = () => ({
    ...(agreement.trim() ? { customer_agreement: agreement.trim() } : {}),
    ...(mitigation.trim() ? { mitigation_plan: mitigation.trim() } : {}),
    ...(transportNotes.trim() ? { transport_notes: transportNotes.trim() } : {}),
  });
  const submit = async (send: () => Promise<boolean>) => {
    if (submitting.current) return false;
    submitting.current = true;
    setPending(true);
    onPendingChange(true);
    try {
      const success = await send();
      setSaved(success);
      return success;
    } finally {
      submitting.current = false;
      setPending(false);
      onPendingChange(false);
    }
  };
  const confirm = () => {
    if (!action || !note.trim() || (action === 'loss_confirmed' && !evidence)) return;
    const form = new FormData();
    form.append('resolution', action);
    form.append('note', note.trim());
    form.append('responsible_party', responsibility);
    if (incident) {
      form.append('request_key', requestKey);
      form.append('expected_revision', String(incident.revision ?? 0));
      for (const [field, value] of Object.entries(decisionFindings())) form.append(`findings[${field}]`, value);
    }
    if (evidence) form.append('evidence_files[]', evidence);
    void submit(() => onResolve(form));
  };
  if (saved) return <p role="status" className="mt-3 rounded-lg border p-3 text-sm">Recovery decision saved. Refreshing delivery details…</p>;
  const inputClass = 'mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white';
  return <section aria-label="Resolve delivery issue" className="mt-3 space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
    <h4 className="font-semibold">Resolve delivery issue</h4>
    <fieldset disabled={pending} className="space-y-2">
      <legend className="mb-2 font-medium">What should happen next?</legend>
      {actions.map(([value, title]) => <label key={value} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-gray-300 bg-white p-3 dark:border-gray-600 dark:bg-gray-800">
        <input type="radio" name={`resolution-${leg.id}-${incident?.id ?? 'attempt'}`} value={value} checked={action === value} onChange={() => setAction(value)} />{title}
      </label>)}
    </fieldset>
    {incident && action && <>
      {incident.type === 'customer_dispute' && action !== 'return_required' && <label className="block">Customer agreement and resolution<textarea className={inputClass} maxLength={1000} value={agreement} onChange={event => setAgreement(event.target.value)} /></label>}
      {['vehicle_problem', 'other'].includes(incident.type) && ['dismissed', 'retry'].includes(action) && <label className="block">Resolved obstacle or operational arrangement<textarea className={inputClass} maxLength={1000} value={mitigation} onChange={event => setMitigation(event.target.value)} /></label>}
      {action === 'return_required' && <label className="block">Safe handling instructions, if needed<textarea className={inputClass} maxLength={1000} value={transportNotes} onChange={event => setTransportNotes(event.target.value)} /></label>}
    </>}
    {incident && <label className="block">Investigated responsibility<select className={inputClass} value={responsibility} onChange={event => setResponsibility(event.target.value)} disabled={pending}>
      <option value="undetermined">Undetermined</option><option value="customer">Customer</option><option value="rider">Rider</option><option value="shop">Shop</option><option value="third_party">Third party</option>
    </select></label>}
    {action === 'retry' ? <RetryResolutionDialog embedded leg={leg} riders={riders} schedule={schedule} today={today} error={error}
      onClose={() => setAction('')} onSubmit={payload => submit(() => onRetry({ ...payload, responsible_party: responsibility,
        ...(incident ? { expected_revision: incident.revision ?? 0, findings: decisionFindings() } : {}) }))} />
      : action && <div className="space-y-3">
        {error && <p role="alert" className="text-red-700 dark:text-red-300">{error}</p>}
        <label className="block">Decision reason<textarea className={inputClass} required maxLength={1000} value={note} onChange={event => setNote(event.target.value)} disabled={pending} /></label>
        {action === 'loss_confirmed' && <label className="block">Investigation evidence<input className="mt-1 block w-full" type="file" accept="image/jpeg,image/png,image/webp" onChange={event => setEvidence(event.target.files?.[0] ?? null)} disabled={pending} /></label>}
        <p role="status">{action === 'dismissed' ? 'Next: the same rider continues the existing delivery only when its previous state and normal eligibility checks allow it. No new retry offer is created.'
          : action === 'return_required' ? 'Next: the actual parcel holder follows the return workflow. The shop must confirm physical receipt. This does not approve inspection, refunds or restocking.'
            : 'Confirm only an investigated loss. This records a loss decision and applies the existing source-specific recovery rules; it is not a completed delivery.'}</p>
        <button type="button" disabled={pending || !note.trim() || (action === 'loss_confirmed' && !evidence)} onClick={confirm} className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 font-semibold text-white disabled:opacity-50">
          {pending ? 'Saving…' : action === 'dismissed' ? 'Confirm issue resolved' : action === 'return_required' ? 'Authorize return' : 'Confirm parcel lost'}
        </button>
      </div>}
  </section>;
}
