import { Head, router, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import OwnerActionCenterAvailability from "../../components/owner-action-center/OwnerActionCenterAvailability";
import OwnerAttentionList from "../../components/owner-action-center/OwnerAttentionList";
import OwnerApprovalDetailPanel from "../../components/owner-action-center/OwnerApprovalDetailPanel";
import OwnerApprovalFilters, { actionCenterUrl } from "../../components/owner-action-center/OwnerApprovalFilters";
import { approvalDefinitionFor } from "../../components/owner-action-center/approvalPanelRegistry";
import { parseApprovalSelection, type ApprovalSelection } from "../../components/owner-action-center/approvalSelection";
import type {
  OwnerActionCenterCoverage,
  OwnerAttentionItem,
  OwnerActionCenterResult,
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
  approvalSelection?: ApprovalSelection | null;
  approvalSelectionError?: "invalid" | null;
}

const approvalCoverage = (sourceType: OwnerAttentionItem["source_type"]): OwnerAttentionCoverageSource => {
  if (sourceType === "product_price_change" || sourceType === "repair_price_change" || sourceType === "repair_package_price_change") return "prices";
  if (sourceType === "payslip") return "payslips";
  if (sourceType === "salary_change") return "salary_changes";
  if (sourceType === "purchase_request") return "purchase_requests";
  if (sourceType === "expense") return "expenses";
  if (sourceType === "repair_rejection") return "repair_rejections";
  return "refunds";
};

const approvalUrl = (selection: ApprovalSelection): string => {
  if (typeof window === "undefined") {
    return `/shop-owner/action-center?approval=${selection.sourceType}:${selection.sourceId}`;
  }

  const params = new URLSearchParams(window.location.search);
  params.set("approval", `${selection.sourceType}:${selection.sourceId}`);
  return `${window.location.pathname}?${params.toString()}`;
};

const clearApprovalUrl = (): string => {
  const params = new URLSearchParams(window.location.search);
  params.delete("approval");
  const query = params.toString();
  return `${window.location.pathname}${query ? `?${query}` : ""}`;
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
  const summaries = props.bucketSummaries ?? {};
  const rawApproval = typeof window === "undefined"
    ? null
    : new URLSearchParams(window.location.search).get("approval");
  const hasInvalidApproval = props.approvalSelectionError === "invalid"
    || (rawApproval !== null && parseApprovalSelection(rawApproval) === null);
  const [selectedSelection, setSelectedSelection] = useState<ApprovalSelection | null>(props.approvalSelection ?? null);
  const lastReviewedKey = useRef<string | null>(null);
  const [queueAnnouncement, setQueueAnnouncement] = useState("");

  useEffect(() => {
    setSelectedSelection(props.approvalSelection ?? null);
  }, [props.approvalSelection]);

  const selectedItem = selectedSelection
    ? result?.items.find((item) => item.source_type === selectedSelection.sourceType && item.source_id === selectedSelection.sourceId)
      ?? (() => {
        const definition = approvalDefinitionFor(selectedSelection.sourceType);
        if (!definition) return null;

        return {
          attention_key: `${selectedSelection.sourceType}:${selectedSelection.sourceId}:owner_approval`,
          source_type: selectedSelection.sourceType,
          source_id: selectedSelection.sourceId,
          category: "owner_approval",
          primary_bucket: "needs_my_decision",
          module: "owner_action_center",
          title: definition.label,
          concise_summary: "Selected approval record from the Action Center link.",
          priority_tier: "normal",
          materiality_tier: "none",
          comparable_monetary_exposure: null,
          urgency_at: null,
          actionable_since: new Date().toISOString(),
          waiting_on: "shop_owner",
          owner_action_required: true,
          coverage_source: approvalCoverage(selectedSelection.sourceType),
          destination_url: approvalUrl(selectedSelection),
        } satisfies OwnerAttentionItem;
      })()
    : null;

  const selectApproval = (item: OwnerAttentionItem) => {
    const nextSelection: ApprovalSelection = { sourceType: item.source_type, sourceId: item.source_id };
    lastReviewedKey.current = item.attention_key;
    setSelectedSelection(nextSelection);
    window.history.pushState({}, "", approvalUrl(nextSelection));
  };

  const closeApproval = () => {
    setSelectedSelection(null);
    window.history.replaceState({}, "", clearApprovalUrl());
    setQueueAnnouncement("Approval details closed. The Action Center queue is still available.");
    window.requestAnimationFrame(() => {
      const button = Array.from(document.querySelectorAll<HTMLButtonElement>("button[data-attention-key]"))
        .find((candidate) => candidate.dataset.attentionKey === lastReviewedKey.current);
      button?.focus();
    });
  };

  const decisionComplete = () => {
    closeApproval();
    setQueueAnnouncement("Decision saved. The Action Center queue was refreshed.");
    router.reload({ preserveScroll: true, preserveState: true });
  };
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

          {hasInvalidApproval && (
            <p role="alert" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-100">
              This approval link is invalid. The Action Center queue is still available below.
            </p>
          )}

          <OwnerApprovalFilters result={result} source={source} perPage={perPage} />

          <div className={selectedItem ? "mt-5 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(26rem,38rem)]" : "mt-5"}>
            <div className="min-w-0">
              <OwnerAttentionList
                items={result?.items ?? []}
                onReview={selectApproval}
                selectedAttentionKey={selectedItem?.attention_key ?? null}
              />

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
            </div>

            {selectedItem && selectedSelection && (
              <OwnerApprovalDetailPanel
                item={selectedItem}
                selection={selectedSelection}
                onClose={closeApproval}
                onDecisionComplete={decisionComplete}
              />
            )}
          </div>
          <div role="status" aria-live="polite" className="sr-only">{queueAnnouncement}</div>
        </section>
      </main>
    </AppLayoutShopOwner>
  );
}
