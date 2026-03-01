import { Head } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type RequestPriority = "High" | "Medium" | "Low";
type RequestStatus = "Pending" | "Approved" | "Rejected" | "Needs Details";
type MetricColor = "success" | "warning" | "info";

interface StockRequestItem {
	id: number;
	requestNo: string;
	productName: string;
	skuCode: string;
	quantityNeeded: number;
	priority: RequestPriority;
	neededDate: string;
	requestedDate: string;
	status: RequestStatus;
	notes: string;
}

interface RequestFormState {
	productName: string;
	skuCode: string;
	quantityNeeded: string;
	priority: RequestPriority;
	neededDate: string;
	notes: string;
}

const stockRequestRows: StockRequestItem[] = [
	{
		id: 1,
		requestNo: "SR-2026-001",
		productName: "Nike Air Max 270",
		skuCode: "NK-AM270-BLK",
		quantityNeeded: 40,
		priority: "High",
		neededDate: "2026-03-05",
		requestedDate: "2026-02-28 08:45 AM",
		status: "Pending",
		notes: "Out of stock in sizes 8 to 10.",
	},
	{
		id: 2,
		requestNo: "SR-2026-002",
		productName: "Adidas Ultraboost 22",
		skuCode: "AD-UB22-WHT",
		quantityNeeded: 25,
		priority: "High",
		neededDate: "2026-03-06",
		requestedDate: "2026-02-28 09:15 AM",
		status: "Approved",
		notes: "For weekend demand and promo displays.",
	},
	{
		id: 3,
		requestNo: "SR-2026-003",
		productName: "Cleaning Foam",
		skuCode: "CARE-FOAM-CLN",
		quantityNeeded: 50,
		priority: "Low",
		neededDate: "2026-03-09",
		requestedDate: "2026-02-27 01:10 PM",
		status: "Needs Details",
		notes: "Procurement asked for branch-level quantity breakdown.",
	},
	{
		id: 4,
		requestNo: "SR-2026-004",
		productName: "Shoe Box (Large)",
		skuCode: "PKG-BOX-L",
		quantityNeeded: 60,
		priority: "Medium",
		neededDate: "2026-03-08",
		requestedDate: "2026-02-27 03:30 PM",
		status: "Rejected",
		notes: "Current packaging stock is enough this week.",
	},
];

const initialFormState: RequestFormState = {
	productName: "",
	skuCode: "",
	quantityNeeded: "",
	priority: "Medium",
	neededDate: "",
	notes: "",
};

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

const priorityBadgeClass: Record<RequestPriority, string> = {
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

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

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

export default function StockRequest() {
	const [requests, setRequests] = useState<StockRequestItem[]>(stockRequestRows);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [formData, setFormData] = useState<RequestFormState>(initialFormState);
	const [viewingRequest, setViewingRequest] = useState<StockRequestItem | null>(null);

	const filteredData = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();

		if (!query) return requests;

		return requests.filter((request) =>
			request.requestNo.toLowerCase().includes(query) ||
			request.productName.toLowerCase().includes(query) ||
			request.skuCode.toLowerCase().includes(query) ||
			request.status.toLowerCase().includes(query)
		);
	}, [searchQuery, requests]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalRequests = requests.length;
	const pendingRequests = requests.filter((request) => request.status === "Pending").length;
	const approvedRequests = requests.filter((request) => request.status === "Approved").length;

	const handleCreateRequest = async () => {
		if (!formData.productName.trim() || !formData.skuCode.trim() || !formData.quantityNeeded.trim() || !formData.neededDate.trim() || !formData.notes.trim()) {
			await Swal.fire({
				icon: "warning",
				title: "Missing fields",
				text: "Please complete all fields before submitting.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const parsedQty = Number(formData.quantityNeeded);
		if (Number.isNaN(parsedQty) || parsedQty <= 0) {
			await Swal.fire({
				icon: "warning",
				title: "Invalid quantity",
				text: "Quantity needed must be greater than 0.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const nextId = Math.max(...requests.map((request) => request.id), 0) + 1;
		const nextRequestNo = `SR-2026-${String(nextId).padStart(3, "0")}`;

		const nextRequest: StockRequestItem = {
			id: nextId,
			requestNo: nextRequestNo,
			productName: formData.productName,
			skuCode: formData.skuCode,
			quantityNeeded: parsedQty,
			priority: formData.priority,
			neededDate: formData.neededDate,
			requestedDate: new Date().toLocaleString(),
			status: "Pending",
			notes: formData.notes,
		};

		setRequests((prev) => [nextRequest, ...prev]);
		setFormData(initialFormState);
		setIsCreateModalOpen(false);
		setCurrentPage(1);

		await Swal.fire({
			icon: "success",
			title: "Request submitted",
			text: "Stock request has been sent to Procurement for approval.",
			confirmButtonColor: "#2563eb",
			timer: 1500,
			showConfirmButton: false,
		});
	};

	const isAnyModalOpen = isCreateModalOpen || Boolean(viewingRequest);

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Stock Request - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Stock Request</h1>
						<p className="text-gray-600 dark:text-gray-400">Create and track stock requests to Procurement for out-of-stock items</p>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<button
							onClick={() => setIsCreateModalOpen(true)}
							className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
						>
							+ New Request
						</button>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests" value={totalRequests} description="All stock requests created" icon={ClipboardIcon} color="info" />
					<MetricCard title="Pending Approval" value={pendingRequests} description="Requests currently waiting review" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved Requests" value={approvedRequests} description="Requests approved for procurement" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Stock Request Table</h2>
						<p className="text-sm text-gray-500">View request status and track updates from Procurement</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by request no, product, SKU, or status..."
								value={searchQuery}
								onChange={(event) => {
									setSearchQuery(event.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Request no</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Needed date</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{paginatedItems.length > 0 ? (
									paginatedItems.map((request) => (
										<tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
											<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{request.requestNo}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
												<p className="font-medium text-gray-900 dark:text-white">{request.productName}</p>
												<p className="text-xs text-gray-500 dark:text-gray-400">{request.skuCode}</p>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.quantityNeeded}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[request.priority]}`}>
													{request.priority}
												</span>
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{request.neededDate}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
													{request.status}
												</span>
											</td>
											<td className="px-4 py-3 text-center">
												<button
													onClick={() => setViewingRequest(request)}
													className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
													title="View request details"
												>
													<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
														<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
														<circle cx="12" cy="12" r="3" />
													</svg>
												</button>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">No stock requests found.</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {filteredData.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredData.length)} of {filteredData.length} requests
						</p>
						<div className="flex gap-2">
							<button
								type="button"
								onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
								disabled={currentPage === 1}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Previous page"
							>
								<ChevronLeftIcon className="w-5 h-5" />
							</button>
							<button
								type="button"
								onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
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

			{isCreateModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close create request modal" className="absolute inset-0 bg-black/50" onClick={() => { setIsCreateModalOpen(false); setFormData(initialFormState); }} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Create Stock Request</h2>
							<button onClick={() => { setIsCreateModalOpen(false); setFormData(initialFormState); }} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Product Name *</label>
								<input
									type="text"
									value={formData.productName}
									onChange={(event) => setFormData((prev) => ({ ...prev, productName: event.target.value }))}
									placeholder="e.g., Nike Air Max 270"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">SKU Code *</label>
									<input
										type="text"
										value={formData.skuCode}
										onChange={(event) => setFormData((prev) => ({ ...prev, skuCode: event.target.value }))}
										placeholder="e.g., NK-AM270-BLK"
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Quantity Needed *</label>
									<input
										type="number"
										min={1}
										value={formData.quantityNeeded}
										onChange={(event) => setFormData((prev) => ({ ...prev, quantityNeeded: event.target.value }))}
										placeholder="e.g., 40"
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority *</label>
									<select
										title="Select request priority"
										aria-label="Select request priority"
										value={formData.priority}
										onChange={(event) => setFormData((prev) => ({ ...prev, priority: event.target.value as RequestPriority }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									>
										<option value="High">High</option>
										<option value="Medium">Medium</option>
										<option value="Low">Low</option>
									</select>
								</div>
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Needed Date *</label>
									<input
										type="date"
										title="Select needed date"
										aria-label="Select needed date"
										placeholder="Select needed date"
										value={formData.neededDate}
										onChange={(event) => setFormData((prev) => ({ ...prev, neededDate: event.target.value }))}
										className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
									/>
								</div>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes *</label>
								<textarea
									rows={3}
									value={formData.notes}
									onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
									placeholder="Add request reason, size breakdown, or branch allocation..."
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
								/>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button
								onClick={() => { setIsCreateModalOpen(false); setFormData(initialFormState); }}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleCreateRequest}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors"
							>
								Submit Request
							</button>
						</div>
					</div>
				</div>
			)}

			{viewingRequest && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close request details modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingRequest(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Stock Request Details</h2>
							<button onClick={() => setViewingRequest(null)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none">×</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Request No</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.requestNo}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[viewingRequest.status]}`}>
										{viewingRequest.status}
									</span>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.productName}</p>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">SKU: {viewingRequest.skuCode}</p>
							</div>

							<div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity Needed</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.quantityNeeded}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Priority</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[viewingRequest.priority]}`}>
										{viewingRequest.priority}
									</span>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Needed Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.neededDate}</p>
								</div>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Submitted On</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingRequest.requestedDate}</p>
							</div>

							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</p>
								<p className="text-base font-semibold text-gray-900 dark:text-white whitespace-pre-wrap">{viewingRequest.notes}</p>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button onClick={() => setViewingRequest(null)} className="w-full px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors">Close</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
