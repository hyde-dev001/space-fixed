import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Message from '../message';

const mocks = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { auth: { user: { id: 2, name: 'Customer' } } } }),
  router: { visit: mocks.visit },
}));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));

const conversation = {
  id: 1, shop_owner_id: 3, customer_id: 2, status: 'open',
  last_message_at: '2026-10-13T10:00:00Z',
  shopOwner: { id: 3, business_name: 'Kicks Store' },
  order: { id: 4, order_number: 'ORD-4', status: 'delivered' },
};

let content: string;
let orderItems: { product_name: string; quantity: number; product_image: string | null }[];

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: vi.fn() });
  vi.spyOn(console, 'log').mockImplementation(() => {});
  content = '**Order Report Submitted**\n\n**Order Number:** ORD-4\n**Reason:** Damaged item\n**Status:** Pending dispatcher investigation';
  orderItems = [
    { product_name: 'Nike Shoes', quantity: 1, product_image: '/storage/products/nike.jpg' },
  ];
  vi.stubGlobal('fetch', vi.fn(async (url: string) => ({
    ok: true,
    status: 200,
    headers: new Headers(),
    json: async () => url === '/api/customer/conversations' ? [conversation]
      : url === '/api/my-orders' ? { orders: [{ ...conversation.order, items: orderItems }] }
      : [{ id: 5, conversation_id: 1, sender_id: 3, sender_type: 'system', content, created_at: conversation.last_message_at }],
  })));
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('customer order message cards', () => {
  it.each(['Pending dispatcher investigation', 'Pending'])('shows product photos and Pending for a report stored as %s', async (storedStatus) => {
    content = content.replace('Pending dispatcher investigation', storedStatus);
    render(<Message />);
    await waitFor(() => expect(screen.getByText('Order Report Submitted')).toBeInTheDocument());
    expect(screen.getByText('Reason:')).toBeInTheDocument();
    expect(screen.getByText('Damaged item')).toBeInTheDocument();
    expect(screen.getByText('Order Number:')).toBeInTheDocument();
    expect(screen.getByText('ORD-4')).toBeInTheDocument();
    expect(screen.getByText('Pending')).toBeInTheDocument();
    expect(screen.queryByText('Pending dispatcher investigation')).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('img', { name: 'Nike Shoes' })).toHaveAttribute('src', '/storage/products/nike.jpg'));
    expect(screen.queryByText('delivered')).not.toBeInTheDocument();
    expect(screen.getByText('Your order report has been received. The dispatcher will investigate the issue.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'View Full Details' }));
    expect(mocks.visit).toHaveBeenCalledWith('/my-orders');
  });

  it('keeps each product image matched to its item when an image is missing', async () => {
    orderItems = [
      { product_name: 'Missing photo shoes', quantity: 2, product_image: null },
      { product_name: 'Adidas Shoes', quantity: 1, product_image: 'storage/products/adidas.jpg' },
    ];
    render(<Message />);
    await waitFor(() => expect(screen.getByText('Missing photo shoes')).toBeInTheDocument());
    expect(screen.queryByRole('img', { name: 'Missing photo shoes' })).not.toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Adidas Shoes' })).toHaveAttribute('src', '/storage/products/adidas.jpg');
    expect(screen.getByText('Qty: 2')).toBeInTheDocument();
  });

  it('does not use a different orders photos from the same conversation', async () => {
    content = content.replace('ORD-4', 'ORD-OTHER');
    render(<Message />);
    await waitFor(() => expect(screen.getByText('Order Report Submitted')).toBeInTheDocument());
    expect(screen.getByText('Pending')).toBeInTheDocument();
    expect(screen.queryByRole('img', { name: 'Nike Shoes' })).not.toBeInTheDocument();
    expect(screen.queryByText('Products:')).not.toBeInTheDocument();
  });

  it('preserves the new order card and live delivery status', async () => {
    content = '**New Order Placed**\n\n**Order Number:** ORD-4\n**Items:** Nike Shoes x1\n**Total:** ₱1,000.00\n**Status:** Pending Payment';
    render(<Message />);
    await waitFor(() => expect(screen.getByText('New Order Placed')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByText('delivered')).toBeInTheDocument());
    expect(screen.getByText('Nike Shoes')).toBeInTheDocument();
    expect(screen.queryByText('Reason:')).not.toBeInTheDocument();
  });
});
