export type TrackingShipmentLeg = {
  id: number;
  sequence: number;
  leg_type: string;
  status: string;
  origin_snapshot?: Record<string, unknown> | null;
  destination_snapshot?: Record<string, unknown> | null;
  tracking_number?: string | null;
  tracking_url?: string | null;
  requires_delivery_proof?: boolean;
  scheduled_pickup_at?: string | null;
  assigned_at?: string | null;
  picked_up_at?: string | null;
  delivered_at?: string | null;
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
    reason_code?: string | null;
    notes?: string | null;
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
};
