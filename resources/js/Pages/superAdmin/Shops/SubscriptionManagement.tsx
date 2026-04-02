import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { router, usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';
import Button from '../../../components/ui/button/Button';

const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === 'undefined') return null;
  return createPortal(children, document.body);
};

interface SubscriptionItem {
  id: number;
  shop: {
    id: number;
    business_name: string;
    owner_name: string;
    email: string;
  };
  premium_plan: {
    id: number;
    name: string;
    price: number;
    duration_days: number;
  } | null;
  plan_code: string;
  showroom_slot_limit: number;
  status: string;
  amount_paid: number;
  starts_at: string | null;
  ends_at: string | null;
  next_billing_at: string | null;
  cancellation_reason: string | null;
  cancellation_notes: string | null;
  payment_method?: string | null;
  replaces_subscription_id?: number | null;
  previous_plan_name?: string | null;
  created_at: string;
}

interface SubscriptionStats {
  active: number;
  expired: number;
  total_revenue: number;
  expiring_soon: number;
}

interface PageProps extends Record<string, unknown> {
  subscriptions: SubscriptionItem[];
  stats: SubscriptionStats;
  success?: string;
  error?: string;
}

type SortValue = 'latest' | 'oldest' | 'amount_high' | 'amount_low';
type UiStatus = 'ongoing' | 'end' | 'deactivated';
type ChangeTypeFilter = 'all' | 'upgraded' | 'regular';

const StoreIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
  </svg>
);

const CreditCardIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h.01M11 15h.01M15 15h.01M4 8a2 2 0 00-2 2v8a2 2 0 002 2h16a2 2 0 002-2v-8a2 2 0 00-2-2H4z" />
  </svg>
);

const CalendarIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

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

const EyeIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const XIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

const formatDate = (value: string | null) => {
  if (!value) return 'N/A';
  return new Date(value).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

const formatMoney = (value: number) => {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(value || 0);
};

const resolveUiStatus = (status: string, endsAt: string | null): UiStatus => {
  if (status === 'deactivated') {
    return 'deactivated';
  }

  if (endsAt) {
    const endDate = new Date(endsAt);
    if (!Number.isNaN(endDate.getTime())) {
      return endDate.getTime() >= Date.now() ? 'ongoing' : 'end';
    }
  }

  return status === 'active' ? 'ongoing' : 'end';
};

const StatusBadge = ({ uiStatus }: { uiStatus: UiStatus }) => {
  const styles: Record<string, string> = {
    ongoing: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    end: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    deactivated: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
  };

  return (
    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${styles[uiStatus]}`}>
      {uiStatus === 'ongoing' ? 'Ongoing' : uiStatus === 'end' ? 'End' : 'Deactivated'}
    </span>
  );
};

const isUpgradeRecord = (item: SubscriptionItem) => {
  const paymentMethod = String(item.payment_method || '').toLowerCase();
  if (paymentMethod === 'proration_credit') return true;

  if (item.replaces_subscription_id) return true;

  const planPrice = Number(item.premium_plan?.price || 0);
  const paidAmount = Number(item.amount_paid || 0);

  // Fallback for older records: prorated upgrades usually pay less than full target plan price.
  return planPrice > 0 && paidAmount > 0 && paidAmount + 0.01 < planPrice;
};

const SubscriptionTypeBadge = ({ item }: { item: SubscriptionItem }) => {
  const upgraded = isUpgradeRecord(item);

  return upgraded ? (
    <span className="inline-flex rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/25 dark:text-blue-300">
      Upgraded
    </span>
  ) : (
    <span className="inline-flex rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
      Regular
    </span>
  );
};

const MetricCard = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description,
}: {
  title: string;
  value: string | number;
  change: number;
  changeType: 'increase' | 'decrease';
  icon: React.ComponentType<{ className?: string }>;
  color: 'success' | 'error' | 'warning' | 'info';
  description: string;
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
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="mb-4 flex items-center justify-between">
          <div className={`flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br ${getColorClasses()} shadow-lg transition-all duration-300 group-hover:rotate-6 group-hover:scale-110`}>
            <Icon className="size-7 text-white drop-shadow-sm" />
          </div>

          <div className={`flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold transition-all duration-300 ${
            changeType === 'increase'
              ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
              : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
          }`}>
            {changeType === 'increase' ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
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

export default function SubscriptionManagement() {
  const { subscriptions, stats, success, error: errorMessage } = usePage<PageProps>().props;
  const itemsPerPage = 6;

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | UiStatus>('all');
  const [changeTypeFilter, setChangeTypeFilter] = useState<ChangeTypeFilter>('all');
  const [sortBy, setSortBy] = useState<SortValue>('latest');
  const [currentPage, setCurrentPage] = useState(1);
  const [isSubmitting, setIsSubmitting] = useState<number | null>(null);
  const [selected, setSelected] = useState<SubscriptionItem | null>(null);

  useEffect(() => {
    if (!selected) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setSelected(null);
      }
    };

    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.style.overflow = '';
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [selected]);

  const metrics = [
    {
      title: 'Active Subscriptions',
      value: stats.active.toLocaleString(),
      change: 12,
      changeType: 'increase' as const,
      icon: StoreIcon,
      color: 'success' as const,
      description: 'Currently active plans',
    },
    {
      title: 'Total Revenue',
      value: formatMoney(stats.total_revenue),
      change: 18,
      changeType: 'increase' as const,
      icon: CreditCardIcon,
      color: 'info' as const,
      description: 'From premium subscriptions',
    },
    {
      title: 'Expiring Soon',
      value: stats.expiring_soon.toLocaleString(),
      change: -5,
      changeType: 'decrease' as const,
      icon: AlertIcon,
      color: 'warning' as const,
      description: 'In the next 7 days',
    },
    {
      title: 'Expired',
      value: stats.expired.toLocaleString(),
      change: 3,
      changeType: 'increase' as const,
      icon: CalendarIcon,
      color: 'error' as const,
      description: 'Subscriptions ended',
    },
  ];

  const rows = useMemo(() => {
    const keyword = search.trim().toLowerCase();
    const filtered = subscriptions.filter((item) => {
      const uiStatus = resolveUiStatus(item.status, item.ends_at);
      const matchesSearch =
        !keyword ||
        item.shop.business_name?.toLowerCase().includes(keyword) ||
        item.shop.owner_name?.toLowerCase().includes(keyword) ||
        item.shop.email?.toLowerCase().includes(keyword) ||
        item.plan_code?.toLowerCase().includes(keyword);
      const matchesStatus = statusFilter === 'all' || uiStatus === statusFilter;
      const upgraded = isUpgradeRecord(item);
      const matchesChangeType =
        changeTypeFilter === 'all' ||
        (changeTypeFilter === 'upgraded' && upgraded) ||
        (changeTypeFilter === 'regular' && !upgraded);

      return matchesSearch && matchesStatus && matchesChangeType;
    });

    return filtered.sort((a, b) => {
      if (sortBy === 'latest') return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      if (sortBy === 'oldest') return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
      if (sortBy === 'amount_high') return b.amount_paid - a.amount_paid;
      return a.amount_paid - b.amount_paid;
    });
  }, [subscriptions, search, statusFilter, changeTypeFilter, sortBy]);

  const totalPages = Math.max(1, Math.ceil(rows.length / itemsPerPage));
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedRows = rows.slice(startIndex, endIndex);

  useEffect(() => {
    setCurrentPage(1);
  }, [search, statusFilter, changeTypeFilter, sortBy]);

  const upgradedCount = useMemo(() => subscriptions.filter(isUpgradeRecord).length, [subscriptions]);
  const subscriptionById = useMemo(() => {
    return subscriptions.reduce<Record<number, SubscriptionItem>>((acc, item) => {
      acc[item.id] = item;
      return acc;
    }, {});
  }, [subscriptions]);
  const selectedPreviousSubscription = useMemo(() => {
    if (!selected?.replaces_subscription_id) return null;
    return subscriptionById[selected.replaces_subscription_id] || null;
  }, [selected, subscriptionById]);

  useEffect(() => {
    setCurrentPage((prev) => Math.min(prev, totalPages));
  }, [totalPages]);

  const withSubmitState = (subscriptionId: number, callback: () => void) => {
    setIsSubmitting(subscriptionId);
    callback();
  };

  const deactivateSubscription = (subscription: SubscriptionItem) => {
    Swal.fire({
      title: 'Deactivate subscription?',
      text: `This will deactivate ${subscription.shop.business_name}'s premium subscription renewal.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, deactivate',
      confirmButtonColor: '#dc2626',
    }).then((result) => {
      if (!result.isConfirmed) return;

      withSubmitState(subscription.id, () => {
        router.post(
          `/admin/subscriptions/${subscription.id}/cancel`,
          {
            cancellation_reason: 'admin_deactivated',
            cancellation_notes: null,
          },
          {
            preserveScroll: true,
            onFinish: () => {
              setIsSubmitting(null);
              setSelected(null);
            },
          }
        );
      });
    });
  };

  return (
    <AppLayout>
      <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
        <div className="mx-auto max-w-7xl space-y-6">
          <div>
            <h1 className="text-4xl font-bold tracking-tight text-gray-900 dark:text-white">Subscription Management</h1>
            <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
              Monitor premium benefits and active shop subscriptions
            </p>
          </div>

          {(success || errorMessage) && (
            <div
              className={`rounded-xl border px-4 py-3 text-sm ${
                success
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-900/20 dark:text-emerald-400'
                  : 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/60 dark:bg-rose-900/20 dark:text-rose-400'
              }`}
            >
              {success || errorMessage}
            </div>
          )}

          <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            {metrics.map((metric) => (
              <MetricCard key={metric.title} {...metric} />
            ))}
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="grid gap-3 md:grid-cols-4">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search shop name or owner..."
                aria-label="Search subscriptions"
                className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              />

              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value as 'all' | UiStatus)}
                aria-label="Filter subscriptions by status"
                className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              >
                <option value="all">All Status</option>
                <option value="ongoing">Ongoing</option>
                <option value="end">End</option>
                <option value="deactivated">Deactivated</option>
              </select>

              <select
                value={changeTypeFilter}
                onChange={(e) => setChangeTypeFilter(e.target.value as ChangeTypeFilter)}
                aria-label="Filter subscriptions by change type"
                className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              >
                <option value="all">All Types</option>
                <option value="upgraded">Upgraded Only</option>
                <option value="regular">Regular Only</option>
              </select>

              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as SortValue)}
                aria-label="Sort subscriptions"
                className="w-full rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
              >
                <option value="latest">Latest First</option>
                <option value="oldest">Oldest First</option>
                <option value="amount_high">Amount High to Low</option>
                <option value="amount_low">Amount Low to High</option>
              </select>
            </div>

            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
              Showing {rows.length} of {subscriptions.length} subscriptions
            </p>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Upgrade records: {upgradedCount} total. "Upgraded" means the plan was changed mid-cycle and charged with prorated amount.
            </p>
          </div>

          <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="overflow-x-auto">
              <table className="w-full min-w-245">
                <thead className="bg-gray-50 dark:bg-gray-900/50">
                  <tr>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Shop Details</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Plan</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Amount</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Type</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Started</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Next Billing</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Expires</th>
                    <th className="px-6 py-4 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                    <th className="px-6 py-4 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                  {rows.length === 0 && (
                    <tr>
                      <td colSpan={9} className="px-6 py-14 text-center">
                        <p className="text-sm font-medium text-gray-700 dark:text-gray-300">No subscriptions found</p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Try changing filters or searching by another keyword.</p>
                      </td>
                    </tr>
                  )}

                  {paginatedRows.map((subscription) => (
                    <tr
                      key={subscription.id}
                      onClick={() => setSelected(subscription)}
                      className="cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    >
                      <td className="px-6 py-4">
                        <p className="font-semibold text-gray-900 dark:text-white">{subscription.shop.business_name}</p>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-800 dark:text-gray-200">
                        <p>{subscription.premium_plan?.name ?? 'No plan linked'}</p>
                      </td>
                      <td className="px-6 py-4 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                        {formatMoney(subscription.amount_paid)}
                      </td>
                      <td className="px-6 py-4">
                        <SubscriptionTypeBadge item={subscription} />
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{formatDate(subscription.starts_at)}</td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{formatDate(subscription.next_billing_at)}</td>
                      <td className="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{formatDate(subscription.ends_at)}</td>
                      <td className="px-6 py-4">
                        <StatusBadge uiStatus={resolveUiStatus(subscription.status, subscription.ends_at)} />
                      </td>
                      <td className="px-6 py-4 text-center">
                        <button
                          type="button"
                          onClick={(event) => {
                            event.stopPropagation();
                            setSelected(subscription);
                          }}
                          className="inline-flex h-8 w-8 items-center justify-center rounded-md text-blue-600 transition-colors hover:bg-blue-50 hover:text-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/20"
                          aria-label={`View subscription ${subscription.id}`}
                        >
                          <EyeIcon className="size-5" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {rows.length > 0 && (
              <div className="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                <div className="flex items-center justify-between">
                  <div className="text-sm text-gray-700 dark:text-gray-300">
                    Showing <span className="font-medium">{startIndex + 1}</span> to <span className="font-medium">{Math.min(endIndex, rows.length)}</span> of <span className="font-medium">{rows.length}</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                      disabled={currentPage === 1}
                      className="rounded-lg border border-gray-300 bg-white p-2 text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                      title="Previous page"
                    >
                      <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                      </svg>
                    </button>

                    {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => (
                      <button
                        key={page}
                        onClick={() => setCurrentPage(page)}
                        className={`h-10 min-w-10 rounded-lg px-3 font-medium transition-colors ${
                          currentPage === page
                            ? 'bg-blue-600 text-white'
                            : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800'
                        }`}
                      >
                        {page}
                      </button>
                    ))}

                    <button
                      onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                      disabled={currentPage === totalPages}
                      className="rounded-lg border border-gray-300 bg-white p-2 text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                      title="Next page"
                    >
                      <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>

        {selected && (
          <ModalPortal>
          <div
            className="fixed inset-0 z-999999 flex items-center justify-center bg-black/45 p-4 backdrop-blur-[1px]"
            onClick={() => setSelected(null)}
          >
            <div
              className="w-full max-w-5xl rounded-3xl border border-gray-200 bg-white p-7 shadow-2xl dark:border-gray-700 dark:bg-gray-800 sm:p-8"
              onClick={(event) => event.stopPropagation()}
            >
              <div className="mb-6 flex items-start justify-between border-b border-gray-200 pb-5 dark:border-gray-700">
                <div>
                  <h2 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Subscription Details</h2>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{selected.shop.business_name}</p>
                </div>
                <div className="flex items-center gap-2">
                  <StatusBadge uiStatus={resolveUiStatus(selected.status, selected.ends_at)} />
                  <button
                    type="button"
                    onClick={() => setSelected(null)}
                    className="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-700"
                    aria-label="Close modal"
                  >
                    <XIcon className="size-4" />
                  </button>
                </div>
              </div>

              <div className="grid gap-4 lg:grid-cols-3">
                <div className="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Owner</p>
                  <p className="mt-2 text-base font-semibold text-gray-900 dark:text-white">{selected.shop.owner_name}</p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{selected.shop.email}</p>
                </div>

                <div className="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Plan</p>
                  <p className="mt-2 text-base font-semibold text-gray-900 dark:text-white">
                    {selected.premium_plan?.name ?? 'No linked plan'}
                  </p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Status: {resolveUiStatus(selected.status, selected.ends_at) === 'ongoing' ? 'Active' : 'Not Active'}</p>
                  {isUpgradeRecord(selected) ? (
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                      Previous Plan: <span className="font-medium text-gray-700 dark:text-gray-200">{selected.previous_plan_name || selectedPreviousSubscription?.premium_plan?.name || 'Unavailable'}</span>
                    </p>
                  ) : null}
                </div>

                <div className="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Amount Paid</p>
                  <p className="mt-2 text-base font-semibold text-emerald-600 dark:text-emerald-400">{formatMoney(selected.amount_paid)}</p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Type: {isUpgradeRecord(selected) ? 'Upgraded (prorated)' : 'Regular subscription'}</p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Showroom slots: {selected.showroom_slot_limit}</p>
                </div>

                <div className="rounded-2xl border border-gray-200 p-5 dark:border-gray-700 lg:col-span-2">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Subscription Period</p>
                  <div className="mt-2 grid gap-2 sm:grid-cols-3">
                    <p className="text-sm text-gray-700 dark:text-gray-300">Start: <span className="font-medium">{formatDate(selected.starts_at)}</span></p>
                    <p className="text-sm text-gray-700 dark:text-gray-300">Next Billing: <span className="font-medium">{formatDate(selected.next_billing_at)}</span></p>
                    <p className="text-sm text-gray-700 dark:text-gray-300">Expires: <span className="font-medium">{formatDate(selected.ends_at)}</span></p>
                  </div>
                </div>

                {selected.status === 'cancelled' && (
                  <div className="rounded-2xl border border-gray-200 p-5 dark:border-gray-700">
                    <p className="text-xs uppercase tracking-wide text-gray-400">Cancellation Feedback</p>
                    <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">
                      Reason: <span className="font-medium">{selected.cancellation_reason ? selected.cancellation_reason.replaceAll('_', ' ') : 'No reason submitted'}</span>
                    </p>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                      Notes: {selected.cancellation_notes || 'No additional notes'}
                    </p>
                  </div>
                )}
              </div>

              <div className="mt-6 flex flex-wrap items-center justify-end gap-2">
                <Button variant="outline" size="sm" onClick={() => setSelected(null)}>
                  Close
                </Button>
                <Button
                  size="sm"
                  onClick={() => {
                    if (isSubmitting === selected.id || selected.status === 'deactivated') return;
                    deactivateSubscription(selected);
                  }}
                  className="bg-red-600 text-white hover:bg-red-700"
                >
                  {isSubmitting === selected.id ? 'Processing...' : 'Deactivate'}
                </Button>
              </div>
            </div>
          </div>
          </ModalPortal>
        )}
      </div>
    </AppLayout>
  );
}
