import React, { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import Swal from "sweetalert2";

type LeaveStatus = "pending" | "approved" | "rejected";
type LeaveType = "vacation" | "sick" | "personal" | "maternity" | "paternity" | "unpaid";

type LeaveRequest = {
  id: number;
  employeeId: number;
  employeeName: string;
  department: string;
  leaveType: LeaveType;
  startDate: string;
  endDate: string;
  noOfDays: number;
  reason: string;
  status: LeaveStatus;
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
const transformLeaveFromApi = (apiLeave: any): LeaveRequest => {
  // Build employee name from employee relationship
  const employeeName = apiLeave.employee 
    ? `${apiLeave.employee.first_name || ''} ${apiLeave.employee.last_name || ''}`.trim()
    : 'Unknown';
  
  const department = apiLeave.employee?.department || 'N/A';
  
  return {
    id: apiLeave.id,
    employeeId: apiLeave.employee_id,
    employeeName: employeeName,
    department: department,
    leaveType: apiLeave.leave_type as LeaveType,
    startDate: apiLeave.start_date,
    endDate: apiLeave.end_date,
    noOfDays: apiLeave.no_of_days || 0,
    reason: apiLeave.reason || '',
    status: apiLeave.status as LeaveStatus,
    approvedBy: apiLeave.approved_by,
    approvalDate: apiLeave.approval_date,
    rejectionReason: apiLeave.rejection_reason,
    createdAt: apiLeave.created_at,
  };
};

export function LeaveRequests() {
  const [leaveRequestsState, setLeaveRequestsState] = useState<LeaveRequest[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedStatus, setSelectedStatus] = useState<LeaveStatus | "">("");
  const [selectedLeaveType, setSelectedLeaveType] = useState<LeaveType | "">("");
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<LeaveRequest | null>(null);
  const [isRejectModalOpen, setIsRejectModalOpen] = useState(false);
  const [requestToReject, setRequestToReject] = useState<LeaveRequest | null>(null);
  const [rejectionReason, setRejectionReason] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);
  const [paginationMeta, setPaginationMeta] = useState<any>(null);

  // Fetch leave requests from API
  useEffect(() => {
    const fetchLeaveRequests = async () => {
      setIsLoading(true);
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const params = new URLSearchParams();
        if (searchTerm) params.append('search', searchTerm);
        if (selectedStatus) params.append('status', selectedStatus);
        if (selectedLeaveType) params.append('leave_type', selectedLeaveType);
        params.append('page', currentPage.toString());
        params.append('per_page', itemsPerPage.toString());

        const response = await fetch(`/api/hr/leave-requests?${params.toString()}`, {
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
        console.log('Leave requests API response:', data);

        // Check if response has Laravel pagination structure
        if (data.data && Array.isArray(data.data)) {
          const transformedData = data.data.map(transformLeaveFromApi);
          setLeaveRequestsState(transformedData);
          setPaginationMeta({
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
          });
        } else if (Array.isArray(data)) {
          const transformedData = data.map(transformLeaveFromApi);
          setLeaveRequestsState(transformedData);
          setPaginationMeta(null);
        } else {
          console.error('Unexpected API response format:', data);
          setLeaveRequestsState([]);
        }
      } catch (error) {
        console.error('Error fetching leave requests:', error);
        setLeaveRequestsState([]);
      } finally {
        setIsLoading(false);
      }
    };

    fetchLeaveRequests();
  }, [searchTerm, selectedStatus, selectedLeaveType, currentPage, itemsPerPage]);

  // Filter requests - only used for client-side filtering when no server pagination
  const filteredRequests = useMemo(() => {
    // If server pagination is active, return data as-is (filtering done server-side)
    if (paginationMeta) {
      return leaveRequestsState;
    }
    
    // Client-side filtering
    return leaveRequestsState.filter((request) => {
      const matchesSearch =
        request.employeeName.toLowerCase().includes(searchTerm.toLowerCase()) ||
        request.reason.toLowerCase().includes(searchTerm.toLowerCase()) ||
        request.department.toLowerCase().includes(searchTerm.toLowerCase());

      const matchesStatus = !selectedStatus || request.status === selectedStatus;
      const matchesType = !selectedLeaveType || request.leaveType === selectedLeaveType;

      return matchesSearch && matchesStatus && matchesType;
    });
  }, [leaveRequestsState, searchTerm, selectedStatus, selectedLeaveType, paginationMeta]);

  // Pagination calculations
  const totalPages = paginationMeta ? paginationMeta.last_page : Math.ceil(filteredRequests.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedRequests = paginationMeta ? filteredRequests : filteredRequests.slice(startIndex, endIndex);

  // Calculate stats
  const stats = {
    total: paginationMeta ? paginationMeta.total : leaveRequestsState.length,
    pending: leaveRequestsState.filter(r => r.status === "pending").length,
    approved: leaveRequestsState.filter(r => r.status === "approved").length,
    rejected: leaveRequestsState.filter(r => r.status === "rejected").length,
  };

  const handleApprove = async (request: LeaveRequest) => {
    const result = await Swal.fire({
      title: "Approve Leave Request?",
      text: `Approve ${request.noOfDays}-day ${request.leaveType} leave for ${request.employeeName}?`,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#10b981",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, approve",
      cancelButtonText: "Cancel",
    });
    
    if (result.isConfirmed) {
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const response = await fetch(`/api/hr/leave-requests/${request.id}/approve`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
            'Accept': 'application/json',
          },
          credentials: 'include',
        });

        if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.error || errorData.message || 'Failed to approve leave request');
        }

        // Update local state
        setLeaveRequestsState((prev) =>
          prev.map((req) =>
            req.id === request.id
              ? { ...req, status: "approved" as LeaveStatus, approvalDate: new Date().toISOString() }
              : req
          )
        );
        
        Swal.fire({
          icon: "success",
          title: "Approved!",
          text: "Leave request has been approved.",
          timer: 2000,
          showConfirmButton: false,
        });
      } catch (error: any) {
        console.error('Error approving leave request:', error);
        Swal.fire({
          icon: "error",
          title: "Approval Failed",
          text: error.message || 'An error occurred while approving the leave request.',
        });
      }
    }
  };

  const handleReject = (request: LeaveRequest) => {
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
      
      const response = await fetch(`/api/hr/leave-requests/${requestToReject.id}/reject`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          reason: rejectionReason,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || errorData.message || 'Failed to reject leave request');
      }

      // Update local state
      setLeaveRequestsState((prev) =>
        prev.map((req) =>
          req.id === requestToReject.id
            ? { ...req, status: "rejected" as LeaveStatus, rejectionReason: rejectionReason }
            : req
        )
      );
      
      setIsRejectModalOpen(false);
      setRequestToReject(null);
      setRejectionReason("");
      
      Swal.fire({
        icon: "success",
        title: "Rejected!",
        text: "Leave request has been rejected.",
        timer: 2000,
        showConfirmButton: false,
      });
    } catch (error: any) {
      console.error('Error rejecting leave request:', error);
      Swal.fire({
        icon: "error",
        title: "Rejection Failed",
        text: error.message || 'An error occurred while rejecting the leave request.',
      });
    }
  };

  const getLeaveTypeColor = (type: LeaveType) => {
    const colors = {
      vacation: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
      sick: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
      personal: "bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400",
      maternity: "bg-pink-100 text-pink-800 dark:bg-pink-900/30 dark:text-pink-400",
      paternity: "bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400",
      unpaid: "bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400",
    };
    return colors[type];
  };

  const getStatusIcon = (status: LeaveStatus) => {
    switch (status) {
      case "approved":
        return <CheckCircleIcon className="size-5 text-green-500" />;
      case "rejected":
        return <XCircleIcon className="size-5 text-red-500" />;
      default:
        return <ClockIcon className="size-5 text-yellow-500" />;
    }
  };

  const getStatusColor = (status: LeaveStatus) => {
    switch (status) {
      case "approved":
        return "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400";
      case "rejected":
        return "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400";
      default:
        return "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400";
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Leave Requests</h1>
        <p className="text-gray-600 dark:text-gray-400 mt-2">Manage and review employee leave requests</p>
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
          description="Leave requests this period"
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
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
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
              onChange={(e) => setSelectedStatus(e.target.value as LeaveStatus | "")}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
            >
              <option value="">All Status</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>

          {/* Leave Type Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Leave Type
            </label>
            <select
              value={selectedLeaveType}
              onChange={(e) => setSelectedLeaveType(e.target.value as LeaveType | "")}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
            >
              <option value="">All Types</option>
              <option value="vacation">Vacation</option>
              <option value="sick">Sick</option>
              <option value="personal">Personal</option>
              <option value="maternity">Maternity</option>
              <option value="paternity">Paternity</option>
              <option value="unpaid">Unpaid</option>
            </select>
          </div>
        </div>
      </div>

      {/* Leave Requests Table */}
      <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
              <tr>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Employee</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Leave Type</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Duration</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Days</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Reason</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                <th className="px-6 py-4 text-center text-sm font-semibold text-gray-900 dark:text-white">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {isLoading ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center">
                    <div className="flex flex-col items-center justify-center space-y-3">
                      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                      <p className="text-sm text-gray-500 dark:text-gray-400">Loading leave requests...</p>
                    </div>
                  </td>
                </tr>
              ) : paginatedRequests.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center">
                    <p className="text-sm text-gray-500 dark:text-gray-400">No leave requests found.</p>
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
                    <span className={`inline-flex px-3 py-1 text-xs font-semibold rounded-full ${getLeaveTypeColor(request.leaveType)}`}>
                      {request.leaveType.charAt(0).toUpperCase() + request.leaveType.slice(1)}
                    </span>
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm text-gray-900 dark:text-white">
                      {new Date(request.startDate).toLocaleDateString()} - {new Date(request.endDate).toLocaleDateString()}
                    </p>
                  </td>
                  <td className="px-6 py-4">
                    <p className="text-sm font-medium text-gray-900 dark:text-white">{request.noOfDays} days</p>
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
                      {request.status === "pending" && (
                        <>
                          <button
                            onClick={() => handleApprove(request)}
                            className="p-2 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors"
                            title="Approve"
                          >
                            <CheckCircleIcon className="size-5 text-green-600 dark:text-green-400" />
                          </button>
                          <button
                            onClick={() => handleReject(request)}
                            className="p-2 hover:bg-orange-50 dark:hover:bg-orange-900/20 rounded-lg transition-colors"
                            title="Reject"
                          >
                            <AlertIcon className="size-5 text-orange-600 dark:text-orange-400" />
                          </button>
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
                    Leave Request Details
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

                  {/* Leave Details Card */}
                  <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                      <CalendarIcon className="size-4 text-purple-600 dark:text-purple-400" />
                      Leave Details
                    </h4>
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Leave Type</p>
                        <span className={`inline-flex px-2 py-1 text-sm font-semibold rounded-full ${getLeaveTypeColor(selectedRequest.leaveType)}`}>
                          {selectedRequest.leaveType.charAt(0).toUpperCase() + selectedRequest.leaveType.slice(1)}
                        </span>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Duration</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{selectedRequest.noOfDays} days</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Start Date</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{new Date(selectedRequest.startDate).toLocaleDateString()}</p>
                      </div>
                      <div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">End Date</p>
                        <p className="text-base font-medium text-gray-900 dark:text-white">{new Date(selectedRequest.endDate).toLocaleDateString()}</p>
                      </div>
                    </div>
                  </div>

                  {/* Reason Section */}
                  <div className="border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <h4 className="text-base font-semibold text-gray-900 dark:text-white mb-3">Reason for Leave</h4>
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

                  {/* Close Button */}
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button
                      onClick={() => setIsViewModalOpen(false)}
                      className="w-full px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                    >
                      Close
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </ModalPortal>
      )}

      {/* Reject Leave Request Modal */}
      {isRejectModalOpen && requestToReject && (
        <ModalPortal>
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-xl w-full">
              <div className="p-6">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white mb-4">Reject Leave Request</h3>

                {/* Employee Info */}
                <div className="mb-4">
                  <p className="text-sm text-gray-700 dark:text-gray-300 mb-1">
                    <span className="font-medium">Employee:</span> {requestToReject.employeeName}
                  </p>
                  <p className="text-sm text-gray-700 dark:text-gray-300 mb-1">
                    <span className="font-medium">Leave Type:</span> {requestToReject.leaveType.charAt(0).toUpperCase() + requestToReject.leaveType.slice(1)}
                  </p>
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    <span className="font-medium">Duration:</span> {requestToReject.noOfDays} days
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
                    <option value="Insufficient Leave Balance">Insufficient Leave Balance</option>
                    <option value="Conflict with Project Timeline">Conflict with Project Timeline</option>
                    <option value="During Critical Period">During Critical Period</option>
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
                          ? `Your leave request has been rejected. Reason: ${rejectionReason}. Please contact your manager for more details.`
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
                        This action will reject the leave request. The employee will be notified of the rejection and the reason provided.
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
    </div>
  );
}

export function LeaveManagement() {
  return <LeaveRequests />;
}

// Default export for compatibility
export default LeaveManagement;
