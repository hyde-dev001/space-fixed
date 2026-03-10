import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const PROCUREMENT_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 970001,
    type: 'procurement_supplier_order_monitoring',
    title: 'Supplier Order Monitoring',
    message: 'Monitor PO delivery timelines and remaining days before expected arrival.',
    data: {
      profile_icon: 'supplier_order_monitoring',
    },
    action_url: '/erp/procurement/supplier-order-monitoring',
    is_read: false,
    created_at: now,
  },
  {
    id: 970002,
    type: 'procurement_purchase_request',
    title: 'Purchase Request',
    message: 'Create and review purchase requests for procurement approval workflow.',
    data: {
      profile_icon: 'purchase_request',
    },
    action_url: '/erp/procurement/purchase-request',
    is_read: false,
    created_at: now,
  },
  {
    id: 970003,
    type: 'procurement_purchase_orders',
    title: 'Purchase Orders',
    message: 'Track and manage purchase orders from approved requests.',
    data: {
      profile_icon: 'purchase_orders',
    },
    action_url: '/erp/procurement/purchase-orders',
    is_read: false,
    created_at: now,
  },
  {
    id: 970004,
    type: 'procurement_stock_request_approval',
    title: 'Stock Request Approval',
    message: 'Review and process stock requests submitted by inventory staff.',
    data: {
      profile_icon: 'stock_request_approval',
    },
    action_url: '/erp/procurement/stock-request-approval',
    is_read: false,
    created_at: now,
  },
  {
    id: 970005,
    type: 'procurement_my_payslips',
    title: 'My Payslips',
    message: 'Access your payslip records and payroll history.',
    data: {
      profile_icon: 'my_payslips',
    },
    action_url: '/erp/my-payslips',
    is_read: false,
    created_at: now,
  },
];
