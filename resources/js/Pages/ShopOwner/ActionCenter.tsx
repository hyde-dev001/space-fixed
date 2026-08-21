import { Head, router, usePage } from "@inertiajs/react";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import OwnerActionCenterAvailability from "../../components/owner-action-center/OwnerActionCenterAvailability";
import OwnerAttentionList from "../../components/owner-action-center/OwnerAttentionList";
import type {
  OwnerActionCenterCoverage,
  OwnerActionCenterResult,
  OwnerAttentionAdapterKey,
  OwnerAttentionBucket,
  OwnerAttentionCoverageSource,
} from "../../types/ownerActionCenter";

interface ActionCenterPageProps {
  ownerActionCenter?: OwnerActionCenterResult;
  bucketSummaries?: Partial<Record<OwnerAttentionBucket, OwnerActionCenterResult>>;
  bucket?: OwnerAttentionBucket;
  source?: OwnerActionCenterCoverage;
  page?: number;
  per_page?: number;
}

const filterLabels: Array<{ key: OwnerAttentionCoverageSource; label: string }> = [
  { key: "refunds", label: "Refunds" },
  { key: "expenses", label: "Expenses" },
  { key: "purchase_requests", label: "Purchase Requests" },
  { key: "compliance", label: "Compliance" },
  { key: "logistics", label: "Logistics" },
];

const adapterCoverage = (key: OwnerAttentionAdapterKey): OwnerAttentionCoverageSource | null => {
  if (["order_refunds", "repair_refunds", "failed_order_refunds", "failed_repair_refunds"].includes(key)) return "refunds";
  if (["waiting_order_refund_recovery", "waiting_repair_refund_recovery"].includes(key)) return "refunds";
  if (key === "expenses") return "expenses";
  if (key === "purchase_requests") return "purchase_requests";
  if (["compliance_documents", "pending_compliance_renewals"].includes(key)) return "compliance";
  if (["unowned_logistics_failures", "active_logistics_recovery"].includes(key)) return "logistics";
  return null;
};

const bucketCoverages: Record<OwnerAttentionBucket, OwnerAttentionCoverageSource[]> = {
  needs_my_decision: ["refunds", "expenses", "purchase_requests"],
  urgent_exceptions: ["compliance", "refunds", "logistics"],
  waiting_on_others: ["compliance", "refunds", "logistics"],
};

const availableFilters = (result: OwnerActionCenterResult): Array<{ key: OwnerActionCenterCoverage; label: string }> => {
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

const actionCenterUrl = (
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

const pageNumbers = (current: number, last: number): number[] => {
  const first = Math.max(1, Math.min(current - 2, last - 4));
  const end = Math.min(last, first + 4);

  return Array.from({ length: end - first + 1 }, (_, index) => first + index);
};

export default function ActionCenter() {
  const { props } = usePage() as { props: ActionCenterPageProps };
  const result = props.ownerActionCenter ?? null;
  const bucket = result?.bucket ?? props.bucket ?? "needs_my_decision";
  const source = result?.coverage ?? props.source ?? "all";
  const page = result?.pagination.page ?? props.page ?? 1;
  const perPage = result?.pagination.per_page ?? props.per_page ?? 20;
  const lastPage = result?.pagination.last_page ?? 1;
  const filters = result === null ? [] : availableFilters(result);
  const summaries = props.bucketSummaries ?? {};
  const buckets: Array<{ key: OwnerAttentionBucket; label: string }> = [
    { key: "needs_my_decision", label: "Needs My Decision" },
    ...(summaries.urgent_exceptions
      ? [{ key: "urgent_exceptions" as const, label: "Urgent Exceptions" }]
      : []),
    ...(summaries.waiting_on_others || bucket === "waiting_on_others"
      ? [{ key: "waiting_on_others" as const, label: "Waiting on Others" }]
      : []),
  ];
  const selectedLabel = bucket === "urgent_exceptions"
    ? "Urgent Exceptions"
    : bucket === "waiting_on_others"
      ? "Waiting on Others"
      : "Needs My Decision";

  return (
    <AppLayoutShopOwner>
      <Head title="Action Center - Shop Owner" />
      <main className="space-y-6" aria-labelledby="owner-action-center-title">
        <header>
          <h1 id="owner-action-center-title" className="text-2xl font-bold text-gray-800 dark:text-white/90">
            Owner Action Center
          </h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Review current owner decisions and material exceptions, then continue in the authoritative workflow.
          </p>
        </header>

        <nav aria-label="Action Center buckets" className="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800">
          {buckets.map(({ key, label }) => {
            const active = bucket === key;
            const summary = summaries[key] ?? (active ? result : null);
            const count = summary?.pagination.total ?? 0;

            return (
              <a
                key={key}
                href={actionCenterUrl(key, "all", 1, perPage)}
                aria-current={active ? "page" : undefined}
                className={active
                  ? "inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 border-blue-600 px-3 text-sm font-semibold text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-blue-300"
                  : "inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 border-transparent px-3 text-sm font-semibold text-gray-500 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:text-white"}
              >
                <span>{label}</span>
                <span className={active
                  ? "rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900/40 dark:text-blue-200"
                  : "rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"}
                >
                  {count}
                </span>
              </a>
            );
          })}
        </nav>

        <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="selected-attention-bucket-title">
          <div className="flex flex-col gap-4 border-b border-gray-200 pb-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 id="selected-attention-bucket-title" className="text-lg font-semibold text-gray-800 dark:text-white/90">
                {selectedLabel}
              </h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {bucket === "urgent_exceptions"
                  ? "Material conditions that need your awareness and currently have no assigned next owner."
                  : bucket === "waiting_on_others"
                    ? "Material work where another legitimate party owns the next step."
                    : "Decisions that currently require your approval or review."}
              </p>
            </div>
            <button
              type="button"
              aria-label="Refresh Action Center"
              onClick={() => router.reload({ preserveScroll: true, preserveState: true })}
              className="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/[0.06] dark:focus-visible:ring-offset-gray-900"
            >
              Refresh
            </button>
          </div>

          <div className="mt-4">
            <OwnerActionCenterAvailability result={result} />
          </div>

          {filters.length > 0 && (
            <nav aria-label="Action Center source filters" className="mt-5 flex flex-wrap gap-2">
              {filters.map((filter) => {
                const active = source === filter.key;

                return (
                  <a
                    key={filter.key}
                    href={actionCenterUrl(bucket, filter.key, 1, perPage)}
                    aria-current={active ? "page" : undefined}
                    className={active
                      ? "rounded-full bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                      : "rounded-full border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/[0.06]"}
                  >
                    {filter.label}
                  </a>
                );
              })}
            </nav>
          )}

          <div className="mt-5">
            <OwnerAttentionList items={result?.items ?? []} />
          </div>

          {result !== null && result.degradation_status !== "unavailable" && result.degradation_status !== "no_enabled_adapters" && result.items.length === 0 && (
            <p className="mt-5 rounded-xl border border-dashed border-gray-300 p-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
              {bucket === "urgent_exceptions"
                ? "No urgent exceptions are listed on this page."
                : bucket === "waiting_on_others"
                  ? "No waiting items are listed on this page."
                  : "No decisions are listed on this page."}
            </p>
          )}

          {result !== null && result.degradation_status !== "unavailable" && result.degradation_status !== "no_enabled_adapters" && lastPage > 1 && (
            <nav aria-label="Action Center pagination" className="mt-6 flex flex-wrap items-center justify-center gap-2">
              {page > 1 ? (
                <a
                  href={actionCenterUrl(bucket, source, page - 1, perPage)}
                  aria-label="Previous page"
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200"
                >
                  Previous
                </a>
              ) : (
                <button type="button" disabled aria-label="Previous page" className="cursor-not-allowed rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-400 dark:border-gray-800">
                  Previous
                </button>
              )}
              {pageNumbers(page, lastPage).map((pageNumber) => pageNumber === page ? (
                <span key={pageNumber} aria-current="page" className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white">
                  Page {pageNumber}
                </span>
              ) : (
                <a
                  key={pageNumber}
                  href={actionCenterUrl(bucket, source, pageNumber, perPage)}
                  aria-label={`Page ${pageNumber}`}
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200"
                >
                  {pageNumber}
                </a>
              ))}
              {page < lastPage ? (
                <a
                  href={actionCenterUrl(bucket, source, page + 1, perPage)}
                  aria-label="Next page"
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200"
                >
                  Next
                </a>
              ) : (
                <button type="button" disabled aria-label="Next page" className="cursor-not-allowed rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-400 dark:border-gray-800">
                  Next
                </button>
              )}
            </nav>
          )}
        </section>
      </main>
    </AppLayoutShopOwner>
  );
}
