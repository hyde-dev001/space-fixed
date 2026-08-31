export type OwnerActionCenterCoverage =
  | "all"
  | "refunds"
  | "prices"
  | "payslips"
  | "salary_changes"
  | "expenses"
  | "purchase_requests"
  | "suspensions"
  | "terminations"
  | "rehires"
  | "repair_rejections"
  | "compliance"
  | "logistics";

export type OwnerAttentionBucket =
  | "needs_my_decision"
  | "urgent_exceptions"
  | "waiting_on_others";

export type OwnerAttentionCoverageSource =
  | "refunds"
  | "prices"
  | "payslips"
  | "salary_changes"
  | "expenses"
  | "purchase_requests"
  | "suspensions"
  | "terminations"
  | "rehires"
  | "repair_rejections"
  | "compliance"
  | "logistics";

export type OwnerAttentionAdapterKey =
  | "order_refunds"
  | "repair_refunds"
  | "price_approvals"
  | "payslips"
  | "salary_changes"
  | "expenses"
  | "purchase_requests"
  | "suspension_requests"
  | "termination_requests"
  | "rehire_requests"
  | "repair_rejections"
  | "compliance_documents"
  | "failed_order_refunds"
  | "failed_repair_refunds"
  | "unowned_logistics_failures"
  | "pending_compliance_renewals"
  | "waiting_order_refund_recovery"
  | "waiting_repair_refund_recovery"
  | "active_logistics_recovery";

export type OwnerAttentionSourceType =
  | "order_refund"
  | "repair_refund"
  | "product_price_change"
  | "repair_price_change"
  | "repair_package_price_change"
  | "payslip"
  | "salary_change"
  | "expense"
  | "purchase_request"
  | "suspension_request"
  | "termination_request"
  | "rehire_request"
  | "repair_rejection"
  | "compliance_document"
  | "logistics_failure";

export type OwnerActionCenterDegradationStatus =
  | "not_selected"
  | "none"
  | "no_enabled_adapters"
  | "partial"
  | "unavailable";

export type OwnerApprovalCenterView = "pending" | "history";

export type OwnerApprovalHistoryStatus = "approved" | "rejected";

export interface OwnerAttentionItem {
  attention_key: string;
  source_type: OwnerAttentionSourceType;
  source_id: number;
  category: string;
  primary_bucket: "needs_my_decision" | "urgent_exceptions" | "waiting_on_others";
  module: string;
  title: string;
  concise_summary: string;
  priority_tier: "critical" | "high" | "normal" | "low";
  materiality_tier: "critical" | "high" | "medium" | "low" | "none";
  comparable_monetary_exposure: number | null;
  urgency_at: string | null;
  actionable_since: string;
  waiting_on: "shop_owner" | "none" | "super_admin" | "finance" | "payment_recovery" | "rider" | "dispatcher";
  owner_action_required: boolean;
  coverage_source: OwnerAttentionCoverageSource;
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
  bucket: OwnerAttentionBucket;
  coverage: OwnerActionCenterCoverage;
  pagination: OwnerActionCenterPagination;
}

export interface OwnerApprovalHistoryItem {
  attention_key: string;
  source_type: OwnerAttentionSourceType;
  source_id: number;
  title: string;
  concise_summary: string;
  coverage_source: OwnerAttentionCoverageSource;
  status: OwnerApprovalHistoryStatus;
  decision_at: string;
  requested_at: string | null;
  comparable_monetary_exposure: number | null;
  comments: string | null;
  reviewed_by: string | null;
  destination_url: string;
}

export interface OwnerApprovalHistoryResult {
  items: OwnerApprovalHistoryItem[];
  coverage_counts: Partial<Record<OwnerAttentionCoverageSource, number>>;
  coverage: OwnerActionCenterCoverage;
  pagination: OwnerActionCenterPagination;
}
