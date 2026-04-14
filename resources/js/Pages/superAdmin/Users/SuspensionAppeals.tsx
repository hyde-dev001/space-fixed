import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';

interface AppealItem {
  id: number;
  account_type: 'shop_owner' | 'customer';
  account_id: number;
  account_name: string | null;
  recipient_email: string;
  suspension_reason: string | null;
  status: 'eligible' | 'submitted' | 'approved' | 'rejected' | 'expired';
  appeal_message: string | null;
  reviewer_notes: string | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  expires_at: string | null;
  created_at: string | null;
}

interface Stats {
  total: number;
  eligible: number;
  submitted: number;
  approved: number;
  rejected: number;
}

interface Props {
  appeals: AppealItem[];
  stats: Stats;
}

const SearchIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-5.2-5.2m2.2-5.3a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z" />
  </svg>
);

const InboxIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 12l2-7h12l2 7m-16 0v6h16v-6m-16 0h3l2 3h6l2-3h3" />
  </svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const CheckIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
  </svg>
);

const XIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

const ShieldIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 3l7 3v6c0 4.5-2.7 7.8-7 9-4.3-1.2-7-4.5-7-9V6l7-3z" />
  </svg>
);

type MetricTone = 'blue' | 'slate' | 'amber' | 'green' | 'rose';

const metricToneClasses: Record<MetricTone, { gradient: string; chip: string }> = {
  blue: {
    gradient: 'from-blue-500 to-indigo-600',
    chip: 'bg-blue-100 text-blue-700',
  },
  slate: {
    gradient: 'from-slate-500 to-gray-600',
    chip: 'bg-slate-100 text-slate-700',
  },
  amber: {
    gradient: 'from-amber-500 to-orange-600',
    chip: 'bg-amber-100 text-amber-700',
  },
  green: {
    gradient: 'from-green-500 to-emerald-600',
    chip: 'bg-green-100 text-green-700',
  },
  rose: {
    gradient: 'from-rose-500 to-red-600',
    chip: 'bg-rose-100 text-rose-700',
  },
};

const MetricCard = ({
  title,
  value,
  subtitle,
  chip,
  tone,
  icon: Icon,
}: {
  title: string;
  value: number;
  subtitle: string;
  chip: string;
  tone: MetricTone;
  icon: React.ComponentType<{ className?: string }>;
}) => {
  const toneConfig = metricToneClasses[tone];

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl">
      <div className={`absolute inset-0 bg-linear-to-br ${toneConfig.gradient} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="mb-4 flex items-center justify-between">
          <div className={`flex h-11 w-11 items-center justify-center rounded-xl bg-linear-to-br ${toneConfig.gradient} shadow-lg`}>
            <Icon className="h-5 w-5 text-white" />
          </div>
          <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${toneConfig.chip}`}>
            {chip}
          </span>
        </div>

        <p className="text-sm font-medium text-gray-600">{title}</p>
        <h3 className="mt-1 text-3xl font-bold text-gray-900">{value.toLocaleString()}</h3>
        <p className="mt-1 text-xs text-gray-500">{subtitle}</p>
      </div>
    </div>
  );
};

const statusBadgeClasses: Record<AppealItem['status'], string> = {
  submitted: 'bg-amber-100 text-amber-700 border border-amber-200',
  approved: 'bg-green-100 text-green-700 border border-green-200',
  rejected: 'bg-rose-100 text-rose-700 border border-rose-200',
  expired: 'bg-slate-200 text-slate-700 border border-slate-300',
  eligible: 'bg-blue-100 text-blue-700 border border-blue-200',
};

const statusLabel = (status: AppealItem['status']) => status.charAt(0).toUpperCase() + status.slice(1);

export default function SuspensionAppeals({ appeals = [], stats }: Props) {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | AppealItem['status']>('all');

  const safeStats: Stats = {
    total: stats?.total || 0,
    eligible: stats?.eligible || 0,
    submitted: stats?.submitted || 0,
    approved: stats?.approved || 0,
    rejected: stats?.rejected || 0,
  };

  const ratioText = (value: number) => {
    if (!safeStats.total) return '0% of total';
    return `${Math.round((value / safeStats.total) * 100)}% of total`;
  };

  const filtered = useMemo(() => {
    return appeals.filter((item) => {
      const statusOk = statusFilter === 'all' || item.status === statusFilter;
      const keyword = searchTerm.trim().toLowerCase();
      const searchOk =
        keyword === '' ||
        (item.account_name || '').toLowerCase().includes(keyword) ||
        item.recipient_email.toLowerCase().includes(keyword) ||
        item.account_type.toLowerCase().includes(keyword);
      return statusOk && searchOk;
    });
  }, [appeals, searchTerm, statusFilter]);

  const handleDecision = async (id: number, action: 'approve' | 'reject') => {
    const notesPrompt = await Swal.fire({
      title: action === 'approve' ? 'Approve appeal?' : 'Reject appeal?',
      text: action === 'approve' ? 'This restores account access.' : 'This keeps the account suspended.',
      input: 'textarea',
      inputPlaceholder: 'Reviewer notes (optional)',
      icon: action === 'approve' ? 'question' : 'warning',
      showCancelButton: true,
      confirmButtonColor: action === 'approve' ? '#10b981' : '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: action === 'approve' ? 'Approve' : 'Reject',
    });

    if (!notesPrompt.isConfirmed) return;

    router.post(`/admin/appeals/${id}/${action}`, {
      reviewer_notes: notesPrompt.value || null,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        Swal.fire({
          icon: 'success',
          title: action === 'approve' ? 'Appeal approved' : 'Appeal rejected',
          timer: 1400,
          showConfirmButton: false,
        });
      },
      onError: () => {
        Swal.fire({
          icon: 'error',
          title: 'Action failed',
          text: 'Please try again.',
        });
      },
    });
  };

  return (
    <AppLayout>
      <Head title="Suspension Appeals" />
      <div className="space-y-8 p-6 md:p-8">
        <div className="flex items-start gap-3">
          <div className="mt-1 flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-blue-600">
            <ShieldIcon className="h-6 w-6" />
          </div>
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Suspension Appeals</h1>
            <p className="mt-1 text-gray-600">Review and resolve appeals submitted by suspended shop owner and customer accounts.</p>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-5">
          <MetricCard
            title="Total Appeals"
            value={safeStats.total}
            subtitle="All records in the appeals queue"
            chip={`${filtered.length} visible`}
            tone="blue"
            icon={InboxIcon}
          />
          <MetricCard
            title="Eligible"
            value={safeStats.eligible}
            subtitle="Accounts that can still submit"
            chip={ratioText(safeStats.eligible)}
            tone="slate"
            icon={ClockIcon}
          />
          <MetricCard
            title="Submitted"
            value={safeStats.submitted}
            subtitle="Appeals waiting for action"
            chip={ratioText(safeStats.submitted)}
            tone="amber"
            icon={ClockIcon}
          />
          <MetricCard
            title="Approved"
            value={safeStats.approved}
            subtitle="Access restored successfully"
            chip={ratioText(safeStats.approved)}
            tone="green"
            icon={CheckIcon}
          />
          <MetricCard
            title="Rejected"
            value={safeStats.rejected}
            subtitle="Appeals closed without restore"
            chip={ratioText(safeStats.rejected)}
            tone="rose"
            icon={XIcon}
          />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-600">Search and filters</h2>
            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
              {filtered.length} result{filtered.length === 1 ? '' : 's'}
            </span>
          </div>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div className="relative md:col-span-2">
              <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <input
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                placeholder="Search account name, email, or account type"
                className="w-full rounded-xl border border-gray-300 py-2.5 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
              />
            </div>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as 'all' | AppealItem['status'])}
              aria-label="Filter appeals by status"
              className="rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            >
              <option value="all">All statuses</option>
              <option value="eligible">Eligible</option>
              <option value="submitted">Submitted</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="expired">Expired</option>
            </select>
          </div>
        </div>

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3">
            <p className="text-sm font-semibold text-gray-700">Appeal records</p>
            <p className="text-xs text-gray-500">Showing {filtered.length} of {appeals.length}</p>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left font-semibold text-gray-600">Account</th>
                  <th className="px-4 py-3 text-left font-semibold text-gray-600">Type</th>
                  <th className="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                  <th className="px-4 py-3 text-left font-semibold text-gray-600">Reason</th>
                  <th className="px-4 py-3 text-left font-semibold text-gray-600">Appeal Message</th>
                  <th className="px-4 py-3 text-left font-semibold text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {filtered.length === 0 && (
                  <tr>
                    <td className="px-4 py-10 text-center text-gray-500" colSpan={6}>
                      No appeals found.
                    </td>
                  </tr>
                )}

                {filtered.map((item) => (
                  <tr key={item.id} className="transition-colors hover:bg-gray-50/70">
                    <td className="px-4 py-3">
                      <p className="font-semibold text-gray-900">{item.account_name || 'Unknown'}</p>
                      <p className="text-xs text-gray-500">{item.recipient_email}</p>
                    </td>
                    <td className="px-4 py-3 capitalize text-gray-700">{item.account_type.replace('_', ' ')}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses[item.status]}`}>
                        {statusLabel(item.status)}
                      </span>
                    </td>
                    <td className="max-w-xs px-4 py-3 text-gray-700">{item.suspension_reason || '-'}</td>
                    <td className="max-w-sm px-4 py-3 text-gray-700">{item.appeal_message || '-'}</td>
                    <td className="px-4 py-3">
                      {item.status === 'submitted' ? (
                        <div className="flex gap-2">
                          <button
                            onClick={() => handleDecision(item.id, 'approve')}
                            className="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700"
                          >
                            Approve
                          </button>
                          <button
                            onClick={() => handleDecision(item.id, 'reject')}
                            className="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                          >
                            Reject
                          </button>
                        </div>
                      ) : (
                        <span className="text-xs text-gray-400">No action</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
