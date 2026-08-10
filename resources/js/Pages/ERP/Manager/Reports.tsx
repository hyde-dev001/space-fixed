import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { erpUrl } from "@/utils/erpCapabilities";

const DocumentIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm4 18H6V4h7v5h5v11z" />
  </svg>
);

const ChartIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M3 13h2v8H3v-8zm4-4h2v12H7V9zm4-4h2v16h-2V5zm4 8h2v8h-2v-8zm4-4h2v12h-2V9z" />
  </svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
  </svg>
);

const SendIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
  </svg>
);

const DownloadIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
  </svg>
);

const CloseIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

interface ReportRecord {
  id: number;
  report_type: string;
  report_title: string;
  description: string;
  date_range: string;
  status: "generated" | "sent";
  notes: string | null;
  generated_at: string | null;
  sent_at: string | null;
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
    reports_sent: number;
  };
  report_types: ReportTypeCard[];
  recent_reports: ReportRecord[];
}

interface MetricCardProps {
  title: string;
  value: number | string;
  icon: ({ className }: { className?: string }) => JSX.Element;
  colorClass: string;
  description: string;
}

const MetricCard = ({ title, value, icon: Icon, colorClass, description }: MetricCardProps) => {
  return (
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
};

const reportStyleMap: Record<string, { cardBg: string; iconColor: string }> = {
  sales: {
    cardBg: "bg-blue-50 dark:bg-blue-900/20",
    iconColor: "text-blue-600 dark:text-blue-400",
  },
  stock: {
    cardBg: "bg-emerald-50 dark:bg-emerald-900/20",
    iconColor: "text-emerald-600 dark:text-emerald-400",
  },
  complaints: {
    cardBg: "bg-amber-50 dark:bg-amber-900/20",
    iconColor: "text-amber-600 dark:text-amber-400",
  },
  damaged: {
    cardBg: "bg-red-50 dark:bg-red-900/20",
    iconColor: "text-red-600 dark:text-red-400",
  },
  missing: {
    cardBg: "bg-orange-50 dark:bg-orange-900/20",
    iconColor: "text-orange-600 dark:text-orange-400",
  },
  performance: {
    cardBg: "bg-purple-50 dark:bg-purple-900/20",
    iconColor: "text-purple-600 dark:text-purple-400",
  },
};

const reportIconMap: Record<string, ({ className }: { className?: string }) => JSX.Element> = {
  sales: ChartIcon,
  stock: DocumentIcon,
  complaints: AlertIcon,
  damaged: AlertIcon,
  missing: AlertIcon,
  performance: ChartIcon,
};

const defaultPayload: ReportsPayload = {
  metrics: {
    reports_generated: 0,
    pending_issues: 0,
    reports_sent: 0,
  },
  report_types: [],
  recent_reports: [],
};

export default function ERPReports() {
  const { auth, erpCapabilities } = usePage().props as any;
  const ownerMode = auth?.erpActor?.ownerMode === true;

  const queryPreset = useMemo(() => {
    if (typeof window === "undefined") {
      return {
        report: null as string | null,
        range: "week",
        openGenerate: false,
      };
    }

    const params = new URLSearchParams(window.location.search);
    const requestedReport = params.get("report");
    const range = params.get("range") || "week";
    const openGenerate = params.get("openGenerate") === "1";
    const allowedRanges = ["week", "month", "quarter", "year"];
    const allowedReports = ["sales", "stock", "complaints", "damaged", "missing", "performance"];
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
  const [selectedReportId, setSelectedReportId] = useState<string | null>(queryPreset.openGenerate ? queryPreset.report : null);
  const [dateRange, setDateRange] = useState(queryPreset.range);
  const [generateModalOpen, setGenerateModalOpen] = useState(Boolean(!ownerMode && queryPreset.openGenerate && queryPreset.report));
  const [reportNotes, setReportNotes] = useState("");

  const fetchReports = async () => {
    try {
      setLoading(true);
      setError(null);

      const reportsUrl = erpUrl(erpCapabilities, "GET:api.manager.reports.index")
        ?? (ownerMode ? null : "/api/manager/reports");

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
        metrics: payload?.metrics ?? defaultPayload.metrics,
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

  const openGenerateModal = (reportId: string) => {
    setSelectedReportId(reportId);
    setGenerateModalOpen(true);
  };

  const closeGenerateModal = () => {
    setGenerateModalOpen(false);
    setSelectedReportId(null);
    setReportNotes("");
    setDateRange("week");
  };

  const handleGenerateAndSend = async () => {
    if (ownerMode || !selectedReportId) {
      return;
    }

    if (!reportNotes.trim()) {
      Swal.fire({
        title: "Notes Required",
        text: "Please add notes for the shop owner before sending.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      setSubmitting(true);

      const generateResponse = await fetch("/api/manager/reports/generate", {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          report_type: selectedReportId,
          date_range: dateRange,
          notes: reportNotes,
        }),
      });

      if (!generateResponse.ok) {
        const errorPayload = await generateResponse.json().catch(() => ({}));
        throw new Error(errorPayload.message || errorPayload.error || "Failed to generate report");
      }

      const generatedPayload = await generateResponse.json();
      const generatedReportId = generatedPayload?.report?.id;

      if (!generatedReportId) {
        throw new Error("Generated report ID was not returned");
      }

      const sendResponse = await fetch(`/api/manager/reports/${generatedReportId}/send`, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          notes: reportNotes,
        }),
      });

      if (!sendResponse.ok) {
        const errorPayload = await sendResponse.json().catch(() => ({}));
        throw new Error(errorPayload.message || errorPayload.error || "Failed to send report");
      }

      closeGenerateModal();
      await fetchReports();

      await Swal.fire({
        title: "Report Sent",
        text: "The report has been generated and marked as sent to the shop owner.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    } catch (submitError) {
      await Swal.fire({
        title: "Action Failed",
        text: submitError instanceof Error ? submitError.message : "Unable to process report action",
        icon: "error",
        confirmButtonColor: "#dc2626",
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleDownloadReport = async (reportId: string) => {
    if (ownerMode) return;

    const reportType = reportsData.report_types.find((entry) => entry.id === reportId);
    const latestReportId = reportType?.last_report?.id;

    if (!latestReportId) {
      Swal.fire({
        title: "No Report Available",
        text: "Generate this report first before downloading.",
        icon: "info",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      const response = await fetch(`/api/manager/reports/${latestReportId}/download`, {
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
      const fileName = fileNameMatch?.[1] || `${reportId}-report.csv`;
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
        title: "Download Failed",
        text: downloadError instanceof Error ? downloadError.message : "Failed to download report",
        icon: "error",
        confirmButtonColor: "#dc2626",
      });
    }
  };

  const getStatusBadgeClass = (status: string) => {
    if (status === "sent") {
      return "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200";
    }

    return "bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200";
  };

  return (
    <AppLayoutERP>
      <Head title="Reports - Solespace" />

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold">Reports & Analytics</h1>
            <p className="text-gray-600 dark:text-gray-400">Generate and send detailed reports to shop owner</p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
            <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              Manager Access
            </span>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
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
            description="Requires attention"
          />
          <MetricCard
            title="Reports Sent"
            value={reportsData.metrics.reports_sent}
            icon={SendIcon}
            colorClass="bg-linear-to-br from-green-500 to-emerald-600"
            description="To shop owner"
          />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
          <div className="mb-6">
            <h2 className="text-lg font-semibold">Available Reports</h2>
            <p className="text-sm text-gray-500">Select a report type to generate and send to shop owner</p>
          </div>

          {loading ? (
            <div className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">Loading reports...</div>
          ) : error ? (
            <div className="py-10 text-center text-sm text-red-600 dark:text-red-400">{error}</div>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              {reportsData.report_types.map((report) => {
                const style = reportStyleMap[report.id] || reportStyleMap.sales;
                const ReportIcon = reportIconMap[report.id] || DocumentIcon;

                return (
                  <div
                    key={report.id}
                    className="group cursor-pointer rounded-xl border border-gray-200 p-5 transition-all duration-300 hover:border-blue-500 hover:shadow-lg dark:border-gray-700 dark:hover:border-blue-400"
                  >
                    <div className="mb-4 flex items-start justify-between">
                      <div className={`rounded-lg p-3 ${style.cardBg}`}>
                        <ReportIcon className={`h-6 w-6 ${style.iconColor}`} />
                      </div>
                    </div>

                    <h3 className="mb-2 text-base font-semibold text-gray-900 dark:text-white">{report.title}</h3>
                    <p className="mb-4 text-sm text-gray-600 dark:text-gray-400">{report.description}</p>
                    <p className="mb-4 text-xs text-gray-500 dark:text-gray-500">
                      Last generated: {formatDateTime(report.last_report?.generated_at ?? null)}
                    </p>

                    {!ownerMode && <div className="flex gap-2">
                      <button
                        onClick={() => openGenerateModal(report.id)}
                        className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                        title="Generate and send report"
                        aria-label={`Generate and send ${report.title}`}
                      >
                        <SendIcon className="h-4 w-4" />
                        Generate & Send
                      </button>
                      <button
                        onClick={() => handleDownloadReport(report.id)}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                        title={`Download latest ${report.title}`}
                        aria-label={`Download latest ${report.title}`}
                      >
                        <DownloadIcon className="h-4 w-4" />
                      </button>
                    </div>}
                  </div>
                );
              })}
            </div>
          )}
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Recent Reports</h2>
            <p className="text-sm text-gray-500">Recently generated and sent reports</p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-200 text-left text-gray-500 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Report Type</th>
                  <th className="pb-2">Generated</th>
                  <th className="pb-2">Sent</th>
                  <th className="pb-2">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {!loading && reportsData.recent_reports.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                      No reports generated yet.
                    </td>
                  </tr>
                ) : (
                  reportsData.recent_reports.map((report) => (
                    <tr key={report.id}>
                      <td className="py-3 font-medium text-gray-900 dark:text-gray-100">{report.report_title}</td>
                      <td className="py-3 text-gray-600 dark:text-gray-400">{formatDateTime(report.generated_at)}</td>
                      <td className="py-3 text-gray-600 dark:text-gray-400">{formatDateTime(report.sent_at)}</td>
                      <td className="py-3">
                        <span className={`rounded-full px-2 py-1 text-xs font-semibold capitalize ${getStatusBadgeClass(report.status)}`}>
                          {report.status}
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {generateModalOpen && selectedReportId && (
          <div className="fixed inset-0 z-999999 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
            <div className="w-full max-w-lg rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
              <div className="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                  Generate {reportsData.report_types.find((report) => report.id === selectedReportId)?.title}
                </h2>
                <button
                  onClick={closeGenerateModal}
                  className="rounded-lg p-2 text-gray-500 transition-colors hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                  title="Close generate report modal"
                  aria-label="Close generate report modal"
                >
                  <CloseIcon className="h-5 w-5" />
                </button>
              </div>

              <div className="space-y-4 p-6">
                <div>
                  <p className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Date Range</p>
                  <select
                    value={dateRange}
                    onChange={(event) => setDateRange(event.target.value)}
                    title="Select report date range"
                    aria-label="Select report date range"
                    className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-blue-400"
                  >
                    <option value="week">Last 7 Days</option>
                    <option value="month">Last 30 Days</option>
                    <option value="quarter">Last 3 Months</option>
                    <option value="year">Last Year</option>
                  </select>
                </div>

                <div>
                  <p className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Notes for Shop Owner</p>
                  <textarea
                    value={reportNotes}
                    onChange={(event) => setReportNotes(event.target.value)}
                    rows={4}
                    placeholder="Add summary, highlights, or important notes about this report..."
                    className="w-full resize-none rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-blue-400"
                  />
                </div>
              </div>

              <div className="flex gap-3 border-t border-gray-200 p-6 dark:border-gray-700">
                <button
                  onClick={closeGenerateModal}
                  disabled={submitting}
                  className="flex-1 rounded-lg bg-gray-100 px-4 py-2 font-semibold text-gray-900 transition-colors hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-white dark:hover:bg-gray-600"
                >
                  Cancel
                </button>
                <button
                  onClick={handleGenerateAndSend}
                  disabled={submitting}
                  className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                >
                  <SendIcon className="h-4 w-4" />
                  {submitting ? "Processing..." : "Generate & Send"}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutERP>
  );
}
