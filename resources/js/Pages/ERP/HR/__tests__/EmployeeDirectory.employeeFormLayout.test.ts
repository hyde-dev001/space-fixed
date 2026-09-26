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
    expect(personalInformation.match(/lg:col-span-2/g)).toHaveLength(3);
    expect(personalInformation.match(/lg:col-span-3/g)).toHaveLength(2);
    expect(personalInformation).toContain('lg:col-start-1');
    expect(personalInformation).toContain('lg:col-start-4');
    expect(phoneField).toBeGreaterThan(-1);
    expect(emailField).toBeGreaterThan(phoneField);
  });
});
