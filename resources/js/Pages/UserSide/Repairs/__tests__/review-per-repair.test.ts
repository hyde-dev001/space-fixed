import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const myRepairsSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/myRepairs.tsx'),
  'utf8',
);
const repairShowSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/repairShow.tsx'),
  'utf8',
);

describe('repair reviews are scoped to the selected repair request', () => {
  it('passes the selected repair request to the shop page', () => {
    expect(myRepairsSource).toContain("review_order_id: String(order.id)");
  });

  it('uses the per-repair review endpoints on the shop page', () => {
    expect(repairShowSource).toContain("get('review_order_id')");
    expect(repairShowSource).toContain('/api/customer/repairs/${reviewOrderId}/can-review');
    expect(repairShowSource).toContain('/api/customer/repairs/${reviewOrderId}/review');
    expect(repairShowSource).not.toContain('/api/shops/${shop.id}/reviews/check-eligibility');
    expect(repairShowSource).not.toContain('You have already reviewed this shop');
  });
});
