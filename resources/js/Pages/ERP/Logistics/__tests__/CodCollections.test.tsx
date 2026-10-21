import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CodCollections from '../CodCollections';

const mocks = vi.hoisted(() => ({
  codCollections: vi.fn(),
  submitCodRemittanceSupplement: vi.fn(),
  confirm: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <main>{children}</main> }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: mocks }));
vi.mock('@/utils/workflowFeedback', () => ({ workflowFeedback: mocks }));

const page = {
  summary: { cash_currently_held: '100.00', pending_remittance_count: 0 },
  pending_remittance: [],
  submitted_remittances: [{
    id: 21,
    reference: 'COD-REM-SHORTAGE',
    expected_amount: '100.00',
    submitted_amount: '100.00',
    received_amount: '96.00',
    cumulative_received_amount: '96.00',
    remaining_shortage_amount: '4.00',
    pending_supplement_amount: '0.00',
    available_supplement_amount: '4.00',
    variance_amount: '-4.00',
    status: 'disputed',
    submitted_at: '2026-10-10T09:00:00.000Z',
    dispute_reason: 'Short by PHP 4',
  }],
  settled_history: [],
};

describe('COD collection shortage submission', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { randomUUID: () => 'test-key' });
    mocks.codCollections.mockResolvedValue({ data: page });
    mocks.submitCodRemittanceSupplement.mockResolvedValue({});
    mocks.confirm.mockResolvedValue({ isConfirmed: true });
    mocks.success.mockResolvedValue(undefined);
    mocks.error.mockResolvedValue(undefined);
  });

  it('submits remaining cash against the disputed remittance without selecting collections', async () => {
    render(<CodCollections />);

    expect(await screen.findByText('COD-REM-SHORTAGE')).toBeInTheDocument();
    expect(screen.getByText('Short by PHP 4')).toBeInTheDocument();
    expect(screen.getByRole('spinbutton', { name: /Additional cash for COD-REM-SHORTAGE/i })).toHaveValue(4);

    fireEvent.click(screen.getByRole('button', { name: 'Submit remaining cash' }));

    await waitFor(() => expect(mocks.submitCodRemittanceSupplement).toHaveBeenCalledWith(
      21,
      '4.00',
      'cod-remittance-supplement:21:test-key',
    ));
    expect(mocks.confirm).toHaveBeenCalled();
    expect(mocks.success).toHaveBeenCalled();
  });
});
