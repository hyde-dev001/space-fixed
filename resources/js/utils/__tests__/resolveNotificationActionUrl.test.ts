import { describe, expect, it } from 'vitest';
import { resolveNotificationActionUrl } from '../resolveNotificationActionUrl';

describe('resolveNotificationActionUrl', () => {
  it('routes a legacy product price-change notification to Finance product pricing', () => {
    expect(resolveNotificationActionUrl('/erp/finance', 'price_change_request', {
      price_change_id: 42,
      product_id: 7,
    })).toBe('/finance?section=shoe-pricing&price_change=42');
  });

  it('routes legacy Finance approval pages to the canonical query-driven Finance page', () => {
    expect(resolveNotificationActionUrl('/erp/finance/payslip-approvals', 'payroll_generated', {
      payroll_id: 18,
    })).toBe('/finance?section=payslip-approvals&payroll=18');

    expect(resolveNotificationActionUrl('/erp/finance/invoices', 'invoice_created', {
      invoice_id: 21,
    })).toBe('/finance?section=invoice-generation&invoice=21');
  });

  it('routes legacy inventory and HR destinations to pages that still exist', () => {
    expect(resolveNotificationActionUrl('/erp/procurement/replenishment-request-approval', 'stock_request', {
      request_id: 3,
    })).toBe('/erp/inventory/request-material-approval?stock_request=3');

    expect(resolveNotificationActionUrl('/erp/procurement/stock-request-approval', 'stock_request', {
      request_id: 4,
    })).toBe('/erp/inventory/request-material-approval?stock_request=4');

    expect(resolveNotificationActionUrl('/erp/procurement/supplier-orders', 'supplier_order_overdue', {
      po_number: 'PO-1001',
    })).toBe('/erp/inventory/supplier-order-monitoring?supplier=PO-1001');

    expect(resolveNotificationActionUrl('/erp/hr/payslips', 'payslip_rejected', {
      payroll_id: 9,
    })).toBe('/erp/hr?section=payroll-view&payroll=9');

    expect(resolveNotificationActionUrl('/erp/hr?section=suspensions', 'suspension_reviewed', {
      suspension_request_id: 11,
    })).toBe('/erp/notifications');
  });

  it('preserves canonical owner approval URLs and rejects external destinations', () => {
    const ownerApproval = '/shop-owner/action-center?bucket=needs_my_decision&approval=expense:5';

    expect(resolveNotificationActionUrl(ownerApproval, 'expense_approval', null)).toBe(ownerApproval);
    expect(resolveNotificationActionUrl('https://example.com/phishing', 'expense_approval', null)).toBeNull();
    expect(resolveNotificationActionUrl('//example.com/phishing', 'expense_approval', null)).toBeNull();
  });
});
