import { router } from '@inertiajs/react';
import { useState } from 'react';
import PrivilegedAuthShell, {
  BackToLogin,
  ErrorSummary,
  FieldErrors,
  firstError,
  inputClassName,
  primaryButtonClassName,
} from './PrivilegedAuthShell';

export default function PrivilegedMfaChallenge() {
  const [code, setCode] = useState('');
  const [recoveryMode, setRecoveryMode] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    if (!recoveryMode && !/^\d{6}$/.test(code)) {
      setError('Enter the complete six-digit verification code.');
      return;
    }

    if (recoveryMode && !code.trim()) {
      setError('Enter one of your saved recovery codes.');
      return;
    }

    setError(undefined);
    setProcessing(true);
    router.post('/admin/mfa/challenge', { code }, {
      onError: (errors) => setError(firstError(errors as FieldErrors, ['code', 'error', 'message']) ?? 'The verification code is invalid.'),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <PrivilegedAuthShell
      title="Verify your sign in"
      description="Enter a current code from your authenticator app. This challenge must succeed before the admin console opens."
      footer={<BackToLogin />}
    >
      <form onSubmit={submit} className="space-y-5" noValidate>
        <ErrorSummary message={error} />
        <div>
          <label htmlFor="privileged-mfa-code" className="mb-2 block text-sm font-semibold text-slate-800">
            {recoveryMode ? 'Recovery code' : 'Six-digit verification code'}
          </label>
          <input
            id="privileged-mfa-code"
            type="text"
            inputMode={recoveryMode ? 'text' : 'numeric'}
            autoComplete={recoveryMode ? 'off' : 'one-time-code'}
            autoCorrect="off"
            spellCheck={false}
            value={code}
            onChange={(event) => setCode(recoveryMode ? event.target.value : event.target.value.replace(/\D/g, '').slice(0, 6))}
            className={`${inputClassName} text-center font-mono text-xl tracking-[0.35em]`}
            aria-describedby={error ? 'privileged-mfa-error' : undefined}
            required
          />
        </div>
        {error && <span id="privileged-mfa-error" className="sr-only">{error}</span>}
        <button type="submit" className={primaryButtonClassName} disabled={processing}>
          {processing ? 'Verifying…' : 'Verify'}
        </button>
        <button
          type="button"
          className="w-full text-center text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
          onClick={() => {
            setRecoveryMode((current) => !current);
            setCode('');
            setError(undefined);
          }}
        >
          {recoveryMode ? 'Use an authenticator code instead' : 'Use a recovery code instead'}
        </button>
      </form>
    </PrivilegedAuthShell>
  );
}
