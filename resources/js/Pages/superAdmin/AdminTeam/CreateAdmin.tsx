import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../layout/AppLayout';

interface InviteForm {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  role: 'admin' | 'super_admin';
}

type InviteErrors = Record<string, string | string[] | undefined>;

const inputClassName = 'w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-gray-900 outline-none transition focus:border-blue-600 focus:ring-4 focus:ring-blue-600/10 disabled:bg-gray-100';

function errorText(errors: InviteErrors, key: string): string | undefined {
  const value = errors[key];
  return Array.isArray(value) ? value[0] : value;
}

export default function CreateAdmin() {
  const [form, setForm] = useState<InviteForm>({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    role: 'admin',
  });
  const [errors, setErrors] = useState<InviteErrors>({});
  const [processing, setProcessing] = useState(false);

  const update = (field: keyof InviteForm, value: string) => {
    setForm((current) => ({ ...current, [field]: value } as InviteForm));
    setErrors((current) => ({ ...current, [field]: undefined }));
  };

  const submit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (processing) return;

    const nextErrors: InviteErrors = {};
    if (form.first_name.trim().length < 2) nextErrors.first_name = 'First name is required.';
    if (form.last_name.trim().length < 2) nextErrors.last_name = 'Last name is required.';
    if (!form.email.trim()) nextErrors.email = 'Email is required.';
    if (!form.phone.trim()) nextErrors.phone = 'Phone number is required.';

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors);
      return;
    }

    setErrors({});
    setProcessing(true);
    router.post('/admin/create-admin', form, {
      onError: (serverErrors) => setErrors(serverErrors as InviteErrors),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <AppLayout>
      <main className="space-y-8 p-6 md:p-8">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-gray-500">Administrator lifecycle</p>
            <h1 className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">Invite Administrator</h1>
            <p className="mt-2 max-w-2xl text-gray-600 dark:text-gray-400">
              Send a one-time setup invitation. The invitee creates their own password and enrolls MFA before access is activated.
            </p>
          </div>
          <Link href="/admin/admin" className="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 hover:bg-gray-50">
            Back to administrators
          </Link>
        </div>

        <div className="mx-auto max-w-3xl rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
          <form onSubmit={submit} className="space-y-6 p-6 md:p-8" noValidate>
            {errorText(errors, 'error') && (
              <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {errorText(errors, 'error')}
              </div>
            )}

            <div className="grid gap-5 sm:grid-cols-2">
              <div>
                <label htmlFor="invite-first-name" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">First name</label>
                <input id="invite-first-name" type="text" autoComplete="given-name" value={form.first_name} onChange={(event) => update('first_name', event.target.value)} className={inputClassName} required />
                {errorText(errors, 'first_name') && <p className="mt-1 text-sm text-red-600">{errorText(errors, 'first_name')}</p>}
              </div>
              <div>
                <label htmlFor="invite-last-name" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Last name</label>
                <input id="invite-last-name" type="text" autoComplete="family-name" value={form.last_name} onChange={(event) => update('last_name', event.target.value)} className={inputClassName} required />
                {errorText(errors, 'last_name') && <p className="mt-1 text-sm text-red-600">{errorText(errors, 'last_name')}</p>}
              </div>
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
              <div>
                <label htmlFor="invite-email" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Email address</label>
                <input id="invite-email" type="email" autoComplete="email" value={form.email} onChange={(event) => update('email', event.target.value)} className={inputClassName} required />
                {errorText(errors, 'email') && <p className="mt-1 text-sm text-red-600">{errorText(errors, 'email')}</p>}
              </div>
              <div>
                <label htmlFor="invite-phone" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Phone number</label>
                <input id="invite-phone" type="tel" autoComplete="tel" value={form.phone} onChange={(event) => update('phone', event.target.value)} className={inputClassName} required />
                {errorText(errors, 'phone') && <p className="mt-1 text-sm text-red-600">{errorText(errors, 'phone')}</p>}
              </div>
            </div>

            <div>
              <label htmlFor="invite-role" className="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Administrator role</label>
              <select id="invite-role" value={form.role} onChange={(event) => update('role', event.target.value)} className={inputClassName}>
                <option value="admin">Admin</option>
                <option value="super_admin">Super Admin</option>
              </select>
              {errorText(errors, 'role') && <p className="mt-1 text-sm text-red-600">{errorText(errors, 'role')}</p>}
            </div>

            <div className="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-950">
              No password is collected here. The invitation recipient creates a server-validated password and completes MFA from the one-time link.
            </div>

            <button type="submit" disabled={processing} className="inline-flex w-full items-center justify-center rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-600/20 disabled:cursor-not-allowed disabled:bg-blue-400">
              {processing ? 'Sending invitation…' : 'Send invitation'}
            </button>
          </form>
        </div>
      </main>
    </AppLayout>
  );
}
