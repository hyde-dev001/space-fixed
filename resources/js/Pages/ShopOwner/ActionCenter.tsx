import { Head, router, usePage } from "@inertiajs/react";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import OwnerActionCenterAvailability from "../../components/owner-action-center/OwnerActionCenterAvailability";
import OwnerAttentionList from "../../components/owner-action-center/OwnerAttentionList";
import type {
  OwnerActionCenterCoverage,
  OwnerActionCenterResult,
} from "../../types/ownerActionCenter";

interface ActionCenterPageProps {
  ownerActionCenter?: OwnerActionCenterResult;
  source?: OwnerActionCenterCoverage;
  page?: number;
  per_page?: number;
}

const filterLabels: Array<{ key: OwnerActionCenterCoverage; label: string }> = [
  { key: "all", label: "All" },
  { key: "refunds", label: "Refunds" },
  { key: "expenses", label: "Expenses" },
  { key: "purchase_requests", label: "Purchase Requests" },
];

const hasRefundCoverage = (result: OwnerActionCenterResult): boolean =>
  result.health.enabled_adapter_keys.includes("order_refunds")
  || result.health.enabled_adapter_keys.includes("repair_refunds");

const hasCoverage = (result: OwnerActionCenterResult, coverage: OwnerActionCenterCoverage): boolean => {
  if (coverage === "all") {
    return result.health.enabled_adapter_keys.length > 0;
  }

  if (coverage === "refunds") {
    return hasRefundCoverage(result);
  }

  return result.health.enabled_adapter_keys.includes(coverage);
};

const actionCenterUrl = (source: OwnerActionCenterCoverage, page: number, perPage: number): string => {
  const params = new URLSearchParams({
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
  const source = result?.coverage ?? props.source ?? "all";
  const page = result?.pagination.page ?? props.page ?? 1;
  const perPage = result?.pagination.per_page ?? props.per_page ?? 20;
  const lastPage = result?.pagination.last_page ?? 1;
  const filters = result === null
    ? []
    : filterLabels.filter(({ key }) => hasCoverage(result, key));

  return (
    <AppLayoutShopOwner>
      <Head title="Action Center - Shop Owner" />
      <main className="space-y-6" aria-labelledby="owner-action-center-title">
        <header>
          <h1 id="owner-action-center-title" className="text-2xl font-bold text-gray-800 dark:text-white/90">
            Owner Action Center
          </h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Supported decisions that currently need your review. Existing workflows remain the execution surfaces.
          </p>
        </header>

        <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="needs-my-decision-title">
          <div className="flex flex-col gap-4 border-b border-gray-200 pb-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 id="needs-my-decision-title" className="text-lg font-semibold text-gray-800 dark:text-white/90">
                Needs My Decision
              </h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">One bounded bucket for current Shop Owner decisions.</p>
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
            <nav aria-label="Action Center filters" className="mt-5 flex flex-wrap gap-2">
              {filters.map((filter) => {
                const active = source === filter.key;

                return (
                  <a
                    key={filter.key}
                    href={actionCenterUrl(filter.key, 1, perPage)}
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
              No decisions are listed on this page.
            </p>
          )}

          {result !== null && result.degradation_status !== "unavailable" && result.degradation_status !== "no_enabled_adapters" && lastPage > 1 && (
            <nav aria-label="Action Center pagination" className="mt-6 flex flex-wrap items-center justify-center gap-2">
              {page > 1 ? (
                <a
                  href={actionCenterUrl(source, page - 1, perPage)}
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
                  href={actionCenterUrl(source, pageNumber, perPage)}
                  aria-label={`Page ${pageNumber}`}
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200"
                >
                  {pageNumber}
                </a>
              ))}
              {page < lastPage ? (
                <a
                  href={actionCenterUrl(source, page + 1, perPage)}
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
