import type { PropsWithChildren } from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import TimeIn from '../TimeIn';

const fetchMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => ({
        props: {
            auth: { user: { id: 11, role: 'STAFF' } },
        },
    }),
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));

vi.mock('sweetalert2', () => ({
    default: { fire: vi.fn() },
}));

beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ data: [] }),
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('keeps the attendance page mobile-safe and the live clock accessible', async () => {
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    expect(screen.getByTestId('time-in-page')).toHaveClass('min-h-screen', 'overflow-x-hidden');
    expect(screen.getByRole('heading', { name: 'Attendance Tracking' })).toHaveClass(
        'text-xl',
        'sm:text-2xl',
    );
    expect(screen.getByTestId('attendance-dashboard')).toHaveClass(
        'xl:grid-cols-5',
        'xl:items-stretch',
    );
    expect(screen.getByTestId('attendance-dashboard')).not.toHaveClass('md:grid-cols-2', 'lg:grid-cols-5');
    expect(screen.getByTestId('attendance-summary')).toHaveClass('grid-cols-2', 'xl:h-full');
    expect(screen.getByTestId('attendance-mobile-history')).toHaveClass('xl:hidden');
    expect(screen.getByTestId('attendance-history-table')).toHaveClass('hidden', 'xl:block');
    expect(screen.getByRole('button', { name: /clock in/i })).toHaveClass(
        'min-h-12',
        'w-full',
        'rounded-full',
    );
    expect(screen.getByText('Current Time').nextElementSibling).toHaveAttribute('aria-live', 'polite');
});
