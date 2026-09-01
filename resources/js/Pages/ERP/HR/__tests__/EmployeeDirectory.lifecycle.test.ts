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
    expect(source).toContain('Hired Date');
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
    expect(rehireModal).toContain('Hired Date');
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
    const actionColumn = source.slice(
      source.indexOf("{['inactive', 'suspended'].includes(employee.status) && ("),
      source.indexOf('{!ownerReadOnly && (', source.indexOf("{['inactive', 'suspended'].includes(employee.status) && (")),
    );

    expect(actionColumn).toContain('<Button');
    expect(actionColumn).toContain('variant="success"');
    expect(actionColumn).toContain('Activate Account');
    expect(actionColumn).toContain('Request Rehire');
    expect(actionColumn).toContain('Request Termination');
  });

  it('does not offer Activate Account for terminated employee rows', () => {
    expect(source).toContain("employee.status === 'terminated'");
    expect(source).toContain("['inactive', 'suspended'].includes(employee.status)");
    expect(source).toContain("onClick={() => handleRehireClick(employee)}");
    expect(source).toContain("onClick={() => handleTerminateClick(employee)}");
  });
});
