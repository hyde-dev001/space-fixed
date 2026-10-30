import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const settingsSource = readFileSync(
  join(process.cwd(), 'resources/js/Pages/ShopOwner/Settings/shopSetting.tsx'),
  'utf8',
);

describe('shop owner COD settings', () => {
  it('exposes a retail-only toggle and a positive merchandise threshold', () => {
    expect(settingsSource).toContain('cod_enabled: boolean;');
    expect(settingsSource).toContain('cod_order_threshold: number;');
    expect(settingsSource).toContain("{hasRetailSignal && (");
    expect(settingsSource).toContain('Cash on Delivery');
    expect(settingsSource).toContain('Maximum merchandise total for COD');
    expect(settingsSource).toContain("cod_enabled: codEnabled");
    expect(settingsSource).toContain("cod_order_threshold: Number(parsed.toFixed(2))");
  });
});
