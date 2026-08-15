import type { OwnerActionCenterResult, OwnerAttentionAdapterKey } from "../../types/ownerActionCenter";

interface OwnerActionCenterAvailabilityProps {
  result: OwnerActionCenterResult | null;
}

const adapterLabels: Record<OwnerAttentionAdapterKey, string> = {
  order_refunds: "Order refunds",
  repair_refunds: "Repair refunds",
  expenses: "Expenses",
  purchase_requests: "Purchase requests",
  compliance_documents: "Compliance documents",
  failed_order_refunds: "Order refund recovery",
  failed_repair_refunds: "Repair refund recovery",
  unowned_logistics_failures: "Logistics failures",
};

const failedSourceLabels = (keys: OwnerAttentionAdapterKey[]): string[] => {
  const labels = keys.map((key) => adapterLabels[key]);
  return Array.from(new Set(labels));
};

export default function OwnerActionCenterAvailability({ result }: OwnerActionCenterAvailabilityProps) {
  const bucketLabel = result?.bucket === "urgent_exceptions" ? "Urgent Exceptions" : "Needs My Decision";
  const itemLabel = result?.bucket === "urgent_exceptions" ? "exceptions" : "actions";

  if (result === null || result.degradation_status === "unavailable") {
    return (
      <div role="status" aria-live="polite" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-100">
        <p className="font-semibold">{bucketLabel} currently unavailable</p>
        <p className="mt-1">Counts are not available while the supported sources recover.</p>
      </div>
    );
  }

  if (result.degradation_status === "no_enabled_adapters") {
    return (
      <div role="status" aria-live="polite" className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
        <p className="font-semibold text-gray-700 dark:text-white/80">{bucketLabel} sources are not enabled</p>
        <p className="mt-1">The existing module and approval pages remain the current action surfaces.</p>
      </div>
    );
  }

  const total = result.pagination.total;
  const partial = result.degradation_status === "partial";
  const failedSources = failedSourceLabels(result.health.failed_adapter_keys);

  return (
    <div role="status" aria-live="polite" className="space-y-1 text-sm text-gray-600 dark:text-gray-300">
      <p>
        <span className="font-semibold text-gray-800 dark:text-white/90">{bucketLabel}</span>{" "}
        <span className="font-semibold">{total}</span>
      </p>
      {partial && (
        <>
          <p>{total} {itemLabel} from currently available sources (partial coverage).</p>
          {failedSources.map((source) => (
            <p key={source} className="text-amber-700 dark:text-amber-300">
              {source} temporarily unavailable
            </p>
          ))}
        </>
      )}
      {!partial && total === 0 && (
        <p>{result.bucket === "urgent_exceptions"
          ? "No urgent exceptions from currently supported sources."
          : "No decisions from currently supported sources require action."}</p>
      )}
    </div>
  );
}
