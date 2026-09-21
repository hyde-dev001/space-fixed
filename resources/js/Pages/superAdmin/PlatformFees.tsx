import { Head, router, usePage } from '@inertiajs/react';
import { BadgeCheck, CircleDollarSign, Clock3, WalletCards } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import Swal, { type SweetAlertOptions } from 'sweetalert2';
import { DashboardMetricCard } from '../../components/dashboard';
import { Modal } from '../../components/ui/modal';
import AppLayout from '../../layout/AppLayout';

type FeeSetting = {
    scope: 'platform' | 'shop_type';
    shop_type: 'individual' | 'business' | null;
    platform_fee_rate: string | null;
    platform_fee_vat_enabled: boolean | null;
    platform_fee_vat_rate: string | null;
    balance_limit: string | null;
    warning_threshold_percentage: string | null;
    critical_threshold_percentage: string | null;
    enforcement_enabled: boolean | null;
    effective_from: string | null;
    terms_version: string | null;
    terms_text: string | null;
    reliability_window_days: number | null;
    reliability_version: string | null;
    reliability_weights: Record<string, number> | null;
    reliability_tiers: Array<Record<string, unknown>> | null;
};

type ShopRow = {
    id: number;
    name: string;
    shop_type: string;
    reliability_score: string | null;
    outstanding_balance: string;
    available_credits: string;
    net_payable: string;
    balance_limit: string;
    utilization_percentage: string;
    platform_fee_rate: string;
    is_restricted: boolean;
    last_payment_at: string | null;
    active_request_status: string | null;
    score_recommendation: { tier: string; minimum_score: string; recommended_limit: string | null } | null;
    credit_summary: CreditSummary;
    credit_movements: CreditMovement[];
    recommendation: { id: number; recommended_limit: string; tier: string; score: string | null } | null;
};

type CreditSummary = {
    issued: string;
    applied: string;
    remaining: string;
    outstanding_before_credits: string;
    outstanding_after_credits: string;
    outstanding_reduced_by_credits: string;
};

type CreditMovement = {
    id: number;
    source_type: string;
    source_id: number;
    source_origin: string;
    credit_amount: string;
    applied_amount: string;
    remaining_amount: string;
    status: string;
    reason: string;
    created_at: string | null;
    last_applied_at: string | null;
    outstanding_before_credit: string;
    outstanding_after_credit: string;
};

type AdminCreditMovement = CreditMovement & {
    shop_name: string;
};

const CREDIT_MOVEMENT_PAGE_SIZE = 10;
const SHOP_PAGE_SIZE = 15;

type AdminProps = {
    shops: ShopRow[];
    metrics: {
        platform_fee_earned: string;
        collected: string;
        outstanding: string;
        pending_payments: number;
    };
    settings: FeeSetting[];
    defaults: {
        platform_fee_rate: string;
        platform_fee_vat_enabled: boolean;
        platform_fee_vat_rate: string;
        warning_threshold_percentage: string;
        critical_threshold_percentage: string;
        enforcement_enabled: boolean;
        balance_limits: { individual: string; business: string };
        reliability: {
            window_days: number;
            version: string;
            weights: Record<string, number>;
            tiers: Array<Record<string, unknown>>;
        };
    };
};

type LimitEditorState = {
    shopId: number;
    shopName: string;
    value: string;
    score: string | null;
    recommendedLimit: string | null;
    recommendationTier: string | null;
};

type SettingForm = {
    platform_fee_rate: string;
    platform_fee_vat_enabled: boolean;
    platform_fee_vat_rate: string;
    balance_limit: string;
    warning_threshold_percentage: string;
    critical_threshold_percentage: string;
    enforcement_enabled: boolean;
    effective_from: string;
    terms_version: string;
    terms_text: string;
    reliability_window_days: string;
    reliability_version: string;
    reliability_weights: ReliabilityWeights;
    reliability_tiers: ReliabilityTierForm[];
};

type ReliabilityWeightKey =
    | 'payment_history'
    | 'settlement_timeliness'
    | 'marketplace_history'
    | 'refund_performance'
    | 'dispute_rate'
    | 'account_activity';

type ReliabilityWeights = Record<ReliabilityWeightKey, number>;

type ReliabilityTierForm = {
    key: string;
    minimum_score: string;
    recommended_limit: string;
};

const reliabilityWeightFields: Array<{
    key: ReliabilityWeightKey;
    label: string;
    help: string;
}> = [
    { key: 'payment_history', label: 'Payment history', help: 'Pays platform fees as agreed.' },
    { key: 'settlement_timeliness', label: 'Settlement timeliness', help: 'Pays balances within the expected time.' },
    { key: 'marketplace_history', label: 'Marketplace history', help: 'Builds a consistent sales track record.' },
    { key: 'refund_performance', label: 'Refund performance', help: 'Handles refunds without repeated issues.' },
    { key: 'dispute_rate', label: 'Dispute rate', help: 'Avoids delivery and order disputes.' },
    { key: 'account_activity', label: 'Account activity', help: 'Maintains an active, established account.' },
];

const money = (value: string | null | undefined): string => `₱${value ?? '0.00'}`;

const dateOnly = (value: string | null | undefined): string => (value ? value.slice(0, 10) : '');

const creditSourceLabel = (source: string): string => ({
    order_refund: 'Order refund',
    repair_refund: 'Repair refund',
}[source] ?? source.replaceAll('_', ' '));

const movementDate = (value: string | null | undefined): string => value
    ? new Date(value).toLocaleDateString()
    : 'Not applied yet';

const platformFeeAlert = (options: SweetAlertOptions) => Swal.fire({
    ...options,
    customClass: {
        container: 'platform-fee-swal2-container',
        ...options.customClass,
    },
});

const postPlatformFeeAction = (url: string, title: string, text: string, successText: string) => {
    void platformFeeAlert({
        icon: 'question',
        title,
        text,
        showCancelButton: true,
        confirmButtonText: 'Continue',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (!result.isConfirmed) return;

        router.post(url, {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.component === 'superAdmin/Auth/PrivilegedReauthenticate') return;

                void platformFeeAlert({
                    icon: 'success',
                    title: 'Done',
                    text: successText,
                    timer: 1800,
                    showConfirmButton: false,
                });
            },
            onError: () => {
                void platformFeeAlert({
                    icon: 'error',
                    title: 'Action failed',
                    text: 'The request could not be completed. Please try again.',
                });
            },
        });
    });
};

export default function PlatformFeesPage() {
    const { shops, metrics, settings, defaults } = usePage<AdminProps>().props;
    const [scope, setScope] = useState<'platform' | 'shop_type'>('shop_type');
    const [shopType, setShopType] = useState<'individual' | 'business'>('individual');
    const [form, setForm] = useState<SettingForm>(() => emptyForm(defaults, 'individual'));
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [creditMovementModalOpen, setCreditMovementModalOpen] = useState(false);
    const [creditMovementPage, setCreditMovementPage] = useState(1);
    const [shopPage, setShopPage] = useState(1);
    const [limitEditor, setLimitEditor] = useState<LimitEditorState | null>(null);

    const selectedSetting = useMemo(
        () => settings.find((setting) => setting.scope === scope && (scope === 'platform' || setting.shop_type === shopType)) ?? null,
        [settings, scope, shopType],
    );

    const adminCreditMovements = useMemo<AdminCreditMovement[]>(() => shops
        .flatMap((shop) => shop.credit_movements.map((movement) => ({ ...movement, shop_name: shop.name })))
        .sort((left, right) => {
            const leftDate = new Date(left.last_applied_at ?? left.created_at ?? 0).getTime();
            const rightDate = new Date(right.last_applied_at ?? right.created_at ?? 0).getTime();

            return rightDate - leftDate || right.id - left.id;
        }), [shops]);
    const creditMovementPageCount = Math.max(1, Math.ceil(adminCreditMovements.length / CREDIT_MOVEMENT_PAGE_SIZE));
    const visibleCreditMovements = adminCreditMovements.slice(
        (creditMovementPage - 1) * CREDIT_MOVEMENT_PAGE_SIZE,
        creditMovementPage * CREDIT_MOVEMENT_PAGE_SIZE,
    );
    const shopPageCount = Math.max(1, Math.ceil(shops.length / SHOP_PAGE_SIZE));
    const visibleShops = useMemo(
        () => shops.slice((shopPage - 1) * SHOP_PAGE_SIZE, shopPage * SHOP_PAGE_SIZE),
        [shops, shopPage],
    );

    useEffect(() => {
        setForm(fromSetting(selectedSetting, defaults, shopType));
    }, [selectedSetting, defaults, shopType]);

    useEffect(() => {
        setCreditMovementPage((current) => Math.min(current, creditMovementPageCount));
    }, [creditMovementPageCount]);

    useEffect(() => {
        setShopPage((current) => Math.min(current, shopPageCount));
    }, [shopPageCount]);

    useEffect(() => {
        if (!settingsOpen) return;

        const previousOverflow = document.body.style.overflow;
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setSettingsOpen(false);
        };
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [settingsOpen]);

    const setField = <K extends keyof SettingForm>(key: K, value: SettingForm[K]) => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const reliabilityWeightTotal = Object.values(form.reliability_weights).reduce((total, value) => total + value, 0);
    const reliabilityTiersValid = form.reliability_tiers.length > 0
        && form.reliability_tiers.every((tier) => (
            tier.key.trim() !== ''
            && Number.isFinite(Number(tier.minimum_score))
            && Number(tier.minimum_score) >= 0
            && Number(tier.minimum_score) <= 100
            && (tier.recommended_limit.trim() === '' || Number(tier.recommended_limit) >= 0)
        ));

    const setReliabilityWeight = (key: ReliabilityWeightKey, value: string) => {
        const numericValue = Number(value);
        setForm((current) => ({
            ...current,
            reliability_weights: {
                ...current.reliability_weights,
                [key]: Number.isFinite(numericValue) ? Math.max(0, Math.min(100, numericValue)) : 0,
            },
        }));
    };

    const setReliabilityTier = (index: number, field: keyof ReliabilityTierForm, value: string) => {
        setForm((current) => ({
            ...current,
            reliability_tiers: current.reliability_tiers.map((tier, tierIndex) => (
                tierIndex === index ? { ...tier, [field]: value } : tier
            )),
        }));
    };

    const addReliabilityTier = () => {
        setForm((current) => ({
            ...current,
            reliability_tiers: [
                ...current.reliability_tiers,
                { key: `tier_${current.reliability_tiers.length + 1}`, minimum_score: '0', recommended_limit: '' },
            ],
        }));
    };

    const removeReliabilityTier = (index: number) => {
        setForm((current) => ({
            ...current,
            reliability_tiers: current.reliability_tiers.filter((_, tierIndex) => tierIndex !== index),
        }));
    };

    const saveSettings = (event: React.FormEvent) => {
        event.preventDefault();
        if (reliabilityWeightTotal !== 100 || !reliabilityTiersValid) {
            void platformFeeAlert({
                icon: 'warning',
                title: 'Check fee settings',
                text: 'Reliability weights must total 100% and every tier must be complete.',
            });
            return;
        }

        const data = {
            scope,
            shop_type: scope === 'shop_type' ? shopType : null,
            ...form,
            reliability_weights: JSON.stringify(form.reliability_weights),
            reliability_tiers: JSON.stringify(form.reliability_tiers.map((tier) => ({
                key: tier.key.trim(),
                minimum_score: Number(tier.minimum_score),
                recommended_limit: tier.recommended_limit.trim() === '' ? null : tier.recommended_limit.trim(),
            }))),
        };

        void platformFeeAlert({
            icon: 'question',
            title: 'Save fee settings?',
            text: 'These settings will apply to charges created after the effective date.',
            showCancelButton: true,
            confirmButtonText: 'Save settings',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (!result.isConfirmed) return;

            router.post('/admin/platform-fees/settings', data, {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (page.component === 'superAdmin/Auth/PrivilegedReauthenticate') return;

                    setSettingsOpen(false);
                    void platformFeeAlert({
                        icon: 'success',
                        title: 'Settings saved',
                        text: 'The new fee policy is now ready for future charges.',
                        timer: 1800,
                        showConfirmButton: false,
                    });
                },
                onError: () => {
                    void platformFeeAlert({
                        icon: 'error',
                        title: 'Could not save settings',
                        text: 'Please review the values and try again.',
                    });
                },
            });
        });
    };

    const saveShopLimit = (event: React.FormEvent) => {
        event.preventDefault();
        if (!limitEditor || limitEditor.value.trim() === '' || Number(limitEditor.value) < 0) {
            void platformFeeAlert({
                icon: 'warning',
                title: 'Enter a valid limit',
                text: 'The shop balance limit must be zero or higher.',
            });
            return;
        }

        void platformFeeAlert({
            icon: 'question',
            title: 'Change this shop limit?',
            text: 'The new limit applies immediately to this shop.',
            showCancelButton: true,
            confirmButtonText: 'Save limit',
            cancelButtonText: 'Cancel',
        }).then((result) => {
            if (!result.isConfirmed || !limitEditor) return;

            router.post(`/admin/platform-fees/shops/${limitEditor.shopId}/limit`, {
                balance_limit: limitEditor.value,
            }, {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (page.component === 'superAdmin/Auth/PrivilegedReauthenticate') return;

                    setLimitEditor(null);
                    void platformFeeAlert({
                        icon: 'success',
                        title: 'Limit updated',
                        text: 'The shop now uses the new Platform Balance limit.',
                        timer: 1800,
                        showConfirmButton: false,
                    });
                },
                onError: () => {
                    void platformFeeAlert({
                        icon: 'error',
                        title: 'Could not update limit',
                        text: 'Please check the value and try again.',
                    });
                },
            });
        });
    };

    return (
        <AppLayout>
            <Head title="Platform Fees" />
            <div className="space-y-6">
                <header>
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Platform Fees</h1>
                    <p className="mt-2 text-gray-600 dark:text-gray-400">Review marketplace balances, reliability recommendations, and fee policy.</p>
                </header>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Platform fee summary">
                    <DashboardMetricCard testId="platform-fees-generated-card" label="Platform Fees Generated" value={money(metrics.platform_fee_earned)} description="Finalized charges less finalized reversals; confirmed payments are tracked separately." context="Platform" icon={CircleDollarSign} tone="success" />
                    <DashboardMetricCard testId="platform-fee-collected-card" label="Collected" value={money(metrics.collected)} description="Paid by shop owners" context="Collected" icon={BadgeCheck} tone="success" />
                    <DashboardMetricCard testId="platform-fee-net-payable-card" label="Net Payable" value={money(metrics.outstanding)} description="Outstanding charges after available credits" context="Receivable" icon={WalletCards} tone="warning" />
                    <DashboardMetricCard testId="platform-fee-pending-card" label="Pending payments" value={String(metrics.pending_payments)} description="Awaiting confirmation" context="Pending" icon={Clock3} tone="warning" />
                </section>

                <section className="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5 shadow-sm dark:border-emerald-900/60 dark:bg-emerald-950/20" aria-labelledby="platform-fee-credit-movement-heading" aria-label="Credit movement">
                    <div>
                        <h2 id="platform-fee-credit-movement-heading" className="text-lg font-semibold text-gray-900 dark:text-white">Credit movement</h2>
                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">Credits reduce a shop&apos;s outstanding charges; they are separate from Platform Fees Generated and do not increase it.</p>
                    </div>
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <span className="text-sm text-gray-600 dark:text-gray-400">{adminCreditMovements.length} credit movements recorded across shops</span>
                        <button type="button" onClick={() => { setCreditMovementPage(1); setCreditMovementModalOpen(true); }} className="rounded-lg border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-950/40">View credit movement history</button>
                    </div>
                </section>

                {creditMovementModalOpen && (
                    <Modal isOpen={creditMovementModalOpen} onClose={() => setCreditMovementModalOpen(false)} size="7xl" showCloseButton={false} zIndex={1000000} className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden !rounded-3xl">
                        <div className="flex max-h-[calc(100dvh-2rem)] flex-col p-5 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="platform-credit-movement-modal-heading">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h2 id="platform-credit-movement-modal-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Credit movement history</h2>
                                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">Every credit issued and applied to a shop&apos;s Platform Balance, newest first.</p>
                                </div>
                                <button type="button" aria-label="Close credit movement history" onClick={() => setCreditMovementModalOpen(false)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Close</button>
                            </div>
                            <div className="mt-4 min-h-0 flex-1 overflow-y-auto">
                            {visibleCreditMovements.length > 0 ? (
                                <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
                                    <table className="min-w-full text-left text-sm">
                                        <caption className="sr-only">Credit movement history across shops</caption>
                                        <thead className="sticky top-0 border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                            <tr>
                                                <th className="px-3 py-3">Shop</th>
                                                <th className="px-3 py-3">Source</th>
                                                <th className="px-3 py-3">Credit issued</th>
                                                <th className="px-3 py-3">Applied</th>
                                                <th className="px-3 py-3">Remaining</th>
                                                <th className="px-3 py-3">Balance after</th>
                                                <th className="px-3 py-3">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {visibleCreditMovements.map((movement) => (
                                                <tr key={movement.id} className="border-b border-gray-100 last:border-0 dark:border-gray-900">
                                                    <td className="px-3 py-3 font-medium text-gray-900 dark:text-white">{movement.shop_name}</td>
                                                    <td className="px-3 py-3">{creditSourceLabel(movement.source_type)} #{movement.source_id}</td>
                                                    <td className="px-3 py-3">{money(movement.credit_amount)}</td>
                                                    <td className="px-3 py-3 font-semibold text-emerald-700 dark:text-emerald-300">{money(movement.applied_amount)}</td>
                                                    <td className="px-3 py-3">{money(movement.remaining_amount)}</td>
                                                    <td className="px-3 py-3">{money(movement.outstanding_before_credit)} <span aria-hidden="true">→</span> {money(movement.outstanding_after_credit)}</td>
                                                    <td className="px-3 py-3">{movementDate(movement.last_applied_at ?? movement.created_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <p className="py-6 text-sm text-gray-500 dark:text-gray-400">No credit applications or deductions have been recorded yet.</p>
                            )}
                            </div>
                            <div className="flex items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-800">
                                <button type="button" aria-label="Previous page" disabled={creditMovementPage === 1} onClick={() => setCreditMovementPage((current) => Math.max(1, current - 1))} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Previous</button>
                                <span className="text-sm text-gray-600 dark:text-gray-400" aria-live="polite">Page {creditMovementPage} of {creditMovementPageCount}</span>
                                <button type="button" aria-label="Next page" disabled={creditMovementPage === creditMovementPageCount} onClick={() => setCreditMovementPage((current) => Math.min(creditMovementPageCount, current + 1))} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Next</button>
                            </div>
                        </div>
                    </Modal>
                )}

                <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="platform-fee-settings-heading">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="platform-fee-settings-heading" className="text-lg font-semibold text-gray-900 dark:text-white">Fee settings</h2>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Configure rates, terms, reliability weights, and tiers when needed.</p>
                        </div>
                        <button type="button" onClick={() => setSettingsOpen(true)} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Open fee settings</button>
                    </div>
                </section>

                {settingsOpen && (
                    <div className="fixed inset-0 z-[1000000] flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 sm:p-8 erp-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setSettingsOpen(false); }}>
                        <section className="flex max-h-[calc(100dvh-2rem)] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-2xl dark:border-gray-800 dark:bg-gray-950" role="dialog" aria-modal="true" aria-labelledby="platform-fee-settings-modal-heading">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 id="platform-fee-settings-modal-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Fee settings</h2>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">New settings apply only to charges created after the effective date.</p>
                                </div>
                                <button type="button" onClick={() => setSettingsOpen(false)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-900">Close</button>
                            </div>

                            <div className="mt-5 flex flex-wrap gap-2">
                                <button type="button" onClick={() => setScope('platform')} className={tabClass(scope === 'platform')}>Platform</button>
                                <button type="button" onClick={() => setScope('shop_type')} className={tabClass(scope === 'shop_type')}>Shop type</button>
                            </div>

                            {scope === 'shop_type' && (
                        <div className="mt-4 flex gap-2">
                            <button type="button" onClick={() => setShopType('individual')} className={tabClass(shopType === 'individual')}>Individual</button>
                            <button type="button" onClick={() => setShopType('business')} className={tabClass(shopType === 'business')}>Business</button>
                        </div>
                    )}

                            <form onSubmit={saveSettings} className="mt-5 min-h-0 flex-1 grid gap-4 overflow-y-auto pr-1 md:grid-cols-3">
                        <Field label="Platform Fee rate (%)" value={form.platform_fee_rate} onChange={(value) => setField('platform_fee_rate', value)} />
                        <Field label="VAT rate (%)" value={form.platform_fee_vat_rate} onChange={(value) => setField('platform_fee_vat_rate', value)} />
                        <Field label="Balance limit" value={form.balance_limit} onChange={(value) => setField('balance_limit', value)} />
                        <Field label="Warning threshold (%)" value={form.warning_threshold_percentage} onChange={(value) => setField('warning_threshold_percentage', value)} />
                        <Field label="Critical threshold (%)" value={form.critical_threshold_percentage} onChange={(value) => setField('critical_threshold_percentage', value)} />
                        <Field label="Effective from" type="date" value={form.effective_from} onChange={(value) => setField('effective_from', value)} />
                        <Field label="Terms version" value={form.terms_version} onChange={(value) => setField('terms_version', value)} />
                        <Field label="Reliability window (days)" value={form.reliability_window_days} onChange={(value) => setField('reliability_window_days', value)} />
                        <Field label="Reliability version" value={form.reliability_version} onChange={(value) => setField('reliability_version', value)} />
                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" checked={form.platform_fee_vat_enabled} onChange={(event) => setField('platform_fee_vat_enabled', event.target.checked)} />
                            Platform Fee VAT enabled
                        </label>
                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" checked={form.enforcement_enabled} onChange={(event) => setField('enforcement_enabled', event.target.checked)} />
                            Marketplace enforcement enabled
                        </label>
                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" checked={Boolean(form.terms_version)} onChange={(event) => setField('terms_version', event.target.checked ? (form.terms_version || 'v1') : '')} />
                            Require current terms acceptance
                        </label>
                        <label className="md:col-span-3 text-sm text-gray-700 dark:text-gray-200">
                            Terms disclosure
                            <textarea value={form.terms_text} onChange={(event) => setField('terms_text', event.target.value)} rows={3} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
                        </label>
                        <section className="md:col-span-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-900/50" aria-labelledby="reliability-scoring-heading">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 id="reliability-scoring-heading" className="text-base font-semibold text-gray-900 dark:text-white">Reliability scoring</h3>
                                    <p className="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">Choose how much each business signal contributes to the score. The percentages must add up to 100%.</p>
                                </div>
                                <div
                                    className={`rounded-full px-3 py-1 text-sm font-semibold ${reliabilityWeightTotal === 100 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300'}`}
                                    role="status"
                                    aria-live="polite"
                                >
                                    Total: {reliabilityWeightTotal}%
                                </div>
                            </div>

                            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {reliabilityWeightFields.map((field) => (
                                    <label key={field.key} className="rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200">
                                        <span className="font-medium">{field.label}</span>
                                        <span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">{field.help}</span>
                                        <span className="mt-3 flex items-center gap-2">
                                            <input
                                                aria-label={`${field.label} weight (%)`}
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="1"
                                                value={form.reliability_weights[field.key]}
                                                onChange={(event) => setReliabilityWeight(field.key, event.target.value)}
                                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base dark:border-gray-700 dark:bg-gray-900"
                                            />
                                            <span aria-hidden="true" className="font-semibold">%</span>
                                        </span>
                                    </label>
                                ))}
                            </div>

                            <div className="mt-6 border-t border-gray-200 pt-5 dark:border-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 className="text-base font-semibold text-gray-900 dark:text-white">Reliability tiers</h3>
                                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">Set the minimum score for each tier and the recommended Platform Balance limit.</p>
                                    </div>
                                    <button type="button" onClick={addReliabilityTier} className="rounded-lg border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-950/40">Add tier</button>
                                </div>

                                <div className="mt-4 space-y-3">
                                    {form.reliability_tiers.map((tier, index) => (
                                        <div key={`${tier.key}-${index}`} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-3 sm:grid-cols-[1fr_10rem_12rem_auto] sm:items-end dark:border-gray-800 dark:bg-gray-950">
                                            <label className="text-sm text-gray-700 dark:text-gray-200">
                                                <span className="font-medium">Tier name</span>
                                                <input aria-label={`Tier ${index + 1} name`} required value={tier.key} onChange={(event) => setReliabilityTier(index, 'key', event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
                                            </label>
                                            <label className="text-sm text-gray-700 dark:text-gray-200">
                                                <span className="font-medium">Minimum score</span>
                                                <input aria-label={`Tier ${index + 1} minimum score`} required type="number" min="0" max="100" step="1" value={tier.minimum_score} onChange={(event) => setReliabilityTier(index, 'minimum_score', event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
                                            </label>
                                            <label className="text-sm text-gray-700 dark:text-gray-200">
                                                <span className="font-medium">Recommended limit</span>
                                                <input aria-label={`Tier ${index + 1} recommended limit`} type="number" min="0" step="0.01" value={tier.recommended_limit} onChange={(event) => setReliabilityTier(index, 'recommended_limit', event.target.value)} placeholder="No limit" className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
                                            </label>
                                            <button type="button" aria-label={`Remove tier ${index + 1}`} onClick={() => removeReliabilityTier(index)} disabled={form.reliability_tiers.length <= 1} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-900">Remove</button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </section>
                        <div className="md:col-span-3 flex justify-end">
                            <button type="submit" disabled={reliabilityWeightTotal !== 100 || !reliabilityTiersValid} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">Save settings</button>
                        </div>
                            </form>
                        </section>
                    </div>
                )}

                {!settingsOpen && <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby="platform-fee-shops-heading">
                    <h2 id="platform-fee-shops-heading" className="text-lg font-semibold text-gray-900 dark:text-white">Shop Platform Balance overview</h2>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Showing shops {shops.length === 0 ? 0 : (shopPage - 1) * SHOP_PAGE_SIZE + 1}-{Math.min(shopPage * SHOP_PAGE_SIZE, shops.length)} of {shops.length}
                    </p>
                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                <tr>
                                    {['Shop', 'Type', 'Score', 'Net Payable', 'Limit', 'Utilization', 'Status', 'Workflow', 'Actions'].map((heading) => <th key={heading} className="px-3 py-3">{heading}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {visibleShops.map((shop) => (
                                    <tr key={shop.id} className="border-b border-gray-100 last:border-0 dark:border-gray-900">
                                        <td className="px-3 py-3 font-medium text-gray-900 dark:text-white">{shop.name}</td>
                                        <td className="px-3 py-3 capitalize">{shop.shop_type}</td>
                                        <td className="px-3 py-3">{shop.reliability_score ?? '—'}</td>
                                        <td className="px-3 py-3 font-semibold">{money(shop.net_payable)}</td>
                                        <td className="px-3 py-3">{money(shop.balance_limit)}</td>
                                        <td className="px-3 py-3">{shop.utilization_percentage}%</td>
                                        <td className="px-3 py-3">{shop.is_restricted ? <span className="text-rose-600">Restricted</span> : 'Active'}</td>
                                        <td className="px-3 py-3">{shop.active_request_status ?? 'None'}</td>
                                        <td className="px-3 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                {shop.recommendation && <button type="button" onClick={() => postPlatformFeeAction(`/admin/platform-fees/recommendations/${shop.recommendation?.id}/approve`, 'Approve this recommendation?', 'The recommended Platform Balance limit will be applied to this shop.', 'Recommendation approved.')} className="rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700">Approve {money(shop.recommendation.recommended_limit)}</button>}
                                                <button type="button" aria-label={`Change limit for ${shop.name}`} onClick={() => setLimitEditor({ shopId: shop.id, shopName: shop.name, value: shop.balance_limit, score: shop.reliability_score, recommendedLimit: shop.score_recommendation?.recommended_limit ?? shop.recommendation?.recommended_limit ?? null, recommendationTier: shop.score_recommendation?.tier ?? shop.recommendation?.tier ?? null })} className="rounded border border-violet-300 px-2 py-1 text-xs font-semibold text-violet-700">Change limit</button>
                                                <button type="button" onClick={() => postPlatformFeeAction(`/admin/platform-fees/shops/${shop.id}/recalculate`, 'Recalculate this shop?', 'The reliability score and balance recommendation will be refreshed.', 'Shop balance recalculated.')} className="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700">Recalculate</button>
                                                <button type="button" onClick={() => postPlatformFeeAction(`/admin/platform-fees/shops/${shop.id}/remind`, 'Send a payment reminder?', 'The shop owner will be notified about the outstanding Platform Balance.', 'Payment reminder sent.')} className="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-700">Remind</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {shops.length === 0 && <p className="py-6 text-sm text-gray-500">No approved shops found.</p>}
                    </div>
                    {shopPageCount > 1 && (
                        <nav aria-label="Shop balance pages" className="mt-4 flex items-center justify-between gap-3">
                            <button type="button" aria-label="Previous shop page" onClick={() => setShopPage((current) => Math.max(1, current - 1))} disabled={shopPage === 1} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Previous</button>
                            <span className="text-sm text-gray-600 dark:text-gray-400" aria-live="polite">Page {shopPage} of {shopPageCount}</span>
                            <button type="button" aria-label="Next shop page" onClick={() => setShopPage((current) => Math.min(shopPageCount, current + 1))} disabled={shopPage === shopPageCount} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300">Next</button>
                        </nav>
                    )}
                </section>}

                <Modal isOpen={limitEditor !== null} onClose={() => setLimitEditor(null)} size="md" showCloseButton={false} zIndex={1000000}>
                    {limitEditor && (
                        <div role="dialog" aria-modal="true" aria-labelledby="shop-limit-modal-heading" className="p-6">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h2 id="shop-limit-modal-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Change Platform Balance limit</h2>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{limitEditor.shopName}</p>
                                </div>
                                <button type="button" onClick={() => setLimitEditor(null)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Close</button>
                            </div>
                            <div className="mt-5 grid gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4 sm:grid-cols-2 dark:border-violet-900/60 dark:bg-violet-950/20">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">Reliability score</p>
                                    <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{limitEditor.score ?? 'Not calculated'}{limitEditor.score && <span className="text-sm font-medium text-gray-500"> / 100</span>}</p>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">Score-based recommendation</p>
                                    {limitEditor.recommendedLimit ? (
                                        <>
                                            <p className="mt-1 font-semibold text-gray-900 dark:text-white">{money(limitEditor.recommendedLimit)}</p>
                                            <p className="text-xs text-gray-500 dark:text-gray-400">Tier: {limitEditor.recommendationTier ?? 'Recommended'}</p>
                                            <button type="button" onClick={() => setLimitEditor((current) => current ? { ...current, value: current.recommendedLimit ?? current.value } : current)} className="mt-2 rounded border border-violet-300 px-3 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-100 dark:border-violet-700 dark:text-violet-300 dark:hover:bg-violet-950/50">Use recommended limit</button>
                                        </>
                                    ) : (
                                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">No higher limit is recommended for this score yet. Recalculate the shop to refresh it.</p>
                                    )}
                                </div>
                            </div>
                            <form onSubmit={saveShopLimit} className="mt-5 space-y-4">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                    New balance limit
                                    <input
                                        aria-label="New balance limit"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={limitEditor.value}
                                        onChange={(event) => setLimitEditor((current) => current ? { ...current, value: event.target.value } : current)}
                                        className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                                        required
                                    />
                                </label>
                                <p className="text-xs text-gray-500 dark:text-gray-400">Use 0 to remove the balance limit for this shop.</p>
                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => setLimitEditor(null)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
                                    <button type="submit" className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Save balance limit</button>
                                </div>
                            </form>
                        </div>
                    )}
                </Modal>
            </div>
        </AppLayout>
    );
}

function tabClass(active: boolean): string {
    return `rounded-lg px-3 py-2 text-sm font-semibold ${active ? 'bg-blue-600 text-white' : 'border border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'}`;
}

function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (value: string) => void; type?: string }) {
    return (
        <label className="text-sm text-gray-700 dark:text-gray-200">
            {label}
            <input type={type} value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
        </label>
    );
}

function emptyForm(defaults: AdminProps['defaults'], shopType: 'individual' | 'business'): SettingForm {
    const reliability = defaults.reliability;
    return {
        platform_fee_rate: defaults.platform_fee_rate,
        platform_fee_vat_enabled: defaults.platform_fee_vat_enabled,
        platform_fee_vat_rate: defaults.platform_fee_vat_rate,
        balance_limit: defaults.balance_limits[shopType],
        warning_threshold_percentage: defaults.warning_threshold_percentage,
        critical_threshold_percentage: defaults.critical_threshold_percentage,
        enforcement_enabled: defaults.enforcement_enabled,
        effective_from: '',
        terms_version: '',
        terms_text: '',
        reliability_window_days: String(reliability.window_days),
        reliability_version: reliability.version,
        reliability_weights: normalizeReliabilityWeights(reliability.weights),
        reliability_tiers: normalizeReliabilityTiers(reliability.tiers),
    };
}

function fromSetting(setting: FeeSetting | null, defaults: AdminProps['defaults'], shopType: 'individual' | 'business'): SettingForm {
    const fallback = emptyForm(defaults, shopType);
    if (!setting) return fallback;
    return {
        platform_fee_rate: setting.platform_fee_rate ?? fallback.platform_fee_rate,
        platform_fee_vat_enabled: setting.platform_fee_vat_enabled ?? fallback.platform_fee_vat_enabled,
        platform_fee_vat_rate: setting.platform_fee_vat_rate ?? fallback.platform_fee_vat_rate,
        balance_limit: setting.balance_limit ?? fallback.balance_limit,
        warning_threshold_percentage: setting.warning_threshold_percentage ?? fallback.warning_threshold_percentage,
        critical_threshold_percentage: setting.critical_threshold_percentage ?? fallback.critical_threshold_percentage,
        enforcement_enabled: setting.enforcement_enabled ?? fallback.enforcement_enabled,
        effective_from: dateOnly(setting.effective_from),
        terms_version: setting.terms_version ?? '',
        terms_text: setting.terms_text ?? '',
        reliability_window_days: String(setting.reliability_window_days ?? fallback.reliability_window_days),
        reliability_version: setting.reliability_version ?? fallback.reliability_version,
        reliability_weights: normalizeReliabilityWeights(setting.reliability_weights ?? fallback.reliability_weights),
        reliability_tiers: normalizeReliabilityTiers(
            setting.reliability_tiers && setting.reliability_tiers.length > 0
                ? setting.reliability_tiers
                : fallback.reliability_tiers,
        ),
    };
}

function normalizeReliabilityWeights(weights: Record<string, number>): ReliabilityWeights {
    return reliabilityWeightFields.reduce((normalized, field) => {
        normalized[field.key] = Number(weights[field.key] ?? 0);

        return normalized;
    }, {} as ReliabilityWeights);
}

function normalizeReliabilityTiers(tiers: Array<Record<string, unknown>>): ReliabilityTierForm[] {
    return tiers.map((tier, index) => ({
        key: String(tier.key ?? `tier_${index + 1}`),
        minimum_score: String(tier.minimum_score ?? 0),
        recommended_limit: tier.recommended_limit === null || tier.recommended_limit === undefined
            ? ''
            : String(tier.recommended_limit),
    }));
}
