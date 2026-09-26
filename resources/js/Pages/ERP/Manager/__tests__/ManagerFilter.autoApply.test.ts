import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const pages = [
  'EmploymentLifecycleApprovals.tsx',
  'LeaveApprovals.tsx',
  'JobOrders.tsx',
  'RepairJobs.tsx',
  'StaffWorkload.tsx',
  'SuspensionApprovals.tsx',
];

describe('Manager filter pages', () => {
  for (const page of pages) {
    it(page + ' applies filters without a manual Apply button', () => {
      const source = readFileSync(
        join(process.cwd(), 'resources/js/Pages/ERP/Manager', page),
        'utf8',
      );

      expect(source).not.toMatch(/Apply filters/i);
      expect(source).not.toContain('const applyFilters');
      expect(source).toContain('useEffect');
      expect(source).toContain('setFilters');
    });
  }
});
