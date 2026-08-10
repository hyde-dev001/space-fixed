import React, { useState, useMemo, useEffect, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayoutShopOwner from '../../../layout/AppLayout_shopOwner';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import Swal from 'sweetalert2';
import Button from '../../../components/ui/button/Button';
import { Modal } from '../../../components/ui/modal';
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from '../../../components/ui/table';
import {
  PlusIcon,
  ArrowUpIcon,
  ArrowDownIcon,
} from '../../../icons';

// Icon Components
const UserCircleIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);



const GroupIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
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

interface Employee {
  id: number;
  name: string;
  email: string;
  role: string;
  status: 'active' | 'inactive';
  createdAt: Date;
  salary?: number | string;
  hire_date?: string;
  department?: string;
  phone?: string;
  address?: string;
  userId?: number;
  roleName?: string;
  permissions?: string[];
  rolePermissions?: string[];
  directPermissions?: string[];
  primaryRole?: string;
  additionalRoles?: string[];
}

interface Role {
  id: number;
  name: string;
  userCount: number;
  permissions: string[];
}

interface UserAccount {
  id: number;
  name: string;
  status: 'active' | 'suspended';
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

interface InvitationModalData {
  inviteUrl: string;
  expiresAt: string;
  workEmail: string;
  employeeName: string;
  employeeUserId?: number;
  wasRegenerated?: boolean;
}

type FieldValidationState = {
  status: 'idle' | 'checking' | 'valid' | 'error';
  message: string;
};

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

          <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${changeType === 'increase'
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

const UserAccessControl: React.FC = () => {
  const pageProps = usePage().props as any;
  const erpMode = pageProps?.erpMode === true;
  const Layout = erpMode ? AppLayoutERP : AppLayoutShopOwner;
  const flash = pageProps.flash || {};
  const initialEmployees = pageProps.employees;
  
  // Get shop owner data from auth
  const auth = pageProps.auth;
  const shopOwner = auth?.shop_owner;
  const rawBusinessType =
    shopOwner?.business_type
    || auth?.user?.shop_owner?.business_type
    || pageProps?.shop_owner?.business_type
    || auth?.business_type
    || auth?.user?.business_type
    || '';
  const normalizedBusinessType = String(rawBusinessType).trim().toLowerCase() === 'both (retail & repair)'
    ? 'both'
    : String(rawBusinessType).trim().toLowerCase();
  const isRetailCapable = normalizedBusinessType === 'retail' || normalizedBusinessType === 'both';
  const isRepairCapable = normalizedBusinessType === 'repair' || normalizedBusinessType === 'both';
  const isCashierCapable = isRetailCapable || isRepairCapable;
  const currentUserId = Number(auth?.user?.id ?? 0);
  const currentAccountEmail = String(auth?.user?.email ?? shopOwner?.email ?? '').trim().toLowerCase();
  
  // Access flash data from shared props (HandleInertiaRequests shares session flash data)
  const success = pageProps.success;
  const invite_url = pageProps.invite_url;
  const invite_expires_at = pageProps.invite_expires_at;
  const email_sent = pageProps.email_sent;
  const employee = pageProps.employee;
  const timestamp = pageProps.timestamp; // Unique identifier for each creation
  const work_email = pageProps.work_email;
  
  const [activeTab, setActiveTab] = useState<'employees'>('employees');
  const [isEmployeeModalOpen, setIsEmployeeModalOpen] = useState(false);
  const [isAccountModalOpen, setIsAccountModalOpen] = useState(false);
  const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);
  const [selectedUser, setSelectedUser] = useState<UserAccount | null>(null);
  const [employeeFilter, setEmployeeFilter] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);
  const [isInviteModalOpen, setIsInviteModalOpen] = useState(false);
  const [invitationModalData, setInvitationModalData] = useState<InvitationModalData | null>(null);
  const [personalInviteEmail, setPersonalInviteEmail] = useState('');
  const [personalInviteEmailError, setPersonalInviteEmailError] = useState('');
  const [isInviteLinkCopied, setIsInviteLinkCopied] = useState(false);
  const [isSendingInviteEmail, setIsSendingInviteEmail] = useState(false);
  const [inviteEmailStatus, setInviteEmailStatus] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const lastInvitationKeyRef = useRef<string | null>(null);

  const openInvitationModal = (
    payload: {
      invite_url?: string;
      invite_expires_at?: string;
      work_email?: string;
      employee?: { name?: string; email?: string; userId?: number };
      timestamp?: string | number;
      wasRegenerated?: boolean;
    },
    uniqueToken?: string | number,
  ) => {
    if (!payload.invite_url || !payload.employee) {
      return;
    }

    const inviteUrl = payload.invite_url;
    const employeeName = payload.employee?.name || 'Employee';
    const dedupeKey = `${uniqueToken ?? payload.timestamp ?? 'none'}-${inviteUrl}-${employeeName}`;

    if (lastInvitationKeyRef.current === dedupeKey) {
      return;
    }

    lastInvitationKeyRef.current = dedupeKey;

    const modalData: InvitationModalData = {
      inviteUrl,
      expiresAt: payload.invite_expires_at ? new Date(payload.invite_expires_at).toLocaleString() : 'N/A',
      workEmail: payload.work_email || payload.employee?.email || 'N/A',
      employeeName,
      employeeUserId: payload.employee?.userId,
      wasRegenerated: payload.wasRegenerated || false,
    };

    setInvitationModalData(modalData);
    setPersonalInviteEmail('');
    setPersonalInviteEmailError('');
    setInviteEmailStatus(null);
    setIsInviteModalOpen(true);
    setIsInviteLinkCopied(false);

    navigator.clipboard.writeText(modalData.inviteUrl)
      .then(() => {
        setIsInviteLinkCopied(true);
        setTimeout(() => setIsInviteLinkCopied(false), 2000);
      })
      .catch(() => {});
  };

  const handleCopyInvitationLink = async () => {
    if (!invitationModalData) return;
    try {
      await navigator.clipboard.writeText(invitationModalData.inviteUrl);
      setIsInviteLinkCopied(true);
      setTimeout(() => setIsInviteLinkCopied(false), 2000);
    } catch {
      setIsInviteLinkCopied(false);
    }
  };

  const handleSendToPersonalEmail = async () => {
    if (!invitationModalData) return;

    const personalEmail = personalInviteEmail.trim();
    const workEmailNormalized = invitationModalData.workEmail.trim().toLowerCase();

    if (!personalEmail) {
      setPersonalInviteEmailError('Please enter an email address.');
      return;
    }

    if (personalEmail.toLowerCase() === workEmailNormalized) {
      setPersonalInviteEmailError('Use their personal email, not work email.');
      return;
    }

    setPersonalInviteEmailError('');
    setInviteEmailStatus(null);

    if (invitationModalData.employeeUserId) {
      try {
        setIsSendingInviteEmail(true);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const emailResponse = await fetch(`/api/shop-owner/employees/${invitationModalData.employeeUserId}/send-invitation-email`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf || '',
          },
          body: JSON.stringify({ personal_email: personalEmail }),
        });

        if (!emailResponse.ok) {
          const errorData = await emailResponse.json().catch(() => ({}));
          throw new Error(errorData.error || 'Failed to send invitation email');
        }

        setInviteEmailStatus({
          type: 'success',
          message: `Invitation email sent to ${personalEmail}`,
        });
      } catch (error) {
        const message = error instanceof Error ? error.message : 'An unexpected error occurred while sending email';
        setInviteEmailStatus({ type: 'error', message });
      } finally {
        setIsSendingInviteEmail(false);
      }
      return;
    }

    const shopName = shopOwner?.business_name || 'SoleSpace';
    window.location.href = `mailto:${personalEmail}?subject=Your ${shopName} Account Invitation&body=Hi ${invitationModalData.employeeName},%0D%0A%0D%0AYou've been invited to join our team at ${shopName}!%0D%0A%0D%0AClick this link to set up your account:%0D%0A${encodeURIComponent(invitationModalData.inviteUrl)}%0D%0A%0D%0AThis link expires on ${invitationModalData.expiresAt}%0D%0A%0D%0AYour work email will be: ${invitationModalData.workEmail}%0D%0A%0D%0AThanks!`;
  };

  // Human-readable labels for role codes
  const roleLabels: Record<string, string> = {
    Manager: 'Manager',
    Finance: 'Finance',
    HR: 'Human Resources',
    CRM: 'Customer Relationship Management',
    Cashier: 'Cashier',
    Repairer: 'Repairer',
    Inventory: 'Inventory',
    Procurement: 'Procurement',
    'Logistics Dispatcher': 'Logistics Dispatcher',
    'Logistics Rider': 'Logistics Rider',
    'Inventory Manager': 'Inventory',
    'Procurement Manager': 'Procurement',
    Staff: 'Staff',
    MANAGER: 'Manager',
    FINANCE: 'Finance',
    INVENTORY: 'Inventory',
    PROCUREMENT: 'Procurement',
    LOGISTICS_DISPATCHER: 'Logistics Dispatcher',
    LOGISTICS_RIDER: 'Logistics Rider',
    REPAIRER: 'Repairer',
    INVENTORY_MANAGER: 'Inventory',
    PROCUREMENT_MANAGER: 'Procurement',
    STAFF: 'Staff',
    CASHIER: 'Cashier',
  };

  function normalizeRoleName(role?: string | null) {
    if (!role) {
      return 'Staff';
    }

    const normalizedKey = role.trim().replace(/\s+/g, ' ').toUpperCase();
    const aliases: Record<string, string> = {
      MANAGER: 'Manager',
      FINANCE: 'Finance',
      HR: 'HR',
      CRM: 'CRM',
      CASHIER: 'Cashier',
      REPAIRER: 'Repairer',
      INVENTORY: 'Inventory',
      PROCUREMENT: 'Procurement',
      LOGISTICS_DISPATCHER: 'Logistics Dispatcher',
      LOGISTICS_RIDER: 'Logistics Rider',
      STAFF: 'Staff',
      'INVENTORY MANAGER': 'Inventory',
      'PROCUREMENT MANAGER': 'Procurement',
      'LOGISTICS DISPATCHER': 'Logistics Dispatcher',
      'LOGISTICS RIDER': 'Logistics Rider',
    };

    return aliases[normalizedKey] || role.trim();
  }

  const isRepairerRole = (roleValue?: string | null) => normalizeRoleName(roleValue) === 'Repairer';

  function mapEmployeeFromServer(emp: any): Employee {
    return {
      ...emp,
      role: normalizeRoleName(emp.role ?? emp.roleName ?? emp.primaryRole ?? emp.department ?? 'Staff'),
      roleName: emp.roleName ? normalizeRoleName(emp.roleName) : emp.roleName,
      primaryRole: normalizeRoleName(emp.primaryRole ?? emp.roleName ?? emp.role ?? emp.department ?? 'Staff'),
      additionalRoles: Array.isArray(emp.additionalRoles)
        ? emp.additionalRoles.map((role: string) => normalizeRoleName(role))
        : emp.additionalRoles,
      createdAt: new Date(emp.createdAt)
    };
  }

  // Initialize employees from database and sync with Inertia props
  const [employees, setEmployees] = useState<Employee[]>(
    (initialEmployees || []).map(mapEmployeeFromServer)
  );

  // Sync employees when Inertia props update (e.g., after successful employee creation)
  useEffect(() => {
    if (initialEmployees) {
      setEmployees(initialEmployees.map(mapEmployeeFromServer));
    }
  }, [initialEmployees]);

  // Check for flash data with employee invitation
  useEffect(() => {
    if (success && invite_url && employee) {
      openInvitationModal({
        invite_url,
        invite_expires_at,
        work_email,
        employee,
        timestamp,
      });
    }
  }, [success, invite_url, invite_expires_at, employee, timestamp, work_email]);

  const [roles, setRoles] = useState<Role[]>([]);

  const [userAccounts, setUserAccounts] = useState<UserAccount[]>([]);

  // Form states - Employee information
  const [employeeForm, setEmployeeForm] = useState({
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    address: '',
    department: '', // Maps to role in backend
    hire_date: new Date().toISOString().split('T')[0],
    role: '', // preserved for backward compatibility
    position: '', // Simple text field for position/job title
    salary: '',
  });
  const [accountAction, setAccountAction] = useState<'activate' | 'suspend'>('activate');
  const [accountReason, setAccountReason] = useState('');
  const [isSubmittingEmployee, setIsSubmittingEmployee] = useState(false);
  const [employeeEmailValidation, setEmployeeEmailValidation] = useState<FieldValidationState>({ status: 'idle', message: '' });
  const [employeePhoneValidation, setEmployeePhoneValidation] = useState<FieldValidationState>({ status: 'idle', message: '' });
  const emailValidationRequestRef = useRef(0);
  const phoneValidationRequestRef = useRef(0);

  // Permission Management State (Phase 6)
  const [isPermissionModalOpen, setIsPermissionModalOpen] = useState(false);
  const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);
  const [availablePermissions, setAvailablePermissions] = useState<{
    all: string[];
    grouped: {
      finance: string[];
      hr: string[];
      crm: string[];
      manager: string[];
      cashier: string[];
      inventory: string[];
      procurement: string[];
      repairer: string[];
      staff: string[];
    };
    roles: Array<{ name: string; permissions: string[] }>;
  } | null>(null);
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([]);
  const [isSavingPermissions, setIsSavingPermissions] = useState(false);

  // Permission Categories State (Phase 6+) - Collapsible categories for better UX
  const [expandedCategories, setExpandedCategories] = useState<{
    finance: boolean;
    hr: boolean;
    crm: boolean;
    manager: boolean;
    cashier: boolean;
    inventory: boolean;
    procurement: boolean;
    repairer: boolean;
    staff: boolean;
  }>({
    finance: true,
    hr: true,
    crm: true,
    manager: false,
    cashier: false,
    inventory: false,
    procurement: false,
    repairer: false,
    staff: false,
  });

  const toggleCategory = (category: 'finance' | 'hr' | 'crm' | 'manager' | 'cashier' | 'inventory' | 'procurement' | 'repairer' | 'staff') => {
    setExpandedCategories(prev => ({
      ...prev,
      [category]: !prev[category]
    }));
  };

  const expandAllCategories = () => {
    setExpandedCategories({
      finance: true,
      hr: true,
      crm: true,
      manager: true,
      cashier: true,
      inventory: true,
      procurement: true,
      repairer: true,
      staff: true,
    });
  };

  const collapseAllCategories = () => {
    setExpandedCategories({
      finance: false,
      hr: false,
      crm: false,
      manager: false,
      cashier: false,
      inventory: false,
      procurement: false,
      repairer: false,
      staff: false,
    });
  };

  const addAllPermissions = () => {
    if (!availablePermissions) return;
    const allPermissions = [
      ...(availablePermissions.grouped.finance || []),
      ...(availablePermissions.grouped.hr || []),
      ...(availablePermissions.grouped.crm || []),
      ...(availablePermissions.grouped.manager || []),
      ...(isCashierCapable ? (availablePermissions.grouped.cashier || []) : []),
      ...(availablePermissions.grouped.inventory || []),
      ...(availablePermissions.grouped.procurement || []),
      ...(isRepairCapable ? (availablePermissions.grouped.repairer || []) : []),
      ...(isRetailCapable ? (availablePermissions.grouped.staff || []) : []),
    ];
    const newPermissions = Array.from(new Set([...selectedPermissions, ...allPermissions]));
    setSelectedPermissions(newPermissions);
  };

  const isStaffPermission = (permission: string) => {
    return permission.startsWith('access-staff-')
      || permission.includes('staff-job-orders')
      || permission.includes('product-management')
      || permission.includes('product-upload-staff')
      || permission.includes('shoe-pricing')
      || permission.includes('staff-time-in')
      || permission.includes('staff-leave')
      || permission.includes('color-variant-manager')
      || permission.includes('staff-customers');
  };

  const isRepairerPermission = (permission: string) => {
    return permission.startsWith('access-repairer-')
      || permission.includes('repair-job-orders')
      || permission.includes('pricing-services')
      || permission.includes('repairer-support')
      || permission.includes('repair-stocks')
      || permission.includes('upload-service');
  };

  const isCashierPermission = (permission: string) => {
    return permission.startsWith('access-cashier-')
      || permission.includes('unified-pos');
  };

  const filterPermissionsByBusinessType = (permissions: string[]) => {
    return permissions.filter((permission) => {
      if (!isRetailCapable && isStaffPermission(permission)) {
        return false;
      }

      if (!isRepairCapable && isRepairerPermission(permission)) {
        return false;
      }

      if (!isCashierCapable && isCashierPermission(permission)) {
        return false;
      }

      return true;
    });
  };

  const clearAllPermissions = () => {
    setSelectedPermissions([]);
  };

  const addRolePermissions = (roleKey: string) => {
    if (!availablePermissions || !availablePermissions.grouped[roleKey]) return;
    const rolePermissions = availablePermissions.grouped[roleKey];
    const newPermissions = Array.from(new Set([...selectedPermissions, ...rolePermissions]));
    setSelectedPermissions(newPermissions);
  };

  const clearRolePermissions = (roleKey: string) => {
    if (!availablePermissions || !availablePermissions.grouped[roleKey]) return;
    const rolePermissions = availablePermissions.grouped[roleKey];
    const newPermissions = selectedPermissions.filter(p => !rolePermissions.includes(p));
    setSelectedPermissions(newPermissions);
  };

  // Computed values
  const filteredEmployees = useMemo(() => {
    return employees.filter((employee) => {
      const normalizedEmployeeRole = normalizeRoleName(employee.role);
      const matchesFilter = employeeFilter === 'all' ||
        (employeeFilter === 'recent' && employee.createdAt >= new Date(Date.now() - 7 * 24 * 60 * 60 * 1000)) ||
        (normalizedEmployeeRole === employeeFilter);

      const matchesSearch = employee.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        employee.email.toLowerCase().includes(searchTerm.toLowerCase()) ||
        normalizedEmployeeRole.toLowerCase().includes(searchTerm.toLowerCase());

      return matchesFilter && matchesSearch;
    });
  }, [employees, employeeFilter, searchTerm]);

  const filteredRoles = useMemo(() => {
    return roles.filter((role) =>
      role.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      role.permissions.some(p => p.toLowerCase().includes(searchTerm.toLowerCase()))
    );
  }, [roles, searchTerm]);

  const filteredUsers = useMemo(() => {
    return userAccounts.filter((user) =>
      user.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      user.status.toLowerCase().includes(searchTerm.toLowerCase())
    );
  }, [userAccounts, searchTerm]);

  // Pagination calculations
  const totalPages = Math.ceil(
    activeTab === 'employees' ? filteredEmployees.length :
    activeTab === 'roles' ? filteredRoles.length :
    filteredUsers.length
  ) / itemsPerPage;
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedEmployees = filteredEmployees.slice(startIndex, endIndex);
  const paginatedRoles = filteredRoles.slice(startIndex, endIndex);
  const paginatedUsers = filteredUsers.slice(startIndex, endIndex);

  // Reset to page 1 when filters or tab changes
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, employeeFilter, activeTab]);

  const stats = useMemo(() => ({
    totalUsers: userAccounts.length,
    activeUsers: userAccounts.filter(u => u.status === 'active').length,
    suspendedUsers: userAccounts.filter(u => u.status === 'suspended').length,
    totalEmployees: employees.length,
    activeEmployees: employees.filter(e => e.status === 'active').length,
    totalRoles: roles.length,
  }), [userAccounts, employees, roles]);

  const metricsData: MetricData[] = [
    {
      title: 'Total Employees',
      value: stats.totalUsers,
      change: 12,
      changeType: 'increase',
      icon: UserCircleIcon,
      color: 'info',
      description: 'from last month'
    },
    {
      title: 'Active Employees',
      value: stats.activeEmployees,
      change: 5,
      changeType: 'increase',
      icon: GroupIcon,
      color: 'success',
      description: 'from last month'
    },
    {
      title: 'Total Roles',
      value: stats.totalRoles,
      change: 0,
      changeType: 'increase',
      icon: GroupIcon,
      color: 'warning',
      description: 'from last month'
    },
    {
      title: 'Suspended Employees',
      value: stats.suspendedUsers,
      change: 8,
      changeType: 'decrease',
      icon: AlertIcon,
      color: 'error',
      description: 'from last month'
    }
  ];

  // CRUD Functions
  const getRoleStyle = (role: string) => {
    const styles: Record<string, string> = {
      'Manager': 'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-800 text-purple-800 dark:text-purple-300',
      'Finance': 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',
      'HR': 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300',
      'CRM': 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-800 text-orange-800 dark:text-orange-300',
      'Cashier': 'bg-cyan-50 dark:bg-cyan-900/20 border-cyan-200 dark:border-cyan-800 text-cyan-800 dark:text-cyan-300',
      'Repairer': 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-800 text-indigo-800 dark:text-indigo-300',
      'Inventory': 'bg-teal-50 dark:bg-teal-900/20 border-teal-200 dark:border-teal-800 text-teal-800 dark:text-teal-300',
      'Procurement': 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300',
      'Logistics Dispatcher': 'bg-sky-50 dark:bg-sky-900/20 border-sky-200 dark:border-sky-800 text-sky-800 dark:text-sky-300',
      'Logistics Rider': 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300',
      'Inventory Manager': 'bg-teal-50 dark:bg-teal-900/20 border-teal-200 dark:border-teal-800 text-teal-800 dark:text-teal-300',
      'Procurement Manager': 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300',
      'Staff': 'bg-gray-50 dark:bg-gray-900/20 border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-300',
    };
    return styles[role] || 'bg-gray-50 border-gray-200';
  };

  const getRoleInfo = (role: string) => {
    const info: Record<string, { title: string; description: string; permissions: number }> = {
      'Manager': {
        title: '👔 Full System Access',
        description: 'Complete access to all modules: HR, Finance, CRM, Products, Reports, Settings',
        permissions: 84
      },
      'Finance': {
        title: '💰 Finance Department',
        description: 'Manage invoices, expenses, financial reports, approvals, and accounting',
        permissions: 27
      },
      'HR': {
        title: '👥 Human Resources Department',
        description: 'Manage employees, attendance, leave, payroll, and HR reports',
        permissions: 16
      },
      'CRM': {
        title: '🤝 Customer Relationship Management',
        description: 'Manage customers, leads, opportunities, sales, and customer support conversations',
        permissions: 21
      },
      'Cashier': {
        title: '🧾 Cashier Operations',
        description: 'Handle unified point-of-sale operations for walk-in retail and repair transactions',
        permissions: 8
      },
      'Repairer': {
        title: '🔧 Technical Support & Repairs',
        description: 'Handle technical support conversations, repair services, and job orders',
        permissions: 13
      },
      'Inventory': {
        title: '📦 Inventory Management',
        description: 'Manage stock levels, product inventory, upload stocks, and track stock movements',
        permissions: 9
      },
      'Procurement': {
        title: '🛒 Procurement Management',
        description: 'Manage purchase requests, purchase orders, supplier management, and order monitoring',
        permissions: 11
      },
      'Inventory Manager': {
        title: '📦 Inventory Management',
        description: 'Manage stock levels, product inventory, upload stocks, and track stock movements',
        permissions: 9
      },
      'Procurement Manager': {
        title: '🛒 Procurement Management',
        description: 'Manage purchase requests, purchase orders, supplier management, and order monitoring',
        permissions: 11
      },
      'Staff': {
        title: '⚙️ General Staff (Customizable)',
        description: 'Basic access - HR/Shop Owner can grant specific permissions based on job role',
        permissions: 3
      }
    };
    
    return info[role] || { title: 'Unknown Role', description: '', permissions: 0 };
  };

  // Filter available roles based on business type
  const getAvailableRoles = () => {
    const allRoles = [
      { value: 'Manager', label: 'Manager' },
      { value: 'Finance', label: 'Finance' },
      { value: 'HR', label: 'Human Resources' },
      { value: 'CRM', label: 'Customer Relationship Management' },
      { value: 'Cashier', label: 'Cashier' },
      { value: 'Repairer', label: 'Repairer' },
      { value: 'Inventory', label: 'Inventory' },
      { value: 'Procurement', label: 'Procurement' },
      { value: 'Logistics Dispatcher', label: 'Logistics Dispatcher' },
      { value: 'Logistics Rider', label: 'Logistics Rider' },
      { value: 'Staff', label: 'Staff' },
    ];

    if (normalizedBusinessType === 'repair') {
      return allRoles.filter(role => role.value !== 'Staff');
    }

    if (normalizedBusinessType === 'retail') {
      return allRoles.filter(role => role.value !== 'Repairer');
    }

    if (!isRetailCapable && !isRepairCapable) {
      return allRoles.filter(role => !['Repairer', 'Staff', 'Cashier'].includes(role.value));
    }

    return allRoles;
  };

  const availableRoleOptions = useMemo(() => {
    const roleOptions = new Map<string, { value: string; label: string }>();

    getAvailableRoles().forEach((role) => {
      roleOptions.set(role.value, role);
    });

    employees.forEach((employee) => {
      const normalizedRole = normalizeRoleName(employee.role);

      if (!isRetailCapable && normalizedRole === 'Staff') {
        return;
      }

      if (!isRepairCapable && normalizedRole === 'Repairer') {
        return;
      }

      if (!isCashierCapable && normalizedRole === 'Cashier') {
        return;
      }

      roleOptions.set(normalizedRole, {
        value: normalizedRole,
        label: roleLabels[normalizedRole] || normalizedRole,
      });
    });

    return Array.from(roleOptions.values());
  }, [employees, isRetailCapable, isRepairCapable, isCashierCapable]);

  const checkEmailAvailability = async (email: string): Promise<{ available: boolean; message?: string }> => {
    try {
      const response = await fetch(`/auth/check-email-availability?email=${encodeURIComponent(email)}`, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
        credentials: 'include',
      });

      const data = await response.json().catch(() => ({}));
      return {
        available: Boolean(data?.available),
        message: typeof data?.message === 'string' ? data.message : undefined,
      };
    } catch {
      return {
        available: false,
        message: 'Unable to verify email right now. Please try again.',
      };
    }
  };

  const checkPhoneAvailability = async (phone: string): Promise<{ available: boolean; message?: string }> => {
    try {
      const response = await fetch(`/auth/check-phone-availability?phone=${encodeURIComponent(phone)}`, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
        credentials: 'include',
      });

      const data = await response.json().catch(() => ({}));
      return {
        available: Boolean(data?.available),
        message: typeof data?.message === 'string' ? data.message : undefined,
      };
    } catch {
      return {
        available: false,
        message: 'Unable to verify phone number right now. Please try again.',
      };
    }
  };

  useEffect(() => {
    if (!isEmployeeModalOpen || Boolean(editingEmployee)) {
      setEmployeeEmailValidation({ status: 'idle', message: '' });
      return;
    }

    const normalizedEmail = employeeForm.email.trim();
    if (!normalizedEmail) {
      setEmployeeEmailValidation({ status: 'idle', message: '' });
      return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(normalizedEmail)) {
      setEmployeeEmailValidation({ status: 'error', message: 'Please enter a valid email address.' });
      return;
    }

    const requestId = ++emailValidationRequestRef.current;
    setEmployeeEmailValidation({ status: 'checking', message: 'Checking email availability...' });

    const timer = window.setTimeout(async () => {
      const result = await checkEmailAvailability(normalizedEmail);
      if (requestId !== emailValidationRequestRef.current) {
        return;
      }

      if (result.available) {
        setEmployeeEmailValidation({ status: 'valid', message: '' });
      } else {
        setEmployeeEmailValidation({ status: 'error', message: result.message || 'This email is already registered.' });
      }
    }, 350);

    return () => window.clearTimeout(timer);
  }, [employeeForm.email, isEmployeeModalOpen, editingEmployee]);

  useEffect(() => {
    if (!isEmployeeModalOpen || Boolean(editingEmployee)) {
      setEmployeePhoneValidation({ status: 'idle', message: '' });
      return;
    }

    const normalizedPhone = employeeForm.phone.replace(/\D/g, '').slice(0, 11);
    if (!normalizedPhone) {
      setEmployeePhoneValidation({ status: 'idle', message: '' });
      return;
    }

    if (normalizedPhone.length < 11) {
      setEmployeePhoneValidation({ status: 'error', message: 'Phone number must be exactly 11 digits.' });
      return;
    }

    const requestId = ++phoneValidationRequestRef.current;
    setEmployeePhoneValidation({ status: 'checking', message: 'Checking phone number availability...' });

    const timer = window.setTimeout(async () => {
      const result = await checkPhoneAvailability(normalizedPhone);
      if (requestId !== phoneValidationRequestRef.current) {
        return;
      }

      if (result.available) {
        setEmployeePhoneValidation({ status: 'valid', message: '' });
      } else {
        setEmployeePhoneValidation({ status: 'error', message: result.message || 'This phone number is already registered.' });
      }
    }, 350);

    return () => window.clearTimeout(timer);
  }, [employeeForm.phone, isEmployeeModalOpen, editingEmployee]);

  const handleAddEmployee = async () => {
    // Check required fields
    if (!employeeForm.firstName || !employeeForm.lastName || !employeeForm.email || !employeeForm.department) {
      Swal.fire({
        icon: 'error',
        title: 'Validation Error',
        text: 'Please fill in all required fields (First name, Last name, Email, Role)',
        timer: 3000,
        showConfirmButton: false
      });
      return;
    }

    const trimmedEmail = employeeForm.email.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(trimmedEmail)) {
      Swal.fire({
        icon: 'error',
        title: 'Validation Error',
        text: 'Please enter a valid email address.',
        timer: 3000,
        showConfirmButton: false
      });
      return;
    }

    const normalizedPhone = employeeForm.phone.replace(/\D/g, '').slice(0, 11);
    if (normalizedPhone && !/^\d{11}$/.test(normalizedPhone)) {
      Swal.fire({
        icon: 'error',
        title: 'Validation Error',
        text: 'Phone number must be exactly 11 digits.',
        timer: 3000,
        showConfirmButton: false
      });
      return;
    }

    if (employeeEmailValidation.status === 'checking' || employeePhoneValidation.status === 'checking') {
      Swal.fire({
        icon: 'info',
        title: 'Please wait',
        text: 'We are still checking email/phone availability.',
        timer: 2000,
        showConfirmButton: false,
      });
      return;
    }

    if (employeeEmailValidation.status === 'error') {
      Swal.fire({
        icon: 'error',
        title: 'Email not available',
        text: employeeEmailValidation.message || 'This email is already registered.',
      });
      return;
    }

    if (normalizedPhone && employeePhoneValidation.status === 'error') {
      Swal.fire({
        icon: 'error',
        title: 'Phone number not available',
        text: employeePhoneValidation.message || 'This phone number is already registered.',
      });
      return;
    }

    const emailAvailability = await checkEmailAvailability(trimmedEmail);
    if (!emailAvailability.available) {
      Swal.fire({
        icon: 'error',
        title: 'Email not available',
        text: emailAvailability.message || 'This email is already registered.',
        showConfirmButton: true,
      });
      return;
    }

    if (normalizedPhone) {
      const phoneAvailability = await checkPhoneAvailability(normalizedPhone);
      if (!phoneAvailability.available) {
        Swal.fire({
          icon: 'error',
          title: 'Phone number not available',
          text: phoneAvailability.message || 'This phone number is already registered.',
          showConfirmButton: true,
        });
        return;
      }
    }

    setIsEmployeeModalOpen(false);
    setIsSubmittingEmployee(true);

    setTimeout(async () => {
      const result = await Swal.fire({
        title: 'Add Employee',
        text: `Are you sure you want to add ${employeeForm.firstName} ${employeeForm.lastName} as an employee in ${employeeForm.department || employeeForm.role}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, add it!'
      });

      if (result.isConfirmed) {
        setIsSubmittingEmployee(true);
        
        // Use Inertia router.post for proper CSRF handling with shop-owner web route
        router.post('/shop-owner/employees', {
          first_name: employeeForm.firstName,
          last_name: employeeForm.lastName,
          name: `${employeeForm.firstName} ${employeeForm.lastName}`,
          email: trimmedEmail,
          phone: normalizedPhone,
          address: employeeForm.address,
          department: employeeForm.department || 'General',
          position: employeeForm.position || '',
          functional_role: '',
          salary: parseFloat(employeeForm.salary) || 0,
          hire_date: employeeForm.hire_date || new Date().toISOString().split('T')[0],
          role: employeeForm.department || employeeForm.role,
          status: 'active',
        }, {
          preserveScroll: true,
          preserveState: true,
          onSuccess: async (page) => {
            // Get the response data from the page props
            const responseData = page.props as any;

            setIsSubmittingEmployee(false);
            
            // Clear the form
            setEmployeeForm({
              firstName: '',
              lastName: '',
              email: '',
              phone: '',
              address: '',
              department: '',
              hire_date: new Date().toISOString().split('T')[0],
              role: '',
              position: '',
              salary: '',
            });
            
            // Show invitation modal immediately with the response data
            if (responseData.invite_url && responseData.employee) {
              openInvitationModal(responseData, responseData.timestamp);
            }
          },
          onError: (errors) => {
            console.error('Errors:', errors);
            
            // Handle Laravel validation errors
            let errorMessage = 'Failed to add employee. Please try again.';
            
            if (typeof errors === 'object' && errors !== null) {
              // Check for validation errors
              const validationErrors = Object.values(errors).flat();
              if (validationErrors.length > 0) {
                errorMessage = validationErrors.join('<br>');
              } else if (errors.message) {
                errorMessage = errors.message;
              } else if (errors.error) {
                errorMessage = errors.error;
              }
            } else if (typeof errors === 'string') {
              errorMessage = errors;
            }
            
            Swal.fire({
              icon: 'error',
              title: 'Error',
              html: errorMessage,
              showConfirmButton: true
            });
            setIsSubmittingEmployee(false);
          }
        });
      } else {
        setIsSubmittingEmployee(false);
      }
    }, 100);
  };

  const handleEditEmployee = async () => {
    if (!editingEmployee || !employeeForm.firstName || !employeeForm.lastName || !employeeForm.email) {
      setIsEmployeeModalOpen(false);
      setTimeout(() => {
        Swal.fire({
          icon: 'error',
          title: 'Validation Error',
          text: 'Please fill in all required fields',
          timer: 3000,
          showConfirmButton: false
        });
      }, 100);
      return;
    }

    const trimmedEmail = employeeForm.email.trim();
    const normalizedPhone = employeeForm.phone.replace(/\D/g, '').slice(0, 11);

    setIsSubmittingEmployee(true);

    setTimeout(() => {
      router.put(`/shop-owner/employees/${editingEmployee.id}`, {
        name: `${employeeForm.firstName} ${employeeForm.lastName}`,
        email: trimmedEmail,
        phone: normalizedPhone,
        address: employeeForm.address,
        department: employeeForm.department || 'General',
        position: employeeForm.position || '',
        salary: parseFloat(employeeForm.salary) || 0,
        hire_date: employeeForm.hire_date || new Date().toISOString().split('T')[0],
        status: editingEmployee.status,
      }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          setEmployees(employees.map((employee) =>
            employee.id === editingEmployee.id
              ? {
                ...employee,
                name: `${employeeForm.firstName} ${employeeForm.lastName}`,
                email: trimmedEmail,
                phone: normalizedPhone,
                address: employeeForm.address,
                department: employeeForm.department || 'General',
                role: employeeForm.department || employeeForm.role,
                position: employeeForm.position || employee.position,
                salary: parseFloat(employeeForm.salary) || 0,
                hire_date: employeeForm.hire_date,
              }
              : employee
          ));

          setIsEmployeeModalOpen(false);
          setEditingEmployee(null);
          setEmployeeForm({
            firstName: '',
            lastName: '',
            email: '',
            phone: '',
            address: '',
            department: '',
            hire_date: new Date().toISOString().split('T')[0],
            role: '',
            salary: '',
          });

          Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Employee updated successfully!',
            timer: 2000,
            showConfirmButton: false
          });
        },
        onError: (errors) => {
          let errorMessage = 'Failed to update employee. Please try again.';

          if (typeof errors === 'object' && errors !== null) {
            const validationErrors = Object.values(errors).flat();
            if (validationErrors.length > 0) {
              errorMessage = validationErrors.join('<br>');
            } else if (errors.message) {
              errorMessage = errors.message;
            } else if (errors.error) {
              errorMessage = errors.error;
            }
          } else if (typeof errors === 'string') {
            errorMessage = errors;
          }

          Swal.fire({
            icon: 'error',
            title: 'Error',
            html: errorMessage,
            showConfirmButton: true
          });
        },
        onFinish: () => {
          setIsSubmittingEmployee(false);
        }
      });
    }, 100);
  };

  const handleDeleteEmployee = async (employeeId: number) => {
    const result = await Swal.fire({
      title: 'Are you sure?',
      text: "This will permanently delete the employee.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete it!'
    });

    if (!result.isConfirmed) return;

    try {
      let wasSuccessful = false;
      await router.delete(`/shop-owner/employees/${employeeId}` , {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          wasSuccessful = true;
          setEmployees(employees.filter(employee => employee.id !== employeeId));
          Swal.fire({
            icon: 'success',
            title: 'Deleted!',
            text: 'Employee deleted successfully!',
            timer: 2000,
            showConfirmButton: false
          });
        },
        onError: (errors: any) => {
          const message = typeof errors === 'string' ? errors : errors?.message || errors?.error || 'Failed to delete employee.';
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: message,
          });
        },
        onFinish: () => {
          if (!wasSuccessful) {
            // Fallback: show success if state already updated
            // Useful when server returns 204 and Inertia omits new props
            // and onSuccess isn't called in some edge cases
            const exists = employees.some(e => e.id === employeeId);
            if (!exists) {
              Swal.fire({
                icon: 'success',
                title: 'Deleted!',
                text: 'Employee deleted successfully!',
                timer: 2000,
                showConfirmButton: false
              });
            }
          }
        }
      });
    } catch (err) {
      console.error(err);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'An unexpected error occurred while deleting.',
      });
    }
  };



  const handleDeleteRole = async (roleId: number) => {
    const result = await Swal.fire({
      title: 'Are you sure?',
      text: "You won't be able to revert this! All users assigned to this role will lose their permissions.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete it!'
    });

    if (result.isConfirmed) {
      setRoles(roles.filter(role => role.id !== roleId));
      Swal.fire({
        icon: 'success',
        title: 'Deleted!',
        text: 'Role deleted successfully!',
        timer: 2000,
        showConfirmButton: false
      });
    }
  };

  const handleAccountAction = async () => {
    if (!selectedUser) {
      setIsAccountModalOpen(false);
      setTimeout(() => {
        Swal.fire({
          icon: 'error',
          title: 'Validation Error',
          text: 'Please select a user',
          timer: 3000,
          showConfirmButton: false
        });
      }, 100);
      return;
    }

    setIsAccountModalOpen(false);
    setTimeout(async () => {
      const result = await Swal.fire({
        title: `${accountAction.charAt(0).toUpperCase() + accountAction.slice(1)} Account`,
        text: `Are you sure you want to ${accountAction} the account for ${selectedUser.name}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: accountAction === 'activate' ? '#3085d6' : '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: `Yes, ${accountAction} it!`
      });

      if (result.isConfirmed) {
        setUserAccounts(userAccounts.map(user =>
          user.id === selectedUser.id
            ? { ...user, status: accountAction === 'activate' ? 'active' : 'suspended' }
            : user
        ));

        setSelectedUser(null);
        setAccountAction('activate');
        setAccountReason('');
        setTimeout(() => {
          Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: `Account ${accountAction}d successfully!`,
            timer: 2000,
            showConfirmButton: false
          });
        }, 100);
      }
    }, 100);
  };

  // ===== Permission Management Functions (Phase 6) =====
  
  // Fetch available permissions on component mount
  useEffect(() => {
    const fetchPermissions = async () => {
      try {
        const response = await fetch('/shop-owner/permissions/available', {
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          }
        });
        
        if (response.ok) {
          const data = await response.json();
          setAvailablePermissions(data);
        }
      } catch (error) {
        console.error('Failed to fetch permissions:', error);
      }
    };
    
    fetchPermissions();
  }, []);

  // Open permission management modal
  const openPermissionModal = async (employee: Employee) => {
    if (!employee.userId) {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Employee user ID not found',
        timer: 2000
      });
      return;
    }

    if (!employee.id) {
      console.warn('Warning: Employee object missing id property', employee);
    }

    // Fetch fresh permission data from server
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(`/shop-owner/employees/${employee.userId}/permissions`, {
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf || ''
        }
      });

      if (!response.ok) {
        throw new Error('Failed to load permissions');
      }

      const data = await response.json();
      
      // Update the employee with fresh permission data
      const updatedEmployee: Employee = {
        ...employee,
        permissions: data.allPermissions,
        rolePermissions: data.rolePermissions,
        directPermissions: data.directPermissions,
        additionalRoles: data.additionalRoles || data.additional_roles || employee.additionalRoles || []
      };

      console.log('Fresh permissions loaded:', {
        employee: updatedEmployee.name,
        userId: employee.userId,
        directPermissions: data.directPermissions,
        rolePermissions: data.rolePermissions
      });

      setSelectedEmployee(updatedEmployee);
      setSelectedPermissions(data.directPermissions || []);
      setIsPermissionModalOpen(true);

    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : 'Failed to load permissions';
      console.error('Error loading permissions:', errorMessage);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: errorMessage + '. Please try again.',
        timer: 3000
      });
    }
  };

  // Toggle permission selection
  const togglePermission = (permission: string) => {
    setSelectedPermissions(prev => {
      if (prev.includes(permission)) {
        return prev.filter(p => p !== permission);
      } else {
        return [...prev, permission];
      }
    });
  };

  // Save permission changes
  const savePermissions = async () => {
    if (!selectedEmployee || !selectedEmployee.userId) {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Employee information is missing. Please try again.',
      });
      return;
    }

    setIsSavingPermissions(true);

    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const knownPermissions = new Set((availablePermissions?.all || []).filter((p): p is string => typeof p === 'string'));
      const normalizePermissions = (values: string[]) =>
        Array.from(
          new Set(
            values
              .filter((value): value is string => typeof value === 'string')
              .map((value) => value.trim())
              .filter((value) => value.length > 0)
              .filter((value) => knownPermissions.size === 0 || knownPermissions.has(value))
          )
        );

      if (!csrf) {
        throw new Error('CSRF token not found. Please refresh the page.');
      }

      let permissionsToSync = filterPermissionsByBusinessType(normalizePermissions(selectedPermissions));

      console.log('Saving permissions for employee:', selectedEmployee.userId, 'Permissions:', permissionsToSync);

      const postSyncPermissions = async (permissionsPayload: string[]) => {
        const response = await fetch(`/shop-owner/employees/${selectedEmployee.userId}/permissions/sync`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf
          },
          credentials: 'include',
          body: JSON.stringify({
            permissions: permissionsPayload
          })
        });

        let data;
        try {
          data = await response.json();
        } catch {
          throw new Error(`Server error: ${response.status} ${response.statusText}. Please try again.`);
        }

        return { response, data };
      };

      let { response, data } = await postSyncPermissions(permissionsToSync);

      // If backend reports invalid permissions, remove them and retry once.
      if (!response.ok && response.status === 422 && Array.isArray(data?.invalid_permissions) && data.invalid_permissions.length > 0) {
        const invalidSet = new Set(
          data.invalid_permissions.filter((permission: unknown): permission is string => typeof permission === 'string')
        );
        permissionsToSync = permissionsToSync.filter((permission) => !invalidSet.has(permission));
        setSelectedPermissions(permissionsToSync);
        ({ response, data } = await postSyncPermissions(permissionsToSync));
      }

      if (!response.ok) {
        // Handle specific error responses from backend
        let errorMessage = 'Failed to update permissions';
        
        // Check for Laravel validation errors (422 status)
        if (data.errors && typeof data.errors === 'object') {
          const validationErrors = Object.values(data.errors).flat();
          if (Array.isArray(validationErrors) && validationErrors.length > 0) {
            errorMessage = validationErrors.join('\n');
          }
        } else if (data.invalid_permissions && Array.isArray(data.invalid_permissions)) {
          // Check for our custom invalid_permissions array
          errorMessage = `Invalid permissions: ${data.invalid_permissions.join(', ')}`;
          if (data.error) {
            errorMessage = data.error;
          }
        } else if (data.error) {
          errorMessage = data.error;
        } else if (data.message) {
          errorMessage = data.message;
        }
        
        const forbiddenPerms = data.forbidden_permissions;
        if (forbiddenPerms && Array.isArray(forbiddenPerms)) {
          errorMessage += `\n\nForbidden permissions:\n${forbiddenPerms.join(', ')}`;
        }
        
        throw new Error(errorMessage);
      }

      console.log('Permission sync response:', data);

      // Verify the employee exists in our local state before updating
      const employeeIndex = employees.findIndex(emp => emp.id === selectedEmployee.id);
      if (employeeIndex === -1) {
        console.warn('Employee not found in local state. Using userId for matching:', selectedEmployee.userId);
      }

      // Update local employee data - use userId as fallback
      setEmployees(employees.map(emp => {
        const isTargetEmployee = emp.id === selectedEmployee.id || emp.userId === selectedEmployee.userId;
        if (isTargetEmployee) {
          return {
            ...emp,
            permissions: data.allPermissions || [],
            rolePermissions: data.rolePermissions || [],
            directPermissions: data.directPermissions || []
          };
        }
        return emp;
      }));

      setIsPermissionModalOpen(false);
      setSelectedPermissions([]);
      
      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: `Permissions updated successfully for ${selectedEmployee.name}`,
        timer: 2000,
        showConfirmButton: false
      });

    } catch (error) {
      let displayMessage = 'Failed to update permissions. Please try again.';
      
      if (error instanceof Error && error.message) {
        displayMessage = error.message;
      } else if (typeof error === 'string') {
        displayMessage = error;
      }
      
      console.error('Failed to update permissions:', error);
      
      Swal.fire({
        icon: 'error',
        title: 'Error Updating Permissions',
        text: displayMessage,
        didOpen: () => {
          // Auto-close after 5 seconds if user doesn't interact
          setTimeout(() => {
            if (Swal.isVisible()) {
              Swal.hideLoading();
            }
          }, 5000);
        }
      });
    } finally {
      setIsSavingPermissions(false);
    }
  };

  // Modal open handlers
  const openAddEmployeeModal = () => {
    setEditingEmployee(null);
    setEmployeeEmailValidation({ status: 'idle', message: '' });
    setEmployeePhoneValidation({ status: 'idle', message: '' });
    setEmployeeForm({
      firstName: '',
      lastName: '',
      email: '',
      phone: '',
      address: '',
      department: '',
      hire_date: new Date().toISOString().split('T')[0],
      role: '',
      position: '',
      salary: '',
    });
    setIsEmployeeModalOpen(true);
  };

  const openEditEmployeeModal = (employee: Employee) => {
    setEditingEmployee(employee);
    setEmployeeEmailValidation({ status: 'idle', message: '' });
    setEmployeePhoneValidation({ status: 'idle', message: '' });
    setEmployeeForm({
      firstName: (employee.name || '').split(' ')[0] || '',
      lastName: ((employee.name || '').split(' ').slice(1).join(' ')) || '',
      email: employee.email,
      phone: employee.phone || '',
      address: employee.address || '',
      department: employee.department || employee.role || '',
      hire_date: employee.hire_date || new Date().toISOString().split('T')[0],
      role: employee.role || '',
      position: (employee as any).position || '',
      salary: employee.salary?.toString() || '',
    });

    setIsEmployeeModalOpen(true);
  };

  // View/Resend Invitation Link
  const viewInvitationLink = async (employee: Employee) => {
    if (!employee.userId) {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Employee user ID not found',
        timer: 2000
      });
      return;
    }

    const employeeEmail = String(employee.email ?? '').trim().toLowerCase();
    const isSelfAccount = (currentUserId > 0 && Number(employee.userId) === currentUserId)
      || (currentAccountEmail !== '' && employeeEmail === currentAccountEmail);

    if (isSelfAccount) {
      await Swal.fire({
        icon: 'info',
        title: 'Action Blocked',
        text: 'You cannot reset the password of the account you are currently using.',
      });
      return;
    }

    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(`/api/shop-owner/employees/${employee.userId}/regenerate-invite`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf || ''
        }
      });

      if (!response.ok) {
        throw new Error('Failed to get invitation link');
      }

      const data = await response.json();
      openInvitationModal(
        {
          invite_url: data.invite_url,
          invite_expires_at: data.invite_expires_at,
          work_email: employee.email,
          employee: {
            name: employee.name,
            email: employee.email,
            userId: employee.userId,
          },
          timestamp: data.timestamp || Date.now(),
          wasRegenerated: true,
        },
        `${employee.userId}-${data.timestamp || Date.now()}`,
      );

    } catch (error) {
      console.error('Failed to get invitation link:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to get invitation link. Please try again.',
      });
    }
  };

  const renderTabContent = () => {
    switch (activeTab) {
      case 'employees':
        return (
          <div className="space-y-6">
            <div className="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
              <div className="flex items-center space-x-4">
                <input
                  type="text"
                  placeholder="Search Employee..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white w-64"
                />
                <label htmlFor="admin-filter-select" className="sr-only">Filter admins</label>
                <select
                  id="employee-filter-select"
                  value={employeeFilter}
                  onChange={(e) => setEmployeeFilter(e.target.value)}
                  className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                  aria-label="Filter employees"
                  title="Filter employees"
                >
                  <option value="all">All</option>
                  {availableRoleOptions.map(role => (
                    <option key={role.value} value={role.value}>{role.value}</option>
                  ))}
                  <option value="recent">Recent (7 days)</option>
                </select>
              </div>
              <button
                onClick={openAddEmployeeModal}
                type="button"
                className="inline-flex items-center gap-2 rounded-lg border border-brand-500 bg-brand-500 px-4 py-2.5 text-theme-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 hover:text-white focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:border-brand-500 dark:bg-brand-500 dark:text-white dark:hover:bg-brand-600 dark:hover:text-white dark:focus:ring-brand-500 cursor-pointer"
              >
                <PlusIcon className="h-4 w-4" />
                <span>Add Employee</span>
              </button>
            </div>

            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Name</TableCell>
                      <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Email</TableCell>
                      <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Role</TableCell>
                      <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Status</TableCell>
                      <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white">Created</TableCell>
                      <TableCell isHeader className="px-6 py-4 font-semibold text-gray-900 dark:text-white text-left">Actions</TableCell>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paginatedEmployees.map((employee) => (
                      <TableRow key={employee.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <TableCell className="px-6 py-4">
                          <div className="flex items-center space-x-3">
                            <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                              {employee.name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                              <div className="font-medium text-gray-900 dark:text-white">{employee.name}</div>
                              {employee.address && (
                                <div className="text-sm text-gray-500 dark:text-gray-400">{employee.address}</div>
                              )}
                            </div>
                          </div>
                        </TableCell>
                        <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">{employee.email}</TableCell>
                        <TableCell className="px-6 py-4">
                          <span
                            className={`inline-flex max-w-[180px] sm:max-w-[240px] items-center rounded-full border px-2 py-1 text-xs font-semibold ${getRoleStyle(normalizeRoleName(employee.role))}`}
                            title={roleLabels[normalizeRoleName(employee.role)] || normalizeRoleName(employee.role)}
                          >
                            <span className="truncate whitespace-nowrap">
                              {roleLabels[normalizeRoleName(employee.role)] || normalizeRoleName(employee.role)}
                            </span>
                          </span>
                          {employee.permissions && (
                            <div className="mt-1">
                              <span className="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400">
                                <svg className="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                {employee.permissions.length} permissions
                              </span>
                            </div>
                          )}
                        </TableCell>
                        <TableCell className="px-6 py-4">
                          <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${employee.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' :
                              'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'
                            }`}>
                            {employee.status}
                          </span>
                        </TableCell>
                        <TableCell className="px-6 py-4 text-gray-600 dark:text-gray-300">
                          {employee.createdAt.toLocaleDateString()}
                        </TableCell>
                        <TableCell className="px-6 py-4">
                          <div className="flex items-center space-x-2">
                            <button
                              type="button"
                              onClick={() => viewInvitationLink(employee)}
                              className={`p-2 rounded-lg transition-colors duration-200 ${(String(employee.email ?? '').trim().toLowerCase() === currentAccountEmail) ? 'text-green-600/50 cursor-not-allowed' : 'text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20'}`}
                              title={(String(employee.email ?? '').trim().toLowerCase() === currentAccountEmail) ? 'You cannot reset your own account password' : 'View/Resend Invitation Link'}
                              disabled={String(employee.email ?? '').trim().toLowerCase() === currentAccountEmail}
                            >
                              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                              </svg>
                            </button>
                            <button
                              type="button"
                              onClick={() => openPermissionModal(employee)}
                              className="p-2 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg transition-colors duration-200"
                              title="Manage Permissions"
                            >
                              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                              </svg>
                            </button>
                            <button
                              type="button"
                              onClick={() => openEditEmployeeModal(employee)}
                              className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors duration-200"
                              title="Edit Employee"
                            >
                              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                              </svg>
                            </button>
                            {/* Delete button removed per request */}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {/* Pagination for Employees */}
              {filteredEmployees.length > 0 && (
                <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                  <div className="flex items-center justify-between">
                    <div className="text-sm text-gray-700 dark:text-gray-300">
                      Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                      <span className="font-medium">{Math.min(endIndex, filteredEmployees.length)}</span> of{" "}
                      <span className="font-medium">{filteredEmployees.length}</span>
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

                      {Array.from({ length: Math.ceil(filteredEmployees.length / itemsPerPage) }, (_, i) => i + 1).map((page) => {
                        const totalPagesCalc = Math.ceil(filteredEmployees.length / itemsPerPage);
                        if (
                          page === 1 ||
                          page === totalPagesCalc ||
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
                        onClick={() => setCurrentPage((prev) => Math.min(prev + 1, Math.ceil(filteredEmployees.length / itemsPerPage)))}
                        disabled={currentPage === Math.ceil(filteredEmployees.length / itemsPerPage)}
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
          </div>
        );

      case 'users':
        return (
          <div className="space-y-6">
            {/* Keep only the Suspended Users metric card; remove search and Active Users card and table header */}
            <div className="grid grid-cols-1 md:grid-cols-1 gap-6 mb-6">
              <div className="bg-gradient-to-br from-red-50 to-red-100 dark:from-red-900/20 dark:to-red-800/20 rounded-xl p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-red-700 dark:text-red-300">Suspended Users</p>
                    <p className="text-3xl font-bold text-red-800 dark:text-red-200">{stats.suspendedUsers}</p>
                  </div>
                  <AlertIcon className="h-12 w-12 text-red-500" />
                </div>
              </div>
            </div>

            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
              <div className="overflow-x-auto">
                <Table>
                  <TableBody>
                    {paginatedUsers.map((user) => (
                      <TableRow key={user.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <TableCell className="px-6 py-4">
                          <div className="flex items-center space-x-3">
                            <div className="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-full flex items-center justify-center text-white font-semibold">
                              {user.name.charAt(0).toUpperCase()}
                            </div>
                            <span className="font-medium text-gray-900 dark:text-white">{user.name}</span>
                          </div>
                        </TableCell>
                        <TableCell className="px-6 py-4 text-center">
                          <div className="flex justify-center">
                            <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${user.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' :
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'
                              }`}>
                              {user.status}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell className="px-6 py-4 text-right">
                          <div className="flex justify-end">
                            <Button
                              size="sm"
                              variant={user.status === 'active' ? 'outline' : 'primary'}
                              onClick={() => {
                                setSelectedUser(user);
                                setAccountAction(user.status === 'active' ? 'suspend' : 'activate');
                                setIsAccountModalOpen(true);
                              }}
                            >
                              {user.status === 'active' ? 'Suspend' : 'Activate'}
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {/* Pagination for Users */}
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

                      {Array.from({ length: Math.ceil(filteredUsers.length / itemsPerPage) }, (_, i) => i + 1).map((page) => {
                        const totalPagesCalc = Math.ceil(filteredUsers.length / itemsPerPage);
                        if (
                          page === 1 ||
                          page === totalPagesCalc ||
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
                        type="button"
                        onClick={() => setCurrentPage((prev) => Math.min(prev + 1, Math.ceil(filteredUsers.length / itemsPerPage)))}
                        disabled={currentPage === Math.ceil(filteredUsers.length / itemsPerPage)}
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
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <Layout>
      <Head title="User Access Control" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
        <div className="max-w-7xl mx-auto p-6">
          {/* Header */}
          <div className="mb-8">
            <h1 className="text-4xl font-bold text-gray-900 dark:text-white mb-2">User Access Control</h1>
            <p className="text-lg text-gray-600 dark:text-gray-400">Manage users, roles, and permissions with ease</p>
          </div>

          {/* Stats Overview */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            {metricsData.map((metric, index) => (
              <MetricCard
                key={index}
                title={metric.title}
                value={metric.value}
                change={metric.change}
                changeType={metric.changeType}
                icon={metric.icon}
                color={metric.color}
                description={metric.description}
              />
            ))}
          </div>

          {/* Tabs */}
          <div className="mb-6">
            <div className="border-b border-gray-200 dark:border-gray-700">
              <nav className="-mb-px flex space-x-8">
                {[
                  { id: 'employees', label: 'Employees', icon: UserCircleIcon },
                ].map((tab) => (
                  <button
                    key={tab.id}
                    onClick={() => {
                      setActiveTab(tab.id as typeof activeTab);
                      setSearchTerm('');
                    }}
                    className={`py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2 ${activeTab === tab.id
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'
                      }`}
                  >
                    <tab.icon className="h-5 w-5" />
                    <span>{tab.label}</span>
                  </button>
                ))}
              </nav>
            </div>
          </div>

          {/* Tab Content */}
          {renderTabContent()}

          {/* Modals */}
          <Modal isOpen={isEmployeeModalOpen} onClose={() => setIsEmployeeModalOpen(false)}>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-white dark:bg-gray-900 rounded-xl shadow-xl max-w-2xl w-full border border-gray-200 dark:border-gray-800 overflow-hidden">
                {/* Header */}
                <div className="border-b border-gray-200 dark:border-gray-800 px-8 py-6">
                  <h2 className="text-2xl font-bold text-gray-900 dark:text-white">{editingEmployee ? 'Edit Employee' : 'Add New Employee'}</h2>
                  <p className="text-gray-600 dark:text-gray-400 text-sm mt-1">Fill in the employee details below</p>
                </div>

                {/* Content */}
                <div className="p-8 max-h-[calc(90vh-140px)] overflow-y-auto">
                  <div className="space-y-6">
                    <div>
                      <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 tracking-wider">PERSONAL INFORMATION</h4>
                      <hr className="mt-3 mb-4 border-gray-200 dark:border-gray-700" />

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name *</label>
                          <input type="text" value={employeeForm.firstName} onChange={(e) => setEmployeeForm({ ...employeeForm, firstName: e.target.value })} placeholder="First name" className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white" />
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name *</label>
                          <input type="text" value={employeeForm.lastName} onChange={(e) => setEmployeeForm({ ...employeeForm, lastName: e.target.value })} placeholder="Last name" className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white" />
                        </div>
                      </div>

                      <div className="mt-4">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email *</label>
                        <input type="email" value={employeeForm.email} onChange={(e) => setEmployeeForm({ ...employeeForm, email: e.target.value })} placeholder="Email address" className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white ${employeeEmailValidation.status === 'error' ? 'border-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600'}`} />
                        {employeeEmailValidation.status === 'error' && (
                          <p className="mt-1 text-xs text-red-600 dark:text-red-400">{employeeEmailValidation.message}</p>
                        )}
                        {employeeEmailValidation.status === 'checking' && (
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{employeeEmailValidation.message}</p>
                        )}
                      </div>

                      <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone</label>
                          <input type="tel" value={employeeForm.phone} onChange={(e) => setEmployeeForm({ ...employeeForm, phone: e.target.value.replace(/\D/g, '').slice(0, 11) })} inputMode="numeric" pattern="[0-9]*" maxLength={11} placeholder="09XXXXXXXXX" className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white ${employeePhoneValidation.status === 'error' ? 'border-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600'}`} />
                          {employeePhoneValidation.status === 'error' && (
                            <p className="mt-1 text-xs text-red-600 dark:text-red-400">{employeePhoneValidation.message}</p>
                          )}
                          {employeePhoneValidation.status === 'checking' && (
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{employeePhoneValidation.message}</p>
                          )}
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                          <input type="text" value={employeeForm.address} onChange={(e) => setEmployeeForm({ ...employeeForm, address: e.target.value })} placeholder="Address" className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white" />
                        </div>
                      </div>
                    </div>

                    <div>
                      <h4 className="text-xs font-semibold text-gray-700 dark:text-gray-300 tracking-wider">JOB INFORMATION</h4>
                      <hr className="mt-3 mb-4 border-gray-200 dark:border-gray-700" />

                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Department / Role <span className="text-red-500">*</span>
                          </label>
                          <select 
                            value={employeeForm.department} 
                            onChange={(e) => setEmployeeForm({ ...employeeForm, department: e.target.value })} 
                            title="Department or role"
                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                          >
                            <option value="">Select department/role</option>
                            {availableRoleOptions.map(role => (
                              <option key={role.value} value={role.value}>{role.label}</option>
                            ))}
                          </select>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Position / Job Title</label>
                          <input 
                            type="text" 
                            value={employeeForm.position} 
                            onChange={(e) => setEmployeeForm({ ...employeeForm, position: e.target.value })} 
                            placeholder="e.g., Sales Associate, Cashier, Stock Clerk"
                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                          />
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            You can assign permissions manually after creating the employee
                          </p>
                        </div>
                      </div>

                      <div className="mt-4">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Hired Date</label>
                        <div className="relative">
                          <input type="date" value={employeeForm.hire_date} onChange={(e) => setEmployeeForm({ ...employeeForm, hire_date: e.target.value })} className="w-full pl-4 pr-10 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white" />
                          <div className="absolute right-3 top-2.5 text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M6 2a1 1 0 011 1v1h6V3a1 1 0 112 0v1h1a2 2 0 012 2v9a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2h1V3a1 1 0 011-1zM4 9h12v6H4V9z" clipRule="evenodd"/></svg>
                          </div>
                        </div>
                      </div>

                      <div className="mt-4">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Daily Rate</label>
                        <div className="relative">
                          <span className="absolute left-3 top-2.5 text-gray-500 dark:text-gray-400">₱</span>
                          <input 
                            type="number" 
                            step="0.01" 
                            min="0" 
                            placeholder="0.00" 
                            value={employeeForm.salary} 
                            onChange={(e) => setEmployeeForm({ ...employeeForm, salary: e.target.value })} 
                            className="w-full pl-8 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                          />
                        </div>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Daily base rate for payroll calculation</p>
                      </div>
                    </div>

                    <div className="flex justify-end gap-3">
                      <button
                        onClick={() => setIsEmployeeModalOpen(false)}
                        className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                      >
                        Cancel
                      </button>
                      <button
                        onClick={editingEmployee ? handleEditEmployee : handleAddEmployee}
                        disabled={isSubmittingEmployee}
                        className="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
                      >
                        {isSubmittingEmployee ? 'Processing...' : (editingEmployee ? 'Update Employee' : 'Add Employee')}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </Modal>

          <Modal isOpen={isAccountModalOpen} onClose={() => setIsAccountModalOpen(false)}>
            <div className="p-6">
              <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                {accountAction.charAt(0).toUpperCase() + accountAction.slice(1)} Account
              </h3>
              <div className="space-y-4">
                <p className="text-gray-600 dark:text-gray-400">
                  Are you sure you want to {accountAction} the selected account{accountAction === 'suspend' ? ' and provide a reason?' : '?'}
                </p>
                {accountAction === 'suspend' && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Reason for Suspension
                    </label>
                    <textarea
                      value={accountReason}
                      onChange={(e) => setAccountReason(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                      placeholder="Enter reason for suspension"
                      rows={3}
                    />
                  </div>
                )}
              </div>
              <div className="mt-6 flex justify-end space-x-3">
                <Button variant="outline" onClick={() => setIsAccountModalOpen(false)}>
                  Cancel
                </Button>
                <Button onClick={handleAccountAction}>
                  {accountAction.charAt(0).toUpperCase() + accountAction.slice(1)} Account
                </Button>
              </div>
            </div>
          </Modal>

          {isInviteModalOpen && invitationModalData && (
            <Modal isOpen={isInviteModalOpen} onClose={() => setIsInviteModalOpen(false)} size="4xl">
              <div className="w-full max-w-4xl p-5 sm:p-6 bg-white dark:bg-black">
                <div className="mb-6 flex items-start gap-4">
                  <div className="h-12 w-12 shrink-0 rounded-full bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-white flex items-center justify-center border border-gray-200 dark:border-gray-700">
                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div>
                    <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Employee Invitation Link</h3>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                      Share this invite with {invitationModalData.employeeName} to complete account setup.
                    </p>
                  </div>
                </div>

                <div className="space-y-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-black p-4 sm:p-5">
                  <div className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-black p-4 text-sm text-gray-900 dark:text-white">
                    <p className="font-semibold">Work email created: {invitationModalData.workEmail}</p>
                    <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">This inbox does not exist yet. Share the invite link via personal email, chat, or SMS.</p>
                  </div>

                  <div className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-black p-4">
                    <div className="flex items-center justify-between gap-3">
                      <p className="text-sm font-bold tracking-wide text-gray-900 dark:text-white">INVITATION LINK</p>
                      <Button size="sm" variant="outline" onClick={handleCopyInvitationLink}>
                        Copy Link
                      </Button>
                    </div>
                    <p className="mt-2 break-all rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-black px-3 py-2 font-mono text-sm leading-6 text-black dark:text-white">
                      {invitationModalData.inviteUrl}
                    </p>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-black p-4 text-sm text-gray-900 dark:text-white">
                      <p className="font-bold text-base">How to share</p>
                      <ul className="mt-2 space-y-1.5 leading-6">
                        <li>Personal email (Gmail/Yahoo)</li>
                        <li>WhatsApp/Messenger</li>
                        <li>SMS</li>
                        <li>In person</li>
                      </ul>
                    </div>

                    <div className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-black p-4 text-sm text-gray-900 dark:text-white">
                      <p className="font-bold text-base">Important</p>
                      <ul className="mt-2 space-y-1.5 leading-6">
                        <li>Link expires: {invitationModalData.expiresAt}</li>
                        {invitationModalData.wasRegenerated && <li>A new link was generated (old one is now invalid)</li>}
                        <li>Employee sets their own password</li>
                        <li>You can regenerate the link anytime</li>
                      </ul>
                    </div>
                  </div>

                  <div>
                    <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                      Personal email (optional)
                    </label>
                    <input
                      type="email"
                      value={personalInviteEmail}
                      onChange={(e) => {
                        setPersonalInviteEmail(e.target.value);
                        if (personalInviteEmailError) {
                          setPersonalInviteEmailError('');
                        }
                      }}
                      placeholder="personal.email@gmail.com"
                      className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-600 dark:bg-black dark:text-white"
                    />
                    {personalInviteEmailError && (
                      <p className="mt-2 text-xs text-gray-800 dark:text-gray-200">{personalInviteEmailError}</p>
                    )}
                    {inviteEmailStatus && (
                      <p className="mt-2 text-xs text-gray-800 dark:text-gray-200">
                        {inviteEmailStatus.message}
                      </p>
                    )}
                  </div>
                </div>

                <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
                  {isInviteLinkCopied && (
                    <span className="text-sm font-medium text-gray-800 dark:text-gray-200">Link copied</span>
                  )}
                  <Button variant="outline" onClick={() => setIsInviteModalOpen(false)}>
                    Done
                  </Button>
                  <Button variant="outline" onClick={handleSendToPersonalEmail}>
                    {isSendingInviteEmail ? 'Sending...' : 'Email to Personal Address'}
                  </Button>
                </div>
              </div>
            </Modal>
          )}

          {/* Permission Management Modal (Phase 6) */}
          {isPermissionModalOpen && selectedEmployee && availablePermissions && (
            <Modal isOpen={isPermissionModalOpen} onClose={() => setIsPermissionModalOpen(false)} size="7xl">
              <div className="p-6 max-h-[90vh] overflow-y-auto">
                <div className="mb-6">
                  <h3 className="text-xl font-semibold text-gray-900 dark:text-white">
                    Manage Permissions
                  </h3>
                  <div className="mt-2 flex items-center gap-3">
                    <p className="text-gray-600 dark:text-gray-400">
                      {selectedEmployee.name}
                    </p>
                    <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                      {selectedEmployee.roleName || selectedEmployee.role}
                    </span>
                  </div>
                </div>

                {/* Role Permissions (Read-only) */}
                {selectedEmployee.rolePermissions && selectedEmployee.rolePermissions.length > 0 && (
                  <div className="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                      </svg>
                      Permissions from {selectedEmployee.roleName || selectedEmployee.role} Role
                    </h4>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                      These permissions are granted by the role and cannot be removed individually
                    </p>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                      {selectedEmployee.rolePermissions.map((permission) => (
                        <label key={permission} className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 cursor-not-allowed">
                          <input
                            type="checkbox"
                            checked={true}
                            disabled={true}
                            className="h-4 w-4 text-gray-400 border-gray-300 rounded cursor-not-allowed opacity-50"
                          />
                          <span className="truncate">{permission}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                )}

                {/* Additional Permissions (Editable) */}
                <div>
                  <div className="flex items-center justify-between mb-4">
                    <div>
                      <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-2">
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Additional Permissions
                      </h4>
                      <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Grant additional permissions beyond those provided by the role
                      </p>
                    </div>
                    <div className="flex gap-2 flex-wrap">
                      <button
                        onClick={addAllPermissions}
                        className="text-xs px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                      >
                        Add All
                      </button>
                      <button
                        onClick={clearAllPermissions}
                        className="text-xs px-3 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                      >
                        Clear All
                      </button>
                      <span className="text-gray-300 dark:text-gray-600">|</span>
                      <button
                        onClick={expandAllCategories}
                        className="text-xs text-gray-700 dark:text-gray-300 hover:underline"
                      >
                        Expand All
                      </button>
                      <span className="text-gray-300 dark:text-gray-600">|</span>
                      <button
                        onClick={collapseAllCategories}
                        className="text-xs text-gray-700 dark:text-gray-300 hover:underline"
                      >
                        Collapse All
                      </button>
                    </div>
                  </div>

                  {/* Finance Module */}
                  {availablePermissions.grouped.finance && availablePermissions.grouped.finance.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('finance')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.finance ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Finance Module</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.finance.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.finance.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('finance');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('finance');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.finance && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.finance.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* HR Module */}
                  {availablePermissions.grouped.hr && availablePermissions.grouped.hr.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('hr')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.hr ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">HR Module</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.hr.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.hr.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('hr');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('hr');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.hr && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.hr.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Customer Relationship Management Module */}
                  {availablePermissions.grouped.crm && availablePermissions.grouped.crm.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('crm')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.crm ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Customer Relationship Management Module</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.crm.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.crm.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('crm');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('crm');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.crm && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.crm.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Manager Module */}
                  {availablePermissions.grouped.manager && availablePermissions.grouped.manager.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('manager')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.manager ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Manager Permissions</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.manager.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.manager.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('manager');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('manager');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.manager && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.manager.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Cashier Module */}
                  {isCashierCapable && availablePermissions.grouped.cashier && availablePermissions.grouped.cashier.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('cashier')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.cashier ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 14l6-6m-6 2h.01M15 16h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Cashier Permissions</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.cashier.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.cashier.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('cashier');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('cashier');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.cashier && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.cashier.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Inventory Module */}
                  {availablePermissions.grouped.inventory && availablePermissions.grouped.inventory.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('inventory')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.inventory ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Inventory Module</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.inventory.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.inventory.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('inventory');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('inventory');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.inventory && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.inventory.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Procurement Module */}
                  {availablePermissions.grouped.procurement && availablePermissions.grouped.procurement.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('procurement')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.procurement ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Procurement Module</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.procurement.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.procurement.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('procurement');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('procurement');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.procurement && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.procurement.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Repairer Module - Only show if business type is not retail-only */}
                  {isRepairCapable && availablePermissions.grouped.repairer && availablePermissions.grouped.repairer.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('repairer')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.repairer ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M21 6.5a4.5 4.5 0 01-6.36 4.09l-6.8 6.8a2 2 0 11-2.83-2.83l6.8-6.8A4.5 4.5 0 1116.5 3a4.49 4.49 0 014.5 3.5z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Repairer Permissions</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.repairer.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.repairer.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('repairer');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('repairer');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.repairer && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.repairer.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {/* Staff Module */}
                  {isRetailCapable && availablePermissions.grouped.staff && availablePermissions.grouped.staff.length > 0 && (
                    <div className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                      <button
                        onClick={() => toggleCategory('staff')}
                        className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                      >
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-3">
                            <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories.staff ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <div className="flex items-center gap-2">
                              <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                              </svg>
                              <span className="font-semibold text-gray-900 dark:text-white">Staff Permissions</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.staff.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.staff.length}
                            </span>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('staff');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                            >
                              Add
                            </div>
                            <div
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('staff');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                            >
                              Clear
                            </div>
                          </div>
                        </div>
                      </button>
                      {expandedCategories.staff && (
                        <div className="p-4 bg-white dark:bg-gray-800">
                          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            {availablePermissions.grouped.staff.map((permission) => {
                              const isFromRole = selectedEmployee.rolePermissions?.includes(permission);
                              const isSelected = selectedPermissions.includes(permission);
                              return (
                                <label
                                  key={permission}
                                  className={`flex items-center gap-2 text-sm p-2 rounded ${isFromRole ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed bg-gray-50 dark:bg-gray-900/50' : 'text-gray-700 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800'}`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isFromRole || isSelected}
                                    disabled={isFromRole}
                                    onChange={() => !isFromRole && togglePermission(permission)}
                                    className={`h-4 w-4 rounded ${isFromRole ? 'text-gray-400 border-gray-300 cursor-not-allowed opacity-50' : 'text-gray-900 dark:text-gray-100 border-gray-300 focus:ring-gray-500'}`}
                                  />
                                  <span className="flex-1 truncate">{permission}</span>
                                  {isFromRole && (
                                    <span className="text-xs text-gray-400 italic">from role</span>
                                  )}
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {/* Summary */}
                <div className="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                  <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                    <span>Total Permissions:</span>
                    <span className="font-semibold">
                      {(selectedEmployee.rolePermissions?.length || 0) + selectedPermissions.filter(p => !selectedEmployee.rolePermissions?.includes(p)).length}
                      <span className="text-xs ml-1">
                        ({selectedEmployee.rolePermissions?.length || 0} from role + {selectedPermissions.filter(p => !selectedEmployee.rolePermissions?.includes(p)).length} additional)
                      </span>
                    </span>
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="mt-6 flex justify-end gap-3">
                  <Button
                    variant="outline"
                    onClick={() => {
                      setIsPermissionModalOpen(false);
                      setSelectedEmployee(null);
                      setSelectedPermissions([]);
                    }}
                    disabled={isSavingPermissions}
                  >
                    Cancel
                  </Button>
                  <Button
                    onClick={savePermissions}
                    disabled={isSavingPermissions}
                  >
                    {isSavingPermissions ? (
                      <>
                        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Saving...
                      </>
                    ) : (
                      'Save Access Changes'
                    )}
                  </Button>
                </div>
              </div>
            </Modal>
          )}
        </div>
      </div>
    </Layout>
  );
};

export default UserAccessControl;
