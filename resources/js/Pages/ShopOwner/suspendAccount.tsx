import React, { useState, useMemo, useEffect } from "react";
import { Head } from "@inertiajs/react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";

// Icons
const CheckIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
  </svg>
);

const XIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const LockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
  </svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ArrowUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

interface SuspensionRequest {
  id: number;
  employee_id: number;
  name: string;
  email: string;
  position?: string;
  reason: string;
  evidence?: string;
  status: "pending" | "approved" | "rejected";
  requested_at: string;
  requested_by: string;
  manager_status: string;
  manager_note?: string;
  manager_name: string;
  owner_status?: string;
  owner_note?: string;
}

interface MetricCardProps {
  title: string;
  value: number;
  change: number;
  changeType: "increase" | "decrease";
  icon: React.ComponentType<{ className?: string }>;
  color: "success" | "warning" | "info";
  description: string;
}

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
      case "success":
        return "from-green-500 to-emerald-600";
      case "warning":
        return "from-yellow-500 to-orange-600";
      case "info":
        return "from-blue-500 to-indigo-600";
      default:
        return "from-gray-500 to-gray-600";
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
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {value}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>
    </div>
  );
};

const SuspendAccount: React.FC = () => {
  const [requests, setRequests] = useState<SuspensionRequest[]>([]);
  const [filteredRequests, setFilteredRequests] = useState<SuspensionRequest[]>([]);
  const [loading, setLoading] = useState(false);
  const [statusFilter, setStatusFilter] = useState<"all" | "pending" | "approved" | "rejected">("pending");
  const [searchQuery, setSearchQuery] = useState("");
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [detailsModalOpen, setDetailsModalOpen] = useState(false);
  const [rejectionModalOpen, setRejectionModalOpen] = useState(false);
  const [approvalModalOpen, setApprovalModalOpen] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<SuspensionRequest | null>(null);
  const [rejectionReason, setRejectionReason] = useState("");
  const [approvalNote, setApprovalNote] = useState("");

  // Fetch requests from API
  const fetchRequests = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (statusFilter !== 'all') params.append('status', statusFilter);
      if (searchQuery) params.append('search', searchQuery);

      const response = await fetch(`/api/shop-owner/suspension-requests?${params}`);
      if (!response.ok) throw new Error('Failed to fetch requests');
      
      const data = await response.json();
      const mappedRequests = data.data.map((req: any) => ({
        id: req.id,
        employee_id: req.employee_id,
        name: req.name,
        email: req.email,
        position: req.position,
        reason: req.reason,
        evidence: req.evidence,
        status: req.status,
        requested_at: req.requested_at,
        requested_by: req.requested_by,
        manager_status: req.manager_status,
        manager_note: req.manager_note,
        manager_name: req.manager_name,
      }));
      setRequests(mappedRequests);
    } catch (error) {
      console.error('Error fetching requests:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to fetch suspension requests',
      });
    } finally {
      setLoading(false);
    }
  };

  // Load requests on mount and when filters change
  useEffect(() => {
    fetchRequests();
  }, [statusFilter, searchQuery]);

  // Filter requests
  useEffect(() => {
    let filtered = requests;

    if (statusFilter !== "all") {
      filtered = filtered.filter((req) => req.status === statusFilter);
    }

    if (searchQuery) {
      filtered = filtered.filter(
        (req) =>
          req.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
          req.email.toLowerCase().includes(searchQuery.toLowerCase())
      );
    }

    setFilteredRequests(filtered);
  }, [requests, statusFilter, searchQuery]);

  // Calculate metrics
  const metrics = useMemo(() => {
    const total = requests.length;
    const pending = requests.filter((r) => r.status === "pending").length;
    const approved = requests.filter((r) => r.status === "approved").length;
    const rejected = requests.filter((r) => r.status === "rejected").length;

    return {
      pending,
      approved,
      rejected,
      pendingChange: pending > 0 ? 5 : -2,
      approvedChange: approved > 0 ? 10 : 0,
      rejectedChange: rejected > 0 ? 3 : 0,
    };
  }, [requests]);

  const handleApprove = (request: SuspensionRequest) => {
    setSelectedRequest(request);
    setDetailsModalOpen(false);
    setApprovalNote("");
    setApprovalModalOpen(true);
  };

  const confirmApprove = async () => {
    if (!approvalNote.trim()) {
      Swal.fire({
        title: "Error",
        text: "Please provide a reason for approval",
        icon: "error",
        confirmButtonColor: "#ef4444",
      });
      return;
    }

    if (!selectedRequest) return;

    try {
      const response = await fetch(`/api/shop-owner/suspension-requests/${selectedRequest.id}/review`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          action: 'approve',
          note: approvalNote.trim(),
        }),
      });

      if (!response.ok) throw new Error('Failed to approve request');
      
      const data = await response.json();
      
      Swal.fire({
        icon: 'success',
        title: 'Success',
        text: `${selectedRequest.name}'s account suspension approved and account is now suspended`,
        confirmButtonColor: '#10b981',
      });
      
      setApprovalModalOpen(false);
      setSelectedRequest(null);
      setApprovalNote("");
      fetchRequests();
    } catch (error) {
      console.error('Error approving request:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to approve suspension request',
      });
    }
  };

  const handleReject = (request: SuspensionRequest) => {
    setSelectedRequest(request);
    setDetailsModalOpen(false);
    setRejectionModalOpen(true);
  };

  const handleViewDetails = (request: SuspensionRequest) => {
    setSelectedRequest(request);
    setDetailsModalOpen(true);
  };

  const confirmReject = async () => {
    if (!rejectionReason.trim()) {
      Swal.fire({
        title: "Error",
        text: "Please provide a reason for rejection",
        icon: "error",
        confirmButtonColor: "#ef4444",
      });
      return;
    }

    if (!selectedRequest) return;

    try {
      const response = await fetch(`/api/shop-owner/suspension-requests/${selectedRequest.id}/review`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          action: 'reject',
          note: rejectionReason.trim(),
        }),
      });

      if (!response.ok) throw new Error('Failed to reject request');
      
      const data = await response.json();
      
      Swal.fire({
        icon: 'info',
        title: 'Rejected',
        text: `${selectedRequest.name}'s suspension request was rejected`,
        confirmButtonColor: '#ef4444',
      });
      
      setRejectionModalOpen(false);
      setRejectionReason("");
      setSelectedRequest(null);
      fetchRequests();
    } catch (error) {
      console.error('Error rejecting request:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to reject suspension request',
      });
    }
  };

  const statusColors: Record<string, string> = {
    pending: "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400",
    approved: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
    rejected: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Suspend Accounts" />

      <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 p-6">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-4xl font-bold text-gray-900 dark:text-white mb-2">
            Suspend Accounts
          </h1>
          <p className="text-gray-600 dark:text-gray-400">
            Review and approve/reject account suspension requests
          </p>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <MetricCard
            title="Pending Suspensions"
            value={metrics.pending}
            change={metrics.pendingChange}
            changeType={metrics.pendingChange > 0 ? "increase" : "decrease"}
            icon={ClockIcon}
            color="warning"
            description="Awaiting approval"
          />
          <MetricCard
            title="Approved Suspensions"
            value={metrics.approved}
            change={metrics.approvedChange}
            changeType="increase"
            icon={CheckIcon}
            color="success"
            description="Active suspensions"
          />
          <MetricCard
            title="Rejected Suspensions"
            value={metrics.rejected}
            change={metrics.rejectedChange}
            changeType="increase"
            icon={XIcon}
            color="info"
            description="Rejected requests"
          />
        </div>

        {/* Filters and Search */}
        <div className="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 mb-8 shadow-sm">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* Search */}
            <div>
              <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                Search
              </label>
              <input
                type="text"
                placeholder="Search by name or email..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500"
              />
            </div>

            {/* Status Filter */}
            <div>
              <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                Status
              </label>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value as any)}
                className="w-full px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500"
              >
                <option value="all">All Requests</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
          </div>
        </div>

        {/* Requests Table */}
        <div className="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                    Name
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                    Email
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                    Reason
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                    Requested
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                    Status
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                {filteredRequests.length > 0 ? (
                  filteredRequests.map((request) => (
                    <tr
                      key={request.id}
                      className="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                    >
                      <td className="px-6 py-4 text-sm text-gray-900 dark:text-white font-medium">
                        {request.name}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        {request.email}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        {request.reason}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        {request.requested_at}
                      </td>
                      <td className="px-6 py-4">
                        <span
                          className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ${
                            statusColors[request.status]
                          }`}
                        >
                          {request.status.charAt(0).toUpperCase() + request.status.slice(1)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <button
                          onClick={() => handleViewDetails(request)}
                          className="inline-flex items-center justify-center p-2 text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/30 rounded-lg transition-colors"
                          title="View Details"
                        >
                          <EyeIcon className="size-5" />
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td
                      colSpan={6}
                      className="px-6 py-8 text-center text-gray-600 dark:text-gray-400"
                    >
                      No suspension requests found
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Details Modal */}
      {detailsModalOpen && selectedRequest && (
        <div className="fixed inset-0 flex items-center justify-center z-[100000] p-4">
          <div className="fixed inset-0 bg-black/10 backdrop-blur-sm z-[100000]" onClick={() => { setDetailsModalOpen(false); setSelectedRequest(null); }}></div>
          <div className="bg-white dark:bg-gray-800 rounded-2xl max-w-lg w-full p-6 shadow-xl relative z-[100001]">
            <div className="flex items-center justify-between mb-6">
              <h3 className="text-2xl font-bold text-gray-900 dark:text-white">
                Suspension Request Details
              </h3>
              <button
                onClick={() => {
                  setDetailsModalOpen(false);
                  setSelectedRequest(null);
                }}
                className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
              >
                <XIcon className="size-6" />
              </button>
            </div>

            {/* Request Details */}
            <div className="space-y-4 mb-6">
              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Account Name
                </p>
                <p className="text-lg font-bold text-gray-900 dark:text-white">
                  {selectedRequest.name}
                </p>
              </div>

              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Email Address
                </p>
                <p className="text-gray-700 dark:text-gray-300 font-mono">
                  {selectedRequest.email}
                </p>
              </div>

              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Suspension Reason
                </p>
                <p className="text-gray-700 dark:text-gray-300">
                  {selectedRequest.reason}
                </p>
              </div>

              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Requested Date
                </p>
                <p className="text-gray-700 dark:text-gray-300">
                  {selectedRequest.requested_at}
                </p>
              </div>

              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Requested By
                </p>
                <p className="text-gray-700 dark:text-gray-300">
                  {selectedRequest.requested_by}
                </p>
              </div>

              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Manager Review
                </p>
                <div className="space-y-2">
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    <span className="font-semibold">Reviewed by:</span> {selectedRequest.manager_name}
                  </p>
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    <span className="font-semibold">Status:</span>{" "}
                    <span className={`inline-block px-2 py-1 rounded text-xs font-semibold ${
                      selectedRequest.manager_status === 'approved' 
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                        : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                    }`}>
                      {selectedRequest.manager_status}
                    </span>
                  </p>
                  {selectedRequest.manager_note && (
                    <p className="text-sm text-gray-700 dark:text-gray-300">
                      <span className="font-semibold">Note:</span> {selectedRequest.manager_note}
                    </p>
                  )}
                </div>
              </div>

              <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                  Status
                </p>
                <span
                  className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ${
                    statusColors[selectedRequest.status]
                  }`}
                >
                  {selectedRequest.status.charAt(0).toUpperCase() + selectedRequest.status.slice(1)}
                </span>
              </div>

              {selectedRequest.status === "approved" && (
                <>
                  <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                    <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                      Owner Approval Status
                    </p>
                    <p className="text-gray-700 dark:text-gray-300">
                      {selectedRequest.owner_status}
                    </p>
                  </div>

                  {selectedRequest.owner_note && (
                    <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                      <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                        Owner Note
                      </p>
                      <p className="text-gray-700 dark:text-gray-300">
                        {selectedRequest.owner_note}
                      </p>
                    </div>
                  )}
                </>
              )}

              {selectedRequest.status === "rejected" && (
                <div className="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                  <p className="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold mb-1">
                    Rejection Reason
                  </p>
                  <p className="text-gray-700 dark:text-gray-300">
                    {selectedRequest.owner_note || "No reason provided"}
                  </p>
                </div>
              )}
            </div>

            {/* Action Buttons */}
            {selectedRequest.status === "pending" && (
              <div className="flex gap-3 justify-end">
                <button
                  onClick={() => handleReject(selectedRequest)}
                  className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-semibold flex items-center justify-center gap-2"
                >
                  <XIcon className="size-4" />
                  Reject
                </button>
                <button
                  onClick={() => handleApprove(selectedRequest)}
                  className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-semibold flex items-center justify-center gap-2"
                >
                  <CheckIcon className="size-4" />
                  Approve
                </button>
                <button
                  onClick={() => {
                    setDetailsModalOpen(false);
                    setSelectedRequest(null);
                  }}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-semibold"
                >
                  Cancel
                </button>
              </div>
            )}
            {selectedRequest.status !== "pending" && (
              <button
                onClick={() => {
                  setDetailsModalOpen(false);
                  setSelectedRequest(null);
                }}
                className="w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors font-semibold"
              >
                Close
              </button>
            )}
          </div>
        </div>
      )}

      {rejectionModalOpen && selectedRequest && (
        <div className="fixed inset-0 flex items-center justify-center z-[100000] p-4">
          <div className="fixed inset-0 bg-black/10 backdrop-blur-sm z-[100000]" onClick={() => { setRejectionModalOpen(false); setRejectionReason(""); }}></div>
          <div className="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-xl relative z-[100001]">
            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-4">
              Reject Suspension Request
            </h3>
            <p className="text-gray-600 dark:text-gray-400 mb-4">
              Are you sure you want to reject the suspension request for{" "}
              <strong>{selectedRequest?.name}</strong>?
            </p>
            <textarea
              value={rejectionReason}
              onChange={(e) => setRejectionReason(e.target.value)}
              placeholder="Provide a reason for rejection..."
              className="w-full px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 mb-4"
              rows={4}
            />
            <div className="flex gap-3 justify-end">
              <button
                onClick={confirmReject}
                className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-semibold"
              >
                Reject
              </button>
              <button
                onClick={() => {
                  setRejectionModalOpen(false);
                  setRejectionReason("");
                  handleViewDetails(selectedRequest);
                }}
                className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-semibold"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
      {approvalModalOpen && selectedRequest && (
        <div className="fixed inset-0 flex items-center justify-center z-[100000] p-4">
          <div className="fixed inset-0 bg-black/10 backdrop-blur-sm z-[100000]" onClick={() => { setApprovalModalOpen(false); setApprovalNote(""); }}></div>
          <div className="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-xl relative z-[100001]">
            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-4">
              Approve Suspension Request
            </h3>
            <p className="text-gray-600 dark:text-gray-400 mb-4">
              You are approving the suspension request for <strong>{selectedRequest?.name}</strong>.
            </p>
            <textarea
              value={approvalNote}
              onChange={(e) => setApprovalNote(e.target.value)}
              placeholder="Add an optional note for approval..."
              className="w-full px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 mb-4"
              rows={4}
            />
            <div className="flex gap-3 justify-end">
              <button
                onClick={confirmApprove}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-semibold"
              >
                Approve
              </button>
              <button
                onClick={() => {
                  setApprovalModalOpen(false);
                  setApprovalNote("");
                  handleViewDetails(selectedRequest);
                }}
                className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-semibold"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayoutShopOwner>
  );
};

export default SuspendAccount;
