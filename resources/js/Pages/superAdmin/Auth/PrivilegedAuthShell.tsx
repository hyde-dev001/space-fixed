import axios from 'axios';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';

export type FieldErrors = Record<string, string | string[] | undefined>;

export const inputClassName =
  'w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-950 shadow-sm outline-none transition focus:border-slate-950 focus:ring-4 focus:ring-slate-950/10 disabled:bg-slate-100';

export const primaryButtonClassName =
  'inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-950/20 disabled:cursor-not-allowed disabled:bg-slate-400';

export const secondaryButtonClassName =
  'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 font-semibold text-slate-800 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-950/10 disabled:cursor-not-allowed disabled:opacity-50';

export function firstError(errors: FieldErrors | undefined, keys: string[]): string | undefined {
  for (const key of keys) {
    const value = errors?.[key];
    if (Array.isArray(value) && value[0]) return value[0];
    if (typeof value === 'string' && value) return value;
  }

  return undefined;
}

export function apiErrorMessage(error: unknown, fallback: string): string {
  const responseData = (error as {
    response?: { data?: { message?: unknown; errors?: FieldErrors } };
  })?.response?.data;

  if (typeof responseData?.message === 'string' && responseData.message) {
    return responseData.message;
  }

  return firstError(responseData?.errors, Object.keys(responseData?.errors ?? {})) ?? fallback;
}

export function ErrorSummary({ message }: { message?: string }) {
  if (!message) return null;

  return (
    <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" aria-live="assertive">
      {message}
    </div>
  );
}

export function PasswordPolicy({ password }: { password: string }) {
  const requirements = [
    { label: 'At least 12 characters', met: password.length >= 12 },
    { label: 'Uppercase and lowercase letters', met: /[A-Z]/.test(password) && /[a-z]/.test(password) },
    { label: 'At least one number', met: /\d/.test(password) },
    { label: 'At least one symbol', met: /[^a-zA-Z0-9]/.test(password) },
  ];

  return (
    <ul className="mt-3 grid gap-1 text-xs text-slate-600" aria-label="Password requirements">
      {requirements.map((requirement) => (
        <li key={requirement.label} className={requirement.met ? 'text-emerald-700' : undefined}>
          <span aria-hidden="true" className="mr-2">{requirement.met ? '✓' : '○'}</span>
          {requirement.label}
        </li>
      ))}
    </ul>
  );
}

export function passwordMeetsPolicy(password: string): boolean {
  return password.length >= 12
    && /[A-Z]/.test(password)
    && /[a-z]/.test(password)
    && /\d/.test(password)
    && /[^a-zA-Z0-9]/.test(password);
}

interface PrivilegedAuthShellProps {
  title: string;
  eyebrow?: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}

export default function PrivilegedAuthShell({
  title,
  eyebrow = 'Privileged access',
  description,
  children,
  footer,
}: PrivilegedAuthShellProps) {
  return (
    <>
      <Head title={title} />
      <div className="min-h-screen bg-slate-950 px-4 py-8 text-slate-950 sm:px-6 lg:py-12">
        <main className="mx-auto flex min-h-[calc(100vh-4rem)] max-w-6xl items-center justify-center">
          <div className="grid w-full overflow-hidden rounded-3xl bg-white shadow-2xl lg:grid-cols-[0.85fr_1.15fr]">
            <aside className="hidden bg-slate-900 p-10 text-white lg:flex lg:flex-col lg:justify-between">
              <div>
                <p className="text-sm font-semibold uppercase tracking-[0.24em] text-slate-400">SoleSpace</p>
                <p className="mt-3 text-3xl font-semibold leading-tight">A deliberate path into privileged operations.</p>
              </div>
              <p className="max-w-xs text-sm leading-6 text-slate-400">
                Every privileged action is protected by staged authentication, MFA, recent reauthentication, and audit logging.
              </p>
            </aside>

            <section className="p-6 sm:p-10 lg:p-14" aria-labelledby="privileged-page-title">
              <div className="mb-8">
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">{eyebrow}</p>
                <h1 id="privileged-page-title" className="mt-3 text-3xl font-semibold tracking-tight text-slate-950 sm:text-4xl">
                  {title}
                </h1>
                {description && <p className="mt-3 max-w-xl text-sm leading-6 text-slate-600">{description}</p>}
              </div>
              {children}
              {footer && <div className="mt-8 border-t border-slate-200 pt-6">{footer}</div>}
            </section>
          </div>
        </main>
      </div>
    </>
  );
}

interface BearerExchangeState {
  exchanging: boolean;
  authorized: boolean;
  error?: string;
}

export function usePrivilegedBearerExchange(endpoint: string): BearerExchangeState {
  const [state, setState] = useState<BearerExchangeState>({ exchanging: true, authorized: false });

  useEffect(() => {
    let mounted = true;
    const fragment = window.location.hash;
    const encodedToken = fragment.startsWith('#token=') ? fragment.slice('#token='.length) : '';

    window.history.replaceState(null, document.title, `${window.location.pathname}${window.location.search}`);

    if (!encodedToken) {
      setState({ exchanging: false, authorized: false, error: 'This security link is missing or invalid.' });
      return () => {
        mounted = false;
      };
    }

    let rawToken = '';
    try {
      rawToken = decodeURIComponent(encodedToken);
    } catch {
      rawToken = '';
    }

    if (!rawToken) {
      setState({ exchanging: false, authorized: false, error: 'This security link is missing or invalid.' });
      return () => {
        mounted = false;
      };
    }

    setState({ exchanging: true, authorized: false });

    axios.post(endpoint, { token: rawToken }, {
      headers: { Accept: 'application/json' },
      withCredentials: true,
    }).then(() => {
      if (mounted) setState({ exchanging: false, authorized: true });
    }).catch((error: unknown) => {
      if (mounted) {
        setState({
          exchanging: false,
          authorized: false,
          error: apiErrorMessage(error, 'This security link is invalid or expired.'),
        });
      }
    }).finally(() => {
      rawToken = '';
    });

    return () => {
      mounted = false;
      rawToken = '';
    };
  }, [endpoint]);

  return state;
}

export function BackToLogin() {
  return (
    <Link href="/admin/login" className="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">
      Return to privileged sign in
    </Link>
  );
}
