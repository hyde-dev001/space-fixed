import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../layout/AppLayout';
import Swal from 'sweetalert2';
import Button from '../../components/ui/button/Button';

// Icon Components
const UserCircleIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const LockIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
  </svg>
);

const CheckCircleIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const AlertIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const TrashBinIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

const UserIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
  </svg>
);

const InfoIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ArrowUpIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

interface User {
  id: number;
  firstName: string;
  lastName: string;
  name: string;
  email: string;
  address: string;
  phone: string;
  age: number;
  role?: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'active' | 'deactivated' | 'suspended';
  createdAt: string;
  lastLogin?: string;
  validIdPath?: string;
  employee?: {
    id?: number;
    name?: string | null;
    phone?: string | null;
    position?: string | null;
    department?: string | null;
    branch?: string | null;
    functionalRole?: string | null;
    salary?: number | null;
    hireDate?: string | null;
    status?: string | null;
  } | null;
}

interface MetricData {
  title: string;
  value: number;
  change: number;
  changeType: 'increase' | 'decrease';
  icon: React.ComponentType<{ className?: string }>;
  color: 'success' | 'error' | 'warning' | 'info';
  description: string;
}

// Professional Metric Card Component
const MetricCard: React.FC<MetricData> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description
}) => {

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
      {/* Animated background gradient */}
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>

          <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
            changeType === 'increase'
              ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
              : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
          }`}>
            {changeType === 'increase' ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
            {Math.abs(change)}%
          </div>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
            {title}
          </p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {value.toLocaleString()}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {description}
          </p>
        </div>
      </div>
    </div>
  );
};





interface PageProps {
  users: User[] | { data?: User[]; meta?: any; links?: any } | null;
}

const SuperAdminUserManagement: React.FC<PageProps> = ({ users: initialUsers }) => {
  // Modal refs for focus trapping
  const passwordModalRef = useRef<HTMLDivElement | null>(null);
  const viewModalRef = useRef<HTMLDivElement | null>(null);
  const deactivateModalRef = useRef<HTMLDivElement | null>(null);
  const suspendModalRef = useRef<HTMLDivElement | null>(null);
  const detailsModalRef = useRef<HTMLDivElement | null>(null);

  // Simple focus trap + body scroll lock for modals
  const useFocusTrap = (
    modalRef: React.RefObject<HTMLElement>,
    isOpen: boolean,
    onClose?: () => void
  ) => {
    useEffect(() => {
      if (!isOpen || !modalRef.current) return;
      const modal = modalRef.current;
      const previousActive = document.activeElement as HTMLElement | null;
      const focusableSelector =
        'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])';
      const focusables = Array.from(modal.querySelectorAll<HTMLElement>(focusableSelector));
      const first = focusables[0];
      const last = focusables[focusables.length - 1];

      // Focus first element inside modal
      (first || modal).focus();

      const handleKey = (e: KeyboardEvent) => {
        if (e.key === 'Escape') {
          e.stopPropagation();
          onClose && onClose();
        }
        if (e.key === 'Tab') {
          if (focusables.length === 0) {
            e.preventDefault();
            return;
          }
          if (e.shiftKey) {
            if (document.activeElement === first) {
              e.preventDefault();
              last.focus();
            }
          } else {
            if (document.activeElement === last) {
              e.preventDefault();
              first.focus();
            }
          }
        }
      };

      document.addEventListener('keydown', handleKey);
      // prevent background scroll and interactions
      const prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      return () => {
        document.removeEventListener('keydown', handleKey);
        document.body.style.overflow = prevOverflow;
        previousActive?.focus();
      };
    }, [isOpen, modalRef.current]);
  };
  const [isPasswordModalOpen, setIsPasswordModalOpen] = useState(false);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [isDeactivateModalOpen, setIsDeactivateModalOpen] = useState(false);
  const [isSuspendModalOpen, setIsSuspendModalOpen] = useState(false);
  const [isDetailsModalOpen, setIsDetailsModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [expandedDocuments, setExpandedDocuments] = useState<Set<number>>(new Set());
  const [imageLoadErrors, setImageLoadErrors] = useState<Set<number>>(new Set());

  // Build a browser-accessible URL for files stored on Laravel's public disk
  // - Prefixes with "/storage/" when needed
  // - Encodes spaces and special characters
  // - Works with absolute URLs without modification
  const buildDocumentUrl = (rawPath: string): string => {
    if (!rawPath) return '';
    const trimmed = rawPath.trim();
    if (/^https?:\/\//i.test(trimmed)) return trimmed;
    // Normalize separators and remove leading slashes
    let p = trimmed.replace(/\\/g, '/').replace(/^\/+/, '');
    // If path already includes storage/, keep it; otherwise prefix it
    if (!p.toLowerCase().startsWith('storage/')) {
      p = `storage/${p}`;
    }
    // Encode segments but keep slashes
    const encoded = encodeURI(p);
    return `${window.location.origin}/${encoded}`;
  };

  const [filterStatus, setFilterStatus] = useState<'pending' | 'approved' | 'rejected' | 'active' | 'deactivated' | 'suspended'>('active');
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [deactivateReason, setDeactivateReason] = useState<string>('');
  const [suspendReason, setSuspendReason] = useState<string>('');
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);
  // UI state for disabling buttons and showing API errors
  const [isProcessingId, setIsProcessingId] = useState<number | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);
  const pollingRef = useRef<number | null>(null);

  // Initialize with data from backend (accept paginated payload or raw array)
  const normalizeUsers = (u: PageProps['users']): User[] => {
    if (!u) return [];
    if (Array.isArray(u)) return u as User[];
    if ((u as any).data && Array.isArray((u as any).data)) return (u as any).data as User[];
    return [];
  };

  const [users, setUsers] = useState<User[]>(normalizeUsers(initialUsers));

  // preserve pagination metadata when available
  const [pagination, setPagination] = useState<any>((initialUsers && (initialUsers as any).meta) || null);

  // attach focus traps for each modal
  useFocusTrap(passwordModalRef, isPasswordModalOpen, () => setIsPasswordModalOpen(false));
  useFocusTrap(viewModalRef, isViewModalOpen, () => setIsViewModalOpen(false));
  useFocusTrap(deactivateModalRef, isDeactivateModalOpen, () => setIsDeactivateModalOpen(false));
  useFocusTrap(suspendModalRef, isSuspendModalOpen, () => setIsSuspendModalOpen(false));
  useFocusTrap(detailsModalRef, isDetailsModalOpen, () => {
    setIsDetailsModalOpen(false);
    setExpandedDocuments(new Set());
    setImageLoadErrors(new Set());
  });

  // Filter users based on status, and search term
  const filteredUsers = users.filter(user => {
    const statusMatch = user.status === filterStatus;
    const searchMatch = searchTerm === '' ||
      user.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      user.email.toLowerCase().includes(searchTerm.toLowerCase());
    return statusMatch && searchMatch;
  });

  // Pagination calculations
  const totalPages = Math.ceil(filteredUsers.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedUsers = filteredUsers.slice(startIndex, endIndex);

  // Reset to page 1 when filters change
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, filterStatus]);

  // Handle approve/reject user
  const handleApproval = async (userId: number, action: 'approve' | 'reject') => {
    let rejectionReason = '';
    
    if (action === 'reject') {
      const { value } = await Swal.fire({
        title: 'Reject User?',
        text: 'Are you sure you want to reject this user registration?',
        input: 'textarea',
        inputPlaceholder: 'Enter reason for rejection (optional)',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, reject',
        cancelButtonText: 'Cancel'
      });

      if (value === undefined) return; // User cancelled
      rejectionReason = value || '';
    } else {
      const result = await Swal.fire({
        title: 'Approve User?',
        text: 'Are you sure you want to approve this user registration?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, approve',
        cancelButtonText: 'Cancel'
      });

      if (!result.isConfirmed) return;
    }

    try {
      setIsProcessingId(userId);
      setApiError(null);
      const formData = new FormData();
      if (rejectionReason) {
        formData.append('reason', rejectionReason);
      }

      const response = await fetch(`/superAdmin/users/${userId}/${action}`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json',
        },
        body: formData,
      });

      if (response.ok) {
        setUsers(users.map(user =>
          user.id === userId
            ? { ...user, status: action === 'approve' ? 'active' : 'rejected' }
            : user
        ));
        Swal.fire({
          icon: 'success',
          title: action === 'approve' ? 'Approved!' : 'Rejected!',
          text: `User has been ${action}d successfully.`,
          timer: 2000,
          showConfirmButton: false
        });
      } else {
        const data = await response.json();
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: data.message || `Failed to ${action} user`,
        });
      }
    } catch (error: any) {
      console.error('Error:', error);
      setApiError(error?.message || 'An error occurred while processing your request');
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error?.message || 'An error occurred while processing your request',
      });
    } finally {
      setIsProcessingId(null);
    }
  };

  // Polling: fetch latest users periodically so Shop Owner changes appear without reload
  const fetchUsersList = async () => {
    try {
      const res = await fetch(`/admin/users/list?status=${filterStatus}`, {
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) return;
      const data = await res.json();
      if (data && Array.isArray(data.data)) {
        setUsers(data.data as User[]);
      }
    } catch (e) {
      // ignore polling errors
    }
  };

  useEffect(() => {
    // start polling every 10 seconds
    fetchUsersList();
    pollingRef.current = window.setInterval(fetchUsersList, 10000);
    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
    };
  }, [filterStatus]);

  // Handle deactivate account
  const handleDeactivate = async () => {
    if (!selectedUser) return;

    const userToDeactivate = selectedUser; // Capture user before closing modal

    // Close modal first to prevent z-index issues
    setIsDeactivateModalOpen(false);
    setSelectedUser(null);
    setDeactivateReason('');

    // Wait for modal to close before showing SweetAlert
    setTimeout(async () => {
      const result = await Swal.fire({
        title: 'Deactivate Account?',
        text: `Are you sure you want to deactivate ${userToDeactivate.name}'s account? This action can be reversed later.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, deactivate',
        cancelButtonText: 'Cancel',
        customClass: {
          popup: 'z-[10000]'
        }
      });

      if (result.isConfirmed) {
        try {
          setIsProcessingId(userToDeactivate.id);
          setApiError(null);
          // simulate server processing if needed (replace with real request)
          await new Promise((r) => setTimeout(r, 300));
          setUsers(users.map(user =>
            user.id === userToDeactivate.id
              ? { ...user, status: 'deactivated' }
              : user
          ));

          // Show success message
          Swal.fire({
            icon: 'success',
            title: 'Account Deactivated!',
            text: `${userToDeactivate.name}'s account has been deactivated successfully.`,
            timer: 2000,
            showConfirmButton: false,
            customClass: {
              popup: 'z-[10000]'
            }
          });
        } catch (e: any) {
          setApiError(e?.message || 'Failed to deactivate account');
          Swal.fire({ icon: 'error', title: 'Error', text: e?.message || 'Failed to deactivate account' });
        } finally {
          setIsProcessingId(null);
        }
      }
    }, 100); // Small delay to ensure modal closes
  };

  const openDeactivateModal = (user: User) => {
    setSelectedUser(user);
    setIsDeactivateModalOpen(true);
  };

  // Handle suspend/activate account
  const handleSuspend = async () => {
    if (!selectedUser) return;

    const userToSuspend = selectedUser;
    setIsSuspendModalOpen(false);
    setSelectedUser(null);
    setSuspendReason('');

    setTimeout(async () => {
      try {
        setIsProcessingId(userToSuspend.id);
        setApiError(null);
        const formData = new FormData();
        formData.append('reason', suspendReason);

        // Use fetch for a straightforward AJAX request with CSRF token
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        try {
          const res = await fetch(`/admin/users/${userToSuspend.id}/suspend`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ reason: suspendReason }),
          });

          const data = await res.json().catch(() => ({}));

          if (res.ok) {
            setUsers(users.map(user =>
              user.id === userToSuspend.id
                ? { ...user, status: 'suspended' }
                : user
            ));

            Swal.fire({
              icon: 'success',
              title: 'Account Suspended!',
              text: `${userToSuspend.name}'s account has been suspended successfully.`,
              timer: 2000,
              showConfirmButton: false,
              customClass: {
                popup: 'z-[10000]'
              }
            });
          } else {
            const msg = data?.message || 'Failed to suspend user';
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
          }
        } catch (err: any) {
          console.error(err);
          Swal.fire({ icon: 'error', title: 'Error', text: err?.message || 'Failed to suspend user' });
        } finally {
          setIsProcessingId(null);
        }
      } catch (error) {
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An error occurred while suspending the account',
        });
      } finally {
        setIsProcessingId(null);
      }
    }, 100);
  };

  // Handle reactivate account
  const handleReactivate = async (userId: number, userName: string) => {
    const result = await Swal.fire({
      title: 'Reactivate Account?',
      text: `Are you sure you want to reactivate ${userName}'s account?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, reactivate',
      cancelButtonText: 'Cancel'
    });

    if (!result.isConfirmed) return;

    try {
      setIsProcessingId(userId);
      setApiError(null);
      // Use fetch to call admin activate endpoint and handle JSON response
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      try {
        const res = await fetch(`/admin/users/${userId}/activate`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({}),
        });

        const data = await res.json().catch(() => ({}));

        if (res.ok) {
          setUsers(users.map(user =>
            user.id === userId
              ? { ...user, status: 'active' }
              : user
          ));

          Swal.fire({
            icon: 'success',
            title: 'Account Reactivated!',
            text: `${userName}'s account has been reactivated successfully.`,
            timer: 2000,
            showConfirmButton: false
          });
        } else {
          const msg = data?.message || 'Failed to reactivate user';
          Swal.fire({ icon: 'error', title: 'Error', text: msg });
        }
      } catch (err: any) {
        console.error(err);
        Swal.fire({ icon: 'error', title: 'Error', text: err?.message || 'Failed to reactivate user' });
      } finally {
        setIsProcessingId(null);
      }
    } catch (error) {
      console.error('Error:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'An error occurred while reactivating the account',
      });
    } finally {
      setIsProcessingId(null);
    }
  };

  // Handle reset password
  const handleResetPassword = () => {
    // In real app, this would send a reset email
    Swal.fire({
      icon: 'info',
      title: 'Password Reset',
      text: 'A password reset link has been sent to the user\'s email.',
      timer: 3000,
      showConfirmButton: false
    });
  };



  return (
    <AppLayout>
      <Head title="User Registration Management" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 p-6">
        <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="flex justify-between items-start mb-8">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
              User Registration Management
            </h1>
            <p className="text-gray-600 dark:text-gray-400">
              Manage user registrations, approvals, and account controls
            </p>
          </div>
          <Link
            href="/superAdmin/flagged-accounts"
            className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200"
          >
            View Flagged Accounts
          </Link>
        </div>

        {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
          <MetricCard
            title="Total Users"
            value={users.length}
            change={12}
            changeType="increase"
            icon={UserCircleIcon}
            color="info"
            description="Total registered users"
          />
          <MetricCard
            title="Pending Approvals"
            value={users.filter(u => u.status === 'pending').length}
            change={-5}
            changeType="decrease"
            icon={AlertIcon}
            color="warning"
            description="Users awaiting approval"
          />
          <MetricCard
            title="Active Users"
            value={users.filter(u => u.status === 'active' || u.status === 'approved').length}
            change={8}
            changeType="increase"
            icon={CheckCircleIcon}
            color="success"
            description="Currently active users"
          />
          <MetricCard
            title="Deactivated"
            value={users.filter(u => u.status === 'deactivated').length}
            change={2}
            changeType="increase"
            icon={TrashBinIcon}
            color="error"
            description="Deactivated accounts"
          />
        </div>

        {/* Filters */}
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-8">
          <div className="flex flex-wrap gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Search Users
              </label>
              <input
                type="text"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                placeholder="Search by name or email..."
                aria-label="Search Users"
                className="px-9 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Filter by Status
              </label>
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value as 'pending' | 'approved' | 'rejected' | 'active' | 'deactivated' | 'suspended')}
                aria-label="Filter by Status"
                className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
              >
                <option value="pending">Pending</option>
                <option value="active">Active</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="suspended">Suspended</option>
                <option value="deactivated">Deactivated</option>
              </select>
            </div>
          </div>
        </div>

        {/* Users Table */}
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
          <div className="p-6 border-b border-gray-200 dark:border-gray-700">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
              New Register Users ({filteredUsers.length})
            </h3>
          </div>
          <div className="overflow-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created By</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Employee</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Login</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {paginatedUsers.map((user) => (
                  <tr key={user.id}>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <div className="flex-shrink-0 h-10 w-10">
                          <div className="h-10 w-10 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center">
                            <UserIcon className="h-6 w-6 text-gray-600 dark:text-gray-300" />
                          </div>
                        </div>
                        <div className="ml-4">
                          <div className="text-sm font-medium text-gray-900 dark:text-white">{user.name}</div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">{user.email}</div>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                        {user.role || 'Customer'}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${
                        user.status === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                        user.status === 'approved' || user.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                        user.status === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                        'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
                      }`}>
                        {user.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {new Date(user.createdAt).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {(user as any).createdBy || 'Direct Registration'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {user.employee ? (
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Linked</span>
                      ) : (
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300">No Link</span>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {user.lastLogin ? new Date(user.lastLogin).toLocaleDateString() : 'Never'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      <div className="flex space-x-2 items-center">
                        {user.status === 'pending' ? (
                          <>
                            <button
                              onClick={() => handleApproval(user.id, 'approve')}
                              disabled={isProcessingId === user.id}
                              className={`inline-flex items-center justify-center w-8 h-8 rounded-md text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 transition-colors ${isProcessingId === user.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                              title="Approve User"
                            >
                              <CheckCircleIcon className="h-4 w-4" />
                            </button>
                            <button
                              onClick={() => handleApproval(user.id, 'reject')}
                              disabled={isProcessingId === user.id}
                              className={`inline-flex items-center justify-center w-8 h-8 rounded-md text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 transition-colors ${isProcessingId === user.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                              title="Reject User"
                            >
                              <AlertIcon className="h-4 w-4" />
                            </button>
                          </>
                        ) : (
                          <>
                            <button
                              onClick={() => {
                                setSelectedUser(user);
                                setIsDetailsModalOpen(true);
                              }}
                              disabled={isProcessingId === user.id}
                              className={`inline-flex items-center justify-center w-8 h-8 rounded-md text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors ${isProcessingId === user.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                              title="View Registration Details"
                            >
                              <InfoIcon className="h-5 w-5" />
                            </button>

                            {user.status !== 'suspended' && user.status !== 'deactivated' && (
                              <button
                                onClick={() => {
                                    setSelectedUser(user);
                                    setIsSuspendModalOpen(true);
                                  }}
                                  disabled={isProcessingId === user.id}
                                  className={`inline-flex items-center justify-center w-8 h-8 rounded-md text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300 transition-colors ${isProcessingId === user.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                                  title="Suspend Account"
                                >
                                  <AlertIcon className="h-5 w-5" />
                                </button>
                            )}

                            {user.status === 'suspended' && (
                              <button
                                  onClick={() => handleReactivate(user.id, user.name)}
                                  disabled={isProcessingId === user.id}
                                  className={`inline-flex items-center justify-center w-8 h-8 rounded-md text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 transition-colors ${isProcessingId === user.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                                  title="Reactivate Account"
                                >
                                  <CheckCircleIcon className="h-4 w-4" />
                                </button>
                            )}
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {filteredUsers.length > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(endIndex, filteredUsers.length)}</span> of{" "}
                  <span className="font-medium">{filteredUsers.length}</span>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                    disabled={currentPage === 1}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Previous page"
                  >
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                  </button>

                  {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                    // Show first page, last page, current page, and pages around current
                    if (
                      page === 1 ||
                      page === totalPages ||
                      (page >= currentPage - 1 && page <= currentPage + 1)
                    ) {
                      return (
                        <button
                          key={page}
                          onClick={() => setCurrentPage(page)}
                          className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
                            currentPage === page
                              ? "bg-blue-600 text-white"
                              : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                          }`}
                        >
                          {page}
                        </button>
                      );
                    } else if (page === currentPage - 2 || page === currentPage + 2) {
                      return (
                        <span key={page} className="px-2 text-gray-500 dark:text-gray-400">
                          ...
                        </span>
                      );
                    }
                    return null;
                  })}

                  <button
                    onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                    disabled={currentPage === totalPages}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Next page"
                  >
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>



        {/* Password Reset Modal */}
        {isPasswordModalOpen && (
          <>
              {/* Backdrop */}
              <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" />
              {/* Modal */}
              <div ref={passwordModalRef} tabIndex={-1} className="fixed inset-0 flex items-center justify-center z-[100001] p-4 pointer-events-auto">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                  Reset Password
                </h3>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      User: {selectedUser?.name}
                    </label>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Email: {selectedUser?.email}
                    </label>
                  </div>
                  <div className="bg-yellow-50 dark:bg-yellow-900 border border-yellow-200 dark:border-yellow-700 rounded-md p-4">
                    <div className="flex">
                      <AlertIcon className="h-5 w-5 text-yellow-400" />
                      <div className="ml-3">
                        <h3 className="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                          Password Reset Confirmation
                        </h3>
                        <div className="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                          <p>
                            This will send a password reset link to the user's email address.
                            The user will be able to set a new password using the link.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="mt-6 flex justify-end space-x-3">
                  <Button variant="outline" onClick={() => setIsPasswordModalOpen(false)}>
                    Cancel
                  </Button>
                  <Button onClick={() => {
                    if (selectedUser) {
                      handleResetPassword();
                      setIsPasswordModalOpen(false);
                      setSelectedUser(null);
                    }
                  }}>
                    Send Reset Link
                  </Button>
                </div>
              </div>
            </div>
          </>
        )}

        {/* View User Details Modal */}
        {isViewModalOpen && (
          <>
            {/* Backdrop */}
            <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" />
            {/* Modal */}
            <div ref={viewModalRef} tabIndex={-1} className="fixed inset-0 flex items-center justify-center z-[100001] p-4 pointer-events-auto">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-auto p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                  User Details
                </h3>
                {selectedUser && (
                  <div className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Name
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.name}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Email
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.email}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Address
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.address}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Phone
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.phone}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Age
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.age}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Role
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.role}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Created By
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{(selectedUser as any).createdBy || 'Direct Registration'}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Status
                        </label>
                        <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${
                          selectedUser.status === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                          selectedUser.status === 'approved' || selectedUser.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                          selectedUser.status === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                          'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
                        }`}>
                          {selectedUser.status}
                        </span>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Created At
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{new Date(selectedUser.createdAt).toLocaleDateString()}</p>
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                          Last Login
                        </label>
                        <p className="text-sm text-gray-900 dark:text-white">{selectedUser.lastLogin ? new Date(selectedUser.lastLogin).toLocaleDateString() : 'Never'}</p>
                      </div>
                    </div>
                    <div className="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                      {selectedUser.status === 'pending' && (
                        <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-4">
                          Approval Actions
                        </h4>
                      )}
                      <div className="flex justify-between items-center">
                        {selectedUser.status === 'pending' && (
                          <div className="flex space-x-3">
                            <Button
                              onClick={() => {
                                handleApproval(selectedUser.id, 'approve');
                                setIsViewModalOpen(false);
                              }}
                              className="bg-green-600 hover:bg-green-700 text-white"
                            >
                              <CheckCircleIcon className="h-4 w-4 mr-2" />
                              Approve
                            </Button>
                            <Button
                              onClick={() => {
                                handleApproval(selectedUser.id, 'reject');
                                setIsViewModalOpen(false);
                              }}
                              variant="outline"
                              className="border-red-300 text-red-600 hover:bg-red-50 dark:border-red-600 dark:text-red-400 dark:hover:bg-red-900"
                            >
                              <AlertIcon className="h-4 w-4 mr-2" />
                              Reject
                            </Button>
                          </div>
                        )}
                        <Button variant="outline" onClick={() => setIsViewModalOpen(false)}>
                          Close
                        </Button>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </>
        )}

        {/* Deactivate Account Modal */}
        {isDeactivateModalOpen && (
          <>
            {/* Backdrop */}
            <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" />
            {/* Modal */}
            <div ref={deactivateModalRef} tabIndex={-1} className="fixed inset-0 flex items-center justify-center z-[100001] p-4 pointer-events-auto">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                  Deactivate Account
                </h3>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      User: {selectedUser?.name}
                    </label>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Reason for Deactivation
                    </label>
                    <select
                      value={deactivateReason}
                      onChange={(e) => setDeactivateReason(e.target.value)}
                      aria-label="Select Reason"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                    >
                      <option value="">Select a reason</option>
                      <option value="Violation of Terms">Violation of Terms</option>
                      <option value="Suspicious Activity">Suspicious Activity</option>
                      <option value="Request by User">Request by User</option>
                      <option value="Inactivity">Inactivity</option>
                      <option value="Other">Other</option>
                    </select>
                  </div>
                  <div className="bg-red-50 dark:bg-red-900 border border-red-200 dark:border-red-700 rounded-md p-4">
                    <div className="flex">
                      <AlertIcon className="h-5 w-5 text-red-400" />
                      <div className="ml-3">
                        <h3 className="text-sm font-medium text-red-800 dark:text-red-200">
                          Account Deactivation Warning
                        </h3>
                        <div className="mt-2 text-sm text-red-700 dark:text-red-300">
                          <p>
                            This action will deactivate the user's account. The user will no longer be able to access the system.
                            This action can be reversed by reactivating the account.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="mt-6 flex justify-end space-x-3">
                  <Button variant="outline" onClick={() => setIsDeactivateModalOpen(false)}>
                    Cancel
                  </Button>
                  <Button
                    onClick={handleDeactivate}
                    disabled={!deactivateReason}
                    className="bg-red-600 hover:bg-red-700 text-white disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Deactivate Account
                  </Button>
                </div>
              </div>
            </div>
          </>
        )}

        {/* Suspend Account Modal */}
        {isSuspendModalOpen && (
          <>
            {/* Backdrop */}
            <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto" />
            {/* Modal */}
            <div ref={suspendModalRef} tabIndex={-1} className="fixed inset-0 flex items-center justify-center z-[100001] p-4 pointer-events-auto">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full mx-4 p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                  Suspend Account
                </h3>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      User: {selectedUser?.name}
                    </label>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Reason for Suspension
                    </label>
                    <select
                      value={suspendReason}
                      onChange={(e) => setSuspendReason(e.target.value)}
                      aria-label="Select Reason"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-orange-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                    >
                      <option value="">Select a reason</option>
                      <option value="Suspicious Activity">Suspicious Activity</option>
                      <option value="Violation of Terms">Violation of Terms</option>
                      <option value="Spam or Fraud">Spam or Fraud</option>
                      <option value="User Request">User Request</option>
                      <option value="Maintenance">Maintenance</option>
                      <option value="Other">Other</option>
                    </select>
                  </div>

                  <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-md p-4">
                    <div className="flex">
                      <InfoIcon className="h-5 w-5 text-blue-400 flex-shrink-0 mt-0.5" />
                      <div className="ml-3">
                        <h3 className="text-sm font-medium text-blue-800 dark:text-blue-200">
                          Suspension Message Preview
                        </h3>
                        <div className="mt-2 text-sm text-blue-700 dark:text-blue-300">
                          <p className="italic">
                            {suspendReason
                              ? `"We're sorry, but we have decided to suspend your account due to ${suspendReason.toLowerCase()}.
                              During this suspension period, you will not be able to access your account.
                              If you believe this suspension was made in error, please contact our support team for assistance."`
                              : "Please select a suspension reason above to preview the message that will be sent to the user."
                            }
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div className="bg-orange-50 dark:bg-orange-900 border border-orange-200 dark:border-orange-700 rounded-md p-4">
                    <div className="flex">
                      <AlertIcon className="h-5 w-5 text-orange-400" />
                      <div className="ml-3">
                        <h3 className="text-sm font-medium text-orange-800 dark:text-orange-200">
                          Account Suspension Warning
                        </h3>
                        <div className="mt-2 text-sm text-orange-700 dark:text-orange-300">
                          <p>
                            This action will suspend the user's account. The user will not be able to access the system.
                            This action can be reversed by reactivating the account.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="mt-6 flex justify-end space-x-3">
                  <Button variant="outline" onClick={() => {
                    setIsSuspendModalOpen(false);
                    setSuspendReason('');
                  }}>
                    Cancel
                  </Button>
                  <Button
                    onClick={handleSuspend}
                    disabled={!suspendReason}
                    className="bg-orange-600 hover:bg-orange-700 text-white disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Suspend Account
                  </Button>
                </div>
              </div>
            </div>
          </>
        )}

        {/* Registration Details Modal */}
        {isDetailsModalOpen && selectedUser && (
          <>
            {/* Backdrop */}
            <div 
              className="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100000] pointer-events-auto"
              onClick={() => {
                setIsDetailsModalOpen(false);
                setExpandedDocuments(new Set());
                setImageLoadErrors(new Set());
              }}
              style={{ animation: 'fadeIn 0.3s ease-out' }}
            />
            {/* Modal */}
            <div ref={detailsModalRef} tabIndex={-1} className="fixed inset-0 flex items-center justify-center z-[100001] p-4" 
              style={{ animation: 'slideIn 0.3s ease-out' }}>
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-2xl max-w-4xl w-full max-h-[95vh] overflow-y-auto">
                <div className="p-8">
                  <div className="flex justify-between items-center mb-6">
                    <h3 className="text-3xl font-bold text-gray-900 dark:text-white">
                      User Registration Details
                    </h3>
                    <button
                      onClick={() => {
                        setIsDetailsModalOpen(false);
                        setExpandedDocuments(new Set());
                        setImageLoadErrors(new Set());
                      }}
                      className="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 text-2xl font-bold"
                    >
                      ✕
                    </button>
                  </div>
                  
                  <div className="space-y-8">
                    {/* Personal Information */}
                    <div>
                      <h4 className="text-xl font-bold text-gray-900 dark:text-white mb-4 pb-3 border-b-2 border-gray-200 dark:border-gray-700">
                        Personal Information
                      </h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            First Name
                          </label>
                          <p className="text-base text-gray-900 dark:text-white font-medium">{selectedUser.firstName || (selectedUser.employee?.name ? (selectedUser.employee.name.split(' ')[0]) : '')}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Last Name
                          </label>
                          <p className="text-base text-gray-900 dark:text-white font-medium">{selectedUser.lastName || (selectedUser.employee?.name ? (selectedUser.employee.name.split(' ').slice(1).join(' ')) : '')}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Email Address
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">{selectedUser.email}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Phone Number
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">{selectedUser.phone || selectedUser.employee?.phone || 'Not provided'}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Age
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">{selectedUser.age}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Role
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">{selectedUser.role || 'Customer'}</p>
                        </div>
                      </div>
                    </div>

                    {/* Address Information */}
                    <div>
                      <h4 className="text-xl font-bold text-gray-900 dark:text-white mb-4 pb-3 border-b-2 border-gray-200 dark:border-gray-700">
                        Address
                      </h4>
                      <div>
                        <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                          Address
                        </label>
                        <p className="text-base text-gray-900 dark:text-white">{selectedUser.address || 'Not provided'}</p>
                      </div>
                    </div>

                    {/* HR Information (fallback from employee record) */}
                    {selectedUser.employee && (
                      <div>
                        <h4 className="text-xl font-bold text-gray-900 dark:text-white mb-4 pb-3 border-b-2 border-gray-200 dark:border-gray-700">
                          HR Information
                        </h4>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                          <div>
                            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">Position</label>
                            <p className="text-base text-gray-900 dark:text-white">{selectedUser.employee.position || 'N/A'}</p>
                          </div>
                          <div>
                            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">Department</label>
                            <p className="text-base text-gray-900 dark:text-white">{selectedUser.employee.department || 'N/A'}</p>
                          </div>
                          <div>
                            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">Functional Role</label>
                            <p className="text-base text-gray-900 dark:text-white">{selectedUser.employee.functionalRole || 'N/A'}</p>
                          </div>
                          <div>
                            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">Branch</label>
                            <p className="text-base text-gray-900 dark:text-white">{selectedUser.employee.branch || 'N/A'}</p>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Document Information */}
                    {selectedUser.validIdPath && (
                      <div>
                        <h4 className="text-xl font-bold text-gray-900 dark:text-white mb-4 pb-3 border-b-2 border-gray-200 dark:border-gray-700">
                          Submitted Documents
                        </h4>
                        <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg max-h-96 overflow-y-auto">
                          <div className="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-white dark:bg-gray-800">
                            <div className="flex items-center justify-between mb-2">
                              <div className="flex items-center space-x-2">
                                <div className="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded flex items-center justify-center">
                                  <span className="text-blue-600 dark:text-blue-400 text-sm">📄</span>
                                </div>
                                <div>
                                  <p className="text-xs font-semibold text-gray-900 dark:text-white">
                                    Valid Identification
                                  </p>
                                  <p className="text-xs text-gray-500 dark:text-gray-400">
                                    Government-issued ID
                                  </p>
                                </div>
                              </div>
                              <button
                                onClick={() => {
                                  const newExpanded = new Set(expandedDocuments);
                                  if (newExpanded.has(0)) {
                                    newExpanded.delete(0);
                                  } else {
                                    newExpanded.add(0);
                                  }
                                  setExpandedDocuments(newExpanded);
                                }}
                                className="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-xs font-medium px-2 py-1 rounded transition-colors"
                              >
                                {expandedDocuments.has(0) ? 'Hide' : 'View'}
                              </button>
                            </div>
                            {expandedDocuments.has(0) && (
                              <div className="mt-2 pt-2 border-t border-gray-200 dark:border-gray-600">
                                <div className="bg-gray-100 dark:bg-gray-900 rounded border border-gray-300 dark:border-gray-600 flex items-center justify-center" style={{ minHeight: '200px' }}>
                                  {!imageLoadErrors.has(0) ? (
                                    <img
                                      src={buildDocumentUrl(selectedUser.validIdPath || '')}
                                      alt="Valid ID Document"
                                      className="max-w-full max-h-96 rounded object-contain"
                                      onError={(e) => {
                                        setImageLoadErrors(prev => new Set(prev).add(0));
                                        e.currentTarget.style.display = 'none';
                                      }}
                                    />
                                  ) : null}
                                  {imageLoadErrors.has(0) && (
                                    <p className="text-sm text-gray-500 dark:text-gray-400 text-center p-6">
                                      Unable to load image
                                    </p>
                                  )}
                                </div>
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Account Status */}
                    <div>
                      <h4 className="text-xl font-bold text-gray-900 dark:text-white mb-4 pb-3 border-b-2 border-gray-200 dark:border-gray-700">
                        Account Status
                      </h4>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Status
                          </label>
                          <span className={`inline-block px-4 py-2 text-sm font-bold rounded-lg ${
                            selectedUser.status === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                            selectedUser.status === 'approved' || selectedUser.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                            selectedUser.status === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                            selectedUser.status === 'suspended' ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' :
                            'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
                          }`}>
                            {selectedUser.status.charAt(0).toUpperCase() + selectedUser.status.slice(1)}
                          </span>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Registration Date
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">{new Date(selectedUser.createdAt).toLocaleDateString()}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Registration Time
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">{new Date(selectedUser.createdAt).toLocaleTimeString()}</p>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Last Login
                          </label>
                          <p className="text-base text-gray-900 dark:text-white">
                            {selectedUser.lastLogin ? new Date(selectedUser.lastLogin).toLocaleDateString() : 'Never logged in'}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div className="mt-10 flex justify-end gap-3 pt-6 border-t border-gray-200 dark:border-gray-700">
                    <Button variant="outline" onClick={() => setIsDetailsModalOpen(false)}>
                      Close
                    </Button>
                  </div>
                </div>
              </div>
            </div>
            <style>{`
              @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
              }
              @keyframes slideIn {
                from { transform: scale(0.95) translateY(-20px); opacity: 0; }
                to { transform: scale(1) translateY(0); opacity: 1; }
              }
            `}</style>
          </>
        )}
      </div>
      </div>
    </AppLayout>
  );
};

export default SuperAdminUserManagement;
