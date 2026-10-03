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
    const ageField = personalInformation.indexOf('>Age *</label>');

    expect(personalInformationStart).toBeGreaterThan(-1);
    expect(phoneField).toBeGreaterThan(-1);
    expect(emailField).toBeGreaterThan(phoneField);
    expect(ageField).toBeGreaterThan(emailField);
    expect(personalInformation.match(/lg:col-span-2/g)).toHaveLength(6);
    expect(personalInformation).not.toContain('lg:col-span-3');
    expect(source).toContain("age: '',");
    expect(source).toContain('const age = Number(normalizedAge);');
    expect(source).toContain('          age,');
    expect(source).toContain('min={0}');
    expect(source).toContain('max={100}');
    expect(source).toContain('Number(value) <= 100');
    expect(personalInformation).toMatch(/<div className="mt-3 lg:col-span-6 lg:mt-0">[\s\S]*PhilippineAddressFields/);
  });
});
