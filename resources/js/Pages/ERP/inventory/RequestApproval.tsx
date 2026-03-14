import { Head } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type RequestStatus = "Pending" | "Approved" | "Rejected" | "Needs Details";
type Priority = "High" | "Medium" | "Low";
type MetricColor = "success" | "warning" | "info";

interface MaterialApprovalRequest {
	id: number;
	requestNumber: string;
	requestedBy: string;
	role: string;
	materialName: string;
	sku: string;
	quantity: number;
	availableStock: number;
	size: string;
	priority: Priority;
	status: RequestStatus;
	notes: string;
	requestedAt: string;
	reviewedAt?: string;
}

const initialRequests: MaterialApprovalRequest[] = [
	{
		id: 1,
		requestNumber: "RM-2026-0313-001",
		requestedBy: "Thomas Rodriguez",
		role: "Repairer",
		materialName: "Leather Sole Sheet",
		sku: "MAT-LTH-001",
		quantity: 6,
		availableStock: 68,
		size: "EU 41",
		priority: "High",
		status: "Pending",
		notes: "For outsole replacement of 3 incoming repair jobs.",
		requestedAt: "2026-03-13 09:12 AM",
	},
	{
		id: 2,
		requestNumber: "RM-2026-0312-014",
		requestedBy: "Patricia Reyes",
		role: "Repairer",
		materialName: "Contact Adhesive",
		sku: "MAT-ADH-021",
		quantity: 3,
		availableStock: 40,
		size: "N/A",
		priority: "Medium",
		status: "Approved",
		notes: "Daily adhesive consumption for patching and resealing.",
		requestedAt: "2026-03-12 04:42 PM",
		reviewedAt: "2026-03-12 05:00 PM",
	},
	{
		id: 3,
		requestNumber: "RM-2026-0311-009",
		requestedBy: "John Cruz",
		role: "Repairer",
		materialName: "Insole Foam",
		sku: "MAT-INS-031",
		quantity: 24,
		availableStock: 15,
		size: "Cut-to-fit",
		priority: "Low",
		status: "Needs Details",
		notes: "Requested for comfort restoration jobs.",
		requestedAt: "2026-03-11 02:05 PM",
		reviewedAt: "2026-03-11 03:15 PM",
	},
];

const priorityBadgeClass: Record<Priority, string> = {
	High: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	Medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
	Low: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<RequestStatus, string> = {
	Pending: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
	Approved: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	Rejected: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
	"Needs Details": "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
};

const ClipboardIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
		<rect x="9" y="3" width="6" height="4" rx="1" />
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6M9 16h4" />
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

const EyeIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
	</svg>
);

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const MetricCard = ({ title, value, description, icon: Icon, color }: MetricCardProps) => {
	const getColorClasses = () => {
		switch (color) {
			case "success":
				return "from-green-500 to-emerald-600";
			case "warning":
				return "from-yellow-500 to-orange-600";
			case "info":
				return "from-blue-500 to-indigo-600";
			default:
				return "from-gray-500 to-gray-600";
		}
	};

	return (
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
			<div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
			<div className="relative">
				<div className="flex items-center justify-between mb-4">
					<div className={`flex items-center justify-center w-14 h-14 bg-linear-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
						<Icon className="text-white size-7 drop-shadow-sm" />
					</div>
				</div>
				<div className="space-y-2">
					<p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
					<h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">{value}</h3>
					<p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
				</div>
			</div>
		</div>
	);
};

export default function RequestApproval() {
	const [requests, setRequests] = useState<MaterialApprovalRequest[]>(initialRequests);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState<"All" | RequestStatus>("All");
	const [currentPage, setCurrentPage] = useState(1);
	const [selectedRequest, setSelectedRequest] = useState<MaterialApprovalRequest | null>(null);

	const filteredRequests = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		return requests.filter((request) => {
			const matchesQuery = !query ||
				request.requestNumber.toLowerCase().includes(query) ||
				request.materialName.toLowerCase().includes(query) ||
				request.sku.toLowerCase().includes(query) ||
				request.requestedBy.toLowerCase().includes(query) ||
				request.status.toLowerCase().includes(query);

			const matchesStatus = statusFilter === "All" || request.status === statusFilter;
			return matchesQuery && matchesStatus;
		});
	}, [requests, searchQuery, statusFilter]);

	const totalRequests = requests.length;
	const pendingRequests = requests.filter((request) => request.status === "Pending").length;
	const approvedRequests = requests.filter((request) => request.status === "Approved").length;

	const itemsPerPage = 7;
	const totalPages = Math.max(1, Math.ceil(filteredRequests.length / itemsPerPage));
	const currentSafePage = Math.min(currentPage, totalPages);
	const startIndex = (currentSafePage - 1) * itemsPerPage;
	const paginatedRequests = filteredRequests.slice(startIndex, startIndex + itemsPerPage);

	const updateStatus = (id: number, status: RequestStatus) => {
		setRequests((prev) => prev.map((request) => (
			request.id === id
				? { ...request, status, reviewedAt: "2026-03-13 10:25 PM" }
				: request
		)));
	};

	const isReviewModalOpen = Boolean(selectedRequest);

	return (
		<AppLayoutERP hideHeader={isReviewModalOpen}>
			<Head title="Request Material Approval - Inventory - Solespace" />

			{isReviewModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Request Material Approval</h1>
						<p className="text-gray-600 dark:text-gray-400">Review and approve material requests submitted by repair accounts</p>
					</div>
					<span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 w-fit">
						Repair to Inventory
					</span>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests" value={totalRequests} description="Material requests from repairers" icon={ClipboardIcon} color="info" />
					<MetricCard title="Pending Review" value={pendingRequests} description="Waiting for inventory action" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved Requests" value={approvedRequests} description="Allocated or ready for release" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Material Approval Queue</h2>
						<p className="text-sm text-gray-500">Inspect request details before approving or rejecting</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by request no, material, SKU, requester, or status..."
								value={searchQuery}
								onChange={(event) => {
									setSearchQuery(event.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
						<div className="sm:w-56">
							<select
								title="Filter by status"
								aria-label="Filter by status"
								value={statusFilter}
								onChange={(event) => {
									setStatusFilter(event.target.value as "All" | RequestStatus);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="All">All Status</option>
								<option value="Pending">Pending</option>
								<option value="Approved">Approved</option>
								<option value="Rejected">Rejected</option>
								<option value="Needs Details">Needs Details</option>
							</select>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Request no</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Material</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Requested by</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{paginatedRequests.length > 0 ? (
									paginatedRequests.map((request) => (
										<tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{request.requestNumber}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{request.materialName}</p>
												<p className="text-xs text-gray-500 dark:text-gray-400">{request.sku}</p>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.quantity}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[request.priority]}`}>
													{request.priority}
												</span>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{request.requestedBy}</p>
												<p className="text-xs text-gray-500 dark:text-gray-400">{request.role}</p>
											</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
													{request.status}
												</span>
											</td>
											<td className="px-4 py-3 text-center">
												<button
													onClick={() => setSelectedRequest(request)}
													className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 transition-colors"
													aria-label="View request details"
													title="View request details"
												>
													<EyeIcon className="w-5 h-5" />
												</button>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">
											No requests found for the selected filters.
										</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between border-t border-gray-200 dark:border-gray-800 pt-4">
						<p className="text-sm text-gray-500">
							Showing {filteredRequests.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredRequests.length)} of {filteredRequests.length} requests
						</p>
						<div className="flex items-center gap-2">
							<button
								onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
								disabled={currentSafePage === 1}
								aria-label="Previous page"
								title="Previous page"
								className="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 text-sm disabled:opacity-50"
							>
								<ChevronLeftIcon className="w-4 h-4" />
							</button>
							<span className="text-sm text-gray-600 dark:text-gray-300">Page {currentSafePage} of {totalPages}</span>
							<button
								onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
								disabled={currentSafePage === totalPages}
								aria-label="Next page"
								title="Next page"
								className="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 text-sm disabled:opacity-50"
							>
								<ChevronRightIcon className="w-4 h-4" />
							</button>
						</div>
					</div>
				</div>
			</div>

			{selectedRequest && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<div className="absolute inset-0 bg-black/40" onClick={() => setSelectedRequest(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 p-6 shadow-2xl">
						<div className="mb-4">
							<h3 className="text-xl font-semibold text-gray-900 dark:text-white">Review Material Request</h3>
							<p className="text-sm text-gray-500">Check stock sufficiency and decide request outcome.</p>
						</div>

						<div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
							<div>
								<p className="text-gray-500">Request no</p>
								<p className="font-semibold text-gray-900 dark:text-white">{selectedRequest.requestNumber}</p>
							</div>
							<div>
								<p className="text-gray-500">Requested by</p>
								<p className="font-semibold text-gray-900 dark:text-white">{selectedRequest.requestedBy}</p>
							</div>
							<div>
								<p className="text-gray-500">Material</p>
								<p className="font-semibold text-gray-900 dark:text-white">{selectedRequest.materialName}</p>
							</div>
							<div>
								<p className="text-gray-500">SKU</p>
								<p className="font-semibold text-gray-900 dark:text-white">{selectedRequest.sku}</p>
							</div>
							<div>
								<p className="text-gray-500">Requested quantity</p>
								<p className="font-semibold text-gray-900 dark:text-white">{selectedRequest.quantity}</p>
							</div>
							<div>
								<p className="text-gray-500">Available stock</p>
								<p className={`font-semibold ${selectedRequest.availableStock < selectedRequest.quantity ? "text-red-600 dark:text-red-400" : "text-gray-900 dark:text-white"}`}>
									{selectedRequest.availableStock}
								</p>
							</div>
							<div>
								<p className="text-gray-500">Priority</p>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[selectedRequest.priority]}`}>
									{selectedRequest.priority}
								</span>
							</div>
							<div>
								<p className="text-gray-500">Status</p>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[selectedRequest.status]}`}>
									{selectedRequest.status}
								</span>
							</div>
							<div className="md:col-span-2">
								<p className="text-gray-500 mb-1">Repair notes</p>
								<p className="text-gray-800 dark:text-gray-200 rounded-lg border border-gray-200 dark:border-gray-700 p-3 bg-gray-50 dark:bg-gray-800/50">
									{selectedRequest.notes}
								</p>
							</div>
						</div>

						<div className="mt-6 flex items-center justify-end gap-3">
							<button
								onClick={() => setSelectedRequest(null)}
								className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300"
							>
								Close
							</button>
							<button
								onClick={() => {
									updateStatus(selectedRequest.id, "Rejected");
									setSelectedRequest(null);
								}}
								className="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium"
							>
								Reject
							</button>
							<button
								onClick={() => {
									updateStatus(selectedRequest.id, "Approved");
									setSelectedRequest(null);
								}}
								className="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium"
							>
								Approve
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
