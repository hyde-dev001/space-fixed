import { useEffect, useMemo, useState } from "react";
import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

interface ProductItem {
  id: number;
  name: string;
  sku: string;
  category: string;
  quantity: number;
  price: number;
  status: "Active" | "Inactive";
  stock_status: "In Stock" | "Low Stock" | "Out of Stock";
  image: string | null;
  updated_at: string | null;
}

interface PaginatedResponse<T> {
  current_page: number;
  data: T[];
  last_page: number;
  per_page: number;
  total: number;
}

interface ProductsResponse {
  products: PaginatedResponse<ProductItem>;
  summary: {
    total: number;
    active: number;
    inactive: number;
  };
  categories: string[];
}

export default function ProductsPage() {
  const initialQuery = typeof window !== "undefined"
    ? new URLSearchParams(window.location.search)
    : new URLSearchParams();

  const initialPage = Number(initialQuery.get("page") || "1");
  const requestedStatus = initialQuery.get("status") || "All";
  const allowedStatuses = ["All", "Active", "Inactive"];

  const [products, setProducts] = useState<ProductItem[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [summary, setSummary] = useState({ total: 0, active: 0, inactive: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(Number.isFinite(initialPage) && initialPage > 0 ? initialPage : 1);
  const [lastPage, setLastPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [total, setTotal] = useState(0);
  const [search, setSearch] = useState(initialQuery.get("search") || "");
  const [statusFilter, setStatusFilter] = useState(allowedStatuses.includes(requestedStatus) ? requestedStatus : "All");
  const [categoryFilter, setCategoryFilter] = useState(initialQuery.get("category") || "All");

  useEffect(() => {
    const fetchProducts = async () => {
      try {
        setLoading(true);
        setError(null);

        const params = new URLSearchParams({
          page: currentPage.toString(),
          per_page: "10",
        });

        if (search.trim()) params.append("search", search.trim());
        if (statusFilter !== "All") params.append("status", statusFilter);
        if (categoryFilter !== "All") params.append("category", categoryFilter);

        const response = await fetch(`/api/manager/products?${params.toString()}`, {
          credentials: "include",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
        });

        if (!response.ok) {
          throw new Error("Failed to load products");
        }

        const payload: ProductsResponse = await response.json();
        setProducts(payload.products.data);
        setSummary(payload.summary);
        setCategories(payload.categories || []);
        setLastPage(payload.products.last_page || 1);
        setCurrentPage(payload.products.current_page || 1);
        setPerPage(payload.products.per_page || 10);
        setTotal(payload.products.total || 0);
      } catch (fetchError) {
        setError(fetchError instanceof Error ? fetchError.message : "Failed to load products");
      } finally {
        setLoading(false);
      }
    };

    fetchProducts();
  }, [currentPage, search, statusFilter, categoryFilter]);

  const startItem = useMemo(() => ((currentPage - 1) * perPage) + 1, [currentPage, perPage]);
  const endItem = useMemo(() => Math.min(currentPage * perPage, total), [currentPage, perPage, total]);

  const formatPrice = (value: number) =>
    new Intl.NumberFormat("en-PH", {
      style: "currency",
      currency: "PHP",
      minimumFractionDigits: 2,
    }).format(value || 0);

  return (
    <AppLayoutERP>
      <Head title="Products - Solespace ERP" />

      <div className="p-6 space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Products</h1>
            <p className="text-gray-600 dark:text-gray-400">Manager view of retail product inventory and stock health</p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <span className="px-3 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">
              Manager View
            </span>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="rounded-xl border border-gray-200 dark:border-gray-800 p-4 bg-white dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Total Products</p>
            <p className="text-2xl font-semibold text-gray-900 dark:text-white">{summary.total}</p>
          </div>
          <div className="rounded-xl border border-gray-200 dark:border-gray-800 p-4 bg-white dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Active</p>
            <p className="text-2xl font-semibold text-emerald-600">{summary.active}</p>
          </div>
          <div className="rounded-xl border border-gray-200 dark:border-gray-800 p-4 bg-white dark:bg-white/3">
            <p className="text-sm text-gray-500 dark:text-gray-400">Inactive</p>
            <p className="text-2xl font-semibold text-red-600">{summary.inactive}</p>
          </div>
        </div>

        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by product name or SKU..."
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setCurrentPage(1);
                }}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
              />
            </div>

            <div className="sm:w-48">
              <select
                value={categoryFilter}
                onChange={(event) => {
                  setCategoryFilter(event.target.value);
                  setCurrentPage(1);
                }}
                title="Filter products by category"
                aria-label="Filter products by category"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
              >
                <option value="All">All Categories</option>
                {categories.map((category) => (
                  <option key={category} value={category}>{category}</option>
                ))}
              </select>
            </div>

            <div className="sm:w-48">
              <select
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(event.target.value);
                  setCurrentPage(1);
                }}
                title="Filter products by lifecycle status"
                aria-label="Filter products by lifecycle status"
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
              >
                <option value="All">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Product</th>
                  <th className="pb-2">SKU</th>
                  <th className="pb-2">Category</th>
                  <th className="pb-2">Quantity</th>
                  <th className="pb-2">Price</th>
                  <th className="pb-2">Stock</th>
                  <th className="pb-2">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {loading ? (
                  <tr>
                    <td colSpan={7} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">Loading products...</td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan={7} className="py-10 text-center text-sm text-red-600 dark:text-red-400">{error}</td>
                  </tr>
                ) : products.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">No products found.</td>
                  </tr>
                ) : products.map((product) => (
                  <tr key={product.id}>
                    <td className="py-3">
                      <div className="flex items-center gap-3">
                        <div className="h-12 w-12 overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
                          <img src={product.image || "/images/product/product-01.jpg"} alt={product.name} className="h-full w-full object-cover" />
                        </div>
                        <div>
                          <p className="font-medium text-gray-900 dark:text-gray-100">{product.name}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">{product.updated_at ?? "-"}</p>
                        </div>
                      </div>
                    </td>
                    <td className="py-3 text-gray-700 dark:text-gray-300">{product.sku}</td>
                    <td className="py-3 text-gray-700 dark:text-gray-300">{product.category}</td>
                    <td className="py-3 font-semibold text-gray-900 dark:text-gray-100">{product.quantity}</td>
                    <td className="py-3 text-gray-900 dark:text-gray-100">{formatPrice(product.price)}</td>
                    <td className="py-3">
                      <span className={`px-2 py-1 rounded-full text-xs font-semibold ${
                        product.stock_status === "In Stock"
                          ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                          : product.stock_status === "Low Stock"
                            ? "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                            : "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                      }`}>
                        {product.stock_status}
                      </span>
                    </td>
                    <td className="py-3">
                      <span className={`px-2 py-1 rounded-full text-xs font-semibold ${
                        product.status === "Active"
                          ? "bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200"
                          : "bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                      }`}>
                        {product.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!loading && !error && total > 0 && (
            <div className="px-1 py-4 border-t border-gray-200 dark:border-gray-700 mt-4 flex items-center justify-between">
              <div className="text-sm text-gray-700 dark:text-gray-300">
                Showing <span className="font-medium">{startItem}</span> to <span className="font-medium">{endItem}</span> of <span className="font-medium">{total}</span> products
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                  disabled={currentPage === 1}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 disabled:opacity-50"
                  title="Previous page"
                  aria-label="Previous page"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                  </svg>
                </button>
                <button
                  onClick={() => setCurrentPage((prev) => Math.min(prev + 1, lastPage))}
                  disabled={currentPage === lastPage}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 disabled:opacity-50"
                  title="Next page"
                  aria-label="Next page"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                  </svg>
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </AppLayoutERP>
  );
}
