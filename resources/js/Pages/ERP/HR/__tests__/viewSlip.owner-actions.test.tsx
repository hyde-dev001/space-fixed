import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const viewSlipSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/ERP/HR/viewSlip.tsx'),
  'utf8',
);

describe('owner payslip action boundary', () => {
  it('does not expose the matrix-denied Generate Slip action from Payslips', () => {
    expect(viewSlipSource).toContain('const ownerMode = auth?.erpActor?.ownerMode === true;');
    expect(viewSlipSource).not.toContain('Generate Slip');
    expect(viewSlipSource).not.toContain('payroll-generate');
    expect(viewSlipSource).not.toContain('/shop-owner/erp/hr/payroll-generate');
  });
});
