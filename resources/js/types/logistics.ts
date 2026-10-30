export type LogisticsModule = 'retail' | 'repair';

export type DeliveryType =
  | 'retail_delivery'
  | 'retail_return'
  | 'repair_pickup'
  | 'repair_return'
  | 'return_to_shop'
  | 'unknown'
  | 'mixed';

export type DeliveryTypePresentation = {
  delivery_type?: DeliveryType | string | null;
  delivery_label?: string | null;
  delivery_method?: 'shop_owned' | 'third_party' | string | null;
  provider?: string | null;
  provider_status?: string | null;
};

export const logisticsDeliveryLabel = (value?: DeliveryTypePresentation | null): string =>
  value?.delivery_label || 'Unknown Delivery Type';

export type LogisticsSchedule = {
  morning_start?: string;
  morning_end?: string;
  afternoon_start?: string;
  afternoon_end?: string;
  timezone?: string;
  operating_days: number[];
  blackout_dates: string[];
};

export const logisticsWindowLabel = (window: 'morning' | 'afternoon', schedule?: LogisticsSchedule) => {
  const label = window === 'morning' ? 'Morning' : 'Afternoon';
  const start = schedule?.[`${window}_start`], end = schedule?.[`${window}_end`];
  return start && end ? `${label} (${start} - ${end})` : `${label} (hours set by shop)`;
};

export const logisticsModuleForSourceType = (sourceType?: string | null): LogisticsModule | null => {
  if (sourceType === 'order' || sourceType === 'order_refund') return 'retail';
  if (sourceType === 'repair_request') return 'repair';
  return null;
};

export const logisticsModuleLabel = (module?: LogisticsModule | 'mixed' | null) => {
  if (module === 'retail') return 'Retail';
  if (module === 'repair') return 'Repair';
  return 'Mixed (legacy)';
};

export type LogisticsSourceSummary = {
  request_number: string;
  customer_name: string;
  shoe_summary: string;
};

export type LogisticsOrderItemSummary = {
  id: number;
  brand?: string | null;
  model: string;
  image?: string | null;
  color?: string | null;
  size?: string | null;
  quantity: number;
};

export type LogisticsOrderSummary = {
  available: boolean;
  order_id: number;
  order_number?: string | null;
  payment_method?: string | null;
  delivery_method?: 'shop_owned' | 'third_party' | null;
  carrier_company?: string | null;
  cod_expected_amount?: string | null;
  cod_collection_status?: 'pending' | 'cash_collected' | 'settled' | string | null;
  cod_collected_amount?: string | null;
  cod_collection_reference?: string | null;
  cod_collected_at?: string | null;
  cod_rider_name?: string | null;
  cod_remittance_status?: 'not_submitted' | 'submitted' | 'settled' | 'disputed' | string | null;
  cod_remittance_reference?: string | null;
  items: LogisticsOrderItemSummary[];
  total_quantity: number;
  variant_count: number;
  model_count: number;
};

export type CodCollectionView = {
  id: number;
  order_id: number;
  order_number?: string | null;
  expected_amount: string;
  collected_amount: string;
  status: 'pending' | 'cash_collected' | 'settled' | string;
  collected_at?: string | null;
  remittance_reference?: string | null;
};

export type CodRemittanceView = {
  id: number;
  reference: string;
  rider_user_id?: number | null;
  rider_name?: string | null;
  expected_amount: string;
  submitted_amount: string;
  received_amount?: string | null;
  variance_amount?: string | null;
  status: 'submitted' | 'settled' | 'disputed' | string;
  submitted_at?: string | null;
  confirmed_at?: string | null;
  dispute_reason?: string | null;
  cumulative_received_amount: string;
  remaining_shortage_amount: string;
  pending_supplement_amount: string;
  available_supplement_amount: string;
  supplements?: CodRemittanceSupplementView[];
  items?: Array<{
    id: number;
    cod_collection_id: number;
    expected_amount: string;
    collected_amount: string;
    collection_status: string;
    order_id?: number | null;
    order_number?: string | null;
  }>;
};

export type CodRemittanceSupplementView = {
  id: number;
  submitted_amount: string;
  received_amount?: string | null;
  submitted_at?: string | null;
  submitted_by_name?: string | null;
  confirmed_at?: string | null;
  confirmed_by_name?: string | null;
  dispute_reason?: string | null;
  pending: boolean;
};

export type CodCollectionPageData = {
  summary: {
    cash_currently_held: string;
    pending_remittance_count: number;
  };
  pending_remittance: CodCollectionView[];
  submitted_remittances: Array<Pick<CodRemittanceView, 'id' | 'reference' | 'expected_amount' | 'submitted_amount' | 'received_amount' | 'variance_amount' | 'status' | 'submitted_at' | 'dispute_reason' | 'cumulative_received_amount' | 'remaining_shortage_amount' | 'pending_supplement_amount' | 'available_supplement_amount'>>;
  settled_history: CodCollectionView[];
};

export const logisticsSourceLabel = (shipment?: {
  source_type: string;
  source_id: number;
  source_summary?: LogisticsSourceSummary | null;
  order_summary?: LogisticsOrderSummary | null;
} | null) => {
  if (!shipment) return 'Delivery';
  if (shipment.source_type === 'repair_request') {
    return `Repair ${shipment.source_summary?.request_number || `#${shipment.source_id}`}`;
  }
  if (shipment.source_type === 'order_refund') return `Return #${shipment.source_id}`;
  if (shipment.source_type === 'order') {
    const rawOrderNumber = String(shipment.order_summary?.order_number ?? '').trim();
    const orderNumber = rawOrderNumber || String(shipment.source_id);

    return `Order ${orderNumber.startsWith('#') ? orderNumber : `#${orderNumber}`}`;
  }
  return `Delivery #${shipment.source_id}`;
};

export type DeliveryContactSnapshot = {
  type?: string;
  name?: string;
  phone?: string;
  address?: string;
  delivery_instructions?: string | null;
  latitude?: number | null;
  longitude?: number | null;
};

export type DeliveryArrival = {
  id: number;
  arrival_type: 'pickup' | 'dropoff';
  place?: 'shop' | 'customer';
  result: 'verified' | 'recorded' | 'outside_geofence' | 'low_accuracy' | 'location_unavailable';
  distance_m?: number | null;
  radius_m: number;
  accuracy_m?: number | null;
  exception_reason?: string | null;
  exception_notes?: string | null;
  recorded_at: string;
};

export type CustomerDeliveryProof = {
  id: number;
  proof_number?: number | null;
  available: boolean;
  url: string | null;
  delivered_at?: string | null;
  location: string;
  tracking_number: string;
  status: 'Delivered';
};

export type RiderProgressState =
  | 'active'
  | 'proof_submitted'
  | 'proof_action_required'
  | 'rider_released';

export type ProofReviewSummary = {
  state: string;
  proof_id?: number | null;
  replaces_proof_id?: number | null;
  rejection_reason?: string | null;
  replacement_allowed?: boolean;
};

export type TrackingShipmentLeg = {
  operational_label?: string | null;
  activation_required?: boolean;
  custody_state?: string;
  custody_rider_profile_id?: number | null;
  batch_interruption?: BatchInterruption | null;
  start_blocked_reason?: string | null;
  customer_appointment_date?: string | null;
  customer_appointment_window?: 'morning' | 'afternoon' | null;
  id: number;
  delivery_number?: number | null;
  delivery_type?: DeliveryType | string | null;
  delivery_label?: string | null;
  delivery_method?: 'shop_owned' | 'third_party' | string | null;
  provider?: string | null;
  delivery_batch_id?: number | null;
  return_for_leg_id?: number | null;
  sequence: number;
  leg_type: string;
  status: string;
  rider_progress_state?: RiderProgressState;
  proof_review?: ProofReviewSummary | null;
  resolution_type?: string | null;
  resolution_reason?: string | null;
  failed_attempt_count?: number;
  failed_pickup_count?: number;
  max_delivery_attempts?: number;
  origin_snapshot?: (DeliveryContactSnapshot & Record<string, unknown>) | null;
  destination_snapshot?: (DeliveryContactSnapshot & Record<string, unknown>) | null;
  tracking_number?: string | null;
  tracking_url?: string | null;
  requires_delivery_proof?: boolean;
  scheduled_pickup_at?: string | null;
  assigned_at?: string | null;
  picked_up_at?: string | null;
  delivered_at?: string | null;
  scheduled_delivery_date?: string | null;
  delivery_window?: 'morning' | 'afternoon' | null;
  schedule_status?: string | null;
  stop_sequence?: number | null;
  urgent_at?: string | null;
  arrivals?: Partial<Record<'pickup' | 'dropoff', DeliveryArrival>>;
  delivery_proof?: CustomerDeliveryProof | null;
  latest_failed_attempt?: {
    id: number;
    attempt_type?: 'pickup' | 'delivery';
    attempt_number?: number | null;
    delivery_assignment_id?: number | null;
    delivery_batch_id?: number | null;
    reason: string;
    attempted_at?: string | null;
    proof_url?: string | null;
  } | null;
  shipment?: {
    id: number;
    shipment_number?: number | null;
    delivery_type?: DeliveryType | string | null;
    delivery_label?: string | null;
    source_type: string;
    source_id: number;
    source_summary?: LogisticsSourceSummary | null;
    order_summary?: LogisticsOrderSummary | null;
    purpose: string;
  };
  assignments?: Array<{
    id: number;
    status: string;
    rider_profile?: LogisticsRider | null;
  }>;
  proofs?: Array<{
    id: number;
    proof_number?: number | null;
    handoff_type: string;
    proof_type: string;
    proof_url?: string | null;
    review_status?: string;
    rejection_reason?: string | null;
    replaces_proof_id?: number | null;
    replaces_proof_number?: number | null;
    recorded_at?: string | null;
    reviewed_at?: string | null;
    reviewed_by_id?: number | null;
  }>;
  attempts?: Array<{
    id: number;
    attempt_type?: 'pickup' | 'delivery';
    attempt_number?: number | null;
    status?: string;
    reason_code?: string | null;
    notes?: string | null;
    proof_available?: boolean;
    proof_url?: string | null;
    attempted_at?: string | null;
  }>;
  incidents?: LogisticsIncident[];
  live_tracking?: {
    leg_id: number;
    delivery_type?: DeliveryType | string | null;
    delivery_label?: string | null;
    status: string | null;
    destination: Partial<DeliveryContactSnapshot> & Record<string, unknown>;
    location: {
      latitude: number;
      longitude: number;
      accuracy_m: number | null;
      speed_mps: number | null;
      heading_deg: number | null;
      recorded_at: string | null;
      received_at: string | null;
    };
    stale: boolean;
    route?: LiveTrackingRoute | null;
  } | null;
};

export type LogisticsIncident = {
  loss?: { review_reason?: string | null; action_incident_id?: number; action_revision?: number; recovery?: { parent_id: number; condition: string; notes: string; reported_at: string | null; confirmed: boolean; status: string } | null; stage: string; actions: string[]; parent_id: number | null; after_final: boolean; reporting_rider_profile_id: number } | null;
  revision?: number;
  decided_at?: string | null;
  eligibility?: Record<string, { allowed: boolean; conditional: boolean; label: string; reason: string | null }>;
  id: number;
  type: 'damaged' | 'lost' | 'vehicle_problem' | 'customer_dispute' | 'other' | string;
  status: 'reported' | 'under_review' | 'resolved' | string;
  notes?: string | null;
  resolution?: string | null;
  resolved_at?: string | null;
  reporting_rider_profile_id?: number | null;
  evidence_urls?: string[];
};

export type ParcelRecovery = {
  staff_review_required?: boolean;
  can_receive?: boolean;
  receipt_recorded?: boolean;
  can_reauthorize?: boolean;
  can_investigate?: boolean;
  can_supervise_recovery?: boolean;
  safekeeping_pending?: boolean;
  can_inspect?: boolean;
  current_delivery_id?: number;
  movement_status?: string;
  holder_label?: string;
  shop_action_blocker?: string | null;
  evidence_urls?: string[];
  retry_findings?: Record<string, unknown> | null;
  incident_id: number;
  revision: number;
  type: string;
  resolution: string;
  operational_status: string;
  source_type: string;
  source_id: number;
  shipment_id: number;
  delivery_id: number;
  custody: string;
  received: boolean;
  inspected: boolean;
  quarantined: boolean;
  next_action: string;
  complete: boolean;
  return_leg_id: number | null;
  return_proof_id: number | null;
  retry_context: TrackingShipmentLeg;
  expected_items: Array<{ reference: string; label: string; expected_quantity: number }>;
  actions: Array<{ action: string; label: string; allowed: boolean }>;
  can_close: boolean;
  source_outcome: { shipment_id?: number; leg_id?: number; refund_id?: number; repair_request_id?: number };
  obligations: Array<{ type: string; id: number; status: string | null; complete: boolean; next_actor: string; note?: string }>;
  transfers: CustodyHandoff[];
};

export type TrackingShipmentEvent = {
  id: number;
  shipment_leg_id?: number | null;
  event_type: string;
  message?: string | null;
  created_at?: string | null;
};

export type TrackingShipment = {
  recovery?: { state: 'delayed' | 'awaiting_handoff' | 'preparing_continuation'; label: string; message: string; next_step: string } | null;
  id: number;
  shipment_number?: number | null;
  delivery_type?: DeliveryType | string | null;
  delivery_label?: string | null;
  delivery_method?: 'shop_owned' | 'third_party' | string | null;
  provider?: string | null;
  purpose: string;
  status: string;
  source_type: string;
  source_summary?: LogisticsSourceSummary | null;
  order_summary?: LogisticsOrderSummary | null;
  created_at?: string | null;
  legs: TrackingShipmentLeg[];
  events: TrackingShipmentEvent[];
  live_tracking_enabled?: boolean;
};

export type LogisticsStats = {
  requested: number;
  active: number;
  completed: number;
  cancelled: number;
  due_today: number;
  overdue: number;
  failed_attempts: number;
  unassigned: number;
  rider_workload: number;
  delivery_success_rate: number;
};

export type PaginationLink = {
  url: string | null;
  label: string;
  active: boolean;
};

export type PaginatedResponse<T> = {
  data: T[];
  links: PaginationLink[];
  from: number | null;
  to: number | null;
  total: number;
  current_page: number;
  last_page: number;
};

export type LogisticsShipment = {
  id: number;
  shipment_number?: number | null;
  delivery_type?: DeliveryType | string | null;
  delivery_label?: string | null;
  delivery_method?: 'shop_owned' | 'third_party' | string | null;
  provider?: string | null;
  purpose: string;
  status: string;
  source_type: string;
  source_id: number;
  source_summary?: LogisticsSourceSummary | null;
  order_summary?: LogisticsOrderSummary | null;
  customer_disputes?: Array<{
    id: number;
    status: string;
    reason: string;
    notes?: string | null;
    reported_at?: string | null;
    resolution?: string | null;
    resolution_note?: string | null;
    resolved_at?: string | null;
    evidence?: Array<{
      id: string;
      kind: 'image' | 'video';
      mime_type?: string | null;
      original_name?: string | null;
      url: string;
    }>;
  }>;
  created_at?: string | null;
  legs?: TrackingShipmentLeg[];
};

export type LogisticsRiderOption = { id: number; name: string; daily_capacity?: number | null; availability_status?: string };

export type LogisticsRider = {
  id: number;
  rider_type: string;
  name: string;
  phone?: string | null;
  availability_status: string;
  active: boolean;
  daily_capacity?: number | null;
};

export type DeliveryBatchStatus = 'draft' | 'offered' | 'accepted' | 'in_progress' | 'interrupted' | 'recovered' | 'completed' | 'cancelled';

export type BatchInterruption = {
  id: number;
  revision: number;
  status: string;
  source_batch_id: number;
  recovery_batch_id?: number | null;
  recovery_batch_status?: string | null;
  notes?: string;
  resolution?: string | null;
  resolution_note?: string | null;
  reported_at?: string | null;
  decided_at?: string | null;
  decided_by_user_id?: number | null;
  resolved_at?: string | null;
  required_parcels: number;
  confirmed_parcels: number;
  evidence_urls?: string[];
  stops: Array<{ shipment_leg_id: number; delivery_reference: string; sequence: number | null; status: string;
    custody_state: string; custody_rider_profile_id?: number | null; requires_handoff: boolean; handoff_complete: boolean; transfer_status: string | null;
    customer_appointment_date?: string | null; customer_appointment_window?: string | null }>;
};

export type BatchSuggestion = {
  rider_profile_id: number;
  capacity: number;
  assigned_count: number;
  overload_count: number;
  module: LogisticsModule;
  leg_ids: number[];
};

export type DeliveryBatch = {
  interruption?: BatchInterruption | null;
  id: number;
  delivery_date: string;
  delivery_window: 'morning' | 'afternoon';
  status: DeliveryBatchStatus;
  capacity: number;
  assigned_stop_count: number;
  rejection_reason?: string | null;
  rejected_at?: string | null;
  cancellation_reason?: string | null;
  stop_snapshot?: TrackingShipmentLeg[] | null;
  cancelled_stops?: TrackingShipmentLeg[] | null;
  dispatcher_override_reason?: string | null;
  module?: LogisticsModule | 'mixed';
  rider_profile?: LogisticsRider | null;
  legs: TrackingShipmentLeg[];
};

export type DeliveryBatchPageProps = {
  today?: string;
  logisticsSchedule?: LogisticsSchedule;
  batches: DeliveryBatch[];
  pool: TrackingShipmentLeg[];
  unscheduled: TrackingShipmentLeg[];
  riders: LogisticsRider[];
  dailyRiderCapacity: number;
  filters?: {
    module?: 'all' | LogisticsModule;
    date?: string | null;
    window?: 'all' | 'morning' | 'afternoon';
  };
  availableModules?: LogisticsModule[];
  showModuleFilter?: boolean;
};

export type RiderDeliveryBusiness = 'all' | 'retail' | 'repair';
export type RiderDeliveryTab = 'upcoming' | 'history' | 'issues' | 'all';

export type RiderDeliveryWorkItem = {
  interruption?: BatchInterruption | null;
  start_blocked_reason?: string | null;
  item_type: 'work';
  key: string;
  kind: 'batch' | 'single';
  id: number;
  status: string;
  group: 'offer' | 'current' | 'upcoming' | 'history' | 'conflict';
  business_types: Array<Exclude<RiderDeliveryBusiness, 'all'>>;
  business_label: string;
  delivery_type?: DeliveryType | string | null;
  delivery_label?: string | null;
  delivery_date?: string | null;
  delivery_window?: 'morning' | 'afternoon' | null;
  started_at?: string | null;
  offered_at?: string | null;
  response_deadline?: string | null;
  terminal_at?: string | null;
  matched_delivery_id?: number | null;
  deliveries: TrackingShipmentLeg[];
};

export type RiderDeliveryIssue = {
  item_type: 'issue';
  issue_type?: 'delivery_attempt' | 'proof_correction';
  key: string;
  id: number;
  delivery_id: number;
  delivery_number?: number | null;
  parent_key: string;
  business_types: Array<Exclude<RiderDeliveryBusiness, 'all'>>;
  delivery_type?: DeliveryType | string | null;
  delivery_label?: string | null;
  reason?: string | null;
  proof_id?: number | null;
  proof_number?: number | null;
  replaces_proof_id?: number | null;
  replaces_proof_number?: number | null;
  replacement_allowed?: boolean;
  attempted_at?: string | null;
  delivery_date?: string | null;
};

export type RiderDeliveryPageData = {
  offers: RiderDeliveryWorkItem[];
  current: RiderDeliveryWorkItem | null;
  active_conflicts: RiderDeliveryWorkItem[];
  has_active_conflict: boolean;
  up_next: RiderDeliveryWorkItem | null;
  list: PaginatedResponse<RiderDeliveryWorkItem | RiderDeliveryIssue>;
  filters: {
    tab: RiderDeliveryTab;
    business: RiderDeliveryBusiness;
    window: 'all' | 'today' | 'week';
    search: string;
  };
};

export type LiveTrackingRoute = {
  distance_m: number;
  duration_s: number;
  geometry: Array<[number, number]>;
  source?: 'road' | 'direct';
  route_version?: number;
  active_stop_id?: number | null;
  updated_at?: string | null;
  off_route_state?: 'on_route' | 'monitoring' | 'cooldown' | string;
};
export interface CustodyHandoff {
  source_reference?: string;
  shipment_reference?: string;
  stage?: string;
  details_url?: string;
  id: number;
  shipment_leg_id: number;
  delivery_reference: string;
  status: 'requested' | 'released';
  from_label: string;
  to_label: string;
  can_release: boolean;
  can_receive: boolean;
  can_cancel?: boolean;
}
