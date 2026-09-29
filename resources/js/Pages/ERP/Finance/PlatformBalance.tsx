import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Head } from '@inertiajs/react';
import { CircleDollarSign, ShieldAlert } from 'lucide-react';
import Swal from 'sweetalert2';
import { Modal } from '../../../components/ui/modal';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { useFinanceApi } from '../../../hooks/useFinanceApi';
import {
    DashboardMetricCard,
    DashboardPanel,
    DashboardShell,
    DashboardState,
} from '../../../components/dashboard';

type Money = string;

interface PlatformBalance {
    outstanding_balance: Money;
    available_credits: Money;
    net_payable: Money;
    balance_limit: Money;
    utilization_percentage: string;
    warning_threshold_percentage: string;
    critical_threshold_percentage: string;
    enforcement_enabled: boolean;
    shop_type: 'individual' | 'business';
    is_restricted: boolean;
    credit_summary: PlatformCreditSummary;
    credit_movements: PlatformCreditMovement[];
}

interface PlatformCreditSummary {
    issued: Money;
    applied: Money;
    remaining: Money;
    outstanding_before_credits: Money;
    outstanding_after_credits: Money;
    outstanding_reduced_by_credits: Money;
}

interface PlatformCreditMovement {
    id: number;
    source_type: string;
    source_id: number;
    source_origin: string;
    credit_amount: Money;
    applied_amount: Money;
    remaining_amount: Money;
    status: string;
    reason: string;
    created_at: string | null;
    last_applied_at: string | null;
    outstanding_before_credit: Money;
    outstanding_after_credit: Money;
}

interface PlatformFeeCharge {
    id: number;
    source_type: string;
    source_id: number;
    source_origin: string;
    fee_base: Money;
    fee_rate: string;
    platform_fee_amount: Money;
    vat_rate: string;
    vat_amount: Money;
    total_charge: Money;
    status: string;
    finalized_at: string | null;
}

interface PlatformFeePaymentRequest {
    id: number;
    requested_amount: Money;
    balance_snapshot: Money;
    credit_snapshot: Money;
    net_payable_snapshot: Money;
    status: string;
    owner_approved_at: string | null;
    owner_rejected_at: string | null;
    created_at: string;
}

interface PlatformBalancePayload {
    balance: PlatformBalance;
    charges: PlatformFeeCharge[];
    payment_requests: PlatformFeePaymentRequest[];
    reliability: {
        latest: { score: string; factor_breakdown: Record<string, { score: string; weight: number; contribution: string }>; metrics: Record<string, number> | null } | null;
        history: Array<{ id: number; score_date: string; score: string }>;
    };
    terms: { version: string; text: string; accepted: boolean; accepted_at: string | null };
}

class PlatformBalanceError extends Error {
    constructor(public readonly status: number, message: string) {
        super(message);
    }
}

const formatMoney = (value: Money | undefined): string => `₱${value ?? '0.00'}`;

const statusLabels: Record<string, string> = {
    pending_owner_approval: 'Waiting for shop owner approval',
    owner_approved: 'Approved by shop owner',
    payment_pending: 'Payment checkout pending',
    paid: 'Paid',
    failed: 'Payment failed',
    rejected: 'Rejected',
};

const humanizeStatus = (status: string | null | undefined): string => status
    ? statusLabels[status] ?? status.replaceAll('_', ' ')
    : 'No active payment';

const sourceLabel = (source: string): string => ({
    order: 'Order',
    repair: 'Repair',
    order_refund: 'Order refund',
    repair_refund: 'Repair refund',
}[source] ?? source.replaceAll('_', ' '));

const movementDate = (value: string | null): string => value
    ? new Date(value).toLocaleDateString()
    : 'Not applied yet';

const MOVEMENT_PAGE_SIZE = 10;

export default function PlatformBalancePage() {
    const api = useFinanceApi();
    const [actionError, setActionError] = useState<string | null>(null);
    const [actionPending, setActionPending] = useState(false);
    const [movementModalOpen, setMovementModalOpen] = useState(false);
    const [movementPage, setMovementPage] = useState(1);
    const isOwner = api.ownerMode;
    const query = useQuery<PlatformBalancePayload, PlatformBalanceError>({
        queryKey: ['finance', 'platform-balance'],
        queryFn: async () => {
            const response = await api.get<PlatformBalancePayload>('/api/finance/platform-balance');
            if (!response.ok || !response.data) {
                throw new PlatformBalanceError(response.status, response.error || 'Platform Balance unavailable');
            }
            return response.data;
        },
    });

    const payload = query.data;
    const forbidden = query.isError && query.error.status === 403;
    const movements = payload?.balance.credit_movements ?? [];
    const movementPageCount = Math.max(1, Math.ceil(movements.length / MOVEMENT_PAGE_SIZE));
    const visibleMovements = movements.slice(
        (movementPage - 1) * MOVEMENT_PAGE_SIZE,
        movementPage * MOVEMENT_PAGE_SIZE,
    );

    useEffect(() => {
        setMovementPage((current) => Math.min(current, movementPageCount));
    }, [movementPageCount]);

    const reconcilePayment = async (paymentId?: number): Promise<boolean> => {
        setActionError(null);
        setActionPending(true);
        const response = await api.post<{ payment?: { status?: string } }>('/api/finance/platform-balance/reconcile', paymentId ? { payment_id: paymentId } : undefined);
        setActionPending(false);
        if (!response.ok) {
            setActionError(response.error || 'The payment status could not be verified yet.');
            void Swal.fire({
                icon: 'error',
                title: 'Payment status unavailable',
                text: response.error || 'We could not confirm this payment yet. Please try again.',
            });
            return false;
        }

        return true;
    };

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const params = new URLSearchParams(window.location.search);
        if (params.get('paymongo_failed') === '1') {
            void Swal.fire({
                icon: 'error',
                title: 'Payment not completed',
                text: 'The PayMongo checkout was not completed. Your Platform Balance was not changed.',
            });
            window.history.replaceState({}, document.title, `${window.location.pathname}${window.location.hash}`);
            return;
        }
        if (params.get('paymongo_success') !== '1') return;

        const rawPaymentId = Number(params.get('payment_id'));
        const paymentId = Number.isInteger(rawPaymentId) && rawPaymentId > 0 ? rawPaymentId : undefined;
        void reconcilePayment(paymentId).then((confirmed) => {
            void query.refetch();
            if (confirmed) {
                void Swal.fire({
                    icon: 'success',
                    title: 'Payment confirmed',
                    text: 'Your Platform Balance has been updated.',
                    timer: 1800,
                    showConfirmButton: false,
                });
            }
            window.history.replaceState({}, document.title, `${window.location.pathname}${window.location.hash}`);
        });
    }, [isOwner]);

    const runAction = async (
        url: string,
        body: Record<string, unknown> | undefined,
        options: {
            confirmTitle: string;
            confirmText: string;
            successTitle: string;
            successText: string;
        },
    ) => {
        const confirmation = await Swal.fire({
            icon: 'question',
            title: options.confirmTitle,
            text: options.confirmText,
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel',
        });
        if (!confirmation.isConfirmed) return;

        setActionError(null);
        setActionPending(true);
        const response = await api.post<{ payment?: { checkout_url?: string | null } }>(url, body);
        setActionPending(false);
        if (!response.ok) {
            setActionError(response.error || 'The Platform Balance action could not be completed.');
            await Swal.fire({
                icon: 'error',
                title: 'Action failed',
                text: response.error || 'The Platform Balance action could not be completed.',
            });
            return;
        }
        const checkoutUrl = response.data?.payment?.checkout_url;
        if (checkoutUrl) {
            await Swal.fire({
                icon: 'info',
                title: 'Continue to PayMongo',
                text: 'You will be redirected to complete the full Platform Balance payment.',
                confirmButtonText: 'Open payment page',
            });
            window.location.assign(checkoutUrl);
            return;
        }
        await query.refetch();
        await Swal.fire({
            icon: 'success',
            title: options.successTitle,
            text: options.successText,
            timer: 1800,
            showConfirmButton: false,
        });
    };

    const activeRequest = payload?.payment_requests.find((request) => ['pending_owner_approval', 'owner_approved', 'payment_pending'].includes(request.status));
    const isBusiness = payload?.balance.shop_type === 'business';
    const workflowSteps = isBusiness
        ? ['Finance requests payment', 'Shop owner approves', 'Finance opens payment']
        : ['Review your balance', 'Pay in PayMongo', 'Balance clears after confirmation'];
    const currentStep = isBusiness
        ? (activeRequest?.status === 'owner_approved' || activeRequest?.status === 'payment_pending' ? 3 : activeRequest ? 2 : 1)
        : Number(payload?.balance.net_payable ?? 0) > 0 ? 2 : 3;
    const refresh = async () => {
        await reconcilePayment();
        await query.refetch();
    };

    return (
        <AppLayoutERP>
            <Head title="Platform Balance - SoleSpace ERP" />
            <DashboardShell
                testId="platform-balance-page"
                title="Platform Balance"
                description={isOwner
                    ? (isBusiness
                        ? 'Review your shop balance and approve the payment request sent by Finance.'
                        : 'Review your shop balance and pay SoleSpace directly. No Finance approval is required.')
                    : 'Review shop balances, payment approvals, and Platform Fee settlements.'}
                icon={CircleDollarSign}
                onRefresh={() => void refresh()}
                isRefreshing={query.isFetching || actionPending}
            >
                {query.isLoading && <DashboardState status="loading" title="Loading Platform Balance" />}
                {forbidden && <DashboardState status="error" title="Finance access required" message="You do not have access to the Platform Balance." />}
                {query.isError && !forbidden && <DashboardState status="error" title="Platform Balance unavailable" message="The Platform Balance is temporarily unavailable." onRetry={() => void query.refetch()} />}
                {!query.isLoading && !query.isError && !payload && <DashboardState status="empty" title="No Platform Balance data" />}

                {payload && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <DashboardMetricCard label="Outstanding Charges" value={formatMoney(payload.balance.outstanding_balance)} description="Unpaid finalized charges before unused credits; applied credits are already reflected." context="Platform" icon={CircleDollarSign} />
                            <DashboardMetricCard label="Available Credits" value={formatMoney(payload.balance.available_credits)} description="Unused refund/reversal credits available to reduce this balance." context="Platform" icon={CircleDollarSign} />
                            <DashboardMetricCard label="Net Payable" value={formatMoney(payload.balance.net_payable)} description="Outstanding Charges less Available Credits, never below zero." context="Platform" icon={CircleDollarSign} />
                            <DashboardMetricCard label="Balance utilization" value={`${payload.balance.utilization_percentage}%`} description={`Limit ${formatMoney(payload.balance.balance_limit)}`} context={payload.balance.is_restricted ? 'Restricted' : 'Active'} icon={payload.balance.is_restricted ? ShieldAlert : CircleDollarSign} tone={payload.balance.is_restricted ? 'danger' : 'success'} />
                        </div>
                        <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">Net payable = max(0, Outstanding Charges - Available Credits). Applied credits are already reflected in Outstanding Charges.</p>

                        <DashboardPanel className="mt-6" eyebrow="Money movement" title="How credits changed your balance" description={isOwner ? 'Refund credits are applied automatically to future Platform Fee charges.' : 'Track refund credits applied to this shop before reviewing payment actions.'}>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <MovementStat label="Credits issued" value={formatMoney(payload.balance.credit_summary.issued)} />
                                <MovementStat label="Credits applied" value={formatMoney(payload.balance.credit_summary.applied)} />
                                <MovementStat label="Outstanding reduced by credits" value={formatMoney(payload.balance.credit_summary.outstanding_reduced_by_credits)} />
                                <MovementStat label="Credit remaining" value={formatMoney(payload.balance.credit_summary.remaining)} />
                            </div>
                            <p className="mt-4 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200" role="status">
                                Outstanding before credit applications: <strong>{formatMoney(payload.balance.credit_summary.outstanding_before_credits)}</strong>
                                <span className="mx-2" aria-hidden="true">→</span>
                                after applications: <strong>{formatMoney(payload.balance.credit_summary.outstanding_after_credits)}</strong>
                            </p>
                            {movements.length === 0 ? (
                                <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">No Platform Fee credits have been issued for this shop.</p>
                            ) : (
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 dark:border-gray-800 dark:bg-gray-900/50">
                                    <div>
                                        <p className="font-semibold text-gray-900 dark:text-white">{movements.length} credit movements recorded</p>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Open the history when you need to review individual deductions.</p>
                                    </div>
                                    <button type="button" onClick={() => { setMovementPage(1); setMovementModalOpen(true); }} className="rounded-lg bg-gray-950 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-white dark:text-gray-950 dark:hover:bg-gray-200">View movement history</button>
                                </div>
                            )}
                        </DashboardPanel>

                        {actionError && <p className="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{actionError}</p>}

                        <div className="mt-6 grid gap-6 lg:grid-cols-2">
                            <DashboardPanel eyebrow="Reliability" title="Platform Reliability Score" description="Internal decision-support metric based on marketplace behavior and settlement history.">
                                <div className="flex flex-wrap items-end justify-between gap-4">
                                    <div>
                                        <p className="text-4xl font-bold text-gray-900 dark:text-white">{payload.reliability.latest?.score ?? '—'}<span className="ml-1 text-base font-medium text-gray-500">/ 100</span></p>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">History retained for daily review.</p>
                                    </div>
                                    <div className="text-right text-sm text-gray-500 dark:text-gray-400">{payload.reliability.latest?.metrics?.marketplace_orders ?? 0} marketplace orders<br />{payload.reliability.latest?.metrics?.marketplace_repairs ?? 0} marketplace repairs</div>
                                </div>
                            </DashboardPanel>

                            <DashboardPanel eyebrow="Payment workflow" title={isBusiness ? 'Business payment approval' : 'Pay your Platform Balance'} description={isBusiness ? 'Business shops use Finance request → owner approval → Finance checkout.' : 'Individual shops pay the full server-calculated balance directly. No Finance employee is needed.'}>
                                <ol className="mb-5 grid gap-2 sm:grid-cols-3" aria-label="Platform Balance payment steps">
                                    {workflowSteps.map((step, index) => {
                                        const stepNumber = index + 1;
                                        const isCurrent = stepNumber === currentStep;
                                        const isComplete = stepNumber < currentStep;

                                        return (
                                            <li key={step} className={`rounded-lg border px-3 py-2 text-xs ${isCurrent ? 'border-blue-500 bg-blue-50 text-blue-900 dark:bg-blue-950/30 dark:text-blue-200' : isComplete ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/20 dark:text-emerald-300' : 'border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400'}`}>
                                                <span className="font-semibold">{isComplete ? '✓ ' : `${stepNumber}. `}</span>{step}
                                            </li>
                                        );
                                    })}
                                </ol>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="font-semibold text-gray-900 dark:text-white">{humanizeStatus(activeRequest?.status)}</p>
                                        {activeRequest && <p className="mt-1 text-sm text-gray-500">Requested {formatMoney(activeRequest.net_payable_snapshot)}</p>}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {isOwner && !isBusiness && Number(payload.balance.net_payable) > 0 && <button type="button" disabled={actionPending} onClick={() => void runAction('/api/finance/platform-balance/pay', undefined, { confirmTitle: 'Pay your full Platform Balance?', confirmText: `Pay ${formatMoney(payload.balance.net_payable)} through PayMongo.`, successTitle: 'Payment started', successText: 'Complete the payment on the PayMongo checkout page.' })} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Pay {formatMoney(payload.balance.net_payable)} now</button>}
                                        {isOwner && isBusiness && activeRequest?.status === 'pending_owner_approval' && <><button type="button" disabled={actionPending} onClick={() => void runAction(`/api/finance/platform-balance/payment-requests/${activeRequest.id}/approve`, undefined, { confirmTitle: 'Approve this payment request?', confirmText: 'Finance will be able to open the PayMongo checkout after your approval.', successTitle: 'Payment request approved', successText: 'Finance can now continue with the payment.' })} className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Approve payment</button><button type="button" disabled={actionPending} onClick={() => void runAction(`/api/finance/platform-balance/payment-requests/${activeRequest.id}/reject`, undefined, { confirmTitle: 'Reject this payment request?', confirmText: 'The Finance payment request will be closed.', successTitle: 'Payment request rejected', successText: 'The request has been closed.' })} className="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700 disabled:opacity-50">Reject request</button></>}
                                        {!isOwner && isBusiness && !activeRequest && Number(payload.balance.net_payable) > 0 && <button type="button" disabled={actionPending} onClick={() => void runAction('/api/finance/platform-balance/payment-requests', undefined, { confirmTitle: 'Request Platform Balance payment approval?', confirmText: 'The shop owner will receive a notification to approve the full balance.', successTitle: 'Approval request sent', successText: 'The shop owner has been notified.' })} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Send approval request</button>}
                                        {!isOwner && isBusiness && activeRequest?.status === 'owner_approved' && <button type="button" disabled={actionPending} onClick={() => void runAction(`/api/finance/platform-balance/payment-requests/${activeRequest.id}/execute`, undefined, { confirmTitle: 'Open the PayMongo checkout?', confirmText: 'This starts the full Platform Balance payment for the approved request.', successTitle: 'Checkout ready', successText: 'Continue on the PayMongo payment page.' })} className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Open PayMongo checkout</button>}
                                    </div>
                                </div>
                            </DashboardPanel>
                        </div>

                        {isOwner && payload.terms.version && (
                            <DashboardPanel className="mt-6" eyebrow="Terms" title={`Platform Fee terms ${payload.terms.version}`} description="These terms explain the merchant-only Platform Fee and full-balance payment process.">
                                <p className="whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">{payload.terms.text || 'Platform Fee terms are configured by SoleSpace.'}</p>
                                {!payload.terms.accepted && <button type="button" disabled={actionPending} onClick={() => void runAction('/api/finance/platform-balance/terms/accept', undefined, { confirmTitle: 'Accept the current Platform Fee terms?', confirmText: 'You confirm that you understand the Platform Balance payment process.', successTitle: 'Terms accepted', successText: 'The current Platform Fee terms are now accepted.' })} className="mt-4 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Accept current terms</button>}
                                {payload.terms.accepted && <p className="mt-4 text-sm font-medium text-emerald-700">Accepted</p>}
                            </DashboardPanel>
                        )}

                        <DashboardPanel className="mt-6" eyebrow="Fee ledger" title="Marketplace Platform Fee charges" description="POS and customer-facing VAT are excluded from this ledger.">
                            {payload.charges.length === 0 ? (
                                <p className="text-sm text-gray-500 dark:text-gray-400">No marketplace Platform Fee charges have been finalized.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-left text-sm">
                                        <thead className="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                            <tr>
                                                <th className="px-3 py-3">Source</th>
                                                <th className="px-3 py-3">Fee base</th>
                                                <th className="px-3 py-3">Rate</th>
                                                <th className="px-3 py-3">VAT</th>
                                                <th className="px-3 py-3">Total</th>
                                                <th className="px-3 py-3">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {payload.charges.map((charge) => (
                                                <tr key={charge.id} className="border-b border-gray-100 last:border-0 dark:border-gray-900">
                                                    <td className="px-3 py-3 font-medium text-gray-900 dark:text-white">{sourceLabel(charge.source_type)} #{charge.source_id}</td>
                                                    <td className="px-3 py-3">{formatMoney(charge.fee_base)}</td>
                                                    <td className="px-3 py-3">{charge.fee_rate}%</td>
                                                    <td className="px-3 py-3">{formatMoney(charge.vat_amount)}</td>
                                                    <td className="px-3 py-3 font-semibold">{formatMoney(charge.total_charge)}</td>
                                                    <td className="px-3 py-3 capitalize">{humanizeStatus(charge.status)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </DashboardPanel>

                        <Modal
                            isOpen={movementModalOpen}
                            onClose={() => setMovementModalOpen(false)}
                            size="7xl"
                            showCloseButton={false}
                            zIndex={1000000}
                            className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden !rounded-3xl"
                        >
                            <div className="flex max-h-[calc(100dvh-2rem)] flex-col p-5 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="platform-credit-movement-modal-heading">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <h2 id="platform-credit-movement-modal-heading" className="text-xl font-semibold text-gray-900 dark:text-white">Credit movement history</h2>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Review how each credit was applied to the Platform Balance.</p>
                                    </div>
                                    <button type="button" aria-label="Close movement history" onClick={() => setMovementModalOpen(false)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Close</button>
                                </div>

                                <div className="mt-4 min-h-0 flex-1 overflow-y-auto">
                                    <CreditMovementTable movements={visibleMovements} />
                                </div>

                                <div className="mt-4 flex items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-800">
                                    <button type="button" aria-label="Previous page" disabled={movementPage === 1} onClick={() => setMovementPage((current) => Math.max(1, current - 1))} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">Previous</button>
                                    <span className="text-sm text-gray-500 dark:text-gray-400" aria-live="polite">Page {movementPage} of {movementPageCount}</span>
                                    <button type="button" aria-label="Next page" disabled={movementPage === movementPageCount} onClick={() => setMovementPage((current) => Math.min(movementPageCount, current + 1))} className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">Next</button>
                                </div>
                            </div>
                        </Modal>
                    </>
                )}
            </DashboardShell>
        </AppLayoutERP>
    );
}

function MovementStat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3 dark:border-gray-800 dark:bg-gray-900/50">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
            <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{value}</p>
        </div>
    );
}

function CreditMovementTable({ movements }: { movements: PlatformCreditMovement[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm" aria-label="Credit movement history">
                <caption className="sr-only">Platform Fee credit movement history</caption>
                <thead className="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        <th className="px-3 py-3">Source</th>
                        <th className="px-3 py-3">Credit issued</th>
                        <th className="px-3 py-3">Applied</th>
                        <th className="px-3 py-3">Remaining</th>
                        <th className="px-3 py-3">Balance after</th>
                        <th className="px-3 py-3">Date</th>
                    </tr>
                </thead>
                <tbody>
                    {movements.map((movement) => (
                        <tr key={movement.id} className="border-b border-gray-100 last:border-0 dark:border-gray-900">
                            <td className="px-3 py-3 font-medium text-gray-900 dark:text-white">{sourceLabel(movement.source_type)} #{movement.source_id}</td>
                            <td className="px-3 py-3">{formatMoney(movement.credit_amount)}</td>
                            <td className="px-3 py-3 font-semibold text-emerald-700 dark:text-emerald-300">{formatMoney(movement.applied_amount)}</td>
                            <td className="px-3 py-3">{formatMoney(movement.remaining_amount)}</td>
                            <td className="px-3 py-3">{formatMoney(movement.outstanding_before_credit)} <span aria-hidden="true">→</span> {formatMoney(movement.outstanding_after_credit)}</td>
                            <td className="px-3 py-3">{movementDate(movement.last_applied_at ?? movement.created_at)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
