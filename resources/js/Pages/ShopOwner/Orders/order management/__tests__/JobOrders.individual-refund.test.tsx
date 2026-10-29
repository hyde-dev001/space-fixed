import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import JobOrders from '../JobOrders';

vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => ({ props: { auth: { shop_owner: { registration_type: 'individual' } } } }) }));
vi.mock('@/layout/AppLayout_shopOwner', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
afterEach(() => vi.unstubAllGlobals());

it.each([
  ['pending_customer_shipment', 'Awaiting Return Shipment'],
  ['received', 'Ready for Refund Payout'],
])('shows the real next step for an approved individual refund: %s', async (returnStatus, label) => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => [{
    id: 1, order_number: 'OWNER-1', customer_name: 'Buyer', customer_email: 'buyer@example.test',
    total_amount: 1000, grand_total: 1000, status: 'delivered', payment_status: 'paid', items: [],
    created_at: '2026-10-29', latest_refund: {
      id: 9, status: 'requested', flow_type: 'request_approval', shop_owner_status: 'approved',
      finance_status: 'pending', approvals_complete: true, return_status: returnStatus,
      can_execute_payout: returnStatus === 'received',
    },
  }] }));
  render(<JobOrders />);
  fireEvent.click(await screen.findByRole('button', { name: /All Orders/ }));
  expect(await screen.findByText(label)).toBeInTheDocument();
  expect(screen.queryByText('Awaiting Approval')).not.toBeInTheDocument();
});
