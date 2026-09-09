import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const usePageMock = vi.fn();
const axiosGetMock = vi.fn();
const axiosPostMock = vi.fn();
const swalFireMock = vi.fn();

vi.mock('axios', () => ({
  default: {
    get: (...args: unknown[]) => axiosGetMock(...args),
    post: (...args: unknown[]) => axiosPostMock(...args),
  },
}));

vi.mock('sweetalert2', () => ({
  default: {
    fire: (...args: unknown[]) => swalFireMock(...args),
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

const canonicalRetailProduct = {
  id: 101,
  name: 'Canonical Retail Product',
  price: 1000,
  stock_quantity: 16,
  main_image: null,
  variants: [
    {
      id: 501,
      size: '8',
      color: 'Black',
      quantity: 4,
      inventory_item_id: 1001,
      inventory_color_variant_id: 1101,
      inventory_size_id: 1201,
    },
    {
      id: 502,
      size: '9',
      color: 'Black',
      quantity: 12,
      inventory_item_id: 1001,
      inventory_color_variant_id: 1101,
      inventory_size_id: 1202,
    },
  ],
};

describe('Cashier retail catalog pagination', () => {
  beforeEach(() => {
    usePageMock.mockReset();
    axiosGetMock.mockReset();
    axiosPostMock.mockReset();
    swalFireMock.mockReset();

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
    axiosPostMock.mockResolvedValue({
      data: {
        data: {
          id: 900,
          transaction_no: 'RPOS-900',
        },
      },
    });
    swalFireMock.mockResolvedValue({ isConfirmed: true });
  });

  afterEach(() => {
    cleanup();
  });

  it('limits the catalog to one page and uses the cashier pagination controls', async () => {
    render(<CashierPOS />);

    await waitFor(() => expect(screen.getByText('Retail Product 1', { exact: true })).toBeInTheDocument());

    const pagination = screen.getByRole('navigation', { name: 'Retail product pagination' });
    expect(pagination).toHaveTextContent('Showing 1 to 9 of 11 products');
    expect(within(pagination).getByText('1', { exact: true })).toHaveClass('bg-[#111111]', 'text-white');
    expect(within(pagination).getByText('1', { exact: true })).not.toHaveClass('bg-blue-600');
    expect(within(pagination).getByRole('button', { name: 'Previous retail product page' })).toBeDisabled();
    expect(within(pagination).getByRole('button', { name: 'Next retail product page' })).toBeEnabled();
    expect(screen.queryByText('Retail Product 10', { exact: true })).not.toBeInTheDocument();

    const checkoutWarning = screen.getByText('Add at least one product before checkout.', { exact: true });
    expect(checkoutWarning).toHaveClass('bg-gray-100', 'border-gray-300', 'text-gray-700');
    expect(checkoutWarning).not.toHaveClass('bg-amber-50', 'border-amber-200', 'text-amber-700');

    fireEvent.click(within(pagination).getByRole('button', { name: 'Next retail product page' }));

    expect(await screen.findByText('Retail Product 10', { exact: true })).toBeInTheDocument();
    expect(screen.queryByText('Retail Product 1', { exact: true })).not.toBeInTheDocument();
    expect(pagination).toHaveTextContent('Showing 10 to 11 of 11 products');
  });
  it('shows the selected canonical target stock and refreshes it without a hard reload', async () => {
    const refreshedProduct = {
      ...canonicalRetailProduct,
      stock_quantity: 6,
      variants: canonicalRetailProduct.variants.map((variant) => (
        variant.id === 502 ? { ...variant, quantity: 2 } : variant
      )),
    };

    axiosGetMock
      .mockImplementationOnce(() => Promise.resolve({ data: { data: [canonicalRetailProduct] } }))
      .mockImplementationOnce(() => Promise.resolve({ data: { data: [refreshedProduct] } }));

    render(<CashierPOS />);

    await screen.findByText('Canonical Retail Product', { exact: true });
    expect(screen.getByText('4 in stock', { exact: true })).toBeInTheDocument();

    fireEvent.change(screen.getByTitle('Select size for Canonical Retail Product'), {
      target: { value: '9' },
    });

    expect(screen.getByText('12 in stock', { exact: true })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));

    await waitFor(() => expect(screen.getByText('2 in stock', { exact: true })).toBeInTheDocument());
    expect(axiosGetMock).toHaveBeenCalledTimes(2);
  });

  it('submits the selected catalog and inventory target IDs during checkout', async () => {
    axiosGetMock.mockImplementation(() => Promise.resolve({
      data: { data: [canonicalRetailProduct] },
    }));

    render(<CashierPOS />);

    await screen.findByText('Canonical Retail Product', { exact: true });
    fireEvent.click(screen.getByRole('button', { name: 'Add', exact: true }));
    fireEvent.change(screen.getByTitle('Retail cash received'), {
      target: { value: '1000' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Pay', exact: true }));

    await waitFor(() => expect(axiosPostMock).toHaveBeenCalledWith(
      '/api/retail-pos/checkout',
      expect.objectContaining({
        items: [
          expect.objectContaining({
            product_id: 101,
            variant_id: 501,
            inventory_color_variant_id: 1101,
            inventory_size_id: 1201,
            size: '8',
            color: 'Black',
          }),
        ],
      }),
      expect.anything(),
    ));
    await waitFor(() => expect(axiosGetMock).toHaveBeenCalledTimes(2));
  });
});
