import type {
  OwnerActionCenterCoverage,
  OwnerActionCenterResult,
  OwnerAttentionAdapterKey,
  OwnerAttentionBucket,
  OwnerAttentionCoverageSource,
} from "../../types/ownerActionCenter";

export const filterLabels: Array<{ key: OwnerAttentionCoverageSource; label: string }> = [
  { key: "refunds", label: "Refunds" },
  { key: "prices", label: "Prices" },
  { key: "payslips", label: "Payslips" },
  { key: "salary_changes", label: "Salary Adjustments" },
  { key: "purchase_requests", label: "Purchase Requests" },
  { key: "expenses", label: "Expenses" },
  { key: "repair_rejections", label: "Repair Rejections" },
  { key: "compliance", label: "Compliance" },
  { key: "logistics", label: "Logistics" },
];

export const adapterCoverage = (key: OwnerAttentionAdapterKey): OwnerAttentionCoverageSource | null => {
  if (["order_refunds", "repair_refunds", "failed_order_refunds", "failed_repair_refunds", "waiting_order_refund_recovery", "waiting_repair_refund_recovery"].includes(key)) {
    return "refunds";
  }
  if (key === "price_approvals") return "prices";
  if (key === "payslips") return "payslips";
  if (key === "salary_changes") return "salary_changes";
  if (key === "expenses") return "expenses";
  if (key === "purchase_requests") return "purchase_requests";
  if (key === "repair_rejections") return "repair_rejections";
  if (["compliance_documents", "pending_compliance_renewals"].includes(key)) return "compliance";
  if (["unowned_logistics_failures", "active_logistics_recovery"].includes(key)) return "logistics";
  return null;
};

export const bucketCoverages: Record<OwnerAttentionBucket, OwnerAttentionCoverageSource[]> = {
  needs_my_decision: ["refunds", "prices", "payslips", "salary_changes", "purchase_requests", "expenses", "repair_rejections"],
  urgent_exceptions: ["compliance", "refunds", "logistics"],
  waiting_on_others: ["compliance", "refunds", "logistics"],
};

export const actionCenterUrl = (
  bucket: OwnerAttentionBucket,
  source: OwnerActionCenterCoverage,
  page: number,
  perPage: number,
): string => {
  const params = new URLSearchParams({
    bucket,
    source,
    page: String(page),
    per_page: String(perPage),
  });

  return `/shop-owner/action-center?${params.toString()}`;
};

export const availableFilters = (result: OwnerActionCenterResult): Array<{ key: OwnerActionCenterCoverage; label: string }> => {
  const allowedCoverages = bucketCoverages[result.bucket];
  const coverages = Array.from(new Set(result.health.enabled_adapter_keys
    .map(adapterCoverage)
    .filter((coverage): coverage is OwnerAttentionCoverageSource => coverage !== null)
    .filter((coverage) => allowedCoverages.includes(coverage))));

  if (coverages.length <= 1) return [];

  return [
    { key: "all", label: "All" },
    ...filterLabels.filter(({ key }) => coverages.includes(key)),
  ];
};

interface OwnerApprovalFiltersProps {
  result: OwnerActionCenterResult | null;
  source: OwnerActionCenterCoverage;
  perPage: number;
}

export default function OwnerApprovalFilters({ result, source, perPage }: OwnerApprovalFiltersProps) {
  if (result === null) return null;

  const filters = availableFilters(result);
  if (filters.length === 0) return null;

  return (
    <nav aria-label="Action Center source filters" className="mt-5 flex flex-wrap gap-2">
      {filters.map((filter) => {
        const active = source === filter.key;

        return (
          <a
            key={filter.key}
            href={actionCenterUrl(result.bucket, filter.key, 1, perPage)}
            aria-current={active ? "page" : undefined}
            className={active
              ? "inline-flex min-h-11 items-center rounded-full bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
              : "inline-flex min-h-11 items-center rounded-full border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/[0.06]"}
          >
            {filter.label}
          </a>
        );
      })}
    </nav>
  );
}

