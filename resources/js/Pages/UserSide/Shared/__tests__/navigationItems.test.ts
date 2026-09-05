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

  it('removes Home from both guest and authenticated primary navigation', () => {
    expect(getCustomerNavItems(false).map((item) => item.label)).not.toContain('Home');
    expect(getCustomerNavItems(true).map((item) => item.label)).not.toContain('Home');
  });

  it('adds authenticated My Orders and My Repairs links without putting Download in primary navigation', () => {
    const labels = getCustomerNavItems(true).map((item) => item.label);

    expect(labels.slice(-2)).toEqual(['My Orders', 'My Repairs']);
    expect(labels).not.toContain('Download');
  });
});
