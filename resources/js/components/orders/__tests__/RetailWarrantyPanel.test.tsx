import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RetailWarrantyPanel from '../RetailWarrantyPanel';

const warranty = { id: 1, reference: 'WRNTY-2026-OPAQUE', order_number: 'SS-1001', customer_name: 'Buyer', shop_name: 'Shop',
  issued_at: '2026-10-07T00:00:00Z', fulfilled_at: '2026-10-07T00:00:00Z', timezone: 'Asia/Manila', status: 'partially_used',
  download_url: '/orders/warranties/WRNTY-2026-OPAQUE/certificate', items: [
    { id: 10, order_item_id: 100, product_name: 'Product A', size: '9', color: 'Black', covered_quantity: 2, refunded_quantity: 1,
      remaining_quantity: 1, reserved_quantity: 0, available_quantity: 1, status: 'active', can_assess: true,
      start_date: '2026-10-07T00:00:00Z', expiration_date: '2027-10-07T00:00:00Z',
      policy: { title: 'Product Warranty', duration_value: 1, duration_unit: 'years' as const, terms: 'Original shop terms', description: '', exclusions: 'Wear', instructions: 'Visit the shop' }, void_reason: null },
  ] };

describe('Product Warranty panel', () => {
  it('shows one order reference/download and independent remaining quantity', () => {
    const assess = vi.fn();
    render(<RetailWarrantyPanel warranty={warranty} onAssess={assess} />);
    expect(screen.getByText('WRNTY-2026-OPAQUE')).toBeInTheDocument();
    expect(screen.getByText(/Partially Used/)).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Download Warranty PDF' })).toHaveLength(1);
    expect(screen.getByText(/Qty Remaining: 1/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Request Warranty Assessment' }));
    expect(assess).toHaveBeenCalledOnce();
    fireEvent.click(screen.getByText('Original terms and instructions'));
    expect(screen.getByText('Original shop terms')).toBeInTheDocument();
  });

  it('does not clutter orders without coverage or offer an expired assessment', () => {
    const { rerender, container } = render(<RetailWarrantyPanel warranty={null} />);
    expect(container).toBeEmptyDOMElement();
    rerender(<RetailWarrantyPanel warranty={{ ...warranty, status: 'expired', items: [{ ...warranty.items[0], status: 'expired', can_assess: false }] }} onAssess={vi.fn()} />);
    expect(screen.queryByRole('button', { name: 'Request Warranty Assessment' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Download Warranty PDF' })).toHaveAttribute('href', warranty.download_url);
  });
});
