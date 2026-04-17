import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { hasPermission } from "../../../utils/permissions";
import RepairPackageManager from "./components/RepairPackageManager";

type Service = {
  id: number;
  name: string;
  category: string;
  price: string;
  duration: string;
  description: string;
  status: "Active" | "Inactive" | "Pending";
  material_templates?: MaterialTemplateLine[];
};

type MaterialTemplateLine = {
  id?: number;
  inventory_item_id: number;
  inventory_item_name?: string | null;
  default_quantity: number;
};

type RepairMaterialOption = {
  id: number;
  name: string;
  available_quantity: number;
};

type MetricCardProps = {
  title: string;
  value: number | string;
  change?: number;
  changeType?: "increase" | "decrease";
  description?: string;
  color?: "success" | "error" | "warning" | "info";
  icon: React.FC<{ className?: string }>;
};

// Icons
const UploadIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
  </svg>
);

const PlusIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
  </svg>
);

const EditIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
  </svg>
);

const ArchiveBoxIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v1A2.5 2.5 0 0117.5 11h-11A2.5 2.5 0 014 8.5v-1z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 11v6.5A2.5 2.5 0 009.5 20h5a2.5 2.5 0 002.5-2.5V11" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 15h4" />
  </svg>
);

const ArchiveRestoreIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7.5A2.5 2.5 0 016.5 5h11A2.5 2.5 0 0120 7.5v1A2.5 2.5 0 0117.5 11h-11A2.5 2.5 0 014 8.5v-1z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 11v6.5A2.5 2.5 0 009.5 20h5a2.5 2.5 0 002.5-2.5V11" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 14l-2-2-2 2" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 12v4" />
  </svg>
);

const TagIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
  </svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ArrowUpIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

// Professional Metric Card Component
const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description,
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success": return "from-green-500 to-emerald-600";
      case "error": return "from-red-500 to-rose-600";
      case "warning": return "from-yellow-500 to-orange-600";
      case "info": return "from-blue-500 to-indigo-600";
      default: return "from-gray-500 to-gray-600";
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
          {change !== undefined && (
            <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}>
              {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
              {Math.abs(change)}%
            </div>
          )}
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {typeof value === 'number' ? value.toLocaleString() : value}
          </h3>
          {description && <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>}
        </div>
      </div>
    </div>
  );
};

export default function UploadService() {
  const { auth } = usePage().props as any;
  const userRole = auth?.user?.role;
  const [services, setServices] = useState<Service[]>([]);
  const [repairMaterials, setRepairMaterials] = useState<RepairMaterialOption[]>([]);
  const [loading, setLoading] = useState(true);

  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [selectedService, setSelectedService] = useState<Service | null>(null);
  const [searchTerm, setSearchTerm] = useState("");
  const [filterCategory, setFilterCategory] = useState<string>("all");
  const [filterStatus, setFilterStatus] = useState<string>("all");
  const [activeTab, setActiveTab] = useState<"services" | "packages">("services");
  const [showArchivedServices, setShowArchivedServices] = useState(false);

  // Form state
  const [formData, setFormData] = useState({
    name: "",
    category: "",
    categoryCustom: "",
    price: "",
    duration: "",
    durationFrom: "",
    durationTo: "",
    durationUnit: "hours" as "minutes" | "hours" | "days",
    description: "",
    status: "Active" as "Active" | "Inactive" | "Pending",
    material_templates: [] as Array<{
      inventory_item_id: number;
      default_quantity: string;
    }>,
  });

  // Fetch services from backend
  const fetchServices = async (archived = showArchivedServices) => {
    try {
      setLoading(true);
      const response = await axios.get('/api/repair-services', {
        params: { archived },
      });
      if (response.data.success) {
        // Format price with peso sign for display
        const formattedServices = response.data.data.map((service: any) => ({
          ...service,
          price: `₱${parseFloat(service.price).toFixed(0)}`,
        }));
        setServices(formattedServices);
      }
    } catch (error) {
      console.error('Error fetching services:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to load services',
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchRepairMaterials = async () => {
    try {
      const response = await axios.get('/api/repairer/materials', {
        params: { category: 'repair_materials' },
      });

      if (response.data?.success) {
        setRepairMaterials(response.data.data || []);
      } else {
        setRepairMaterials([]);
      }
    } catch {
      setRepairMaterials([]);
    }
  };

  // Load static material options once.
  useEffect(() => {
    fetchRepairMaterials();
  }, []);

  // Reload services whenever active/archived view is toggled.
  useEffect(() => {
    fetchServices(showArchivedServices);
  }, [showArchivedServices]);

  // Check authorization - Shop staff/managers/repairers or users with access-upload-service permission
  // Note: Super admin does NOT have access - this is shop-level operation only
  const hasRoleAccess = userRole === "STAFF" || userRole === "MANAGER" || userRole === "REPAIRER";
  const hasPermissionAccess = hasPermission(auth, 'access-upload-service');
  
  if (!hasRoleAccess && !hasPermissionAccess) {
    return (
      <AppLayoutERP>
        <div className="max-w-xl mx-auto mt-24 text-center p-8 bg-white dark:bg-gray-900 rounded-xl shadow">
          <div className="w-20 h-20 bg-red-100 dark:bg-red-900/20 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg className="w-10 h-10 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <h2 className="text-2xl font-bold text-gray-900 dark:text-white mb-3">Access Denied</h2>
          <p className="text-gray-600 dark:text-gray-400">You don't have permission to manage repair services.</p>
        </div>
      </AppLayoutERP>
    );
  }

  const getMaterialTemplateValidationError = (): string | null => {
    if (formData.material_templates.length === 0) {
      return 'Add at least one predefined material template before saving this service.';
    }

    for (const line of formData.material_templates) {
      const defaultQuantity = Number(line.default_quantity);

      if (!line.inventory_item_id) {
        return 'Each material template line must select an inventory material.';
      }

      if (!Number.isFinite(defaultQuantity) || defaultQuantity < 1 || !Number.isInteger(defaultQuantity)) {
        return 'Material default quantity must be a whole number (1, 2, 3...).';
      }
    }

    return null;
  };

  const normalizeWholeQuantity = (value: unknown): string => {
    const numericValue = Number(value);

    if (!Number.isFinite(numericValue) || numericValue <= 0) {
      return '1';
    }

    return String(Math.max(1, Math.ceil(numericValue)));
  };

  const parseDurationValue = (value: string): { durationFrom: string; durationTo: string; durationUnit: "minutes" | "hours" | "days" } => {
    const normalized = value.trim().toLowerCase().replace(/\s+/g, " ");
    const rangeMatch = normalized.match(/^(\d+(?:\.\d+)?)\s*(?:-|to)\s*(\d+(?:\.\d+)?)\s*(minutes?|hours?|days?)$/i);

    if (rangeMatch) {
      return {
        durationFrom: rangeMatch[1],
        durationTo: rangeMatch[2],
        durationUnit: (
          rangeMatch[3].startsWith("day")
            ? "days"
            : rangeMatch[3].startsWith("minute")
            ? "minutes"
            : "hours"
        ) as "minutes" | "hours" | "days",
      };
    }

    const singleMatch = normalized.match(/^(\d+(?:\.\d+)?)\s*(minutes?|hours?|days?)$/i);

    if (singleMatch) {
      return {
        durationFrom: singleMatch[1],
        durationTo: "",
        durationUnit: (
          singleMatch[2].startsWith("day")
            ? "days"
            : singleMatch[2].startsWith("minute")
            ? "minutes"
            : "hours"
        ) as "minutes" | "hours" | "days",
      };
    }

    return {
      durationFrom: "",
      durationTo: "",
      durationUnit: "hours" as const,
    };
  };

  const buildDurationValue = (durationFrom: string, durationTo: string, durationUnit: "minutes" | "hours" | "days") => {
    const fromValue = Number(durationFrom);

    if (!Number.isFinite(fromValue) || fromValue <= 0) {
      return "";
    }

    const formatUnit = (value: number) => (value === 1 ? durationUnit.slice(0, -1) : durationUnit);

    if (durationTo.trim()) {
      const toValue = Number(durationTo);

      if (!Number.isFinite(toValue) || toValue < fromValue) {
        return "";
      }

      return `${fromValue} to ${toValue} ${durationUnit}`;
    }

    return `${fromValue} ${formatUnit(fromValue)}`;
  };

  const serviceCategoryOptions = ["Care", "Repair", "Restoration"];

  const getEffectiveCategory = () => (
    formData.category === "Others"
      ? formData.categoryCustom.trim()
      : formData.category
  );

  const normalizePriceInput = (value: string): string => {
    const cleaned = value.replace(/[^\d.]/g, "");

    if (!cleaned) {
      return "";
    }

    const firstDotIndex = cleaned.indexOf(".");
    if (firstDotIndex === -1) {
      return cleaned;
    }

    const wholePart = cleaned.slice(0, firstDotIndex);
    const decimalPart = cleaned.slice(firstDotIndex + 1).replace(/\./g, "").slice(0, 2);

    return `${wholePart}.${decimalPart}`;
  };

  const isValidPriceInput = (value: string): boolean => {
    const trimmed = value.trim();
    if (!trimmed || trimmed === ".") {
      return false;
    }

    const numericValue = Number(trimmed);
    return Number.isFinite(numericValue) && numericValue >= 0;
  };

  const handlePriceInputChange = (value: string) => {
    setFormData((prev) => ({
      ...prev,
      price: normalizePriceInput(value),
    }));
  };

  const handleAddService = async () => {
    const effectiveCategory = getEffectiveCategory();

    if (!formData.name || !formData.category || !formData.price) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Please fill in all required fields",
      });
      return;
    }

    if (!isValidPriceInput(formData.price)) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Price must be a valid number.",
      });
      return;
    }

    if (formData.category === "Others" && !effectiveCategory) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Please enter a custom category name.",
      });
      return;
    }

    const durationValue = buildDurationValue(formData.durationFrom, formData.durationTo, formData.durationUnit);
    if (!durationValue) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Please enter a valid duration and choose minutes, hours, or days.",
      });
      return;
    }

    const materialValidationError = getMaterialTemplateValidationError();
    if (materialValidationError) {
      Swal.fire({
        icon: 'error',
        title: 'Validation Error',
        text: materialValidationError,
      });
      return;
    }

    try {
      // Remove peso sign and parse price
      const priceValue = formData.price.replace(/[₱,]/g, '');
      
      const response = await axios.post('/api/repair-services', {
        name: formData.name,
        category: effectiveCategory,
        price: priceValue,
        duration: durationValue,
        description: formData.description,
        status: 'Active', // New services are always Active, no approval needed
        material_templates: formData.material_templates.map((line) => ({
          inventory_item_id: Number(line.inventory_item_id),
          default_quantity: Number(line.default_quantity),
        })),
      });

      if (response.data.success) {
        setIsAddModalOpen(false);
        resetForm();
        await fetchServices(showArchivedServices); // Refresh the list

        Swal.fire({
          icon: "success",
          title: "Service Added",
          text: "The service has been successfully added!",
          timer: 2000,
          showConfirmButton: false,
        });
      }
    } catch (error: any) {
      console.error('Error adding service:', error);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: error.response?.data?.message || "Failed to add service",
      });
    }
  };

  const handleEditService = async () => {
    if (!selectedService) return;

    const effectiveCategory = getEffectiveCategory();

    if (!formData.name || !formData.category) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Please fill in all required fields",
      });
      return;
    }

    if (formData.category === "Others" && !effectiveCategory) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Please enter a custom category name.",
      });
      return;
    }

    const durationValue = buildDurationValue(formData.durationFrom, formData.durationTo, formData.durationUnit);
    if (!durationValue) {
      Swal.fire({
        icon: "error",
        title: "Validation Error",
        text: "Please enter a valid duration and choose minutes, hours, or days.",
      });
      return;
    }

    const materialValidationError = getMaterialTemplateValidationError();
    if (materialValidationError) {
      Swal.fire({
        icon: 'error',
        title: 'Validation Error',
        text: materialValidationError,
      });
      return;
    }

    try {
      const response = await axios.put(`/api/repair-services/${selectedService.id}`, {
        name: formData.name,
        category: effectiveCategory,
        // Price is not sent - can only be changed via pricing approval workflow
        duration: durationValue,
        description: formData.description,
        status: formData.status,
        material_templates: formData.material_templates.map((line) => ({
          inventory_item_id: Number(line.inventory_item_id),
          default_quantity: Number(line.default_quantity),
        })),
      });

      if (response.data.success) {
        setIsEditModalOpen(false);
        setSelectedService(null);
        resetForm();
        await fetchServices(showArchivedServices); // Refresh the list

        Swal.fire({
          icon: "success",
          title: "Service Updated",
          text: "The service has been successfully updated!",
          timer: 2000,
          showConfirmButton: false,
        });
      }
    } catch (error: any) {
      console.error('Error updating service:', error);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: error.response?.data?.message || "Failed to update service",
      });
    }
  };

  const handleArchiveService = async (id: number) => {
    const result = await Swal.fire({
      title: "Archive service?",
      text: "This service will be moved to archived list.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#7c3aed",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, archive it",
    });

    if (result.isConfirmed) {
      try {
        const response = await axios.delete(`/api/repair-services/${id}`);
        
        if (response.data.success) {
          await fetchServices(showArchivedServices); // Refresh the list
          Swal.fire({
            title: "Archived",
            text: "The service has been archived.",
            icon: "success",
            timer: 2000,
            showConfirmButton: false,
          });
        }
      } catch (error: any) {
        console.error('Error deleting service:', error);
        Swal.fire({
          icon: "error",
          title: "Error",
          text: error.response?.data?.message || "Failed to archive service",
        });
      }
    }
  };

  const handleRestoreService = async (id: number) => {
    const result = await Swal.fire({
      title: "Restore service?",
      text: "This service will return to active list.",
      icon: "question",
      showCancelButton: true,
      confirmButtonColor: "#2563eb",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, restore it",
    });

    if (result.isConfirmed) {
      try {
        const response = await axios.post(`/api/repair-services/${id}/restore`);

        if (response.data.success) {
          await fetchServices(showArchivedServices);
          Swal.fire({
            title: "Restored",
            text: "The service has been restored.",
            icon: "success",
            timer: 2000,
            showConfirmButton: false,
          });
        }
      } catch (error: any) {
        console.error('Error restoring service:', error);
        Swal.fire({
          icon: "error",
          title: "Error",
          text: error.response?.data?.message || "Failed to restore service",
        });
      }
    }
  };

  const openEditModal = (service: Service) => {
    const parsedDuration = parseDurationValue(service.duration);

    setSelectedService(service);
    setFormData({
      name: service.name,
      category: serviceCategoryOptions.includes(service.category) ? service.category : "Others",
      categoryCustom: serviceCategoryOptions.includes(service.category) ? "" : service.category,
      price: normalizePriceInput(String(service.price ?? "")),
      duration: service.duration,
      durationFrom: parsedDuration.durationFrom,
      durationTo: parsedDuration.durationTo,
      durationUnit: parsedDuration.durationUnit,
      description: service.description,
      status: service.status,
      material_templates: (service.material_templates || []).map((line) => ({
        inventory_item_id: Number(line.inventory_item_id),
        default_quantity: normalizeWholeQuantity(line.default_quantity),
      })),
    });
    setIsEditModalOpen(true);
  };

  const resetForm = () => {
    setFormData({
      name: "",
      category: "",
      categoryCustom: "",
      price: "",
      duration: "",
      durationFrom: "",
      durationTo: "",
      durationUnit: "hours",
      description: "",
      status: "Active",
      material_templates: [],
    });
  };

  const addMaterialTemplateLine = () => {
    setFormData((prev) => ({
      ...prev,
      material_templates: [
        ...prev.material_templates,
        {
          inventory_item_id: 0,
          default_quantity: '1',
        },
      ],
    }));
  };

  const updateMaterialTemplateLine = (
    index: number,
    field: 'inventory_item_id' | 'default_quantity',
    value: number | string | boolean,
  ) => {
    setFormData((prev) => ({
      ...prev,
      material_templates: prev.material_templates.map((line, lineIndex) => (
        lineIndex === index ? { ...line, [field]: value } : line
      )),
    }));
  };

  const removeMaterialTemplateLine = (index: number) => {
    setFormData((prev) => ({
      ...prev,
      material_templates: prev.material_templates.filter((_, lineIndex) => lineIndex !== index),
    }));
  };

  const normalizeDescription = (value: string) => {
    return value
      .replace(/\r\n/g, "\n")
      .replace(/\r/g, "\n")
      .replace(/\s*•\s*/g, "\n")
      .replace(/\s*-\s*/g, "\n")
      .split("\n")
      .map((line) => line.trim())
      .filter(Boolean)
      .join("\n");
  };

  const handleFormatDescription = () => {
    setFormData((prev) => ({
      ...prev,
      description: normalizeDescription(prev.description),
    }));
  };

  const getDescriptionPreviewLines = (value: string) => {
    const normalized = normalizeDescription(value);
    return normalized ? normalized.split("\n") : [];
  };

  const filteredServices = services.filter((service) => {
    const matchesSearch = service.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      service.category.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesCategory = filterCategory === "all" || service.category === filterCategory;
    const matchesStatus = filterStatus === "all" || service.status === filterStatus;
    return matchesSearch && matchesCategory && matchesStatus;
  });

  const categories = Array.from(new Set(services.map((s) => s.category)));

  return (
    <AppLayoutERP>
      <Head title="Upload Services" />

      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Upload Services</h1>
            <p className="mt-2 text-gray-600 dark:text-gray-400">
              {activeTab === "services"
                ? "Manage and upload repair services for your shop"
                : "Create and manage bundled repair packages for your shop"}
            </p>
          </div>
          {activeTab === "services" && (
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={() => setShowArchivedServices((prev) => !prev)}
                className={`inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors ${
                  showArchivedServices
                    ? "border-purple-300 bg-purple-50 text-purple-700 hover:bg-purple-100 dark:border-purple-700 dark:bg-purple-900/20 dark:text-purple-300"
                    : "border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                }`}
              >
                {showArchivedServices ? <ArchiveRestoreIcon className="w-5 h-5" /> : <ArchiveBoxIcon className="w-5 h-5" />}
                {showArchivedServices ? "Show Active" : "Show Archived"}
              </button>

              {!showArchivedServices && (
                <button
                  onClick={() => {
                    resetForm();
                    setIsAddModalOpen(true);
                  }}
                  className="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors shadow-sm"
                >
                  <PlusIcon className="w-5 h-5" />
                  Add Service
                </button>
              )}
            </div>
          )}
        </div>

        <div className="inline-flex w-full rounded-xl border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03] md:w-auto">
          <button
            type="button"
            onClick={() => setActiveTab("services")}
            className={`flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors md:flex-none ${
              activeTab === "services"
                ? "bg-blue-600 text-white shadow-sm"
                : "text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800"
            }`}
          >
            Services
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("packages")}
            className={`flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors md:flex-none ${
              activeTab === "packages"
                ? "bg-blue-600 text-white shadow-sm"
                : "text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800"
            }`}
          >
            Packages
          </button>
        </div>

        {activeTab === "services" ? (
          <>
            {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <MetricCard
            title="Total Services"
            value={services.length}
            change={8}
            changeType="increase"
            icon={TagIcon}
            color="info"
            description="All service offerings"
          />
          <MetricCard
            title="Active Services"
            value={services.filter((s) => s.status === "Active").length}
            change={12}
            changeType="increase"
            icon={CheckCircleIcon}
            color="success"
            description="Currently available"
          />
          <MetricCard
            title="Pending Review"
            value={services.filter((s) => s.status === "Pending").length}
            icon={ClockIcon}
            color="warning"
            description="Awaiting approval"
          />
        </div>

        {/* Filters */}
        <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-xl p-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Search
              </label>
              <input
                type="text"
                placeholder="Search services..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Category
              </label>
              <select
                title="Filter by category"
                value={filterCategory}
                onChange={(e) => setFilterCategory(e.target.value)}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              >
                <option value="all">All Categories</option>
                {categories.map((cat) => (
                  <option key={cat} value={cat}>
                    {cat}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Status
              </label>
              <select
                title="Filter by status"
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              >
                <option value="all">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
                <option value="Pending">Pending</option>
              </select>
            </div>
          </div>
        </div>

        {/* Services Table */}
        <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Service Name
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Category
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Price
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Duration
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-white/[0.02] divide-y divide-gray-200 dark:divide-gray-800">
                {loading ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                      Loading services...
                    </td>
                  </tr>
                ) : filteredServices.length > 0 ? (
                  filteredServices.map((service) => (
                    <tr key={service.id} className="hover:bg-gray-50 dark:hover:bg-white/[0.02] transition-colors">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900 dark:text-white">
                          {service.name}
                        </div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                          {showArchivedServices ? "Archived service" : "View description in Edit"}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/20 dark:text-blue-400">
                          {service.category}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900 dark:text-white">
                          {service.price}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                          {service.duration}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span
                          className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                            service.status === "Active"
                              ? "bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400"
                              : service.status === "Pending"
                              ? "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400"
                              : "bg-gray-100 text-gray-800 dark:bg-gray-900/20 dark:text-gray-400"
                          }`}
                        >
                          {service.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center gap-2">
                          {!showArchivedServices ? (
                            <>
                              <button
                                onClick={() => openEditModal(service)}
                                className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                                title="Edit"
                              >
                                <EditIcon className="w-5 h-5" />
                              </button>
                              <button
                                onClick={() => handleArchiveService(service.id)}
                                className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                                title="Archive"
                              >
                                <ArchiveBoxIcon className="w-5 h-5" />
                              </button>
                            </>
                          ) : (
                            <button
                              onClick={() => handleRestoreService(service.id)}
                              className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                              title="Restore"
                            >
                              <ArchiveRestoreIcon className="w-5 h-5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={6} className="px-6 py-12 text-center">
                      <div className="flex flex-col items-center justify-center">
                        <UploadIcon className="w-12 h-12 text-gray-400 dark:text-gray-600 mb-4" />
                        <p className="text-gray-500 dark:text-gray-400">
                          {showArchivedServices ? "No archived services found" : "No services found"}
                        </p>
                        <p className="text-sm text-gray-400 dark:text-gray-500 mt-1">
                          {showArchivedServices
                            ? "Try switching to active list or adjusting filters"
                            : "Try adjusting your filters or add a new service"}
                        </p>
                      </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

          </>
        ) : (
          <div className="space-y-4">
            <p className="text-xs text-gray-500 dark:text-gray-400">
              Predefined materials must be selected from existing repair-material inventory items only.
            </p>
            <RepairPackageManager materialsEndpoint="/api/repairer/materials" />
          </div>
        )}
      </div>

      {/* Add Service Modal */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-gray-200 dark:border-gray-800">
              <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Add New Service</h2>
            </div>

            <div className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  Service Name *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  placeholder="Enter service name"
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Category *
                  </label>
                  <select
                    title="Select service category"
                    value={formData.category}
                    onChange={(e) => setFormData({ ...formData, category: e.target.value, categoryCustom: e.target.value === "Others" ? formData.categoryCustom : "" })}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="">Select Category</option>
                    {serviceCategoryOptions.map((option) => (
                      <option key={option} value={option}>
                        {option}
                      </option>
                    ))}
                    <option value="Others">Others</option>
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Status *
                  </label>
                  <select
                    title="Select service status"
                    value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value as "Active" | "Inactive" | "Pending" })}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Pending">Pending</option>
                  </select>
                </div>
              </div>

              {formData.category === "Others" && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Custom Category *
                  </label>
                  <input
                    type="text"
                    value={formData.categoryCustom}
                    onChange={(e) => setFormData({ ...formData, categoryCustom: e.target.value })}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="Enter a custom category name"
                  />
                </div>
              )}

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Price *
                  </label>
                  <input
                    type="text"
                    inputMode="decimal"
                    value={formData.price}
                    onChange={(e) => handlePriceInputChange(e.target.value)}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="₱0.00"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Duration Estimate *
                  </label>
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                      <input
                        type="number"
                        min="1"
                        step="1"
                        value={formData.durationFrom}
                        onChange={(e) => setFormData({ ...formData, durationFrom: e.target.value })}
                        className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="2"
                      />
                    </div>
                    <div>
                      <input
                        type="number"
                        min={formData.durationFrom ? Number(formData.durationFrom) : 1}
                        step="1"
                        value={formData.durationTo}
                        onChange={(e) => setFormData({ ...formData, durationTo: e.target.value })}
                        className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="3"
                      />
                    </div>
                    <div>
                      <select
                        title="Select duration unit"
                        value={formData.durationUnit}
                        onChange={(e) => setFormData({ ...formData, durationUnit: e.target.value as "minutes" | "hours" | "days" })}
                        className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                      >
                        <option value="minutes">Minutes</option>
                        <option value="hours">Hours</option>
                        <option value="days">Days</option>
                      </select>
                    </div>
                  </div>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Leave the second box empty for an exact estimate. Fill both for a range like 30 to 45 minutes, 2 to 3 hours, or 1 to 2 days.
                  </p>
                  {buildDurationValue(formData.durationFrom, formData.durationTo, formData.durationUnit) && (
                    <p className="mt-2 text-xs font-medium text-blue-600 dark:text-blue-400">
                      Preview: {buildDurationValue(formData.durationFrom, formData.durationTo, formData.durationUnit)}
                    </p>
                  )}
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Description
                  </label>
                  <button
                    type="button"
                    onClick={handleFormatDescription}
                    className="text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                  >
                    Auto format lines
                  </button>
                </div>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  rows={4}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  placeholder={"Type one service detail per line\nExample:\nDeep cleaning\nStain removal\nDeodorizing"}
                />
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                  Tip: One detail per line. If pasted with dashes/bullets, click "Auto format lines".
                </p>
                {getDescriptionPreviewLines(formData.description).length > 0 && (
                  <div className="mt-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 p-3 max-h-32 overflow-y-auto">
                    <p className="text-xs font-medium text-gray-600 dark:text-gray-300 mb-2">Preview</p>
                    <ul className="list-disc pl-5 space-y-1">
                      {getDescriptionPreviewLines(formData.description).slice(0, 4).map((line, index) => (
                        <li key={`add-desc-${index}`} className="text-xs text-gray-700 dark:text-gray-200">{line}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>

              <div className="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Predefined Material Templates (Required)</h3>
                  <button
                    type="button"
                    onClick={addMaterialTemplateLine}
                    className="px-3 py-1.5 text-xs rounded-md bg-blue-600 text-white hover:bg-blue-700"
                  >
                    + Add Material
                  </button>
                </div>

                <p className="text-xs text-gray-500 dark:text-gray-400">
                  Attach at least one default material for this service using existing repair-material inventory items.
                </p>

                {formData.material_templates.length === 0 ? (
                  <div className="rounded-md border border-dashed border-gray-300 dark:border-gray-700 px-3 py-4 text-xs text-gray-500 dark:text-gray-400">
                    No template lines yet. Add at least one material line to continue.
                  </div>
                ) : (
                  <div className="space-y-3">
                    {formData.material_templates.map((line, index) => (
                      <div key={`add-service-material-${index}`} className="rounded-lg border border-gray-200 dark:border-gray-700 p-3 grid grid-cols-1 md:grid-cols-8 gap-2 items-end">
                        <div className="md:col-span-5">
                          <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-300 mb-1">Inventory Material</label>
                          <select
                            title="Select inventory material"
                            value={line.inventory_item_id || ""}
                            onChange={(e) => updateMaterialTemplateLine(index, 'inventory_item_id', Number(e.target.value || 0))}
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
                            onChange={(e) => updateMaterialTemplateLine(index, 'default_quantity', e.target.value)}
                            className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white"
                          />
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
                onClick={() => {
                  setIsAddModalOpen(false);
                  resetForm();
                }}
                className="px-6 py-2 border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddService}
                className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
              >
                Add Service
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Edit Service Modal */}
      {isEditModalOpen && selectedService && (
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-900 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-gray-200 dark:border-gray-800">
              <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Edit Service</h2>
            </div>

            <div className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  Service Name *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  placeholder="Enter service name"
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Category *
                  </label>
                  <select
                    title="Select service category"
                    value={formData.category}
                    onChange={(e) => setFormData({ ...formData, category: e.target.value })}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="">Select Category</option>
                    <option value="Care">Care</option>
                    <option value="Repair">Repair</option>
                    <option value="Restoration">Restoration</option>
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Status *
                  </label>
                  <select
                    title="Select service status"
                    value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value as "Active" | "Inactive" | "Pending" })}
                    className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Pending">Pending</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Current Price
                  </label>
                  <div className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                    {selectedService.price}
                  </div>
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Price can only be changed via Pricing & Services page
                  </p>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Duration Estimate *
                  </label>
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                      <input
                        type="number"
                        min="1"
                        step="1"
                        value={formData.durationFrom}
                        onChange={(e) => setFormData({ ...formData, durationFrom: e.target.value })}
                        className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="2"
                      />
                    </div>
                    <div>
                      <input
                        type="number"
                        min={formData.durationFrom ? Number(formData.durationFrom) : 1}
                        step="1"
                        value={formData.durationTo}
                        onChange={(e) => setFormData({ ...formData, durationTo: e.target.value })}
                        className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="3"
                      />
                    </div>
                    <div>
                      <select
                        title="Select duration unit"
                        value={formData.durationUnit}
                        onChange={(e) => setFormData({ ...formData, durationUnit: e.target.value as "minutes" | "hours" | "days" })}
                        className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                      >
                        <option value="minutes">Minutes</option>
                        <option value="hours">Hours</option>
                        <option value="days">Days</option>
                      </select>
                    </div>
                  </div>
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Leave the second box empty for an exact estimate. Fill both for a range like 30 to 45 minutes, 2 to 3 hours, or 1 to 2 days.
                  </p>
                  {buildDurationValue(formData.durationFrom, formData.durationTo, formData.durationUnit) && (
                    <p className="mt-2 text-xs font-medium text-blue-600 dark:text-blue-400">
                      Preview: {buildDurationValue(formData.durationFrom, formData.durationTo, formData.durationUnit)}
                    </p>
                  )}
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Description
                  </label>
                  <button
                    type="button"
                    onClick={handleFormatDescription}
                    className="text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                  >
                    Auto format lines
                  </button>
                </div>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  rows={4}
                  className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  placeholder={"Type one service detail per line\nExample:\nDeep cleaning\nStain removal\nDeodorizing"}
                />
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                  Tip: One detail per line. If pasted with dashes/bullets, click "Auto format lines".
                </p>
                {getDescriptionPreviewLines(formData.description).length > 0 && (
                  <div className="mt-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 p-3 max-h-32 overflow-y-auto">
                    <p className="text-xs font-medium text-gray-600 dark:text-gray-300 mb-2">Preview</p>
                    <ul className="list-disc pl-5 space-y-1">
                      {getDescriptionPreviewLines(formData.description).slice(0, 4).map((line, index) => (
                        <li key={`edit-desc-${index}`} className="text-xs text-gray-700 dark:text-gray-200">{line}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>

              <div className="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Predefined Material Templates (Required)</h3>
                  <button
                    type="button"
                    onClick={addMaterialTemplateLine}
                    className="px-3 py-1.5 text-xs rounded-md bg-blue-600 text-white hover:bg-blue-700"
                  >
                    + Add Material
                  </button>
                </div>

                <p className="text-xs text-gray-500 dark:text-gray-400">
                  Attach at least one default material for this service using existing repair-material inventory items.
                </p>

                {formData.material_templates.length === 0 ? (
                  <div className="rounded-md border border-dashed border-gray-300 dark:border-gray-700 px-3 py-4 text-xs text-gray-500 dark:text-gray-400">
                    No template lines yet. Add at least one material line to continue.
                  </div>
                ) : (
                  <div className="space-y-3">
                    {formData.material_templates.map((line, index) => (
                      <div key={`edit-service-material-${index}`} className="rounded-lg border border-gray-200 dark:border-gray-700 p-3 grid grid-cols-1 md:grid-cols-8 gap-2 items-end">
                        <div className="md:col-span-5">
                          <label className="block text-[11px] font-medium text-gray-600 dark:text-gray-300 mb-1">Inventory Material</label>
                          <select
                            title="Select inventory material"
                            value={line.inventory_item_id || ""}
                            onChange={(e) => updateMaterialTemplateLine(index, 'inventory_item_id', Number(e.target.value || 0))}
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
                            onChange={(e) => updateMaterialTemplateLine(index, 'default_quantity', e.target.value)}
                            className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white"
                          />
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
                onClick={() => {
                  setIsEditModalOpen(false);
                  setSelectedService(null);
                  resetForm();
                }}
                className="px-6 py-2 border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleEditService}
                className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
              >
                Update Service
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayoutERP>
  );
}
