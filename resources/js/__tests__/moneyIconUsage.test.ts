import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const moneyIconSource = readFileSync(resolve('resources/js/components/common/MoneyIcon.tsx'), 'utf8');

const moneyIconConsumers = [
  'resources/js/components/ecommerce/EcommerceMetrics.tsx',
  'resources/js/Pages/ERP/Manager/Dashboard.tsx',
  'resources/js/Pages/ERP/Finance/Invoice.tsx',
  'resources/js/Pages/ERP/STAFF/JobOrders.tsx',
  'resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx',
  'resources/js/Pages/ShopOwner/DssInsights.tsx',
  'resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx',
  'resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx',
];

describe('Shared money icon usage', () => {
  it('draws a neutral stacked-coins icon without a currency glyph', () => {
    expect(moneyIconSource).toContain('export const MoneyIcon');
    expect(moneyIconSource).toContain('<ellipse cx="12" cy="6.5" rx="6.5" ry="2.5" />');
    expect(moneyIconSource).toContain('<path d="M5.5 6.5v3c0 1.38 2.91 2.5 6.5 2.5s6.5-1.12 6.5-2.5v-3" />');
    expect(moneyIconSource).not.toContain('<rect');
    expect(moneyIconSource).not.toContain('<circle');
    expect(moneyIconSource).not.toMatch(/[$₱]|CurrencyDollarIcon|DollarIcon|PesoIcon/);
  });

  it.each(moneyIconConsumers)('uses MoneyIcon in %s', (file) => {
    const source = readFileSync(resolve(file), 'utf8');

    expect(source).toContain('MoneyIcon');
    expect(source).not.toMatch(/(?:PesoIcon|DollarIcon|CurrencyDollarIcon)/);
  });
});
