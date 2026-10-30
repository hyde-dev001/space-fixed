import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/components/owner-action-center/OwnerApprovalDetailPanel.tsx'), 'utf8');

describe('Shop Owner approval feedback contract', () => {
  it('shows SweetAlert success and error feedback after lifecycle decisions', () => {
    expect(source).toContain('workflowFeedback.success');
    expect(source).toContain('workflowFeedback.error');
    expect(source).toContain('Approval successful');
    expect(source).toContain('Rejection successful');
  });
});
