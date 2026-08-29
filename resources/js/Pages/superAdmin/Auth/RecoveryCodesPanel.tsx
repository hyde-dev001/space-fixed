import axios from 'axios';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
  ErrorSummary,
  primaryButtonClassName,
  secondaryButtonClassName,
} from './PrivilegedAuthShell';

interface RecoveryCodesPanelProps {
  recoveryCodes: string[];
  acknowledgementToken: string;
  acknowledgementEndpoint?: string;
  continueLabel?: string;
  useJsonRequest?: boolean;
  onAcknowledged: () => void;
}

export default function RecoveryCodesPanel({
  recoveryCodes,
  acknowledgementToken,
  acknowledgementEndpoint = '/admin/mfa/setup/recovery/acknowledge',
  continueLabel = 'Continue',
  useJsonRequest = false,
  onAcknowledged,
}: RecoveryCodesPanelProps) {
  const [saved, setSaved] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();
  const [copyStatus, setCopyStatus] = useState<string>();

  const codeText = recoveryCodes.join('\n');

  const copyCodes = async () => {
    try {
      await navigator.clipboard.writeText(codeText);
      setCopyStatus('Recovery codes copied.');
    } catch {
      setCopyStatus('Copy was unavailable. Use the readable list below.');
    }
  };

  const acknowledge = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing || !saved) return;

    setError(undefined);
    setProcessing(true);
    if (useJsonRequest) {
      axios.post(acknowledgementEndpoint, { token: acknowledgementToken }, {
        headers: { Accept: 'application/json' },
        withCredentials: true,
      }).then(() => onAcknowledged())
        .catch(() => setError('The acknowledgement could not be completed. Keep these codes safe and try again.'))
        .finally(() => setProcessing(false));
      return;
    }

    router.post(acknowledgementEndpoint, { token: acknowledgementToken }, {
      onError: () => setError('The acknowledgement could not be completed. Keep these codes safe and try again.'),
      onSuccess: () => onAcknowledged(),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <div className="space-y-6">
      <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm leading-6 text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
        <p className="font-semibold">Save these recovery codes now.</p>
        <p className="mt-1">Each code works once. They will not be shown again after you finish.</p>
      </div>

      <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:grid-cols-2" aria-label="Recovery codes">
        {recoveryCodes.map((code) => (
          <code key={code} className="rounded-lg border border-slate-200 bg-white px-4 py-3 text-center font-mono text-sm font-semibold tracking-wide text-slate-950">
            {code}
          </code>
        ))}
      </div>

      <div className="flex flex-wrap gap-3">
        <button type="button" className={secondaryButtonClassName} onClick={copyCodes}>
          Copy codes
        </button>
        <a
          className={secondaryButtonClassName}
          href={`data:text/plain;charset=utf-8,${encodeURIComponent(codeText)}`}
          download="solespace-recovery-codes.txt"
        >
          Download codes
        </a>
        <button type="button" className={secondaryButtonClassName} onClick={() => window.print()}>
          Print codes
        </button>
      </div>
      {copyStatus && <p className="text-sm text-emerald-700" role="status">{copyStatus}</p>}

      <form onSubmit={acknowledge} className="space-y-4">
        <ErrorSummary message={error} />
        <label className="flex items-start gap-3 text-sm leading-6 text-slate-700">
          <input
            type="checkbox"
            checked={saved}
            onChange={(event) => setSaved(event.target.checked)}
            className="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-slate-950"
          />
          <span>I have saved these recovery codes somewhere secure.</span>
        </label>
        <button type="submit" className={primaryButtonClassName} disabled={!saved || processing}>
          {processing ? 'Finishing…' : continueLabel}
        </button>
      </form>
    </div>
  );
}
