import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/AuditLogs.tsx'), 'utf8');

describe('Manager Audit Logs page contract', () => {
  it('uses the canonical Manager audit endpoint and traceability fields', () => {
    expect(source).toContain('/api/manager/audit-logs');
    expect(source).toContain('previous_state');
    expect(source).toContain('new_state');
    expect(source).toContain('reference_id');
    expect(source).toContain('correlation_id');
    expect(source).toContain('last_updated_at');
  });

  it('uses business-friendly audit presentation and simplified filters', () => {
    expect(source).toContain('action_label');
    expect(source).toContain('display_description');
    expect(source).toContain('type_label');
    expect(source).toContain('Activity');
    expect(source).toContain('Record / reference');
    expect(source).not.toContain('Actor ID');
    expect(source).not.toContain('Target ID');
    expect(source).not.toContain('filters.actor_id');
    expect(source).not.toContain('filters.target_id');
    expect(source).not.toContain('Correlation:');
  });

  it('does not present invented trend percentages or an unrestricted oversight claim', () => {
    expect(source).not.toContain('change={12}');
    expect(source).not.toContain('change={8}');
    expect(source).not.toContain('change={15}');
    expect(source).not.toContain('change={5}');
    expect(source).not.toContain('Complete oversight of all activities across departments');
  });
});
