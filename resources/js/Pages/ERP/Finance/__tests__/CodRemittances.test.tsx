import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CodRemittances from '../CodRemittances';

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  isAxiosError: vi.fn(() => false),
  confirm: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <main>{children}</main> }));
vi.mock('axios', () => ({ default: mocks }));
vi.mock('@/utils/workflowFeedback', () => ({ workflowFeedback: mocks }));

const remittance = {
  id: 21,
  reference: 'COD-REM-SHORTAGE',
  rider_name: 'Rider A',
  expected_amount: '100.00',
  submitted_amount: '100.00',
  received_amount: '96.00',
  cumulative_received_amount: '96.00',
  remaining_shortage_amount: '4.00',
  pending_supplement_amount: '3.00',
  available_supplement_amount: '1.00',
  variance_amount: '-4.00',
  status: 'disputed',
  submitted_at: '2026-10-10T09:00:00.000Z',
  dispute_reason: 'Short by PHP 4',
  supplements: [{
    id: 33,
    submitted_amount: '3.00',
    received_amount: null,
    submitted_at: '2026-10-10T10:00:00.000Z',
    submitted_by_name: 'Rider A',
    confirmed_at: null,
    confirmed_by_name: null,
    dispute_reason: null,
    pending: true,
  }],
  items: [],
};

describe('Finance COD remittance supplements', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.get.mockResolvedValue({ data: { data: [remittance] } });
    mocks.post.mockResolvedValue({ data: { message: 'Additional cash confirmed; the shortage remains disputed.', remittance: { status: 'disputed' } } });
    mocks.confirm.mockResolvedValue({ isConfirmed: true });
    mocks.success.mockResolvedValue(undefined);
    mocks.error.mockResolvedValue(undefined);
  });

  it('shows supplemental history and confirms the physically received amount', async () => {
    render(<CodRemittances />);

    expect(await screen.findByText(/Submitted.*3\.00/)).toBeInTheDocument();
    const received = screen.getByRole('spinbutton', { name: 'Physical cash received' });
    fireEvent.change(received, { target: { value: '2.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm supplemental cash' }));

    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      '/api/finance/cod-remittances/21/supplements/33/confirm',
      { received_amount: '2.00', dispute_reason: undefined },
    ));
    expect(mocks.confirm).toHaveBeenCalled();
    expect(mocks.success).toHaveBeenCalled();
  });
});
