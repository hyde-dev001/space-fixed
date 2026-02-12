import React, { useState } from 'react';
import { Head } from "@inertiajs/react";
import AppLayout from "../../layout/AppLayout";
import Swal from 'sweetalert2';
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "../../components/ui/table";
import Badge from "../../components/ui/badge/Badge";

// Icon Components
const PaperPlaneIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
  </svg>
);

const ChatIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
  </svg>
);

const EyeIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const PlusIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
  </svg>
);

const TrashBinIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

const EnvelopeIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
  </svg>
);

const UserIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
  </svg>
);

const AlertIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const MoreDotIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 12h.01M12 12h.01M19 12h.01" />
  </svg>
);

// Types for better TypeScript support
interface Announcement {
  id: string;
  title: string;
  message: string;
  target: 'all' | 'shop-owners' | 'users';
  status: 'draft' | 'sent' | 'scheduled';
  createdAt: string;
  sentAt?: string;
  scheduledFor?: string;
}

interface Alert {
  id: string;
  type: 'maintenance' | 'update' | 'warning' | 'info';
  title: string;
  message: string;
  active: boolean;
  createdAt: string;
  expiresAt?: string;
}

interface SupportTicket {
  id: string;
  userId: string;
  userName: string;
  subject: string;
  message: string;
  status: 'open' | 'in-progress' | 'resolved' | 'closed';
  priority: 'low' | 'medium' | 'high' | 'urgent';
  createdAt: string;
  updatedAt: string;
}

// Professional Metric Card Component
const MetricCard: React.FC<{
  title: string;
  value: string | number;
  icon: React.ComponentType<{ className?: string }>;
  color: 'success' | 'warning' | 'error' | 'info';
  description: string;
}> = ({ title, value, icon: Icon, color, description }) => {
  const getColorClasses = () => {
    switch (color) {
      case 'success': return 'from-green-500 to-emerald-600';
      case 'error': return 'from-red-500 to-rose-600';
      case 'warning': return 'from-yellow-500 to-orange-600';
      case 'info': return 'from-blue-500 to-indigo-600';
      default: return 'from-gray-500 to-gray-600';
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
            {title}
          </p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {typeof value === 'number' ? value.toLocaleString() : value}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {description}
          </p>
        </div>
      </div>
    </div>
  );
};

// Announcement Form Component
const AnnouncementForm: React.FC<{
  onSubmit: (announcement: Omit<Announcement, 'id' | 'createdAt'>) => void;
  onCancel: () => void;
}> = ({ onSubmit, onCancel }) => {
  const [formData, setFormData] = useState({
    title: '',
    message: '',
    target: 'all' as Announcement['target'],
    status: 'draft' as const,
    scheduledFor: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    Swal.fire({
      title: formData.scheduledFor ? 'Schedule Announcement?' : 'Send Announcement?',
      text: `Are you sure you want to ${formData.scheduledFor ? 'schedule' : 'send'} this announcement?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#2563EB',
      cancelButtonColor: '#6B7280',
      confirmButtonText: `Yes, ${formData.scheduledFor ? 'schedule' : 'send'} it!`,
      cancelButtonText: 'Cancel'
    }).then((result) => {
      if (result.isConfirmed) {
        onSubmit(formData);
      }
    });
  };

  return (
    <div className="fixed inset-0 h-full w-full bg-black/10 backdrop-blur-sm flex items-center justify-center z-50">
      <div className="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4">
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-10 h-10 bg-blue-100 rounded-xl dark:bg-blue-900/30">
              <PaperPlaneIcon className="text-blue-600 size-5 dark:text-blue-400" />
            </div>
            <div>
              <h3 className="text-lg font-bold text-gray-900 dark:text-white">Create New Announcement</h3>
              <p className="text-sm text-gray-600 dark:text-gray-400">Send announcements to users or specific groups</p>
            </div>
          </div>
          <button onClick={onCancel} className="text-gray-400 hover:text-gray-600 text-xl" title="Close" aria-label="Close">×</button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Title
            </label>
            <input
              type="text"
              value={formData.title}
              onChange={(e) => setFormData({ ...formData, title: e.target.value })}
              className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              placeholder="Enter announcement title"
              title="Title"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Message
            </label>
            <textarea
              value={formData.message}
              onChange={(e) => setFormData({ ...formData, message: e.target.value })}
              rows={4}
              className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              placeholder="Enter announcement message"
              title="Message"
              required
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Target Audience
              </label>
              <select
                value={formData.target}
                onChange={(e) => setFormData({ ...formData, target: e.target.value as Announcement['target'] })}
                className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                title="Target Audience"
              >
                <option value="all">All Users</option>
                <option value="shop-owners">Shop Owners Only</option>
                <option value="users">Regular Users Only</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Schedule For (Optional)
              </label>
              <input
                type="datetime-local"
                value={formData.scheduledFor}
                onChange={(e) => setFormData({ ...formData, scheduledFor: e.target.value })}
                className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                title="Schedule For"
                placeholder="Select date and time"
              />
            </div>
          </div>

          <div className="flex gap-4">
            <button
              type="submit"
              className="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-colors duration-200 flex items-center justify-center gap-2"
            >
              <PaperPlaneIcon className="size-4" />
              {formData.scheduledFor ? 'Schedule Announcement' : 'Send Announcement'}
            </button>
            <button
              type="button"
              onClick={onCancel}
              className="px-6 py-3 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors duration-200 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

// New Alert Modal Component
const NewAlertModal: React.FC<{
  onSubmit: (alert: Omit<Alert, 'id' | 'createdAt'>) => void;
  onCancel: () => void;
}> = ({ onSubmit, onCancel }) => {
  const [formData, setFormData] = useState({
    type: 'info' as Alert['type'],
    title: '',
    message: '',
    active: true,
    expiresAt: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    Swal.fire({
      title: 'Create Alert?',
      text: 'Are you sure you want to create this system alert?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#F97316',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, create it!',
      cancelButtonText: 'Cancel'
    }).then((result) => {
      if (result.isConfirmed) {
        onSubmit(formData);
      }
    });
  };

  return (
    <div className="fixed inset-0 h-full w-full bg-black/10 backdrop-blur-sm flex items-center justify-center z-50">
      <div className="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-10 h-10 bg-orange-100 rounded-xl dark:bg-orange-900/30">
              <AlertIcon className="text-orange-600 size-5 dark:text-orange-400" />
            </div>
            <div>
              <h3 className="text-lg font-bold text-gray-900 dark:text-white">Create New Alert</h3>
              <p className="text-sm text-gray-600 dark:text-gray-400">Add a system-wide alert or notification</p>
            </div>
          </div>
          <button onClick={onCancel} className="text-gray-400 hover:text-gray-600 text-xl" title="Close" aria-label="Close">×</button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Alert Type
            </label>
            <select
              value={formData.type}
              onChange={(e) => setFormData({ ...formData, type: e.target.value as Alert['type'] })}
              className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              title="Alert Type"
            >
              <option value="info">Info</option>
              <option value="warning">Warning</option>
              <option value="update">Update</option>
              <option value="maintenance">Maintenance</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Title
            </label>
            <input
              type="text"
              value={formData.title}
              onChange={(e) => setFormData({ ...formData, title: e.target.value })}
              className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              placeholder="Enter alert title"
              title="Title"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Message
            </label>
            <textarea
              value={formData.message}
              onChange={(e) => setFormData({ ...formData, message: e.target.value })}
              rows={3}
              className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              placeholder="Enter alert message"
              title="Message"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Expires At (Optional)
            </label>
            <input
              type="datetime-local"
              value={formData.expiresAt}
              onChange={(e) => setFormData({ ...formData, expiresAt: e.target.value })}
              className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              title="Expires At"
              placeholder="Select expiration date and time"
            />
          </div>

          <div className="flex items-center gap-3">
            <input
              type="checkbox"
              id="active"
              checked={formData.active}
              onChange={(e) => setFormData({ ...formData, active: e.target.checked })}
              className="w-4 h-4 text-orange-600 bg-gray-100 border-gray-300 rounded focus:ring-orange-500 dark:focus:ring-orange-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
              title="Active"
            />
            <label htmlFor="active" className="text-sm font-medium text-gray-700 dark:text-gray-300">
              Active immediately
            </label>
          </div>

          <div className="flex gap-4">
            <button
              type="submit"
              className="flex-1 bg-orange-600 text-white px-6 py-3 rounded-lg hover:bg-orange-700 transition-colors duration-200 flex items-center justify-center gap-2"
            >
              <PlusIcon className="size-4" />
              Create Alert
            </button>
            <button
              type="button"
              onClick={onCancel}
              className="px-6 py-3 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors duration-200 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

// Alert Management Component
const AlertManagement: React.FC = () => {
  const [alerts, setAlerts] = useState<Alert[]>([]);

  const [showNewAlertModal, setShowNewAlertModal] = useState(false);

  const toggleAlert = (id: string) => {
    setAlerts(alerts.map(alert =>
      alert.id === id ? { ...alert, active: !alert.active } : alert
    ));
  };

  const deleteAlert = (id: string) => {
    setAlerts(alerts.filter(alert => alert.id !== id));
  };

  const addAlert = (newAlert: Omit<Alert, 'id' | 'createdAt'>) => {
    const alert: Alert = {
      ...newAlert,
      id: Date.now().toString(),
      createdAt: new Date().toISOString(),
    };
    setAlerts(prev => [...prev, alert]);
    setShowNewAlertModal(false);
  };

  const getAlertColor = (type: Alert['type']) => {
    switch (type) {
      case 'maintenance': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
      case 'update': return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
      case 'warning': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
      case 'info': return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
  };

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
      <div className="flex items-center justify-between mb-6">
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-10 h-10 bg-orange-100 rounded-xl dark:bg-orange-900/30">
              <AlertIcon className="text-orange-600 size-5 dark:text-orange-400" />
            </div>
          <div>
            <h3 className="text-lg font-bold text-gray-900 dark:text-white">System Alerts</h3>
            <p className="text-sm text-gray-600 dark:text-gray-400">Manage system-wide alerts and notifications</p>
          </div>
        </div>
        <button
          onClick={() => setShowNewAlertModal(true)}
          className="flex items-center gap-2 px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors duration-200"
          title="New Alert"
          aria-label="New Alert"
        >
          <PlusIcon className="size-4" />
          New Alert
        </button>
      </div>

      <div className="max-h-96 overflow-y-auto">
        <div className="space-y-4">
          {alerts.map((alert) => (
            <div key={alert.id} className="flex items-center justify-between p-4 border border-gray-200 rounded-lg dark:border-gray-700">
              <div className="flex items-center gap-4">
                <div className={`px-3 py-1 rounded-full text-xs font-semibold ${getAlertColor(alert.type)}`}>
                  {alert.type.charAt(0).toUpperCase() + alert.type.slice(1)}
                </div>
                <div>
                  <h4 className="font-semibold text-gray-900 dark:text-white">{alert.title}</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-400">{alert.message}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-500 mt-1">
                    Created: {new Date(alert.createdAt).toLocaleDateString()}
                    {alert.expiresAt && ` • Expires: ${new Date(alert.expiresAt).toLocaleDateString()}`}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => toggleAlert(alert.id)}
                  className={`px-3 py-1 rounded-full text-xs font-semibold transition-colors duration-200 ${
                    alert.active
                      ? 'bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400'
                      : 'bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-900/30 dark:text-gray-400'
                  }`}
                  title={`Toggle alert ${alert.active ? 'inactive' : 'active'}`}
                  aria-label={`Toggle alert ${alert.active ? 'inactive' : 'active'}`}
                >
                  {alert.active ? 'Active' : 'Inactive'}
                </button>
                <button
                  onClick={() => {
                    Swal.fire({
                      title: 'Delete Alert?',
                      text: 'Are you sure you want to delete this alert? This action cannot be undone.',
                      icon: 'warning',
                      showCancelButton: true,
                      confirmButtonColor: '#EF4444',
                      cancelButtonColor: '#6B7280',
                      confirmButtonText: 'Yes, delete it!',
                      cancelButtonText: 'Cancel'
                    }).then((result) => {
                      if (result.isConfirmed) {
                        deleteAlert(alert.id);
                      }
                    });
                  }}
                  className="p-2 text-gray-400 hover:text-red-600 transition-colors duration-200"
                  title="Delete alert"
                  aria-label="Delete alert"
                >
                  <TrashBinIcon className="size-4" />
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>

      {showNewAlertModal && (
        <NewAlertModal
          onSubmit={addAlert}
          onCancel={() => setShowNewAlertModal(false)}
        />
      )}
    </div>
  );
};

// Support Tickets Component
const SupportTickets: React.FC = () => {
  const [tickets] = useState<SupportTicket[]>([]);

  const [selectedTicket, setSelectedTicket] = useState<SupportTicket | null>(null);

  // Ticket Details Modal Component
  const TicketDetailsModal: React.FC<{ ticket: SupportTicket; onClose: () => void }> = ({ ticket, onClose }) => {
    return (
      <div className="fixed inset-0 h-full w-full bg-black/10 backdrop-blur-sm flex items-center justify-center z-50">
        <div className="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-bold text-gray-900 dark:text-white">Ticket Details</h3>
            <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl" title="Close" aria-label="Close">×</button>
          </div>
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">ID</label>
              <p className="text-sm text-gray-900 dark:text-white">{ticket.id}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">User</label>
              <p className="text-sm text-gray-900 dark:text-white">{ticket.userName} ({ticket.userId})</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
              <p className="text-sm text-gray-900 dark:text-white">{ticket.subject}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Message</label>
              <p className="text-sm text-gray-900 dark:text-white">{ticket.message}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
              <p className="text-sm text-gray-900 dark:text-white">{ticket.status.replace('-', ' ')}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Priority</label>
              <p className="text-sm text-gray-900 dark:text-white">{ticket.priority}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Created</label>
              <p className="text-sm text-gray-900 dark:text-white">{new Date(ticket.createdAt).toLocaleString()}</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Updated</label>
              <p className="text-sm text-gray-900 dark:text-white">{new Date(ticket.updatedAt).toLocaleString()}</p>
            </div>
          </div>
          <div className="flex justify-end mt-6">
            <button onClick={onClose} className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Close</button>
        </div>
      </div>
    </div>
  );
};

  const getStatusColor = (status: SupportTicket['status']) => {
    switch (status) {
      case 'open': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
      case 'in-progress': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
      case 'resolved': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
      case 'closed': return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
  };

  const getPriorityColor = (priority: SupportTicket['priority']) => {
    switch (priority) {
      case 'urgent': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
      case 'high': return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
      case 'medium': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
      case 'low': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
    }
  };

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex items-center gap-3 mb-6">
          <div className="flex items-center justify-center w-10 h-10 bg-purple-100 rounded-xl dark:bg-purple-900/30">
            <ChatIcon className="text-purple-600 size-5 dark:text-purple-400" />
          </div>
        <div>
          <h3 className="text-lg font-bold text-gray-900 dark:text-white">Support Tickets</h3>
          <p className="text-sm text-gray-600 dark:text-gray-400">View and manage user feedback and support requests</p>
        </div>
      </div>

      <div className="max-h-96 overflow-y-auto">
        <div className="space-y-4">
          {tickets.map((ticket) => (
            <div key={ticket.id} className="p-4 border border-gray-200 rounded-lg hover:shadow-md transition-shadow duration-200 dark:border-gray-700">
              <div className="flex items-start justify-between mb-3">
                <div>
                  <h4 className="font-semibold text-gray-900 dark:text-white">{ticket.subject}</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-400">by {ticket.userName} • {ticket.id}</p>
                </div>
                <div className="flex gap-2">
                  <span className={`px-2 py-1 rounded-full text-xs font-semibold ${getStatusColor(ticket.status)}`}>
                    {ticket.status.replace('-', ' ')}
                  </span>
                  <span className={`px-2 py-1 rounded-full text-xs font-semibold ${getPriorityColor(ticket.priority)}`}>
                    {ticket.priority}
                  </span>
                </div>
              </div>
              <p className="text-sm text-gray-700 dark:text-gray-300 mb-3">{ticket.message}</p>
              <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>Created: {new Date(ticket.createdAt).toLocaleDateString()}</span>
                <span>Updated: {new Date(ticket.updatedAt).toLocaleDateString()}</span>
                <button onClick={() => setSelectedTicket(ticket)} className="flex items-center gap-1 text-blue-600 hover:text-blue-700 dark:text-blue-400" title="View Details" aria-label="View ticket details">
                  <EyeIcon className="size-3" />
                  View Details
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>

      {selectedTicket && (
        <TicketDetailsModal ticket={selectedTicket} onClose={() => setSelectedTicket(null)} />
      )}
    </div>
  );
};

// Main Dashboard Component
export default function NotificationCommunicationTools() {
  const [showAnnouncementForm, setShowAnnouncementForm] = useState(false);
  const [communicationSettings, setCommunicationSettings] = useState({
    emailNotifications: true,
    pushNotifications: true,
  });

  const handleAnnouncementSubmit = (announcement: Omit<Announcement, 'id' | 'createdAt'>) => {
    // Handle announcement submission
    console.log('Announcement submitted:', announcement);
    setShowAnnouncementForm(false);
  };

  const handleSettingToggle = (setting: keyof typeof communicationSettings) => {
    setCommunicationSettings(prev => ({
      ...prev,
      [setting]: !prev[setting]
    }));
  };

  return (
    <AppLayout>
      <Head title="Notification & Communication Tools" />

      <div className="space-y-8">
        {/* Header Section */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">
              Notification & Communication Tools
            </h1>
            <p className="text-gray-600 dark:text-gray-400 mt-2">
              Manage system announcements, alerts, and user communications effectively
            </p>
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={() => setShowAnnouncementForm(true)}
              className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200"
              title="New Announcement"
            >
              <PlusIcon className="size-4" />
              New Announcement
            </button>
          </div>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <MetricCard
            title="Total Announcements"
            value={24}
            icon={EnvelopeIcon}
            color="info"
            description="Sent this month"
          />
          <MetricCard
            title="Active Alerts"
            value={3}
            icon={AlertIcon}
            color="warning"
            description="Currently active"
          />
          <MetricCard
            title="Open Tickets"
            value={12}
            icon={ChatIcon}
            color="error"
            description="Require attention"
          />
          <MetricCard
            title="User Engagement"
            value="87%"
            icon={UserIcon}
            color="success"
            description="Announcement read rate"
          />
        </div>

        {/* Announcement Form */}
        {showAnnouncementForm && (
          <AnnouncementForm
            onSubmit={handleAnnouncementSubmit}
            onCancel={() => setShowAnnouncementForm(false)}
          />
        )}

        {/* Main Content Grid */}
        <div className="grid grid-cols-1 gap-8 lg:grid-cols-2">
          <AlertManagement />
          <SupportTickets />
        </div>

        {/* Recent Activity Section */}
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
          <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
            <div className="flex items-center justify-between mb-6">
              <div className="flex items-center gap-3">
                <div className="flex items-center justify-center w-10 h-10 bg-blue-100 rounded-xl dark:bg-blue-900/30">
                  <EnvelopeIcon className="text-blue-600 size-5 dark:text-blue-400" />
                </div>
                <div>
                  <h4 className="text-lg font-semibold text-gray-800 dark:text-white/90">Recent Announcements</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Latest system announcements and notifications</p>
                </div>
              </div>
              <div className="relative inline-block">
                <button className="dropdown-toggle" title="More options" aria-label="More options">
                  <MoreDotIcon className="text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 size-6" />
                </button>
              </div>
            </div>

            <div className="max-w-full overflow-x-auto custom-scrollbar">
              <Table>
                <TableHeader className="border-gray-100 dark:border-gray-800 border-y">
                  <TableRow>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Title</TableCell>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Target</TableCell>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Status</TableCell>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Sent</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  <TableRow>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center dark:bg-blue-900/30">
                          <EnvelopeIcon className="text-blue-600 size-4 dark:text-blue-400" />
                        </div>
                        <span className="text-gray-800 text-theme-sm dark:text-white/90">System Maintenance Notice</span>
                      </div>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">All Users</TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <Badge size="sm" color="success">Sent</Badge>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">2 hours ago</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center dark:bg-green-900/30">
                          <PaperPlaneIcon className="text-green-600 size-4 dark:text-green-400" />
                        </div>
                        <span className="text-gray-800 text-theme-sm dark:text-white/90">New Feature Release</span>
                      </div>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">Shop Owners</TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <Badge size="sm" color="success">Sent</Badge>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">1 day ago</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center dark:bg-orange-900/30">
                          <AlertIcon className="text-orange-600 size-4 dark:text-orange-400" />
                        </div>
                        <span className="text-gray-800 text-theme-sm dark:text-white/90">Holiday Schedule Update</span>
                      </div>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">All Users</TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <Badge size="sm" color="warning">Scheduled</Badge>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">Tomorrow</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          </div>

          <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
            <div className="flex items-center justify-between mb-6">
              <div className="flex items-center gap-3">
                <div className="flex items-center justify-center w-10 h-10 bg-purple-100 rounded-xl dark:bg-purple-900/30">
                  <ChatIcon className="text-purple-600 size-5 dark:text-purple-400" />
                </div>
                <div>
                  <h4 className="text-lg font-semibold text-gray-800 dark:text-white/90">Communication Settings</h4>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Configure notification preferences and channels</p>
                </div>
              </div>
              <div className="relative inline-block">
                <button className="dropdown-toggle" title="More options" aria-label="More options">
                  <MoreDotIcon className="text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 size-6" />
                </button>
              </div>
            </div>

            <div className="max-w-full overflow-x-auto custom-scrollbar">
              <Table>
                <TableHeader className="border-gray-100 dark:border-gray-800 border-y">
                  <TableRow>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Setting</TableCell>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Status</TableCell>
                    <TableCell isHeader className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400">Actions</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  <TableRow>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center dark:bg-blue-900/30">
                          <EnvelopeIcon className="text-blue-600 size-4 dark:text-blue-400" />
                        </div>
                        <span className="text-gray-800 text-theme-sm dark:text-white/90">Email Notifications</span>
                      </div>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <Badge size="sm" color={communicationSettings.emailNotifications ? "success" : "error"}>
                        {communicationSettings.emailNotifications ? "Enabled" : "Disabled"}
                      </Badge>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <button
                        onClick={() => handleSettingToggle('emailNotifications')}
                        className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                          communicationSettings.emailNotifications ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700'
                        }`}
                        title={`Toggle email notifications ${communicationSettings.emailNotifications ? 'off' : 'on'}`}
                        aria-label={`Toggle email notifications ${communicationSettings.emailNotifications ? 'off' : 'on'}`}
                      >
                        <span
                          className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 ${
                            communicationSettings.emailNotifications ? 'translate-x-6' : 'translate-x-1'
                          }`}
                        />
                      </button>
                    </TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center dark:bg-green-900/30">
                          <AlertIcon className="text-green-600 size-4 dark:text-green-400" />
                        </div>
                        <span className="text-gray-800 text-theme-sm dark:text-white/90">Push Notifications</span>
                      </div>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <Badge size="sm" color={communicationSettings.pushNotifications ? "success" : "error"}>
                        {communicationSettings.pushNotifications ? "Enabled" : "Disabled"}
                      </Badge>
                    </TableCell>
                    <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                      <button
                        onClick={() => handleSettingToggle('pushNotifications')}
                        className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                          communicationSettings.pushNotifications ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700'
                        }`}
                        title={`Toggle push notifications ${communicationSettings.pushNotifications ? 'off' : 'on'}`}
                        aria-label={`Toggle push notifications ${communicationSettings.pushNotifications ? 'off' : 'on'}`}
                      >
                        <span
                          className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 ${
                            communicationSettings.pushNotifications ? 'translate-x-6' : 'translate-x-1'
                          }`}
                        />
                      </button>
                    </TableCell>
                  </TableRow>

                </TableBody>
              </Table>
            </div>
        </div>

      </div>

    </div>

    </AppLayout>
  );
}
