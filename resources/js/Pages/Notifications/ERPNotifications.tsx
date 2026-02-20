/**
 * ERP Notification Page
 * Full notification page for ERP staff
 */

import React from 'react';
import NotificationList from './NotificationList';

const ERPNotifications: React.FC = () => {
  return (
    <NotificationList 
      basePath="/api/hr/notifications"
      title="ERP Notifications"
    />
  );
};

export default ERPNotifications;
