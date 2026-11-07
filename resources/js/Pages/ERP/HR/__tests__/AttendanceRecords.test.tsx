import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AttendanceRecords from '../AttendanceRecords';

const state = vi.hoisted(() => ({
  reload: vi.fn(),
  props: {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: { data: [] },
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => state,
  router: { reload: state.reload },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));

beforeEach(() => {
  state.reload.mockReset();
  state.props = {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: { data: [] },
  };
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

it('mounts the attendance records page in owner mode', () => {
  render(<AttendanceRecords />);

  expect(screen.getByRole('heading', { name: 'Attendance Records' })).toBeInTheDocument();
});

it('shows the newest owner attendance record first', () => {
  state.props = {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: {
      data: [
        {
          id: 4200,
          date: '2026-10-22',
          status: 'present',
          check_in_time: '08:00:00',
          employee: { first_name: 'Aaron', last_name: 'Older', position: 'Sales Associate' },
        },
        {
          id: 4201,
          date: '2026-10-23',
          status: 'present',
          check_in_time: '09:15:00',
          employee: { first_name: 'Zoe', last_name: 'Newest', position: 'Sales Associate' },
        },
        {
          id: 4202,
          date: '2026-10-23',
          status: 'present',
          check_in_time: '10:15:00',
          employee: { first_name: 'Maya', last_name: 'Latest', position: 'Sales Associate' },
        },
      ],
    },
  };

  render(<AttendanceRecords />);

  expect(screen.getAllByRole('row')[1]).toHaveTextContent('Maya Latest');
});

it('refreshes owner attendance records while the page is open', async () => {
  const latestRecords = [
    {
      id: 4201,
      date: '2026-10-23',
      status: 'present',
      check_in_time: '09:15:00',
      employee: { first_name: 'Zoe', last_name: 'Newest', position: 'Sales Associate' },
    },
    {
      id: 4200,
      date: '2026-10-22',
      status: 'present',
      check_in_time: '08:00:00',
      employee: { first_name: 'Aaron', last_name: 'Older', position: 'Sales Associate' },
    },
  ];
  state.props = {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: { data: [latestRecords[1]] },
  };
  state.reload.mockImplementation((options: any) => {
    options.onSuccess?.({ props: { initialAttendance: { data: latestRecords } } });
  });

  vi.useFakeTimers();
  render(<AttendanceRecords />);

  await act(async () => {
    vi.advanceTimersByTime(30_000);
  });

  expect(state.reload).toHaveBeenCalledWith(expect.objectContaining({
    only: ['initialAttendance'],
    preserveScroll: true,
  }));
  expect(screen.getAllByRole('row')[1]).toHaveTextContent('Zoe Newest');
});

it('shows a captured clock-in photo in the HR attendance receipt', () => {
  state.props = {
    auth: { erpActor: { ownerMode: false } },
    initialAttendance: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
      data: [{
        id: 4157,
        date: '2026-10-13',
        status: 'present',
        check_in_time: '08:00:00',
        employee: { first_name: 'Thomas', last_name: 'Rodriguez', position: 'Technician', department: 'Repair' },
        has_check_in_photo: true,
      }],
    },
  };

  render(<AttendanceRecords />);
  fireEvent.click(screen.getByRole('button', { name: 'View attendance details for Thomas Rodriguez' }));

  expect(screen.getByRole('img', { name: 'Check-in photo for Thomas Rodriguez' })).toHaveAttribute(
    'src',
    '/api/hr/attendance/4157/check-in-photo',
  );
});

it('shows a captured clock-in photo in the shop owner attendance receipt', () => {
  state.props = {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
      data: [{
        id: 4160,
        date: '2026-10-13',
        status: 'present',
        check_in_time: '08:00:00',
        employee: { first_name: 'Marco', last_name: 'Santos', position: 'Rider', department: 'Logistics' },
        has_check_in_photo: true,
      }],
    },
  };

  render(<AttendanceRecords />);
  fireEvent.click(screen.getByRole('button', { name: 'View attendance details for Marco Santos' }));

  expect(screen.getByRole('img', { name: 'Check-in photo for Marco Santos' })).toHaveAttribute(
    'src',
    '/api/shop-owner/erp/hr/attendance/4160/check-in-photo',
  );
});

it('labels legacy attendance receipts without a photo', () => {
  state.props = {
    auth: { erpActor: { ownerMode: false } },
    initialAttendance: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
      data: [{
        id: 4158,
        date: '2026-10-13',
        status: 'present',
        check_in_time: '08:00:00',
        employee: { first_name: 'David', last_name: 'Williams', position: 'HR Specialist', department: 'HR' },
      }],
    },
  };

  render(<AttendanceRecords />);
  fireEvent.click(screen.getByRole('button', { name: 'View attendance details for David Williams' }));

  expect(screen.getByText('No check-in photo')).toBeInTheDocument();
});

it('shows the unavailable message if a private attendance photo cannot be loaded', () => {
  state.props = {
    auth: { erpActor: { ownerMode: false } },
    initialAttendance: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
      data: [{
        id: 4159,
        date: '2026-10-13',
        status: 'present',
        check_in_time: '08:00:00',
        employee: { first_name: 'Jessica', last_name: 'Garcia', position: 'Sales Associate', department: 'Sales' },
        has_check_in_photo: true,
      }],
    },
  };

  render(<AttendanceRecords />);
  fireEvent.click(screen.getByRole('button', { name: 'View attendance details for Jessica Garcia' }));
  fireEvent.error(screen.getByRole('img', { name: 'Check-in photo for Jessica Garcia' }));

  expect(screen.getByText('No check-in photo')).toBeInTheDocument();
});

it('does not keep a stale result total after a filtered attendance request fails', async () => {
  vi.spyOn(console, 'error').mockImplementation(() => {});
  const record = {
    id: 4300,
    date: '2026-10-23',
    status: 'present',
    check_in_time: '08:00:00',
    employee: { first_name: 'Alex', last_name: 'Garcia', position: 'Staff' },
  };
  const fetchMock = vi.fn()
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({ data: [record], current_page: 1, last_page: 3, per_page: 7, total: 21 }),
    })
    .mockResolvedValueOnce({ ok: false, status: 500, text: async () => 'Unable to load attendance' });
  vi.stubGlobal('fetch', fetchMock);
  state.props = { auth: { erpActor: { ownerMode: false } } };

  render(<AttendanceRecords />);
  await screen.findByText('Alex Garcia');

  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: 'late' } });

  await waitFor(() => expect(screen.getByText('No attendance records found')).toBeInTheDocument());

  expect(screen.queryByText('21', { selector: 'span.font-medium' })).not.toBeInTheDocument();
});

it('ignores an older attendance request after a newer filter response arrives', async () => {
  vi.spyOn(console, 'error').mockImplementation(() => {});
  const apiResponse = (employee: string) => ({
    ok: true,
    json: async () => ({
      data: [{
        id: employee === 'Filtered' ? 4302 : 4301,
        date: '2026-10-23',
        status: 'present',
        check_in_time: '08:00:00',
        employee: { first_name: employee, last_name: 'Employee', position: 'Staff' },
      }],
      current_page: 1,
      last_page: 3,
      per_page: 7,
      total: 21,
    }),
  });
  let resolveOldRequest: ((response: any) => void) | undefined;
  const oldRequest = new Promise((resolve) => { resolveOldRequest = resolve; });
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(apiResponse('Initial'))
    .mockReturnValueOnce(oldRequest)
    .mockResolvedValueOnce(apiResponse('Filtered'));
  vi.stubGlobal('fetch', fetchMock);
  state.props = { auth: { erpActor: { ownerMode: false } } };

  render(<AttendanceRecords />);
  await screen.findByText('Initial Employee');
  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: 'late' } });
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

  fireEvent.change(screen.getAllByRole('combobox')[0], { target: { value: 'present' } });
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
  await screen.findByText('Filtered Employee');

  await act(async () => {
    resolveOldRequest?.({ ok: false, status: 500, text: async () => 'Stale request failed' });
  });

  expect(screen.getByText('Filtered Employee')).toBeInTheDocument();
});
