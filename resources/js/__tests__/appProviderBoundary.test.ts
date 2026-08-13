import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/app.jsx'), 'utf8');

describe('application provider boundaries', () => {
  it('mounts the customer cart provider only for non-auth UserSide pages', () => {
    expect(source).toContain("!component.startsWith('UserSide/Auth/')");

    const customerStart = source.indexOf('if (usesCustomerCart)');
    const authStart = source.indexOf('else if (isUserSidePage)', customerStart);
    const internalStart = source.indexOf('else {', authStart);
    const setupEnd = source.indexOf('\n\n        dismissAppLoader();', internalStart);

    expect(customerStart).toBeGreaterThan(-1);
    expect(authStart).toBeGreaterThan(customerStart);
    expect(internalStart).toBeGreaterThan(authStart);
    expect(setupEnd).toBeGreaterThan(internalStart);

    const customerBranch = source.slice(customerStart, authStart);
    const authBranch = source.slice(authStart, internalStart);
    const internalBranch = source.slice(internalStart, setupEnd);

    expect(customerBranch).toContain('<CartProvider>');
    expect(authBranch).not.toContain('<CartProvider>');
    expect(internalBranch).not.toContain('<CartProvider>');
  });
});
