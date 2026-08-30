import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve(process.cwd(), 'resources/js/Pages/UserSide/Repairs/myRepairs.tsx'), 'utf8');

describe('My Repairs page layout', () => {
  it('keeps the page top focused on repair filters', () => {
    expect(source).not.toContain('Track every request, payment, and pickup update in one place.');
    expect(source).not.toContain('>Repairs</h1>');
    expect(source).toContain('REPAIR_TABS.map');
  });
});
