import { Head } from "@inertiajs/react";
import { useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

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
  price: string;
  image: string;
  status: "In Stock" | "Low Stock" | "Out of Stock";
  lastRestocked?: string;
}

const inventoryData: InventoryItem[] = [
  { id: 1, name: "Nike Air Max 270", sku: "SKU-001", category: "Shoes", quantity: 45, price: "₱6,499", image: "/images/product/product-01.jpg", status: "In Stock", lastRestocked: "2026-01-28" },
  { id: 2, name: "Adidas Ultraboost 22", sku: "SKU-002", category: "Shoes", quantity: 32, price: "₱8,999", image: "/images/product/product-02.jpg", status: "In Stock", lastRestocked: "2026-01-25" },
  { id: 3, name: "New Balance 550", sku: "SKU-003", category: "Shoes", quantity: 8, price: "₱5,299", image: "/images/product/product-03.jpg", status: "Low Stock", lastRestocked: "2026-01-20" },
  { id: 4, name: "Puma RS-X", sku: "SKU-004", category: "Shoes", quantity: 28, price: "₱4,799", image: "/images/product/product-04.jpg", status: "In Stock", lastRestocked: "2026-01-30" },
  { id: 5, name: "Adidas Samba", sku: "SKU-005", category: "Shoes", quantity: 0, price: "₱5,499", image: "/images/product/product-05.jpg", status: "Out of Stock", lastRestocked: "2026-01-15" },
  { id: 6, name: "Premium Shoelaces", sku: "SKU-006", category: "Accessories", quantity: 120, price: "₱180", image: "/images/product/product-01.jpg", status: "In Stock", lastRestocked: "2026-02-01" },
  { id: 7, name: "Cleaning Foam", sku: "SKU-007", category: "Care Products", quantity: 15, price: "₱220", image: "/images/product/product-02.jpg", status: "In Stock", lastRestocked: "2026-01-22" },
  { id: 8, name: "Odor Eliminator Spray", sku: "SKU-008", category: "Care Products", quantity: 5, price: "₱260", image: "/images/product/product-03.jpg", status: "Low Stock", lastRestocked: "2026-01-18" },
];

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

export default function ERPInventoryOverview() {
  const [currentPage, setCurrentPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("All");
  const [categoryFilter, setCategoryFilter] = useState("All");
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [selectedItem, setSelectedItem] = useState<InventoryItem | null>(null);

  // Filter data
  const filteredData = inventoryData.filter((item) => {
    const matchesSearch = item.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
                          item.sku.toLowerCase().includes(searchQuery.toLowerCase());
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
  const totalItems = inventoryData.reduce((sum, item) => sum + item.quantity, 0);
  const lowStockCount = inventoryData.filter(item => item.status === "Low Stock").length;
  const outOfStockCount = inventoryData.filter(item => item.status === "Out of Stock").length;

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
            <h1 className="text-2xl font-semibold mb-1">Inventory Overview</h1>
            <p className="text-gray-600 dark:text-gray-400">View all available stock and inventory levels (Read-only)</p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              Manager View
            </span>
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200">
              Read-Only Access
            </span>
          </div>
        </div>

        {/* Metrics */}
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

        {/* Inventory Table */}
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
                placeholder="Search by product name or SKU..."
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
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Categories</option>
                <option value="Shoes">Shoes</option>
                <option value="Accessories">Accessories</option>
                <option value="Care Products">Care Products</option>
              </select>
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
                  <th className="pb-2">Product</th>
                  <th className="pb-2">SKU</th>
                  <th className="pb-2">Category</th>
                  <th className="pb-2">Quantity</th>
                  <th className="pb-2">Price</th>
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
                    <td className="py-3 text-gray-600 dark:text-gray-400">{item.sku}</td>
                    <td className="py-3 text-gray-600 dark:text-gray-400">{item.category}</td>
                    <td className="py-3">
                      <span className="font-semibold text-gray-900 dark:text-gray-100">{item.quantity}</span>
                    </td>
                    <td className="py-3 text-gray-900 dark:text-gray-100">{item.price}</td>
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
        </div>

        {/* View Details Modal */}
        {viewModalOpen && selectedItem && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full">
              <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Inventory Item Details</h2>
                <button
                  onClick={() => setViewModalOpen(false)}
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
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">SKU</p>
                    <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.sku}</p>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Category</p>
                    <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.category}</p>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Price</p>
                    <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.price}</p>
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
    </AppLayoutERP>
  );
}
