import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const authPages = [
  'UserLogin.tsx',
  'Register.tsx',
  'Forgot.tsx',
  'Otp.tsx',
  'NewPassword.tsx',
].map((file) => ({
  file,
  source: readFileSync(resolve(`resources/js/Pages/UserSide/Auth/${file}`), 'utf8'),
}));

describe('customer auth chrome', () => {
  it.each(authPages)('$file uses the shared auth background without storefront navigation', ({ source }) => {
    expect(source).toContain('userside-auth-pattern');
    expect(source).toContain('<AuthBrand />');
    expect(source).not.toContain("import Navigation");
    expect(source).not.toContain('<Navigation');
  });

  it.each(authPages)('$file keeps the auth form as the only visible page-level content', ({ source }) => {
    expect(source).not.toContain('userside-auth-title');
    expect(source).not.toContain('userside-auth-subtitle');
    expect(source).toContain('userside-auth-card');
    expect(source).toContain('mx-auto');
  });

  it.each(authPages)('$file keeps its full-width card centered in the viewport', ({ source }) => {
    expect(source).toContain('relative min-h-screen');
    expect(source).toContain('flex min-h-screen w-full');
    expect(source).toContain('items-center');
    expect(source).toContain('justify-center');
    expect(source).toMatch(/className=\{?(?:`|\")w-full max-w-/);
  });

});
