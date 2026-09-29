import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MaintenanceIndex from '../Index';

const { routerGet, routerPost, confirmAlert } = vi.hoisted(() => ({
  routerGet: vi.fn(),
  routerPost: vi.fn(),
  confirmAlert: vi.fn(),
}));
const pageState = vi.hoisted(() => ({
  props: {
    current: null,
    upcoming: null,
    history: [],
    filters: { status: '', search: '', date_from: '', date_to: '', per_page: 25 },
    pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    status_counts: { draft: 0, scheduled: 0, active: 0, ended: 0, cancelled: 0 },
    can_manage: false,
  },
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { get: routerGet, post: routerPost },
  usePage: () => pageState,
}));

vi.mock('../../../../utils/workflowFeedback', () => ({
  workflowFeedback: { confirm: confirmAlert },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

const windowRecord = {
  id: 42,
  title: 'Platform upgrade',
  public_message: 'A short maintenance window.',
  internal_note: 'Internal note',
  status: 'scheduled',
  state: 'scheduled',
  starts_at: '2026-09-14T15:00:00.000000Z',
  ends_at: '2026-09-14T15:30:00.000000Z',
  activated_at: null,
  ended_at: null,
  cancelled_at: null,
  notify_before_minutes: 15,
  transaction_freeze_minutes: 3,
  progress_stage: null,
  public_update_message: null,
  public_update_updated_at: null,
  version: 1,
};

beforeEach(() => {
  routerGet.mockReset();
  routerPost.mockReset();
  confirmAlert.mockReset();
  confirmAlert.mockResolvedValue({ isConfirmed: true });
  pageState.props = {
    current: windowRecord,
    upcoming: null,
    history: [windowRecord],
    filters: { status: '', search: '', date_from: '', date_to: '', per_page: 25 },
    pagination: { current_page: 1, last_page: 2, per_page: 25, total: 26 },
    status_counts: { draft: 0, scheduled: 1, active: 0, ended: 0, cancelled: 0 },
    can_manage: false,
  };
});

describe('Super Admin maintenance page', () => {
  it('renders the current summary, history, and read-only mode', () => {
    render(<MaintenanceIndex />);

    expect(screen.getByRole('heading', { name: 'System Maintenance' })).toBeInTheDocument();
    expect(screen.getAllByText('Platform upgrade').length).toBeGreaterThan(0);
    expect(screen.getByText('Maintenance history')).toBeInTheDocument();
    expect(screen.getByText('Read-only access')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /start maintenance now/i })).not.toBeInTheDocument();
  });

  it('automatically applies server-backed filters and preserves the current page contract', () => {
    vi.useFakeTimers();
    render(<MaintenanceIndex />);

    fireEvent.change(screen.getByLabelText('Text search'), { target: { value: 'upgrade' } });
    vi.advanceTimersByTime(250);

    expect(routerGet).toHaveBeenLastCalledWith(
      '/admin/maintenance',
      { search: 'upgrade' },
      { preserveState: true, preserveScroll: true, replace: true },
    );

    fireEvent.change(screen.getByLabelText('Date from'), { target: { value: '2026-09-14' } });

    expect(routerGet).toHaveBeenLastCalledWith(
      '/admin/maintenance',
      { search: 'upgrade', date_from: '2026-09-14' },
      { preserveState: true, preserveScroll: true, replace: true },
    );

    vi.useRealTimers();
  });

  it('shows lifecycle controls only with the manage capability', () => {
    pageState.props.can_manage = true;
    render(<MaintenanceIndex />);

    expect(screen.getByRole('button', { name: /start maintenance now/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /clear filters/i })).toBeInTheDocument();
  });

  it('uses the branded confirmation workflow before starting maintenance', async () => {
    pageState.props.can_manage = true;
    render(<MaintenanceIndex />);

    fireEvent.change(screen.getByLabelText('Emergency title'), { target: { value: 'Emergency update' } });
    fireEvent.change(screen.getByLabelText('Public message'), { target: { value: 'We are applying an urgent update.' } });
    fireEvent.change(screen.getByLabelText('Estimated end (Manila)'), { target: { value: '2026-09-14T23:00' } });
    fireEvent.submit(screen.getByRole('button', { name: /start maintenance now/i }).closest('form')!);

    expect(confirmAlert).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Start platform maintenance now?',
    }));
    await waitFor(() => expect(routerPost).toHaveBeenCalledWith(
      '/admin/maintenance/start-now',
      expect.objectContaining({ title: 'Emergency update' }),
      { preserveScroll: true },
    ));
  });

  it('submits a scheduled window so users can receive advance notice', () => {
    pageState.props.can_manage = true;
    render(<MaintenanceIndex />);

    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Planned update' } });
    fireEvent.change(screen.getByLabelText('Scheduled public message'), { target: { value: 'Maintenance is planned.' } });
    fireEvent.change(screen.getByLabelText('Starts (Manila)'), { target: { value: '2026-09-15T10:00' } });
    fireEvent.change(screen.getByLabelText('Expected end (Manila)'), { target: { value: '2026-09-15T11:00' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Schedule maintenance' }).closest('form')!);

    expect(routerPost).toHaveBeenCalledWith(
      '/admin/maintenance',
      expect.objectContaining({
        title: 'Planned update',
        starts_at: expect.any(String),
        ends_at: expect.any(String),
        notify_before_minutes: 15,
        transaction_freeze_minutes: 3,
      }),
      { preserveScroll: true },
    );
  });
});
