import { Head } from "@inertiajs/react";
import { useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type MetricColor = "success" | "warning" | "info";
type ChangeType = "increase" | "decrease";

// Icons
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

interface MetricCardProps {
  title: string;
  value: number | string;
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
          <div
            className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
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
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">{value}</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>
    </div>
  );
};

interface ReportType {
  id: string;
  title: string;
  description: string;
  icon: ComponentType<{ className?: string }>;
  color: string;
  lastGenerated?: string;
}

const reportTypes: ReportType[] = [
  {
    id: "sales",
    title: "Sales Report",
    description: "Comprehensive sales data and revenue analysis",
    icon: ChartIcon,
    color: "blue",
    lastGenerated: "2026-02-01 14:30"
  },
  {
    id: "stock",
    title: "Stock Update Report",
    description: "Current inventory levels and stock movements",
    icon: DocumentIcon,
    color: "emerald",
    lastGenerated: "2026-02-02 09:15"
  },
  {
    id: "complaints",
    title: "Customer Complaints",
    description: "Customer feedback and complaint tracking",
    icon: AlertIcon,
    color: "amber",
    lastGenerated: "2026-01-31 16:45"
  },
  {
    id: "damaged",
    title: "Damaged Items Report",
    description: "Items marked as damaged or defective",
    icon: AlertIcon,
    color: "red",
    lastGenerated: "2026-01-30 11:20"
  },
  {
    id: "missing",
    title: "Missing Items Report",
    description: "Unaccounted or lost inventory items",
    icon: AlertIcon,
    color: "orange",
    lastGenerated: "2026-01-29 10:00"
  },
  {
    id: "performance",
    title: "Performance Summary",
    description: "Overall business performance metrics",
    icon: ChartIcon,
    color: "purple",
    lastGenerated: "2026-02-01 18:00"
  },
];

export default function ERPReports() {
  const [selectedReport, setSelectedReport] = useState<string | null>(null);
  const [dateRange, setDateRange] = useState("week");
  const [generateModalOpen, setGenerateModalOpen] = useState(false);
  const [reportNotes, setReportNotes] = useState("");

  const handleGenerateReport = (reportId: string) => {
    const report = reportTypes.find(r => r.id === reportId);
    setSelectedReport(reportId);
    setGenerateModalOpen(true);
  };

  const handleSendReport = async () => {
    if (!reportNotes.trim()) {
      Swal.fire({
        title: "Notes Required",
        text: "Please add notes or summary for the shop owner.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    setGenerateModalOpen(false);

    const result = await Swal.fire({
      title: "Send Report?",
      html: `<div style="text-align: left;"><strong>Report Type:</strong> ${reportTypes.find(r => r.id === selectedReport)?.title}<br/><strong>Date Range:</strong> ${dateRange === 'week' ? 'Last 7 Days' : dateRange === 'month' ? 'Last 30 Days' : 'Custom Range'}<br/><strong>Notes:</strong> ${reportNotes}</div>`,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, Send to Shop Owner",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
      Swal.fire({
        title: "Report Sent!",
        text: "The report has been successfully sent to the shop owner.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
      setReportNotes("");
      setSelectedReport(null);
    }
  };

  const handleDownloadReport = async (reportId: string) => {
    const report = reportTypes.find(r => r.id === reportId);
    
    const result = await Swal.fire({
      title: "Download Report?",
      html: `<div style="text-align: left;"><strong>Report:</strong> ${report?.title}<br/><strong>Last Generated:</strong> ${report?.lastGenerated}</div>`,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, Download",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
      Swal.fire({
        title: "Download Started",
        text: `Downloading ${report?.title}...`,
        icon: "success",
        timer: 2000,
        showConfirmButton: false,
      });
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Reports - Solespace" />
      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Reports & Analytics</h1>
            <p className="text-gray-600 dark:text-gray-400">
              Generate and send detailed reports to shop owner
            </p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              Manager Access
            </span>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <MetricCard
            title="Reports Generated"
            value={24}
            change={8}
            changeType="increase"
            icon={DocumentIcon}
            color="info"
            description="This month"
          />
          <MetricCard
            title="Pending Issues"
            value={7}
            change={3}
            changeType="decrease"
            icon={AlertIcon}
            color="warning"
            description="Requires attention"
          />
          <MetricCard
            title="Reports Sent"
            value={18}
            change={12}
            changeType="increase"
            icon={SendIcon}
            color="success"
            description="To shop owner"
          />
        </div>

        {/* Report Types Grid */}
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-6">
            <h2 className="text-lg font-semibold">Available Reports</h2>
            <p className="text-sm text-gray-500">Select a report type to generate and send to shop owner</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {reportTypes.map((report) => (
              <div
                key={report.id}
                className="group border border-gray-200 dark:border-gray-700 rounded-xl p-5 hover:border-blue-500 dark:hover:border-blue-400 hover:shadow-lg transition-all duration-300 cursor-pointer"
              >
                <div className="flex items-start justify-between mb-4">
                  <div className={`p-3 rounded-lg bg-${report.color}-50 dark:bg-${report.color}-900/20`}>
                    <report.icon className={`w-6 h-6 text-${report.color}-600 dark:text-${report.color}-400`} />
                  </div>
                </div>
                <h3 className="text-base font-semibold text-gray-900 dark:text-white mb-2">
                  {report.title}
                </h3>
                <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
                  {report.description}
                </p>
                {report.lastGenerated && (
                  <p className="text-xs text-gray-500 dark:text-gray-500 mb-4">
                    Last generated: {report.lastGenerated}
                  </p>
                )}
                <div className="flex gap-2">
                  <button
                    onClick={() => handleGenerateReport(report.id)}
                    className="flex-1 px-3 py-2 text-sm font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors flex items-center justify-center gap-2"
                  >
                    <SendIcon className="w-4 h-4" />
                    Generate & Send
                  </button>
                  <button
                    onClick={() => handleDownloadReport(report.id)}
                    className="px-3 py-2 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    title="Download"
                  >
                    <DownloadIcon className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Recent Reports Table */}
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Recent Reports</h2>
            <p className="text-sm text-gray-500">Recently generated and sent reports</p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Report Type</th>
                  <th className="pb-2">Generated</th>
                  <th className="pb-2">Sent To</th>
                  <th className="pb-2">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                <tr>
                  <td className="py-3 font-medium text-gray-900 dark:text-gray-100">Sales Report</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">2026-02-01 14:30</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">Shop Owner</td>
                  <td className="py-3">
                    <span className="px-2 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">
                      Sent
                    </span>
                  </td>
                </tr>
                <tr>
                  <td className="py-3 font-medium text-gray-900 dark:text-gray-100">Stock Update Report</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">2026-02-02 09:15</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">Shop Owner</td>
                  <td className="py-3">
                    <span className="px-2 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">
                      Sent
                    </span>
                  </td>
                </tr>
                <tr>
                  <td className="py-3 font-medium text-gray-900 dark:text-gray-100">Customer Complaints</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">2026-01-31 16:45</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">Shop Owner</td>
                  <td className="py-3">
                    <span className="px-2 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
                      Viewed
                    </span>
                  </td>
                </tr>
                <tr>
                  <td className="py-3 font-medium text-gray-900 dark:text-gray-100">Damaged Items Report</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">2026-01-30 11:20</td>
                  <td className="py-3 text-gray-600 dark:text-gray-400">Shop Owner</td>
                  <td className="py-3">
                    <span className="px-2 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">
                      Sent
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        {/* Generate Report Modal */}
        {generateModalOpen && selectedReport && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-lg w-full">
              <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
                  Generate {reportTypes.find(r => r.id === selectedReport)?.title}
                </h2>
                <button
                  onClick={() => setGenerateModalOpen(false)}
                  className="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
                >
                  <CloseIcon className="w-5 h-5" />
                </button>
              </div>
              <div className="p-6 space-y-4">
                <div>
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date Range</p>
                  <select
                    value={dateRange}
                    onChange={(e) => setDateRange(e.target.value)}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
                  >
                    <option value="week">Last 7 Days</option>
                    <option value="month">Last 30 Days</option>
                    <option value="quarter">Last 3 Months</option>
                    <option value="year">Last Year</option>
                  </select>
                </div>
                <div>
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes for Shop Owner</p>
                  <textarea
                    value={reportNotes}
                    onChange={(e) => setReportNotes(e.target.value)}
                    rows={4}
                    placeholder="Add summary, highlights, or important notes about this report..."
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 resize-none"
                  />
                </div>
              </div>
              <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                <button
                  onClick={() => setGenerateModalOpen(false)}
                  className="flex-1 px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={handleSendReport}
                  className="flex-1 px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors flex items-center justify-center gap-2"
                >
                  <SendIcon className="w-4 h-4" />
                  Send to Shop Owner
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutERP>
  );
}
