import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';
import Button from '../../../components/ui/button/Button';

const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === 'undefined') return null;
  return createPortal(children, document.body);
};

interface RefundAttempt {
  id: number;
  payment_id?: number;
  local_reference?: string;
  provider_refund_id?: string | null;
  amount: number;
  currency: string;
  business_reason: string;
  provider_reason: string;
  status: string;
  failure_code?: string | null;
  initiated_at?: string | null;
  finalized_at?: string | null;
  reconciled_at?: string | null;
  created_at?: string | null;
}

interface PaymentLedgerRow {
  id: number;
  payment_type: string;
  amount_due: number | null;
  amount_paid: number | null;
  currency: string;
  status: string;
  paid_at: string | null;
  created_at?: string | null;
}

interface Paginator<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}

interface SubscriptionHistory {
  subscription_id: number;
  payments: Paginator<PaymentLedgerRow>;
  refunds: Paginator<RefundAttempt>;
}

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
  refunded_amount?: number;
  net_collected?: number;
  can_cancel?: boolean;
  legacy_correction_available?: boolean;
  eligible_for_refund?: boolean;
  refund_payment_id?: number | null;
  refund_block_reason?: string | null;
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
  gross_collected?: number;
  refunded_amount?: number;
  net_collected?: number;
}

interface PremiumPlanItem {
  id: number;
  plan_code: string;
  name: string;
  description: string | null;
  price: string | number;
  duration_days: number;
  showroom_slot_limit: number;
  benefits: string[] | null;
  status: 'active' | 'inactive';
  active_subscriptions_count: number;
}

type PlanForm = {
  plan_code: string;
  name: string;
  description: string;
  price: string;
  duration_days: number;
  showroom_slot_limit: number;
  benefits: string[];
};

const emptyPlan: PlanForm = { plan_code: '', name: '', description: '', price: '', duration_days: 30, showroom_slot_limit: 48, benefits: [] };

interface PageProps extends Record<string, unknown> {
  subscriptions: Paginator<SubscriptionItem> | SubscriptionItem[];
  stats: SubscriptionStats;
  plans: PremiumPlanItem[];
  filters?: {
    search?: string | null;
    status?: string | null;
    change_type?: string | null;
    sort?: string | null;
  };
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
  icon: Icon,
  color,
  description,
}: {
  title: string;
  value: string | number;
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

          <span className="rounded-full bg-gray-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-700 dark:text-gray-300">Snapshot</span>
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
  const { subscriptions, stats, plans, filters, success, error: errorMessage } = usePage<PageProps>().props;
  const subscriptionPage: Paginator<SubscriptionItem> = Array.isArray(subscriptions)
    ? {
      data: subscriptions,
      current_page: 1,
      last_page: 1,
      per_page: subscriptions.length || 25,
      total: subscriptions.length,
      from: subscriptions.length > 0 ? 1 : null,
      to: subscriptions.length || null,
    }
    : subscriptions;
  const subscriptionRows = subscriptionPage.data;

  const [search, setSearch] = useState(filters?.search ?? '');
  const [statusFilter, setStatusFilter] = useState<'all' | UiStatus>((filters?.status as 'all' | UiStatus) || 'all');
  const [changeTypeFilter, setChangeTypeFilter] = useState<ChangeTypeFilter>((filters?.change_type as ChangeTypeFilter) || 'all');
  const [sortBy, setSortBy] = useState<SortValue>((filters?.sort as SortValue) || 'latest');
  const [selected, setSelected] = useState<SubscriptionItem | null>(null);
  const [history, setHistory] = useState<SubscriptionHistory | null>(null);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [editingPlan, setEditingPlan] = useState<PremiumPlanItem | null>(null);
  const [isPlanModalOpen, setIsPlanModalOpen] = useState(false);
  const [planStatusFilter, setPlanStatusFilter] = useState<'active' | 'inactive'>('active');
  const [mutationPending, setMutationPending] = useState(false);
  const [mutationError, setMutationError] = useState<string | null>(null);
  const [mutationNotice, setMutationNotice] = useState<string | null>(null);
  const [correctionOpen, setCorrectionOpen] = useState(false);
  const [correctionStatus, setCorrectionStatus] = useState<'cancelled' | 'expired'>('expired');
  const [correctionDate, setCorrectionDate] = useState('');
  const [correctionReason, setCorrectionReason] = useState('');
  const planForm = useForm<PlanForm>(emptyPlan);
  const filterInitialized = useRef(false);

  const reloadBilling = () => router.reload({ only: ['subscriptions', 'stats'], preserveScroll: true });

  const errorMessageFor = (error: unknown) => error instanceof Error
    ? error.message
    : 'The billing operation could not be completed.';

  const loadHistory = async (subscriptionId: number, paymentPage = 1, refundPage = 1) => {
    setHistoryLoading(true);
    setHistoryError(null);

    try {
      const response = await axios.get(`/admin/subscriptions/${subscriptionId}/history`, {
        params: { payment_page: paymentPage, refund_page: refundPage, per_page: 25 },
      });
      setHistory(response.data as SubscriptionHistory);
    } catch (error: unknown) {
      setHistory(null);
      setHistoryError(errorMessageFor(error));
    } finally {
      setHistoryLoading(false);
    }
  };

  const openSubscription = (subscription: SubscriptionItem) => {
    setSelected(subscription);
    setHistory(null);
    setHistoryError(null);
    void loadHistory(subscription.id);
  };

  const navigateSubscriptionPage = (page: number) => {
    router.get('/admin/subscriptions', {
      search: search || undefined,
      status: statusFilter === 'all' ? undefined : statusFilter,
      change_type: changeTypeFilter === 'all' ? undefined : changeTypeFilter,
      sort: sortBy,
      page,
      per_page: subscriptionPage.per_page || 25,
    }, { preserveState: true, preserveScroll: true, replace: true });
  };

  useEffect(() => {
    if (!filterInitialized.current) {
      filterInitialized.current = true;
      return;
    }

    const timeout = window.setTimeout(() => navigateSubscriptionPage(1), 300);
    return () => window.clearTimeout(timeout);
  }, [search, statusFilter, changeTypeFilter, sortBy]);

  const cancelSubscription = async () => {
    if (!selected?.can_cancel || mutationPending) return;

    const result = await Swal.fire({
      title: 'Cancel at period end?',
      text: 'Access remains available through the current paid end date. No refund is issued by this action.',
      input: 'select',
      inputOptions: {
        reduce_costs: 'Reduce costs',
        low_value: 'Low value',
        technical_issues: 'Technical issues',
        missing_features: 'Missing features',
        subscribed_by_mistake: 'Subscribed by mistake',
        temporary_pause: 'Temporary pause',
        others: 'Other',
        operator_correction: 'Operator correction',
      },
      inputPlaceholder: 'Choose a reason',
      inputValidator: (value) => value ? undefined : 'Choose a cancellation reason.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Cancel at period end',
    });

    if (!result.isConfirmed || typeof result.value !== 'string' || !result.value) return;

    setMutationPending(true);
    setMutationError(null);
    setMutationNotice(null);

    try {
      const response = await axios.post(`/admin/subscriptions/${selected.id}/cancel`, {
        cancellation_reason: result.value,
      });

      if (!response.data?.success) {
        setMutationError(response.data?.message || 'The subscription was not cancelled.');
        return;
      }

      setSelected(null);
      reloadBilling();
    } catch (error: unknown) {
      setMutationError(errorMessageFor(error));
    } finally {
      setMutationPending(false);
    }
  };

  const refundSubscription = async () => {
    if (!selected?.eligible_for_refund || !selected.refund_payment_id || mutationPending) return;

    const result = await Swal.fire({
      title: 'Issue full refund?',
      text: 'This sends one provider-backed full refund request for the paid ledger entry. The subscription is ended only after provider confirmation.',
      input: 'textarea',
      inputPlaceholder: 'Required business reason',
      inputValidator: (value) => value?.trim() ? undefined : 'Enter a business reason.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Issue full refund',
    });

    if (!result.isConfirmed || typeof result.value !== 'string' || !result.value.trim()) return;

    setMutationPending(true);
    setMutationError(null);
    setMutationNotice(null);

    try {
      const response = await axios.post(`/admin/subscription-payments/${selected.refund_payment_id}/refunds`, {
        business_reason: result.value.trim(),
        provider_reason: 'others',
      });

      if (!response.data?.success) {
        setMutationError(response.data?.message || 'The refund was not completed.');
        return;
      }

      if (response.data.status !== 'succeeded') {
        setMutationNotice(response.data.message || 'The refund is still being processed and requires reconciliation.');
        reloadBilling();
        void loadHistory(selected.id);
        return;
      }

      setSelected(null);
      reloadBilling();
    } catch (error: unknown) {
      setMutationError(errorMessageFor(error));
    } finally {
      setMutationPending(false);
    }
  };

  const correctLegacySubscription = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!selected?.legacy_correction_available || mutationPending) return;

    if (!correctionDate || !correctionReason.trim()) {
      setMutationError('Choose an effective end date and provide a correction reason.');
      return;
    }

    setMutationPending(true);
    setMutationError(null);
    setMutationNotice(null);

    try {
      const response = await axios.patch(`/admin/subscriptions/${selected.id}/legacy-correction`, {
        target_status: correctionStatus,
        effective_ends_at: new Date(`${correctionDate}T00:00:00Z`).toISOString(),
        correction_reason: correctionReason.trim(),
      });

      if (!response.data?.success) {
        setMutationError(response.data?.message || 'The legacy state was not corrected.');
        return;
      }

      setSelected(null);
      setCorrectionOpen(false);
      reloadBilling();
    } catch (error: unknown) {
      setMutationError(errorMessageFor(error));
    } finally {
      setMutationPending(false);
    }
  };

  const openPlanModal = (plan?: PremiumPlanItem) => {
    setEditingPlan(plan ?? null);
    planForm.clearErrors();
    planForm.setData(plan ? {
      plan_code: plan.plan_code,
      name: plan.name,
      description: plan.description ?? '',
      price: String(plan.price),
      duration_days: plan.duration_days,
      showroom_slot_limit: plan.showroom_slot_limit,
      benefits: plan.benefits ?? [],
    } : emptyPlan);
    setIsPlanModalOpen(true);
  };

  const submitPlan = (event: React.FormEvent) => {
    event.preventDefault();
    const options = { preserveScroll: true, onSuccess: () => setIsPlanModalOpen(false) };
    editingPlan ? planForm.put(`/admin/plans/${editingPlan.id}`, options) : planForm.post('/admin/plans', options);
  };

  const togglePlan = (plan: PremiumPlanItem) => {
    const action = plan.status === 'active' ? 'archive' : 'reactivate';
    Swal.fire({
      title: `${action === 'archive' ? 'Archive' : 'Reactivate'} ${plan.name}?`,
      text: action === 'archive' ? 'Existing subscribers keep their current access.' : 'The plan will be available for purchase again.',
      icon: 'question', showCancelButton: true, confirmButtonText: action === 'archive' ? 'Archive' : 'Reactivate',
    }).then((result) => result.isConfirmed && router.post(`/admin/plans/${plan.id}/${action}`, {}, { preserveScroll: true }));
  };

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

  useEffect(() => {
    setMutationError(null);
    setMutationNotice(null);
    setHistoryError(null);
    setCorrectionOpen(false);
    setCorrectionDate('');
    setCorrectionReason('');
    setCorrectionStatus('expired');
  }, [selected?.id]);

  const metrics = [
    {
      title: 'Active Subscriptions',
      value: stats.active.toLocaleString(),
      icon: StoreIcon,
      color: 'success' as const,
      description: 'Currently active plans',
    },
    {
      title: 'Gross Collected',
      value: formatMoney(stats.gross_collected ?? stats.total_revenue),
      icon: CreditCardIcon,
      color: 'info' as const,
      description: 'Paid payment-ledger totals',
    },
    {
      title: 'Expiring Soon',
      value: stats.expiring_soon.toLocaleString(),
      icon: AlertIcon,
      color: 'warning' as const,
      description: 'In the next 7 days',
    },
    {
      title: 'Expired',
      value: stats.expired.toLocaleString(),
      icon: CalendarIcon,
      color: 'error' as const,
      description: 'Subscriptions ended',
    },
    {
      title: 'Net Collected',
      value: formatMoney(stats.net_collected ?? stats.total_revenue),
      icon: CalendarIcon,
      color: 'success' as const,
      description: `Refunded: ${formatMoney(stats.refunded_amount ?? 0)}`,
    },
  ];

  const totalPages = Math.max(1, subscriptionPage.last_page || 1);
  const currentPage = subscriptionPage.current_page || 1;
  const upgradedCount = useMemo(() => subscriptionRows.filter(isUpgradeRecord).length, [subscriptionRows]);
  const subscriptionById = useMemo(() => {
    return subscriptionRows.reduce<Record<number, SubscriptionItem>>((acc, item) => {
      acc[item.id] = item;
      return acc;
    }, {});
  }, [subscriptionRows]);
  const selectedPreviousSubscription = useMemo(() => {
    if (!selected?.replaces_subscription_id) return null;
    return subscriptionById[selected.replaces_subscription_id] || null;
  }, [selected, subscriptionById]);

  return (
    <AppLayout>
      <div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
        <div className="mx-auto max-w-7xl space-y-6">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p className="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">Revenue & plans</p>
              <h1 className="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Subscription Management</h1>
              <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Manage premium plans and monitor every shop subscription.</p>
            </div>
            <button type="button" onClick={() => openPlanModal()} className="inline-flex items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">+ Create Plan</button>
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

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {metrics.map((metric) => <MetricCard key={metric.title} {...metric} />)}
          </div>

          <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div><h2 className="text-xl font-bold text-gray-900 dark:text-white">Premium Plans</h2><p className="text-sm text-gray-500">Configure pricing, duration, benefits, and showroom capacity.</p></div>
              <div className="inline-flex rounded-xl bg-gray-100 p-1 dark:bg-gray-900">
                {(['active', 'inactive'] as const).map((status) => <button key={status} type="button" onClick={() => setPlanStatusFilter(status)} className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${planStatusFilter === status ? 'bg-white text-blue-600 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-800 dark:hover:text-white'}`}>{status === 'active' ? 'Active' : 'Archived'} ({plans.filter((plan) => plan.status === status).length})</button>)}
              </div>
            </div>
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {plans.filter((plan) => plan.status === planStatusFilter).map((plan) => (
                <article key={plan.id} className={`flex h-full flex-col rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-md ${plan.status === 'active' ? 'border-gray-200 dark:border-gray-700' : 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40'}`}>
                  <div className="flex items-start justify-between gap-3"><div><h3 className="font-bold text-gray-900 dark:text-white">{plan.name}</h3><p className="text-xs uppercase tracking-wide text-gray-400">{plan.plan_code}</p></div><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${plan.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600'}`}>{plan.status === 'active' ? 'Active' : 'Archived'}</span></div>
                  <p className="mt-3 line-clamp-2 min-h-10 text-sm leading-5 text-gray-500">{plan.description || 'No description'}</p>
                  <div className="mt-4 grid grid-cols-3 divide-x rounded-xl bg-gray-50 py-3 text-center text-sm dark:bg-gray-900/50"><div><b className="block text-gray-900 dark:text-white">{formatMoney(Number(plan.price))}</b><span className="text-xs text-gray-400">Price</span></div><div><b className="block text-gray-900 dark:text-white">{plan.duration_days}</b><span className="text-xs text-gray-400">Days</span></div><div><b className="block text-gray-900 dark:text-white">{plan.showroom_slot_limit}</b><span className="text-xs text-gray-400">Slots</span></div></div>
                  <ul className="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">{(plan.benefits ?? []).slice(0, 2).map((benefit) => <li key={benefit} className="flex gap-2"><span className="text-emerald-500">✓</span><span className="line-clamp-1">{benefit}</span></li>)}</ul>
                  {(plan.benefits?.length ?? 0) > 2 && <p className="mt-2 text-xs font-medium text-blue-600">+{(plan.benefits?.length ?? 0) - 2} more benefits</p>}
                  <div className="mt-auto pt-5"><p className="mb-3 text-xs text-gray-400">{plan.active_subscriptions_count} active subscriber(s)</p><div className="flex gap-2"><button type="button" onClick={() => openPlanModal(plan)} className="flex-1 rounded-lg border border-blue-200 px-3 py-2 text-sm font-semibold text-blue-600 transition hover:bg-blue-50">Edit</button><button type="button" onClick={() => togglePlan(plan)} className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-50">{plan.status === 'active' ? 'Archive' : 'Reactivate'}</button></div></div>
                </article>
              ))}
              {plans.every((plan) => plan.status !== planStatusFilter) && <div className="col-span-full rounded-xl border border-dashed py-10 text-center text-sm text-gray-500">No {planStatusFilter === 'active' ? 'active' : 'archived'} plans.</div>}
            </div>
          </section>

          <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div className="mb-4"><h2 className="text-xl font-bold text-gray-900 dark:text-white">Shop Subscriptions</h2><p className="text-sm text-gray-500">Search, filter, and review subscription activity.</p></div>
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
              Showing {subscriptionPage.from ?? 0} to {subscriptionPage.to ?? 0} of {subscriptionPage.total} subscriptions
            </p>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Upgrade records on this page: {upgradedCount}. "Upgraded" means the plan was changed mid-cycle and charged with prorated amount.
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
                  {subscriptionRows.length === 0 && (
                    <tr>
                      <td colSpan={9} className="px-6 py-14 text-center">
                        <p className="text-sm font-medium text-gray-700 dark:text-gray-300">No subscriptions found</p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Try changing filters or searching by another keyword.</p>
                      </td>
                    </tr>
                  )}

                  {subscriptionRows.map((subscription) => (
                    <tr
                      key={subscription.id}
                      onClick={() => openSubscription(subscription)}
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
                            openSubscription(subscription);
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

            {subscriptionPage.total > 0 && (
              <div className="border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                <div className="flex items-center justify-between">
                  <div className="text-sm text-gray-700 dark:text-gray-300">
                    Showing <span className="font-medium">{subscriptionPage.from ?? 0}</span> to <span className="font-medium">{subscriptionPage.to ?? 0}</span> of <span className="font-medium">{subscriptionPage.total}</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => navigateSubscriptionPage(Math.max(currentPage - 1, 1))}
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
                        type="button"
                        key={page}
                        onClick={() => navigateSubscriptionPage(page)}
                        aria-label={`Go to subscription page ${page}`}
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
                      type="button"
                      onClick={() => navigateSubscriptionPage(Math.min(currentPage + 1, totalPages))}
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

        {isPlanModalOpen && (
          <ModalPortal><div className="fixed inset-0 z-999999 flex items-center justify-center bg-black/50 p-4" onClick={() => setIsPlanModalOpen(false)}><form onSubmit={submitPlan} onClick={(e) => e.stopPropagation()} className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
            <div className="mb-5 flex items-center justify-between"><h2 className="text-xl font-bold dark:text-white">{editingPlan ? 'Edit Plan' : 'Create Plan'}</h2><button type="button" onClick={() => setIsPlanModalOpen(false)} aria-label="Close"><XIcon className="size-5" /></button></div>
            <div className="grid gap-4 sm:grid-cols-2">
              {([['plan_code','Plan code'],['name','Name'],['price','Price'],['duration_days','Duration (days)'],['showroom_slot_limit','Showroom slots']] as const).map(([field,label]) => <label key={field} className="text-sm font-medium text-gray-700 dark:text-gray-200">{label}<input disabled={field === 'plan_code' && !!editingPlan} type={field === 'plan_code' || field === 'name' ? 'text' : 'number'} min={field === 'showroom_slot_limit' || field === 'duration_days' ? 1 : 0} max={field === 'showroom_slot_limit' ? 150 : field === 'duration_days' ? 3650 : undefined} value={planForm.data[field]} onChange={(e) => planForm.setData(field, field === 'plan_code' || field === 'name' || field === 'price' ? e.target.value : Number(e.target.value))} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-700" />{planForm.errors[field] && <span className="text-xs text-red-600">{planForm.errors[field]}</span>}</label>)}
            </div>
            <label className="mt-4 block text-sm font-medium text-gray-700 dark:text-gray-200">Description<textarea value={planForm.data.description} onChange={(e) => planForm.setData('description', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-700" rows={3} /></label>
            <p className="mt-2 text-xs text-gray-500">1–84: current room · 85–150: two connected rooms</p>
            <div className="mt-5"><div className="flex items-center justify-between"><h3 className="font-semibold dark:text-white">Benefits</h3><button type="button" onClick={() => planForm.setData('benefits', [...planForm.data.benefits, ''])} className="text-sm font-semibold text-blue-600">Add benefit</button></div>
              <div className="mt-2 space-y-2">{planForm.data.benefits.map((benefit,index) => <div key={index} className="flex gap-2"><input maxLength={200} value={benefit} onChange={(e) => { const benefits=[...planForm.data.benefits]; benefits[index]=e.target.value; planForm.setData('benefits',benefits); }} className="min-w-0 flex-1 rounded-lg border px-3 py-2" /><button type="button" disabled={index===0} onClick={() => { const b=[...planForm.data.benefits]; [b[index-1],b[index]]=[b[index],b[index-1]]; planForm.setData('benefits',b); }}>↑</button><button type="button" disabled={index===planForm.data.benefits.length-1} onClick={() => { const b=[...planForm.data.benefits]; [b[index+1],b[index]]=[b[index],b[index+1]]; planForm.setData('benefits',b); }}>↓</button><button type="button" onClick={() => planForm.setData('benefits',planForm.data.benefits.filter((_,i)=>i!==index))} className="text-red-600">Remove</button></div>)}</div>
              {planForm.errors.benefits && <p className="mt-1 text-xs text-red-600">{planForm.errors.benefits}</p>}
            </div>
            <div className="mt-6 flex justify-end gap-3"><button type="button" onClick={() => setIsPlanModalOpen(false)} className="rounded-lg border px-4 py-2">Cancel</button><button disabled={planForm.processing} className="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50">{planForm.processing ? 'Saving…' : 'Save Plan'}</button></div>
          </form></div></ModalPortal>
        )}

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
                  {selected.status === 'deactivated' && (
                    <p className="mt-1 text-sm font-medium text-amber-700 dark:text-amber-400">Needs correction: legacy state is unresolved.</p>
                  )}
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

              {historyLoading && <p className="mt-6 text-sm text-gray-500">Loading payment and refund history…</p>}
              {!historyLoading && historyError && <p className="mt-6 text-sm text-rose-600" role="alert">{historyError}</p>}

              {(history?.payments.data.length ?? 0) > 0 && (
                <section className="mt-6 rounded-2xl border border-gray-200 p-5 dark:border-gray-700" aria-labelledby="payment-ledger-heading">
                  <h3 id="payment-ledger-heading" className="text-xs font-semibold uppercase tracking-wide text-gray-400">Payment ledger</h3>
                  <div className="mt-3 divide-y divide-gray-200 dark:divide-gray-700">
                    {history?.payments.data.map((payment) => (
                      <div key={payment.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div>
                          <p className="text-sm font-semibold text-gray-900 dark:text-white">{payment.payment_type.replaceAll('_', ' ')}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">{payment.status} · {formatDate(payment.paid_at)}</p>
                        </div>
                        <p className="text-sm font-semibold text-gray-700 dark:text-gray-200">{formatMoney(Number(payment.amount_paid ?? payment.amount_due))}</p>
                      </div>
                    ))}
                  </div>
                </section>
              )}

              {!historyLoading && !historyError && history && history.payments.data.length === 0 && (
                <p className="mt-6 text-sm text-gray-500">No payment records.</p>
              )}

              {history && history.payments.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-gray-500">
                  <span>Payment page {history.payments.current_page} of {history.payments.last_page}</span>
                  <div className="flex gap-2">
                    <button type="button" disabled={historyLoading || history.payments.current_page === 1} onClick={() => void loadHistory(selected.id, history.payments.current_page - 1, history.refunds.current_page)} className="rounded border px-2 py-1 disabled:opacity-50">Previous</button>
                    <button type="button" disabled={historyLoading || history.payments.current_page === history.payments.last_page} onClick={() => void loadHistory(selected.id, history.payments.current_page + 1, history.refunds.current_page)} className="rounded border px-2 py-1 disabled:opacity-50">Next</button>
                  </div>
                </div>
              )}

              {((history?.refunds.data.length ?? 0) > 0 || selected.refund_block_reason) && (
                <section className="mt-4 rounded-2xl border border-gray-200 p-5 dark:border-gray-700" aria-labelledby="refund-history-heading">
                  <h3 id="refund-history-heading" className="text-xs font-semibold uppercase tracking-wide text-gray-400">Refund history</h3>
                  {selected.refund_block_reason && <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">Reconciliation required before another refund can be issued.</p>}
                  <div className="mt-3 space-y-2">
                    {history?.refunds.data.map((refund) => (
                      <p key={refund.id} className="text-sm text-gray-700 dark:text-gray-300">
                        {refund.status} · {formatMoney(refund.amount)} · {refund.business_reason}
                      </p>
                    ))}
                  </div>
                </section>
              )}

              {!historyLoading && !historyError && history && history.refunds.data.length === 0 && !selected.refund_block_reason && (
                <p className="mt-3 text-sm text-gray-500">No refund attempts.</p>
              )}

              {history && history.refunds.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-gray-500">
                  <span>Refund page {history.refunds.current_page} of {history.refunds.last_page}</span>
                  <div className="flex gap-2">
                    <button type="button" disabled={historyLoading || history.refunds.current_page === 1} onClick={() => void loadHistory(selected.id, history.payments.current_page, history.refunds.current_page - 1)} className="rounded border px-2 py-1 disabled:opacity-50">Previous</button>
                    <button type="button" disabled={historyLoading || history.refunds.current_page === history.refunds.last_page} onClick={() => void loadHistory(selected.id, history.payments.current_page, history.refunds.current_page + 1)} className="rounded border px-2 py-1 disabled:opacity-50">Next</button>
                  </div>
                </div>
              )}

              {(mutationError || mutationNotice) && (
                <div className={`mt-4 rounded-xl border px-4 py-3 text-sm ${mutationError ? 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/60 dark:bg-rose-900/20 dark:text-rose-400' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-900/20 dark:text-amber-300'}`} role="alert">
                  {mutationError || mutationNotice}
                </div>
              )}

              {correctionOpen && selected.legacy_correction_available && (
                <form onSubmit={correctLegacySubscription} className="mt-4 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900/60 dark:bg-amber-900/10">
                  <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Correct legacy state</h3>
                  <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">Only the status, effective end date, and correction reason can change. Billing and paid history stay untouched.</p>
                  <div className="mt-4 grid gap-3 sm:grid-cols-3">
                    <label className="text-sm font-medium text-gray-700 dark:text-gray-200">Target status
                      <select value={correctionStatus} onChange={(event) => setCorrectionStatus(event.target.value as 'cancelled' | 'expired')} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-600 dark:bg-gray-700">
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                      </select>
                    </label>
                    <label className="text-sm font-medium text-gray-700 dark:text-gray-200">Effective end date
                      <input type="date" required value={correctionDate} onChange={(event) => setCorrectionDate(event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <label className="text-sm font-medium text-gray-700 dark:text-gray-200 sm:col-span-1">Correction reason
                      <input type="text" required maxLength={120} value={correctionReason} onChange={(event) => setCorrectionReason(event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                  </div>
                  <div className="mt-4 flex justify-end gap-2">
                    <button type="button" onClick={() => setCorrectionOpen(false)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200">Close correction</button>
                    <button type="submit" disabled={mutationPending} className="rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{mutationPending ? 'Saving…' : 'Save correction'}</button>
                  </div>
                </form>
              )}

              <div className="mt-6 flex flex-wrap items-center justify-end gap-2">
                {selected.can_cancel && (
                  <button type="button" onClick={cancelSubscription} disabled={mutationPending} className="rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-700 dark:text-amber-300">
                    {mutationPending ? 'Working…' : 'Cancel at period end'}
                  </button>
                )}
                {selected.eligible_for_refund && selected.refund_payment_id && (
                  <button type="button" onClick={refundSubscription} disabled={mutationPending} className="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-700 dark:text-rose-300">
                    {mutationPending ? 'Working…' : 'Issue full refund'}
                  </button>
                )}
                {selected.legacy_correction_available && !correctionOpen && (
                  <button type="button" onClick={() => setCorrectionOpen(true)} disabled={mutationPending} className="rounded-lg border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-blue-700 dark:text-blue-300">
                    Correct legacy state
                  </button>
                )}
                <Button variant="outline" size="sm" onClick={() => setSelected(null)}>
                  Close
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
