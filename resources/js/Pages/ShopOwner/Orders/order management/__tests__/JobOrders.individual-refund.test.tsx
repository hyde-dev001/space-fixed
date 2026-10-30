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


it.each([false, true])('opens and reopens return pickup directly from the list (warranty: %s)', async (hasWarranty) => {
  const warranty = hasWarranty ? {
    id: 22, reference: 'WARRANTY-OWNER-RETURN', order_number: 'OWNER-RETURN', customer_name: 'Buyer',
    shop_name: 'Shop', issued_at: '2026-10-29T08:00:00Z', fulfilled_at: '2026-10-29T08:00:00Z',
    timezone: 'Asia/Singapore', status: 'active', download_url: null, items: [],
  } : null;
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => [{
    id: 2, order_number: 'OWNER-RETURN', customer_name: 'Return Buyer', customer_email: 'return@example.test',
    total_amount: 1000, grand_total: 1000, status: 'delivered', payment_status: 'paid', items: [],
    created_at: '2026-10-29', product_warranty: warranty, latest_refund: {
      id: 10, status: 'requested', flow_type: 'request_approval', shop_owner_status: 'approved',
      finance_status: 'pending', approvals_complete: true, return_status: 'pending_customer_shipment',
      can_execute_payout: false,
    },
  }] }));
  render(<JobOrders />);
  fireEvent.click(await screen.findByRole('button', { name: /All Orders/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Arrange return pickup' }));
  expect(screen.getByRole('heading', { name: 'Arrange Return Pickup' })).toBeInTheDocument();
  expect(screen.getByText('OWNER-RETURN')).toBeInTheDocument();
  if (hasWarranty) expect(screen.getByText('WARRANTY-OWNER-RETURN')).toBeInTheDocument();
  else expect(screen.queryByRole('region', { name: 'Product Warranty' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Close', exact: true }));
  expect(screen.queryByRole('heading', { name: 'Arrange Return Pickup' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Arrange return pickup' }));
  expect(screen.getByRole('heading', { name: 'Arrange Return Pickup' })).toBeInTheDocument();
});

it('shows backend return progress separately from fulfillment', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => [{
    id: 4, order_number: 'RETURN-4', customer_name: 'Buyer', total_amount: 100, grand_total: 100,
    status: 'shipped', payment_status: 'paid', items: [], created_at: '2026-10-30',
    owner_projection: { fulfillment_status: 'shipped', business_closed: false, blockers: ['fulfillment'],
      parcel_recovery_label: 'Returned parcel received; item handling pending' },
  }] }));
  render(<JobOrders />);
  fireEvent.click(await screen.findByRole('button', { name: /All Orders/ }));
  expect(await screen.findByText('Returned parcel received; item handling pending')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Confirm Parcel Received' })).not.toBeInTheDocument();
});
