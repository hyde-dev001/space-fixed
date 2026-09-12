import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const repair = readFileSync(resolve('resources/js/Pages/UserSide/Repairs/Repair.tsx'), 'utf8');
const show = readFileSync(resolve('resources/js/Pages/UserSide/Repairs/repairShow.tsx'), 'utf8');

describe('repair shop address coverage integration', () => {
  it('uses saved pinned addresses and server coverage on the shop list', () => {
    expect(repair).toContain("CustomerAddressManager");
    expect(repair).toContain('/delivery-quote?address_id=');
    expect(repair).toContain('Within coverage');
    expect(repair).toContain('Outside coverage');
    expect(repair).toContain('Pin required');
    expect(repair).toContain('address_id=');
  });

  it('keeps the chosen address through shop details and repair booking', () => {
    expect(show).toContain('CustomerAddressManager');
    expect(show).toContain('/delivery-quote?address_id=');
    expect(show).toContain('requestRepairHref');
    expect(show).toContain('address_id=');
  });
});
