/**
 * Customer Notification Page
 * Full notification page for customers
 */

import React from 'react';
import NotificationList from './NotificationList';
import Navigation from '../UserSide/Shared/Navigation';

const CustomerNotifications: React.FC = () => {
  return (
    <>
      <Navigation />
      <NotificationList
        basePath="/api/notifications"
        title="My Notifications"
      />
    </>
  );
};

export default CustomerNotifications;
