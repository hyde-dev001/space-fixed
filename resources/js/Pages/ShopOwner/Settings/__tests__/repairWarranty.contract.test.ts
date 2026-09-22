import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const settingsSource = readFileSync(resolve('resources/js/Pages/ShopOwner/Settings/shopSetting.tsx'), 'utf8');
const repairsSource = readFileSync(resolve('resources/js/Pages/UserSide/Repairs/myRepairs.tsx'), 'utf8');

describe('repair warranty UI contract', () => {
  it('exposes plain-language shop warranty controls and disables terms when off', () => {
    expect(settingsSource).toContain('repair_warranty_enabled');
    expect(settingsSource).toContain('repair_warranty_duration_unit');
    expect(settingsSource).toContain('Save Warranty Settings');
    expect(settingsSource).toContain('disabled={!repairWarrantyEnabled}');
    expect(settingsSource).toContain('existing warranties keep their original terms');
  });

  it('uses the server warranty state for customer visibility and claim action', () => {
    expect(repairsSource).toContain('order.warranty &&');
    expect(repairsSource).toContain('order.warranty?.can_claim');
    expect(repairsSource).toContain('Repair warranty: {order.warranty.active ? \'Active\' : \'Expired\'}');
    expect(repairsSource).toContain('This repair was not issued a warranty by the shop.');
  });
});
