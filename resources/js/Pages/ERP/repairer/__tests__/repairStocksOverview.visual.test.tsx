import React from 'react';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RepairStocksOverview from '../repairStocksOverview';

const mocks = vi.hoisted(() => ({
  getStocksOverview: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/services/repairMaterialsApi', () => ({
  default: {
    getStocksOverview: mocks.getStocksOverview,
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.getStocksOverview.mockResolvedValue({
    success: true,
    data: [{
      id: 1,
      name: 'Glue',
      category: 'repair_materials',
      available_quantity: 97,
      reorder_level: 10,
      unit: 'piece',
      images: [],
    }],
    metrics: { total_items: 97, low_stock_count: 0, out_of_stock_count: 0 },
  });
});

afterEach(() => {
  cleanup();
});

describe('Repair stocks visual controls', () => {
  it('renders category labels without colored backgrounds', async () => {
    render(<RepairStocksOverview />);

    const table = screen.getByRole('table');
    await waitFor(() => expect(within(table).getByText('Repair Materials')).toBeInTheDocument());
    const category = within(table).getByText('Repair Materials');
    expect(category).toHaveClass('font-medium', 'text-gray-900');
    expect(category).not.toHaveClass('bg-sky-50', 'rounded-full');
  });
});
