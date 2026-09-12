import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import ShipmentTrackingPanel from '@/components/logistics/ShipmentTrackingPanel';
import { logisticsDeliveryLabel, type TrackingShipment } from '@/types/logistics';

export default function ShipmentTracking() {
  const { shipment } = usePage<{ shipment: TrackingShipment }>().props;

  const itemLabel = logisticsDeliveryLabel(shipment);

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
