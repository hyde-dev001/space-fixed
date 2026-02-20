/**
 * Shop Owner Notification Page
 * Full notification page for shop owners
 */

import React from 'react';
import NotificationList from './NotificationList';

const ShopOwnerNotifications: React.FC = () => {
  return (
    <NotificationList 
      basePath="/api/shop-owner/notifications"
      title="Shop Notifications"
    />
  );
};

export default ShopOwnerNotifications;
