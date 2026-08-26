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
        : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white'
    } lg:h-11 lg:w-11 lg:rounded-2xl lg:shadow-theme-xs`}
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
        <div className="lg:flex lg:items-start lg:justify-between lg:gap-3">
          <h3 className="font-semibold text-gray-900 dark:text-white">{module.label}</h3>
          <span className="hidden shrink-0 items-center gap-1.5 rounded-full border border-green-200 bg-green-50 px-2.5 py-1 text-[11px] font-semibold text-green-700 lg:inline-flex dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
            <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-green-500" />
            Ready
          </span>
        </div>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Enabled for this company</p>
        {module.url && (
          <span className="mt-3 inline-flex items-center text-sm font-semibold text-gray-950 transition group-hover:text-black dark:text-white dark:group-hover:text-gray-200">
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
      className="group flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-5 transition hover:-translate-y-0.5 hover:border-gray-900 hover:shadow-theme-sm focus:outline-none focus:ring-2 focus:ring-black/40 lg:min-h-40 lg:rounded-2xl lg:p-6 lg:shadow-theme-xs dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-400 dark:focus:ring-white/40"
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
      <main className="w-full space-y-8 lg:space-y-10" aria-labelledby="erp-workspace-title">
        <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-gray-900 sm:p-8 lg:rounded-3xl lg:border-gray-200/80 lg:p-10">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between lg:gap-10">
            <div className="max-w-2xl space-y-3 lg:max-w-3xl">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-700 dark:text-gray-300">
                Company operations
              </p>
              <h1 id="erp-workspace-title" className="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white lg:text-4xl lg:leading-tight">
                ERP Workspace
              </h1>
              <p className="text-sm leading-6 text-gray-600 dark:text-gray-300 lg:text-[15px] lg:leading-7">
                A single, owner-safe entry point for the operational tools enabled for this company.
                Module access is controlled by the server and may change as your business configuration changes.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-3 lg:justify-end lg:pt-1">
              <span className="inline-flex items-center rounded-full bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white lg:border lg:border-gray-700 lg:px-4 lg:py-2 dark:bg-white dark:text-gray-900">
                Owner mode
              </span>
              <Link
                href={props.urls.portal}
                className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-900 transition hover:border-gray-900 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-black/30 lg:min-w-[13.5rem] lg:whitespace-nowrap lg:shadow-theme-xs dark:border-gray-600 dark:text-gray-100 dark:hover:border-gray-300 dark:hover:bg-gray-800 dark:focus:ring-white/30"
              >
                Back to Shop Owner Portal
              </Link>
            </div>
          </div>
        </section>

        <section aria-labelledby="available-modules-title" className="space-y-4 lg:space-y-5">
          <div className="lg:flex lg:items-end lg:justify-between lg:gap-6">
            <div>
              <h2 id="available-modules-title" className="text-xl font-semibold text-gray-900 dark:text-white lg:text-2xl">
                Available modules
              </h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400 lg:mt-2">
                These modules are ready to open for your company.
              </p>
            </div>
            <span className="hidden items-center rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 lg:inline-flex dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
              {enabledModules.length} {enabledModules.length === 1 ? 'module' : 'modules'} ready
            </span>
          </div>
          {enabledModules.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2 lg:gap-5">
              {enabledModules.map((module) => (
                <ModuleCard key={module.key} module={module} />
              ))}
            </div>
          ) : (
            <p className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 lg:rounded-2xl lg:p-6 lg:shadow-theme-xs dark:border-gray-700 dark:text-gray-400">
              No ERP modules are available yet.
            </p>
          )}
        </section>

        <section aria-labelledby="unavailable-modules-title" className="space-y-4 lg:space-y-5">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 id="unavailable-modules-title" className="text-xl font-semibold text-gray-900 dark:text-white lg:text-2xl">
                Unavailable modules
              </h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400 lg:mt-2">
                Reasons are shown so you know what needs attention.
              </p>
            </div>
            <Link
              href={props.urls.settings}
              className="inline-flex min-h-11 items-center justify-center rounded-lg bg-black px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-black/40 lg:min-w-[9rem] lg:shadow-theme-sm dark:bg-black dark:hover:bg-gray-900 dark:focus:ring-white/40"
            >
              Manage modules
            </Link>
          </div>
          {unavailableModules.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2 lg:gap-5">
              {unavailableModules.map((module) => (
                <article key={module.key} className="flex items-start gap-4 rounded-xl border border-gray-200 bg-gray-50 p-5 lg:min-h-40 lg:rounded-2xl lg:p-6 lg:shadow-theme-xs dark:border-gray-800 dark:bg-gray-900/60">
                  <ModuleGlyph muted />
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="font-semibold text-gray-900 dark:text-white">{module.label}</h3>
                      <span className="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold text-gray-600 lg:border lg:border-gray-300/70 dark:bg-gray-800 dark:text-gray-300">
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
            <p className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 lg:rounded-2xl lg:p-6 lg:shadow-theme-xs dark:border-gray-700 dark:text-gray-400">
              All cataloged modules are currently available.
            </p>
          )}
        </section>
      </main>
    </AppLayoutERP>
  );
};

export default Workspace;
