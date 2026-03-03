import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const HR_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 930001,
    type: 'hr_leave_requests',
    title: 'Leave Requests',
    message: 'Review employee leave requests.',
    data: {
      profile_icon: 'leave',
    },
    action_url: '/erp/hr?section=leaves',
    is_read: false,
    created_at: now,
  },
  {
    id: 930002,
    type: 'hr_overtime_requests',
    title: 'Overtime Requests',
    message: 'Review employee overtime requests.',
    data: {
      profile_icon: 'overtime',
    },
    action_url: '/erp/hr?section=overtime',
    is_read: false,
    created_at: now,
  },
];
