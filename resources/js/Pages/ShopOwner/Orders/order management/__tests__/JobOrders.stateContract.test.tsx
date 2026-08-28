import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx'),
  'utf8',
);
const presentationSource = readFileSync(
  join(process.cwd(), 'resources/js/utils/orderStatusPresentation.ts'),
  'utf8',
);

describe('shop owner order state contract', () => {
  it('declares and presents both terminal fulfillment outcomes', () => {
    expect(presentationSource).toMatch(/"pending"[\s\S]*"processing"[\s\S]*"shipped"[\s\S]*"delivered"[\s\S]*"completed"/);
    expect(source).toContain('ORDER_STATUS_PRESENTATION');
    expect(presentationSource).toContain('completed:');
    expect(presentationSource).toContain('shipped:');
  });

  it('uses server-provided action metadata for fulfillment controls', () => {
    expect(source).toContain('availableActions?:');
    expect(source).toContain("availableActions?.includes('processing')");
    expect(source).toContain("availableActions?.includes('shipped')");
    expect(source).not.toMatch(/order\.status\s*===\s*["']pending["'][\s\S]{0,120}Start processing/);
  });

  it('keeps company-owner orders read-only while preserving the details modal', () => {
    expect(source).toContain("const isIndividualRegistration = shopOwnerRegistrationType === 'individual';");
    expect(source).toContain("isIndividualRegistration && order.availableActions?.includes('processing')");
    expect(source).toContain("isIndividualRegistration && order.availableActions?.includes('shipped')");
    expect(source).toContain('isIndividualRegistration && viewOrder.status === "pending"');
    expect(source).toContain('isIndividualRegistration && viewOrder.status === "shipped"');
    expect(source).toContain('title="View order details"');
    expect(source).toContain('Monitor customer shoe orders');
    expect(source).not.toContain('Open Approval');
  });
});
