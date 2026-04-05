import { useEffect, useMemo, useState } from "react";
import axios from "axios";
import Swal from "sweetalert2";

type RepairServiceOption = {
  id: number;
  name: string;
  category: string;
  price: string | number;
  duration: string;
  status: string;
};

type RepairPackage = {
  id: number;
  name: string;
  description?: string | null;
  package_price: number;
  status: "active" | "inactive";
  starts_at?: string | null;
  ends_at?: string | null;
  service_count: number;
  services_total_price: number;
  savings_amount: number;
  services: RepairServiceOption[];
  material_templates?: Array<{
    id?: number;
    inventory_item_id: number;
    inventory_item_name?: string | null;
    default_quantity: number;
    is_critical: boolean;
    tolerance_percent: number;
  }>;
};

type RepairMaterialOption = {
  id: number;
  name: string;
  available_quantity: number;
};

type PackageAnalytics = {
  overview: {
    total_packages: number;
    active_packages: number;
    inactive_packages: number;
    total_bookings: number;
    package_revenue: number;
    package_base_revenue: number;
    add_on_revenue: number;
    average_order_value: number;
    add_on_attach_rate: number;
    bookings_last_30_days: number;
    revenue_last_30_days: number;
  };
  top_packages: Array<{
    id: number;
    name: string;
    status: "active" | "inactive";
    booking_count: number;
    revenue: number;
    add_on_revenue: number;
    average_order_value: number;
    services_total_price: number;
    package_price: number;
    savings_amount: number;
    last_booked_at?: string | null;
  }>;
  status_breakdown: Array<{
    status: string;
    count: number;
  }>;
  monthly_trend: Array<{
    month: string;
    bookings: number;
    revenue: number;
  }>;
  recent_bookings: Array<{
    repair_request_id: number;
    order_number: string;
    package_id: number;
    package_name: string;
    booked_at?: string | null;
    final_total: number;
    add_ons_total: number;
    status: string;
  }>;
};

type PackageFormState = {
  name: string;
  description: string;
  package_price: string;
  status: "active" | "inactive";
  starts_at: string;
  ends_at: string;
  service_ids: number[];
  material_templates: Array<{
    inventory_item_id: number;
    default_quantity: string;
    is_critical: boolean;
    tolerance_percent: string;
  }>;
};

const defaultFormState: PackageFormState = {
  name: "",
  description: "",
  package_price: "",
  status: "active",
  starts_at: "",
  ends_at: "",
  service_ids: [],
  material_templates: [],
};

const formatMoney = (value: number | string) => {
  const amount = typeof value === "string" ? parseFloat(value || "0") : value;
  return `₱${Number.isFinite(amount) ? amount.toFixed(2) : "0.00"}`;
};

const formatDateTime = (value?: string | null) => {
  if (!value) return "—";

  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(new Date(value));
};

const EditIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
  </svg>
);

const TrashIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

type RepairPackageManagerProps = {
  serviceEndpoint?: string;
  materialsEndpoint?: string;
};

export default function RepairPackageManager({
  serviceEndpoint = "/api/repair-services",
  materialsEndpoint = "/api/repairer/materials",
}: RepairPackageManagerProps) {
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [packages, setPackages] = useState<RepairPackage[]>([]);
  const [services, setServices] = useState<RepairServiceOption[]>([]);
  const [repairMaterials, setRepairMaterials] = useState<RepairMaterialOption[]>([]);
  const [analytics, setAnalytics] = useState<PackageAnalytics | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | "active" | "inactive">("all");

  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [selectedPackage, setSelectedPackage] = useState<RepairPackage | null>(null);
  const [formState, setFormState] = useState<PackageFormState>(defaultFormState);

  const loadData = async () => {
    try {
      setLoading(true);
      const [packagesResponse, servicesResponse] = await Promise.all([
        axios.get("/api/repair-packages"),
        axios.get(serviceEndpoint),
      ]);

      if (packagesResponse.data?.success) {
        setPackages(packagesResponse.data.data || []);
      }

      if (servicesResponse.data?.success) {
        const activeServices = (servicesResponse.data.data || []).filter(
          (service: RepairServiceOption) => String(service.status || "").toLowerCase() === "active"
        );
        setServices(activeServices);
      }

      try {
        const materialsResponse = await axios.get(materialsEndpoint, {
          params: { category: "repair_materials" },
        });

        if (materialsResponse.data?.success) {
          setRepairMaterials(materialsResponse.data.data || []);
        } else {
          setRepairMaterials([]);
        }
      } catch {
        setRepairMaterials([]);
      }
    } catch (error) {
      console.error("Failed to load repair packages", error);
      Swal.fire({ icon: "error", title: "Error", text: "Failed to load repair package data." });
    } finally {
      setLoading(false);
    }

    // Analytics is non-critical — fetch separately so a permissions/auth error
    // never blocks the main package list from rendering.
    try {
      const analyticsResponse = await axios.get("/api/repair-packages/analytics");
      if (analyticsResponse.data?.success) {
        setAnalytics(analyticsResponse.data.data || null);
      }
    } catch {
      // Silently skip — user may not have analytics permission.
    }
  };

  useEffect(() => {
    loadData();
  }, [serviceEndpoint, materialsEndpoint]);

  const filteredPackages = useMemo(() => {
    return packages.filter((item) => {
      const searchMatch =
        item.name.toLowerCase().includes(search.toLowerCase()) ||
        (item.description || "").toLowerCase().includes(search.toLowerCase());
      const statusMatch = statusFilter === "all" || item.status === statusFilter;
      return searchMatch && statusMatch;
    });
  }, [packages, search, statusFilter]);

  const selectedServicesTotal = useMemo(() => {
    const picked = services.filter((service) => formState.service_ids.includes(service.id));
    return picked.reduce((sum, service) => sum + (typeof service.price === "string" ? parseFloat(service.price) : service.price), 0);
  }, [services, formState.service_ids]);

  const packageDraftPrice = useMemo(() => parseFloat(formState.package_price || "0"), [formState.package_price]);

  const draftSavings = useMemo(
    () => selectedServicesTotal - (Number.isFinite(packageDraftPrice) ? packageDraftPrice : 0),
    [selectedServicesTotal, packageDraftPrice]
  );

  const resetAndCloseModal = () => {
    setFormState(defaultFormState);
    setSelectedPackage(null);
    setIsAddModalOpen(false);
    setIsEditModalOpen(false);
  };

  const openAddModal = () => {
    setFormState(defaultFormState);
    setSelectedPackage(null);
    setIsAddModalOpen(true);
  };

  const normalizeWholeQuantity = (value: unknown): string => {
    const numericValue = Number(value);

    if (!Number.isFinite(numericValue) || numericValue <= 0) {
      return "1";
    }

    return String(Math.max(1, Math.ceil(numericValue)));
  };

  const openEditModal = (pkg: RepairPackage) => {
    setSelectedPackage(pkg);
    setFormState({
      name: pkg.name,
      description: pkg.description || "",
      package_price: String(pkg.package_price),
      status: pkg.status,
      starts_at: pkg.starts_at ? pkg.starts_at.slice(0, 16) : "",
      ends_at: pkg.ends_at ? pkg.ends_at.slice(0, 16) : "",
      service_ids: pkg.services.map((service) => service.id),
      material_templates: (pkg.material_templates || []).map((line) => ({
        inventory_item_id: line.inventory_item_id,
        default_quantity: normalizeWholeQuantity(line.default_quantity),
        is_critical: Boolean(line.is_critical),
        tolerance_percent: String(line.tolerance_percent ?? 20),
      })),
    });
    setIsEditModalOpen(true);
  };

  const toggleService = (serviceId: number) => {
    setFormState((prev) => {
      const nextServiceIds = prev.service_ids.includes(serviceId)
        ? prev.service_ids.filter((id) => id !== serviceId)
        : [...prev.service_ids, serviceId];

      return {
        ...prev,
        service_ids: nextServiceIds,
      };
    });
  };

  const addMaterialTemplateLine = () => {
    setFormState((prev) => ({
      ...prev,
      material_templates: [
        ...prev.material_templates,
        {
          inventory_item_id: 0,
          default_quantity: "1",
          is_critical: false,
          tolerance_percent: "20",
        },
      ],
    }));
  };

  const updateMaterialTemplateLine = (
    index: number,
    field: "inventory_item_id" | "default_quantity" | "is_critical" | "tolerance_percent",
    value: number | string | boolean
  ) => {
    setFormState((prev) => ({
      ...prev,
      material_templates: prev.material_templates.map((line, lineIndex) => {
        if (lineIndex !== index) return line;

        return {
          ...line,
          [field]: value,
        };
      }),
    }));
  };

  const removeMaterialTemplateLine = (index: number) => {
    setFormState((prev) => ({
      ...prev,
      material_templates: prev.material_templates.filter((_, lineIndex) => lineIndex !== index),
    }));
  };

  const validateBeforeSubmit = () => {
    if (!formState.name.trim()) {
      return "Package name is required.";
    }

    if (formState.service_ids.length < 2) {
      return "Select at least 2 services to form a package.";
    }

    if (formState.material_templates.length < 1) {
      return "At least one predefined material template is required for a package.";
    }

    for (const line of formState.material_templates) {
      const defaultQuantity = Number(line.default_quantity);
      const tolerancePercent = Number(line.tolerance_percent || "20");

      if (!line.inventory_item_id) {
        return "Each material template line must select an inventory material.";
      }

      if (!Number.isFinite(defaultQuantity) || defaultQuantity < 1 || !Number.isInteger(defaultQuantity)) {
        return "Material default quantity must be a whole number (1, 2, 3...).";
      }

      if (!Number.isFinite(tolerancePercent) || tolerancePercent < 0 || tolerancePercent > 100) {
        return "Material tolerance percent must be between 0 and 100.";
      }
    }

    if (formState.package_price.trim() === "") {
      return "Package price is required.";
    }

    const packagePrice = Number(formState.package_price);
    if (!Number.isFinite(packagePrice) || packagePrice < 0) {
      return "Package price must be a valid non-negative number.";
    }

    return null;
  };

  const submitCreate = async () => {
    const validationError = validateBeforeSubmit();
    if (validationError) {
      Swal.fire({ icon: "error", title: "Validation Error", text: validationError });
      return;
    }

    setSubmitting(true);
    try {
      const materialTemplatesPayload = formState.material_templates.map((line) => ({
        inventory_item_id: Number(line.inventory_item_id),
        default_quantity: Number(line.default_quantity),
        is_critical: Boolean(line.is_critical),
        tolerance_percent: Number(line.tolerance_percent || "20"),
      }));

      const payload = {
        ...formState,
        package_price: Number(formState.package_price),
        starts_at: formState.starts_at || null,
        ends_at: formState.ends_at || null,
        material_templates: materialTemplatesPayload,
      };

      const response = await axios.post("/api/repair-packages", payload);
      if (response.data?.success) {
        await loadData();
        resetAndCloseModal();
        Swal.fire({ icon: "success", title: "Created", text: "Repair package created successfully.", timer: 1800, showConfirmButton: false });
      }
    } catch (error: any) {
      console.error("Failed to create package", error);
      Swal.fire({ icon: "error", title: "Error", text: error?.response?.data?.message || "Failed to create package." });
    } finally {
      setSubmitting(false);
    }
  };

  const submitUpdate = async () => {
    if (!selectedPackage) return;

    const validationError = validateBeforeSubmit();
    if (validationError) {
      Swal.fire({ icon: "error", title: "Validation Error", text: validationError });
      return;
    }

    setSubmitting(true);
    try {
      const materialTemplatesPayload = formState.material_templates.map((line) => ({
        inventory_item_id: Number(line.inventory_item_id),
        default_quantity: Number(line.default_quantity),
        is_critical: Boolean(line.is_critical),
        tolerance_percent: Number(line.tolerance_percent || "20"),
      }));

      const payload = {
        ...formState,
        package_price: Number(formState.package_price),
        starts_at: formState.starts_at || null,
        ends_at: formState.ends_at || null,
        material_templates: materialTemplatesPayload,
      };

      const response = await axios.put(`/api/repair-packages/${selectedPackage.id}`, payload);
      if (response.data?.success) {
        await loadData();
        resetAndCloseModal();
        Swal.fire({ icon: "success", title: "Updated", text: "Repair package updated successfully. Price changes can be made in the Repair Pricing section.", timer: 1800, showConfirmButton: false });
      }
    } catch (error: any) {
      console.error("Failed to update package", error);
      Swal.fire({ icon: "error", title: "Error", text: error?.response?.data?.message || "Failed to update package." });
    } finally {
      setSubmitting(false);
    }
  };

  const deletePackage = async (id: number) => {
    const result = await Swal.fire({
      title: "Delete package?",
      text: "This will archive/remove the package from active management.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Delete",
      confirmButtonColor: "#d33",
    });

    if (!result.isConfirmed) return;

    try {
      const response = await axios.delete(`/api/repair-packages/${id}`);
      if (response.data?.success) {
        await loadData();
        Swal.fire({ icon: "success", title: "Deleted", timer: 1400, showConfirmButton: false });
      }
    } catch (error: any) {
      console.error("Failed to delete package", error);
      Swal.fire({ icon: "error", title: "Error", text: error?.response?.data?.message || "Failed to delete package." });
    }
  };

  const renderModal = (mode: "add" | "edit") => {
    const isOpen = mode === "add" ? isAddModalOpen : isEditModalOpen;
    if (!isOpen) return null;

    const title = mode === "add" ? "Create Repair Package" : "Edit Repair Package";
    const submitHandler = mode === "add" ? submitCreate : submitUpdate;

    return (
      <div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div className="bg-white dark:bg-gray-900 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
          <div className="p-6 border-b border-gray-200 dark:border-gray-800">
            <h2 className="text-2xl font-bold text-gray-900 dark:text-white">{title}</h2>
          </div>

          <div className="p-6 space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Package Name *</label>
                <input
                  type="text"
                  value={formState.name}
                  onChange={(e) => setFormState((prev) => ({ ...prev, name: e.target.value }))}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                  placeholder="e.g. Full Restore Bundle"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                <select
                  title="Package status"
                  value={formState.status}
                  onChange={(e) => setFormState((prev) => ({ ...prev, status: e.target.value as "active" | "inactive" }))}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
              <textarea
                rows={3}
                value={formState.description}
                onChange={(e) => setFormState((prev) => ({ ...prev, description: e.target.value }))}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                placeholder="Describe what this package includes"
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Package Price *</label>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={formState.package_price}
                  onChange={(e) => {
                    setFormState((prev) => ({ ...prev, package_price: e.target.value }));
                  }}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                  placeholder="e.g. 948.00"
                  disabled={mode === "edit"}
                />
                {mode === "add" ? (
                  <div className="mt-1 space-y-1">
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      Suggested from selected services (reference): <span className="font-semibold">{formatMoney(selectedServicesTotal)}</span>
                    </p>
                    <button
                      type="button"
                      onClick={() => {
                        setFormState((prev) => ({ ...prev, package_price: String(selectedServicesTotal.toFixed(2)) }));
                      }}
                      className="text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                    >
                      Apply suggested price
                    </button>
                  </div>
                ) : (
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Price updates after creation are handled in the Repair Pricing workflow.</p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Starts At (optional)</label>
                <input
                  type="datetime-local"
                  title="Package start date and time"
                  value={formState.starts_at}
                  onChange={(e) => setFormState((prev) => ({ ...prev, starts_at: e.target.value }))}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Ends At (optional)</label>
                <input
                  type="datetime-local"
                  title="Package end date and time"
                  value={formState.ends_at}
                  onChange={(e) => setFormState((prev) => ({ ...prev, ends_at: e.target.value }))}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                />
              </div>
            </div>

            <div className="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
              <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Included Services *</h3>
                <span className="text-xs text-gray-500 dark:text-gray-400">{formState.service_ids.length} selected</span>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                {services.map((service) => {
                  const checked = formState.service_ids.includes(service.id);
                  return (
                    <label key={service.id} className={`flex items-center justify-between gap-3 rounded border p-3 cursor-pointer ${checked ? "border-blue-500 bg-blue-50 dark:bg-blue-900/20" : "border-gray-200 dark:border-gray-700"}`}>
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900 dark:text-white truncate">{service.name}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{service.category} • {service.duration}</p>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="text-sm font-semibold text-gray-800 dark:text-gray-100">{formatMoney(service.price)}</span>
                        <input type="checkbox" checked={checked} onChange={() => toggleService(service.id)} className="h-4 w-4" />
                      </div>
                    </label>
                  );
                })}
              </div>
              <div className="mt-3 text-xs text-gray-600 dark:text-gray-300">
                Selected services total: <span className="font-semibold">{formatMoney(selectedServicesTotal)}</span>
              </div>
              <div className="mt-2 text-xs text-gray-600 dark:text-gray-300">
                Draft package savings: <span className={`font-semibold ${draftSavings >= 0 ? "text-green-600 dark:text-green-400" : "text-amber-600 dark:text-amber-400"}`}>{formatMoney(Math.abs(draftSavings))}</span>
                <span className="ml-1">{draftSavings >= 0 ? "below combined service price" : "above combined service price"}</span>
              </div>
            </div>

            <div className="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Predefined Material Templates *</h3>
                <button
                  type="button"
                  onClick={addMaterialTemplateLine}
                  className="px-3 py-1.5 text-xs rounded-md bg-blue-600 text-white hover:bg-blue-700"
                >
                  + Add Material
                </button>
              </div>

              <p className="text-xs text-gray-500 dark:text-gray-400">
                Choose from existing repair-material inventory items only. Free-text entries are not allowed.
              </p>

              {formState.material_templates.length === 0 ? (
                <div className="rounded-md border border-dashed border-gray-300 dark:border-gray-700 px-3 py-4 text-xs text-gray-500 dark:text-gray-400">
                  At least one material template line is required.
                </div>
              ) : (
                <div className="space-y-3">
                  {formState.material_templates.map((line, index) => (
                    <div key={`material-template-${index}`} className="rounded-lg border border-gray-200 dark:border-gray-700 p-3 grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                      <div className="md:col-span-5">
                        <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-300 mb-1">Inventory Material</label>
                        <select
                          title="Select inventory material"
                          value={line.inventory_item_id || ""}
                          onChange={(e) => updateMaterialTemplateLine(index, "inventory_item_id", Number(e.target.value || 0))}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white"
                        >
                          <option value="">Select material</option>
                          {repairMaterials.map((material) => (
                            <option key={material.id} value={material.id}>
                              {material.name} (Available: {material.available_quantity})
                            </option>
                          ))}
                        </select>
                      </div>

                      <div className="md:col-span-2">
                        <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-300 mb-1">Default Qty</label>
                        <input
                          type="number"
                          title="Default quantity"
                          placeholder="1"
                          min="1"
                          step="1"
                          value={line.default_quantity}
                          onChange={(e) => updateMaterialTemplateLine(index, "default_quantity", e.target.value)}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white"
                        />
                      </div>

                      <div className="md:col-span-2">
                        <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-300 mb-1">Tolerance %</label>
                        <input
                          type="number"
                          title="Tolerance percent"
                          placeholder="20"
                          min="0"
                          max="100"
                          step="0.01"
                          value={line.tolerance_percent}
                          onChange={(e) => updateMaterialTemplateLine(index, "tolerance_percent", e.target.value)}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white"
                        />
                      </div>

                      <div className="md:col-span-2">
                        <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-300 mb-1">Critical</label>
                        <label className="inline-flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                          <input
                            type="checkbox"
                            checked={line.is_critical}
                            onChange={(e) => updateMaterialTemplateLine(index, "is_critical", e.target.checked)}
                            className="h-4 w-4"
                          />
                          Yes
                        </label>
                      </div>

                      <div className="md:col-span-1 flex justify-end">
                        <button
                          type="button"
                          onClick={() => removeMaterialTemplateLine(index)}
                          className="px-2.5 py-2 text-xs rounded-md border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20"
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          <div className="p-6 border-t border-gray-200 dark:border-gray-800 flex justify-end gap-3">
            <button
              onClick={resetAndCloseModal}
              className="px-5 py-2 border border-gray-300 dark:border-gray-700 rounded-lg text-gray-700 dark:text-gray-300"
              disabled={submitting}
            >
              Cancel
            </button>
            <button
              onClick={submitHandler}
              className="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
              disabled={submitting}
            >
              {submitting ? "Saving..." : mode === "add" ? "Create Package" : "Save Changes"}
            </button>
          </div>
        </div>
      </div>
    );
  };

  return (
    <div className="bg-white dark:bg-white/3 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
      {analytics && (
        <div className="p-6 border-b border-gray-200 dark:border-gray-800 space-y-6 bg-gray-50/60 dark:bg-gray-900/20">
          <div>
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Package Analytics</h3>
            <p className="text-sm text-gray-600 dark:text-gray-400">Track bundle adoption, revenue, and add-on usage from package bookings.</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
            <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500">Packages</p>
              <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{analytics.overview.total_packages}</p>
              <p className="mt-1 text-xs text-gray-500">{analytics.overview.active_packages} active • {analytics.overview.inactive_packages} inactive</p>
            </div>
            <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500">Bookings</p>
              <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{analytics.overview.total_bookings}</p>
              <p className="mt-1 text-xs text-gray-500">{analytics.overview.bookings_last_30_days} in the last 30 days</p>
            </div>
            <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500">Revenue</p>
              <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{formatMoney(analytics.overview.package_revenue)}</p>
              <p className="mt-1 text-xs text-gray-500">{formatMoney(analytics.overview.revenue_last_30_days)} in the last 30 days</p>
            </div>
            <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500">Avg Order</p>
              <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{formatMoney(analytics.overview.average_order_value)}</p>
              <p className="mt-1 text-xs text-gray-500">Base {formatMoney(analytics.overview.package_base_revenue)} • Add-ons {formatMoney(analytics.overview.add_on_revenue)}</p>
            </div>
            <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
              <p className="text-xs uppercase tracking-wide text-gray-500">Add-on Attach Rate</p>
              <p className="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{analytics.overview.add_on_attach_rate}%</p>
              <p className="mt-1 text-xs text-gray-500">Orders with add-ons attached</p>
            </div>
          </div>

          <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div className="xl:col-span-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
              <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                <h4 className="text-sm font-semibold text-gray-900 dark:text-white">Top Package Performance</h4>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 dark:bg-gray-800/60 text-gray-500 uppercase text-xs">
                    <tr>
                      <th className="px-4 py-3 text-left">Package</th>
                      <th className="px-4 py-3 text-left">Bookings</th>
                      <th className="px-4 py-3 text-left">Revenue</th>
                      <th className="px-4 py-3 text-left">Avg Order</th>
                      <th className="px-4 py-3 text-left">Last Booked</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                    {analytics.top_packages.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="px-4 py-6 text-center text-gray-500">No package bookings yet.</td>
                      </tr>
                    ) : (
                      analytics.top_packages.slice(0, 5).map((item) => (
                        <tr key={item.id}>
                          <td className="px-4 py-3 align-top">
                            <p className="font-medium text-gray-900 dark:text-white">{item.name}</p>
                            <p className="text-xs text-gray-500">Savings {formatMoney(item.savings_amount)} • Add-ons {formatMoney(item.add_on_revenue)}</p>
                          </td>
                          <td className="px-4 py-3 text-gray-700 dark:text-gray-200">{item.booking_count}</td>
                          <td className="px-4 py-3 font-medium text-gray-900 dark:text-white">{formatMoney(item.revenue)}</td>
                          <td className="px-4 py-3 text-gray-700 dark:text-gray-200">{formatMoney(item.average_order_value)}</td>
                          <td className="px-4 py-3 text-gray-700 dark:text-gray-200">{formatDateTime(item.last_booked_at)}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="space-y-6">
              <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                  <h4 className="text-sm font-semibold text-gray-900 dark:text-white">Monthly Trend</h4>
                </div>
                <div className="p-4 space-y-3">
                  {analytics.monthly_trend.map((item) => (
                    <div key={item.month} className="flex items-center justify-between rounded-lg bg-gray-50 dark:bg-gray-800/60 px-3 py-2">
                      <div>
                        <p className="text-sm font-medium text-gray-900 dark:text-white">{item.month}</p>
                        <p className="text-xs text-gray-500">{item.bookings} booking{item.bookings !== 1 ? "s" : ""}</p>
                      </div>
                      <p className="text-sm font-semibold text-gray-900 dark:text-white">{formatMoney(item.revenue)}</p>
                    </div>
                  ))}
                </div>
              </div>

              <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                  <h4 className="text-sm font-semibold text-gray-900 dark:text-white">Recent Package Bookings</h4>
                </div>
                <div className="p-4 space-y-3">
                  {analytics.recent_bookings.length === 0 ? (
                    <p className="text-sm text-gray-500">No recent package bookings yet.</p>
                  ) : (
                    analytics.recent_bookings.map((booking) => (
                      <div key={booking.repair_request_id} className="rounded-lg bg-gray-50 dark:bg-gray-800/60 px-3 py-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-medium text-gray-900 dark:text-white">{booking.package_name}</p>
                            <p className="text-xs text-gray-500">{booking.order_number} • {formatDateTime(booking.booked_at)}</p>
                          </div>
                          <span className="text-xs font-medium uppercase tracking-wide text-gray-500">{booking.status.replace(/_/g, " ")}</span>
                        </div>
                        <div className="mt-2 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                          <span>Add-ons {formatMoney(booking.add_ons_total)}</span>
                          <span className="font-semibold text-gray-900 dark:text-white">{formatMoney(booking.final_total)}</span>
                        </div>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      <div className="p-6 border-b border-gray-200 dark:border-gray-800 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-gray-900 dark:text-white">Repair Packages</h2>
          <p className="text-sm text-gray-600 dark:text-gray-400">Create bundled service packages (repairer-managed).</p>
        </div>
        <button
          onClick={openAddModal}
          className="inline-flex items-center gap-2 px-4 py-2 bg-black text-white rounded-lg hover:bg-gray-800"
        >
          + Add Package
        </button>
      </div>

      <div className="p-6 border-b border-gray-200 dark:border-gray-800 grid grid-cols-1 md:grid-cols-2 gap-4">
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search package name or description"
          className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
        />
        <select
          title="Package status filter"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as "all" | "active" | "inactive")}
          className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
        >
          <option value="all">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Package</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Included Services</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Savings</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
            {!loading && filteredPackages.length === 0 && (
              <tr>
                <td colSpan={6} className="px-6 py-10 text-center text-gray-500 dark:text-gray-400">No repair packages found.</td>
              </tr>
            )}

            {filteredPackages.map((pkg) => (
              <tr key={pkg.id} className="hover:bg-gray-50 dark:hover:bg-white/2">
                <td className="px-6 py-4 align-top">
                  <p className="text-sm font-semibold text-gray-900 dark:text-white">{pkg.name}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">{pkg.description || "No description"}</p>
                </td>
                <td className="px-6 py-4 align-top text-sm text-gray-700 dark:text-gray-200">
                  {pkg.service_count} service{pkg.service_count !== 1 ? "s" : ""}
                </td>
                <td className="px-6 py-4 align-top text-sm font-semibold text-gray-900 dark:text-white">{formatMoney(pkg.package_price)}</td>
                <td className="px-6 py-4 align-top text-sm text-green-600 dark:text-green-400">{formatMoney(pkg.savings_amount)}</td>
                <td className="px-6 py-4 align-top">
                  <span className={`inline-flex px-2 py-1 rounded-full text-xs font-medium ${pkg.status === "active" ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-700"}`}>
                    {pkg.status}
                  </span>
                </td>
                <td className="px-6 py-4 align-top">
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => openEditModal(pkg)}
                      className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                      title="Edit package"
                      aria-label="Edit package"
                    >
                      <EditIcon className="w-5 h-5" />
                    </button>
                    <button
                      onClick={() => deletePackage(pkg.id)}
                      className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                      title="Delete package"
                      aria-label="Delete package"
                    >
                      <TrashIcon className="w-5 h-5" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {renderModal("add")}
      {renderModal("edit")}
    </div>
  );
}
