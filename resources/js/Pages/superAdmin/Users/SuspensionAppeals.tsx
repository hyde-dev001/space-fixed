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

const MetricCard = ({ title, value, color }: { title: string; value: number; color: 'blue' | 'orange' | 'green' | 'red' | 'gray' }) => {
  const map = {
    blue: 'from-blue-500 to-indigo-600',
    orange: 'from-orange-500 to-amber-600',
    green: 'from-green-500 to-emerald-600',
    red: 'from-red-500 to-rose-600',
    gray: 'from-gray-500 to-slate-600',
  } as const;

  return (
    <div className="relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg">
      <div className={`absolute inset-0 bg-linear-to-br ${map[color]} opacity-[0.04]`} />
      <div className="relative">
        <p className="text-sm font-medium text-gray-600">{title}</p>
        <h3 className="mt-2 text-3xl font-bold text-gray-900">{value.toLocaleString()}</h3>
      </div>
    </div>
  );
};

export default function SuspensionAppeals({ appeals = [], stats }: Props) {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | AppealItem['status']>('all');

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
        <div>
          <h1 className="text-3xl font-bold text-gray-900">Suspension Appeals</h1>
          <p className="mt-2 text-gray-600">Review submitted appeals from suspended shop and customer accounts.</p>
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-5">
          <MetricCard title="Total" value={stats?.total || 0} color="blue" />
          <MetricCard title="Eligible" value={stats?.eligible || 0} color="gray" />
          <MetricCard title="Submitted" value={stats?.submitted || 0} color="orange" />
          <MetricCard title="Approved" value={stats?.approved || 0} color="green" />
          <MetricCard title="Rejected" value={stats?.rejected || 0} color="red" />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <input
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Search account name or email"
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#16233b] focus:outline-none focus:ring-2 focus:ring-[#16233b]/20 md:col-span-2"
            />
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as 'all' | AppealItem['status'])}
              aria-label="Filter appeals by status"
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#16233b] focus:outline-none focus:ring-2 focus:ring-[#16233b]/20"
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

        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white">
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
                  <tr key={item.id}>
                    <td className="px-4 py-3">
                      <p className="font-semibold text-gray-900">{item.account_name || 'Unknown'}</p>
                      <p className="text-xs text-gray-500">{item.recipient_email}</p>
                    </td>
                    <td className="px-4 py-3 capitalize text-gray-700">{item.account_type.replace('_', ' ')}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${
                        item.status === 'submitted'
                          ? 'bg-amber-100 text-amber-700'
                          : item.status === 'approved'
                            ? 'bg-green-100 text-green-700'
                            : item.status === 'rejected'
                              ? 'bg-red-100 text-red-700'
                              : item.status === 'expired'
                                ? 'bg-gray-200 text-gray-700'
                                : 'bg-blue-100 text-blue-700'
                      }`}>
                        {item.status}
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
