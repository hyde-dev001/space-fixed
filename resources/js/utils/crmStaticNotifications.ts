import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const CRM_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 940001,
    type: 'crm_customer_support',
    title: 'Customer Support Inbox',
    message: 'Check new customer support conversations and respond quickly.',
    data: {
      profile_icon: 'support',
    },
    action_url: '/crm/customer-support',
    is_read: false,
    created_at: now,
  },
  {
    id: 940002,
    type: 'crm_customer_reviews',
    title: 'Customer Reviews Follow-up',
    message: 'Review customer feedback and post follow-up responses.',
    data: {
      profile_icon: 'review',
    },
    action_url: '/crm/customer-reviews',
    is_read: false,
    created_at: now,
  },
  {
    id: 940003,
    type: 'crm_my_payslips',
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
