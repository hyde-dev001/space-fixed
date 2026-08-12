import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShopReports from '../ShopReports';

const { postMock, swalFireMock, usePageMock } = vi.hoisted(() => ({
  postMock: vi.fn(),
  swalFireMock: vi.fn(),
  usePageMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { post: postMock },
  usePage: () => usePageMock(),
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const report = (id: number, status = 'submitted') => ({
  id,
  reason: 'misconduct',
  reason_label: 'Poor Service Misconduct',
  description: 'A report with enough detail for the moderation fixture.',
  status,
  status_label: status,
  transaction_type: null,
  transaction_id: null,
  admin_notes: null,
  reviewed_at: null,
  ip_address: null,
  created_at: '2026-08-12 12:00:00',
  reporter: null,
});

const page = (openReportIds: number[]) => ({
  props: {
    shopGroups: [{
      shop_owner_id: 7,
      business_name: 'Sole Space',
      shop_email: 'owner@example.test',
      shop_status: 'approved',
      total_reports: openReportIds.length,
      open_reports: openReportIds.length,
      open_report_ids: openReportIds,
      latest_reason: 'Poor Service Misconduct',
      latest_date: '2026-08-12 12:00:00',
      pattern_flags: [],
      warning_strike: 0,
      warning_limit: 3,
      warnings_until_suspension: 3,
      next_warn_will_suspend: false,
      priority: 'normal' as const,
      reports: openReportIds.map((id) => report(id)),
    }],
    stats: { total_reports: openReportIds.length, pending_review: openReportIds.length, high_priority: 0, resolved: 0 },
  },
});

beforeEach(() => {
  postMock.mockReset();
  swalFireMock.mockReset();
  usePageMock.mockReset();
  usePageMock.mockReturnValue(page([10, 11, 12]));
  swalFireMock.mockResolvedValue({ isConfirmed: true });
  postMock.mockImplementation((_url: string, _data: unknown, options: { onSuccess?: () => void; onFinish?: () => void }) => {
    options.onSuccess?.();
    options.onFinish?.();
  });
});

describe('ShopReports exact-set moderation UI', () => {
  it('submits the server-visible open report IDs without client-side expansion', async () => {
    render(<ShopReports />);

    fireEvent.click(screen.getByRole('button', { name: 'Take Action' }));
    fireEvent.click(screen.getByRole('button', { name: 'Warn' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm Action' }));

    await waitFor(() => expect(postMock).toHaveBeenCalledWith(
      '/admin/shop-reports/7/action',
      {
        action: 'warn',
        report_ids: [10, 11, 12],
        admin_notes: '',
      },
      expect.any(Object),
    ));
  });

  it('does not render an action control for a group with no open reports', () => {
    usePageMock.mockReturnValue(page([]));

    render(<ShopReports />);

    expect(screen.queryByRole('button', { name: 'Take Action' })).not.toBeInTheDocument();
  });
});
