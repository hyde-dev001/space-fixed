import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (relativePath: string) => readFileSync(resolve(relativePath), 'utf8');

const collectPageSources = (relativeDirectory: string): string[] => readdirSync(resolve(relativeDirectory), { withFileTypes: true }).flatMap((entry) => {
  const relativePath = join(relativeDirectory, entry.name);

  if (entry.isDirectory()) {
    return collectPageSources(relativePath);
  }

  return /\.(tsx|jsx)$/.test(entry.name) && !relativePath.includes('__tests__') ? [relativePath] : [];
});

const erpAndOwnerPageSources = [
  ...collectPageSources('resources/js/Pages/ERP'),
  ...collectPageSources('resources/js/Pages/ShopOwner'),
];

describe('ERP modal backdrop adoption', () => {
  it('marks representative page-level modal systems', () => {
    const pageLevelSources = [
      'resources/js/Pages/ERP/Manager/JobOrders.tsx',
      'resources/js/Pages/ERP/STAFF/TimeIn.tsx',
      'resources/js/Pages/ERP/Finance/Expense.tsx',
      'resources/js/Pages/ERP/HR/LeaveApprovals.tsx',
      'resources/js/Pages/ERP/CRM/CustomerReviews.tsx',
      'resources/js/Pages/ERP/cashier/POS.tsx',
      'resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx',
      'resources/js/Pages/ERP/inventory/UploadInventory.tsx',
      'resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx',
      'resources/js/Pages/ERP/Logistics/MyDeliveries.tsx',
      'resources/js/Pages/ShopOwner/Operations/JobOrders.tsx',
      'resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx',
      'resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx',
    ];

    for (const relativePath of pageLevelSources) {
      expect(source(relativePath), relativePath).toContain('erp-modal-backdrop');
    }
  });

  it('keeps shared ERP modal consumers on the shared implementation', () => {
    expect(source('resources/js/components/owner-action-center/OwnerApprovalDetailPanel.tsx')).toContain('<Modal');
    expect(source('resources/js/Pages/ERP/inventory/ReplenishmentSettingsModal.tsx')).toContain('<Modal');
    expect(source('resources/js/components/common/ErrorModal.tsx')).toContain('erp-modal-backdrop');
  });

  it('marks every full-screen page backdrop and removes transparent duplicate shields', () => {
    const unmarkedBackdropLines = erpAndOwnerPageSources.flatMap((relativePath) => source(relativePath)
      .split(/\r?\n/)
      .filter((line) => {
        const isFullScreen = /\bfixed\b/.test(line) && /\binset-0\b/.test(line);
        const paintsBackdrop = /\bbg-[^\s"'`]+|\bbg-opacity-|\bbackdrop-blur/.test(line);

        return isFullScreen && paintsBackdrop && !line.includes('erp-modal-backdrop');
      })
      .map((line) => `${relativePath}: ${line.trim()}`));

    expect(unmarkedBackdropLines).toEqual([]);

    const duplicateShields = erpAndOwnerPageSources.flatMap((relativePath) => {
      const matches = source(relativePath).match(/<div className="fixed inset-0 z-40"\s*\/>/g) ?? [];

      return matches.map(() => relativePath);
    });

    expect(duplicateShields).toEqual([]);
  });
});
