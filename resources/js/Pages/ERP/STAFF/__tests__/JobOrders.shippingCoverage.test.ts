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
  it('hides receive activation for shipped shop-owned orders but keeps it for third-party orders', async () => {
    const shopOwnedOrder = { ...makeOrder(31), status: 'shipped', carrier_company: 'Shop-owned logistics' };
    const thirdPartyOrder = { ...makeOrder(32), status: 'shipped', carrier_company: 'J&T' };
    mockPage.props.initialOrders = [shopOwnedOrder, thirdPartyOrder];
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(jsonResponse(200, [shopOwnedOrder, thirdPartyOrder]))));

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Shipped (2)' }));
    const viewButtons = await screen.findAllByTitle('View order details');

    fireEvent.click(viewButtons[0]);
    expect(screen.queryByRole('button', { name: 'Activate Receive' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Close'));

    fireEvent.click(viewButtons[1]);
    expect(screen.getByRole('button', { name: 'Activate Receive' })).toBeInTheDocument();
  });

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

  it('only selects pending orders for bulk processing', async () => {
    mockPage.props.initialOrders = [
      { ...makeOrder(1), status: 'pending' },
      { ...makeOrder(2), status: 'delivered' },
    ];
    vi.stubGlobal('fetch', vi.fn((input: string) => {
      if (input === '/api/staff/orders') {
        return Promise.resolve(jsonResponse(200, mockPage.props.initialOrders));
      }
      throw new Error(`Unexpected fetch: ${input}`);
    }));

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'All Orders (2)' }));

    const pendingCheckbox = screen.getByRole('checkbox', { name: 'Select order ORDER-1' });
    const deliveredCheckbox = screen.getByRole('checkbox', { name: 'Select order ORDER-2' });
    expect(pendingCheckbox).toBeEnabled();
    expect(deliveredCheckbox).toBeDisabled();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Select all orders on this page' }));

    await waitFor(() => expect(screen.getByText('1 order selected')).toBeInTheDocument());
    expect(pendingCheckbox).toBeChecked();
    expect(deliveredCheckbox).not.toBeChecked();
  });

  it('keeps an order pending when bulk processing is rejected', async () => {
    mockPage.props.initialOrders = [{ ...makeOrder(1), status: 'pending' }];
    const fetchMock = vi.fn((input: string, init?: RequestInit) => {
      if (input === '/api/csrf-token') return Promise.resolve(jsonResponse(200, { csrf_token: 'token' }));
      if (init?.method === 'PATCH') {
        return Promise.resolve(jsonResponse(409, {
          message: 'The order status changed. Refresh and try again.',
        }));
      }
      if (input === '/api/staff/orders') {
        return Promise.resolve(jsonResponse(200, mockPage.props.initialOrders));
      }
      throw new Error(`Unexpected fetch: ${input}`);
    });
    vi.stubGlobal('fetch', fetchMock);

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select order ORDER-1' }));
    fireEvent.click(screen.getByRole('button', { name: 'Mark as Processing' }));

    await waitFor(() => expect(mockSwalFire).toHaveBeenCalledWith(
      'Error',
      'The order status changed. Refresh and try again.',
      'error',
    ));
    expect(screen.getByRole('button', { name: 'Pending (1)' })).toBeInTheDocument();
  });
});

describe('staff delivered order refresh', () => {
  it('shows completed backend orders in the Delivered tab', async () => {
    const completedOrder = { ...makeOrder(19), status: 'completed' };
    mockPage.props.initialOrders = [completedOrder];
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(jsonResponse(200, [completedOrder]))));

    render(React.createElement(JobOrdersPage));

    expect(await screen.findByRole('button', { name: 'Delivered (1)' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Shipped (0)' })).toBeInTheDocument();
  });

  it('refreshes a shipped order when the browser window regains focus', async () => {
    const shippedOrder = { ...makeOrder(19), status: 'shipped' };
    const completedOrder = { ...shippedOrder, status: 'completed' };
    mockPage.props.initialOrders = [shippedOrder];
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse(200, [shippedOrder]))
      .mockResolvedValueOnce(jsonResponse(200, [completedOrder]));
    vi.stubGlobal('fetch', fetchMock);

    render(React.createElement(JobOrdersPage));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(screen.getByRole('button', { name: 'Shipped (1)' })).toBeInTheDocument();

    await act(async () => {
      window.dispatchEvent(new Event('focus'));
    });

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('button', { name: 'Delivered (1)' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Shipped (0)' })).toBeInTheDocument();
  });
});

describe('staff refund visibility', () => {
  const makeRefundOrder = () => ({
    ...makeOrder(41),
    status: 'refund',
    payment_status: 'refunded',
    total_amount: 2231.25,
    shipping_fee: 108,
    vat_amount: 267.75,
    grand_total: 2607,
    logistics: {
      shipment_id: 81,
      shipment_status: 'completed',
      leg_id: 82,
      leg_type: 'outbound',
      leg_status: 'delivered',
      carrier: 'Shop-owned logistics',
      rider_name: 'Marco Santos',
      rider_phone: '09171234567',
      tracking_number: 'DELIVERY-81',
      tracking_url: 'https://example.test/deliveries/81',
      proofs: [],
    },
    latest_refund: {
      id: 71,
      status: 'succeeded',
      reason_code: 'product_defective_or_damaged',
      shop_owner_status: 'approved',
      finance_status: 'approved',
      return_status: 'received',
      flow_type: 'request_approval',
      payout_amount_value: 2499,
      evidence_media: ['/storage/refunds/customer-evidence.jpg'],
      items: [{
        order_item_id: 41,
        product_name: 'Product 41',
        requested_qty: 1,
        approved_qty: 1,
        line_amount: 2499,
      }],
      return_logistics: {
        shipment_id: 91,
        shipment_status: 'completed',
        leg_id: 92,
        leg_type: 'return_to_shop',
        leg_status: 'delivered',
        carrier: 'Shop-owned logistics',
        rider_name: 'Paolo Mendoza',
        rider_phone: '09179876543',
        tracking_number: 'RETURN-91',
        tracking_url: 'https://example.test/returns/91',
        proofs: [{
          id: 93,
          handoff_type: 'return',
          proof_type: 'photo',
          file_url: '/api/logistics/proofs/93/file',
        }],
      },
    },
  });

  it('always shows a logistics empty state when no shipment exists', async () => {
    const order = makeOrder(42);
    mockPage.props.initialOrders = [order];
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(jsonResponse(200, [order]))));

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Processing (1)' }));
    fireEvent.click((await screen.findAllByTitle('View order details'))[0]);

    expect(screen.getByText('Logistics')).toBeInTheDocument();
    expect(screen.getByText('No logistics shipment yet.')).toBeInTheDocument();
  });

  it('shows a shipping-excluded full payout as Refunded', async () => {
    const order = makeRefundOrder();
    mockPage.props.initialOrders = [order];
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(jsonResponse(200, [order]))));

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Refund (1)' }));

    expect(await screen.findByText('Refunded')).toBeInTheDocument();
    expect(screen.queryByText('Partially Refunded')).not.toBeInTheDocument();
  });

  it('shows customer evidence and return logistics proof in order details', async () => {
    const order = makeRefundOrder();
    mockPage.props.initialOrders = [order];
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(jsonResponse(200, [order]))));

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Refund (1)' }));
    fireEvent.click((await screen.findAllByTitle('View order details'))[0]);

    expect(screen.getByText('Refund Evidence')).toBeInTheDocument();
    expect(screen.getByAltText('Refund evidence 1')).toHaveAttribute('src', '/storage/refunds/customer-evidence.jpg');
    expect(screen.getByText('Logistics')).toBeInTheDocument();
    expect(screen.getByText('Customer delivery')).toBeInTheDocument();
    expect(screen.getByText('Return to shop')).toBeInTheDocument();
    expect(screen.getByText('81')).toBeInTheDocument();
    expect(screen.getByText('91')).toBeInTheDocument();
    expect(screen.getByText('Received')).toBeInTheDocument();
    expect(screen.getAllByText('Shop-owned logistics')).toHaveLength(2);
    expect(screen.getByText('Marco Santos')).toBeInTheDocument();
    expect(screen.getByText('09171234567')).toBeInTheDocument();
    expect(screen.getByText('Paolo Mendoza')).toBeInTheDocument();
    expect(screen.getByText('RETURN-91')).toBeInTheDocument();
    const proofTrigger = screen.getByRole('button', { name: 'Return delivery proof 1' });
    expect(proofTrigger).toBeInTheDocument();
    fireEvent.click(proofTrigger);
    expect(screen.getByRole('dialog', { name: 'Delivery proof image' })).toBeInTheDocument();
    expect(screen.getByAltText('Enlarged delivery proof')).toHaveAttribute('src', '/api/logistics/proofs/93/file');
    fireEvent.click(screen.getByRole('button', { name: 'Close delivery proof image' }));
    expect(screen.queryByRole('dialog', { name: 'Delivery proof image' })).not.toBeInTheDocument();
    expect(screen.getByText('No proof submitted yet.')).toBeInTheDocument();
  });

  it('shows a shipment even before its delivery leg is created', async () => {
    const order = {
      ...makeOrder(43),
      logistics: {
        shipment_id: 83,
        shipment_status: 'requested',
        leg_id: null,
        leg_type: null,
        leg_status: null,
        carrier: null,
        rider_name: null,
        rider_phone: null,
        tracking_number: null,
        tracking_url: null,
        proofs: [],
      },
    };
    mockPage.props.initialOrders = [order];
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(jsonResponse(200, [order]))));

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Processing (1)' }));
    fireEvent.click((await screen.findAllByTitle('View order details'))[0]);

    expect(screen.getByText('83')).toBeInTheDocument();
    expect(screen.getByText('Leg not created yet')).toBeInTheDocument();
    expect(screen.getAllByText('Not assigned').length).toBeGreaterThan(0);
  });

  it('closes stale order details after confirming a returned item', async () => {
    const order = {
      ...makeRefundOrder(),
      payment_status: 'paid',
      latest_refund: {
        ...makeRefundOrder().latest_refund,
        status: 'processing',
        return_status: 'in_transit',
      },
    };
    mockPage.props.initialOrders = [order];
    let confirmed = false;
    const fetchMock = vi.fn((input: string, init?: RequestInit) => {
      if (input === '/api/csrf-token') return Promise.resolve(jsonResponse(200, { csrf_token: 'token' }));
      if (input === '/api/staff/orders/41/confirm-return-received' && init?.method === 'POST') {
        confirmed = true;
        return Promise.resolve(jsonResponse(200, { message: 'Return received.' }));
      }
      if (input === '/api/staff/orders') return Promise.resolve(jsonResponse(200, confirmed ? [] : [order]));
      throw new Error(`Unexpected fetch: ${input}`);
    });
    vi.stubGlobal('fetch', fetchMock);

    render(React.createElement(JobOrdersPage));
    fireEvent.click(await screen.findByRole('button', { name: 'Refund (1)' }));
    fireEvent.click((await screen.findAllByTitle('View order details'))[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm Return Received' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/api/staff/orders/41/confirm-return-received',
      expect.objectContaining({ method: 'POST' }),
    ));
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Order Details' })).not.toBeInTheDocument());
  });
});
