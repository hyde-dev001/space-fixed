import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/HR/EmployeeDirectory.tsx'),
  'utf8',
);

describe('employee termination and rehire directory workflow', () => {
  it('submits explicit lifecycle requests and collects new employment terms for rehire', () => {
    expect(source).toContain("endpoint: '/api/hr/termination-requests'");
    expect(source).toContain("endpoint: '/api/hr/rehire-requests'");
    expect(source).toContain('Request Termination');
    expect(source).toContain('Request Rehire');
    expect(source).toContain('New Start Date');
    expect(source).toContain('New Account Role');
    expect(source).toContain('Previous permissions are not restored automatically');
    expect(source).toContain('Employment History');
  });

  it('does not offer Activate Account for terminated employee rows', () => {
    expect(source).toContain("employee.status === 'terminated'");
    expect(source).toContain("['inactive', 'suspended'].includes(employee.status)");
    expect(source).toContain("onClick={() => handleRehireClick(employee)}");
    expect(source).toContain("onClick={() => handleTerminateClick(employee)}");
  });
});
