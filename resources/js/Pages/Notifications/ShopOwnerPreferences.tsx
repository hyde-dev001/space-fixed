/**
 * Shop Owner Notification Preferences
 */

import React from 'react';
import NotificationPreferences from './NotificationPreferences';

const ShopOwnerPreferences: React.FC = () => {
  return (
    <NotificationPreferences 
      basePath="/api/shop-owner/notifications"
      title="Shop Notification Settings"
      userType="shop_owner"
    />
  );
};

export default ShopOwnerPreferences;
