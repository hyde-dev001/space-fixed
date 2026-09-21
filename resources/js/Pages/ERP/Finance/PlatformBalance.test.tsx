import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import PlatformBalancePage from './PlatformBalance';

const { apiGet, apiPost, queryRefetch, payload } = vi.hoisted(() => ({
    apiGet: vi.fn(),
    apiPost: vi.fn(),
    queryRefetch: vi.fn(),
    payload: {
        balance: {
            outstanding_balance: '28.00',
            available_credits: '0.00',
            net_payable: '28.00',
            balance_limit: '25000.00',
            utilization_percentage: '0.11',
            warning_threshold_percentage: '80.0000',
            critical_threshold_percentage: '90.0000',
            enforcement_enabled: true,
            shop_type: 'individual',
            is_restricted: false,
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
                credit_amount: '5.00',
                applied_amount: '5.00',
                remaining_amount: '0.00',
                status: 'applied',
                reason: 'Refund credit',
                created_at: '2026-09-21T10:00:00.000000Z',
                last_applied_at: '2026-09-21T10:05:00.000000Z',
                outstanding_before_credit: '84.00',
                outstanding_after_credit: '79.00',
            })),
        },
        charges: [],
        payment_requests: [],
        reliability: { latest: null, history: [] },
        terms: { version: '', text: '', accepted: false, accepted_at: null },
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@tanstack/react-query', () => ({
    useQuery: () => ({
        data: payload,
        isLoading: false,
        isError: false,
        error: null,
        isFetching: false,
        refetch: queryRefetch,
    }),
}));

vi.mock('../../../hooks/useFinanceApi', () => ({
    useFinanceApi: () => ({
        get: apiGet,
        post: apiPost,
        ownerMode: true,
    }),
}));

vi.mock('../../../layout/AppLayout_ERP', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('sweetalert2', () => ({
    default: { fire: vi.fn().mockResolvedValue({ isConfirmed: false }) },
}));

beforeEach(() => {
    apiGet.mockReset();
    apiPost.mockReset();
    queryRefetch.mockReset();
});

it('keeps long credit movement history in a paginated modal', () => {
    render(<PlatformBalancePage />);

    expect(screen.getByRole('article', { name: 'Outstanding Charges' })).toHaveTextContent('Unpaid finalized charges before unused credits; applied credits are already reflected.');
    expect(screen.getByRole('article', { name: 'Available Credits' })).toHaveTextContent('Unused refund/reversal credits available to reduce this balance.');
    expect(screen.getByRole('article', { name: 'Net Payable' })).toHaveTextContent('Outstanding Charges less Available Credits, never below zero.');
    expect(screen.getByText(/Net payable = max\(0, Outstanding Charges - Available Credits\)\./)).toBeInTheDocument();
    expect(screen.getByText('12 credit movements recorded')).toBeInTheDocument();
    expect(screen.queryByText('Order refund #12')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'View movement history' }));

    expect(screen.getByRole('dialog', { name: 'Credit movement history' })).toBeInTheDocument();
    expect(screen.getByText('Order refund #12')).toBeInTheDocument();
    expect(screen.queryByText('Order refund #1')).not.toBeInTheDocument();
    expect(screen.getByText('Page 1 of 2')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));

    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
    expect(screen.getByText('Order refund #1')).toBeInTheDocument();
    expect(screen.queryByText('Order refund #12')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Close movement history' }));
    expect(screen.queryByRole('dialog', { name: 'Credit movement history' })).not.toBeInTheDocument();
});
