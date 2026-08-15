export type OwnerActionCenterCoverage =
  | "all"
  | "refunds"
  | "expenses"
  | "purchase_requests";

export type OwnerAttentionCoverageSource =
  | "refunds"
  | "expenses"
  | "purchase_requests";

export type OwnerAttentionAdapterKey =
  | "order_refunds"
  | "repair_refunds"
  | "expenses"
  | "purchase_requests";

export type OwnerAttentionSourceType =
  | "order_refund"
  | "repair_refund"
  | "expense"
  | "purchase_request";

export type OwnerActionCenterDegradationStatus =
  | "not_selected"
  | "none"
  | "no_enabled_adapters"
  | "partial"
  | "unavailable";

export interface OwnerAttentionItem {
  attention_key: string;
  source_type: OwnerAttentionSourceType;
  source_id: number;
  category: string;
  primary_bucket: "needs_my_decision";
  module: string;
  title: string;
  concise_summary: string;
  priority_tier: "urgent" | "high" | "normal" | "low";
  materiality_tier: "high" | "medium" | "low" | "none";
  comparable_monetary_exposure: number | null;
  urgency_at: string | null;
  actionable_since: string;
  waiting_on: "shop_owner";
  owner_action_required: true;
  destination_url: string;
}

export interface OwnerAttentionAdapterHealth {
  enabled_adapter_keys: OwnerAttentionAdapterKey[];
  healthy_adapter_keys: OwnerAttentionAdapterKey[];
  failed_adapter_keys: OwnerAttentionAdapterKey[];
}

export interface OwnerActionCenterPagination {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface OwnerActionCenterResult {
  items: OwnerAttentionItem[];
  coverage_counts: Partial<Record<OwnerAttentionCoverageSource, number>>;
  health: OwnerAttentionAdapterHealth;
  degradation_status: OwnerActionCenterDegradationStatus;
  coverage: OwnerActionCenterCoverage;
  pagination: OwnerActionCenterPagination;
}
