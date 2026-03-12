import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const MANAGER_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 910001,
    type: 'manager_suspend_approval',
    title: 'Suspend Approval',
    message: 'Review pending suspend account approvals.',
    data: {
      profile_icon: 'suspend',
    },
    action_url: '/erp/manager/suspend-approval',
    is_read: false,
    created_at: now,
  },
  {
    id: 910002,
    type: 'manager_repair_rejection_review',
    title: 'Repair Rejection Review',
    message: 'Review rejected repair requests.',
    data: {
      profile_icon: 'repair_rejection',
    },
    action_url: '/erp/manager/repair-rejection-review',
    is_read: false,
    created_at: now,
  },
  {
    id: 910003,
    type: 'manager_inventory_overview',
    title: 'Inventory Overview',
    message: 'Check inventory overview updates.',
    data: {
      profile_icon: 'inventory',
    },
    action_url: '/erp/manager/inventory-overview',
    is_read: false,
    created_at: now,
  },
  {
    id: 910006,
    type: 'manager_my_payslips',
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
