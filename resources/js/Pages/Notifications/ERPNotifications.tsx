/**
 * ERP Notification Page
 * Full notification page for ERP staff
 */

import React from 'react';
import { usePage } from '@inertiajs/react';
import NotificationList from './NotificationList';

const ERPNotifications: React.FC = () => {
  const { auth } = usePage().props as any;
  const userRole = String(auth?.user?.role || '').toUpperCase();
  const userRoles = Array.isArray(auth?.user?.roles) ? auth.user.roles.map((role: string) => String(role).toUpperCase()) : [];
  const isStaffRole = userRole.includes('STAFF') || userRoles.includes('STAFF');
  const isRepairerRole = userRole === 'REPAIRER' || userRoles.includes('REPAIRER');
  const isStaffScopedNotifications = isRepairerRole || isStaffRole;
  const basePath = isStaffScopedNotifications ? '/api/staff/notifications' : '/api/hr/notifications';

  return (
    <NotificationList 
      basePath={basePath}
      title="ERP Notifications"
    />
  );
};

export default ERPNotifications;
