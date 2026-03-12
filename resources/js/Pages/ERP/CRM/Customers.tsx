import { Head, usePage } from "@inertiajs/react";
import { useMemo, useState, useCallback } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import Swal from "sweetalert2";
import axios from "axios";

type CustomerStatus = "active" | "inactive";
type TabType = "personal" | "purchase" | "repair" | "notes";

interface StaffNote {
  id: number;
  author: string;
  date: string;
  content: string;
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

// seedCustomers removed — data comes from Inertia props (initialCustomers)

// ─── Inertia prop shape from the server ──────────────────────────────────────
interface CustomerListItem {
  id: number;
  name: string;
  email: string;
  phone: string;
  address: string;
  status: CustomerStatus;
  totalOrders: number;
  totalRepairs: number;
  totalSpent: number;
  lastActivity: string | null;
  memberSince: string;
  notesCount: number;
}

// Full detail loaded lazily when the modal opens
interface CustomerDetail extends CustomerListItem {
  orders: Array<{
    id: number; order_number: string; total_amount: number;
    status: string; created_at: string;
    items?: Array<{ id: number; product_name?: string; quantity?: number; price?: number }>;
  }>;
  repairs: Array<{
    id: number; request_id?: string; shoe_type?: string; description?: string;
    total: number; status: string; created_at: string;
  }>;
  notes: StaffNote[];
  stats: { total_orders: number; total_repairs: number; total_spent: number; member_since: string | null };
}

const metricCardClasses = "group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700";

export default function Customers() {
  const { initialCustomers = [] } = usePage<{ initialCustomers: CustomerListItem[] }>().props;

  const [customers, setCustomers] = useState<CustomerListItem[]>(initialCustomers);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | CustomerStatus>("all");
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null);
  const [activeTab, setActiveTab] = useState<TabType>("personal");
  const [editing, setEditing] = useState(false);
  const [noteDraft, setNoteDraft] = useState("");
  const [showDetailsModal, setShowDetailsModal] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [savingEdit, setSavingEdit] = useState(false);
  const [savingNote, setSavingNote] = useState(false);
  const [customerDetail, setCustomerDetail] = useState<CustomerDetail | null>(null);
  const itemsPerPage = 5;

  const [formData, setFormData] = useState({
    name: "",
    email: "",
    phone: "",
    address: "",
    city: "",
    status: "active" as CustomerStatus,
  });

  const filteredCustomers = useMemo(() => {
    return customers.filter((customer) => {
      const text = `${customer.name} ${customer.email} ${customer.phone}`.toLowerCase();
      const matchesSearch = text.includes(search.toLowerCase());
      const matchesStatus = statusFilter === "all" || customer.status === statusFilter;
      return matchesSearch && matchesStatus;
    });
  }, [customers, search, statusFilter]);

  const isFormDirty = useMemo(() => {
    if (!customerDetail) return false;
    return (
      formData.name    !== customerDetail.name    ||
      formData.phone   !== customerDetail.phone   ||
      formData.address !== customerDetail.address ||
      formData.status  !== customerDetail.status
    );
  }, [formData, customerDetail]);

  const totalPages = Math.max(1, Math.ceil(filteredCustomers.length / itemsPerPage));
  const safeCurrentPage = Math.min(currentPage, totalPages);
  const startIndex = (safeCurrentPage - 1) * itemsPerPage;
  const paginatedCustomers = filteredCustomers.slice(startIndex, startIndex + itemsPerPage);

  const totalCustomers = customers.length;
  const activeCustomers = customers.filter((customer) => customer.status === "active").length;
  const totalOrders = customers.reduce((sum, customer) => sum + customer.totalOrders, 0);
  const totalRepairs = customers.reduce((sum, customer) => sum + customer.totalRepairs, 0);

  // Lazy-load full customer detail when modal opens
  const openCustomer = useCallback(async (customer: CustomerListItem) => {
    setSelectedCustomerId(customer.id);
    setActiveTab("personal");
    setEditing(false);
    setCustomerDetail(null);
    setShowDetailsModal(true);
    setLoadingDetail(true);
    try {
      const { data } = await axios.get<CustomerDetail>(`/api/crm/customers/${customer.id}`);
      // Normalise: server returns { customer, orders, repairs, notes, stats }
      const detail: CustomerDetail = {
        ...(data as any).customer,
        orders:  (data as any).orders  ?? [],
        repairs: (data as any).repairs ?? [],
        notes:   (data as any).notes   ?? [],
        stats:   (data as any).stats   ?? {},
      };
      setCustomerDetail(detail);
      setFormData({
        name:    detail.name    ?? "",
        email:   detail.email   ?? "",
        phone:   detail.phone   ?? "",
        address: detail.address ?? "",
        city:    "",
        status:  (detail.status as CustomerStatus) ?? "active",
      });
    } catch {
      void Swal.fire({ title: "Error", text: "Failed to load customer details.", icon: "error", timer: 2000, showConfirmButton: false });
      setShowDetailsModal(false);
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  const startEdit = () => {
    if (!customerDetail) return;
    setFormData({
      name:    customerDetail.name    ?? "",
      email:   customerDetail.email   ?? "",
      phone:   customerDetail.phone   ?? "",
      address: customerDetail.address ?? "",
      city:    "",
      status:  (customerDetail.status as CustomerStatus) ?? "active",
    });
    setEditing(true);
  };

  const saveEdit = async () => {
    if (!customerDetail || !isFormDirty) return;

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

    try {
      setSavingEdit(true);
      await axios.put(`/api/crm/customers/${customerDetail.id}`, {
        name:    formData.name,
        phone:   formData.phone,
        address: formData.address,
        status:  formData.status,
      });
      // Update list row
      setCustomers((prev) =>
        prev.map((c) =>
          c.id === customerDetail.id
            ? { ...c, name: formData.name, phone: formData.phone, address: formData.address, status: formData.status as CustomerStatus }
            : c
        )
      );
      setCustomerDetail((prev) => prev ? { ...prev, name: formData.name, phone: formData.phone, address: formData.address, status: formData.status as CustomerStatus } : prev);
      setEditing(false);
      void Swal.fire({ title: "Saved", text: "Customer details updated.", icon: "success", timer: 1400, showConfirmButton: false });
    } catch {
      void Swal.fire({ title: "Error", text: "Failed to save changes.", icon: "error", timer: 2000, showConfirmButton: false });
    } finally {
      setSavingEdit(false);
    }
  };

  const addStaffNote = async () => {
    if (!customerDetail || !noteDraft.trim()) return;
    try {
      setSavingNote(true);
      const { data } = await axios.post(`/api/crm/customers/${customerDetail.id}/notes`, { content: noteDraft.trim() });
      const saved: StaffNote = {
        id:      data.note?.id      ?? Date.now(),
        author:  data.note?.author  ?? "CRM Staff",
        date:    data.note?.created_at ? data.note.created_at.slice(0, 10) : new Date().toISOString().slice(0, 10),
        content: data.note?.content ?? noteDraft.trim(),
      };
      setCustomerDetail((prev) => prev ? { ...prev, notes: [saved, ...prev.notes] } : prev);
      setCustomers((prev) => prev.map((c) => c.id === customerDetail.id ? { ...c, notesCount: c.notesCount + 1 } : c));
      setNoteDraft("");
    } catch {
      void Swal.fire({ title: "Error", text: "Failed to save note.", icon: "error", timer: 2000, showConfirmButton: false });
    } finally {
      setSavingNote(false);
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Customers - Solespace ERP" />

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold text-gray-900 dark:text-white">Customers</h1>
            <p className="text-gray-600 dark:text-gray-400">View, edit, and track customer orders, service requests, payments, and staff notes.</p>
          </div>
          <div className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
            CRM Workspace
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
            <p className="text-sm text-gray-500 dark:text-gray-400">{filteredCustomers.length} customers</p>
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
                {paginatedCustomers.map((customer) => (
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
                        onClick={() => openCustomer(customer)}
                        title={`View ${customer.name}`}
                        className="inline-flex items-center justify-center bg-transparent text-blue-600 transition-colors hover:text-blue-700 dark:bg-transparent dark:text-blue-400 dark:hover:text-blue-300"
                      >
                        <EyeIcon className="size-5" />
                      </button>
                    </td>
                  </tr>
                ))}

                {filteredCustomers.length === 0 && (
                  <tr>
                    <td colSpan={8} className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                      No customers found for the current filter.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {filteredCustomers.length > 0 && (
            <div className="mt-4 flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-700 md:flex-row md:items-center md:justify-between">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredCustomers.length)} of {filteredCustomers.length} customers
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

        {showDetailsModal && (
          <>
            <div className="fixed inset-0 z-100000 bg-black/50" />
            <div className="fixed inset-0 z-100001 flex items-center justify-center p-4">
              <div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                {/* Loading skeleton */}
                {loadingDetail && (
                  <div className="flex flex-col gap-4 animate-pulse">
                    <div className="flex items-center gap-3 border-b border-gray-200 pb-5">
                      <div className="h-12 w-12 rounded-full bg-gray-200 dark:bg-gray-700" />
                      <div className="space-y-2">
                        <div className="h-4 w-40 rounded bg-gray-200 dark:bg-gray-700" />
                        <div className="h-3 w-24 rounded bg-gray-200 dark:bg-gray-700" />
                      </div>
                    </div>
                    {[...Array(4)].map((_, i) => (
                      <div key={i} className="h-16 rounded-xl bg-gray-100 dark:bg-gray-800" />
                    ))}
                  </div>
                )}

                {!loadingDetail && customerDetail && (<>
                <div className="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 md:flex-row md:items-center md:justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-linear-to-br from-blue-500 to-indigo-600 text-base font-bold text-white">
                      {getInitials(customerDetail.name)}
                    </div>
                    <div>
                      <h2 className="text-lg font-semibold text-gray-900 dark:text-white">{customerDetail.name}</h2>
                      <p className="text-sm text-gray-500 dark:text-gray-400">Customer #{customerDetail.id} • Joined {customerDetail.memberSince ?? "—"}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    {editing ? (
                      <>
                        <button onClick={() => setEditing(false)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
                        <button
                          onClick={saveEdit}
                          disabled={!isFormDirty || savingEdit}
                          className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400 disabled:hover:bg-gray-400 dark:disabled:bg-gray-600"
                        >
                          {savingEdit ? "Saving…" : "Save Changes"}
                        </button>
                      </>
                    ) : (
                      <>
                        <button onClick={startEdit} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Edit Customer</button>
                        <button
                          onClick={() => { setShowDetailsModal(false); setEditing(false); setNoteDraft(""); setActiveTab("personal"); setCustomerDetail(null); }}
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
                    { key: "purchase", label: `Purchase history (${customerDetail.orders?.length ?? 0})` },
                    { key: "repair",   label: `Repair history (${customerDetail.repairs?.length ?? 0})` },
                    { key: "notes",    label: `Notes (${customerDetail.notes?.length ?? 0})` },
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
                      {[ 
                        { label: "Full Name",  field: "name"    as const, placeholder: "Enter full name" },
                        { label: "Email",      field: "email"   as const, placeholder: "Email (read-only)", readonly: true },
                        { label: "Phone",      field: "phone"   as const, placeholder: "Enter phone number" },
                      ].map(({ label, field, placeholder, readonly }) => (
                        <div key={field} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">{label}</p>
                          {editing && !readonly ? (
                            <input
                              value={formData[field]}
                              onChange={(e) => setFormData((prev) => ({ ...prev, [field]: e.target.value }))}
                              placeholder={placeholder}
                              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                            />
                          ) : (
                            <p className="text-sm font-medium text-gray-900 dark:text-white">{(customerDetail as any)[field] || "—"}</p>
                          )}
                        </div>
                      ))}

                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Status</p>
                        {editing ? (
                          <select value={formData.status} onChange={(e) => setFormData((prev) => ({ ...prev, status: e.target.value as CustomerStatus }))} className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                          </select>
                        ) : (
                          <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${customerDetail.status === "active" ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" : "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"}`}>{customerDetail.status}</span>
                        )}
                      </div>

                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700 md:col-span-2">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Address</p>
                        {editing ? (
                          <input value={formData.address} onChange={(e) => setFormData((prev) => ({ ...prev, address: e.target.value }))} className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white" placeholder="Street / full address" />
                        ) : (
                          <p className="text-sm font-medium text-gray-900 dark:text-white">{customerDetail.address || "—"}</p>
                        )}
                      </div>
                    </div>
                  )}

                  {activeTab === "purchase" && (
                    <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                      {customerDetail.orders.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-gray-500">No purchase records.</p>
                      ) : (
                        <table className="w-full min-w-170">
                          <thead className="bg-gray-50 dark:bg-gray-800">
                            <tr>
                              <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Order #</th>
                              <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Date</th>
                              <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Amount</th>
                              <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                            {customerDetail.orders.map((o) => (
                              <tr key={o.id} className="bg-white dark:bg-transparent">
                                <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{o.order_number ?? `#${o.id}`}</td>
                                <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{o.created_at ? dateText(o.created_at) : "—"}</td>
                                <td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">{money(Number(o.total_amount ?? 0))}</td>
                                <td className="px-4 py-3 text-sm">
                                  <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                    String(o.status).toLowerCase().includes("complet") ? "bg-green-100 text-green-700" :
                                    String(o.status).toLowerCase().includes("cancel")  ? "bg-red-100 text-red-700" :
                                    "bg-yellow-100 text-yellow-700"
                                  }`}>{String(o.status).replace(/_/g, " ")}</span>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </div>
                  )}

                  {activeTab === "repair" && (
                    <div className="space-y-3">
                      {customerDetail.repairs.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-gray-500">No repair records.</p>
                      ) : customerDetail.repairs.map((r) => (
                        <div key={r.id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                              <p className="text-sm font-semibold text-gray-900 dark:text-white">{r.request_id ?? `#${r.id}`} • {r.shoe_type ?? "Repair"}</p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">{r.description || "—"}</p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">Submitted {r.created_at ? dateText(r.created_at) : "—"}</p>
                            </div>
                            <div className="flex items-center gap-3">
                              <span className="text-sm font-semibold text-gray-900 dark:text-white">{money(Number(r.total ?? 0))}</span>
                              <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                String(r.status).includes("complet") || String(r.status).includes("done")   ? "bg-green-100 text-green-700" :
                                String(r.status).includes("progress")                                        ? "bg-blue-100 text-blue-700" :
                                "bg-yellow-100 text-yellow-700"
                              }`}>{String(r.status).replace(/_/g, " ")}</span>
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}

                  {activeTab === "notes" && (
                    <div className="space-y-4">
                      <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <label className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Add staff note</label>
                        <textarea
                          value={noteDraft}
                          onChange={(e) => setNoteDraft(e.target.value)}
                          rows={3}
                          placeholder="Write a note about this customer..."
                          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        />
                        <div className="mt-3 flex justify-end">
                          <button
                            onClick={addStaffNote}
                            disabled={savingNote || !noteDraft.trim()}
                            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                          >
                            <WalletIcon className="size-4" />
                            {savingNote ? "Saving…" : "Save Note"}
                          </button>
                        </div>
                      </div>

                      <div className="space-y-3">
                        {(customerDetail.notes ?? []).map((note) => (
                          <div key={note.id} className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <div className="mb-1 flex items-center justify-between gap-2">
                              <p className="text-sm font-semibold text-gray-900 dark:text-white">{note.author}</p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">{dateText(note.date)}</p>
                            </div>
                            <p className="text-sm text-gray-600 dark:text-gray-300">{note.content}</p>
                          </div>
                        ))}
                        {(customerDetail.notes ?? []).length === 0 && <p className="text-sm text-gray-500 text-center py-4">No staff notes yet.</p>}
                      </div>
                    </div>
                  )}
                </div>

                <div className="mt-5 grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/40 md:grid-cols-3">
                  <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">Lifetime Spend</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{money(Number(customerDetail.stats?.total_spent ?? customerDetail.totalSpent ?? 0))}</p>
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">Last Activity</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{customerDetail.lastActivity ? dateText(customerDetail.lastActivity) : "—"}</p>
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">Service Requests</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{customerDetail.stats?.total_repairs ?? customerDetail.totalRepairs ?? 0}</p>
                  </div>
                </div>
                </>)} {/* end !loadingDetail && customerDetail */}
              </div>
            </div>
          </>
        )}
      </div>
    </AppLayoutERP>
  );
}
