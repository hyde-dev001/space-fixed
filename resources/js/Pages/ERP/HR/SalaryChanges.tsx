import React, { useEffect, useMemo, useState } from "react";
import { createPortal } from "react-dom";
import { usePage } from "@inertiajs/react";
import Swal from "sweetalert2";

// ─── Types ────────────────────────────────────────────────────────────────────

type ChangeStatus = "pending" | "approved" | "rejected" | "applied" | "cancelled";
type ChangeType = "new_hire_rate_setup" | "minor_adjustment" | "major_adjustment" | "correction";

interface Employee {
  id: number;
  name: string;
  department?: string;
  position?: string;
  salary: number;
}

interface SalaryChange {
  id: number;
  employee_id: number;
  employee?: { id: number; name: string; department?: string; position?: string };
  proposed_by: number;
  proposer?: { id: number; name: string };
  approved_by?: number | null;
  approver?: { id: number; name: string } | null;
  rejected_by?: number | null;
  rejector?: { id: number; name: string } | null;
  retroactive_override_by?: number | null;
  retroactive_override_grantor?: { id: number; name: string } | null;
  previous_salary: number;
  new_salary: number;
  change_percent: number;
  change_type: ChangeType;
  effective_date: string;
  reason: string;
  status: ChangeStatus;
  notes?: string | null;
  approved_at?: string | null;
  rejected_at?: string | null;
  applied_at?: string | null;
  retroactive: boolean;
  retroactive_override_reason?: string | null;
  created_at: string;
  updated_at: string;
}

interface Summary {
  pending: number;
  approved: number;
  applied: number;
  rejected: number;
  cancelled: number;
}

type MetricCardProps = {
  title: string;
  value: number;
  description?: string;
  color?: "success" | "error" | "warning" | "info";
  icon: React.FC<{ className?: string }>;
};

// ─── Status / Type Helpers ────────────────────────────────────────────────────

const statusPill: Record<ChangeStatus, string> = {
  pending: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300",
  approved: "bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300",
  applied: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300",
  rejected: "bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300",
  cancelled: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
};

const changeTypeLabel: Record<ChangeType, string> = {
  new_hire_rate_setup: "New Hire Setup",
  minor_adjustment: "Minor Adj. (≤5%)",
  major_adjustment: "Major Adj. (>5%)",
  correction: "Correction",
};

const changeTypePill: Record<ChangeType, string> = {
  new_hire_rate_setup: "bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300",
  minor_adjustment: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
  major_adjustment: "bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300",
  correction: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
};

// ─── Modal Portal ─────────────────────────────────────────────────────────────

const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === "undefined") return null;
  return createPortal(children, document.body);
};

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const XCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l6-6m0 0l-6-6m6 6l6 6m-6-6l-6 6" />
  </svg>
);

const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const SparklesIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3l1.5 3L10 7.5 6.5 9 5 12l-1.5-3L0 7.5 3.5 6 5 3zm14 2l2 4 4 2-4 2-2 4-2-4-4-2 4-2 2-4zM8 15l1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2z" />
  </svg>
);

const BanIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m0 0L18.364 18.364m-12.728 0L18.364 5.636" />
  </svg>
);

const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  icon: Icon,
  color,
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success": return "from-green-500 to-emerald-600";
      case "error": return "from-red-500 to-rose-600";
      case "warning": return "from-yellow-500 to-orange-600";
      case "info": return "from-blue-500 to-indigo-600";
      default: return "from-gray-500 to-gray-600";
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="mb-4 flex items-center justify-between">
          <div className={`flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br ${getColorClasses()} shadow-lg transition-all duration-300 group-hover:rotate-6 group-hover:scale-110`}>
            <Icon className="size-7 text-white drop-shadow-sm" />
          </div>
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 transition-colors duration-300 dark:text-white">
            {value.toLocaleString()}
          </h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

const toNumber = (value: unknown, fallback = 0): number => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const fmtCurrency = (val: number | string | null | undefined) =>
  new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(toNumber(val));

const fmtDate = (str?: string | null) => {
  if (!str) return "—";
  return new Date(str).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
};

const fmtPct = (val: number | string | null | undefined) => {
  const numeric = toNumber(val);
  const sign = numeric >= 0 ? "+" : "";
  return `${sign}${numeric.toFixed(2)}%`;
};

const getInitials = (name?: string | null) => {
  if (!name) return "--";
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "--";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0] ?? ""}${parts[1][0] ?? ""}`.toUpperCase();
};

const getCsrfToken = (): string =>
  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? "";

const normalizeSalaryChange = (raw: any): SalaryChange => ({
  ...raw,
  previous_salary: toNumber(raw?.previous_salary),
  new_salary: toNumber(raw?.new_salary),
  change_percent: toNumber(raw?.change_percent),
});

// ─── Main Component ───────────────────────────────────────────────────────────

const SalaryChanges: React.FC = () => {
  const { auth } = usePage().props as any;
  const permissions: string[] = auth?.permissions ?? [];
  const currentUserId: number = auth?.user?.id ?? 0;

  const canManage = permissions.includes("manage-salary-changes");
  const canApprove = permissions.includes("approve-salary-change");
  const canOverrideRetroactive = permissions.includes("override-salary-retroactive");

  // ─── State ──────────────────────────────────────────────────────────────────

  const [changes, setChanges] = useState<SalaryChange[]>([]);
  const [summary, setSummary] = useState<Summary>({ pending: 0, approved: 0, applied: 0, rejected: 0, cancelled: 0 });
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<"All" | ChangeStatus>("All");
  const [search, setSearch] = useState("");
  const [viewChange, setViewChange] = useState<SalaryChange | null>(null);
  const [isNewChangeOpen, setIsNewChangeOpen] = useState(false);
  const [isSubmittingNewChange, setIsSubmittingNewChange] = useState(false);
  const [newChangeError, setNewChangeError] = useState<string | null>(null);
  const [isActionProcessing, setIsActionProcessing] = useState(false);
  const [newChangeForm, setNewChangeForm] = useState({
    employee_id: "",
    new_salary: "",
    effective_date: new Date().toISOString().slice(0, 10),
    reason: "",
  });

  // ─── Data Fetching ───────────────────────────────────────────────────────────

  const fetchChanges = async () => {
    try {
      const params = new URLSearchParams();
      if (statusFilter !== "All") params.set("status", statusFilter);
      const res = await fetch(`/api/hr/salary-changes?${params.toString()}`, {
        headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
      });
      if (!res.ok) throw new Error(await res.text());
      const data = await res.json();
      const rawChanges = data.data?.data ?? data.data ?? [];
      setChanges(Array.isArray(rawChanges) ? rawChanges.map(normalizeSalaryChange) : []);
      setSummary(data.summary ?? { pending: 0, approved: 0, applied: 0, rejected: 0, cancelled: 0 });
    } catch (err: any) {
      console.error("Error fetching salary changes:", err);
    } finally {
      setLoading(false);
    }
  };

  const fetchEmployees = async () => {
    try {
      const res = await fetch("/api/hr/employees?per_page=200", {
        headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
      });
      if (!res.ok) return;
      const data = await res.json();
      setEmployees(data.data?.data ?? data.data ?? []);
    } catch {
      // non-critical
    }
  };

  useEffect(() => {
    fetchChanges();
  }, [statusFilter]);

  useEffect(() => {
    fetchEmployees();
  }, []);

  // ─── Filtered Rows ───────────────────────────────────────────────────────────

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return changes;
    return changes.filter((c) => {
      const name = c.employee?.name?.toLowerCase() ?? "";
      const dept = c.employee?.department?.toLowerCase() ?? "";
      return name.includes(q) || dept.includes(q);
    });
  }, [changes, search]);

  // ─── Action Handlers ──────────────────────────────────────────────────────────

  const resetNewChangeForm = () => {
    setNewChangeForm({
      employee_id: "",
      new_salary: "",
      effective_date: new Date().toISOString().slice(0, 10),
      reason: "",
    });
    setNewChangeError(null);
  };

  const openNewChangeModal = () => {
    resetNewChangeForm();
    setIsNewChangeOpen(true);
  };

  const closeNewChangeModal = () => {
    if (isSubmittingNewChange) return;
    setIsNewChangeOpen(false);
    setNewChangeError(null);
  };

  const handleSubmitNewChange = async () => {
    setNewChangeError(null);

    const employeeId = Number(newChangeForm.employee_id);
    const salary = Number(newChangeForm.new_salary);
    const reason = newChangeForm.reason.trim();

    if (!employeeId) {
      setNewChangeError("Please select an employee.");
      return;
    }
    if (!Number.isFinite(salary) || salary <= 0) {
      setNewChangeError("Please enter a valid new daily rate.");
      return;
    }
    if (!newChangeForm.effective_date) {
      setNewChangeError("Effective date is required.");
      return;
    }
    if (!reason) {
      setNewChangeError("Please provide a reason.");
      return;
    }

    setIsSubmittingNewChange(true);

    try {
      const res = await fetch("/api/hr/salary-changes", {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
        body: JSON.stringify({
          employee_id: employeeId,
          new_salary: salary,
          effective_date: newChangeForm.effective_date,
          reason,
        }),
      });

      const data = await res.json().catch(() => ({} as any));

      if (!res.ok) {
        if (res.status === 403 && data.code === "RETROACTIVE_LOCKED") {
          setNewChangeError(data.message ?? "This effective date falls within a closed payroll period. You need override authority to proceed.");
        } else {
          setNewChangeError(data.message ?? "Failed to submit salary change.");
        }
        return;
      }

      setIsNewChangeOpen(false);
      resetNewChangeForm();
      fetchChanges();
    } catch {
      setNewChangeError("A network error occurred. Please try again.");
    } finally {
      setIsSubmittingNewChange(false);
    }
  };

  const handleApprove = async (change: SalaryChange) => {
    const result = await Swal.fire({
      title: "Approve Salary Change?",
      html: `
        <p style="margin-bottom:12px;">Approve salary change for <strong>${change.employee?.name}</strong>?</p>
        <p style="margin-bottom:12px;">${fmtCurrency(change.previous_salary)} → <strong>${fmtCurrency(change.new_salary)}</strong> (${fmtPct(change.change_percent)})</p>
        <p style="margin-bottom:12px;font-size:13px;color:#6b7280;">Effective: ${fmtDate(change.effective_date)}</p>
        ${change.retroactive ? `<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:13px;color:#92400e;">⚠️ This is a retroactive change (effective date is in a past payroll period).</div>` : ""}
        <label style="font-weight:600;display:block;margin-bottom:4px;text-align:left;">Notes (optional)</label>
        <textarea id="approve-notes" class="swal2-textarea" placeholder="Approver notes..." style="width:100%;height:70px;margin:0;"></textarea>
      `,
      showCancelButton: true,
      confirmButtonText: "Approve",
      confirmButtonColor: "#10b981",
      preConfirm: () => ({ notes: (document.getElementById("approve-notes") as HTMLTextAreaElement).value.trim() }),
    });

    if (!result.isConfirmed) return;

    setIsActionProcessing(true);
    try {
      const res = await fetch(`/api/hr/salary-changes/${change.id}/approve`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
        body: JSON.stringify(result.value),
      });
      const data = await res.json();
      if (!res.ok) { Swal.fire("Error", data.message ?? "Approval failed.", "error"); return; }
      Swal.fire("Approved", data.message ?? "Salary change approved successfully.", "success");
      setViewChange(null);
      fetchChanges();
    } catch {
      Swal.fire("Error", "A network error occurred.", "error");
    } finally {
      setIsActionProcessing(false);
    }
  };

  const handleReject = async (change: SalaryChange) => {
    const result = await Swal.fire({
      title: "Reject Salary Change",
      html: `
        <p style="margin-bottom:12px;">Reject salary change for <strong>${change.employee?.name}</strong>?</p>
        <label style="font-weight:600;display:block;margin-bottom:4px;text-align:left;">Rejection Reason *</label>
        <textarea id="reject-notes" class="swal2-textarea" placeholder="Required: why is this being rejected?" style="width:100%;height:80px;margin:0;"></textarea>
      `,
      showCancelButton: true,
      confirmButtonText: "Reject",
      confirmButtonColor: "#ef4444",
      preConfirm: () => {
        const notes = (document.getElementById("reject-notes") as HTMLTextAreaElement).value.trim();
        if (!notes) { Swal.showValidationMessage("Rejection reason is required."); return false; }
        return { notes };
      },
    });

    if (!result.isConfirmed) return;

    setIsActionProcessing(true);
    try {
      const res = await fetch(`/api/hr/salary-changes/${change.id}/reject`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
        body: JSON.stringify(result.value),
      });
      const data = await res.json();
      if (!res.ok) { Swal.fire("Error", data.message ?? "Rejection failed.", "error"); return; }
      Swal.fire("Rejected", "Salary change has been rejected.", "success");
      setViewChange(null);
      fetchChanges();
    } catch {
      Swal.fire("Error", "A network error occurred.", "error");
    } finally {
      setIsActionProcessing(false);
    }
  };

  const handleApply = async (change: SalaryChange) => {
    const confirm = await Swal.fire({
      title: "Apply Salary Change?",
      text: `This will immediately update ${change.employee?.name}'s salary to ${fmtCurrency(change.new_salary)}.`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Apply Now",
      confirmButtonColor: "#3b82f6",
    });
    if (!confirm.isConfirmed) return;

    setIsActionProcessing(true);
    try {
      const res = await fetch(`/api/hr/salary-changes/${change.id}/apply`, {
        method: "POST",
        headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
      });
      const data = await res.json();
      if (!res.ok) { Swal.fire("Error", data.message ?? "Apply failed.", "error"); return; }
      Swal.fire("Applied", "Salary change has been applied to the employee record.", "success");
      setViewChange(null);
      fetchChanges();
      fetchEmployees();
    } catch {
      Swal.fire("Error", "A network error occurred.", "error");
    } finally {
      setIsActionProcessing(false);
    }
  };

  const handleCancel = async (change: SalaryChange) => {
    const confirm = await Swal.fire({
      title: "Cancel Salary Change?",
      text: "This will cancel the pending proposal. This cannot be undone.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, Cancel It",
      confirmButtonColor: "#6b7280",
    });
    if (!confirm.isConfirmed) return;

    setIsActionProcessing(true);
    try {
      const res = await fetch(`/api/hr/salary-changes/${change.id}/cancel`, {
        method: "POST",
        headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
      });
      const data = await res.json();
      if (!res.ok) { Swal.fire("Error", data.message ?? "Cancel failed.", "error"); return; }
      Swal.fire("Cancelled", "Salary change proposal has been cancelled.", "success");
      setViewChange(null);
      fetchChanges();
    } catch {
      Swal.fire("Error", "A network error occurred.", "error");
    } finally {
      setIsActionProcessing(false);
    }
  };

  // ─── View Modal ──────────────────────────────────────────────────────────────

  const ViewModal: React.FC<{ change: SalaryChange }> = ({ change }) => (
    <ModalPortal>
      <div className="fixed inset-0 z-999999 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
        <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto bg-white dark:bg-gray-900 rounded-2xl shadow-2xl">
          <div className="p-6 space-y-6">
            {/* Retroactive Warning */}
            {change.retroactive && (
              <div className="flex items-start gap-3 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                <svg width="20" height="20" className="text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div>
                  <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">Retroactive Change</p>
                  <p className="text-sm text-amber-700 dark:text-amber-400 mt-0.5">Effective date falls within a closed payroll period.</p>
                  {change.retroactive_override_grantor && (
                    <p className="text-xs text-amber-600 dark:text-amber-500 mt-1">Override granted by: {change.retroactive_override_grantor.name}</p>
                  )}
                  {change.retroactive_override_reason && (
                    <p className="text-xs text-amber-600 dark:text-amber-500">Override reason: {change.retroactive_override_reason}</p>
                  )}
                </div>
              </div>
            )}

            {/* Salary Delta */}
            <div className="grid grid-cols-3 gap-4 p-4 bg-gray-50 dark:bg-gray-800/50 rounded-xl">
              <div className="text-center">
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Previous Salary</p>
                <p className="text-lg font-bold text-gray-700 dark:text-gray-200">{fmtCurrency(change.previous_salary)}</p>
              </div>
              <div className="text-center flex flex-col items-center justify-center">
                <span className={`text-sm font-bold px-2 py-0.5 rounded-full ${change.change_percent >= 0 ? "text-emerald-700 bg-emerald-100 dark:bg-emerald-900/40 dark:text-emerald-300" : "text-rose-700 bg-rose-100 dark:bg-rose-900/40 dark:text-rose-300"}`}>
                  {fmtPct(change.change_percent)}
                </span>
                <svg width="24" height="12" viewBox="0 0 24 12" fill="none" className="mt-1 text-gray-400 dark:text-gray-600" stroke="currentColor" strokeWidth="2">
                  <path d="M0 6h20m-6-5l6 5-6 5" />
                </svg>
              </div>
              <div className="text-center">
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">New Salary</p>
                <p className="text-lg font-bold text-emerald-600 dark:text-emerald-400">{fmtCurrency(change.new_salary)}</p>
              </div>
            </div>

            {/* Meta */}
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p className="text-gray-500 dark:text-gray-400">Status</p>
                <span className={`inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-semibold ${statusPill[change.status]}`}>
                  {change.status.charAt(0).toUpperCase() + change.status.slice(1)}
                </span>
              </div>
              <div>
                <p className="text-gray-500 dark:text-gray-400">Effective Date</p>
                <p className="font-medium text-gray-900 dark:text-white mt-0.5">{fmtDate(change.effective_date)}</p>
              </div>
              <div>
                <p className="text-gray-500 dark:text-gray-400">Proposed By</p>
                <p className="font-medium text-gray-900 dark:text-white mt-0.5">{change.proposer?.name ?? "—"}</p>
              </div>
              <div>
                <p className="text-gray-500 dark:text-gray-400">Department</p>
                <p className="font-medium text-gray-900 dark:text-white mt-0.5">{change.employee?.department ?? "—"}</p>
              </div>
              <div>
                <p className="text-gray-500 dark:text-gray-400">Position</p>
                <p className="font-medium text-gray-900 dark:text-white mt-0.5">{change.employee?.position ?? "—"}</p>
              </div>
            </div>

            {/* Reason */}
            <div>
              <p className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Reason</p>
              <p className="text-sm text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3">{change.reason}</p>
            </div>

            {/* Approval Trail */}
            {(change.approver || change.approved_at) && (
              <div className="p-4 rounded-xl bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800">
                <p className="text-xs font-semibold text-sky-700 dark:text-sky-300 uppercase tracking-wide mb-2">Approval Record</p>
                <div className="space-y-1 text-sm">
                  <div className="flex justify-between">
                    <span className="text-gray-600 dark:text-gray-400">Approved By</span>
                    <span className="font-medium text-gray-900 dark:text-white">{change.approver?.name ?? "—"}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-600 dark:text-gray-400">Approved At</span>
                    <span className="font-medium text-gray-900 dark:text-white">{fmtDate(change.approved_at)}</span>
                  </div>
                  {change.notes && (
                    <div className="mt-2 pt-2 border-t border-sky-200 dark:border-sky-800">
                      <span className="text-gray-600 dark:text-gray-400">Notes: </span>
                      <span className="text-gray-800 dark:text-gray-200">{change.notes}</span>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Rejection Trail */}
            {(change.rejector || change.rejected_at) && (
              <div className="p-4 rounded-xl bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800">
                <p className="text-xs font-semibold text-rose-700 dark:text-rose-300 uppercase tracking-wide mb-2">Rejection Record</p>
                <div className="space-y-1 text-sm">
                  <div className="flex justify-between">
                    <span className="text-gray-600 dark:text-gray-400">Rejected By</span>
                    <span className="font-medium text-gray-900 dark:text-white">{change.rejector?.name ?? "—"}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-600 dark:text-gray-400">Rejected At</span>
                    <span className="font-medium text-gray-900 dark:text-white">{fmtDate(change.rejected_at)}</span>
                  </div>
                  {change.notes && (
                    <div className="mt-2 pt-2 border-t border-rose-200 dark:border-rose-800">
                      <span className="text-gray-600 dark:text-gray-400">Reason: </span>
                      <span className="text-gray-800 dark:text-gray-200">{change.notes}</span>
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* Applied Record */}
            {change.applied_at && (
              <div className="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
                <p className="text-xs font-semibold text-emerald-700 dark:text-emerald-300 uppercase tracking-wide mb-2">Applied Record</p>
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Applied to employee record on <strong>{fmtDate(change.applied_at)}</strong>.
                </div>
              </div>
            )}

            {/* Action Buttons */}
            <div className="flex flex-wrap gap-2 pt-2 border-t border-gray-200 dark:border-gray-800">
              {canApprove && change.status === "pending" && change.proposed_by !== currentUserId && (
                <>
                  <button
                    onClick={() => handleApprove(change)}
                    disabled={isActionProcessing}
                    className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors"
                  >
                    Approve
                  </button>
                  <button
                    onClick={() => handleReject(change)}
                    disabled={isActionProcessing}
                    className="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors"
                  >
                    Reject
                  </button>
                </>
              )}
              {canManage && change.status === "approved" && !change.applied_at && (
                <button
                  onClick={() => handleApply(change)}
                  disabled={isActionProcessing}
                  className="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors"
                >
                  Apply Now
                </button>
              )}
              {change.status === "pending" && (change.proposed_by === currentUserId || canManage || canApprove) && (
                <button
                  onClick={() => handleCancel(change)}
                  disabled={isActionProcessing}
                  className="px-4 py-2 bg-gray-200 hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg transition-colors"
                >
                  Cancel
                </button>
              )}
              <button
                onClick={() => setViewChange(null)}
                disabled={isActionProcessing}
                className="ml-auto px-4 py-2 border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    </ModalPortal>
  );

  const stats = {
    total: summary.pending + summary.approved + summary.applied + summary.rejected + summary.cancelled,
    pending: summary.pending,
    approved: summary.approved,
    applied: summary.applied,
    rejected: summary.rejected,
    cancelled: summary.cancelled,
  };

  const selectedEmployee = useMemo(() => {
    const id = Number(newChangeForm.employee_id);
    if (!id) return null;
    return employees.find((employee) => employee.id === id) ?? null;
  }, [employees, newChangeForm.employee_id]);

  // ─── Render ──────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Salary Change Requests</h1>
          <p className="mt-2 text-gray-600 dark:text-gray-400">Propose, review, and track employee salary adjustments.</p>
        </div>
        {canManage && (
          <button
            onClick={openNewChangeModal}
            disabled={isSubmittingNewChange}
            className={`inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ${isSubmittingNewChange ? "opacity-50 cursor-not-allowed" : ""}`}
          >
            <svg className="w-5 h-5 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 5v14M5 12h14" />
            </svg>
            New Salary Change
          </button>
        )}
      </div>

      {/* Metrics */}
      <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-5">
        <MetricCard
          title="Total Requests"
          value={stats.total}
          icon={SparklesIcon}
          color="info"
          description="All salary change records"
        />
        <MetricCard
          title="Pending"
          value={stats.pending}
          icon={ClockIcon}
          color="warning"
          description="Awaiting approval"
        />
        <MetricCard
          title="Approved"
          value={stats.approved}
          icon={CheckCircleIcon}
          color="info"
          description="Ready to apply"
        />
        <MetricCard
          title="Applied"
          value={stats.applied}
          icon={CheckCircleIcon}
          color="success"
          description="Updated on employee records"
        />
        <MetricCard
          title="Rejected / Cancelled"
          value={stats.rejected + stats.cancelled}
          icon={XCircleIcon}
          color="error"
          description="Closed without apply"
        />
      </div>

      {/* Filters */}
      <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/3">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="lg:col-span-3">
            <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by employee or department..."
              className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-gray-900 placeholder-gray-500 transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder-gray-400"
            />
          </div>
          <div>
            <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as "All" | ChangeStatus)}
              title="Filter by status"
              className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-gray-900 transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
            >
              <option value="All">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="applied">Applied</option>
              <option value="rejected">Rejected</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-white/3">
        {loading ? (
          <div className="py-24 text-center text-gray-400 dark:text-gray-600">
            <svg className="animate-spin w-8 h-8 mx-auto mb-3 text-blue-500" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
            </svg>
            Loading salary changes...
          </div>
        ) : filtered.length === 0 ? (
          <div className="py-24 text-center">
            <p className="text-gray-400 dark:text-gray-600 text-sm">No salary change requests found.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="border-b border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900/50">
                <tr>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Employee</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Previous</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">New</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Change</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Effective</th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                  <th className="px-6 py-4 text-center text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {filtered.map((change) => (
                  <tr key={change.id} className="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/50">
                    <td className="px-6 py-4">
                      <div className="flex items-center space-x-3">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40">
                          <span className="text-sm font-medium text-blue-700 dark:text-blue-300">
                            {getInitials(change.employee?.name)}
                          </span>
                        </div>
                        <div>
                          <p className="font-medium text-gray-900 dark:text-white">{change.employee?.name ?? `#${change.employee_id}`}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">{change.employee?.department ?? "No department"}</p>
                          {change.retroactive && (
                            <span className="mt-0.5 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                              Retroactive
                            </span>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 font-mono text-sm text-gray-700 dark:text-gray-300">{fmtCurrency(change.previous_salary)}</td>
                    <td className="px-6 py-4 font-mono text-sm font-medium text-gray-900 dark:text-white">{fmtCurrency(change.new_salary)}</td>
                    <td className="px-6 py-4">
                      <span className={`font-medium ${change.change_percent >= 0 ? "text-emerald-600 dark:text-emerald-400" : "text-rose-600 dark:text-rose-400"}`}>
                        {fmtPct(change.change_percent)}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{fmtDate(change.effective_date)}</td>
                    <td className="px-6 py-4">
                      <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold ${statusPill[change.status]}`}>
                        {change.status.charAt(0).toUpperCase() + change.status.slice(1)}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <div className="flex items-center justify-center gap-2">
                        <button
                          onClick={() => setViewChange(change)}
                          className="rounded-lg p-2 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/20"
                          title="View details"
                        >
                          <svg className="size-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                          </svg>
                        </button>
                        {canApprove && change.status === "pending" && change.proposed_by !== currentUserId && (
                          <button
                            onClick={() => handleApprove(change)}
                            className="rounded-lg p-2 transition-colors hover:bg-green-50 dark:hover:bg-green-900/20"
                            title="Approve"
                          >
                            <CheckCircleIcon className="size-5 text-green-600 dark:text-green-400" />
                          </button>
                        )}
                        {canManage && change.status === "approved" && !change.applied_at && (
                          <button
                            onClick={() => handleApply(change)}
                            className="rounded-lg p-2 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/20"
                            title="Apply now"
                          >
                            <SparklesIcon className="size-5 text-blue-600 dark:text-blue-400" />
                          </button>
                        )}
                        {change.status === "pending" && (change.proposed_by === currentUserId || canManage || canApprove) && (
                          <button
                            onClick={() => handleCancel(change)}
                            className="rounded-lg p-2 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                            title="Cancel"
                          >
                            <BanIcon className="size-5 text-gray-500 dark:text-gray-400" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* View Modal */}
      {viewChange && <ViewModal change={viewChange} />}

      {/* New Salary Change Modal */}
      {isNewChangeOpen && (
        <ModalPortal>
          <div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-900 rounded-xl shadow-xl max-w-2xl w-full border border-gray-200 dark:border-gray-800 overflow-hidden">
              <div className="border-b border-gray-200 dark:border-gray-800 px-8 py-6 flex items-start justify-between gap-4">
                <div>
                  <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Propose Daily Rate Change</h2>
                  <p className="text-gray-600 dark:text-gray-400 text-sm mt-1">Create a salary change request for approval.</p>
                </div>
                <button
                  onClick={closeNewChangeModal}
                  className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 text-2xl leading-none font-light transition-colors"
                  aria-label="Close"
                >
                  ×
                </button>
              </div>

              <div className="p-8 max-h-[calc(90vh-150px)] overflow-y-auto">
                <div className="space-y-5">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                      Employee <span className="text-red-500">*</span>
                    </label>
                    <select
                      value={newChangeForm.employee_id}
                      onChange={(e) => setNewChangeForm((prev) => ({ ...prev, employee_id: e.target.value }))}
                      title="Select employee"
                      className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                    >
                      <option value="">Select employee</option>
                      {employees.map((employee) => (
                        <option key={employee.id} value={employee.id}>
                          {employee.name}{employee.department ? ` - ${employee.department}` : ""}
                        </option>
                      ))}
                    </select>
                  </div>

                  {selectedEmployee && (
                    <div className="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 px-4 py-3">
                      <p className="text-sm text-blue-900 dark:text-blue-300">
                        Current daily rate: <span className="font-semibold">{fmtCurrency(selectedEmployee.salary)}</span>
                      </p>
                    </div>
                  )}

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        New Daily Rate (PHP) <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="e.g. 850.00"
                        value={newChangeForm.new_salary}
                        onChange={(e) => setNewChangeForm((prev) => ({ ...prev, new_salary: e.target.value }))}
                        className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        Effective Date <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="date"
                        title="Effective date"
                        value={newChangeForm.effective_date}
                        onChange={(e) => setNewChangeForm((prev) => ({ ...prev, effective_date: e.target.value }))}
                        className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                      Reason <span className="text-red-500">*</span>
                    </label>
                    <textarea
                      value={newChangeForm.reason}
                      onChange={(e) => setNewChangeForm((prev) => ({ ...prev, reason: e.target.value }))}
                      placeholder="Describe the reason for this change..."
                      rows={4}
                      className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all resize-none"
                    />
                  </div>

                  {newChangeError && (
                    <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-300">
                      {newChangeError}
                    </div>
                  )}
                </div>
              </div>

              <div className="bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700 px-8 py-4 flex gap-3 justify-end">
                <button
                  onClick={closeNewChangeModal}
                  disabled={isSubmittingNewChange}
                  className="px-5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-all duration-200 hover:shadow-sm disabled:opacity-50"
                >
                  Cancel
                </button>
                <button
                  onClick={handleSubmitNewChange}
                  disabled={isSubmittingNewChange}
                  className={`px-5 py-2.5 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-all duration-200 hover:shadow-md active:shadow-sm ${isSubmittingNewChange ? "opacity-50 cursor-not-allowed" : ""}`}
                >
                  {isSubmittingNewChange ? "Submitting..." : "Submit Salary Change"}
                </button>
              </div>
            </div>
          </div>
        </ModalPortal>
      )}
    </div>
  );
};

export default SalaryChanges;
