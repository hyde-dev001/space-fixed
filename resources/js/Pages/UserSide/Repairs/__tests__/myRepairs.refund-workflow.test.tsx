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
});
