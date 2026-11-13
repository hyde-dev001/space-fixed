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
import { Modal } from "../ui/modal";
import { useMaintenance } from "../../providers/MaintenanceProvider";
import { workflowFeedback } from "../../utils/workflowFeedback";

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

const isTerminal = (detail: ApprovalDetail, sourceType: string): boolean => {
  if (sourceType === "order_refund" && detail.canRequestCorrection === true) return false;
  if (sourceType === "repair_refund" || sourceType === "order_refund") {
    if (isRecord(detail.owner_projection) && detail.owner_projection.owner_action_required === false) {
      return true;
    }

    const status = normalizedStatus(detail.rawStatus ?? detail.status ?? detail.approval_status
      ?? (isRecord(detail.approval) ? detail.approval.status : null));
    return terminalStatuses.has(status) || status === "refunded" || status === "processing";
  }

  const status = sourceType === "repair_package_price_change"
    ? detail.approval_status ?? detail.status ?? (isRecord(detail.approval) ? detail.approval.status : null)
    : detail.status ?? detail.approval_status ?? (isRecord(detail.approval) ? detail.approval.status : null);
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

const validationMessage = (payload: unknown): string => {
  if (isRecord(payload)) {
    if (typeof payload.message === "string" && payload.message.trim() !== "") {
      return payload.message;
    }

    if (isRecord(payload.errors)) {
      for (const value of Object.values(payload.errors)) {
        if (!Array.isArray(value)) continue;
        const message = value.find((entry) => typeof entry === "string" && entry.trim() !== "");
        if (typeof message === "string") return message;
      }
    }
  }

  return "The decision could not be saved. Check the submitted values and try again.";
};

const mutationMessage = (status: number, payload: unknown): string => {
  if (status === 409) {
    const code = isRecord(payload) && typeof payload.code === "string" ? payload.code : "";
    if (code === "" || code.endsWith("_REQUEST_ALREADY_DECIDED")) {
      return "This approval changed before the decision was saved. The selected record is still open; refresh to load its current state.";
    }

    return validationMessage(payload);
  }

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
  const [correctionReason, setCorrectionReason] = useState("");
  const [confirmingCorrection, setConfirmingCorrection] = useState(false);
  const [correctionRequestKey, setCorrectionRequestKey] = useState(() => crypto.randomUUID());
  const mutationInFlight = useRef(false);
  const { isRouteFrozen } = useMaintenance();
  const correctionMode = selection.sourceType === 'order_refund' && detail?.canRequestCorrection === true;
  const correctionFrozen = isRouteFrozen('shop_owner.refunds.request-correction');
  const closeSafely = () => { if (!submitting && !confirmingCorrection) onClose(); };

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

  useEffect(() => {
    setCorrectionReason("");
    setCorrectionRequestKey(crypto.randomUUID());
  }, [selection.sourceType, selection.sourceId]);

  const handleKeyDown = (event: KeyboardEvent<HTMLElement>) => {
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      closeSafely();
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

  const canDecide = item.owner_action_required && item.primary_bucket === "needs_my_decision" && detail !== null && !isTerminal(detail, item.source_type);

  const submitDecision = async (action: ApprovalAction, reason?: string) => {
    if (!definition || submitting || mutationInFlight.current || !canDecide) return;
    const config = correctionMode ? {
      path: (id: number) => `/api/shop-owner/refunds/${id}/request-correction`,
      body: (instructions?: string) => ({ reason: instructions ?? '', request_key: correctionRequestKey }),
    } : definition[action];
    if (!config) return;

    mutationInFlight.current = true;
    setSubmitting(true);
    setError(null);
    setAnnouncement(correctionMode ? "Correction request is being saved." : `${action === "approve" ? "Approval" : "Rejection"} is being saved.`);

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

      if (!response.ok) {
        let payload: unknown = null;
        try {
          payload = await response.json();
        } catch {
          // Keep the generic mutation message when the server has no JSON error body.
        }
        throw { status: response.status, payload };
      }

      setAnnouncement(correctionMode ? "Sent back for correction." : `${action === "approve" ? "Approval" : "Rejection"} saved.`);
      await workflowFeedback.success({
        title: correctionMode ? "Sent back for correction" : action === "approve" ? "Approval successful" : "Rejection successful",
        text: correctionMode ? "A linked request is now with Staff. Required approvals must be completed again; no payout was authorized." : action === "approve"
          ? "The approval was saved successfully."
          : "The rejection was saved successfully.",
        timer: 1800,
        showConfirmButton: false,
      });
      onDecisionComplete();
    } catch (caught) {
      const status = isRecord(caught) && typeof caught.status === "number" ? caught.status : null;
      const payload = isRecord(caught) ? caught.payload : null;
      const message = status === 422
        ? validationMessage(payload)
        : status === null
          ? "The decision could not be saved. The selected record is still open; refresh and try again."
          : mutationMessage(status, payload);
      setError({ status, message });
      setAnnouncement("The decision could not be saved.");
      await workflowFeedback.error(
        message,
        correctionMode ? "Correction request failed" : action === "approve" ? "Approval failed" : "Rejection failed",
      );
    } finally {
      mutationInFlight.current = false;
      setSubmitting(false);
    }
  };

  if (!definition) {
    return (
      <Modal isOpen={true} onClose={closeSafely} showCloseButton={false} size="5xl" className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden !rounded-3xl">
        <section role="dialog" aria-modal="true" aria-label="Approval details" className="max-h-[min(92dvh,60rem)] overflow-y-auto bg-white p-5 dark:bg-gray-950 sm:p-6">
          <button type="button" onClick={closeSafely} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold dark:border-gray-700">Close</button>
          <p role="alert" className="mt-4 text-sm">This record is not an owner approval decision.</p>
        </section>
      </Modal>
    );
  }

  const Renderer = definition.renderer;

  return (
    <Modal isOpen={true} onClose={closeSafely} showCloseButton={false} size="5xl" className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden !rounded-3xl">
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="owner-approval-detail-title"
        onKeyDown={handleKeyDown}
        className="flex max-h-[min(92dvh,60rem)] min-h-0 flex-col overflow-hidden bg-white p-5 shadow-2xl dark:bg-gray-950 sm:p-6"
      >
      <header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-800">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Owner approval</p>
          <h2 id="owner-approval-detail-title" className="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{definition.label}</h2>
          <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{item.title} · #{item.source_id}</p>
        </div>
        <button
          ref={closeButtonRef}
          type="button"
          disabled={submitting || confirmingCorrection}
          aria-label="Close approval details"
          onClick={closeSafely}
          className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-700 dark:text-gray-200"
        >
          Close
        </button>
      </header>

      <div aria-live="polite" className="sr-only">{announcement}</div>

      {error && (
        <div role="alert" className="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
          <p>{error.message}</p>
          <button
            type="button"
            onClick={() => setRefreshToken((value) => value + 1)}
            className="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-300 px-4 py-2 font-semibold text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 dark:border-gray-700 dark:text-gray-100 dark:focus-visible:ring-gray-400"
          >
            Refresh
          </button>
        </div>
      )}

      <div className="min-h-0 flex-1 overflow-y-auto py-4">
        {loading ? (
          <div role="status" className="rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">Loading approval details…</div>
        ) : detail ? (
          <Renderer detail={detail} item={item} />
        ) : null}
      </div>

      {!loading && detail && (
        <section className="shrink-0 border-t border-gray-200 bg-gray-50 pb-[env(safe-area-inset-bottom)] pt-4 dark:border-gray-800 dark:bg-gray-900" aria-label="Approval decision">
          <div className="flex justify-end">
            {canDecide && correctionMode ? (
              <form aria-label="Return rejected loss for correction" className="w-full space-y-3" onSubmit={event => {
                event.preventDefault();
                if (correctionFrozen || submitting || confirmingCorrection || correctionReason.trim().length < 10) return;
                setConfirmingCorrection(true);
                void (async () => {
                  try {
                    const confirmation = await workflowFeedback.confirm({ title: 'Send back for correction?',
                      text: 'Keep the original rejection and create a linked Staff review request. All required financial approvals must be completed again; this does not authorize payment.',
                      confirmButtonText: 'Send back for correction' });
                    if (confirmation.isConfirmed) await submitDecision('approve', correctionReason.trim());
                  } finally { setConfirmingCorrection(false); }
                })();
              }}>
                <label htmlFor="loss-refund-correction-instructions" className="block text-sm font-semibold">Correction instructions</label>
                  <textarea id="loss-refund-correction-instructions" minLength={10} maxLength={1000} required disabled={submitting || confirmingCorrection}
                    value={correctionReason} onChange={event => setCorrectionReason(event.target.value)}
                    className="mt-1 block min-h-24 w-full rounded-lg border border-gray-300 bg-white p-3 text-gray-950 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                <p className="text-sm text-gray-600 dark:text-gray-300">Next: Staff → Retail Job Orders → Refund. The rejected request and its reviewer remain in history.</p>
                {correctionFrozen && <p role="status">Correction actions are paused during maintenance.</p>}
                <button type="submit" data-critical disabled={submitting || confirmingCorrection || correctionFrozen || correctionReason.trim().length < 10}
                  className="min-h-11 rounded-lg bg-gray-950 px-4 py-2 font-semibold text-white disabled:opacity-50">
                  {submitting ? 'Sending…' : 'Send back for correction'}
                </button>
              </form>
            ) : canDecide ? (
              <ApprovalDecisionFooter
                definition={definition}
                recordLabel={approvalRecordLabel(item)}
                submitting={submitting}
                onSubmit={submitDecision}
              />
            ) : (
              <p className="text-sm text-gray-600 dark:text-gray-300">
                {item.owner_action_required
                  ? "This record is no longer assigned to your decision queue. Refresh the Approval Center to see its current status."
                  : "This decision is recorded in approval history. No further action is available."}
              </p>
            )}
          </div>
        </section>
      )}
      </section>
    </Modal>
  );
}
