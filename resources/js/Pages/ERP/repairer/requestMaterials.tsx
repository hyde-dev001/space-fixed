import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import repairMaterialsApi from "../../../services/repairMaterialsApi";
import type { RepairMaterialInventoryItem, RepairMaterialRequest } from "../../../services/repairMaterialsApi";

type Priority = "High" | "Medium" | "Low";
type RequestStatus = "pending" | "accepted" | "rejected" | "needs_details";
type MetricColor = "success" | "warning" | "info";

interface NewRequestForm {
  materialId: string;
  quantity: string;
  size: string;
  priority: Priority;
  notes: string;
}

interface CartItem {
  id: string;
  materialId: string;
  materialName: string;
  skuCode: string;
  quantity: number;
  size: string;
  priority: Priority;
  notes: string;
}

const priorityBadgeClass: Record<Priority, string> = {
  High: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
  Medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
  Low: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<RequestStatus, string> = {
  pending: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
  accepted: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
  rejected: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
  needs_details: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
};

const statusLabel: Record<RequestStatus, string> = {
  pending: "Pending",
  accepted: "Approved",
  rejected: "Rejected",
  needs_details: "Needs Details",
};

const ClipboardIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
    <rect x="9" y="3" width="6" height="4" rx="1" />
    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6M9 16h4" />
  </svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
    <circle cx="12" cy="12" r="9" />
    <path strokeLinecap="round" strokeLinejoin="round" d="M12 7v5l3 3" />
  </svg>
);

const CheckCircleIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
    <circle cx="12" cy="12" r="9" />
    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4" />
  </svg>
);

const ChevronLeftIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
  </svg>
);

const ChevronRightIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
  </svg>
);

const XMarkIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
  </svg>
);

const ShoppingCartIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
    <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 8h12l-2-8M9 21a1 1 0 11-2 0 1 1 0 012 0m8 0a1 1 0 11-2 0 1 1 0 012 0" />
  </svg>
);

interface MetricCardProps {
  title: string;
  value: number;
  description: string;
  icon: ComponentType<{ className?: string }>;
  color: MetricColor;
}

const MetricCard = ({ title, value, description, icon: Icon, color }: MetricCardProps) => {
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
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-linear-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
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

const formatDate = (value: string): string => {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString();
};

const toPriorityPayload = (priority: Priority): "high" | "medium" | "low" => {
  if (priority === "High") return "high";
  if (priority === "Low") return "low";
  return "medium";
};

export default function RequestMaterials() {
  const [materials, setMaterials] = useState<RepairMaterialInventoryItem[]>([]);
  const [requests, setRequests] = useState<RepairMaterialRequest[]>([]);
  const [loading, setLoading] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<"All" | RequestStatus>("All");
  const [currentPage, setCurrentPage] = useState(1);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isCartOpen, setIsCartOpen] = useState(false);
  const [isSubmittingCart, setIsSubmittingCart] = useState(false);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [formData, setFormData] = useState<NewRequestForm>({
    materialId: "",
    quantity: "",
    size: "",
    priority: "Medium",
    notes: "",
  });

  const selectedMaterial = useMemo(
    () => materials.find((material) => String(material.id) === formData.materialId) ?? null,
    [materials, formData.materialId]
  );

  const loadPageData = async () => {
    try {
      setLoading(true);
      const [materialsResponse, requestsResponse] = await Promise.all([
        repairMaterialsApi.getStocksOverview({ category: "repair_materials" }),
        repairMaterialsApi.getMyMaterialRequests(),
      ]);

      if (materialsResponse.success) {
        setMaterials(materialsResponse.data ?? []);
      }

      if (requestsResponse.success) {
        setRequests(requestsResponse.data ?? []);
      }
    } catch (error) {
      console.error("Failed to load repair material requests page", error);
      await Swal.fire({
        title: "Load failed",
        text: "Failed to load materials and requests.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadPageData();
  }, []);

  const filteredRequests = useMemo(() => {
    const query = searchQuery.trim().toLowerCase();
    return requests.filter((request) => {
      const matchesQuery = !query ||
        request.request_number.toLowerCase().includes(query) ||
        request.product_name.toLowerCase().includes(query) ||
        request.sku_code.toLowerCase().includes(query) ||
        request.status.toLowerCase().includes(query);

      const matchesStatus = statusFilter === "All" || request.status === statusFilter;

      return matchesQuery && matchesStatus;
    });
  }, [requests, searchQuery, statusFilter]);

  const totalRequests = requests.length;
  const pendingRequests = requests.filter((request) => request.status === "pending").length;
  const approvedRequests = requests.filter((request) => request.status === "accepted").length;

  const itemsPerPage = 7;
  const totalPages = Math.max(1, Math.ceil(filteredRequests.length / itemsPerPage));
  const currentSafePage = Math.min(currentPage, totalPages);
  const startIndex = (currentSafePage - 1) * itemsPerPage;
  const paginatedRequests = filteredRequests.slice(startIndex, startIndex + itemsPerPage);

  const handleAddToCart = async () => {
    if (!selectedMaterial || !formData.quantity || !formData.notes.trim()) {
      await Swal.fire({
        title: "Missing details",
        text: "Please complete material, quantity, and notes.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    const quantity = Number(formData.quantity);
    if (!Number.isFinite(quantity) || quantity <= 0) {
      await Swal.fire({
        title: "Invalid quantity",
        text: "Please enter a valid quantity greater than zero.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    // Check if material already in cart
    const existingItemIndex = cart.findIndex(
      (item) => item.materialId === formData.materialId && item.size === formData.size.trim()
    );

    const newCartItem: CartItem = {
      id: Date.now().toString(),
      materialId: selectedMaterial.id.toString(),
      materialName: selectedMaterial.name,
      skuCode: selectedMaterial.sku || "N/A",
      quantity,
      size: formData.size.trim(),
      priority: formData.priority,
      notes: formData.notes.trim(),
    };

    if (existingItemIndex >= 0) {
      // Update existing item
      const updatedCart = [...cart];
      updatedCart[existingItemIndex].quantity += quantity;
      setCart(updatedCart);
    } else {
      // Add new item
      setCart([...cart, newCartItem]);
    }

    // Reset form
    setFormData({ materialId: "", quantity: "", size: "", priority: "Medium", notes: "" });
    
    await Swal.fire({
      title: "Added to cart",
      text: `${selectedMaterial.name} added to bulk request cart. You can add more items or submit all at once.`,
      icon: "success",
      confirmButtonColor: "#2563eb",
      timer: 1500,
    });
  };

  const handleRemoveFromCart = (itemId: string) => {
    setCart(cart.filter((item) => item.id !== itemId));
  };

  const handleUpdateCartItem = (itemId: string, updates: Partial<CartItem>) => {
    setCart(
      cart.map((item) =>
        item.id === itemId ? { ...item, ...updates } : item
      )
    );
  };

  const handleSubmitCart = async () => {
    if (cart.length === 0) {
      await Swal.fire({
        title: "Empty cart",
        text: "Please add at least one material request to the cart.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      setIsSubmittingCart(true);
      const response = await repairMaterialsApi.createBulkMaterialRequest({
        materials: cart.map((item) => ({
          inventory_item_id: Number(item.materialId),
          quantity_needed: item.quantity,
          priority: toPriorityPayload(item.priority),
          requested_size: item.size || undefined,
          notes: item.notes,
        })),
      });

      if (response.success) {
        await Swal.fire({
          title: "Bulk request submitted",
          text: `${response.data?.total_created || cart.length} material request(s) have been submitted successfully.`,
          icon: "success",
          confirmButtonColor: "#2563eb",
        });
        setCart([]);
        setIsCartOpen(false);
        setCurrentPage(1);
        await loadPageData();
      }
    } catch (error: any) {
      await Swal.fire({
        title: "Failed to submit",
        text: error?.response?.data?.message || "Please try again.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    } finally {
      setIsSubmittingCart(false);
    }
  };

  const handleSubmitRequest = async () => {
    // Original single submission kept for backward compatibility
    if (!selectedMaterial || !formData.quantity || !formData.notes.trim()) {
      await Swal.fire({
        title: "Missing details",
        text: "Please complete material, quantity, and notes.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    const quantity = Number(formData.quantity);
    if (!Number.isFinite(quantity) || quantity <= 0) {
      await Swal.fire({
        title: "Invalid quantity",
        text: "Please enter a valid quantity greater than zero.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      const response = await repairMaterialsApi.createMaterialRequest({
        inventory_item_id: selectedMaterial.id,
        quantity_needed: quantity,
        priority: toPriorityPayload(formData.priority),
        requested_size: formData.size.trim() || undefined,
        notes: formData.notes.trim(),
      });

      if (response.success) {
        await Swal.fire({
          title: "Request submitted",
          text: response.message,
          icon: "success",
          confirmButtonColor: "#2563eb",
        });
        setFormData({ materialId: "", quantity: "", size: "", priority: "Medium", notes: "" });
        setCurrentPage(1);
        setIsCreateModalOpen(false);
        await loadPageData();
      }
    } catch (error: any) {
      await Swal.fire({
        title: "Failed to submit",
        text: error?.response?.data?.message || "Please try again.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  return (
    <AppLayoutERP hideHeader={isCreateModalOpen}>
      <Head title="Request Material - Repair - Solespace" />

      {isCreateModalOpen && <div className="fixed inset-0 z-40" />}

      <div className="p-6 space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Request Material</h1>
            <p className="text-gray-600 dark:text-gray-400">Request repair materials from Inventory and monitor their approval status</p>
          </div>
          <div className="flex gap-2">
            {cart.length > 0 && (
              <button
                onClick={() => setIsCartOpen(true)}
                className="relative px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition-colors flex items-center gap-2"
              >
                <ShoppingCartIcon className="w-5 h-5" />
                <span>Cart ({cart.length})</span>
              </button>
            )}
            <button
              onClick={() => setIsCreateModalOpen(true)}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors w-fit"
            >
              + New Material Request
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <MetricCard title="Total Requests" value={totalRequests} description="All material requests submitted" icon={ClipboardIcon} color="info" />
          <MetricCard title="Pending Approval" value={pendingRequests} description="Waiting for inventory decision" icon={ClockIcon} color="warning" />
          <MetricCard title="Approved Requests" value={approvedRequests} description="Ready to allocate for repairs" icon={CheckCircleIcon} color="success" />
        </div>

        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Material Request Table</h2>
            <p className="text-sm text-gray-500">Track request progress and inventory responses</p>
          </div>

          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by request no, material, SKU, or status..."
                value={searchQuery}
                onChange={(event) => {
                  setSearchQuery(event.target.value);
                  setCurrentPage(1);
                }}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              />
            </div>
            <div className="sm:w-56">
              <select
                title="Filter by request status"
                aria-label="Filter by request status"
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(event.target.value as "All" | RequestStatus);
                  setCurrentPage(1);
                }}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Status</option>
                <option value="pending">Pending</option>
                <option value="accepted">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="needs_details">Needs Details</option>
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-800/50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Request no</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Material</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Qty</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Priority</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Requested at</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {loading ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">Loading requests...</td>
                  </tr>
                ) : paginatedRequests.length > 0 ? (
                  paginatedRequests.map((request) => {
                    const priority = (request.priority?.charAt(0).toUpperCase() + request.priority?.slice(1)) as Priority;

                    return (
                      <tr key={request.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                        <td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{request.request_number}</td>
                        <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                          <p className="font-medium text-gray-900 dark:text-white">{request.product_name}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">{request.sku_code}</p>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{request.quantity_needed}</td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${priorityBadgeClass[priority]}`}>
                            {priority}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{formatDate(request.requested_date)}</td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClass[request.status]}`}>
                            {statusLabel[request.status]}
                          </span>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">
                      No requests found for the selected filters.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between border-t border-gray-200 dark:border-gray-800 pt-4">
            <p className="text-sm text-gray-500">
              Showing {filteredRequests.length === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredRequests.length)} of {filteredRequests.length} requests
            </p>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
                disabled={currentSafePage === 1}
                aria-label="Previous page"
                title="Previous page"
                className="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 text-sm disabled:opacity-50"
              >
                <ChevronLeftIcon className="w-4 h-4" />
              </button>
              <span className="text-sm text-gray-600 dark:text-gray-300">Page {currentSafePage} of {totalPages}</span>
              <button
                onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
                disabled={currentSafePage === totalPages}
                aria-label="Next page"
                title="Next page"
                className="inline-flex items-center px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 text-sm disabled:opacity-50"
              >
                <ChevronRightIcon className="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>
      </div>

      {isCreateModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/40" onClick={() => setIsCreateModalOpen(false)} />
          <div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 p-6 shadow-2xl">
            <div className="mb-4">
              <h3 className="text-xl font-semibold text-gray-900 dark:text-white">Create Material Request</h3>
              <p className="text-sm text-gray-500">Fill in required details so Inventory can review your request quickly.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Material</label>
                <select
                  value={formData.materialId}
                  onChange={(event) => setFormData((prev) => ({ ...prev, materialId: event.target.value }))}
                  title="Select material"
                  aria-label="Select material"
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
                >
                  <option value="">Select material</option>
                  {materials.map((material) => (
                    <option key={material.id} value={material.id}>
                      {material.name} ({material.sku || "N/A"})
                    </option>
                  ))}
                </select>
                {selectedMaterial && (
                  <p className="mt-1 text-xs text-gray-500">Available stock: {selectedMaterial.available_quantity} {selectedMaterial.unit || "unit"}(s)</p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
                <input
                  type="number"
                  min={1}
                  value={formData.quantity}
                  onChange={(event) => setFormData((prev) => ({ ...prev, quantity: event.target.value }))}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
                  placeholder="Enter quantity"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Priority</label>
                <select
                  value={formData.priority}
                  onChange={(event) => setFormData((prev) => ({ ...prev, priority: event.target.value as Priority }))}
                  title="Select priority"
                  aria-label="Select priority"
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
                >
                  <option value="High">High</option>
                  <option value="Medium">Medium</option>
                  <option value="Low">Low</option>
                </select>
              </div>

              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shoe Size / Variant</label>
                <input
                  type="text"
                  value={formData.size}
                  onChange={(event) => setFormData((prev) => ({ ...prev, size: event.target.value }))}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
                  placeholder="Example: EU 42, Brown variant"
                />
              </div>

              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Repair Notes</label>
                <textarea
                  value={formData.notes}
                  onChange={(event) => setFormData((prev) => ({ ...prev, notes: event.target.value }))}
                  rows={4}
                  className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800"
                  placeholder="Explain why this material is needed for current shoe repair tasks"
                />
              </div>
            </div>

            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                onClick={() => setIsCreateModalOpen(false)}
                className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300"
              >
                Cancel
              </button>
              <button
                onClick={handleSubmitRequest}
                className="px-4 py-2 rounded-lg bg-gray-600 hover:bg-gray-700 text-white font-medium"
                title="Submit this request immediately"
              >
                Submit Immediately
              </button>
              <button
                onClick={handleAddToCart}
                className="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium"
                title="Add to cart for bulk submission"
              >
                + Add to Cart
              </button>
            </div>
          </div>
        </div>
      )}

      {isCartOpen && <div className="fixed inset-0 z-40" />}

      {isCartOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/40" onClick={() => !isSubmittingCart && setIsCartOpen(false)} />
          <div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div className="mb-4">
              <h3 className="text-xl font-semibold text-gray-900 dark:text-white">Bulk Material Request Cart</h3>
              <p className="text-sm text-gray-500">Review and adjust quantities before bulk submission</p>
            </div>

            {cart.length === 0 ? (
              <div className="py-8 text-center text-gray-500">
                <p>Your cart is empty. Add items to proceed with bulk requests.</p>
              </div>
            ) : (
              <div className="space-y-4">
                {cart.map((item, index) => (
                  <div
                    key={item.id}
                    className="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700"
                  >
                    <div className="flex items-start justify-between mb-3">
                      <div className="flex-1">
                        <h4 className="font-semibold text-gray-900 dark:text-white">{item.materialName}</h4>
                        <p className="text-xs text-gray-500 dark:text-gray-400">SKU: {item.skuCode}</p>
                      </div>
                      <button
                        onClick={() => handleRemoveFromCart(item.id)}
                        disabled={isSubmittingCart}
                        className="p-1 hover:bg-red-100 dark:hover:bg-red-900/40 rounded disabled:opacity-50"
                        title="Remove from cart"
                      >
                        <XMarkIcon className="w-5 h-5 text-red-600 dark:text-red-400" />
                      </button>
                    </div>

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                      <div>
                        <label className="text-xs font-medium text-gray-600 dark:text-gray-400">Quantity</label>
                        <input
                          type="number"
                          min={1}
                          value={item.quantity}
                          onChange={(e) =>
                            handleUpdateCartItem(item.id, {
                              quantity: Math.max(1, Number(e.target.value) || 1),
                            })
                          }
                          disabled={isSubmittingCart}
                          aria-label="Quantity"
                          title="Enter quantity"
                          className="w-full mt-1 px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm disabled:opacity-50"
                        />
                      </div>

                      <div>
                        <label className="text-xs font-medium text-gray-600 dark:text-gray-400">Priority</label>
                        <select
                          value={item.priority}
                          onChange={(e) =>
                            handleUpdateCartItem(item.id, {
                              priority: e.target.value as Priority,
                            })
                          }
                          disabled={isSubmittingCart}
                          title="Select priority"
                          aria-label="Select priority"
                          className="w-full mt-1 px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm disabled:opacity-50"
                        >
                          <option value="High">High</option>
                          <option value="Medium">Medium</option>
                          <option value="Low">Low</option>
                        </select>
                      </div>

                      <div className="md:col-span-2">
                        <label className="text-xs font-medium text-gray-600 dark:text-gray-400">Size / Variant</label>
                        <input
                          type="text"
                          value={item.size}
                          onChange={(e) =>
                            handleUpdateCartItem(item.id, {
                              size: e.target.value,
                            })
                          }
                          disabled={isSubmittingCart}
                          aria-label="Size or variant"
                          title="Enter size or variant"
                          placeholder="E.g., EU 42"
                          className="w-full mt-1 px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm disabled:opacity-50"
                        />
                      </div>
                    </div>

                    <div>
                      <label className="text-xs font-medium text-gray-600 dark:text-gray-400">Notes</label>
                      <textarea
                        value={item.notes}
                        onChange={(e) =>
                          handleUpdateCartItem(item.id, {
                            notes: e.target.value,
                          })
                        }
                        disabled={isSubmittingCart}
                        aria-label="Request notes"
                        title="Enter request notes"
                        rows={2}
                        className="w-full mt-1 px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm disabled:opacity-50"
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}

            <div className="mt-6 flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pt-4">
              <span className="text-sm font-semibold text-gray-900 dark:text-white">
                {cart.length} item{cart.length !== 1 ? "s" : ""} in cart
              </span>
              <div className="flex gap-3">
                <button
                  onClick={() => setIsCartOpen(false)}
                  disabled={isSubmittingCart}
                  className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 disabled:opacity-50"
                >
                  Continue Shopping
                </button>
                <button
                  onClick={handleSubmitCart}
                  disabled={isSubmittingCart || cart.length === 0}
                  className="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isSubmittingCart ? "Submitting..." : "Submit All Requests"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </AppLayoutERP>
  );
}