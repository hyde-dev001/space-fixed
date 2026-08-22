import { useEffect, useRef, useState } from "react";
import type { KeyboardEvent } from "react";
import type { ApprovalSelection } from "./approvalSelection";
import {
  approvalDefinitionFor,
  approvalRecordLabel,
  type ApprovalAction,
  type ApprovalPanelDefinition,
} from "./approvalPanelRegistry";
import ApprovalDecisionFooter from "./ApprovalDecisionFooter";
import { isRecord, type ApprovalDetail } from "./approvalDetails";
import type { OwnerAttentionItem } from "../../types/ownerActionCenter";

interface OwnerApprovalDetailPanelProps {
  item: OwnerAttentionItem;
  selection: ApprovalSelection;
  onClose: () => void;
  onDecisionComplete: () => void;
}

interface ApprovalError {
  status: number | null;
  message: string;
}

const terminalStatuses = new Set([
  "approved",
  "owner_approved",
  "rejected",
  "owner_rejected",
  "succeeded",
  "failed",
  "cancelled",
  "active",
  "paid",
  "applied",
  "finalized",
  "completed",
  "posted",
]);

const normalizedStatus = (value: unknown): string => (
  typeof value === "string" ? value.toLowerCase().replace(/[\s-]+/g, "_") : ""
);

const isTerminal = (detail: ApprovalDetail): boolean => {
  const status = detail.status ?? detail.approval_status ?? (isRecord(detail.approval) ? detail.approval.status : null);
  return terminalStatuses.has(normalizedStatus(status));
};

const unwrapDetail = (value: unknown): ApprovalDetail => {
  if (!isRecord(value)) return {};

  for (const key of ["data", "expense", "purchase_request", "payslip", "salary_change", "refund", "price_change", "repair_service", "package", "repair"]) {
    if (isRecord(value[key])) return { ...value, ...value[key] };
  }

  return value;
};

const responseMessage = (status: number): string => {
  if (status === 404) return "This approval record is no longer available in the current shop workflow.";
  if (status === 409 || status === 422) return "This approval changed before the decision was saved. Refresh to load the current workflow state.";
  return "The approval record could not be loaded. Refresh to try again.";
};

const mutationMessage = (status: number): string => {
  if (status === 409 || status === 422) return "This approval changed before the decision was saved. The selected record is still open; refresh to load its current state.";
  return "The decision could not be saved. The selected record is still open; refresh and try again.";
};

const csrfToken = (): string | null => (
  typeof document === "undefined"
    ? null
    : document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? null
);

export default function OwnerApprovalDetailPanel({
  item,
  selection,
  onClose,
  onDecisionComplete,
}: OwnerApprovalDetailPanelProps) {
  const definition = approvalDefinitionFor(selection.sourceType);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  const [detail, setDetail] = useState<ApprovalDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApprovalError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [announcement, setAnnouncement] = useState("");
  const [refreshToken, setRefreshToken] = useState(0);

  useEffect(() => {
    previousFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    closeButtonRef.current?.focus();

    return () => {
      previousFocusRef.current?.focus();
    };
  }, []);

  useEffect(() => {
    if (!definition) return;

    let cancelled = false;
    const load = async () => {
      setLoading(true);
      setError(null);
      setDetail(null);
      setAnnouncement("Loading approval details.");

      try {
        const response = await fetch(definition.detailPath(selection.sourceId), {
          credentials: "include",
          headers: { Accept: "application/json" },
        });

        if (!response.ok) {
          throw { status: response.status };
        }

        const payload: unknown = await response.json();
        if (!cancelled) {
          setDetail(unwrapDetail(payload));
          setAnnouncement("Approval details loaded.");
        }
      } catch (caught) {
        if (cancelled) return;
        const status = isRecord(caught) && typeof caught.status === "number" ? caught.status : null;
        setError({ status, message: status === null ? "The approval record could not be loaded. Refresh to try again." : responseMessage(status) });
        setAnnouncement("Approval details could not be loaded.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();
    return () => {
      cancelled = true;
    };
  }, [definition, selection.sourceId, selection.sourceType, refreshToken]);

  const handleKeyDown = (event: KeyboardEvent<HTMLElement>) => {
    if (event.key === "Escape") {
      event.preventDefault();
      onClose();
      return;
    }

    if (event.key !== "Tab") return;
    const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>(
      'button:not([disabled]), textarea:not([disabled]), a[href], input:not([disabled]), select:not([disabled])',
    ));
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  const submitDecision = async (action: ApprovalAction, reason?: string) => {
    if (!definition || submitting) return;
    const config = definition[action];
    if (!config) return;

    setSubmitting(true);
    setError(null);
    setAnnouncement(`${action === "approve" ? "Approval" : "Rejection"} is being saved.`);

    try {
      const token = csrfToken();
      const headers: Record<string, string> = {
        Accept: "application/json",
        "Content-Type": "application/json",
      };
      if (token) headers["X-CSRF-TOKEN"] = token;

      const response = await fetch(config.path(selection.sourceId), {
        method: "POST",
        credentials: "include",
        headers,
        body: JSON.stringify(config.body?.(reason) ?? {}),
      });

      if (!response.ok) throw { status: response.status };

      setAnnouncement(`${action === "approve" ? "Approval" : "Rejection"} saved.`);
      onDecisionComplete();
    } catch (caught) {
      const status = isRecord(caught) && typeof caught.status === "number" ? caught.status : null;
      setError({ status, message: status === null ? "The decision could not be saved. The selected record is still open; refresh and try again." : mutationMessage(status) });
      setAnnouncement("The decision could not be saved.");
    } finally {
      setSubmitting(false);
    }
  };

  if (!definition) {
    return (
      <section role="dialog" aria-modal="true" aria-label="Approval details" className="fixed inset-0 z-50 overflow-y-auto bg-white p-5 dark:bg-gray-950 lg:static">
        <button type="button" onClick={onClose} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold dark:border-gray-700">Close</button>
        <p role="alert" className="mt-4 text-sm">This record is not an owner approval decision.</p>
      </section>
    );
  }

  const canDecide = item.owner_action_required && item.primary_bucket === "needs_my_decision" && detail !== null && !isTerminal(detail);
  const Renderer = definition.renderer;

  return (
    <section
      role="dialog"
      aria-modal="true"
      aria-labelledby="owner-approval-detail-title"
      onKeyDown={handleKeyDown}
      className="fixed inset-0 z-50 flex max-h-screen flex-col overflow-y-auto bg-white p-5 shadow-2xl dark:bg-gray-950 lg:static lg:max-h-[calc(100vh-12rem)] lg:rounded-xl lg:border lg:border-gray-200 lg:p-6 lg:shadow-none lg:dark:border-gray-800"
    >
      <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-800">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-300">Owner approval</p>
          <h2 id="owner-approval-detail-title" className="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{definition.label}</h2>
          <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{item.title} · #{item.source_id}</p>
        </div>
        <button
          ref={closeButtonRef}
          type="button"
          aria-label="Close approval details"
          onClick={onClose}
          className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:text-gray-200"
        >
          Close
        </button>
      </header>

      <div aria-live="polite" className="sr-only">{announcement}</div>

      {error && (
        <div role="alert" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-100">
          <p>{error.message}</p>
          <button
            type="button"
            onClick={() => setRefreshToken((value) => value + 1)}
            className="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-amber-300 px-4 py-2 font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 dark:border-amber-800"
          >
            Refresh
          </button>
        </div>
      )}

      <div className="min-h-0 flex-1 py-4">
        {loading ? (
          <div role="status" className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">Loading approval details…</div>
        ) : detail ? (
          <Renderer detail={detail} item={item} />
        ) : null}
      </div>

      {!loading && detail && (
        <section className="shrink-0 border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)] pt-4 dark:border-gray-800 dark:bg-gray-950" aria-labelledby="owner-approval-decision-footer-title">
          <h3 id="owner-approval-decision-footer-title" className="text-sm font-semibold text-gray-900 dark:text-white">Decision footer</h3>
          <div className="mt-3">
            {canDecide ? (
              <ApprovalDecisionFooter
                definition={definition}
                item={item}
                recordLabel={approvalRecordLabel(item)}
                submitting={submitting}
                onSubmit={submitDecision}
              />
            ) : (
              <p className="text-sm text-gray-600 dark:text-gray-300">
                This record is no longer assigned to your decision queue. Refresh the Action Center to see its current status.
              </p>
            )}
          </div>
        </section>
      )}
    </section>
  );
}
