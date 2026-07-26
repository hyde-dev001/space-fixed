import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import React from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { mockPage, mockSwalFire } = vi.hoisted(() => ({
  mockPage: {
    props: {
      auth: { user: { role: 'STAFF', roles: [] }, permissions: ['access-staff-job-orders'] },
      initialOrders: [] as any[],
    },
  },
  mockSwalFire: vi.fn(() => Promise.resolve({ isConfirmed: true })),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => mockPage,
}));
vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('../../../../components/common/ErrorModal', () => ({ default: () => null }));
vi.mock('sweetalert2', () => ({ default: { fire: mockSwalFire } }));

import JobOrdersPage from '../JobOrders';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/STAFF/JobOrders.tsx'),
  'utf8',
);

const makeOrder = (id: number) => ({
  id,
  order_number: `ORDER-${id}`,
  customer_name: `Customer ${id}`,
  customer_email: `customer${id}@example.test`,
  customer_phone: '09170000000',
  shipping_address: `${id} Test Street`,
  total_amount: 100,
  shipping_fee: 0,
  grand_total: 100,
  payment_status: 'paid',
  payment_method: 'cod',
  status: 'processing',
  created_at: '2026-07-22T00:00:00Z',
  items: [{ id, product_name: `Product ${id}`, quantity: 1, price: '100', subtotal: '100' }],
  shop_owned_coverage: {
    available: true,
    reason: null,
    distance_km: 1,
    coverage_radius_km: 20,
  },
});

const jsonResponse = (status: number, body: unknown) => ({
  ok: status >= 200 && status < 300,
  status,
  json: () => Promise.resolve(body),
});

beforeEach(() => {
  mockPage.props.initialOrders = [makeOrder(1), makeOrder(2)];
  mockSwalFire.mockClear();
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('staff order shipping coverage integration', () => {
  it('uses the shared paid shop-owned delivery revenue rule', () => {
    expect(source).toContain('calculateRetailRevenue({');
    expect(source).toContain('Products + paid shop-owned delivery, excl. VAT');
  });

  it('maps the backend shop-owned coverage contract to the order model', () => {
    expect(source).toMatch(/shopOwnedCoverage\?:\s*\{[\s\S]*available:\s*boolean;[\s\S]*reason:\s*string\s*\|\s*null;[\s\S]*distance_km:\s*number\s*\|\s*null;[\s\S]*coverage_radius_km:\s*number\s*\|\s*null;/);
    expect(source).toContain('shopOwnedCoverage: order.shop_owned_coverage || undefined');
  });

  it('defaults only eligible orders to shop-owned logistics and disables only that option', () => {
    expect(source).toContain('const shopOwnedEligible = order.shopOwnedCoverage?.available === true;');
    expect(source).toContain('existingCarrier === SHOP_OWNED_LOGISTICS && !shopOwnedEligible');
    expect(source).toContain('existingCarrier || (shopOwnedEligible ? SHOP_OWNED_LOGISTICS : "")');
    expect(source).toMatch(/<option value=\{SHOP_OWNED_LOGISTICS\} disabled=\{!shopOwnedEligible\}>/);
    expect(source).toContain('<option value="Lalamove">Lalamove</option>');
    expect(source).toContain('<option value="J&T">J&amp;T</option>');
    expect(source).toContain('<option value="Express Padala">Express Padala</option>');
  });

  it('announces plain-language coverage states with distance context and an icon', () => {
    expect(source).toContain('Outside shop-owned coverage:');
    expect(source).toContain('km away; coverage radius is');
    expect(source).toContain('Customer address must be pinned before shop-owned delivery can be used.');
    expect(source).toContain('Shop location needs configuration before shop-owned delivery can be used.');
    expect(source).toContain('Shop-owned logistics is currently unavailable.');
    expect(source).toContain('role="status"');
    expect(source).toContain('aria-live="polite"');
    expect(source).toContain('aria-hidden="true"');
    expect(source).not.toContain('{selectedOrder.shopOwnedCoverage.reason}');
  });

  it('guards stale shop-owned selections and refreshes coverage after a carrier validation response', () => {
    expect(source).toContain('usesShopOwnedLogistics && selectedOrder.shopOwnedCoverage?.available !== true');
    expect(source).toContain("'Accept': 'application/json'");
    expect(source).toContain('response.status === 422');
    expect(source).toContain('errorData?.errors?.carrier_company');
    expect(source).toContain('const coverageError = coverageErrors[0]');
    expect(source).toContain('text: coverageError');
    expect(source).toContain("await fetch('/api/staff/orders'");
    expect(source).toContain('setSelectedOrder(refreshedOrder)');
    expect(source).toContain('setCarrierCompany("")');
    expect(source).toMatch(/response\.json\(\)\.catch\(\(\) => null\)/);
  });

  it.each([
    ['late coverage rejection', 422, {
      message: 'Shop-owned logistics is unavailable for this delivery address.',
      errors: { carrier_company: ['Shop-owned logistics is unavailable for this delivery address.'] },
      shop_owned_coverage: { available: false, reason: 'outside_coverage', distance_km: 10, coverage_radius_km: 1 },
    }],
    ['late success', 200, { success: true }],
  ])('does not let order A %s mutate the order B modal', async (_label, status, body) => {
    let resolvePatch!: (response: ReturnType<typeof jsonResponse>) => void;
    const patchResponse = new Promise<ReturnType<typeof jsonResponse>>((resolve) => {
      resolvePatch = resolve;
    });
    const fetchMock = vi.fn((input: string, init?: RequestInit) => {
      if (input === '/api/csrf-token') return Promise.resolve(jsonResponse(200, { csrf_token: 'token' }));
      if (init?.method === 'PATCH') return patchResponse;
      if (input === '/api/staff/orders') return Promise.resolve(jsonResponse(200, [makeOrder(1), makeOrder(2)]));
      throw new Error(`Unexpected fetch: ${input}`);
    });
    vi.stubGlobal('fetch', fetchMock);

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Processing (2)' }));
    const shipButtons = await screen.findAllByRole('button', { name: 'Mark as shipped' });

    fireEvent.click(shipButtons[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm Shipping' }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/api/staff/orders/1/status',
      expect.objectContaining({ method: 'PATCH' }),
    ));
    const orderAModal = screen.getByRole('heading', { name: 'Ship Order' }).parentElement?.parentElement as HTMLElement;
    expect(within(orderAModal).getByRole('button', { name: 'Cancel' })).toBeDisabled();

    fireEvent.click(shipButtons[1]);
    let modal = screen.getByRole('heading', { name: 'Ship Order' }).parentElement?.parentElement as HTMLElement;
    expect(within(modal).getByText('Customer 2')).toBeInTheDocument();

    await act(async () => {
      resolvePatch(jsonResponse(status, body));
      await patchResponse;
    });

    await waitFor(() => {
      modal = screen.getByRole('heading', { name: 'Ship Order' }).parentElement?.parentElement as HTMLElement;
      expect(within(modal).getByText('Customer 2')).toBeInTheDocument();
      expect(within(modal).getByDisplayValue('Shop-owned logistics')).toBeInTheDocument();
    });
    expect(mockSwalFire).not.toHaveBeenCalled();
  });

  it('disables cancellation while shipping confirmation is pending', () => {
    expect(source).toMatch(/onClick=\{closeShippingModal\}\s+disabled=\{isConfirmingShipping\}[\s\S]{0,300}disabled:cursor-not-allowed[\s\S]{0,100}>\s*Cancel/);
  });
});
