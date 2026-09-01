import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const axiosGetMock = vi.fn();

vi.mock('axios', () => ({
  default: {
    get: (...args: unknown[]) => axiosGetMock(...args),
  },
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => usePageMock(),
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

import CashierPOS from '../POS';

const retailProducts = Array.from({ length: 11 }, (_, index) => ({
  id: index + 1,
  name: `Retail Product ${index + 1}`,
  price: 1000 + index,
  stock_quantity: 10,
  variants: [],
}));

describe('Cashier retail catalog pagination', () => {
  beforeEach(() => {
    usePageMock.mockReset();
    axiosGetMock.mockReset();

    usePageMock.mockReturnValue({
      props: {
        auth: {
          user: {
            shop_owner: {
              business_type: 'retail',
            },
          },
        },
      },
    });

    axiosGetMock.mockImplementation((url: string) => {
      if (url === '/api/retail-pos/products') {
        return Promise.resolve({ data: { data: retailProducts } });
      }

      return Promise.resolve({ data: { data: [] } });
    });
  });

  afterEach(() => {
    cleanup();
  });

  it('limits the catalog to one page and uses the cashier pagination controls', async () => {
    render(<CashierPOS />);

    await waitFor(() => expect(screen.getByText('Retail Product 1', { exact: true })).toBeInTheDocument());

    const pagination = screen.getByRole('navigation', { name: 'Retail product pagination' });
    expect(pagination).toHaveTextContent('Showing 1 to 9 of 11 products');
    expect(within(pagination).getByRole('button', { name: 'Previous retail product page' })).toBeDisabled();
    expect(within(pagination).getByRole('button', { name: 'Next retail product page' })).toBeEnabled();
    expect(screen.queryByText('Retail Product 10', { exact: true })).not.toBeInTheDocument();

    fireEvent.click(within(pagination).getByRole('button', { name: 'Next retail product page' }));

    expect(await screen.findByText('Retail Product 10', { exact: true })).toBeInTheDocument();
    expect(screen.queryByText('Retail Product 1', { exact: true })).not.toBeInTheDocument();
    expect(pagination).toHaveTextContent('Showing 10 to 11 of 11 products');
  });
});
