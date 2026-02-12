import { Head } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useState, useEffect } from "react";
import Swal from "sweetalert2";
import { hasPermission } from "../../../utils/permissions";

// Mock data for pending approval requests
const initialPendingRequests = [
  {
    id: 1,
    item: "Converse",
    currentPrice: "₱2500",
    requestedPrice: "₱2800",
    requestedBy: "John Manager",
    requestDate: "2026-02-01",
    reason: "Supplier cost increased by 15%",
    status: "pending",
    image: "/images/product/product-01.jpg",
    financeReviewedBy: null,
    financeReviewedAt: null,
    financeNotes: null,
    ownerReviewedBy: null,
    ownerReviewedAt: null,
  },
  {
    id: 2,
    item: "Vans",
    currentPrice: "₱2900",
    requestedPrice: "₱3200",
    requestedBy: "Jane Supervisor",
    requestDate: "2026-02-01",
    reason: "Market demand increased, competitors raised prices",
    status: "pending",
    image: "/images/product/product-02.jpg",
    financeReviewedBy: null,
    financeReviewedAt: null,
    financeNotes: null,
    ownerReviewedBy: null,
    ownerReviewedAt: null,
  },
  {
    id: 3,
    item: "Skechers",
    currentPrice: "₱3500",
    requestedPrice: "₱3100",
    requestedBy: "Mike Manager",
    requestDate: "2026-01-31",
    reason: "Clearance sale to move old inventory",
    status: "finance_approved",
    image: "/images/product/product-03.jpg",
    financeReviewedBy: "Finance Manager",
    financeReviewedAt: "2026-02-02",
    financeNotes: "Margin acceptable for clearance",
    ownerReviewedBy: null,
    ownerReviewedAt: null,
  },
  {
    id: 4,
    item: "Fila",
    currentPrice: "₱2200",
    requestedPrice: "₱2600",
    requestedBy: "Sarah Lead",
    requestDate: "2026-01-31",
    reason: "New premium model with better features",
    status: "owner_approved",
    image: "/images/product/product-04.jpg",
    financeReviewedBy: "Finance Manager",
    financeReviewedAt: "2026-02-01",
    financeNotes: "Good margin improvement",
    ownerReviewedBy: "Shop Owner",
    ownerReviewedAt: "2026-02-02",
  },
  {
    id: 5,
    item: "New Balance",
    currentPrice: "₱4500",
    requestedPrice: "₱3800",
    requestedBy: "Tom Staff",
    requestDate: "2026-01-30",
    reason: "Competitor pricing pressure",
    status: "finance_rejected",
    image: "/images/product/product-01.jpg",
    financeReviewedBy: "Finance Manager",
    financeReviewedAt: "2026-01-31",
    financeNotes: null,
    ownerReviewedBy: null,
    ownerReviewedAt: null,
    rejectionReason: "Price reduction too steep, profit margin below 15%",
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

const TagIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M20.59 13.41l-6-6A2 2 0 0013.17 7H7a2 2 0 00-2 2v6.17a2 2 0 00.59 1.41l6 6a2 2 0 002.83 0l6.17-6.17a2 2 0 000-2.83zM8.5 11A1.5 1.5 0 118.5 8a1.5 1.5 0 010 3z" />
  </svg>
);

const TrendingUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
  </svg>
);

const DocumentIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
  </svg>
);

interface PriceRequest {
  id: number;
  item: string;
  currentPrice: string;
  requestedPrice: string;
  requestedBy: string;
  requestDate: string;
  reason: string;
  status: 'pending' | 'finance_approved' | 'finance_rejected' | 'owner_approved' | 'owner_rejected';
  image: string;
  financeReviewedBy: string | null;
  financeReviewedAt: string | null;
  financeNotes: string | null;
  ownerReviewedBy: string | null;
  ownerReviewedAt: string | null;
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
                ? "bg-green-100 text-green-700 dark:bg-green-900 dark:bg-opacity-30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900 dark:bg-opacity-30 dark:text-red-400"
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

export default function ShoePriceApproval() {
  const [requests, setRequests] = useState<PriceRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [selectedRequest, setSelectedRequest] = useState<PriceRequest | null>(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("pending");
  const [viewMode, setViewMode] = useState<"pending" | "recent">("pending"); // New: View toggle

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

  const fetchRequests = async () => {
    try {
      setLoading(true);
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const url = statusFilter === "All" 
        ? '/api/finance/price-changes' 
        : `/api/finance/price-changes?status=${statusFilter}`;

      const response = await fetch(url, {
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
      });

      if (!response.ok) {
        throw new Error('Failed to fetch price change requests');
      }

      const result = await response.json();
      const apiRequests = result.data.map((item: any) => ({
        id: item.id,
        item: item.product_name,
        currentPrice: `₱${parseFloat(item.current_price).toLocaleString()}`,
        requestedPrice: `₱${parseFloat(item.proposed_price).toLocaleString()}`,
        requestedBy: item.requester?.name || 'Unknown',
        requestDate: new Date(item.created_at).toISOString().split('T')[0],
        reason: item.reason,
        status: item.status,
        image: item.product?.main_image || '/images/product/product-01.jpg',
        financeReviewedBy: item.finance_reviewer?.name || null,
        financeReviewedAt: item.finance_reviewed_at ? new Date(item.finance_reviewed_at).toISOString().split('T')[0] : null,
        financeNotes: item.finance_notes,
        rejectionReason: item.finance_rejection_reason || item.owner_rejection_reason,
        ownerReviewedBy: item.owner_reviewer?.name || null,
        ownerReviewedAt: item.owner_reviewed_at ? new Date(item.owner_reviewed_at).toISOString().split('T')[0] : null,
      }));
      
      setRequests(apiRequests);
    } catch (error) {
      console.error('Error fetching price change requests:', error);
      Swal.fire({
        title: 'Error',
        text: 'Failed to load price change requests',
        icon: 'error',
        confirmButtonColor: '#000000',
      });
    } finally {
      setLoading(false);
    }
  };

  // Filter data based on view mode
  const filteredData = requests.filter((item) => {
    const matchesSearch = item.item.toLowerCase().includes(searchQuery.toLowerCase()) || 
                         item.requestedBy.toLowerCase().includes(searchQuery.toLowerCase());
    
    // Apply view mode filter
    let matchesViewMode = true;
    if (viewMode === 'pending') {
      matchesViewMode = item.status === 'pending'; // Awaiting Finance review
    } else if (viewMode === 'recent') {
      matchesViewMode = item.status === 'finance_approved' || item.status === 'owner_approved'; // Recently approved by Finance
    }
    
    return matchesSearch && matchesViewMode;
  });

  const itemsPerPage = 5;
  const totalPages = Math.ceil(filteredData.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedRequests = filteredData.slice(startIndex, startIndex + itemsPerPage);

  const pendingCount = requests.filter(r => r.status === "pending").length;
  const financeApprovedCount = requests.filter(r => r.status === "finance_approved").length;
  const approvedCount = requests.filter(r => r.status === "owner_approved").length;
  const rejectedCount = requests.filter(r => r.status === "finance_rejected" || r.status === "owner_rejected").length;

  const handleViewClick = (request: PriceRequest) => {
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

  const handleApprove = async (request: PriceRequest) => {
    const { value: notes } = await Swal.fire({
      title: "Approve & Forward to Owner",
      html: `
        <div style="text-align: left; margin-top: 1rem; margin-bottom: 1rem;">
          <p style="margin-bottom: 0.5rem;"><strong>Item:</strong> ${request.item}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Current Price:</strong> ${request.currentPrice}</p>
          <p style="margin-bottom: 0.5rem;"><strong>New Price:</strong> ${request.requestedPrice}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Requested by:</strong> ${request.requestedBy}</p>
          <p style="margin-bottom: 1rem;"><strong>Reason:</strong> ${request.reason}</p>
          <p style="color: #6b7280; font-size: 0.875rem;">This will forward the request to the Shop Owner for final approval.</p>
        </div>
      `,
      input: "textarea",
      inputLabel: "Finance Notes (Optional)",
      inputPlaceholder: "Add notes about margin analysis, profitability, etc...",
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#10b981",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Approve & Forward",
      cancelButtonText: "Cancel",
    });

    if (notes !== undefined) {
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(`/api/finance/price-changes/${request.id}/approve`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
          body: JSON.stringify({ notes: notes || null }),
        });

        if (!response.ok) {
          const error = await response.json();
          throw new Error(error.message || 'Failed to approve request');
        }

        const result = await response.json();
        
        // Close view modal if open
        setViewModalOpen(false);
        setSelectedRequest(null);

        // Show centered modal notification
        await Swal.fire({
          icon: 'success',
          title: 'Approved & Forwarded',
          text: `${request.item} sent to Shop Owner for final approval`,
          confirmButtonColor: '#000000',
        });

        // Refresh data to get updated list and metrics
        await fetchRequests();
      } catch (error: any) {
        console.error('Error approving request:', error);
        Swal.fire({
          title: 'Error',
          text: error.message || 'Failed to approve price change request',
          icon: 'error',
          confirmButtonColor: '#000000',
        });
      }
    }
  };

  const handleReject = async (request: PriceRequest) => {
    const { value: reason } = await Swal.fire({
      title: "Reject Price Change",
      html: `
        <div style="text-align: left; margin-top: 1rem; margin-bottom: 1rem;">
          <p style="margin-bottom: 0.5rem;"><strong>Item:</strong> ${request.item}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Current Price:</strong> ${request.currentPrice}</p>
          <p style="margin-bottom: 0.5rem;"><strong>New Price:</strong> ${request.requestedPrice}</p>
          <p style="margin-bottom: 0.5rem;"><strong>Requested by:</strong> ${request.requestedBy}</p>
          <p style="margin-bottom: 1rem;"><strong>Reason:</strong> ${request.reason}</p>
          <p style="margin-top: 1rem; color: #ef4444; font-size: 0.875rem;">
            ⚠️ This will reject the request and notify the STAFF member.
          </p>
        </div>
      `,
      input: "textarea",
      inputLabel: "Rejection Reason (Required)",
      inputPlaceholder: "E.g., Profit margin below threshold, pricing strategy mismatch...",
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
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(`/api/finance/price-changes/${request.id}/reject`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
          body: JSON.stringify({ reason }),
        });

        if (!response.ok) {
          const error = await response.json();
          throw new Error(error.message || 'Failed to reject request');
        }

        const result = await response.json();
        
        // Close view modal if open
        setViewModalOpen(false);
        setSelectedRequest(null);

        // Show centered modal notification
        await Swal.fire({
          icon: 'info',
          title: 'Request Rejected',
          text: `${request.item} price change rejected by Finance`,
          confirmButtonColor: '#000000',
        });

        // Refresh data to get updated list and metrics
        await fetchRequests();
      } catch (error: any) {
        console.error('Error rejecting request:', error);
        Swal.fire({
          title: 'Error',
          text: error.message || 'Failed to reject price change request',
          icon: 'error',
          confirmButtonColor: '#000000',
        });
      }
    }
  };

  // Keyboard support for modal
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && viewModalOpen) {
        setViewModalOpen(false);
        setSelectedRequest(null);
      }
    };

    if (viewModalOpen) {
      document.addEventListener('keydown', handleKeyDown);
      // Prevent body scroll when modal is open
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = 'unset';
    };
  }, [viewModalOpen]);

  return (
    <>
      <Head title="Shoe Price Approvals - Solespace ERP" />
      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold text-gray-900 dark:text-white">Price Approval Requests</h1>
            <p className="text-gray-600 dark:text-gray-400 mt-1">Review and approve price changes from STAFF</p>
          </div>
          {/* View Toggle */}
          <div className="flex gap-2 bg-gray-100 dark:bg-gray-800 p-1 rounded-lg">
            <button
              onClick={() => setViewMode('pending')}
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                viewMode === 'pending'
                  ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                  : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'
              }`}
            >
              Pending Review
            </button>
            <button
              onClick={() => setViewMode('recent')}
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                viewMode === 'recent'
                  ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                  : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'
              }`}
            >
              Recently Approved
            </button>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <MetricCard
            title="Pending Finance Review"
            value={pendingCount}
            change={12}
            changeType="increase"
            icon={ClockIcon}
            color="warning"
            description="Awaiting your review"
          />
          <MetricCard
            title="Forwarded to Owner"
            value={financeApprovedCount}
            change={5}
            changeType="increase"
            icon={TrendingUpIcon}
            color="info"
            description="Awaiting owner approval"
          />
          <MetricCard
            title="Fully Approved"
            value={approvedCount}
            change={8}
            changeType="increase"
            icon={CheckIcon}
            color="success"
            description="Owner approved & applied"
          />
          <MetricCard
            title="Rejected"
            value={rejectedCount}
            change={3}
            changeType="decrease"
            icon={XIcon}
            color="warning"
            description="Finance or owner rejected"
          />
        </div>

        {/* Main Content */}
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Price Change Requests</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">Review and take action on shoe pricing requests</p>
          </div>

          {/* Search Filter */}
          <div className="mb-4">
            <input
              type="text"
              placeholder="Search by item or requestor..."
              value={searchQuery}
              onChange={(e) => {
                setSearchQuery(e.target.value);
                setCurrentPage(1);
              }}
              className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
            />
          </div>

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-3 font-medium">Item</th>
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
                    <td colSpan={8} className="py-8 text-center text-gray-500 dark:text-gray-400">
                      No requests found
                    </td>
                  </tr>
                ) : (
                  paginatedRequests.map((request) => {
                    const { change, percentage } = calculatePriceChange(request.currentPrice, request.requestedPrice);
                    const isIncrease = change > 0;

                    return (
                      <tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800 dark:hover:bg-opacity-50 transition-colors">
                        <td className="py-4">
                          <div className="flex items-center gap-3">
                            <div className="h-12 w-12 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                              <img src={request.image} alt={request.item} className="h-full w-full object-cover" />
                            </div>
                            <div>
                              <p className="font-medium text-gray-900 dark:text-white">{request.item}</p>
                            </div>
                          </div>
                        </td>
                        <td className="py-4 text-gray-700 dark:text-gray-300">{request.currentPrice}</td>
                        <td className="py-4 text-gray-900 dark:text-white font-semibold">{request.requestedPrice}</td>
                        <td className="py-4">
                          <span
                            className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold ${
                              isIncrease
                                ? "bg-green-100 text-green-700 dark:bg-green-900 dark:bg-opacity-30 dark:text-green-400"
                                : "bg-red-100 text-red-700 dark:bg-red-900 dark:bg-opacity-30 dark:text-red-400"
                            }`}
                          >
                            {isIncrease ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
                            {isIncrease ? "+" : ""}{percentage}%
                          </span>
                        </td>
                        <td className="py-4 text-gray-700 dark:text-gray-300">{request.requestedBy}</td>
                        <td className="py-4 text-gray-600 dark:text-gray-400 text-xs">{request.requestDate}</td>
                        <td className="py-4">
                          <span
                            className={`px-2 py-1 rounded-full text-xs font-semibold ${
                              request.status === "pending"
                                ? "bg-yellow-50 text-yellow-700 dark:bg-yellow-900 dark:bg-opacity-40 dark:text-yellow-200"
                                : request.status === "finance_approved"
                                ? "bg-blue-50 text-blue-700 dark:bg-blue-900 dark:bg-opacity-40 dark:text-blue-200"
                                : request.status === "owner_approved"
                                ? "bg-green-50 text-green-700 dark:bg-green-900 dark:bg-opacity-40 dark:text-green-200"
                                : "bg-red-50 text-red-700 dark:bg-red-900 dark:bg-opacity-40 dark:text-red-200"
                            }`}
                          >
                            {request.status === "pending" && "Pending Finance"}
                            {request.status === "finance_approved" && "→ Owner Review"}
                            {request.status === "owner_approved" && "✓ Approved"}
                            {request.status === "finance_rejected" && "✗ Finance Rejected"}
                            {request.status === "owner_rejected" && "✗ Owner Rejected"}
                          </span>
                        </td>
                        <td className="py-4">
                          <div className="flex items-center gap-2">
                            <button
                              onClick={() => handleViewClick(request)}
                              className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900 dark:hover:bg-opacity-20 transition-colors"
                              title="View Details"
                            >
                              <EyeIcon className="w-5 h-5" />
                            </button>
                            {/* Approve/Reject buttons removed from table rows per UI update; actions are available in the detail modal */}
                          </div>
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
                <div className="flex items-center gap-3">
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Finance Price Review</h2>
                  {selectedRequest.status === 'finance_approved' && (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                      <CheckIcon className="w-3 h-3" />
                      Forwarded to Owner
                    </span>
                  )}
                  {selectedRequest.status === 'owner_approved' && (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                      <CheckIcon className="w-3 h-3" />
                      Approved & Applied
                    </span>
                  )}
                  {selectedRequest.status === 'finance_rejected' && (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                      <XIcon className="w-3 h-3" />
                      Rejected
                    </span>
                  )}
                  {selectedRequest.status === 'owner_rejected' && (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                      <XIcon className="w-3 h-3" />
                      Rejected by Owner
                    </span>
                  )}
                  {selectedRequest.status === 'pending' && (
                    <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                      <ClockIcon className="w-3 h-3" />
                      Awaiting Your Review
                    </span>
                  )}
                </div>
                <button
                  onClick={() => setViewModalOpen(false)}
                  className="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
                >
                  <CloseIcon className="w-5 h-5" />
                </button>
              </div>
              <div className="p-6 space-y-6">
                {/* Item Info */}
                <div className="flex items-center gap-4">
                  <div className="h-24 w-24 overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-700">
                    <img src={selectedRequest.image} alt={selectedRequest.item} className="h-full w-full object-cover" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Item</p>
                    <p className="text-xl font-bold text-gray-900 dark:text-white">{selectedRequest.item}</p>
                  </div>
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
                <div className="p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Price Change</p>
                  <p className="text-base text-gray-900 dark:text-white">{selectedRequest.reason}</p>
                </div>

                {/* Finance Approval Info (if reviewed) */}
                {selectedRequest.financeReviewedBy && (
                  <div className="p-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20">
                    <p className="text-sm font-medium text-green-700 dark:text-green-300 mb-3">✓ Finance Approval</p>
                    <div className="space-y-2 text-sm">
                      <div className="flex justify-between">
                        <span className="text-green-600 dark:text-green-400">Approved by:</span>
                        <span className="font-semibold text-green-900 dark:text-green-200">{selectedRequest.financeReviewedBy}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-green-600 dark:text-green-400">Date:</span>
                        <span className="font-semibold text-green-900 dark:text-green-200">{selectedRequest.financeReviewedAt}</span>
                      </div>
                      {selectedRequest.financeNotes && (
                        <div className="mt-2 pt-2 border-t border-green-200 dark:border-green-800">
                          <p className="text-green-600 dark:text-green-400 mb-1">Finance Notes:</p>
                          <p className="text-green-900 dark:text-green-200">{selectedRequest.financeNotes}</p>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {/* Owner Review Info (if reviewed) */}
                {selectedRequest.ownerReviewedBy && (
                  <div className="p-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
                    <p className="text-sm font-medium text-blue-700 dark:text-blue-300 mb-3">✓ Owner Review</p>
                    <div className="space-y-2 text-sm">
                      <div className="flex justify-between">
                        <span className="text-blue-600 dark:text-blue-400">Reviewed by:</span>
                        <span className="font-semibold text-blue-900 dark:text-blue-200">{selectedRequest.ownerReviewedBy}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-blue-600 dark:text-blue-400">Date:</span>
                        <span className="font-semibold text-blue-900 dark:text-blue-200">{selectedRequest.ownerReviewedAt}</span>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Actions */}
              <div className="flex gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                {selectedRequest.status === 'pending' ? (
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
                      Approve & Forward to Owner
                    </button>
                  </>
                ) : selectedRequest.status === 'finance_approved' ? (
                  <div className="flex-1 px-4 py-3 rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-semibold text-center">
                    ✓ Forwarded to Owner - Awaiting Final Approval
                  </div>
                ) : selectedRequest.status === 'owner_approved' ? (
                  <div className="flex-1 px-4 py-3 rounded-lg bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 font-semibold text-center">
                    ✓ Price Change Applied - Request Finalized
                  </div>
                ) : selectedRequest.status === 'owner_rejected' ? (
                  <div className="flex-1 px-4 py-3 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-semibold text-center">
                    ✗ Rejected by Owner
                  </div>
                ) : selectedRequest.status === 'finance_rejected' ? (
                  <div className="flex-1 px-4 py-3 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-semibold text-center">
                    ✗ Request Rejected by Finance
                  </div>
                ) : (
                  <button
                    onClick={() => setViewModalOpen(false)}
                    className="flex-1 px-4 py-2.5 rounded-lg bg-gray-600 text-white font-semibold hover:bg-gray-700 transition-colors"
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
