import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../../layout/AppLayout_shopOwner";
import axios from "axios";

interface HighValueRepair {
	id: number;
	request_number?: string;
	description: string;
	status: string;
	is_high_value: boolean;
	requires_owner_approval: boolean;
	estimated_price: number;
	customer_confirmed_at: string;
	created_at: string;
	user?: {
		id: number;
		first_name: string;
		last_name: string;
		email: string;
		phone_number?: string;
	};
	services?: Array<{
		id: number;
		name: string;
		base_price: number;
	}>;
	repairer?: {
		id: number;
		first_name: string;
		last_name: string;
	};
	conversation?: {
		id: number;
	};
}

export default function HighValueRepairs() {
	const { auth } = usePage().props as any;
	
	const [repairs, setRepairs] = useState<HighValueRepair[]>([]);
	const [loading, setLoading] = useState(true);
	const [selectedRepair, setSelectedRepair] = useState<HighValueRepair | null>(null);
	const [viewModalOpen, setViewModalOpen] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");

	useEffect(() => {
		const isAuthenticated = auth?.shop_owner;
		
		if (!isAuthenticated) {
			window.location.href = "/shop-owner/login";
			return;
		}

		fetchHighValueRepairs();
	}, []);

	const fetchHighValueRepairs = async () => {
		setLoading(true);
		try {
			const response = await axios.get('/api/shop-owner/repairs/high-value-pending');
			if (response.data.success) {
				setRepairs(response.data.repairs);
			}
		} catch (error: any) {
			console.error('Failed to fetch high-value repairs:', error);
			Swal.fire({
				title: 'Error',
				text: error.response?.data?.message || 'Failed to load high-value repairs',
				icon: 'error'
			});
		} finally {
			setLoading(false);
		}
	};

	const handleApproveRepair = async (repair: HighValueRepair) => {
		const { value: notes } = await Swal.fire({
			title: "Approve High-Value Repair?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Customer:</strong> ${repair.user?.first_name} ${repair.user?.last_name}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Services:</strong> ${repair.services?.map(s => s.name).join(', ')}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Estimated Price:</strong> ₱${repair.estimated_price?.toLocaleString()}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Repairer:</strong> ${repair.repairer?.first_name} ${repair.repairer?.last_name}</p>
					<p style="margin-bottom: 1rem;"><strong>Description:</strong> ${repair.description}</p>
					<label for="approval-notes" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Approval Notes (optional):</label>
					<textarea 
						id="approval-notes" 
						class="swal2-textarea" 
						placeholder="Add any notes about this approval..."
						style="width: 100%; height: 80px;"
					></textarea>
				</div>
			`,
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#10b981",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Approve",
			cancelButtonText: "Cancel",
			preConfirm: () => {
				return (document.getElementById('approval-notes') as HTMLTextAreaElement)?.value || '';
			}
		});

		if (notes !== undefined) {
			try {
				const response = await axios.post(`/api/shop-owner/repairs/${repair.id}/approve-high-value`, {
					notes
				});

				if (response.data.success) {
					Swal.fire({
						title: "Approved!",
						text: "The high-value repair has been approved. Repairer can now start work.",
						icon: "success",
						confirmButtonColor: "#2563eb",
					});
					fetchHighValueRepairs(); // Refresh the list
				}
			} catch (error: any) {
				Swal.fire({
					title: 'Error',
					text: error.response?.data?.message || 'Failed to approve repair',
					icon: 'error'
				});
			}
		}
	};

	const handleRejectRepair = async (repair: HighValueRepair) => {
		const { value: notes } = await Swal.fire({
			title: "Reject High-Value Repair?",
			html: `
				<div style="text-align: left; margin-top: 1rem;">
					<p style="margin-bottom: 0.5rem;"><strong>Customer:</strong> ${repair.user?.first_name} ${repair.user?.last_name}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Services:</strong> ${repair.services?.map(s => s.name).join(', ')}</p>
					<p style="margin-bottom: 0.5rem;"><strong>Estimated Price:</strong> ₱${repair.estimated_price?.toLocaleString()}</p>
					<p style="margin-bottom: 1rem; color: #ef4444;"><strong>Warning:</strong> This will cancel the repair request.</p>
					<label for="rejection-notes" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Rejection Reason (required, min 10 characters):</label>
					<textarea 
						id="rejection-notes" 
						class="swal2-textarea" 
						placeholder="Explain why this repair is being rejected..."
						style="width: 100%; height: 100px;"
						required
					></textarea>
				</div>
			`,
			icon: "warning",
			showCancelButton: true,
			confirmButtonColor: "#ef4444",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Reject Repair",
			cancelButtonText: "Cancel",
			preConfirm: () => {
				const notes = (document.getElementById('rejection-notes') as HTMLTextAreaElement)?.value || '';
				if (notes.length < 10) {
					Swal.showValidationMessage('Rejection reason must be at least 10 characters');
					return false;
				}
				return notes;
			}
		});

		if (notes) {
			try {
				const response = await axios.post(`/api/shop-owner/repairs/${repair.id}/reject-high-value`, {
					notes
				});

				if (response.data.success) {
					Swal.fire({
						title: "Rejected",
						text: "The high-value repair has been rejected. Customer will be notified.",
						icon: "success",
						confirmButtonColor: "#2563eb",
					});
					fetchHighValueRepairs(); // Refresh the list
				}
			} catch (error: any) {
				Swal.fire({
					title: 'Error',
					text: error.response?.data?.message || 'Failed to reject repair',
					icon: 'error'
				});
			}
		}
	};

	const handleViewDetails = (repair: HighValueRepair) => {
		setSelectedRepair(repair);
		setViewModalOpen(true);
	};

	const closeViewModal = () => {
		setViewModalOpen(false);
		setSelectedRepair(null);
	};

	const filteredRepairs = repairs.filter(repair => {
		const customerName = `${repair.user?.first_name} ${repair.user?.last_name}`.toLowerCase();
		const services = repair.services?.map(s => s.name).join(' ').toLowerCase() || '';
		const query = searchQuery.toLowerCase();
		return customerName.includes(query) || services.includes(query) || repair.description.toLowerCase().includes(query);
	});

	const totalValue = repairs.reduce((sum, r) => sum + (r.estimated_price || 0), 0);

	return (
		<AppLayoutShopOwner>
			<Head title="High-Value Repair Approvals" />

			<div className="mx-auto max-w-screen-2xl p-4 md:p-6 2xl:p-10">
				{/* Header */}
				<div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
					<h2 className="text-title-md2 font-semibold text-black dark:text-white">
						High-Value Repair Approvals
					</h2>
				</div>

				{/* Stats Cards */}
				<div className="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
					<div className="rounded-lg border border-stroke bg-white p-6 shadow-default dark:border-strokedark dark:bg-boxdark">
						<div className="flex items-center">
							<div className="flex h-12 w-12 items-center justify-center rounded-full bg-meta-8 bg-opacity-10">
								<svg className="w-6 h-6 text-meta-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
								</svg>
							</div>
							<div className="ml-4">
								<h4 className="font-bold text-black dark:text-white">{repairs.length}</h4>
								<p className="text-sm text-gray-600 dark:text-gray-400">Pending Approvals</p>
							</div>
						</div>
					</div>

					<div className="rounded-lg border border-stroke bg-white p-6 shadow-default dark:border-strokedark dark:bg-boxdark">
						<div className="flex items-center">
							<div className="flex h-12 w-12 items-center justify-center rounded-full bg-meta-3 bg-opacity-10">
								<svg className="w-6 h-6 text-meta-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
								</svg>
							</div>
							<div className="ml-4">
								<h4 className="font-bold text-black dark:text-white">₱{totalValue.toLocaleString()}</h4>
								<p className="text-sm text-gray-600 dark:text-gray-400">Total Value</p>
							</div>
						</div>
					</div>

					<div className="rounded-lg border border-stroke bg-white p-6 shadow-default dark:border-strokedark dark:bg-boxdark">
						<div className="flex items-center">
							<div className="flex h-12 w-12 items-center justify-center rounded-full bg-meta-1 bg-opacity-10">
								<svg className="w-6 h-6 text-meta-1" fill="currentColor" viewBox="0 0 24 24">
									<path d="M21 6.5a4.5 4.5 0 01-6.36 4.09l-6.8 6.8a2 2 0 11-2.83-2.83l6.8-6.8A4.5 4.5 0 1116.5 3a4.49 4.49 0 014.5 3.5z" />
								</svg>
							</div>
							<div className="ml-4">
								<h4 className="font-bold text-black dark:text-white">{repairs.length > 0 ? `₱${Math.floor(totalValue / repairs.length).toLocaleString()}` : '₱0'}</h4>
								<p className="text-sm text-gray-600 dark:text-gray-400">Average Value</p>
							</div>
						</div>
					</div>
				</div>

				{/* Search Bar */}
				<div className="mb-6">
					<input
						type="text"
						placeholder="Search by customer name, service, or description..."
						value={searchQuery}
						onChange={(e) => setSearchQuery(e.target.value)}
						className="w-full rounded-lg border border-stroke bg-transparent py-3 px-5 outline-none focus:border-primary dark:border-strokedark dark:bg-meta-4"
					/>
				</div>

				{/* Repairs Table */}
				<div className="rounded-sm border border-stroke bg-white shadow-default dark:border-strokedark dark:bg-boxdark">
					<div className="py-6 px-4 md:px-6 xl:px-7.5">
						<h4 className="text-xl font-semibold text-black dark:text-white">
							Pending High-Value Repairs
						</h4>
					</div>

					<div className="overflow-x-auto">
						{loading ? (
							<div className="py-12 text-center">
								<p className="text-gray-600 dark:text-gray-400">Loading...</p>
							</div>
						) : filteredRepairs.length === 0 ? (
							<div className="py-12 text-center">
								<p className="text-gray-600 dark:text-gray-400">No high-value repairs pending approval</p>
							</div>
						) : (
							<table className="w-full table-auto">
								<thead>
									<tr className="bg-gray-2 text-left dark:bg-meta-4">
										<th className="py-4 px-4 font-medium text-black dark:text-white">Customer</th>
										<th className="py-4 px-4 font-medium text-black dark:text-white">Services</th>
										<th className="py-4 px-4 font-medium text-black dark:text-white">Repairer</th>
										<th className="py-4 px-4 font-medium text-black dark:text-white">Price</th>
										<th className="py-4 px-4 font-medium text-black dark:text-white">Date</th>
										<th className="py-4 px-4 font-medium text-black dark:text-white">Actions</th>
									</tr>
								</thead>
								<tbody>
									{filteredRepairs.map((repair) => (
										<tr key={repair.id} className="border-b border-stroke dark:border-strokedark">
											<td className="py-5 px-4">
												<p className="font-medium text-black dark:text-white">
													{repair.user?.first_name} {repair.user?.last_name}
												</p>
												<p className="text-sm text-gray-600 dark:text-gray-400">{repair.user?.email}</p>
											</td>
											<td className="py-5 px-4">
												<div className="flex flex-col gap-1">
													{repair.services?.map((service) => (
														<span key={service.id} className="text-sm">
															{service.name}
														</span>
													))}
												</div>
											</td>
											<td className="py-5 px-4">
												<p className="text-sm text-black dark:text-white">
													{repair.repairer?.first_name} {repair.repairer?.last_name}
												</p>
											</td>
											<td className="py-5 px-4">
												<p className="font-semibold text-meta-3">₱{repair.estimated_price?.toLocaleString()}</p>
											</td>
											<td className="py-5 px-4">
												<p className="text-sm">{new Date(repair.created_at).toLocaleDateString()}</p>
											</td>
											<td className="py-5 px-4">
												<div className="flex items-center space-x-2">
													<button
														onClick={() => handleViewDetails(repair)}
														className="inline-flex rounded bg-primary py-2 px-4 font-medium text-white hover:bg-opacity-90"
													>
														View
													</button>
													<button
														onClick={() => handleApproveRepair(repair)}
														className="inline-flex rounded bg-success py-2 px-4 font-medium text-white hover:bg-opacity-90"
													>
														Approve
													</button>
													<button
														onClick={() => handleRejectRepair(repair)}
														className="inline-flex rounded bg-danger py-2 px-4 font-medium text-white hover:bg-opacity-90"
													>
														Reject
													</button>
												</div>
											</td>
										</tr>
									))}
								</tbody>
							</table>
						)}
					</div>
				</div>

				{/* View Details Modal */}
				{viewModalOpen && selectedRepair && (
					<div className="fixed inset-0 z-999 flex items-center justify-center bg-black bg-opacity-50">
						<div className="relative max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white p-8 dark:bg-boxdark">
							<button
								onClick={closeViewModal}
								className="absolute top-4 right-4 text-gray-500 hover:text-gray-700"
							>
								<svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>

							<h3 className="text-2xl font-bold mb-6 text-black dark:text-white">Repair Details</h3>

							<div className="space-y-4">
								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Customer</p>
									<p className="font-medium text-black dark:text-white">
										{selectedRepair.user?.first_name} {selectedRepair.user?.last_name}
									</p>
									<p className="text-sm text-gray-600 dark:text-gray-400">{selectedRepair.user?.email}</p>
									{selectedRepair.user?.phone_number && (
										<p className="text-sm text-gray-600 dark:text-gray-400">{selectedRepair.user?.phone_number}</p>
									)}
								</div>

								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Services</p>
									{selectedRepair.services?.map((service) => (
										<div key={service.id} className="flex justify-between py-1">
											<span className="font-medium text-black dark:text-white">{service.name}</span>
											<span className="text-gray-600 dark:text-gray-400">₱{service.base_price.toLocaleString()}</span>
										</div>
									))}
								</div>

								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Assigned Repairer</p>
									<p className="font-medium text-black dark:text-white">
										{selectedRepair.repairer?.first_name} {selectedRepair.repairer?.last_name}
									</p>
								</div>

								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Description</p>
									<p className="font-medium text-black dark:text-white">{selectedRepair.description}</p>
								</div>

								<div>
									<p className="text-sm text-gray-600 dark:text-gray-400">Estimated Price</p>
									<p className="text-2xl font-bold text-meta-3">₱{selectedRepair.estimated_price?.toLocaleString()}</p>
								</div>

								<div className="flex gap-3 mt-6">
									<button
										onClick={() => {
											closeViewModal();
											handleApproveRepair(selectedRepair);
										}}
										className="flex-1 inline-flex justify-center rounded bg-success py-3 px-6 font-medium text-white hover:bg-opacity-90"
									>
										Approve Repair
									</button>
									<button
										onClick={() => {
											closeViewModal();
											handleRejectRepair(selectedRepair);
										}}
										className="flex-1 inline-flex justify-center rounded bg-danger py-3 px-6 font-medium text-white hover:bg-opacity-90"
									>
										Reject Repair
									</button>
								</div>
							</div>
						</div>
					</div>
				)}
			</div>
		</AppLayoutShopOwner>
	);
}
