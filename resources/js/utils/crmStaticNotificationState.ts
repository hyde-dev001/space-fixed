import type { Notification } from '../hooks/useNotifications';
import { CRM_STATIC_NOTIFICATIONS } from './crmStaticNotifications';

const STORAGE_KEY = 'crm_static_notification_read_ids';
const ARCHIVED_STORAGE_KEY = 'crm_static_notification_archived_ids';
const EVENT_NAME = 'crm-static-notifications-updated';

const canUseStorage = () => typeof window !== 'undefined' && typeof window.localStorage !== 'undefined';

const getStoredIds = (key: string): number[] => {
  if (!canUseStorage()) return [];

  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((value) => Number.isFinite(value)).map((value) => Number(value));
  } catch {
    return [];
  }
};

const saveStoredIds = (key: string, ids: number[]) => {
  if (!canUseStorage()) return;

  const uniqueIds = Array.from(new Set(ids.map((id) => Number(id)))).filter((id) => Number.isFinite(id));
  window.localStorage.setItem(key, JSON.stringify(uniqueIds));
  window.dispatchEvent(new CustomEvent(EVENT_NAME));
};

export const getCrmStaticNotificationsWithReadState = (archivedOnly = false): Notification[] => {
  const readIds = new Set(getStoredIds(STORAGE_KEY));
  const archivedIds = new Set(getStoredIds(ARCHIVED_STORAGE_KEY));

  return CRM_STATIC_NOTIFICATIONS
    .map((notification) => ({
      ...notification,
      is_read: readIds.has(notification.id),
    }))
    .filter((notification) => archivedOnly ? archivedIds.has(notification.id) : !archivedIds.has(notification.id));
};

export const markCrmStaticNotificationAsRead = (id: number) => {
  const ids = getStoredIds(STORAGE_KEY);
  if (ids.includes(id)) return;
  saveStoredIds(STORAGE_KEY, [...ids, id]);
};

export const markAllCrmStaticNotificationsAsRead = () => {
  saveStoredIds(STORAGE_KEY, CRM_STATIC_NOTIFICATIONS.map((notification) => notification.id));
};

export const archiveCrmStaticNotification = (id: number) => {
  const ids = getStoredIds(ARCHIVED_STORAGE_KEY);
  if (ids.includes(id)) return;
  saveStoredIds(ARCHIVED_STORAGE_KEY, [...ids, id]);
};

export const unarchiveCrmStaticNotification = (id: number) => {
  const ids = getStoredIds(ARCHIVED_STORAGE_KEY);
  if (!ids.includes(id)) return;
  saveStoredIds(ARCHIVED_STORAGE_KEY, ids.filter((archivedId) => archivedId !== id));
};

export const CRM_STATIC_NOTIFICATIONS_EVENT = EVENT_NAME;
