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

const longProductName = 'A Very Long Shoe Name That Must Stay Aligned';

const retailProducts = [
  {
    id: 1,
    name: longProductName,
    price: 1000,
    stock_quantity: 10,
    variants: [
      { id: 101, size: '7', color: 'Black', quantity: 4 },
      { id: 102, size: '8', color: 'Black', quantity: 4 },
      { id: 103, size: '7', color: 'White', quantity: 4 },
    ],
  },
  {
    id: 2,
    name: 'Basic Sneaker',
    price: 1200,
    stock_quantity: 6,
    variants: [],
  },
];

describe('Cashier retail variant selection', () => {
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

  it('opens one size/color modal and only applies the draft after Apply', async () => {
    render(<CashierPOS />);

    await screen.findByText(longProductName, { exact: true });

    fireEvent.click(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name/i }));

    const dialog = screen.getByRole('dialog', { name: /choose options for a very long shoe name/i });
    expect(within(dialog).getByRole('radio', { name: '7' })).toHaveAttribute('aria-checked', 'true');

    fireEvent.click(within(dialog).getByRole('radio', { name: '8' }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name.*7/i })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name/i }));
    const reopenedDialog = screen.getByRole('dialog', { name: /choose options for a very long shoe name/i });
    fireEvent.click(within(reopenedDialog).getByRole('radio', { name: '8' }));
    fireEvent.click(within(reopenedDialog).getByRole('button', { name: 'Apply' }));

    expect(screen.getByRole('button', { name: /Select size for A Very Long Shoe Name.*8/i })).toBeInTheDocument();
  });

  it('uses the same modal to update a variant already in the current order', async () => {
    render(<CashierPOS />);

    await screen.findByText(longProductName, { exact: true });
    fireEvent.click(screen.getAllByRole('button', { name: 'Add' })[0]);

    fireEvent.click(screen.getByRole('button', { name: /Select color for A Very Long Shoe Name That Must Stay Aligned in current order/i }));

    const dialog = screen.getByRole('dialog', { name: /choose options for a very long shoe name/i });
    fireEvent.click(within(dialog).getByRole('radio', { name: 'White' }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Apply' }));

    expect(screen.getByRole('button', { name: /Select color for A Very Long Shoe Name That Must Stay Aligned in current order.*White/i })).toBeInTheDocument();
  });

  it('reserves the same title height for long and short product names', async () => {
    render(<CashierPOS />);

    await waitFor(() => expect(screen.getByText(longProductName, { exact: true })).toBeInTheDocument());

    expect(screen.getByTestId('retail-product-title-1')).toHaveClass('h-14', 'leading-7', 'line-clamp-2');
    expect(screen.getByTestId('retail-product-title-2')).toHaveClass('h-14', 'leading-7', 'line-clamp-2');
  });
});
