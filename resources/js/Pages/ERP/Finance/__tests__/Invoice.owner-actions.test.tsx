import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Invoice from '../Invoice';

const mocks = vi.hoisted(() => ({
  page: {
    props: {
      ownerMode: false,
      auth: { user: { id: 1 }, erpActor: { ownerMode: false, type: 'employee' } },
      erpCapabilities: {},
    },
  },
  visit: vi.fn(),
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
  refetch: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => mocks.page,
  router: { visit: mocks.visit },
}));

vi.mock('../../../../hooks/useFinanceApi', () => ({
  useFinanceApi: () => ({
    get: mocks.get,
    post: mocks.post,
    delete: mocks.delete,
  }),
}));

vi.mock('../../../../hooks/useFinanceQueries', () => ({
  useInvoices: () => ({
    data: [],
    isLoading: false,
    refetch: mocks.refetch,
  }),
}));

vi.mock('sweetalert2', () => ({ default: { fire: vi.fn() } }));

const employeeCapabilities = {
  'GET:finance.create-invoice': {
    allowed: true,
    url: '/finance?section=create-invoice',
  },
};

beforeEach(() => {
  mocks.page.props = {
    ownerMode: false,
    auth: { user: { id: 1 }, erpActor: { ownerMode: false, type: 'employee' } },
    erpCapabilities: employeeCapabilities,
  };
  vi.clearAllMocks();
});

afterEach(() => {
  cleanup();
});

describe('owner invoice action boundary', () => {
  it('hides Create Invoice for owners even when a stale capability is present', () => {
    mocks.page.props = {
      ownerMode: true,
      auth: { user: { id: 1 }, erpActor: { ownerMode: true, type: 'shop_owner' } },
      erpCapabilities: employeeCapabilities,
    };

    render(<Invoice />);

    expect(screen.getByRole('heading', { name: 'Invoices' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Create Invoice' })).not.toBeInTheDocument();
    expect(mocks.visit).not.toHaveBeenCalled();
  });

  it('keeps the employee Create Invoice action and destination available', () => {
    render(<Invoice />);

    fireEvent.click(screen.getByRole('button', { name: 'Create Invoice' }));

    expect(mocks.visit).toHaveBeenCalledWith('/finance?section=create-invoice');
  });
});
