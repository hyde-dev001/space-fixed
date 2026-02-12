import React, { useState, useMemo, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayoutShopOwner from '../../layout/AppLayout_shopOwner';
import Swal from 'sweetalert2';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from '../../components/ui/table';
import {
  PlusIcon,
  ArrowUpIcon,
  ArrowDownIcon,
} from '../../icons';

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
  const flash = pageProps.flash;
  const initialEmployees = pageProps.employees;
  
  // Also check for Laravel's default flash data keys
  const success = pageProps.success || flash?.success;
  const temporary_password = pageProps.temporary_password || flash?.temporary_password;
  const employee = pageProps.employee || flash?.employee;
  
  const [activeTab, setActiveTab] = useState<'employees'>('employees');
  const [isEmployeeModalOpen, setIsEmployeeModalOpen] = useState(false);
  const [isAccountModalOpen, setIsAccountModalOpen] = useState(false);
  const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);
  const [selectedUser, setSelectedUser] = useState<UserAccount | null>(null);
  const [employeeFilter, setEmployeeFilter] = useState<string>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);

  // Initialize employees from database and sync with Inertia props
  const [employees, setEmployees] = useState<Employee[]>(
    (initialEmployees || []).map((emp: any) => ({
      ...emp,
      createdAt: new Date(emp.createdAt)
    }))
  );

  // Sync employees when Inertia props update (e.g., after successful employee creation)
  useEffect(() => {
    if (initialEmployees) {
      setEmployees(
        initialEmployees.map((emp: any) => ({
          ...emp,
          createdAt: new Date(emp.createdAt)
        }))
      );
    }
  }, [initialEmployees]);

  // Check for flash data with employee credentials
  useEffect(() => {
    if (success && temporary_password) {
      const tempPass = temporary_password;
      const email = employee?.email || 'N/A';
      const employeeName = employee?.name || 'Employee';
      
      Swal.fire({
        icon: 'success',
        title: '✅ Employee Account Created Successfully',
        html: `
          <div style="text-align:left;padding:20px;background:#f0fdf4;border-radius:8px;margin-bottom:16px">
            <h3 style="color:#15803d;margin:0 0 12px 0;font-size:14px">📋 Share these credentials with ${employeeName}:</h3>

            <div style="background:white;padding:12px;border-radius:6px;margin-bottom:12px;border-left:4px solid #15803d">
              <label style="display:block;font-size:11px;color:#666;margin-bottom:4px;font-weight:bold">📧 LOGIN EMAIL</label>
              <code style="font-size:13px;font-weight:bold;color:#000;word-break:break-all">${email}</code>
            </div>

            <div style="background:white;padding:12px;border-radius:6px;margin-bottom:12px;border-left:4px solid #15803d">
              <label style="display:block;font-size:11px;color:#666;margin-bottom:4px;font-weight:bold">🔐 TEMPORARY PASSWORD</label>
              <code style="font-size:13px;font-weight:bold;color:#15803d;word-break:break-all;letter-spacing:2px">${tempPass}</code>
            </div>

            <div style="background:#fef3c7;padding:12px;border-radius:6px;border-left:4px solid #f59e0b;font-size:12px;color:#78350f">
              <strong style="display:block;margin-bottom:6px">⚠️ IMPORTANT INSTRUCTIONS:</strong>
              <ol style="margin:4px 0;padding-left:20px">
                <li><strong>This password will NOT be displayed again</strong> - copy and save it now</li>
                <li>Share it securely with the employee via email or secure messenger</li>
                <li>Employee should login at: <code style="background:#fff;padding:2px 6px;border-radius:3px">${window.location.origin}/user/login</code></li>
                <li><strong>Employee must change password</strong> immediately after first login</li>
                <li>Employee can then access ERP modules based on their role</li>
              </ol>
            </div>
          </div>
        `,
        width: 600,
        showConfirmButton: true,
        confirmButtonText: '✓ I have saved the credentials',
        confirmButtonColor: '#15803d',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
          // Auto-copy to clipboard
          navigator.clipboard.writeText(tempPass).catch(() => {});
        }
      });
    }
  }, [success, temporary_password, employee]);

  const [roles, setRoles] = useState<Role[]>([]);

  const [userAccounts, setUserAccounts] = useState<UserAccount[]>([]);

  // Human-readable labels for role codes
  const roleLabels: Record<string, string> = {
    Manager: 'Manager',
    Finance: 'Finance',
    HR: 'Human Resources',
    CRM: 'CRM',
    Staff: 'Staff',
    MANAGER: 'Manager',
    FINANCE: 'Finance',
    STAFF: 'Staff',
  };

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
  // Employee suspend modal state
  const [isEmployeeSuspendModalOpen, setIsEmployeeSuspendModalOpen] = useState(false);
  const [employeeToSuspend, setEmployeeToSuspend] = useState<Employee | null>(null);
  const [selectedEmployeeSuspensionReason, setSelectedEmployeeSuspensionReason] = useState('');
  const [otherEmployeeReasonText, setOtherEmployeeReasonText] = useState('');

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
      staff: string[];
    };
    roles: Array<{ name: string; permissions: string[] }>;
  } | null>(null);
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([]);
  const [isSavingPermissions, setIsSavingPermissions] = useState(false);

  // Additional Roles State (Phase 7)
  const [availableRoles, setAvailableRoles] = useState<Array<{ name: string; permissionCount: number }>>([]);
  const [selectedAdditionalRoles, setSelectedAdditionalRoles] = useState<string[]>([]);
  const [isSavingRoles, setIsSavingRoles] = useState(false);

  // Permission Categories State (Phase 6+) - Collapsible categories for better UX
  const [expandedCategories, setExpandedCategories] = useState<{
    finance: boolean;
    hr: boolean;
    crm: boolean;
    manager: boolean;
    staff: boolean;
  }>({
    finance: true,
    hr: true,
    crm: true,
    manager: false,
    staff: false,
  });

  const toggleCategory = (category: 'finance' | 'hr' | 'crm' | 'manager' | 'staff') => {
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
      staff: true,
    });
  };

  const collapseAllCategories = () => {
    setExpandedCategories({
      finance: false,
      hr: false,
      crm: false,
      manager: false,
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
      ...(availablePermissions.grouped.staff || []),
    ];
    const newPermissions = Array.from(new Set([...selectedPermissions, ...allPermissions]));
    setSelectedPermissions(newPermissions);
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
      const matchesFilter = employeeFilter === 'all' ||
        (employeeFilter === 'recent' && employee.createdAt >= new Date(Date.now() - 7 * 24 * 60 * 60 * 1000)) ||
        (employeeFilter === 'Staff' && employee.role === 'Staff') ||
        (employeeFilter === 'Manager' && employee.role === 'Manager');

      const matchesSearch = employee.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        employee.email.toLowerCase().includes(searchTerm.toLowerCase()) ||
        employee.role.toLowerCase().includes(searchTerm.toLowerCase());

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
      title: 'Total Users',
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
      title: 'Suspended Users',
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
      'Staff': 'bg-gray-50 dark:bg-gray-900/20 border-gray-200 dark:border-gray-800 text-gray-800 dark:text-gray-300',
    };
    return styles[role] || 'bg-gray-50 border-gray-200';
  };

  const getRoleInfo = (role: string) => {
    const info: Record<string, { title: string; description: string; permissions: number }> = {
      'Manager': {
        title: '👔 Full System Access',
        description: 'Complete access to all modules: HR, Finance, CRM, Products, Reports, Settings',
        permissions: 66
      },
      'Finance': {
        title: '💰 Finance Department',
        description: 'Manage invoices, expenses, financial reports, approvals, and accounting',
        permissions: 16
      },
      'HR': {
        title: '👥 Human Resources Department',
        description: 'Manage employees, attendance, leave, payroll, and HR reports',
        permissions: 14
      },
      'CRM': {
        title: '🤝 Customer Relationship Management',
        description: 'Manage customers, leads, opportunities, and sales pipeline',
        permissions: 13
      },
      'Staff': {
        title: '⚙️ General Staff (Customizable)',
        description: 'Basic access - HR/Shop Owner can grant specific permissions based on job role',
        permissions: 3
      }
    };
    
    return info[role] || { title: 'Unknown Role', description: '', permissions: 0 };
  };

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
          email: employeeForm.email,
          phone: employeeForm.phone,
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
          onSuccess: () => {
            // Clear the form - Inertia will automatically refresh the employees prop from the server
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
            setIsSubmittingEmployee(false);
            // Note: No need to manually update employees state - Inertia handles this
            // Password will be shown via flash message in useEffect
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

  const handleEditEmployee = () => {
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

    setEmployees(employees.map(employee =>
      employee.id === editingEmployee.id
        ? {
          ...employee,
          name: `${employeeForm.firstName} ${employeeForm.lastName}`,
          email: employeeForm.email,
          role: employeeForm.department || employeeForm.role,
        }
        : employee
    ));

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
    setIsEmployeeModalOpen(false);
    setTimeout(() => {
      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Employee updated successfully!',
        timer: 2000,
        showConfirmButton: false
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

  const handleToggleEmployeeStatus = async (employee: Employee) => {
    // If employee is active, open suspend modal to collect reason (temporary suspend)
    if (employee.status === 'active') {
      setEmployeeToSuspend(employee);
      setSelectedEmployeeSuspensionReason('');
      setOtherEmployeeReasonText('');
      setIsEmployeeSuspendModalOpen(true);
      return;
    }

    // If currently inactive, confirm activation
    const result = await Swal.fire({
      title: `Activate Employee`,
      text: `Are you sure you want to activate ${employee.name}?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#6c757d',
      confirmButtonText: `Yes, activate`
    });

    if (!result.isConfirmed) return;

    // Call server to activate using fetch and update local state on success
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const res = await fetch(`/shop-owner/employees/${employee.id}/activate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf || ''
        },
        body: JSON.stringify({})
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw err;
      }

      await res.json();
      setEmployees(employees.map(e => e.id === employee.id ? { ...e, status: 'active' } : e));
      Swal.fire({ icon: 'success', title: 'Activated!', text: 'Employee activated successfully.', timer: 1500, showConfirmButton: false });
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to activate employee. Please try again.' });
    }
  };

  const confirmEmployeeSuspend = () => {
    if (!employeeToSuspend) return;

    let reason = '';
    if (selectedEmployeeSuspensionReason === 'Other') {
      reason = otherEmployeeReasonText.trim();
      if (!reason) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Please specify the reason for suspension', confirmButtonColor: '#ef4444' });
        return;
      }
    } else {
      reason = selectedEmployeeSuspensionReason;
    }

    if (!reason) {
      Swal.fire({ icon: 'error', title: 'Error', text: 'Please select a reason for suspension', confirmButtonColor: '#ef4444' });
      return;
    }

    // Call server to suspend using fetch so we can handle JSON and update local state immediately
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    fetch(`/shop-owner/employees/${employeeToSuspend.id}/suspend`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf || ''
      },
      body: JSON.stringify({ suspension_reason: reason })
    })
      .then(async (res) => {
        if (!res.ok) {
          const err = await res.json().catch(() => ({}));
          throw err;
        }
        return res.json();
      })
      .then((data) => {
        setEmployees(employees.map(e => e.id === employeeToSuspend.id ? { ...e, status: 'inactive' } : e));
        setIsEmployeeSuspendModalOpen(false);
        setEmployeeToSuspend(null);
        setSelectedEmployeeSuspensionReason('');
        setOtherEmployeeReasonText('');
        Swal.fire({ title: 'Suspended!', text: 'Employee has been suspended temporarily.', icon: 'success', confirmButtonColor: '#10b981' });
      })
      .catch(() => {
        Swal.fire({ title: 'Error', text: 'Failed to suspend employee. Please try again.', icon: 'error', confirmButtonColor: '#ef4444' });
      });
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

  // Fetch available roles on component mount
  useEffect(() => {
    const fetchRoles = async () => {
      try {
        const response = await fetch('/shop-owner/roles/available', {
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          }
        });
        
        if (response.ok) {
          const data = await response.json();
          setAvailableRoles(data.roles || []);
        }
      } catch (error) {
        console.error('Failed to fetch roles:', error);
      }
    };
    
    fetchRoles();
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

    setSelectedEmployee(employee);
    setSelectedPermissions(employee.directPermissions || []);
    setIsPermissionModalOpen(true);
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
    if (!selectedEmployee || !selectedEmployee.userId) return;

    setIsSavingPermissions(true);

    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(`/shop-owner/employees/${selectedEmployee.userId}/permissions/sync`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf || ''
        },
        body: JSON.stringify({
          permissions: selectedPermissions
        })
      });

      if (!response.ok) {
        throw new Error('Failed to update permissions');
      }

      const data = await response.json();

      // Update local employee data
      setEmployees(employees.map(emp => 
        emp.id === selectedEmployee.id 
          ? { 
              ...emp, 
              permissions: data.allPermissions,
              rolePermissions: data.rolePermissions,
              directPermissions: data.directPermissions
            }
          : emp
      ));

      setIsPermissionModalOpen(false);
      
      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Permissions updated successfully',
        timer: 2000,
        showConfirmButton: false
      });

    } catch (error) {
      console.error('Failed to update permissions:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to update permissions. Please try again.',
      });
    } finally {
      setIsSavingPermissions(false);
    }
  };

  // Modal open handlers
  const openAddEmployeeModal = () => {
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
      position: '',
      salary: '',
    });
    setIsEmployeeModalOpen(true);
  };

  const openEditEmployeeModal = (employee: Employee) => {
    setEditingEmployee(employee);
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
                  <option value="Manager">Manager</option>
                  <option value="Staff">Staff</option>
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
                          <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${employee.role === 'MANAGER' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400' :
                              employee.role === 'Manager' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400' :
                              employee.role === 'STAFF' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' :
                              employee.role === 'Staff' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' :
                                'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400'
                            }`}> 
                            {roleLabels[employee.role] || employee.role}
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
                              onClick={() => openPermissionModal(employee)}
                              className="p-2 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg transition-colors duration-200"
                              title="Manage Permissions"
                            >
                              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                              </svg>
                            </button>
                            <button
                              onClick={() => openEditEmployeeModal(employee)}
                              className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors duration-200"
                              title="Edit Employee"
                            >
                              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                              </svg>
                            </button>
                            <button
                              onClick={() => handleToggleEmployeeStatus(employee)}
                              className={`inline-flex items-center justify-center w-8 h-8 rounded-md ${employee.status === 'active' ? 'text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300' : 'text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300'} transition-colors`}
                              title={employee.status === 'active' ? 'Suspend Employee' : 'Activate Employee'}
                            >
                              {employee.status === 'active' ? (
                                <AlertIcon className="h-5 w-5" />
                              ) : (
                                <CheckCircleIcon className="h-5 w-5" />
                              )}
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
    <AppLayoutShopOwner>
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
                        <input type="email" value={employeeForm.email} onChange={(e) => setEmployeeForm({ ...employeeForm, email: e.target.value })} placeholder="Email address" className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white" />
                      </div>

                      <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone</label>
                          <input type="tel" value={employeeForm.phone} onChange={(e) => setEmployeeForm({ ...employeeForm, phone: e.target.value })} placeholder="Phone number" className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white" />
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
                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                          >
                            <option value="">Select department/role</option>
                            <option value="Manager">Manager - Full System Access</option>
                            <option value="Finance">Finance - Invoices, Expenses, Reports</option>
                            <option value="HR">Human Resources - Employees, Payroll, Attendance</option>
                            <option value="CRM">CRM - Customers, Leads, Sales</option>
                            <option value="Staff">Staff - Basic Access (Customizable)</option>
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

                      {employeeForm.department && (
                        <div className={`p-3 rounded-lg border ${getRoleStyle(employeeForm.department)}`}>
                          <p className="text-sm">
                            <strong>{getRoleInfo(employeeForm.department).title}</strong>
                            <br />
                            <span className="text-xs">{getRoleInfo(employeeForm.department).description}</span>
                            <br />
                            <span className="text-xs mt-1 block font-medium">
                              ✅ {getRoleInfo(employeeForm.department).permissions} base permissions
                              {employeeForm.department !== 'Manager' && ' + HR can grant additional permissions'}
                            </span>
                          </p>
                        </div>
                      )}

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
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Monthly Salary</label>
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
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Basic monthly salary for payroll calculation</p>
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

          {/* Employee Suspend Modal */}
          <Modal isOpen={isEmployeeSuspendModalOpen} onClose={() => { setIsEmployeeSuspendModalOpen(false); setEmployeeToSuspend(null); setSelectedEmployeeSuspensionReason(''); setOtherEmployeeReasonText(''); }}>
            <div className="p-6">
              <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">Suspend Employee</h3>
              <div className="mb-4">
                <p className="text-gray-700 dark:text-gray-300">Are you sure you want to temporarily suspend <strong className="text-gray-900 dark:text-white">{employeeToSuspend?.name}</strong>? This will be a temporary suspension and can be reversed by activating the account.</p>
              </div>

              <div className="space-y-3">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Select reason for suspension:</label>
                <div className="space-y-2">
                  {[
                    'Policy Violation',
                    'Fraudulent Activity',
                    'Customer Complaints',
                    'Inappropriate Conduct',
                    'Other'
                  ].map((reason) => (
                    <label key={reason} className="flex items-start cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 p-3 rounded-lg border border-gray-200 dark:border-gray-700 transition-colors">
                      <input type="checkbox" checked={selectedEmployeeSuspensionReason === reason} onChange={(e) => {
                        if (e.target.checked) {
                          setSelectedEmployeeSuspensionReason(reason);
                          if (reason !== 'Other') setOtherEmployeeReasonText('');
                        } else {
                          setSelectedEmployeeSuspensionReason('');
                          setOtherEmployeeReasonText('');
                        }
                      }} className="mt-1 mr-3 h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded" />
                      <span className="text-sm text-gray-700 dark:text-gray-300">{reason === 'Other' ? <span className="font-medium">{reason} (please specify)</span> : reason}</span>
                    </label>
                  ))}
                </div>

                {selectedEmployeeSuspensionReason === 'Other' && (
                  <div>
                    <textarea value={otherEmployeeReasonText} onChange={(e) => setOtherEmployeeReasonText(e.target.value)} rows={3} className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-700 dark:text-white" placeholder="Enter other reason..." />
                  </div>
                )}
              </div>

              <div className="mt-6 flex justify-end gap-3">
                <Button variant="outline" onClick={() => { setIsEmployeeSuspendModalOpen(false); setEmployeeToSuspend(null); setSelectedEmployeeSuspensionReason(''); setOtherEmployeeReasonText(''); }}>Cancel</Button>
                <Button onClick={confirmEmployeeSuspend}>Yes, suspend (temporary)</Button>
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
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('finance');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                            >
                              Add
                            </button>
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('finance');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                            >
                              Clear
                            </button>
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
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('hr');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                            >
                              Add
                            </button>
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('hr');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                            >
                              Clear
                            </button>
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

                  {/* CRM Module */}
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
                              <span className="font-semibold text-gray-900 dark:text-white">CRM Module</span>
                            </div>
                          </div>
                          <div className="flex items-center gap-3">
                            <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                              {availablePermissions.grouped.crm.filter(p => selectedPermissions.includes(p) || selectedEmployee.rolePermissions?.includes(p)).length} / {availablePermissions.grouped.crm.length}
                            </span>
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('crm');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                            >
                              Add
                            </button>
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('crm');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                            >
                              Clear
                            </button>
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
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('manager');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                            >
                              Add
                            </button>
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('manager');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                            >
                              Clear
                            </button>
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

                  {/* Staff Module */}
                  {availablePermissions.grouped.staff && availablePermissions.grouped.staff.length > 0 && (
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
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                addRolePermissions('staff');
                              }}
                              className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors"
                            >
                              Add
                            </button>
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                clearRolePermissions('staff');
                              }}
                              className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors"
                            >
                              Clear
                            </button>
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
                      'Save Permissions'
                    )}
                  </Button>
                </div>
              </div>
            </Modal>
          )}
        </div>
      </div>
    </AppLayoutShopOwner>
  );
};

export default UserAccessControl;
