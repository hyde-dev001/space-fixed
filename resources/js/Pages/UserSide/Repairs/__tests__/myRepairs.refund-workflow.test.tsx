import { describe, expect, it } from 'vitest';
import { refundStageLabel } from '../refundWorkflow';

describe('myRepairs refund workflow labels', () => {
  it('renders repairer-first refund stage badge on myRepairs timeline', async () => {
    const label = refundStageLabel({
      overall_status: 'requested',
      repairer_status: 'pending',
      finance_status: 'pending',
      shop_owner_status: 'pending',
    });

    expect(label).toBe('Under Repairer Review');
  });

  it('renders rejected when overall refund status is rejected', async () => {
    const label = refundStageLabel({
      overall_status: 'rejected',
      repairer_status: 'rejected',
      finance_status: 'pending',
      shop_owner_status: 'pending',
    });

    expect(label).toBe('Rejected');
  });

  it('moves to owner review after Finance gives initial approval', () => {
    const label = refundStageLabel({
      overall_status: 'requested',
      repairer_status: 'approved',
      finance_status: 'approved_initial',
      shop_owner_status: 'pending',
    });

    expect(label).toBe('Under Owner Review');
  });
});
