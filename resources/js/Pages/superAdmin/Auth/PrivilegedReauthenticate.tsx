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

interface PrivilegedReauthenticateProps {
  intended?: string;
}

export default function PrivilegedReauthenticate({ intended = '/admin/security' }: PrivilegedReauthenticateProps) {
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    if (!password || !/^\d{6}$/.test(code)) {
      setError('Enter your current password and six-digit authenticator code.');
      return;
    }

    setError(undefined);
    setProcessing(true);
    router.post('/admin/reauthenticate', { password, code, intended }, {
      onError: (errors) => setError(firstError(errors as FieldErrors, ['password', 'code', 'error', 'message']) ?? 'Reauthentication failed.'),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <PrivilegedAuthShell
      title="Confirm it’s you"
      description="High-risk administrator actions require your current password and a fresh authenticator code. This confirmation remains valid for 15 minutes."
      footer={<BackToLogin />}
    >
      <form onSubmit={submit} className="space-y-5" noValidate>
        <ErrorSummary message={error} />
        <div>
          <label htmlFor="reauth-password" className="mb-2 block text-sm font-semibold text-slate-800">Current password</label>
          <input
            id="reauth-password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className={inputClassName}
            required
          />
        </div>
        <div>
          <label htmlFor="reauth-code" className="mb-2 block text-sm font-semibold text-slate-800">Six-digit verification code</label>
          <input
            id="reauth-code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            value={code}
            onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
            className={`${inputClassName} font-mono tracking-[0.35em]`}
            required
          />
        </div>
        <button type="submit" className={primaryButtonClassName} disabled={processing}>
          {processing ? 'Confirming…' : 'Reauthenticate'}
        </button>
      </form>
    </PrivilegedAuthShell>
  );
}
