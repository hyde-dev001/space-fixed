type NotificationData = Record<string, unknown> | null | undefined;

const getValue = (data: NotificationData, key: string): string | number | null => {
  const value = data?.[key];

  if (typeof value === 'string' || typeof value === 'number') {
    return value;
  }

  return null;
};

const appendQueryParam = (href: string, key: string, value: string | number | null): string => {
  if (value === null || value === '') return href;

  const separator = href.includes('?') ? '&' : '?';
  return `${href}${separator}${key}=${encodeURIComponent(String(value))}`;
};

const financeSectionUrl = (
  section: string,
  queryKey?: string,
  queryValue?: string | number | null,
): string => {
  const href = `/finance?section=${encodeURIComponent(section)}`;
  return queryKey ? appendQueryParam(href, queryKey, queryValue ?? null) : href;
};

/**
 * Normalize notification destinations that were written before ERP pages were
 * consolidated. Only local application paths are accepted.
 */
export function resolveNotificationActionUrl(
  actionUrl?: string | null,
  notificationType?: string | null,
  data?: NotificationData,
): string | null {
  const normalized = String(actionUrl ?? '').trim();
  if (!normalized) return null;

  // Do not turn a notification into an open redirect.
  if (!normalized.startsWith('/') || normalized.startsWith('//')) return null;

  const [path, query = ''] = normalized.split('?', 2);
  const type = String(notificationType ?? '').toLowerCase();
  const priceChangeId = getValue(data, 'price_change_id');
  const payrollId = getValue(data, 'payroll_id');
  const invoiceId = getValue(data, 'invoice_id') ?? getValue(data, 'id');
  const supplierNumber = getValue(data, 'po_number');
  const suspensionRequestId = getValue(data, 'suspension_request_id') ?? getValue(data, 'request_id');

  if (path === '/erp/finance' || path === '/erp/finance/') {
    const params = new URLSearchParams(query);
    const section = params.get('section');

    if (section) {
      return financeSectionUrl(section);
    }

    if (type.includes('payslip') || type.includes('payroll')) {
      return financeSectionUrl('payslip-approvals', 'payroll', payrollId);
    }

    if (type.includes('repair') || getValue(data, 'service_id') !== null || getValue(data, 'package_id') !== null) {
      return financeSectionUrl('repair-pricing');
    }

    if (type.includes('price') || priceChangeId !== null || getValue(data, 'product_id') !== null) {
      return financeSectionUrl('shoe-pricing', 'price_change', priceChangeId);
    }

    return '/finance';
  }

  if (path === '/erp/finance/repair-refunds') {
    return financeSectionUrl('refund-approvals');
  }

  if (path === '/erp/finance/expenses' || path === '/erp/finance/approvals') {
    return financeSectionUrl('expense-tracking');
  }

  if (path === '/erp/finance/invoices') {
    return financeSectionUrl('invoice-generation', 'invoice', invoiceId);
  }

  if (path === '/erp/finance/payslip-approvals') {
    return financeSectionUrl('payslip-approvals', 'payroll', payrollId);
  }

  if (path === '/erp/hr/payslips') {
    return `/erp/hr?section=payroll-view${payrollId === null ? '' : `&payroll=${encodeURIComponent(String(payrollId))}`}`;
  }

  if (path === '/erp/hr' && new URLSearchParams(query).get('section') === 'suspensions') {
    return '/erp/notifications';
  }

  if (path === '/erp/procurement/replenishment-request-approval') {
    return appendQueryParam(
      '/erp/inventory/request-material-approval',
      'stock_request',
      getValue(data, 'stock_request_id') ?? getValue(data, 'request_id') ?? new URLSearchParams(query).get('stock_request'),
    );
  }

  if (path === '/erp/procurement/stock-request-approval') {
    return appendQueryParam(
      '/erp/inventory/request-material-approval',
      'stock_request',
      getValue(data, 'stock_request_id') ?? getValue(data, 'request_id') ?? new URLSearchParams(query).get('stock_request'),
    );
  }

  if (path === '/erp/procurement/supplier-orders') {
    return appendQueryParam('/erp/inventory/supplier-order-monitoring', 'supplier', supplierNumber);
  }

  if (path === '/shop-owner/expenses') {
    return '/shop-owner/action-center';
  }

  if ((path === '/shop-owner/suspend-accounts' || path === '/shopOwner/suspend-accounts') && suspensionRequestId !== null) {
    return `/shop-owner/action-center?bucket=needs_my_decision&approval=suspension_request:${encodeURIComponent(String(suspensionRequestId))}`;
  }

  return normalized;
}
