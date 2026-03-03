import type { Notification } from '../hooks/useNotifications';

const now = new Date().toISOString();

export const SHOP_OWNER_STATIC_NOTIFICATIONS: Notification[] = [
  {
    id: 900001,
    type: 'shop_owner_inventory_overview',
    title: 'Inventory Overview',
    message: 'View your inventory overview updates.',
    data: {
      sidebar_group: 'Main Menu',
      profile_icon: 'inventory',
    },
    action_url: '/shop-owner/inventory-overview',
    is_read: false,
    created_at: now,
  },
  {
    id: 900002,
    type: 'shop_owner_refund_approval',
    title: 'Refund Approval',
    message: 'Review refund approval requests.',
    data: {
      sidebar_group: 'Approval Workflow',
      profile_icon: 'refund',
    },
    action_url: '/shop-owner/refund-approvals',
    is_read: false,
    created_at: now,
  },
  {
    id: 900003,
    type: 'shop_owner_price_approval',
    title: 'Price Approval',
    message: 'Check price approval requests.',
    data: {
      sidebar_group: 'Approval Workflow',
      profile_icon: 'price',
    },
    action_url: '/shop-owner/price-approvals',
    is_read: false,
    created_at: now,
  },
  {
    id: 900004,
    type: 'shop_owner_purchase_request_approval',
    title: 'Purchase Request Approval',
    message: 'Review purchase request approvals.',
    data: {
      sidebar_group: 'Approval Workflow',
      profile_icon: 'purchase',
    },
    action_url: '/shop-owner/purchase-request-approval',
    is_read: false,
    created_at: now,
  },
  {
    id: 900005,
    type: 'shop_owner_reject_approval',
    title: 'Repair Reject Approval',
    message: 'Handle repair reject approvals.',
    data: {
      sidebar_group: 'Approval Workflow',
      profile_icon: 'repair_reject',
    },
    action_url: '/shop-owner/repair-reject-approval',
    is_read: false,
    created_at: now,
  },
];
