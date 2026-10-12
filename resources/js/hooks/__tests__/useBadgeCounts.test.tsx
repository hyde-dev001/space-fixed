import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useBadgeCounts } from '../useBadgeCounts';

const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
  router: { visit: inertia.visit },
}));

const Probe = () => {
  const counts = useBadgeCounts(true, { orderStatusCount: 4 });

  return <span>{counts.orderStatusCount}</span>;
};

describe('useBadgeCounts', () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it('stops polling and clears counts without navigating after a 401', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 401 });
    vi.stubGlobal('fetch', fetchMock);

    render(<Probe />);

    await act(async () => {
      await Promise.resolve();
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(4000);
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(inertia.visit).not.toHaveBeenCalled();
    expect(screen.getByText('0')).toBeInTheDocument();
  });
});
