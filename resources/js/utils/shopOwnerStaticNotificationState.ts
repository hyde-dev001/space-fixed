import type { Notification } from '../hooks/useNotifications';
import { SHOP_OWNER_STATIC_NOTIFICATIONS } from './shopOwnerStaticNotifications';

const STORAGE_KEY = 'shop_owner_static_notification_read_ids';
const ARCHIVED_STORAGE_KEY = 'shop_owner_static_notification_archived_ids';
const EVENT_NAME = 'shop-owner-static-notifications-updated';

const canUseStorage = () => typeof window !== 'undefined' && typeof window.localStorage !== 'undefined';

const getReadIds = (): number[] => {
  if (!canUseStorage()) return [];

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((value) => Number.isFinite(value)).map((value) => Number(value));
  } catch {
    return [];
  }
};

const getArchivedIds = (): number[] => {
  if (!canUseStorage()) return [];

  try {
    const raw = window.localStorage.getItem(ARCHIVED_STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((value) => Number.isFinite(value)).map((value) => Number(value));
  } catch {
    return [];
  }
};

const saveReadIds = (ids: number[]) => {
  if (!canUseStorage()) return;

  const uniqueIds = Array.from(new Set(ids.map((id) => Number(id)))).filter((id) => Number.isFinite(id));
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(uniqueIds));
  window.dispatchEvent(new CustomEvent(EVENT_NAME));
};

const saveArchivedIds = (ids: number[]) => {
  if (!canUseStorage()) return;

  const uniqueIds = Array.from(new Set(ids.map((id) => Number(id)))).filter((id) => Number.isFinite(id));
  window.localStorage.setItem(ARCHIVED_STORAGE_KEY, JSON.stringify(uniqueIds));
  window.dispatchEvent(new CustomEvent(EVENT_NAME));
};

export const getShopOwnerStaticNotificationsWithReadState = (archivedOnly = false): Notification[] => {
  const readIds = new Set(getReadIds());
  const archivedIds = new Set(getArchivedIds());

  return SHOP_OWNER_STATIC_NOTIFICATIONS
    .map((notification) => ({
      ...notification,
      is_read: readIds.has(notification.id),
    }))
    .filter((notification) => archivedOnly ? archivedIds.has(notification.id) : !archivedIds.has(notification.id));
};

export const markShopOwnerStaticNotificationAsRead = (id: number) => {
  const ids = getReadIds();
  if (ids.includes(id)) return;
  saveReadIds([...ids, id]);
};

export const markAllShopOwnerStaticNotificationsAsRead = () => {
  saveReadIds(SHOP_OWNER_STATIC_NOTIFICATIONS.map((notification) => notification.id));
};

export const clearShopOwnerStaticNotificationReadState = () => {
  saveReadIds([]);
};

export const archiveShopOwnerStaticNotification = (id: number) => {
  const ids = getArchivedIds();
  if (ids.includes(id)) return;
  saveArchivedIds([...ids, id]);
};

export const unarchiveShopOwnerStaticNotification = (id: number) => {
  const ids = getArchivedIds();
  if (!ids.includes(id)) return;
  saveArchivedIds(ids.filter((archivedId) => archivedId !== id));
};

export const SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT = EVENT_NAME;
