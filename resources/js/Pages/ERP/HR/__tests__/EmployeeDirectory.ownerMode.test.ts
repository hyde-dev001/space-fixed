import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/HR/EmployeeDirectory.tsx'),
  'utf8',
);

describe('shop-owner HR employee directory routes', () => {
  it('selects owner-scoped employee, invitation, template, and permission endpoints', () => {
    expect(source).toContain("const ownerMode = auth?.erpActor?.ownerMode === true;");
    expect(source).toContain("const employeeApiBase = ownerMode ? '/shop-owner/employees' : '/api/hr/employees';");
    expect(source).toContain("const invitationApiBase = ownerMode ? '/api/shop-owner/employees' : '/api/hr/employees';");
    expect(source).toContain("ownerMode ? '/shop-owner/position-templates' : '/api/hr/position-templates'");
    expect(source).toContain("ownerMode ? '/shop-owner/permissions/available' : '/api/hr/permissions/available'");
    expect(source).toContain('if (ownerMode) return;');
  });
});
