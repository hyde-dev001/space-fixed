export type DeliveryContactSnapshot = {
  type?: string;
  name?: string;
  phone?: string;
  address?: string;
  delivery_instructions?: string | null;
  latitude?: number | null;
  longitude?: number | null;
};

export type TrackingShipmentLeg = {
  id: number;
  delivery_batch_id?: number | null;
  sequence: number;
  leg_type: string;
  status: string;
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
  latest_failed_attempt?: {
    id: number;
    reason: string;
    attempted_at?: string | null;
    proof_url?: string | null;
  } | null;
  shipment?: {
    id: number;
    source_type: string;
    source_id: number;
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
    file_path?: string | null;
    review_status?: string;
  }>;
  attempts?: Array<{
    id: number;
    status?: string;
    reason_code?: string | null;
    notes?: string | null;
    file_path?: string | null;
    attempted_at?: string | null;
  }>;
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
  rider_profile?: LogisticsRider | null;
  legs: TrackingShipmentLeg[];
};

export type DeliveryBatchPageProps = {
  batches: DeliveryBatch[];
  pool: TrackingShipmentLeg[];
  unscheduled: TrackingShipmentLeg[];
  riders: LogisticsRider[];
  dailyRiderCapacity: number;
};
