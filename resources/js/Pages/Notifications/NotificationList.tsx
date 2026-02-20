/**
 * NotificationList Page Component - Phase 7 Enhanced
 * Full notification management with advanced filters, bulk actions, and Phase 6 features
 */

import React, { useState } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import { 
  Bell, Filter, Trash2, CheckCheck, AlertCircle, Settings, 
  Search, Calendar, Archive, Download, X, ChevronDown, CheckSquare, Square 
} from 'lucide-react';
import { useNotifications, useMarkAsRead, useMarkAllAsRead, useDeleteNotification, type NotificationFilters } from '../../hooks/useNotifications';
import NotificationItem from '../../Components/common/NotificationItem';
import ExportModal from '../../Components/Notifications/ExportModal';

interface NotificationListProps {
  basePath?: string;
  title?: string;
}

const NotificationList: React.FC<NotificationListProps> = ({ 
  basePath = '/api/notifications',
  title = 'Notifications'
}) => {
  const { auth } = usePage().props as any;
  const [currentPage, setCurrentPage] = useState(1);
  const [showFilters, setShowFilters] = useState(true);
  const [showArchived, setShowArchived] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkActionMode, setBulkActionMode] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [showExportModal, setShowExportModal] = useState(false);
  const [filters, setFilters] = useState<NotificationFilters>({
    unread_only: false,
    action_required: false,
    priority: undefined,
    start_date: undefined,
    end_date: undefined,
    archived: false,
  });

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
  const deleteNotification = useDeleteNotification(basePath, 'delete-notification');

  const handleFilterChange = (key: keyof NotificationFilters, value: any) => {
    setFilters(prev => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const handleMarkAsRead = (id: number) => {
    markAsRead.mutate(id);
  };

  const handleMarkAllAsRead = () => {
    if (confirm('Mark all notifications as read?')) {
      markAllAsRead.mutate();
    }
  };

  const handleDelete = (id: number) => {
    if (confirm('Delete this notification? This action cannot be undone.')) {
      deleteNotification.mutate(id);
    }
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
    if (data && data.data) {
      const allIds = data.data.map(n => n.id);
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

  const categories = [
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

  return (
    <>
      <Head title={title} />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Header */}
        <div className="mb-8">
          <div className="flex items-center justify-between flex-wrap gap-4">
            <div className="flex items-center gap-3">
              <Bell size={32} className="text-gray-700" />
              <div>
                <h1 className="text-3xl font-bold text-gray-900">{title}</h1>
                {data && (
                  <p className="text-gray-600 mt-1">
                    {data.unread_count > 0 ? (
                      <>
                        <span className="font-semibold text-blue-600">{data.unread_count}</span> unread
                        {' • '}
                      </>
                    ) : null}
                    <span className="font-semibold">{data.total}</span> total
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
                    : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50'
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
                    : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50'
                }`}
              >
                <Archive size={18} />
                {showArchived ? 'Show Active' : 'Show Archived'}
              </button>

              {/* Export Button */}
              <button
                onClick={() => setShowExportModal(true)}
                className="flex items-center gap-2 px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
              >
                <Download size={18} />
                Export
              </button>

              {/* Settings Link */}
              <Link
                href={basePath.includes('shop-owner') ? '/shop-owner/notifications/settings' : basePath.includes('hr') ? '/erp/notifications/settings' : '/notifications/settings'}
                className="flex items-center gap-2 px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
              >
                <Settings size={18} />
                Settings
              </Link>

              {/* Mark All Read */}
              {!showArchived && data && data.total > 0 && (
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
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <CheckSquare size={20} className="text-blue-600" />
                <span className="font-medium text-blue-900">
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
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 overflow-hidden">
          <button
            onClick={() => setShowFilters(!showFilters)}
            className="w-full flex items-center justify-between p-4 hover:bg-gray-50 transition-colors"
          >
            <div className="flex items-center gap-2">
              <Filter size={20} className="text-gray-600" />
              <h2 className="text-lg font-semibold text-gray-900">Advanced Filters</h2>
            </div>
            <ChevronDown
              size={20}
              className={`text-gray-600 transition-transform ${showFilters ? 'rotate-180' : ''}`}
            />
          </button>

          {showFilters && (
            <div className="p-4 border-t border-gray-200 bg-gray-50">
              {/* Search Bar */}
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Search
                </label>
                <div className="relative">
                  <Search size={18} className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" />
                  <input
                    type="text"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="Search in title or message..."
                    className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                  {searchQuery && (
                    <button
                      onClick={() => setSearchQuery('')}
                      className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    >
                      <X size={18} />
                    </button>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Category Filter */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Category
                  </label>
                  <select
                    value={filters.category || ''}
                    onChange={(e) => handleFilterChange('category', e.target.value || undefined)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    {categories.map(cat => (
                      <option key={cat.value} value={cat.value}>{cat.label}</option>
                    ))}
                  </select>
                </div>

                {/* Priority Filter */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Priority Level
                  </label>
                  <select
                    value={filters.priority || ''}
                    onChange={(e) => handleFilterChange('priority', e.target.value || undefined)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    {priorities.map(pri => (
                      <option key={pri.value} value={pri.value}>{pri.label}</option>
                    ))}
                  </select>
                </div>

                {/* Start Date */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    From Date
                  </label>
                  <div className="relative">
                    <Calendar size={18} className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" />
                    <input
                      type="date"
                      value={filters.start_date || ''}
                      onChange={(e) => handleFilterChange('start_date', e.target.value || undefined)}
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    />
                  </div>
                </div>

                {/* End Date */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    To Date
                  </label>
                  <div className="relative">
                    <Calendar size={18} className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" />
                    <input
                      type="date"
                      value={filters.end_date || ''}
                      onChange={(e) => handleFilterChange('end_date', e.target.value || undefined)}
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    />
                  </div>
                </div>
              </div>

              {/* Quick Filters */}
              <div className="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-200">
                <label className="flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={filters.unread_only}
                    onChange={(e) => handleFilterChange('unread_only', e.target.checked)}
                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                  <span className="ml-2 text-sm text-gray-700">Unread only</span>
                </label>

                <label className="flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={filters.action_required}
                    onChange={(e) => handleFilterChange('action_required', e.target.checked)}
                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                  <span className="ml-2 text-sm text-gray-700">Action required</span>
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
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-20">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            </div>
          ) : error ? (
            <div className="flex flex-col items-center justify-center py-20 text-red-600">
              <AlertCircle size={48} className="mb-4" />
              <p className="text-lg font-medium">Failed to load notifications</p>
              <p className="text-sm text-gray-600 mt-2">Please try again later</p>
            </div>
          ) : !data || data.data.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-20 text-gray-500">
              <Bell size={64} className="mb-4 text-gray-300" />
              <p className="text-lg font-medium">No notifications found</p>
              <p className="text-sm text-gray-600 mt-2">
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
                <div className="px-6 py-3 bg-gray-50 border-b border-gray-200">
                  <label className="flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={data.data.length > 0 && selectedIds.length === data.data.length}
                      onChange={handleSelectAll}
                      className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                    />
                    <span className="ml-2 text-sm font-medium text-gray-700">
                      Select all ({data.data.length})
                    </span>
                  </label>
                </div>
              )}

              <div className="divide-y divide-gray-100">
                {data.data.map((notification) => (
                  <div key={notification.id} className="relative group hover:bg-gray-50 transition-colors">
                    <div className="flex items-start gap-3 px-6 py-4">
                      {/* Bulk Selection Checkbox */}
                      {bulkActionMode && (
                        <div className="flex items-center pt-1">
                          <input
                            type="checkbox"
                            checked={selectedIds.includes(notification.id)}
                            onChange={() => handleToggleSelect(notification.id)}
                            className="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                      )}

                      {/* Notification Content */}
                      <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-4">
                          <div className="flex-1">
                            {/* Priority Badge + Title */}
                            <div className="flex items-center gap-2 mb-1">
                              {notification.priority && getPriorityBadge(notification.priority)}
                              <h3 className={`text-base font-semibold ${notification.is_read ? 'text-gray-600' : 'text-gray-900'}`}>
                                {notification.title}
                              </h3>
                            </div>

                            {/* Message */}
                            <p className={`text-sm ${notification.is_read ? 'text-gray-500' : 'text-gray-700'} mb-2`}>
                              {notification.message}
                            </p>

                            {/* Meta Info */}
                            <div className="flex items-center gap-3 text-xs text-gray-500">
                              <span>{new Date(notification.created_at).toLocaleString()}</span>
                              {notification.requires_action && (
                                <span className="inline-flex items-center px-2 py-0.5 rounded bg-orange-100 text-orange-800 font-medium">
                                  Action Required
                                </span>
                              )}
                              {notification.group_key && (
                                <span className="inline-flex items-center px-2 py-0.5 rounded bg-purple-100 text-purple-800 font-medium">
                                  Grouped
                                </span>
                              )}
                            </div>
                          </div>

                          {/* Actions */}
                          {!bulkActionMode && (
                            <div className="flex items-center gap-2">
                              {!notification.is_read && (
                                <button
                                  onClick={() => handleMarkAsRead(notification.id)}
                                  className="p-2 text-blue-600 hover:bg-blue-50 rounded transition-colors"
                                  title="Mark as read"
                                >
                                  <CheckCheck size={16} />
                                </button>
                              )}
                              <button
                                onClick={() => handleDelete(notification.id)}
                                className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors opacity-0 group-hover:opacity-100"
                                title="Delete notification"
                              >
                                <Trash2 size={16} />
                              </button>
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              {/* Pagination */}
              {data.last_page > 1 && (
                <div className="flex items-center justify-between px-6 py-4 border-t border-gray-200 bg-gray-50">
                  <div className="text-sm text-gray-600">
                    Showing <span className="font-medium">{data.from}</span> to{' '}
                    <span className="font-medium">{data.to}</span> of{' '}
                    <span className="font-medium">{data.total}</span> notifications
                  </div>

                  <div className="flex gap-2">
                    <button
                      onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                      disabled={currentPage === 1}
                      className="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      Previous
                    </button>
                    
                    <div className="flex gap-1">
                      {Array.from({ length: Math.min(5, data.last_page) }, (_, i) => {
                        let pageNum;
                        if (data.last_page <= 5) {
                          pageNum = i + 1;
                        } else if (currentPage <= 3) {
                          pageNum = i + 1;
                        } else if (currentPage >= data.last_page - 2) {
                          pageNum = data.last_page - 4 + i;
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
                                : 'border-gray-300 hover:bg-gray-100'
                            }`}
                          >
                            {pageNum}
                          </button>
                        );
                      })}
                    </div>

                    <button
                      onClick={() => setCurrentPage(prev => Math.min(data.last_page, prev + 1))}
                      disabled={currentPage === data.last_page}
                      className="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
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

      {/* Export Modal */}
      <ExportModal isOpen={showExportModal} onClose={() => setShowExportModal(false)} />
    </>
  );
};

export default NotificationList;
