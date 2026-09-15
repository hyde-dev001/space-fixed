import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/JobOrders.tsx'), 'utf8');

describe('Manager Job Orders page contract', () => {
  it('uses the canonical order queue and exception-only reassignment flow', () => {
    expect(source).toContain('useManagerOrders');
    expect(source).toContain('fetchManagerOrderReplacements');
    expect(source).toContain('assignment_state');
    expect(source).toContain('lock_state');
    expect(source).toContain('reassignment_reason_label');
    expect(source).toContain('Confirm reassignment');
    expect(source).toContain('Reason');
  });

  it('does not expose routine assignment or takeover controls', () => {
    expect(source).not.toContain('Assign staff');
    expect(source).not.toContain('Takeover');
    expect(source).not.toContain('>Unassign<');
  });

  it('aligns filter actions with the overdue-only control', () => {
    const formStart = source.indexOf('<form onSubmit={(event) => event.preventDefault()}');
    const filterForm = source.slice(formStart, source.indexOf('</form>', formStart));

    expect(filterForm).toContain('xl:col-span-6 flex flex-wrap items-end justify-between gap-4');
    expect(filterForm).toContain('xl:col-span-6 flex flex-wrap items-center justify-end gap-2');
    expect(filterForm).not.toContain('<ManagerFilterActions>');
    expect(filterForm).not.toContain('Apply filters');
  });
});
