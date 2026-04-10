import React, { useState } from "react";
import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import Swal from "sweetalert2";
import { useFilteredPagination } from "../../../hooks/useFilteredPagination";
import { useActivityLogFormatters } from "../../../hooks/useActivityLogFormatters";

interface ActivityLog {
  id: number;
  log_name: string | null;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  subject_label?: string;
  causer_type: string | null;
  causer_id: number | null;
  event: string;
  properties: Record<string, any>;
  changes: Record<string, { old: any; new: any; label?: string }>;
  created_at: string;
  updated_at: string;
  causer?: {
    id: number;
    name: string;
    email: string;
    role: string;
  };
  metadata?: {
    ip_address: string;
    user_agent: string;
  };
}

interface PaginationData {
  data: ActivityLog[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

interface Stats {
  total_logs: number;
  logs_last_24h: number;
  event_counts: Record<string, number>;
  subject_type_counts: Record<string, number>;
}

// Icons
const DocumentTextIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
  </svg>
);

const ShieldCheckIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
  </svg>
);

const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const FunnelIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
  </svg>
);

const ChevronLeftIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
  </svg>
);

const ChevronRightIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
  </svg>
);

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const BuildingStorefrontIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
  </svg>
);

const ArrowUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

interface MetricData {
  title: string;
  value: number;
  change: number;
  changeType: 'increase' | 'decrease';
  icon: React.ComponentType<{ className?: string }>;
  color: 'success' | 'error' | 'warning' | 'info';
  description: string;
}

// Professional Metric Card Component
const MetricCard: React.FC<MetricData> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description
}) => {
  const getColorClasses = () => {
    switch (color) {
      case 'success': return 'from-green-500 to-emerald-600';
      case 'error': return 'from-red-500 to-rose-600';
      case 'warning': return 'from-yellow-500 to-orange-600';
      case 'info': return 'from-blue-500 to-indigo-600';
      default: return 'from-gray-500 to-gray-600';
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      {/* Animated background gradient */}
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-linear-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>

          <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${changeType === 'increase'
              ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
              : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
            }`}>
            {changeType === 'increase' ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
            {Math.abs(change)}%
          </div>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
            {title}
          </p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {value.toLocaleString()}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {description}
          </p>
        </div>
      </div>
    </div>
  );
};

export default function ManagerAuditLogs() {
  const [logs, setLogs] = useState<ActivityLog[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [pagination, setPagination] = useState<PaginationData | null>(null);
  const [showFilters, setShowFilters] = useState(false);
  const { escapeHtml, formatSubjectType, formatValue, humanizeValue, parseUserAgent, getModalSubjectReference, formatDetailedDescription, getModalTitle } = useActivityLogFormatters();

  // Unified filter + pagination state with URL persistence
  const { page, perPage, filters, setFilter, setPersistentPage, resetFilters, loading, error, setLoading, setError } = useFilteredPagination({
    perPage: 10,
    defaultFilters: {
      event: "",
      subject_type: "",
      date_from: "",
      date_to: "",
    },
    pageParamName: 'page',
    onFilterChange: (newFilters, newPage) => {
      fetchLogs(newFilters, newPage);
    },
  });

  const fetchLogs = async (currentFilters: Record<string, any>, currentPage: number) => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      params.append('page', currentPage.toString());
      params.append('per_page', perPage.toString());
      
      if (currentFilters.date_from) params.append('date_from', String(currentFilters.date_from));
      if (currentFilters.date_to) params.append('date_to', String(currentFilters.date_to));
      if (currentFilters.event) params.append('event', String(currentFilters.event));
      if (currentFilters.subject_type) params.append('subject_type', String(currentFilters.subject_type));

      const response = await fetch(`/api/activity-logs?${params.toString()}`);
      if (!response.ok) throw new Error(`HTTP ${response.status}: Failed to fetch logs`);
      
      const data = await response.json();
      setLogs(data.logs.data || []);
      setPagination(data.logs);
      setStats(data.stats);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Failed to load activity logs';
      console.error('Error fetching logs:', error);
      setError(message);
      setLogs([]);
      setPagination(null);
    } finally {
      setLoading(false);
    }
  };

  const viewLogDetails = (log: ActivityLog) => {
    const properties: Record<string, any> =
      log.properties && typeof log.properties === 'object' ? log.properties : {};
    const changes: Record<string, { old: any; new: any; label?: string }> =
      log.changes && typeof log.changes === 'object' ? log.changes : {};
    
    // Detect dark mode
    const isDarkMode = document.documentElement.classList.contains('dark');
    
    // Color scheme based on dark mode
    const colors = {
      grayBg: isDarkMode ? '#1f2937' : '#f9fafb',
      grayText: isDarkMode ? '#9ca3af' : '#6b7280',
      grayDarkText: isDarkMode ? '#e5e7eb' : '#374151',
      redBg: isDarkMode ? '#7f1d1d' : '#fee2e2',
      redText: isDarkMode ? '#fca5a5' : '#dc2626',
      greenBg: isDarkMode ? '#15803d' : '#dcfce7',
      greenText: isDarkMode ? '#86efac' : '#16a34a',
      blueBg: isDarkMode ? '#1e3a8a' : '#dbeafe',
      blueText: isDarkMode ? '#93c5fd' : '#2563eb',
      indigoBg: isDarkMode ? '#312e81' : '#eef2ff',
      indigoText: isDarkMode ? '#a5b4fc' : '#4f46e5',
      borderColor: isDarkMode ? '#374151' : '#e5e7eb',
    };
    
    // Get concise modal title
    const modalTitle = getModalTitle(log);
    
    // Build diff view HTML
    let diffHtml = '';
    if (Object.keys(changes).length > 0) {
      diffHtml = `<div style="margin-top: 1rem;"><h3 style="font-weight: 600; font-size: 1.125rem; margin-bottom: 0.75rem; color: ${colors.grayDarkText}">Changes Made:</h3><div style="display: flex; flex-direction: column; gap: 0.75rem;">`;
      for (const [field, change] of Object.entries(changes)) {
        const fieldName = escapeHtml(change.label || field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
        diffHtml += `
          <div style="background-color: ${colors.grayBg}; padding: 0.75rem; border-radius: 0.5rem;">
            <p style="font-weight: 600; color: ${colors.grayDarkText}; margin-bottom: 0.5rem;">${fieldName}:</p>
            <div style="display: flex; gap: 1rem;">
              <div style="flex: 1;">
                <p style="font-size: 0.75rem; color: ${colors.grayText}; margin-bottom: 0.25rem;">Old Value</p>
                <p style="color: ${colors.redText}; text-decoration: line-through; background-color: ${colors.redBg}; padding: 0.25rem 0.5rem; border-radius: 0.25rem;">${escapeHtml(formatValue(change.old))}</p>
              </div>
              <div style="display: flex; align-items: center;">
                <svg style="width: 1.25rem; height: 1.25rem; color: ${colors.grayText};" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                </svg>
              </div>
              <div style="flex: 1;">
                <p style="font-size: 0.75rem; color: ${colors.grayText}; margin-bottom: 0.25rem;">New Value</p>
                <p style="color: ${colors.greenText}; background-color: ${colors.greenBg}; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-weight: 600;">${escapeHtml(formatValue(change.new))}</p>
              </div>
            </div>
          </div>
        `;
      }
      diffHtml += '</div></div>';
    } else if (log.event === 'created') {
      const attributes = properties?.attributes && typeof properties.attributes === 'object'
        ? properties.attributes
        : {};
      if (Object.keys(attributes).length > 0) {
        diffHtml = `<div style="margin-top: 1rem;"><h3 style="font-weight: 600; font-size: 1.125rem; margin-bottom: 0.75rem; color: ${colors.grayDarkText}">Created With:</h3><div style="background-color: ${colors.greenBg}; padding: 0.75rem; border-radius: 0.5rem;"><div style="display: flex; flex-direction: column; gap: 0.25rem;">`;
        for (const [key, value] of Object.entries(attributes)) {
          diffHtml += `<div style="font-size: 0.875rem;"><span style="color: ${colors.greenText}; font-weight: 600;">${escapeHtml(key.replace(/_/g, ' '))}:</span> ${escapeHtml(formatValue(value))}</div>`;
        }
        diffHtml += '</div></div></div>';
      }
    }

    // Build metadata HTML
    const metadataHtml = log.metadata ? `
      <div style="margin-top: 1rem; border-top: 1px solid ${colors.borderColor}; padding-top: 1rem;">
        <h3 style="font-weight: 600; font-size: 1.125rem; margin-bottom: 0.75rem; color: ${colors.grayDarkText}">Security Information:</h3>
        <div style="background-color: ${colors.blueBg}; padding: 0.75rem; border-radius: 0.5rem; display: flex; flex-direction: column; gap: 0.5rem;">
          <p style="font-size: 0.875rem;"><span style="font-weight: 600; color: ${colors.grayDarkText};">IP Address:</span> <span style="color: ${colors.blueText}; font-family: monospace;">${escapeHtml(log.metadata.ip_address)}</span></p>
          <p style="font-size: 0.875rem;"><span style="font-weight: 600; color: ${colors.grayDarkText};">Device/Browser:</span> <span style="color: ${colors.grayText};">${escapeHtml(parseUserAgent(log.metadata.user_agent))}</span></p>
        </div>
      </div>
    ` : '';

    const causerHtml = log.causer ? `
      <div style="background-color: ${colors.indigoBg}; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <p style="font-size: 0.875rem;"><span style="font-weight: 600; color: ${colors.grayDarkText};">Performed by:</span> <span style="color: ${colors.indigoText}; font-weight: 600;">${escapeHtml(log.causer.name)}</span></p>
        <p style="font-size: 0.875rem;"><span style="font-weight: 600; color: ${colors.grayDarkText};">Role:</span> <span style="color: ${colors.indigoText};">${escapeHtml(log.causer.role)}</span></p>
        <p style="font-size: 0.875rem;"><span style="font-weight: 600; color: ${colors.grayDarkText};">Email:</span> <span style="color: ${colors.grayText};">${escapeHtml(log.causer.email)}</span></p>
      </div>
    ` : '';

    Swal.fire({
      title: modalTitle,
      html: `
        <div style="text-align: left; color: ${colors.grayDarkText};">
          ${causerHtml}
          <p style="margin-bottom: 0.5rem; font-size: 0.875rem;"><strong>Date:</strong> ${escapeHtml(new Date(log.created_at).toLocaleString())}</p>
          <p style="margin-bottom: 1rem; font-size: 0.875rem;"><strong>Subject Type:</strong> ${escapeHtml(formatSubjectType(log.subject_type))}</p>
          ${diffHtml}
          ${metadataHtml}
        </div>
      `,
      width: 900,
      confirmButtonText: 'Close',
      confirmButtonColor: '#3b82f6',
      didOpen: (modal) => {
        // Ensure modal text is visible
        const htmlContent = modal.querySelector('.swal2-html-container');
        if (htmlContent) {
          htmlContent.style.color = colors.grayDarkText;
        }
      }
    });
  };

  const getEventBadgeColor = (event: string) => {
    switch (event) {
      case 'created': return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400';
      case 'updated': return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400';
      case 'deleted': return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400';
      default: return 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200';
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Manager - Activity Audit Logs" />

      <div className="p-6">
        {/* Header */}
        <div className="mb-6">
          <div className="flex items-center gap-3 mb-2">
            <h1 className="text-3xl font-bold text-gray-800 dark:text-white">Activity Audit Logs</h1>
          </div>
          <p className="text-gray-600 dark:text-gray-400 mt-2">
            Complete oversight of all activities across departments
          </p>
          <p className="text-sm text-gray-500 dark:text-gray-500 mt-1">
            Track all ERP operations: HR, Finance, CRM, and business activities
          </p>
        </div>

        {/* Stats Cards */}
        {stats && (
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <MetricCard
              title="Total Logs"
              value={stats.total_logs}
              change={12}
              changeType="increase"
              icon={DocumentTextIcon}
              color="info"
              description="All recorded activities"
            />

            <MetricCard
              title="Last 24 Hours"
              value={stats.logs_last_24h}
              change={8}
              changeType="increase"
              icon={ClockIcon}
              color="success"
              description="Recent activity count"
            />

            <MetricCard
              title="Created"
              value={stats.event_counts.created || 0}
              change={15}
              changeType="increase"
              icon={ShieldCheckIcon}
              color="success"
              description="New items created"
            />

            <MetricCard
              title="Updated"
              value={stats.event_counts.updated || 0}
              change={5}
              changeType="increase"
              icon={BuildingStorefrontIcon}
              color="warning"
              description="Items modified"
            />
          </div>
        )}

        {/* Filters */}
        <div className="bg-white dark:bg-gray-900 p-4 rounded-lg shadow mb-6 border border-gray-200 dark:border-gray-700">
          <button
            onClick={() => setShowFilters(!showFilters)}
            className="flex items-center gap-2 text-gray-700 dark:text-gray-300 font-semibold mb-4 hover:text-blue-600 dark:hover:text-blue-400 transition"
            aria-label={showFilters ? "Hide audit log filters" : "Show audit log filters"}
          >
            <FunnelIcon className="w-5 h-5" />
            {showFilters ? 'Hide' : 'Show'} Filters
          </button>

          {showFilters && (
            <fieldset className="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
              <legend className="text-sm font-semibold text-gray-700 dark:text-gray-300 px-2">Filter Activity Logs</legend>
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                <div>
                  <label htmlFor="date-from" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date From</label>
                  <input
                    id="date-from"
                    type="date"
                    value={filters.date_from || ""}
                    onChange={(e) => setFilter('date_from', e.target.value || null)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                    aria-label="Filter from date"
                  />
                </div>

                <div>
                  <label htmlFor="date-to" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date To</label>
                  <input
                    id="date-to"
                    type="date"
                    value={filters.date_to || ""}
                    onChange={(e) => setFilter('date_to', e.target.value || null)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                    aria-label="Filter to date"
                  />
                </div>

                <div>
                  <label htmlFor="event-filter" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Event</label>
                  <select
                    id="event-filter"
                    value={filters.event || ""}
                    onChange={(e) => setFilter('event', e.target.value || null)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                    aria-label="Filter by event type"
                  >
                    <option value="">All Events</option>
                    <option value="created">Created</option>
                    <option value="updated">Updated</option>
                    <option value="deleted">Deleted</option>
                  </select>
                </div>

                <div>
                  <label htmlFor="activity-type-filter" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Activity Type</label>
                  <select
                    id="activity-type-filter"
                    value={filters.subject_type || ""}
                    onChange={(e) => setFilter('subject_type', e.target.value || null)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                    aria-label="Filter by activity type"
                  >
                    <option value="">All Types</option>
                    <option value="Product">Products</option>
                    <option value="Expense">Expenses</option>
                    <option value="User">Employees</option>
                    <option value="Order">Orders</option>
                    <option value="Invoice">Invoices</option>
                    <option value="Customer">Customers</option>
                    <option value="Payroll">Payroll</option>
                    <option value="LeaveRequest">Leave Requests</option>
                    <option value="AttendanceRecord">Attendance</option>
                    <option value="RepairRequest">Repair Requests</option>
                    <option value="RepairService">Repair Services</option>
                    <option value="PriceChangeRequest">Price Changes</option>
                  </select>
                </div>

                <div className="md:col-span-4">
                  <button
                    onClick={() => resetFilters()}
                    className="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition font-medium"
                    aria-label="Reset all filters"
                  >
                    Clear Filters
                  </button>
                </div>
              </div>
            </fieldset>
          )}
        </div>

        {/* Logs Table */}
        <div className="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden border border-gray-200 dark:border-gray-700">
          {error ? (
            <div className="p-12 text-center">
              <div className="bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-6 rounded">
                <h3 className="text-lg font-semibold text-red-800 dark:text-red-400 mb-2">Failed to Load Activity Logs</h3>
                <p className="text-red-700 dark:text-red-300 mb-4">{error}</p>
                <button
                  onClick={() => fetchLogs(filters, page)}
                  className="px-4 py-2 bg-red-600 dark:bg-red-700 text-white rounded-lg hover:bg-red-700 dark:hover:bg-red-600 transition font-medium"
                  aria-label="Retry loading activity logs"
                >
                  Retry
                </button>
              </div>
            </div>
          ) : loading ? (
            <div className="flex justify-center items-center h-64">
              <div className="text-center">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-3"></div>
                <p className="text-gray-600 dark:text-gray-400 font-medium">Loading activity logs...</p>
              </div>
            </div>
          ) : logs.length === 0 ? (
            <div className="text-center py-12 text-gray-500 dark:text-gray-400">
              <DocumentTextIcon className="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-600" />
              <p className="text-lg font-medium">No activity logs found</p>
              <p className="text-sm text-gray-400 dark:text-gray-500 mt-2">
                {Object.values(filters).some(f => f) 
                  ? "Try adjusting your filters or clear them to see all activities"
                  : "Activities will appear here as changes are made"}
              </p>
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Event</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Description</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User/Actor</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Subject</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                    {logs.map((log) => {
                      const formattedDesc = formatDetailedDescription(log);
                      
                      return (
                        <tr key={log.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                          <td className="px-6 py-4 whitespace-nowrap">
                            <span className={`px-3 py-1 rounded-full text-xs font-semibold ${getEventBadgeColor(log.event)}`}>
                              {log.event}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <p className="text-sm text-gray-900 dark:text-gray-100 font-medium">{formattedDesc}</p>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            {log.causer ? (
                              <>
                                <p className="text-sm text-gray-900 dark:text-gray-100 font-medium">{log.causer.name}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{log.causer.role}</p>
                              </>
                            ) : (
                              <p className="text-xs text-gray-400 dark:text-gray-600">Unknown User</p>
                            )}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <p className="text-sm text-gray-900 dark:text-gray-100">{formatSubjectType(log.subject_type)}</p>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <p className="text-sm text-gray-900 dark:text-gray-100">{new Date(log.created_at).toLocaleDateString()}</p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">{new Date(log.created_at).toLocaleTimeString()}</p>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <button
                              onClick={() => viewLogDetails(log)}
                              className="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/30 p-2 rounded-lg transition"
                              title="View details"
                              aria-label={`View details for ${formatDetailedDescription(log)}`}
                            >
                              <EyeIcon className="w-5 h-5" />
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {pagination && pagination.last_page > 1 && (
                <div className="flex justify-between items-center px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex-wrap gap-4">
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    Showing <span className="font-semibold">{((pagination.current_page - 1) * pagination.per_page) + 1}</span> to{' '}
                    <span className="font-semibold">{Math.min(pagination.current_page * pagination.per_page, pagination.total)}</span> of{' '}
                    <span className="font-semibold">{pagination.total}</span> results
                  </p>

                  <div className="flex gap-2">
                    <button
                      onClick={() => {
                        if (page > 1) {
                          window.scrollTo({ top: 0, behavior: 'smooth' });
                          setPersistentPage(page - 1);
                        }
                      }}
                      disabled={page === 1}
                      className="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white dark:hover:bg-gray-700 dark:bg-gray-800 dark:text-white transition"
                      aria-label="Previous page"
                    >
                      <ChevronLeftIcon className="w-5 h-5" />
                    </button>

                    {[...Array(pagination.last_page)].map((_, idx) => {
                      const pageNum = idx + 1;
                      if (
                        pageNum === 1 ||
                        pageNum === pagination.last_page ||
                        (pageNum >= page - 1 && pageNum <= page + 1)
                      ) {
                        return (
                          <button
                            key={pageNum}
                            onClick={() => {
                              setPersistentPage(pageNum);
                              window.scrollTo({ top: 0, behavior: 'smooth' });
                            }}
                            className={`px-3 py-1 border rounded-lg transition ${
                              page === pageNum
                                ? 'bg-blue-500 text-white border-blue-500'
                                : 'border-gray-300 dark:border-gray-600 hover:bg-white dark:hover:bg-gray-700 dark:bg-gray-800 dark:text-white'
                            }`}
                            aria-label={`Go to page ${pageNum}`}
                            aria-current={page === pageNum ? "page" : undefined}
                          >
                            {pageNum}
                          </button>
                        );
                      } else if (pageNum === page - 2 || pageNum === page + 2) {
                        return <span key={pageNum} className="px-2 text-gray-500 dark:text-gray-400">...</span>;
                      }
                      return null;
                    })}

                    <button
                      onClick={() => {
                        if (page < pagination.last_page) {
                          window.scrollTo({ top: 0, behavior: 'smooth' });
                          setPersistentPage(page + 1);
                        }
                      }}
                      disabled={page === pagination.last_page}
                      className="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white dark:hover:bg-gray-700 dark:bg-gray-800 dark:text-white transition"
                      aria-label="Next page"
                    >
                      <ChevronRightIcon className="w-5 h-5" />
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </AppLayoutERP>
  );
}




