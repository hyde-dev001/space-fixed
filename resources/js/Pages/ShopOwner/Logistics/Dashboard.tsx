import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AppLayoutShopOwner from '@/layout/AppLayout_shopOwner';
import type { LogisticsStats } from '@/types/logistics';

export default function Dashboard() {
  const { stats } = usePage<{ stats: LogisticsStats }>().props;

  return (
    <AppLayoutShopOwner>
      <Head title="Logistics" />
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-950">Logistics</h1>
          <p className="text-sm text-gray-500">Track movement, assignments, proof, and riders.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {Object.entries(stats).map(([label, value]) => (
            <div key={label} className="rounded-lg border border-gray-200 bg-white p-5">
              <p className="text-xs font-semibold uppercase text-gray-500">{label}</p>
              <p className="mt-2 text-3xl font-bold text-gray-950">{value}</p>
            </div>
          ))}
        </div>
        <div className="flex flex-wrap gap-3">
          <Link href="/shop-owner/logistics/shipments" className="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white">Shipments</Link>
          <Link href="/shop-owner/logistics/riders" className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-900">Riders</Link>
        </div>
      </div>
    </AppLayoutShopOwner>
  );
}
