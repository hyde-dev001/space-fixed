/**
 * ERP Notification Preferences
 */

import React from 'react';
import { usePage } from '@inertiajs/react';
import NotificationPreferences from './NotificationPreferences';

const ERPPreferences: React.FC = () => {
  const { auth } = usePage().props as any;
  const userRole = String(auth?.user?.role || '').toUpperCase();
  const userRoles = Array.isArray(auth?.user?.roles) ? auth.user.roles.map((role: string) => String(role).toUpperCase()) : [];
  
  // Debug: Log the user role to see what we're getting
  console.log('ERP Preferences - User Role:', userRole, 'Type:', typeof userRole);
  
  // Determine the correct notification API basePath based on user role
  // Staff/Repairers use /api/staff/notifications (requires 'old_role:Staff|Manager|Shop Owner|Repairer')
  // HR/Finance/Manager use /api/hr/notifications (requires HR permissions)
  const isStaffRole = userRole.includes('STAFF') || userRoles.includes('STAFF');
  const isRepairerRole = userRole === 'REPAIRER' || userRoles.includes('REPAIRER');
  const isStaffScopedNotifications = isRepairerRole || isStaffRole;
  const basePath = isStaffScopedNotifications ? '/api/staff/notifications' : '/api/hr/notifications';

  console.log('Using basePath:', basePath);

  return (
    <NotificationPreferences 
      basePath={basePath}
      title="ERP Notification Settings"
      userType="erp"
    />
  );
};

export default ERPPreferences;
