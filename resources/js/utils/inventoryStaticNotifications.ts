import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const INVENTORY_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 960001,
    type: 'inventory_dashboard',
    title: 'Inventory Dashboard',
    message: 'View your inventory overview and current stock health.',
    data: {
      profile_icon: 'inventory_dashboard',
    },
    action_url: '/erp/inventory/inventory-dashboard',
    is_read: false,
    created_at: now,
  },
  {
    id: 960002,
    type: 'inventory_upload_stocks',
    title: 'Upload Stocks',
    message: 'Upload and sync new stock entries.',
    data: {
      profile_icon: 'upload_stocks',
    },
    action_url: '/erp/inventory/upload-stocks',
    is_read: false,
    created_at: now,
  },
  {
    id: 960003,
    type: 'inventory_stock_movement',
    title: 'Stock Movement',
    message: 'Track in/out movement and adjustments.',
    data: {
      profile_icon: 'stock_movement',
    },
    action_url: '/erp/inventory/stock-movement',
    is_read: false,
    created_at: now,
  },
  {
    id: 960004,
    type: 'inventory_product_inventory',
    title: 'Product Inventory',
    message: 'Check product-level inventory details.',
    data: {
      profile_icon: 'product_inventory',
    },
    action_url: '/erp/inventory/product-inventory',
    is_read: false,
    created_at: now,
  },
  {
    id: 960005,
    type: 'inventory_stock_request',
    title: 'Stock Request',
    message: 'Review and manage stock requests.',
    data: {
      profile_icon: 'stock_request',
    },
    action_url: '/erp/inventory/stock-request',
    is_read: false,
    created_at: now,
  },
  {
    id: 960006,
    type: 'inventory_my_payslips',
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
