import { Head } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type RequestStatus = "Pending" | "Approved" | "Rejected" | "Needs Details";
type Priority = "High" | "Medium" | "Low";
type MetricColor = "success" | "warning" | "info";

interface MaterialItem {
	id: number;
	name: string;
	sku: string;
	category: string;
	availableStock: number;
	unit: string;
}

interface MaterialRequest {
	id: number;
	requestNumber: string;
	materialId: number;
	materialName: string;
	sku: string;
	quantity: number;
	size: string;
	priority: Priority;
	status: RequestStatus;
	notes: string;
	requestedAt: string;
}

interface NewRequestForm {
	materialId: string;
	quantity: string;
	size: string;
	priority: Priority;
	notes: string;
}

const materials: MaterialItem[] = [
	{ id: 1, name: "Leather Sole Sheet", sku: "MAT-LTH-001", category: "Soles", availableStock: 68, unit: "sheet" },
	{ id: 2, name: "Rubber Heel Tip", sku: "MAT-RHB-014", category: "Heels", availableStock: 22, unit: "pair" },
	{ id: 3, name: "Nylon Stitch Thread", sku: "MAT-THR-009", category: "Thread", availableStock: 180, unit: "roll" },
	{ id: 4, name: "Contact Adhesive", sku: "MAT-ADH-021", category: "Adhesive", availableStock: 40, unit: "can" },
	{ id: 5, name: "Insole Foam", sku: "MAT-INS-031", category: "Insole", availableStock: 15, unit: "piece" },
];

const initialRequests: MaterialRequest[] = [
	{
		id: 1,
		requestNumber: "RM-2026-0313-001",
		materialId: 1,
		materialName: "Leather Sole Sheet",
		sku: "MAT-LTH-001",
		quantity: 6,
		size: "EU 41",
		priority: "High",
		status: "Pending",
		notes: "For outsole replacement of 3 incoming repair jobs.",
		requestedAt: "2026-03-13 09:12 AM",
	},
	{
		id: 2,
		requestNumber: "RM-2026-0312-014",
		materialId: 4,
		materialName: "Contact Adhesive",
		sku: "MAT-ADH-021",
		quantity: 3,
		size: "N/A",
		priority: "Medium",
		status: "Approved",
		notes: "Daily adhesive consumption for patching and resealing.",
		requestedAt: "2026-03-12 04:42 PM",
	},
	{
		id: 3,
		requestNumber: "RM-2026-0311-009",
		materialId: 5,
		materialName: "Insole Foam",
		sku: "MAT-INS-031",
		quantity: 10,
		size: "Cut-to-fit",
		priority: "Low",
		status: "Needs Details",
		notes: "Requested for comfort restoration jobs.",
		requestedAt: "2026-03-11 02:05 PM",
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

export default function RequestMaterials() {
	const [requests, setRequests] = useState<MaterialRequest[]>(initialRequests);
	const [searchQuery, setSearchQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState<"All" | RequestStatus>("All");
	const [currentPage, setCurrentPage] = useState(1);
	const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
	const [formData, setFormData] = useState<NewRequestForm>({
		materialId: "",
		quantity: "",
		size: "",
		priority: "Medium",
		notes: "",
	});

	const selectedMaterial = useMemo(
		() => materials.find((material) => String(material.id) === formData.materialId) ?? null,
		[formData.materialId],
	);

	const filteredRequests = useMemo(() => {
		const query = searchQuery.trim().toLowerCase();
		return requests.filter((request) => {
			const matchesQuery = !query ||
				request.requestNumber.toLowerCase().includes(query) ||
				request.materialName.toLowerCase().includes(query) ||
				request.sku.toLowerCase().includes(query) ||
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

	const handleSubmitRequest = () => {
		if (!selectedMaterial || !formData.quantity || !formData.notes.trim()) {
			return;
		}

		const quantity = Number(formData.quantity);
		if (!Number.isFinite(quantity) || quantity <= 0) {
			return;
		}

		const newId = requests.length > 0 ? Math.max(...requests.map((item) => item.id)) + 1 : 1;
		const nextRequest: MaterialRequest = {
			id: newId,
			requestNumber: `RM-2026-0313-${String(newId).padStart(3, "0")}`,
			materialId: selectedMaterial.id,
			materialName: selectedMaterial.name,
			sku: selectedMaterial.sku,
			quantity,
			size: formData.size.trim() || "N/A",
			priority: formData.priority,
			status: "Pending",
			notes: formData.notes.trim(),
			requestedAt: "2026-03-13 10:20 PM",
		};

		setRequests((prev) => [nextRequest, ...prev]);
		setFormData({ materialId: "", quantity: "", size: "", priority: "Medium", notes: "" });
		setCurrentPage(1);
		setIsCreateModalOpen(false);
	};

	return (
		<AppLayoutERP hideHeader={isCreateModalOpen}>
			<Head title="Request Material - Repair - Solespace" />

			{isCreateModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Request Material</h1>
						<p className="text-gray-600 dark:text-gray-400">Request repair materials from Inventory and monitor their approval status</p>
					</div>
					<button
						onClick={() => setIsCreateModalOpen(true)}
						className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors w-fit"
					>
						+ New Material Request
					</button>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Requests" value={totalRequests} description="All material requests submitted" icon={ClipboardIcon} color="info" />
					<MetricCard title="Pending Approval" value={pendingRequests} description="Waiting for inventory decision" icon={ClockIcon} color="warning" />
					<MetricCard title="Approved Requests" value={approvedRequests} description="Ready to allocate for repairs" icon={CheckCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Material Request Table</h2>
						<p className="text-sm text-gray-500">Track request progress and inventory responses</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by request no, material, SKU, or status..."
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
								title="Filter by request status"
								aria-label="Filter by request status"
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
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Requested at</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
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
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{request.requestedAt}</td>
											<td className="px-4 py-3">
												<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
													{request.status}
												</span>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">
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

			{isCreateModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<div className="absolute inset-0 bg-black/40" onClick={() => setIsCreateModalOpen(false)} />
					<div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 p-6 shadow-2xl">
						<div className="mb-4">
							<h3 className="text-xl font-semibold text-gray-900 dark:text-white">Create Material Request</h3>
							<p className="text-sm text-gray-500">Fill in required details so Inventory can review your request quickly.</p>
						</div>

						<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
							<div className="md:col-span-2">
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Material</label>
								<select
									value={formData.materialId}
									onChange={(event) => setFormData((prev) => ({ ...prev, materialId: event.target.value }))}
									title="Select material"
									aria-label="Select material"
									className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
								>
									<option value="">Select material</option>
									{materials.map((material) => (
										<option key={material.id} value={material.id}>
											{material.name} ({material.sku})
										</option>
									))}
								</select>
								{selectedMaterial && (
									<p className="mt-1 text-xs text-gray-500">Available stock: {selectedMaterial.availableStock} {selectedMaterial.unit}(s)</p>
								)}
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
								<input
									type="number"
									min={1}
									value={formData.quantity}
									onChange={(event) => setFormData((prev) => ({ ...prev, quantity: event.target.value }))}
									className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
									placeholder="Enter quantity"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Priority</label>
								<select
									value={formData.priority}
									onChange={(event) => setFormData((prev) => ({ ...prev, priority: event.target.value as Priority }))}
									title="Select priority"
									aria-label="Select priority"
									className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
								>
									<option value="High">High</option>
									<option value="Medium">Medium</option>
									<option value="Low">Low</option>
								</select>
							</div>

							<div className="md:col-span-2">
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shoe Size / Variant</label>
								<input
									type="text"
									value={formData.size}
									onChange={(event) => setFormData((prev) => ({ ...prev, size: event.target.value }))}
									className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
									placeholder="Example: EU 42, Brown variant"
								/>
							</div>

							<div className="md:col-span-2">
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Repair Notes</label>
								<textarea
									value={formData.notes}
									onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
									rows={4}
									className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
									placeholder="Explain why this material is needed for current shoe repair tasks"
								/>
							</div>
						</div>

						<div className="mt-6 flex items-center justify-end gap-3">
							<button
								onClick={() => setIsCreateModalOpen(false)}
								className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300"
							>
								Cancel
							</button>
							<button
								onClick={handleSubmitRequest}
								className="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium"
							>
								Submit Request
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
