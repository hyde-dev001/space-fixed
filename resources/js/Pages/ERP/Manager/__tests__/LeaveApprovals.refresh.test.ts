import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const page = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/Manager/LeaveApprovals.tsx'),
  'utf8',
);
const hooks = readFileSync(
  join(process.cwd(), 'resources/js/hooks/useManagerApi.ts'),
  'utf8',
);

describe('Manager Leave Approvals refresh contract', () => {
  it('revalidates the list after decisions and keeps background refresh enabled', () => {
    expect(page).toContain('await approvals.refetch()');
    expect(page).toContain('Refreshing leave approvals');
    expect(hooks).toContain('manager-leave-approvals');
    expect(hooks).toContain('refetchInterval: 30000');
    expect(hooks).toContain('refetchOnWindowFocus: true');
  });
});
