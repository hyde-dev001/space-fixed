import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { LeaveRequests } from '../LeaveApprovals';

const pageState = vi.hoisted(() => ({
  props: {
    auth: { erpActor: { ownerMode: false } },
    initialLeaveRequests: {
      data: [{
        id: 1,
        employee_id: 12,
        employee: { name: 'Test Employee', department: 'Operations' },
        leave_type: 'personal',
        start_date: '2026-09-10',
        end_date: '2026-09-10',
        no_of_days: 1,
        reason: 'Personal appointment',
        status: 'pending',
        created_at: '2026-09-02T00:00:00.000000Z',
      }],
      total: 1,
      current_page: 1,
      last_page: 1,
      per_page: 7,
    },
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => pageState,
}));

vi.mock('sweetalert2', () => ({
  default: { fire: vi.fn() },
}));

beforeEach(() => {
  pageState.props.auth = { erpActor: { ownerMode: false } };
});

afterEach(() => {
  cleanup();
});

describe('leave request review actions', () => {
  it('requires opening View Details before showing approval decisions', () => {
    render(<LeaveRequests />);

    expect(screen.getByTitle('View Details')).toBeInTheDocument();
    expect(screen.queryByTitle('Approve')).not.toBeInTheDocument();
    expect(screen.queryByTitle('Reject')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTitle('View Details'));

    expect(screen.getByRole('button', { name: /approve leave request/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /reject leave request/i })).toBeInTheDocument();
  });
});
