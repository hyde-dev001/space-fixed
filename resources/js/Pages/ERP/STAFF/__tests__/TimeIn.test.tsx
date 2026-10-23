import type { PropsWithChildren } from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import TimeIn, { isClockInAllowedAtTime } from '../TimeIn';

const fetchMock = vi.fn();
const mediaDescriptors = new Map<[object, PropertyKey], PropertyDescriptor | undefined>();

const overrideDescriptor = (target: object, property: PropertyKey, descriptor: PropertyDescriptor) => {
    mediaDescriptors.set([target, property], Object.getOwnPropertyDescriptor(target, property));
    Object.defineProperty(target, property, descriptor);
};

const setupAttendanceApi = (checkInSucceeds = true) => {
    fetchMock.mockImplementation(async (url: string) => {
        if (url === '/api/staff/attendance/status') {
            return { ok: true, json: async () => ({}) };
        }
        if (url === '/api/staff/shop-hours/today') {
            return { ok: true, json: async () => ({ open: '00:00', close: '23:59', is_open: true }) };
        }
        if (url === '/api/staff/attendance/check-in') {
            if (!checkInSucceeds) {
                return { ok: false, json: async () => ({ message: 'Photo must be smaller than 5 MB.' }) };
            }
            return { ok: true, json: async () => ({ attendance: {} }) };
        }
        if (url === '/api/staff/attendance/my-lateness-stats') {
            return { ok: true, json: async () => ({}) };
        }
        return { ok: true, json: async () => ({ data: [] }) };
    });
};

const setupCamera = () => {
    const track = { stop: vi.fn() };
    const stream = { getTracks: () => [track] } as unknown as MediaStream;
    const getUserMedia = vi.fn().mockResolvedValue(stream);
    overrideDescriptor(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } });
    overrideDescriptor(HTMLCanvasElement.prototype, 'getContext', {
        configurable: true,
        value: () => ({ drawImage: vi.fn() }),
    });
    overrideDescriptor(HTMLCanvasElement.prototype, 'toBlob', {
        configurable: true,
        value: (callback: BlobCallback) => callback(new Blob(['camera image'], { type: 'image/jpeg' })),
    });
    overrideDescriptor(HTMLVideoElement.prototype, 'videoWidth', { configurable: true, value: 640 });
    overrideDescriptor(HTMLVideoElement.prototype, 'videoHeight', { configurable: true, value: 480 });
    overrideDescriptor(HTMLVideoElement.prototype, 'play', { configurable: true, value: vi.fn().mockResolvedValue(undefined) });
    overrideDescriptor(HTMLVideoElement.prototype, 'srcObject', {
        configurable: true,
        get() { return (this as HTMLVideoElement & { __srcObject?: MediaStream }).__srcObject; },
        set(value: MediaStream | null) { Object.defineProperty(this, '__srcObject', { configurable: true, value }); },
    });
    overrideDescriptor(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:clock-in-photo') });
    overrideDescriptor(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

    return { getUserMedia, track };
};

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
    for (const [[target, property], descriptor] of mediaDescriptors) {
        if (descriptor) Object.defineProperty(target, property, descriptor);
        else Reflect.deleteProperty(target, property);
    }
    mediaDescriptors.clear();
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

it('places clock out beside clock in and lunch below the action row', async () => {
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

        if (url === '/api/staff/shop-hours/today') {
            return response({ open: '08:00', close: '17:00', is_open: true });
        }

        return response({ data: [] });
    });

    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    const clockInButton = screen.getByRole('button', { name: /clock in/i });
    const actionGrid = clockInButton.parentElement;
    expect(actionGrid).not.toBeNull();

    const actionButtons = within(actionGrid as HTMLElement).getAllByRole('button');
    expect(actionButtons).toHaveLength(3);
    expect(actionButtons[0]).toHaveTextContent('Clock In');
    expect(actionButtons[1]).toHaveTextContent('Clock Out');
    expect(actionButtons[2]).toHaveTextContent('Start Lunch');
    expect(actionButtons[2]).toHaveClass('min-[420px]:col-span-2');
});

it('keeps attendance actions inside the history card', async () => {
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    const historyCard = screen.getByTestId('attendance-history-card');

    expect(within(historyCard).getByRole('button', { name: 'Overtime' })).toBeInTheDocument();
    expect(within(historyCard).getByRole('button', { name: 'Request Leave' })).toBeInTheDocument();
    expect(screen.getByTestId('attendance-actions')).toHaveClass('xl:justify-end');
    expect(within(historyCard).getByRole('button', { name: 'Overtime' })).toHaveClass('min-h-10', 'whitespace-nowrap');
    expect(within(historyCard).getByRole('button', { name: 'Request Leave' })).toHaveClass('min-h-10', 'whitespace-nowrap');
    expect(screen.getAllByRole('button', { name: 'Overtime' })).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: 'Request Leave' })).toHaveLength(1);
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

    const statusFilter = screen.getByRole('combobox', { name: 'Filter attendance history by status' });

    expect(statusFilter).toHaveClass('bg-white', 'text-gray-900', 'hover:bg-gray-100');
    expect(statusFilter).not.toHaveClass('bg-[#111111]');

    fireEvent.change(statusFilter, { target: { value: 'Late' } });

    expect(statusFilter).toHaveClass('bg-[#111111]', 'text-white', 'hover:bg-gray-200');

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

it('keeps the attendance page rendered behind the overtime modal', async () => {
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

    fireEvent.click(screen.getByRole('button', { name: 'Overtime' }));

    expect(screen.getByTestId('attendance-dashboard')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Request Overtime' })).toBeInTheDocument();
});

it('blocks clock in outside the shop clock-in window', () => {
    const shopHours = { open: '10:00', close: '20:00', is_open: true };

    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 9, 29), shopHours)).toBe(false);
    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 9, 30), shopHours)).toBe(true);
    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 20, 1), shopHours)).toBe(false);
});

it('allows the configured closing minute through second 59', () => {
    const shopHours = { open: '10:00', close: '20:00', is_open: true };

    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 20, 0, 59), shopHours)).toBe(true);
    expect(isClockInAllowedAtTime(new Date(2026, 8, 7, 20, 1, 0), shopHours)).toBe(false);
});

it('refreshes shop hours when the attendance tab regains focus', async () => {
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
    const initialShopHoursCalls = fetchMock.mock.calls.filter(
        ([url]) => url === '/api/staff/shop-hours/today',
    ).length;

    fireEvent(window, new Event('focus'));

    await waitFor(() => {
        expect(
            fetchMock.mock.calls.filter(([url]) => url === '/api/staff/shop-hours/today'),
        ).toHaveLength(initialShopHoursCalls + 1);
    });
});

it('requires an explicit camera click and has no prompt preference checkbox', async () => {
    setupAttendanceApi();
    const { getUserMedia, track } = setupCamera();
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
    fireEvent.click(screen.getByRole('button', { name: /clock in/i }));

    expect(screen.getByRole('dialog', { name: 'Photo required to clock in' })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(getUserMedia).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Open Camera' }));
    await waitFor(() => expect(getUserMedia).toHaveBeenCalledOnce());
    expect(getUserMedia).toHaveBeenCalledWith({ video: { facingMode: 'user' }, audio: false });

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(track.stop).toHaveBeenCalledOnce();
});

it('lets an employee close the first required-photo prompt without sending a check-in', async () => {
    setupAttendanceApi();
    const { getUserMedia } = setupCamera();
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
    fireEvent.click(screen.getByRole('button', { name: /clock in/i }));

    expect(screen.getByRole('dialog', { name: 'Photo required to clock in' })).toBeInTheDocument();
    expect(getUserMedia).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(fetchMock.mock.calls.some(([url]) => url === '/api/staff/attendance/check-in')).toBe(false);
});

it('requires a camera photo and submits it with the clock-in request', async () => {
    setupAttendanceApi();
    const { getUserMedia, track } = setupCamera();
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
    fireEvent.click(screen.getByRole('button', { name: /clock in/i }));
    expect(getUserMedia).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Open Camera' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Take Photo' })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Take Photo' }));
    await waitFor(() => expect(screen.getByRole('img', { name: 'Clock-in photo preview' })).toBeInTheDocument());
    expect(track.stop).toHaveBeenCalledOnce();
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Photo required to clock in' })).getByRole('button', { name: 'Clock In' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
        '/api/staff/attendance/check-in',
        expect.objectContaining({ method: 'POST', body: expect.any(FormData) }),
    ));

    const request = fetchMock.mock.calls.find(([url]) => url === '/api/staff/attendance/check-in');
    const formData = request?.[1]?.body as FormData;
    expect(formData.get('photo')).toBeInstanceOf(File);
    expect(formData.has('dismiss_camera_prompt')).toBe(false);
    expect(screen.queryByRole('dialog', { name: 'Photo required to clock in' })).not.toBeInTheDocument();
});

it('keeps the captured photo available when the check-in request fails', async () => {
    setupAttendanceApi(false);
    setupCamera();
    render(<TimeIn />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
    fireEvent.click(screen.getByRole('button', { name: /clock in/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Open Camera' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Take Photo' })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Take Photo' }));
    await waitFor(() => expect(screen.getByRole('img', { name: 'Clock-in photo preview' })).toBeInTheDocument());
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Photo required to clock in' })).getByRole('button', { name: 'Clock In' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Photo must be smaller than 5 MB.');
    expect(screen.getByRole('img', { name: 'Clock-in photo preview' })).toBeInTheDocument();
});
