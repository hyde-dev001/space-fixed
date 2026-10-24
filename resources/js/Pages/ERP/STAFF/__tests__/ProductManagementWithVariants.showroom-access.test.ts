import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx'),
  'utf8',
);

describe('Staff product showroom access', () => {
  it('gates showroom actions on an active showroom subscription', () => {
    const headerStart = source.indexOf('className="flex items-center justify-end gap-3"');
    const header = source.slice(headerStart, source.indexOf('+ Add New Product', headerStart));

    expect(source).toContain(
      'const canAccessShowroom = staffShopOwnerId > 0 && showroomEntitlement?.is_eligible === true && showroomEntitlement?.has_active_subscription === true;',
    );
    expect(header).toContain('Virtual Showroom');
    expect(header).toContain('Product Image Spin Tutorial');
    expect(header.match(/\{canAccessShowroom && \(/g) ?? []).toHaveLength(2);
  });
});
