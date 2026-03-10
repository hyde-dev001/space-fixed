import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";
import Swal from "sweetalert2";

type CustomerStatus = "active" | "inactive";
type TabType = "personal" | "purchase" | "repair" | "payment" | "notes";

interface PurchaseRecord {
  id: number;
  orderNumber: string;
  itemSummary: string;
  date: string;
  amount: number;
  status: "completed" | "processing" | "cancelled";
}

interface RepairRecord {
  id: number;
  requestNumber: string;
  service: string;
  date: string;
  cost: number;
  status: "done" | "in_progress" | "queued";
}

interface PaymentRecord {
  id: number;
  reference: string;
  method: string;
  date: string;
  amount: number;
  status: "paid" | "pending" | "failed";
}

interface StaffNote {
  id: number;
  author: string;
  date: string;
  content: string;
}

interface Customer {
  id: number;
  name: string;
  email: string;
  phone: string;
  address: string;
  city: string;
  status: CustomerStatus;
  joinedAt: string;
  lastActivity: string;
  totalOrders: number;
  totalRepairs: number;
  totalSpent: number;
}

interface Stats {
  totalCustomers: number;
  activeCustomers: number;
  totalOrders: number;
  totalRepairs: number;
}

const UserGroupIcon = ({ className = "" }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3Zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.96 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z" />
  </svg>
);

const BagIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
  </svg>
);

const WrenchIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.7 6.3a4 4 0 11-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 005.4-5.4l-3.2 3.2-2.2-2.2 3.4-3.4z" />
  </svg>
);

const WalletIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-2m3-4h-6m0 0a2 2 0 100 4h6m-6-4a2 2 0 110-4h6" />
  </svg>
);

const EyeIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const getInitials = (name: string) => {
  const parts = name.split(" ").filter(Boolean);
  if (parts.length === 0) return "";
  if (parts.length === 1) return parts[0][0].toUpperCase();
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
};

const money = (value: number) => `₱${value.toLocaleString()}`;

const dateText = (value: string) => new Date(value).toLocaleDateString();



const metricCardClasses = "group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700";

export default function Customers() {
  // ── Core data state ───────────────────────────────────────────────────────
  const [customers, setCustomers]     = useState<Customer[]>([]);
  const [stats, setStats]             = useState<Stats>({ totalCustomers: 0, activeCustomers: 0, totalOrders: 0, totalRepairs: 0 });
  const [loading, setLoading]         = useState(true);

  // ── Filters + pagination ──────────────────────────────────────────────────
  const [search, setSearch]               = useState("");
  const [statusFilter, setStatusFilter]   = useState<"all" | CustomerStatus>("all");
  const [currentPage, setCurrentPage]     = useState(1);
  const itemsPerPage = 10;

  // ── Detail modal ──────────────────────────────────────────────────────────
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
  const [showDetailsModal, setShowDetailsModal]  = useState(false);
  const [activeTab, setActiveTab]                = useState<TabType>("personal");
  const [editing, setEditing]                    = useState(false);
  const [noteDraft, setNoteDraft]                = useState("");

  // ── Lazy-loaded detail data ───────────────────────────────────────────────
  const [purchases, setPurchases]         = useState<PurchaseRecord[]>([]);
  const [repairs, setRepairs]             = useState<RepairRecord[]>([]);
  const [staffNotes, setStaffNotes]       = useState<StaffNote[]>([]);
  const [loadingDetail, setLoadingDetail] = useState(false);

  const [formData, setFormData] = useState({
    name: "", email: "", phone: "", address: "", city: "",
    status: "active" as CustomerStatus,
  });

  // ── Fetch customer list ───────────────────────────────────────────────────
  const fetchCustomers = (q: string, s: string) => {
    setLoading(true);
    const params = new URLSearchParams({ search: q, status: s });
    fetch(`/api/shop-owner/customers?${params}`, { credentials: "include" })
      .then((r) => r.json())
      .then((data) => {
        setCustomers(data.customers ?? []);
        setStats(data.stats ?? { totalCustomers: 0, activeCustomers: 0, totalOrders: 0, totalRepairs: 0 });
      })
      .catch(() => setCustomers([]))
      .finally(() => setLoading(false));
  };

  // Initial load
  useEffect(() => { fetchCustomers("", "all"); }, []);

  // Debounced re-fetch on search/filter changes
  useEffect(() => {
    const timer = window.setTimeout(() => {
      fetchCustomers(search, statusFilter);
      setCurrentPage(1);
    }, 300);
    return () => window.clearTimeout(timer);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, statusFilter]);

  // Fetch purchase/repair detail when a modal tab is opened
  useEffect(() => {
    if (!showDetailsModal || !selectedCustomer) return;

    if (activeTab === "purchase" && purchases.length === 0) {
      setLoadingDetail(true);
      fetch(`/api/shop-owner/customers/${selectedCustomer.id}/orders`, { credentials: "include" })
        .then((r) => r.json())
        .then((d) => setPurchases(d.orders ?? []))
        .finally(() => setLoadingDetail(false));
    }

    if (activeTab === "repair" && repairs.length === 0) {
      setLoadingDetail(true);
      fetch(`/api/shop-owner/customers/${selectedCustomer.id}/repairs`, { credentials: "include" })
        .then((r) => r.json())
        .then((d) => setRepairs(d.repairs ?? []))
        .finally(() => setLoadingDetail(false));
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab, showDetailsModal, selectedCustomer]);

  const isFormDirty = useMemo(() => {
    if (!selectedCustomer) return false;
    return (
      formData.name !== selectedCustomer.name ||
      formData.email !== selectedCustomer.email ||
      formData.phone !== selectedCustomer.phone ||
      formData.address !== selectedCustomer.address ||
      formData.city !== selectedCustomer.city ||
      formData.status !== selectedCustomer.status
    );
  }, [formData, selectedCustomer]);

  const totalPages        = Math.max(1, Math.ceil(customers.length / itemsPerPage));
  const safeCurrentPage   = Math.min(currentPage, totalPages);
  const startIndex        = (safeCurrentPage - 1) * itemsPerPage;
  const paginatedCustomers = customers.slice(startIndex, startIndex + itemsPerPage);

  const { totalCustomers, activeCustomers, totalOrders, totalRepairs } = stats;

  const openCustomer = (customer: Customer) => {
    setSelectedCustomer(customer);
    setActiveTab("personal");
    setEditing(false);
    setPurchases([]);
    setRepairs([]);
    setStaffNotes([]);
    setNoteDraft("");
    setFormData({
      name: customer.name,
      email: customer.email,
      phone: customer.phone,
      address: customer.address,
      city: customer.city,
      status: customer.status,
    });
  };

  const startEdit = () => {
    if (!selectedCustomer) return;
    setFormData({
      name: selectedCustomer.name,
      email: selectedCustomer.email,
      phone: selectedCustomer.phone,
      address: selectedCustomer.address,
      city: selectedCustomer.city,
      status: selectedCustomer.status,
    });
    setEditing(true);
  };

  const saveEdit = async () => {
    if (!selectedCustomer) return;
    if (!isFormDirty) return;

    const result = await Swal.fire({
      title: "Save changes?",
      text: "Customer details will be updated.",
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, save",
      cancelButtonText: "Cancel",
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    const updated = { name: formData.name, email: formData.email, phone: formData.phone, address: formData.address, city: formData.city, status: formData.status };
    setCustomers((prev) => prev.map((c) => c.id === selectedCustomer.id ? { ...c, ...updated } : c));
    setSelectedCustomer((prev) => prev ? { ...prev, ...updated } : prev);
    setEditing(false);

    void Swal.fire({
      title: "Saved",
      text: "Customer details updated successfully.",
      icon: "success",
      timer: 1400,
      showConfirmButton: false,
    });
  };

  const addStaffNote = () => {
    if (!noteDraft.trim()) return;
    const newNote: StaffNote = {
      id: Date.now(),
      author: "Shop Owner",
      date: new Date().toISOString().slice(0, 10),
      content: noteDraft.trim(),
    };
    setStaffNotes((prev) => [newNote, ...prev]);
    setNoteDraft("");
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Customers - Shop Owner" />

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold text-gray-900 dark:text-white">Customers</h1>
            <p className="text-gray-600 dark:text-gray-400">View, edit, and track customer orders, service requests, payments, and staff notes.</p>
          </div>
          <div className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
            Shop Owner Workspace
          </div>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
          <div className={metricCardClasses}>
            <div className="mb-4 flex items-center justify-between">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-linear-to-br from-blue-500 to-indigo-600 text-white">
                <UserGroupIcon className="size-6" />
              </div>
              <span className="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">All</span>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">Total Customers</p>
            <h3 className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{totalCustomers}</h3>
          </div>

          <div className={metricCardClasses}>
            <div className="mb-4 flex items-center justify-between">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-linear-to-br from-green-500 to-emerald-600 text-white">
                <UserGroupIcon className="size-6" />
              </div>
              <span className="rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700 dark:bg-green-900/30 dark:text-green-400">Active</span>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">Active Customers</p>
            <h3 className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{activeCustomers}</h3>
          </div>

          <div className={metricCardClasses}>
            <div className="mb-4 flex items-center justify-between">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-linear-to-br from-yellow-500 to-orange-600 text-white">
                <BagIcon className="size-6" />
              </div>
              <span className="rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">Orders</span>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">Purchase Records</p>
            <h3 className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{totalOrders}</h3>
          </div>

          <div className={metricCardClasses}>
            <div className="mb-4 flex items-center justify-between">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-linear-to-br from-purple-500 to-fuchsia-600 text-white">
                <WrenchIcon className="size-6" />
              </div>
              <span className="rounded-full bg-purple-100 px-2.5 py-1 text-xs font-semibold text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">Repairs</span>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">Service Requests</p>
            <h3 className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{totalRepairs}</h3>
          </div>
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/3">
          <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex w-full flex-col gap-3 md:flex-row">
              <input
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setCurrentPage(1);
                }}
                placeholder="Search by name, email, or phone"
                className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              />
              <select
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(event.target.value as "all" | CustomerStatus);
                  setCurrentPage(1);
                }}
                title="Filter customers by status"
                className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white md:w-52"
              >
                <option value="all">All status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <p className="text-sm text-gray-500 dark:text-gray-400">{customers.length} customers</p>
          </div>

          <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table className="w-full min-w-210">
              <thead className="bg-gray-50 dark:bg-gray-800">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Customer</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Contact</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Orders</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Repairs</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Spent</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Last Activity</th>
                  <th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">View</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {loading ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-12 text-center">
                      <div className="inline-block h-7 w-7 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
                    </td>
                  </tr>
                ) : paginatedCustomers.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                      No customers found.
                    </td>
                  </tr>
                ) : paginatedCustomers.map((customer) => (
                  <tr key={customer.id} className="bg-white transition-colors hover:bg-gray-50 dark:bg-transparent dark:hover:bg-gray-800/40">
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-linear-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white">
                          {getInitials(customer.name)}
                        </div>
                        <div>
                          <p className="text-sm font-semibold text-gray-900 dark:text-white">{customer.name}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">Customer #{customer.id}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                      <p>{customer.email}</p>
                      <p className="text-xs text-gray-500 dark:text-gray-400">{customer.phone}</p>
                    </td>
                    <td className="px-4 py-3 text-sm">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                          customer.status === "active"
                            ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                            : "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                        }`}
                      >
                        {customer.status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{customer.totalOrders}</td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{customer.totalRepairs}</td>
                    <td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{money(customer.totalSpent)}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{dateText(customer.lastActivity)}</td>
                    <td className="px-4 py-3 text-center">
                      <button
                        onClick={() => {
                          openCustomer(customer);
                          setShowDetailsModal(true);
                        }}
                        title={`View ${customer.name}`}
                        className="inline-flex items-center justify-center bg-transparent text-blue-600 transition-colors hover:text-blue-700 dark:bg-transparent dark:text-blue-400 dark:hover:text-blue-300"
                      >
                        <EyeIcon className="size-5" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {customers.length > itemsPerPage && (
            <div className="mt-4 flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-700 md:flex-row md:items-center md:justify-between">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, customers.length)} of {customers.length} customers
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
                  disabled={safeCurrentPage === 1}
                  className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                  Previous
                </button>
                <button
                  onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
                  disabled={safeCurrentPage === totalPages}
                  className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </div>

        {showDetailsModal && selectedCustomer && (
          <>
            <div className="fixed inset-0 z-100000 bg-black/50" />
            <div className="fixed inset-0 z-100001 flex items-center justify-center p-4">
              <div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <div className="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 md:flex-row md:items-center md:justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-linear-to-br from-blue-500 to-indigo-600 text-base font-bold text-white">
                      {getInitials(selectedCustomer.name)}
                    </div>
                    <div>
                      <h2 className="text-lg font-semibold text-gray-900 dark:text-white">{selectedCustomer.name}</h2>
                      <p className="text-sm text-gray-500 dark:text-gray-400">Customer #{selectedCustomer.id} • Joined {dateText(selectedCustomer.joinedAt)}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    {editing ? (
                      <>
                        <button
                          onClick={() => setEditing(false)}
                          className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                          Cancel
                        </button>
                        <button
                          onClick={saveEdit}
                          disabled={!isFormDirty}
                          className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:hover:bg-gray-400 dark:disabled:bg-gray-600"
                        >
                          Save Changes
                        </button>
                      </>
                    ) : (
                      <>
                        <button onClick={startEdit} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                          Edit Customer
                        </button>
                        <button
                          onClick={() => {
                            setShowDetailsModal(false);
                            setEditing(false);
                            setNoteDraft("");
                            setActiveTab("personal");
                          }}
                          className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                          Close
                        </button>
                      </>
                    )}
                  </div>
                </div>

                <div className="mt-5 flex flex-wrap gap-2 border-b border-gray-200 pb-4 dark:border-gray-800">
                  {[
                    { key: "personal", label: "Personal details" },
                    { key: "purchase", label: "Purchase history" },
                    { key: "repair", label: "Repair history" },
                    { key: "payment", label: "Payment history" },
                    { key: "notes", label: "Notes" },
                  ].map((tab) => (
                    <button
                      key={tab.key}
                      onClick={() => setActiveTab(tab.key as TabType)}
                      className={`rounded-lg px-3.5 py-2 text-sm font-medium transition-colors ${
                        activeTab === tab.key
                          ? "bg-blue-600 text-white"
                          : "bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                      }`}
                    >
                      {tab.label}
                    </button>
                  ))}
                </div>

                <div className="mt-5">
                  {activeTab === "personal" && (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Full Name</p>
                        {editing ? (
                          <input
                            value={formData.name}
                            onChange={(event) => setFormData((prev) => ({ ...prev, name: event.target.value }))}
                            title="Customer full name"
                            placeholder="Enter full name"
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                          />
                        ) : (
                          <p className="text-sm font-medium text-gray-900 dark:text-white">{selectedCustomer.name}</p>
                        )}
                      </div>

                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Email</p>
                        {editing ? (
                          <input
                            value={formData.email}
                            onChange={(event) => setFormData((prev) => ({ ...prev, email: event.target.value }))}
                            title="Customer email"
                            placeholder="Enter email address"
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                          />
                        ) : (
                          <p className="text-sm font-medium text-gray-900 dark:text-white">{selectedCustomer.email}</p>
                        )}
                      </div>

                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Phone</p>
                        {editing ? (
                          <input
                            value={formData.phone}
                            onChange={(event) => setFormData((prev) => ({ ...prev, phone: event.target.value }))}
                            title="Customer phone number"
                            placeholder="Enter phone number"
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                          />
                        ) : (
                          <p className="text-sm font-medium text-gray-900 dark:text-white">{selectedCustomer.phone}</p>
                        )}
                      </div>

                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Status</p>
                        {editing ? (
                          <select
                            value={formData.status}
                            onChange={(event) => setFormData((prev) => ({ ...prev, status: event.target.value as CustomerStatus }))}
                            title="Customer status"
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                          >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                          </select>
                        ) : (
                          <span
                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                              selectedCustomer.status === "active"
                                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                : "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                            }`}
                          >
                            {selectedCustomer.status}
                          </span>
                        )}
                      </div>

                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700 md:col-span-2">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Address</p>
                        {editing ? (
                          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <input
                              value={formData.address}
                              onChange={(event) => setFormData((prev) => ({ ...prev, address: event.target.value }))}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                              placeholder="Street"
                            />
                            <input
                              value={formData.city}
                              onChange={(event) => setFormData((prev) => ({ ...prev, city: event.target.value }))}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                              placeholder="City"
                            />
                          </div>
                        ) : (
                          <p className="text-sm font-medium text-gray-900 dark:text-white">{selectedCustomer.address}, {selectedCustomer.city}</p>
                        )}
                      </div>
                    </div>
                  )}

                  {activeTab === "purchase" && (
                    loadingDetail ? (
                      <div className="flex justify-center py-10">
                        <div className="h-7 w-7 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
                      </div>
                    ) : purchases.length === 0 ? (
                      <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No purchase records found.</p>
                    ) : (
                    <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                      <table className="w-full min-w-170">
                        <thead className="bg-gray-50 dark:bg-gray-800">
                          <tr>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Order</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Items</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Date</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Amount</th>
                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                          {purchases.map((entry) => (
                            <tr key={entry.id} className="bg-white dark:bg-transparent">
                              <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{entry.orderNumber}</td>
                              <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{entry.itemSummary}</td>
                              <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{dateText(entry.date)}</td>
                              <td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{money(entry.amount)}</td>
                              <td className="px-4 py-3 text-sm">
                                <span
                                  className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                    entry.status === "completed"
                                      ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                      : entry.status === "processing"
                                      ? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400"
                                      : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
                                  }`}
                                >
                                  {entry.status.replace("_", " ")}
                                </span>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    )
                  )}

                  {activeTab === "repair" && (
                    loadingDetail ? (
                      <div className="flex justify-center py-10">
                        <div className="h-7 w-7 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
                      </div>
                    ) : repairs.length === 0 ? (
                      <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No repair records found.</p>
                    ) : (
                    <div className="space-y-3">
                      {repairs.map((entry) => (
                        <div key={entry.id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                              <p className="text-sm font-semibold text-gray-900 dark:text-white">{entry.requestNumber} • {entry.service}</p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">Requested on {dateText(entry.date)}</p>
                            </div>
                            <div className="flex items-center gap-3">
                              <span className="text-sm font-semibold text-gray-900 dark:text-white">{money(entry.cost)}</span>
                              <span
                                className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                  entry.status === "done"
                                    ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                                    : entry.status === "in_progress"
                                    ? "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                                    : "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400"
                                }`}
                              >
                                {entry.status.replace("_", " ")}
                              </span>
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                    )
                  )}

                  {activeTab === "payment" && (
                    <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Payment history is not available yet.</p>
                  )}

                  {activeTab === "notes" && (
                    <div className="space-y-4">
                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Add staff note</label>
                        <textarea
                          value={noteDraft}
                          onChange={(event) => setNoteDraft(event.target.value)}
                          rows={3}
                          placeholder="Write a note about this customer..."
                          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        />
                        <div className="mt-3 flex justify-end">
                          <button onClick={addStaffNote} className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            <WalletIcon className="size-4" />
                            Save Note
                          </button>
                        </div>
                      </div>

                      <div className="space-y-3">
                        {staffNotes.length === 0 ? (
                          <p className="text-center text-sm text-gray-500 dark:text-gray-400">No notes yet.</p>
                        ) : staffNotes.map((note) => (
                          <div key={note.id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div className="mb-1 flex items-center justify-between gap-2">
                              <p className="text-sm font-semibold text-gray-900 dark:text-white">{note.author}</p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">{dateText(note.date)}</p>
                            </div>
                            <p className="text-sm text-gray-600 dark:text-gray-300">{note.content}</p>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>

                <div className="mt-5 grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/40 md:grid-cols-3">
                  <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">Lifetime Spend</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{money(selectedCustomer.totalSpent)}</p>
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">Last Activity</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{dateText(selectedCustomer.lastActivity)}</p>
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">Service Requests</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{selectedCustomer.totalRepairs}</p>
                  </div>
                </div>
              </div>
            </div>
          </>
        )}
      </div>
    </AppLayoutShopOwner>
  );
}
