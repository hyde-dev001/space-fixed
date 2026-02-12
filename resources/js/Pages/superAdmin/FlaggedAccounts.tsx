import React, { useState, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '../../layout/AppLayout';
import Swal from 'sweetalert2';
import { UserIcon } from '../../icons';
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableCell,
} from '../../components/ui/table';

// Types
interface FlaggedAccount {
  id: string;
  username: string;
  email: string;
  flaggedReason: string;
  flaggedDate: string;
  status: string;
}

const FlaggedAccounts: React.FC = () => {
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [flaggedAccounts, setFlaggedAccounts] = useState<FlaggedAccount[]>([]);

  // Memoized filter options with counts
  const filterOptions = useMemo(() => {
    const baseOptions = [
      { label: 'All', value: 'all' },
      { label: 'Pending Review', value: 'pending_review' },
      { label: 'Under Investigation', value: 'under_investigation' },
      { label: 'Reviewed', value: 'read' },
      { label: 'Resolved', value: 'resolved' },
    ];

    return baseOptions.map(option => ({
      ...option,
      count: option.value === 'all'
        ? flaggedAccounts.filter(account => !account.status.startsWith('read,')).length
        : option.value === 'resolved'
        ? flaggedAccounts.filter(account => account.status.startsWith('read,')).length
        : flaggedAccounts.filter(account => account.status === option.value).length
    }));
  }, [flaggedAccounts]);

  // Memoized filtered and searched accounts
  const filteredAccounts = useMemo(() => {
    let filtered = flaggedAccounts;

    // Apply status filter
    if (filterStatus === 'all') {
      filtered = filtered.filter(account => !account.status.startsWith('read,'));
    } else if (filterStatus === 'resolved') {
      filtered = filtered.filter(account => account.status.startsWith('read,'));
    } else {
      filtered = filtered.filter(account => account.status === filterStatus);
    }

    // Apply search filter
    if (searchTerm.trim()) {
      const term = searchTerm.toLowerCase();
      filtered = filtered.filter(account =>
        account.username.toLowerCase().includes(term) ||
        account.email.toLowerCase().includes(term) ||
        account.flaggedReason.toLowerCase().includes(term)
      );
    }

    return filtered;
  }, [flaggedAccounts, filterStatus, searchTerm]);

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pending_review': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
      case 'under_investigation': return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
      case 'under_investigation, unflag': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
      case 'under_investigation, ban': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
      case 'resolved': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
      case 'read': return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
      case 'read, unflag': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
      case 'read, ban': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
      default: return 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
    }
  };

  const handleReviewAccount = (accountId: string) => {
    const account = flaggedAccounts.find(acc => acc.id === accountId);
    if (!account) return;

    // Sample activities based on account type
    const getActivities = (accountId: string) => {
      const activities = {
        '1': [
          { date: '2024-01-15 10:30', action: 'Failed login attempt', details: 'IP: 192.168.1.100' },
          { date: '2024-01-15 10:25', action: 'Failed login attempt', details: 'IP: 192.168.1.100' },
          { date: '2024-01-15 10:20', action: 'Failed login attempt', details: 'IP: 192.168.1.100' },
          { date: '2024-01-14 15:45', action: 'Account created', details: 'Email verification sent' },
          { date: '2024-01-14 15:40', action: 'Profile updated', details: 'Changed password' }
        ],
        '2': [
          { date: '2024-01-12 14:20', action: 'Reported for fraud', details: 'Multiple users reported suspicious activity' },
          { date: '2024-01-12 12:00', action: 'Bulk product upload', details: 'Uploaded 50 products in 5 minutes' },
          { date: '2024-01-11 16:30', action: 'Shop created', details: 'Shop "Fake Electronics" registered' },
          { date: '2024-01-11 16:25', action: 'Payment method added', details: 'Credit card ending in ****1234' },
          { date: '2024-01-10 09:15', action: 'Account created', details: 'Email: fake@domain.com' }
        ],
        '3': [
          { date: '2024-01-10 09:15', action: 'Bulk upload detected', details: 'Uploaded 200 files in 10 minutes' },
          { date: '2024-01-10 09:10', action: 'Multiple file uploads', details: '50 images uploaded simultaneously' },
          { date: '2024-01-09 18:30', action: 'Account verification', details: 'Email verified successfully' },
          { date: '2024-01-09 18:25', action: 'Profile completed', details: 'All required fields filled' },
          { date: '2024-01-09 18:20', action: 'Account created', details: 'Registration completed' }
        ]
      };
      return activities[accountId as keyof typeof activities] || [];
    };

    const activities = getActivities(accountId);
    const activitiesHtml = activities.map(activity =>
      `<div class="text-left mb-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
        <div class="font-semibold text-gray-900 dark:text-white">${activity.action}</div>
        <div class="text-sm text-gray-600 dark:text-gray-400">${activity.date}</div>
        <div class="text-xs text-gray-500 dark:text-gray-500">${activity.details}</div>
      </div>`
    ).join('');

    const handleMarkAsReviewed = () => {
      setFlaggedAccounts(prevAccounts =>
        prevAccounts.map(acc =>
          acc.id === accountId ? { ...acc, status: 'read' } : acc
        )
      );
      Swal.fire({
        title: 'Account Reviewed',
        text: 'The account has been marked as read.',
        icon: 'success',
        confirmButtonColor: '#10B981',
      });
    };

    Swal.fire({
      title: `Account Activities: ${account.username}`,
      html: `
        <div class="text-left max-h-96 overflow-y-auto">
          <div class="mb-4">
            <strong>Email:</strong> ${account.email}<br>
            <strong>Flagged Reason:</strong> ${account.flaggedReason}<br>
            <strong>Status:</strong> ${account.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
          </div>
          <div class="border-t pt-4">
            <h4 class="font-semibold mb-3 text-gray-900 dark:text-white">Recent Activities:</h4>
            ${activitiesHtml}
          </div>
        </div>
      `,
      width: '600px',
      showCloseButton: true,
      showConfirmButton: account.status === 'pending_review' || account.status === 'under_investigation',
      confirmButtonText: 'Mark as Reviewed',
      confirmButtonColor: '#10B981',
      customClass: {
        popup: 'text-left'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        handleMarkAsReviewed();
      }
    });
  };

  const handleAction = (accountId: string, action: 'ban' | 'unflag') => {
    const account = flaggedAccounts.find(acc => acc.id === accountId);
    if (!account) return;

    const actionText = action === 'ban' ? 'ban' : 'unflag';
    Swal.fire({
      title: `Confirm ${actionText.charAt(0).toUpperCase() + actionText.slice(1)}`,
      text: `Are you sure you want to ${actionText} this account?`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: action === 'ban' ? '#DC2626' : '#10B981',
      cancelButtonColor: '#6B7280',
      confirmButtonText: action === 'ban' ? 'Ban Account' : 'Unflag Account',
      cancelButtonText: 'Cancel'
    }).then((result) => {
      if (result.isConfirmed) {
        let newStatus = account.status;
        if (account.status === 'read' || account.status === 'under_investigation') {
          newStatus = action === 'ban' ? `${account.status}, ban` : `${account.status}, unflag`;
        }
        setFlaggedAccounts(prevAccounts =>
          prevAccounts.map(acc =>
            acc.id === accountId ? { ...acc, status: newStatus } : acc
          )
        );
        Swal.fire({
          title: 'Action Completed',
          text: `Account has been ${action === 'ban' ? 'banned' : 'unflagged'} successfully.`,
          icon: 'success',
          confirmButtonColor: '#10B981',
        });
      }
    });
  };

  return (
    <AppLayout>
      <Head title="Flagged Accounts" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 p-6">
      <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-8">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                Flagged Accounts
              </h1>
              <p className="text-gray-600 dark:text-gray-400">
                Manage flagged user accounts requiring attention
              </p>
            </div>
            <div className="text-right">
              <div className="text-2xl font-bold text-gray-900 dark:text-white">
                {filteredAccounts.length}
              </div>
              <div className="text-sm text-gray-500 dark:text-gray-400">
                Total Accounts
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="flex items-center gap-3 mb-6">
            <div className="flex items-center justify-center w-10 h-10 bg-red-100 rounded-xl dark:bg-red-900/30">
              <UserIcon className="text-red-600 size-5 dark:text-red-400" />
            </div>
            <div className="flex-1">
              <h3 className="text-lg font-bold text-gray-900 dark:text-white">Account Management</h3>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Review and take action on flagged accounts to maintain platform security
              </p>
            </div>
          </div>

          {/* Search and Filters */}
          <div className="mb-6 space-y-4">
            {/* Search Bar */}
            <div className="relative">
              <input
                type="text"
                placeholder="Search by username, email, or reason..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-800 dark:border-gray-600 dark:text-white dark:placeholder-gray-400"
              />
              <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </div>
            </div>

            {/* Status Filters */}
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                Filter by Status
              </label>
              <div className="flex flex-wrap gap-2">
                {filterOptions.map((option) => (
                  <button
                    key={option.value}
                    onClick={() => setFilterStatus(option.value)}
                    className={`px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 flex items-center gap-2 ${
                      filterStatus === option.value
                        ? 'bg-red-600 text-white shadow-md'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'
                    }`}
                    title={`Filter by ${option.label} (${option.count})`}
                  >
                    {option.label}
                    <span className={`px-2 py-0.5 rounded-full text-xs ${
                      filterStatus === option.value
                        ? 'bg-white/20 text-white'
                        : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'
                    }`}>
                      {option.count}
                    </span>
                  </button>
                ))}
              </div>
            </div>
          </div>

          <div className="max-w-full overflow-x-auto">
            <Table>
              <TableHeader className="border-b border-gray-100 dark:border-white/[0.05]">
                <TableRow>
                  <TableCell isHeader className="px-5 py-4 text-start">
                    User
                  </TableCell>
                  <TableCell isHeader className="px-4 py-3 text-start">
                    Email
                  </TableCell>
                  <TableCell isHeader className="px-4 py-3 text-start">
                    Flagged Reason
                  </TableCell>
                  <TableCell isHeader className="px-4 py-3 text-start">
                    Flagged Date
                  </TableCell>
                  <TableCell isHeader className="px-4 py-3 text-start">
                    Status
                  </TableCell>
                  <TableCell isHeader className="px-4 py-3 text-start">
                    Actions
                  </TableCell>
                </TableRow>
              </TableHeader>
              <TableBody className="divide-y divide-gray-100 dark:divide-white/[0.05]">
                {filteredAccounts.map((account) => (
                  <TableRow key={account.id}>
                    <TableCell className="px-5 py-4 text-start">
                      <div className="flex items-center gap-3">
                        <div className="flex items-center justify-center w-10 h-10 bg-red-100 rounded-xl dark:bg-red-900/30">
                          <UserIcon className="text-red-600 size-5 dark:text-red-400" />
                        </div>
                        <div>
                          <h4 className="font-semibold text-gray-900 dark:text-white">{account.username}</h4>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                      {account.email}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                      {account.flaggedReason}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                      {new Date(account.flaggedDate).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                      <span className={`px-3 py-1 rounded-full text-xs font-semibold ${getStatusColor(account.status)}`}>
                        {account.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-gray-500 text-start text-theme-sm dark:text-gray-400">
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => handleReviewAccount(account.id)}
                          className="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors duration-200 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-800/50"
                          title="Review account"
                        >
                          Review
                        </button>
                        {account.status === 'read' && (
                          <>
                            <button
                              onClick={() => handleAction(account.id, 'unflag')}
                              className="px-3 py-1 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors duration-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-800/50"
                              title="Unflag account"
                            >
                              Unflag
                            </button>
                            <button
                              onClick={() => handleAction(account.id, 'ban')}
                              className="px-3 py-1 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors duration-200 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-800/50"
                              title="Ban account"
                            >
                              Ban
                            </button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </div>
      </div>
      </div>
    </AppLayout>
  );
};

export default FlaggedAccounts;
