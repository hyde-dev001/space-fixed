import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/EmploymentLifecycleApprovals.tsx'), 'utf8');

describe('Manager employment lifecycle approvals page contract', () => {
  it('keeps termination and rehire decisions inside the details view', () => {
    const queue = source.slice(source.indexOf('requests.map'), source.indexOf('{selected &&'));
    const details = source.slice(source.indexOf('{selected &&'), source.indexOf('</AppLayoutERP>'));

    expect(queue).toContain('View details');
    expect(queue).not.toContain('Approve stage');
    expect(queue).not.toContain('Reject request');
    expect(details).toContain('Approve stage');
    expect(details).toContain('Reject request');
  });

  it('uses SweetAlert feedback for Manager lifecycle decisions', () => {
    expect(source).toContain('workflowFeedback.confirm');
    expect(source).toContain('workflowFeedback.success');
    expect(source).toContain('workflowFeedback.error');
    expect(source).not.toContain('window.confirm');
    expect(source).not.toContain('window.alert');
  });

  it('shows the rehire terms in the details view', () => {
    const details = source.slice(source.indexOf('{selected &&'), source.indexOf('</AppLayoutERP>'));

    expect(details).toContain('rehire_start_date');
    expect(details).toContain('rehire_position');
    expect(details).toContain('rehire_department');
    expect(details).toContain('rehire_role');
  });
});
