import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CustomerSupport from '../customerSupport';

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

const orderContent = [
  '🛍️ **New Order Placed**',
  '',
  '**Order Number:** ORD-20260912-711',
  '**Items:** Nike Air Force 1 x1',
  '**Products:**',
  '- Nike Air Force 1 x1 (₱5,000.00)',
  '**Total:** ₱5,280.00',
  '**Status:** Pending Payment',
  '',
  'Thank you for your order! Please complete payment to start processing.',
].join('\n');

const conversation = {
  id: 1,
  customer: { id: 2, name: 'John Paul Yambao', email: 'john@example.com' },
  status: 'open',
  priority: 'medium',
  last_message_at: '2026-09-12T05:20:00Z',
  messages: [{
    id: 11,
    sender_type: 'system',
    content: orderContent,
    attachments: ['/storage/products/nike-air-force-1.jpg'],
    created_at: '2026-09-12T05:20:00Z',
  }],
};

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

vi.mock('axios', () => ({
  default: { get: mocks.get, post: mocks.post },
}));

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
    configurable: true,
    value: vi.fn(),
  });
  mocks.get.mockImplementation(async (url: string) => {
    if (url === '/api/crm/conversations') {
      return { data: { data: [conversation] } };
    }

    return { data: conversation };
  });
});

afterEach(() => {
  cleanup();
});

describe('CRM order notifications', () => {
  it('renders new orders with the structured order card layout', async () => {
    render(<CustomerSupport />);

    await waitFor(() => expect(screen.getByText('New Order Placed')).toBeInTheDocument());

    expect(screen.getByText('New Order Placed').closest('.my-6')).toHaveClass('justify-end');
    expect(screen.getByText('Products:')).toBeInTheDocument();
    expect(screen.getByText('Nike Air Force 1')).toBeInTheDocument();
    expect(screen.getByText('Items:')).toBeInTheDocument();
    expect(screen.getByText('Total:')).toBeInTheDocument();
    expect(screen.getByText("💡 Thank you for your order! We'll notify you once your items are ready for shipment.")).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'View Full Details' })).toBeInTheDocument();
  });
});
