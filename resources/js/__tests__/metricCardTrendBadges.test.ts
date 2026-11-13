import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const decorativeTrendSources = [
  'resources/js/components/ecommerce/EcommerceMetrics.tsx',
  'resources/js/Pages/ERP/CRM/CustomerReviews.tsx',
  'resources/js/Pages/ERP/Finance/Expense.tsx',
  'resources/js/Pages/ERP/Finance/Invoice.tsx',
  'resources/js/Pages/ERP/Finance/payslipApproval.tsx',
  'resources/js/Pages/ERP/Finance/refundApproval.tsx',
  'resources/js/Pages/ERP/HR/AttendanceRecords.tsx',
  'resources/js/Pages/ERP/HR/EmployeeDirectory.tsx',
  'resources/js/Pages/ERP/HR/LeaveApprovals.tsx',
  'resources/js/Pages/ERP/HR/OvertimeApprovals.tsx',
  'resources/js/Pages/ERP/inventory/UploadInventory.tsx',
  'resources/js/Pages/ERP/repairer/JobOrdersRepair.tsx',
  'resources/js/Pages/ERP/repairer/PricingAndServices.tsx',
  'resources/js/Pages/ERP/repairer/repairStocksOverview.tsx',
  'resources/js/Pages/ERP/repairer/uploadService.tsx',
  'resources/js/Pages/ERP/repairer/WarrantyQueue.tsx',
  'resources/js/Pages/ERP/STAFF/Customers.tsx',
  'resources/js/Pages/ERP/STAFF/JobOrders.tsx',
  'resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx',
  'resources/js/Pages/ERP/STAFF/shoePricing.tsx',
  'resources/js/Pages/ShopOwner/Customers/customer management/CustomersReviews.tsx',
  'resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx',
  'resources/js/Pages/ShopOwner/Products/product management/InventoryOverview.tsx',
  'resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx',
  'resources/js/Pages/ShopOwner/Repairs/individual/uploadStockMaterial.tsx',
  'resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx',
  'resources/js/Pages/ShopOwner/Repairs/service management/uploadService.tsx',
  'resources/js/Pages/ShopOwner/Repairs/service management/WarrantyQueue.tsx',
  'resources/js/Pages/ShopOwner/Settings/AuditLogs.tsx',
  'resources/js/Pages/ShopOwner/TeamManagement/suspendAccount.tsx',
  'resources/js/Pages/ShopOwner/TeamManagement/UserAccessControl.tsx',
  'resources/js/Pages/superAdmin/Shops/RegisteredShops.tsx',
  'resources/js/Pages/superAdmin/Shops/ShopOwnerRegistrationView.tsx',
  'resources/js/Pages/superAdmin/SystemMonitoringDashboard.tsx',
  'resources/js/Pages/superAdmin/Users/SuperAdminUserManagement.tsx',
];

const legitimatePriceChangeSources = [
  'resources/js/Pages/ERP/Finance/repairPriceApproval.tsx',
  'resources/js/Pages/ERP/Finance/shoePriceApproval.tsx',
];

describe('dashboard metric cards', () => {
  it.each(decorativeTrendSources)('does not render a decorative trend badge in %s', (file) => {
    const source = readFileSync(resolve(file), 'utf8');

    expect(source).not.toMatch(/Math\.abs\(change!?\)/);

    if (file.endsWith('EcommerceMetrics.tsx')) {
      expect(source).not.toMatch(/growth_percentage|revenueGrowth|ordersGrowth|<Badge/);
    }

    if (file.endsWith('UploadInventory.tsx')) {
      expect(source).not.toMatch(/ArrowUpIcon|0%/);
    }
  });

  it.each(legitimatePriceChangeSources)('keeps actual price-change details separate from metric badges in %s', (file) => {
    const source = readFileSync(resolve(file), 'utf8');

    expect(source).not.toMatch(/changeType/);
    expect(source).toMatch(/Price Change/);
  });
});
