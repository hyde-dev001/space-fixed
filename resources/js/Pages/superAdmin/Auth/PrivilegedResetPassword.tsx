import { router } from '@inertiajs/react';
import { useState } from 'react';
import PrivilegedAuthShell, {
  BackToLogin,
  ErrorSummary,
  FieldErrors,
  firstError,
  inputClassName,
  passwordMeetsPolicy,
  PasswordPolicy,
  primaryButtonClassName,
  usePrivilegedBearerExchange,
} from './PrivilegedAuthShell';

export default function PrivilegedResetPassword() {
  const exchange = usePrivilegedBearerExchange('/admin/reset-password/exchange');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();
  const completionProof = exchange.completionProof;

  if (exchange.exchanging) {
    return (
      <PrivilegedAuthShell title="Validate reset link" description="Validating this one-time password reset link.">
        <p className="text-sm text-slate-600" role="status">Checking the link…</p>
      </PrivilegedAuthShell>
    );
  }

  if (!exchange.authorized || !completionProof) {
    return (
      <PrivilegedAuthShell title="Reset link unavailable" description="This link may have expired or already been used." footer={<BackToLogin />}>
        <ErrorSummary message={exchange.error} />
      </PrivilegedAuthShell>
    );
  }

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    if (!passwordMeetsPolicy(password)) {
      setError('Choose a password that meets the displayed 12-character policy.');
      return;
    }

    if (password !== confirmation) {
      setError('The password confirmation does not match.');
      return;
    }

    setError(undefined);
    setProcessing(true);
    router.post('/admin/reset-password/complete', {
      completion_proof: completionProof,
      password,
      password_confirmation: confirmation,
    }, {
      onError: (errors) => setError(firstError(errors as FieldErrors, ['completion_proof', 'password', 'password_confirmation', 'error', 'message']) ?? 'Password reset could not be completed.'),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <PrivilegedAuthShell
      title="Choose a new administrator password"
      description="Use a unique password. The server remains the final authority on the displayed 12-character policy."
      footer={<BackToLogin />}
    >
      <form onSubmit={submit} className="space-y-5" noValidate>
        <ErrorSummary message={error} />
        <div>
          <label htmlFor="reset-password" className="mb-2 block text-sm font-semibold text-slate-800">New password</label>
          <input
            id="reset-password"
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className={inputClassName}
            required
          />
          <PasswordPolicy password={password} />
        </div>
        <div>
          <label htmlFor="reset-password-confirmation" className="mb-2 block text-sm font-semibold text-slate-800">Confirm password</label>
          <input
            id="reset-password-confirmation"
            type="password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            className={inputClassName}
            required
          />
        </div>
        <button type="submit" className={primaryButtonClassName} disabled={processing}>
          {processing ? 'Resetting password…' : 'Reset password'}
        </button>
      </form>
    </PrivilegedAuthShell>
  );
}
