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
    expect(source).toContain('data-erp-icon-action="true"');
    expect(source).toContain('data-semantic-color="success"');
    expect(source).toContain('Order Details');
    expect(source).toContain('aria-label="Process Order"');
    expect(source).toContain('onClick={() => handleProcessOrder(viewOrder)}');
    expect(source).toContain("/api/shop-owner/orders/${order.id}/status");
  });

  it('refreshes the next action after processing so the check opens shipping', () => {
    expect(source).toMatch(
      /status:\s*"processing"[\s\S]{0,100}availableActions:\s*\["shipped"\]\s+as\s+OrderAction\[\]/,
    );
  });

  it('does not expose a shop-owner mark delivered action', () => {
    expect(source).not.toContain('Mark Delivered');
    expect(source).not.toContain("handleThirdPartyDelivery(viewOrder, 'delivered')");
  });
});
