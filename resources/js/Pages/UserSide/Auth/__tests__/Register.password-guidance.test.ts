import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { getPasswordRequirementState, isPasswordValid } from '../passwordRequirements';

const source = readFileSync(resolve('resources/js/Pages/UserSide/Auth/Register.tsx'), 'utf8');

describe('customer registration password guidance', () => {
  it('keeps the password helper aligned with the registration rules', () => {
    expect(isPasswordValid('short')).toBe(false);
    expect(isPasswordValid('lowercase8')).toBe(false);
    expect(isPasswordValid('Uppercase')).toBe(false);
    expect(isPasswordValid('Uppercase8')).toBe(true);
    expect(getPasswordRequirementState('Uppercase8')).toEqual([
      { key: 'minLength', label: 'At least 8 characters', met: true },
      { key: 'uppercase', label: 'One uppercase letter', met: true },
      { key: 'lowercase', label: 'One lowercase letter', met: true },
      { key: 'number', label: 'One number', met: true },
    ]);
  });

  it('renders a non-layout-shifting password requirements popover', () => {
    const passwordField = source.slice(
      source.indexOf('htmlFor="password"'),
      source.indexOf('htmlFor="confirmPassword"'),
    );

    expect(passwordField).toContain('password-requirements');
    expect(passwordField).toContain('group-hover:opacity-100');
    expect(passwordField).toContain('group-focus-within:opacity-100');
    expect(passwordField).toContain('absolute');
    expect(passwordField).toContain('aria-describedby="password-requirements"');
    expect(passwordField).toContain('passwordRequirementState.map');
    expect(passwordField).toContain('<span>{label}</span>');
  });
});
