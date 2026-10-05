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
    expect(source).toContain('rehire_start_date');
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
    expect(rehireModal).toContain('requested start date is reviewed through the approval process');
    expect(rehireModal).toContain('New Start Date');
    expect(rehireModal).toContain('rehireStartDate');
    expect(rehireModal).not.toContain('Hired Date');
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
    expect(source).toContain('flex w-full max-w-[340px] flex-nowrap items-center justify-end gap-2');
    expect(actionColumn).toContain('<Button');
    expect(actionColumn).toContain('<IconButton');
    expect(actionColumn).toContain('variant="neutral"');
    expect(actionColumn).toContain('variant="primary"');
    expect(actionColumn).toContain('variant="warning"');
    expect(actionColumn).not.toContain('variant="danger"');
    expect(actionColumn).toContain('variant="success"');
    const activationActionStart = source.indexOf("{['inactive', 'suspended'].includes(employee.status)");
    const activationAction = source.slice(
      activationActionStart,
      source.indexOf("{canRequestEmployeeLifecycle && employee.status === 'terminated'", activationActionStart),
    );

    expect(activationAction).toContain('<IconButton');
    expect(activationAction).toContain('title="Activate Account"');
    expect(activationAction).toContain('aria-label={`Activate account for ${buildName(employee)}`}');
    expect(activationAction).toContain('<UserCheckIcon');
    expect(activationAction).not.toContain('<Button');
    expect(actionColumn).toContain('title="Request Rehire"');
    expect(actionColumn).toContain('aria-label={`Request rehire for ${buildName(employee)}`}');
    expect(actionColumn).toContain('<UserCheckIcon');
    expect(actionColumn).toContain('Rehire Pending');
    expect(actionColumn).not.toContain('title="Request Termination"');
    expect(actionColumn).not.toContain('aria-label={`Request termination for ${buildName(employee)}`}');
    expect(actionColumn).not.toContain('>\n                              Request Termination\n');
    expect(actionColumn).not.toContain('text-purple-600');
    expect(actionColumn).not.toContain('text-orange-600');

    const viewModalStart = source.indexOf('{isViewModalOpen &&');
    const viewModal = source.slice(
      viewModalStart,
      source.indexOf('{/* Add Employee Modal */}', viewModalStart),
    );

    expect(viewModal).toContain('Request Termination');
    expect(viewModal).toContain('variant="danger"');
  });

  it('does not offer Activate Account for terminated employee rows', () => {
    expect(source).toContain("employee.status === 'terminated'");
    expect(source).toContain("['inactive', 'suspended'].includes(employee.status)");
    expect(source).toContain("onClick={() => handleRehireClick(employee)}");
    expect(source).toContain("onClick={() => handleTerminateClick(selectedEmployee)}");
  });

  it('limits terminated employee rows to details and rehire actions', () => {
    const actionColumnStart = source.indexOf('<td className="px-3 py-3 align-top text-right');
    const actionColumn = source.slice(
      actionColumnStart,
      source.indexOf('</td>', actionColumnStart),
    );

    expect(actionColumn.match(/!ownerReadOnly && employee\.status !== 'terminated'/g)).toHaveLength(2);
    expect(actionColumn).toContain('title="View Details"');
    expect(actionColumn).toContain('title="Request Rehire"');
  });

  it('renders the employee suffix and complete address in details', () => {
    const buildNameStart = source.indexOf('const buildName =');
    const buildName = source.slice(buildNameStart, source.indexOf('\n\n', buildNameStart));
    const viewModalStart = source.indexOf('{isViewModalOpen &&');
    const viewModal = source.slice(
      viewModalStart,
      source.indexOf('{/* Add Employee Modal */}', viewModalStart),
    );

    expect(buildName).toContain('employee.suffix');
    expect(viewModal).toContain('Address');
    expect(viewModal).toContain('selectedEmployee.location');
    expect(viewModal).toContain('Province');
    expect(viewModal).toContain('selectedEmployee.province');
    expect(viewModal).toContain('City/Municipality');
    expect(viewModal).toContain('selectedEmployee.cityMunicipality');
    expect(viewModal).toContain('Postal Code');
    expect(viewModal).toContain('selectedEmployee.postalCode');
  });

  it('shows a contextual SweetAlert when a rehire request fails', () => {
    expect(source).toContain('errorTitle?: string;');
    expect(source).toContain('title: errorTitle');
    expect(source).toContain("errorTitle: 'Rehire Request Failed'");
    expect(source).toContain("icon: 'error'");
  });

  it('shows missing rehire fields in a warning before submitting', () => {
    const submitHandler = source.slice(
      source.indexOf('const handleRehireRequestSubmit'),
      source.indexOf('const handleSuspendClick'),
    );
    const rehireModal = source.slice(
      source.indexOf('{isRehireRequestModalOpen &&'),
      source.indexOf('{/* Add Employee Modal */}'),
    );

    expect(submitHandler).toContain('const missingRehireFields');
    expect(submitHandler).toContain("!rehireRequestForm.rehireSalary.trim() ? 'Daily Rate' : null");
    expect(submitHandler).toContain('missingRehireFields.join');
    expect(rehireModal).toContain('Daily Rate <span className="text-red-500">*</span>');
    expect(rehireModal).toContain('disabled={isProcessingId === employeeToRehire.id}');
    expect(rehireModal).not.toContain('rehireRequestForm.reason.trim().length < 3 ||');
  });

  it('marks employees with pending termination requests on the details action', () => {
    const actionColumnStart = source.indexOf('<td className="px-3 py-3 align-top text-right');
    const actionColumn = source.slice(
      actionColumnStart,
      source.indexOf('</td>', actionColumnStart),
    );

    expect(source).toContain('terminationPending?: boolean;');
    expect(source).toContain('terminationPending: Boolean(apiEmployee.has_pending_termination_request ?? apiEmployee.termination_pending)');
    expect(actionColumn).toContain('variant={employee.terminationPending ? "danger" : "neutral"}');
    expect(actionColumn).toContain('Termination request pending');
    expect(source).toContain('terminationPending: true');
  });

  it('disables termination requests while one is pending', () => {
    const viewModalStart = source.indexOf('{isViewModalOpen &&');
    const viewModal = source.slice(
      viewModalStart,
      source.indexOf('{/* Add Employee Modal */}', viewModalStart),
    );

    expect(source).toContain(
      "if (!canRequestEmployeeLifecycle || employee.status === 'terminated' || employee.terminationPending) return;",
    );
    expect(viewModal).toContain(
      'disabled={isProcessingId === selectedEmployee.id || isSelfEmployeeAccount(selectedEmployee) || selectedEmployee.terminationPending}',
    );
  });
});
