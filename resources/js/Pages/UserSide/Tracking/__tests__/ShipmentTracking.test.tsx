import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ShipmentTracking from '../ShipmentTracking';

const mockPageState = {
  props: {
    shipment: {
      id: 7,
      purpose: 'retail_delivery',
      status: 'active',
      source_type: 'order',
      created_at: '2026-07-10T10:00:00.000000Z',
      legs: [
        {
          id: 10,
          sequence: 1,
          leg_type: 'outbound',
          status: 'in_transit',
          origin_snapshot: { type: 'shop', name: 'SoleSpace Shop', address: 'Shop Address' },
          destination_snapshot: { type: 'customer', name: 'Customer Name', address: 'Customer Address' },
          tracking_number: 'TRK-123',
          tracking_url: 'https://courier.test/track/TRK-123',
          requires_delivery_proof: true,
        },
      ],
      events: [
        {
          id: 1,
          shipment_leg_id: 10,
          event_type: 'in_transit',
          message: 'Your order is in transit.',
          created_at: '2026-07-10T10:30:00.000000Z',
        },
      ],
    },
  },
};

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
  usePage: () => mockPageState,
}));

vi.mock('../../Shared/Navigation', () => ({
  default: () => <nav>Navigation</nav>,
}));

describe('ShipmentTracking', () => {
  it('renders customer shipment tracking details', () => {
    render(<ShipmentTracking />);

    expect(screen.getByText('Retail Delivery')).toBeInTheDocument();
    expect(screen.getByText('TRK-123')).toBeInTheDocument();
    expect(screen.getByText('SoleSpace Shop - Shop Address')).toBeInTheDocument();
    expect(screen.getByText('Customer Name - Customer Address')).toBeInTheDocument();
    expect(screen.getByText('Your order is in transit.')).toBeInTheDocument();
  });
});
