import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve(process.cwd(), 'resources/js/Pages/UserSide/Orders/MyOrders.tsx'), 'utf8');

describe('My Orders page layout', () => {
  it('keeps the page top focused on order filters', () => {
    expect(source).not.toContain('Manage deliveries, returns, and refunds with clear real-time order progress.');
    expect(source).not.toContain('>My Purchases</h1>');
    expect(source).toContain('ORDER_TABS.map');
  });
});
