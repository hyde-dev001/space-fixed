import { Head, usePage } from "@inertiajs/react";
import { useState, useCallback } from "react";
import Chart from "react-apexcharts";
import type { ApexOptions } from "apexcharts";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import type { ComponentType } from "react";
import axios from "axios";

type MetricColor = "success" | "warning" | "info" | "error";
type ChangeType = "increase" | "decrease";

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

const UsersIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3Zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z" />
  </svg>
);

const MessageIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2Zm-2 10H6v-2h12v2Zm0-3H6V7h12v2Zm0-3H6V4h12v2Z" />
  </svg>
);

const DealIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4Zm1 16.93V19h-2v-1.07c-1.72-.36-3-1.89-3-3.68h2c0 1.1.9 2 2 2s2-.9 2-2c0-1.1-.9-2-2-2-2.21 0-4-1.79-4-4 0-1.79 1.28-3.32 3-3.68V4h2v1.07c1.72.36 3 1.89 3 3.68h-2c0-1.1-.9-2-2-2s-2 .9-2 2c0 1.1.9 2 2 2 2.21 0 4 1.79 4 4 0 1.79-1.28 3.32-3 3.68Z" />
  </svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 1a11 11 0 1 0 11 11A11.01 11.01 0 0 0 12 1Zm1 11.41 3.29 3.3-1.42 1.41L11 13V6h2Z" />
  </svg>
);

interface MetricCardProps {
  title: string;
  value: string;
  change: number;
  changeType: ChangeType;
  icon: ComponentType<{ className?: string }>;
  color: MetricColor;
  description: string;
}

const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }: MetricCardProps) => {
  const getColorClasses = () => {
    switch (color) {
      case "success":
        return "from-green-500 to-emerald-600";
      case "warning":
        return "from-yellow-500 to-orange-600";
      case "info":
        return "from-blue-500 to-indigo-600";
      case "error":
        return "from-rose-500 to-red-600";
      default:
        return "from-gray-500 to-gray-600";
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="mb-4 flex items-center justify-between">
          <div className={`flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br ${getColorClasses()} shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="size-7 text-white drop-shadow-sm" />
          </div>

          <div
            className={`flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}
          >
            {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
            {Math.abs(change)}%
          </div>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 transition-colors duration-300 dark:text-white">{value}</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>
    </div>
  );
};

const RefreshIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
  </svg>
);

interface DashboardStats {
  activeCustomers: number;
  openConversations: number;
  pendingReviews: number;
  averageRating: number;
}
interface EngagementItem { channel: string; count: number; }
interface InteractionItem {
  conversation_id: number;
  customer_name: string;
  customer_email: string | null;
  last_message: string;
  last_message_at: string;
  status: string;
  priority: string;
}

export default function CRMDashboard() {
  const { initialStats, initialEngagement, initialInteractions } =
    usePage<{ initialStats: DashboardStats; initialEngagement: EngagementItem[]; initialInteractions: InteractionItem[] }>().props;

  const defaultStats: DashboardStats = { activeCustomers: 0, openConversations: 0, pendingReviews: 0, averageRating: 0 };

  const [stats, setStats]               = useState<DashboardStats>(initialStats    ?? defaultStats);
  const [engagementData, setEngagement] = useState<EngagementItem[]>(initialEngagement  ?? []);
  const [interactions, setInteractions] = useState<InteractionItem[]>(initialInteractions ?? []);
  const [refreshing, setRefreshing]     = useState(false);
  const [lastSynced, setLastSynced]     = useState<Date>(new Date());

  const handleRefresh = useCallback(async () => {
    setRefreshing(true);
    try {
      const { data } = await axios.get("/api/crm/dashboard-stats");
      setStats({
        activeCustomers:   data.active_customers   ?? 0,
        openConversations: data.open_conversations ?? 0,
        pendingReviews:    data.pending_reviews    ?? 0,
        averageRating:     data.average_rating     ?? 0,
      });
      setEngagement(data.engagement_by_channel ?? []);
      setInteractions(data.recent_interactions  ?? []);
      setLastSynced(new Date());
    } catch {
      // silently ignore
    } finally {
      setRefreshing(false);
    }
  }, []);
  const crmChartOptions: ApexOptions = {
    legend: { show: false },
    colors: ["#465FFF"],
    chart: {
      fontFamily: "Outfit, sans-serif",
      height: 320,
      type: "bar",
      toolbar: { show: false },
    },
    plotOptions: {
      bar: {
        horizontal: false,
        columnWidth: "45%",
        borderRadius: 6,
        borderRadiusApplication: "end",
      },
    },
    dataLabels: { enabled: false },
    stroke: { show: true, width: 2, colors: ["transparent"] },
    grid: {
      xaxis: { lines: { show: false } },
      yaxis: { lines: { show: true } },
    },
    xaxis: {
      type: "category",
      categories: engagementData.map((item) => item.channel),
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { fontSize: "12px", colors: ["#6B7280"] } },
    },
    yaxis: {
      labels: { style: { fontSize: "12px", colors: ["#6B7280"] } },
      title: { text: "Total Conversations", style: { fontSize: "12px", color: "#6B7280" } },
    },
    tooltip: {
      enabled: true,
      y: { formatter: (val: number) => `${val} conversations` },
    },
  };

  const crmChartSeries = [{ name: "Engagement", data: engagementData.map((item) => item.count) }];

  return (
    <AppLayoutERP>
      <Head title="CRM Dashboard - Solespace ERP" />

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold text-gray-900 dark:text-white">CRM Dashboard</h1>
            <p className="text-gray-600 dark:text-gray-400">Track customer engagement, pipeline, and support response performance.</p>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-xs text-gray-400 dark:text-gray-500">Synced {lastSynced.toLocaleTimeString()}</span>
            <button
              onClick={handleRefresh}
              disabled={refreshing}
              className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
            >
              <RefreshIcon className={`size-4 ${refreshing ? "animate-spin" : ""}`} />
              {refreshing ? "Refreshing…" : "Refresh"}
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
          <MetricCard
            title="Active Customers"
            value={stats.activeCustomers.toLocaleString()}
            change={0}
            changeType="increase"
            icon={UsersIcon}
            color="info"
            description="Customers with activity this month"
          />
          <MetricCard
            title="Open Conversations"
            value={stats.openConversations.toLocaleString()}
            change={0}
            changeType="increase"
            icon={MessageIcon}
            color="warning"
            description="Unread or unresolved customer messages"
          />
          <MetricCard
            title="Pending Reviews"
            value={stats.pendingReviews.toLocaleString()}
            change={0}
            changeType="decrease"
            icon={DealIcon}
            color="success"
            description="Customer reviews awaiting response"
          />
          <MetricCard
            title="Avg Rating"
            value={`${Number(stats.averageRating).toFixed(1)} / 5`}
            change={0}
            changeType="increase"
            icon={ClockIcon}
            color="error"
            description="Overall customer satisfaction score"
          />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 dark:border-gray-800 dark:bg-white/3 sm:px-6 sm:pt-6">
          <div className="mb-6">
            <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Customer Engagement by Channel</h3>
            <p className="mt-1 text-gray-500 text-theme-sm dark:text-gray-400">Conversation volume from primary CRM touchpoints</p>
          </div>

          <div className="max-w-full overflow-x-auto custom-scrollbar">
            <div className="min-w-175 xl:min-w-full">
              <Chart options={crmChartOptions} series={crmChartSeries} type="bar" height={320} />
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-6">
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/3">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Recent Customer Interactions</h3>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Latest conversation updates and support context</p>

            <div className="mt-4 max-h-72 space-y-3 overflow-y-auto pr-1">
              {interactions.length === 0 && (
                <p className="text-sm text-gray-500 text-center py-6">No recent interactions.</p>
              )}
              {interactions.map((item) => (
                <div key={item.conversation_id} className="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-gray-900 dark:text-white">{item.customer_name}</p>
                      <p className="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{item.last_message || "—"}</p>
                    </div>
                    <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300 capitalize">{item.status?.replace(/_/g, " ") ?? "open"}</span>
                  </div>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {item.last_message_at ? new Date(item.last_message_at).toLocaleString() : ""}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </AppLayoutERP>
  );
}
