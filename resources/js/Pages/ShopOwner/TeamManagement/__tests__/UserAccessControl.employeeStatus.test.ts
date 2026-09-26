import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx'),
  'utf8',
);

describe('shop-owner team employee account states', () => {
  it('uses canonical account states and normalizes legacy presentation values', () => {
    expect(source).toContain("type EmployeeStatus = 'active' | 'inactive' | 'suspended' | 'terminated';");
    expect(source).toContain('status: canonicalEmployeeStatus(emp.status),');
    expect(source).not.toContain("status: 'active' | 'inactive';");
  });
  it('uses read-only employee details and keeps password reset in the access-control flow', () => {
    expect(source).toContain('View Details');
    expect(source).toContain('reset-password');
    expect(source).toContain('Personal email unavailable');
    expect(source).not.toContain('Edit Employee');
    expect(source).not.toContain('handleEditEmployee');
    expect(source).not.toContain('openEditEmployeeModal');
  });
});
