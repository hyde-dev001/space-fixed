import axios from 'axios';
import type { ReactNode } from 'react';
import { useState } from 'react';
import AppLayout from '../../../layout/AppLayout';
import RecoveryCodesPanel from '../Auth/RecoveryCodesPanel';
import {
  ErrorSummary,
  apiErrorMessage,
  inputClassName,
  passwordMeetsPolicy,
  PasswordPolicy,
  primaryButtonClassName,
  secondaryButtonClassName,
} from '../Auth/PrivilegedAuthShell';

interface SecurityState {
  role: string;
  status: string;
  mfa_complete: boolean;
  recovery_code_count: number;
}

interface SecurityProps {
  security: SecurityState;
}

const jsonRequest = {
  headers: { Accept: 'application/json' },
  withCredentials: true,
};

export default function Security({ security }: SecurityProps) {
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [acknowledgementToken, setAcknowledgementToken] = useState<string | null>(null);
  const [recoveryProcessing, setRecoveryProcessing] = useState(false);
  const [mfaProcessing, setMfaProcessing] = useState(false);
  const [recoveryError, setRecoveryError] = useState<string>();
  const [recoveryMessage, setRecoveryMessage] = useState<string>();
  const [password, setPassword] = useState({ next: '', confirmation: '' });
  const [passwordProcessing, setPasswordProcessing] = useState(false);
  const [passwordError, setPasswordError] = useState<string>();
  const [passwordMessage, setPasswordMessage] = useState<string>();

  const generateRecoveryCodes = () => {
    if (recoveryProcessing || !security.mfa_complete) return;

    setRecoveryError(undefined);
    setRecoveryMessage(undefined);
    setRecoveryProcessing(true);
    axios.post('/admin/security/recovery/generate', {}, jsonRequest)
      .then((response) => {
        const codes = response.data?.recovery_codes;
        const token = response.data?.acknowledgement_token;
        if (!Array.isArray(codes) || typeof token !== 'string') {
          throw new Error('The recovery-code response was incomplete.');
        }
        setRecoveryCodes(codes);
        setAcknowledgementToken(token);
      })
      .catch((error: unknown) => setRecoveryError(apiErrorMessage(error, 'New recovery codes could not be generated.')))
      .finally(() => setRecoveryProcessing(false));
  };

  const resetMfa = () => {
    if (mfaProcessing || !security.mfa_complete || !window.confirm('Reset your MFA setup? You will need to enroll an authenticator again.')) return;

    setRecoveryError(undefined);
    setMfaProcessing(true);
    axios.post('/admin/security/mfa/reset', {}, jsonRequest)
      .then(() => {
        window.location.assign('/admin/mfa/setup');
      })
      .catch((error: unknown) => setRecoveryError(apiErrorMessage(error, 'MFA could not be reset.')))
      .finally(() => setMfaProcessing(false));
  };

  const changePassword = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (passwordProcessing) return;

    if (!passwordMeetsPolicy(password.next)) {
      setPasswordError('Choose a password that meets the displayed 12-character policy.');
      return;
    }
    if (password.next !== password.confirmation) {
      setPasswordError('The password confirmation does not match.');
      return;
    }

    setPasswordError(undefined);
    setPasswordMessage(undefined);
    setPasswordProcessing(true);
    axios.post('/admin/security/password', {
      password: password.next,
      password_confirmation: password.confirmation,
    }, jsonRequest)
      .then(() => {
        setPassword({ current: '', next: '', confirmation: '' });
        setPasswordMessage('Password changed successfully.');
      })
      .catch((error: unknown) => setPasswordError(apiErrorMessage(error, 'The password could not be changed.')))
      .finally(() => setPasswordProcessing(false));
  };

  const recoveryPanel = recoveryCodes && acknowledgementToken ? (
    <RecoveryCodesPanel
      recoveryCodes={recoveryCodes}
      acknowledgementToken={acknowledgementToken}
      acknowledgementEndpoint="/admin/security/recovery/acknowledge"
      continueLabel="Acknowledge and finish"
      useJsonRequest
      onAcknowledged={() => {
        setRecoveryCodes(null);
        setAcknowledgementToken(null);
        setRecoveryMessage('Recovery codes acknowledged. MFA remains enabled.');
      }}
    />
  ) : null;

  return (
    <main className="space-y-8 p-6 md:p-8">
      <div>
        <p className="text-xs font-bold uppercase tracking-[0.2em] text-gray-500">Account security</p>
        <h1 className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">Security settings</h1>
        <p className="mt-2 max-w-2xl text-gray-600 dark:text-gray-400">Review the server-reported security state and complete sensitive changes deliberately.</p>
      </div>

      <div className="grid max-w-5xl gap-6 lg:grid-cols-2">
        <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800" aria-labelledby="mfa-status-title">
          <div className="flex items-start justify-between gap-4">
            <div>
              <h2 id="mfa-status-title" className="text-xl font-semibold text-gray-900 dark:text-white">Multi-factor authentication</h2>
              <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">The status below is authoritative server state.</p>
            </div>
            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${security.mfa_complete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}`}>
              {security.mfa_complete ? 'MFA enabled' : 'MFA setup required'}
            </span>
          </div>

          {security.mfa_complete ? (
            <>
              <div className="mt-6 rounded-xl bg-gray-50 p-4 dark:bg-gray-900/50">
                <p className="text-sm text-gray-600 dark:text-gray-400">Recovery codes remaining</p>
                <p className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{security.recovery_code_count} remaining</p>
                {security.recovery_code_count === 0 && <p className="mt-2 text-sm text-amber-800">MFA remains active. Generate a new recovery set before you need one.</p>}
              </div>
              <div className="mt-5 flex flex-wrap gap-3">
                <button type="button" onClick={generateRecoveryCodes} disabled={recoveryProcessing || Boolean(recoveryCodes)} className={secondaryButtonClassName}>
                  {recoveryProcessing ? 'Generating…' : 'Generate new recovery codes'}
                </button>
                <button type="button" onClick={resetMfa} disabled={mfaProcessing} className="inline-flex items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-3 font-semibold text-red-800 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50">
                  {mfaProcessing ? 'Resetting…' : 'Reset MFA and require setup'}
                </button>
              </div>
            </>
          ) : (
            <div className="mt-6 rounded-xl bg-amber-50 p-4 text-sm leading-6 text-amber-950">
              MFA setup is required before privileged operations are available.
            </div>
          )}

          <div className="mt-5">
            <ErrorSummary message={recoveryError} />
            {recoveryMessage && <p className="mt-3 text-sm text-emerald-700" role="status">{recoveryMessage}</p>}
          </div>
        </section>

        <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800" aria-labelledby="password-title">
          <h2 id="password-title" className="text-xl font-semibold text-gray-900 dark:text-white">Change password</h2>
          <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">Password changes require recent reauthentication and invalidate other privileged sessions.</p>
          <form onSubmit={changePassword} className="mt-6 space-y-4" noValidate>
            <ErrorSummary message={passwordError} />
            {passwordMessage && <p className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{passwordMessage}</p>}
            <div>
              <label htmlFor="security-new-password" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">New password</label>
              <input id="security-new-password" type="password" autoComplete="new-password" value={password.next} onChange={(event) => setPassword({ ...password, next: event.target.value })} className={inputClassName} required />
              <PasswordPolicy password={password.next} />
            </div>
            <div>
              <label htmlFor="security-password-confirmation" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Confirm new password</label>
              <input id="security-password-confirmation" type="password" autoComplete="new-password" value={password.confirmation} onChange={(event) => setPassword({ ...password, confirmation: event.target.value })} className={inputClassName} required />
            </div>
            <button type="submit" className={primaryButtonClassName} disabled={passwordProcessing}>
              {passwordProcessing ? 'Changing password…' : 'Change password'}
            </button>
          </form>
        </section>
      </div>

      {recoveryPanel && (
        <section className="max-w-5xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800" aria-labelledby="recovery-panel-title">
          <h2 id="recovery-panel-title" className="mb-6 text-xl font-semibold text-gray-900 dark:text-white">New recovery codes</h2>
          {recoveryPanel}
        </section>
      )}
    </main>
  );
}

Security.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
