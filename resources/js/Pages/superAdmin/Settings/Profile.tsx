import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLayout from '../../../layout/AppLayout';

interface ProfileProps {
  auth?: {
    user?: {
      name?: string;
      email?: string;
    };
  };
  admin?: {
    name?: string;
    first_name?: string;
    last_name?: string;
    email?: string;
  };
}

export default function Profile({ auth, admin }: ProfileProps) {
  const user = auth?.user;
  const displayName = user?.name || admin?.name || [admin?.first_name, admin?.last_name].filter(Boolean).join(' ') || 'Super Admin';
  const displayEmail = user?.email || admin?.email || 'Administrator email';

  return (
    <div className="p-6">
      <div className="mb-8">
        <p className="text-xs font-bold uppercase tracking-[0.2em] text-gray-500">Privileged account</p>
        <h1 className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">My Profile</h1>
        <p className="mt-2 text-gray-600 dark:text-gray-400">Review your administrator identity and open protected security controls.</p>
      </div>

      <div className="max-w-4xl space-y-6">
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-xl font-semibold text-gray-900 dark:text-white">Security settings</h2>
              <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Review MFA status, recovery-code availability, and protected security actions.
              </p>
            </div>
            <Link
              href="/admin/security"
              className="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
            >
              Security settings
            </Link>
          </div>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
          <h2 className="mb-6 text-xl font-semibold text-gray-900 dark:text-white">Profile information</h2>
          <dl className="space-y-4">
            <div>
              <dt className="text-sm font-medium text-gray-700 dark:text-gray-300">Full name</dt>
              <dd className="mt-1 text-lg text-gray-900 dark:text-white">{displayName}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-700 dark:text-gray-300">Email address</dt>
              <dd className="mt-1 text-lg text-gray-900 dark:text-white">{displayEmail}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-700 dark:text-gray-300">Role</dt>
              <dd className="mt-1">
                <span className="inline-flex rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                  Super Administrator
                </span>
              </dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  );
}

Profile.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
