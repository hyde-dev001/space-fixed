import { beforeEach, describe, expect, it, vi } from 'vitest';
import { getFreshCsrfToken } from '../csrf';

describe('getFreshCsrfToken', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '<meta name="csrf-token" content="stale-token">';
  });

  it('loads the current session token and refreshes the document meta tag', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ csrf_token: 'current-token' }),
    }));

    await expect(getFreshCsrfToken()).resolves.toBe('current-token');

    expect(fetch).toHaveBeenCalledWith('/api/csrf-token', expect.objectContaining({
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
    }));
    expect(document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')).toBe('current-token');
  });
});
