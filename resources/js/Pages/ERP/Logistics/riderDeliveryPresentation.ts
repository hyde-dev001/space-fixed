import type {
  RiderDeliveryBusiness,
  RiderDeliveryIssue,
  RiderDeliveryWorkItem,
  TrackingShipmentLeg,
} from '@/types/logistics';

const actionableStatuses = new Set([
  'assigned',
  'pickup_scheduled',
  'picked_up',
  'in_transit',
]);

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
  orderedDeliveries(deliveries).find(({ status }) => actionableStatuses.has(status));

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
