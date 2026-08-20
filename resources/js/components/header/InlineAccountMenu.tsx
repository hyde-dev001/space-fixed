import type { ReactNode } from 'react';

type AccountTone = 'blue' | 'purple' | 'red';

export type InlineAccountAction = {
  label: string;
  onClick: () => void | Promise<void>;
  icon?: ReactNode;
  meta?: ReactNode;
  destructive?: boolean;
};

type InlineAccountMenuProps = {
  name: string;
  email: string;
  role: string;
  tone: AccountTone;
  actions: InlineAccountAction[];
  error?: string | null;
};

const toneClasses: Record<AccountTone, { avatar: string; icon: string; pill: string }> = {
  blue: {
    avatar: 'bg-blue-100 dark:bg-blue-900',
    icon: 'text-blue-600 dark:text-blue-300',
    pill: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
  },
  purple: {
    avatar: 'bg-purple-100 dark:bg-purple-900',
    icon: 'text-purple-600 dark:text-purple-300',
    pill: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
  },
  red: {
    avatar: 'bg-red-100 dark:bg-red-900',
    icon: 'text-red-600 dark:text-red-300',
    pill: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
  },
};

export default function InlineAccountMenu({ name, email, role, tone, actions, error }: InlineAccountMenuProps) {
  const colors = toneClasses[tone];

  return (
    <div className="w-full">
      <div className="border-b border-gray-200 px-4 py-4 dark:border-gray-700 sm:px-5">
        <div className="flex min-w-0 items-center gap-3">
          <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${colors.avatar}`}>
            <svg className={`h-6 w-6 ${colors.icon}`} fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
              <path fillRule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clipRule="evenodd" />
            </svg>
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold text-gray-900 dark:text-white">{name}</p>
            <p className="truncate text-xs text-gray-500 dark:text-gray-400">{email}</p>
            <p className={`mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${colors.pill}`}>{role}</p>
          </div>
        </div>
      </div>

      {error && <p role="alert" className="px-4 pt-3 text-sm text-red-600 dark:text-red-400">{error}</p>}

      <nav aria-label="Account actions" className="divide-y divide-gray-200 dark:divide-gray-700">
        {actions.map((action) => (
          <button
            key={action.label}
            type="button"
            onClick={action.onClick}
            className={`flex min-h-11 w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium transition sm:px-5 ${action.destructive
              ? 'text-gray-700 hover:bg-red-50 hover:text-red-700 dark:text-gray-300 dark:hover:bg-red-900/20 dark:hover:text-red-400'
              : 'text-gray-700 hover:bg-gray-50 hover:text-blue-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-blue-400'}`}
            >
            <span className="flex min-w-0 items-center gap-3">
              <span className="text-gray-400 dark:text-gray-500">
                {action.icon ?? (
                  <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M5 12h14M12 5l7 7-7 7" />
                  </svg>
                )}
              </span>
              <span className="truncate">{action.label}</span>
            </span>
            {action.meta}
          </button>
        ))}
      </nav>
    </div>
  );
}
