import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import type { LogisticsRider } from '@/types/logistics';

export default function Riders() {
  const { riders } = usePage<{ riders: LogisticsRider[] }>().props;

  return (
    <AppLayoutERP>
      <Head title="ERP Logistics Riders" />
      <div className="space-y-4">
        <h1 className="text-2xl font-bold text-gray-950 dark:text-white">Riders</h1>
        <div className="grid gap-3">
          {riders.length === 0 ? <p className="rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-500 dark:border-gray-800 dark:bg-gray-900">No riders yet.</p> : riders.map((rider) => (
            <div key={rider.id} className="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
              <p className="font-semibold text-gray-950 dark:text-white">{rider.name}</p>
              <p className="text-sm text-gray-500">{rider.rider_type} - {rider.availability_status}</p>
            </div>
          ))}
        </div>
      </div>
    </AppLayoutERP>
  );
}
