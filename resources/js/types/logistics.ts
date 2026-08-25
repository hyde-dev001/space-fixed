export type LogisticsModule = 'retail' | 'repair';

export type LogisticsSchedule = {
  operating_days: number[];
  blackout_dates: string[];
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
  items: LogisticsOrderItemSummary[];
  total_quantity: number;
  variant_count: number;
  model_count: number;
};

export const logisticsSourceLabel = (shipment?: {
  source_type: string;
  source_id: number;
  source_summary?: LogisticsSourceSummary | null;
} | null) => {
  if (!shipment) return 'Delivery';
  if (shipment.source_type === 'repair_request') {
    return `Repair ${shipment.source_summary?.request_number || `#${shipment.source_id}`}`;
  }
  if (shipment.source_type === 'order_refund') return `Return #${shipment.source_id}`;
  if (shipment.source_type === 'order') return `Order #${shipment.source_id}`;
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
  result: 'verified' | 'outside_geofence' | 'low_accuracy' | 'location_unavailable';
  distance_m?: number | null;
  radius_m: number;
  accuracy_m?: number | null;
  exception_reason?: string | null;
  exception_notes?: string | null;
  recorded_at: string;
};

export type CustomerDeliveryProof = {
  id: number;
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
  id: number;
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
    handoff_type: string;
    proof_type: string;
    proof_url?: string | null;
    review_status?: string;
    rejection_reason?: string | null;
    replaces_proof_id?: number | null;
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
};

export type LogisticsIncident = {
  id: number;
  type: 'damaged' | 'lost' | 'vehicle_problem' | 'customer_dispute' | 'other' | string;
  status: 'reported' | 'under_review' | 'resolved' | string;
  notes?: string | null;
  resolution?: string | null;
  resolved_at?: string | null;
  reporting_rider_profile_id?: number | null;
  evidence_urls?: string[];
};

export type TrackingShipmentEvent = {
  id: number;
  shipment_leg_id?: number | null;
  event_type: string;
  message?: string | null;
  created_at?: string | null;
};

export type TrackingShipment = {
  id: number;
  purpose: string;
  status: string;
  source_type: string;
  source_summary?: LogisticsSourceSummary | null;
  order_summary?: LogisticsOrderSummary | null;
  created_at?: string | null;
  legs: TrackingShipmentLeg[];
  events: TrackingShipmentEvent[];
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

export type LogisticsRider = {
  id: number;
  rider_type: string;
  name: string;
  phone?: string | null;
  availability_status: string;
  active: boolean;
  daily_capacity?: number | null;
};

export type DeliveryBatchStatus = 'draft' | 'offered' | 'accepted' | 'in_progress' | 'completed' | 'cancelled';

export type DeliveryBatch = {
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
  item_type: 'work';
  key: string;
  kind: 'batch' | 'single';
  id: number;
  status: string;
  group: 'offer' | 'current' | 'upcoming' | 'history' | 'conflict';
  business_types: Array<Exclude<RiderDeliveryBusiness, 'all'>>;
  business_label: string;
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
  parent_key: string;
  business_types: Array<Exclude<RiderDeliveryBusiness, 'all'>>;
  reason?: string | null;
  proof_id?: number | null;
  replaces_proof_id?: number | null;
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
