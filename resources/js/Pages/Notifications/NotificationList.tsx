                                /**
 * NotificationList Page Component - Phase 7 Enhanced
 * Full notification management with advanced filters, bulk actions, and Phase 6 features
 */

import React, { useEffect, useMemo, useState } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import Swal from 'sweetalert2';
import { 
  Bell, Filter, Trash2, CheckCheck, AlertCircle, Settings, 
  Search, Calendar, Archive, Download, X, ChevronDown, CheckSquare, Square, ArchiveRestore,
} from 'lucide-react';
import { useNotifications, useMarkAsRead, useMarkAllAsRead, useDeleteNotification, useUnarchiveNotification, type NotificationFilters } from '../../hooks/useNotifications';
import NotificationItem from '../../Components/common/NotificationItem';
import ExportModal from '../../Components/Notifications/ExportModal';
import type { Notification } from '../../hooks/useNotifications';
import {
  getShopOwnerStaticNotificationsWithReadState,
  markAllShopOwnerStaticNotificationsAsRead,
  markShopOwnerStaticNotificationAsRead,
  archiveShopOwnerStaticNotification,
  unarchiveShopOwnerStaticNotification,
  SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/shopOwnerStaticNotificationState';
import {
  archiveManagerStaticNotification,
  getManagerStaticNotificationsWithReadState,
  MANAGER_STATIC_NOTIFICATIONS_EVENT,
  markAllManagerStaticNotificationsAsRead,
  markManagerStaticNotificationAsRead,
  unarchiveManagerStaticNotification,
} from '../../utils/managerStaticNotificationState';
import {
  archiveFinanceStaticNotification,
  FINANCE_STATIC_NOTIFICATIONS_EVENT,
  getFinanceStaticNotificationsWithReadState,
  markAllFinanceStaticNotificationsAsRead,
  markFinanceStaticNotificationAsRead,
  unarchiveFinanceStaticNotification,
} from '../../utils/financeStaticNotificationState';
import {
  archiveHrStaticNotification,
  getHrStaticNotificationsWithReadState,
  HR_STATIC_NOTIFICATIONS_EVENT,
  markAllHrStaticNotificationsAsRead,
  markHrStaticNotificationAsRead,
  unarchiveHrStaticNotification,
} from '../../utils/hrStaticNotificationState';

interface NotificationListProps {
  basePath?: string;
  title?: string;
}

const NotificationList: React.FC<NotificationListProps> = ({ 
  basePath = '/api/notifications',
  title = 'Notifications'
}) => {
  const page = usePage();
  const { auth } = page.props as any;
  const [currentPage, setCurrentPage] = useState(1);
  const [showFilters, setShowFilters] = useState(true);
  const [showArchived, setShowArchived] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkActionMode, setBulkActionMode] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [showExportModal, setShowExportModal] = useState(false);
  const [shopOwnerNotifications, setShopOwnerNotifications] = useState<Notification[]>([]);
  const [managerNotifications, setManagerNotifications] = useState<Notification[]>([]);
  const [financeNotifications, setFinanceNotifications] = useState<Notification[]>([]);
  const [hrNotifications, setHrNotifications] = useState<Notification[]>([]);
  const isShopOwnerView = basePath.includes('shop-owner');
  const isErpView = basePath.includes('hr');
  const normalizedUserRole = String(auth?.user?.role || '').toUpperCase();
  const isManagerStaticView = isErpView && normalizedUserRole === 'MANAGER';
  const isFinanceStaticView = isErpView && normalizedUserRole.includes('FINANCE');
  const isHrStaticView = isErpView && normalizedUserRole.includes('HR') && !isManagerStaticView && !isFinanceStaticView;
  const isStaticView = isShopOwnerView || isManagerStaticView || isFinanceStaticView || isHrStaticView;
  const isCustomerView = !isShopOwnerView && !isErpView;
  const highlightedIdFromUrl = useMemo(() => {
    const queryString = page.url.includes('?') ? page.url.split('?')[1] : '';
    const highlightParam = new URLSearchParams(queryString).get('highlight');
    const parsed = Number(highlightParam);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }, [page.url]);
  const archivedFromUrl = useMemo(() => {
    const queryString = page.url.includes('?') ? page.url.split('?')[1] : '';
    const archivedParam = new URLSearchParams(queryString).get('archived');
    return archivedParam === '1' || archivedParam === 'true';
  }, [page.url]);
  const [highlightedNotificationId, setHighlightedNotificationId] = useState<number | null>(highlightedIdFromUrl);
  const [filters, setFilters] = useState<NotificationFilters>({
    unread_only: false,
    action_required: false,
    priority: undefined,
    start_date: undefined,
    end_date: undefined,
    archived: false,
  });

  const notificationsListHref = basePath.includes('shop-owner')
    ? '/shop-owner/notifications'
    : basePath.includes('staff') || basePath.includes('hr')
      ? '/erp/notifications'
      : '/notifications';

  const { data, isLoading, error } = useNotifications(
    filters.unread_only,
    currentPage,
    basePath,
    filters.category,
    filters.action_required,
    filters.priority,
    filters.archived,
    filters.start_date,
    filters.end_date,
    searchQuery
  );

  const markAsRead = useMarkAsRead(basePath);
  const markAllAsRead = useMarkAllAsRead(basePath, 'mark-all-read');
  const deleteNotification = useDeleteNotification(basePath);
  const unarchiveNotification = useUnarchiveNotification(basePath);

  useEffect(() => {
    setHighlightedNotificationId(highlightedIdFromUrl);
  }, [highlightedIdFromUrl]);

  useEffect(() => {
    setShowArchived(archivedFromUrl);
    setFilters((prev) => ({ ...prev, archived: archivedFromUrl }));
  }, [archivedFromUrl]);

  useEffect(() => {
    if (!isShopOwnerView) return;

    const sync = () => setShopOwnerNotifications(getShopOwnerStaticNotificationsWithReadState(showArchived));
    sync();

    window.addEventListener(SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isShopOwnerView, showArchived]);

  useEffect(() => {
    if (!isManagerStaticView) return;

    const sync = () => setManagerNotifications(getManagerStaticNotificationsWithReadState(showArchived));
    sync();

    window.addEventListener(MANAGER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(MANAGER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isManagerStaticView, showArchived]);

  useEffect(() => {
    if (!isFinanceStaticView) return;

    const sync = () => setFinanceNotifications(getFinanceStaticNotificationsWithReadState(showArchived));
    sync();

    window.addEventListener(FINANCE_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(FINANCE_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isFinanceStaticView, showArchived]);

  useEffect(() => {
    if (!isHrStaticView) return;

    const sync = () => setHrNotifications(getHrStaticNotificationsWithReadState(showArchived));
    sync();

    window.addEventListener(HR_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(HR_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isHrStaticView, showArchived]);

  const notifications = useMemo(() => {
    if (isShopOwnerView) return shopOwnerNotifications;
    if (isManagerStaticView) return managerNotifications;
    if (isFinanceStaticView) return financeNotifications;
    if (isHrStaticView) return hrNotifications;
    return Array.isArray(data?.data) ? data.data : [];
  }, [isShopOwnerView, shopOwnerNotifications, isManagerStaticView, managerNotifications, isFinanceStaticView, financeNotifications, isHrStaticView, hrNotifications, data]);

  const totalNotifications = isStaticView
    ? notifications.length
    : typeof data?.total === 'number'
      ? data.total
      : notifications.length;
  const unreadCount = isStaticView
    ? notifications.filter((notification: Notification) => !notification.is_read).length
    : typeof data?.unread_count === 'number'
      ? data.unread_count
      : 0;
  const lastPage = isStaticView ? 1 : typeof data?.last_page === 'number' ? data.last_page : 1;
  const from = isStaticView
    ? (notifications.length > 0 ? 1 : 0)
    : typeof data?.from === 'number'
      ? data.from
      : notifications.length > 0
        ? 1
        : 0;
  const to = isStaticView
    ? notifications.length
    : typeof data?.to === 'number'
      ? data.to
      : notifications.length;

  useEffect(() => {
    if (!highlightedNotificationId || notifications.length === 0) return;

    const target = document.getElementById(`notification-${highlightedNotificationId}`);
    if (!target) return;

    target.scrollIntoView({ behavior: 'smooth', block: 'center' });

    const timer = window.setTimeout(() => {
      setHighlightedNotificationId(null);
    }, 5000);

    return () => window.clearTimeout(timer);
  }, [highlightedNotificationId, notifications]);

  const handleFilterChange = (key: keyof NotificationFilters, value: any) => {
    setFilters(prev => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const handleMarkAsRead = (id: number) => {
    if (isShopOwnerView) {
      markShopOwnerStaticNotificationAsRead(id);
      return;
    }
    if (isManagerStaticView) {
      markManagerStaticNotificationAsRead(id);
      return;
    }
    if (isFinanceStaticView) {
      markFinanceStaticNotificationAsRead(id);
      return;
    }
    if (isHrStaticView) {
      markHrStaticNotificationAsRead(id);
      return;
    }
    markAsRead.mutate(id);
  };

  const getNotificationHref = (notification: Notification) => {
    if (isShopOwnerView && notification.action_url) {
      return notification.action_url;
    }
    if (isManagerStaticView && notification.action_url) {
      return notification.action_url;
    }
    if (isFinanceStaticView && notification.action_url) {
      return notification.action_url;
    }
    if (isHrStaticView && notification.action_url) {
      return notification.action_url;
    }

    if (basePath.includes('staff') && notification.type?.includes('repair')) {
      const repairId = notification.data?.repair_id || notification.data?.repair_request_id || notification.id;
      return `/erp/staff/job-orders-repair?highlightRepair=${repairId}`;
    }

    if (basePath.includes('shop-owner') && notification.type?.includes('repair')) {
      const repairId = notification.data?.repair_id || notification.data?.repair_request_id || notification.id;
      return `/shop-owner/job-orders-repair?highlightRepair=${repairId}`;
    }

    if (!basePath.includes('shop-owner') && !basePath.includes('staff') && notification.type?.includes('repair')) {
      const repairId = notification.data?.repair_id || notification.data?.repair_request_id || notification.id;
      return `/my-repairs?highlightRepair=${repairId}`;
    }

    if (!basePath.includes('shop-owner') && !basePath.includes('staff') && notification.type?.includes('order')) {
      const orderId = notification.data?.order_id || notification.id;
      return `/my-orders?highlightOrder=${orderId}`;
    }

    const type = notification.type?.toLowerCase() || '';
    const data = notification.data || {};

    const isRepairNotification = type.includes('repair') || data.repair_id || data.repair_request_id;
    if (isRepairNotification) {
      const repairId = data.repair_id ?? data.repair_request_id;
      if (basePath.includes('shop-owner')) {
        return repairId ? `/shop-owner/job-orders-repair?highlightRepair=${repairId}` : '/shop-owner/job-orders-repair';
      }
      if (basePath.includes('staff')) {
        return repairId ? `/erp/staff/job-orders-repair?highlightRepair=${repairId}` : '/erp/staff/job-orders-repair';
      }
      return repairId ? `/my-repairs?highlightRepair=${repairId}` : '/my-repairs';
    }

    const isOrderNotification = type.includes('order') || data.order_id;
    if (isOrderNotification) {
      const orderId = data.order_id;
      return orderId ? `/my-orders?highlightOrder=${orderId}` : '/my-orders';
    }

    return notification.action_url || notificationsListHref;
  };

  const handleNotificationNavigate = (notification: Notification) => {
    if (bulkActionMode) return;

    if (!notification.is_read) {
      handleMarkAsRead(notification.id);
    }

    const href = getNotificationHref(notification);
    if (href) {
      window.location.href = href;
    }
  };

  const handleMarkAllAsRead = () => {
    if (isShopOwnerView) {
      markAllShopOwnerStaticNotificationsAsRead();
      return;
    }
    if (isManagerStaticView) {
      markAllManagerStaticNotificationsAsRead();
      return;
    }
    if (isFinanceStaticView) {
      markAllFinanceStaticNotificationsAsRead();
      return;
    }
    if (isHrStaticView) {
      markAllHrStaticNotificationsAsRead();
      return;
    }
    if (confirm('Mark all notifications as read?')) {
      markAllAsRead.mutate();
    }
  };

  const handleDelete = async (id: number) => {
    if (isShopOwnerView) {
      archiveShopOwnerStaticNotification(id);
      return;
    }
    if (isManagerStaticView) {
      archiveManagerStaticNotification(id);
      return;
    }
    if (isFinanceStaticView) {
      archiveFinanceStaticNotification(id);
      return;
    }
    if (isHrStaticView) {
      archiveHrStaticNotification(id);
      return;
    }
    const result = await Swal.fire({
      title: 'Archive notification?',
      text: 'You can view archived notifications anytime.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, archive it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#DC2626',
    });

    if (!result.isConfirmed) return;

    deleteNotification.mutate(id, {
      onSuccess: async () => {
        await Swal.fire({
          title: 'Archived!',
          text: 'Notification moved to archives.',
          icon: 'success',
          timer: 1400,
          showConfirmButton: false,
        });
      },
      onError: async () => {
        await Swal.fire({
          title: 'Archive failed',
          text: 'Please try again.',
          icon: 'error',
        });
      },
    });
  };

  const handleUnarchive = async (id: number) => {
    if (isShopOwnerView) {
      unarchiveShopOwnerStaticNotification(id);
      return;
    }
    if (isManagerStaticView) {
      unarchiveManagerStaticNotification(id);
      return;
    }
    if (isFinanceStaticView) {
      unarchiveFinanceStaticNotification(id);
      return;
    }
    if (isHrStaticView) {
      unarchiveHrStaticNotification(id);
      return;
    }
    const result = await Swal.fire({
      title: 'Unarchive notification?',
      text: 'This will move the notification back to active.',
      icon: 'info',
      showCancelButton: true,
      confirmButtonText: 'Yes, unarchive it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563EB',
    });

    if (!result.isConfirmed) return;

    unarchiveNotification.mutate(id, {
      onSuccess: async () => {
        setShowArchived(false);
        handleFilterChange('archived', false);

        await Swal.fire({
          title: 'Unarchived!',
          text: 'Notification moved to active.',
          icon: 'success',
          timer: 1400,
          showConfirmButton: false,
        });
      },
      onError: async () => {
        await Swal.fire({
          title: 'Unarchive failed',
          text: 'Please try again.',
          icon: 'error',
        });
      },
    });
  };

  const handleBulkMarkAsRead = async () => {
    if (selectedIds.length === 0) return;
    if (!confirm(`Mark ${selectedIds.length} notifications as read?`)) return;

    try {
      const response = await fetch(`${basePath}/bulk/mark-read`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ ids: selectedIds }),
      });

      if (response.ok) {
        setSelectedIds([]);
        setBulkActionMode(false);
        window.location.reload();
      }
    } catch (error) {
      console.error('Bulk mark as read failed:', error);
    }
  };

  const handleBulkDelete = async () => {
    if (selectedIds.length === 0) return;
    if (!confirm(`Delete ${selectedIds.length} notifications? This action cannot be undone.`)) return;

    try {
      const response = await fetch(`${basePath}/bulk/delete`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ ids: selectedIds }),
      });

      if (response.ok) {
        setSelectedIds([]);
        setBulkActionMode(false);
        window.location.reload();
      }
    } catch (error) {
      console.error('Bulk delete failed:', error);
    }
  };

  const handleBulkArchive = async () => {
    if (selectedIds.length === 0) return;
    if (!confirm(`Archive ${selectedIds.length} notifications?`)) return;

    try {
      const response = await fetch(`${basePath}/bulk/archive`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ ids: selectedIds }),
      });

      if (response.ok) {
        setSelectedIds([]);
        setBulkActionMode(false);
        window.location.reload();
      }
    } catch (error) {
      console.error('Bulk archive failed:', error);
    }
  };

  const handleToggleSelect = (id: number) => {
    setSelectedIds(prev =>
      prev.includes(id)
        ? prev.filter(selectedId => selectedId !== id)
        : [...prev, id]
    );
  };

  const handleSelectAll = () => {
    if (notifications.length > 0) {
      const allIds = notifications.map((n: Notification) => n.id);
      setSelectedIds(selectedIds.length === allIds.length ? [] : allIds);
    }
  };

  const handleExport = async () => {
    try {
      const queryParams = new URLSearchParams();
      if (filters.start_date) queryParams.append('start_date', filters.start_date);
      if (filters.end_date) queryParams.append('end_date', filters.end_date);
      if (filters.priority) queryParams.append('priority', filters.priority);
      if (filters.category) queryParams.append('type', filters.category);

      const response = await fetch(`${basePath}/export?${queryParams.toString()}`, {
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
      });

      if (response.ok) {
        const result = await response.json();
        const dataStr = JSON.stringify(result.data, null, 2);
        const dataUri = 'data:application/json;charset=utf-8,' + encodeURIComponent(dataStr);
        const exportFileDefaultName = `notifications-export-${new Date().toISOString().split('T')[0]}.json`;
        
        const linkElement = document.createElement('a');
        linkElement.setAttribute('href', dataUri);
        linkElement.setAttribute('download', exportFileDefaultName);
        linkElement.click();
      }
    } catch (error) {
      console.error('Export failed:', error);
    }
  };

  const categories = isCustomerView
    ? [
        { value: '', label: 'All Categories' },
        { value: 'orders', label: 'Orders' },
        { value: 'repairs', label: 'Repairs' },
        { value: 'messages', label: 'Messages from Shop' },
      ]
    : [
        { value: '', label: 'All Categories' },
        { value: 'orders', label: 'Orders' },
        { value: 'repairs', label: 'Repairs' },
        { value: 'payments', label: 'Payments' },
        { value: 'messages', label: 'Messages' },
        { value: 'reviews', label: 'Reviews' },
        { value: 'system', label: 'System' },
        { value: 'employees', label: 'Employees' },
        { value: 'leave', label: 'Leave Requests' },
      ];

  const priorities = [
    { value: '', label: 'All Priorities' },
    { value: 'high', label: '🔴 High Priority' },
    { value: 'medium', label: '🔵 Medium Priority' },
    { value: 'low', label: '⚪ Low Priority' },
  ];

  const getPriorityBadge = (priority: string) => {
    switch (priority) {
      case 'high':
        return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">🔴 High</span>;
      case 'low':
        return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">⚪ Low</span>;
      default:
        return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">🔵 Medium</span>;
    }
  };

  const getCustomerSegment = (notification: Notification): 'orders' | 'repairs' | 'messages' | null => {
    const type = notification.type?.toLowerCase() || '';
    const title = notification.title?.toLowerCase() || '';
    const message = notification.message?.toLowerCase() || '';
    const data = notification.data || {};

    if (type.includes('order') || data.order_id || title.includes('order')) return 'orders';
    if (type.includes('repair') || data.repair_id || data.repair_request_id || title.includes('repair')) return 'repairs';
    if (type.includes('message') || data.conversation_id || title.includes('message') || message.includes('chat')) return 'messages';

    return null;
  };

  const getDisplayMessage = (notification: Notification) => {
    if (!isCustomerView) return notification.message;

    const type = notification.type?.toLowerCase() || '';
    const data = notification.data || {};
    const status = (data.status || data.repair_status || '').toLowerCase();

    if (type.includes('order')) {
      return notification.message
        .replace(/Order\s+ORD-[A-Za-z0-9-]+/gi, 'Your order')
        .replace(/ORD-[A-Za-z0-9-]+/gi, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
    }

    if (
      type.includes('repair') &&
      (status === 'assigned_to_repairer' || status === 'repairer_accepted' || type.includes('repair_assigned') || type.includes('repair_accepted'))
    ) {
      return 'Repair request is under review. Please wait for the shop to review it.';
    }

    return notification.message;
  };

  const getShoeName = (notification: Notification) => {
    const data = notification.data || {};

    return (
      data.shoe_name ||
      data.product_name ||
      data.item_name ||
      data.shoe ||
      data.product?.name ||
      data.item?.name ||
      data.items?.[0]?.product_name ||
      data.items?.[0]?.name ||
      null
    );
  };

  const formatTimeAgo = (dateString: string) => {
    const notificationDate = new Date(dateString).getTime();
    const diffMinutes = Math.floor((Date.now() - notificationDate) / (1000 * 60));

    if (diffMinutes < 1) return 'Just now';
    if (diffMinutes < 60) return `${diffMinutes}m`;

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) return `${diffHours}h`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d`;

    return new Date(dateString).toLocaleDateString();
  };

  const customerCategorySections = useMemo(() => {
    if (!isCustomerView) return [] as Array<{ key: 'orders' | 'repairs' | 'messages'; label: string; notifications: Notification[] }>;

    return [
      {
        key: 'orders' as const,
        label: 'Order Updates',
        notifications: notifications.filter((notification: Notification) => getCustomerSegment(notification) === 'orders'),
      },
      {
        key: 'repairs' as const,
        label: 'Repair Updates',
        notifications: notifications.filter((notification: Notification) => getCustomerSegment(notification) === 'repairs'),
      },
      {
        key: 'messages' as const,
        label: 'Messages from Shop',
        notifications: notifications.filter((notification: Notification) => getCustomerSegment(notification) === 'messages'),
      },
    ];
  }, [isCustomerView, notifications]);

  const hasCustomerSectionItems = useMemo(
    () => customerCategorySections.some((section) => section.notifications.length > 0),
    [customerCategorySections]
  );

  const normalizePhotoPath = (photoPath?: string | null) => {
    if (!photoPath) return null;
    if (photoPath.startsWith('http://') || photoPath.startsWith('https://') || photoPath.startsWith('/')) {
      return photoPath;
    }
    return `/storage/${photoPath}`;
  };

  const getShopProfilePhoto = (notification: Notification) => {
    const data = notification.data || {};
    return (
      normalizePhotoPath(data.shop_profile_photo) ||
      normalizePhotoPath(data.profile_photo) ||
      normalizePhotoPath(data.avatar) ||
      normalizePhotoPath(data.shop_logo) ||
      '/images/user/owner.jpg'
    );
  };

  const renderNotificationCard = (notification: Notification) => (
    (() => {
      const shoeName = getShoeName(notification);
      const shopProfilePhoto = getShopProfilePhoto(notification);
      return (
    <div
      id={`notification-${notification.id}`}
      key={notification.id}
      className={`relative group transition-all duration-150 ${
        highlightedNotificationId === notification.id
          ? 'bg-blue-50 ring-2 ring-blue-500 ring-inset dark:bg-blue-900/20'
          : 'hover:bg-gray-50 dark:hover:bg-gray-800/70'
      } ${!bulkActionMode ? 'cursor-pointer' : ''} ${!notification.is_read ? 'border-l-4 border-l-blue-500' : 'border-l-4 border-l-transparent'}`}
      onClick={() => handleNotificationNavigate(notification)}
    >
      <div className="flex items-start gap-3 px-6 py-4">
        {bulkActionMode && (
          <div className="flex items-center pt-1">
            <input
              type="checkbox"
              checked={selectedIds.includes(notification.id)}
              onChange={() => handleToggleSelect(notification.id)}
              onClick={(e) => e.stopPropagation()}
              title={`Select notification ${notification.id}`}
              aria-label={`Select notification ${notification.id}`}
              className="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
            />
          </div>
        )}

        <div className="w-10 h-10 mt-0.5 shrink-0">
          {isCustomerView ? (
            <img
              src={shopProfilePhoto}
              alt="Shop profile"
              onError={(event) => {
                const target = event.currentTarget;
                if (!target.src.includes('/images/user/owner.jpg')) {
                  target.src = '/images/user/owner.jpg';
                }
              }}
              className="w-10 h-10 rounded-full object-cover border border-gray-200 dark:border-gray-700"
            />
          ) : (
            <div className="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
              <Bell size={16} className="text-gray-600 dark:text-gray-300" />
            </div>
          )}
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between gap-4">
            <div className="flex-1">
              <div className="flex items-center gap-2 mb-1">
                {notification.priority && getPriorityBadge(notification.priority)}
                <h3 className={`text-base font-semibold ${notification.is_read ? 'text-gray-600 dark:text-gray-400' : 'text-gray-900 dark:text-white'}`}>
                  {notification.title}
                </h3>
              </div>

              <p className={`text-sm ${notification.is_read ? 'text-gray-500 dark:text-gray-500' : 'text-gray-700 dark:text-gray-200'} mb-2`}>
                {getDisplayMessage(notification)}
              </p>
              {shoeName && (
                <p className="text-xs text-gray-500 mb-2 dark:text-gray-400">
                  Shoe: {shoeName}
                </p>
              )}

              <div className="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span className={`${notification.is_read ? 'text-gray-500 dark:text-gray-400' : 'text-blue-600 dark:text-blue-400'} font-medium`}>
                  {isCustomerView ? formatTimeAgo(notification.created_at) : new Date(notification.created_at).toLocaleString()}
                </span>
                {notification.requires_action && (
                  <span className="inline-flex items-center px-2 py-0.5 rounded bg-orange-100 text-orange-800 font-medium">
                    Action Required
                  </span>
                )}
                {notification.group_key && !isCustomerView && (
                  <span className="inline-flex items-center px-2 py-0.5 rounded bg-purple-100 text-purple-800 font-medium">
                    Grouped
                  </span>
                )}
              </div>
            </div>

            {!bulkActionMode && (
              <div className="flex items-center gap-2">
                {!notification.is_read && <span className="w-2.5 h-2.5 rounded-full bg-blue-600" />}
                {showArchived ? (
                  <>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleUnarchive(notification.id);
                      }}
                      className="p-2 text-blue-600 hover:bg-blue-50 rounded-full transition-colors dark:hover:bg-blue-900/20"
                      title="Unarchive notification"
                    >
                      <ArchiveRestore size={16} />
                    </button>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDelete(notification.id);
                      }}
                      className="p-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-full transition-colors dark:hover:bg-red-900/20 dark:text-red-500"
                      title="Delete permanently"
                    >
                      <Trash2 size={16} />
                    </button>
                  </>
                ) : (
                  <>
                    {!notification.is_read && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          handleMarkAsRead(notification.id);
                        }}
                        className="p-2 text-blue-600 hover:bg-blue-50 rounded-full transition-colors"
                        title="Mark as read"
                      >
                        <CheckCheck size={16} />
                      </button>
                    )}
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDelete(notification.id);
                      }}
                      className="p-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-full transition-colors dark:text-red-500"
                      title="Archive notification"
                    >
                      <Trash2 size={16} />
                    </button>
                  </>
                )}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
      );
    })()
  );

  return (
    <>
      <Head title={title} />

      <div className="min-h-screen px-4 sm:px-6 lg:px-8 py-8 bg-gray-50 dark:bg-gray-950">
        {isShopOwnerView && (
          <div className="mb-2">
            <button
              type="button"
              onClick={() => window.history.back()}
              className="inline-flex items-center px-1 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white"
            >
              ← Back
            </button>
          </div>
        )}

        {isCustomerView && (
          <div className="mb-2">
            <button
              type="button"
              onClick={() => window.history.back()}
              className="inline-flex items-center px-1 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white"
            >
              ← Back
            </button>
          </div>
        )}

        <div className="max-w-7xl mx-auto">

        {/* Header */}
        <div className="mb-8">
          <div className="flex items-center justify-between flex-wrap gap-4">
            <div className="flex items-center gap-3">
              <Bell size={32} className="text-gray-700 dark:text-gray-200" />
              <div>
                <h1 className="text-3xl font-bold text-gray-900 dark:text-white">{showArchived ? 'Archives' : title}</h1>
                {data && (
                  <p className="text-gray-600 mt-1 dark:text-gray-400">
                    {unreadCount > 0 ? (
                      <>
                        <span className="font-semibold text-blue-600">{unreadCount}</span> unread
                        {' • '}
                      </>
                    ) : null}
                    <span className="font-semibold">{totalNotifications}</span> total
                    {showArchived && ' archived'}
                  </p>
                )}
              </div>
            </div>

            <div className="flex items-center gap-3 flex-wrap">
              {/* Bulk Actions Toggle */}
              <button
                onClick={() => {
                  setBulkActionMode(!bulkActionMode);
                  setSelectedIds([]);
                }}
                className={`flex items-center gap-2 px-4 py-2 border rounded-lg transition-colors ${
                  bulkActionMode
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-800'
                }`}
              >
                <CheckSquare size={18} />
                {bulkActionMode ? 'Cancel Selection' : 'Select Multiple'}
              </button>

              {/* Archive Toggle */}
              <button
                onClick={() => {
                  setShowArchived(!showArchived);
                  handleFilterChange('archived', !showArchived);
                  setSelectedIds([]);
                }}
                className={`flex items-center gap-2 px-4 py-2 border rounded-lg transition-colors ${
                  showArchived
                    ? 'bg-gray-600 text-white border-gray-600'
                    : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-800'
                }`}
              >
                <Archive size={18} />
                {showArchived ? 'Show Active' : 'Show Archived'}
              </button>

              {/* Export Button */}
              <button
                onClick={() => setShowExportModal(true)}
                className="flex items-center gap-2 px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-800"
              >
                <Download size={18} />
                Export
              </button>

              {/* Settings Link */}
              <Link
                href={basePath.includes('shop-owner') ? '/shop-owner/notifications/settings' : basePath.includes('hr') ? '/erp/notifications/settings' : '/notifications/settings'}
                className="flex items-center gap-2 px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors dark:bg-gray-900 dark:text-gray-200 dark:border-gray-700 dark:hover:bg-gray-800"
              >
                <Settings size={18} />
                Settings
              </Link>

              {/* Mark All Read */}
              {!showArchived && totalNotifications > 0 && (
                <button
                  onClick={handleMarkAllAsRead}
                  disabled={markAllAsRead.isPending}
                  className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed transition-colors"
                >
                  <CheckCheck size={18} />
                  Mark All Read
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Bulk Action Bar */}
        {bulkActionMode && selectedIds.length > 0 && (
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 dark:bg-blue-900/20 dark:border-blue-800">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <CheckSquare size={20} className="text-blue-600" />
                <span className="font-medium text-blue-900 dark:text-blue-300">
                  {selectedIds.length} selected
                </span>
              </div>
              <div className="flex items-center gap-3">
                <button
                  onClick={handleBulkMarkAsRead}
                  className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm"
                >
                  <CheckCheck size={16} />
                  Mark as Read
                </button>
                {!showArchived && (
                  <button
                    onClick={handleBulkArchive}
                    className="flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm"
                  >
                    <Archive size={16} />
                    Archive
                  </button>
                )}
                <button
                  onClick={handleBulkDelete}
                  className="flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm"
                >
                  <Trash2 size={16} />
                  Delete
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Advanced Filters */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 overflow-hidden dark:bg-gray-900 dark:border-gray-700">
          <button
            onClick={() => setShowFilters(!showFilters)}
            className="w-full flex items-center justify-between p-4 hover:bg-gray-50 transition-colors dark:hover:bg-gray-800"
          >
            <div className="flex items-center gap-2">
              <Filter size={20} className="text-gray-600 dark:text-gray-300" />
              <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Advanced Filters</h2>
            </div>
            <ChevronDown
              size={20}
              className={`text-gray-600 dark:text-gray-300 transition-transform ${showFilters ? 'rotate-180' : ''}`}
            />
          </button>

          {showFilters && (
            <div className="p-4 border-t border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/70">
              {/* Search Bar */}
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">
                  Search
                </label>
                <div className="relative">
                  <Search size={18} className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                  <input
                    type="text"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="Search in title or message..."
                    className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-900 dark:border-gray-600 dark:text-white"
                  />
                  {searchQuery && (
                    <button
                      onClick={() => setSearchQuery('')}
                      title="Clear search"
                      aria-label="Clear search"
                      className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                    >
                      <X size={18} />
                    </button>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Category Filter */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">
                    Category
                  </label>
                  <select
                    value={filters.category || ''}
                    onChange={(e) => handleFilterChange('category', e.target.value || undefined)}
                    title="Filter by category"
                    aria-label="Filter by category"
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-900 dark:border-gray-600 dark:text-white"
                  >
                    {categories.map(cat => (
                      <option key={cat.value} value={cat.value}>{cat.label}</option>
                    ))}
                  </select>
                </div>

                {/* Priority Filter */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">
                    Priority Level
                  </label>
                  <select
                    value={filters.priority || ''}
                    onChange={(e) => handleFilterChange('priority', e.target.value || undefined)}
                    title="Filter by priority"
                    aria-label="Filter by priority"
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-900 dark:border-gray-600 dark:text-white"
                  >
                    {priorities.map(pri => (
                      <option key={pri.value} value={pri.value}>{pri.label}</option>
                    ))}
                  </select>
                </div>

                {/* Start Date */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">
                    From Date
                  </label>
                  <div className="relative">
                    <Calendar size={18} className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                    <input
                      type="date"
                      value={filters.start_date || ''}
                      onChange={(e) => handleFilterChange('start_date', e.target.value || undefined)}
                      title="Filter notifications from date"
                      aria-label="Filter notifications from date"
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-900 dark:border-gray-600 dark:text-white"
                    />
                  </div>
                </div>

                {/* End Date */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">
                    To Date
                  </label>
                  <div className="relative">
                    <Calendar size={18} className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                    <input
                      type="date"
                      value={filters.end_date || ''}
                      onChange={(e) => handleFilterChange('end_date', e.target.value || undefined)}
                      title="Filter notifications to date"
                      aria-label="Filter notifications to date"
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-900 dark:border-gray-600 dark:text-white"
                    />
                  </div>
                </div>
              </div>

              {/* Quick Filters */}
              <div className="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                <label className="flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={filters.unread_only}
                    onChange={(e) => handleFilterChange('unread_only', e.target.checked)}
                    title="Show unread notifications only"
                    aria-label="Show unread notifications only"
                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                  <span className="ml-2 text-sm text-gray-700 dark:text-gray-300">Unread only</span>
                </label>

                <label className="flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={filters.action_required}
                    onChange={(e) => handleFilterChange('action_required', e.target.checked)}
                    title="Show action required notifications only"
                    aria-label="Show action required notifications only"
                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                  <span className="ml-2 text-sm text-gray-700 dark:text-gray-300">Action required</span>
                </label>

                {/* Clear Filters Button */}
                {(filters.category || filters.priority || filters.start_date || filters.end_date || searchQuery || filters.unread_only || filters.action_required) && (
                  <button
                    onClick={() => {
                      setFilters({
                        unread_only: false,
                        action_required: false,
                        priority: undefined,
                        start_date: undefined,
                        end_date: undefined,
                        category: undefined,
                        archived: showArchived,
                      });
                      setSearchQuery('');
                    }}
                    className="ml-auto flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700 font-medium"
                  >
                    <X size={16} />
                    Clear all filters
                  </button>
                )}
              </div>
            </div>
          )}
        </div>

        {/* Notifications List */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden dark:bg-gray-900 dark:border-gray-700">
          {isLoading ? (
            <div className="flex items-center justify-center py-20">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            </div>
          ) : error ? (
            <div className="flex flex-col items-center justify-center py-20 text-red-600 dark:text-red-400">
              <AlertCircle size={48} className="mb-4" />
              <p className="text-lg font-medium">Failed to load notifications</p>
              <p className="text-sm text-gray-600 mt-2 dark:text-gray-400">Please try again later</p>
            </div>
          ) : notifications.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-20 text-gray-500 dark:text-gray-400">
              <Bell size={64} className="mb-4 text-gray-300 dark:text-gray-600" />
              <p className="text-lg font-medium dark:text-white">No notifications found</p>
              <p className="text-sm text-gray-600 mt-2 dark:text-gray-400">
                {filters.unread_only || filters.action_required || filters.category || filters.priority || searchQuery
                  ? 'Try adjusting your filters'
                  : showArchived
                  ? 'No archived notifications'
                  : 'You\'re all caught up!'}
              </p>
            </div>
          ) : (
            <>
              {/* Select All Checkbox */}
              {bulkActionMode && (
                <div className="px-6 py-3 bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                  <label className="flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={notifications.length > 0 && selectedIds.length === notifications.length}
                      onChange={handleSelectAll}
                      className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                    />
                    <span className="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                      Select all ({notifications.length})
                    </span>
                  </label>
                </div>
              )}

              <div className="divide-y divide-gray-100 dark:divide-gray-700">
                {isCustomerView ? (
                  customerCategorySections.map((section) => (
                    section.notifications.length > 0 ? (
                      <div key={section.key} className="border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                        <div className="px-6 pt-5 pb-2 bg-gray-50/80 dark:bg-gray-800/40">
                          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 tracking-wide uppercase">
                            {section.label}
                          </h3>
                        </div>
                        {section.notifications.map((notification: Notification) => renderNotificationCard(notification))}
                      </div>
                    ) : null
                  ))
                ) : (
                  notifications.map((notification: Notification) => renderNotificationCard(notification))
                )}
                {isCustomerView && !hasCustomerSectionItems && notifications.map((notification: Notification) => renderNotificationCard(notification))}
              </div>

              {/* Pagination */}
              {lastPage > 1 && (
                <div className="flex items-center justify-between px-6 py-4 border-t border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                  <div className="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span className="font-medium">{from}</span> to{' '}
                    <span className="font-medium">{to}</span> of{' '}
                    <span className="font-medium">{totalNotifications}</span> notifications
                  </div>

                  <div className="flex gap-2">
                    <button
                      onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                      disabled={currentPage === 1}
                      className="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      Previous
                    </button>
                    
                    <div className="flex gap-1">
                      {Array.from({ length: Math.min(5, lastPage) }, (_, i) => {
                        let pageNum;
                        if (lastPage <= 5) {
                          pageNum = i + 1;
                        } else if (currentPage <= 3) {
                          pageNum = i + 1;
                        } else if (currentPage >= lastPage - 2) {
                          pageNum = lastPage - 4 + i;
                        } else {
                          pageNum = currentPage - 2 + i;
                        }
                        
                        return (
                          <button
                            key={pageNum}
                            onClick={() => setCurrentPage(pageNum)}
                            className={`px-3 py-1 border rounded-lg transition-colors ${
                              currentPage === pageNum
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'border-gray-300 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700'
                            }`}
                          >
                            {pageNum}
                          </button>
                        );
                      })}
                    </div>

                    <button
                      onClick={() => setCurrentPage(prev => Math.min(lastPage, prev + 1))}
                      disabled={currentPage === lastPage}
                      className="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      Next
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
        </div>
      </div>

      {/* Export Modal */}
      <ExportModal isOpen={showExportModal} onClose={() => setShowExportModal(false)} />
    </>
  );
};

export default NotificationList;
