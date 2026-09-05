import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { erpUrl } from "@/utils/erpCapabilities";

type IconComponent = ({ className }: { className?: string }) => JSX.Element;

const DocumentIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm4 18H6V4h7v5h5v11z" />
  </svg>
);

const ChartIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
    <path d="M3 13h2v8H3v-8zm4-4h2v12H7V9zm4-4h2v16h-2V5zm4 8h2v8h-2v-8zm4-4h2v12h-2V9z" />
  </svg>
);

const AlertIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const CheckIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
  </svg>
);

const DownloadIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
  </svg>
);

const RefreshIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h5M20 20v-5h-5M5.5 9A7 7 0 0118.5 6.5L20 9M18.5 15A7 7 0 015.5 17.5L4 15" />
  </svg>
);

const CloseIcon: IconComponent = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

type ReportStatus = "generated" | "reviewed" | "failed" | "sent";

interface ReportRecord {
  id: number;
  report_type: string;
  report_title: string;
  description: string;
  date_range: string;
  status: ReportStatus;
  notes: string | null;
  generated_at: string | null;
  reviewed_at: string | null;
  downloaded_at: string | null;
  row_count?: number;
}

interface ReportTypeCard {
  id: string;
  title: string;
  description: string;
  last_report: ReportRecord | null;
}

interface ReportsPayload {
  metrics: {
    reports_generated: number;
    pending_issues: number;
    reports_reviewed: number;
    last_updated_at: string | null;
  };
  report_types: ReportTypeCard[];
  recent_reports: ReportRecord[];
}

interface MetricCardProps {
  title: string;
  value: number | string;
  icon: IconComponent;
  colorClass: string;
  description: string;
}

const MetricCard = ({ title, value, icon: Icon, colorClass, description }: MetricCardProps) => (
  <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-300 hover:shadow-lg dark:border-gray-800 dark:bg-white/3">
    <div className="relative">
      <div className="mb-4 flex items-center justify-between">
        <div className={`flex h-14 w-14 items-center justify-center rounded-2xl ${colorClass}`}>
          <Icon className="size-7 text-white" />
        </div>
      </div>
      <div className="space-y-2">
        <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
        <h3 className="text-3xl font-bold text-gray-900 dark:text-white">{value}</h3>
        <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
      </div>
    </div>
  </div>
);

const reportStyleMap: Record<string, { cardBg: string; iconColor: string }> = {
  sales: { cardBg: "bg-blue-50 dark:bg-blue-900/20", iconColor: "text-blue-600 dark:text-blue-400" },
  stock: { cardBg: "bg-emerald-50 dark:bg-emerald-900/20", iconColor: "text-emerald-600 dark:text-emerald-400" },
  damaged: { cardBg: "bg-red-50 dark:bg-red-900/20", iconColor: "text-red-600 dark:text-red-400" },
  missing: { cardBg: "bg-orange-50 dark:bg-orange-900/20", iconColor: "text-orange-600 dark:text-orange-400" },
  performance: { cardBg: "bg-purple-50 dark:bg-purple-900/20", iconColor: "text-purple-600 dark:text-purple-400" },
};

const reportIconMap: Record<string, IconComponent> = {
  sales: ChartIcon,
  stock: DocumentIcon,
  damaged: AlertIcon,
  missing: AlertIcon,
  performance: ChartIcon,
};

const defaultPayload: ReportsPayload = {
  metrics: {
    reports_generated: 0,
    pending_issues: 0,
    reports_reviewed: 0,
    last_updated_at: null,
  },
  report_types: [],
  recent_reports: [],
};

const isReviewed = (status: ReportStatus): boolean => status === "reviewed" || status === "sent";

export default function ERPReports() {
  const { auth, erpCapabilities } = usePage().props as any;
  const ownerMode = auth?.erpActor?.ownerMode === true;

  const queryPreset = useMemo(() => {
    if (typeof window === "undefined") {
      return { report: null as string | null, range: "week", openGenerate: false };
    }

    const params = new URLSearchParams(window.location.search);
    const requestedReport = params.get("report");
    const range = params.get("range") || "week";
    const openGenerate = params.get("openGenerate") === "1";
    const allowedRanges = ["week", "month", "quarter", "year"];
    const allowedReports = ["sales", "stock", "damaged", "missing", "performance"];
    const report = requestedReport && allowedReports.includes(requestedReport) ? requestedReport : null;

    return {
      report,
      range: allowedRanges.includes(range) ? range : "week",
      openGenerate: openGenerate && Boolean(report),
    };
  }, []);

  const [reportsData, setReportsData] = useState<ReportsPayload>(defaultPayload);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedReportType, setSelectedReportType] = useState<string | null>(queryPreset.openGenerate ? queryPreset.report : null);
  const [reviewTarget, setReviewTarget] = useState<ReportRecord | null>(null);
  const [dateRange, setDateRange] = useState(queryPreset.range);
  const [generateModalOpen, setGenerateModalOpen] = useState(Boolean(!ownerMode && queryPreset.openGenerate && queryPreset.report));
  const [reportNotes, setReportNotes] = useState("");
  const [reviewNotes, setReviewNotes] = useState("");
  const [generationRequestKey, setGenerationRequestKey] = useState<string | null>(null);

  const reportsUrl = erpUrl(erpCapabilities, "GET:api.manager.reports.index")
    ?? (ownerMode ? null : "/api/manager/reports");

  const fetchReports = async () => {
    try {
      setLoading(true);
      setError(null);

      if (!reportsUrl) {
        setLoading(false);
        return;
      }

      const response = await fetch(reportsUrl, {
        credentials: "include",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!response.ok) {
        const errorPayload = await response.json().catch(() => ({}));
        throw new Error(errorPayload.message || errorPayload.error || "Failed to load manager reports");
      }

      const payload = await response.json();
      setReportsData({
        metrics: {
          ...defaultPayload.metrics,
          ...(payload?.metrics ?? {}),
        },
        report_types: payload?.report_types ?? [],
        recent_reports: payload?.recent_reports ?? [],
      });
    } catch (fetchError) {
      setError(fetchError instanceof Error ? fetchError.message : "Failed to load reports");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchReports();
  }, []);

  const formatDateTime = (dateTime: string | null) => {
    if (!dateTime) return "—";

    const date = new Date(dateTime);
    if (Number.isNaN(date.getTime())) return dateTime;

    return date.toLocaleString("en-PH", {
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  };

  const openGenerateModal = (reportType: string) => {
    setSelectedReportType(reportType);
    setGenerationRequestKey(`manager-report-${Date.now()}-${Math.random().toString(36).slice(2)}`);
    setGenerateModalOpen(true);
  };

  const closeGenerateModal = () => {
    setGenerateModalOpen(false);
    setSelectedReportType(null);
    setGenerationRequestKey(null);
    setReportNotes("");
    setDateRange("week");
  };

  const openReviewModal = (report: ReportRecord) => {
    setReviewTarget(report);
    setReviewNotes(report.notes ?? "");
  };

  const closeReviewModal = () => {
    setReviewTarget(null);
    setReviewNotes("");
  };

  const handleGenerate = async () => {
    if (ownerMode || !selectedReportType) return;

    try {
      setSubmitting(true);
      const response = await fetch("/api/manager/reports/generate", {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          ...(generationRequestKey ? { "Idempotency-Key": generationRequestKey } : {}),
        },
        body: JSON.stringify({
          report_type: selectedReportType,
          date_range: dateRange,
          notes: reportNotes.trim() || null,
        }),
      });

      if (!response.ok) {
        const errorPayload = await response.json().catch(() => ({}));
        throw new Error(errorPayload.message || errorPayload.error || "Failed to generate report");
      }

      closeGenerateModal();
      await fetchReports();

      await Swal.fire({
        title: "Report generated",
        text: "The report is ready to download or mark as reviewed.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    } catch (submitError) {
      await Swal.fire({
        title: "Generation failed",
        text: submitError instanceof Error ? submitError.message : "Unable to generate report",
        icon: "error",
        confirmButtonColor: "#dc2626",
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleReview = async () => {
    if (ownerMode || !reviewTarget || !reviewNotes.trim()) {
      if (!reviewNotes.trim()) {
        await Swal.fire({
          title: "Notes required",
          text: "Add a short note before marking the report as reviewed.",
          icon: "warning",
          confirmButtonColor: "#2563eb",
        });
      }
      return;
    }

    try {
      setSubmitting(true);
      const response = await fetch(`/api/manager/reports/${reviewTarget.id}/review`, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ notes: reviewNotes.trim() }),
      });

      if (!response.ok) {
        const errorPayload = await response.json().catch(() => ({}));
        throw new Error(errorPayload.message || errorPayload.error || "Failed to review report");
      }

      closeReviewModal();
      await fetchReports();

      await Swal.fire({
        title: "Report reviewed",
        text: "The review decision was recorded in the report history.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    } catch (submitError) {
      await Swal.fire({
        title: "Review failed",
        text: submitError instanceof Error ? submitError.message : "Unable to review report",
        icon: "error",
        confirmButtonColor: "#dc2626",
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleDownloadReport = async (reportId: number) => {
    if (ownerMode) return;

    try {
      const response = await fetch(`/api/manager/reports/${reportId}/download`, {
        credentials: "include",
        headers: {
          Accept: "text/csv,application/octet-stream,application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!response.ok) {
        const errorPayload = await response.json().catch(() => ({}));
        throw new Error(errorPayload.message || errorPayload.error || "Failed to download report");
      }

      const disposition = response.headers.get("content-disposition") || "";
      const fileNameMatch = disposition.match(/filename="?([^";]+)"?/i);
      const fileName = fileNameMatch?.[1] || "manager-report.csv";
      const blob = await response.blob();
      const blobUrl = window.URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = blobUrl;
      anchor.download = fileName;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.URL.revokeObjectURL(blobUrl);

      await fetchReports();
    } catch (downloadError) {
      await Swal.fire({
        title: "Download failed",
        text: downloadError instanceof Error ? downloadError.message : "Failed to download report",
        icon: "error",
        confirmButtonColor: "#dc2626",
      });
    }
  };

  const getStatusBadgeClass = (status: ReportStatus) => {
    if (isReviewed(status)) return "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200";
    if (status === "failed") return "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200";
    return "bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200";
  };

  const getStatusLabel = (status: ReportStatus) => (isReviewed(status) ? "Reviewed" : status === "failed" ? "Failed" : "Generated");

  return (
    <AppLayoutERP>
      <Head title="Reports - Solespace" />

      <div className="space-y-6 p-4 sm:p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold">Reports &amp; Analytics</h1>
            <p className="text-gray-600 dark:text-gray-400">Review operational reports for your authorized shop.</p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              {ownerMode ? "Shop Owner View" : "Manager Access"}
            </span>
            <button
              type="button"
              onClick={fetchReports}
              disabled={loading}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
              aria-label="Refresh reports"
            >
              <RefreshIcon className={`h-4 w-4 ${loading ? "animate-spin" : ""}`} />
              Refresh
            </button>
          </div>
        </div>

        {error && (
          <div className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 sm:flex-row sm:items-center sm:justify-between dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
            <span>{error}</span>
            <button type="button" onClick={fetchReports} className="font-semibold underline">Try again</button>
          </div>
        )}

        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
          <MetricCard
            title="Reports Generated"
            value={reportsData.metrics.reports_generated}
            icon={DocumentIcon}
            colorClass="bg-linear-to-br from-blue-500 to-indigo-600"
            description="This month"
          />
          <MetricCard
            title="Pending Issues"
            value={reportsData.metrics.pending_issues}
            icon={AlertIcon}
            colorClass="bg-linear-to-br from-yellow-500 to-orange-600"
            description="Low or out-of-stock inventory"
          />
          <MetricCard
            title="Reports Reviewed"
            value={reportsData.metrics.reports_reviewed}
            icon={CheckIcon}
            colorClass="bg-linear-to-br from-green-500 to-emerald-600"
            description="This month"
          />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-800 dark:bg-gray-900">
          <div className="mb-6">
            <h2 className="text-lg font-semibold">Available Reports</h2>
            <p className="text-sm text-gray-500">Generate a shop-scoped report, then review it when ready.</p>
          </div>

          {loading && reportsData.report_types.length === 0 ? (
            <div className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">Loading reports...</div>
          ) : reportsData.report_types.length === 0 ? (
            <div className="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
              No report definitions are available for this shop.
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              {reportsData.report_types.map((report) => {
                const style = reportStyleMap[report.id] || reportStyleMap.sales;
                const ReportIcon = reportIconMap[report.id] || DocumentIcon;
                const latestReport = report.last_report;

                return (
                  <div key={report.id} className="rounded-xl border border-gray-200 p-5 transition-all duration-300 hover:border-blue-500 hover:shadow-lg dark:border-gray-700 dark:hover:border-blue-400">
                    <div className="mb-4 flex items-start justify-between">
                      <div className={`rounded-lg p-3 ${style.cardBg}`}>
                        <ReportIcon className={`h-6 w-6 ${style.iconColor}`} />
                      </div>
                      {latestReport && (
                        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${getStatusBadgeClass(latestReport.status)}`}>
                          {getStatusLabel(latestReport.status)}
                        </span>
                      )}
                    </div>

                    <h3 className="mb-2 text-base font-semibold text-gray-900 dark:text-white">{report.title}</h3>
                    <p className="mb-4 text-sm text-gray-600 dark:text-gray-400">{report.description}</p>
                    <p className="mb-4 text-xs text-gray-500 dark:text-gray-500">
                      Last generated: {formatDateTime(latestReport?.generated_at ?? null)}
                    </p>

                    {!ownerMode && (
                      <div className="flex flex-wrap gap-2">
                        <button
                          type="button"
                          onClick={() => openGenerateModal(report.id)}
                          className="flex min-w-36 flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                          aria-label={`Generate ${report.title}`}
                        >
                          <DocumentIcon className="h-4 w-4" />
                          Generate report
                        </button>
                        {latestReport && (
                          <button
                            type="button"
                            onClick={() => handleDownloadReport(latestReport.id)}
                            className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                            aria-label={`Download latest ${report.title}`}
                          >
                            <DownloadIcon className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-800 dark:bg-gray-900">
          <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-lg font-semibold">Recent Reports</h2>
              <p className="text-sm text-gray-500">Generated and reviewed history for this shop.</p>
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Last updated: {formatDateTime(reportsData.metrics.last_updated_at)}
            </p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead className="border-b border-gray-200 text-left text-gray-500 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Report Type</th>
                  <th className="pb-2">Period</th>
                  <th className="pb-2">Generated</th>
                  <th className="pb-2">Reviewed</th>
                  <th className="pb-2">Status</th>
                  {!ownerMode && <th className="pb-2 text-right">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {!loading && reportsData.recent_reports.length === 0 ? (
                  <tr>
                    <td colSpan={ownerMode ? 5 : 6} className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                      No reports generated yet.
                    </td>
                  </tr>
                ) : (
                  reportsData.recent_reports.map((report) => (
                    <tr key={report.id}>
                      <td className="py-3 font-medium text-gray-900 dark:text-gray-100">{report.report_title}</td>
                      <td className="py-3 text-gray-600 dark:text-gray-400">{report.date_range}</td>
                      <td className="py-3 text-gray-600 dark:text-gray-400">{formatDateTime(report.generated_at)}</td>
                      <td className="py-3 text-gray-600 dark:text-gray-400">{formatDateTime(report.reviewed_at)}</td>
                      <td className="py-3">
                        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${getStatusBadgeClass(report.status)}`}>
                          {getStatusLabel(report.status)}
                        </span>
                      </td>
                      {!ownerMode && (
                        <td className="py-3 text-right">
                          <div className="flex justify-end gap-2">
                            {!isReviewed(report.status) && report.status !== "failed" && (
                              <button
                                type="button"
                                onClick={() => openReviewModal(report)}
                                className="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950/30"
                              >
                                Mark as reviewed
                              </button>
                            )}
                            <button
                              type="button"
                              onClick={() => handleDownloadReport(report.id)}
                              className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                            >
                              Download
                            </button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {generateModalOpen && selectedReportType && (
          <div className="fixed inset-0 z-999999 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
            <div className="w-full max-w-lg rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
              <div className="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                  Generate {reportsData.report_types.find((report) => report.id === selectedReportType)?.title}
                </h2>
                <button type="button" onClick={closeGenerateModal} disabled={submitting} className="rounded-lg p-2 text-gray-500 transition-colors hover:text-gray-700 disabled:opacity-50 dark:text-gray-400 dark:hover:text-gray-300" aria-label="Close generate report modal">
                  <CloseIcon className="h-5 w-5" />
                </button>
              </div>

              <div className="space-y-4 p-6">
                <div>
                  <label htmlFor="manager-report-range" className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Date range</label>
                  <select id="manager-report-range" value={dateRange} onChange={(event) => setDateRange(event.target.value)} className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-blue-400">
                    <option value="week">Last 7 days</option>
                    <option value="month">Last 30 days</option>
                    <option value="quarter">Last 3 months</option>
                    <option value="year">Last year</option>
                  </select>
                </div>

                <div>
                  <label htmlFor="manager-report-notes" className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Context notes (optional)</label>
                  <textarea id="manager-report-notes" value={reportNotes} onChange={(event) => setReportNotes(event.target.value)} rows={4} placeholder="Add highlights or context for the report history..." className="w-full resize-none rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-blue-400" />
                </div>
              </div>

              <div className="flex gap-3 border-t border-gray-200 p-6 dark:border-gray-700">
                <button type="button" onClick={closeGenerateModal} disabled={submitting} className="flex-1 rounded-lg bg-gray-100 px-4 py-2 font-semibold text-gray-900 transition-colors hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600">Cancel</button>
                <button type="button" onClick={handleGenerate} disabled={submitting} className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                  <DocumentIcon className="h-4 w-4" />
                  {submitting ? "Generating..." : "Generate report"}
                </button>
              </div>
            </div>
          </div>
        )}

        {reviewTarget && (
          <div className="fixed inset-0 z-999999 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
            <div className="w-full max-w-lg rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
              <div className="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Mark report as reviewed</h2>
                  <p className="mt-1 text-sm text-gray-500">{reviewTarget.report_title}</p>
                </div>
                <button type="button" onClick={closeReviewModal} disabled={submitting} className="rounded-lg p-2 text-gray-500 transition-colors hover:text-gray-700 disabled:opacity-50 dark:text-gray-400 dark:hover:text-gray-300" aria-label="Close review report modal">
                  <CloseIcon className="h-5 w-5" />
                </button>
              </div>

              <div className="p-6">
                <label htmlFor="manager-review-notes" className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Review notes</label>
                <textarea id="manager-review-notes" value={reviewNotes} onChange={(event) => setReviewNotes(event.target.value)} rows={4} placeholder="Record what was reviewed or what follow-up is needed..." className="w-full resize-none rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-emerald-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-emerald-400" />
              </div>

              <div className="flex gap-3 border-t border-gray-200 p-6 dark:border-gray-700">
                <button type="button" onClick={closeReviewModal} disabled={submitting} className="flex-1 rounded-lg bg-gray-100 px-4 py-2 font-semibold text-gray-900 transition-colors hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600">Cancel</button>
                <button type="button" onClick={handleReview} disabled={submitting} className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50">
                  <CheckIcon className="h-4 w-4" />
                  {submitting ? "Saving..." : "Mark as reviewed"}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutERP>
  );
}
