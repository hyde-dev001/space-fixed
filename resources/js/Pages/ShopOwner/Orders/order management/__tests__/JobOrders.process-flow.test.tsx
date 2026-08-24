import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx'),
  'utf8',
);

describe('shop owner order processing flow', () => {
  it('routes pending processing through the order details modal', () => {
    expect(source).toContain("onClick={() => handleViewOrder(order)}");
    expect(source).toContain('aria-label="Start processing"');
    expect(source).toContain('Order Details');
    expect(source).toContain('aria-label="Process Order"');
    expect(source).toContain('onClick={() => handleProcessOrder(viewOrder)}');
    expect(source).toContain("/api/shop-owner/orders/${order.id}/status");
  });
});
