/**
 * Customer Notification Preferences
 */

import React from 'react';
import NotificationPreferences from './NotificationPreferences';
import Navigation from '../UserSide/Shared/Navigation';

const CustomerPreferences: React.FC = () => {
  return (
    <>
      <Navigation />
      <NotificationPreferences
        basePath="/api/notifications"
        title="Notification Settings"
        userType="customer"
      />
    </>
  );
};

export default CustomerPreferences;
