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
  overview: {
    label: string;
    url: string;
  };
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
              className="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-900 transition hover:border-gray-900 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-black/30 dark:border-gray-700 dark:text-gray-200 dark:hover:border-gray-400 dark:hover:bg-gray-800"
            >
              Back to ERP Workspace
            </Link>
          </div>
        </section>

        <section aria-labelledby="erp-module-pages-title" className="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-gray-900 sm:p-8">
          <div className="space-y-2">
            <h2 id="erp-module-pages-title" className="text-xl font-semibold text-gray-900 dark:text-white">
              Available pages
            </h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {module.pages.length} {module.pages.length === 1 ? 'page' : 'pages'} available in the module navigation.
            </p>
          </div>
        </section>
      </main>
    </AppLayoutERP>
  );
};

export default ModuleLanding;
