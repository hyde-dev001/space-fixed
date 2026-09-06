import React from 'react';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UploadService from '../uploadService';

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      auth: { user: { role: 'REPAIRER' } },
    },
  }),
}));
vi.mock('axios', () => ({
  default: { get: mocks.get },
}));
vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));
vi.mock('../components/RepairPackageManager', () => ({
  default: () => null,
}));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.get.mockImplementation(async (url: string) => {
    if (url === '/api/repair-services') {
      return {
        data: {
          success: true,
          data: [{
            id: 1,
            name: 'Glue',
            category: 'Care',
            price: '150',
            duration: '1 to 1 hours',
            description: 'Basic care service',
            status: 'Active',
          }],
        },
      };
    }

    if (url === '/api/repairer/materials') {
      return { data: { success: true, data: [] } };
    }

    throw new Error(`Unexpected GET ${url}`);
  });
});

afterEach(() => {
  cleanup();
});

describe('Repair services visual controls', () => {
  it('keeps the Add Service action compact and category text unfilled', async () => {
    render(<UploadService />);

    const addServiceButton = screen.getByRole('button', { name: 'Add Service' });
    expect(addServiceButton).toHaveClass('px-4', 'py-2');
    expect(addServiceButton.parentElement).toHaveClass('self-start');

    const servicesTab = screen.getByRole('button', { name: 'Services' });
    expect(servicesTab.parentElement).toHaveClass('self-start');

    const table = await screen.findByRole('table');
    const category = within(table).getByText('Care');
    expect(category).toHaveClass('font-medium', 'text-gray-900');
    expect(category).not.toHaveClass('bg-blue-100');
  });
});
