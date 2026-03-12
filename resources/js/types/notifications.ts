/**
 * Notification Types
 * Type definitions for the notification system
 */

export type NotificationCategory = 
  | 'orders' 
  | 'repairs' 
  | 'payments' 
  | 'messages' 
  | 'reviews' 
  | 'finance' 
  | 'hr' 
  | 'crm' 
  | 'general';

export interface NotificationData {
  [key: string]: any;
}

export interface Notification {
  id: number;
  type: string;
  type_label: string;
  category: NotificationCategory;
  title: string;
  message: string;
  data: NotificationData | null;
  action_url: string | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginatedNotifications {
  current_page: number;
  data: Notification[];
  first_page_url: string;
  from: number;
  last_page: number;
  last_page_url: string;
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number;
  total: number;
}

export interface NotificationStats {
  total: number;
  unread: number;
  by_category: Record<string, number>;
  requires_action: number;
}

export interface NotificationFilters {
  unread_only?: boolean;
  category?: NotificationCategory;
  requires_action?: boolean;
  per_page?: number;
}

export interface NotificationBellProps {
  baseUrl?: string; // '/api/notifications' for customers, '/api/shop-owner/notifications' for shop owners
  className?: string;
  iconSize?: number;
}

export interface NotificationDropdownProps {
  notifications: Notification[];
  unreadCount: number;
  baseUrl: string;
  onMarkAsRead: (id: number) => void;
  onMarkAllAsRead: () => void;
  onClose: () => void;
  isOpen: boolean;
}

export interface NotificationItemProps {
  notification: Notification;
  onMarkAsRead?: (id: number) => void;
  onClick?: () => void;
  showActions?: boolean;
}
