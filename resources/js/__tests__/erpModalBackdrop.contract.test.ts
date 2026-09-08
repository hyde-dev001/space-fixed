import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (relativePath: string) => readFileSync(resolve(relativePath), 'utf8');

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
});
