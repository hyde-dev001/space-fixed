import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RequestMaterials from '../requestMaterials';

const mocks = vi.hoisted(() => ({
  getStocksOverview: vi.fn(),
  getMyMaterialRequests: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
}));
vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/services/repairMaterialsApi', () => ({
  default: {
    getStocksOverview: mocks.getStocksOverview,
    getMyMaterialRequests: mocks.getMyMaterialRequests,
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.getStocksOverview.mockResolvedValue({ success: true, data: [] });
  mocks.getMyMaterialRequests.mockResolvedValue({ success: true, data: [] });
});

afterEach(() => {
  cleanup();
});

describe('Material request visual controls', () => {
  it('places the new material request action on the right side', async () => {
    render(<RequestMaterials />);

    const button = screen.getByRole('button', { name: '+ New Material Request' });
    expect(button.parentElement).toHaveClass('ml-auto');
    await waitFor(() => {
      expect(mocks.getStocksOverview).toHaveBeenCalledTimes(1);
      expect(mocks.getMyMaterialRequests).toHaveBeenCalledTimes(1);
    });
  });
});
