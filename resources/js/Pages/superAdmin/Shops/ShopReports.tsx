import React, { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';

// ── Types ──────────────────────────────────────────────────────────────────────
interface Reporter {
  id: number;
  name: string;
  email: string;
  created_at: string;
  days_old: number;
}

interface Report {
  id: number;
  reason: string;
  reason_label: string;
  description: string;
  status: string;
  status_label: string;
  transaction_type: string | null;
  transaction_id: number | null;
  admin_notes: string | null;
  reviewed_at: string | null;
  ip_address: string | null;
  created_at: string;
  reporter: Reporter | null;
}

interface ShopGroup {
  shop_owner_id: number;
  business_name: string;
  shop_email: string;
  shop_status: string;
  total_reports: number;
  open_reports: number;
  open_report_ids?: number[];
  latest_reason: string;
  latest_date: string | null;
  pattern_flags: string[];
  warning_strike: number;
  warning_limit: number;
  warnings_until_suspension: number;
  next_warn_will_suspend: boolean;
  priority: 'high' | 'medium' | 'normal';
  reports?: Report[];
}

interface ShopGroupPage {
  data: ShopGroup[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

interface Stats {
  total_reports: number;
  pending_review: number;
  high_priority: number;
  resolved: number;
}

interface PageProps {
  shopGroups: ShopGroup[] | ShopGroupPage;
  stats: Stats;
  filters?: { search?: string | null; priority?: string; status?: string };
  success?: string;
  error?: string;
}

interface ReportDetail {
  reports: {
    data: Report[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  open_report_ids: number[];
}

const isShopGroupPage = (value: ShopGroup[] | ShopGroupPage): value is ShopGroupPage => !Array.isArray(value);

// ── Icons ──────────────────────────────────────────────────────────────────────
const FlagIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6H13l-1-1H5a2 2 0 00-2 2zm9-13.5V9" />
  </svg>
);

const ShieldIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
  </svg>
);

const ChevronDownIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
  </svg>
);

const ChevronUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
  </svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const SearchIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
  </svg>
);

const XIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

// ── Stat Card ──────────────────────────────────────────────────────────────────
const StatCard = ({
  title,
  value,
  icon: Icon,
}: {
  title: string;
  value: number;
  icon: React.FC<{ className?: string }>;
}) => {
  return (
    <div className="metrics-card overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition hover:border-gray-300 hover:shadow-md dark:border-gray-800 dark:bg-gray-800 dark:hover:border-gray-700">
      <div className="p-6">
        <div className="flex items-start justify-between gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-gray-200 bg-gray-100 text-gray-900 dark:border-gray-700 dark:bg-gray-700 dark:text-gray-100">
            <Icon className="h-6 w-6" />
          </div>
          <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">Snapshot</span>
        </div>
        <p className="mt-5 text-sm font-medium text-gray-500 dark:text-gray-400">{title}</p>
        <p className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{value}</p>
      </div>
    </div>
  );
};

// ── Pattern Flag Badges ────────────────────────────────────────────────────────
const FLAG_LABELS: Record<string, string> = {
  batch_reports: '⚡ Batch Reports',
  new_account_reporters: '🆕 New Accounts',
  ip_clustering: '🌐 IP Cluster',
};

const PatternFlagBadge = ({ flag }: { flag: string }) => (
  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 border border-orange-200 dark:border-orange-700">
    {FLAG_LABELS[flag] ?? flag}
  </span>
);

// ── Priority Badge ─────────────────────────────────────────────────────────────
const PriorityBadge = ({ priority }: { priority: string }) => {
  const styles: Record<string, string> = {
    high: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 border border-red-200 dark:border-red-700',
    medium: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-700',
    normal: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400 border border-gray-200 dark:border-gray-600',
  };
  const labels: Record<string, string> = {
    high: '🔴 High Priority',
    medium: '🟡 Needs Review',
    normal: '⚪ Normal',
  };
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${styles[priority] ?? styles.normal}`}>
      {labels[priority] ?? priority}
    </span>
  );
};

// ── Report Status Badge ────────────────────────────────────────────────────────
const StatusBadge = ({ status }: { status: string }) => {
  const styles: Record<string, string> = {
    submitted: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    under_review: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    dismissed: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
    warned: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    suspended: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };
  const labels: Record<string, string> = {
    submitted: 'Submitted',
    under_review: 'Under Review',
    dismissed: 'Dismissed',
    warned: 'Warned',
    suspended: 'Suspended',
  };
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${styles[status] ?? styles.submitted}`}>
      {labels[status] ?? status}
    </span>
  );
};

// ── Action Modal ───────────────────────────────────────────────────────────────
const ActionModal = ({
  shopGroup,
  onClose,
}: {
  shopGroup: ShopGroup;
  onClose: () => void;
}) => {
  const [action, setAction] = useState<'dismiss' | 'warn' | 'suspend' | ''>('');
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const openReportIds = shopGroup.open_report_ids ?? [];

  const nextWarnStrike = Math.min(shopGroup.warning_strike + 1, shopGroup.warning_limit);
  const suspensionPending = action === 'suspend'
    || (action === 'warn' && shopGroup.next_warn_will_suspend);

  const handleSubmit = () => {
    if (!action) return;

    const labels: Record<string, string> = {
      dismiss: 'Dismiss Reports',
      warn: 'Warn Shop',
      suspend: 'Suspend Shop',
    };

    const warnings: Record<string, string> = {
      dismiss: `This will mark ${openReportIds.length} selected report(s) as dismissed.`,
      warn: shopGroup.next_warn_will_suspend
        ? `Current warning level is <b>${shopGroup.warning_strike}/${shopGroup.warning_limit}</b>. Applying warn now will auto-escalate this shop to <b>suspension</b>.`
        : `Current warning level is <b>${shopGroup.warning_strike}/${shopGroup.warning_limit}</b>. This warn action will move the shop to <b>${nextWarnStrike}/${shopGroup.warning_limit}</b>.`,
      suspend: `This will <b>suspend "${shopGroup.business_name}"</b> immediately. The shop will lose access until reactivated.`,
    };

    Swal.fire({
      title: `Confirm: ${labels[action]}`,
      html: warnings[action],
      icon: action === 'suspend' ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonColor: action === 'suspend' ? '#ef4444' : action === 'warn' ? '#f59e0b' : '#6b7280',
      confirmButtonText: labels[action],
      cancelButtonText: 'Cancel',
    }).then((result) => {
      if (!result.isConfirmed) return;

      setErrorMessage('');
      setSubmitting(true);
      router.post(
        `/admin/shop-reports/${shopGroup.shop_owner_id}/action`,
        {
          action,
          report_ids: openReportIds,
          admin_notes: notes,
        },
        {
          preserveScroll: true,
          onSuccess: () => {
            onClose();
          },
          onError: (errors) => {
            const firstError = Object.values(errors)[0];
            setErrorMessage(Array.isArray(firstError) ? firstError[0] : firstError ?? 'The server did not apply this decision.');
          },
          onFinish: () => setSubmitting(false),
        }
      );
    });
  };

  return (
    <div className="fixed inset-0 z-100000 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
          <div>
            <h3 className="text-lg font-bold text-gray-900 dark:text-white">Take Action</h3>
            <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
              {shopGroup.business_name} — {shopGroup.open_reports} open report(s)
            </p>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 transition-colors"
          >
            <XIcon className="w-5 h-5" />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-4">
          <div className={`rounded-lg border px-3 py-2 text-xs font-medium ${shopGroup.next_warn_will_suspend ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300' : 'border-yellow-200 bg-yellow-50 text-yellow-700 dark:border-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-300'}`}>
            Warning Progress: {shopGroup.warning_strike}/{shopGroup.warning_limit}
            {shopGroup.next_warn_will_suspend
              ? ' • Next warn will auto-suspend this shop.'
              : ` • ${shopGroup.warnings_until_suspension} warning(s) before auto-suspension.`}
          </div>
          {errorMessage && (
            <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300">
              {errorMessage}
            </p>
          )}

          {/* Action selector */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Select Action
            </label>
            <div className="grid grid-cols-3 gap-2">
              {([
                { value: 'dismiss', label: 'Dismiss', color: 'gray' },
                { value: 'warn', label: 'Warn', color: 'yellow' },
                { value: 'suspend', label: 'Suspend', color: 'red' },
              ] as const).map(({ value, label, color }) => {
                const selected = action === value;
                const colorMap: Record<string, string> = {
                  gray: selected
                    ? 'bg-gray-700 text-white border-gray-700'
                    : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700',
                  yellow: selected
                    ? 'bg-yellow-500 text-white border-yellow-500'
                    : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-yellow-50 dark:hover:bg-gray-700',
                  red: selected
                    ? 'bg-red-600 text-white border-red-600'
                    : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-red-50 dark:hover:bg-gray-700',
                };
                return (
                  <button
                    key={value}
                    onClick={() => setAction(value)}
                    className={`py-2 rounded-lg border-2 text-sm font-semibold transition-all ${colorMap[color]}`}
                  >
                    {label}
                  </button>
                );
              })}
            </div>
            {action === 'warn' && (
              <p className={`mt-2 text-xs ${shopGroup.next_warn_will_suspend ? 'text-red-500 dark:text-red-400' : 'text-yellow-600 dark:text-yellow-400'}`}>
                {shopGroup.next_warn_will_suspend
                  ? 'Warning now will trigger automatic suspension (3/3).'
                  : `Warning now moves this shop to ${nextWarnStrike}/${shopGroup.warning_limit}.`}
              </p>
            )}
          </div>

          {/* Notes */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Admin Notes {suspensionPending && <span className="text-red-500">*</span>}
            </label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={3}
              placeholder={
                suspensionPending
                  ? 'Reason for suspension (required — will be shown to shop owner)...'
                  : 'Optional notes for this action...'
              }
              className="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-none"
            />
          </div>

          {suspensionPending && (
            <p className="text-xs text-red-500 dark:text-red-400 flex items-center gap-1">
              <AlertIcon className="w-4 h-4 flex-shrink-0" />
              This will immediately suspend the shop and block access.
            </p>
          )}
        </div>

        {/* Footer */}
        <div className="flex gap-3 p-6 pt-0">
          <button
            onClick={onClose}
            className="flex-1 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={handleSubmit}
            disabled={!action || submitting || !openReportIds.length || (suspensionPending && !notes.trim())}
            className="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed bg-blue-600 hover:bg-blue-700"
          >
            {submitting ? 'Processing…' : 'Confirm Action'}
          </button>
        </div>
      </div>
    </div>
  );
};

// ── Individual Report Row ──────────────────────────────────────────────────────
const ReportRow = ({ report }: { report: Report }) => (
  <div className="border border-gray-100 dark:border-gray-700 rounded-xl p-4 space-y-2">
    <div className="flex flex-wrap items-center gap-2">
      <StatusBadge status={report.status} />
      <span className="text-xs font-semibold text-gray-700 dark:text-gray-300">
        {report.reason_label}
      </span>
      {report.transaction_type && (
        <span className="text-xs px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
          Proof: {report.transaction_type} #{report.transaction_id}
        </span>
      )}
      <span className="text-xs text-gray-400 ml-auto">
        {new Date(report.created_at).toLocaleDateString('en-US', {
          month: 'short', day: 'numeric', year: 'numeric'
        })}
      </span>
    </div>
    <p className="text-sm text-gray-600 dark:text-gray-300 line-clamp-3">{report.description}</p>
    {report.reporter && (
      <div className="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
        <span>
          Reporter: <span className="font-medium text-gray-600 dark:text-gray-300">{report.reporter.name}</span>
        </span>
        <span>·</span>
        <span>{report.reporter.email}</span>
        {report.reporter.days_old < 7 && (
          <span className="px-1.5 py-0.5 rounded bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400">
            New account ({report.reporter.days_old}d old)
          </span>
        )}
        {report.ip_address && (
          <>
            <span>·</span>
            <span>IP: {report.ip_address}</span>
          </>
        )}
      </div>
    )}
    {report.admin_notes && (
      <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg px-3 py-2 text-xs text-gray-500 dark:text-gray-400 italic">
        Admin: {report.admin_notes}
      </div>
    )}
  </div>
);

// ── Shop Group Row ─────────────────────────────────────────────────────────────
const ShopGroupRow = ({
  group,
  onAction,
  onLoadReports,
  detail,
  loadingDetail,
}: {
  group: ShopGroup;
  onAction: (g: ShopGroup) => void;
  onLoadReports: (g: ShopGroup) => void;
  detail?: ReportDetail;
  loadingDetail: boolean;
  detailError?: string;
}) => {
  const [expanded, setExpanded] = useState(false);
  const hasOpenReports = group.open_reports > 0;
  const reports = detail?.reports.data ?? group.reports ?? [];

  return (
    <div
      className={`bg-white dark:bg-gray-800 rounded-2xl border shadow-sm transition-all ${
        group.priority === 'high'
          ? 'border-red-300 dark:border-red-700'
          : group.priority === 'medium'
          ? 'border-yellow-300 dark:border-yellow-700'
          : 'border-gray-100 dark:border-gray-700'
      }`}
    >
      {/* Main row */}
      <div className="p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          {/* Left: shop info */}
          <div className="flex-1 min-w-0">
            <div className="flex flex-wrap items-center gap-2 mb-1">
              <h3 className="text-base font-bold text-gray-900 dark:text-white truncate">
                {group.business_name}
              </h3>
              <PriorityBadge priority={group.priority} />
              <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${group.next_warn_will_suspend ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 border border-red-200 dark:border-red-700' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-700'}`}>
                Warning {group.warning_strike}/{group.warning_limit}
              </span>
              {group.shop_status === 'suspended' && (
                <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-600 text-white">
                  Shop Suspended
                </span>
              )}
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">{group.shop_email}</p>

            {/* Counts */}
            <div className="flex flex-wrap items-center gap-4 text-sm">
              <span>
                <span className="font-bold text-gray-900 dark:text-white">{group.open_reports}</span>
                <span className="text-gray-500 dark:text-gray-400"> open</span>
              </span>
              <span>
                <span className="font-bold text-gray-900 dark:text-white">{group.total_reports}</span>
                <span className="text-gray-500 dark:text-gray-400"> total</span>
              </span>
              {group.shop_status !== 'suspended' && (
                <span className={`text-xs ${group.next_warn_will_suspend ? 'text-red-500 dark:text-red-400' : 'text-gray-400'}`}>
                  {group.next_warn_will_suspend
                    ? 'Next warn auto-suspends'
                    : `${group.warnings_until_suspension} warn(s) left before auto-suspension`}
                </span>
              )}
              <span className="text-xs text-gray-400">Latest: {group.latest_reason}</span>
            </div>

            {/* Pattern flags */}
            {group.pattern_flags.length > 0 && (
              <div className="flex flex-wrap gap-1 mt-2">
                {group.pattern_flags.map((f) => (
                  <PatternFlagBadge key={f} flag={f} />
                ))}
              </div>
            )}
          </div>

          {/* Right: actions */}
          <div className="flex items-center gap-2 flex-shrink-0">
            {hasOpenReports && (
              <button
                onClick={() => onAction(group)}
                className="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 transition-colors shadow"
              >
                Take Action
              </button>
            )}
            <button
              onClick={() => {
                setExpanded(!expanded);
                if (!expanded && !detail) onLoadReports(group);
              }}
              className="p-2 rounded-xl border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-500 transition-colors"
              title="View all reports"
            >
              {expanded ? (
                <ChevronUpIcon className="w-5 h-5" />
              ) : (
                <ChevronDownIcon className="w-5 h-5" />
              )}
            </button>
          </div>
        </div>
      </div>

      {/* Expanded reports */}
      {expanded && (
        <div className="border-t border-gray-100 dark:border-gray-700 p-5 space-y-3">
          <p className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
            Reports on this page ({reports.length})
          </p>
          {loadingDetail && <p className="text-sm text-gray-500">Loading reports…</p>}
          {!loadingDetail && detailError && (
            <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300">
              {detailError}
            </p>
          )}
          {!loadingDetail && !detailError && reports.length === 0 && <p className="text-sm text-gray-500">No report rows are available.</p>}
          {reports.map((r) => (
            <ReportRow key={r.id} report={r} />
          ))}
        </div>
      )}
    </div>
  );
};

// ── Main Page ──────────────────────────────────────────────────────────────────
export default function ShopReports() {
  const { shopGroups, stats, filters, success, error: errorMsg } = usePage<PageProps>().props;
  const serverPage = isShopGroupPage(shopGroups) ? shopGroups : null;
  const serverPaginated = serverPage !== null;
  const rows: ShopGroup[] = Array.isArray(shopGroups) ? shopGroups : shopGroups.data;
  const [search, setSearch] = useState(filters?.search ?? '');
  const [priorityFilter, setPriorityFilter] = useState<string>(filters?.priority ?? 'all');
  const [statusFilter, setStatusFilter] = useState<string>(filters?.status ?? 'all');
  const [actionTarget, setActionTarget] = useState<ShopGroup | null>(null);
  const [details, setDetails] = useState<Record<number, ReportDetail>>({});
  const [loadingDetail, setLoadingDetail] = useState<number | null>(null);
  const [detailErrors, setDetailErrors] = useState<Record<number, string>>({});
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const skipInitialSearch = useRef(true);

  const requestPage = (page: number, overrides: { priority?: string; status?: string } = {}) => {
    router.get('/admin/shop-reports', {
      search: search || undefined,
      priority: overrides.priority ?? priorityFilter,
      status: overrides.status ?? statusFilter,
      page,
    }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  useEffect(() => {
    if (!serverPaginated) return;
    if (skipInitialSearch.current) {
      skipInitialSearch.current = false;
      return;
    }

    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => requestPage(1), 250);

    return () => {
      if (searchTimer.current) clearTimeout(searchTimer.current);
    };
  }, [search]);

  const loadReports = async (group: ShopGroup): Promise<ReportDetail | null> => {
    if (details[group.shop_owner_id]) return details[group.shop_owner_id];

    setLoadingDetail(group.shop_owner_id);
    setDetailErrors((current) => {
      const next = { ...current };
      delete next[group.shop_owner_id];
      return next;
    });
    try {
      const response = await axios.get(`/admin/shop-reports/${group.shop_owner_id}`, {
        params: { per_page: 100 },
      });
      const detail = response.data as ReportDetail;
      setDetails((current) => ({ ...current, [group.shop_owner_id]: detail }));
      return detail;
    } catch {
      setDetailErrors((current) => ({
        ...current,
        [group.shop_owner_id]: 'The report details could not be loaded. Try again.',
      }));
      return null;
    } finally {
      setLoadingDetail(null);
    }
  };

  const handleAction = async (group: ShopGroup) => {
    if (group.open_report_ids?.length) {
      setActionTarget(group);
      return;
    }

    const detail = await loadReports(group);
    if (detail) {
      setActionTarget({
        ...group,
        open_report_ids: detail.open_report_ids,
        reports: detail.reports.data,
      });
    }
  };

  // Filters
  const filtered = serverPaginated ? rows : rows.filter((g) => {
    const matchSearch =
      !search ||
      g.business_name.toLowerCase().includes(search.toLowerCase()) ||
      g.shop_email.toLowerCase().includes(search.toLowerCase());
    const matchPriority = priorityFilter === 'all' || g.priority === priorityFilter;
    return matchSearch && matchPriority;
  });

  return (
    <AppLayout>
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 p-6">
        {/* ── Header ── */}
        <div className="mb-8">
          <div className="flex items-center gap-3 mb-1">
            <div className="p-2 rounded-xl bg-red-100 dark:bg-red-900/30">
              <FlagIcon className="w-6 h-6 text-red-600 dark:text-red-400" />
            </div>
            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Shop Reports</h1>
          </div>
          <p className="text-sm text-gray-500 dark:text-gray-400 ml-11">
            Review customer-submitted reports. Dismiss, warn, or suspend shops based on your investigation.
          </p>
        </div>

        {/* ── Flash messages ── */}
        {success && (
          <div className="mb-6 flex items-center gap-3 px-4 py-3 rounded-xl bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-400">
            <ShieldIcon className="w-5 h-5 flex-shrink-0" />
            <span className="text-sm font-medium">{success}</span>
          </div>
        )}
        {errorMsg && (
          <div className="mb-6 flex items-center gap-3 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-400">
            <AlertIcon className="w-5 h-5 flex-shrink-0" />
            <span className="text-sm font-medium">{errorMsg}</span>
          </div>
        )}

        {/* ── Stats ── */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
          <StatCard title="Total Reports" value={stats.total_reports} icon={FlagIcon} />
          <StatCard title="Pending Review" value={stats.pending_review} icon={AlertIcon} />
          <StatCard title="High Priority Shops" value={stats.high_priority} icon={AlertIcon} />
          <StatCard title="Resolved" value={stats.resolved} icon={ShieldIcon} />
        </div>

        {/* ── Filters ── */}
        <div className="flex flex-wrap gap-3 mb-6">
          <div className="relative flex-1 min-w-[200px]">
            <SearchIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <input
              type="text"
              placeholder="Search by shop name or email…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-9 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
            />
          </div>
          <select
            value={priorityFilter}
            onChange={(e) => {
              const nextPriority = e.target.value;
              setPriorityFilter(nextPriority);
              if (serverPaginated) requestPage(1, { priority: nextPriority });
            }}
            className="px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
          >
            <option value="all">All Priorities</option>
            <option value="high">🔴 High Priority (5+ reports)</option>
            <option value="medium">🟡 Needs Review (3–4 reports)</option>
            <option value="normal">⚪ Normal</option>
          </select>
          <select
            value={statusFilter}
            aria-label="Filter reports by status"
            onChange={(e) => {
              const nextStatus = e.target.value;
              setStatusFilter(nextStatus);
              if (serverPaginated) requestPage(1, { status: nextStatus });
            }}
            className="px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
          >
            <option value="all">All statuses</option>
            <option value="open">Open</option>
            <option value="resolved">Resolved</option>
          </select>
        </div>

        {/* ── Results count ── */}
        <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
          Showing <span className="font-semibold text-gray-700 dark:text-gray-200">{serverPage?.from ?? (filtered.length ? 1 : 0)}-{serverPage?.to ?? filtered.length}</span> of{' '}
          <span className="font-semibold text-gray-700 dark:text-gray-200">{serverPage?.total ?? filtered.length}</span> shop(s)
          {priorityFilter !== 'all' && ' matching filter'}
        </p>

        {/* ── Shop group list ── */}
        {filtered.length === 0 ? (
          <div className="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-12 text-center">
            <ShieldIcon className="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" />
            <p className="text-gray-500 dark:text-gray-400 font-medium">No reports found</p>
            <p className="text-sm text-gray-400 dark:text-gray-500 mt-1">
              {search ? 'Try a different search term.' : 'No shops have been reported yet.'}
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {filtered.map((group) => (
              <ShopGroupRow
                key={group.shop_owner_id}
                group={group}
                onAction={(target) => void handleAction(target)}
                onLoadReports={(target) => void loadReports(target)}
                detail={details[group.shop_owner_id]}
                loadingDetail={loadingDetail === group.shop_owner_id}
                detailError={detailErrors[group.shop_owner_id]}
              />
            ))}
          </div>
        )}
        {serverPage && serverPage.last_page > 1 && (
          <div className="mt-6 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
            <button
              type="button"
              title="Previous page"
              aria-label="Previous page"
              disabled={serverPage.current_page <= 1}
              onClick={() => requestPage(Math.max(1, serverPage.current_page - 1))}
              className="rounded-lg border px-3 py-2 text-sm disabled:opacity-50"
            >
              Previous
            </button>
            <span className="text-sm text-gray-500">Page {serverPage.current_page} of {serverPage.last_page}</span>
            <button
              type="button"
              title="Next page"
              aria-label="Next page"
              disabled={serverPage.current_page >= serverPage.last_page}
              onClick={() => requestPage(Math.min(serverPage.last_page, serverPage.current_page + 1))}
              className="rounded-lg border px-3 py-2 text-sm disabled:opacity-50"
            >
              Next page
            </button>
          </div>
        )}
      </div>

      {/* ── Action Modal ── */}
      {actionTarget && (
        <ActionModal
          shopGroup={actionTarget}
          onClose={() => setActionTarget(null)}
        />
      )}
    </AppLayout>
  );
}
