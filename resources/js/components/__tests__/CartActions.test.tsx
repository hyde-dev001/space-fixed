import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AddToCartButton from '../CartActions';
import { CART_EVENTS, type CartAddedEventDetail } from '../../types/cart-events';

const fetchMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
  router: { visit: vi.fn() },
  usePage: () => ({
    props: {
      auth: { user: { id: 1, name: 'Customer' } },
    },
  }),
}));

vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));

describe('AddToCartButton', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockResolvedValue({
      ok: true,
      json: vi.fn().mockResolvedValue({ success: true, total_count: 3 }),
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    fetchMock.mockReset();
  });

  it('emits drawer-open metadata after a successful product add', async () => {
    let addedDetail: CartAddedEventDetail | null = null;
    const handleAdded = (event: Event) => {
      addedDetail = (event as CustomEvent<CartAddedEventDetail>).detail;
    };

    window.addEventListener(CART_EVENTS.ADDED, handleAdded);

    render(
      <AddToCartButton
        productId={7}
        product={{
          id: 7,
          name: 'SoleSpace Runner',
          price: 5555,
          selectedImage: '/storage/products/runner.jpg',
          size: 'US 8',
          color: 'Black',
          qty: 2,
        }}
        label="Add to Cart"
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Add to Cart' }));

    await waitFor(() => expect(addedDetail?.openDrawer).toBe(true));
    window.removeEventListener(CART_EVENTS.ADDED, handleAdded);

    expect(addedDetail).toMatchObject({
      added: 2,
      total: 3,
      openDrawer: true,
      item: {
        name: 'SoleSpace Runner',
        price: 5555,
        image: '/storage/products/runner.jpg',
        size: 'US 8',
        color: 'Black',
        quantity: 2,
      },
    });
  });
});
