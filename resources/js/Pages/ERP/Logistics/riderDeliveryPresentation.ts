import type {
  DeliveryArrival,
  RiderDeliveryBusiness,
  RiderDeliveryIssue,
  RiderDeliveryWorkItem,
  RiderProgressState,
  TrackingShipmentLeg,
} from '@/types/logistics';

export const arrivalStatusText = (arrival: DeliveryArrival) => {
  if (arrival.result === 'verified') {
    const distance = arrival.distance_m == null ? '' : ` · ${Math.round(arrival.distance_m)} m`;
    const time = new Date(arrival.recorded_at).toLocaleTimeString([], {
      hour: '2-digit',
      minute: '2-digit',
    });

    return `Verified arrival${distance} · ${time}`;
  }

  return {
    outside_geofence: 'Outside service point · rider reason recorded',
    low_accuracy: 'Low GPS accuracy · rider reason recorded',
    location_unavailable: 'Location unavailable · rider reason recorded',
  }[arrival.result];
};

const actionableStatuses = new Set([
  'assigned',
  'pickup_scheduled',
  'picked_up',
  'in_transit',
]);

const riderCanProgress = (delivery: TrackingShipmentLeg) =>
  delivery.rider_progress_state === undefined
  || delivery.rider_progress_state === 'active';

export const orderedDeliveries = (deliveries: TrackingShipmentLeg[]) =>
  [...deliveries].sort(
    (a, b) =>
      (a.stop_sequence ?? Number.MAX_SAFE_INTEGER) -
        (b.stop_sequence ?? Number.MAX_SAFE_INTEGER) ||
      a.id - b.id,
  );

export const completedProgress = (deliveries: TrackingShipmentLeg[]) => {
  const completed = deliveries.filter(({ status }) => status === 'delivered').length;
  const total = deliveries.length;

  return {
    completed,
    total,
    percent: total ? Math.round((completed / total) * 100) : 0,
  };
};

export const nextActionableDelivery = (deliveries: TrackingShipmentLeg[]) =>
  orderedDeliveries(deliveries).find((delivery) =>
    riderCanProgress(delivery)
    && (
      actionableStatuses.has(delivery.status)
      || (delivery.status === 'needs_resolution' && delivery.resolution_type === 'retry')
    ),
  );

export const riderProgressLabel = (state?: RiderProgressState) => ({
  active: 'Active delivery',
  proof_submitted: 'Submitted for dispatcher review',
  proof_action_required: 'Proof correction required',
  rider_released: 'Rider released',
}[state ?? 'active'] ?? 'Active delivery');

export const riderResolutionInstruction = (delivery: TrackingShipmentLeg) => {
  if (delivery.resolution_type === 'retry') {
    return `Dispatcher scheduled another attempt${delivery.resolution_reason ? `: ${delivery.resolution_reason}` : ''}`;
  }
  if (delivery.resolution_type === 'return_required') {
    return `Return item to shop${delivery.resolution_reason ? `: ${delivery.resolution_reason}` : ''}`;
  }
  return null;
};

export const matchesBusiness = (
  item: Pick<RiderDeliveryWorkItem | RiderDeliveryIssue, 'business_types'>,
  business: RiderDeliveryBusiness,
) => business === 'all' || item.business_types.includes(business);

export const deliveryContact = (leg?: TrackingShipmentLeg) => {
  const snapshot =
    (leg?.leg_type === 'inbound' ? leg.origin_snapshot : leg?.destination_snapshot) ?? {};
  const value = (key: string) => (typeof snapshot[key] === 'string' ? snapshot[key] : '');

  return {
    name: value('name'),
    phone: value('phone'),
    address: value('address'),
    instructions: value('delivery_instructions'),
  };
};

export const deliveryStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    offered: 'New batch offer',
    accepted: 'Ready to start',
    in_progress: 'In progress',
    assigned: 'Ready for pickup',
    pickup_scheduled: 'Pickup scheduled',
    picked_up: 'Ready to deliver',
    in_transit: 'Delivering',
    delivery_attempted: 'Needs attention',
    awaiting_proof_approval: 'Waiting for proof approval',
    proof_submitted: 'Submitted for dispatcher review',
    proof_action_required: 'Proof correction required',
    proof_correction_required: 'Proof correction required',
    needs_resolution: 'Resolution required',
    delivered: 'Delivered',
    completed: 'Completed',
    declined: 'Declined',
    cancelled: 'Cancelled',
    reassigned: 'Reassigned',
  };

  return (
    labels[status] ??
    status.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase())
  );
};
