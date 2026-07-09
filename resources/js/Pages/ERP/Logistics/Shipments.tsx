import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '@/layout/AppLayout_ERP';
import type { LogisticsShipment } from '@/types/logistics';

export default function Shipments() {
  const { shipments } = usePage<{ shipments: LogisticsShipment[] }>().props;

  return (
    <AppLayoutERP>
      <Head title="ERP Logistics Shipments" />
      <div className="space-y-4">
        <h1 className="text-2xl font-bold text-gray-950 dark:text-white">Shipments</h1>
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
          <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
            <thead className="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500 dark:bg-gray-900">
              <tr><th className="px-4 py-3">ID</th><th>Purpose</th><th>Status</th><th>Source</th><th>Legs</th></tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {shipments.map((shipment) => (
                <tr key={shipment.id}>
                  <td className="px-4 py-3 font-semibold">#{shipment.id}</td>
                  <td>{shipment.purpose}</td>
                  <td>{shipment.status}</td>
                  <td>{shipment.source_type} #{shipment.source_id}</td>
                  <td>{shipment.legs?.length ?? 0}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </AppLayoutERP>
  );
}
