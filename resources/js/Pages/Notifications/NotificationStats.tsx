/**
 * NotificationStats Page Component - Phase 7
 * Visual dashboard showing notification statistics and analytics
 */

import React from 'react';
import { Head } from '@inertiajs/react';
import { BarChart3, TrendingUp, CheckCircle, AlertCircle, Archive, Bell, Clock, Star } from 'lucide-react';
import { useNotificationStats } from '../../hooks/useNotifications';

const NotificationStats: React.FC = () => {
  const { data: stats, isLoading } = useNotificationStats();

  if (isLoading) {
    return (
      <>
        <Head title="Notification Statistics" />
        <div className="p-6">
          <div className="animate-pulse space-y-4">
            <div className="h-8 bg-gray-200 rounded w-1/4"></div>
            <div className="grid grid-cols-4 gap-4">
              {[1, 2, 3, 4].map(i => (
                <div key={i} className="h-32 bg-gray-200 rounded"></div>
              ))}
            </div>
          </div>
        </div>
      </>
    );
  }

  const priorityData = [
    { label: 'High', value: stats?.by_priority?.high || 0, color: 'bg-red-500', icon: '🔴' },
    { label: 'Medium', value: stats?.by_priority?.medium || 0, color: 'bg-blue-500', icon: '🔵' },
    { label: 'Low', value: stats?.by_priority?.low || 0, color: 'bg-gray-400', icon: '⚪' }
  ];

  const maxPriorityValue = Math.max(...priorityData.map(d => d.value), 1);

  return (
    <>
      <Head title="Notification Statistics" />
      <div className="p-6 max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-3">
            <BarChart3 className="w-8 h-8 text-blue-600" />
            Notification Statistics
          </h1>
          <p className="text-gray-600 mt-1">Overview of your notification activity and trends</p>
        </div>

        {/* Key Metrics Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          {/* Total Notifications */}
          <div className="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-600">Total Notifications</p>
                <p className="text-3xl font-bold text-gray-900 mt-2">{stats?.total || 0}</p>
              </div>
              <Bell className="w-12 h-12 text-blue-500 opacity-20" />
            </div>
          </div>

          {/* Unread Count */}
          <div className="bg-white rounded-lg shadow p-6 border-l-4 border-orange-500">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-600">Unread</p>
                <p className="text-3xl font-bold text-gray-900 mt-2">{stats?.unread || 0}</p>
              </div>
              <AlertCircle className="w-12 h-12 text-orange-500 opacity-20" />
            </div>
            <div className="mt-2 flex items-center text-sm text-gray-600">
              <span className="font-medium">
                {stats?.total ? ((stats.unread / stats.total) * 100).toFixed(1) : 0}%
              </span>
              <span className="ml-1">of total</span>
            </div>
          </div>

          {/* Read Count */}
          <div className="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-600">Read</p>
                <p className="text-3xl font-bold text-gray-900 mt-2">{stats?.read || 0}</p>
              </div>
              <CheckCircle className="w-12 h-12 text-green-500 opacity-20" />
            </div>
            <div className="mt-2 flex items-center text-sm text-gray-600">
              <span className="font-medium">
                {stats?.total ? ((stats.read / stats.total) * 100).toFixed(1) : 0}%
              </span>
              <span className="ml-1">of total</span>
            </div>
          </div>

          {/* Archived Count */}
          <div className="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-600">Archived</p>
                <p className="text-3xl font-bold text-gray-900 mt-2">{stats?.archived || 0}</p>
              </div>
              <Archive className="w-12 h-12 text-purple-500 opacity-20" />
            </div>
          </div>
        </div>

        {/* Charts Row */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
          {/* Priority Distribution */}
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
              <Star className="w-5 h-5 text-blue-600" />
              Priority Distribution
            </h2>
            <div className="space-y-4">
              {priorityData.map((item) => (
                <div key={item.label}>
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-medium text-gray-700 flex items-center gap-2">
                      <span>{item.icon}</span>
                      {item.label}
                    </span>
                    <span className="text-sm font-semibold text-gray-900">{item.value}</span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-3">
                    <div
                      className={`${item.color} h-3 rounded-full transition-all duration-500`}
                      style={{ width: `${(item.value / maxPriorityValue) * 100}%` }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Type Breakdown */}
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
              <TrendingUp className="w-5 h-5 text-blue-600" />
              Notification Types
            </h2>
            <div className="space-y-3">
              {stats?.by_type && Object.entries(stats.by_type).map(([type, count]) => (
                <div key={type} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                  <span className="text-sm font-medium text-gray-700 capitalize">{type.replace('_', ' ')}</span>
                  <span className="px-3 py-1 bg-blue-100 text-blue-800 text-sm font-semibold rounded-full">
                    {count as number}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Additional Insights */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Action Required */}
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
              <AlertCircle className="w-5 h-5 text-orange-600" />
              Action Required
            </h2>
            <div className="text-center py-8">
              <p className="text-5xl font-bold text-orange-600">{stats?.action_required || 0}</p>
              <p className="text-gray-600 mt-2">notifications need your attention</p>
            </div>
          </div>

          {/* Recent Activity */}
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
              <Clock className="w-5 h-5 text-blue-600" />
              Recent Activity
            </h2>
            <div className="space-y-3">
              <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                <span className="text-sm text-gray-700">Read Today</span>
                <span className="text-sm font-semibold text-green-700">{stats?.read_today || 0}</span>
              </div>
              <div className="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                <span className="text-sm text-gray-700">Received Today</span>
                <span className="text-sm font-semibold text-blue-700">{stats?.received_today || 0}</span>
              </div>
              <div className="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                <span className="text-sm text-gray-700">Grouped Notifications</span>
                <span className="text-sm font-semibold text-purple-700">{stats?.grouped || 0}</span>
              </div>
            </div>
          </div>
        </div>

        {/* Read Rate Gauge */}
        <div className="mt-6 bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Overall Read Rate</h2>
          <div className="flex items-center gap-8">
            <div className="flex-1">
              <div className="relative pt-1">
                <div className="flex mb-2 items-center justify-between">
                  <div>
                    <span className="text-xs font-semibold inline-block text-blue-600">
                      {stats?.total ? ((stats.read / stats.total) * 100).toFixed(1) : 0}% Read
                    </span>
                  </div>
                </div>
                <div className="overflow-hidden h-4 text-xs flex rounded-full bg-gray-200">
                  <div
                    style={{ width: `${stats?.total ? ((stats.read / stats.total) * 100) : 0}%` }}
                    className="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-gradient-to-r from-blue-500 to-blue-600 transition-all duration-500"
                  />
                </div>
              </div>
            </div>
            <div className="text-right">
              <p className="text-3xl font-bold text-blue-600">
                {stats?.total ? ((stats.read / stats.total) * 100).toFixed(0) : 0}%
              </p>
              <p className="text-sm text-gray-600">Engagement</p>
            </div>
          </div>
        </div>
      </div>
    </>
  );
};

export default NotificationStats;
