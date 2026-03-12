import type { Notification } from '../hooks/useNotifications';
import * as staffStaticNotificationsModule from './staffStaticNotifications';

const STAFF_STATIC_NOTIFICATIONS: Notification[] = Array.isArray(staffStaticNotificationsModule.STAFF_STATIC_NOTIFICATIONS)
	? staffStaticNotificationsModule.STAFF_STATIC_NOTIFICATIONS
	: [
			{
				id: 980001,
				type: 'staff_job_orders_retail',
				title: 'Job Orders Retail',
				message: 'Open and manage your retail job orders.',
				data: { profile_icon: 'job_orders_retail' },
				action_url: '/erp/staff/job-orders',
				is_read: false,
				created_at: new Date().toISOString(),
			},
			{
				id: 980002,
				type: 'staff_product_uploader',
				title: 'Product Uploader',
				message: 'Upload and update product listings for the shop.',
				data: { profile_icon: 'product_uploader' },
				action_url: '/erp/staff/products',
				is_read: false,
				created_at: new Date().toISOString(),
			},
			{
				id: 980003,
				type: 'staff_shoe_pricing',
				title: 'Shoe Pricing',
				message: 'Review and maintain shoe pricing details.',
				data: { profile_icon: 'shoe_pricing' },
				action_url: '/erp/staff/shoe-pricing',
				is_read: false,
				created_at: new Date().toISOString(),
			},
			{
				id: 980004,
				type: 'staff_inventory_overview',
				title: 'Inventory Overview',
				message: 'Check stock status and inventory summary.',
				data: { profile_icon: 'inventory_overview' },
				action_url: '/erp/staff/inventory-overview',
				is_read: false,
				created_at: new Date().toISOString(),
			},
			{
				id: 980005,
				type: 'staff_my_payslips',
				title: 'My Payslips',
				message: 'View your payslip records and payroll history.',
				data: { profile_icon: 'my_payslips' },
				action_url: '/erp/my-payslips',
				is_read: false,
				created_at: new Date().toISOString(),
			},
		];

const STORAGE_KEY = 'staff_static_notification_read_ids_v2';
const ARCHIVED_STORAGE_KEY = 'staff_static_notification_archived_ids_v2';
const EVENT_NAME = 'staff-static-notifications-updated';

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

export const getStaffStaticNotificationsWithReadState = (archivedOnly = false): Notification[] => {
	const readIds = new Set(getStoredIds(STORAGE_KEY));
	const archivedIds = new Set(getStoredIds(ARCHIVED_STORAGE_KEY));

	return STAFF_STATIC_NOTIFICATIONS
		.map((notification) => ({
			...notification,
			is_read: readIds.has(notification.id),
		}))
		.filter((notification) => archivedOnly ? archivedIds.has(notification.id) : !archivedIds.has(notification.id));
};

export const markStaffStaticNotificationAsRead = (id: number) => {
	const ids = getStoredIds(STORAGE_KEY);
	if (ids.includes(id)) return;
	saveStoredIds(STORAGE_KEY, [...ids, id]);
};

export const markAllStaffStaticNotificationsAsRead = () => {
	saveStoredIds(STORAGE_KEY, STAFF_STATIC_NOTIFICATIONS.map((notification) => notification.id));
};

export const archiveStaffStaticNotification = (id: number) => {
	const ids = getStoredIds(ARCHIVED_STORAGE_KEY);
	if (ids.includes(id)) return;
	saveStoredIds(ARCHIVED_STORAGE_KEY, [...ids, id]);
};

export const unarchiveStaffStaticNotification = (id: number) => {
	const ids = getStoredIds(ARCHIVED_STORAGE_KEY);
	if (!ids.includes(id)) return;
	saveStoredIds(ARCHIVED_STORAGE_KEY, ids.filter((archivedId) => archivedId !== id));
};

export const STAFF_STATIC_NOTIFICATIONS_EVENT = EVENT_NAME;
