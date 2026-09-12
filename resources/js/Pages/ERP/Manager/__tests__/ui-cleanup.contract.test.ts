import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (file: string) => readFileSync(resolve(file), 'utf8');

describe('requested ERP UI cleanup contract', () => {
  it('removes the specified Manager decorative headers and refresh controls', () => {
    for (const file of ['JobOrders', 'RepairJobs', 'StaffWorkload', 'LeaveApprovals']) {
      const source = read(`resources/js/Pages/ERP/Manager/${file}.tsx`);
      expect(source).not.toContain('Operations');
      expect(source).not.toContain('People &amp; approvals');
    }

    expect(read('resources/js/Pages/ERP/Manager/InventoryOverview.tsx')).not.toContain('>\n              Refresh\n');
    expect(read('resources/js/Pages/ERP/Manager/AuditLogs.tsx')).not.toContain('>\n            Refresh\n');
    expect(read('resources/js/Pages/ERP/Manager/Reports.tsx')).not.toContain('Manager Access');
  });

  it('keeps Audit Logs on compact numbered pagination controls', () => {
    const source = read('resources/js/Pages/ERP/Manager/AuditLogs.tsx');
    expect(source).toContain('aria-label="Audit log pagination"');
    expect(source).toContain('aria-label="Previous page"');
    expect(source).toContain('aria-label="Next page"');
    expect(source).toContain('aria-current={pagination.current_page === pageItem ? "page" : undefined}');
    expect(source).not.toContain('Page {pagination.current_page} of {pagination.last_page}');
  });

  it('keeps the added supplier and phone UI constraints explicit', () => {
    const orders = read('resources/js/Pages/ERP/inventory/SupplierOrderMonitoring.tsx');
    expect(orders).toContain('Total Supplier Orders');
    expect(orders).toContain('aria-label="View"');
    expect(orders).toContain('aria-label="Receive"');

    const suppliers = read('resources/js/Pages/ERP/Procurement/SuppliersManagement.tsx');
    expect(suppliers).toContain('type="tel"');
    expect(suppliers).toContain('maxLength={11}');
    expect(read('app/Http/Controllers/Erp/SupplierController.php')).toContain("'phone' => 'nullable|digits_between:1,11'");
  });
});
