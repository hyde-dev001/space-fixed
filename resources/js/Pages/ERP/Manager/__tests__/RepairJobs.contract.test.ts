import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/RepairJobs.tsx'), 'utf8');

describe('Manager Repair Jobs page contract', () => {
  it('uses the canonical repair queue and supports rejection/unavailability decisions', () => {
    expect(source).toContain('useManagerRepairJobs');
    expect(source).toContain('fetchManagerRepairerOptions');
    expect(source).toContain('repairer_workload');
    expect(source).toContain('review_state');
    expect(source).toContain('Forward to Shop Owner');
    expect(source).toContain('Confirm final rejection');
    expect(source).toContain('Confirm reassignment');
  });

  it('does not expose Manager physical repair or routine balancing controls', () => {
    expect(source).not.toContain('Manager takeover');
    expect(source).not.toContain('Unassign');
    expect(source).not.toContain('Balance workload');
  });
});
