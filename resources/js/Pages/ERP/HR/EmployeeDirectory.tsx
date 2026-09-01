import React, { useMemo, useState, useRef, useEffect } from "react";
import { createPortal } from "react-dom";
import Swal from "sweetalert2";
import { Inertia } from "@inertiajs/inertia";
import { router, usePage } from '@inertiajs/react';

type EmployeeStatus = "active" | "inactive" | "suspended" | "terminated";

type Employee = {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
  phone?: string;
  department: string;
  position: string;
  status: EmployeeStatus;
  onLeave?: boolean;
  probation?: boolean;
  suspensionReason?: string;
  hiredAt: string;
  lastActiveAt?: string;
  location?: string;
  // optional metadata supplied by the backend
  createdBy?: string;
  linkedUser?: string | number; // username or id of linked user account
  terminatedAt?: string;
  employmentHistory?: EmploymentPeriod[];
};

type EmployeeSummaryStats = {
  total: number;
  active: number;
  onLeave: number;
  probation: number;
};

type MetricCardProps = {
  title: string;
  value: number;
  change?: number;
  changeType?: "increase" | "decrease";
  description?: string;
  color?: "success" | "error" | "warning" | "info";
  icon: React.FC<{ className?: string }>;
};

type FieldValidationState = {
  status: "idle" | "checking" | "valid" | "error";
  message: string;
};

type EmploymentPeriod = {
  id: number;
  startDate: string;
  endDate?: string | null;
  endReason?: string | null;
  position?: string | null;
  department?: string | null;
  role?: string | null;
  salary?: string | number | null;
};

type LifecycleRequestForm = {
  reason: string;
  evidence: string;
};

type RehireRequestForm = LifecycleRequestForm & {
  rehireStartDate: string;
  rehirePosition: string;
  rehireDepartment: string;
  rehireSalary: string;
  rehireRole: string;
};

// Portal wrapper to mirror the registration modal layering and avoid navbar stacking issues
const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === "undefined") return null;
  return createPortal(children, document.body);
};

const UserCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const BriefcaseIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-7 8h8a2 2 0 002-2V8a2 2 0 00-2-2h-1V5a2 2 0 00-2-2h-2a2 2 0 00-2 2v1H9a2 2 0 00-2 2v10a2 2 0 002 2z" />
  </svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const AlertIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const LockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
  </svg>
);

const InfoIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const CalendarIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

const ArrowUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

const Button: React.FC<{
  children: React.ReactNode;
  variant?: "primary" | "secondary" | "success" | "danger";
  onClick?: () => void;
  className?: string;
  disabled?: boolean;
}> = ({ children, variant = "primary", onClick, className = "", disabled = false }) => {
  const baseClasses = "inline-flex min-h-11 items-center justify-center px-4 py-2 rounded-lg transition-colors duration-200 font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2";
  const variantClasses = {
    primary: "bg-blue-600 text-white hover:bg-blue-700 disabled:bg-blue-400",
    secondary: "bg-gray-600 text-white hover:bg-gray-700 disabled:bg-gray-400",
    success: "bg-green-600 text-white hover:bg-green-700 disabled:bg-green-400",
    danger: "bg-red-600 text-white hover:bg-red-700 disabled:bg-red-400",
  } as const;

  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={`${baseClasses} ${variantClasses[variant]} ${className}`}
    >
      {children}
    </button>
  );
};

const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color = "info",
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success":
        return "from-green-500 to-emerald-600";
      case "error":
        return "from-red-500 to-rose-600";
      case "warning":
        return "from-yellow-500 to-orange-600";
      case "info":
      default:
        return "from-blue-500 to-indigo-600";
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

          {change !== undefined && changeType && (
            <div
              className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
                changeType === "increase"
                  ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                  : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
              }`}
            >
              {changeType === "increase" ? (
                <ArrowUpIcon className="size-3" />
              ) : (
                <ArrowDownIcon className="size-3" />
              )}
              {Math.abs(change)}%
            </div>
          )}
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {value.toLocaleString()}
          </h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

const statusLabel: Record<EmployeeStatus, string> = {
  active: "Active",
  inactive: "Inactive",
  suspended: "Suspended",
  terminated: "Terminated",
};

const statusBadge = (status: EmployeeStatus) => {
  if (status === "active") return "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200";
  if (status === "inactive") return "bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200";
  if (status === "suspended") return "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200";
  return "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200";
};

const seedEmployees: Employee[] = [
  {
    id: 1,
    firstName: "Samantha",
    lastName: "Lopez",
    email: "samantha.lopez@solespace.com",
    phone: "+63 917 123 4567",
    department: "Operations",
    position: "Store Manager",
    status: "active",
    hiredAt: "2022-03-14",
    lastActiveAt: "2026-01-18",
    location: "Makati City",
  },
  {
    id: 2,
    firstName: "Carlos",
    lastName: "Reyes",
    email: "carlos.reyes@solespace.com",
    phone: "+63 928 112 0034",
    department: "Sales",
    position: "Sales Specialist",
    status: "active",
    onLeave: true,
    hiredAt: "2023-07-01",
    lastActiveAt: "2026-01-12",
    location: "Cebu",
  },
  {
    id: 3,
    firstName: "Alicia",
    lastName: "Tan",
    email: "alicia.tan@solespace.com",
    phone: "+63 915 222 9182",
    department: "HR",
    position: "HR Generalist",
    status: "active",
    probation: true,
    hiredAt: "2025-11-20",
    lastActiveAt: "2026-01-19",
    location: "Quezon City",
  },
  {
    id: 4,
    firstName: "Miguel",
    lastName: "Santos",
    email: "miguel.santos@solespace.com",
    phone: "+63 917 808 3311",
    department: "IT",
    position: "Support Engineer",
    status: "inactive",
    hiredAt: "2021-09-05",
    lastActiveAt: "2025-12-28",
    location: "Pasig",
  },
  {
    id: 5,
    firstName: "Erika",
    lastName: "Del Rosario",
    email: "erika.delrosario@solespace.com",
    phone: "+63 927 444 1199",
    department: "Marketing",
    position: "Campaign Lead",
    status: "active",
    hiredAt: "2024-05-18",
    lastActiveAt: "2026-01-20",
    location: "Taguig",
  },
  {
    id: 6,
    firstName: "Paolo",
    lastName: "Dizon",
    email: "paolo.dizon@solespace.com",
    phone: "+63 918 555 7621",
    department: "Finance",
    position: "Accounting Associate",
    status: "suspended",
    suspensionReason: "Misconduct",
    hiredAt: "2020-02-10",
    lastActiveAt: "2025-10-03",
    location: "Mandaluyong",
  },
];

const formatDate = (value?: string) => {
  if (!value) return "Never";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString();
};

const buildName = (employee: Employee) => `${employee.firstName} ${employee.lastName}`;

const canonicalEmployeeStatus = (value: unknown): EmployeeStatus => {
  switch (String(value ?? '').trim().toLowerCase()) {
    case 'active':
      return 'active';
    case 'inactive':
      return 'inactive';
    case 'suspended':
      return 'suspended';
    case 'terminated':
      return 'terminated';
    case 'on_leave':
    case 'on-leave':
    case 'probation':
      return 'active';
    default:
      return 'inactive';
  }
};

const parseLinkedUserId = (linkedUser?: string | number) => {
  const numericValue = Number(linkedUser ?? 0);
  return Number.isFinite(numericValue) && numericValue > 0 ? numericValue : null;
};

const isRepairerRole = (roleValue?: string) => (roleValue || '').trim().toLowerCase() === 'repairer';
const isCashierRole = (roleValue?: string) => (roleValue || '').trim().toLowerCase() === 'cashier';

const transformEmploymentPeriodFromApi = (period: any): EmploymentPeriod => ({
  id: Number(period.id),
  startDate: period.start_date || period.startDate,
  endDate: period.end_date || period.endDate || null,
  endReason: period.end_reason || period.endReason || null,
  position: period.position || null,
  department: period.department || null,
  role: period.role || null,
  salary: period.salary ?? null,
});

// Transform snake_case API response to camelCase for frontend
const transformEmployeeFromApi = (apiEmployee: any): Employee => {
  // Handle name splitting if first_name/last_name are missing but name exists
  let firstName = apiEmployee.first_name || apiEmployee.firstName || "";
  let lastName = apiEmployee.last_name || apiEmployee.lastName || "";
  
  if (!firstName && !lastName && apiEmployee.name) {
    const nameParts = apiEmployee.name.trim().split(/\s+/);
    if (nameParts.length > 1) {
      firstName = nameParts[0];
      lastName = nameParts.slice(1).join(' ');
    } else {
      firstName = apiEmployee.name;
      lastName = "";
    }
  }
  
  const projection = apiEmployee.owner_projection || apiEmployee.ownerProjection || {};
  const rawStatus = String(apiEmployee.status ?? '').trim().toLowerCase();
  const legacyOnLeave = rawStatus === 'on_leave' || rawStatus === 'on-leave';
  const employmentPeriods = apiEmployee.employment_periods || apiEmployee.employmentPeriods;

  return {
    id: apiEmployee.id,
    firstName: firstName,
    lastName: lastName,
    email: apiEmployee.email,
    phone: apiEmployee.phone,
    department: apiEmployee.department,
    position: apiEmployee.position,
    status: canonicalEmployeeStatus(apiEmployee.status),
    onLeave: Boolean(projection.on_leave ?? projection.onLeave ?? apiEmployee.on_leave ?? apiEmployee.onLeave ?? legacyOnLeave),
    probation: Boolean(projection.probation ?? apiEmployee.probation ?? rawStatus === 'probation'),
    suspensionReason: apiEmployee.suspension_reason || apiEmployee.suspensionReason,
    hiredAt: apiEmployee.hire_date || apiEmployee.hiredAt || apiEmployee.created_at,
    lastActiveAt: apiEmployee.last_active_at || apiEmployee.lastActiveAt || apiEmployee.updated_at,
    location: apiEmployee.location || apiEmployee.address,
    createdBy: apiEmployee.created_by || apiEmployee.createdBy,
    linkedUser: apiEmployee.linked_user || apiEmployee.linkedUser,
    terminatedAt: apiEmployee.terminated_at || apiEmployee.terminatedAt,
    employmentHistory: Array.isArray(employmentPeriods)
      ? employmentPeriods.map(transformEmploymentPeriodFromApi)
      : undefined,
  };
};

export const EmployeeManagement: React.FC<{
  employees?:
    | Employee[]
    | {
        data: Employee[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
      };
}> = ({ employees }) => {
  // Get page props for flash messages
  const pageProps = usePage().props as any;
  const flash = pageProps.flash;
  
  // Get shop owner data from auth for business type filtering
  const auth = pageProps.auth;
  const ownerMode = auth?.erpActor?.ownerMode === true;
  const ownerReadOnly = ownerMode;
  const ownerCanCreate = ownerMode;
  const employeeApiBase = ownerMode ? '/shop-owner/employees' : '/api/hr/employees';
  const invitationApiBase = ownerMode ? '/api/shop-owner/employees' : '/api/hr/employees';
  const shopOwner = auth?.shop_owner || auth?.user?.shop_owner || pageProps?.shop_owner;
  const isCompanyShop = String(
    shopOwner?.registration_type
    ?? auth?.registration_type
    ?? auth?.user?.registration_type
    ?? ''
  ).toLowerCase().trim() === 'company';
  const rawBusinessType = String(
    shopOwner?.business_type
    ?? auth?.business_type
    ?? auth?.user?.business_type
    ?? ''
  ).toLowerCase().trim();
  const normalizedBusinessType = rawBusinessType.includes('both')
    ? 'both'
    : rawBusinessType.includes('repair') && rawBusinessType.includes('retail')
      ? 'both'
      : rawBusinessType.includes('repair')
        ? 'repair'
        : rawBusinessType.includes('retail')
          ? 'retail'
          : rawBusinessType;
  const isRepairCapableBusiness = normalizedBusinessType === 'repair' || normalizedBusinessType === 'both';
  const isRetailCapableBusiness = normalizedBusinessType === 'retail' || normalizedBusinessType === 'both';
  const isCashierCapableBusiness = isRepairCapableBusiness || isRetailCapableBusiness;
  const currentUserId = Number(auth?.user?.id ?? 0);
  const currentUserEmail = String(auth?.user?.email ?? '').trim().toLowerCase();
  const actorRole = String(auth?.user?.role ?? '').trim().toLowerCase();
  const actorRoles = Array.isArray(auth?.user?.roles)
    ? auth.user.roles.map((role: unknown) => String(role).trim().toLowerCase())
    : [];
  const actorPermissions = [
    ...(Array.isArray(auth?.permissions) ? auth.permissions : []),
    ...(Array.isArray(auth?.user?.permissions) ? auth.user.permissions : []),
  ].map((permission: unknown) => String(permission).trim());
  const canRequestEmployeeLifecycle = isCompanyShop && !ownerMode && (
    actorRole === 'hr'
    || actorRoles.includes('hr')
    || actorPermissions.includes('request-employee-terminations')
    || actorPermissions.includes('request-employee-rehires')
  );
  
  // Check for flash data with employee credentials
  const success = pageProps.success || flash?.success;
  const invite_url = pageProps.invite_url || flash?.invite_url;
  const invite_expires_at = pageProps.invite_expires_at || flash?.invite_expires_at;
  const email_sent = pageProps.email_sent || flash?.email_sent;
  const employee = pageProps.employee || flash?.employee;
  
  // detect server paginator shape: { data: [...], meta: { current_page, last_page, per_page, total } }
  const isServerPaginated = !!(
    employees && (employees as any).data !== undefined && (employees as any).meta !== undefined
  );
  const serverData = isServerPaginated ? (employees as any).data as Employee[] : undefined;
  const serverMeta = isServerPaginated ? (employees as any).meta : undefined;

  const [filterStatus, setFilterStatus] = useState<EmployeeStatus | "all">("all");
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [isLoadingData, setIsLoadingData] = useState(false);
  const [summaryStats, setSummaryStats] = useState<EmployeeSummaryStats | null>(null);

  // rows are seeded from server page or client-provided array (or fallback to empty for now)
  const [rows, setRows] = useState<Employee[]>(() => {
    if (isServerPaginated && serverData) return serverData;
    if (Array.isArray(employees) && employees.length > 0) return employees as Employee[];
    return [];
  });

  // Pagination: use server meta when paginated, otherwise client-side state
  const [currentPage, setCurrentPage] = useState<number>(() => (isServerPaginated && serverMeta ? serverMeta.current_page : 1));
  const [itemsPerPage, setItemsPerPage] = useState<number>(() => (isServerPaginated && serverMeta ? serverMeta.per_page : 7));
  const [paginationMeta, setPaginationMeta] = useState<any>(serverMeta);

  const fetchEmployeeStats = async (): Promise<EmployeeSummaryStats | null> => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch('/api/hr/employees/statistics', {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
        },
        credentials: 'include',
      });

      if (!response.ok) {
        return null;
      }

      const data = await response.json();

      return {
        total: Number(data?.totalEmployees ?? 0),
        active: Number(data?.activeEmployees ?? 0),
        onLeave: Number(data?.onLeaveEmployees ?? 0),
        probation: Number(data?.probationEmployees ?? 0),
      };
    } catch {
      return null;
    }
  };

  // Fetch employees from API when component mounts or filters change
  useEffect(() => {
    if (ownerMode) return;

    const fetchEmployees = async () => {
      setIsLoadingData(true);
      try {
        const params = new URLSearchParams();
        if (searchTerm) params.append('search', searchTerm);
        if (filterStatus !== 'all') params.append('status', filterStatus);
        params.append('page', String(currentPage));
        params.append('per_page', String(itemsPerPage));

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const response = await fetch(`/api/hr/employees?${params.toString()}`, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
          },
          credentials: 'include',
        });

        if (!response.ok) {
          const errorText = await response.text();
          console.error('API Error:', response.status, errorText);
          throw new Error(`Failed to fetch employees: ${response.status}`);
        }

        const data = await response.json();
        
        // Check if response has Laravel pagination structure
        if (data.data && data.current_page) {
          const transformedData = data.data.map(transformEmployeeFromApi);
          setRows(transformedData);
          setPaginationMeta({
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
          });
        } else {
          const transformedData = Array.isArray(data) ? data.map(transformEmployeeFromApi) : [];
          setRows(transformedData);
        }

        const stats = await fetchEmployeeStats();
        if (stats) {
          setSummaryStats(stats);
        }
      } catch (error) {
        console.error('Error fetching employees:', error);
        setRows([]);
      } finally {
        setIsLoadingData(false);
      }
    };

    // Only fetch if no employees prop was provided
    if (!employees || (Array.isArray(employees) && employees.length === 0)) {
      fetchEmployees();
    }
  }, [searchTerm, filterStatus, currentPage, itemsPerPage, ownerMode]);

  // keep component in sync when parent provides new employees (server or client)
  useEffect(() => {
    if (isServerPaginated && serverData) {
      setRows(serverData);
      setCurrentPage(serverMeta?.current_page ?? 1);
      setItemsPerPage(serverMeta?.per_page ?? itemsPerPage);
      setPaginationMeta(serverMeta);
    } else if (Array.isArray(employees) && employees.length > 0) {
      setRows(employees as Employee[]);
      setCurrentPage(1);
      setItemsPerPage(7);
    }
  }, [employees]);

  // debounce ref for server-side queries
  const debounceRef = useRef<number | null>(null);

  // helper to navigate while preserving query params
  const navigateWithQuery = (params: Record<string, any>) => {
    const query: Record<string, any> = {};
    if (params.q !== undefined) query.q = params.q;
    if (params.status !== undefined) query.status = params.status;
    if (params.page !== undefined) query.page = params.page;
    // Use Inertia to request the current path with new query params
    Inertia.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true });
  };

  // When server paginated, debounce search & filter to call server
  useEffect(() => {
    if (!isServerPaginated) return;
    if (debounceRef.current) window.clearTimeout(debounceRef.current);
    debounceRef.current = window.setTimeout(() => {
      navigateWithQuery({ q: searchTerm || undefined, status: filterStatus !== "all" ? filterStatus : undefined, page: 1 });
    }, 400) as unknown as number;
    return () => {
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchTerm, filterStatus, isServerPaginated]);
  
  // Add employee modal state
  const [isAddEmployeeOpen, setIsAddEmployeeOpen] = useState(false);
  const [addEmployeeForm, setAddEmployeeForm] = useState({
    firstName: "",
    lastName: "",
    email: "",
    phone: "",
    department: "",
    position: "",
    hiredAt: new Date().toISOString().split("T")[0],
    location: "",
    salary: "",
  });
  // UI state for in-flight requests / errors
  const [isProcessingId, setIsProcessingId] = useState<number | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [apiError, setApiError] = useState<string | null>(null);
  const [addEmployeeEmailValidation, setAddEmployeeEmailValidation] = useState<FieldValidationState>({ status: "idle", message: "" });
  const [addEmployeePhoneValidation, setAddEmployeePhoneValidation] = useState<FieldValidationState>({ status: "idle", message: "" });
  const addEmployeeEmailRequestRef = useRef(0);
  const addEmployeePhoneRequestRef = useRef(0);

  // Suspension Request modal state
  const [isSuspensionRequestModalOpen, setIsSuspensionRequestModalOpen] = useState(false);
  const [employeeToSuspend, setEmployeeToSuspend] = useState<Employee | null>(null);
  const [suspensionRequestForm, setSuspensionRequestForm] = useState({
    reason: "",
    evidence: "",
  });
  const [isTerminationRequestModalOpen, setIsTerminationRequestModalOpen] = useState(false);
  const [employeeToTerminate, setEmployeeToTerminate] = useState<Employee | null>(null);
  const [terminationRequestForm, setTerminationRequestForm] = useState<LifecycleRequestForm>({
    reason: "",
    evidence: "",
  });
  const [isRehireRequestModalOpen, setIsRehireRequestModalOpen] = useState(false);
  const [employeeToRehire, setEmployeeToRehire] = useState<Employee | null>(null);
  const [rehireRequestForm, setRehireRequestForm] = useState<RehireRequestForm>({
    reason: "",
    evidence: "",
    rehireStartDate: "",
    rehirePosition: "",
    rehireDepartment: "",
    rehireSalary: "",
    rehireRole: "",
  });
  
  // Position Templates State
  const [positionTemplates, setPositionTemplates] = useState<Array<{
    id: number;
    name: string;
    slug: string;
    description: string;
    category: string;
  }>>([]);

  // Permission Management State
  const [isPermissionModalOpen, setIsPermissionModalOpen] = useState(false);
  const [selectedEmployeeForPermissions, setSelectedEmployeeForPermissions] = useState<Employee | null>(null);
  const [availablePermissions, setAvailablePermissions] = useState<{
    all: string[];
    grouped: {
      finance: string[];
      hr: string[];
      crm: string[];
      manager: string[];
      cashier: string[];
      repairer: string[];
      inventory: string[];
      procurement: string[];
      staff: string[];
    };
    roles: Array<{ name: string; permissions: string[] }>;
  } | null>(null);
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([]);
  const [isSavingPermissions, setIsSavingPermissions] = useState(false);
  const [availableRoles, setAvailableRoles] = useState<Array<{ name: string; permissions: string[] }>>([]);
  const [selectedAdditionalRoles, setSelectedAdditionalRoles] = useState<string[]>([]);
  const [isSavingRoles, setIsSavingRoles] = useState(false);
  const [expandedCategories, setExpandedCategories] = useState<{
    finance: boolean;
    hr: boolean;
    crm: boolean;
    manager: boolean;
    cashier: boolean;
    repairer: boolean;
    inventory: boolean;
    procurement: boolean;
    staff: boolean;
  }>({
    finance: true,
    hr: true,
    crm: true,
    manager: false,
    cashier: false,
    repairer: false,
    inventory: false,
    procurement: false,
    staff: false,
  });

  const [invitationModal, setInvitationModal] = useState<{
    isOpen: boolean;
    employeeId: number | null;
    employeeUserId: number | null;
    employeeName: string;
    workEmail: string;
    inviteUrl: string;
    expiresAt: string;
    showRegeneratedNote: boolean;
    copied: boolean;
    personalEmail: string;
    isSendingEmail: boolean;
  }>({
    isOpen: false,
    employeeId: null,
    employeeUserId: null,
    employeeName: "",
    workEmail: "",
    inviteUrl: "",
    expiresAt: "N/A",
    showRegeneratedNote: false,
    copied: false,
    personalEmail: "",
    isSendingEmail: false,
  });

  const openInvitationModal = (payload: {
    employeeId?: number | null;
    employeeUserId?: number | null;
    employeeName: string;
    workEmail: string;
    inviteUrl: string;
    inviteExpiresAt?: string;
    showRegeneratedNote?: boolean;
  }) => {
    setInvitationModal({
      isOpen: true,
      employeeId: payload.employeeId ?? null,
      employeeUserId: payload.employeeUserId ?? null,
      employeeName: payload.employeeName,
      workEmail: payload.workEmail,
      inviteUrl: payload.inviteUrl,
      expiresAt: payload.inviteExpiresAt ? new Date(payload.inviteExpiresAt).toLocaleString() : "N/A",
      showRegeneratedNote: !!payload.showRegeneratedNote,
      copied: false,
      personalEmail: "",
      isSendingEmail: false,
    });
  };

  const closeInvitationModal = () => {
    setInvitationModal((prev) => ({ ...prev, isOpen: false, personalEmail: "", isSendingEmail: false }));
  };

  const copyInvitationLink = async () => {
    try {
      await navigator.clipboard.writeText(invitationModal.inviteUrl);
      setInvitationModal((prev) => ({ ...prev, copied: true }));
    } catch {
      Swal.fire({
        icon: 'error',
        title: 'Copy Failed',
        text: 'Unable to copy invitation link. Please copy it manually.',
        timer: 1800,
      });
    }
  };

  const sendInvitationToPersonalEmail = async () => {
    const invitationTargetId = ownerMode
      ? invitationModal.employeeUserId
      : invitationModal.employeeId;

    if (!invitationTargetId) {
      Swal.fire({ icon: 'error', title: 'Missing Employee', text: 'Employee identifier is not available.' });
      return;
    }

    const personalEmail = invitationModal.personalEmail.trim();
    if (!personalEmail) {
      Swal.fire({ icon: 'warning', title: 'Email Required', text: 'Please enter a personal email address.' });
      return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(personalEmail)) {
      Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid personal email address.' });
      return;
    }

    if (personalEmail.toLowerCase() === invitationModal.workEmail.toLowerCase()) {
      Swal.fire({ icon: 'warning', title: 'Use Personal Email', text: 'Please use a personal email, not the work email.' });
      return;
    }

    try {
      setInvitationModal((prev) => ({ ...prev, isSendingEmail: true }));
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const requestBody = JSON.stringify({
        personal_email: personalEmail,
      });
      const requestHeaders = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrf || ''
      };

      const emailResponse = await fetch(`${invitationApiBase}/${invitationTargetId}/send-invitation-email`, {
        method: 'POST',
        headers: requestHeaders,
        credentials: 'include',
        body: requestBody,
      });

      if (!emailResponse.ok) {
        const errorData = await emailResponse.json().catch(() => ({}));
        throw new Error(errorData.error || errorData.message || 'Failed to send email');
      }

      Swal.fire({
        icon: 'success',
        title: 'Email Sent!',
        text: `Invitation email was sent to ${personalEmail}`,
        timer: 1800,
        showConfirmButton: false,
      });
      setInvitationModal((prev) => ({ ...prev, isSendingEmail: false }));
    } catch (error) {
      setInvitationModal((prev) => ({ ...prev, isSendingEmail: false }));
      Swal.fire({
        icon: 'error',
        title: 'Failed to Send Email',
        text: error instanceof Error ? error.message : 'An unexpected error occurred',
      });
    }
  };

  useEffect(() => {
    if (!invitationModal.isOpen || !invitationModal.inviteUrl) return;
    navigator.clipboard.writeText(invitationModal.inviteUrl)
      .then(() => {
        setInvitationModal((prev) => ({ ...prev, copied: true }));
      })
      .catch(() => {});
  }, [invitationModal.isOpen, invitationModal.inviteUrl]);

  const showInsufficientPermissionModal = async (message?: string) => {
    await Swal.fire({
      icon: 'warning',
      title: 'Insufficient Permission',
      html: `
        <p class="mb-2">You don't have permission to perform this action.</p>
        <p class="text-sm text-gray-600">${message || 'Please contact your manager or shop owner to request access.'}</p>
      `,
      confirmButtonColor: '#f59e0b',
    });
  };

  // Fetch position templates on component mount
  useEffect(() => {
    const fetchPositionTemplates = async () => {
      try {
        const response = await fetch(
          ownerMode ? '/shop-owner/position-templates' : '/api/hr/position-templates',
          {
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          },
          credentials: 'include'
          },
        );
        
        if (response.ok) {
          const data = await response.json();
          setPositionTemplates(data.templates || data || []);
        }
      } catch (error) {
        console.error('Failed to fetch position templates:', error);
      }
    };
    
    const fetchPermissions = async () => {
      try {
        const response = await fetch(
          ownerMode ? '/shop-owner/permissions/available' : '/api/hr/permissions/available',
          {
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          },
          credentials: 'include'
          },
        );

        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));

          if (response.status === 403) {
            await showInsufficientPermissionModal(errorData.error || errorData.message);
            return;
          }

          if (response.status === 401) {
            throw new Error('Your session has expired. Please log in again.');
          }

          throw new Error(errorData.error || errorData.message || 'Failed to fetch permissions');
        }
        
        if (response.ok) {
          const data = await response.json();
          const allPermissions = Array.isArray(data?.all)
            ? data.all.filter((permission: unknown): permission is string => typeof permission === 'string')
            : [];

          const groupedCashier = Array.isArray(data?.grouped?.cashier)
            ? data.grouped.cashier.filter((permission: unknown): permission is string => typeof permission === 'string')
            : [];

          const derivedCashier = allPermissions.filter(
            (permission: string) =>
              permission.startsWith('access-cashier-')
              || permission.includes('unified-pos')
              || permission.includes('unified_pos')
          );

          const normalizedPermissions = {
            ...data,
            grouped: {
              ...(data?.grouped || {}),
              cashier: groupedCashier.length > 0 ? groupedCashier : derivedCashier,
            },
          };

          setAvailablePermissions(normalizedPermissions);
          if (data.roles) {
            setAvailableRoles(data.roles);
          }
        }
      } catch (error: any) {
        console.error('Failed to fetch permissions:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: error?.message || 'Failed to fetch permissions',
          timer: 2000,
        });
      }
    };
    
    fetchPositionTemplates();
    fetchPermissions();
  }, [ownerMode]);

  // Check for flash data with employee invitation after successful creation
  useEffect(() => {
    if (!success || !invite_url) return;
    openInvitationModal({
      employeeId: employee?.id ?? null,
      employeeUserId: parseLinkedUserId(employee?.linked_user || employee?.linkedUser),
      employeeName: employee?.name || 'Employee',
      workEmail: employee?.email || 'N/A',
      inviteUrl: invite_url,
      inviteExpiresAt: invite_expires_at,
      showRegeneratedNote: false,
    });
  }, [success, invite_url, invite_expires_at, employee]);

  const stats = useMemo(() => {
    const meta = paginationMeta || serverMeta;

    if (summaryStats) {
      return {
        total: summaryStats.total,
        active: summaryStats.active,
        onLeave: summaryStats.onLeave,
        probation: summaryStats.probation,
      };
    }

    const total = (isServerPaginated || meta) ? (meta?.total ?? rows.length) : rows.length;
    const active = rows.filter((r) => r.status === "active").length;
    const onLeave = rows.filter((r) => r.onLeave).length;
    const probation = rows.filter((r) => r.probation).length;
    return {
      total,
      active,
      onLeave,
      probation,
    };
  }, [rows, serverMeta, paginationMeta, isServerPaginated, summaryStats]);

  const filteredEmployees = useMemo(() => {
    if (isServerPaginated) return rows; // server already applied filters
    const term = searchTerm.trim().toLowerCase();
    return rows.filter((employee) => {
      const matchesSearch =
        term.length === 0 ||
        buildName(employee).toLowerCase().includes(term) ||
        employee.email.toLowerCase().includes(term) ||
        employee.department.toLowerCase().includes(term);

      const matchesStatus = filterStatus === "all" ? true : employee.status === filterStatus;
      return matchesSearch && matchesStatus;
    });
  }, [rows, filterStatus, searchTerm, isServerPaginated]);

  // Pagination calculations
  const meta = paginationMeta || serverMeta;
  const usePagination = isServerPaginated || paginationMeta;
  const totalPages = usePagination ? meta?.last_page ?? 1 : Math.max(1, Math.ceil(filteredEmployees.length / itemsPerPage));
  const startIndex = usePagination ? ((meta?.current_page ?? 1) - 1) * (meta?.per_page ?? itemsPerPage) : (currentPage - 1) * itemsPerPage;
  const endIndex = usePagination ? startIndex + rows.length : startIndex + itemsPerPage;
  const paginatedEmployees = usePagination ? rows : filteredEmployees.slice(startIndex, endIndex);

  // Reset to page 1 when filters change (client-side only)
  React.useEffect(() => {
    if (!isServerPaginated) setCurrentPage(1);
  }, [searchTerm, filterStatus, isServerPaginated]);

  const handleNavigatePage = (page: number) => {
    if (isServerPaginated) {
      navigateWithQuery({ q: searchTerm || undefined, status: filterStatus !== "all" ? filterStatus : undefined, page });
    } else {
      setCurrentPage(page);
    }
  };

  const displayedPage = usePagination ? meta?.current_page ?? currentPage : currentPage;
  const totalItems = usePagination ? meta?.total ?? filteredEmployees.length : filteredEmployees.length;

  const openViewModal = (employee: Employee) => {
    setSelectedEmployee(employee);
    setIsViewModalOpen(true);
  };

  const handleActivate = (employeeId: number, name: string) => {
    Swal.fire({
      title: "Reactivate Account?",
      text: `Activate ${name}?`,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#16a34a",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, activate",
    }).then((result) => {
        if (result.isConfirmed) {
          (async () => {
            try {
              setIsProcessingId(employeeId);
              setApiError(null);
              
              const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
              const response = await fetch(`${employeeApiBase}/${employeeId}/activate`, {
                method: 'POST',
                headers: {
                  'Accept': 'application/json',
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
                },
                credentials: 'include',
                body: JSON.stringify({}),
              });
              
              if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: 'Failed to reactivate account' }));
                throw new Error(errorData.message || 'Failed to reactivate account');
              }
              
              const apiResponse = await response.json();
              const updatedEmployee = transformEmployeeFromApi(apiResponse.employee || apiResponse.data || apiResponse);
              setRows((prev) => prev.map((row) => (row.id === employeeId ? updatedEmployee : row)));
              Swal.fire({ title: "Reactivated", text: `${name} is now active.`, icon: "success", timer: 1400, showConfirmButton: false });
              setIsViewModalOpen(false);
              setSelectedEmployee(null);
            } catch (e: any) {
              setApiError(e?.message || "Failed to reactivate account.");
              Swal.fire({ title: "Error", text: e?.message || "Failed to reactivate account.", icon: "error" });
            } finally {
              setIsProcessingId(null);
            }
          })();
        }
    });
  };

  const isSelfEmployeeAccount = (employee?: Employee | null): boolean => {
    if (!employee) return false;
    const employeeEmail = String(employee.email ?? '').trim().toLowerCase();
    const linkedUserId = Number(employee.linkedUser ?? 0);
    return (linkedUserId > 0 && linkedUserId === currentUserId)
      || (employeeEmail !== '' && employeeEmail === currentUserEmail);
  };

  const submitLifecycleRequest = async ({
    endpoint,
    employeeId,
    body,
    successTitle,
    successFallback,
    onSubmitted,
  }: {
    endpoint: string;
    employeeId: number;
    body: Record<string, unknown>;
    successTitle: string;
    successFallback: string;
    onSubmitted: () => void;
  }) => {
    if (isProcessingId === employeeId) return;

    try {
      setIsProcessingId(employeeId);
      setApiError(null);

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
        },
        credentials: 'include',
        body: JSON.stringify(body),
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        const validationMessage = data?.errors && typeof data.errors === 'object'
          ? Object.values(data.errors as Record<string, unknown>)
              .flatMap((value) => Array.isArray(value) ? value : [value])
              .filter((value): value is string => typeof value === 'string')
              .join(' ')
          : '';
        throw new Error(validationMessage || data?.message || 'Failed to submit employee lifecycle request.');
      }

      onSubmitted();
      await Swal.fire({
        icon: 'success',
        title: successTitle,
        text: data?.message || successFallback,
        confirmButtonColor: '#10b981',
      });
    } catch (error: unknown) {
      const message = error instanceof Error
        ? error.message
        : 'Failed to submit employee lifecycle request.';
      setApiError(message);
      await Swal.fire({
        title: 'Error',
        text: message,
        icon: 'error',
        confirmButtonColor: '#ef4444',
      });
    } finally {
      setIsProcessingId(null);
    }
  };

  const handleTerminateClick = (employee: Employee) => {
    if (!canRequestEmployeeLifecycle || employee.status === 'terminated') return;

    if (isSelfEmployeeAccount(employee)) {
      void Swal.fire({
        icon: 'info',
        title: 'Action Blocked',
        text: 'You cannot file a termination request for the account you are currently using.',
      });
      return;
    }

    setEmployeeToTerminate(employee);
    setTerminationRequestForm({ reason: '', evidence: '' });
    setIsViewModalOpen(false);
    setIsTerminationRequestModalOpen(true);
  };

  const handleTerminationRequestSubmit = async () => {
    const employee = employeeToTerminate;
    const reason = terminationRequestForm.reason.trim();
    if (!employee) return;

    if (reason.length < 3) {
      await Swal.fire({
        title: 'Reason Required',
        text: 'Please provide a termination reason with at least 3 characters.',
        icon: 'warning',
        confirmButtonColor: '#f59e0b',
      });
      return;
    }

    await submitLifecycleRequest({
      endpoint: '/api/hr/termination-requests',
      employeeId: employee.id,
      body: {
        employee_id: employee.id,
        reason,
        evidence: terminationRequestForm.evidence.trim() || null,
      },
      successTitle: 'Termination Request Submitted',
      successFallback: 'The request will be reviewed by the Manager, then the Company Shop Owner.',
      onSubmitted: () => {
        setIsTerminationRequestModalOpen(false);
        setEmployeeToTerminate(null);
        setTerminationRequestForm({ reason: '', evidence: '' });
      },
    });
  };

  const handleRehireClick = (employee: Employee) => {
    if (!canRequestEmployeeLifecycle || employee.status !== 'terminated') return;

    setEmployeeToRehire(employee);
    setRehireRequestForm({
      reason: '',
      evidence: '',
      rehireStartDate: '',
      rehirePosition: employee.position || '',
      rehireDepartment: employee.department || '',
      rehireSalary: '',
      rehireRole: '',
    });
    setIsViewModalOpen(false);
    setIsRehireRequestModalOpen(true);
  };

  const handleRehireRequestSubmit = async () => {
    const employee = employeeToRehire;
    const reason = rehireRequestForm.reason.trim();
    if (!employee) return;

    if (reason.length < 3 || !rehireRequestForm.rehireStartDate || !rehireRequestForm.rehirePosition.trim() || !rehireRequestForm.rehireRole.trim()) {
      await Swal.fire({
        title: 'Complete Rehire Details',
        text: 'Provide a reason, new start date, position, and role before submitting.',
        icon: 'warning',
        confirmButtonColor: '#f59e0b',
      });
      return;
    }

    await submitLifecycleRequest({
      endpoint: '/api/hr/rehire-requests',
      employeeId: employee.id,
      body: {
        employee_id: employee.id,
        reason,
        evidence: rehireRequestForm.evidence.trim() || null,
        rehire_start_date: rehireRequestForm.rehireStartDate,
        rehire_position: rehireRequestForm.rehirePosition.trim(),
        rehire_department: rehireRequestForm.rehireDepartment.trim() || null,
        rehire_salary: rehireRequestForm.rehireSalary.trim() || null,
        rehire_role: rehireRequestForm.rehireRole.trim(),
      },
      successTitle: 'Rehire Request Submitted',
      successFallback: 'The request will be reviewed by the Manager, then the Company Shop Owner.',
      onSubmitted: () => {
        setIsRehireRequestModalOpen(false);
        setEmployeeToRehire(null);
        setRehireRequestForm({
          reason: '',
          evidence: '',
          rehireStartDate: '',
          rehirePosition: '',
          rehireDepartment: '',
          rehireSalary: '',
          rehireRole: '',
        });
      },
    });
  };

  const handleSuspendClick = (employee: Employee) => {
    if (isSelfEmployeeAccount(employee)) {
      void Swal.fire({
        icon: 'info',
        title: 'Action Blocked',
        text: 'You cannot file a suspension request for the account you are currently using.',
      });
      return;
    }

    setEmployeeToSuspend(employee);
    setSuspensionRequestForm({ reason: "", evidence: "" });
    setIsSuspensionRequestModalOpen(true);
  };

  const handleSuspensionRequestSubmit = async () => {
    if (!suspensionRequestForm.reason.trim()) {
      Swal.fire({
        title: "Reason Required",
        text: "Please provide a reason for suspension.",
        icon: "warning",
        confirmButtonColor: "#ef4444",
      });
      return;
    }

    if (!employeeToSuspend) return;

    // Prevent multiple submissions
    if (isProcessingId === employeeToSuspend.id) {
      return;
    }

    try {
      setIsProcessingId(employeeToSuspend.id);
      setApiError(null);

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(
        ownerMode ? `${employeeApiBase}/${employeeToSuspend.id}/suspend` : '/api/hr/suspension-requests',
        {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
        },
        credentials: 'include',
        body: JSON.stringify(ownerMode
          ? { suspension_reason: suspensionRequestForm.reason.trim() }
          : {
              employee_id: employeeToSuspend.id,
              reason: suspensionRequestForm.reason.trim(),
              evidence: suspensionRequestForm.evidence.trim() || null,
            }),
        },
      );

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Failed to submit suspension request' }));
        throw new Error(errorData.message || 'Failed to submit suspension request');
      }

      const data = await response.json();

      setIsSuspensionRequestModalOpen(false);
      setEmployeeToSuspend(null);
      setSuspensionRequestForm({ reason: "", evidence: "" });

      Swal.fire({
        icon: 'success',
        title: ownerMode ? 'Employee Suspended' : 'Suspension Request Submitted',
        html: ownerMode
          ? `<p>${data.message || 'Employee suspended successfully.'}</p>`
          : `<p>${data.message || 'Request submitted successfully.'}</p><p class="text-sm text-gray-600 mt-2">The request will be reviewed by the manager, then forwarded to the shop owner for final approval.</p>`,
        confirmButtonColor: '#10b981',
      });
    } catch (e: any) {
      setApiError(e?.message || "Failed to submit suspension request.");
      Swal.fire({
        title: "Error",
        text: e?.message || "Failed to submit suspension request.",
        icon: "error",
        confirmButtonColor: '#ef4444',
      });
    } finally {
      setIsProcessingId(null);
    }
  };

  const handleAddEmployee = () => {
    setAddEmployeeEmailValidation({ status: "idle", message: "" });
    setAddEmployeePhoneValidation({ status: "idle", message: "" });
    setIsAddEmployeeOpen(true);
  };

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
    if (!isAddEmployeeOpen) {
      setAddEmployeeEmailValidation({ status: "idle", message: "" });
      return;
    }

    const normalizedEmail = addEmployeeForm.email.trim();
    if (!normalizedEmail) {
      setAddEmployeeEmailValidation({ status: "idle", message: "" });
      return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(normalizedEmail)) {
      setAddEmployeeEmailValidation({ status: "error", message: "Please enter a valid email address." });
      return;
    }

    const requestId = ++addEmployeeEmailRequestRef.current;
    setAddEmployeeEmailValidation({ status: "checking", message: "Checking email availability..." });

    const timer = window.setTimeout(async () => {
      const result = await checkEmailAvailability(normalizedEmail);
      if (requestId !== addEmployeeEmailRequestRef.current) {
        return;
      }

      if (result.available) {
        setAddEmployeeEmailValidation({ status: "valid", message: "" });
      } else {
        setAddEmployeeEmailValidation({ status: "error", message: result.message || "This email is already registered." });
      }
    }, 350);

    return () => window.clearTimeout(timer);
  }, [addEmployeeForm.email, isAddEmployeeOpen]);

  useEffect(() => {
    if (!isAddEmployeeOpen) {
      setAddEmployeePhoneValidation({ status: "idle", message: "" });
      return;
    }

    const normalizedPhone = addEmployeeForm.phone.replace(/\D/g, '').slice(0, 11);
    if (!normalizedPhone) {
      setAddEmployeePhoneValidation({ status: "idle", message: "" });
      return;
    }

    if (normalizedPhone.length < 11) {
      setAddEmployeePhoneValidation({ status: "error", message: "Phone number must be exactly 11 digits." });
      return;
    }

    const requestId = ++addEmployeePhoneRequestRef.current;
    setAddEmployeePhoneValidation({ status: "checking", message: "Checking phone number availability..." });

    const timer = window.setTimeout(async () => {
      const result = await checkPhoneAvailability(normalizedPhone);
      if (requestId !== addEmployeePhoneRequestRef.current) {
        return;
      }

      if (result.available) {
        setAddEmployeePhoneValidation({ status: "valid", message: "" });
      } else {
        setAddEmployeePhoneValidation({ status: "error", message: result.message || "This phone number is already registered." });
      }
    }, 350);

    return () => window.clearTimeout(timer);
  }, [addEmployeeForm.phone, isAddEmployeeOpen]);

  // Permission Management Functions
  const openPermissionModal = async (employee: Employee) => {
    const linkedUserId = parseLinkedUserId(employee.linkedUser);
    if (ownerMode && !linkedUserId) {
      await Swal.fire({
        icon: 'error',
        title: 'No User Account',
        text: 'This employee does not have a linked user account yet.',
      });
      return;
    }

    try {
      const response = await fetch(
        ownerMode
          ? `${employeeApiBase}/${linkedUserId}/permissions`
          : `${employeeApiBase}/${employee.id}`,
        {
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        credentials: 'include'
        },
      );

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));

        if (response.status === 403) {
          await showInsufficientPermissionModal(errorData.error || errorData.message);
          return;
        }

        if (response.status === 401) {
          throw new Error('Your session has expired. Please log in again.');
        }

        throw new Error(errorData.error || errorData.message || 'Failed to fetch employee details');
      }

      const data = await response.json();
      const userId = ownerMode ? data.userId : data.user_id;
      
      const employeeWithUser = {
        ...employee,
        userId,
        permissions: data.permissions || data.allPermissions || [],
        directPermissions: data.direct_permissions || data.directPermissions || [],
        rolePermissions: data.role_permissions || data.rolePermissions || [],
      };

      if (!employeeWithUser.userId) {
        Swal.fire({
          icon: 'error',
          title: 'No User Account',
          text: 'This employee does not have a user account yet. Please contact IT support.',
          timer: 3000
        });
        return;
      }

      setSelectedEmployeeForPermissions(employeeWithUser);
      setSelectedPermissions(data.direct_permissions || data.directPermissions || []);
      setSelectedAdditionalRoles(data.additional_roles || data.additionalRoles || []);
      setIsPermissionModalOpen(true);
    } catch (error: any) {
      console.error('Failed to open permission modal:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error?.message || 'Failed to load employee permissions',
        timer: 2000
      });
    }
  };

  const togglePermission = (permission: string) => {
    setSelectedPermissions(prev => {
      if (prev.includes(permission)) {
        return prev.filter(p => p !== permission);
      } else {
        return [...prev, permission];
      }
    });
  };

  const toggleCategory = (category: 'finance' | 'hr' | 'crm' | 'manager' | 'cashier' | 'repairer' | 'inventory' | 'procurement' | 'staff') => {
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
      repairer: true,
      inventory: true,
      procurement: true,
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
      repairer: false,
      inventory: false,
      procurement: false,
      staff: false,
    });
  };

  const addAllPermissions = () => {
    if (!availablePermissions) return;

    const rolePermissions = new Set(((selectedEmployeeForPermissions as any)?.rolePermissions || []) as string[]);
    const allPermissions = [
      ...(availablePermissions.grouped.finance || []),
      ...(availablePermissions.grouped.hr || []),
      ...(availablePermissions.grouped.crm || []),
      ...(availablePermissions.grouped.manager || []),
      ...(isCashierCapableBusiness ? (availablePermissions.grouped.cashier || []) : []),
      ...(availablePermissions.grouped.inventory || []),
      ...(availablePermissions.grouped.procurement || []),
      ...(isRepairCapableBusiness ? (availablePermissions.grouped.repairer || []) : []),
      ...(isRetailCapableBusiness ? (availablePermissions.grouped.staff || []) : []),
    ].filter((permission) => !rolePermissions.has(permission));

    setSelectedPermissions((prev) => Array.from(new Set([...prev, ...allPermissions])));
  };

  const clearAllPermissions = () => {
    setSelectedPermissions([]);
  };

  const handleResetEmployeePassword = async (employee: Employee) => {
    const employeeEmail = String(employee.email ?? '').trim().toLowerCase();
    const linkedUserId = Number(employee.linkedUser ?? 0);
    const isSelfAccount = (linkedUserId > 0 && linkedUserId === currentUserId) || (employeeEmail !== '' && employeeEmail === currentUserEmail);

    if (isSelfAccount) {
      await Swal.fire({
        icon: 'info',
        title: 'Action Blocked',
        text: 'You cannot reset the password of the account you are currently using.',
      });
      return;
    }

    const result = await Swal.fire({
      title: 'Reset employee password?',
      html: `This will invalidate the current password for <strong>${buildName(employee)}</strong> and generate a new account setup link.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, reset password',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
    });

    if (!result.isConfirmed) return;

    try {
      setIsProcessingId(employee.id);
      const invitationTargetId = ownerMode ? linkedUserId : employee.id;
      if (!invitationTargetId) {
        throw new Error('This employee does not have a linked user account.');
      }

      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(
        `${invitationApiBase}/${invitationTargetId}/${ownerMode ? 'regenerate-invite' : 'reset-password'}`,
        {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf || ''
        },
        credentials: 'include'
        },
      );

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data?.error || data?.message || 'Failed to reset employee password');
      }

      const inviteUrl = data.invite_url;
      const expiresAt = data.invite_expires_at ? new Date(data.invite_expires_at).toLocaleString() : 'N/A';

      await Swal.fire({
        icon: 'success',
        title: 'Password Reset Ready',
        html: `
          <div style="text-align:left;">
            <p style="margin-bottom:8px;">A new setup link was generated for <strong>${buildName(employee)}</strong>.</p>
            <p style="margin-bottom:8px;"><strong>Work email:</strong> ${employee.email}</p>
            <p style="margin-bottom:8px;"><strong>Expires:</strong> ${expiresAt}</p>
            <div style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;word-break:break-all;font-family:monospace;font-size:12px;">
              ${inviteUrl}
            </div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Copy Link',
        cancelButtonText: 'Done',
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#6b7280',
      }).then((copyResult) => {
        if (copyResult.isConfirmed && inviteUrl) {
          navigator.clipboard.writeText(inviteUrl).catch(() => {});
          Swal.fire({
            icon: 'success',
            title: 'Copied',
            text: 'Setup link copied to clipboard.',
            timer: 1500,
            showConfirmButton: false,
          });
        }
      });
    } catch (error: any) {
      Swal.fire({
        icon: 'error',
        title: 'Reset Failed',
        text: error?.message || 'Failed to reset employee password',
      });
    } finally {
      setIsProcessingId(null);
    }
  };

  // View/Resend Invitation Link
  const viewInvitationLink = async (employee: Employee) => {
    const employeeEmail = String(employee.email ?? '').trim().toLowerCase();
    const linkedUserId = Number(employee.linkedUser ?? 0);
    const isSelfAccount = (linkedUserId > 0 && linkedUserId === currentUserId) || (employeeEmail !== '' && employeeEmail === currentUserEmail);

    if (isSelfAccount) {
      await Swal.fire({
        icon: 'info',
        title: 'Action Blocked',
        text: 'You cannot reset the password of the account you are currently using.',
      });
      return;
    }

    try {
      const invitationTargetId = ownerMode ? linkedUserId : employee.id;
      if (!invitationTargetId) {
        throw new Error('This employee does not have a linked user account.');
      }

      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch(`${invitationApiBase}/${invitationTargetId}/regenerate-invite`, {
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
      openInvitationModal({
        employeeId: employee.id,
        employeeUserId: parseLinkedUserId(employee.linkedUser),
        employeeName: buildName(employee),
        workEmail: employee.email,
        inviteUrl: data.invite_url,
        inviteExpiresAt: data.invite_expires_at,
        showRegeneratedNote: true,
      });

    } catch (error) {
      console.error('Failed to get invitation link:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error instanceof Error ? error.message : 'Failed to get invitation link. Please try again.',
      });
    }
  };

  const toggleRole = (roleName: string) => {
    setSelectedAdditionalRoles(prev => {
      if (prev.includes(roleName)) {
        return prev.filter(r => r !== roleName);
      } else {
        return [...prev, roleName];
      }
    });
  };

  // View/Resend Invitation Link
  const addRolePermissions = (roleKey: 'finance' | 'hr' | 'crm' | 'manager' | 'cashier' | 'repairer' | 'inventory' | 'procurement' | 'staff') => {
    if (!availablePermissions || !availablePermissions.grouped[roleKey]) return;
    const inheritedPermissions = new Set(((selectedEmployeeForPermissions as any)?.rolePermissions || []) as string[]);
    const rolePermissions = availablePermissions.grouped[roleKey].filter((permission) => !inheritedPermissions.has(permission));
    const newPermissions = Array.from(new Set([...selectedPermissions, ...rolePermissions]));
    setSelectedPermissions(newPermissions);
  };

  const clearRolePermissions = (roleKey: 'finance' | 'hr' | 'crm' | 'manager' | 'cashier' | 'repairer' | 'inventory' | 'procurement' | 'staff') => {
    if (!availablePermissions || !availablePermissions.grouped[roleKey]) return;
    const inheritedPermissions = new Set(((selectedEmployeeForPermissions as any)?.rolePermissions || []) as string[]);
    const rolePermissions = availablePermissions.grouped[roleKey].filter((permission) => !inheritedPermissions.has(permission));
    const newPermissions = selectedPermissions.filter(p => !rolePermissions.includes(p));
    setSelectedPermissions(newPermissions);
  };

  const savePermissions = async () => {
    if (!selectedEmployeeForPermissions || !(selectedEmployeeForPermissions as any).userId) return;

    setIsSavingPermissions(true);

    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      // Guard against stale/invalid permission values so backend sync does not fail
      // when historical permissions no longer exist in the permissions table.
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

      let permissionsToSync = normalizePermissions(selectedPermissions);
      
      const postSyncPermissions = async (permissionsPayload: string[]) => {
        const syncUrl = `${employeeApiBase}/${(selectedEmployeeForPermissions as any).userId}/permissions/sync`;
        return fetch(syncUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf || ''
          },
          credentials: 'include',
          body: JSON.stringify({
            permissions: permissionsPayload
          })
        });
      };

      let response = await postSyncPermissions(permissionsToSync);

      // If backend reports invalid permission names, remove them and retry once.
      if (!response.ok && response.status === 422) {
        let invalidData: any = {};
        try {
          const contentType = response.headers.get('content-type');
          if (contentType?.includes('application/json')) {
            invalidData = await response.json();
          }
        } catch {
          // ignore parsing errors
        }

        const invalidPermissions = Array.isArray(invalidData?.invalid_permissions)
          ? invalidData.invalid_permissions.filter((p: unknown): p is string => typeof p === 'string')
          : [];

        if (invalidPermissions.length > 0) {
          const invalidSet = new Set(invalidPermissions);
          permissionsToSync = permissionsToSync.filter((permission) => !invalidSet.has(permission));
          setSelectedPermissions(permissionsToSync);
          response = await postSyncPermissions(permissionsToSync);
        }
      }

      if (!response.ok) {
        let errorData: any = {};
        
        try {
          const contentType = response.headers.get('content-type');
          if (contentType?.includes('application/json')) {
            errorData = await response.json();
          }
        } catch (e) {
          // If parsing fails, leave errorData empty
        }
        
        // Handle Laravel validation errors (422)
        if (response.status === 422 && errorData.errors) {
          const validationErrors = Object.values(errorData.errors)
            .flat()
            .join(', ');
          throw new Error(`Validation error: ${validationErrors}`);
        }
        
        // Check if it's a finance permission restriction error for managers
        if (response.status === 403 && errorData.forbidden_permissions) {
          Swal.fire({
            icon: 'error',
            title: 'Finance Access Restricted',
            html: `
              <p class="mb-3">Managers cannot be assigned finance permissions.</p>
              <p class="text-sm text-gray-600 mb-2">The following finance permissions were blocked:</p>
              <ul class="text-sm text-left bg-red-50 p-3 rounded list-disc list-inside">
                ${errorData.forbidden_permissions.map((p: string) => `<li class="text-red-700">${p}</li>`).join('')}
              </ul>
              <p class="text-sm text-gray-600 mt-3">Only the <strong>Shop Owner</strong> can access finance modules.</p>
            `,
            confirmButtonColor: '#ef4444'
          });
          return;
        }

        if (response.status === 403) {
          await showInsufficientPermissionModal(errorData.error || errorData.message);
          return;
        }

        if (response.status === 401) {
          throw new Error(errorData.error || errorData.message || 'Your session has expired. Please log in again.');
        }
        
        throw new Error(errorData.error || errorData.message || 'Failed to update permissions');
      }

      setIsPermissionModalOpen(false);
      
      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Permissions updated successfully',
        timer: 2000,
        showConfirmButton: false
      });

    } catch (error: any) {
      console.error('Failed to update permissions:', error);
      
      // Extract error message from various error types
      let errorMessage = 'Failed to update permissions. Please try again.';
      if (error instanceof Error && error.message) {
        errorMessage = error.message;
      } else if (typeof error === 'string') {
        errorMessage = error;
      } else if (error?.response?.data?.message) {
        errorMessage = error.response.data.message;
      } else if (error?.response?.data?.error) {
        errorMessage = error.response.data.error;
      }
      
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: errorMessage,
      });
    } finally {
      setIsSavingPermissions(false);
    }
  };

  const handleSaveNewEmployee = async () => {
    // Check required fields based on role
    const isManager = addEmployeeForm.department === 'Manager';
    const positionRequired = !isManager;

    if (!isRepairCapableBusiness && isRepairerRole(addEmployeeForm.department)) {
      Swal.fire({
        icon: 'warning',
        title: 'Role Not Allowed',
        text: 'Repairer accounts are only available for repair-capable businesses.',
        confirmButtonColor: '#f59e0b'
      });
      return;
    }

    if (!isRetailCapableBusiness && (addEmployeeForm.department || '').trim().toLowerCase() === 'staff') {
      Swal.fire({
        icon: 'warning',
        title: 'Role Not Allowed',
        text: 'Staff accounts are only available for retail-capable businesses.',
        confirmButtonColor: '#f59e0b'
      });
      return;
    }

    if (!isCashierCapableBusiness && isCashierRole(addEmployeeForm.department)) {
      Swal.fire({
        icon: 'warning',
        title: 'Role Not Allowed',
        text: 'Cashier accounts are only available for retail or repair-capable businesses.',
        confirmButtonColor: '#f59e0b'
      });
      return;
    }
    
    if (!addEmployeeForm.firstName || !addEmployeeForm.lastName || !addEmployeeForm.email || !addEmployeeForm.department) {
      Swal.fire({
        icon: 'error',
        title: 'Validation Error',
        text: 'Please fill in all required fields (First name, Last name, Email, Role)',
        confirmButtonColor: '#ef4444'
      });
      return;
    }

    // Email validation
    const trimmedEmail = addEmployeeForm.email.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(trimmedEmail)) {
      Swal.fire({
        title: "Invalid Email",
        text: "Please enter a valid email address.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    const normalizedPhone = addEmployeeForm.phone.replace(/\D/g, '').slice(0, 11);
    if (normalizedPhone && !/^\d{11}$/.test(normalizedPhone)) {
      Swal.fire({
        title: "Invalid Phone Number",
        text: "Phone number must be exactly 11 digits.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (addEmployeeEmailValidation.status === 'checking' || addEmployeePhoneValidation.status === 'checking') {
      Swal.fire({
        icon: 'info',
        title: 'Please wait',
        text: 'We are still checking email/phone availability.',
        timer: 2000,
        showConfirmButton: false,
      });
      return;
    }

    if (addEmployeeEmailValidation.status === 'error') {
      Swal.fire({
        icon: 'error',
        title: 'Email not available',
        text: addEmployeeEmailValidation.message || 'This email is already registered.',
        confirmButtonColor: '#ef4444'
      });
      return;
    }

    if (normalizedPhone && addEmployeePhoneValidation.status === 'error') {
      Swal.fire({
        icon: 'error',
        title: 'Phone number not available',
        text: addEmployeePhoneValidation.message || 'This phone number is already registered.',
        confirmButtonColor: '#ef4444'
      });
      return;
    }

    try {
      const availabilityData = await checkEmailAvailability(trimmedEmail);
      if (!availabilityData?.available) {
        Swal.fire({
          icon: 'error',
          title: 'Email not available',
          text: availabilityData?.message || 'This email is already registered.',
          confirmButtonColor: '#ef4444'
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
            confirmButtonColor: '#ef4444'
          });
          return;
        }
      }
    } catch {
      Swal.fire({
        icon: 'error',
        title: 'Email Check Failed',
        text: 'Unable to verify email right now. Please try again.',
        confirmButtonColor: '#ef4444'
      });
      return;
    }

    // Close modal FIRST to prevent blocking navigation
    setIsAddEmployeeOpen(false);
    setIsAdding(true);

    // Show confirmation dialog AFTER closing modal with small delay
    setTimeout(async () => {
      const result = await Swal.fire({
        title: 'Add Employee',
        text: `Are you sure you want to add ${addEmployeeForm.firstName} ${addEmployeeForm.lastName} as an employee in ${addEmployeeForm.department}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, add it!'
      });

      if (result.isConfirmed) {
        setIsAdding(true);
        
        try {
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
          const response = await fetch(employeeApiBase, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
            },
            credentials: 'include',
            body: JSON.stringify(ownerMode
              ? {
                  name: `${addEmployeeForm.firstName} ${addEmployeeForm.lastName}`.trim(),
                  email: trimmedEmail,
                  phone: normalizedPhone,
                  address: addEmployeeForm.location,
                  position: addEmployeeForm.position || 'General Staff',
                  department: addEmployeeForm.department || 'General',
                  role: addEmployeeForm.department || 'Staff',
                  salary: parseFloat(addEmployeeForm.salary) || 0,
                  hire_date: addEmployeeForm.hiredAt || new Date().toISOString().split('T')[0],
                  status: 'active',
                }
              : {
                  firstName: addEmployeeForm.firstName,
                  lastName: addEmployeeForm.lastName,
                  email: trimmedEmail,
                  phone: normalizedPhone,
                  position: addEmployeeForm.position || 'General Staff',
                  department: addEmployeeForm.department || 'General',
                  role: addEmployeeForm.department || 'Staff',
                  salary: parseFloat(addEmployeeForm.salary) || 0,
                  hireDate: addEmployeeForm.hiredAt || new Date().toISOString().split('T')[0],
                  location: addEmployeeForm.location,
                }),
          });

          if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'Failed to add employee' }));
            
            // Handle validation errors (422)
            if (errorData.errors && typeof errorData.errors === 'object') {
              const errorMessages = Object.entries(errorData.errors)
                .map(([field, messages]: [string, any]) => {
                  const msgs = Array.isArray(messages) ? messages : [messages];
                  return `${field}: ${msgs.join(', ')}`;
                })
                .join('\n');
              throw new Error(errorMessages || 'Validation failed');
            }
            
            throw new Error(errorData.message || 'Failed to add employee');
          }

          const data = await response.json();

          setIsAdding(false);
          
          // Reset form
          setAddEmployeeForm({ 
            firstName: "", 
            lastName: "", 
            email: "", 
            phone: "", 
            department: "", 
            position: "", 
            hiredAt: new Date().toISOString().split("T")[0], 
            location: "",
            salary: "",
          });

          // Add new employee to the list
          const newEmployee = transformEmployeeFromApi(data.employee || data);
          setRows(prev => [newEmployee, ...prev]);

          // Show success message with invitation link
          if (data.invite_url) {
            openInvitationModal({
              employeeId: newEmployee.id,
              employeeUserId: parseLinkedUserId(newEmployee.linkedUser),
              employeeName: `${newEmployee.firstName} ${newEmployee.lastName}`,
              workEmail: newEmployee.email,
              inviteUrl: data.invite_url,
              inviteExpiresAt: data.invite_expires_at,
              showRegeneratedNote: false,
            });
          } else {
            Swal.fire({
              icon: 'success',
              title: 'Success',
              text: 'Employee added successfully',
              timer: 2000,
              showConfirmButton: false
            });
          }
        } catch (error: any) {
          setIsAdding(false);
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to add employee',
            confirmButtonColor: '#ef4444'
          });
        }
      } else {
        setIsAdding(false);
      }
    }, 100);
  };

  const selectedEmployeeRolePermissions = (((selectedEmployeeForPermissions as any)?.rolePermissions || []) as string[]);
  const selectedEmployeeRoleName = ((selectedEmployeeForPermissions as any)?.roleName
    || selectedEmployeeForPermissions?.department
    || 'Staff') as string;

  const permissionCategoryConfigs = [
    {
      key: 'finance',
      label: 'Finance Module',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      ),
      show: true,
    },
    {
      key: 'hr',
      label: 'HR Module',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
      ),
      show: true,
    },
    {
      key: 'crm',
      label: 'Customer Relationship Management Module',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
      ),
      show: true,
    },
    {
      key: 'manager',
      label: 'Manager Permissions',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
      ),
      show: true,
    },
    {
      key: 'cashier',
      label: 'Cashier Permissions',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2v6a3 3 0 003 3h4a3 3 0 003-3v-6a2 2 0 00-2-2h-1m-8 0h8m-8 0V6a3 3 0 016 0v2" />
        </svg>
      ),
      show: isCashierCapableBusiness,
    },
    {
      key: 'inventory',
      label: 'Inventory Module',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
        </svg>
      ),
      show: true,
    },
    {
      key: 'procurement',
      label: 'Procurement Module',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
      ),
      show: true,
    },
    {
      key: 'repairer',
      label: 'Repairer Permissions',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="currentColor" viewBox="0 0 24 24">
          <path d="M21 6.5a4.5 4.5 0 01-6.36 4.09l-6.8 6.8a2 2 0 11-2.83-2.83l6.8-6.8A4.5 4.5 0 1116.5 3a4.49 4.49 0 014.5 3.5z" />
        </svg>
      ),
      show: isRepairCapableBusiness,
    },
    {
      key: 'staff',
      label: 'Staff Permissions',
      icon: (
        <svg className="w-5 h-5 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
      ),
      show: isRetailCapableBusiness,
    },
  ] as const;

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      <div className="w-full">
        <div className="flex justify-between items-start mb-8">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Employee Management</h1>
            <p className="text-gray-600 dark:text-gray-400">
              {ownerReadOnly
                ? 'Review your shop workforce and add employees without changing account permissions.'
                : 'Manage employee accounts, access, and lifecycle'}
            </p>
          </div>
          {(!ownerReadOnly || ownerCanCreate) && (
          <div className="flex gap-3">
            <button
              onClick={handleAddEmployee}
              disabled={isAdding}
              className={`inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 ${isAdding ? 'opacity-50 cursor-not-allowed' : ''}`}
            >
              <svg className="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              Add Employee
            </button>
          </div>
          )}
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
          <MetricCard
            title="Total Employees"
            value={stats.total}
            change={8}
            changeType="increase"
            icon={UserCircleIcon}
            color="info"
            description="All employee records"
          />
          <MetricCard
            title="Active"
            value={stats.active}
            change={3}
            changeType="increase"
            icon={CheckCircleIcon}
            color="success"
            description="Currently available to work"
          />
          <MetricCard
            title="On Leave"
            value={stats.onLeave}
            change={2}
            changeType="decrease"
            icon={CalendarIcon}
            color="warning"
            description="Temporarily unavailable"
          />
          <MetricCard
            title="Probation"
            value={stats.probation}
            change={1}
            changeType="increase"
            icon={BriefcaseIcon}
            color="error"
            description="New hires under review"
          />
        </div>

        <div className="bg-white dark:bg-gray-800 shadow-md rounded-lg">
          <div className="p-6 border-b border-gray-200 dark:border-gray-700">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
              <div className="flex-1 w-full sm:w-auto">
                <input
                  type="text"
                  placeholder="Search by name, email, or role..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                />
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setFilterStatus("all")}
                  className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    filterStatus === "all"
                      ? "bg-blue-600 text-white"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                  }`}
                >
                  All
                </button>
                <button
                  onClick={() => setFilterStatus("active")}
                  className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    filterStatus === "active"
                      ? "bg-green-600 text-white"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                  }`}
                >
                  Active
                </button>
                <button
                  onClick={() => setFilterStatus("inactive")}
                  className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    filterStatus === "inactive"
                      ? "bg-gray-800 text-white"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                  }`}
                >
                  Inactive
                </button>
                <button
                  onClick={() => setFilterStatus("suspended")}
                  className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    filterStatus === "suspended"
                      ? "bg-red-600 text-white"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                  }`}
                >
                  Suspended
                </button>
                <button
                  onClick={() => setFilterStatus("terminated")}
                  className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    filterStatus === "terminated"
                      ? "bg-red-800 text-white"
                      : "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                  }`}
                >
                  Terminated
                </button>
              </div>
            </div>
          </div>

          <div className="overflow-x-hidden">
            <table className="w-full table-fixed text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th className="w-[18%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                  <th className="w-[14%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                  <th className="w-[10%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role</th>
                  <th className="w-[15%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Position</th>
                  <th className="w-[7%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                  <th className="w-[7%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Active</th>
                  <th className="w-[10%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created By</th>
                  <th className="w-[8%] px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Account</th>
                  <th className="w-[220px] px-3 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {isLoadingData ? (
                  <tr>
                    <td colSpan={9} className="px-6 py-12 text-center">
                      <div className="flex flex-col items-center justify-center text-gray-500 dark:text-gray-400">
                        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-2"></div>
                        <p className="text-lg font-medium">Loading employees...</p>
                      </div>
                    </td>
                  </tr>
                ) : paginatedEmployees.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="px-6 py-12 text-center">
                      <div className="flex flex-col items-center justify-center text-gray-500 dark:text-gray-400">
                        <UserCircleIcon className="h-12 w-12 mb-2" />
                        <p className="text-lg font-medium">No employees found</p>
                        <p className="text-sm">Try adjusting your filters or search term</p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  paginatedEmployees.map((employee) => (
                    <tr key={employee.id} className="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                      <td className="px-3 py-3 align-top">
                        <div className="flex items-center">
                          <div className="flex-shrink-0 h-8 w-8">
                            <div className="h-8 w-8 rounded-full bg-gray-950 dark:bg-blue-900 flex items-center justify-center">
                              <span className="text-white dark:text-blue-300 font-medium text-xs">
                                {buildName(employee)
                                  .split(" ")
                                  .map((n) => n[0])
                                  .join("")
                                  .toUpperCase()}
                              </span>
                            </div>
                          </div>
                          <div className="ml-2 min-w-0">
                            <div className="text-sm font-medium text-gray-900 dark:text-white truncate" title={buildName(employee)}>{buildName(employee)}</div>
                            <div className="text-xs text-gray-500 dark:text-gray-400 truncate" title={ownerReadOnly ? 'Restricted' : (employee.location || "-")}>{ownerReadOnly ? 'Restricted' : (employee.location || "-")}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-3 py-3 align-top">
                        <div className="text-sm text-gray-900 dark:text-white truncate" title={ownerReadOnly ? 'Restricted' : employee.email}>{ownerReadOnly ? 'Restricted' : employee.email}</div>
                        {!ownerReadOnly && employee.phone && <div className="text-xs text-gray-500 dark:text-gray-400 truncate" title={employee.phone}>{employee.phone}</div>}
                      </td>
                      <td className="px-3 py-3 align-top">
                        <div className="text-sm text-gray-900 dark:text-white truncate" title={employee.department}>{employee.department}</div>
                      </td>
                      <td className="px-3 py-3 align-top">
                        <div className="text-sm text-gray-900 dark:text-white truncate" title={employee.position}>{employee.position}</div>
                        <div className="text-xs text-gray-500 dark:text-gray-400">Hired {formatDate(employee.hiredAt)}</div>
                      </td>
                      <td className="px-3 py-3 align-top">
                        <div className="flex flex-wrap gap-1">
                          <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${statusBadge(employee.status)}`}>
                            {statusLabel[employee.status]}
                          </span>
                          {employee.onLeave && (
                            <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                              On Leave
                            </span>
                          )}
                          {employee.probation && (
                            <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                              Probation
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-3 py-3 align-top text-sm text-gray-500 dark:text-gray-400">
                        {formatDate(employee.lastActiveAt)}
                      </td>
                      <td className="px-3 py-3 align-top text-sm text-gray-500 dark:text-gray-400 truncate" title={employee.createdBy || 'Direct Registration'}>
                        {employee.createdBy || 'Direct Registration'}
                      </td>
                      <td className="px-3 py-3 align-top text-sm text-gray-500 dark:text-gray-400">
                        {employee.linkedUser ? (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Linked</span>
                        ) : (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300">No Link</span>
                        )}
                      </td>
                      <td className="px-3 py-3 whitespace-nowrap text-right text-sm font-medium">
                        <div className="flex flex-wrap justify-end gap-2">
                          {!ownerReadOnly && (
                            <>
                          <button
                            onClick={() => handleResetEmployeePassword(employee)}
                            className={`text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300 ${(isProcessingId === employee.id || String(employee.email ?? '').trim().toLowerCase() === currentUserEmail) ? 'opacity-50 cursor-not-allowed' : ''}`}
                            title={String(employee.email ?? '').trim().toLowerCase() === currentUserEmail ? "You cannot reset your own account password" : "Reset Employee Password"}
                            disabled={isProcessingId === employee.id || String(employee.email ?? '').trim().toLowerCase() === currentUserEmail}
                          >
                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m6-10h-1V6a5 5 0 00-10 0v1H6a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V9a2 2 0 00-2-2zM9 7V6a3 3 0 016 0v1H9z" />
                            </svg>
                          </button>
                          <button
                            onClick={() => viewInvitationLink(employee)}
                            className={`text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 ${(isProcessingId === employee.id || String(employee.email ?? '').trim().toLowerCase() === currentUserEmail) ? 'opacity-50 cursor-not-allowed' : ''}`}
                            title={String(employee.email ?? '').trim().toLowerCase() === currentUserEmail ? "You cannot reset your own account password" : "View/Resend Invitation Link"}
                            disabled={isProcessingId === employee.id || String(employee.email ?? '').trim().toLowerCase() === currentUserEmail}
                          >
                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                          </button>
                            </>
                          )}
                          <button
                            onClick={() => openViewModal(employee)}
                            className={`text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 ${isProcessingId === employee.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                            title="View Details"
                            disabled={isProcessingId === employee.id}
                          >
                            <InfoIcon className="h-5 w-5" />
                          </button>
                          {['inactive', 'suspended'].includes(employee.status) && (
                            <Button
                              variant="success"
                              onClick={() => handleActivate(employee.id, buildName(employee))}
                              className="whitespace-nowrap px-3 py-2 text-xs"
                              disabled={isProcessingId === employee.id}
                            >
                              {isProcessingId === employee.id ? 'Processing...' : 'Activate Account'}
                            </Button>
                          )}
                          {canRequestEmployeeLifecycle && employee.status === 'terminated' && (
                            <Button
                              variant="primary"
                              onClick={() => handleRehireClick(employee)}
                              className="whitespace-nowrap px-3 py-2 text-xs"
                              disabled={isProcessingId === employee.id}
                            >
                              Request Rehire
                            </Button>
                          )}
                          {canRequestEmployeeLifecycle && employee.status !== 'terminated' && (
                            <Button
                              variant="danger"
                              onClick={() => handleTerminateClick(employee)}
                              className="whitespace-nowrap px-3 py-2 text-xs"
                              disabled={isProcessingId === employee.id || isSelfEmployeeAccount(employee)}
                            >
                              Request Termination
                            </Button>
                          )}
                          {!ownerReadOnly && (
                            <>
                          <button
                            onClick={() => openPermissionModal(employee)}
                            className={`text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300 ${isProcessingId === employee.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                            title="Manage Permissions"
                            disabled={isProcessingId === employee.id}
                          >
                            <LockIcon className="h-5 w-5" />
                          </button>
                          {!['inactive', 'suspended', 'terminated'].includes(employee.status) && (
                            <>
                              <button
                                onClick={() => handleSuspendClick(employee)}
                                className={`inline-flex items-center justify-center w-8 h-8 rounded-md text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300 transition-colors ${(isProcessingId === employee.id || isSelfEmployeeAccount(employee)) ? 'opacity-50 cursor-not-allowed' : ''}`}
                                title={isSelfEmployeeAccount(employee)
                                  ? 'You cannot suspend your own account'
                                  : ownerMode ? 'Suspend Employee' : 'File Suspension Request'}
                                disabled={isProcessingId === employee.id || isSelfEmployeeAccount(employee)}
                              >
                                <AlertIcon className="h-5 w-5" />
                              </button>
                            </>
                          )}
                          {/* Delete button removed per request */}
                            </>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalItems > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(endIndex, totalItems)}</span> of{" "}
                  <span className="font-medium">{totalItems}</span>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => handleNavigatePage(Math.max(displayedPage - 1, 1))}
                    disabled={displayedPage === 1}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Previous page"
                  >
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                  </button>

                  {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                    if (
                      page === 1 ||
                      page === totalPages ||
                      (page >= displayedPage - 1 && page <= displayedPage + 1)
                    ) {
                      return (
                        <button
                          key={page}
                          onClick={() => handleNavigatePage(page)}
                          className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
                            displayedPage === page
                              ? "bg-blue-600 text-white"
                              : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                          }`}
                        >
                          {page}
                        </button>
                      );
                    } else if (page === displayedPage - 2 || page === displayedPage + 2) {
                      return (
                        <span key={page} className="px-2 text-gray-500 dark:text-gray-400">
                          ...
                        </span>
                      );
                    }
                    return null;
                  })}

                  <button
                    onClick={() => handleNavigatePage(Math.min(displayedPage + 1, totalPages))}
                    disabled={displayedPage === totalPages}
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

        {isViewModalOpen && selectedEmployee && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
              <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-4xl w-full max-h-[95vh] overflow-auto">
                <div className="p-8">
                  <h3 className="text-3xl font-semibold text-gray-900 dark:text-white mb-6">Employee Details</h3>
                  <div className="space-y-5">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Name</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{buildName(selectedEmployee)}</p>
                    </div>
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Email</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{ownerReadOnly ? 'Restricted' : selectedEmployee.email}</p>
                    </div>
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Phone</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{ownerReadOnly ? 'Restricted' : (selectedEmployee.phone || "N/A")}</p>
                    </div>
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Role</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{selectedEmployee.department}</p>
                    </div>
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Position</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{selectedEmployee.position}</p>
                    </div>
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Location</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{ownerReadOnly ? 'Restricted' : (selectedEmployee.location || "N/A")}</p>
                    </div>
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Status</p>
                      <span className={`inline-flex px-2.5 py-1.5 text-sm font-semibold rounded-full ${statusBadge(selectedEmployee.status)}`}>
                        {statusLabel[selectedEmployee.status]}
                      </span>
                    </div>
                    {selectedEmployee.status === "suspended" && (
                      <div>
                        <p className="text-base text-gray-500 dark:text-gray-400">Suspension Reason</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{selectedEmployee.suspensionReason || "No reason provided."}</p>
                      </div>
                    )}
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Hired</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{formatDate(selectedEmployee.hiredAt)}</p>
                    </div>
                    {selectedEmployee.status === "terminated" && (
                      <div>
                        <p className="text-base text-gray-500 dark:text-gray-400">Employment Terminated</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{formatDate(selectedEmployee.terminatedAt)}</p>
                      </div>
                    )}
                    <div>
                      <p className="text-base text-gray-500 dark:text-gray-400">Last Active</p>
                      <p className="text-base font-medium text-gray-900 dark:text-white">{formatDate(selectedEmployee.lastActiveAt)}</p>
                    </div>
                    {selectedEmployee.employmentHistory && selectedEmployee.employmentHistory.length > 0 && (
                      <div className="md:col-span-2 border-t border-gray-200 dark:border-gray-700 pt-5">
                        <p className="text-base font-semibold text-gray-900 dark:text-white mb-3">Employment History</p>
                        <div className="space-y-3">
                          {selectedEmployee.employmentHistory.map((period) => (
                            <div key={period.id} className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/40 p-4">
                              <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-semibold text-gray-900 dark:text-white">
                                  {formatDate(period.startDate)} — {period.endDate ? formatDate(period.endDate) : 'Present'}
                                </p>
                                <span className="text-xs font-medium text-gray-600 dark:text-gray-300">
                                  {period.role || 'Role not recorded'}
                                </span>
                              </div>
                              <p className="text-sm text-gray-700 dark:text-gray-300 mt-1">
                                {period.position || 'Position not recorded'}
                                {period.department ? ' · ' + period.department : ''}
                              </p>
                              {period.endReason && (
                                <p className="text-xs text-gray-600 dark:text-gray-400 mt-2">
                                  Closed: {period.endReason}
                                </p>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                    <div className="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                      <div className="flex justify-end gap-2">
                        {['inactive', 'suspended'].includes(selectedEmployee.status) && (
                          <Button variant="success" onClick={() => handleActivate(selectedEmployee.id, buildName(selectedEmployee))} className="mr-2" disabled={isProcessingId === selectedEmployee.id}>
                            {isProcessingId === selectedEmployee.id ? 'Processing...' : 'Activate Account'}
                          </Button>
                        )}
                        {canRequestEmployeeLifecycle && selectedEmployee.status === 'terminated' && (
                          <Button
                            variant="primary"
                            onClick={() => handleRehireClick(selectedEmployee)}
                            disabled={isProcessingId === selectedEmployee.id}
                          >
                            Request Rehire
                          </Button>
                        )}
                        {canRequestEmployeeLifecycle && selectedEmployee.status !== 'terminated' && (
                          <Button
                            variant="danger"
                            onClick={() => handleTerminateClick(selectedEmployee)}
                            disabled={isProcessingId === selectedEmployee.id || isSelfEmployeeAccount(selectedEmployee)}
                          >
                            Request Termination
                          </Button>
                        )}
                        <Button variant="secondary" onClick={() => setIsViewModalOpen(false)}>
                          Close
                        </Button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </ModalPortal>
        )}

        {/* Suspension Request Modal */}
        {isSuspensionRequestModalOpen && employeeToSuspend && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-xl w-full">
                <div className="p-6">
                  <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">
                    {ownerMode ? 'Suspend Employee' : 'File Suspension Request'}
                  </h3>

                  <div className="mb-4">
                    <p className="text-sm text-gray-700 dark:text-gray-300 mb-1">
                      <span className="font-medium">Employee:</span> {buildName(employeeToSuspend)}
                    </p>
                    <p className="text-sm text-gray-700 dark:text-gray-300">
                      <span className="font-medium">Position:</span> {employeeToSuspend.position || 'N/A'}
                    </p>
                  </div>

                  <div className="mb-4">
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Reason for Suspension <span className="text-red-500">*</span>
                    </label>
                    <select
                      value={suspensionRequestForm.reason}
                      onChange={(e) => setSuspensionRequestForm({ ...suspensionRequestForm, reason: e.target.value })}
                      className="w-full px-4 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                    >
                      <option value="">Select a reason</option>
                      <option value="Poor Performance">Poor Performance</option>
                      <option value="Violation of Business Policy">Violation of Business Policy</option>
                      <option value="Insubordination">Insubordination</option>
                      <option value="Attendance Issues">Attendance Issues</option>
                      <option value="Misconduct">Misconduct</option>
                      <option value="Harassment">Harassment</option>
                      <option value="Theft or Fraud">Theft or Fraud</option>
                      <option value="Safety Violations">Safety Violations</option>
                      <option value="Other">Other</option>
                    </select>
                  </div>

                  <div className="mb-4">
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Evidence / Details (Optional)
                    </label>
                    <textarea
                      value={suspensionRequestForm.evidence}
                      onChange={(e) => setSuspensionRequestForm({ ...suspensionRequestForm, evidence: e.target.value })}
                      rows={4}
                      placeholder="Provide any supporting evidence or detailed explanation..."
                      className="w-full px-4 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white resize-none"
                    />
                  </div>

                  {!ownerMode && <div className="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <div className="flex items-start gap-2">
                      <InfoIcon className="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" />
                      <div>
                        <p className="text-sm font-semibold text-blue-900 dark:text-blue-300 mb-1">Approval Process</p>
                        <p className="text-sm text-blue-700 dark:text-blue-400">
                          This request will be sent to the Manager for initial review. If approved by the Manager, 
                          it will then be forwarded to the Shop Owner for final approval. The employee's account 
                          will only be suspended after receiving both approvals.
                        </p>
                      </div>
                    </div>
                  </div>}

                  <div className="flex justify-end gap-3">
                    <button
                      onClick={() => {
                        setIsSuspensionRequestModalOpen(false);
                        setEmployeeToSuspend(null);
                        setSuspensionRequestForm({ reason: "", evidence: "" });
                      }}
                      className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                    >
                      Cancel
                    </button>
                    <button
                      onClick={handleSuspensionRequestSubmit}
                      disabled={isProcessingId === employeeToSuspend?.id || !suspensionRequestForm.reason.trim()}
                      className={`px-4 py-2 text-sm font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-md transition-colors ${
                        isProcessingId === employeeToSuspend?.id || !suspensionRequestForm.reason.trim()
                          ? 'opacity-50 cursor-not-allowed'
                          : ''
                      }`}
                    >
                      {isProcessingId === employeeToSuspend?.id
                        ? ownerMode ? 'Suspending...' : 'Submitting...'
                        : ownerMode ? 'Suspend Employee' : 'Submit Request'}
                    </button>
                  </div>
              </div>
            </div>
            </div>
          </ModalPortal>
        )}

        {isTerminationRequestModalOpen && employeeToTerminate && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-xl w-full">
                <div className="p-6">
                  <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                    Request Employee Termination
                  </h3>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mb-5">
                    This starts the HR lifecycle approval process. The employee remains active until the Manager and Company Shop Owner approve the request.
                  </p>

                  <div className="mb-4 rounded-lg bg-gray-50 dark:bg-gray-700/50 p-4">
                    <p className="text-sm text-gray-700 dark:text-gray-300">
                      <span className="font-medium">Employee:</span> {buildName(employeeToTerminate)}
                    </p>
                    <p className="text-sm text-gray-700 dark:text-gray-300 mt-1">
                      <span className="font-medium">Position:</span> {employeeToTerminate.position || 'N/A'}
                    </p>
                  </div>

                  <div className="mb-4">
                    <label htmlFor="termination-reason" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Reason for Termination <span className="text-red-500">*</span>
                    </label>
                    <textarea
                      id="termination-reason"
                      value={terminationRequestForm.reason}
                      onChange={(event) => setTerminationRequestForm({ ...terminationRequestForm, reason: event.target.value })}
                      rows={4}
                      maxLength={2000}
                      placeholder="Document the employment decision and relevant facts..."
                      className="w-full px-4 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-700 dark:text-white resize-none"
                    />
                  </div>

                  <div className="mb-5">
                    <label htmlFor="termination-evidence" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Evidence / Notes (Optional)
                    </label>
                    <textarea
                      id="termination-evidence"
                      value={terminationRequestForm.evidence}
                      onChange={(event) => setTerminationRequestForm({ ...terminationRequestForm, evidence: event.target.value })}
                      rows={3}
                      maxLength={5000}
                      placeholder="Add supporting records or context for the reviewers..."
                      className="w-full px-4 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-700 dark:text-white resize-none"
                    />
                  </div>

                  <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-900/20">
                    <p className="text-sm font-semibold text-red-900 dark:text-red-300">What happens after approval</p>
                    <p className="text-sm text-red-800 dark:text-red-200 mt-1">
                      The employment period is closed, the account is disabled, and the termination remains in employment history. Reopening later requires Request Rehire.
                    </p>
                  </div>

                  <div className="flex justify-end gap-3">
                    <button
                      onClick={() => {
                        setIsTerminationRequestModalOpen(false);
                        setEmployeeToTerminate(null);
                        setTerminationRequestForm({ reason: '', evidence: '' });
                      }}
                      className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                    >
                      Cancel
                    </button>
                    <button
                      onClick={handleTerminationRequestSubmit}
                      disabled={isProcessingId === employeeToTerminate.id || terminationRequestForm.reason.trim().length < 3}
                      className="px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      {isProcessingId === employeeToTerminate.id ? 'Submitting...' : 'Submit Termination Request'}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </ModalPortal>
        )}

        {isRehireRequestModalOpen && employeeToRehire && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-white dark:bg-gray-900 rounded-xl shadow-xl max-w-2xl w-full border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div className="border-b border-gray-200 dark:border-gray-800 px-8 py-6">
                  <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
                    Request Rehire / Reinstate Employee
                  </h2>
                  <p className="text-gray-600 dark:text-gray-400 text-sm mt-1">
                    Fill in the employee details below
                  </p>
                </div>

                <div className="p-8 max-h-[calc(90vh-140px)] overflow-y-auto">
                  <div className="space-y-6">
                    <div>
                      <div className="mb-5 rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4">
                    <p className="text-sm text-gray-700 dark:text-gray-300">
                      <span className="font-medium">Employee:</span> {buildName(employeeToRehire)}
                    </p>
                    <p className="text-sm text-gray-700 dark:text-gray-300 mt-1">
                      <span className="font-medium">Previous termination:</span> {formatDate(employeeToRehire.terminatedAt)}
                    </p>
                  </div>

                      <h3 className="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wide mb-4 pb-2 border-b border-gray-200 dark:border-gray-700">
                        Personal Information
                      </h3>
                      <div className="grid grid-cols-2 gap-4">
                        <div className="col-span-2">
                          <label htmlFor="rehire-email" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Email <span className="text-red-500">*</span>
                          </label>
                          <input
                            id="rehire-email"
                            type="email"
                            value={employeeToRehire.email}
                            readOnly
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>
                        <div>
                          <label htmlFor="rehire-phone" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Phone
                          </label>
                          <input
                            id="rehire-phone"
                            type="tel"
                            value={employeeToRehire.phone || ""}
                            readOnly
                            placeholder="09XXXXXXXXX"
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>
                      </div>
                    </div>

                    <div>
                      <h3 className="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wide mb-4 pb-2 border-b border-gray-200 dark:border-gray-700">
                        Job Information
                      </h3>
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label htmlFor="rehire-role" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Department / Role <span className="text-red-500">*</span>
                          </label>
                          {availableRoles.length > 0 ? (
                            <select
                              id="rehire-role"
                              value={rehireRequestForm.rehireRole}
                              onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, rehireRole: event.target.value, rehireDepartment: event.target.value })}
                              className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                            >
                              <option value="">Select department/role</option>
                              {availableRoles
                                .filter((role) => !['shop owner', 'super admin'].includes(role.name.trim().toLowerCase()))
                                .map((role) => (
                                  <option key={role.name} value={role.name}>{role.name}</option>
                                ))}
                            </select>
                          ) : (
                            <input
                              id="rehire-role"
                              type="text"
                              value={rehireRequestForm.rehireRole}
                              onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, rehireRole: event.target.value, rehireDepartment: event.target.value })}
                              placeholder="Select department/role"
                              className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                            />
                          )}
                        </div>

                        <div>
                          <label htmlFor="rehire-position" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Position / Job Title <span className="text-red-500">*</span>
                          </label>
                          <input
                            id="rehire-position"
                            type="text"
                            value={rehireRequestForm.rehirePosition}
                            onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, rehirePosition: event.target.value })}
                            maxLength={100}
                            placeholder="e.g., Sales Associate, Cashier, Stock Clerk"
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Required for the new employment period
                          </p>
                        </div>
                      </div>

                      <div className="grid grid-cols-2 gap-4 mt-4">
                        <div>
                          <label htmlFor="rehire-start-date" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Hired Date <span className="text-red-500">*</span>
                          </label>
                          <input
                            id="rehire-start-date"
                            type="date"
                            value={rehireRequestForm.rehireStartDate}
                            onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, rehireStartDate: event.target.value })}
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>

                        <div>
                          <label htmlFor="rehire-salary" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Daily Rate
                          </label>
                          <div className="relative">
                            <span className="absolute left-3 top-2.5 text-gray-500 dark:text-gray-400">&#8369;</span>
                            <input
                              id="rehire-salary"
                              type="number"
                              min="0"
                              step="0.01"
                              value={rehireRequestForm.rehireSalary}
                              onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, rehireSalary: event.target.value })}
                              placeholder="0.00"
                              className="w-full pl-8 pr-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                            />
                          </div>
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Daily base rate for payroll calculation</p>
                        </div>
                      </div>
                    </div>

                  <div>
                    <label htmlFor="rehire-reason" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                      Reason for Rehire <span className="text-red-500">*</span>
                    </label>
                    <textarea
                      id="rehire-reason"
                      value={rehireRequestForm.reason}
                      onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, reason: event.target.value })}
                      rows={3}
                      maxLength={2000}
                      placeholder="Explain the rehire or reinstatement decision..."
                      className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all resize-none"
                    />
                  </div>

                  <div>
                    <label htmlFor="rehire-evidence" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                      Evidence / Notes (Optional)
                    </label>
                    <textarea
                      id="rehire-evidence"
                      value={rehireRequestForm.evidence}
                      onChange={(event) => setRehireRequestForm({ ...rehireRequestForm, evidence: event.target.value })}
                      rows={3}
                      maxLength={5000}
                      placeholder="Add the approved terms or supporting context..."
                      className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all resize-none"
                    />
                  </div>

                  <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/60 dark:bg-blue-900/20">
                    <p className="text-sm font-semibold text-blue-900 dark:text-blue-300">Approval Process</p>
                    <p className="text-sm text-blue-800 dark:text-blue-200 mt-1">
                      The Manager reviews this request first. The Company Shop Owner gives final approval, then the account is enabled with only the newly approved role and permissions.
                    </p>
                  </div>

                  <div className="bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700 px-8 py-4 flex gap-3 justify-end">
                    <button
                      type="button"
                      onClick={() => {
                        setIsRehireRequestModalOpen(false);
                        setEmployeeToRehire(null);
                        setRehireRequestForm({
                          reason: '',
                          evidence: '',
                          rehireStartDate: '',
                          rehirePosition: '',
                          rehireDepartment: '',
                          rehireSalary: '',
                          rehireRole: '',
                        });
                      }}
                      className="px-5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-all duration-200 hover:shadow-sm"
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      onClick={handleRehireRequestSubmit}
                      disabled={isProcessingId === employeeToRehire.id || rehireRequestForm.reason.trim().length < 3 || !rehireRequestForm.rehireStartDate || !rehireRequestForm.rehirePosition.trim() || !rehireRequestForm.rehireRole.trim()}
                      className={`px-5 py-2.5 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-all duration-200 hover:shadow-md active:shadow-sm ${isProcessingId === employeeToRehire.id ? 'opacity-50 cursor-not-allowed' : ''}`}
                    >
                      {isProcessingId === employeeToRehire.id ? 'Submitting...' : 'Submit Rehire Request'}
                    </button>
                  </div>
                 </div>
               </div>
             </div>
            </div>
           </ModalPortal>
        )}

        {/* Add Employee Modal */}
        {isAddEmployeeOpen && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-white dark:bg-gray-900 rounded-xl shadow-xl max-w-2xl w-full border border-gray-200 dark:border-gray-800 overflow-hidden">
                {/* Header */}
                <div className="border-b border-gray-200 dark:border-gray-800 px-8 py-6">
                  <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
                    Add New Employee
                  </h2>
                  <p className="text-gray-600 dark:text-gray-400 text-sm mt-1">
                    Fill in the employee details below
                  </p>
                </div>

                {/* Content */}
                <div className="p-8 max-h-[calc(90vh-140px)] overflow-y-auto">
                  <div className="space-y-6">
                    {/* Personal Information Section */}
                    <div>
                      <h3 className="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wide mb-4 pb-2 border-b border-gray-200 dark:border-gray-700">
                        Personal Information
                      </h3>
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            First Name <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={addEmployeeForm.firstName}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                firstName: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Last Name <span className="text-red-500">*</span>
                          </label>
                          <input
                            type="text"
                            value={addEmployeeForm.lastName}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                lastName: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>
                      </div>

                      <div className="mt-4">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                          Email <span className="text-red-500">*</span>
                        </label>
                        <input
                          type="email"
                          value={addEmployeeForm.email}
                          onChange={(e) =>
                            setAddEmployeeForm({
                              ...addEmployeeForm,
                              email: e.target.value,
                            })
                          }
                          className={`w-full px-3 py-2.5 rounded-lg border bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all ${addEmployeeEmailValidation.status === 'error' ? 'border-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600'}`}
                        />
                        {addEmployeeEmailValidation.status === 'error' && (
                          <p className="mt-1 text-xs text-red-600 dark:text-red-400">{addEmployeeEmailValidation.message}</p>
                        )}
                        {addEmployeeEmailValidation.status === 'checking' && (
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{addEmployeeEmailValidation.message}</p>
                        )}
                      </div>

                      <div className="grid grid-cols-2 gap-4 mt-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Phone
                          </label>
                          <input
                            type="tel"
                            value={addEmployeeForm.phone}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                phone: e.target.value.replace(/\D/g, '').slice(0, 11),
                              })
                            }
                            inputMode="numeric"
                            pattern="[0-9]*"
                            maxLength={11}
                            placeholder="09XXXXXXXXX"
                            className={`w-full px-3 py-2.5 rounded-lg border bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all ${addEmployeePhoneValidation.status === 'error' ? 'border-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600'}`}
                          />
                          {addEmployeePhoneValidation.status === 'error' && (
                            <p className="mt-1 text-xs text-red-600 dark:text-red-400">{addEmployeePhoneValidation.message}</p>
                          )}
                          {addEmployeePhoneValidation.status === 'checking' && (
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{addEmployeePhoneValidation.message}</p>
                          )}
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Address
                          </label>
                          <input
                            type="text"
                            value={addEmployeeForm.location}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                location: e.target.value,
                              })
                            }
                            placeholder="Address"
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>
                      </div>
                    </div>

                    {/* Job Information Section */}
                    <div>
                      <h3 className="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wide mb-4 pb-2 border-b border-gray-200 dark:border-gray-700">
                        Job Information
                      </h3>
                      <div className="grid grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Department / Role <span className="text-red-500">*</span>
                          </label>
                          <select
                            value={addEmployeeForm.department}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                department: (!isRepairCapableBusiness && isRepairerRole(e.target.value))
                                  || (!isRetailCapableBusiness && (e.target.value || '').trim().toLowerCase() === 'staff')
                                  || (!isCashierCapableBusiness && isCashierRole(e.target.value))
                                  ? ''
                                  : e.target.value,
                              })
                            }
                            title="Department or role"
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          >
                            <option value="">Select department/role</option>
                            <option value="Manager">Manager</option>
                            <option value="Finance">Finance</option>
                            <option value="HR">Human Resources</option>
                            <option value="CRM">Customer Relationship Management</option>
                            {isCashierCapableBusiness && <option value="Cashier">Cashier</option>}
                            {isRepairCapableBusiness && <option value="Repairer">Repairer</option>}
                            <option value="Inventory">Inventory</option>
                            <option value="Procurement">Procurement</option>
                            <option value="Logistics Dispatcher">Logistics Dispatcher</option>
                            <option value="Logistics Rider">Logistics Rider</option>
                            {isRetailCapableBusiness && <option value="Staff">Staff</option>}
                          </select>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Position / Job Title
                          </label>
                          <input
                            type="text"
                            value={addEmployeeForm.position}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                position: e.target.value,
                              })
                            }
                            placeholder="e.g., Sales Associate, Cashier, Stock Clerk"
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Optional - describe their role or job title
                          </p>
                        </div>

                      </div>

                      <div className="grid grid-cols-2 gap-4 mt-4">
                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Hired Date
                          </label>
                          <input
                            type="date"
                            value={addEmployeeForm.hiredAt}
                            onChange={(e) =>
                              setAddEmployeeForm({
                                ...addEmployeeForm,
                                hiredAt: e.target.value,
                              })
                            }
                            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                          />
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Daily Rate
                          </label>
                          <div className="relative">
                            <span className="absolute left-3 top-2.5 text-gray-500 dark:text-gray-400">₱</span>
                            <input
                              type="number"
                              step="0.01"
                              min="0"
                              placeholder="0.00"
                              value={addEmployeeForm.salary}
                              onChange={(e) =>
                                setAddEmployeeForm({
                                  ...addEmployeeForm,
                                  salary: e.target.value,
                                })
                              }
                              className="w-full pl-8 pr-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-400 focus:border-transparent outline-none transition-all"
                            />
                          </div>
                          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Daily base rate for payroll calculation</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Footer */}
                <div className="bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-700 px-8 py-4 flex gap-3 justify-end">
                  <button
                    onClick={() => setIsAddEmployeeOpen(false)}
                    className="px-5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-all duration-200 hover:shadow-sm"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleSaveNewEmployee}
                    disabled={isAdding}
                    className={`px-5 py-2.5 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-all duration-200 hover:shadow-md active:shadow-sm ${isAdding ? 'opacity-50 cursor-not-allowed' : ''}`}
                  >
                    {isAdding ? 'Processing...' : 'Add Employee'}
                  </button>
                </div>
              </div>
            </div>
          </ModalPortal>
        )}

        {/* Permission Management Modal */}
        {isPermissionModalOpen && selectedEmployeeForPermissions && availablePermissions && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="w-full max-w-5xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700">
                <div className="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex items-start justify-between">
                  <div>
                    <h3 className="text-3xl font-bold text-gray-900 dark:text-white">Manage Permissions</h3>
                    <div className="mt-2 flex items-center gap-3">
                      <p className="text-gray-600 dark:text-gray-400">{buildName(selectedEmployeeForPermissions)}</p>
                      <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                        {selectedEmployeeRoleName}
                      </span>
                    </div>
                  </div>
                  <button
                    onClick={() => setIsPermissionModalOpen(false)}
                    title="Close permissions modal"
                    className="h-10 w-10 inline-flex items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                  >
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <div className="p-6 max-h-[70vh] overflow-y-auto">
                  {selectedEmployeeRolePermissions.length > 0 && (
                    <div className="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                      <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        Permissions from {selectedEmployeeRoleName} Role
                      </h4>
                      <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        These permissions are granted by the role and cannot be removed individually
                      </p>
                      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                        {selectedEmployeeRolePermissions.map((permission) => (
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

                    {permissionCategoryConfigs.map((category) => {
                      if (!category.show) return null;

                      const permissions = availablePermissions.grouped[category.key] || [];
                      if (permissions.length === 0) return null;

                      return (
                        <div key={category.key} className="mb-3 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                          <button
                            onClick={() => toggleCategory(category.key)}
                            className="w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                          >
                            <div className="flex items-center justify-between">
                              <div className="flex items-center gap-3">
                                <svg className={`w-5 h-5 text-gray-700 dark:text-gray-300 transition-transform ${expandedCategories[category.key] ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                                <div className="flex items-center gap-2">
                                  {category.icon}
                                  <span className="font-semibold text-gray-900 dark:text-white">{category.label}</span>
                                </div>
                              </div>
                              <div className="flex items-center gap-3">
                                <span className="px-2.5 py-0.5 text-xs font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-full">
                                  {permissions.filter((permission) => selectedPermissions.includes(permission) || selectedEmployeeRolePermissions.includes(permission)).length} / {permissions.length}
                                </span>
                                <div
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    addRolePermissions(category.key);
                                  }}
                                  className="text-xs px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600 transition-colors cursor-pointer"
                                >
                                  Add
                                </div>
                                <div
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    clearRolePermissions(category.key);
                                  }}
                                  className="text-xs px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600 transition-colors cursor-pointer"
                                >
                                  Clear
                                </div>
                              </div>
                            </div>
                          </button>

                          {expandedCategories[category.key] && (
                            <div className="p-4 bg-white dark:bg-gray-800">
                              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                {permissions.map((permission) => {
                                  const isFromRole = selectedEmployeeRolePermissions.includes(permission);
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
                      );
                    })}
                  </div>

                  <div className="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                      <span>Total Permissions:</span>
                      <span className="font-semibold">
                        {selectedEmployeeRolePermissions.length + selectedPermissions.filter((p) => !selectedEmployeeRolePermissions.includes(p)).length}
                        <span className="text-xs ml-1">
                          ({selectedEmployeeRolePermissions.length} from role + {selectedPermissions.filter((p) => !selectedEmployeeRolePermissions.includes(p)).length} additional)
                        </span>
                      </span>
                    </div>
                  </div>
                </div>

                <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                  <button
                    onClick={() => {
                      setIsPermissionModalOpen(false);
                      setSelectedEmployeeForPermissions(null);
                      setSelectedPermissions([]);
                    }}
                    disabled={isSavingPermissions}
                    className="px-5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={savePermissions}
                    disabled={isSavingPermissions}
                    className="px-5 py-2.5 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center"
                  >
                    {isSavingPermissions && (
                      <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                    )}
                    {isSavingPermissions ? 'Saving...' : 'Save Permissions'}
                  </button>
                </div>
              </div>
            </div>
          </ModalPortal>
        )}

        {invitationModal.isOpen && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/50 backdrop-blur-sm flex items-center justify-center p-6">
              <div className="w-full max-w-5xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700">
                <div className="px-8 py-6 border-b border-gray-200 dark:border-gray-700 flex items-start justify-between">
                  <div className="flex items-start gap-3">
                    <div className="mt-1 inline-flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                      <InfoIcon className="h-6 w-6" />
                    </div>
                    <div>
                      <h3 className="text-3xl font-bold text-gray-900 dark:text-white">Employee Invitation Link</h3>
                      <p className="text-base text-gray-600 dark:text-gray-400 mt-1">
                        Share this invite with {invitationModal.employeeName} to complete account setup.
                      </p>
                    </div>
                  </div>
                  <button
                    onClick={closeInvitationModal}
                    title="Close invitation modal"
                    className="h-10 w-10 inline-flex items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                  >
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <div className="px-8 py-6 space-y-4">
                  <div className="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-700 dark:bg-gray-800">
                    <p className="text-base text-gray-700 dark:text-gray-300">
                      <span className="font-semibold">Work email created:</span> {invitationModal.workEmail}
                    </p>
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                      This inbox does not exist yet. Share the invite link via personal email, chat, or SMS.
                    </p>
                  </div>

                  <div className="rounded-lg border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                    <div className="flex items-center justify-between mb-2">
                      <p className="text-sm font-semibold tracking-wide text-gray-500 dark:text-gray-400">INVITATION LINK</p>
                      <button
                        onClick={copyInvitationLink}
                        className="px-4 py-2 rounded-md border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                      >
                        Copy Link
                      </button>
                    </div>
                    <div className="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm break-all font-mono text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                      {invitationModal.inviteUrl}
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="rounded-lg border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                      <p className="text-base font-semibold text-gray-900 dark:text-white mb-2">How to share</p>
                      <ul className="space-y-1 text-base text-gray-700 dark:text-gray-300">
                        <li>Personal email (Gmail/Yahoo)</li>
                        <li>WhatsApp/Messenger</li>
                        <li>SMS</li>
                        <li>In person</li>
                      </ul>
                    </div>
                    <div className="rounded-lg border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                      <p className="text-base font-semibold text-gray-900 dark:text-white mb-2">Important</p>
                      <ul className="space-y-1 text-base text-gray-700 dark:text-gray-300">
                        <li>Link expires: {invitationModal.expiresAt}</li>
                        {invitationModal.showRegeneratedNote && <li>A new link was generated (old one is now invalid)</li>}
                        <li>Employee sets their own password</li>
                        <li>You can regenerate the link anytime</li>
                      </ul>
                    </div>
                  </div>

                  <div className="rounded-lg border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                    <label htmlFor="personal-email" className="block text-base font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Personal email (optional)
                    </label>
                    <input
                      id="personal-email"
                      type="email"
                      value={invitationModal.personalEmail}
                      onChange={(e) => setInvitationModal((prev) => ({ ...prev, personalEmail: e.target.value }))}
                      placeholder="personal.email@gmail.com"
                      className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                </div>

                <div className="px-8 py-5 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-end gap-2">
                  {invitationModal.copied && (
                    <span className="mr-auto text-base text-green-700 dark:text-green-400">Link copied</span>
                  )}
                  <button
                    onClick={closeInvitationModal}
                    className="px-5 py-2.5 rounded-md border border-gray-300 text-base font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                  >
                    Done
                  </button>
                  <button
                    onClick={sendInvitationToPersonalEmail}
                    disabled={invitationModal.isSendingEmail || (!invitationModal.employeeId && !invitationModal.employeeUserId)}
                    className={`px-5 py-2.5 rounded-md text-base font-medium text-white ${invitationModal.isSendingEmail ? 'bg-blue-400 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700'}`}
                  >
                    {invitationModal.isSendingEmail ? 'Sending...' : 'Email to Personal Address'}
                  </button>
                </div>
              </div>
            </div>
          </ModalPortal>
        )}
      </div>
    </div>
  );
};

export default EmployeeManagement;
