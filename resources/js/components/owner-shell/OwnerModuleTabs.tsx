import { Link } from '@inertiajs/react';

export type OwnerModuleTabLink = {
  label: string;
  url: string;
};

type OwnerModuleTabsProps = {
  moduleLabel: string;
  links: OwnerModuleTabLink[];
  currentUrl: string;
};

const pageUrl = (url: string): string => {
  const parsed = new URL(url, 'http://localhost');

  return `${parsed.pathname}${parsed.search}`;
};

const OwnerModuleTabs: React.FC<OwnerModuleTabsProps> = ({ moduleLabel, links, currentUrl }) => {
  const currentPageUrl = pageUrl(currentUrl);

  return (
    <nav aria-label={`${moduleLabel} navigation`} className="mb-6 max-w-full overflow-hidden md:mb-8">
      <div className="flex w-full max-w-full gap-2 overflow-x-auto border-b border-gray-200 pb-1 dark:border-gray-800">
        {links.map((link) => {
          const isCurrent = pageUrl(link.url) === currentPageUrl;

          return (
            <Link
              key={link.url}
              href={link.url}
              aria-current={isCurrent ? 'page' : undefined}
              className={`inline-flex min-h-11 shrink-0 items-center justify-center rounded-t-lg border-b-2 px-4 py-2 text-sm font-semibold transition-colors motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-950 ${
                isCurrent
                  ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-300 dark:bg-blue-500/10 dark:text-blue-200'
                  : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-white'
              }`}
            >
              {link.label}
            </Link>
          );
        })}
      </div>
    </nav>
  );
};

export default OwnerModuleTabs;
