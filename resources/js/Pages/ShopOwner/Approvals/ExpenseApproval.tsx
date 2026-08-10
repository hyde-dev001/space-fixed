import { Head, usePage } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayout_shopOwner from "../../../layout/AppLayout_shopOwner";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type ExpenseStatus = "submitted" | "approved" | "rejected" | "draft" | "posted";

interface Expense {
	id: number;
	reference: string;
	date: string;
	category: string;
	description?: string;
	amount: number | string;
	tax_amount?: number | string;
	status: ExpenseStatus;
	approval_notes?: string | null;
	receipt_path?: string | null;
	receipt_original_name?: string | null;
}

type MetricColor = "success" | "warning" | "info";

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const currency = new Intl.NumberFormat("en-PH", {
	style: "currency",
	currency: "PHP",
	maximumFractionDigits: 2,
});

const dateFormatter = new Intl.DateTimeFormat("en-PH", {
	year: "numeric",
	month: "short",
	day: "2-digit",
});

const formatExpenseDate = (value?: string | null) => {
	if (!value) return "-";

	const normalizedValue = value.replace(/\.(\d{3})\d+Z$/, ".$1Z");
	const parsedDate = new Date(normalizedValue);

	if (Number.isNaN(parsedDate.getTime())) {
		return value;
	}

	return dateFormatter.format(parsedDate);
};

// ---- Icons ----------------------------------------------------------------

const WalletIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M21 12V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2v-5z" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M16 12h.01" />
	</svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<circle cx="12" cy="12" r="9" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M12 7v5l3 3" />
	</svg>
);

const CheckCircleIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<circle cx="12" cy="12" r="9" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4" />
	</svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
		<circle cx="12" cy="12" r="3" />
	</svg>
);

const ChevronLeftIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
	</svg>
);

const ChevronRightIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
	</svg>
);

// ---- MetricCard ------------------------------------------------------------

const MetricCard = ({ title, value, description, icon: Icon, color }: MetricCardProps) => {
	const gradient =
		color === "success"
			? "from-green-500 to-emerald-600"
			: color === "warning"
			? "from-yellow-500 to-orange-600"
			: "from-blue-500 to-indigo-600";

	return (
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03]">
			<div className={`absolute inset-0 bg-gradient-to-br ${gradient} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
			<div className="relative">
				<div className="flex items-center justify-between mb-4">
					<div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${gradient} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
						<Icon className="text-white size-7 drop-shadow-sm" />
					</div>
				</div>
				<div className="space-y-2">
					<p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
					<h3 className="text-3xl font-bold text-gray-900 dark:text-white">{value}</h3>
					<p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
				</div>
			</div>
		</div>
	);
};

// ---- Status badge ----------------------------------------------------------

const statusBadgeClass: Record<ExpenseStatus, string> = {
	submitted: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
	approved:  "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	rejected:  "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	draft:     "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
	posted:    "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300",
};

const statusLabel: Record<ExpenseStatus, string> = {
	submitted: "Pending Approval",
	approved:  "Approved",
	rejected:  "Rejected",
	draft:     "Draft",
	posted:    "Posted",
};

// ---- Main component --------------------------------------------------------

interface ExpenseApprovalProps {
	onModalStateChange?: (isOpen: boolean) => void;
}

export default function ExpenseApproval({ onModalStateChange }: ExpenseApprovalProps) {
	const erpMode = (usePage().props as any)?.erpMode === true;
	const Layout = erpMode ? AppLayoutERP : AppLayout_shopOwner;
	const [expenses, setExpenses] = useState<Expense[]>([]);
	const [loading, setLoading] = useState(true);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [viewing, setViewing] = useState<Expense | null>(null);
	const [statusFilter, setStatusFilter] = useState<ExpenseStatus | 'all'>('all');

	const fetchExpenses = async () => {
		try {
			setLoading(true);
			const response = await axios.get("/api/shop-owner/expenses", {
				params: { per_page: 100 },
			});
			const data = response.data?.data || response.data || [];
			setExpenses(Array.isArray(data) ? data : []);
		} catch (err) {
			console.error("Error fetching expenses:", err);
			setExpenses([]);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchExpenses();
	}, []);

	const filteredData = useMemo(() => {
		const q = searchQuery.trim().toLowerCase();
		return expenses.filter(
			(e) => {
				const matchesSearch = !q || e.category?.toLowerCase().includes(q) || (e.description ?? "").toLowerCase().includes(q);
				const matchesStatus = statusFilter === 'all' || e.status === statusFilter;
				return matchesSearch && matchesStatus;
			}
		);
	}, [searchQuery, statusFilter, expenses]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalCount = expenses.length;
	const pendingCount = expenses.filter((e) => e.status === "submitted").length;
	const approvedCount = expenses.filter((e) => e.status === "approved").length;

	// ---- Approve ----

	const handleApprove = async (expense: Expense) => {
		setViewing(null);

		const result = await Swal.fire({
			title: "Approve this expense?",
			text: `${expense.category} — ${currency.format(Number(expense.amount))}`,
			input: "textarea",
			inputLabel: "Approval notes (optional)",
			inputPlaceholder: "Add any notes...",
			showCancelButton: true,
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#16a34a",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		try {
			await axios.post(`/api/shop-owner/expenses/${expense.id}/approve`, {
				approval_notes: result.value || undefined,
			});
			await fetchExpenses();
			await Swal.fire({
				icon: "success",
				title: "Approved",
				text: `Expense "${expense.category}" has been approved.`,
				timer: 1600,
				showConfirmButton: false,
			});
		} catch (err: any) {
			await Swal.fire({
				icon: "error",
				title: "Error",
				text: err.response?.data?.message || "Failed to approve expense.",
				confirmButtonColor: "#2563eb",
			});
		}
	};

	// ---- Reject ----

	const handleReject = async (expense: Expense) => {
		setViewing(null);

		const result = await Swal.fire({
			title: "Reject this expense?",
			text: `Please provide a reason for rejecting "${expense.category}".`,
			icon: "warning",
			input: "textarea",
			inputLabel: "Rejection reason",
			inputPlaceholder: "Type reason here...",
			showCancelButton: true,
			confirmButtonText: "Reject",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#dc2626",
			cancelButtonColor: "#6b7280",
			inputValidator: (value) => (!value || !value.trim() ? "A rejection reason is required." : undefined),
		});

		if (!result.isConfirmed || !result.value) return;

		try {
			await axios.post(`/api/shop-owner/expenses/${expense.id}/reject`, {
				rejection_reason: String(result.value),
			});
			await fetchExpenses();
			await Swal.fire({
				icon: "success",
				title: "Rejected",
				text: `Expense "${expense.category}" was rejected.`,
				timer: 1600,
				showConfirmButton: false,
			});
		} catch (err: any) {
			await Swal.fire({
				icon: "error",
				title: "Error",
				text: err.response?.data?.message || "Failed to reject expense.",
				confirmButtonColor: "#2563eb",
			});
		}
	};

	const isAnyModalOpen = Boolean(viewing);

	useEffect(() => {
		onModalStateChange?.(isAnyModalOpen);
		return () => { onModalStateChange?.(false); };
	}, [isAnyModalOpen, onModalStateChange]);

	return (
		<Layout hideHeader={isAnyModalOpen}>
			<Head title="Expense Approvals - Solespace ERP" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				{/* Header */}
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Expense Approvals</h1>
						<p className="text-gray-600 dark:text-gray-400">
							Review expenses submitted by Finance before they are settled
						</p>
					</div>
					<span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 w-fit">
						Shop Owner Review
					</span>
				</div>

				{/* Metrics */}
				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Expenses" value={totalCount} description="All recorded expenses" icon={WalletIcon} color="info" />
					<MetricCard title="Pending Review" value={pendingCount} description="Awaiting your approval" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved" value={approvedCount} description="Expenses cleared" icon={CheckCircleIcon} color="success" />
				</div>

				{/* Table */}
				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
						<div>
							<h2 className="text-lg font-semibold">Expense Records</h2>
							<p className="text-sm text-gray-500">Review category, amount, and description before approving</p>
						</div>
						{/* Status Filter Dropdown */}
						<select
							value={statusFilter}
							onChange={(e) => {
								setStatusFilter(e.target.value as ExpenseStatus | 'all');
								setCurrentPage(1);
							}}
							className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm font-medium focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						>
							<option value="all">All Status</option>
							<option value="submitted">Pending Approval</option>
							<option value="approved">Approved</option>
							<option value="rejected">Rejected</option>
							<option value="draft">Draft</option>
							<option value="posted">Posted</option>
						</select>
					</div>

					<div className="mb-4">
						<input
							type="text"
							placeholder="Search by category or description..."
							value={searchQuery}
							onChange={(e) => { setSearchQuery(e.target.value); setCurrentPage(1); }}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						/>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Date</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Category</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Description</th>
									<th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Amount</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{loading ? (
									<tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">Loading...</td></tr>
								) : paginatedItems.length > 0 ? (
									paginatedItems.map((expense) => (
										<tr key={expense.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{formatExpenseDate(expense.date)}</td>
											<td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{expense.category}</td>
											<td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate">{expense.description || "—"}</td>
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white text-right whitespace-nowrap">
												{currency.format(Number(expense.amount))}
											</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[expense.status]}`}>
													{statusLabel[expense.status]}
												</span>
											</td>
											<td className="px-4 py-3 text-center">
												<div className="flex items-center justify-center gap-2">
													<button
														onClick={() => setViewing(expense)}
														className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
														title="View details"
													>
														<EyeIcon className="h-5 w-5 text-blue-600 dark:text-blue-400" />
													</button>
													{expense.status === "submitted" && (
														<>
															<button
																onClick={() => handleApprove(expense)}
																className="px-3 py-1 text-xs font-semibold rounded-lg bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 transition-colors"
															>
																Approve
															</button>
															<button
																onClick={() => handleReject(expense)}
																className="px-3 py-1 text-xs font-semibold rounded-lg bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50 transition-colors"
															>
																Reject
															</button>
														</>
													)}
												</div>
											</td>
										</tr>
									))
								) : (
									<tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">No expenses found.</td></tr>
								)}
							</tbody>
						</table>
					</div>

					{/* Pagination */}
					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {filteredData.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} records
						</p>
						<div className="flex gap-2">
							<button
								type="button"
								onClick={() => setCurrentPage((p) => Math.max(p - 1, 1))}
								disabled={currentPage === 1}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Previous page"
							>
								<ChevronLeftIcon className="w-5 h-5" />
							</button>
							<button
								type="button"
								onClick={() => setCurrentPage((p) => Math.min(p + 1, totalPages))}
								disabled={currentPage === totalPages}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Next page"
							>
								<ChevronRightIcon className="w-5 h-5" />
							</button>
						</div>
					</div>
				</div>
			</div>

			{/* Detail modal */}
			{viewing && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
					<div className="w-full max-w-md rounded-2xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
						<div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-800">
							<div>
								<p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Expense Detail</p>
								<h4 className="text-lg font-semibold text-gray-900 dark:text-white">{viewing.category}</h4>
							</div>
							<button
								onClick={() => setViewing(null)}
								className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 text-xl leading-none"
								aria-label="Close"
							>
								✕
							</button>
						</div>

						<div className="px-6 py-4 space-y-3">
							{[
								["Date", formatExpenseDate(viewing.date)],
								["Amount", currency.format(Number(viewing.amount))],
								["Tax", viewing.tax_amount ? currency.format(Number(viewing.tax_amount)) : "–"],
								["Description", viewing.description || "–"],
							].map(([label, value]) => (
								<div key={label} className="flex justify-between text-sm text-gray-700 dark:text-gray-300">
									<span className="text-gray-500 dark:text-gray-400">{label}</span>
									<span className="font-medium text-right max-w-[60%]">{value}</span>
								</div>
							))}
							<div className="flex justify-between text-sm items-center">
								<span className="text-gray-500 dark:text-gray-400">Status</span>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewing.status]}`}>
									{statusLabel[viewing.status]}
								</span>
							</div>
							{viewing.receipt_path && (
								<div className="flex items-center justify-between text-sm pt-1">
									<span className="text-gray-500 dark:text-gray-400">Receipt</span>
									<a
										href={`/api/finance/session/expenses/${viewing.id}/receipt/download`}
										target="_blank"
										rel="noreferrer"
										className="px-3 py-1 text-xs bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors"
									>
										Download
									</a>
								</div>
							)}
						</div>

						<div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800">
							<button
								onClick={() => setViewing(null)}
								className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-sm"
							>
								Close
							</button>
							{viewing.status === "submitted" && (
								<>
									<button
										onClick={() => handleApprove(viewing)}
										className="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition-colors text-sm"
									>
										Approve
									</button>
									<button
										onClick={() => handleReject(viewing)}
										className="px-4 py-2 rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition-colors text-sm"
									>
										Reject
									</button>
								</>
							)}
						</div>
					</div>
				</div>
			)}
		</Layout>
	);
}
