import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const REPAIRER_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 950001,
    type: 'repairer_job_orders',
    title: 'Job Orders Repair',
    message: 'Open Job Orders Repair to review assigned repairs.',
    data: {
      profile_icon: 'job_orders_repair',
    },
    action_url: '/erp/staff/job-orders-repair',
    is_read: false,
    created_at: now,
  },
  {
    id: 950002,
    type: 'repairer_pricing',
    title: 'Repair Pricing',
    message: 'Review and update service pricing.',
    data: {
      profile_icon: 'repair_pricing',
    },
    action_url: '/erp/repairer/pricing-and-services',
    is_read: false,
    created_at: now,
  },
  {
    id: 950003,
    type: 'repairer_stocks',
    title: 'Stocks Overview',
    message: 'Check available repair materials and stock levels.',
    data: {
      profile_icon: 'stocks_overview',
    },
    action_url: '/erp/staff/stocks-overview',
    is_read: false,
    created_at: now,
  },
  {
    id: 950004,
    type: 'repairer_chat',
    title: 'Chat',
    message: 'Open chat to respond to customer and team messages.',
    data: {
      profile_icon: 'chat',
    },
    action_url: '/erp/staff/repairer-support',
    is_read: false,
    created_at: now,
  },
  {
    id: 950005,
    type: 'repairer_payslips',
    title: 'My Payslips',
    message: 'View your latest payslips and payroll details.',
    data: {
      profile_icon: 'payslip',
    },
    action_url: '/erp/my-payslips',
    is_read: false,
    created_at: now,
  },
];
