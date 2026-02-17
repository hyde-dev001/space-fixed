import { Head, usePage, Link } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useState, useMemo, useEffect } from "react";
import axios from "axios";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";

// TypeScript interfaces
interface HistoryEvent {
	id: number;
	event: string;
	description: string;
	changedBy: string;
	changedAt: string;
	status: string;
	notes?: string;
}

interface RejectionType {
	id: number;
	requestNumber: string;
	serviceName: string;
	category: string;
	customerName: string;
	orderedBy: string;
	requestedOn: string;
	reason: string;
	rejectionReason: string;
	status: string;
	approvedBy?: string;
	approvedAt?: string;
	rejectedBy?: string;
	rejectedAt?: string;
	decisionReason?: string;
	media?: string[];
	history: HistoryEvent[];
}

// Sample initial data (fallback - will be replaced by API)
const initialRepairRejections: RejectionType[] = [
	{
		id: 101,
		requestNumber: "RR-2026-0045",
		serviceName: "Sole Replacement",
		category: "Structural Repair",
		customerName: "Maria Santos",
		orderedBy: "John Manager",
		requestedOn: "2026-02-08",
		reason: "Customer declined high repair cost",
		rejectionReason: "Cost exceeds 40% of shoe value",
		status: "Pending",
		media: [
			"/images/product/product-01.jpg",
			"/images/product/product-02.jpg",
		],
		history: [
			{
				id: 1,
				event: "submitted",
				description: "Rejection request submitted",
				changedBy: "John Manager",
				changedAt: "2026-02-08 10:30 AM",
				status: "Submitted",
			},
			{
				id: 2,
				event: "reviewed",
				description: "Request received and under review",
				changedBy: "Finance Team",
				changedAt: "2026-02-08 02:15 PM",
				status: "Under Review",
				notes: "Cost analysis in progress",
			},
		],
	},
	{
		id: 102,
		requestNumber: "RR-2026-0046",
		serviceName: "Stitching Repair",
		category: "Minor Repair",
		customerName: "Carlos Reyes",
		orderedBy: "Jane Supervisor",
		requestedOn: "2026-02-07",
		reason: "Customer preferred DIY solution",
		rejectionReason: "Low value repair, customer declined",
		status: "Pending",
		media: [
			"/images/product/product-03.jpg",
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
		],
		history: [
			{
				id: 1,
				event: "submitted",
				description: "Rejection request submitted",
				changedBy: "Jane Supervisor",
				changedAt: "2026-02-07 09:00 AM",
				status: "Submitted",
			},
		],
	},
	{
		id: 103,
		requestNumber: "RR-2026-0044",
		serviceName: "Heel Replacement",
		category: "Structural Repair",
		customerName: "Sofia Cruz",
		orderedBy: "Mike Manager",
		requestedOn: "2026-02-05",
		reason: "Shoes out of warranty",
		rejectionReason: "Out of warranty period (60+ days)",
		status: "Approved",
		approvedBy: "Finance Admin",
		approvedAt: "2026-02-06",
		media: [
			"/images/product/product-06.jpg",
			"/images/product/product-07.jpg",
		],
		history: [
			{
				id: 1,
				event: "submitted",
				description: "Rejection request submitted",
				changedBy: "Mike Manager",
				changedAt: "2026-02-05 11:45 AM",
				status: "Submitted",
			},
			{
				id: 2,
				event: "reviewed",
				description: "Request received and under review",
				changedBy: "Finance Team",
				changedAt: "2026-02-05 03:30 PM",
				status: "Under Review",
				notes: "Warranty verification completed",
			},
			{
				id: 3,
				event: "approved",
				description: "Rejection approved by Finance",
				changedBy: "Finance Admin",
				changedAt: "2026-02-06 10:00 AM",
				status: "Approved",
				notes: "Out of warranty confirmed",
			},
		],
	},
	{
		id: 104,
		requestNumber: "RR-2026-0043",
		serviceName: "Insole Replacement",
		category: "Comfort Work",
		customerName: "Paolo Fernandez",
		orderedBy: "Sarah Lead",
		requestedOn: "2026-02-04",
		reason: "Normal wear and tear",
		rejectionReason: "Normal wear excluded from warranty",
		status: "Rejected",
		rejectedBy: "Finance Reviewer",
		rejectedAt: "2026-02-04",
		decisionReason: "Does not meet warranty criteria",
		media: [
			"/images/product/product-02.jpg",
			"/images/product/product-03.jpg",
		],
		history: [
			{
				id: 1,
				event: "submitted",
				description: "Rejection request submitted",
				changedBy: "Sarah Lead",
				changedAt: "2026-02-04 08:15 AM",
				status: "Submitted",
			},
			{
				id: 2,
				event: "reviewed",
				description: "Request received and under review",
				changedBy: "Finance Team",
				changedAt: "2026-02-04 01:00 PM",
				status: "Under Review",
				notes: "Checking warranty policy",
			},
			{
				id: 3,
				event: "rejected",
				description: "Rejection request rejected",
				changedBy: "Finance Reviewer",
				changedAt: "2026-02-04 03:45 PM",
				status: "Rejected",
				notes: "Normal wear is excluded from coverage",
			},
		],
	},
	{
		id: 105,
		requestNumber: "RR-2026-0042",
		serviceName: "Waterproofing Treatment",
		category: "Preventive Treatment",
		customerName: "Elena Mercado",
		orderedBy: "John Manager",
		requestedOn: "2026-02-03",
		reason: "Not covered service",
		rejectionReason: "Preventive treatment not in standard warranty",
		status: "Pending",
		media: [
			"/images/product/product-04.jpg",
			"/images/product/product-05.jpg",
			"/images/product/product-06.jpg",
		],
		history: [
			{
				id: 1,
				event: "submitted",
				description: "Rejection request submitted",
				changedBy: "John Manager",
				changedAt: "2026-02-03 02:30 PM",
				status: "Submitted",
			},
		],
	},
];

// Icons
const ArrowLeftIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
	</svg>
);

export default function HistoryRejection() {
	const { auth } = usePage().props as any;

	const [rejections, setRejections] = useState<RejectionType[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [historyStatusFilter, setHistoryStatusFilter] = useState("All");
	const [expandedRejections, setExpandedRejections] = useState<Record<number, boolean>>({});

	// Fetch rejection history from API
	useEffect(() => {
		const fetchRejections = async () => {
			try {
				setLoading(true);
				setError(null);
				
				// Fetch with status filter from backend
				const response = await axios.get('/api/manager/repairs/rejection-history', {
					params: {
						status: historyStatusFilter
					}
				});
				
				if (response.data.success) {
					setRejections(response.data.rejections);
				} else {
					setError(response.data.message || 'Failed to load rejection history');
				}
			} catch (err: any) {
				console.error('Error fetching rejection history:', err);
				setError(
					err.response?.data?.message || 
					err.message || 
					'Failed to load rejection history. Please try again.'
				);
			} finally {
				setLoading(false);
			}
		};
		
		fetchRejections();
	}, [historyStatusFilter]); // Refetch when filter changes

	return (
		<AppLayoutShopOwner>
			<Head title="All Rejection Requests History - Solespace ERP" />
			<div className="p-6 space-y-6">
				{/* Header */}
				<div className="flex items-center">
					<div className="flex items-center gap-4">
						<Link
							href="/shop-owner/repair-reject-approval"
							className="inline-flex items-center justify-center w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							title="Go back"
						>
							<ArrowLeftIcon className="w-5 h-5 text-gray-600 dark:text-gray-400" />
						</Link>
						<div>
							<h1 className="text-2xl font-semibold text-gray-900 dark:text-white">All Rejection Requests History</h1>
							<p className="text-gray-600 dark:text-gray-400">Complete timeline of all rejection requests</p>
						</div>
					</div>
				</div>

				{/* Filter Bar */}
				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-4">
					<div className="flex items-center gap-3 flex-wrap">
						<span className="text-sm font-medium text-gray-600 dark:text-gray-400">Filter by Status:</span>
						{["All", "Pending", "Approved", "Rejected"].map((status) => (
							<button
								key={status}
								onClick={() => setHistoryStatusFilter(status)}
								className={`px-4 py-2 rounded-full text-sm font-semibold transition-all duration-200 ${
									historyStatusFilter === status
										? "bg-blue-500 text-white dark:bg-blue-600"
										: "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
								}`}
							>
								{status}
							</button>
						))}
					</div>
				</div>

				{/* Loading State */}
				{loading && (
					<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-12 text-center">
						<div className="flex flex-col items-center gap-4">
							<div className="w-12 h-12 border-4 border-blue-200 border-t-blue-500 dark:border-gray-700 dark:border-t-blue-400 rounded-full animate-spin"></div>
							<p className="text-gray-600 dark:text-gray-400 font-medium">Loading rejection history...</p>
						</div>
					</div>
				)}

				{/* Error State */}
				{error && !loading && (
					<div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl p-6">
						<div className="flex items-start gap-4">
						<div className="shrink-0">
								<svg className="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
								</svg>
							</div>
							<div className="flex-1">
								<h3 className="text-red-800 dark:text-red-300 font-semibold mb-1">Error Loading History</h3>
								<p className="text-red-700 dark:text-red-400 text-sm">{error}</p>
								<button
									onClick={() => window.location.reload()}
									className="mt-3 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors"
								>
									Retry
								</button>
							</div>
						</div>
					</div>
				)}

				{/* Content Area */}
				{!loading && !error && (

				<div className="space-y-6">
					{rejections.length > 0 ? (
						rejections.map((rejection) => (
							<div key={rejection.id} className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden">
								{/* Header Button */}
								<button
									onClick={() => setExpandedRejections(prev => ({ ...prev, [rejection.id]: !prev[rejection.id] }))}
									className="w-full px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors flex items-start justify-between"
								>
									<div className="flex items-start gap-4 flex-1 text-left">
										<div className="mt-1">
											<svg className={`w-5 h-5 text-gray-600 dark:text-gray-400 transition-transform duration-200 ${expandedRejections[rejection.id] ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
												<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
											</svg>
										</div>
										<div className="flex-1">
											<h3 className="text-lg font-semibold text-gray-900 dark:text-white">{rejection.requestNumber}</h3>
											<p className="text-sm text-gray-600 dark:text-gray-400">{rejection.serviceName} - {rejection.customerName}</p>
											<p className="text-xs text-gray-500 dark:text-gray-500 mt-1">Ordered by: {rejection.orderedBy}</p>
										</div>
									</div>
									<span
										className={`px-3 py-1 rounded-full text-xs font-semibold whitespace-nowrap ${
											rejection.status === "Pending"
												? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300"
												: rejection.status === "Approved"
												? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
												: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
										}`}
									>
										{rejection.status}
									</span>
								</button>

								{/* Expanded Content */}
								{expandedRejections[rejection.id] && rejection.history && rejection.history.length > 0 && (
									<div className="border-t border-gray-200 dark:border-gray-800 px-6 py-4 bg-gray-50 dark:bg-gray-800/30">
										<div className="space-y-3">
											{rejection.history.map((event, index) => (
												<div key={event.id} className="flex gap-4">
													<div className="flex flex-col items-center min-w-fit">
														<div
															className={`w-8 h-8 rounded-full flex items-center justify-center font-semibold text-white text-xs ${
																event.event === "submitted"
																	? "bg-blue-500"
																	: event.event === "reviewed"
																	? "bg-yellow-500"
																	: event.event === "approved"
																	? "bg-green-500"
																	: event.event === "rejected"
																	? "bg-red-500"
																	: "bg-purple-500"
															}`}
														>
															•
														</div>
														{index < (rejection.history?.length || 0) - 1 && (
															<div className="w-0.5 h-8 bg-gray-300 dark:bg-gray-700 mt-1" />
														)}
													</div>
													<div className="flex-1 pb-2">
														<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-lg p-3">
															<div className="flex items-start justify-between gap-2 mb-2">
																<div>
																	<h5 className="font-semibold text-gray-900 dark:text-white text-sm capitalize">
																		{event.event === "submitted"
																			? "Request Submitted"
																			: event.event === "reviewed"
																			? "Under Review"
																			: event.event === "approved"
																			? "Request Approved"
																			: event.event === "rejected"
																			? "Request Rejected"
																			: "Comment Added"}
																	</h5>
																	<p className="text-xs text-gray-600 dark:text-gray-400">{event.description}</p>
																</div>
																<span
																	className={`px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap min-w-fit ${
																		event.status === "Submitted"
																			? "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
																			: event.status === "Under Review"
																			? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300"
																			: event.status === "Approved"
																			? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
																			: event.status === "Rejected"
																			? "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
																			: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
																	}`}
																>
																	{event.status}
																</span>
															</div>
															<div className="space-y-1 text-xs">
																<div className="flex justify-between">
																	<span className="text-gray-600 dark:text-gray-400">By:</span>
																	<span className="font-medium text-gray-900 dark:text-white">{event.changedBy}</span>
																</div>
																<div className="flex justify-between">
																	<span className="text-gray-600 dark:text-gray-400">Date:</span>
																	<span className="font-medium text-gray-900 dark:text-white">{event.changedAt}</span>
																</div>
																{event.notes && (
																	<div className="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
																		<p className="text-gray-600 dark:text-gray-400 mb-0.5">Notes:</p>
																		<p className="text-gray-700 dark:text-gray-300 italic">{event.notes}</p>
																	</div>
																)}
															</div>
														</div>
													</div>
												</div>
											))}
										</div>
									</div>
								)}

								{expandedRejections[rejection.id] && (!rejection.history || rejection.history.length === 0) && (
									<div className="border-t border-gray-200 dark:border-gray-800 px-6 py-4 bg-gray-50 dark:bg-gray-800/30">
										<p className="text-sm text-gray-500 dark:text-gray-400">No history available</p>
									</div>
								)}
							</div>
						))
					) : (
						<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-8 text-center">
							<p className="text-gray-500 dark:text-gray-400">No rejection requests found matching the selected filter</p>
						</div>
					)}
				</div>
				)}
			</div>
		</AppLayoutShopOwner>
	);
}
