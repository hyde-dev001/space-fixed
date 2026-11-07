import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCreateInvoice, useInvoices } from '../useFinanceQueries';

const financeApi = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  invoices: [] as Array<{ id: number; reference: string }>,
}));

vi.mock('../useFinanceApi', () => ({
  useFinanceApi: () => ({ get: financeApi.get, post: financeApi.post }),
}));

function InvoiceQueryProbe() {
  const { data: invoices = [] } = useInvoices({ archived: false });
  const createInvoice = useCreateInvoice();

  return (
    <>
      <ul>{invoices.map((invoice) => <li key={invoice.id}>{invoice.reference}</li>)}</ul>
      <button onClick={() => createInvoice.mutate({ reference: 'INV-NEW' })}>Create</button>
    </>
  );
}

describe('invoice query synchronization', () => {
  beforeEach(() => {
    financeApi.invoices = [];
    financeApi.get.mockReset().mockImplementation(async () => ({ ok: true, data: financeApi.invoices }));
    financeApi.post.mockReset().mockImplementation(async () => {
      const invoice = { id: 1, reference: 'INV-NEW' };
      financeApi.invoices = [invoice, ...financeApi.invoices];
      return { ok: true, data: invoice };
    });
  });

  it('refetches the active invoice list after creation succeeds', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, staleTime: Infinity }, mutations: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <InvoiceQueryProbe />
      </QueryClientProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Create' }));

    expect(await screen.findByText('INV-NEW')).toBeInTheDocument();
    await waitFor(() => expect(financeApi.get).toHaveBeenCalledTimes(2));
    expect(financeApi.post).toHaveBeenCalledTimes(1);
  });
});
