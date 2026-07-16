import React from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MyOrders from '../MyOrders';

const order = {
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
};

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => <a href={href} {...props}>{children}</a>,
  router: { reload: vi.fn(), visit: vi.fn() },
  usePage: () => ({ props: { orders: [order] } }),
}));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('../../Shared/UserModal', () => ({ default: { fire: vi.fn() } }));

describe('MyOrders delivery tracking', () => {
  beforeEach(() => {
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      value: { getItem: vi.fn(() => null), setItem: vi.fn() },
    });
  });

  afterEach(() => vi.clearAllMocks());

  it('shows shop-owned delivery status, rider, and tracking action even before the order status is shipped', () => {
    render(<MyOrders />);

    expect(screen.getByText('In Transit')).toBeInTheDocument();
    expect(screen.getByText('Marco Santos')).toBeInTheDocument();
    expect(screen.getByText('09053338826')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Track Shipment' })).toHaveAttribute('href', '/tracking/shipments/12');
  });
});
