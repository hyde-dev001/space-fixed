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

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | UiStatus>('all');
  const [sortBy, setSortBy] = useState<SortValue>('latest');
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
      return matchesSearch && matchesStatus;
    });

    return filtered.sort((a, b) => {
      if (sortBy === 'latest') return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      if (sortBy === 'oldest') return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
      if (sortBy === 'amount_high') return b.amount_paid - a.amount_paid;
      return a.amount_paid - b.amount_paid;
    });
  }, [subscriptions, search, statusFilter, sortBy]);

  const withSubmitState = (subscriptionId: number, callback: () => void) => {
    setIsSubmitting(subscriptionId);
    callback();
  };

  const deactivateSubscription = (subscription: SubscriptionItem) => {
    Swal.fire({
      title: 'Deactivate subscription?',
      text: `This will deactivate ${subscription.shop.business_name}'s subscription renewal.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, deactivate',
      confirmButtonColor: '#dc2626',
    }).then((result) => {
      if (!result.isConfirmed) return;

      withSubmitState(subscription.id, () => {
        router.post(
          `/admin/subscriptions/${subscription.id}/cancel`,
          {},
          {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(null),
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
            <div className="grid gap-3 md:grid-cols-3">
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
          </div>

          <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="overflow-x-auto">
              <table className="min-w-full">
                <thead className="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-700/40">
                  <tr>
                    <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Shop Details</th>
                    <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Plan</th>
                    <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Amount</th>
                    <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Started</th>
                    <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Expires</th>
                    <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Status</th>
                    <th className="px-5 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.length === 0 && (
                    <tr>
                      <td colSpan={7} className="px-5 py-14 text-center">
                        <p className="text-sm font-medium text-gray-700 dark:text-gray-300">No subscriptions found</p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Try changing filters or searching by another keyword.</p>
                      </td>
                    </tr>
                  )}

                  {rows.map((subscription) => (
                    <tr
                      key={subscription.id}
                      onClick={() => setSelected(subscription)}
                      className="cursor-pointer border-b border-gray-100 transition hover:bg-blue-50/50 dark:border-gray-700 dark:hover:bg-gray-700/30"
                    >
                      <td className="px-5 py-4">
                        <p className="font-semibold text-gray-900 dark:text-white">{subscription.shop.business_name}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{subscription.shop.owner_name}</p>
                        <p className="text-xs text-gray-400 dark:text-gray-500">{subscription.shop.email}</p>
                      </td>
                      <td className="px-5 py-4 text-sm text-gray-800 dark:text-gray-200">
                        <p>{subscription.premium_plan?.name ?? 'No plan linked'}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">Code: {subscription.plan_code}</p>
                      </td>
                      <td className="px-5 py-4 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                        {formatMoney(subscription.amount_paid)}
                      </td>
                      <td className="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">{formatDate(subscription.starts_at)}</td>
                      <td className="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">{formatDate(subscription.ends_at)}</td>
                      <td className="px-5 py-4">
                        <StatusBadge uiStatus={resolveUiStatus(subscription.status, subscription.ends_at)} />
                      </td>
                      <td className="px-5 py-4 text-center">
                        <button
                          type="button"
                          onClick={(event) => {
                            event.stopPropagation();
                            setSelected(subscription);
                          }}
                          className="flex items-center justify-center text-blue-600 hover:text-blue-700 dark:text-blue-400"
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
          </div>
        </div>

        {selected && (
          <ModalPortal>
          <div
            className="fixed inset-0 z-999999 flex items-center justify-center bg-black/45 p-4 backdrop-blur-[1px]"
            onClick={() => setSelected(null)}
          >
            <div
              className="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800"
              onClick={(event) => event.stopPropagation()}
            >
              <div className="mb-5 flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                <div>
                  <h2 className="text-lg font-bold text-gray-900 dark:text-white">Subscription Details</h2>
                  <p className="text-sm text-gray-500 dark:text-gray-400">{selected.shop.business_name}</p>
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

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Owner</p>
                  <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{selected.shop.owner_name}</p>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{selected.shop.email}</p>
                </div>

                <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Plan</p>
                  <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    {selected.premium_plan?.name ?? 'No linked plan'}
                  </p>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Code: {selected.plan_code}</p>
                </div>

                <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Amount Paid</p>
                  <p className="mt-1 text-sm font-semibold text-emerald-600 dark:text-emerald-400">{formatMoney(selected.amount_paid)}</p>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Showroom slots: {selected.showroom_slot_limit}</p>
                </div>

                <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                  <p className="text-xs uppercase tracking-wide text-gray-400">Subscription Period</p>
                  <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">Start: {formatDate(selected.starts_at)}</p>
                  <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">Expires: {formatDate(selected.ends_at)}</p>
                </div>
              </div>

              <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
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
