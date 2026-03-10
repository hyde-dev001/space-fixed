import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const STAFF_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 980001,
    type: 'staff_job_orders_retail',
    title: 'Job Orders Retail',
    message: 'Open and manage your retail job orders.',
    data: {
      profile_icon: 'job_orders_retail',
    },
    action_url: '/erp/staff/job-orders',
    is_read: false,
    created_at: now,
  },
  {
    id: 980002,
    type: 'staff_product_uploader',
    title: 'Product Uploader',
    message: 'Upload and update product listings for the shop.',
    data: {
      profile_icon: 'product_uploader',
    },
    action_url: '/erp/staff/products',
    is_read: false,
    created_at: now,
  },
  {
    id: 980003,
    type: 'staff_shoe_pricing',
    title: 'Shoe Pricing',
    message: 'Review and maintain shoe pricing details.',
    data: {
      profile_icon: 'shoe_pricing',
    },
    action_url: '/erp/staff/shoe-pricing',
    is_read: false,
    created_at: now,
  },
  {
    id: 980004,
    type: 'staff_inventory_overview',
    title: 'Inventory Overview',
    message: 'Check stock status and inventory summary.',
    data: {
      profile_icon: 'inventory_overview',
    },
    action_url: '/erp/staff/inventory-overview',
    is_read: false,
    created_at: now,
  },
  {
    id: 980005,
    type: 'staff_my_payslips',
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