import { Head } from "@inertiajs/react";
import type { ComponentType } from "react";
import { useState } from "react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

const initialServicePrices = [
  { name: "Basic Cleaning", category: "Care", price: "₱180", duration: "20 min", status: "Active" },
  { name: "Deep Clean & Deodorize", category: "Care", price: "₱350", duration: "45 min", status: "Active" },
  { name: "Sole Whitening", category: "Restoration", price: "₱420", duration: "60 min", status: "Active" },
  { name: "Suede Treatment", category: "Restoration", price: "₱520", duration: "75 min", status: "Active" },
  { name: "Premium Leather Conditioning", category: "Care", price: "₱650", duration: "90 min", status: "Rejected", rejectionReason: "Service price exceeds approved budget limits. Please revise pricing to align with company standards." },
];

const repairServices = [
  { name: "Minor Stitch Repair", labor: "₱220", materials: "₱80", total: "₱300" },
  { name: "Full Sole Reglue", labor: "₱480", materials: "₱120", total: "₱600" },
  { name: "Heel Replacement", labor: "₱520", materials: "₱180", total: "₱700" },
];

const retailPricing = [
  { sku: "SKU-CLN-001", item: "Cleaning Foam", cost: "₱120", price: "₱220", margin: "45%" },
  { sku: "SKU-CLN-002", item: "Odor Eliminator Spray", cost: "₱150", price: "₱260", margin: "42%" },
  { sku: "SKU-ACC-014", item: "Premium Shoelaces", cost: "₱90", price: "₱180", margin: "50%" },
];

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

const GridIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z" />
  </svg>
);

const WrenchIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M21 6.5a4.5 4.5 0 01-6.36 4.09l-6.8 6.8a2 2 0 11-2.83-2.83l6.8-6.8A4.5 4.5 0 1116.5 3a4.49 4.49 0 014.5 3.5z" />
  </svg>
);

const TagIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="currentColor" viewBox="0 0 24 24">
    <path d="M20.59 13.41l-6-6A2 2 0 0013.17 7H7a2 2 0 00-2 2v6.17a2 2 0 00.59 1.41l6 6a2 2 0 002.83 0l6.17-6.17a2 2 0 000-2.83zM8.5 11A1.5 1.5 0 118.5 8a1.5 1.5 0 010 3z" />
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

const CloseIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

interface ServiceItem {
  name: string;
  category: string;
  price: string;
  duration: string;
  status: string;
  rejectionReason?: string;
}

export default function ERPPricingAndServices() {
  const [currentPage, setCurrentPage] = useState(1);
  const [viewModalOpen, setViewModalOpen] = useState(false);
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [selectedService, setSelectedService] = useState<ServiceItem | null>(null);
  const [editFormData, setEditFormData] = useState({ price: "", reason: "" });
  const [addFormData, setAddFormData] = useState({ name: "", category: "Care", price: "", duration: "" });
  const [servicePrices, setServicePrices] = useState(initialServicePrices);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("All");

  // Filter data based on search and status
  const filteredData = servicePrices.filter((item) => {
    const matchesSearch = item.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
                          item.category.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesStatus = statusFilter === "All" || item.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  const itemsPerPage = 7;
  const totalPages = Math.ceil(filteredData.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedServices = filteredData.slice(startIndex, startIndex + itemsPerPage);

  const handleViewClick = (item: ServiceItem) => {
    setSelectedService(item);
    setViewModalOpen(true);
  };

  const handleEditClick = (item: ServiceItem) => {
    setSelectedService(item);
    setEditFormData({ price: item.price, reason: "" });
    setEditModalOpen(true);
  };

  const handleSaveEdit = async () => {
    // Check if there are any changes
    if (editFormData.price === selectedService?.price) {
      Swal.fire({
        title: "No Changes",
        text: "Please make changes before saving.",
        icon: "info",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    // Check if reason is provided
    if (!editFormData.reason.trim()) {
      Swal.fire({
        title: "Reason Required",
        text: "Please provide a reason for changing the price.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    setEditModalOpen(false);

    const result = await Swal.fire({
      title: "Confirm Save",
      html: `<div style="text-align: left;"><strong>New Price:</strong> ${editFormData.price}<br/><strong>Reason:</strong> ${editFormData.reason}</div>`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, Save",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
      // Update the service price and status
      const updatedServices = servicePrices.map((service) =>
        service.name === selectedService?.name
          ? { ...service, price: editFormData.price, status: "Under Review" }
          : service
      );
      setServicePrices(updatedServices);

      Swal.fire({
        title: "Success!",
        text: "Service price updated successfully. Pleease wait for the Finance to approved it.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleAddService = async () => {
    // Validate inputs
    if (!addFormData.name.trim()) {
      Swal.fire({
        title: "Service Name Required",
        text: "Please enter a service name.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!addFormData.price || !/^\d+$/.test(addFormData.price)) {
      Swal.fire({
        title: "Valid Price Required",
        text: "Please enter a valid price (numbers only).",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!addFormData.duration.trim()) {
      Swal.fire({
        title: "Duration Required",
        text: "Please enter a duration.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    setAddModalOpen(false);

    const result = await Swal.fire({
      title: "Confirm Add Service",
      html: `<div style="text-align: left;"><strong>Service:</strong> ${addFormData.name}<br/><strong>Category:</strong> ${addFormData.category}<br/><strong>Price:</strong> ₱${addFormData.price}<br/><strong>Duration:</strong> ${addFormData.duration}</div>`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, Add Service",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
      const newService: ServiceItem = {
        name: addFormData.name,
        category: addFormData.category,
        price: `₱${addFormData.price}`,
        duration: addFormData.duration,
        status: "Active",
      };
      setServicePrices([...servicePrices, newService]);
      setAddFormData({ name: "", category: "Care", price: "", duration: "" });

      Swal.fire({
        title: "Success!",
        text: "Service added successfully.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    }
  };
  return (
    <AppLayoutERP>
      <Head title="Repair Pricing - Solespace" />
      <div className="p-6 space-y-6">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold mb-1">Repair Pricing</h1>
            <p className="text-gray-600 dark:text-gray-400">
              Manager controls for repair pricing and shoe pricing.
            </p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-3">
            <button onClick={() => setAddModalOpen(true)} className="px-4 py-2 text-sm font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700">
              Add Repair Service
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <MetricCard
            title="Active Services"
            value={12}
            change={6}
            changeType="increase"
            icon={GridIcon}
            color="info"
            description="3 categories"
          />
          <MetricCard
            title="Repair Options"
            value={8}
            change={3}
            changeType="increase"
            icon={WrenchIcon}
            color="warning"
            description="Labor + materials"
          />
          <MetricCard
            title="Retail SKUs"
            value={24}
            change={2}
            changeType="decrease"
            icon={TagIcon}
            color="success"
            description="Pricing updated weekly"
          />
        </div>

        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
          <div className="mb-4">
            <h2 className="text-lg font-semibold">Repair Services & Price</h2>
            <p className="text-sm text-gray-500">Manager can edit service prices only.</p>
          </div>
          
          {/* Search and Filter */}
          <div className="mb-4 flex flex-col sm:flex-row gap-3">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by service name or category..."
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
                <option value="Rejected">Rejected</option>
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="pb-2">Service</th>
                  <th className="pb-2">Category</th>
                  <th className="pb-2">Price</th>
                  <th className="pb-2">Duration</th>
                  <th className="pb-2">Status</th>
                  <th className="pb-2">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {paginatedServices.map((item) => (
                  <tr key={item.name}>
                    <td className="py-3 font-medium text-gray-900 dark:text-gray-100">{item.name}</td>
                    <td className="py-3 text-gray-500">{item.category}</td>
                    <td className="py-3 text-gray-900 dark:text-gray-100">{item.price}</td>
                    <td className="py-3 text-gray-500">{item.duration}</td>
                    <td className="py-3">
                      <span
                        className={`px-2 py-1 rounded-full text-xs font-semibold ${
                          item.status === "Active"
                            ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                            : item.status === "Under Review"
                            ? "bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200"
                            : item.status === "Rejected"
                            ? "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                            : "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                        }`}
                      >
                        {item.status}
                      </span>
                    </td>
                    <td className="py-3">
                      <div className="flex items-center gap-2">
                        <button 
                          onClick={() => handleViewClick(item)}
                          className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 transition-colors"
                        >
                          <EyeIcon className="w-5 h-5" />
                        </button>
                        <button 
                          onClick={() => handleEditClick(item)}
                          className="p-2 rounded-lg text-blue-600 hover:text-blue-700 dark:hover:text-blue-400 transition-colors"
                        >
                          <PencilIcon className="w-5 h-5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {servicePrices.length > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredData.length)}</span> of{" "}
                  <span className="font-medium">{filteredData.length}</span> results
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
                    if (
                      page === 1 ||
                      page === totalPages ||
                      (page >= currentPage - 1 && page <= currentPage + 1)
                    ) {
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

          {/* Add Service Modal */}
          {addModalOpen && (
            <div className="fixed inset-0 z-[999999] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl max-w-lg w-full border border-gray-200/80 dark:border-gray-700/60 overflow-hidden">
                <div className="h-1.5 w-full bg-gradient-to-r from-blue-600 via-indigo-500 to-emerald-500" />
                <div className="flex items-center justify-between p-6 bg-gradient-to-b from-gray-50 to-white dark:from-gray-900 dark:to-gray-900 border-b border-gray-200 dark:border-gray-700">
                  <div>
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Add Repair Service</h2>
                    <p className="text-xs text-gray-500 dark:text-gray-400">Create a new service entry for review.</p>
                  </div>
                  <button
                    onClick={() => setAddModalOpen(false)}
                    className="h-9 w-9 grid place-items-center rounded-full border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
                    aria-label="Close"
                  >
                    <CloseIcon className="w-4 h-4" />
                  </button>
                </div>
                <div className="p-6 space-y-4">
                  <div>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Service Name</p>
                    <input
                      type="text"
                      value={addFormData.name}
                      onChange={(e) => setAddFormData({ ...addFormData, name: e.target.value })}
                      placeholder="Enter service name..."
                      className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 dark:focus:border-blue-400 transition"
                    />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category</p>
                    <select
                      value={addFormData.category}
                      onChange={(e) => setAddFormData({ ...addFormData, category: e.target.value })}
                      className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 dark:focus:border-blue-400 transition"
                    >
                      <option value="Care">Care</option>
                      <option value="Restoration">Restoration</option>
                      <option value="Repair">Repair</option>
                    </select>
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Price</p>
                    <input
                      type="number"
                      inputMode="numeric"
                      step="1"
                      value={addFormData.price}
                      onChange={(e) => {
                        const value = e.target.value;
                        if (value === '' || /^\d+$/.test(value)) {
                          setAddFormData({ ...addFormData, price: value });
                        }
                      }}
                      placeholder="Enter price..."
                      className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 dark:focus:border-blue-400 transition"
                    />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Duration</p>
                    <input
                      type="text"
                      value={addFormData.duration}
                      onChange={(e) => setAddFormData({ ...addFormData, duration: e.target.value })}
                      placeholder="e.g., 30 min, 1 hour..."
                      className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 dark:focus:border-blue-400 transition"
                    />
                  </div>
                </div>
                <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                  <button
                    onClick={() => setAddModalOpen(false)}
                    className="flex-1 px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleAddService}
                    className="flex-1 px-4 py-2.5 rounded-xl bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition-colors"
                  >
                    Add Service
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* View Modal */}
        {viewModalOpen && selectedService && (
          <div className="fixed inset-0 z-[999999] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl max-w-lg w-full border border-gray-200/80 dark:border-gray-700/60 overflow-hidden">
              <div className="h-1.5 w-full bg-gradient-to-r from-emerald-500 via-blue-500 to-indigo-500" />
              <div className="flex items-center justify-between p-6 bg-gradient-to-b from-gray-50 to-white dark:from-gray-900 dark:to-gray-900 border-b border-gray-200 dark:border-gray-700">
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Service Details</h2>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Review pricing and approval status.</p>
                </div>
                <button
                  onClick={() => setViewModalOpen(false)}
                  className="h-9 w-9 grid place-items-center rounded-full border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
                  aria-label="Close"
                >
                  <CloseIcon className="w-4 h-4" />
                </button>
              </div>
              <div className="p-6 space-y-4">
                <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50 p-4">
                  <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Service Name</p>
                  <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{selectedService.name}</p>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50 p-4">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Category</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{selectedService.category}</p>
                  </div>
                  <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50 p-4">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Price</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{selectedService.price}</p>
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50 p-4">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Duration</p>
                    <p className="mt-1 text-base font-semibold text-gray-900 dark:text-white">{selectedService.duration}</p>
                  </div>
                  <div className="rounded-2xl border border-gray-200/70 dark:border-gray-700/60 bg-gray-50/80 dark:bg-gray-800/50 p-4">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</p>
                    <span
                      className={`mt-2 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold ${
                        selectedService.status === "Active"
                          ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200"
                          : selectedService.status === "Under Review"
                          ? "bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200"
                          : selectedService.status === "Rejected"
                          ? "bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200"
                          : "bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                      }`}
                    >
                      {selectedService.status}
                    </span>
                  </div>
                </div>
                {selectedService.status === "Rejected" && selectedService.rejectionReason && (
                  <div className="p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                    <p className="text-xs uppercase tracking-wide text-red-700 dark:text-red-200">Rejection Reason</p>
                    <p className="mt-1 text-sm text-red-700 dark:text-red-300">{selectedService.rejectionReason}</p>
                  </div>
                )}
              </div>
              <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                <button
                  onClick={() => setViewModalOpen(false)}
                  className="flex-1 px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Edit Modal */}
        {editModalOpen && selectedService && (
          <div className="fixed inset-0 z-[999999] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl max-w-lg w-full border border-gray-200/80 dark:border-gray-700/60 overflow-hidden">
              <div className="h-1.5 w-full bg-gradient-to-r from-blue-600 via-indigo-500 to-emerald-500" />
              <div className="flex items-center justify-between p-6 bg-gradient-to-b from-gray-50 to-white dark:from-gray-900 dark:to-gray-900 border-b border-gray-200 dark:border-gray-700">
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Edit Service</h2>
                  <p className="text-xs text-gray-500 dark:text-gray-400">Price updates require a reason.</p>
                </div>
                <button
                  onClick={() => setEditModalOpen(false)}
                  className="h-9 w-9 grid place-items-center rounded-full border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
                  aria-label="Close"
                >
                  <CloseIcon className="w-4 h-4" />
                </button>
              </div>
              <div className="p-6 space-y-4">
                <div>
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Service Name</p>
                  <input
                    type="text"
                    value={selectedService.name}
                    disabled
                    className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white disabled:opacity-60"
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category</p>
                    <input
                      type="text"
                      value={selectedService.category}
                      disabled
                      className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white disabled:opacity-60"
                    />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Price</p>
                    <input
                      type="number"
                      inputMode="numeric"
                      step="1"
                      value={editFormData.price}
                      onChange={(e) => {
                        const value = e.target.value;
                        if (value === '' || /^\d+$/.test(value)) {
                          setEditFormData({ ...editFormData, price: value });
                        }
                      }}
                      className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 dark:focus:border-blue-400 transition"
                    />
                  </div>
                </div>
                <div>
                  <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Change</p>
                  <textarea
                    value={editFormData.reason}
                    onChange={(e) => setEditFormData({ ...editFormData, reason: e.target.value })}
                    placeholder="Enter reason for price change..."
                    rows={3}
                    className="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950/60 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 dark:focus:border-blue-400 resize-none transition"
                  />
                </div>
              </div>
              <div className="flex gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
                <button
                  onClick={() => setEditModalOpen(false)}
                  className="flex-1 px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={handleSaveEdit}
                  className="flex-1 px-4 py-2.5 rounded-xl bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition-colors"
                >
                  Save
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutERP>
  );
}
