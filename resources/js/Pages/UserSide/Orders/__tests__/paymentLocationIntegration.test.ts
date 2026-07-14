import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const paymentSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Orders/payment.tsx'),
  'utf8',
);

describe('payment location integration', () => {
  it('uses dependent Philippine province and municipality selectors', () => {
    expect(paymentSource).toContain('PHILIPPINE_LOCATIONS');
    expect(paymentSource).toContain('getCityMunicipalityOptions');
    expect(paymentSource).toContain('normalizeProvinceSelection');
    expect(paymentSource).toContain('City/Municipality');
    expect(paymentSource).not.toContain('PH_CITY_OPTIONS');
  });
});
