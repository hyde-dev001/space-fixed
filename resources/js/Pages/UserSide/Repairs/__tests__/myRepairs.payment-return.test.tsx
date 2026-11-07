import React from 'react';
import { cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ get: vi.fn(), fetch: vi.fn(), swal: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ children }: React.PropsWithChildren) => <>{children}</> }));
vi.mock('@/Pages/UserSide/Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({ default: { fire: mocks.swal, showLoading: vi.fn() } }));
vi.mock('axios', () => ({ default: { get: mocks.get } }));
import MyRepairs from '../myRepairs';

const returnUrl = '/my-repairs?paymongo_success=1&pending_repair_id=77&return_ts=123&return_sig=test-signature';
const verified = { ok: true, status: 200, json: async () => ({ success: true, payment_verified: true }) };
let sessionValues: Record<string, string>;

beforeEach(() => {
  vi.clearAllMocks();
  sessionValues = {};
  window.history.replaceState({ page: { url: '/my-repairs', rememberedState: { tab: 'pending' } } }, '', '/my-repairs');
  const storage = {
    getItem: vi.fn((key: string) => sessionValues[key] ?? null),
    setItem: vi.fn((key: string, value: string) => { sessionValues[key] = value; }),
    removeItem: vi.fn((key: string) => { delete sessionValues[key]; }),
    clear: vi.fn(),
  };
  Object.defineProperty(window, 'sessionStorage', { configurable: true, value: storage });
  Object.defineProperty(window, 'localStorage', { configurable: true, value: { ...storage } });
  vi.stubGlobal('location', {
    get href() { return window.document.URL; },
    get origin() { return new URL(window.document.URL).origin; },
    get pathname() { return new URL(window.document.URL).pathname; },
    get search() { return new URL(window.document.URL).search; },
    get hash() { return new URL(window.document.URL).hash; },
    reload: mocks.reload,
  });
  mocks.get.mockImplementation(async (url: string) => url === '/api/customer/repairs'
    ? { data: { success: true, data: [] } }
    : { data: [] });
  mocks.fetch.mockResolvedValue(verified);
  mocks.swal.mockResolvedValue({ isConfirmed: false });
  vi.stubGlobal('fetch', mocks.fetch);
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

const expectVerified = async () => waitFor(() => expect(mocks.swal).toHaveBeenCalledWith(
  expect.objectContaining({ title: 'Payment Confirmed!' }),
));

describe('My Repairs payment return lifecycle', () => {
  it('verifies the signed query repair instead of an unrelated pending session ID', async () => {
    sessionValues.pendingRepairId = '42';
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await expectVerified();
    expect(mocks.fetch).toHaveBeenCalledWith('/api/customer/repairs/77/verify-payment-return', expect.objectContaining({
      method: 'POST', body: JSON.stringify({ return_ts: 123, return_sig: 'test-signature' }),
    }));
    expect(mocks.fetch).not.toHaveBeenCalledWith('/api/customer/repairs/42/verify-payment-return', expect.anything());
  });

  it('preserves Inertia history state, unrelated query parameters and the hash', async () => {
    const originalState = window.history.state;
    window.history.replaceState(originalState, '', `${returnUrl}&tab=pending&source=receipt#repair-77`);
    render(<MyRepairs />);
    await expectVerified();
    expect(window.history.state).toEqual(originalState);
    expect(window.location.search).toBe('?tab=pending&source=receipt');
    expect(window.location.hash).toBe('#repair-77');
  });

  it.each(['inertia', 'popstate'])('handles %s arrival while the page remains mounted', async (navigation) => {
    render(<MyRepairs />);
    await waitFor(() => expect(mocks.get).toHaveBeenCalledWith('/api/customer/repairs', expect.anything()));
    window.history.replaceState(window.history.state, '', returnUrl);
    if (navigation === 'inertia') {
      fireEvent(document, new CustomEvent('inertia:navigate', { detail: { page: { url: returnUrl } } }));
    } else {
      fireEvent(window, new PopStateEvent('popstate', { state: window.history.state }));
    }
    await expectVerified();
    expect(mocks.fetch).toHaveBeenCalledTimes(1);
    expect(window.location.search).toBe('');
  });

  it('does not mark an in-flight verification as handled', async () => {
    mocks.fetch.mockImplementation(() => new Promise(() => {}));
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await waitFor(() => expect(mocks.fetch).toHaveBeenCalled());
    expect(sessionValues['repairPaymentReturnHandled:77']).toBeUndefined();
    expect(mocks.reload).not.toHaveBeenCalled();
  });

  it('can retry an unverified return after a clean refresh', async () => {
    mocks.fetch.mockResolvedValueOnce({ ok: false, status: 500, json: async () => ({ success: false, payment_verified: false }) });
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await waitFor(() => expect(mocks.swal).toHaveBeenCalledWith(expect.objectContaining({ title: 'Payment Not Verified' })));
    expect(window.location.search).toBe('');
    cleanup();
    render(<MyRepairs />);
    await expectVerified();
    expect(mocks.fetch).toHaveBeenCalledTimes(2);
    expect(sessionValues['repairPaymentReturnHandled:77']).toBe('1');
  });

  it('verifies even when browser session storage is unavailable', async () => {
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      value: {
        getItem: () => { throw new DOMException('Storage denied', 'SecurityError'); },
        setItem: () => { throw new DOMException('Storage denied', 'SecurityError'); },
        removeItem: () => { throw new DOMException('Storage denied', 'SecurityError'); },
      },
    });
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await expectVerified();
    expect(mocks.fetch).toHaveBeenCalledTimes(1);
    expect(window.location.search).toBe('');
  });

  it.each([
    { name: 'legacy premature marker', context: null },
    { name: 'a different signed return', context: '[122,"old-signature"]' },
  ])('does not let $name suppress current verification', async ({ context }) => {
    sessionValues['repairPaymentReturnHandled:77'] = '1';
    if (context) sessionValues['repairPaymentReturnHandled:77:verifiedContext'] = context;
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await expectVerified();
    expect(mocks.fetch).toHaveBeenCalledTimes(1);
  });

  it('keeps the signed callback retryable when storage is denied and verification fails', async () => {
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      value: { getItem: () => null, setItem: () => { throw new Error('Storage unavailable'); }, removeItem: () => {} },
    });
    mocks.fetch.mockResolvedValue({ ok: false, status: 500, json: async () => ({ success: false, payment_verified: false }) });
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await waitFor(() => expect(mocks.swal).toHaveBeenCalledWith(expect.objectContaining({ title: 'Payment Not Verified' })));
    expect(new URLSearchParams(window.location.search).get('return_sig')).toBe('test-signature');
    expect(mocks.reload).not.toHaveBeenCalled();
  });

  it('ignores a late verified response after the page unmounts', async () => {
    let resolve: (response: typeof verified) => void = () => {};
    mocks.fetch.mockImplementation(() => new Promise((done) => { resolve = done; }));
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await waitFor(() => expect(mocks.fetch).toHaveBeenCalledTimes(1));
    cleanup();
    resolve(verified);
    await Promise.resolve();
    await Promise.resolve();
    expect(mocks.swal).not.toHaveBeenCalledWith(expect.objectContaining({ title: 'Payment Confirmed!' }));
    expect(sessionValues['repairPaymentReturnHandled:77']).toBeUndefined();
    expect(mocks.reload).not.toHaveBeenCalled();
  });

  it('waits for verified provider evidence after a pending response', async () => {
    mocks.fetch.mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ success: false, payment_verified: false }) });
    window.history.replaceState(window.history.state, '', returnUrl);
    render(<MyRepairs />);
    await waitFor(() => expect(mocks.fetch).toHaveBeenCalledTimes(1));
    expect(mocks.swal).not.toHaveBeenCalledWith(expect.objectContaining({ title: 'Payment Confirmed!' }));
    await waitFor(() => expect(mocks.fetch).toHaveBeenCalledTimes(2), { timeout: 4000 });
    await expectVerified();
    expect(mocks.reload).toHaveBeenCalledTimes(1);
  });
});
