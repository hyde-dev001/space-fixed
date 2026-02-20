/**
 * NotificationArchive Page Component - Phase 7
 * Manage archived notifications with restore and permanent delete
 */

import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Archive, RotateCcw, Trash2, Search, Calendar, AlertCircle } from 'lucide-react';
import { useNotifications, useMarkAsRead, useDeleteNotification } from '../../hooks/useNotifications';
import axios from 'axios';

const NotificationArchive: React.FC = () => {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedType, setSelectedType] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, refetch } = useNotifications(
    page,
    10,
    undefined, // category
    undefined, // actionRequired
    undefined, // priority
    true, // archived = true
    startDate,
    endDate,
    searchQuery
  );

  const deleteNotification = useDeleteNotification();

  const handleRestore = async (id: number) => {
    try {
      await axios.post(`/api/notifications/${id}/archive`, {
        archived: false
      });
      refetch();
    } catch (error) {
      console.error('Failed to restore notification:', error);
      alert('Failed to restore notification');
    }
  };

  const handlePermanentDelete = async (id: number) => {
    if (!confirm('Are you sure? This action cannot be undone.')) return;

    try {
      await deleteNotification.mutateAsync(id);
      refetch();
    } catch (error) {
      console.error('Failed to delete notification:', error);
      alert('Failed to delete notification');
    }
  };

  const handleBulkRestore = async () => {
    if (!confirm(`Restore all ${data?.data?.length || 0} archived notifications?`)) return;

    try {
      const ids = data?.data?.map((n: any) => n.id) || [];
      await axios.post('/api/notifications/bulk/archive', {
        notification_ids: ids,
        archived: false
      });
      refetch();
    } catch (error) {
      console.error('Failed to bulk restore:', error);
      alert('Failed to restore notifications');
    }
  };

  const handleClearFilters = () => {
    setSearchQuery('');
    setSelectedType('');
    setStartDate('');
    setEndDate('');
    setPage(1);
  };

  return (
    <>
      <Head title="Archived Notifications" />
      <div className="p-6 max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-3">
            <Archive className="w-8 h-8 text-purple-600" />
            Archived Notifications
          </h1>
          <p className="text-gray-600 mt-1">View and manage your archived notifications</p>
        </div>

        {/* Filters & Actions */}
        <div className="bg-white rounded-lg shadow mb-6 p-4">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            {/* Search */}
            <div className="relative">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input
                type="text"
                placeholder="Search archived..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
              />
            </div>

            {/* Type Filter */}
            <select
              value={selectedType}
              onChange={(e) => setSelectedType(e.target.value)}
              className="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
            >
              <option value="">All Types</option>
              <option value="order">Order</option>
              <option value="payment">Payment</option>
              <option value="shipping">Shipping</option>
              <option value="promotion">Promotion</option>
              <option value="system">System</option>
            </select>

            {/* Start Date */}
            <div className="relative">
              <Calendar className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
              />
            </div>

            {/* End Date */}
            <div className="relative">
              <Calendar className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
              />
            </div>
          </div>

          {/* Action Buttons */}
          <div className="flex items-center justify-between">
            <button
              onClick={handleClearFilters}
              className="text-sm text-purple-600 hover:text-purple-700 font-medium"
            >
              Clear Filters
            </button>
            <button
              onClick={handleBulkRestore}
              disabled={!data?.data?.length}
              className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            >
              <RotateCcw className="w-4 h-4" />
              Restore All
            </button>
          </div>
        </div>

        {/* Archive List */}
        <div className="bg-white rounded-lg shadow">
          {isLoading ? (
            <div className="p-12 text-center">
              <div className="animate-spin rounded-full h-12 w-12 border-4 border-purple-200 border-t-purple-600 mx-auto"></div>
              <p className="text-gray-600 mt-4">Loading archived notifications...</p>
            </div>
          ) : !data?.data?.length ? (
            <div className="p-12 text-center">
              <Archive className="w-16 h-16 text-gray-300 mx-auto mb-4" />
              <h3 className="text-lg font-medium text-gray-900 mb-2">No Archived Notifications</h3>
              <p className="text-gray-600">You haven't archived any notifications yet.</p>
            </div>
          ) : (
            <div className="divide-y divide-gray-200">
              {data.data.map((notification: any) => (
                <div
                  key={notification.id}
                  className="p-4 hover:bg-gray-50 transition-colors"
                >
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex-1">
                      <div className="flex items-center gap-2 mb-1">
                        <h3 className="font-medium text-gray-900">{notification.title}</h3>
                        <span className="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded-full">
                          {notification.type}
                        </span>
                        {notification.priority && (
                          <span className={`text-xs font-medium ${
                            notification.priority === 'high' ? 'text-red-600' :
                            notification.priority === 'medium' ? 'text-blue-600' :
                            'text-gray-600'
                          }`}>
                            {notification.priority === 'high' ? '🔴' :
                             notification.priority === 'medium' ? '🔵' : '⚪'}
                          </span>
                        )}
                      </div>
                      <p className="text-sm text-gray-600 mb-2">{notification.message}</p>
                      <div className="flex items-center gap-4 text-xs text-gray-500">
                        <span>Archived: {new Date(notification.archived_at).toLocaleDateString()}</span>
                        <span>Created: {new Date(notification.created_at).toLocaleDateString()}</span>
                      </div>
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleRestore(notification.id)}
                        className="p-2 text-purple-600 hover:bg-purple-50 rounded-lg transition-colors"
                        title="Restore"
                      >
                        <RotateCcw className="w-4 h-4" />
                      </button>
                      <button
                        onClick={() => handlePermanentDelete(notification.id)}
                        className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                        title="Delete Permanently"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Pagination */}
          {data?.data?.length > 0 && (
            <div className="p-4 border-t border-gray-200 flex items-center justify-between">
              <p className="text-sm text-gray-600">
                Showing {((page - 1) * 10) + 1} to {Math.min(page * 10, data.total || 0)} of {data.total || 0} archived
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage(p => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Previous
                </button>
                <button
                  onClick={() => setPage(p => p + 1)}
                  disabled={!data.next_page_url}
                  className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Warning Box */}
        <div className="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4 flex items-start gap-3">
          <AlertCircle className="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" />
          <div>
            <p className="text-sm text-yellow-800">
              <strong>Note:</strong> Permanently deleted notifications cannot be recovered. 
              Restored notifications will appear in your main notification list.
            </p>
          </div>
        </div>
      </div>
    </>
  );
};

export default NotificationArchive;
