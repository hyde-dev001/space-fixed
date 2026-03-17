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

// ─── Helpers ──────────────────────────────────────────────────────────────────

const fmtCurrency = (val: number) =>
  new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(val);

const fmtDate = (str?: string | null) => {
  if (!str) return "—";
  return new Date(str).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
};

const fmtPct = (val: number) => {
  const sign = val >= 0 ? "+" : "";
  return `${sign}${val.toFixed(2)}%`;
};

const getCsrfToken = (): string =>
  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? "";

// ─── Main Component ───────────────────────────────────────────────────────────

const SalaryChanges: React.FC = () => {
  const { auth } = usePage().props as any;
  const userRole: string = auth?.user?.role ?? "";
  const permissions: string[] = auth?.permissions ?? [];
  const currentUserId: number = auth?.user?.id ?? 0;

  const canManage = userRole === "Manager" || permissions.includes("manage-salary-changes");
  const canApprove = userRole === "Manager" || permissions.includes("approve-salary-change");
  const canOverrideRetroactive = userRole === "Manager" || permissions.includes("override-salary-retroactive");

  // ─── State ──────────────────────────────────────────────────────────────────

  const [changes, setChanges] = useState<SalaryChange[]>([]);
  const [summary, setSummary] = useState<Summary>({ pending: 0, approved: 0, applied: 0, rejected: 0, cancelled: 0 });
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<"All" | ChangeStatus>("All");
  const [search, setSearch] = useState("");
  const [viewChange, setViewChange] = useState<SalaryChange | null>(null);

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
      setChanges(data.data?.data ?? data.data ?? []);
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

  const handleNewChange = async () => {
    const employeeOptions = employees
      .map((e) => `<option value="${e.id}" data-salary="${e.salary}">${e.name}${e.department ? ` — ${e.department}` : ""}</option>`)
      .join("");

    const today = new Date().toISOString().slice(0, 10);

    const result = await Swal.fire({
      title: "Propose Salary Change",
      html: `
        <div style="text-align:left;font-size:14px;">
          <label style="font-weight:600;display:block;margin-bottom:4px;">Employee *</label>
          <select id="sc-employee" class="swal2-input" style="margin:0 0 12px;width:100%;height:40px;padding:0 10px;">
            <option value="">— Select employee —</option>
            ${employeeOptions}
          </select>

          <div id="sc-current-salary-row" style="display:none;margin-bottom:12px;padding:8px 12px;background:#f1f5f9;border-radius:6px;">
            Current salary: <strong id="sc-current-salary-display"></strong>
          </div>

          <label style="font-weight:600;display:block;margin-bottom:4px;">New Salary (PHP) *</label>
          <input id="sc-new-salary" type="number" min="0" step="0.01" class="swal2-input" placeholder="e.g. 25000.00" style="margin:0 0 12px;width:100%;" />

          <label style="font-weight:600;display:block;margin-bottom:4px;">Effective Date *</label>
          <input id="sc-effective-date" type="date" class="swal2-input" value="${today}" style="margin:0 0 12px;width:100%;" />

          <label style="font-weight:600;display:block;margin-bottom:4px;">Reason *</label>
          <textarea id="sc-reason" class="swal2-textarea" placeholder="Describe the reason for this change..." style="margin:0;width:100%;height:80px;"></textarea>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: "Submit",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#3b82f6",
      focusConfirm: false,
      didOpen: () => {
        const sel = document.getElementById("sc-employee") as HTMLSelectElement;
        sel?.addEventListener("change", () => {
          const opt = sel.options[sel.selectedIndex];
          const salary = opt.getAttribute("data-salary");
          const row = document.getElementById("sc-current-salary-row")!;
          const disp = document.getElementById("sc-current-salary-display")!;
          if (salary) {
            disp.textContent = fmtCurrency(parseFloat(salary));
            row.style.display = "block";
          } else {
            row.style.display = "none";
          }
        });
      },
      preConfirm: () => {
        const employeeId = (document.getElementById("sc-employee") as HTMLSelectElement).value;
        const newSalary = (document.getElementById("sc-new-salary") as HTMLInputElement).value;
        const effectiveDate = (document.getElementById("sc-effective-date") as HTMLInputElement).value;
        const reason = (document.getElementById("sc-reason") as HTMLTextAreaElement).value.trim();

        if (!employeeId) { Swal.showValidationMessage("Please select an employee."); return false; }
        if (!newSalary || parseFloat(newSalary) <= 0) { Swal.showValidationMessage("Please enter a valid new salary."); return false; }
        if (!effectiveDate) { Swal.showValidationMessage("Effective date is required."); return false; }
        if (!reason) { Swal.showValidationMessage("Please provide a reason."); return false; }

        return { employee_id: parseInt(employeeId), new_salary: parseFloat(newSalary), effective_date: effectiveDate, reason };
      },
    });

    if (!result.isConfirmed || !result.value) return;

    try {
      const res = await fetch("/api/hr/salary-changes", {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": getCsrfToken() },
        credentials: "same-origin",
        body: JSON.stringify(result.value),
      });
      const data = await res.json();

      if (!res.ok) {
        if (res.status === 403 && data.code === "RETROACTIVE_LOCKED") {
          Swal.fire("Retroactive Change Blocked", data.message ?? "This effective date falls within a closed payroll period. You need override authority to proceed.", "warning");
        } else {
          Swal.fire("Error", data.message ?? "Failed to submit salary change.", "error");
        }
        return;
      }

      Swal.fire("Submitted", "Salary change proposal has been submitted for approval.", "success");
      fetchChanges();
    } catch {
      Swal.fire("Error", "A network error occurred. Please try again.", "error");
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
    } catch {
      Swal.fire("Error", "A network error occurred.", "error");
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
    }
  };

  // ─── View Modal ──────────────────────────────────────────────────────────────

  const ViewModal: React.FC<{ change: SalaryChange }> = ({ change }) => (
    <ModalPortal>
      <div className="fixed inset-0 z-9999 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
        <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto bg-white dark:bg-gray-900 rounded-2xl shadow-2xl">
          {/* Header */}
          <div className="sticky top-0 z-10 flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 rounded-t-2xl">
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white">Salary Change #{change.id}</h2>
              <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{change.employee?.name} · {fmtDate(change.effective_date)}</p>
            </div>
            <button
              onClick={() => setViewChange(null)}
              aria-label="Close"
              className="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>

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
                <p className="text-gray-500 dark:text-gray-400">Change Type</p>
                <span className={`inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-semibold ${changeTypePill[change.change_type]}`}>
                  {changeTypeLabel[change.change_type]}
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
                    className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition-colors"
                  >
                    Approve
                  </button>
                  <button
                    onClick={() => handleReject(change)}
                    className="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-sm font-medium rounded-lg transition-colors"
                  >
                    Reject
                  </button>
                </>
              )}
              {canApprove && change.status === "approved" && !change.applied_at && (
                <button
                  onClick={() => handleApply(change)}
                  className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
                >
                  Apply Now
                </button>
              )}
              {change.status === "pending" && (change.proposed_by === currentUserId || userRole === "Manager") && (
                <button
                  onClick={() => handleCancel(change)}
                  className="px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg transition-colors"
                >
                  Cancel
                </button>
              )}
              <button
                onClick={() => setViewChange(null)}
                className="ml-auto px-4 py-2 border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
    </ModalPortal>
  );

  // ─── Summary Cards ────────────────────────────────────────────────────────────

  const cards = [
    { label: "Pending", value: summary.pending, color: "amber" },
    { label: "Approved", value: summary.approved, color: "sky" },
    { label: "Applied", value: summary.applied, color: "emerald" },
    { label: "Rejected", value: summary.rejected, color: "rose" },
  ] as const;

  const cardColorMap = {
    amber: "border-amber-400 dark:border-amber-600",
    sky: "border-sky-400 dark:border-sky-600",
    emerald: "border-emerald-400 dark:border-emerald-600",
    rose: "border-rose-400 dark:border-rose-600",
  };

  const cardTextMap = {
    amber: "text-amber-600 dark:text-amber-400",
    sky: "text-sky-600 dark:text-sky-400",
    emerald: "text-emerald-600 dark:text-emerald-400",
    rose: "text-rose-600 dark:text-rose-400",
  };

  // ─── Render ──────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Salary Change Requests</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Propose, review, and track employee salary adjustments.</p>
        </div>
        {canManage && (
          <button
            onClick={handleNewChange}
            className="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
              <path d="M12 5v14M5 12h14" />
            </svg>
            New Salary Change
          </button>
        )}
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {cards.map((card) => (
          <div key={card.label} className={`bg-white dark:bg-gray-900 rounded-2xl border-l-4 ${cardColorMap[card.color]} p-4 shadow-sm`}>
            <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">{card.label}</p>
            <p className={`text-3xl font-bold mt-1 ${cardTextMap[card.color]}`}>{card.value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-4 shadow-sm">
        <div className="flex flex-col sm:flex-row gap-3">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by employee or department..."
            className="flex-1 px-4 py-2 text-sm border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as "All" | ChangeStatus)}
            title="Filter by status"
            className="px-4 py-2 text-sm border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="All">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="applied">Applied</option>
            <option value="rejected">Rejected</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>

      {/* Table */}
      <div className="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
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
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Employee</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Previous</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">New</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Change</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Type</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Effective</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {filtered.map((change) => (
                  <tr key={change.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                    <td className="px-4 py-3">
                      <div className="font-medium text-gray-900 dark:text-white">{change.employee?.name ?? `#${change.employee_id}`}</div>
                      {change.employee?.department && (
                        <div className="text-xs text-gray-500 dark:text-gray-400">{change.employee.department}</div>
                      )}
                      {change.retroactive && (
                        <span className="inline-block text-xs font-medium text-amber-600 dark:text-amber-400">⚠ Retroactive</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300 font-mono">{fmtCurrency(change.previous_salary)}</td>
                    <td className="px-4 py-3 text-gray-900 dark:text-white font-mono font-medium">{fmtCurrency(change.new_salary)}</td>
                    <td className="px-4 py-3">
                      <span className={`font-medium ${change.change_percent >= 0 ? "text-emerald-600 dark:text-emerald-400" : "text-rose-600 dark:text-rose-400"}`}>
                        {fmtPct(change.change_percent)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ${changeTypePill[change.change_type]}`}>
                        {changeTypeLabel[change.change_type]}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{fmtDate(change.effective_date)}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold ${statusPill[change.status]}`}>
                        {change.status.charAt(0).toUpperCase() + change.status.slice(1)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => setViewChange(change)}
                          className="px-3 py-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                        >
                          View
                        </button>
                        {canApprove && change.status === "pending" && change.proposed_by !== currentUserId && (
                          <button
                            onClick={() => handleApprove(change)}
                            className="px-3 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-colors"
                          >
                            Approve
                          </button>
                        )}
                        {canApprove && change.status === "approved" && !change.applied_at && (
                          <button
                            onClick={() => handleApply(change)}
                            className="px-3 py-1 text-xs font-medium text-sky-600 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-900/20 rounded-lg transition-colors"
                          >
                            Apply
                          </button>
                        )}
                        {change.status === "pending" && (change.proposed_by === currentUserId || userRole === "Manager") && (
                          <button
                            onClick={() => handleCancel(change)}
                            className="px-3 py-1 text-xs font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors"
                          >
                            Cancel
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
    </div>
  );
};

export default SalaryChanges;
