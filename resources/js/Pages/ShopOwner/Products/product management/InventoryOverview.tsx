import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import type { ComponentType } from "react";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";

type MetricColor = "success" | "warning" | "info";
type ChangeType = "increase" | "decrease";

// Icons
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

const BoxIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M21 16V8l-9-5-9 5v8l9 5 9-5zM3.3 7.6L12 12l8.7-4.4M12 12v9" />
  </svg>
);

const TrendUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
  </svg>
);

const AlertIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none" />
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
  name: string;
  sku: string;
  category: string;
  quantity: number;
  image: string;
  status: "In Stock" | "Low Stock" | "Out of Stock";
  lastRestocked?: string;
}

const defaultInventoryData: InventoryItem[] = [];

const formatCategoryLabel = (category: string) =>
  category
    .replace(/_/g, " ")
    .split(" ")
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");

const getCategoryBadgeClasses = (category: string) => {
  const normalized = category.toLowerCase().replace(/\s+/g, "_");

  if (normalized === "shoes") {
    return "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200";
  }

  if (normalized === "repair_materials") {
    return "bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200";
  }

  if (normalized === "accessories") {
    return "bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-200";
  }

  if (normalized === "care_products") {
    return "bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200";
  }

  return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200";
};

const isProductItem = (item: InventoryItem) => {
  const normalized = item.category.toLowerCase().replace(/\s+/g, "_");
  return normalized === "shoes" || normalized === "product" || normalized === "products";
};

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
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-linear-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
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

export default function InventoryOverview() {
  const pageProps = usePage().props as any;
  const userRole = String(pageProps?.auth?.user?.role ?? pageProps?.auth?.user?.account_type ?? "").toLowerCase();
  const isStaffAccount = userRole.includes("staff") || window.location.pathname.toLowerCase().includes("/staff/");

  const [inventoryData, setInventoryData] = useState<InventoryItem[]>(defaultInventoryData);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("All");
  const [categoryFilter, setCategoryFilter] = useState("All");
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [selectedItem, setSelectedItem] = useState<InventoryItem | null>(null);

  // Fetch inventory data from API
  useEffect(() => {
    const fetchInventoryData = async () => {
      try {
        setLoading(true);
        setError(null);
        
        // Use shop owner endpoint for authenticated shop owners
        const response = await fetch('/api/shop-owner/inventory/overview');
        
        if (!response.ok) {
          throw new Error(`Failed to fetch inventory data: ${response.statusText}`);
        }

        const data = await response.json();
        
        // Format the API response to match our InventoryItem interface
        const formattedItems: InventoryItem[] = data.items.data.map((item: any) => ({
          id: item.id,
          name: item.name,
          sku: item.sku,
          category: item.category,
          quantity: item.quantity,
          image: item.image || '/images/product/product-placeholder.jpg',
          status: item.status as "In Stock" | "Low Stock" | "Out of Stock",
          lastRestocked: item.last_updated ? new Date(item.last_updated).toISOString().split('T')[0] : undefined,
        }));

        setInventoryData(formattedItems);
      } catch (err) {
        const errorMessage = err instanceof Error ? err.message : 'Failed to load inventory data';
        setError(errorMessage);
        console.error('Inventory fetch error:', err);
      } finally {
        setLoading(false);
      }
    };

    fetchInventoryData();
  }, []);

  const visibleInventoryData = isStaffAccount ? inventoryData.filter(isProductItem) : inventoryData;

  const availableCategories = Array.from(new Set(visibleInventoryData.map((item) => item.category)));

  // Filter data
  const filteredData = visibleInventoryData.filter((item) => {
    const matchesSearch = item.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
                          item.category.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesStatus = statusFilter === "All" || item.status === statusFilter;
    const matchesCategory = categoryFilter === "All" || item.category === categoryFilter;
    return matchesSearch && matchesStatus && matchesCategory;
  });

  // Pagination
  const itemsPerPage = 7;
  const totalPages = Math.ceil(filteredData.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedItems = filteredData.slice(startIndex, startIndex + itemsPerPage);

  // Calculate metrics
  const totalItems = visibleInventoryData.reduce((sum, item) => sum + item.quantity, 0);
  const lowStockCount = visibleInventoryData.filter(item => item.status === "Low Stock").length;
  const outOfStockCount = visibleInventoryData.filter(item => item.status === "Out of Stock").length;

  const handleViewClick = (item: InventoryItem) => {
    setSelectedItem(item);
    setViewModalOpen(true);
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Inventory Overview - Solespace" />
      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Inventory Overview</h1>
            <p className="text-gray-600 dark:text-gray-400">View all available stock and inventory levels (Read-only)</p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              Shop Owner View
            </span>
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200">
              Read-Only Access
            </span>
          </div>
        </div>

        {/* Error State */}
        {error && (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 flex items-start gap-3">
            <div className="shrink-0">
              <AlertIcon className="w-5 h-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
              <h3 className="font-semibold text-red-900 dark:text-red-200">Error Loading Inventory</h3>
              <p className="text-sm text-red-700 dark:text-red-300 mt-1">{error}</p>
            </div>
          </div>
        )}

        {/* Loading State */}
        {loading && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {[1, 2, 3].map((i) => (
              <div key={i} className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm animate-pulse">
                <div className="h-14 w-14 bg-gray-200 dark:bg-gray-800 rounded-2xl mb-4" />
                <div className="h-4 bg-gray-200 dark:bg-gray-800 rounded w-3/4 mb-2" />
                <div className="h-8 bg-gray-200 dark:bg-gray-800 rounded w-1/2" />
              </div>
            ))}
          </div>
        )}

        {/* Metrics */}
        {!loading && !error && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <MetricCard
              title="Total Items in Stock"
              value={totalItems}
              change={12}
              changeType="increase"
              icon={BoxIcon}
              color="info"
              description="Across all categories"
            />
            <MetricCard
              title="Low Stock Items"
              value={lowStockCount}
              change={5}
              changeType="decrease"
              icon={AlertIcon}
              color="warning"
              description="Need attention"
            />
            <MetricCard
              title="Out of Stock"
              value={outOfStockCount}
              change={2}
              changeType="decrease"
              icon={TrendUpIcon}
              color="success"
              description="Awaiting restock"
            />
          </div>
        )}

        {/* Inventory Table */}
        {!loading && !error && (
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Stock Inventory</h2>
            <p className="text-sm text-gray-500">View all products and their stock levels</p>
          </div>

          {/* Search and Filters */}
          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by product name or category..."
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
                value={categoryFilter}
                onChange={(e) => {
                  setCategoryFilter(e.target.value);
                  setCurrentPage(1);
                }}
                aria-label="Filter by category"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Categories</option>
                {availableCategories.map((category) => (
                  <option key={category} value={category}>
                    {formatCategoryLabel(category)}
                  </option>
                ))}
              </select>
            </div>
            <div className="sm:w-48">
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setCurrentPage(1);
                }}
                aria-label="Filter by stock status"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Status</option>
                <option value="In Stock">In Stock</option>
                <option value="Low Stock">Low Stock</option>
                <option value="Out of Stock">Out of Stock</option>
              </select>
            </div>
          </div>

          {/* Empty State */}
          {paginatedItems.length === 0 && !loading ? (
            <div className="py-12 text-center">
              <BoxIcon className="w-12 h-12 text-gray-400 dark:text-gray-600 mx-auto mb-4" />
              <p className="text-gray-600 dark:text-gray-400 mb-2">No inventory items found</p>
              <p className="text-sm text-gray-500 dark:text-gray-500">Try adjusting your search or filters</p>
            </div>
          ) : (
            <>
              {/* Table */}
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                    <tr>
                      <th className="pb-2">Product</th>
                      <th className="pb-2">Category</th>
                      <th className="pb-2">Quantity</th>
                      <th className="pb-2">Status</th>
                      <th className="pb-2">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {paginatedItems.map((item) => (
                      <tr key={item.id}>
                        <td className="py-3">
                          <div className="flex items-center gap-3">
                            <div className="h-12 w-12 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                              <img src={item.image} alt={item.name} className="h-full w-full object-cover" />
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
              {filteredData.length > 0 && (
                <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 mt-4">
                  <div className="flex items-center justify-between">
                    <div className="text-sm text-gray-700 dark:text-gray-300">
                      Showing <span className="font-medium">{startIndex + 1}</span> to <span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredData.length)}</span> of <span className="font-medium">{filteredData.length}</span> items
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
            </>
          )}
        </div>
        )}

        {/* View Details Modal */}
        {viewModalOpen && selectedItem && (
          <div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full">
              <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Inventory Item Details</h2>
                <button
                  onClick={() => setViewModalOpen(false)}
                  title="Close details"
                  aria-label="Close details"
                  className="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
                >
                  <CloseIcon className="w-5 h-5" />
                </button>
              </div>
              <div className="p-6 space-y-6">
                {/* Product Image */}
                <div className="flex justify-center">
                  <div className="h-48 w-48 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700">
                    <img src={selectedItem.image} alt={selectedItem.name} className="h-full w-full object-cover" />
                  </div>
                </div>

                {/* Product Details */}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Product Name</p>
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
                  {selectedItem.lastRestocked && (
                    <div className="col-span-2">
                      <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Last Restocked</p>
                      <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.lastRestocked}</p>
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
    </AppLayoutShopOwner>
  );
}
