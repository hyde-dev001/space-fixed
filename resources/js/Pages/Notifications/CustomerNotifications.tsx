/**
 * Customer Notification Page
 * Full notification page for customers
 */

import React from 'react';
import NotificationList from './NotificationList';

const CustomerNotifications: React.FC = () => {
  return (
    <NotificationList 
      basePath="/api/notifications"
      title="My Notifications"
    />
  );
};

export default CustomerNotifications;
