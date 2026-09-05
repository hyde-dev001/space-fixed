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

  it('reveals order filters, states, and dynamically loaded order cards', () => {
    expect(source).toContain("import { useScrollReveal } from '../Shared/useScrollReveal';");
    expect(source).toContain('const revealRootRef = useRef<HTMLDivElement | null>(null);');
    expect(source).toContain('useScrollReveal(revealRootRef);');
    expect(source).toContain('ref={revealRootRef}');
    expect(source.match(/data-scroll-reveal/g)?.length ?? 0).toBeGreaterThanOrEqual(3);
    expect(source).toContain('data-order-id={order.id}');
    expect(source).toContain('className={`scroll-reveal border overflow-hidden');
  });
});
