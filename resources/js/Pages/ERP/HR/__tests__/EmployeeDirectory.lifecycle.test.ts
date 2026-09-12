import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ERP/HR/EmployeeDirectory.tsx'),
  'utf8',
);

describe('employee termination and rehire directory workflow', () => {
  it('submits explicit lifecycle requests and collects new employment terms for rehire', () => {
    expect(source).toContain("endpoint: '/api/hr/termination-requests'");
    expect(source).toContain("endpoint: '/api/hr/rehire-requests'");
    expect(source).toContain('Request Termination');
    expect(source).toContain('Request Rehire');
    expect(source).toContain('Rehire Pending');
    expect(source).not.toContain('rehire_start_date');
    expect(source).toContain('Department / Role');
    expect(source).toContain('Approval Process');
    expect(source).toContain('Employment History');
  });

  it('keeps the rehire form aligned with Add Employee inputs', () => {
    const rehireModal = source.slice(
      source.indexOf('{isRehireRequestModalOpen &&'),
      source.indexOf('{/* Add Employee Modal */}'),
    );

    expect(rehireModal).toContain('Email');
    expect(rehireModal).toContain('Phone');
    expect(rehireModal).toContain('Department / Role');
    expect(rehireModal).toContain('Position / Job Title');
    expect(rehireModal).toContain('system generates the effective hired date');
    expect(rehireModal).not.toContain('Hired Date');
    expect(rehireModal).not.toContain('rehireStartDate');
    expect(rehireModal).toContain('Daily Rate');
    expect(rehireModal).toContain('Reason for Rehire');
    expect(rehireModal).toContain('Evidence / Notes');
    expect(rehireModal).not.toContain('First Name');
    expect(rehireModal).not.toContain('Last Name');
    expect(rehireModal).not.toContain('Address');
    expect(rehireModal).not.toContain('Functional Role');
    expect(rehireModal).not.toContain('rehireFunctionalRole');
  });

  it('renders lifecycle actions as visible buttons in the directory', () => {
    const actionColumnStart = source.indexOf('<td className="px-3 py-3 align-top text-right');
    const actionColumn = source.slice(
      actionColumnStart,
      source.indexOf('</td>', actionColumnStart),
    );

    expect(source).toContain('const employeeActionButtonClass =');
    expect(source).toContain('inline-flex size-11 shrink-0 items-center justify-center');
    expect(source).toContain('w-[340px]');
    expect(source).toContain('w-full max-w-[340px]');
    expect(source).toContain('flex flex-wrap items-center justify-end gap-2');
    expect(actionColumn).toContain('<Button');
    expect(actionColumn).toContain('<IconButton');
    expect(actionColumn).toContain('variant="neutral"');
    expect(actionColumn).toContain('variant="primary"');
    expect(actionColumn).toContain('variant="warning"');
    expect(actionColumn).toContain('variant="danger"');
    expect(actionColumn).toContain('variant="success"');
    expect(actionColumn).toContain('Activate Account');
    expect(actionColumn).toContain('title="Request Rehire"');
    expect(actionColumn).toContain('aria-label={`Request rehire for ${buildName(employee)}`}');
    expect(actionColumn).toContain('<UserCheckIcon');
    expect(actionColumn).toContain('Rehire Pending');
    expect(actionColumn).toContain('title="Request Termination"');
    expect(actionColumn).toContain('aria-label={`Request termination for ${buildName(employee)}`}');
    expect(actionColumn).not.toContain('>\n                              Request Termination\n');
    expect(actionColumn).not.toContain('text-purple-600');
    expect(actionColumn).not.toContain('text-orange-600');
  });

  it('does not offer Activate Account for terminated employee rows', () => {
    expect(source).toContain("employee.status === 'terminated'");
    expect(source).toContain("['inactive', 'suspended'].includes(employee.status)");
    expect(source).toContain("onClick={() => handleRehireClick(employee)}");
    expect(source).toContain("onClick={() => handleTerminateClick(employee)}");
  });

  it('shows a contextual SweetAlert when a rehire request fails', () => {
    expect(source).toContain('errorTitle?: string;');
    expect(source).toContain('title: errorTitle');
    expect(source).toContain("errorTitle: 'Rehire Request Failed'");
    expect(source).toContain("icon: 'error'");
  });
});
