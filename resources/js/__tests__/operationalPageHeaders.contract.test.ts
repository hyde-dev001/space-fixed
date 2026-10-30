import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const readPage = (path: string) => readFileSync(resolve(path), 'utf8');

const operationalPages = [
  ['resources/js/Pages/ERP/STAFF/ProductManagementWithVariants.tsx', 'Product Management'],
  ['resources/js/Pages/ERP/STAFF/JobOrders.tsx', 'Customer Orders'],
  ['resources/js/Pages/ERP/Manager/InventoryOverview.tsx', 'Stocks Overview'],
  ['resources/js/Pages/ERP/inventory/ProductInventory.tsx', 'Product Inventory'],
  ['resources/js/Pages/ERP/Finance/Expense.tsx', 'Expense Management'],
  ['resources/js/Pages/ERP/HR/EmployeeDirectory.tsx', 'Employee Management'],
  ['resources/js/Pages/ERP/CRM/Customers.tsx', 'Customers'],
  ['resources/js/Pages/ERP/cashier/POS.tsx', 'Point of Sale'],
  ['resources/js/Pages/ERP/Procurement/PurchaseOrders.tsx', 'Purchase Orders'],
  ['resources/js/Pages/ERP/Logistics/Batches.tsx', 'Delivery Batches'],
  ['resources/js/Pages/ERP/repairer/PricingAndServices.tsx', 'Repair Pricing'],
  ['resources/js/Pages/ShopOwner/Products/product management/ProductManagementWithVariants.tsx', 'Product Management'],
  ['resources/js/Pages/ShopOwner/Orders/order management/JobOrders.tsx', 'Customer Orders'],
  ['resources/js/Pages/ShopOwner/Repairs/service management/JobOrdersRepair.tsx', 'Shoe Repair &amp; Cleaning Services'],
  ['resources/js/Pages/ShopOwner/Settings/shopSetting.tsx', 'Shop Settings'],
];

describe('operational page header presentation contract', () => {
  it.each(operationalPages)('visually hides the redundant top-level heading on %s', (path, title) => {
    const source = readPage(path);

    expect(source, path).toContain('className="sr-only"');
    expect(source, path).toContain(title);
  });

  it('removes decorative inventory context labels without changing the page identity', () => {
    const managerInventory = readPage('resources/js/Pages/ERP/Manager/InventoryOverview.tsx');
    const productInventory = readPage('resources/js/Pages/ERP/inventory/ProductInventory.tsx');

    expect(managerInventory).not.toContain('Products + Repair Materials');
    expect(productInventory).not.toContain('Inventory Tracking');
  });

  it('keeps dashboard headings visible', () => {
    const staffDashboard = readPage('resources/js/Pages/ERP/STAFF/Dashboard.tsx');
    const procurementDashboard = readPage('resources/js/Pages/ERP/Procurement/Dashboard.tsx');

    expect(staffDashboard).toContain('title={dashboard?.title ?? \'Staff Dashboard\'}');
    expect(procurementDashboard).toContain('dashboard?.title ?? \'Procurement Dashboard\'');
    expect(procurementDashboard).toContain('text-3xl font-bold tracking-tight');
  });
});
