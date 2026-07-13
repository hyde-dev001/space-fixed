import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const repairProcessSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Repairs/RepairProcess.tsx'),
  'utf8',
);

describe('repair process return-location integration', () => {
  it('uses dependent Philippine province and municipality selectors', () => {
    expect(repairProcessSource).toContain('PHILIPPINE_LOCATIONS');
    expect(repairProcessSource).toContain('getCityMunicipalityOptions(formData.returnRegion)');
    expect(repairProcessSource).toContain('normalizeProvinceSelection');
    expect(repairProcessSource).toContain('normalizeCityMunicipalitySelection');
    expect(repairProcessSource).toContain('City/Municipality');
    expect(repairProcessSource).toContain('!formData.returnRegion');
    expect(repairProcessSource).toContain("returnCity: ''");
    expect(repairProcessSource).toContain("submitFormData.append('return_city', returnCity)");
    expect(repairProcessSource).toContain("submitFormData.append('return_region', returnRegion)");
    expect(repairProcessSource).toContain('handleDropdownTriggerKeyDown');
    expect(repairProcessSource).toContain('handleListboxKeyDown');
    expect(repairProcessSource).toContain('aria-expanded={isReturnProvinceDropdownOpen}');
    expect(repairProcessSource).toContain('aria-expanded={isReturnCityDropdownOpen}');
    expect(repairProcessSource).not.toContain('PH_CITY_OPTIONS');
    expect(repairProcessSource).not.toContain('DEFAULT_SHIPPING_REGION');
  });
});
