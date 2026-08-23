import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const invoiceSource = readFileSync(
  resolve(process.cwd(), 'resources/js/Pages/ERP/Finance/Invoice.tsx'),
  'utf8',
);

const navigationSources = [
  readFileSync(
    resolve(process.cwd(), 'resources/js/components/owner-shell/OwnerModuleTabs.tsx'),
    'utf8',
  ),
  readFileSync(
    resolve(process.cwd(), 'resources/js/layout/CanonicalOwnerSidebar.tsx'),
    'utf8',
  ),
].join('\n');

describe('owner invoice action boundary', () => {
  it('keeps the matrix-denied Create Invoice action out of owner mode', () => {
    expect(invoiceSource).toContain("const ownerMode = page.props.ownerMode === true || auth?.erpActor?.ownerMode === true;");
    expect(invoiceSource).toContain("const canCreateInvoice = !ownerMode && canUseErpCapability(");
    expect(invoiceSource).toContain("'GET:finance.create-invoice'");
    expect(invoiceSource).toContain("router.visit('/finance?section=create-invoice')");
    expect(invoiceSource).not.toContain("router.visit(ownerMode ? '/shop-owner/erp/finance/create-invoice'");
  });

  it('keeps denied creation actions out of owner tabs and global navigation', () => {
    for (const deniedAction of ['Create Invoice', 'Generate Slip', 'Upload Stocks']) {
      expect(navigationSources).not.toContain(deniedAction);
    }
  });
});
