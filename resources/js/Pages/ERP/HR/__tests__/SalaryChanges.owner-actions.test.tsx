import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SalaryChanges from '../SalaryChanges';

const mocks = vi.hoisted(() => ({
  page: {
    props: {
      auth: {
        erpActor: { ownerMode: true, type: 'shop_owner' },
        user: { id: 999 },
      },
    },
  },
  fetch: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => mocks.page,
}));

const ownerRequiredChange = {
  id: 1,
  employee_id: 10,
  employee: { id: 10, name: 'Owner Review Employee', department: 'Operations' },
  proposed_by: 55,
  previous_salary: 1000,
  new_salary: 1200,
  change_percent: 20,
  change_type: 'major_adjustment',
  effective_date: '2026-09-01',
  reason: 'Owner-stage test',
  status: 'pending',
  retroactive: false,
  requires_owner_approval: true,
  owner_action_required: true,
  created_at: '2026-08-28T00:00:00.000000Z',
  updated_at: '2026-08-28T00:00:00.000000Z',
};

const financeOnlyChange = {
  ...ownerRequiredChange,
  id: 2,
  employee_id: 11,
  employee: { id: 11, name: 'Finance Review Employee', department: 'Finance' },
  requires_owner_approval: false,
  owner_action_required: false,
};

beforeEach(() => {
  mocks.fetch.mockReset();
  mocks.fetch.mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => ({
      data: [ownerRequiredChange, financeOnlyChange],
      summary: { pending: 2, approved: 0, applied: 0, rejected: 0, cancelled: 0 },
    }),
  });
  vi.stubGlobal('fetch', mocks.fetch);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('shop-owner salary change actions', () => {
  it('shows approval only for a request awaiting the owner stage', async () => {
    render(<SalaryChanges />);

    expect(await screen.findByText('Owner Review Employee')).toBeInTheDocument();
    expect(screen.getAllByTitle('Approve')).toHaveLength(1);
    expect(screen.queryByTitle('Cancel')).not.toBeInTheDocument();
  });
});
