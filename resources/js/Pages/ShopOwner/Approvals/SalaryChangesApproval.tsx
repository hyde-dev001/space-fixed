import React, { useEffect, useMemo, useState } from "react";
import { createPortal } from "react-dom";
import { Head, usePage } from "@inertiajs/react";
import Swal from "sweetalert2";
import AppLayout_shopOwner from "../../../layout/AppLayout_shopOwner";

type ChangeStatus = "pending" | "approved" | "rejected" | "applied" | "cancelled";
type ChangeType = "new_hire_rate_setup" | "minor_adjustment" | "major_adjustment" | "correction";

interface SalaryAdjustment {
	id: number;
	employee_id: number;
	employee?: { id: number; name: string; department?: string; position?: string };
	proposed_by: number;
	proposer?: { id: number; name: string };
	approver?: { id: number; name: string } | null;
	rejector?: { id: number; name: string } | null;
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

const statusPill: Record<ChangeStatus, string> = {
	pending: "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300",
	approved: "bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300",
	applied: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300",
	rejected: "bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300",
	cancelled: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
};

const changeTypeLabel: Record<ChangeType, string> = {
	new_hire_rate_setup: "New Hire Setup",
	minor_adjustment: "Minor Adj. (<=5%)",
	major_adjustment: "Major Adj. (>5%)",
	correction: "Correction",
};

const changeTypePill: Record<ChangeType, string> = {
	new_hire_rate_setup: "bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300",
	minor_adjustment: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
	major_adjustment: "bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300",
	correction: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
};

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

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
		<path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
	</svg>
);

const MetricCard: React.FC<MetricCardProps> = ({ title, value, icon: Icon, color, description }) => {
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
					<h3 className="text-3xl font-bold text-gray-900 dark:text-white">{value.toLocaleString()}</h3>
					{description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
				</div>
			</div>
		</div>
	);
};

const toNumber = (val: unknown): number => {
	if (typeof val === "number") return Number.isFinite(val) ? val : 0;
	if (typeof val === "string") {
		const n = Number(val);
		return Number.isFinite(n) ? n : 0;
	}
	return 0;
};

const fmtCurrency = (val: unknown) =>
	new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(toNumber(val));

const fmtDate = (str?: string | null) => {
	if (!str) return "-";
	return new Date(str).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
};

const fmtPct = (val: unknown) => {
	const n = toNumber(val);
	return `${n >= 0 ? "+" : ""}${n.toFixed(2)}%`;
};

const normalizeAdjustment = (item: SalaryAdjustment): SalaryAdjustment => ({
	...item,
	previous_salary: toNumber(item.previous_salary),
	new_salary: toNumber(item.new_salary),
	change_percent: toNumber(item.change_percent),
});

const getInitials = (name?: string | null) => {
	if (!name) return "--";
	const parts = name.trim().split(/\s+/).filter(Boolean);
	if (!parts.length) return "--";
	if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
	return `${parts[0][0] ?? ""}${parts[1][0] ?? ""}`.toUpperCase();
};

const getCsrfToken = (): string =>
	(document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? "";

const SalaryAdjustmentApprovalPage: React.FC = () => {
	const { auth } = usePage().props as any;
	const permissions: string[] = auth?.permissions ?? [];
	const canApprove = permissions.includes("approve-salary-change") || Boolean(auth?.shop_owner);

	const [adjustments, setAdjustments] = useState<SalaryAdjustment[]>([]);
	const [summary, setSummary] = useState<Summary>({ pending: 0, approved: 0, applied: 0, rejected: 0, cancelled: 0 });
	const [loading, setLoading] = useState(true);
	const [statusFilter, setStatusFilter] = useState<"All" | ChangeStatus>("All");
	const [search, setSearch] = useState("");
	const [viewAdjustment, setViewAdjustment] = useState<SalaryAdjustment | null>(null);

	const fetchAdjustments = async () => {
		try {
			const params = new URLSearchParams();
			if (statusFilter !== "All") params.set("status", statusFilter);

			const res = await fetch(`/api/shop-owner/salary-changes?${params.toString()}`, {
				headers: { Accept: "application/json", "X-CSRF-TOKEN": getCsrfToken() },
				credentials: "include",
			});

			if (!res.ok) throw new Error(await res.text());
			const data = await res.json();
			const rows: SalaryAdjustment[] = data.data?.data ?? data.data ?? [];
			setAdjustments(rows.map(normalizeAdjustment));
			setSummary(data.summary ?? { pending: 0, approved: 0, applied: 0, rejected: 0, cancelled: 0 });
		} catch (err) {
			console.error("Error fetching salary adjustments:", err);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchAdjustments();
	}, [statusFilter]);

	const filtered = useMemo(() => {
		const q = search.trim().toLowerCase();
		if (!q) return adjustments;
		return adjustments.filter((item) => {
			const name = item.employee?.name?.toLowerCase() ?? "";
			const dept = item.employee?.department?.toLowerCase() ?? "";
			return name.includes(q) || dept.includes(q);
		});
	}, [adjustments, search]);

	const handleApprove = async (adjustment: SalaryAdjustment) => {
		const result = await Swal.fire({
			title: "Approve Salary Adjustment?",
			html: `
				<p style="margin-bottom:12px;">Approve salary adjustment for <strong>${adjustment.employee?.name ?? "Employee"}</strong>?</p>
				<p style="margin-bottom:12px;">${fmtCurrency(adjustment.previous_salary)} -> <strong>${fmtCurrency(adjustment.new_salary)}</strong> (${fmtPct(adjustment.change_percent)})</p>
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
			const res = await fetch(`/api/shop-owner/salary-changes/${adjustment.id}/approve`, {
				method: "POST",
				headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": getCsrfToken() },
				credentials: "include",
				body: JSON.stringify(result.value),
			});

			const data = await res.json();
			if (!res.ok) {
				Swal.fire("Error", data.message ?? "Approval failed.", "error");
				return;
			}

			Swal.fire("Approved", data.message ?? "Salary adjustment approved successfully.", "success");
			setViewAdjustment(null);
			fetchAdjustments();
		} catch {
			Swal.fire("Error", "A network error occurred.", "error");
		}
	};

	const handleReject = async (adjustment: SalaryAdjustment) => {
		const result = await Swal.fire({
			title: "Reject Salary Adjustment",
			html: `
				<p style="margin-bottom:12px;">Reject salary adjustment for <strong>${adjustment.employee?.name ?? "Employee"}</strong>?</p>
				<label style="font-weight:600;display:block;margin-bottom:4px;text-align:left;">Rejection Reason *</label>
				<textarea id="reject-notes" class="swal2-textarea" placeholder="Required: why is this being rejected?" style="width:100%;height:80px;margin:0;"></textarea>
			`,
			showCancelButton: true,
			confirmButtonText: "Reject",
			confirmButtonColor: "#ef4444",
			preConfirm: () => {
				const notes = (document.getElementById("reject-notes") as HTMLTextAreaElement).value.trim();
				if (!notes) {
					Swal.showValidationMessage("Rejection reason is required.");
					return false;
				}
				return { notes };
			},
		});

		if (!result.isConfirmed) return;

		try {
			const res = await fetch(`/api/shop-owner/salary-changes/${adjustment.id}/reject`, {
				method: "POST",
				headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": getCsrfToken() },
				credentials: "include",
				body: JSON.stringify(result.value),
			});

			const data = await res.json();
			if (!res.ok) {
				Swal.fire("Error", data.message ?? "Rejection failed.", "error");
				return;
			}

			Swal.fire("Rejected", "Salary adjustment has been rejected.", "success");
			setViewAdjustment(null);
			fetchAdjustments();
		} catch {
			Swal.fire("Error", "A network error occurred.", "error");
		}
	};

	const stats = {
		total: summary.pending + summary.approved + summary.applied + summary.rejected + summary.cancelled,
		pending: summary.pending,
		approved: summary.approved,
		applied: summary.applied,
		rejected: summary.rejected,
		cancelled: summary.cancelled,
	};

	const ViewModal: React.FC<{ adjustment: SalaryAdjustment }> = ({ adjustment }) => (
		<ModalPortal>
			<div className="fixed inset-0 z-9999 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
				<div className="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
					<div className="sticky top-0 z-10 flex items-center justify-between rounded-t-2xl border-b border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
						<div>
							<h2 className="text-xl font-bold text-gray-900 dark:text-white">Salary Adjustment #{adjustment.id}</h2>
							<p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{adjustment.employee?.name} • {fmtDate(adjustment.effective_date)}</p>
						</div>
						<button
							onClick={() => setViewAdjustment(null)}
							aria-label="Close"
							className="rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-300"
						>
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
								<path d="M18 6L6 18M6 6l12 12" />
							</svg>
						</button>
					</div>

					<div className="space-y-6 p-6">
						<div className="grid grid-cols-3 gap-4 rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
							<div className="text-center">
								<p className="mb-1 text-xs text-gray-500 dark:text-gray-400">Previous Salary</p>
								<p className="text-lg font-bold text-gray-700 dark:text-gray-200">{fmtCurrency(adjustment.previous_salary)}</p>
							</div>
							<div className="flex flex-col items-center justify-center text-center">
								<span className={`rounded-full px-2 py-0.5 text-sm font-bold ${adjustment.change_percent >= 0 ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300" : "bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300"}`}>
									{fmtPct(adjustment.change_percent)}
								</span>
							</div>
							<div className="text-center">
								<p className="mb-1 text-xs text-gray-500 dark:text-gray-400">New Salary</p>
								<p className="text-lg font-bold text-emerald-600 dark:text-emerald-400">{fmtCurrency(adjustment.new_salary)}</p>
							</div>
						</div>

						<div className="grid grid-cols-2 gap-4 text-sm">
							<div>
								<p className="text-gray-500 dark:text-gray-400">Status</p>
								<span className={`mt-1 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ${statusPill[adjustment.status]}`}>
									{adjustment.status.charAt(0).toUpperCase() + adjustment.status.slice(1)}
								</span>
							</div>
							<div>
								<p className="text-gray-500 dark:text-gray-400">Adjustment Type</p>
								<span className={`mt-1 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ${changeTypePill[adjustment.change_type]}`}>
									{changeTypeLabel[adjustment.change_type]}
								</span>
							</div>
							<div>
								<p className="text-gray-500 dark:text-gray-400">Effective Date</p>
								<p className="mt-0.5 font-medium text-gray-900 dark:text-white">{fmtDate(adjustment.effective_date)}</p>
							</div>
							<div>
								<p className="text-gray-500 dark:text-gray-400">Proposed By</p>
								<p className="mt-0.5 font-medium text-gray-900 dark:text-white">{adjustment.proposer?.name ?? "-"}</p>
							</div>
						</div>

						<div>
							<p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Reason</p>
							<p className="rounded-lg bg-gray-50 p-3 text-sm text-gray-800 dark:bg-gray-800/50 dark:text-gray-200">{adjustment.reason}</p>
						</div>

						<div className="flex flex-wrap gap-2 border-t border-gray-200 pt-2 dark:border-gray-800">
							{canApprove && adjustment.status === "pending" && (
								<>
									<button
										onClick={() => handleApprove(adjustment)}
										className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-emerald-700"
									>
										Approve
									</button>
									<button
										onClick={() => handleReject(adjustment)}
										className="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-rose-700"
									>
										Reject
									</button>
								</>
							)}

							{canApprove && adjustment.status === "approved" && !adjustment.applied_at && (
								<p className="rounded-lg bg-blue-50 px-3 py-2 text-xs font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
									Awaiting HR finalization
								</p>
							)}

							<button
								onClick={() => setViewAdjustment(null)}
								className="ml-auto rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
							>
								Close
							</button>
						</div>
					</div>
				</div>
			</div>
		</ModalPortal>
	);

	return (
		<AppLayout_shopOwner>
			<Head title="Salary Adjustment Approval - Solespace" />

			<div className="space-y-6">
				<div>
					<h1 className="text-3xl font-bold text-gray-900 dark:text-white">Salary Adjustment Approval</h1>
					<p className="mt-2 text-gray-600 dark:text-gray-400">Review and approve employee salary adjustments. HR finalizes approved requests.</p>
				</div>

				<div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-5">
					<MetricCard title="Total Requests" value={stats.total} icon={SparklesIcon} color="info" description="All salary adjustment records" />
					<MetricCard title="Pending" value={stats.pending} icon={ClockIcon} color="warning" description="Awaiting approval" />
					<MetricCard title="Approved" value={stats.approved} icon={CheckCircleIcon} color="info" description="Approved by owner; pending HR finalization" />
					<MetricCard title="Applied" value={stats.applied} icon={CheckCircleIcon} color="success" description="Finalized by HR" />
					<MetricCard title="Rejected / Cancelled" value={stats.rejected + stats.cancelled} icon={XCircleIcon} color="error" description="Closed without apply" />
				</div>

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

				<div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-white/3">
					{loading ? (
						<div className="py-24 text-center text-gray-400 dark:text-gray-600">
							<svg className="mx-auto mb-3 h-8 w-8 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
								<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
								<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
							</svg>
							Loading salary adjustments...
						</div>
					) : filtered.length === 0 ? (
						<div className="py-24 text-center">
							<p className="text-sm text-gray-400 dark:text-gray-600">No salary adjustments found.</p>
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
										<th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Type</th>
										<th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Effective</th>
										<th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
										<th className="px-6 py-4 text-center text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
									{filtered.map((adjustment) => (
										<tr key={adjustment.id} className="transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/50">
											<td className="px-6 py-4">
												<div className="flex items-center space-x-3">
													<div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40">
														<span className="text-sm font-medium text-blue-700 dark:text-blue-300">{getInitials(adjustment.employee?.name)}</span>
													</div>
													<div>
														<p className="font-medium text-gray-900 dark:text-white">{adjustment.employee?.name ?? `#${adjustment.employee_id}`}</p>
														<p className="text-xs text-gray-500 dark:text-gray-400">{adjustment.employee?.department ?? "No department"}</p>
													</div>
												</div>
											</td>
											<td className="px-6 py-4 font-mono text-sm text-gray-700 dark:text-gray-300">{fmtCurrency(adjustment.previous_salary)}</td>
											<td className="px-6 py-4 font-mono text-sm font-medium text-gray-900 dark:text-white">{fmtCurrency(adjustment.new_salary)}</td>
											<td className="px-6 py-4">
												<span className={`font-medium ${adjustment.change_percent >= 0 ? "text-emerald-600 dark:text-emerald-400" : "text-rose-600 dark:text-rose-400"}`}>
													{fmtPct(adjustment.change_percent)}
												</span>
											</td>
											<td className="px-6 py-4">
												<span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${changeTypePill[adjustment.change_type]}`}>
													{changeTypeLabel[adjustment.change_type]}
												</span>
											</td>
											<td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{fmtDate(adjustment.effective_date)}</td>
											<td className="px-6 py-4">
												<span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${statusPill[adjustment.status]}`}>
													{adjustment.status.charAt(0).toUpperCase() + adjustment.status.slice(1)}
												</span>
											</td>
											<td className="px-6 py-4 text-center">
												<div className="flex items-center justify-center gap-2">
													<button
														onClick={() => setViewAdjustment(adjustment)}
														className="rounded-lg p-2 transition-colors hover:bg-blue-50 dark:hover:bg-blue-900/20"
														title="View details"
													>
														<EyeIcon className="size-5 text-blue-600 dark:text-blue-400" />
													</button>

													{canApprove && adjustment.status === "pending" && (
														<button
															onClick={() => handleApprove(adjustment)}
															className="rounded-lg p-2 transition-colors hover:bg-green-50 dark:hover:bg-green-900/20"
															title="Approve"
														>
															<CheckCircleIcon className="size-5 text-green-600 dark:text-green-400" />
														</button>
													)}

													{canApprove && adjustment.status === "approved" && !adjustment.applied_at && (
														<span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
															HR Finalization
														</span>
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

				{viewAdjustment && <ViewModal adjustment={viewAdjustment} />}
			</div>
		</AppLayout_shopOwner>
	);
};

export default SalaryAdjustmentApprovalPage;
