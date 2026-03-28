import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import type { ComponentType } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import repairMaterialsApi, { type RepairMaterialInventoryItem } from "../../../services/repairMaterialsApi";

type MetricColor = "success" | "warning" | "info";
type ChangeType = "increase" | "decrease";
type StatusFilter = "All" | "In Stock" | "Low Stock" | "Out of Stock";

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

const formatCategoryLabel = (category: string | null | undefined): string => {
  const raw = String(category ?? "").trim();
  if (!raw) return "Uncategorized";
  return raw
    .split("_")
    .map((word) => (word ? word.charAt(0).toUpperCase() + word.slice(1) : ""))
    .join(" ");
};

const getCategoryBadgeClasses = (category: string | null | undefined): string => {
  const normalized = String(category ?? "").toLowerCase();

  if (normalized === "repair_materials") {
    return "bg-sky-50 text-sky-700 ring-1 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-200 dark:ring-sky-800";
  }

  if (normalized === "shoes") {
    return "bg-violet-50 text-violet-700 ring-1 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-200 dark:ring-violet-800";
  }

  return "bg-gray-100 text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700";
};

const resolveImageUrl = (item: RepairMaterialInventoryItem): string | null => {
  const thumbnail = item.images?.find((image: { is_thumbnail?: boolean }) => image.is_thumbnail) ?? item.images?.[0];
  const path = thumbnail?.image_path;
  if (!path) return null;
  if (path.startsWith("http://") || path.startsWith("https://") || path.startsWith("/")) return path;
  return `/storage/${path}`;
};

export default function RepairStocksOverview() {
  const [currentPage, setCurrentPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("All");
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [selectedItem, setSelectedItem] = useState<RepairMaterialInventoryItem | null>(null);
  const [inventoryData, setInventoryData] = useState<RepairMaterialInventoryItem[]>([]);
  const [metrics, setMetrics] = useState({ total_items: 0, low_stock_count: 0, out_of_stock_count: 0 });
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    const timeout = window.setTimeout(async () => {
      try {
        setIsLoading(true);
        const response = await repairMaterialsApi.getStocksOverview({
          category: "repair_materials",
          search: searchQuery.trim() || undefined,
          status: statusFilter === "All" ? undefined : statusFilter,
        });

        if (response.success) {
          setInventoryData(response.data ?? []);
          setMetrics(response.metrics ?? { total_items: 0, low_stock_count: 0, out_of_stock_count: 0 });
          setCurrentPage(1);
        }
      } catch (error) {
        console.error("Failed to load repair stocks overview", error);
        setInventoryData([]);
      } finally {
        setIsLoading(false);
      }
    }, 250);

    return () => window.clearTimeout(timeout);
  }, [searchQuery, statusFilter]);

  const itemsPerPage = 7;
  const totalPages = Math.max(1, Math.ceil(inventoryData.length / itemsPerPage));

  const paginatedItems = useMemo(() => {
    const safePage = Math.min(currentPage, totalPages);
    const startIndex = (safePage - 1) * itemsPerPage;
    return inventoryData.slice(startIndex, startIndex + itemsPerPage);
  }, [currentPage, inventoryData, totalPages]);

  return (
    <AppLayoutERP>
      <Head title="Stocks Overview - Repair - Solespace" />
      <div className="p-6 space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Stocks Overview</h1>
            <p className="text-gray-600 dark:text-gray-400">Monitor repair-material stock levels and item availability</p>
          </div>
          <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200 w-fit">
            Repair Materials
          </span>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <MetricCard title="Total Items in Stock" value={metrics.total_items} change={12} changeType="increase" icon={BoxIcon} color="info" description="Across repair materials" />
          <MetricCard title="Low Stock Items" value={metrics.low_stock_count} change={5} changeType="decrease" icon={AlertIcon} color="warning" description="Need attention" />
          <MetricCard title="Out of Stock" value={metrics.out_of_stock_count} change={2} changeType="decrease" icon={TrendUpIcon} color="success" description="Awaiting restock" />
        </div>

        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Repair Materials Inventory</h2>
            <p className="text-sm text-gray-500">View stock items available for repair operations</p>
          </div>

          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by material name..."
                value={searchQuery}
                onChange={(event) => setSearchQuery(event.target.value)}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              />
            </div>
            <div className="sm:w-48">
              <select
                title="Filter by stock status"
                aria-label="Filter by stock status"
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value as StatusFilter)}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
              >
                <option value="All">All Status</option>
                <option value="In Stock">In Stock</option>
                <option value="Low Stock">Low Stock</option>
                <option value="Out of Stock">Out of Stock</option>
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Material</th>
                  <th className="pb-2">Category</th>
                  <th className="pb-2">Quantity</th>
                  <th className="pb-2">Status</th>
                  <th className="pb-2">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {isLoading ? (
                  <tr>
                    <td colSpan={5} className="py-8 text-center text-gray-500">Loading stocks...</td>
                  </tr>
                ) : paginatedItems.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-8 text-center text-gray-500">No repair materials found.</td>
                  </tr>
                ) : (
                  paginatedItems.map((item) => {
                    const imageUrl = resolveImageUrl(item);

                    return (
                    <tr key={item.id}>
                      <td className="py-3">
                        <div className="flex items-center gap-3">
                          {imageUrl && (
                            <div className="h-12 w-12 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                              <img src={imageUrl} alt={item.name} className="h-full w-full object-cover" />
                            </div>
                          )}
                          <p className="font-medium text-gray-900 dark:text-gray-100">{item.name}</p>
                        </div>
                      </td>
                      <td className="py-3">
                        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getCategoryBadgeClasses(item.category)}`}>
                          {formatCategoryLabel(item.category)}
                        </span>
                      </td>
                      <td className="py-3 font-semibold text-gray-900 dark:text-gray-100">{item.available_quantity}</td>
                      <td className="py-3">
                        <span className={`px-2 py-1 rounded-full text-xs font-semibold ${
                          item.available_quantity <= 0
                            ? "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                            : item.available_quantity <= Number(item.reorder_level ?? 0)
                            ? "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                            : "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                        }`}>
                          {item.available_quantity <= 0 ? "Out of Stock" : item.available_quantity <= Number(item.reorder_level ?? 0) ? "Low Stock" : "In Stock"}
                        </span>
                      </td>
                      <td className="py-3">
                        <button
                          onClick={() => {
                            setSelectedItem(item);
                            setViewModalOpen(true);
                          }}
                          className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 transition-colors"
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
        </div>

        {viewModalOpen && selectedItem && (
          <div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full">
              <div className="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Material Details</h2>
                <button onClick={() => setViewModalOpen(false)} className="p-2 rounded-lg text-gray-500 hover:text-gray-700 dark:hover:text-gray-300" title="Close">
                  <CloseIcon className="w-5 h-5" />
                </button>
              </div>
              <div className="p-6 grid grid-cols-2 gap-4">
                <div>
                  <p className="text-sm text-gray-500">Material Name</p>
                  <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.name}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500">Category</p>
                  <span className={`mt-1 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getCategoryBadgeClasses(selectedItem.category)}`}>
                    {formatCategoryLabel(selectedItem.category)}
                  </span>
                </div>
                <div>
                  <p className="text-sm text-gray-500">Available Quantity</p>
                  <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.available_quantity}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500">Reorder Level</p>
                  <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.reorder_level ?? 0}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500">Unit</p>
                  <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedItem.unit || "—"}</p>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutERP>
  );
}