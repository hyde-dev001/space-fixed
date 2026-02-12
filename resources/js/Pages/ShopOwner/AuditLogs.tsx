import React, { useState, useEffect } from "react";
import { Head } from "@inertiajs/react";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import Swal from "sweetalert2";

interface ActivityLog {
  id: number;
  log_name: string | null;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  causer_type: string | null;
  causer_id: number | null;
  event: string;
  properties: Record<string, any>;
  changes: Record<string, { old: any; new: any }>;
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

const MagnifyingGlassIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
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
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
      {/* Animated background gradient */}
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
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

export default function ShopOwnerAuditLogs() {
  const [logs, setLogs] = useState<ActivityLog[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [pagination, setPagination] = useState<PaginationData | null>(null);
  const [loading, setLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  
  // Filters
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [eventFilter, setEventFilter] = useState("");
  const [subjectTypeFilter, setSubjectTypeFilter] = useState("");
  const [showFilters, setShowFilters] = useState(false);

  useEffect(() => {
    fetchLogs(currentPage);
  }, [currentPage, dateFrom, dateTo, eventFilter, subjectTypeFilter]);

  const fetchLogs = async (page: number) => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);
      if (eventFilter) params.append('event', eventFilter);
      if (subjectTypeFilter) params.append('subject_type', subjectTypeFilter);

      const response = await fetch(`/api/activity-logs?${params.toString()}`);
      if (!response.ok) throw new Error('Failed to fetch logs');
      
      const data = await response.json();
      setLogs(data.logs.data);
      setPagination(data.logs);
      setStats(data.stats);
    } catch (error) {
      console.error('Error fetching logs:', error);
      Swal.fire({
        title: 'Error',
        text: 'Failed to load activity logs',
        icon: 'error',
      });
    } finally {
      setLoading(false);
    }
  };

  const clearFilters = () => {
    setDateFrom("");
    setDateTo("");
    setEventFilter("");
    setSubjectTypeFilter("");
  };

  // Format value for display
  const formatValue = (value: any): string => {
    if (value === null || value === undefined) return 'N/A';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    if (typeof value === 'number' && value > 100 && value < 999999) {
      // Likely a price
      return `₱${parseFloat(value.toString()).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
    return value.toString();
  };

  // Parse user agent to readable format
  const parseUserAgent = (ua: string): string => {
    if (!ua || ua === 'N/A') return 'Unknown Device';
    
    let browser = 'Unknown Browser';
    let os = 'Unknown OS';
    
    if (ua.includes('Chrome')) browser = 'Chrome';
    else if (ua.includes('Firefox')) browser = 'Firefox';
    else if (ua.includes('Safari') && !ua.includes('Chrome')) browser = 'Safari';
    else if (ua.includes('Edge')) browser = 'Edge';
    
    if (ua.includes('Windows')) os = 'Windows';
    else if (ua.includes('Mac')) os = 'macOS';
    else if (ua.includes('Linux')) os = 'Linux';
    else if (ua.includes('Android')) os = 'Android';
    else if (ua.includes('iOS')) os = 'iOS';
    
    return `${browser} on ${os}`;
  };

  // Format detailed description with context
  const formatDetailedDescription = (log: ActivityLog): string => {
    const causerName = log.causer?.name || 'Unknown User';
    const subjectType = formatSubjectType(log.subject_type);
    const subjectId = log.subject_id || '';
    
    const changes = log.changes || {};
    const changedFields = Object.keys(changes);
    
    switch (log.event) {
      case 'created':
        return `${causerName} created ${subjectType} #${subjectId}`;
      
      case 'updated':
        if (changedFields.length === 1) {
          const field = changedFields[0].replace(/_/g, ' ');
          const oldVal = formatValue(changes[changedFields[0]].old);
          const newVal = formatValue(changes[changedFields[0]].new);
          return `${causerName} updated the ${field} of ${subjectType} #${subjectId} from ${oldVal} to ${newVal}`;
        } else if (changedFields.length > 1) {
          return `${causerName} updated ${changedFields.length} fields in ${subjectType} #${subjectId}`;
        }
        return `${causerName} updated ${subjectType} #${subjectId}`;
      
      case 'deleted':
        return `${causerName} deleted ${subjectType} #${subjectId}`;
      
      default:
        return log.description || `${causerName} performed ${log.event} on ${subjectType} #${subjectId}`;
    }
  };

  // Get subject URL for linking
  const getSubjectUrl = (log: ActivityLog): string | null => {
    if (!log.subject_type || !log.subject_id) return null;
    
    const type = log.subject_type.split('\\').pop()?.toLowerCase();
    
    switch (type) {
      case 'product':
        return `/shop-owner/products?id=${log.subject_id}`;
      case 'expense':
        return `/finance/expenses?id=${log.subject_id}`;
      case 'user':
      case 'employee':
        return `/shop-owner/user-access-control?id=${log.subject_id}`;
      case 'order':
        return `/shop-owner/orders?id=${log.subject_id}`;
      case 'customer':
        return `/erp/crm/customers?id=${log.subject_id}`;
      default:
        return null;
    }
  };

  const viewLogDetails = (log: ActivityLog) => {
    const properties = log.properties;
    const changes = log.changes || {};
    
    // Build formatted description
    const formattedDescription = formatDetailedDescription(log);
    
    // Build diff view HTML
    let diffHtml = '';
    if (Object.keys(changes).length > 0) {
      diffHtml = '<div class="mt-4"><h3 class="font-semibold text-lg mb-3">Changes Made:</h3><div class="space-y-3">';
      for (const [field, change] of Object.entries(changes)) {
        const fieldName = field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        diffHtml += `
          <div class="bg-gray-50 p-3 rounded-lg">
            <p class="font-semibold text-gray-700 mb-2">${fieldName}:</p>
            <div class="flex gap-4">
              <div class="flex-1">
                <p class="text-xs text-gray-500 mb-1">Old Value</p>
                <p class="text-red-600 line-through bg-red-50 px-2 py-1 rounded">${formatValue(change.old)}</p>
              </div>
              <div class="flex items-center">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                </svg>
              </div>
              <div class="flex-1">
                <p class="text-xs text-gray-500 mb-1">New Value</p>
                <p class="text-green-600 bg-green-50 px-2 py-1 rounded font-semibold">${formatValue(change.new)}</p>
              </div>
            </div>
          </div>
        `;
      }
      diffHtml += '</div></div>';
    } else if (log.event === 'created') {
      const attributes = properties.attributes || {};
      if (Object.keys(attributes).length > 0) {
        diffHtml = '<div class="mt-4"><h3 class="font-semibold text-lg mb-3">Created With:</h3><div class="bg-green-50 p-3 rounded-lg"><div class="space-y-1">';
        for (const [key, value] of Object.entries(attributes)) {
          diffHtml += `<div class="text-sm"><span class="text-green-700 font-semibold">${key.replace(/_/g, ' ')}:</span> ${formatValue(value)}</div>`;
        }
        diffHtml += '</div></div></div>';
      }
    }

    // Build metadata HTML
    const metadataHtml = log.metadata ? `
      <div class="mt-4 border-t pt-4">
        <h3 class="font-semibold text-lg mb-3">Security Information:</h3>
        <div class="bg-blue-50 p-3 rounded-lg space-y-2">
          <p class="text-sm"><span class="font-semibold text-gray-700">IP Address:</span> <span class="text-blue-700 font-mono">${log.metadata.ip_address}</span></p>
          <p class="text-sm"><span class="font-semibold text-gray-700">Device/Browser:</span> <span class="text-gray-600">${parseUserAgent(log.metadata.user_agent)}</span></p>
        </div>
      </div>
    ` : '';

    const causerHtml = log.causer ? `
      <div class="bg-indigo-50 p-3 rounded-lg mb-4">
        <p class="text-sm"><span class="font-semibold text-gray-700">Performed by:</span> <span class="text-indigo-700 font-semibold">${log.causer.name}</span></p>
        <p class="text-sm"><span class="font-semibold text-gray-700">Role:</span> <span class="text-indigo-600">${log.causer.role}</span></p>
        <p class="text-sm"><span class="font-semibold text-gray-700">Email:</span> <span class="text-gray-600">${log.causer.email}</span></p>
      </div>
    ` : '';

    Swal.fire({
      title: formattedDescription,
      html: `
        <div class="text-left">
          ${causerHtml}
          <p class="mb-2 text-sm"><strong>Date:</strong> ${new Date(log.created_at).toLocaleString()}</p>
          <p class="mb-4 text-sm"><strong>Subject:</strong> ${formatSubjectType(log.subject_type)} (ID: ${log.subject_id || 'N/A'})</p>
          ${diffHtml}
          ${metadataHtml}
        </div>
      `,
      width: 900,
      confirmButtonText: 'Close',
      confirmButtonColor: '#3b82f6',
    });
  };

  const getEventBadgeColor = (event: string) => {
    switch (event) {
      case 'created': return 'bg-green-100 text-green-800';
      case 'updated': return 'bg-blue-100 text-blue-800';
      case 'deleted': return 'bg-red-100 text-red-800';
      default: return 'bg-gray-100 text-gray-800';
    }
  };

  const formatSubjectType = (type: string | null) => {
    if (!type) return 'N/A';
    const parts = type.split('\\');
    return parts[parts.length - 1];
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Activity Audit Logs" />

      <div className="p-6">
        {/* Header */}
        <div className="mb-6">
          <div className="flex items-center gap-3 mb-2">
            <h1 className="text-3xl font-bold text-gray-800">Activity Audit Logs</h1>
          </div>
          <p className="text-gray-600 mt-2">
            Complete audit trail of all activities in your business
          </p>
          <p className="text-sm text-gray-500 mt-1">
            Track products, expenses, employees, orders, and all business operations
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
        <div className="bg-white p-4 rounded-lg shadow mb-6">
          <button
            onClick={() => setShowFilters(!showFilters)}
            className="flex items-center gap-2 text-gray-700 font-semibold mb-4 hover:text-blue-600 transition"
          >
            <FunnelIcon className="w-5 h-5" />
            {showFilters ? 'Hide' : 'Show'} Filters
          </button>

          {showFilters && (
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input
                  type="date"
                  value={dateFrom}
                  onChange={(e) => setDateFrom(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input
                  type="date"
                  value={dateTo}
                  onChange={(e) => setDateTo(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Event</label>
                <select
                  value={eventFilter}
                  onChange={(e) => setEventFilter(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="">All Events</option>
                  <option value="created">Created</option>
                  <option value="updated">Updated</option>
                  <option value="deleted">Deleted</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Activity Type</label>
                <select
                  value={subjectTypeFilter}
                  onChange={(e) => setSubjectTypeFilter(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="">All Types</option>
                  <option value="Product">Products</option>
                  <option value="Expense">Expenses</option>
                  <option value="User">Employees</option>
                  <option value="Order">Orders</option>
                  <option value="Invoice">Invoices</option>
                  <option value="Customer">Customers</option>
                  <option value="Payroll">Payroll</option>
                  <option value="Leave">Leave Requests</option>
                </select>
              </div>

              <div className="md:col-span-4">
                <button
                  onClick={clearFilters}
                  className="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition"
                >
                  Clear Filters
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Logs Table */}
        <div className="bg-white rounded-lg shadow overflow-hidden">
          {loading ? (
            <div className="flex justify-center items-center h-64">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
            </div>
          ) : logs.length === 0 ? (
            <div className="text-center py-12 text-gray-500">
              <DocumentTextIcon className="w-16 h-16 mx-auto mb-4 text-gray-400" />
              <p className="text-lg">No activity logs found</p>
              <p className="text-sm text-gray-400 mt-2">Activities will appear here as changes are made</p>
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50 border-b">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User/Actor</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {logs.map((log) => {
                      const subjectUrl = getSubjectUrl(log);
                      const formattedDesc = formatDetailedDescription(log);
                      
                      return (
                        <tr key={log.id} className="hover:bg-gray-50 transition">
                          <td className="px-6 py-4 whitespace-nowrap">
                            <span className={`px-3 py-1 rounded-full text-xs font-semibold ${getEventBadgeColor(log.event)}`}>
                              {log.event}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <p className="text-sm text-gray-900 font-medium">{formattedDesc}</p>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            {log.causer ? (
                              <>
                                <p className="text-sm text-gray-900 font-medium">{log.causer.name}</p>
                                <p className="text-xs text-gray-500">{log.causer.role}</p>
                              </>
                            ) : (
                              <p className="text-xs text-gray-400">Unknown User</p>
                            )}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <p className="text-sm text-gray-900">{formatSubjectType(log.subject_type)}</p>
                            {subjectUrl ? (
                              <a 
                                href={subjectUrl} 
                                className="text-xs text-blue-600 hover:text-blue-800 hover:underline"
                                title="View this item"
                              >
                                ID: {log.subject_id || 'N/A'} →
                              </a>
                            ) : (
                              <p className="text-xs text-gray-500">ID: {log.subject_id || 'N/A'}</p>
                            )}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <p className="text-sm text-gray-900">{new Date(log.created_at).toLocaleDateString()}</p>
                            <p className="text-xs text-gray-500">{new Date(log.created_at).toLocaleTimeString()}</p>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <button
                              onClick={() => viewLogDetails(log)}
                              className="text-blue-600 hover:text-blue-800 hover:bg-blue-50 p-2 rounded-lg transition"
                              title="View details"
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
                <div className="flex justify-between items-center px-6 py-4 border-t bg-gray-50">
                  <p className="text-sm text-gray-700">
                    Showing <span className="font-semibold">{((pagination.current_page - 1) * pagination.per_page) + 1}</span> to{' '}
                    <span className="font-semibold">{Math.min(pagination.current_page * pagination.per_page, pagination.total)}</span> of{' '}
                    <span className="font-semibold">{pagination.total}</span> results
                  </p>

                  <div className="flex gap-2">
                    <button
                      onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                      disabled={currentPage === 1}
                      className="px-3 py-1 border rounded-lg disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white transition"
                    >
                      <ChevronLeftIcon className="w-5 h-5" />
                    </button>

                    {[...Array(pagination.last_page)].map((_, idx) => {
                      const pageNum = idx + 1;
                      if (
                        pageNum === 1 ||
                        pageNum === pagination.last_page ||
                        (pageNum >= currentPage - 1 && pageNum <= currentPage + 1)
                      ) {
                        return (
                          <button
                            key={pageNum}
                            onClick={() => setCurrentPage(pageNum)}
                            className={`px-3 py-1 border rounded-lg transition ${
                              currentPage === pageNum
                                ? 'bg-blue-500 text-white border-blue-500'
                                : 'hover:bg-white'
                            }`}
                          >
                            {pageNum}
                          </button>
                        );
                      } else if (pageNum === currentPage - 2 || pageNum === currentPage + 2) {
                        return <span key={pageNum} className="px-2">...</span>;
                      }
                      return null;
                    })}

                    <button
                      onClick={() => setCurrentPage(prev => Math.min(pagination.last_page, prev + 1))}
                      disabled={currentPage === pagination.last_page}
                      className="px-3 py-1 border rounded-lg disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white transition"
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
    </AppLayoutShopOwner>
  );
}
