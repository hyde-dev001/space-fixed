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

export default function PrivilegedForgotPassword() {
  const [email, setEmail] = useState('');
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string>();
  const [submitted, setSubmitted] = useState(false);

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    if (!email.trim()) {
      setError('Enter the administrator email address.');
      return;
    }

    setError(undefined);
    setProcessing(true);
    router.post('/admin/forgot-password', { email }, {
      onSuccess: () => setSubmitted(true),
      onError: (errors) => setError(firstError(errors as FieldErrors, ['email', 'error', 'message']) ?? 'The request could not be completed.'),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <PrivilegedAuthShell
      title="Recover administrator access"
      description="Enter your administrator email. If an active account exists, a reset link will be sent without revealing account status."
      footer={<BackToLogin />}
    >
      <form onSubmit={submit} className="space-y-5" noValidate>
        <ErrorSummary message={error} />
        {submitted && (
          <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            If an active administrator account exists, a reset link will be sent.
          </div>
        )}
        <div>
          <label htmlFor="forgot-email" className="mb-2 block text-sm font-semibold text-slate-800">Administrator email</label>
          <input
            id="forgot-email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className={inputClassName}
            required
          />
        </div>
        <button type="submit" className={primaryButtonClassName} disabled={processing}>
          {processing ? 'Sending…' : 'Send reset link'}
        </button>
      </form>
    </PrivilegedAuthShell>
  );
}
