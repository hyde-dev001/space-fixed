import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(
  resolve('resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx'),
  'utf8',
);

describe('add employee form layout', () => {
  it('keeps contact fields on a balanced row below the name fields', () => {
    const personalInformationStart = source.lastIndexOf(
      '                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-6">',
      source.indexOf('PERSONAL INFORMATION'),
    );
    const personalInformation = source.slice(
      personalInformationStart,
      source.indexOf('JOB INFORMATION'),
    );
    const phoneField = personalInformation.indexOf('>Phone</label>');
    const emailField = personalInformation.indexOf('>Email *</label>');

    expect(personalInformationStart).toBeGreaterThan(-1);
    expect(phoneField).toBeGreaterThan(-1);
    expect(emailField).toBeGreaterThan(phoneField);
    expect(personalInformation.match(/lg:col-span-2/g)).toHaveLength(3);
    expect(personalInformation.match(/lg:col-span-3/g)).toHaveLength(2);
    expect(personalInformation).toMatch(/<div className="mt-3 lg:col-span-6 lg:mt-0">[\s\S]*PhilippineAddressFields/);
  });
});