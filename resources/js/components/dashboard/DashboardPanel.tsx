import type { ReactNode } from 'react';

type DashboardPanelProps = {
  children: ReactNode;
  title: string;
  description?: string;
  eyebrow?: string;
  action?: ReactNode;
  className?: string;
  testId?: string;
};

export default function DashboardPanel({
  children,
  title,
  description,
  eyebrow,
  action,
  className = '',
  testId,
}: DashboardPanelProps) {
  return (
    <section
      data-testid={testId}
      className={'rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-7 ' + className}
    >
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
        <div>
          {eyebrow && <p className="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">{eyebrow}</p>}
          <h2 className="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{title}</h2>
          {description && <p className="mt-1 text-sm leading-5 text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
      <div className="mt-6">{children}</div>
    </section>
  );
}
