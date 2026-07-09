import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import type { LogisticsStats } from '@/types/logistics';

export default function Dashboard() {
  const { stats } = usePage<{ stats: LogisticsStats }>().props;

  return (
    <AppLayoutERP>
      <Head title="ERP Logistics" />
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-950 dark:text-white">Logistics</h1>
          <p className="text-sm text-gray-500">Dispatcher queue and rider operations.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {Object.entries(stats).map(([label, value]) => (
            <div key={label} className="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
              <p className="text-xs font-semibold uppercase text-gray-500">{label}</p>
              <p className="mt-2 text-3xl font-bold text-gray-950 dark:text-white">{value}</p>
            </div>
          ))}
        </div>
        <div className="flex flex-wrap gap-3">
          <Link href="/erp/logistics/shipments" className="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Shipments</Link>
          <Link href="/erp/logistics/riders" className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-900">Riders</Link>
        </div>
      </div>
    </AppLayoutERP>
  );
}
