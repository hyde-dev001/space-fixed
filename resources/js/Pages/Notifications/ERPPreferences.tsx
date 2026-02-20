/**
 * ERP Notification Preferences
 */

import React from 'react';
import NotificationPreferences from './NotificationPreferences';

const ERPPreferences: React.FC = () => {
  return (
    <NotificationPreferences 
      basePath="/api/hr/notifications"
      title="ERP Notification Settings"
      userType="erp"
    />
  );
};

export default ERPPreferences;
