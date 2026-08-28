import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/Reports.tsx'), 'utf8');

describe('Manager Reports page contract', () => {
  it('uses review semantics when no delivery workflow exists', () => {
    expect(source).toContain('Mark as reviewed');
    expect(source).toContain('/review');
    expect(source).toContain('reports_reviewed');
    expect(source).not.toContain('Generate & Send');
    expect(source).not.toContain('sent to the shop owner');
  });

  it('keeps CRM complaints outside the Manager report picker', () => {
    expect(source).not.toContain('Customer Complaints');
    expect(source).not.toContain('complaints');
  });
});
