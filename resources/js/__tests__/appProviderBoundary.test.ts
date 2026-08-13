import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/app.jsx'), 'utf8');

describe('application provider boundaries', () => {
  it('mounts the cart provider for every UserSide page but not internal role pages', () => {
    const customerStart = source.indexOf('if (isUserSidePage)');
    const internalStart = source.indexOf('else {', customerStart);
    const setupEnd = source.indexOf('\n\n        dismissAppLoader();', internalStart);

    expect(customerStart).toBeGreaterThan(-1);
    expect(internalStart).toBeGreaterThan(customerStart);
    expect(setupEnd).toBeGreaterThan(internalStart);

    const customerBranch = source.slice(customerStart, internalStart);
    const internalBranch = source.slice(internalStart, setupEnd);

    expect(customerBranch).toContain('<CartProvider>');
    expect(internalBranch).not.toContain('<CartProvider>');
  });
});
