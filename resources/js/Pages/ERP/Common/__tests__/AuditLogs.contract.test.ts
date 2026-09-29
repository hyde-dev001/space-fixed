import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Common/AuditLogs.tsx'), 'utf8');

describe('Shared ERP audit logs page contract', () => {
  it('uses business-facing labels instead of database terminology', () => {
    expect(source).toContain('action_label');
    expect(source).toContain('display_description');
    expect(source).toContain('entity_type_label');
    expect(source).toContain('Area');
    expect(source).not.toContain('Filter by module');
  });
});
