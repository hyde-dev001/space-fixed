import { Head, Link, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { useEffect } from "react";
import axios from "axios";
import { erpUrl } from "@/utils/erpCapabilities";
import type { ErpCapabilities } from "@/types/erp";
import { DashboardMetricCard, DashboardPanel, DashboardShell, DashboardTrendChart } from "@/components/dashboard";

type MetricColor = "success" | "error" | "warning" | "info";

type PackageAnalytics = {
	overview: {
		total_packages: number;
		active_packages: number;
		inactive_packages: number;
		total_bookings: number;
		package_revenue: number;
		package_base_revenue: number;
		add_on_revenue: number;
		average_order_value: number;
		add_on_attach_rate: number;
		bookings_last_30_days: number;
		revenue_last_30_days: number;
	};
	top_packages: Array<{
		id: number;
		name: string;
		status: "active" | "inactive";
		booking_count: number;
		revenue: number;
		add_on_revenue: number;
		average_order_value: number;
		services_total_price: number;
		package_price: number;
		savings_amount: number;
		last_booked_at?: string | null;
	}>;
	monthly_trend: Array<{
		month: string;
		bookings: number;
		revenue: number;
	}>;
	recent_bookings: Array<{
		repair_request_id: number;
		order_number: string;
		package_id: number;
		package_name: string;
		booked_at?: string | null;
		final_total: number;
		add_ons_total: number;
		status: string;
	}>;
};

type MetricCardProps = {
	title: string;
	value: number | string;
	icon: React.FC<{ className?: string }>;
	color: MetricColor;
	description: string;
};

type MetricCardPayload = Omit<MetricCardProps, "icon" | "color">;

type RequestedService = {
  service: string;
  requests: number;
  avgTurnaround: string;
  lastRequested: string;
};

type RevenueRow = {
  period: string;
  orders: number;
  revenue: string;
  change: string;
};

type RecentRepair = {
  orderId: string;
  customer: string;
  service: string;
  status: string;
  amount: string;
	createdAt: string;
};

type RepairDashboardData = {
	metricCards?: MetricCardPayload[];
	requestedServices?: RequestedService[];
	revenueRows?: RevenueRow[];
	recentRepairs?: RecentRepair[];
};

const WrenchIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
	</svg>
);

const PackageIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 8V7a2 2 0 0 0-2-2h-4V3H9v2H5a2 2 0 0 0-2 2v1" />
		<rect x="3" y="8" width="18" height="13" rx="2" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 3v4M8 3v4" />
	</svg>
);

const ClipboardIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5h6a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 3h6v4H9V3z" />
	</svg>
);

const CheckCircleIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const MetricCard = ({ title, value, icon: Icon, color, description }: MetricCardProps) => {
	const displayValue = typeof value === "number" ? value.toLocaleString() : value;
	return <DashboardMetricCard label={title} value={displayValue} description={description} context="Current" icon={Icon} tone={color === "success" ? "success" : color === "warning" ? "warning" : color === "error" ? "danger" : "neutral"} />;
};

const DashboardRepair: React.FC = () => {
	const { initialDashboard, auth, erpCapabilities } = usePage().props as {
		initialDashboard?: RepairDashboardData | null;
		auth?: { erpActor?: { ownerMode?: boolean } };
		erpCapabilities?: ErpCapabilities;
	};
	const ownerMode = auth?.erpActor?.ownerMode === true;
	const jobOrdersUrl = erpUrl(erpCapabilities, "GET:erp.staff.job-orders-repair")
		?? (ownerMode ? null : "/erp/staff/job-orders-repair");

	// Icon mapping
	const iconMap = {
		"Open Repairs": WrenchIcon,
		"Ready for Pickup": PackageIcon,
		"New Requests": ClipboardIcon,
		"Completed Today": CheckCircleIcon,
	};

	const colorMap = {
		"Open Repairs": "info" as MetricColor,
		"Ready for Pickup": "warning" as MetricColor,
		"New Requests": "info" as MetricColor,
		"Completed Today": "success" as MetricColor,
	};

	const mapCards = (cards: MetricCardPayload[] = []): MetricCardProps[] =>
		cards.map((card) => ({
			title: card.title,
			value: card.value,
			description: card.description,
			icon: iconMap[card.title as keyof typeof iconMap] || WrenchIcon,
			color: colorMap[card.title as keyof typeof colorMap] || ("info" as MetricColor),
		}));

	const metricCards = mapCards(initialDashboard?.metricCards);
	const requestedServices = initialDashboard?.requestedServices ?? [];
	const revenueRows = initialDashboard?.revenueRows ?? [];
	const recentRepairs = initialDashboard?.recentRepairs ?? [];
	const [analytics, setAnalytics] = useState<PackageAnalytics | null>(null);

	useEffect(() => {
		if (ownerMode) return;

		axios.get("/api/repair-packages/analytics")
			.then((res) => {
				if (res.data?.success) setAnalytics(res.data.data || null);
			})
			.catch(() => { /* silently skip if no permission */ });
	}, [ownerMode]);

	return (
		<AppLayoutERP>
			<Head title="Repair Dashboard" />
			<DashboardShell
				testId="repairer-dashboard"
				title="Repair Dashboard"
				description="Overview of repair operations, service demand, and package activity."
				icon={WrenchIcon}
				actions={jobOrdersUrl && <Link href={jobOrdersUrl} className="inline-flex min-h-11 items-center rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-800 hover:border-gray-950 dark:border-gray-700 dark:text-gray-100 dark:hover:border-white">Open repair jobs</Link>}
			>

				<div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
					{metricCards.map((card) => (
						<MetricCard key={card.title} {...card} />
					))}
				</div>

				<div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
					<div className="rounded-xl border border-gray-200 bg-white">
						<div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
							<div>
								<h2 className="text-lg font-semibold text-gray-900">Most Requested Repair Services</h2>
								<p className="text-sm text-gray-500">Top services based on recent requests.</p>
							</div>
							<span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-600">Last 7 days</span>
						</div>
						<div className="overflow-x-auto">
							<table className="min-w-full divide-y divide-gray-100 text-sm">
								<thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
									<tr>
										<th className="px-6 py-3 text-left font-semibold">Service</th>
										<th className="px-6 py-3 text-left font-semibold">Requests</th>
										<th className="px-6 py-3 text-left font-semibold">Avg Turnaround</th>
										<th className="px-6 py-3 text-left font-semibold">Last Requested</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100">
									{requestedServices.length > 0 ? (
										requestedServices.map((service) => (
											<tr key={service.service} className="text-gray-700">
												<td className="px-6 py-4 font-medium text-gray-900">{service.service}</td>
												<td className="px-6 py-4">{service.requests}</td>
												<td className="px-6 py-4">{service.avgTurnaround}</td>
												<td className="px-6 py-4 text-gray-500">{service.lastRequested}</td>
											</tr>
										))
									) : (
										<tr>
											<td colSpan={4} className="px-6 py-8 text-center text-gray-500">
												No repair services requested in the last 7 days
											</td>
										</tr>
									)}
								</tbody>
							</table>
						</div>
					</div>

					<div className="rounded-xl border border-gray-200 bg-white">
						<div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
							<div>
								<h2 className="text-lg font-semibold text-gray-900">Total Revenue</h2>
								<p className="text-sm text-gray-500">Repair net revenue trends by period (excl. VAT, refunds deducted).</p>
							</div>
							<span className="rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-600">Updated today</span>
						</div>
						<div className="overflow-x-auto">
							<table className="min-w-full divide-y divide-gray-100 text-sm">
								<thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
									<tr>
										<th className="px-6 py-3 text-left font-semibold">Period</th>
										<th className="px-6 py-3 text-left font-semibold">Orders</th>
										<th className="px-6 py-3 text-left font-semibold">Revenue</th>
										<th className="px-6 py-3 text-left font-semibold">Change</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100">
									{revenueRows.map((row) => (
										<tr key={row.period} className="text-gray-700">
											<td className="px-6 py-4 font-medium text-gray-900">{row.period}</td>
											<td className="px-6 py-4">{row.orders}</td>
											<td className="px-6 py-4 font-semibold text-gray-900">{row.revenue}</td>
											<td className="px-6 py-4 text-green-600">{row.change}</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div className="rounded-xl border border-gray-200 bg-white">
					<div className="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
						<div>
							<h2 className="text-lg font-semibold text-gray-900">Recent Repair Services</h2>
							<p className="text-sm text-gray-500">Linked to Job Orders Repair.</p>
						</div>
					</div>
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-100 text-sm">
							<thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
								<tr>
									<th className="px-6 py-3 text-left font-semibold">Customer</th>
									<th className="px-6 py-3 text-left font-semibold">Service</th>
									<th className="px-6 py-3 text-left font-semibold">Status</th>
									<th className="px-6 py-3 text-left font-semibold">Amount</th>
									<th className="px-6 py-3 text-left font-semibold">Created</th>
									<th className="px-6 py-3"></th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-100">
								{recentRepairs.map((repair) => (
									<tr key={repair.orderId} className="text-gray-700">
										<td className="px-6 py-4">{repair.customer}</td>
										<td className="px-6 py-4">{repair.service}</td>
										<td className="px-6 py-4">
											<span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
												{repair.status}
											</span>
										</td>
										<td className="px-6 py-4 font-semibold text-gray-900">{repair.amount}</td>
										<td className="px-6 py-4 text-gray-500">{repair.createdAt}</td>
										<td className="px-6 py-4 text-right">
																	{jobOrdersUrl && (
																		<Link
																			href={jobOrdersUrl}
																			className="text-sm font-semibold text-blue-600 hover:text-blue-700"
																		>
																			View
																		</Link>
																	)}
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				</div>

				{analytics && (
					<>
					<DashboardPanel eyebrow="Package performance" title="Package revenue trend" description="Bookings and net revenue from the package analytics endpoint.">
						<DashboardTrendChart
							title="Package revenue trend"
							categories={analytics.monthly_trend.map((item) => item.month)}
							series={[{ name: "Net revenue", data: analytics.monthly_trend.map((item) => item.revenue) }]}
							height={280}
							options={{ yaxis: { labels: { formatter: (value) => `\u20b1${value.toLocaleString()}` } }, tooltip: { y: { formatter: (value) => `\u20b1${value.toLocaleString()}` } } }}
							summary={`Package revenue trend: ${analytics.monthly_trend.map((item) => `${item.month} \u20b1${Number(item.revenue).toFixed(2)}`).join(", ") || "no package trend data"}.`}
						/>
					</DashboardPanel>

					<div className="rounded-xl border border-gray-200 bg-white">
						<div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
							<div>
								<h2 className="text-lg font-semibold text-gray-900">Package Analytics</h2>
								<p className="text-sm text-gray-500">Bundle adoption, revenue, and add-on usage from package bookings.</p>
							</div>
						</div>

						<div className="p-6 space-y-6">
							<div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
									<p className="text-xs uppercase tracking-wide text-gray-500">Packages</p>
									<p className="mt-2 text-2xl font-bold text-gray-900">{analytics.overview.total_packages}</p>
									<p className="mt-1 text-xs text-gray-500">{analytics.overview.active_packages} active • {analytics.overview.inactive_packages} inactive</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
									<p className="text-xs uppercase tracking-wide text-gray-500">Bookings</p>
									<p className="mt-2 text-2xl font-bold text-gray-900">{analytics.overview.total_bookings}</p>
									<p className="mt-1 text-xs text-gray-500">{analytics.overview.bookings_last_30_days} in the last 30 days</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
									<p className="text-xs uppercase tracking-wide text-gray-500">Net Revenue (Excl. VAT)</p>
									<p className="mt-2 text-2xl font-bold text-gray-900">₱{Number(analytics.overview.package_revenue).toFixed(2)}</p>
									<p className="mt-1 text-xs text-gray-500">Refund-adjusted • ₱{Number(analytics.overview.revenue_last_30_days).toFixed(2)} in the last 30 days</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
									<p className="text-xs uppercase tracking-wide text-gray-500">Avg Order</p>
									<p className="mt-2 text-2xl font-bold text-gray-900">₱{Number(analytics.overview.average_order_value).toFixed(2)}</p>
									<p className="mt-1 text-xs text-gray-500">Add-ons ₱{Number(analytics.overview.add_on_revenue).toFixed(2)}</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
									<p className="text-xs uppercase tracking-wide text-gray-500">Add-on Attach Rate</p>
									<p className="mt-2 text-2xl font-bold text-gray-900">{analytics.overview.add_on_attach_rate}%</p>
									<p className="mt-1 text-xs text-gray-500">Orders with add-ons attached</p>
								</div>
							</div>

							<div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
								<div className="xl:col-span-2 rounded-xl border border-gray-200 bg-white overflow-hidden">
									<div className="px-4 py-3 border-b border-gray-100">
										<h4 className="text-sm font-semibold text-gray-900">Top Package Performance</h4>
									</div>
									<div className="overflow-x-auto">
										<table className="min-w-full divide-y divide-gray-100 text-sm">
											<thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
												<tr>
													<th className="px-4 py-3 text-left font-semibold">Package</th>
													<th className="px-4 py-3 text-left font-semibold">Bookings</th>
													<th className="px-4 py-3 text-left font-semibold">Net Revenue</th>
													<th className="px-4 py-3 text-left font-semibold">Avg Order</th>
													<th className="px-4 py-3 text-left font-semibold">Last Booked</th>
												</tr>
											</thead>
											<tbody className="divide-y divide-gray-100">
												{analytics.top_packages.length === 0 ? (
													<tr><td colSpan={5} className="px-4 py-6 text-center text-gray-500">No package bookings yet.</td></tr>
												) : (
													analytics.top_packages.slice(0, 5).map((item) => (
														<tr key={item.id} className="text-gray-700">
															<td className="px-4 py-3 align-top">
																<p className="font-medium text-gray-900">{item.name}</p>
																<p className="text-xs text-gray-500">Savings ₱{Number(item.savings_amount).toFixed(2)} • Add-ons ₱{Number(item.add_on_revenue).toFixed(2)}</p>
															</td>
															<td className="px-4 py-3">{item.booking_count}</td>
															<td className="px-4 py-3 font-semibold text-gray-900">₱{Number(item.revenue).toFixed(2)}</td>
															<td className="px-4 py-3">₱{Number(item.average_order_value).toFixed(2)}</td>
															<td className="px-4 py-3 text-gray-500">{item.last_booked_at ? new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric", year: "numeric" }).format(new Date(item.last_booked_at)) : "—"}</td>
														</tr>
													))
												)}
											</tbody>
										</table>
									</div>
								</div>

								<div className="space-y-6">
									<div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
										<div className="px-4 py-3 border-b border-gray-100">
											<h4 className="text-sm font-semibold text-gray-900">Monthly Trend</h4>
											<p className="mt-1 text-xs text-gray-500">Net revenue excludes VAT and deducts refunds.</p>
										</div>
										<div className="p-4 space-y-3">
											{analytics.monthly_trend.length === 0 ? (
												<p className="text-sm text-gray-500">No trend data yet.</p>
											) : (
												analytics.monthly_trend.map((item) => (
													<div key={item.month} className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
														<div>
															<p className="text-sm font-medium text-gray-900">{item.month}</p>
															<p className="text-xs text-gray-500">{item.bookings} booking{item.bookings !== 1 ? "s" : ""}</p>
														</div>
														<p className="text-sm font-semibold text-gray-900">₱{Number(item.revenue).toFixed(2)}</p>
													</div>
												))
											)}
										</div>
									</div>

									<div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
										<div className="px-4 py-3 border-b border-gray-100">
											<h4 className="text-sm font-semibold text-gray-900">Recent Package Bookings</h4>
										</div>
										<div className="p-4 space-y-3">
											{analytics.recent_bookings.length === 0 ? (
												<p className="text-sm text-gray-500">No recent package bookings yet.</p>
											) : (
												analytics.recent_bookings.map((booking) => (
													<div key={booking.repair_request_id} className="rounded-lg bg-gray-50 px-3 py-3">
														<div className="flex items-start justify-between gap-3">
															<div>
																<p className="text-sm font-medium text-gray-900">{booking.package_name}</p>
																<p className="text-xs text-gray-500">{booking.order_number} • {booking.booked_at ? new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric", year: "numeric" }).format(new Date(booking.booked_at)) : "—"}</p>
															</div>
															<span className="text-xs font-medium uppercase tracking-wide text-gray-500">{booking.status.replace(/_/g, " ")}</span>
														</div>
														<div className="mt-2 flex items-center justify-between text-xs text-gray-600">
															<span>Add-ons ₱{Number(booking.add_ons_total).toFixed(2)}</span>
															<span className="font-semibold text-gray-900">₱{Number(booking.final_total).toFixed(2)}</span>
														</div>
													</div>
												))
											)}
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					</>
				)}
			</DashboardShell>
		</AppLayoutERP>
	);
};

export default DashboardRepair;
