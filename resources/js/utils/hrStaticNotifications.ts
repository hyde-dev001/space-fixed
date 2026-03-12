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
  {
    id: 930003,
    type: 'hr_my_payslips',
    title: 'My Payslips',
    message: 'View your payslip records and payroll history.',
    data: {
      profile_icon: 'my_payslips',
    },
    action_url: '/erp/my-payslips',
    is_read: false,
    created_at: now,
  },
  {
    id: 930004,
    type: 'hr_payroll_generation',
    title: 'Payroll Generation',
    message: 'Generate and manage employee payroll for the current period.',
    data: {
      profile_icon: 'payroll',
    },
    action_url: '/erp/hr?section=payroll',
    is_read: false,
    created_at: now,
  },
  {
    id: 930005,
    type: 'hr_attendance_management',
    title: 'Attendance Management',
    message: 'Monitor and manage employee attendance records.',
    data: {
      profile_icon: 'attendance',
    },
    action_url: '/erp/hr?section=attendance',
    is_read: false,
    created_at: now,
  },
  {
    id: 930006,
    type: 'hr_employee_directory',
    title: 'Employee Directory',
    message: 'View and manage the employee directory.',
    data: {
      profile_icon: 'employees',
    },
    action_url: '/erp/hr?section=employees',
    is_read: false,
    created_at: now,
  },
];
