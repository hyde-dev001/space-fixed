/**
 * Customer Notification Preferences
 */

import React from 'react';
import NotificationPreferences from './NotificationPreferences';

const CustomerPreferences: React.FC = () => {
  return (
    <NotificationPreferences 
      basePath="/api/notifications"
      title="Notification Settings"
      userType="customer"
    />
  );
};

export default CustomerPreferences;
