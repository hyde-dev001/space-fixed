import { Head } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useEffect, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

type PendingPriceRequest = {
  id: number;
  product_id: number;
  proposed_price: number;
  status: "pending" | "finance_approved" | "finance_rejected" | "owner_rejected" | "owner_approved";
  reason: string;
  finance_rejection_reason?: string | null;
  owner_rejection_reason?: string | null;
};

type MetricColor = "success" | "warning" | "info";
type ChangeType = "increase" | "decrease";

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

const BoxIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M21 16V8l-9-5-9 5v8l9 5 9-5zM3.3 7.6L12 12l8.7-4.4M12 12v9" />
  </svg>
);

const WalletIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M3 7a2 2 0 012-2h14a2 2 0 012 2v2h-6a3 3 0 100 6h6v2a2 2 0 01-2 2H5a2 2 0 01-2-2V7zm14 5a1 1 0 110 2 1 1 0 010-2z" />
  </svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const PencilIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
  </svg>
);

const CloseIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

interface ShoeItem {
  id: number;
  item: string;
  price: string;
  status: "Active" | "Under Review" | "Awaiting Owner" | "Rejected";
  rejectionReason?: string;
  image: string;
  pendingRequest?: PendingPriceRequest | null;
}

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

export default function ERPShoePricing() {
  const [loading, setLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [selectedShoe, setSelectedShoe] = useState<ShoeItem | null>(null);
  const [editFormData, setEditFormData] = useState({ price: "", reason: "" });
  const [shoePricing, setShoePricing] = useState<ShoeItem[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("All");

  useEffect(() => {
    fetchShoePricing();
  }, []);

  const fetchShoePricing = async () => {
    try {
      setLoading(true);
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const [productsResponse, pendingResponse] = await Promise.all([
        fetch('/api/products/my/products', {
          credentials: 'include',
          headers: { 'Accept': 'application/json' },
        }),
        fetch('/api/price-change-requests/my-pending', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
        }),
      ]);

      if (!productsResponse.ok) {
        throw new Error('Failed to load products');
      }

      const productsData = await productsResponse.json();
      const pendingData = pendingResponse.ok ? await pendingResponse.json() : { requests: [] };

      const pendingMap = new Map<number, PendingPriceRequest>(
        (pendingData.requests || []).map((req: PendingPriceRequest) => [req.product_id, req])
      );

      const mapped: ShoeItem[] = (productsData.products || []).map((product: any) => {
        const pendingRequest = pendingMap.get(product.id) || null;
        let status: ShoeItem['status'] = 'Active';
        let rejectionReason: string | undefined;

        if (pendingRequest) {
          if (pendingRequest.status === 'finance_approved') {
            status = 'Awaiting Owner';
          } else if (pendingRequest.status === 'finance_rejected' || pendingRequest.status === 'owner_rejected') {
            status = 'Rejected';
            rejectionReason = pendingRequest.finance_rejection_reason || pendingRequest.owner_rejection_reason || undefined;
          } else {
            status = 'Under Review';
          }
        }

        return {
          id: product.id,
          item: product.name,
          price: `₱${Number(product.price || 0).toLocaleString()}`,
          status,
          rejectionReason,
          image: product.main_image || '/images/product/product-01.jpg',
          pendingRequest,
        };
      });

      setShoePricing(mapped);
    } catch (error: any) {
      console.error('Error fetching shoe pricing:', error);
      Swal.fire({
        title: 'Error',
        text: error.message || 'Failed to load shoe pricing',
        icon: 'error',
        confirmButtonColor: '#2563eb',
      });
    } finally {
      setLoading(false);
    }
  };

  // Filter data based on search and status
  const filteredData = shoePricing.filter((item) => {
    const matchesSearch = item.item.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesStatus = statusFilter === "All" || item.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const itemsPerPage = 7;
  const totalPages = Math.ceil(filteredData.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedShoes = filteredData.slice(startIndex, startIndex + itemsPerPage);

  const activeCount = shoePricing.filter(item => item.status === "Active").length;
  const underReviewCount = shoePricing.filter(item => item.status === "Under Review").length;
  const awaitingOwnerCount = shoePricing.filter(item => item.status === "Awaiting Owner").length;
  const rejectedCount = shoePricing.filter(item => item.status === "Rejected").length;

  const handleViewClick = (item: ShoeItem) => {
    setSelectedShoe(item);
    setViewModalOpen(true);
  };

  const handleEditClick = (item: ShoeItem) => {
    // Prevent editing if there's already a pending request
    if (item.pendingRequest && item.status !== 'Rejected') {
      Swal.fire({
        title: 'Pending Request',
        text: 'This item already has a pending price change request. Please wait for approval or cancellation.',
        icon: 'info',
        confirmButtonColor: '#2563eb',
      });
      return;
    }
    
    setSelectedShoe(item);
    const digits = item.price.replace(/\D/g, "");
    setEditFormData({ price: digits, reason: "" });
    setEditModalOpen(true);
  };

  const handleSaveEdit = async () => {
    if (!editFormData.reason.trim()) {
      Swal.fire({ title: "Reason Required", text: "Please provide a reason for changing the price.", icon: "warning", confirmButtonColor: "#2563eb" });
      return;
    }

    if (!selectedShoe) return;

    const original = selectedShoe.price.replace(/\D/g, "");
    if (editFormData.price === original) {
      Swal.fire({ title: "No Changes", text: "Please make changes before saving.", icon: "info", confirmButtonColor: "#2563eb" });
      return;
    }

    setEditModalOpen(false);

    const result = await Swal.fire({
      title: "Confirm Save",
      html: `<div style="text-align: left;"><strong>New Price:</strong> ₱${editFormData.price}<br/><strong>Reason:</strong> ${editFormData.reason}</div>`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, Save",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed && selectedShoe) {
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(`/api/products/${selectedShoe.id}/request-price-change`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken || '',
          },
          body: JSON.stringify({
            product_id: selectedShoe.id,
            product_name: selectedShoe.item,
            current_price: Number(original),
            proposed_price: Number(editFormData.price),
            reason: editFormData.reason,
          }),
        });

        if (!response.ok) {
          const error = await response.json();
          throw new Error(error.message || 'Failed to submit price change request');
        }

        Swal.fire({
          title: "Success!",
          text: "Price change request submitted. Please wait for Finance approval.",
          icon: "success",
          confirmButtonColor: "#2563eb",
        });

        await fetchShoePricing();
      } catch (error: any) {
        console.error('Error submitting price change request:', error);
        Swal.fire({
          title: "Error",
          text: error.message || "Failed to submit price change request",
          icon: "error",
          confirmButtonColor: "#2563eb",
        });
      }
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Shoe Pricing - Solespace" />
      <div className="p-6 space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Shoe Pricing</h1>
            <p className="text-gray-600 dark:text-gray-400">Manager controls for shoe pricing and margins.</p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
          </div>
        </div>

        <div className="mb-12 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <MetricCard title="Active SKUs" value={activeCount} change={0} changeType="increase" icon={TagIcon} color="info" description="Live pricing" />
          <MetricCard title="Under Review" value={underReviewCount} change={0} changeType="increase" icon={WalletIcon} color="warning" description="Awaiting finance review" />
          <MetricCard title="Awaiting Owner" value={awaitingOwnerCount} change={0} changeType="increase" icon={BoxIcon} color="info" description="Forwarded to owner" />
          <MetricCard title="Rejected" value={rejectedCount} change={0} changeType="decrease" icon={ArrowDownIcon} color="warning" description="Needs adjustment" />
        </div>

        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Shoe Pricing</h2>
            <p className="text-sm text-gray-500">Manage retail pricing and margins.</p>
          </div>
          
          {/* Search and Filter */}
          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by brand..."
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
                <option value="Active">Active</option>
                <option value="Under Review">Under Review</option>
                <option value="Awaiting Owner">Awaiting Owner</option>
                <option value="Rejected">Rejected</option>
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Product</th>
                  <th className="pb-2">Retail Price</th>
                  <th className="pb-2">Status</th>
                  <th className="pb-2">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {loading ? (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-gray-500">
                      Loading pricing data...
                    </td>
                  </tr>
                ) : paginatedShoes.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-gray-500">
                      No items found.
                    </td>
                  </tr>
                ) : (
                  paginatedShoes.map((item) => (
                    <tr key={item.id}>
                      <td className="py-3">
                        <div className="flex items-center gap-3">
                          <div className="h-12 w-12 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                            <img src={item.image} alt={item.item} className="h-full w-full object-cover" />
                          </div>
                          <div>
                            <p className="font-medium text-gray-900 dark:text-gray-100">{item.item}</p>
                          </div>
                        </div>
                      </td>
                      <td className="py-3">
                        <div className="space-y-1">
                          <p className="text-gray-900 dark:text-gray-100 font-medium">{item.price}</p>
                          {item.pendingRequest && (
                            <p className="text-xs text-blue-600 dark:text-blue-400">
                              Pending: ₱{Number(item.pendingRequest.proposed_price).toLocaleString()}
                            </p>
                          )}
                        </div>
                      </td>
                      <td className="py-3">
                      <span className={`px-2 py-1 rounded-full text-xs font-semibold ${
                        item.status === "Active"
                          ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                          : item.status === "Under Review"
                          ? "bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200"
                          : item.status === "Awaiting Owner"
                          ? "bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200"
                          : item.status === "Rejected"
                          ? "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                          : "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                      }`}>{item.status}</span>
                    </td>
                    <td className="py-3">
                      <div className="flex items-center gap-2">
                        <button 
                          onClick={() => handleViewClick(item)} 
                          className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                          title="View details"
                        >
                          <EyeIcon className="w-5 h-5" />
                        </button>
                        <button 
                          onClick={() => handleEditClick(item)} 
                          className={`p-2 rounded-lg transition-colors ${
                            item.pendingRequest && item.status !== 'Rejected'
                              ? 'text-gray-400 dark:text-gray-600 cursor-not-allowed'
                              : 'text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20'
                          }`}
                          title={item.pendingRequest && item.status !== 'Rejected' ? 'Pending request exists' : 'Edit price'}
                        >
                          <PencilIcon className="w-5 h-5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {filteredData.length > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to <span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredData.length)}</span> of <span className="font-medium">{filteredData.length}</span>
                </div>
                <div className="flex items-center gap-2">
                  <button onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))} disabled={currentPage === 1} className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" title="Previous page">
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>
                  </button>

                  {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                    if (page === 1 || page === totalPages || (page >= currentPage - 1 && page <= currentPage + 1)) {
                      return (
                        <button key={page} onClick={() => setCurrentPage(page)} className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${currentPage === page ? "bg-blue-600 text-white" : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"}`}>{page}</button>
                      );
                    } else if (page === currentPage - 2 || page === currentPage + 2) {
                      return (<span key={page} className="px-2 text-gray-500 dark:text-gray-400">...</span>);
                    }
                    return null;
                  })}

                  <button onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))} disabled={currentPage === totalPages} className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" title="Next page">
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* View Modal */}
          {viewModalOpen && selectedShoe && (
            <div className="fixed inset-0 z-[999999] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl max-w-2xl w-full border border-gray-200/80 dark:border-gray-700/60 overflow-hidden">
                <div className="h-1.5 w-full bg-gradient-to-r from-emerald-500 via-blue-500 to-indigo-500" />
                <div className="flex items-center justify-between p-6 bg-gradient-to-b from-gray-50 to-white dark:from-gray-900 dark:to-gray-900 border-b border-gray-200 dark:border-gray-700">
                  <div>
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Product Details</h2>
                    <p className="text-xs text-gray-500 dark:text-gray-400">Review pricing information and approval status</p>
                  </div>
                  <button
                    onClick={() => setViewModalOpen(false)}
                    className="h-9 w-9 grid place-items-center rounded-full border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
                    aria-label="Close"
                  >
                    <CloseIcon className="w-4 h-4" />
                  </button>
                </div>
                <div className="p-6 space-y-5">
                  {/* Product Info with Image */}
                  <div className="flex gap-4 p-4 rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50">
                    <div className="flex-shrink-0">
                      <img src={selectedShoe.image} alt={selectedShoe.item} className="w-24 h-24 rounded-xl object-cover border border-gray-200 dark:border-gray-700" />
                    </div>
                    <div className="flex-1">
                      <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Product Name</p>
                      <p className="text-xl font-bold text-gray-900 dark:text-white">{selectedShoe.item}</p>
                    </div>
                  </div>

                  {/* Price and Status Grid */}
                  <div className="grid grid-cols-2 gap-4">
                    <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gradient-to-br from-gray-50 to-gray-100/50 dark:from-gray-800/50 dark:to-gray-800/30 p-4">
                      <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Current Price</p>
                      <p className="text-2xl font-bold text-gray-900 dark:text-white">{selectedShoe.price}</p>
                    </div>
                    <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gradient-to-br from-gray-50 to-gray-100/50 dark:from-gray-800/50 dark:to-gray-800/30 p-4">
                      <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Status</p>
                      <span className={`inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold ${selectedShoe.status === "Active" ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200" : selectedShoe.status === "Under Review" ? "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200" : selectedShoe.status === "Awaiting Owner" ? "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200" : selectedShoe.status === "Rejected" ? "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200" : "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"}`}>{selectedShoe.status}</span>
                    </div>
                  </div>
                  {selectedShoe.pendingRequest && (() => {
                    const currentPrice = Number(selectedShoe.price.replace(/[^0-9.]/g, ''));
                    const proposedPrice = Number(selectedShoe.pendingRequest.proposed_price);
                    const difference = proposedPrice - currentPrice;
                    const percentChange = ((difference / currentPrice) * 100).toFixed(1);
                    const isIncrease = difference > 0;
                    
                    return (
                      <div className="rounded-2xl border-2 border-blue-300/70 dark:border-blue-600/60 bg-gradient-to-br from-blue-50 to-blue-100/50 dark:from-blue-900/30 dark:to-blue-900/20 p-5">
                        <div className="flex items-center justify-between mb-3">
                          <div className="flex items-center gap-2">
                            <div className="h-2 w-2 rounded-full bg-blue-500 animate-pulse" />
                            <p className="text-sm font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">Pending Price Change</p>
                          </div>
                          <span className={`px-2.5 py-1 rounded-full text-xs font-bold ${
                            selectedShoe.status === 'Under Review' 
                              ? 'bg-blue-200 text-blue-800 dark:bg-blue-800/50 dark:text-blue-200'
                              : 'bg-indigo-200 text-indigo-800 dark:bg-indigo-800/50 dark:text-indigo-200'
                          }`}>
                            {selectedShoe.status === 'Under Review' ? 'Finance Review' : 'Owner Review'}
                          </span>
                        </div>
                        
                        {/* Price Comparison */}
                        <div className="grid grid-cols-3 gap-3 mb-4">
                          <div className="text-center p-3 rounded-xl bg-white/60 dark:bg-gray-800/60 border border-blue-200 dark:border-blue-700">
                            <p className="text-xs text-gray-600 dark:text-gray-400 mb-1">Current</p>
                            <p className="text-sm font-bold text-gray-900 dark:text-white">₱{currentPrice.toLocaleString()}</p>
                          </div>
                          <div className="text-center p-3 rounded-xl bg-blue-100 dark:bg-blue-800/40 border-2 border-blue-400 dark:border-blue-500">
                            <p className="text-xs text-blue-700 dark:text-blue-300 mb-1">Proposed</p>
                            <p className="text-sm font-bold text-blue-900 dark:text-blue-100">₱{proposedPrice.toLocaleString()}</p>
                          </div>
                          <div className="text-center p-3 rounded-xl bg-white/60 dark:bg-gray-800/60 border border-blue-200 dark:border-blue-700">
                            <p className="text-xs text-gray-600 dark:text-gray-400 mb-1">Change</p>
                            <p className={`text-sm font-bold ${isIncrease ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                              {isIncrease ? '+' : ''}₱{difference.toLocaleString()} ({percentChange}%)
                            </p>
                          </div>
                        </div>
                        
                        {/* Reason */}
                        <div className="p-3 rounded-xl bg-white/60 dark:bg-gray-800/60 border border-blue-200 dark:border-blue-700">
                          <p className="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1">Reason for Change:</p>
                          <p className="text-sm text-gray-900 dark:text-gray-100 leading-relaxed">{selectedShoe.pendingRequest.reason}</p>
                        </div>
                      </div>
                    );
                  })()}
                  {selectedShoe.status === "Rejected" && selectedShoe.rejectionReason && (
                    <div className="p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                      <p className="text-xs uppercase tracking-wide text-red-700 dark:text-red-200">Rejection Reason</p>
                      <p className="mt-1 text-sm text-red-700 dark:text-red-300">{selectedShoe.rejectionReason}</p>
                    </div>
                  )}
                </div>
                <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                  {selectedShoe.pendingRequest && selectedShoe.status !== 'Rejected' && (
                    <button 
                      onClick={async () => {
                        const result = await Swal.fire({
                          title: 'Cancel Request?',
                          text: 'Are you sure you want to cancel this price change request?',
                          icon: 'warning',
                          showCancelButton: true,
                          confirmButtonColor: '#dc2626',
                          cancelButtonColor: '#6b7280',
                          confirmButtonText: 'Yes, Cancel',
                          cancelButtonText: 'No',
                        });
                        
                        if (result.isConfirmed && selectedShoe.pendingRequest) {
                          try {
                            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                            const response = await fetch(`/api/price-change-requests/${selectedShoe.pendingRequest.id}/cancel`, {
                              method: 'POST',
                              credentials: 'include',
                              headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken || '',
                              },
                            });
                            
                            if (!response.ok) throw new Error('Failed to cancel request');
                            
                            Swal.fire('Cancelled', 'Price change request has been cancelled.', 'success');
                            setViewModalOpen(false);
                            await fetchShoePricing();
                          } catch (error) {
                            Swal.fire('Error', 'Failed to cancel request', 'error');
                          }
                        }
                      }}
                      className="flex-1 px-4 py-2.5 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-semibold hover:bg-red-200 dark:hover:bg-red-900/50 transition-colors"
                    >
                      Cancel Request
                    </button>
                  )}
                  <button onClick={() => setViewModalOpen(false)} className="flex-1 px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">Close</button>
                </div>
              </div>
            </div>
          )}

          {/* Edit Modal */}
          {editModalOpen && selectedShoe && (() => {
            const currentPrice = Number(selectedShoe.price.replace(/[^0-9.]/g, ''));
            const proposedPrice = Number(editFormData.price) || 0;
            const difference = proposedPrice - currentPrice;
            const percentChange = currentPrice > 0 ? ((difference / currentPrice) * 100).toFixed(1) : '0.0';
            const isIncrease = difference > 0;
            
            return (
              <div className="fixed inset-0 z-[999999] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
                <div className="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl max-w-2xl w-full border border-gray-200/80 dark:border-gray-700/60 overflow-hidden">
                  <div className="h-1.5 w-full bg-gradient-to-r from-blue-600 via-indigo-500 to-emerald-500" />
                  <div className="flex items-center justify-between p-6 bg-gradient-to-b from-gray-50 to-white dark:from-gray-900 dark:to-gray-900 border-b border-gray-200 dark:border-gray-700">
                    <div>
                      <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Request Price Change</h2>
                      <p className="text-xs text-gray-500 dark:text-gray-400">Submit a price change request for finance approval</p>
                    </div>
                    <button onClick={() => setEditModalOpen(false)} className="h-9 w-9 grid place-items-center rounded-full border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600 transition-colors" aria-label="Close"><CloseIcon className="w-4 h-4" /></button>
                  </div>
                  <div className="p-6 space-y-5">
                    {/* Product Info */}
                    <div className="flex gap-4 p-4 rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50">
                      <div className="flex-shrink-0">
                        <img src={selectedShoe.image} alt={selectedShoe.item} className="w-20 h-20 rounded-xl object-cover border border-gray-200 dark:border-gray-700" />
                      </div>
                      <div className="flex-1">
                        <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Product Name</p>
                        <p className="text-lg font-bold text-gray-900 dark:text-white">{selectedShoe.item}</p>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">Current Price: <span className="font-semibold text-gray-900 dark:text-white">{selectedShoe.price}</span></p>
                      </div>
                    </div>

                    {/* Price Input */}
                    <div>
                      <p className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Proposed New Price *</p>
                      <div className="relative">
                        <span className="absolute left-4 top-1/2 -translate-y-1/2 text-lg font-semibold text-gray-500 dark:text-gray-400">₱</span>
                        <input 
                          type="number" 
                          inputMode="decimal" 
                          step="0.01" 
                          value={editFormData.price} 
                          onChange={(e) => { 
                            const v = e.target.value; 
                            if (v === '' || /^\d*\.?\d*$/.test(v)) setEditFormData({ ...editFormData, price: v }); 
                          }} 
                          className="w-full pl-10 pr-4 py-3 text-lg rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500/40 focus:border-blue-500 dark:focus:border-blue-400 transition"
                          placeholder="0.00"
                        />
                      </div>
                      
                      {/* Price Change Preview */}
                      {proposedPrice > 0 && proposedPrice !== currentPrice && (
                        <div className={`mt-3 p-3 rounded-xl border-2 ${
                          isIncrease 
                            ? 'bg-green-50 dark:bg-green-900/20 border-green-300 dark:border-green-700' 
                            : 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-700'
                        }`}>
                          <div className="flex items-center justify-between">
                            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Price Change:</span>
                            <div className="flex items-center gap-2">
                              {isIncrease ? (
                                <ArrowUpIcon className="w-4 h-4 text-green-600 dark:text-green-400" />
                              ) : (
                                <ArrowDownIcon className="w-4 h-4 text-red-600 dark:text-red-400" />
                              )}
                              <span className={`text-base font-bold ${
                                isIncrease 
                                  ? 'text-green-700 dark:text-green-300' 
                                  : 'text-red-700 dark:text-red-300'
                              }`}>
                                {isIncrease ? '+' : ''}₱{Math.abs(difference).toLocaleString()} ({percentChange}%)
                              </span>
                            </div>
                          </div>
                        </div>
                      )}
                    </div>

                    {/* Reason */}
                    <div>
                      <p className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Reason for Change *</p>
                      <textarea 
                        value={editFormData.reason} 
                        onChange={(e) => setEditFormData({ ...editFormData, reason: e.target.value })} 
                        rows={4} 
                        placeholder="Explain why this price change is needed (e.g., market adjustment, competitor pricing, seasonal discount)..." 
                        className="w-full px-4 py-3 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40 focus:border-blue-500 dark:focus:border-blue-400 resize-none transition"
                      />
                      <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">This reason will be reviewed by the finance team</p>
                    </div>
                  </div>
                  <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                    <button onClick={() => setEditModalOpen(false)} className="flex-1 px-5 py-3 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600 transition-colors">Cancel</button>
                    <button onClick={handleSaveEdit} className="flex-1 px-5 py-3 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold shadow-lg hover:from-blue-700 hover:to-indigo-700 transition-all hover:shadow-xl">Submit Request</button>
                  </div>
                </div>
              </div>
            );
          })()}
        </div>

      </div>
    </AppLayoutERP>
  );
}

