import { describe, expect, it } from 'vitest';
import { getCustomerNavItems } from '../navigationItems';

describe('customer navigation items', () => {
  it('shows Services in the guest navbar', () => {
    const labels = getCustomerNavItems(false).map((item) => item.label);

    expect(labels).toContain('Services');
    expect(labels).toContain('ACCOUNT');
  });

  it('removes Services and ACCOUNT from the authenticated navbar', () => {
    const labels = getCustomerNavItems(true).map((item) => item.label);

    expect(labels).not.toContain('Services');
    expect(labels).not.toContain('ACCOUNT');
  });
});
