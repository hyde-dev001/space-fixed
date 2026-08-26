import { Link, usePage } from '@inertiajs/react';

import AppLayoutERP from '../../layout/AppLayout_ERP';

type ModulePage = {
  label: string;
  routeName: string;
  url: string;
};

type ActiveModule = {
  key: string;
  slug: string;
  label: string;
  description: string;
  pages: ModulePage[];
};

type ModuleLandingProps = {
  activeModule: ActiveModule;
  urls: {
    workspace: string;
  };
};

const ModuleLanding: React.FC = () => {
  const { props } = usePage<ModuleLandingProps>();
  const module = props.activeModule;

  return (
    <AppLayoutERP>
      <main className="w-full space-y-8" aria-labelledby="erp-module-title">
        <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-gray-900 sm:p-8">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-2xl space-y-3">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-700 dark:text-gray-300">
                ERP module
              </p>
              <h1 id="erp-module-title" className="text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">
                {module.label}
              </h1>
              <p className="text-sm leading-6 text-gray-600 dark:text-gray-300">
                {module.description}
              </p>
            </div>
            <Link
              href={props.urls.workspace}
              className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-900 transition hover:border-gray-900 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-black/30 dark:border-gray-600 dark:text-gray-100 dark:hover:border-gray-300 dark:hover:bg-gray-800 dark:focus:ring-white/30"
            >
              Back to ERP Workspace
            </Link>
          </div>
        </section>

        <section aria-labelledby="erp-module-pages-title" className="space-y-4">
          <div>
            <h2 id="erp-module-pages-title" className="text-xl font-semibold text-gray-900 dark:text-white">
              {module.label} pages
            </h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              Open an operational page for this module.
            </p>
          </div>

          {module.pages.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2">
              {module.pages.map((page) => (
                <Link
                  key={page.routeName}
                  href={page.url}
                  className="group flex items-center justify-between rounded-xl border border-gray-200 bg-white p-5 transition hover:-translate-y-0.5 hover:border-gray-900 hover:shadow-theme-sm focus:outline-none focus:ring-2 focus:ring-black/40 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-400 dark:focus:ring-white/40"
                >
                  <span className="flex items-center gap-4">
                    <span aria-hidden="true" className="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                        <rect x="4" y="4" width="16" height="16" rx="3" />
                        <path d="M8 9h8M8 13h5M8 17h3" strokeLinecap="round" />
                      </svg>
                    </span>
                    <span className="font-semibold text-gray-900 dark:text-white">{page.label}</span>
                  </span>
                  <span aria-hidden="true" className="text-lg text-gray-900 transition-transform group-hover:translate-x-1 dark:text-white">
                    →
                  </span>
                </Link>
              ))}
            </div>
          ) : (
            <p className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
              No pages are available for this module yet.
            </p>
          )}
        </section>
      </main>
    </AppLayoutERP>
  );
};

export default ModuleLanding;
