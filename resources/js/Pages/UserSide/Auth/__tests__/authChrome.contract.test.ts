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

});
