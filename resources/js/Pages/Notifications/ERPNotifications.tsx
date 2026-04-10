/**
 * ERP Notification Page
 * Full notification page for ERP staff
 */

import React from 'react';
import { usePage } from '@inertiajs/react';
import NotificationList from './NotificationList';

const ERPNotifications: React.FC = () => {
  const { auth } = usePage().props as any;
  const userRole = String(auth?.user?.role || '').trim().toUpperCase();
  const userRoles = Array.isArray(auth?.user?.roles)
    ? auth.user.roles.map((role: string) => String(role).trim().toUpperCase())
    : [];

  const normalizedRoles = [userRole, ...userRoles].filter((role) => role.length > 0);
  const staffScopedRoles = new Set(['STAFF', 'REPAIRER']);
  const isStaffScopedNotifications = normalizedRoles.some((role) => staffScopedRoles.has(role));
  const basePath = isStaffScopedNotifications ? '/api/staff/notifications' : '/api/hr/notifications';

  return (
    <NotificationList 
      basePath={basePath}
      title="ERP Notifications"
    />
  );
};

export default ERPNotifications;
