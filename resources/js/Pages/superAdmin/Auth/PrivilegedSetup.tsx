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

export default function PrivilegedSetup() {
  const exchange = usePrivilegedBearerExchange('/admin/setup/exchange');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();

  if (exchange.exchanging) {
    return (
      <PrivilegedAuthShell title="Validate setup link" description="Validating this one-time administrator setup link.">
        <p className="text-sm text-slate-600" role="status">Checking the link…</p>
      </PrivilegedAuthShell>
    );
  }

  if (!exchange.authorized) {
    return (
      <PrivilegedAuthShell title="Setup link unavailable" description="This link may have expired or already been used." footer={<BackToLogin />}>
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
    router.post('/admin/setup/complete', {
      password,
      password_confirmation: confirmation,
    }, {
      onError: (errors) => setError(firstError(errors as FieldErrors, ['password', 'password_confirmation', 'error', 'message']) ?? 'Setup could not be completed.'),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <PrivilegedAuthShell
      title="Create your administrator password"
      description="Choose a unique password before enrolling your authenticator. The server remains the final authority on password validation."
      footer={<p className="text-sm text-slate-600">Already completed setup? <BackToLogin /></p>}
    >
      <form onSubmit={submit} className="space-y-5" noValidate>
        <ErrorSummary message={error} />
        <div>
          <label htmlFor="setup-password" className="mb-2 block text-sm font-semibold text-slate-800">New password</label>
          <input
            id="setup-password"
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
          <label htmlFor="setup-password-confirmation" className="mb-2 block text-sm font-semibold text-slate-800">Confirm password</label>
          <input
            id="setup-password-confirmation"
            type="password"
            autoComplete="new-password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            className={inputClassName}
            required
          />
        </div>
        <button type="submit" className={primaryButtonClassName} disabled={processing}>
          {processing ? 'Saving password…' : 'Continue to MFA setup'}
        </button>
      </form>
    </PrivilegedAuthShell>
  );
}
