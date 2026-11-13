import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ViewSlip from '../viewSlip';

const mocks = vi.hoisted(() => ({
  page: {
    props: {
      auth: { erpActor: { ownerMode: true, type: 'shop_owner' } },
    },
  },
  fetch: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => mocks.page,
}));

const emptyPayrollResponse = () => Promise.resolve({
  ok: true,
  status: 200,
  json: async () => ({ data: [], current_page: 1, last_page: 1, per_page: 7, total: 0 }),
});

beforeEach(() => {
  mocks.page.props = {
    auth: { erpActor: { ownerMode: true, type: 'shop_owner' } },
  };
  mocks.fetch.mockReset();
  mocks.fetch.mockImplementation(emptyPayrollResponse);
  vi.stubGlobal('fetch', mocks.fetch);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('owner payslip action boundary', () => {
  it('loads the owner read endpoint without exposing a Generate Slip action', async () => {
    render(<ViewSlip />);

    expect(await screen.findByRole('heading', { name: 'View Slip' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Generate Slip/i })).not.toBeInTheDocument();

    await waitFor(() => {
      expect(mocks.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/api/shop-owner/hr/payroll?'),
        expect.objectContaining({ method: 'GET' }),
      );
    });
  });
});
