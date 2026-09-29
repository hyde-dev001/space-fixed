import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
  ErrorSummary,
  FieldErrors,
  inputClassName,
  primaryButtonClassName,
  firstError,
} from './PrivilegedAuthShell';

interface LoginPageProps {
  errors?: FieldErrors;
}

export default function SuperAdminLogin() {
  const { errors = {} } = usePage<LoginPageProps>().props;
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [localError, setLocalError] = useState<string>();

  const errorMessage = localError ?? firstError(errors, ['email', 'password', 'error', 'message']);

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    if (!email.trim() || !password) {
      setLocalError('Enter your email address and password to continue.');
      return;
    }

    setLocalError(undefined);
    setProcessing(true);
    router.post('/admin/login', { email, password, remember }, {
      preserveScroll: true,
      onError: (nextErrors) => {
        setLocalError(firstError(nextErrors as FieldErrors, ['email', 'password', 'error', 'message']) ?? 'Sign in failed. Please try again.');
      },
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <>
      <Head title="Privileged sign in" />
      <div className="min-h-screen bg-slate-950 px-4 py-8 text-slate-950 sm:px-6 lg:py-12">
        <main className="mx-auto flex min-h-[calc(100vh-4rem)] max-w-6xl items-center justify-center">
          <div className="grid w-full overflow-hidden rounded-3xl bg-white shadow-2xl lg:grid-cols-[0.85fr_1.15fr]">
            <aside className="hidden bg-slate-900 p-10 text-white lg:flex lg:flex-col lg:justify-between">
              <div>
                <p className="text-sm font-semibold uppercase tracking-[0.24em] text-slate-400">SoleSpace</p>
                <p className="mt-3 text-3xl font-semibold leading-tight">A deliberate path into privileged operations.</p>
              </div>
              <p className="max-w-xs text-sm leading-6 text-slate-400">
                This sign-in is monitored and protected by staged authentication and MFA.
              </p>
            </aside>

            <section className="p-6 sm:p-10 lg:p-14" aria-labelledby="privileged-login-title">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Privileged access</p>
              <h1 id="privileged-login-title" className="mt-3 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">
                Sign in to the admin console
              </h1>
              <p className="mt-3 max-w-xl text-sm leading-6 text-slate-600">
                Use your administrator credentials. A second factor is required before the console opens.
              </p>

              <form onSubmit={submit} className="mt-8 space-y-5" noValidate>
                <ErrorSummary message={errorMessage} />

                <div>
                  <label htmlFor="privileged-email" className="mb-2 block text-sm font-semibold text-slate-800">Email address</label>
                  <input
                    id="privileged-email"
                    name="email"
                    type="email"
                    autoComplete="username"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    className={inputClassName}
                    required
                  />
                </div>

                <div>
                  <label htmlFor="privileged-password" className="mb-2 block text-sm font-semibold text-slate-800">Password</label>
                  <input
                    id="privileged-password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    className={inputClassName}
                    required
                  />
                </div>

                <label className="flex items-center gap-3 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    name="remember"
                    checked={remember}
                    onChange={(event) => setRemember(event.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-slate-950"
                  />
                  Remember me on this device when appropriate
                </label>

                <button type="submit" className={primaryButtonClassName} disabled={processing}>
                  {processing ? 'Signing in…' : 'Sign in'}
                </button>
              </form>

              <div className="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
                <Link href="/admin/forgot-password" className="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">
                  Forgot password?
                </Link>
                <Link href="/" className="text-slate-500 hover:text-slate-950">Back to homepage</Link>
              </div>

              <p className="mt-8 border-t border-slate-200 pt-5 text-xs leading-5 text-slate-500">
                Admin sign-in attempts are logged and rate limited.
              </p>
            </section>
          </div>
        </main>
      </div>
    </>
  );
}
