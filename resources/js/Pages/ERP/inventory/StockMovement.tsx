import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import type { StockMovement as ApiStockMovement } from "@/types/inventory";

type MovementTrack = "Stock IN" | "Stock OUT" | "Adjustments" | "Returns" | "Repairs usage";

interface StockMovementItem {
	id: number;
	date: string;
	time: string;
	product: string;
	actionType: MovementTrack;
	quantityChange: number;
	updatedBy: string;
}

const movementTypeMap: Record<string, MovementTrack> = {
	stock_in: "Stock IN",
	stock_out: "Stock OUT",
	adjustment: "Adjustments",
	return: "Returns",
	repair_usage: "Repairs usage",
	transfer: "Stock OUT",
	damage: "Stock OUT",
	initial: "Stock IN",
};

const mapApiMovement = (m: ApiStockMovement): StockMovementItem => {
	const dt = new Date(m.performed_at);
	const date = dt.toISOString().split("T")[0];
	const time = dt.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" });
	return {
		id: m.id,
		date,
		time,
		product: m.inventory_item?.name ?? m.product?.name ?? "Unknown",
		actionType: movementTypeMap[m.movement_type] ?? "Adjustments",
		quantityChange: m.quantity_change,
		updatedBy: m.performer?.name ?? "System",
	};
};

type MetricColor = "success" | "warning" | "info";

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const ArrowUpIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
	</svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
	</svg>
);

const WrenchIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 11-7.94 7.94l-6.91 6.91a2.12 2.12 0 11-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.77 3.77z" />
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
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
			<div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
			<div className="relative">
				<div className="flex items-center justify-between mb-4">
					<div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
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

const formatQuantity = (quantity: number) => {
	if (quantity > 0) return `+${quantity}`;
	return `${quantity}`;
};

export default function StockMovement() {
	const { initialData } = usePage().props as any;
	const [movements, setMovements] = useState<StockMovementItem[]>(
		() => (initialData?.data ?? []).map(mapApiMovement)
	);
	const [loading, setLoading] = useState(false);
	const [currentPage, setCurrentPage] = useState(1);
	const [searchQuery, setSearchQuery] = useState("");
	const [trackFilter, setTrackFilter] = useState<MovementTrack | "All">("All");

	const filteredData = useMemo(() => {
		return movements.filter((item) => {
			const matchesSearch =
				item.product.toLowerCase().includes(searchQuery.toLowerCase()) ||
				item.updatedBy.toLowerCase().includes(searchQuery.toLowerCase());
			const matchesTrack = trackFilter === "All" || item.actionType === trackFilter;
			return matchesSearch && matchesTrack;
		});
	}, [movements, searchQuery, trackFilter]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const stockInCount = movements.filter((item) => item.actionType === "Stock IN").length;
	const stockOutCount = movements.filter((item) => item.actionType === "Stock OUT").length;
	const repairUsageCount = movements.filter((item) => item.actionType === "Repairs usage").length;

	return (
		<AppLayoutERP>
			<Head title="Stock Movement - Solespace" />

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Stock Movement</h1>
						<p className="text-gray-600 dark:text-gray-400">Track stock changes across purchase/restock, sales, adjustments, returns, and repair materials usage</p>
					</div>
					<div className="flex flex-wrap items-center justify-end gap-3">
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200">Inventory Tracking</span>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Stock IN" value={stockInCount} description="Purchase and restock entries" icon={ArrowUpIcon} color="success" />
					<MetricCard title="Stock OUT" value={stockOutCount} description="Sales and outgoing entries" icon={ArrowDownIcon} color="warning" />
					<MetricCard title="Repairs Usage" value={repairUsageCount} description="Materials consumed in repairs" icon={WrenchIcon} color="info" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Stock Movement Log</h2>
						<p className="text-sm text-gray-500">Tracks: Stock IN, Stock OUT, Adjustments, Returns, Repairs usage</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by product or updated by..."
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
								value={trackFilter}
								onChange={(event) => {
									setTrackFilter(event.target.value as MovementTrack | "All");
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							>
								<option value="All">All Action Types</option>
								<option value="Stock IN">Stock IN (purchase/restock)</option>
								<option value="Stock OUT">Stock OUT (sales)</option>
								<option value="Adjustments">Adjustments</option>
								<option value="Returns">Returns</option>
								<option value="Repairs usage">Repairs usage (materials used)</option>
							</select>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Date</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Time</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Action type</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Quantity change</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">User who updated</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
						{loading ? (
							<tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">Loading stock movements...</td></tr>
						) : paginatedItems.length > 0 ? (
									paginatedItems.map((movement) => (
										<tr key={movement.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{movement.date}</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{movement.time}</td>
											<td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{movement.product}</td>
											<td className="px-4 py-3">
												<span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200">
													{movement.actionType}
												</span>
											</td>
											<td
												className={`px-4 py-3 text-sm font-semibold ${
													movement.quantityChange > 0
														? "text-green-600 dark:text-green-400"
														: "text-red-600 dark:text-red-400"
												}`}
											>
												{formatQuantity(movement.quantityChange)}
											</td>
											<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{movement.updatedBy}</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">
											No stock movement records found.
										</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 mt-4">
						<div className="flex items-center justify-between">
							<div className="text-sm text-gray-700 dark:text-gray-300">
								Showing <span className="font-medium">{filteredData.length === 0 ? 0 : startIndex + 1}</span> to <span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredData.length)}</span> of <span className="font-medium">{filteredData.length}</span> items
							</div>
							<div className="flex gap-2">
								<button
									type="button"
									onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
									disabled={currentPage === 1}
									className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
									title="Previous page"
								>
									<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
									</svg>
								</button>
								<button
									type="button"
									onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
									disabled={currentPage === totalPages}
									className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
									title="Next page"
								>
									<svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
									</svg>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</AppLayoutERP>
	);
}
