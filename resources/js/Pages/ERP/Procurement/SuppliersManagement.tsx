import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { supplierApi, type Supplier } from "@/services/procurementApi";
import { erpUrl } from "@/utils/erpCapabilities";



const PencilIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
	</svg>
);

const ArchiveBoxIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 13V7a2 2 0 00-2-2h-3V3H9v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4m5 4h6" />
	</svg>
);

const RestoreIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h7V3" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10a9 9 0 109 9" />
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

interface FormState {
	name: string;
	contact_person: string;
	email: string;
	phone: string;
	address: string;
	notes: string;
}

const initialFormState: FormState = {
	name: "",
	contact_person: "",
	email: "",
	phone: "",
	address: "",
	notes: "",
};

export default function SuppliersManagement() {
	const { initialData, auth, erpCapabilities } = usePage().props as any;
	const ownerMode = auth?.erpActor?.ownerMode === true;
	const [suppliers, setSuppliers] = useState<Supplier[]>(initialData?.data ?? []);
	const [loading, setLoading] = useState(false);
	const [showArchived, setShowArchived] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isModalOpen, setIsModalOpen] = useState(false);
	const [formData, setFormData] = useState<FormState>(initialFormState);
	const [viewingSupplier, setViewingSupplier] = useState<Supplier | null>(null);
	const [editingSupplier, setEditingSupplier] = useState<Supplier | null>(null);

	const getApiErrorMessage = (error: unknown, fallback: string) => {
		if (!axios.isAxiosError(error)) return fallback;

		const responseData = error.response?.data as {
			message?: string;
			errors?: Record<string, string[]>;
		} | undefined;

		const firstValidationError = responseData?.errors
			? Object.values(responseData.errors).flat()[0]
			: undefined;

		return firstValidationError || responseData?.message || fallback;
	};

	const fetchSuppliers = async () => {
		setLoading(true);
		try {
			const suppliersUrl = erpUrl(erpCapabilities, "GET:procurement.suppliers.index");
			if (ownerMode && !suppliersUrl) return;

			const response = await supplierApi.getAll(
				{ page: 1, per_page: 100, archived: showArchived },
				suppliersUrl ?? undefined,
			);
			setSuppliers(response.data || []);
		} catch (error) {
			console.error("Failed to fetch suppliers:", error);
			await Swal.fire("Error", "Failed to load suppliers", "error");
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		void fetchSuppliers();
	}, [showArchived]);

	const filteredData = useMemo(() => {
		if (!suppliers || !Array.isArray(suppliers)) return [];
		const query = searchQuery.trim().toLowerCase();
		if (!query) return suppliers;

		return suppliers.filter((supplier) =>
			supplier.name.toLowerCase().includes(query) ||
			supplier.email?.toLowerCase().includes(query) ||
			supplier.contact_person?.toLowerCase().includes(query) ||
			supplier.phone?.toLowerCase().includes(query) ||
			supplier.notes?.toLowerCase().includes(query)
		);
	}, [searchQuery, suppliers]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil((filteredData?.length || 0) / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData?.slice(startIndex, startIndex + itemsPerPage) || [];

	const handleView = (supplier: Supplier) => {
		setViewingSupplier(supplier);
	};

	const handleEdit = (supplier: Supplier) => {
		if (ownerMode) return;

		setEditingSupplier(supplier);
		setFormData({
			name: supplier.name,
			contact_person: supplier.contact_person || "",
			email: supplier.email || "",
			phone: supplier.phone || "",
			address: supplier.address || "",
			notes: supplier.notes || "",
		});
	};

	const handleArchive = async (supplierId: number) => {
		if (ownerMode) return;

		const result = await Swal.fire({
			title: "Archive Supplier?",
			text: "Are you sure you want to archive this supplier? You can restore it later if needed.",
			icon: "warning",
			showCancelButton: true,
			confirmButtonColor: "#dc2626",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Archive",
			cancelButtonText: "Cancel",
		});

		if (!result.isConfirmed) return;

		try {
			await supplierApi.delete(supplierId);
			await Swal.fire({
				title: "Archived!",
				text: "Supplier has been archived successfully.",
				icon: "success",
				timer: 1500,
			});
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to archive supplier:", error);
			await Swal.fire("Error", "Failed to archive supplier", "error");
		}
	};

	const handleRestore = async (supplierId: number) => {
		if (ownerMode) return;

		const result = await Swal.fire({
			title: "Restore Supplier?",
			text: "Are you sure you want to restore this supplier to active records?",
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#2563eb",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Restore",
			cancelButtonText: "Cancel",
		});

		if (!result.isConfirmed) return;

		try {
			await supplierApi.restore(supplierId);
			await Swal.fire({
				title: "Restored!",
				text: "Supplier has been restored successfully.",
				icon: "success",
				timer: 1500,
			});
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to restore supplier:", error);
			await Swal.fire("Error", "Failed to restore supplier", "error");
		}
	};

	const handleSaveEdit = async () => {
		if (ownerMode) return;

		if (!formData.name.trim()) {
			await Swal.fire("Warning", "Please fill required field (Supplier Name)", "warning");
			return;
		}

		if (formData.email.trim() && !/^\S+@\S+\.\S+$/.test(formData.email.trim())) {
			await Swal.fire("Warning", "Please enter a valid email address", "warning");
			return;
		}

		if (!editingSupplier) return;

		try {
			await supplierApi.update(editingSupplier.id, {
				name: formData.name,
				contact_person: formData.contact_person,
				email: formData.email,
				phone: formData.phone,
				address: formData.address,
				notes: formData.notes,
			});

			await Swal.fire("Success", "Supplier updated successfully", "success");
			setEditingSupplier(null);
			setFormData(initialFormState);
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to update supplier:", error);
			await Swal.fire("Error", getApiErrorMessage(error, "Failed to update supplier"), "error");
		}
	};

	const handleOpenModal = () => {
		if (ownerMode) return;

		setFormData(initialFormState);
		setIsModalOpen(true);
	};

	const handleCloseModal = () => {
		setIsModalOpen(false);
		setFormData(initialFormState);
	};

	const handleFormChange = (e: React.ChangeEvent<HTMLInputElement>) => {
		const { name, value } = e.target;
		setFormData((prev) => ({
			...prev,
			[name]: value,
		}));
	};

	const handleAddSupplier = async () => {
		if (ownerMode) return;

		if (!formData.name.trim()) {
			await Swal.fire("Warning", "Please fill required field (Supplier Name)", "warning");
			return;
		}

		if (formData.email.trim() && !/^\S+@\S+\.\S+$/.test(formData.email.trim())) {
			await Swal.fire("Warning", "Please enter a valid email address", "warning");
			return;
		}

		try {
			await supplierApi.create({
				name: formData.name,
				contact_person: formData.contact_person,
				email: formData.email,
				phone: formData.phone,
				address: formData.address,
				notes: formData.notes,
			});

			await Swal.fire("Success", "Supplier created successfully", "success");
			handleCloseModal();
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to create supplier:", error);
			await Swal.fire("Error", getApiErrorMessage(error, "Failed to create supplier"), "error");
		}
	};

	const isAnyModalOpen = isModalOpen || !!viewingSupplier || !!editingSupplier;

	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Suppliers Management - Solespace" />
			{isAnyModalOpen && <div className="fixed inset-0 z-40" />}

			<div className="p-6 space-y-6">
				<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
					<div>
						<h1 className="text-2xl font-semibold mb-1">Suppliers Management</h1>
						<p className="text-gray-600 dark:text-gray-400">
							{showArchived
								? "View archived supplier records, contact info, and purchase history"
								: "View and manage supplier records, contact info, and purchase history"}
						</p>
					</div>
					<div className="flex items-center gap-2">
						<button
							type="button"
							onClick={() => {
								setShowArchived((prev) => !prev);
								setCurrentPage(1);
							}}
							className={`px-4 py-2 rounded-lg font-medium transition-colors whitespace-nowrap border ${
								showArchived
									? "border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300"
									: "border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300"
							}`}
						>
							{showArchived ? "Show Active" : "Show Archived"}
						</button>

						{!ownerMode && !showArchived && (
							<button
								onClick={handleOpenModal}
								className="px-4 py-2 bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white rounded-lg font-medium transition-colors whitespace-nowrap"
							>
								+ Add Supplier
							</button>
						)}
					</div>
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Suppliers Table</h2>
						<p className="text-sm text-gray-500">Supplier name, contact info, and purchase history</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by supplier name, contact, or notes..."
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
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Supplier name</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Contact info</th>
								<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Notes</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Purchase history</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{paginatedItems.length > 0 ? (
									paginatedItems.map((supplier) => (
										<tr key={supplier.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
										<td className="px-4 py-3">
											<p className="text-sm font-medium text-gray-900 dark:text-white">{supplier.name}</p>
											<span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium mt-1 ${supplier.is_active ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"}`}>
												{supplier.is_active ? "Active" : "Inactive"}
											</span>
										</td>
										<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
											<p>{supplier.email}</p>
											{supplier.phone && <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{supplier.phone}</p>}
										</td>
										<td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate italic" title={supplier.notes}>{supplier.notes || "—"}</td>
										<td className="px-4 py-3 text-sm">
											<span className="font-semibold text-gray-900 dark:text-white">
												{supplier.purchase_order_count} {supplier.purchase_order_count === 1 ? "order" : "orders"}
											</span>
											{supplier.last_order_date && <p className="text-xs text-gray-400 mt-0.5">Last: {supplier.last_order_date}</p>}
										</td>
											<td className="px-4 py-3 text-center">
												<div className="flex items-center justify-center gap-2">
													<button
														onClick={() => handleView(supplier)}
														className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
														title="View supplier details"
													>
														<svg className="h-5 w-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
															<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
															<circle cx="12" cy="12" r="3" />
														</svg>
													</button>
														{ownerMode ? null : showArchived ? (
														<button
															onClick={() => handleRestore(supplier.id)}
															className="p-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
															title="Restore supplier"
														>
															<RestoreIcon className="w-5 h-5" />
														</button>
													) : (
														<>
															<button
																onClick={() => handleEdit(supplier)}
																className="p-2 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors"
																title="Edit supplier"
															>
																<PencilIcon className="w-5 h-5" />
															</button>
															<button
																onClick={() => handleArchive(supplier.id)}
																className="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
																title="Archive supplier"
															>
																<ArchiveBoxIcon className="w-5 h-5" />
															</button>
														</>
													)}
												</div>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={5} className="px-4 py-10 text-center text-sm text-gray-500">
											{showArchived ? "No archived supplier records found." : "No supplier records found."}
										</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {(filteredData?.length || 0) === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, (filteredData?.length || 0))} of {(filteredData?.length || 0)} suppliers
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

			{/* Add Supplier Modal */}
			{isModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close add supplier modal" className="absolute inset-0 bg-black/50" onClick={handleCloseModal} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Add New Supplier</h2>
							<button
								onClick={handleCloseModal}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
							>
								×
							</button>
						</div>

						<div className="p-6 space-y-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Supplier Name *
								</label>
								<input
									type="text"
									name="name"
									value={formData.name}
									onChange={handleFormChange}
									placeholder="e.g., Metro Footwear Trading"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Contact Person
								</label>
								<input
									type="text"
									name="contact_person"
									value={formData.contact_person}
									onChange={handleFormChange}
									placeholder="e.g., Juan Dela Cruz"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Email
								</label>
								<input
									type="email"
									name="email"
									value={formData.email}
									onChange={handleFormChange}
									placeholder="e.g., contact@email.com"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Phone
								</label>
								<input
									type="text"
									name="phone"
									value={formData.phone}
									onChange={handleFormChange}
									placeholder="e.g., 0917-456-1188"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Notes
								</label>
								<input
									type="text"
									name="notes"
									value={formData.notes}
									onChange={handleFormChange}
									placeholder="e.g., Preferred payment: bank transfer. Lead time: 7 days."
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>


						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button
								onClick={handleCloseModal}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleAddSupplier}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white font-medium transition-colors"
							>
								Add Supplier
							</button>
						</div>
					</div>
				</div>
			)}

			{/* View Supplier Modal */}
			{viewingSupplier && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close view supplier modal" className="absolute inset-0 bg-black/50" onClick={() => setViewingSupplier(null)} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl max-h-[90vh] overflow-y-auto">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800 sticky top-0 bg-white dark:bg-gray-900">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Supplier Details</h2>
							<button
								onClick={() => setViewingSupplier(null)}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
							>
								×
							</button>
						</div>

						<div className="p-6 space-y-4">
							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Supplier Name</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.name}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${viewingSupplier.is_active ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"}`}>
										{viewingSupplier.is_active ? "Active" : "Inactive"}
									</span>
								</div>
							</div>

							{viewingSupplier.contact_person && (
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Contact Person</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.contact_person}</p>
								</div>
							)}

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Email</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white break-all">{viewingSupplier.email || "—"}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Phone</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.phone || "—"}</p>
								</div>
							</div>

							{viewingSupplier.address && (
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Address</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.address}</p>
								</div>
							)}

{viewingSupplier.notes && (
							<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</p>
								<p className="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{viewingSupplier.notes}</p>
							</div>
						)}

							<div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Total Purchase Orders</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.purchase_order_count} {viewingSupplier.purchase_order_count === 1 ? "order" : "orders"}</p>
								</div>
								<div className="rounded-xl bg-gray-50 dark:bg-gray-800/40 p-4 border border-gray-200 dark:border-gray-800">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Last Order Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.last_order_date || "No orders yet"}</p>
								</div>
							</div>
						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 sticky bottom-0">
							<button
								onClick={() => setViewingSupplier(null)}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Close
							</button>
						</div>
					</div>
				</div>
			)}

			{/* Edit Supplier Modal */}
			{editingSupplier && (
				<div className="fixed inset-0 z-50 flex items-center justify-center p-4">
					<button type="button" aria-label="Close edit supplier modal" className="absolute inset-0 bg-black/50" onClick={() => { setEditingSupplier(null); setFormData(initialFormState); }} />
					<div className="relative w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-xl">
						<div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-800">
							<h2 className="text-xl font-semibold text-gray-900 dark:text-white">Edit Supplier</h2>
							<button
								onClick={() => { setEditingSupplier(null); setFormData(initialFormState); }}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
							>
								×
							</button>
						</div>

						<div className="p-6 space-y-4">
							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Supplier Name *
								</label>
								<input
									type="text"
									name="name"
									value={formData.name}
									onChange={handleFormChange}
									placeholder="e.g., Metro Footwear Trading"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Contact Person
								</label>
								<input
									type="text"
									name="contact_person"
									value={formData.contact_person}
									onChange={handleFormChange}
									placeholder="e.g., Juan Dela Cruz"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Email
								</label>
								<input
									type="email"
									name="email"
									value={formData.email}
									onChange={handleFormChange}
									placeholder="e.g., contact@email.com"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Phone
								</label>
								<input
									type="text"
									name="phone"
									value={formData.phone}
									onChange={handleFormChange}
									placeholder="e.g., 0917-456-1188"
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>

							<div>
								<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
									Notes
								</label>
								<input
									type="text"
									name="notes"
									value={formData.notes}
									onChange={handleFormChange}
									placeholder="e.g., Preferred payment: bank transfer. Lead time: 7 days."
									className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/20"
								/>
							</div>


						</div>

						<div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
							<button
								onClick={() => { setEditingSupplier(null); setFormData(initialFormState); }}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleSaveEdit}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white font-medium transition-colors"
							>
								Save Changes
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
