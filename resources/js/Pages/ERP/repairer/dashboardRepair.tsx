import { Head, Link, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { useState } from "react";

type MetricColor = "success" | "error" | "warning" | "info";

type MetricCardProps = {
	title: string;
	value: number | string;
	change: number;
	changeType: "increase" | "decrease";
	icon: React.FC<{ className?: string }>;
	color: MetricColor;
	description: string;
};

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

const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }: MetricCardProps) => {
	const getColorClasses = () => {
		switch (color) {
			case "success":
				return "from-green-500 to-emerald-600";
			case "error":
				return "from-red-500 to-rose-600";
			case "warning":
				return "from-yellow-500 to-orange-600";
			case "info":
				return "from-blue-500 to-indigo-600";
			default:
				return "from-gray-500 to-gray-600";
		}
	};

	const displayValue = typeof value === "number" ? value.toLocaleString() : value;

	return (
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl">
			<div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
			<div className="relative">
				<div className="mb-4 flex items-center justify-between">
					<div className={`flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br ${getColorClasses()} shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
						<Icon className="size-7 text-white drop-shadow-sm" />
					</div>
					<div
						className={`flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold transition-all duration-300 ${
							changeType === "increase" ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"
						}`}
					>
						{changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
						{Math.abs(change)}%
					</div>
				</div>
				<div className="space-y-2">
					<p className="text-sm font-medium text-gray-600">{title}</p>
					<h3 className="text-3xl font-bold text-gray-900">{displayValue}</h3>
					<p className="text-xs text-gray-500">{description}</p>
				</div>
			</div>
		</div>
	);
};

const DashboardRepair: React.FC = () => {
	const { initialDashboard } = usePage().props as any;

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

	const mapCards = (cards: any[]): MetricCardProps[] =>
		(cards || []).map((card: any) => ({
			...card,
			icon: iconMap[card.title as keyof typeof iconMap] || WrenchIcon,
			color: colorMap[card.title as keyof typeof colorMap] || ("info" as MetricColor),
		}));

	const [metricCards, setMetricCards] = useState<MetricCardProps[]>(() => mapCards(initialDashboard?.metricCards ?? []));
	const [requestedServices, setRequestedServices] = useState<RequestedService[]>(initialDashboard?.requestedServices ?? []);
	const [revenueRows, setRevenueRows] = useState<RevenueRow[]>(initialDashboard?.revenueRows ?? []);
	const [recentRepairs, setRecentRepairs] = useState<RecentRepair[]>(initialDashboard?.recentRepairs ?? []);

	return (
		<AppLayoutERP>
			<Head title="Repair Dashboard" />
			<div className="space-y-8">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<h1 className="mb-2 text-3xl font-bold text-gray-900">Repair Dashboard</h1>
						<p className="text-gray-600">Overview of repair operations and activity.</p>
					</div>
				</div>

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
								<p className="text-sm text-gray-500">Repair revenue trends by period.</p>
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
									<th className="px-6 py-3 text-left font-semibold">Order</th>
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
										<td className="px-6 py-4 font-semibold text-gray-900">{repair.orderId}</td>
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
											<Link
												href="/erp/staff/job-orders-repair"
												className="text-sm font-semibold text-blue-600 hover:text-blue-700"
											>
												View
											</Link>
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</AppLayoutERP>
	);
};

export default DashboardRepair;
