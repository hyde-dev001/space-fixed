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
  const [findings, setFindings] = useState<Record<string, boolean>>({});
  const [agreement, setAgreement] = useState('');
  const [itemReference, setItemReference] = useState('');
  const [itemQuantity, setItemQuantity] = useState(1);
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
  const decisionFindings = () => ({ ...findings, operational_ready: findings.operational_ready ?? false,
    ...(agreement.trim() ? { customer_agreement: agreement.trim() } : {}),
    ...(itemReference.trim() ? { items: [{ reference: itemReference.trim(), quantity: itemQuantity, condition: 'Dispatcher verified condition' }] } : {}),
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
      for (const [field, value] of Object.entries(decisionFindings())) {
        if (typeof value === 'boolean') form.append(`findings[${field}]`, value ? '1' : '0');
        else if (typeof value === 'string') form.append(`findings[${field}]`, value);
        else value.forEach((item, index) => Object.entries(item).forEach(([key, entry]) => form.append(`findings[items][${index}][${key}]`, String(entry))));
      }
    }
    if (evidence) form.append('evidence_files[]', evidence);
    void submit(() => onResolve(form));
  };
  if (saved) return <p role="status" className="mt-3 rounded-lg border p-3 text-sm">Recovery decision saved. Refreshing delivery details…</p>;
  const inputClass = 'mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white';
  return <section aria-label="Resolve delivery issue" className="mt-3 space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
    <h4 className="font-semibold">Resolve delivery issue</h4>
    {incident && <fieldset disabled={pending} className="space-y-2">
      <legend className="font-medium">Verified investigation findings</legend>
      {([
        ...(incident.type === 'vehicle_problem' ? [['obstacle_resolved', 'Vehicle or route obstacle resolved']] : []),
        ...(incident.type === 'damaged' ? [['condition_verified', 'Parcel contents and packaging inspected'], ['safe_for_delivery', 'Verified safe for customer handoff']] : []),
        ...(incident.type === 'lost' ? [['recovered_verified', 'Parcel physically recovered and verified']] : []),
        ...(incident.type === 'customer_dispute' ? [['dispute_resolved', 'Customer dispute resolved with an eligible outcome']] : []),
        ...(incident.type === 'other' ? [['operational_ready', 'Operational condition verified as resolved']] : []),
        ['safe_for_transport', 'Verified safe for transport to the shop'],
      ]).map(([field, title]) => <label key={field} className="flex min-h-11 items-center gap-2"><input type="checkbox" checked={findings[field] ?? false}
        onChange={event => setFindings(current => ({ ...current, [field]: event.target.checked }))} />{title}</label>)}
      {incident.type === 'customer_dispute' && <label className="block">Customer agreement and resolution<textarea className={inputClass} maxLength={1000} value={agreement} onChange={event => setAgreement(event.target.value)} /></label>}
      {incident.type === 'damaged' && <div className="grid gap-2 sm:grid-cols-2"><label>Inspected item reference<input className={inputClass} value={itemReference} onChange={event => setItemReference(event.target.value)} /></label>
        <label>Verified item quantity<input className={inputClass} type="number" min={1} value={itemQuantity} onChange={event => setItemQuantity(Number(event.target.value))} /></label></div>}
      <p className="text-xs">Record actual findings and evidence. The server rechecks custody, condition, customer arrangements and every operational gate on save.</p>
    </fieldset>}
    <fieldset disabled={pending} className="space-y-2">
      <legend className="mb-2 font-medium">What should happen next?</legend>
      {actions.map(([value, title]) => <label key={value} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-gray-300 bg-white p-3 dark:border-gray-600 dark:bg-gray-800">
        <input type="radio" name={`resolution-${leg.id}-${incident?.id ?? 'attempt'}`} value={value} checked={action === value} onChange={() => setAction(value)} />{title}
      </label>)}
    </fieldset>
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
