import React from 'react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useMarkAllAsRead } from '../useNotifications';

const MarkAllProbe = ({ basePath }: { basePath: string }) => {
  const markAllAsRead = useMarkAllAsRead(basePath);

  return (
    <button type="button" onClick={() => markAllAsRead.mutate()}>
      mark-all
    </button>
  );
};

describe('useNotifications contract', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ success: true }),
      }),
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it('uses canonical mark-all-read endpoint for all namespaces', async () => {
    const queryClient = new QueryClient();

    const basePaths = [
      '/api/notifications',
      '/api/hr/notifications',
      '/api/staff/notifications',
      '/api/shop-owner/notifications',
    ];

    for (const basePath of basePaths) {
      const view = render(
        <QueryClientProvider client={queryClient}>
          <MarkAllProbe basePath={basePath} />
        </QueryClientProvider>,
      );

      fireEvent.click(view.getByRole('button', { name: 'mark-all' }));

      await waitFor(() => {
        expect(global.fetch).toHaveBeenCalled();
      });

      view.unmount();
    }

    const calledUrls = (global.fetch as any).mock.calls.map((call: any[]) => String(call[0]));

    expect(calledUrls.every((url: string) => url.endsWith('/mark-all-read'))).toBe(true);
    expect(calledUrls.some((url: string) => url.includes('/read-all'))).toBe(false);
  });
});
