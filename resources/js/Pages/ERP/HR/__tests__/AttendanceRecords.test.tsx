import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AttendanceRecords from '../AttendanceRecords';

const state = vi.hoisted(() => ({
  props: {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: { data: [] },
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => state,
}));

vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));

beforeEach(() => {
  state.props = {
    auth: { erpActor: { ownerMode: true } },
    initialAttendance: { data: [] },
  };
});

afterEach(() => {
  cleanup();
});

it('mounts the attendance records page in owner mode', () => {
  render(<AttendanceRecords />);

  expect(screen.getByRole('heading', { name: 'Attendance Records' })).toBeInTheDocument();
});
