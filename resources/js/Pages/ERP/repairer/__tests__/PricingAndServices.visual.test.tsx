import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PricingAndServices from '../PricingAndServices';

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      auth: { user: { role: 'REPAIRER' } },
      initialServices: [{
        id: 1,
        name: 'Glue',
        category: 'Care',
        price: '150',
        duration: '1 hour',
        status: 'Active',
      }],
    },
  }),
}));
vi.mock('axios', () => ({
  default: { get: mocks.get, post: vi.fn() },
}));
vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockImplementation(async (url: string) => {
    if (url === '/api/repair-services') {
      return { data: { success: true, data: [] } };
    }

    return { data: { success: true, data: [] } };
  });
});

afterEach(() => {
  cleanup();
});

describe('Repair pricing visual controls', () => {
  it('uses a monochrome active state for the Services tab', async () => {
    render(<PricingAndServices />);

    const servicesTab = screen.getByRole('button', { name: 'Services (1)' });
    expect(servicesTab).toHaveClass('text-gray-900', 'border-gray-900');
    await waitFor(() => expect(mocks.get).toHaveBeenCalledTimes(2));
  });
});
