import { Head } from "@inertiajs/react";
import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { workflowFeedback } from "../../../utils/workflowFeedback";

type RequestType = "termination" | "rehire";

type LifecycleFilterForm = {
  search: string;
  status: string;
};

type LifecycleFilters = LifecycleFilterForm & {
  page: number;
};

const initialFilterForm: LifecycleFilterForm = {
  search: "",
  status: "pending_manager",
};

const buildLifecycleQuery = (endpoint: string, filters: LifecycleFilters): string => {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.search.trim()) params.set("search", filters.search.trim());
  params.set("page", String(filters.page));
  return `${endpoint}?${params.toString()}`;
};

interface LifecycleRequest {
  id: number;
  name?: string | null;
  email?: string | null;
  position?: string | null;
  requested_by?: string | null;
  requested_at?: string | null;
  reason: string;
  evidence?: string | null;
  workflow_status: string;
  next_action: string;
  manager_note?: string | null;
  owner_note?: string | null;
  rehire_start_date?: string | null;
  rehire_position?: string | null;
  rehire_department?: string | null;
  rehire_role?: string | null;
}

interface ApiResponse {
  data: {
    data: LifecycleRequest[];
    current_page: number;
    last_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
  };
  metrics: {
    pending: number;
    awaiting_owner: number;
    approved: number;
    rejected: number;
    total: number;
  };
}

const csrfToken = (): string | null => (
  typeof document === "undefined"
    ? null
    : document.querySelector("meta[name=csrf-token]")?.getAttribute("content") ?? null
);

const formatDate = (value?: string | null): string => {
  if (!value) return "Not available";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: "medium" });
};

const statusLabel = (value: string): string => value.replace(/[_-]+/g, " ").replace(/w/g, (character) => character.toUpperCase());

const statusClasses = (value: string): string => {
  if (value === "pending_manager") return "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300";
  if (value === "pending_owner") return "border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300";
  if (value === "approved") return "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300";
  return "border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300";
};

const readError = async (response: Response): Promise<string> => {
  const payload: unknown = await response.json().catch(() => null);
  if (typeof payload === "object" && payload !== null && "message" in payload && typeof payload.message === "string") {
    return payload.message;
  }
  return "The request could not be completed. Please refresh and try again.";
};

const Metric = ({ label, value }: { label: string; value: number }) => (
  <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
    <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
    <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{value.toLocaleString()}</p>
  </div>
);

export default function EmploymentLifecycleApprovals() {
  const type: RequestType = typeof window !== "undefined" && window.location.pathname.includes("rehire") ? "rehire" : "termination";
  const label = type === "rehire" ? "Rehire" : "Termination";
  const endpoint = "/api/manager/" + type + "-requests";
  const [payload, setPayload] = useState<ApiResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [selected, setSelected] = useState<LifecycleRequest | null>(null);
  const [rejecting, setRejecting] = useState<LifecycleRequest | null>(null);
  const [note, setNote] = useState("");
  const [processingId, setProcessingId] = useState<number | null>(null);
  const [form, setForm] = useState<LifecycleFilterForm>(initialFilterForm);
  const [filters, setFilters] = useState<LifecycleFilters>({ ...initialFilterForm, page: 1 });

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(buildLifecycleQuery(endpoint, filters), { credentials: "include", headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error(await readError(response));
      setPayload(await response.json() as ApiResponse);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Unable to load requests.");
    } finally {
      setLoading(false);
    }
  }, [endpoint, filters]);

  useEffect(() => { void load(); }, [load]);

  const applyFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFilters({ search: form.search.trim(), status: form.status, page: 1 });
  };

  const clearFilters = () => {
    setForm(initialFilterForm);
    setFilters({ ...initialFilterForm, page: 1 });
  };

  const goToPage = (nextPage: number) => {
    if (!payload || nextPage < 1 || nextPage > payload.data.last_page || nextPage === payload.data.current_page) return;
    setFilters((current) => ({ ...current, page: nextPage }));
  };

  const review = async (request: LifecycleRequest, action: "approve" | "reject", reason = "") => {
    setProcessingId(request.id);
    setActionError(null);

    try {
      const headers: Record<string, string> = { Accept: "application/json", "Content-Type": "application/json" };
      const token = csrfToken();
      if (token) headers["X-CSRF-TOKEN"] = token;

      const response = await fetch(endpoint + "/" + request.id + "/review", {
        method: "POST",
        credentials: "include",
        headers,
        body: JSON.stringify({ action, note: reason }),
      });
      if (!response.ok) throw new Error(await readError(response));

      setSelected(null);
      setRejecting(null);
      setNote("");
      await workflowFeedback.success({
        title: action === "approve" ? label + " stage approved" : label + " request rejected",
        text: action === "approve"
          ? "The request was forwarded to Company Shop Owner review."
          : "The request was rejected and closed at the Manager stage.",
        timer: 1800,
        showConfirmButton: false,
      });
      await load();
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : "Unable to save this decision.";
      setActionError(message);
      await workflowFeedback.error(message, label + " decision failed");
    } finally {
      setProcessingId(null);
    }
  };

  const approve = async (request: LifecycleRequest) => {
    if (processingId === request.id) return;

    const confirmation = await workflowFeedback.confirm({
      title: "Approve " + label + " stage?",
      text: "Approve the Manager stage for " + (request.name || "this employee") + "? The request will move to Company Shop Owner review.",
      confirmButtonText: "Approve stage",
    });
    if (confirmation.isConfirmed) {
      await review(request, "approve");
    }
  };

  const reject = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!rejecting) return;
    if (note.trim().length < 3) {
      void workflowFeedback.warning("Rejection reason required", "Provide at least 3 characters before rejecting this request.");
      return;
    }
    void review(rejecting, "reject", note.trim());
  };

  const closeDetails = () => {
    setSelected(null);
    setRejecting(null);
    setNote("");
  };

  const requests = payload?.data.data ?? [];

  return (
    <AppLayoutERP>
      <Head title={label + " Approvals - Solespace ERP"} />
      <main className="space-y-6 py-6 md:py-8" aria-labelledby="lifecycle-approvals-title">
        <header>
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">People &amp; approvals</p>
          <h1 id="lifecycle-approvals-title" className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">{label} Approvals</h1>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">
            Review the HR -&gt; Manager -&gt; Company Shop Owner {label.toLowerCase()} workflow. Manager approval only forwards the request; the employment/account state changes after Company Shop Owner approval.
          </p>
        </header>

        <section className="grid grid-cols-2 gap-4 lg:grid-cols-4" aria-label={label + " approval summary"}>
          <Metric label="Pending Manager review" value={payload?.metrics.pending ?? 0} />
          <Metric label="Waiting for Shop Owner" value={payload?.metrics.awaiting_owner ?? 0} />
          <Metric label="Approved" value={payload?.metrics.approved ?? 0} />
          <Metric label="Rejected" value={payload?.metrics.rejected ?? 0} />
        </section>

        <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="lifecycle-filters-title">
          <div className="mb-4">
            <h2 id="lifecycle-filters-title" className="text-base font-semibold text-gray-900 dark:text-white">Filter requests</h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Search the {label.toLowerCase()} history in your authorized shop.</p>
          </div>
          <form onSubmit={applyFilters} className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div className="md:col-span-2">
              <label htmlFor="lifecycle-search" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
              <input id="lifecycle-search" type="search" value={form.search} onChange={(event) => setForm((current) => ({ ...current, search: event.target.value }))} placeholder="Employee, email, or reason" className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
            </div>
            <div>
              <label htmlFor="lifecycle-status" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Workflow status</label>
              <select id="lifecycle-status" value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                <option value="pending_manager">Pending Manager review</option>
                <option value="pending_owner">Waiting for Shop Owner</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="">All statuses</option>
              </select>
            </div>
            <div className="flex items-end gap-2 md:col-span-3">
              <button type="submit" className="min-h-11 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">Apply filters</button>
              <button type="button" onClick={clearFilters} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Clear</button>
            </div>
          </form>
        </section>

        {error && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{error} <button type="button" onClick={() => void load()} className="ml-2 font-semibold underline">Retry</button></div>}
        {actionError && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{actionError}</div>}
        {loading && <div role="status" className="rounded-xl border border-gray-200 bg-white p-8 text-sm text-gray-500 dark:border-gray-800 dark:bg-white/[0.03]">Loading {label.toLowerCase()} requests...</div>}
        {!loading && !error && requests.length === 0 && <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-700 dark:bg-white/[0.03]"><h2 className="font-semibold text-gray-900 dark:text-white">No {label.toLowerCase()} requests found</h2><p className="mt-2 text-sm text-gray-500 dark:text-gray-400">There are no requests matching the selected status and search filters in your authorized shop.</p></div>}

        {!loading && requests.length > 0 && (
          <section className="space-y-4" aria-label={label + " request queue"}>
            {requests.map((request) => (
              <article key={request.id} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                  <div><h2 className="text-base font-semibold text-gray-900 dark:text-white">{request.name || "Unknown employee"}</h2><p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{request.position || request.email || "No employee context"}</p></div>
                  <span className={"inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold " + statusClasses(request.workflow_status)}>{statusLabel(request.workflow_status)}</span>
                </div>
                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                  <div><dt className="text-xs uppercase tracking-wide text-gray-500">Requested by</dt><dd className="mt-1 text-gray-800 dark:text-gray-200">{request.requested_by || "Not available"}</dd></div>
                  <div><dt className="text-xs uppercase tracking-wide text-gray-500">Submitted</dt><dd className="mt-1 text-gray-800 dark:text-gray-200">{formatDate(request.requested_at)}</dd></div>
                  <div><dt className="text-xs uppercase tracking-wide text-gray-500">Next action</dt><dd className="mt-1 text-gray-800 dark:text-gray-200">{request.next_action}</dd></div>
                </dl>
                <p className="mt-4 line-clamp-3 text-sm leading-6 text-gray-700 dark:text-gray-300">{request.reason}</p>
                {type === "rehire" && <div className="mt-4 grid gap-3 rounded-xl bg-blue-50 p-4 text-sm dark:bg-blue-950/20 sm:grid-cols-2"><p><strong>New start:</strong> {formatDate(request.rehire_start_date)}</p><p><strong>Position:</strong> {request.rehire_position || "Not specified"}</p><p><strong>Department:</strong> {request.rehire_department || "Not specified"}</p><p><strong>Access role:</strong> {request.rehire_role || "Not specified"}</p></div>}
                <div className="mt-4 flex flex-wrap gap-2">
                  <button type="button" onClick={() => setSelected(request)} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">View details</button>
                </div>
              </article>
            ))}
           </section>
         )}

        {!loading && !error && payload && requests.length > 0 && payload.data.last_page > 1 && (
          <nav className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800 dark:bg-white/[0.03]" aria-label={label + " approval pagination"}>
            <p className="text-sm text-gray-600 dark:text-gray-400">Showing {payload.data.from ?? 0}-{payload.data.to ?? 0} of {payload.data.total.toLocaleString()}</p>
            <div className="flex items-center gap-2">
              <button type="button" onClick={() => goToPage(payload.data.current_page - 1)} disabled={payload.data.current_page <= 1 || loading} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Previous</button>
              <span className="px-2 text-sm font-medium text-gray-700 dark:text-gray-300" aria-current="page">Page {payload.data.current_page} of {payload.data.last_page}</span>
              <button type="button" onClick={() => goToPage(payload.data.current_page + 1)} disabled={payload.data.current_page >= payload.data.last_page || loading} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Next</button>
            </div>
          </nav>
        )}
      </main>

      {selected && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4">
          <div role="dialog" aria-modal="true" aria-labelledby="lifecycle-details-title" className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-blue-600">{label} request #{selected.id}</p>
                <h2 id="lifecycle-details-title" className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{selected.name || "Unknown employee"}</h2>
              </div>
              <button type="button" onClick={closeDetails} className="min-h-11 rounded-lg border border-gray-300 px-3 text-sm font-semibold dark:border-gray-700 dark:text-gray-300">Close</button>
            </div>

            <div className="mt-5 grid gap-4 text-sm sm:grid-cols-2">
              <div><p className="text-xs uppercase tracking-wide text-gray-500">Status</p><p className="mt-1"><span className={"inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold " + statusClasses(selected.workflow_status)}>{statusLabel(selected.workflow_status)}</span></p></div>
              <div><p className="text-xs uppercase tracking-wide text-gray-500">Next action</p><p className="mt-1 text-gray-800 dark:text-gray-200">{selected.next_action}</p></div>
              <div><p className="text-xs uppercase tracking-wide text-gray-500">Requested by</p><p className="mt-1 text-gray-800 dark:text-gray-200">{selected.requested_by || "Not available"}</p></div>
              <div><p className="text-xs uppercase tracking-wide text-gray-500">Submitted</p><p className="mt-1 text-gray-800 dark:text-gray-200">{formatDate(selected.requested_at)}</p></div>
            </div>

            <div className="mt-5 space-y-4 text-sm text-gray-700 dark:text-gray-300">
              <div><h3 className="font-semibold text-gray-900 dark:text-white">HR reason</h3><p className="mt-1 whitespace-pre-wrap leading-6">{selected.reason}</p></div>
              <div><h3 className="font-semibold text-gray-900 dark:text-white">HR evidence / details</h3><p className="mt-1 whitespace-pre-wrap leading-6">{selected.evidence || "No evidence supplied."}</p></div>
              {selected.manager_note && <div><h3 className="font-semibold text-gray-900 dark:text-white">Manager note</h3><p className="mt-1 whitespace-pre-wrap leading-6">{selected.manager_note}</p></div>}
              {selected.owner_note && <div><h3 className="font-semibold text-gray-900 dark:text-white">Owner note</h3><p className="mt-1 whitespace-pre-wrap leading-6">{selected.owner_note}</p></div>}
            </div>

            {type === "rehire" && (
              <div className="mt-5 grid gap-3 rounded-xl bg-blue-50 p-4 text-sm text-gray-800 dark:bg-blue-950/20 dark:text-gray-200 sm:grid-cols-2">
                <p><strong>New start date:</strong> {formatDate(selected.rehire_start_date)}</p>
                <p><strong>Position / Job Title:</strong> {selected.rehire_position || "Not specified"}</p>
                <p><strong>Department / Role:</strong> {selected.rehire_department || "Not specified"}</p>
                <p><strong>New account role:</strong> {selected.rehire_role || "Not specified"}</p>
              </div>
            )}

            {selected.workflow_status === "pending_manager" && !rejecting && (
              <div className="mt-6 flex flex-col-reverse gap-2 border-t border-gray-200 pt-5 sm:flex-row sm:justify-end dark:border-gray-800">
                <button type="button" onClick={() => { setRejecting(selected); setNote(""); }} disabled={processingId === selected.id} className="min-h-11 rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:text-red-300">Reject request</button>
                <button type="button" onClick={() => void approve(selected)} disabled={processingId === selected.id} className="min-h-11 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">Approve stage</button>
              </div>
            )}

            {rejecting?.id === selected.id && (
              <form onSubmit={reject} className="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/20">
                <h3 className="text-sm font-semibold text-red-900 dark:text-red-100">Reject {label.toLowerCase()} request</h3>
                <p className="mt-1 text-sm text-red-800 dark:text-red-200">A rejection closes the request at the Manager stage and does not change the employee account.</p>
                <label htmlFor="lifecycle-rejection-reason" className="mt-4 block text-sm font-semibold text-gray-800 dark:text-gray-200">Rejection reason <span className="font-normal">(required)</span></label>
                <textarea id="lifecycle-rejection-reason" aria-label="Rejection reason" value={note} onChange={(event) => setNote(event.target.value)} required minLength={3} maxLength={1000} rows={5} className="mt-1 w-full rounded-lg border border-gray-300 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white" placeholder="Reason for rejection" />
                <div className="mt-3 flex flex-wrap justify-end gap-2">
                  <button type="button" onClick={() => { setRejecting(null); setNote(""); }} className="min-h-11 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold dark:border-gray-700 dark:text-gray-300">Cancel</button>
                  <button type="submit" disabled={note.trim().length < 3 || processingId === selected.id} className="min-h-11 rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Confirm rejection</button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}
    </AppLayoutERP>
  );
}
