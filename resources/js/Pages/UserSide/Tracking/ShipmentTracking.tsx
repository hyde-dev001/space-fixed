import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import ShipmentTrackingPanel from '@/components/logistics/ShipmentTrackingPanel';
import type { TrackingShipment } from '@/types/logistics';

export default function ShipmentTracking() {
  const { shipment } = usePage<{ shipment: TrackingShipment }>().props;

  const isReturn = shipment.purpose === 'refund_return';
  const isRepair = shipment.source_type === 'repair_request';
  const itemLabel = isReturn ? 'Return' : isRepair ? 'Repair Delivery' : 'Shipment';

  return (
    <div className="min-h-screen bg-gray-50">
      <Head title={`${itemLabel} Tracking #${shipment.shipment_number ?? shipment.id}`} />
      <Navigation />

      <main className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <ShipmentTrackingPanel shipment={shipment} />
      </main>
    </div>
  );
}
