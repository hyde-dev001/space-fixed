import { Head } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type StockStatus = "In stock" | "Low" | "Out";
type MetricColor = "success" | "warning" | "info";

interface ProductInventoryItem {
	id: number;
	productName: string;
	skuCode: string;
	category: string;
	brand: string;
	sizes: string[];
	productImages: string[];
	availableQuantity: number;
	reservedQuantity: number;
	lastUpdated: string;
}

const inventoryRows: ProductInventoryItem[] = [
	{ id: 1, productName: "Nike Air Max 270", skuCode: "NK-AM270-BLK", category: "Shoes", brand: "Nike", sizes: ["8", "9", "10"], productImages: ["/images/product/product-01.jpg", "/images/product/product-02.jpg"], availableQuantity: 42, reservedQuantity: 6, lastUpdated: "2026-02-21 09:14 AM" },
	{ id: 2, productName: "Adidas Ultraboost 22", skuCode: "AD-UB22-WHT", category: "Shoes", brand: "Adidas", sizes: ["7", "8", "9"], productImages: ["/images/product/product-02.jpg", "/images/product/product-03.jpg"], availableQuantity: 11, reservedQuantity: 4, lastUpdated: "2026-02-21 10:02 AM" },
	{ id: 3, productName: "New Balance 550", skuCode: "NB-550-GRY", category: "Shoes", brand: "New Balance", sizes: ["8", "9", "10", "11"], productImages: ["/images/product/product-03.jpg", "/images/product/product-04.jpg"], availableQuantity: 0, reservedQuantity: 3, lastUpdated: "2026-02-20 03:55 PM" },
	{ id: 4, productName: "Puma RS-X", skuCode: "PM-RSX-RED", category: "Shoes", brand: "Puma", sizes: ["6", "7", "8"], productImages: ["/images/product/product-04.jpg", "/images/product/product-05.jpg"], availableQuantity: 8, reservedQuantity: 1, lastUpdated: "2026-02-20 05:20 PM" },
	{ id: 5, productName: "Premium Shoelaces", skuCode: "ACC-LACE-PRM", category: "Accessories", brand: "Solespace", sizes: ["90cm", "120cm"], productImages: ["/images/product/product-05.jpg", "/images/product/product-06.jpg"], availableQuantity: 120, reservedQuantity: 10, lastUpdated: "2026-02-21 08:31 AM" },
	{ id: 6, productName: "Cleaning Foam", skuCode: "CARE-FOAM-CLN", category: "Care Products", brand: "Solespace", sizes: ["150ml"], productImages: ["/images/product/product-06.jpg", "/images/product/product-07.jpg"], availableQuantity: 9, reservedQuantity: 2, lastUpdated: "2026-02-21 11:19 AM" },
	{ id: 7, productName: "Leather Conditioner", skuCode: "CARE-LTH-250", category: "Care Products", brand: "Angelus", sizes: ["250ml"], productImages: ["/images/product/product-07.jpg", "/images/product/product-08.jpg"], availableQuantity: 27, reservedQuantity: 5, lastUpdated: "2026-02-20 01:07 PM" },
	{ id: 8, productName: "Shoe Box (Large)", skuCode: "PKG-BOX-L", category: "Packaging", brand: "Solespace", sizes: ["Large"], productImages: ["/images/product/product-08.jpg", "/images/product/product-01.jpg"], availableQuantity: 6, reservedQuantity: 0, lastUpdated: "2026-02-19 04:18 PM" },
];

const getStatus = (availableQuantity: number): StockStatus => {
	if (availableQuantity <= 0) return "Out";
	if (availableQuantity <= 10) return "Low";
	return "In stock";
};

const statusBadgeClass: Record<StockStatus, string> = {
	"In stock": "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
	Low: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
	Out: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
};

interface MetricCardProps {
	title: string;
	value: number;
	description: string;
	icon: ComponentType<{ className?: string }>;
	color: MetricColor;
}

const BoxIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="currentColor" viewBox="0 0 24 24">
		<path d="M21 16V8l-9-5-9 5v8l9 5 9-5zM3.3 7.6L12 12l8.7-4.4M12 12v9" />
	</svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="currentColor" viewBox="0 0 24 24">
		<path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
	</svg>
);

const XCircleIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
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

export default function ProductInventory() {
	const [inventory, setInventory] = useState<ProductInventoryItem[]>(inventoryRows);
	const [searchQuery, setSearchQuery] = useState("");
	const [categoryFilter, setCategoryFilter] = useState("All");
	const [brandFilter, setBrandFilter] = useState("All");
	const [stockSort, setStockSort] = useState<"high-to-low" | "low-to-high">("low-to-high");
	const [currentPage, setCurrentPage] = useState(1);
	const [selectedProduct, setSelectedProduct] = useState<ProductInventoryItem | null>(null);
	const [selectedImageIndex, setSelectedImageIndex] = useState(0);
	const [editedQuantity, setEditedQuantity] = useState("");

	const categories = useMemo(() => {
		return ["All", ...Array.from(new Set(inventoryRows.map((item) => item.category)))];
	}, []);

	const brands = useMemo(() => {
		return ["All", ...Array.from(new Set(inventoryRows.map((item) => item.brand)))];
	}, []);

	const filteredData = useMemo(() => {
		const normalizedSearch = searchQuery.trim().toLowerCase();

		const filtered = inventory.filter((item) => {
			const matchesSearch =
				item.productName.toLowerCase().includes(normalizedSearch) ||
				item.skuCode.toLowerCase().includes(normalizedSearch);
			const matchesCategory = categoryFilter === "All" || item.category === categoryFilter;
			const matchesBrand = brandFilter === "All" || item.brand === brandFilter;

			return matchesSearch && matchesCategory && matchesBrand;
		});

		return [...filtered].sort((a, b) => {
			if (stockSort === "high-to-low") return b.availableQuantity - a.availableQuantity;
			return a.availableQuantity - b.availableQuantity;
		});
	}, [inventory, searchQuery, categoryFilter, brandFilter, stockSort]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil(filteredData.length / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

	const totalProducts = inventory.length;
	const lowStockCount = inventory.filter((item) => item.availableQuantity > 0 && item.availableQuantity <= 10).length;
	const outOfStockCount = inventory.filter((item) => item.availableQuantity <= 0).length;

	const handleFilterChange = () => {
		setCurrentPage(1);
	};

	const closeDetailsModal = () => {
		setSelectedProduct(null);
		setSelectedImageIndex(0);
		setEditedQuantity("");
	};

	const openDetailsModal = (item: ProductInventoryItem) => {
		setSelectedProduct(item);
		setSelectedImageIndex(0);
		setEditedQuantity(String(item.availableQuantity));
	};

	const updateQuantity = (nextQuantity: number) => {
		if (!selectedProduct) return;
		const quantity = Math.max(0, nextQuantity);
		setEditedQuantity(String(quantity));
	};

	const showPreviousImage = () => {
		if (!selectedProduct) return;
		setSelectedImageIndex((prev) => {
			if (prev <= 0) return selectedProduct.productImages.length - 1;
			return prev - 1;
		});
	};

	const showNextImage = () => {
		if (!selectedProduct) return;
		setSelectedImageIndex((prev) => (prev + 1) % selectedProduct.productImages.length);
	};

	const saveQuantityChanges = async () => {
		if (!selectedProduct) return;
		const product = selectedProduct;
		const parsed = Number(editedQuantity);

		closeDetailsModal();

		if (Number.isNaN(parsed) || parsed < 0) {
			await Swal.fire({
				icon: "warning",
				title: "Invalid quantity",
				text: "Please enter a valid quantity of 0 or higher.",
				confirmButtonColor: "#2563eb",
			});
			return;
		}

		const confirmResult = await Swal.fire({
			title: "Save quantity changes?",
			text: `Set available quantity for ${product.productName} to ${parsed}?`,
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Yes, save it",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#2563eb",
			cancelButtonColor: "#6b7280",
		});

		if (!confirmResult.isConfirmed) return;

		const updatedTime = new Date().toLocaleString();
		setInventory((prevRows) =>
			prevRows.map((row) => {
				if (row.id !== product.id) return row;
				return {
					...row,
					availableQuantity: parsed,
					lastUpdated: updatedTime,
				};
			})
		);

		await Swal.fire({
			icon: "success",
			title: "Quantity saved",
			text: "Available quantity has been updated successfully.",
			confirmButtonColor: "#2563eb",
			timer: 1500,
			showConfirmButton: false,
		});
	};

	return (
		<AppLayoutERP hideHeader={Boolean(selectedProduct)}>
			<Head title="Product Inventory - Solespace" />

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Product Inventory</h1>
						<p className="text-gray-600 dark:text-gray-400">Table view of all stocks across products and variants</p>
					</div>
					<div className="flex flex-wrap items-center justify-end gap-3">
						<span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200">Inventory Tracking</span>
					</div>
				</div>

				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
					<MetricCard title="Total Products" value={totalProducts} description="Products listed in inventory" icon={BoxIcon} color="info" />
					<MetricCard title="Low Stock Items" value={lowStockCount} description="Products with 1-10 quantity left" icon={AlertIcon} color="warning" />
					<MetricCard title="Out of Stock" value={outOfStockCount} description="Products requiring immediate restock" icon={XCircleIcon} color="success" />
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm mt-6">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Stock Table</h2>
						<p className="text-sm text-gray-500">Search, filter, sort by stock level, and view product stock details</p>
					</div>

					<div className="mb-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
						<input
							type="text"
							placeholder="Search by product name or SKU/code..."
							value={searchQuery}
							onChange={(event) => {
								setSearchQuery(event.target.value);
								handleFilterChange();
							}}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						/>

						<select
							value={categoryFilter}
							title="Filter by category"
							onChange={(event) => {
								setCategoryFilter(event.target.value);
								handleFilterChange();
							}}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						>
							{categories.map((category) => (
								<option key={category} value={category}>
									Category: {category}
								</option>
							))}
						</select>

						<select
							value={brandFilter}
							title="Filter by brand"
							onChange={(event) => {
								setBrandFilter(event.target.value);
								handleFilterChange();
							}}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						>
							{brands.map((brand) => (
								<option key={brand} value={brand}>
									Brand: {brand}
								</option>
							))}
						</select>

						<select
							value={stockSort}
							title="Sort by stock level"
							onChange={(event) => {
								setStockSort(event.target.value as "high-to-low" | "low-to-high");
								handleFilterChange();
							}}
							className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
						>
							<option value="low-to-high">Sort stock: Low to High</option>
							<option value="high-to-low">Sort stock: High to Low</option>
						</select>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full table-fixed divide-y divide-gray-200 dark:divide-gray-700">
							<colgroup>
								<col className="w-1/7" />
								<col className="w-1/7" />
								<col className="w-1/7" />
								<col className="w-1/7" />
								<col className="w-1/7" />
								<col className="w-1/7" />
								<col className="w-1/7" />
							</colgroup>
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product name</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">SKU / Code</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Sizes</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Available quantity</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Last updated</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">View details</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{paginatedItems.length > 0 ? (
									paginatedItems.map((item) => {
										const status = getStatus(item.availableQuantity);

										return (
											<tr key={item.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
												<td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{item.productName}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{item.skuCode}</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{item.sizes.join(", ")}</td>
												<td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white text-center">{item.availableQuantity}</td>
												<td className="px-4 py-3 text-center">
													<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[status]}`}>
														{status}
													</span>
												</td>
												<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{item.lastUpdated}</td>
												<td className="px-4 py-3 text-center">
													<button
														type="button"
														onClick={() => openDetailsModal(item)}
														className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
														title="View details"
														aria-label={`View details for ${item.productName}`}
													>
														<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
															<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
															<circle cx="12" cy="12" r="3" />
														</svg>
													</button>
												</td>
											</tr>
										);
									})
								) : (
									<tr>
										<td colSpan={7} className="px-4 py-10 text-center text-sm text-gray-500">
											No product inventory records found.
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

				{selectedProduct && (
					<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
						<button type="button" aria-label="Close product details" className="absolute inset-0 bg-black/50" onClick={closeDetailsModal} />
						<div className="relative w-full max-w-5xl max-h-[90vh] overflow-y-auto rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
							<div className="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
								<div>
									<h3 className="text-xl font-semibold text-gray-900 dark:text-white">{selectedProduct.productName}</h3>
									<p className="text-sm text-gray-500">SKU: {selectedProduct.skuCode}</p>
								</div>
								<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[getStatus(selectedProduct.availableQuantity)]}`}>
									{getStatus(selectedProduct.availableQuantity)}
								</span>
							</div>

							<div className="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
								<div className="space-y-4">
									<div className="relative">
										<img
											src={selectedProduct.productImages[selectedImageIndex]}
											alt={`${selectedProduct.productName} preview`}
											className="w-full h-80 object-cover rounded-xl border border-gray-200 dark:border-gray-700"
										/>
										<button
											type="button"
											onClick={showPreviousImage}
											className="absolute left-3 top-1/2 -translate-y-1/2 h-9 w-9 rounded-full border border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-900/90 text-gray-800 dark:text-gray-200 hover:bg-white dark:hover:bg-gray-900"
											aria-label="Previous image"
										>
											&lt;
										</button>
										<button
											type="button"
											onClick={showNextImage}
											className="absolute right-3 top-1/2 -translate-y-1/2 h-9 w-9 rounded-full border border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-900/90 text-gray-800 dark:text-gray-200 hover:bg-white dark:hover:bg-gray-900"
											aria-label="Next image"
										>
											&gt;
										</button>
									</div>
								</div>

								<div className="space-y-5">
									<div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
										<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
											<p className="text-gray-500 mb-1">Category</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedProduct.category}</p>
										</div>
										<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
											<p className="text-gray-500 mb-1">Brand</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedProduct.brand}</p>
										</div>
										<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800 sm:col-span-2">
											<p className="text-gray-500 mb-1">Sizes</p>
											<p className="font-semibold text-gray-900 dark:text-white">{selectedProduct.sizes.join(", ")}</p>
										</div>
									</div>

									<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800 space-y-4">
										<div className="flex items-center justify-between">
											<p className="text-sm text-gray-500">Last Updated</p>
											<p className="text-sm font-medium text-gray-900 dark:text-white">{selectedProduct.lastUpdated}</p>
										</div>
										<div>
											<label htmlFor="edit-available-quantity" className="block text-sm text-gray-500 mb-2">Edit Available Quantity</label>
											<div className="flex items-center gap-2">
												<button
													type="button"
													onClick={() => updateQuantity((Number(editedQuantity) || 0) - 1)}
													className="h-10 w-10 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300"
												>
													-
												</button>
												<input
													id="edit-available-quantity"
													type="number"
													min={0}
													value={editedQuantity}
													onChange={(event) => setEditedQuantity(event.target.value)}
													className="h-10 flex-1 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
												/>
												<button
													type="button"
													onClick={() => updateQuantity((Number(editedQuantity) || 0) + 1)}
													className="h-10 w-10 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300"
												>
													+
												</button>
											</div>
										</div>
									</div>

									<div className="flex items-center justify-end gap-3">
								<button
									type="button"
									onClick={closeDetailsModal}
									className="rounded-lg border border-gray-300 dark:border-gray-700 px-4 py-2.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
								>
									Close
								</button>
										<button
											type="button"
											onClick={saveQuantityChanges}
											className="rounded-lg border border-blue-600 bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700"
										>
											Save Quantity
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>
				)}
			</div>
		</AppLayoutERP>
	);
}
