import React, { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import Swal from "sweetalert2";

type OvertimeStatus = "pending" | "approved" | "assigned" | "rejected";

type OvertimeRequest = {
  id: number;
  employeeId: number;
  employeeName: string;
  department: string;
  overtimeDate: string;
  startTime: string;
  endTime: string;
  totalHours: number;
  actualStartTime?: string;
  actualEndTime?: string;
  actualHours?: number;
  checkedInAt?: string;
  checkedOutAt?: string;
  confirmedBy?: string;
  confirmedAt?: string;
  reason: string;
  status: OvertimeStatus;
  approvedBy?: string;
  approvalDate?: string;
  rejectionReason?: string;
  createdAt: string;
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

// Portal wrapper to ensure modals sit at the document level
const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === "undefined") return null;
  return createPortal(children, document.body);
};

// Icon Components
const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const XCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l6-6m0 0l-6-6m6 6l6 6m-6-6l-6 6" />
  </svg>
);

const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const CalendarIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

const AlertIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
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

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

// Professional Metric Card Component
const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success": return "from-green-500 to-emerald-600";
      case "error": return "from-red-500 to-rose-600";
      case "warning": return "from-yellow-500 to-orange-600";
      case "info": return "from-blue-500 to-indigo-600";
      default: return "from-gray-500 to-gray-600";
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
          {change !== undefined && (
            <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}>
              {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
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

// Transform function to convert snake_case API response to camelCase
const transformOvertimeFromApi = (apiOvertime: any): OvertimeRequest => {
  // Build employee name from employee relationship
  let employeeName = 'Unknown';
  if (apiOvertime.employee) {
    const firstName = apiOvertime.employee.first_name || '';
    const lastName = apiOvertime.employee.last_name || '';
    const fullName = `${firstName} ${lastName}`.trim();
    
    // If first_name/last_name are empty, use the name field
    employeeName = fullName || apiOvertime.employee.name || 'Unknown';
  }
  
  const department = apiOvertime.employee?.department || 'N/A';
  
  return {
    id: apiOvertime.id,
    employeeId: apiOvertime.employee_id,
    employeeName: employeeName,
    department: department,
    overtimeDate: apiOvertime.overtime_date,
    startTime: apiOvertime.start_time,
    endTime: apiOvertime.end_time,
    totalHours: apiOvertime.hours || apiOvertime.total_hours || 0,
    actualStartTime: apiOvertime.actual_start_time,
    actualEndTime: apiOvertime.actual_end_time,
    actualHours: apiOvertime.actual_hours,
    checkedInAt: apiOvertime.checked_in_at,
    checkedOutAt: apiOvertime.checked_out_at,
    confirmedBy: apiOvertime.confirmed_by,
    confirmedAt: apiOvertime.confirmed_at,
    reason: apiOvertime.reason || '',
    status: apiOvertime.status as OvertimeStatus,
    approvedBy: apiOvertime.approved_by,
    approvalDate: apiOvertime.approval_date,
    rejectionReason: apiOvertime.rejection_reason,
    createdAt: apiOvertime.created_at,
  };
};

export function OvertimeRequests() {
  const [overtimeRequestsState, setOvertimeRequestsState] = useState<OvertimeRequest[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedStatus, setSelectedStatus] = useState<OvertimeStatus | "">("");
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<OvertimeRequest | null>(null);
  const [isRejectModalOpen, setIsRejectModalOpen] = useState(false);
  const [requestToReject, setRequestToReject] = useState<OvertimeRequest | null>(null);
  const [rejectionReason, setRejectionReason] = useState("");
  const [isAssignModalOpen, setIsAssignModalOpen] = useState(false);
  const [employees, setEmployees] = useState<any[]>([]);
  const [assignData, setAssignData] = useState({
    employee_id: "",
    overtime_date: "",
    start_time: "",
    end_time: "",
    reason: "",
    work_description: ""
  });
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);
  const [paginationMeta, setPaginationMeta] = useState<any>(null);

  // Fetch overtime requests from API
  useEffect(() => {
    const fetchOvertimeRequests = async () => {
      setIsLoading(true);
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const params = new URLSearchParams();
        if (searchTerm) params.append('search', searchTerm);
        if (selectedStatus) params.append('status', selectedStatus);
        params.append('page', currentPage.toString());
        params.append('per_page', itemsPerPage.toString());

        const response = await fetch(`/api/hr/overtime-requests?${params.toString()}`, {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
            'Accept': 'application/json',
          },
          credentials: 'include',
        });

        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        console.log('Overtime requests API response:', data);

        // Check if response has Laravel pagination structure
        if (data.data && Array.isArray(data.data)) {
          const transformedData = data.data.map(transformOvertimeFromApi);
          setOvertimeRequestsState(transformedData);
          setPaginationMeta({
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
          });
        } else if (Array.isArray(data)) {
          const transformedData = data.map(transformOvertimeFromApi);
          setOvertimeRequestsState(transformedData);
          setPaginationMeta(null);
        } else {
          console.error('Unexpected API response format:', data);
          setOvertimeRequestsState([]);
        }
      } catch (error) {
        console.error('Error fetching overtime requests:', error);
        setOvertimeRequestsState([]);
      } finally {
        setIsLoading(false);
      }
    };

    fetchOvertimeRequests();
  }, [searchTerm, selectedStatus, currentPage, itemsPerPage]);

  // Filter requests - only used for client-side filtering when no server pagination
  const filteredRequests = useMemo(() => {
    // If server pagination is active, return data as-is (filtering done server-side)
    if (paginationMeta) {
      return overtimeRequestsState;
    }
    
    // Client-side filtering
    return overtimeRequestsState.filter((request) => {
      const matchesSearch =
        request.employeeName.toLowerCase().includes(searchTerm.toLowerCase()) ||
        request.reason.toLowerCase().includes(searchTerm.toLowerCase()) ||
        request.department.toLowerCase().includes(searchTerm.toLowerCase());

      const matchesStatus = !selectedStatus || request.status === selectedStatus;

      return matchesSearch && matchesStatus;
    });
  }, [overtimeRequestsState, searchTerm, selectedStatus, paginationMeta]);

  // Pagination calculations
  const totalPages = paginationMeta ? paginationMeta.last_page : Math.ceil(filteredRequests.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedRequests = paginationMeta ? filteredRequests : filteredRequests.slice(startIndex, endIndex);

  // Calculate stats
  const stats = {
    total: paginationMeta ? paginationMeta.total : overtimeRequestsState.length,
    pending: overtimeRequestsState.filter(r => r.status === "pending").length,
    approved: overtimeRequestsState.filter(r => r.status === "approved").length,
    rejected: overtimeRequestsState.filter(r => r.status === "rejected").length,
    totalHours: overtimeRequestsState.filter(r => r.status === "approved").reduce((sum, r) => sum + r.totalHours, 0),
  };

  const handleApprove = async (request: OvertimeRequest) => {
    const result = await Swal.fire({
      title: "Approve Overtime Request?",
      text: `Approve ${request.totalHours}-hour overtime for ${request.employeeName}?`,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#10b981",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, approve",
      cancelButtonText: "Cancel",
    });

    if (!result.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/hr/overtime-requests/${request.id}/approve`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          notes: `Approved by manager`
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to approve overtime request');
      }

      const data = await response.json();
      
      // Update local state with the approved request
      setOvertimeRequestsState((prev) =>
        prev.map((req) =>
          req.id === request.id
            ? { ...req, status: "approved" as OvertimeStatus, approvalDate: new Date().toISOString() }
            : req
        )
      );

      Swal.fire({
        icon: "success",
        title: "Approved!",
        text: data.message || "Overtime request has been approved.",
        timer: 2000,
        showConfirmButton: false,
      });
    } catch (error: any) {
      console.error('Error approving overtime:', error);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: error.message || "Failed to approve overtime request. Please try again.",
      });
    }
  };

  const handleReject = (request: OvertimeRequest) => {
    setRequestToReject(request);
    setRejectionReason("");
    setIsRejectModalOpen(true);
  };

  const handleConfirmReject = async () => {
    if (!requestToReject || !rejectionReason.trim()) {
      Swal.fire({
        icon: "warning",
        title: "Please provide a reason",
        text: "Enter a rejection reason before submitting.",
      });
      return;
    }

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/hr/overtime-requests/${requestToReject.id}/reject`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          rejection_reason: rejectionReason
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to reject overtime request');
      }

      const data = await response.json();

      // Update local state
      setOvertimeRequestsState((prev) =>
        prev.map((req) =>
          req.id === requestToReject.id
            ? { ...req, status: "rejected" as OvertimeStatus, rejectionReason: rejectionReason }
            : req
        )
      );
      
      setIsRejectModalOpen(false);
      setRequestToReject(null);
      setRejectionReason("");
      
      Swal.fire({
        icon: "success",
        title: "Rejected!",
        text: data.message || "Overtime request has been rejected.",
        timer: 2000,
        showConfirmButton: false,
      });
    } catch (error: any) {
      console.error('Error rejecting overtime:', error);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: error.message || "Failed to reject overtime request. Please try again.",
      });
    }
  };

  // Fetch employees when assign modal opens
  useEffect(() => {
    if (isAssignModalOpen && employees.length === 0) {
      const fetchEmployees = async () => {
        try {
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
          
          // Add status=active filter to only fetch active employees
          const params = new URLSearchParams();
          params.append('status', 'active');
          params.append('per_page', '100'); // Get up to 100 active employees
          
          const response = await fetch(`/api/hr/employees?${params.toString()}`, {
            method: 'GET',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrfToken || '',
              'Accept': 'application/json',
            },
            credentials: 'include',
          });

          if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
          
          const data = await response.json();
          setEmployees(Array.isArray(data) ? data : data.data || []);
        } catch (error) {
          console.error('Error fetching employees:', error);
          Swal.fire({
            icon: "error",
            title: "Error",
            text: "Failed to load employees list.",
          });
        }
      };
      fetchEmployees();
    }
  }, [isAssignModalOpen, employees.length]);

  const handleAssignOvertime = async () => {
    if (!assignData.employee_id || !assignData.overtime_date || !assignData.start_time || 
        !assignData.end_time || !assignData.reason.trim()) {
      Swal.fire({
        icon: "warning",
        title: "Missing Information",
        text: "Please fill in all required fields.",
      });
      return;
    }

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch('/api/hr/overtime-requests/assign', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify(assignData),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to assign overtime');
      }

      const result = await response.json();
      
      // Add the new overtime request to the list
      const newRequest = transformOvertimeFromApi(result.overtime_request);
      setOvertimeRequestsState((prev) => [newRequest, ...prev]);

      // Reset and close
      setAssignData({
        employee_id: "",
        overtime_date: "",
        start_time: "",
        end_time: "",
        reason: "",
        work_description: ""
      });
      setIsAssignModalOpen(false);

      Swal.fire({
        icon: "success",
        title: "Assigned!",
        text: result.message || "Overtime has been assigned to the employee.",
        timer: 2000,
        showConfirmButton: false,
      });
    } catch (error: any) {
      console.error('Error assigning overtime:', error);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: error.message || "Failed to assign overtime. Please try again.",
      });
    }
  };

  const getStatusIcon = (status: OvertimeStatus) => {
    switch (status) {
      case "approved":
        return <CheckCircleIcon className="size-5 text-green-500" />;
      case "assigned":
        return <CheckCircleIcon className="size-5 text-blue-500" />;
      case "rejected":
        return <XCircleIcon className="size-5 text-red-500" />;
      default:
        return <ClockIcon className="size-5 text-yellow-500" />;
    }
  };

  const getStatusColor = (status: OvertimeStatus) => {
    switch (status) {
      case "approved":
        return "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400";
      case "assigned":
        return "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400";
      case "rejected":
        return "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400";
      default:
        return "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400";
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Overtime Requests</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-2">Manage and review employee overtime requests</p>
        </div>
        <button
          onClick={() => setIsAssignModalOpen(true)}
          className="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-xl transition-colors duration-200 shadow-md hover:shadow-lg flex items-center gap-2"
        >
          <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Assign Overtime
        </button>
      </div>

      {/* Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <MetricCard
          title="Total Requests"
          value={stats.total}
          change={12}
          changeType="increase"
          icon={CalendarIcon}
          color="info"
          description="Overtime requests this period"
        />
        <MetricCard
          title="Pending"
          value={stats.pending}
          change={5}
          changeType="increase"
          icon={ClockIcon}
          color="warning"
          description="Awaiting approval"
        />
        <MetricCard
          title="Approved"
          value={stats.approved}
          change={8}
          changeType="increase"
          icon={CheckCircleIcon}
          color="success"
          description="Successfully approved"
        />
        <MetricCard
          title="Rejected"
          value={stats.rejected}
          change={2}
          changeType="decrease"
          icon={AlertIcon}
          color="error"
          description="Rejected requests"
        />
      </div>

      {/* Filters */}
      <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {/* Search Bar */}
          <div className="lg:col-span-1">
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Search
            </label>
            <div className="relative">
              <input
                type="text"
                placeholder="Search by name or reason..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
              />
            </div>
          </div>

          {/* Status Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Status
            </label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value as OvertimeStatus | "")}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
            >
              <option value="">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
        </div>
      </div>

      {/* Overtime Requests Table */}
      <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
              <tr>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Employee</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Date</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Time</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Requested</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Actual</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Reason</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                <th className="px-6 py-4 text-center text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {isLoading ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center">
                    <div className="flex flex-col items-center justify-center space-y-3">
                      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                      <p className="text-sm text-gray-500 dark:text-gray-400">Loading overtime requests...</p>
                    </div>
                  </td>
                </tr>
              ) : paginatedRequests.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center">
                    <p className="text-sm text-gray-500 dark:text-gray-400">No overtime requests found.</p>
                  </td>
                </tr>
              ) : (
                paginatedRequests.map((request) => (
                <tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors">
                  <td className="px-6 py-4">
                    <div className="flex items-center space-x-3">
                      <div className="flex-shrink-0 h-10 w-10">
                        <div className="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                          <span className="text-blue-600 dark:text-blue-300 font-medium text-sm">
                            {request.employeeName
                              .split(" ")
                              .map((n) => n[0])
                              .join("")
                              .toUpperCase()}
                          </span>
                        </div>
                      </div>
                      <div>
                        <p className="font-medium text-gray-900 dark:text-white">{request.employeeName}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{request.department}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm text-gray-900 dark:text-white">
                      {new Date(request.overtimeDate).toLocaleDateString()}
                    </p>
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm text-gray-900 dark:text-white">
                      {request.startTime} - {request.endTime}
                    </p>
                    {request.actualStartTime && request.actualEndTime && (
                      <p className="text-xs text-green-600 dark:text-green-400 mt-1">
                        Actual: {request.actualStartTime} - {request.actualEndTime}
                      </p>
                    )}
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm font-medium text-gray-900 dark:text-white">{request.totalHours} hrs</p>
                  </td>
                  <td className="px-6 py-4">
                    {request.actualHours ? (
                      <div>
                        <p className="text-sm font-medium text-green-600 dark:text-green-400">{request.actualHours} hrs</p>
                        {request.confirmedAt && (
                          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">✓ Confirmed</p>
                        )}
                      </div>
                    ) : request.checkedInAt && !request.checkedOutAt ? (
                      <p className="text-sm text-yellow-600 dark:text-yellow-400">In Progress...</p>
                    ) : (
                      <p className="text-sm text-gray-400 dark:text-gray-500">Not worked yet</p>
                    )}
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm text-gray-600 dark:text-gray-400">{request.reason}</p>
                  </td>
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <span className={`inline-flex px-3 py-1 text-xs font-semibold rounded-full ${getStatusColor(request.status)}`}>
                        {request.status.charAt(0).toUpperCase() + request.status.slice(1)}
                      </span>
                    </div>
                  </td>
                  <td className="px-6 py-4 text-center">
                    <div className="flex justify-center gap-2">
                      <button
                        onClick={() => {
                          setSelectedRequest(request);
                          setIsViewModalOpen(true);
                        }}
                        className="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                        title="View Details"
                      >
                        <EyeIcon className="size-5 text-blue-600 dark:text-blue-400" />
                      </button>
                      {/* Actions moved into the request details modal for clarity */}
                    </div>
                  </td>
                </tr>
              ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {filteredRequests.length > 0 && (
          <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
            <div className="flex items-center justify-between">
              <div className="text-sm text-gray-700 dark:text-gray-300">
                Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                <span className="font-medium">{Math.min(endIndex, filteredRequests.length)}</span> of{" "}
                <span className="font-medium">{filteredRequests.length}</span>
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

      {/* View Details Modal */}
      {isViewModalOpen && selectedRequest && (
        <ModalPortal>
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-4xl w-full max-h-[95vh] overflow-y-auto">
              {/* Header with Status */}
              <div className="sticky top-0 border-b border-gray-200 dark:border-gray-700 px-6 py-5 flex justify-between items-start">
                <div>
                  <h3 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                    Overtime Request Details
                  </h3>
                  <div className="flex items-center gap-2">
                    <span className={`inline-flex px-3 py-1 text-sm font-semibold rounded-full ${getStatusColor(selectedRequest.status)}`}>
                      <span>{selectedRequest.status.charAt(0).toUpperCase() + selectedRequest.status.slice(1)}</span>
                    </span>
                  </div>
                </div>
                <button
                  onClick={() => setIsViewModalOpen(false)}
                  className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 text-2xl leading-none font-light transition-colors"
                >
                  ×
                </button>
              </div>

              <div className="p-6">
                <div className="space-y-6">
                  {/* Employee Card */}
                  <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <div className="flex items-start gap-4">
                      <div className="flex-shrink-0">
                        <div className="h-12 w-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                          <span className="text-blue-600 dark:text-blue-300 font-bold text-sm">
                            {selectedRequest.employeeName
                              .split(" ")
                              .map((n) => n[0])
                              .join("")
                              .toUpperCase()}
                          </span>
                        </div>
                      </div>
                      <div className="flex-1">
                        <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-3">Employee Information</h4>
                        <div className="grid grid-cols-2 gap-3">
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Name</p>
                            <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.employeeName}</p>
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Department</p>
                            <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.department}</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Overtime Details Card */}
                  <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                      <ClockIcon className="size-4 text-purple-600 dark:text-purple-400" />
                      Overtime Details
                    </h4>
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Date</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{new Date(selectedRequest.overtimeDate).toLocaleDateString()}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Total Hours</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.totalHours} hours</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Start Time</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.startTime}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">End Time</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.endTime}</p>
                      </div>
                    </div>
                  </div>

                  {/* Reason Section */}
                  <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-3">Reason for Overtime</h4>
                    <p className="text-base text-gray-700 dark:text-gray-300 leading-relaxed">{selectedRequest.reason}</p>
                  </div>

                  {/* Rejection Reason (if applicable) */}
                  {selectedRequest.status === "rejected" && selectedRequest.rejectionReason && (
                    <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                      <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                        <AlertIcon className="size-4 text-red-600 dark:text-red-400" />
                        Rejection Reason
                      </h4>
                      <p className="text-base text-gray-700 dark:text-gray-300">{selectedRequest.rejectionReason}</p>
                    </div>
                  )}

                  {/* Approval Information (if applicable) */}
                  {selectedRequest.status === "approved" && selectedRequest.approvalDate && (
                    <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                      <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                        <CheckCircleIcon className="size-4 text-green-600 dark:text-green-400" />
                        Approval Information
                      </h4>
                      <div className="grid grid-cols-2 gap-3">
                        <div>
                          <p className="text-sm text-gray-600 dark:text-gray-400">Approved By</p>
                          <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.approvedBy || "N/A"}</p>
                        </div>
                        <div>
                          <p className="text-sm text-gray-600 dark:text-gray-400">Approval Date</p>
                          <p className="text-base font-medium text-gray-900 dark:text-white">{new Date(selectedRequest.approvalDate).toLocaleDateString()}</p>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Modal Actions */}
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <div className="flex justify-end gap-3">
                      {selectedRequest.status === "pending" && (
                        <>
                          <button
                            onClick={() => handleApprove(selectedRequest)}
                            className="px-4 py-2.5 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors"
                          >
                            Approve
                          </button>
                          <button
                            onClick={() => {
                              setRequestToReject(selectedRequest);
                              setRejectionReason("");
                              setIsRejectModalOpen(true);
                            }}
                            className="px-4 py-2.5 text-sm font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-lg transition-colors"
                          >
                            Reject
                          </button>
                        </>
                      )}
                      <button
                        onClick={() => setIsViewModalOpen(false)}
                        className="px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                      >
                        Close
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </ModalPortal>
      )}

      {/* Reject Overtime Request Modal */}
      {isRejectModalOpen && requestToReject && (
        <ModalPortal>
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-xl w-full">
              <div className="p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">Reject Overtime Request</h3>

                {/* Employee Info */}
                <div className="mb-4">
                  <p className="text-sm text-gray-700 dark:text-gray-300 mb-1">
                    <span className="font-medium">Employee:</span> {requestToReject.employeeName}
                  </p>
                  <p className="text-sm text-gray-700 dark:text-gray-300 mb-1">
                    <span className="font-medium">Date:</span> {new Date(requestToReject.overtimeDate).toLocaleDateString()}
                  </p>
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    <span className="font-medium">Hours:</span> {requestToReject.totalHours} hours
                  </p>
                </div>

                {/* Rejection Reason */}
                <div className="mb-4">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Reason for Rejection
                  </label>
                  <select
                    value={rejectionReason}
                    onChange={(e) => setRejectionReason(e.target.value)}
                    className="w-full px-4 py-2 border-2 border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                  >
                    <option value="">Select a reason</option>
                    <option value="Not Pre-Approved">Not Pre-Approved</option>
                    <option value="Exceeds Budget">Exceeds Budget</option>
                    <option value="Insufficient Justification">Insufficient Justification</option>
                    <option value="Duplicate Request">Duplicate Request</option>
                    <option value="Other">Other</option>
                  </select>
                </div>

                {/* Rejection Message Preview */}
                <div className="mb-4 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
                  <div className="flex items-start gap-2">
                    <AlertIcon className="h-5 w-5 text-orange-600 dark:text-orange-400 flex-shrink-0 mt-0.5" />
                    <div>
                      <p className="text-sm font-semibold text-orange-900 dark:text-orange-300 mb-1">Rejection Message Preview</p>
                      <p className="text-sm text-orange-700 dark:text-orange-400 italic">
                        {rejectionReason && rejectionReason !== "Select a reason" && rejectionReason !== ""
                          ? `Your overtime request has been rejected. Reason: ${rejectionReason}. Please contact your manager for more details.`
                          : "Please select a rejection reason above to preview the message that will be sent to the employee."}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Rejection Warning */}
                <div className="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                  <div className="flex items-start gap-2">
                    <AlertIcon className="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                    <div>
                      <p className="text-sm font-semibold text-red-900 dark:text-red-300 mb-1">Rejection Warning</p>
                      <p className="text-sm text-red-700 dark:text-red-400">
                        This action will reject the overtime request. The employee will be notified of the rejection and the reason provided.
                      </p>
                    </div>
                  </div>
                </div>

                <div className="flex justify-end gap-3">
                  <button
                    onClick={() => {
                      setIsRejectModalOpen(false);
                      setRequestToReject(null);
                      setRejectionReason("");
                    }}
                    className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleConfirmReject}
                    className="px-4 py-2 text-sm font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-lg transition-colors"
                  >
                    Reject Request
                  </button>
                </div>
              </div>
            </div>
          </div>
        </ModalPortal>
      )}

      {/* Assign Overtime Modal */}
      {isAssignModalOpen && (
        <ModalPortal>
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
              <div className="p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-6">Assign Overtime to Employee</h3>

                <div className="space-y-4">
                  {/* Employee Selection */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Employee <span className="text-red-500">*</span>
                    </label>
                    <select
                      value={assignData.employee_id}
                      onChange={(e) => setAssignData({ ...assignData, employee_id: e.target.value })}
                      className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                    >
                      <option value="">Select an employee</option>
                      {employees.map((emp) => {
                        const displayName = emp.first_name && emp.last_name 
                          ? `${emp.first_name} ${emp.last_name}` 
                          : emp.name || 'Unknown Employee';
                        const position = emp.position || 'No Position';
                        const department = emp.department || 'No Department';
                        return (
                          <option key={emp.id} value={emp.id}>
                            {displayName} - {position} ({department})
                          </option>
                        );
                      })}
                    </select>
                  </div>

                  {/* Overtime Date */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Overtime Date <span className="text-red-500">*</span>
                    </label>
                    <input
                      type="date"
                      value={assignData.overtime_date}
                      onChange={(e) => setAssignData({ ...assignData, overtime_date: e.target.value })}
                      className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                    />
                  </div>

                  {/* Time Range */}
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Start Time <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="time"
                        value={assignData.start_time}
                        onChange={(e) => setAssignData({ ...assignData, start_time: e.target.value })}
                        className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        End Time <span className="text-red-500">*</span>
                      </label>
                      <input
                        type="time"
                        value={assignData.end_time}
                        onChange={(e) => setAssignData({ ...assignData, end_time: e.target.value })}
                        className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                      />
                    </div>
                  </div>

                  {/* Reason */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Reason <span className="text-red-500">*</span>
                    </label>
                    <input
                      type="text"
                      value={assignData.reason}
                      onChange={(e) => setAssignData({ ...assignData, reason: e.target.value })}
                      placeholder="e.g., Urgent deadline, Peak season"
                      className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                    />
                  </div>

                  {/* Work Description */}
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Work Description (Optional)
                    </label>
                    <textarea
                      value={assignData.work_description}
                      onChange={(e) => setAssignData({ ...assignData, work_description: e.target.value })}
                      placeholder="Describe the tasks to be performed during overtime..."
                      rows={3}
                      className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white resize-none"
                    />
                  </div>

                  {/* Info Box */}
                  <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <div className="flex items-start gap-2">
                      <svg className="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <div>
                        <p className="text-sm font-semibold text-blue-900 dark:text-blue-300 mb-1">Assign Overtime</p>
                        <p className="text-sm text-blue-700 dark:text-blue-400">
                          This will assign overtime to the employee without requiring their approval. The overtime will appear as "Assigned" and will be automatically approved. The employee can start and end their overtime shift from the Time In page.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                  <button
                    onClick={() => {
                      setIsAssignModalOpen(false);
                      setAssignData({
                        employee_id: "",
                        overtime_date: "",
                        start_time: "",
                        end_time: "",
                        reason: "",
                        work_description: ""
                      });
                    }}
                    className="px-6 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleAssignOvertime}
                    className="px-6 py-2.5 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
                  >
                    Assign Overtime
                  </button>
                </div>
              </div>
            </div>
          </div>
        </ModalPortal>
      )}
    </div>
  );
}

export function OvertimeManagement() {
  return <OvertimeRequests />;
}

// Default export for compatibility
export default OvertimeManagement;
