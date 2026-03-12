import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const FINANCE_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 920001,
    type: 'finance_invoices',
    title: 'Invoices',
    message: 'Review invoice generation and updates.',
    data: {
      profile_icon: 'invoice',
    },
    action_url: '/finance?section=invoice-generation',
    is_read: false,
    created_at: now,
  },
  {
    id: 920002,
    type: 'finance_repair_pricing_approval',
    title: 'Repair Pricing Approval',
    message: 'Review repair pricing approval requests.',
    data: {
      profile_icon: 'repair_pricing',
    },
    action_url: '/finance?section=repair-pricing',
    is_read: false,
    created_at: now,
  },
  {
    id: 920003,
    type: 'finance_shoe_pricing_approval',
    title: 'Shoe Pricing Approval',
    message: 'Review shoe pricing approval requests.',
    data: {
      profile_icon: 'shoe_pricing',
    },
    action_url: '/finance?section=shoe-pricing',
    is_read: false,
    created_at: now,
  },
  {
    id: 920004,
    type: 'finance_purchase_request_approval',
    title: 'Purchase Request Approval',
    message: 'Review purchase request approvals.',
    data: {
      profile_icon: 'purchase',
    },
    action_url: '/finance?section=purchase-request-approval',
    is_read: false,
    created_at: now,
  },
  {
    id: 920005,
    type: 'finance_refund_approval',
    title: 'Refund Approval',
    message: 'Review refund approval requests.',
    data: {
      profile_icon: 'refund',
    },
    action_url: '/finance?section=refund-approvals',
    is_read: false,
    created_at: now,
  },
  {
    id: 920006,
    type: 'finance_payslip_approvals',
    title: 'Payslip Approvals',
    message: 'Review payslip approval requests.',
    data: {
      profile_icon: 'payslip',
    },
    action_url: '/finance?section=payslip-approvals',
    is_read: false,
    created_at: now,
  },
  {
    id: 920007,
    type: 'finance_my_payslips',
    title: 'My Payslips',
    message: 'View your payslip records and payroll history.',
    data: {
      profile_icon: 'my_payslips',
    },
    action_url: '/erp/my-payslips',
    is_read: false,
    created_at: now,
  },
];
