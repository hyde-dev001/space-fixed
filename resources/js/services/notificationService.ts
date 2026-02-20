/**
 * Notification API Service
 * Handles all notification-related API calls
 */

import axios from 'axios';
import type { 
  Notification, 
  PaginatedNotifications, 
  NotificationStats, 
  NotificationFilters 
} from '../types/notifications';

export class NotificationService {
  private baseUrl: string;

  constructor(baseUrl: string = '/api/notifications') {
    this.baseUrl = baseUrl;
  }

  /**
   * Get paginated notifications with optional filters
   */
  async getNotifications(filters?: NotificationFilters): Promise<PaginatedNotifications> {
    const response = await axios.get<PaginatedNotifications>(this.baseUrl, { params: filters });
    return response.data;
  }

  /**
   * Get unread notification count
   */
  async getUnreadCount(): Promise<number> {
    const response = await axios.get<{ count: number }>(`${this.baseUrl}/unread-count`);
    return response.data.count;
  }

  /**
   * Get recent notifications (for dropdown)
   */
  async getRecent(limit: number = 5): Promise<Notification[]> {
    const response = await axios.get<Notification[]>(`${this.baseUrl}/recent`, {
      params: { limit }
    });
    return response.data;
  }

  /**
   * Get notification statistics
   */
  async getStats(): Promise<NotificationStats> {
    const response = await axios.get<NotificationStats>(`${this.baseUrl}/stats`);
    return response.data;
  }

  /**
   * Mark a notification as read
   */
  async markAsRead(id: number): Promise<void> {
    await axios.post(`${this.baseUrl}/${id}/read`);
  }

  /**
   * Mark all notifications as read
   */
  async markAllAsRead(): Promise<number> {
    const response = await axios.post<{ count: number }>(`${this.baseUrl}/mark-all-read`);
    return response.data.count;
  }

  /**
   * Delete a notification
   */
  async deleteNotification(id: number): Promise<void> {
    await axios.delete(`${this.baseUrl}/${id}`);
  }
}

// Pre-configured services for different user types
export const customerNotificationService = new NotificationService('/api/notifications');
export const shopOwnerNotificationService = new NotificationService('/api/shop-owner/notifications');
export const erpNotificationService = new NotificationService('/api/hr/notifications');
