import { render, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CartProvider } from '../CartContext';

describe('CartProvider session synchronization', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('does not start session-backed cart requests when synchronization is disabled', () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    render(
      <CartProvider syncEnabled={false}>
        <div>Authentication page</div>
      </CartProvider>,
    );

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('starts synchronization after leaving an authentication page', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: vi.fn().mockResolvedValue({ count: 0, items: [] }),
    });
    vi.stubGlobal('fetch', fetchMock);

    const { rerender } = render(
      <CartProvider syncEnabled={false}>
        <div>Authentication page</div>
      </CartProvider>,
    );

    rerender(
      <CartProvider syncEnabled>
        <div>Customer page</div>
      </CartProvider>,
    );

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  });
});
