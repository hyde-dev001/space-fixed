import { Head, router, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import OwnerActionCenterAvailability from "../../components/owner-action-center/OwnerActionCenterAvailability";
import OwnerAttentionList from "../../components/owner-action-center/OwnerAttentionList";
import OwnerApprovalDetailPanel from "../../components/owner-action-center/OwnerApprovalDetailPanel";
import OwnerApprovalFilters, { actionCenterUrl } from "../../components/owner-action-center/OwnerApprovalFilters";
import OwnerApprovalHistoryList from "../../components/owner-action-center/OwnerApprovalHistoryList";
import { approvalDefinitionFor } from "../../components/owner-action-center/approvalPanelRegistry";
import { parseApprovalSelection, type ApprovalSelection } from "../../components/owner-action-center/approvalSelection";
import type {
  OwnerActionCenterCoverage,
  OwnerAttentionItem,
  OwnerActionCenterResult,
  OwnerAttentionCoverageSource,
  OwnerApprovalCenterView,
  OwnerApprovalHistoryItem,
  OwnerApprovalHistoryResult,
} from "../../types/ownerActionCenter";

interface ActionCenterPageProps {
  ownerActionCenter?: OwnerActionCenterResult;
  approvalHistory?: OwnerApprovalHistoryResult | null;
  approvalCoverageSources?: OwnerAttentionCoverageSource[];
  approvalHistoryCoverageSources?: OwnerAttentionCoverageSource[];
  view?: OwnerApprovalCenterView;
  bucket?: "needs_my_decision";
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
  if (sourceType === "suspension_request") return "suspensions";
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
  const rawResult = props.ownerActionCenter ?? null;
  const result = rawResult?.bucket === "needs_my_decision" ? rawResult : null;
  const history = props.approvalHistory ?? null;
  const view = props.view ?? (typeof window !== "undefined" && new URLSearchParams(window.location.search).get("view") === "history" ? "history" : "pending");
  const bucket = "needs_my_decision" as const;
  const source = view === "history"
    ? history?.coverage ?? props.source ?? "all"
    : result?.coverage ?? props.source ?? "all";
  const page = view === "history"
    ? history?.pagination.page ?? props.page ?? 1
    : result?.pagination.page ?? props.page ?? 1;
  const perPage = view === "history"
    ? history?.pagination.per_page ?? props.per_page ?? 20
    : result?.pagination.per_page ?? props.per_page ?? 20;
  const lastPage = view === "history" ? history?.pagination.last_page ?? 1 : result?.pagination.last_page ?? 1;
  const approvalCount = view === "history" ? history?.pagination.total ?? 0 : result?.pagination.total ?? 0;
  const approvalSummary = view === "history"
    ? `${approvalCount} approval decision${approvalCount === 1 ? "" : "s"} recorded`
    : `${approvalCount} approval${approvalCount === 1 ? "" : "s"} ${approvalCount === 1 ? "requires" : "require"} your decision`;
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

  const selectedHistoryItem = selectedSelection
    ? history?.items.find((item) => item.source_type === selectedSelection.sourceType && item.source_id === selectedSelection.sourceId)
    : null;
  const selectedItem = selectedSelection
    ? (view === "history" ? null : result?.items.find((item) => item.source_type === selectedSelection.sourceType && item.source_id === selectedSelection.sourceId))
      ?? (selectedHistoryItem ? (() => {
        const definition = approvalDefinitionFor(selectedHistoryItem.source_type);
        if (!definition) return null;

        return {
          attention_key: selectedHistoryItem.attention_key,
          source_type: selectedHistoryItem.source_type,
          source_id: selectedHistoryItem.source_id,
          category: "approval_history",
          primary_bucket: "needs_my_decision",
          module: "approval_history",
          title: selectedHistoryItem.title,
          concise_summary: selectedHistoryItem.concise_summary,
          priority_tier: "normal",
          materiality_tier: selectedHistoryItem.comparable_monetary_exposure === null ? "none" : "medium",
          comparable_monetary_exposure: selectedHistoryItem.comparable_monetary_exposure,
          urgency_at: null,
          actionable_since: selectedHistoryItem.decision_at,
          waiting_on: "shop_owner",
          owner_action_required: false,
          coverage_source: selectedHistoryItem.coverage_source,
          destination_url: approvalUrl(selectedSelection),
        } satisfies OwnerAttentionItem;
      })() : null)
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
          concise_summary: "Selected approval record from the Approval Center link.",
          priority_tier: "normal",
          materiality_tier: "none",
          comparable_monetary_exposure: null,
          urgency_at: null,
          actionable_since: new Date().toISOString(),
          waiting_on: "shop_owner",
          owner_action_required: view !== "history",
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

  const selectHistoryApproval = (item: OwnerApprovalHistoryItem) => {
    const nextSelection: ApprovalSelection = { sourceType: item.source_type, sourceId: item.source_id };
    lastReviewedKey.current = item.attention_key;
    setSelectedSelection(nextSelection);
    window.history.pushState({}, "", approvalUrl(nextSelection));
  };

  const closeApproval = () => {
    setSelectedSelection(null);
    window.history.replaceState({}, "", clearApprovalUrl());
    setQueueAnnouncement("Approval details closed. The approval queue is still available.");
    window.requestAnimationFrame(() => {
      const button = Array.from(document.querySelectorAll<HTMLButtonElement>("button[data-attention-key]"))
        .find((candidate) => candidate.dataset.attentionKey === lastReviewedKey.current);
      button?.focus();
    });
  };

  const decisionComplete = () => {
    closeApproval();
    setQueueAnnouncement("Decision saved. The approval queue was refreshed.");
    router.reload({ preserveScroll: true, preserveState: true });
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Approval Center - Shop Owner" />
      <main className="space-y-6" aria-labelledby="approval-center-title">
        <header>
          <h1 id="approval-center-title" className="text-2xl font-bold text-gray-800 dark:text-white/90">
            Approval Center
          </h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {view === "history" ? "Review decisions you have already made." : "Approvals that currently require your decision."}
          </p>
          <p className="mt-4 inline-flex rounded-full bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-200">
            {approvalSummary}
          </p>
          <nav aria-label="Approval Center views" className="mt-5 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-800">
            <a
              href={actionCenterUrl(bucket, "all", 1, result?.pagination.per_page ?? 20)}
              aria-current={view === "pending" ? "page" : undefined}
              className={view === "pending"
                ? "inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white"
                : "inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200"}
            >
              Pending
            </a>
            <a
              href={actionCenterUrl(bucket, "all", 1, history?.pagination.per_page ?? 20, "history")}
              aria-current={view === "history" ? "page" : undefined}
              className={view === "history"
                ? "inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white"
                : "inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200"}
            >
              History
            </a>
          </nav>
        </header>

        <section className="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="approval-queue-title">
          <div className="flex flex-col gap-4 border-b border-gray-200 pb-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 id="approval-queue-title" className="text-lg font-semibold text-gray-800 dark:text-white/90">
                {view === "history" ? "Approval history" : "Approvals requiring your decision"}
              </h2>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {view === "history" ? "Approved and rejected decisions remain available for reference." : "Review and complete decisions assigned to you."}
              </p>
            </div>
            <button
              type="button"
              aria-label="Refresh Approval Center"
              onClick={() => router.reload({ preserveScroll: true, preserveState: true })}
              className="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-white/[0.06] dark:focus-visible:ring-offset-gray-900"
            >
              Refresh
            </button>
          </div>

          {view === "pending" && (
            <div className="mt-4">
              <OwnerActionCenterAvailability result={result} approvalOnly />
            </div>
          )}

          {hasInvalidApproval && (
            <p role="alert" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-100">
              This approval link is invalid. The Approval Center queue is still available below.
            </p>
          )}

          <OwnerApprovalFilters
            result={result}
            availableCoverageSources={view === "history"
              ? props.approvalHistoryCoverageSources ?? props.approvalCoverageSources
              : props.approvalCoverageSources}
            source={source}
            perPage={perPage}
            view={view}
          />

          <div className="mt-5">
            <div className="min-w-0">
              {view === "history" ? (
                <>
                  <OwnerApprovalHistoryList
                    items={history?.items ?? []}
                    onReview={selectHistoryApproval}
                    selectedAttentionKey={selectedItem?.attention_key ?? null}
                  />
                  {history !== null && history.items.length === 0 && (
                    <p className="mt-5 rounded-xl border border-dashed border-gray-300 p-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                      No approval history yet.
                    </p>
                  )}
                </>
              ) : (
                <>
                  <OwnerAttentionList
                    items={result?.items ?? []}
                    onReview={selectApproval}
                    selectedAttentionKey={selectedItem?.attention_key ?? null}
                    ariaLabel="Owner approval queue"
                  />

                  {result !== null && result.degradation_status !== "unavailable" && result.degradation_status !== "no_enabled_adapters" && result.items.length === 0 && (
                    <p className="mt-5 rounded-xl border border-dashed border-gray-300 p-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                      No approvals require your decision.
                    </p>
                  )}
                </>
              )}

              {((view === "history" && history !== null && lastPage > 1)
                || (view === "pending" && result !== null && result.degradation_status !== "unavailable" && result.degradation_status !== "no_enabled_adapters" && lastPage > 1)) && (
                <nav aria-label="Approval Center pagination" className="mt-6 flex flex-wrap items-center justify-center gap-2">
              {page > 1 ? (
                <a
                  href={actionCenterUrl(bucket, source, page - 1, perPage, view)}
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
                  href={actionCenterUrl(bucket, source, pageNumber, perPage, view)}
                  aria-label={`Page ${pageNumber}`}
                  className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200"
                >
                  {pageNumber}
                </a>
              ))}
              {page < lastPage ? (
                <a
                  href={actionCenterUrl(bucket, source, page + 1, perPage, view)}
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

          </div>
          {selectedItem && selectedSelection && (
            <OwnerApprovalDetailPanel
              item={selectedItem}
              selection={selectedSelection}
              onClose={closeApproval}
              onDecisionComplete={decisionComplete}
            />
          )}
          <div role="status" aria-live="polite" className="sr-only">{queueAnnouncement}</div>
        </section>
      </main>
    </AppLayoutShopOwner>
  );
}
