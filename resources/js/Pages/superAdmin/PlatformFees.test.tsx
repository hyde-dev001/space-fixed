import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import PlatformFeesPage from './PlatformFees';

const { pageProps, routerPost, swalFire } = vi.hoisted(() => ({
    pageProps: {
        shops: [{
            id: 1,
            name: 'Credit Movement Shop',
            shop_type: 'individual',
            reliability_score: '80.00',
            outstanding_balance: '28.00',
            available_credits: '0.00',
            net_payable: '28.00',
            balance_limit: '25000.00',
            utilization_percentage: '0.11',
            is_restricted: false,
            last_payment_at: null,
            active_request_status: null,
            recommendation: null,
            score_recommendation: { tier: 'tier_2', minimum_score: '50', recommended_limit: '35000.00' },
            credit_summary: {
                issued: '56.00',
                applied: '56.00',
                remaining: '0.00',
                outstanding_before_credits: '84.00',
                outstanding_after_credits: '28.00',
                outstanding_reduced_by_credits: '56.00',
            },
            credit_movements: Array.from({ length: 12 }, (_, index) => ({
                id: 12 - index,
                source_type: 'order_refund',
                source_id: 12 - index,
                source_origin: 'marketplace',
                credit_amount: '56.00',
                applied_amount: '56.00',
                remaining_amount: '0.00',
                status: 'applied',
                reason: 'Platform Fee paid before a marketplace refund.',
                created_at: '2026-09-21T10:00:00.000000Z',
                last_applied_at: '2026-09-21T10:05:00.000000Z',
                outstanding_before_credit: '84.00',
                outstanding_after_credit: '28.00',
            })),
        }],
        metrics: {
            platform_fee_earned: '125.00',
            total_billed: '0.00',
            collected: '0.00',
            outstanding: '0.00',
            pending_payments: 0,
        },
        settings: [],
        defaults: {
            platform_fee_rate: '5.000000',
            platform_fee_vat_enabled: true,
            platform_fee_vat_rate: '12.000000',
            warning_threshold_percentage: '80.0000',
            critical_threshold_percentage: '90.0000',
            enforcement_enabled: true,
            balance_limits: { individual: '25000.00', business: '50000.00' },
            reliability: {
                window_days: 180,
                version: 'v1',
                weights: {
                    payment_history: 35,
                    settlement_timeliness: 25,
                    marketplace_history: 15,
                    refund_performance: 10,
                    dispute_rate: 10,
                    account_activity: 5,
                },
                tiers: [
                    { key: 'base', minimum_score: 0, recommended_limit: null },
                    { key: 'tier_2', minimum_score: 50, recommended_limit: '35000.00' },
                ],
            },
        },
    },
    routerPost: vi.fn(),
    swalFire: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: { post: routerPost },
    usePage: () => ({ props: pageProps }),
}));

vi.mock('../../layout/AppLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('sweetalert2', () => ({
    default: { fire: swalFire },
}));

beforeEach(() => {
    pageProps.shops = [pageProps.shops[0]];
    routerPost.mockReset();
    swalFire.mockReset();
    swalFire.mockResolvedValue({ isConfirmed: true });
});

it('replaces reliability JSON fields with labeled controls and submits the same JSON contract', async () => {
    render(<PlatformFeesPage />);

    const summary = screen.getByRole('region', { name: 'Platform fee summary' });
    expect(summary.querySelectorAll('article')).toHaveLength(4);
    expect(summary.querySelector('article')).toHaveClass('metrics-card', 'border-gray-200', 'bg-white');
    expect(screen.getByRole('article', { name: 'Platform Fees Generated' })).toHaveTextContent('125.00');
    expect(screen.getByRole('article', { name: 'Platform Fees Generated' })).toHaveTextContent('Finalized charges less finalized reversals; confirmed payments are tracked separately.');
    expect(screen.queryByRole('article', { name: 'Total billed' })).not.toBeInTheDocument();
    expect(screen.getByRole('article', { name: 'Net Payable' })).toHaveTextContent('0.00');
    expect(screen.queryByRole('article', { name: 'Credits issued' })).not.toBeInTheDocument();
    expect(screen.queryByRole('article', { name: 'Credits applied' })).not.toBeInTheDocument();
    expect(screen.queryByRole('article', { name: 'Reduced by credits' })).not.toBeInTheDocument();
    expect(screen.queryByText('Snapshot')).not.toBeInTheDocument();

    const pageHeader = screen.getByRole('banner');
    const settingsButton = screen.getByRole('button', { name: 'Open fee settings' });
    expect(settingsButton).toHaveClass('rounded-xl');
    expect(pageHeader).toContainElement(settingsButton);
    expect(settingsButton).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open fee settings' }));
    expect(screen.getByRole('dialog').parentElement).toHaveClass('z-[1000000]');
    expect(screen.getByRole('heading', { name: 'Reliability scoring' })).toBeInTheDocument();
    const scopeTabs = screen.getByRole('button', { name: 'Platform' }).parentElement?.parentElement;
    expect(scopeTabs).toHaveClass('items-center', 'justify-between');
    const shopTypeTab = screen.getByRole('button', { name: 'Shop type' });
    expect(scopeTabs).toContainElement(shopTypeTab);
    fireEvent.click(shopTypeTab);
    const shopTypeTabs = screen.getByRole('button', { name: 'Individual' }).parentElement;
    expect(shopTypeTabs).toHaveClass('justify-end');
    expect(scopeTabs).toContainElement(screen.getByRole('button', { name: 'Individual' }));
    expect(scopeTabs).toContainElement(screen.getByRole('button', { name: 'Business' }));
    expect(screen.queryByRole('heading', { name: 'Shop Platform Balance overview' })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Payment history weight (%)')).toHaveValue(35);
    expect(screen.getByText('Total: 100%')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add tier' })).toBeInTheDocument();
    expect(screen.queryByText(/Reliability weights \(JSON/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Reliability tiers \(JSON/i)).not.toBeInTheDocument();
    const movementRegion = screen.getByRole('region', { name: 'Credit movement' });
    expect(movementRegion).toBeInTheDocument();
    const historyButton = screen.getByRole('button', { name: 'View credit movement history' });
    expect(historyButton).toHaveClass('rounded-xl');
    expect(pageHeader).toContainElement(historyButton);
    expect(historyButton).toBeInTheDocument();
    expect(movementRegion).not.toContainElement(historyButton);
    expect(screen.getByRole('region', { name: 'Fee settings' })).not.toContainElement(settingsButton);
    expect(movementRegion).not.toHaveTextContent('Credit Movement Shop');
    expect(screen.queryByRole('heading', { name: 'Audited balance adjustment' })).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Payment history weight (%)'), { target: { value: '40' } });
    fireEvent.change(screen.getByLabelText('Settlement timeliness weight (%)'), { target: { value: '20' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }));

    await waitFor(() => expect(routerPost).toHaveBeenCalledWith(
        '/admin/platform-fees/settings',
        expect.objectContaining({
            reliability_weights: JSON.stringify({
                payment_history: 40,
                settlement_timeliness: 20,
                marketplace_history: 15,
                refund_performance: 10,
                dispute_rate: 10,
                account_activity: 5,
            }),
            reliability_tiers: JSON.stringify([
                { key: 'base', minimum_score: 0, recommended_limit: null },
                { key: 'tier_2', minimum_score: 50, recommended_limit: '35000.00' },
            ]),
        }),
        expect.objectContaining({ preserveScroll: true }),
    ));

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('submits a per-shop balance limit change', async () => {
    render(<PlatformFeesPage />);

    fireEvent.click(screen.getByRole('button', { name: 'Change limit for Credit Movement Shop' }));
    expect(screen.getByText('Reliability score')).toBeInTheDocument();
    expect(screen.getByText('₱35000.00')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Use recommended limit' }));
    expect(screen.getByLabelText('New balance limit')).toHaveValue(35000);
    fireEvent.change(screen.getByLabelText('New balance limit'), { target: { value: '40000.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save balance limit' }));

    await waitFor(() => expect(routerPost).toHaveBeenCalledWith(
        '/admin/platform-fees/shops/1/limit',
        { balance_limit: '40000.00' },
        expect.objectContaining({ preserveScroll: true }),
    ));
});

it('keeps admin credit movement history in a paginated modal', () => {
    render(<PlatformFeesPage />);

    expect(screen.getByText('12 credit movements recorded across shops')).toBeInTheDocument();
    expect(screen.queryByText('Order refund #12')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'View credit movement history' }));
    expect(screen.getByRole('heading', { name: 'Credit movement history' })).toBeInTheDocument();
    expect(screen.getByText('Order refund #12')).toBeInTheDocument();
    expect(screen.queryByText('Order refund #1')).not.toBeInTheDocument();
    expect(screen.getByText('Page 1 of 2')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(screen.getByText('Order refund #1')).toBeInTheDocument();
    expect(screen.queryByText('Order refund #12')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Close credit movement history' }));
    expect(screen.queryByRole('heading', { name: 'Credit movement history' })).not.toBeInTheDocument();
});

it('paginates the shop balance overview', () => {
    const baseShop = pageProps.shops[0];
    pageProps.shops = Array.from({ length: 16 }, (_, index) => ({
        ...baseShop,
        id: index + 1,
        name: `Shop ${index + 1}`,
    }));

    render(<PlatformFeesPage />);

    expect(screen.getByText('Showing shops 1-15 of 16')).toBeInTheDocument();
    expect(screen.getByText('Shop 1')).toBeInTheDocument();
    expect(screen.queryByText('Shop 16')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Next shop page' }));

    expect(screen.getByText('Showing shops 16-16 of 16')).toBeInTheDocument();
    expect(screen.getByText('Shop 16')).toBeInTheDocument();
    expect(screen.queryByText('Shop 1')).not.toBeInTheDocument();
});
