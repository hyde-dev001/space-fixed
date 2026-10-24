import axios from 'axios';
import { useState } from 'react';
import PrivilegedAuthShell, {
  ErrorSummary,
  apiErrorMessage,
  inputClassName,
  primaryButtonClassName,
} from './PrivilegedAuthShell';
import PrivilegedRecoveryCodes from './PrivilegedRecoveryCodes';

interface PrivilegedMfaEnrollmentProps {
  qrCode: string;
  manualSecret: string;
  issuer: string;
}

export default function PrivilegedMfaEnrollment({ qrCode, manualSecret, issuer }: PrivilegedMfaEnrollmentProps) {
  const [code, setCode] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();
  const [recovery, setRecovery] = useState<{ codes: string[]; token: string }>();

  if (recovery) {
    return (
      <PrivilegedRecoveryCodes
        recoveryCodes={recovery.codes}
        acknowledgementToken={recovery.token}
        onAcknowledged={() => setRecovery(undefined)}
      />
    );
  }

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    if (!/^\d{6}$/.test(code)) {
      setError('Enter the complete six-digit verification code.');
      return;
    }

    setError(undefined);
    setProcessing(true);
    axios.post('/admin/mfa/setup/verify', { code }, {
      headers: { Accept: 'application/json' },
      withCredentials: true,
    }).then((response) => {
      const recoveryCodes = response.data?.recovery_codes;
      const acknowledgementToken = response.data?.acknowledgement_token;
      if (!Array.isArray(recoveryCodes) || typeof acknowledgementToken !== 'string') {
        throw new Error('The enrollment response was incomplete.');
      }
      setRecovery({ codes: recoveryCodes, token: acknowledgementToken });
    }).catch((requestError: unknown) => {
      setError(apiErrorMessage(requestError, 'The verification code is invalid.'));
    }).finally(() => setProcessing(false));
  };

  return (
    <PrivilegedAuthShell
      title="Enroll your authenticator"
      description="Scan the QR code with your authenticator app, or enter the manual secret. Then verify a current six-digit code."
    >
      <div className="space-y-6">
        <div className="grid gap-6 sm:grid-cols-[auto_1fr] sm:items-start">
          <div className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <img src={qrCode} alt="Authenticator app QR code" className="h-44 w-44" />
          </div>
          <div className="space-y-4 text-sm text-slate-600">
            <div>
              <p className="font-semibold text-slate-950">Account issuer</p>
              <p>{issuer}</p>
            </div>
            <div>
              <p className="font-semibold text-slate-950">Can’t scan?</p>
              <p>Enter this secret manually in your authenticator app:</p>
              <code className="mt-2 block break-all rounded-lg bg-slate-100 px-3 py-2 font-mono text-xs text-slate-950">{manualSecret}</code>
            </div>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-5" noValidate>
          <ErrorSummary message={error} />
          <div>
            <label htmlFor="enrollment-mfa-code" className="mb-2 block text-sm font-semibold text-slate-800">Six-digit verification code</label>
            <input
              id="enrollment-mfa-code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              autoCorrect="off"
              spellCheck={false}
              maxLength={6}
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
              className={`${inputClassName} text-center font-mono text-xl tracking-[0.35em]`}
              required
            />
          </div>
          <button type="submit" className={primaryButtonClassName} disabled={processing}>
            {processing ? 'Verifying…' : 'Verify authenticator'}
          </button>
        </form>
      </div>
    </PrivilegedAuthShell>
  );
}
