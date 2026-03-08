import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import AppLayoutERP from "../../layout/AppLayout_ERP";
import axios from "axios";
import BarChartOne from "../../components/charts/bar/BarChartOne";
import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";

// ─── Types ───────────────────────────────────────────────────────────────────

interface DailyPoint {
  date: string;
  active: number;
  utilization: number;
}

interface MonthlyPoint {
  month: string;
  month_key: string;
  intake: number;
  completed: number;
  revenue: number;
}

interface ServiceStat {
  id: number;
  name: string;
  list_price: number;
  request_count: number;
  total_revenue: number;
  avg_revenue_per_job: number;
  revenue_share_pct: number;
  demand_score: number;
  price_gap_pct: number | null;
  all_time_count: number;
}

interface Recommendation {
  id: string;
  severity: "critical" | "warning" | "info";
  title: string;
  message: string;
  action: string | null;
}

interface WorkloadMetrics {
  active_count: number;
  workload_limit: number;
  utilization_pct: number;
  avg_days: number | null;
  avg_hours: number | null;
  intake_rate: number;
  throughput: number;
  intake_total: number;
  completed_total: number;
  completion_rate: number | null;
  overdue_count: number;
  mom_change: number | null;
  daily_trend: DailyPoint[];
}

interface ServiceMetrics {
  period_revenue: number;
  this_month_revenue: number;
  last_month_revenue: number;
  rev_mom_change: number | null;
  total_services: number;
  services: ServiceStat[];
}

interface DssData {
  business_type: string; // 'repair' | 'retail' | 'both'
  // repair fields (present when business_type is 'repair' or 'both')
  workload?: WorkloadMetrics;
  services?: ServiceMetrics;
  monthly_trend?: MonthlyPoint[];
  // retail fields (present when business_type is 'retail' or 'both')
  retail_sales?: RetailSalesMetrics;
  retail_products?: RetailProductMetrics;
  retail_trend?: RetailMonthlyPoint[];
  // always present
  recommendations: Recommendation[];
  workload_limit: number;
  period_days: number;
}

// ─── Retail types ─────────────────────────────────────────────────────────────

interface ProductStat {
  product_name: string;
  total_qty: number;
  order_count: number;
  total_revenue: number;
  avg_price: number;
  revenue_share_pct: number;
}

interface LowStockProduct {
  id: number;
  name: string;
  stock_quantity: number;
  price: number;
  category: string | null;
}

interface RetailSalesMetrics {
  total_orders: number;
  completed_orders: number;
  active_orders: number;
  period_revenue: number;
  this_month_revenue: number;
  last_month_revenue: number;
  rev_mom_change: number | null;
  avg_order_value: number;
  completion_rate: number | null;
  unique_customers: number;
}

interface RetailProductMetrics {
  top_products: ProductStat[];
  low_stock_products: LowStockProduct[];
  total_products_sold: number;
  active_products: number;
}

interface RetailMonthlyPoint {
  month: string;
  month_key: string;
  orders: number;
  completed: number;
  revenue: number;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const fmt = (n: number) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(n);

const fmtNum = (n: number) =>
  new Intl.NumberFormat("en-PH").format(n);

const severityMeta: Record<Recommendation["severity"], { label: string; border: string; dot: string }> = {
  critical: { label: "Critical", border: "border-l-black", dot: "bg-black" },
  warning: { label: "Warning", border: "border-l-gray-700", dot: "bg-gray-700" },
  info: { label: "Info", border: "border-l-gray-500", dot: "bg-gray-500" },
};

const PERIOD_OPTIONS = [
  { label: "Last 7 days", value: 7 },
  { label: "Last 30 days", value: 30 },
  { label: "Last 90 days", value: 90 },
  { label: "Last 365 days", value: 365 },
];

type MetricTone = "success" | "error" | "warning" | "info";

const WrenchIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.7 6.3a4 4 0 11-5.4 5.4l-5.8 5.8a1.5 1.5 0 102.1 2.1l5.8-5.8a4 4 0 005.4-5.4l-2.1 2.1-2.1-2.1 2.1-2.1z" />
  </svg>
);

const CartIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4h2l1.6 8.2a2 2 0 002 1.6h7.8a2 2 0 001.9-1.4L21 7H6" />
    <circle cx="10" cy="19" r="1.5" strokeWidth={2} />
    <circle cx="17" cy="19" r="1.5" strokeWidth={2} />
  </svg>
);

const ClockMetricIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const PesoIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5h4a4 4 0 110 8H9m0-8v14m0-6h7" />
  </svg>
);

const BoxMetricIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
  </svg>
);

function SummaryStatCard({
  icon,
  label,
  value,
  sub,
  badge,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string | number;
  sub?: string;
  badge?: { text: string; positive?: boolean };
}) {
  const Icon = icon;

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
      <div className="flex items-center justify-center w-12 h-12 bg-gray-100 rounded-xl dark:bg-gray-800">
        <Icon className="text-gray-800 size-6 dark:text-white/90" />
      </div>

      <div className="flex items-end justify-between mt-5 gap-3">
        <div>
          <span className="text-sm text-gray-500 dark:text-gray-400">{label}</span>
          <h4 className="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">{value}</h4>
          {sub && <span className="text-xs text-gray-500 dark:text-gray-400 mt-1 block">{sub}</span>}
        </div>

        {badge && (
          <span className={`px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap ${badge.positive ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"}`}>
            {badge.text}
          </span>
        )}
      </div>
    </div>
  );
}

// ─── Mini bar chart (canvas-free, pure CSS/SVG) ───────────────────────────────

function BarChart({
  data,
  valueKey,
  limitKey,
  colorFn,
  labelKey,
  height = 80,
}: {
  data: Array<Record<string, any>>;
  valueKey: string;
  limitKey?: string;
  colorFn: (v: number, limit?: number) => string;
  labelKey: string;
  height?: number;
}) {
  const max = Math.max(...data.map(d => d[valueKey] as number), 1);

  return (
    <div className="flex items-end gap-[3px]" style={{ height }}>
      {data.map((d, i) => {
        const v = d[valueKey] as number;
        const limit = limitKey ? (d[limitKey] as number) : undefined;
        const pct = (v / max) * 100;
        const color = colorFn(v, limit);
        return (
          <div
            key={i}
            className="flex-1 flex flex-col items-center justify-end gap-0.5"
            title={`${d[labelKey]}: ${v}`}
            style={{ height: "100%" }}
          >
            <div
              className={`w-full rounded-t-sm transition-all duration-500 ${color}`}
              style={{ height: `${pct}%`, minHeight: v > 0 ? 2 : 0 }}
            />
          </div>
        );
      })}
    </div>
  );
}

// ─── KPI Card ─────────────────────────────────────────────────────────────────

function KpiCard({
  label,
  value,
  sub,
  badge,
  badgeColor = "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400",
  icon,
  color,
  className,
  loading,
}: {
  label: string;
  value: string | number;
  sub?: string;
  badge?: string;
  badgeColor?: string;
  icon: React.ComponentType<{ className?: string }>;
  color: MetricTone;
  className?: string;
  loading?: boolean;
}) {
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

  const Icon = icon;

  return (
    <div className={`group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700 ${className ?? ""}`}>
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>

          {badge && (
            <span className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold ${badgeColor}`}>
              {badge}
            </span>
          )}
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{label}</p>

          {loading ? (
            <div className="h-8 w-24 bg-gray-100 dark:bg-gray-800 rounded animate-pulse" />
          ) : (
            <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">{value}</h3>
          )}

          {sub && <p className="text-xs text-gray-500 dark:text-gray-400">{sub}</p>}
        </div>
      </div>
    </div>
  );
}

// ─── Utilization overview (Monthly Target style) ─────────────────────────────

function UtilizationOverviewCard({
  workload,
  workloadLimit,
  period,
  loading,
  className,
}: {
  workload?: WorkloadMetrics;
  workloadLimit: number;
  period: number;
  loading: boolean;
  className?: string;
}) {
  if (loading) {
    return (
      <div className={`rounded-2xl border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-white/[0.03] ${className ?? ""}`}>
        <div className="px-5 pt-5 bg-white shadow-default rounded-2xl pb-8 dark:bg-gray-900 sm:px-6 sm:pt-6">
          <div className="h-6 w-32 rounded bg-gray-200 dark:bg-gray-800 animate-pulse" />
          <div className="mt-2 h-4 w-48 rounded bg-gray-100 dark:bg-gray-800 animate-pulse" />
          <div className="mt-8 h-56 rounded-xl bg-gray-100 dark:bg-gray-800 animate-pulse" />
        </div>
        <div className="h-24 rounded-b-2xl bg-gray-50 dark:bg-gray-900/50 animate-pulse" />
      </div>
    );
  }

  if (!workload) return null;

  const utilizationPct = Math.max(0, Math.min(workload.utilization_pct, 100));
  const growth = workload.mom_change ?? 0;
  const isGrowthPositive = growth >= 0;
  const utilizationColor =
    utilizationPct >= 90
      ? "#D92D20"
      : utilizationPct >= 70
      ? "#F59E0B"
      : "#039855";

  const series = [parseFloat(utilizationPct.toFixed(2))];
  const options: ApexOptions = {
    colors: [utilizationColor],
    chart: {
      fontFamily: "Outfit, sans-serif",
      type: "radialBar",
      height: 280,
      sparkline: {
        enabled: true,
      },
    },
    plotOptions: {
      radialBar: {
        startAngle: -85,
        endAngle: 85,
        hollow: {
          size: "80%",
        },
        track: {
          background: "#E4E7EC",
          strokeWidth: "100%",
          margin: 5,
        },
        dataLabels: {
          name: {
            show: false,
          },
          value: {
            fontSize: "36px",
            fontWeight: "600",
            offsetY: -40,
            color: "#1D2939",
            formatter: (val: number) => `${Math.round(val)}%`,
          },
        },
      },
    },
    fill: {
      type: "solid",
      colors: [utilizationColor],
    },
    stroke: {
      lineCap: "round",
    },
    labels: ["Utilization"],
  };

  return (
    <div className={`rounded-2xl border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-white/[0.03] overflow-hidden h-full flex flex-col ${className ?? ""}`}>
      <div className="px-5 pt-5 bg-white shadow-default rounded-2xl pb-8 dark:bg-gray-900 sm:px-6 sm:pt-6 flex-1">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Repair Request</h3>
            <p className="mt-1 text-gray-500 text-theme-sm dark:text-gray-400">Current repair workload health</p>
          </div>
          <span className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            Limit: {workloadLimit}
          </span>
        </div>

        <div className="relative">
          <div className="max-h-[280px]">
            <Chart options={options} series={series} type="radialBar" height={280} />
          </div>

          <span className={`absolute left-1/2 top-full -translate-x-1/2 -translate-y-[95%] rounded-full px-3 py-1 text-xs font-medium ${
            isGrowthPositive
              ? "bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500"
              : "bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500"
          }`}>
            {isGrowthPositive ? "+" : ""}{growth.toFixed(1)}%
          </span>
        </div>

        <p className="mx-auto mt-8 w-full max-w-[360px] text-center text-sm text-gray-500 sm:text-base">
          {workload.active_count} active repairs out of {workloadLimit} capacity.
          {workload.overdue_count > 0
            ? ` ${workload.overdue_count} item(s) are overdue and need attention.`
            : " Workload is within healthy limits."}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-y-4 gap-x-2 px-6 py-4 sm:grid-cols-4 sm:gap-4 sm:py-5">
        <div>
          <p className="mb-1 text-center text-gray-500 text-theme-xs dark:text-gray-400 sm:text-sm">Active</p>
          <p className="text-center text-base font-semibold text-gray-800 dark:text-white/90 sm:text-lg">{workload.active_count}</p>
        </div>
        <div>
          <p className="mb-1 text-center text-gray-500 text-theme-xs dark:text-gray-400 sm:text-sm">Intake / day</p>
          <p className="text-center text-base font-semibold text-gray-800 dark:text-white/90 sm:text-lg">{workload.intake_rate}</p>
        </div>
        <div>
          <p className="mb-1 text-center text-gray-500 text-theme-xs dark:text-gray-400 sm:text-sm">Completed ({period}d)</p>
          <p className="text-center text-base font-semibold text-gray-800 dark:text-white/90 sm:text-lg">{workload.completed_total}</p>
        </div>
        <div>
          <p className="mb-1 text-center text-gray-500 text-theme-xs dark:text-gray-400 sm:text-sm">Overdue</p>
          <p className={`text-center text-base font-semibold sm:text-lg ${workload.overdue_count > 0 ? "text-red-600 dark:text-red-400" : "text-gray-800 dark:text-white/90"}`}>
            {workload.overdue_count}
          </p>
        </div>
      </div>
      <div className="border-t border-gray-200/70 dark:border-gray-800 px-6 py-3">
        <div className="grid grid-cols-1 gap-2 text-center text-xs sm:grid-cols-3">
          <div>
            <p className="text-gray-500 dark:text-gray-400">Completion Rate</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">
              {workload.completion_rate !== null ? `${workload.completion_rate}%` : "N/A"}
            </p>
          </div>
          <div>
            <p className="text-gray-500 dark:text-gray-400">Throughput / day</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">{workload.throughput}</p>
          </div>
          <div>
            <p className="text-gray-500 dark:text-gray-400">Avg Completion</p>
            <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">
              {workload.avg_days !== null && workload.avg_days !== undefined ? `${workload.avg_days}d` : "N/A"}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

const DssInsights: React.FC = () => {
  const { props } = usePage();
  const auth = (props as any).auth;
  const isErpUser = !auth?.shop_owner && !!auth?.user;

  const [data, setData]       = useState<DssData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState<string | null>(null);
  const [period, setPeriod]   = useState(30);
  const [tab, setTab]         = useState<"workload" | "revenue" | "retail" | "trend">("workload");
  const [workloadWindow, setWorkloadWindow] = useState<7 | 14>(14);
  const [isRecDropdownOpen, setIsRecDropdownOpen] = useState(false);
  const [recFilter, setRecFilter] = useState<"all" | Recommendation["severity"]>("all");
  const [recQuery, setRecQuery] = useState("");
  const recDropdownRef = useRef<HTMLDivElement | null>(null);

  const isRepair = !data || data.business_type === "repair" || data.business_type === "both";
  const isRetail = !!data && (data.business_type === "retail" || data.business_type === "both");

  const apiUrl = isErpUser
    ? `/api/manager/dss-insights`
    : `/api/shop-owner/dashboard/dss-insights`;

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await axios.get(apiUrl, { params: { period } });
      setData(res.data);
      // If the shop is retail-only, default to the retail tab
      if (res.data.business_type === "retail") {
        setTab("retail");
      } else {
        setTab("workload");
      }
    } catch (e: any) {
      setError(e?.response?.data?.error || "Failed to load DSS data.");
    } finally {
      setLoading(false);
    }
  }, [apiUrl, period]);

  useEffect(() => { fetchData(); }, [fetchData]);

  useEffect(() => {
    if (!isRecDropdownOpen) return;

    const handleClickOutside = (event: MouseEvent) => {
      if (recDropdownRef.current && !recDropdownRef.current.contains(event.target as Node)) {
        setIsRecDropdownOpen(false);
      }
    };

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setIsRecDropdownOpen(false);
      }
    };

    document.addEventListener("mousedown", handleClickOutside);
    document.addEventListener("keydown", handleEscape);

    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
      document.removeEventListener("keydown", handleEscape);
    };
  }, [isRecDropdownOpen]);

  const recommendationStats = useMemo(() => {
    const list = data?.recommendations ?? [];
    return {
      total: list.length,
      critical: list.filter((r) => r.severity === "critical").length,
      warning: list.filter((r) => r.severity === "warning").length,
      info: list.filter((r) => r.severity === "info").length,
    };
  }, [data]);

  const filteredRecommendations = useMemo(() => {
    const list = data?.recommendations ?? [];
    const q = recQuery.trim().toLowerCase();
    return list.filter((rec) => {
      const matchSeverity = recFilter === "all" || rec.severity === recFilter;
      const haystack = `${rec.title} ${rec.message} ${rec.action ?? ""}`.toLowerCase();
      const matchQuery = q.length === 0 || haystack.includes(q);
      return matchSeverity && matchQuery;
    });
  }, [data, recFilter, recQuery]);

  // ─── DSS content ────────────────────────────────────────────────────────────

  const content = (
    <>
      <Head title="DSS Insights - Shop Owner" />
      <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white/90">
            Decision Support System
          </h1>
          <p className="mt-1 text-gray-500 dark:text-gray-400">
            {!data || data.business_type === "repair"
              ? "Rule-based insights for repair workload, service revenue, and actionable recommendations."
              : data.business_type === "retail"
              ? "Rule-based insights for retail sales, product performance, and actionable recommendations."
              : "Rule-based insights for repair workload, retail sales, and actionable recommendations."}
          </p>
        </div>
        <div className="flex items-center gap-3">
          <div className="relative" ref={recDropdownRef}>
            <button
              type="button"
              onClick={() => setIsRecDropdownOpen((prev) => !prev)}
              aria-label="Show actionable recommendations"
              className="relative h-10 w-10 rounded-lg border border-gray-200 bg-white text-amber-500 hover:bg-amber-50 transition-colors dark:border-gray-700 dark:bg-gray-800 dark:text-amber-300 dark:hover:bg-amber-900/20"
            >
              <span className="text-xl font-bold leading-none">!</span>
              {(data?.recommendations?.length ?? 0) > 0 && (
                <span className="absolute -top-1.5 -right-1.5 min-w-5 h-5 px-1 rounded-full bg-amber-400 text-gray-900 text-[10px] font-bold flex items-center justify-center">
                  {Math.min(99, data?.recommendations.length ?? 0)}
                </span>
              )}
            </button>

            {isRecDropdownOpen && (
              <div className="absolute right-0 top-full z-30 mt-2 w-[480px] max-w-[95vw] rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                  <h4 className="text-sm font-semibold text-gray-900 dark:text-white">Actionable Recommendations</h4>
                  <button
                    type="button"
                    onClick={() => setIsRecDropdownOpen(false)}
                    className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    aria-label="Close recommendations dropdown"
                  >
                    ✕
                  </button>
                </div>

                <div className="px-4 py-3 border-b border-gray-100 dark:border-gray-800 space-y-3">
                  <div className="grid grid-cols-4 gap-2 text-[11px]">
                    <button
                      onClick={() => setRecFilter("all")}
                      className={`rounded-md border px-2 py-1 font-medium ${recFilter === "all" ? "border-black bg-black text-white" : "border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300"}`}
                    >
                      All ({recommendationStats.total})
                    </button>
                    <button
                      onClick={() => setRecFilter("critical")}
                      className={`rounded-md border px-2 py-1 font-medium ${recFilter === "critical" ? "border-black bg-black text-white" : "border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300"}`}
                    >
                      Critical ({recommendationStats.critical})
                    </button>
                    <button
                      onClick={() => setRecFilter("warning")}
                      className={`rounded-md border px-2 py-1 font-medium ${recFilter === "warning" ? "border-black bg-black text-white" : "border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300"}`}
                    >
                      Warning ({recommendationStats.warning})
                    </button>
                    <button
                      onClick={() => setRecFilter("info")}
                      className={`rounded-md border px-2 py-1 font-medium ${recFilter === "info" ? "border-black bg-black text-white" : "border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300"}`}
                    >
                      Info ({recommendationStats.info})
                    </button>
                  </div>
                  <input
                    type="text"
                    value={recQuery}
                    onChange={(e) => setRecQuery(e.target.value)}
                    placeholder="Search recommendations..."
                    className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-black dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                  />
                </div>

                <div className="max-h-[360px] overflow-y-auto p-3 space-y-2">
                  {loading ? (
                    [...Array(3)].map((_, idx) => (
                      <div key={idx} className="h-16 rounded-lg bg-gray-50 dark:bg-gray-800 animate-pulse" />
                    ))
                  ) : filteredRecommendations.length > 0 ? (
                    filteredRecommendations.map((rec) => (
                      <div key={rec.id} className={`rounded-lg border border-gray-200 border-l-4 ${severityMeta[rec.severity].border} bg-white p-3 dark:border-gray-700 dark:bg-gray-900`}>
                        <div className="flex items-start gap-2">
                          <span className={`mt-1 h-2.5 w-2.5 rounded-full ${severityMeta[rec.severity].dot}`} />
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2">
                              <p className="text-sm font-semibold text-gray-900 dark:text-white">{rec.title}</p>
                              <span className="rounded-full border border-gray-300 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">
                                {severityMeta[rec.severity].label}
                              </span>
                            </div>
                            <p className="text-xs leading-relaxed text-gray-700 dark:text-gray-300 mt-1">{rec.message}</p>
                            {rec.action && (
                              <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-2">Action: {rec.action}</p>
                            )}
                          </div>
                        </div>
                      </div>
                    ))
                  ) : (
                    <div className="rounded-lg border border-dashed border-gray-200 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                      No matching recommendations.
                    </div>
                  )}
                </div>

                <div className="px-4 py-2 border-t border-gray-100 dark:border-gray-800 text-[11px] text-gray-500 dark:text-gray-400">
                  Use filters and search to focus on priority actions.
                </div>
              </div>
            )}
          </div>

          <select
            aria-label="Analysis period"
            value={period}
            onChange={e => setPeriod(Number(e.target.value))}
            className="text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            {PERIOD_OPTIONS.map(o => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
          <button
            onClick={fetchData}
            disabled={loading}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {loading ? "Loading…" : "Refresh"}
          </button>
        </div>
      </div>

      {/* Error state */}
      {error && (
        <div className="bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-400 rounded-xl p-4 text-sm">
          {error}
        </div>
      )}

      {/* ── KPI summary row ── */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 md:gap-6">
        {/* Active Repairs card — repair + both only */}
        {isRepair && (
          <KpiCard
            loading={loading}
            label="Active Repairs"
            value={data?.workload ? fmtNum(data.workload.active_count) : "–"}
            sub={data ? `of ${data.workload_limit} limit` : undefined}
            badge={
              data?.workload
                ? data.workload.utilization_pct >= 90
                  ? "Critical"
                  : data.workload.utilization_pct >= 70
                  ? "High"
                  : data.workload.utilization_pct >= 40
                  ? "Moderate"
                  : "Low"
                : undefined
            }
            badgeColor={
              data?.workload
                ? data.workload.utilization_pct >= 90
                  ? "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400"
                  : data.workload.utilization_pct >= 70
                  ? "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                  : "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400"
                : undefined
            }
            icon={WrenchIcon}
            color="warning"
          />
        )}

        {/* Active Orders card — retail + both only */}
        {isRetail && (
          <KpiCard
            loading={loading}
            label="Active Orders"
            value={data?.retail_sales ? fmtNum(data.retail_sales.active_orders) : "–"}
            sub={data?.retail_sales ? `${data.retail_sales.total_orders} total (${period}d)` : undefined}
            badge={
              data?.retail_sales
                ? data.retail_sales.completion_rate !== null
                  ? `${data.retail_sales.completion_rate}% fulfilled`
                  : undefined
                : undefined
            }
            badgeColor={
              (data?.retail_sales?.completion_rate ?? 100) >= 80
                ? "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400"
                : (data?.retail_sales?.completion_rate ?? 100) >= 60
                ? "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400"
            }
            icon={CartIcon}
            color="info"
          />
        )}

        {/* Avg Completion Time — repair + both */}
        {isRepair && (
          <KpiCard
            loading={loading}
            label="Avg. Completion Time"
            value={data?.workload?.avg_days !== null && data?.workload?.avg_days !== undefined ? `${data.workload.avg_days}d` : "–"}
            sub={data?.workload?.avg_hours ? `(${data.workload.avg_hours}h avg)` : "No completed data"}
            icon={ClockMetricIcon}
            color="info"
          />
        )}

        {/* Avg Order Value — retail + both */}
        {isRetail && (
          <KpiCard
            loading={loading}
            label="Avg. Order Value"
            value={data?.retail_sales ? fmt(data.retail_sales.avg_order_value) : "–"}
            sub={data?.retail_sales ? `${data.retail_sales.unique_customers} unique customers` : undefined}
            icon={PesoIcon}
            color="success"
          />
        )}

        {/* This Month Revenue — repair + both */}
        {isRepair && (
          <KpiCard
            loading={loading}
            label="This Month Repair Revenue"
            value={data?.services ? fmt(data.services.this_month_revenue) : "–"}
            sub="Completed paid repairs"
            badge={
              data?.services?.rev_mom_change !== null && data?.services?.rev_mom_change !== undefined
                ? `${data.services.rev_mom_change > 0 ? "+" : ""}${data.services.rev_mom_change}% vs last mo.`
                : undefined
            }
            badgeColor={
              (data?.services?.rev_mom_change ?? 0) >= 0
                ? "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400"
            }
            icon={PesoIcon}
            color="success"
          />
        )}

        {/* This Month Retail Revenue — retail + both */}
        {isRetail && (
          <KpiCard
            loading={loading}
            label="This Month Retail Revenue"
            value={data?.retail_sales ? fmt(data.retail_sales.this_month_revenue) : "–"}
            sub="Completed + delivered orders"
            badge={
              data?.retail_sales?.rev_mom_change !== null && data?.retail_sales?.rev_mom_change !== undefined
                ? `${data.retail_sales.rev_mom_change > 0 ? "+" : ""}${data.retail_sales.rev_mom_change}% vs last mo.`
                : undefined
            }
            badgeColor={
              (data?.retail_sales?.rev_mom_change ?? 0) >= 0
                ? "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400"
            }
            icon={PesoIcon}
            color="success"
          />
        )}

      </div>

      {/* ── Tab navigation ── */}
      <div className="flex items-center gap-2 flex-wrap">
        {isRepair && (
          <button
            onClick={() => setTab("workload")}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
              tab === "workload"
                ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
            }`}
          >
            Workload
          </button>
        )}
        {isRepair && (
          <button
            onClick={() => setTab("revenue")}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
              tab === "revenue"
                ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
            }`}
          >
            Revenue
          </button>
        )}
        {isRetail && (
          <button
            onClick={() => setTab("retail")}
            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
              tab === "retail"
                ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
            }`}
          >
            Retail
          </button>
        )}
        <button
          onClick={() => setTab("trend")}
          className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
            tab === "trend"
              ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
              : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
          }`}
        >
          Trends
        </button>
      </div>

      {/* ── WORKLOAD TAB ── */}
      {tab === "workload" && isRepair && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
          {/* Utilization panel */}
          <UtilizationOverviewCard
            workload={data?.workload}
            workloadLimit={data?.workload_limit ?? 0}
            period={period}
            loading={loading}
            className="h-full"
          />

          {/* Daily active chart */}
          <div className="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="flex flex-col gap-4 mb-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Daily Active Repairs</h3>
                <p className="mt-1 text-gray-500 text-theme-sm dark:text-gray-400">Track active jobs against your workload limit</p>
              </div>
              <div className="flex items-center gap-3 sm:justify-end">
                <div className="flex items-center gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-gray-900">
                  <button
                    onClick={() => setWorkloadWindow(7)}
                    className={`px-3 py-2 rounded-md text-theme-sm font-medium ${
                      workloadWindow === 7
                        ? "bg-white text-gray-900 shadow-theme-xs dark:bg-gray-800 dark:text-white"
                        : "text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                    }`}
                  >
                    Last 7 Days
                  </button>
                  <button
                    onClick={() => setWorkloadWindow(14)}
                    className={`px-3 py-2 rounded-md text-theme-sm font-medium ${
                      workloadWindow === 14
                        ? "bg-white text-gray-900 shadow-theme-xs dark:bg-gray-800 dark:text-white"
                        : "text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                    }`}
                  >
                    Last 14 Days
                  </button>
                </div>
                {data && (
                  <span className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    Limit: {data.workload_limit}
                  </span>
                )}
              </div>
            </div>

            {loading ? (
              <div className="h-72 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
            ) : data?.workload ? (
              <div className="space-y-5">
                {(() => {
                  const trend = data.workload.daily_trend ?? [];
                  const viewData = trend.slice(-workloadWindow);
                  const avgActive = viewData.length
                    ? viewData.reduce((sum, point) => sum + point.active, 0) / viewData.length
                    : 0;
                  const peakActive = viewData.length
                    ? Math.max(...viewData.map((point) => point.active))
                    : 0;
                  const criticalDays = viewData.filter(
                    (point) => (point.active / Math.max(data.workload_limit, 1)) * 100 >= 90
                  ).length;

                  return (
                    <>
                      <BarChartOne
                        categories={viewData.map((point) => point.date.slice(5))}
                        seriesData={viewData.map((point) => point.active)}
                        seriesName="Active Repairs"
                        color="#465fff"
                        height={300}
                        minWidthClass="min-w-[650px] xl:min-w-full"
                        tooltipFormatter={(val) => `${val.toLocaleString("en-PH")} active repairs`}
                      />

                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900/40">
                          <p className="text-xs text-gray-500 dark:text-gray-400">Average Active</p>
                          <p className="mt-1 text-base font-semibold text-gray-800 dark:text-white/90">{avgActive.toFixed(1)}</p>
                        </div>
                        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900/40">
                          <p className="text-xs text-gray-500 dark:text-gray-400">Peak Active</p>
                          <p className="mt-1 text-base font-semibold text-gray-800 dark:text-white/90">{peakActive.toLocaleString("en-PH")}</p>
                        </div>
                        <div className="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-900/40">
                          <p className="text-xs text-gray-500 dark:text-gray-400">Critical Days</p>
                          <p className={`mt-1 text-base font-semibold ${criticalDays > 0 ? "text-red-600 dark:text-red-400" : "text-gray-800 dark:text-white/90"}`}>
                            {criticalDays}
                          </p>
                        </div>
                      </div>
                    </>
                  );
                })()}
              </div>
            ) : null}

            {/* Intake vs throughput comparison */}
            {data?.workload && (
              <div className="mt-5 pt-4 border-t border-gray-100 dark:border-gray-800 grid grid-cols-1 gap-4 text-sm xl:grid-cols-2">
                <div>
                  <div className="text-gray-500 dark:text-gray-400 text-xs mb-1">Intake vs Throughput ({period}d)</div>
                  <div className="flex items-center gap-2">
                    <div className="flex-1 h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-blue-400 rounded-full"
                        style={{ width: `${Math.min(100, data.workload.throughput > 0 ? (data.workload.intake_rate / Math.max(data.workload.intake_rate, data.workload.throughput)) * 100 : 0)}%` }}
                      />
                    </div>
                    <span className="text-xs font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                      {data.workload?.intake_rate}/d intake
                    </span>
                  </div>
                  <div className="flex items-center gap-2 mt-1">
                    <div className="flex-1 h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-green-400 rounded-full"
                        style={{ width: `${Math.min(100, (data.workload?.intake_rate ?? 0) > 0 ? ((data.workload?.throughput ?? 0) / Math.max(data.workload?.intake_rate ?? 0, data.workload?.throughput ?? 0)) * 100 : 0)}%` }}
                      />
                    </div>
                    <span className="text-xs font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                      {data.workload?.throughput}/d done
                    </span>
                  </div>
                </div>
                <div>
                  <div className="text-gray-500 dark:text-gray-400 text-xs mb-1">Month-over-Month Change</div>
                  {data.workload?.mom_change !== null && data.workload?.mom_change !== undefined ? (
                    <div className={`text-lg font-bold ${(data.workload?.mom_change ?? 0) >= 0 ? "text-blue-600 dark:text-blue-400" : "text-red-500 dark:text-red-400"}`}>
                      {(data.workload?.mom_change ?? 0) > 0 ? "+" : ""}{data.workload?.mom_change}%
                    </div>
                  ) : (
                    <div className="text-gray-400 dark:text-gray-500 text-sm">N/A (no prior month data)</div>
                  )}
                  <div className="text-xs text-gray-400 dark:text-gray-500">vs last month's intake</div>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── REVENUE TAB ── */}
      {tab === "revenue" && isRepair && (
        <div className="space-y-5">
          {/* Revenue summary cards */}
          {data?.services && (
            <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
              <SummaryStatCard
                icon={PesoIcon}
                label={`Period Revenue (${period}d)`}
                value={fmt(data.services.period_revenue)}
                sub="Completed paid repairs"
              />

              <SummaryStatCard
                icon={PesoIcon}
                label="This Month"
                value={fmt(data.services.this_month_revenue)}
                sub="Current month total"
                badge={
                  data.services.rev_mom_change !== null
                    ? {
                        text: `${data.services.rev_mom_change > 0 ? "+" : ""}${data.services.rev_mom_change}%`,
                        positive: data.services.rev_mom_change >= 0,
                      }
                    : undefined
                }
              />

              <SummaryStatCard
                icon={BoxMetricIcon}
                label="Active Services"
                value={data.services.total_services}
                sub="with revenue in period"
              />
            </div>
          )}

          {/* Service revenue table */}
          <div className="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="p-5 border-b border-gray-100 dark:border-gray-800">
              <h3 className="font-semibold text-gray-700 dark:text-gray-300">Service Revenue Breakdown</h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Ranked by revenue for the selected period (completed + paid repairs)</p>
            </div>
            {loading ? (
              <div className="p-5 space-y-3">
                {[...Array(5)].map((_, i) => (
                  <div key={i} className="h-10 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
                ))}
              </div>
            ) : data?.services && data.services.services.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs">
                      <th className="text-left px-5 py-3">Service</th>
                      <th className="text-right px-4 py-3">Jobs</th>
                      <th className="text-right px-4 py-3">Listed Price</th>
                      <th className="text-right px-4 py-3">Avg Rev/Job</th>
                      <th className="text-right px-4 py-3">Total Revenue</th>
                      <th className="text-right px-4 py-3">Rev Share</th>
                      <th className="text-right px-4 py-3">Demand</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {data.services?.services.map((svc, idx) => (
                      <tr key={svc.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td className="px-5 py-3 font-medium text-gray-900 dark:text-white">
                          <div className="flex items-center gap-2">
                            <span className="text-xs text-gray-400 dark:text-gray-500 w-5">#{idx + 1}</span>
                            {svc.name}
                          </div>
                        </td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{svc.request_count}</td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">
                          {svc.list_price > 0 ? fmt(svc.list_price) : <span className="text-gray-400">–</span>}
                        </td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{fmt(svc.avg_revenue_per_job)}</td>
                        <td className="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{fmt(svc.total_revenue)}</td>
                        <td className="px-4 py-3 text-right">
                          <div className="flex items-center justify-end gap-2">
                            <div className="w-16 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                              <div
                                className="h-full bg-blue-400 dark:bg-blue-500 rounded-full"
                                style={{ width: `${svc.revenue_share_pct}%` }}
                              />
                            </div>
                            <span className="text-gray-600 dark:text-gray-400 text-xs w-10 text-right">{svc.revenue_share_pct}%</span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-right">
                          <div className="flex items-center justify-end gap-1">
                            <div className="w-10 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                              <div
                                className={`h-full rounded-full ${svc.demand_score >= 75 ? "bg-green-400" : svc.demand_score >= 40 ? "bg-blue-400" : "bg-gray-300"}`}
                                style={{ width: `${svc.demand_score}%` }}
                              />
                            </div>
                            <span className="text-xs text-gray-500 dark:text-gray-400 w-8 text-right">{svc.demand_score}%</span>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-10 text-center text-gray-400 dark:text-gray-500">
                <div className="text-4xl mb-3">📊</div>
                <div className="font-medium">No service revenue data yet</div>
                <div className="text-sm mt-1">Data appears once repairs are completed and paid.</div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── RETAIL TAB ── */}
      {tab === "retail" && isRetail && (
        <div className="space-y-5">
          {/* Retail KPI cards */}
          {data?.retail_sales && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">Period Revenue ({period}d)</div>
                <div className="text-2xl font-bold text-gray-900 dark:text-white">{fmt(data.retail_sales.period_revenue)}</div>
                <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">{data.retail_sales.completed_orders} completed orders</div>
              </div>
              <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">Avg. Order Value</div>
                <div className="text-2xl font-bold text-gray-900 dark:text-white">{fmt(data.retail_sales.avg_order_value)}</div>
                <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">{data.retail_sales.unique_customers} unique customers</div>
              </div>
              <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">Order Fulfillment</div>
                <div className="text-2xl font-bold text-gray-900 dark:text-white">
                  {data.retail_sales.completion_rate !== null ? `${data.retail_sales.completion_rate}%` : "–"}
                </div>
                <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                  {data.retail_sales.completed_orders} of {data.retail_sales.total_orders} orders
                </div>
              </div>
              <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">Products Sold</div>
                <div className="text-2xl font-bold text-gray-900 dark:text-white">
                  {data.retail_products?.total_products_sold ?? "–"}
                </div>
                <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                  of {data.retail_products?.active_products ?? "–"} active products
                </div>
              </div>
            </div>
          )}

          {/* Top Products table */}
          <div className="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="p-5 border-b border-gray-100 dark:border-gray-800">
              <h3 className="font-semibold text-gray-700 dark:text-gray-300">Top-Selling Products</h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Ranked by revenue from completed orders in the selected period
              </p>
            </div>
            {loading ? (
              <div className="p-5 space-y-3">
                {[...Array(5)].map((_, i) => (
                  <div key={i} className="h-10 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
                ))}
              </div>
            ) : data?.retail_products?.top_products && data.retail_products.top_products.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs">
                      <th className="text-left px-5 py-3">Product</th>
                      <th className="text-right px-4 py-3">Orders</th>
                      <th className="text-right px-4 py-3">Qty Sold</th>
                      <th className="text-right px-4 py-3">Avg Price</th>
                      <th className="text-right px-4 py-3">Total Revenue</th>
                      <th className="text-right px-4 py-3">Rev Share</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {data.retail_products.top_products.map((p, idx) => (
                      <tr key={idx} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td className="px-5 py-3 font-medium text-gray-900 dark:text-white">
                          <div className="flex items-center gap-2">
                            <span className="text-xs text-gray-400 dark:text-gray-500 w-5">#{idx + 1}</span>
                            {p.product_name}
                          </div>
                        </td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{p.order_count}</td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{p.total_qty}</td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{fmt(p.avg_price)}</td>
                        <td className="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">{fmt(p.total_revenue)}</td>
                        <td className="px-4 py-3 text-right">
                          <div className="flex items-center justify-end gap-2">
                            <div className="w-16 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                              <div
                                className="h-full bg-emerald-400 dark:bg-emerald-500 rounded-full"
                                style={{ width: `${p.revenue_share_pct}%` }}
                              />
                            </div>
                            <span className="text-gray-600 dark:text-gray-400 text-xs w-10 text-right">
                              {p.revenue_share_pct}%
                            </span>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-10 text-center text-gray-400 dark:text-gray-500">
                <div className="text-4xl mb-3">🛍️</div>
                <div className="font-medium">No retail sales data yet</div>
                <div className="text-sm mt-1">Data appears once orders are completed or delivered.</div>
              </div>
            )}
          </div>

          {/* Low-stock alerts */}
          {data?.retail_products?.low_stock_products && data.retail_products.low_stock_products.length > 0 && (
            <div className="bg-white dark:bg-gray-900 rounded-2xl border border-amber-200 dark:border-amber-800/50 shadow-sm overflow-hidden">
              <div className="p-5 border-b border-amber-100 dark:border-amber-800/30 flex items-center gap-2">
                <span>⚠️</span>
                <h3 className="font-semibold text-amber-700 dark:text-amber-400">Low Stock Products</h3>
                <span className="ml-auto text-xs text-amber-600 dark:text-amber-500">
                  {data.retail_products.low_stock_products.length} item(s) need restocking
                </span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-amber-50 dark:bg-amber-950/20 text-gray-500 dark:text-gray-400 text-xs">
                      <th className="text-left px-5 py-3">Product</th>
                      <th className="text-left px-4 py-3">Category</th>
                      <th className="text-right px-4 py-3">Price</th>
                      <th className="text-right px-4 py-3">Stock</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {data.retail_products.low_stock_products.map((p) => (
                      <tr key={p.id} className="hover:bg-amber-50/50 dark:hover:bg-amber-950/10 transition-colors">
                        <td className="px-5 py-3 font-medium text-gray-900 dark:text-white">{p.name}</td>
                        <td className="px-4 py-3 text-gray-500 dark:text-gray-400">
                          {p.category ?? <span className="text-gray-300 dark:text-gray-600">–</span>}
                        </td>
                        <td className="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{fmt(p.price)}</td>
                        <td className="px-4 py-3 text-right">
                          <span className={`font-bold ${p.stock_quantity === 0 ? "text-red-600 dark:text-red-400" : "text-amber-600 dark:text-amber-400"}`}>
                            {p.stock_quantity === 0 ? "Out of stock" : `${p.stock_quantity} left`}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── TRENDS TAB ── */}
      {tab === "trend" && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          {/* Repair Volume — only if repair shop */}
          {isRepair && (
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 className="font-semibold text-gray-700 dark:text-gray-300 mb-4">Repair Volume (Last 12 Months)</h3>
            {loading ? (
              <div className="h-56 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
            ) : data?.monthly_trend ? (
              <div className="space-y-3">
                <BarChartOne
                  categories={data.monthly_trend.map((m) => m.month)}
                  series={[
                    { name: "Intake", data: data.monthly_trend.map((m) => m.intake) },
                    { name: "Completed", data: data.monthly_trend.map((m) => m.completed) },
                  ]}
                  colors={["#3b82f6", "#10b981"]}
                  height={220}
                  minWidthClass="min-w-[650px] xl:min-w-full"
                  tooltipFormatter={(val) => `${val.toLocaleString("en-PH")} jobs`}
                />
                {/* Table summary */}
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-gray-400 dark:text-gray-500">
                        <th className="text-left pb-1">Month</th>
                        <th className="text-right pb-1">Intake</th>
                        <th className="text-right pb-1">Done</th>
                        <th className="text-right pb-1">Revenue</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50 dark:divide-gray-800">
                      {[...data.monthly_trend].reverse().slice(0, 6).map((m) => (
                        <tr key={m.month_key}>
                          <td className="py-1 text-gray-700 dark:text-gray-300">{m.month}</td>
                          <td className="py-1 text-right text-gray-600 dark:text-gray-400">{m.intake}</td>
                          <td className="py-1 text-right text-gray-600 dark:text-gray-400">{m.completed}</td>
                          <td className="py-1 text-right font-medium text-gray-900 dark:text-white">{fmt(m.revenue)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            ) : null}
          </div>
          )}

          {/* Repair Revenue Trend — only if repair shop */}
          {isRepair && (
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 className="font-semibold text-gray-700 dark:text-gray-300 mb-1">Repair Revenue Trend (Last 12 Months)</h3>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">Monthly completed repair revenue shown in bars</p>
            {loading ? (
              <div className="h-56 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
            ) : data?.monthly_trend ? (
              <div className="space-y-3">
                <BarChartOne
                  categories={data.monthly_trend.map((m) => m.month)}
                  seriesData={data.monthly_trend.map((m) => m.revenue)}
                  seriesName="Repair Revenue"
                  color="#6366f1"
                  height={220}
                  minWidthClass="min-w-[650px] xl:min-w-full"
                  yAxisFormatter={(val) => `P${Math.round(val).toLocaleString("en-PH")}`}
                  tooltipFormatter={(val) => fmt(val)}
                />
                {/* Peak month callout */}
                {(() => {
                  const peak = [...data.monthly_trend!].sort((a, b) => b.revenue - a.revenue)[0];
                  return peak?.revenue > 0 ? (
                    <div className="bg-purple-50 dark:bg-purple-950/30 rounded-xl p-3 text-sm">
                      <span className="font-semibold text-purple-700 dark:text-purple-400">Peak month:</span>
                      <span className="text-gray-700 dark:text-gray-300 ml-2">{peak.month} — {fmt(peak.revenue)}</span>
                    </div>
                  ) : null;
                })()}
              </div>
            ) : null}
          </div>
          )}

          {/* Retail Order Volume Trend — only if retail shop */}
          {isRetail && (
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 className="font-semibold text-gray-700 dark:text-gray-300 mb-4">Retail Order Volume (Last 12 Months)</h3>
            {loading ? (
              <div className="h-56 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
            ) : data?.retail_trend ? (
              <div className="space-y-3">
                <BarChartOne
                  categories={data.retail_trend.map((m) => m.month)}
                  series={[
                    { name: "Total Orders", data: data.retail_trend.map((m) => m.orders) },
                    { name: "Completed", data: data.retail_trend.map((m) => m.completed) },
                  ]}
                  colors={["#3b82f6", "#10b981"]}
                  height={220}
                  minWidthClass="min-w-[650px] xl:min-w-full"
                  tooltipFormatter={(val) => `${val.toLocaleString("en-PH")} orders`}
                />
                <div className="mt-3 overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="text-gray-400 dark:text-gray-500">
                        <th className="text-left pb-1">Month</th>
                        <th className="text-right pb-1">Orders</th>
                        <th className="text-right pb-1">Completed</th>
                        <th className="text-right pb-1">Revenue</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50 dark:divide-gray-800">
                      {[...data.retail_trend].reverse().slice(0, 6).map((m) => (
                        <tr key={m.month_key}>
                          <td className="py-1 text-gray-700 dark:text-gray-300">{m.month}</td>
                          <td className="py-1 text-right text-gray-600 dark:text-gray-400">{m.orders}</td>
                          <td className="py-1 text-right text-gray-600 dark:text-gray-400">{m.completed}</td>
                          <td className="py-1 text-right font-medium text-gray-900 dark:text-white">{fmt(m.revenue)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            ) : null}
          </div>
          )}

          {/* Retail Revenue Trend line chart — only if retail shop */}
          {isRetail && (
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 className="font-semibold text-gray-700 dark:text-gray-300 mb-1">Retail Revenue Trend (Last 12 Months)</h3>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">Monthly retail revenue displayed as bar graph</p>
            {loading ? (
              <div className="h-56 bg-gray-50 dark:bg-gray-800 rounded-xl animate-pulse" />
            ) : data?.retail_trend ? (
              <div className="space-y-3">
                <BarChartOne
                  categories={data.retail_trend.map((m) => m.month)}
                  seriesData={data.retail_trend.map((m) => m.revenue)}
                  seriesName="Retail Revenue"
                  color="#10b981"
                  height={220}
                  minWidthClass="min-w-[650px] xl:min-w-full"
                  yAxisFormatter={(val) => `P${Math.round(val).toLocaleString("en-PH")}`}
                  tooltipFormatter={(val) => fmt(val)}
                />
                {(() => {
                  const peak = [...data.retail_trend!].sort((a, b) => b.revenue - a.revenue)[0];
                  return peak?.revenue > 0 ? (
                    <div className="bg-emerald-50 dark:bg-emerald-950/30 rounded-xl p-3 text-sm">
                      <span className="font-semibold text-emerald-700 dark:text-emerald-400">Peak month:</span>
                      <span className="text-gray-700 dark:text-gray-300 ml-2">{peak.month} — {fmt(peak.revenue)}</span>
                    </div>
                  ) : null;
                })()}
              </div>
            ) : null}
          </div>
          )}
        </div>
      )}


      </div>
    </>
  );

  // ─── Wrap in appropriate layout ─────────────────────────────────────────────

  if (isErpUser) {
    return <AppLayoutERP>{content}</AppLayoutERP>;
  }

  return (
    <AppLayoutShopOwner>
      {content}
    </AppLayoutShopOwner>
  );
};

export default DssInsights;
