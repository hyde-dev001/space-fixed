import MonochromeSelect from "@/components/form/Select";
/**
 * ExportModal Component - Phase 7
 * Modal for exporting notifications with filter options
 */

import React, { useState } from 'react';
import { X, Download, Calendar, Filter } from 'lucide-react';

interface ExportModalProps {
  isOpen: boolean;
  onClose: () => void;
}

const ExportModal: React.FC<ExportModalProps> = ({ isOpen, onClose }) => {
  const [filters, setFilters] = useState({
    startDate: '',
    endDate: '',
    priority: '',
    type: '',
    status: 'all' // all, read, unread
  });
  const [format, setFormat] = useState<'json' | 'csv'>('json');
  const [isExporting, setIsExporting] = useState(false);

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const params = new URLSearchParams();
      if (filters.startDate) params.append('start_date', filters.startDate);
      if (filters.endDate) params.append('end_date', filters.endDate);
      if (filters.priority) params.append('priority', filters.priority);
      if (filters.type) params.append('type', filters.type);
      if (filters.status !== 'all') {
        params.append('unread_only', filters.status === 'unread' ? '1' : '0');
      }
      params.append('format', format);

      const response = await fetch(`/api/notifications/export?${params.toString()}`, {
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('auth_token')}`
        }
      });

      if (!response.ok) throw new Error('Export failed');

      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `notifications_${new Date().toISOString().split('T')[0]}.${format}`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);

      onClose();
    } catch (error) {
      console.error('Export error:', error);
      alert('Failed to export notifications');
    } finally {
      setIsExporting(false);
    }
  };

  const handleClearFilters = () => {
    setFilters({
      startDate: '',
      endDate: '',
      priority: '',
      type: '',
      status: 'all'
    });
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-screen items-center justify-center p-4">
        {/* Backdrop */}
        <div
          className="fixed inset-0 bg-slate-900/30 backdrop-blur-sm transition-all erp-modal-backdrop"
          onClick={onClose}
        />

        {/* Modal */}
        <div className="relative w-full max-w-2xl rounded-lg bg-white text-gray-900 shadow-xl dark:bg-gray-900 dark:text-white">
          {/* Header */}
          <div className="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
            <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-900 dark:text-white">
              <Download className="h-6 w-6 text-gray-700 dark:text-gray-300" />
              Export Notifications
            </h2>
            <button
              onClick={onClose}
              className="text-gray-400 transition-colors hover:text-gray-950 dark:hover:text-white"
            >
              <X className="h-6 w-6" />
            </button>
          </div>

          {/* Content */}
          <div className="space-y-6 p-6">
            {/* Format Selection */}
            <div>
              <label className="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-300">Export Format</label>
              <div className="flex gap-4">
                <button
                  onClick={() => setFormat('json')}
                  className={`flex-1 py-3 px-4 rounded-lg border-2 font-medium transition-all ${
                    format === 'json'
                      ? 'border-gray-950 bg-gray-950 text-white dark:border-gray-950 dark:bg-gray-950 dark:text-white'
                      : 'border-gray-300 text-gray-700 hover:border-gray-400 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-500'
                  }`}
                >
                  JSON
                </button>
                <button
                  onClick={() => setFormat('csv')}
                  className={`flex-1 py-3 px-4 rounded-lg border-2 font-medium transition-all ${
                    format === 'csv'
                      ? 'border-gray-950 bg-gray-950 text-white dark:border-gray-950 dark:bg-gray-950 dark:text-white'
                      : 'border-gray-300 text-gray-700 hover:border-gray-400 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-500'
                  }`}
                >
                  CSV
                </button>
              </div>
            </div>

            {/* Filters Section */}
            <div className="border-t border-gray-200 pt-6 dark:border-gray-700">
              <div className="flex items-center justify-between mb-4">
                <h3 className="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                  <Filter className="h-4 w-4" />
                  Filter Options
                </h3>
                <button
                  onClick={handleClearFilters}
                  className="text-sm text-gray-700 hover:text-gray-950 font-medium dark:text-gray-300 dark:hover:text-white"
                >
                  Clear All
                </button>
              </div>

              <div className="grid grid-cols-2 gap-4">
                {/* Date Range */}
                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    <Calendar className="mr-1 inline h-4 w-4" />
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={filters.startDate}
                    onChange={(e) => setFilters({ ...filters, startDate: e.target.value })}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-gray-950 focus:ring-2 focus:ring-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-300"
                  />
                </div>
                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    <Calendar className="mr-1 inline h-4 w-4" />
                    End Date
                  </label>
                  <input
                    type="date"
                    value={filters.endDate}
                    onChange={(e) => setFilters({ ...filters, endDate: e.target.value })}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-gray-950 focus:ring-2 focus:ring-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-300"
                  />
                </div>

                {/* Priority */}
                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Priority</label>
                  <MonochromeSelect
                    value={filters.priority}
                    onChange={(e) => setFilters({ ...filters, priority: e.target.value })}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-gray-950 focus:ring-2 focus:ring-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-300"
                  >
                    <option value="">All Priorities</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                  </MonochromeSelect>
                </div>

                {/* Type */}
                <div>
                  <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                  <MonochromeSelect
                    value={filters.type}
                    onChange={(e) => setFilters({ ...filters, type: e.target.value })}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-gray-950 focus:ring-2 focus:ring-gray-950 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-300"
                  >
                    <option value="">All Types</option>
                    <option value="order">Order</option>
                    <option value="payment">Payment</option>
                    <option value="shipping">Shipping</option>
                    <option value="promotion">Promotion</option>
                    <option value="system">System</option>
                    <option value="account">Account</option>
                  </MonochromeSelect>
                </div>

                {/* Status */}
                <div className="col-span-2">
                  <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                  <div className="flex gap-3">
                    {['all', 'read', 'unread'].map((status) => (
                      <button
                        key={status}
                        onClick={() => setFilters({ ...filters, status })}
                        className={`flex-1 py-2 px-4 rounded-lg border font-medium transition-all ${
                          filters.status === status
                            ? 'border-gray-950 bg-gray-950 text-white dark:border-gray-950 dark:bg-gray-950 dark:text-white'
                            : 'border-gray-300 text-gray-700 hover:border-gray-400 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-500'
                        }`}
                      >
                        {status.charAt(0).toUpperCase() + status.slice(1)}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            </div>

            {/* Summary */}
            <div className="rounded-lg border border-gray-300 bg-gray-100 p-4 dark:border-gray-700 dark:bg-gray-800">
              <p className="text-sm text-gray-800 dark:text-gray-200">
                <strong>Note:</strong> Export will include notifications matching the selected filters. 
                All data will be sanitized for privacy compliance.
              </p>
            </div>
          </div>

          {/* Footer */}
          <div className="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 p-6 dark:border-gray-700 dark:bg-gray-800">
            <button
              onClick={onClose}
              disabled={isExporting}
              className="px-4 py-2 font-medium text-gray-700 transition-colors hover:text-gray-950 disabled:opacity-50 dark:text-gray-300 dark:hover:text-white"
            >
              Cancel
            </button>
            <button
              onClick={handleExport}
              disabled={isExporting}
              className="px-6 py-2 bg-gray-950 text-white rounded-lg hover:bg-black dark:bg-gray-950 dark:hover:bg-black font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
            >
              {isExporting ? (
                <>
                  <div className="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent" />
                  Exporting...
                </>
              ) : (
                <>
                  <Download className="w-4 h-4" />
                  Export {format.toUpperCase()}
                </>
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ExportModal;
