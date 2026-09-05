import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Repairs/myRepairs.tsx'),
  'utf8',
);

describe('My Repairs service modification integration', () => {
  it('offers modification only for accepted unpaid repairs and calls the update endpoint', () => {
    expect(source).toContain("order.status === 'repairer_accepted'");
    expect(source).toContain('order.conversation_id');
    expect(source).toContain("'down_payment_paid'");
    expect(source).toContain("'partially_paid'");
    expect(source).toContain("'partially_refunded'");
    expect(source).toContain('!order.payment_completed_at');
    expect(source).toContain('MODIFY');
    expect(source).toContain('Modify Repair Services');
    expect(source).toContain('/api/repair-services?shop_id=');
    expect(source).toContain("method: 'PATCH'");
    expect(source).toContain('/services`');
    expect(source).toContain('remove_package: Boolean(modifyOrder.repair_package_id)');
    expect(source).toContain('selectedModifyServiceIds.length === 0');
  });
});
