import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/SuspensionApprovals.tsx'), 'utf8');

describe('Manager suspension approval feedback contract', () => {
  it('does not use native confirmation or alert dialogs', () => {
    expect(source).not.toContain('window.confirm');
    expect(source).not.toContain('window.alert');
    expect(source).toContain('workflowFeedback.confirm');
    expect(source).toContain('workflowFeedback.error');
  });
});
