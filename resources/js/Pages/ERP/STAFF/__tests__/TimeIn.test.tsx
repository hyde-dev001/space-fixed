import type { PropsWithChildren } from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
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
    expect(screen.getByRole('heading', { name: 'Attendance Tracking' })).toHaveClass('sr-only');
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

it('does not offer another lunch start after the employee ends lunch', async () => {
    const response = (body: unknown) => ({
        ok: true,
        json: async () => body,
    });

    fetchMock.mockImplementation(async (url: string) => {
        if (url === '/api/staff/attendance/status') {
            return response({
                checked_in: true,
                checked_out: false,
                check_in_time: '08:00',
                check_out_time: null,
                lunch_break_start: null,
                lunch_break_end: null,
                is_on_lunch: false,
            });
        }

        if (url === '/api/staff/attendance/my-records') {
            return response({ data: [] });
        }

        if (url === '/api/staff/shop-hours/today') {
            return response({ open: '08:00', close: '17:00', is_open: true });
        }

        if (url === '/api/staff/attendance/my-lateness-stats') {
            return response({});
        }

        if (url === '/api/staff/overtime/today-approved') {
            return response({ data: [] });
        }

        if (url === '/api/staff/attendance/lunch-start') {
            return response({ lunch_break_start: '12:00' });
        }

        if (url === '/api/staff/attendance/lunch-end') {
            return response({ lunch_break_end: '13:00' });
        }

        return response({});
    });

    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    fireEvent.click(screen.getByRole('button', { name: /start lunch/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /end lunch/i })).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /end lunch/i }));
    await waitFor(() => {
        expect(screen.queryByRole('button', { name: /start lunch/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /end lunch/i })).not.toBeInTheDocument();
    });
});

it('disables clock in when the shop is closed and uses red styling for late records', async () => {
    const response = (body: unknown) => ({
        ok: true,
        json: async () => body,
    });

    fetchMock.mockImplementation(async (url: string) => {
        if (url === '/api/staff/attendance/my-records') {
            return response({
                data: [
                    {
                        date: '2026-09-02',
                        check_in_time: '10:18',
                        check_out_time: '20:08',
                        working_hours: 8.83,
                        status: 'late',
                        is_late: true,
                        minutes_late: 18,
                        expected_check_in: '10:00',
                    },
                ],
            });
        }

        if (url === '/api/staff/shop-hours/today') {
            return response({ open: '10:00', close: '20:00', is_open: false });
        }

        return response({ data: [] });
    });

    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    expect(screen.getByRole('button', { name: /clock in/i })).toBeDisabled();
    expect(screen.getAllByText('Late').some((node) => node.className.includes('bg-red-100'))).toBe(true);
});

it('keeps the leave modal and status filter monochrome', async () => {
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    expect(screen.getByRole('combobox', { name: 'Filter attendance history by status' })).toHaveClass(
        'bg-[#111111]',
        'text-white',
        'hover:bg-gray-100',
        'hover:text-gray-900',
    );

    fireEvent.click(screen.getByRole('button', { name: 'Request Leave' }));

    const dialog = screen.getByRole('dialog', { name: 'Request Leave' });
    expect(dialog.querySelector('[class*="blue-"]')).toBeNull();
    expect(screen.getByText('Request Summary')).toHaveClass('text-gray-900');
    expect(screen.getByRole('button', { name: 'Submit Request' })).toHaveClass('bg-[#111111]');
    expect(
        screen
            .getAllByRole('button', { name: /^Select \d{4}-\d{2}-\d{2}$/ })
            .some((button) => button.className.includes('bg-[#111111]')),
    ).toBe(true);
});
