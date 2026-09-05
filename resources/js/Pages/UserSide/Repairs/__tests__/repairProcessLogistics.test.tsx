import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/UserSide/Repairs/RepairProcess.tsx'), 'utf8');

describe('repair booking logistics experience', () => {
  it('separates intake and return methods with saved address controls', () => {
    expect(source).toContain('Send shoes to shop');
    expect(source).toContain('Return repaired shoes');
    expect(source).toContain('CustomerAddressManager');
    expect(source).toContain('Same as intake address');
    expect(source).toContain('intake_delivery_method');
    expect(source).toContain('intake_address_id');
    expect(source).toContain('return_delivery_method');
    expect(source).toContain('return_address_id');
    expect(source).toContain('same_as_intake_address');
  });

  it('makes coverage and third-party responsibility unambiguous', () => {
    expect(source).toContain('/delivery-quote?address_id=');
    expect(source).toContain('Within coverage');
    expect(source).toContain('Outside coverage');
    expect(source).toContain('Pin required');
    expect(source).toContain('You arrange and pay the courier directly');
    expect(source).toContain('disabled={!intakeShopOwnedAvailable}');
    expect(source).toContain('disabled={!returnShopOwnedAvailable}');
  });
});
