import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/HR/EmployeeDirectory.tsx'),
  'utf8',
);
const typesSource = readFileSync(join(process.cwd(), 'resources/js/types/hr.ts'), 'utf8');

describe('shop-owner HR employee directory routes', () => {
  it('uses the owner-scoped projection and keeps employee creation available', () => {
    expect(source).toContain("const ownerMode = auth?.erpActor?.ownerMode === true;");
    expect(source).toContain('const ownerReadOnly = ownerMode;');
    expect(source).toContain('const ownerCanCreate = ownerMode;');
    expect(source).toContain('Review your shop workforce and add employees without changing account permissions.');
    expect(source).toContain('{(!ownerReadOnly || ownerCanCreate) && (');
    expect(source).toContain("const employeeApiBase = ownerMode ? '/shop-owner/employees' : '/api/hr/employees';");
    expect(source).toContain('if (ownerMode) return;');
  });

  it('uses canonical account states and keeps leave and probation separate', () => {
    expect(source).toContain('type EmployeeStatus = "active" | "inactive" | "suspended" | "terminated";');
    expect(source).not.toContain('"on_leave" | "probation"');
    expect(source).not.toContain('setFilterStatus("on_leave")');
    expect(source).not.toContain('setFilterStatus("probation")');
    expect(source).toContain('onLeave?: boolean;');
    expect(source).toContain('probation?: boolean;');
    expect(typesSource).toContain("status: 'active' | 'inactive' | 'suspended' | 'terminated';");
    expect(typesSource).not.toContain("'on-leave'");
  });
});
