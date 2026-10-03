import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve('resources/js/Pages/ERP/HR/EmployeeDirectory.tsx'),
  'utf8',
);

describe('ERP employee form layout', () => {
  it('places phone and email together below the name fields', () => {
    const personalInformationStart = source.indexOf('{/* Personal Information Section */}');
    const personalInformationEnd = source.indexOf('{/* Job Information Section */}', personalInformationStart);
    const personalInformation = source.slice(personalInformationStart, personalInformationEnd);
    const phoneField = personalInformation.indexOf('Phone');
    const emailField = personalInformation.indexOf('Email <span');

    expect(personalInformation).toContain('lg:grid lg:grid-cols-6 lg:gap-3');
    expect(personalInformation.match(/lg:col-span-2/g)).toHaveLength(6);
    expect(personalInformation.match(/lg:col-span-3/g)).toBeNull();
    expect(personalInformation).toContain('lg:col-start-1');
    expect(personalInformation).toContain('lg:col-start-3');
    expect(personalInformation).toContain('lg:col-start-5');
    expect(phoneField).toBeGreaterThan(-1);
    expect(emailField).toBeGreaterThan(phoneField);
  });

  it('requires, submits, fetches, and renders employee age', () => {
    const addEmployeeModalStart = source.indexOf('{/* Add Employee Modal */}');
    const addEmployeeModal = source.slice(addEmployeeModalStart);
    const detailsModalStart = source.indexOf('{isViewModalOpen &&');
    const detailsModal = source.slice(
      detailsModalStart,
      source.indexOf('{/* Add Employee Modal */}', detailsModalStart),
    );

    expect(source).toContain('age: ""');
    expect(source).toContain('age: apiEmployee.age ?? null');
    expect(source).toContain('age: Number(addEmployeeForm.age)');
    expect(addEmployeeModal).toContain('Age <span');
    expect(addEmployeeModal).toContain('type="number"');
    expect(addEmployeeModal).toContain('min="0"');
    expect(addEmployeeModal).toContain('max="100"');
    expect(addEmployeeModal).toContain('step="1"');
    expect(detailsModal).toContain('Age');
    expect(detailsModal).toContain('selectedEmployee.age');
  });
});
