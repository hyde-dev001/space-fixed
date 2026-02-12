import { Head } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useState, useEffect } from "react";
import Swal from "sweetalert2";
import { hasPermission } from "../../../utils/permissions";

// Mock data for pending repair service approval requests
const initialPendingRequests = [
  {
    id: 1,
    serviceName: "Premium Leather Conditioning",
    category: "Care",
    currentPrice: "₱550",
    requestedPrice: "₱650",
    duration: "90 min",
    requestedBy: "John Manager",
    requestDate: "2026-02-01",
    reason: "Premium materials cost increased by 18%",
    status: "Pending",
  },
  {
    id: 2,
    serviceName: "Deep Clean & Deodorize",
    category: "Care",
    currentPrice: "₱300",
    requestedPrice: "₱350",
    duration: "45 min",
    requestedBy: "Jane Supervisor",
    requestDate: "2026-02-01",
    reason: "Labor cost adjustment to match market standards",
    status: "Pending",
  },
  {
    id: 3,
    serviceName: "Sole Whitening",
    category: "Restoration",
    currentPrice: "₱450",
    requestedPrice: "₱420",
    duration: "60 min",
    requestedBy: "Mike Manager",
    requestDate: "2026-01-31",
    reason: "Promotional pricing to increase service uptake",
    status: "Pending",
  },
  {
    id: 4,
    serviceName: "Suede Treatment",
    category: "Restoration",
    currentPrice: "₱480",
    requestedPrice: "₱520",
    duration: "75 min",
    requestedBy: "Sarah Lead",
    requestDate: "2026-01-31",
    reason: "Specialized cleaning agents price increase",
    status: "Pending",
  },
];

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

const CloseIcon = ({ className }: { className?: string }) => (
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

const WrenchIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M21 6.5a4.5 4.5 0 01-6.36 4.09l-6.8 6.8a2 2 0 11-2.83-2.83l6.8-6.8A4.5 4.5 0 1116.5 3a4.49 4.49 0 014.5 3.5z" />
  </svg>
);

const DocumentIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
  </svg>
);

interface RepairPriceRequest {
  id: number;
  serviceName: string;
  category: string;
  currentPrice: string;
  requestedPrice: string;
  duration: string;
  requestedBy: string;
  requestDate: string;
  reason: string;
  status: string;
  rejectionReason?: string;
}

type MetricColor = "success" | "warning" | "info";
type ChangeType = "increase" | "decrease";

interface MetricCardProps {
  title: string;
  value: number | string;
  change: number;
  changeType: ChangeType;
  icon: ComponentType<{ className?: string }>;
  color: MetricColor;
  description: string;
}

const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }: MetricCardProps) => {
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
            {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
            {Math.abs(change)}%
          </div>
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">{value}</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>
    </div>
  );
};

export default function RepairPriceApproval() {
  const [requests, setRequests] = useState<RepairPriceRequest[]>(initialPendingRequests);
  const [currentPage, setCurrentPage] = useState(1);
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<RepairPriceRequest | null>(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("Pending");

  // Check permissions on mount
  useEffect(() => {
    if (!hasPermission('view-pricing') && !hasPermission('edit-pricing') && !hasPermission('manage-service-pricing')) {
      Swal.fire({
        icon: 'error',
        title: 'Access Denied',
        text: 'You do not have permission to access pricing approvals.',
        confirmButtonColor: '#000000',
      }).then(() => {
        window.history.back();
      });
      return;
    }
  }, []);

  // Filter data
  const filteredData = requests.filter((item) => {
    const matchesSearch = item.serviceName.toLowerCase().includes(searchQuery.toLowerCase()) || 
                         item.requestedBy.toLowerCase().includes(searchQuery.toLowerCase()) ||
                         item.category.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesStatus = statusFilter === "All" || item.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const itemsPerPage = 5;
  const totalPages = Math.ceil(filteredData.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedRequests = filteredData.slice(startIndex, startIndex + itemsPerPage);

  const pendingCount = requests.filter(r => r.status === "Pending").length;
  const approvedCount = requests.filter(r => r.status === "Approved").length;
  const rejectedCount = requests.filter(r => r.status === "Rejected").length;

  const handleViewClick = (request: RepairPriceRequest) => {
    setSelectedRequest(request);
    setViewModalOpen(true);
  };

  const calculatePriceChange = (current: string, requested: string) => {
    const currentNum = parseFloat(current.replace(/[₱,]/g, ""));
    const requestedNum = parseFloat(requested.replace(/[₱,]/g, ""));
    const change = requestedNum - currentNum;
    const percentage = ((change / currentNum) * 100).toFixed(1);
    return { change, percentage };
  };

  const handleApprove = async (request: RepairPriceRequest) => {
    const result = await Swal.fire({
      title: "Approve Price Change?",
      html: `
        <div style="text-align: left; margin-top: 1rem;">
          <p style="margin-bottom: 0.5rem;"><strong>Service:</strong> ${request.serviceName}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Category:</strong> ${request.category}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Current Price:</strong> ${request.currentPrice}</p>
          <p style="margin-bottom: 0.5rem;"><strong>New Price:</strong> ${request.requestedPrice}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Requested by:</strong> ${request.requestedBy}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Reason:</strong> ${request.reason}</p>
        </div>
      `,
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#10b981",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Approve",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
      setRequests(requests.map(r => 
        r.id === request.id ? { ...r, status: "Approved" } : r
      ));
      
      Swal.fire({
        title: "Approved!",
        text: "The repair service price change has been approved successfully.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleReject = async (request: RepairPriceRequest) => {
    const { value: reason } = await Swal.fire({
      title: "Reject Price Change",
      html: `
        <div style="text-align: left; margin-bottom: 1rem;">
          <p style="margin-bottom: 0.5rem;"><strong>Service:</strong> ${request.serviceName}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Requested Price:</strong> ${request.requestedPrice}</p>
        </div>
      `,
      input: "textarea",
      inputLabel: "Rejection Reason",
      inputPlaceholder: "Enter the reason for rejection...",
      inputAttributes: {
        "aria-label": "Enter the reason for rejection",
      },
      showCancelButton: true,
      confirmButtonColor: "#ef4444",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Reject",
      cancelButtonText: "Cancel",
      inputValidator: (value) => {
        if (!value) {
          return "Please provide a reason for rejection";
        }
      },
    });

    if (reason) {
      setRequests(requests.map(r => 
        r.id === request.id ? { ...r, status: "Rejected", rejectionReason: reason } : r
      ));
      
      Swal.fire({
        title: "Rejected",
        text: "The repair service price change has been rejected.",
        icon: "info",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  return (
    <>
      <Head title="Repair Service Price Approvals - Solespace ERP" />
      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1 text-gray-900 dark:text-white">Repair Service Price Approvals</h1>
            <p className="text-gray-600 dark:text-gray-400">Review and approve repair service pricing change requests</p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              Finance Only
            </span>
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200">
              Approval Required
            </span>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <MetricCard
            title="Pending Approvals"
            value={pendingCount}
            change={12}
            changeType="increase"
            icon={ClockIcon}
            color="warning"
            description="Awaiting your review"
          />
          <MetricCard
            title="Approved"
            value={approvedCount}
            change={8}
            changeType="increase"
            icon={CheckIcon}
            color="success"
            description="This month"
          />
          <MetricCard
            title="Rejected"
            value={rejectedCount}
            change={3}
            changeType="decrease"
            icon={XIcon}
            color="info"
            description="This month"
          />
        </div>

        {/* Main Content */}
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Repair Service Price Change Requests</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">Review and take action on repair service pricing requests</p>
          </div>

          {/* Search and Filter */}
          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by service name, category, or requestor..."
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              />
            </div>
            <div className="sm:w-48">
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setCurrentPage(1);
                }}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Status</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
              </select>
            </div>
          </div>

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-3 font-medium">Service Name</th>
                  <th className="pb-3 font-medium">Category</th>
                  <th className="pb-3 font-medium">Current Price</th>
                  <th className="pb-3 font-medium">Requested Price</th>
                  <th className="pb-3 font-medium">Change</th>
                  <th className="pb-3 font-medium">Requested By</th>
                  <th className="pb-3 font-medium">Date</th>
                  <th className="pb-3 font-medium">Status</th>
                  <th className="pb-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {paginatedRequests.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="py-8 text-center text-gray-500 dark:text-gray-400">
                      No requests found
                    </td>
                  </tr>
                ) : (
                  paginatedRequests.map((request) => {
                    const { change, percentage } = calculatePriceChange(request.currentPrice, request.requestedPrice);
                    const isIncrease = change > 0;

                    return (
                      <tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td className="py-4">
                          <div>
                            <p className="font-medium text-gray-900 dark:text-white">{request.serviceName}</p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">{request.duration}</p>
                          </div>
                        </td>
                        <td className="py-4">
                          <span className="px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs font-medium">
                            {request.category}
                          </span>
                        </td>
                        <td className="py-4 text-gray-700 dark:text-gray-300">{request.currentPrice}</td>
                        <td className="py-4 text-gray-900 dark:text-white font-semibold">{request.requestedPrice}</td>
                        <td className="py-4">
                          <span
                            className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold ${
                              isIncrease
                                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
                            }`}
                          >
                            {isIncrease ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
                            {percentage}%
                          </span>
                        </td>
                        <td className="py-4 text-gray-700 dark:text-gray-300">{request.requestedBy}</td>
                        <td className="py-4 text-gray-600 dark:text-gray-400 text-xs">{request.requestDate}</td>
                        <td className="py-4">
                          <span
                            className={`px-2 py-1 rounded-full text-xs font-semibold ${
                              request.status === "Pending"
                                ? "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-200"
                                : request.status === "Approved"
                                ? "bg-green-50 text-green-700 dark:bg-green-900/40 dark:text-green-200"
                                : "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                            }`}
                          >
                            {request.status}
                          </span>
                        </td>
                        <td className="py-4">
                          <button
                            onClick={() => handleViewClick(request)}
                            className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                            title="View Details"
                          >
                            <EyeIcon className="w-5 h-5" />
                          </button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {filteredData.length > 0 && (
            <div className="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredData.length)}</span> of{" "}
                  <span className="font-medium">{filteredData.length}</span>
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
                    if (page === 1 || page === totalPages || (page >= currentPage - 1 && page <= currentPage + 1)) {
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

        {/* View Modal */}
        {viewModalOpen && selectedRequest && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
              <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Repair Service Price Change Request Details</h2>
                <button
                  onClick={() => setViewModalOpen(false)}
                  className="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
                >
                  <CloseIcon className="w-5 h-5" />
                </button>
              </div>
              <div className="p-6 space-y-6">
                {/* Service Info */}
                <div>
                  <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Service Name</p>
                  <p className="text-xl font-bold text-gray-900 dark:text-white">{selectedRequest.serviceName}</p>
                  <p className="text-sm text-gray-600 dark:text-gray-400">{selectedRequest.category} • {selectedRequest.duration}</p>
                </div>

                {/* Price Comparison */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Current Price</p>
                    <p className="text-2xl font-bold text-gray-900 dark:text-white">{selectedRequest.currentPrice}</p>
                  </div>
                  <div className="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Requested Price</p>
                    <p className="text-2xl font-bold text-gray-900 dark:text-white">{selectedRequest.requestedPrice}</p>
                  </div>
                </div>

                {/* Price Change Indicator */}
                <div className="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                  {(() => {
                    const { change, percentage } = calculatePriceChange(selectedRequest.currentPrice, selectedRequest.requestedPrice);
                    const isIncrease = change > 0;
                    return (
                      <div className="flex items-center justify-between">
                        <div>
                          <p className="text-sm font-medium text-gray-700 dark:text-gray-300">Price Change</p>
                          <p className={`text-xl font-bold ${isIncrease ? "text-green-600 dark:text-green-400" : "text-red-600 dark:text-red-400"}`}>
                            {isIncrease ? "+" : ""}₱{Math.abs(change).toFixed(2)} ({isIncrease ? "+" : ""}{percentage}%)
                          </p>
                        </div>
                        <div className="p-3 rounded-full border border-gray-200 dark:border-gray-700">
                          {isIncrease ? (
                            <ArrowUpIcon className={`w-8 h-8 ${isIncrease ? "text-green-600 dark:text-green-400" : "text-red-600 dark:text-red-400"}`} />
                          ) : (
                            <ArrowDownIcon className={`w-8 h-8 text-red-600 dark:text-red-400`} />
                          )}
                        </div>
                      </div>
                    );
                  })()}
                </div>

                {/* Request Details */}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Requested By</p>
                    <p className="text-base font-semibold text-gray-900 dark:text-white">{selectedRequest.requestedBy}</p>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Request Date</p>
                    <p className="text-base font-semibold text-gray-900 dark:text-white">{selectedRequest.requestDate}</p>
                  </div>
                </div>

                {/* Reason */}
                <div className="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-2">
                    <DocumentIcon className="w-4 h-4" />
                    Reason for Change
                  </p>
                  <p className="text-sm text-gray-600 dark:text-gray-400">{selectedRequest.reason}</p>
                </div>

                {/* Status */}
                <div>
                  <p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Status</p>
                  <span
                    className={`inline-block px-4 py-2 rounded-lg border text-sm font-semibold ${
                      selectedRequest.status === "Pending"
                        ? "border-yellow-300 text-yellow-700 dark:border-yellow-700 dark:text-yellow-400"
                        : selectedRequest.status === "Approved"
                        ? "border-green-300 text-green-700 dark:border-green-700 dark:text-green-400"
                        : "border-red-300 text-red-700 dark:border-red-700 dark:text-red-400"
                    }`}
                  >
                    {selectedRequest.status}
                  </span>
                </div>

                {/* Rejection Reason */}
                {selectedRequest.status === "Rejected" && selectedRequest.rejectionReason && (
                  <div className="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Rejection Reason</p>
                    <p className="text-sm text-gray-600 dark:text-gray-400">{selectedRequest.rejectionReason}</p>
                  </div>
                )}
              </div>
              <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                {selectedRequest.status === "Pending" ? (
                  <>
                    <button
                      onClick={() => {
                        setViewModalOpen(false);
                        handleReject(selectedRequest);
                      }}
                      className="flex-1 px-4 py-2.5 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-700 transition-colors flex items-center justify-center gap-2"
                    >
                      <XIcon className="w-5 h-5" />
                      Reject
                    </button>
                    <button
                      onClick={() => {
                        setViewModalOpen(false);
                        handleApprove(selectedRequest);
                      }}
                      className="flex-1 px-4 py-2.5 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 transition-colors flex items-center justify-center gap-2"
                    >
                      <CheckIcon className="w-5 h-5" />
                      Approve
                    </button>
                  </>
                ) : (
                  <button
                    onClick={() => setViewModalOpen(false)}
                    className="flex-1 px-4 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                  >
                    Close
                  </button>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  );
}
