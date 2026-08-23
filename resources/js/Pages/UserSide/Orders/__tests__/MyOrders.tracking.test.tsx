import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MyOrders from '../MyOrders';

const order: any = {
  id: 7,
  order_number: 'BATCH-MIGUEL-1',
  status: 'processing',
  payment_status: 'paid',
  payment_method: 'cash_on_delivery',
  total_amount: 2499,
  grand_total: 2499,
  created_at: '2026-07-18 08:00:00',
  shop_name: 'Urban Kicks Store',
  items_count: 1,
  items: [{
    id: 1,
    product_name: 'Urban Kicks Test Runner',
    product_slug: 'urban-kicks-test-runner',
    product_image: '',
    price: 2499,
    quantity: 1,
    subtotal: 2499,
  }],
  carrier_company: 'Shop-owned logistics',
  logistics_shipment_id: 12,
  is_shop_owned_delivery: true,
  delivery_status: 'in_transit',
  delivery_tracking_number: 'SHP-TRACK-1001',
  delivery_rider_name: 'Marco Santos',
  delivery_rider_phone: '09053338826',
  delivery_reference: 'SHP-12',
  delivery_has_failed_attempt: false,
  delivery_scheduled_date: '2026-07-18',
  delivery_window: 'morning',
};

const trackingPayload = {
  shipment: {
    id: 12,
    purpose: 'retail_delivery',
    status: 'active',
    source_type: 'order',
    legs: [{
      id: 22,
      sequence: 1,
      leg_type: 'outbound',
      status: 'in_transit',
      scheduled_delivery_date: '2026-07-18',
      delivery_window: 'morning',
      origin_snapshot: { name: 'Urban Kicks Store', address: 'Cavite' },
      destination_snapshot: { name: 'Mia Santos', address: 'Cavite' },
    }],
    events: [],
  },
};

const fetchMock = vi.fn();
const swalFireMock = vi.hoisted(() => vi.fn());
const pageProps = { props: { orders: [order] } };

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => <a href={href} {...props}>{children}</a>,
  router: { reload: vi.fn(), visit: vi.fn() },
  usePage: () => pageProps,
}));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('../../Shared/UserModal', () => ({ default: { fire: swalFireMock } }));

describe('MyOrders delivery tracking', () => {
  beforeEach(() => {
    swalFireMock.mockReset();
    Object.assign(order, {
      status: 'processing',
      payment_method: 'cash_on_delivery',
      refund_stage: null,
      review_submitted: false,
      carrier_company: 'Shop-owned logistics',
      is_shop_owned_delivery: true,
      delivery_has_failed_attempt: false,
      delivery_scheduled_date: '2026-07-18',
      delivery_window: 'morning',
      eta: null,
    });
    fetchMock.mockResolvedValue({
      ok: true,
      json: () => Promise.resolve(trackingPayload),
    });
    vi.stubGlobal('fetch', fetchMock);
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      value: { getItem: vi.fn(() => null), setItem: vi.fn() },
    });
  });

  afterEach(() => {
    vi.clearAllMocks();
    vi.unstubAllGlobals();
  });

  it('opens outbound tracking in a modal without navigating away', async () => {
    render(<MyOrders />);

    expect(screen.getByText('In Transit')).toBeInTheDocument();
    expect(screen.getByText('Marco Santos')).toBeInTheDocument();
    expect(screen.getByText('09053338826')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Track Shipment' }));

    expect(await screen.findByRole('dialog', { name: 'Shipment tracking' })).toBeInTheDocument();
    expect(screen.getByText('SHP-12')).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(
      '/tracking/shipments/12',
      expect.objectContaining({
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      }),
    );
    expect(screen.getByText(/July 18, 2026.*Morning/)).toBeInTheDocument();
  });

  it('opens return tracking in the same modal with the return shipment id', async () => {
    order.status = 'delivered';
    order.refund_stage = {
      id: 5,
      logistics_shipment_id: 18,
      status: 'requested',
      shop_owner_status: 'pending',
      finance_status: 'pending',
      return_status: 'awaiting_approval',
    };

    render(<MyOrders />);
    fireEvent.click(screen.getByRole('button', { name: 'Track Return' }));

    expect(await screen.findByRole('dialog', { name: 'Shipment tracking' })).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(
      '/tracking/shipments/18',
      expect.objectContaining({ headers: { Accept: 'application/json' } }),
    );
  });

  it('submits a shop-owned return request without asking the customer for tracking details', async () => {
    order.status = 'delivered';
    order.refund_stage = {
      id: 5,
      status: 'processing',
      shop_owner_status: 'approved',
      finance_status: 'approved',
      return_status: 'pending_customer_shipment',
      can_mark_return_shipped: true,
    };
    swalFireMock
      .mockResolvedValueOnce({ isConfirmed: true, value: 'shop_owned' })
      .mockResolvedValueOnce({ isConfirmed: true, value: 'Pickup from my delivery address.' });
    fetchMock.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        refund: {
          return_status: 'pending_staff_pickup',
          return_source: 'staff',
          staff_return_carrier: 'Shop-owned logistics',
          logistics_shipment_id: 18,
        },
      }),
    });

    render(<MyOrders />);
    fireEvent.click(screen.getByRole('button', { name: 'SHIP RETURNED ITEM' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/orders/refunds/5/mark-shipped-return',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          delivery_method: 'shop_owned',
          note: 'Pickup from my delivery address.',
        }),
      }),
    ));
  });

  it('keeps the third-party return tracking flow', async () => {
    order.status = 'delivered';
    order.refund_stage = {
      id: 5,
      status: 'processing',
      shop_owner_status: 'approved',
      finance_status: 'approved',
      return_status: 'pending_customer_shipment',
      can_mark_return_shipped: true,
    };
    swalFireMock
      .mockResolvedValueOnce({ isConfirmed: true, value: 'third_party' })
      .mockResolvedValueOnce({ isConfirmed: true, value: 'TRK-THIRD-PARTY-001' })
      .mockResolvedValueOnce({ isConfirmed: true, value: 'J&T' })
      .mockResolvedValueOnce({ isConfirmed: true, value: 'Dropped off at branch.' });
    fetchMock.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        refund: {
          return_status: 'in_transit',
          return_source: 'customer',
          customer_return_tracking_number: 'TRK-THIRD-PARTY-001',
          customer_return_carrier: 'J&T',
        },
      }),
    });

    render(<MyOrders />);
    fireEvent.click(screen.getByRole('button', { name: 'SHIP RETURNED ITEM' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/orders/refunds/5/mark-shipped-return',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          delivery_method: 'third_party',
          note: 'Dropped off at branch.',
          tracking_number: 'TRK-THIRD-PARTY-001',
          carrier: 'J&T',
        }),
      }),
    ));
  });

  it('flags a failed attempt and links to its shipment details', () => {
    order.delivery_has_failed_attempt = true;
    render(<MyOrders />);

    expect(screen.getByText('Failed delivery attempt')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View attempt details' })).toHaveAttribute('href', '/tracking/shipments/12');
  });

  it('shows not scheduled when the date is missing even if a window exists', () => {
    order.delivery_scheduled_date = null;
    order.delivery_window = 'afternoon';
    render(<MyOrders />);

    expect(screen.getByText('Not scheduled yet')).toBeInTheDocument();
    expect(screen.queryByText('Afternoon')).not.toBeInTheDocument();
  });

  it('keeps the existing estimate for third-party delivery', () => {
    order.is_shop_owned_delivery = false;
    order.carrier_company = 'External Courier';
    order.eta = 'Courier ETA';
    render(<MyOrders />);

    expect(screen.getByText('Courier ETA')).toBeInTheDocument();
    expect(screen.queryByText(/July 18, 2026/)).not.toBeInTheDocument();
  });

  it('reveals the online-payment refund explanation on keyboard focus', () => {
    order.status = 'completed';
    order.carrier_company = 'Third-party Logistics';
    order.is_shop_owned_delivery = false;

    render(<MyOrders />);

    const eligibilityTrigger = screen.getByRole('group', { name: 'Refund eligibility information' });
    fireEvent.focus(eligibilityTrigger);

    expect(screen.getByRole('tooltip')).toHaveTextContent(
      'Only online-paid orders are eligible for refund requests.',
    );

    fireEvent.blur(eligibilityTrigger, { relatedTarget: document.body });
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    fireEvent.mouseEnter(eligibilityTrigger);
    expect(screen.getByRole('tooltip')).toHaveTextContent(
      'Only online-paid orders are eligible for refund requests.',
    );
    fireEvent.mouseLeave(eligibilityTrigger);
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('requires report evidence and hides the direct refund action for shop-owned delivery', async () => {
    Object.assign(order, {
      status: 'delivered',
      payment_method: 'cash_on_delivery',
      customer_receipt_status: 'pending',
      can_report_delivery_issue: true,
      active_delivery_dispute: null,
      refund_stage: null,
    });
    const reportResponse = {
      ok: true,
      json: vi.fn(() => Promise.resolve({
        dispute: {
          id: 91,
          status: 'open',
          reason: 'damaged',
          reported_at: '2026-07-18T10:00:00Z',
        },
      })),
    };
    fetchMock.mockResolvedValueOnce(reportResponse);

    render(<MyOrders />);
    expect(screen.queryByRole('button', { name: 'REFUND', exact: true })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'REPORT ORDER', exact: true }));
    expect(screen.getByRole('dialog', { name: 'Report Order' })).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Report reason'), { target: { value: 'damaged' } });
    const files = [
      new File(['1'], 'proof-1.jpg', { type: 'image/jpeg' }),
      new File(['2'], 'proof-2.jpg', { type: 'image/jpeg' }),
      new File(['3'], 'proof-3.jpg', { type: 'image/jpeg' }),
      new File(['4'], 'proof-4.jpg', { type: 'image/jpeg' }),
      new File(['5'], 'proof-5.jpg', { type: 'image/jpeg' }),
      new File(['video'], 'opening.mp4', { type: 'video/mp4' }),
    ];
    fireEvent.change(screen.getByLabelText('Report evidence files'), { target: { files } });

    expect(screen.getByRole('button', { name: 'Submit Report', exact: true })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Submit Report', exact: true }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/orders/7/delivery-disputes',
      expect.objectContaining({ method: 'POST', body: expect.any(FormData) }),
    ));
    const request = fetchMock.mock.calls.find(([url]) => url === '/orders/7/delivery-disputes');
    const formData = request?.[1]?.body as FormData;
    expect(formData.get('reason')).toBe('damaged');
    expect(formData.getAll('media[]')).toHaveLength(0);
    expect(formData.get('media[0]')).toBe(files[0]);
    expect(formData.get('media[5]')).toBe(files[5]);
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Report Order' })).not.toBeInTheDocument());
    expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({ title: 'Report Submitted' }));
    expect(await screen.findByText(/REPORT SUBMITTED/)).toBeInTheDocument();
  });

  it('keeps the direct refund action visible for third-party delivery', () => {
    Object.assign(order, {
      status: 'delivered',
      payment_method: 'paymongo_card',
      payment_status: 'paid',
      carrier_company: 'Third-party Logistics',
      is_shop_owned_delivery: false,
      can_report_delivery_issue: false,
      refund_stage: null,
    });

    render(<MyOrders />);

    expect(screen.getByRole('button', { name: 'REFUND', exact: true })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'REPORT ORDER', exact: true })).not.toBeInTheDocument();
  });
});
