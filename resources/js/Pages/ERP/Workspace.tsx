import { Link, usePage } from '@inertiajs/react';

import AppLayoutERP from '../../layout/AppLayout_ERP';

type ModuleSummary = {
  key: string;
  label: string;
  url: string | null;
  eligible: boolean;
  enabled: boolean;
  accessible: boolean;
  code: string | null;
  reason: string | null;
};

type WorkspaceProps = {
  enabledModules: ModuleSummary[];
  unavailableModules: ModuleSummary[];
  urls: {
    portal: string;
    settings: string;
    workspace: string;
  };
};

const ModuleGlyph = ({ muted = false }: { muted?: boolean }) => (
  <span
    aria-hidden="true"
    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
      muted
        ? 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
        : 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300'
    }`}
  >
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
      <rect x="4" y="4" width="16" height="16" rx="3" />
      <path d="M8 9h8M8 13h5M8 17h3" strokeLinecap="round" />
    </svg>
  </span>
);

const ModuleCard = ({ module }: { module: ModuleSummary }) => {
  const content = (
    <>
      <ModuleGlyph />
      <div className="min-w-0">
        <h3 className="font-semibold text-gray-900 dark:text-white">{module.label}</h3>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Enabled for this company</p>
        {module.url && (
          <span className="mt-3 inline-flex items-center text-sm font-semibold text-blue-600 transition group-hover:text-blue-700 dark:text-blue-300 dark:group-hover:text-blue-200">
            Open module <span aria-hidden="true" className="ml-1">→</span>
          </span>
        )}
      </div>
    </>
  );

  if (!module.url) {
    return (
      <article className="flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        {content}
      </article>
    );
  }

  return (
    <Link
      href={module.url}
      aria-label={`Open ${module.label} module`}
      className="group flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-5 transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-theme-sm focus:outline-none focus:ring-2 focus:ring-blue-500/40 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-blue-500"
    >
      {content}
    </Link>
  );
};

const Workspace: React.FC = () => {
  const { props } = usePage<WorkspaceProps>();
  const enabledModules = props.enabledModules ?? [];
  const unavailableModules = props.unavailableModules ?? [];

  return (
    <AppLayoutERP>
      <main className="mx-auto max-w-6xl space-y-8" aria-labelledby="erp-workspace-title">
        <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-gray-900 sm:p-8">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-2xl space-y-3">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-300">
                Company operations
              </p>
              <h1 id="erp-workspace-title" className="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                ERP Workspace
              </h1>
              <p className="text-sm leading-6 text-gray-600 dark:text-gray-300">
                A single, owner-safe entry point for the operational tools enabled for this company.
                Module access is controlled by the server and may change as your business configuration changes.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <span className="inline-flex items-center rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-200">
                Owner mode
              </span>
              <Link
                href={props.urls.portal}
                className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-blue-300 hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-gray-700 dark:text-gray-200 dark:hover:border-blue-500 dark:hover:text-blue-200"
              >
                Back to Shop Owner Portal
              </Link>
            </div>
          </div>
        </section>

        <section aria-labelledby="available-modules-title" className="space-y-4">
          <div>
            <h2 id="available-modules-title" className="text-xl font-semibold text-gray-900 dark:text-white">
              Available modules
            </h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              These modules are ready to open for your company.
            </p>
          </div>
          {enabledModules.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2">
              {enabledModules.map((module) => (
                <ModuleCard key={module.key} module={module} />
              ))}
            </div>
          ) : (
            <p className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
              No ERP modules are available yet.
            </p>
          )}
        </section>

        <section aria-labelledby="unavailable-modules-title" className="space-y-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 id="unavailable-modules-title" className="text-xl font-semibold text-gray-900 dark:text-white">
                Unavailable modules
              </h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Reasons are shown so you know what needs attention.
              </p>
            </div>
            <Link
              href={props.urls.settings}
              className="inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/40"
            >
              Manage modules
            </Link>
          </div>
          {unavailableModules.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2">
              {unavailableModules.map((module) => (
                <article key={module.key} className="flex items-start gap-4 rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-gray-900/60">
                  <ModuleGlyph muted />
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="font-semibold text-gray-900 dark:text-white">{module.label}</h3>
                      <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        Unavailable
                      </span>
                    </div>
                    <p className="mt-2 text-sm leading-5 text-gray-600 dark:text-gray-300">
                      {module.reason || 'This module is not available for the current company configuration.'}
                    </p>
                  </div>
                </article>
              ))}
            </div>
          ) : (
            <p className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
              All cataloged modules are currently available.
            </p>
          )}
        </section>
      </main>
    </AppLayoutERP>
  );
};

export default Workspace;
