import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import {
  useManagerInventoryOverview,
  type ManagerInventoryOverviewFilters,
} from "../../../hooks/useManagerApi";

const BoxIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M21 16V8l-9-5-9 5v8l9 5 9-5zM3.3 7.6L12 12l8.7-4.4M12 12v9" />
  </svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
  </svg>
);

const TrendUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
  </svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const CloseIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

interface InventoryItem {
  id: number;
  source_type?: string;
  source_id?: number;
  name: string;
  sku: string;
  category: string;
  quantity: number;
  image: string | null;
  status: "In Stock" | "Low Stock" | "Out of Stock" | string;
  last_updated: string | null;
}

interface PaginationPayload<T> {
  current_page: number;
  data: T[];
  last_page: number;
  per_page: number;
  total: number;
}

interface MetricsPayload {
  total_items?: number;
  total_quantity: number;
  low_stock_count: number;
  out_of_stock_count: number;
}

interface InventoryResponse {
  items: PaginationPayload<InventoryItem>;
  metrics: MetricsPayload;
  categories?: string[];
  snapshot?: {
    captured_at: string;
    scope: string;
  };
  last_updated_at?: string;
}

interface MetricCardProps {
  title: string;
  value: number | string;
  icon: ({ className }: { className?: string }) => JSX.Element;
  color: "success" | "warning" | "info";
  description: string;
}

const MetricCard = ({ title, value, icon: Icon, color, description }: MetricCardProps) => {
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

export default function ERPInventoryOverview() {
  const page = usePage();
  const auth = page.props as any;
  const rawRole = String(auth?.auth?.user?.role ?? "").toUpperCase();
  const rawRoles = Array.isArray(auth?.auth?.user?.roles)
    ? auth.auth.user.roles.map((value: string) => String(value).toUpperCase())
    : [];
  const isManager = rawRole === "MANAGER" || rawRoles.includes("MANAGER");
  const isRepairer = rawRole === "REPAIRER" || rawRoles.includes("REPAIRER");
  const isStaff = !isManager && !isRepairer && (rawRole === "STAFF" || rawRoles.includes("STAFF"));

  const initialQuery = typeof window !== "undefined"
    ? new URLSearchParams(window.location.search)
    : new URLSearchParams();
  const currentPathname = typeof window !== "undefined" ? window.location.pathname : "";
  const inventoryApiBasePath = currentPathname.startsWith("/erp/staff/")
    ? "/api/staff/inventory-overview"
    : "/api/manager/inventory-overview";

  const initialPage = Number(initialQuery.get("page") || "1");
  const requestedStatus = initialQuery.get("status") || "All";
  const allowedStatuses = ["All", "In Stock", "Low Stock", "Out of Stock"];

  const [currentPage, setCurrentPage] = useState(Number.isFinite(initialPage) && initialPage > 0 ? initialPage : 1);
  const [searchQuery, setSearchQuery] = useState(initialQuery.get("search") || "");
  const [statusFilter, setStatusFilter] = useState(allowedStatuses.includes(requestedStatus) ? requestedStatus : "All");
  const [categoryFilter, setCategoryFilter] = useState(initialQuery.get("category") || "All");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshTick, setRefreshTick] = useState(0);
  const [lastUpdatedAt, setLastUpdatedAt] = useState<string | null>(null);
  const [categories, setCategories] = useState<string[]>([]);
  const [metrics, setMetrics] = useState<MetricsPayload>({
    total_quantity: 0,
    low_stock_count: 0,
    out_of_stock_count: 0,
  });
  const [items, setItems] = useState<InventoryItem[]>([]);
  const [totalItems, setTotalItems] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(10);
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [selectedItem, setSelectedItem] = useState<InventoryItem | null>(null);

  const normalizeCategory = (category: string) => category.toLowerCase().replace(/\s+/g, "_").trim();
  const isProductCategory = (category: string) => ["shoes", "products", "product"].includes(normalizeCategory(category));
  const isRepairCategory = (category: string) => ["repair_materials", "repair-materials", "materials"].includes(normalizeCategory(category));

  const forceCategory = isRepairer ? "repair_materials" : isStaff ? "shoes" : null;

  const managerFilters: ManagerInventoryOverviewFilters = {
    page: currentPage,
    per_page: 10,
    ...(searchQuery.trim() ? { search: searchQuery.trim() } : {}),
    ...(categoryFilter !== "All" ? { category: categoryFilter } : {}),
    ...(statusFilter !== "All" ? { status: statusFilter } : {}),
  };
  const managerInventoryQuery = useManagerInventoryOverview(managerFilters, isManager);

  const scopedItems = useMemo(() => {
    if (isRepairer) return items.filter((item) => isRepairCategory(item.category));
    if (isStaff) return items.filter((item) => isProductCategory(item.category));
    if (isManager) return items;
    return items;
  }, [isManager, isRepairer, isStaff, items]);

  const derivedMetrics = useMemo(() => {
    const outOfStock = scopedItems.filter((item) => item.status === "Out of Stock").length;
    const lowStock = scopedItems.filter((item) => item.status === "Low Stock").length;
    const totalQty = scopedItems.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    return {
      total_quantity: totalQty,
      low_stock_count: lowStock,
      out_of_stock_count: outOfStock,
    };
  }, [scopedItems]);

  useEffect(() => {
    if (!isManager || !managerInventoryQuery.data) return;

    const payload = managerInventoryQuery.data;
    setItems(payload.items.data);
    setTotalItems(payload.items.total);
    setItemsPerPage(payload.items.per_page);
    setTotalPages(payload.items.last_page || 1);
    setMetrics(payload.metrics);
    setCategories(payload.categories);
    setLastUpdatedAt(payload.last_updated_at);
  }, [isManager, managerInventoryQuery.data]);

  useEffect(() => {
    if (isManager) return;

    const fetchInventoryOverview = async () => {
      try {
        setLoading(true);
        setError(null);

        const params = new URLSearchParams({
          page: currentPage.toString(),
          per_page: "10",
        });

        if (searchQuery.trim()) params.append("search", searchQuery.trim());
        if (forceCategory) params.append("category", forceCategory);
        if (statusFilter !== "All") params.append("status", statusFilter);

        const response = await fetch(`${inventoryApiBasePath}?${params.toString()}`, {
          credentials: "include",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
        });

        if (!response.ok) {
          throw new Error("Failed to load inventory overview");
        }

        const payload: InventoryResponse = await response.json();
        setItems(payload.items.data);
        setTotalItems(payload.items.total);
        setItemsPerPage(payload.items.per_page);
        setTotalPages(payload.items.last_page || 1);
        setMetrics(payload.metrics);
        setCategories(payload.categories || []);
        setLastUpdatedAt(payload.last_updated_at || null);
      } catch (fetchError) {
        setError(fetchError instanceof Error ? fetchError.message : "Failed to load inventory overview");
      } finally {
        setLoading(false);
      }
    };

    fetchInventoryOverview();
  }, [currentPage, searchQuery, statusFilter, forceCategory, inventoryApiBasePath, isManager, refreshTick]);

  const displayLoading = isManager ? managerInventoryQuery.isLoading : loading;
  const displayError = isManager ? managerInventoryQuery.error?.message || null : error;
  const displayMetrics = isManager ? metrics : derivedMetrics;
  const availableCategories = isManager ? (managerInventoryQuery.data?.categories || categories) : [];

  const startIndex = (currentPage - 1) * itemsPerPage;

  const formatCategoryLabel = (category: string) =>
    category
      .replace(/_/g, " ")
      .split(" ")
      .filter(Boolean)
      .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
      .join(" ");

  const getCategoryBadgeClasses = (category: string) => {
    const normalized = normalizeCategory(category);

    if (normalized === "repair_materials") {
      return "bg-sky-50 text-sky-700 ring-1 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-200 dark:ring-sky-800";
    }

    if (normalized === "shoes" || normalized === "products" || normalized === "product") {
      return "bg-violet-50 text-violet-700 ring-1 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-200 dark:ring-violet-800";
    }

    return "bg-gray-100 text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700";
  };

  const handleViewClick = (item: InventoryItem) => {
    setSelectedItem(item);
    setViewModalOpen(true);
  };

  return (
    <AppLayoutERP>
      <Head title="Inventory Overview - Solespace" />
      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Stocks Overview</h1>
            <p className="text-gray-600 dark:text-gray-400">
              {isRepairer
                ? "Monitor repair-material stock levels and item availability"
                : isStaff
                ? "Monitor product stock levels and item availability"
                : "Monitor stock levels across products and repair materials"}
            </p>
          </div>
          <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200 w-fit">
            {isRepairer ? "Repair Materials" : isStaff ? "Products" : "Products + Repair Materials"}
          </span>
          <div className="flex items-center gap-3">
            {lastUpdatedAt && (
              <span className="text-xs text-gray-500 dark:text-gray-400">
                Updated {new Date(lastUpdatedAt).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })}
              </span>
            )}
            <button
              type="button"
              onClick={() => {
                if (isManager) {
                  void managerInventoryQuery.refetch();
                } else {
                  setRefreshTick((value) => value + 1);
                }
              }}
              disabled={displayLoading}
              className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
            >
              Refresh
            </button>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <MetricCard
            title="Total Items in Stock"
            value={displayMetrics.total_quantity.toLocaleString()}
            icon={BoxIcon}
            color="info"
            description={isRepairer ? "Across repair materials" : isStaff ? "Across products" : "Across products and repair materials"}
          />
          <MetricCard
            title="Low Stock Items"
            value={displayMetrics.low_stock_count}
            icon={AlertIcon}
            color="warning"
            description="Need attention"
          />
          <MetricCard
            title="Out of Stock"
            value={displayMetrics.out_of_stock_count}
            icon={TrendUpIcon}
            color="success"
            description="Awaiting restock"
          />
        </div>

        {/* Inventory Table */}
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">
              {isRepairer ? "Repair Materials Inventory" : isStaff ? "Products Inventory" : "Stock Inventory"}
            </h2>
            <p className="text-sm text-gray-500">
              {isRepairer
                ? "View stock items available for repair operations"
                : isStaff
                ? "View product stock levels for retail operations"
                : "View stock items for both retail and repair operations"}
            </p>
          </div>

          {/* Search and Filters */}
          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder={isRepairer ? "Search by material name..." : "Search by item name..."}
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              />
            </div>
            {isManager && (
            <div className="sm:w-48">
              <select
                value={categoryFilter}
                onChange={(e) => {
                  setCategoryFilter(e.target.value);
                  setCurrentPage(1);
                }}
                title="Filter inventory by category"
                aria-label="Filter inventory by category"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Categories</option>
                {availableCategories.map((category) => (
                  <option key={category} value={category}>{formatCategoryLabel(category)}</option>
                ))}
              </select>
            </div>
            )}
            <div className="sm:w-48">
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setCurrentPage(1);
                }}
                title="Filter inventory by stock status"
                aria-label="Filter inventory by stock status"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Status</option>
                <option value="In Stock">In Stock</option>
                <option value="Low Stock">Low Stock</option>
                <option value="Out of Stock">Out of Stock</option>
              </select>
            </div>
          </div>

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-2">{isRepairer ? "Material" : "Item"}</th>
                  <th className="pb-2">Category</th>
                  <th className="pb-2">Quantity</th>
                  <th className="pb-2">Status</th>
                  <th className="pb-2">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {displayLoading ? (
                  <tr>
                    <td colSpan={5} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                      Loading inventory...
                    </td>
                  </tr>
                ) : displayError ? (
                  <tr>
                    <td colSpan={5} className="py-10 text-center text-sm text-red-600 dark:text-red-400">
                      {displayError}
                    </td>
                  </tr>
                ) : scopedItems.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                      No inventory items found.
                    </td>
                  </tr>
                ) : scopedItems.map((item) => (
                  <tr key={`${item.source_type ?? "inventory"}-${item.source_id ?? item.id}`}>
                    <td className="py-3">
                      <div className="flex items-center gap-3">
                        <div className="h-12 w-12 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                          <img src={item.image || "/images/product/product-01.jpg"} alt={item.name} className="h-full w-full object-cover" />
                        </div>
                        <div>
                          <p className="font-medium text-gray-900 dark:text-gray-100">{item.name}</p>
                        </div>
                      </div>
                    </td>
                    <td className="py-3">
                      <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getCategoryBadgeClasses(item.category)}`}>
                        {formatCategoryLabel(item.category)}
                      </span>
                    </td>
                    <td className="py-3">
                      <span className="font-semibold text-gray-900 dark:text-gray-100">{item.quantity}</span>
                    </td>
                    <td className="py-3">
                      <span
                        className={`px-2 py-1 rounded-full text-xs font-semibold ${
                          item.status === "In Stock"
                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                            : item.status === "Low Stock"
                            ? "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                            : "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                        }`}
                      >
                        {item.status}
                      </span>
                    </td>
                    <td className="py-3">
                      <button
                        onClick={() => handleViewClick(item)}
                        className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 transition-colors"
                        title="View Details"
                      >
                        <EyeIcon className="w-5 h-5" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {!displayLoading && !displayError && totalItems > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 mt-4">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to <span className="font-medium">{Math.min(currentPage * itemsPerPage, totalItems)}</span> of <span className="font-medium">{totalItems}</span> items
                </div>
                <div className="flex gap-2">
                  <button
                    onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                    disabled={currentPage === 1}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Previous page"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                  </button>
                  <button
                    onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                    disabled={currentPage === totalPages}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Next page"
                  >
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* View Details Modal */}
        {viewModalOpen && selectedItem && (
          <div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full">
              <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Item Details</h2>
                <button
                  onClick={() => setViewModalOpen(false)}
                  className="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
                  title="Close details modal"
                  aria-label="Close details modal"
                >
                  <CloseIcon className="w-5 h-5" />
                </button>
              </div>
              <div className="p-6 space-y-6">
                {/* Product Image */}
                <div className="flex justify-center">
                  <div className="h-48 w-48 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700">
                    <img src={selectedItem.image || "/images/product/product-01.jpg"} alt={selectedItem.name} className="h-full w-full object-cover" />
                  </div>
                </div>

                {/* Product Details */}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Name</p>
                    <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.name}</p>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Category</p>
                    <span className={`mt-1 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getCategoryBadgeClasses(selectedItem.category)}`}>
                      {formatCategoryLabel(selectedItem.category)}
                    </span>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Quantity Available</p>
                    <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.quantity} units</p>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Status</p>
                    <span
                      className={`inline-block px-2 py-1 rounded-full text-xs font-semibold ${
                        selectedItem.status === "In Stock"
                          ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                          : selectedItem.status === "Low Stock"
                          ? "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                          : "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                      }`}
                    >
                      {selectedItem.status}
                    </span>
                  </div>
                  {selectedItem.last_updated && (
                    <div className="col-span-2">
                      <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Last Updated</p>
                      <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.last_updated}</p>
                    </div>
                  )}
                </div>
              </div>
              <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                <button
                  onClick={() => setViewModalOpen(false)}
                  className="flex-1 px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutERP>
  );
}
