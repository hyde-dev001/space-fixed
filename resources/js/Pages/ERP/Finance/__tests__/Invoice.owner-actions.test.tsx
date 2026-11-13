import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  invoices: [] as Array<{ id: string; reference: string; date: string; due_date: string; customer_name: string; total: number; status: 'draft' | 'sent' | 'paid'; deleted_at: string | null; items: [] }>,
  swal: vi.fn(),
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
    data: mocks.invoices,
    isLoading: false,
    refetch: mocks.refetch,
  }),
}));

vi.mock('sweetalert2', () => ({ default: { fire: mocks.swal } }));

const employeeCapabilities = {
  'POST:finance.invoices.mark_sent': { allowed: true, url: '/api/finance/invoices/__ERP_PARAM_id__/mark-sent' },
  'POST:finance.invoices.payments.store': { allowed: true, url: '/api/finance/invoices/__ERP_PARAM_id__/payments' },
  'DELETE:finance.invoices.destroy': { allowed: true, url: '/api/finance/invoices/__ERP_PARAM_id__' },
  'POST:finance.invoices.restore': { allowed: true, url: '/api/finance/invoices/__ERP_PARAM_id__/restore' },
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
  mocks.invoices = [];
  mocks.swal.mockResolvedValue({ isConfirmed: false });
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

const showInvoice = (status: 'draft' | 'sent' | 'paid' | 'archived') => {
  mocks.invoices = [{ id: '33', reference: 'INV-QA-33', date: '2026-10-04', due_date: '2026-10-05',
    customer_name: 'QA Customer', total: 100, status: status === 'archived' ? 'draft' : status,
    deleted_at: status === 'archived' ? '2026-10-04' : null, items: [] }];
};

it.each(['draft', 'sent', 'paid', 'archived'] as const)('keeps owner %s rows and details read-only despite stale Finance capabilities', (status) => {
  mocks.page.props.ownerMode = true;
  mocks.page.props.auth.erpActor = { ownerMode: true, type: 'shop_owner' };
  showInvoice(status);
  render(<Invoice />);
  if (status === 'archived') fireEvent.click(screen.getByRole('button', { name: /Show Archived/ }));
  expect(screen.getByTitle('View Invoice')).toBeInTheDocument();
  for (const label of ['Mark as sent', 'Record Payment', 'Archive Invoice', 'Restore Invoice']) {
    expect(screen.queryByTitle(label)).not.toBeInTheDocument();
  }
  fireEvent.click(screen.getByTitle('View Invoice'));
  expect(screen.getByTitle('Download PDF')).toBeInTheDocument();
  for (const label of ['Mark as sent', 'Archive', 'Restore']) {
    expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument();
  }
  expect(mocks.post).not.toHaveBeenCalled();
  expect(mocks.delete).not.toHaveBeenCalled();
});

it('keeps authorized Finance row and modal mutations available', async () => {
  showInvoice('draft');
  mocks.swal.mockResolvedValue({ isConfirmed: true });
  mocks.post.mockResolvedValue({ ok: true });
  render(<Invoice />);
  expect(screen.getByTitle('Archive Invoice')).toBeInTheDocument();
  fireEvent.click(screen.getByTitle('Mark as sent'));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/api/finance/invoices/33/mark-sent'));
  fireEvent.click(screen.getByTitle('View Invoice'));
  expect(screen.getByRole('button', { name: 'Archive' })).toBeInTheDocument();
  expect(screen.getAllByRole('button', { name: 'Mark as sent' })).toHaveLength(2);
});

it('does not offer Finance mutations when explicit capabilities are denied', () => {
  mocks.page.props.erpCapabilities = {};
  showInvoice('sent');
  render(<Invoice />);
  expect(screen.queryByTitle('Record Payment')).not.toBeInTheDocument();
  expect(screen.queryByTitle('Archive Invoice')).not.toBeInTheDocument();
  fireEvent.click(screen.getByTitle('View Invoice'));
  expect(screen.queryByRole('button', { name: 'Archive' })).not.toBeInTheDocument();
  expect(mocks.post).not.toHaveBeenCalled();
});

it('retains Finance payment recording for an authorized sent invoice', async () => {
  showInvoice('sent');
  const form = { amount: '100', received_at: '2026-10-04', payment_method: 'cash', idempotency_key: 'qa-finance-payment' };
  mocks.swal.mockResolvedValueOnce({ value: form }).mockResolvedValue({ isConfirmed: false });
  mocks.post.mockResolvedValue({ ok: true });
  render(<Invoice />);
  fireEvent.click(screen.getByTitle('Record Payment'));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/api/finance/invoices/33/payments', form));
});

it('retains Finance restore for an authorized archived invoice', async () => {
  showInvoice('archived');
  mocks.swal.mockResolvedValue({ isConfirmed: true });
  mocks.post.mockResolvedValue({ ok: true });
  render(<Invoice />);
  fireEvent.click(screen.getByRole('button', { name: /Show Archived/ }));
  fireEvent.click(screen.getByTitle('Restore Invoice'));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/api/finance/invoices/33/restore'));
});
