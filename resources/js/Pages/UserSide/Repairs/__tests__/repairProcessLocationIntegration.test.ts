import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const repairProcessSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/UserSide/Repairs/RepairProcess.tsx'),
  'utf8',
);

describe('repair process saved-address integration', () => {
  it('delegates structured location and map pin editing to the shared address manager', () => {
    expect(repairProcessSource).toContain("import CustomerAddressManager");
    expect(repairProcessSource).toContain('<CustomerAddressManager');
    expect(repairProcessSource).toContain('initialAddressId={initialAddressId}');
    expect(repairProcessSource).toContain("submitFormData.append('intake_address_id'");
    expect(repairProcessSource).toContain("submitFormData.append('return_address_id'");
    expect(repairProcessSource).toContain("submitFormData.append('same_as_intake_address'");
    expect(repairProcessSource).toContain('/delivery-quote?address_id=');
    expect(repairProcessSource).not.toContain("submitFormData.append('return_city'");
    expect(repairProcessSource).not.toContain("submitFormData.append('return_region'");
  });
});
