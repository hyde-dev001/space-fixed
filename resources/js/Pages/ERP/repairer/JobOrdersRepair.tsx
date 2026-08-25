import { useMemo, useState, useEffect, useRef } from "react";
import Swal from "sweetalert2";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import ErrorModal from "../../../components/common/ErrorModal";
import axios from "axios";
import { calculateRepairRevenue } from "../../../utils/deliveryRevenue";
import { buildRepairBreakdown, type RepairTaxMode } from "../../../utils/repairPricing";
import repairMaterialsApi, { type RepairMaterialUsage, type RepairMaterialInventoryItem, type RepairMaterialPlanItem } from "../../../services/repairMaterialsApi";

type IntakeHandoffEvent = {
  id?: number | string;
  label?: string;
  description?: string;
  message?: string;
  event_type?: string;
  status?: string;
  occurred_at?: string | null;
  created_at?: string | null;
};

type IntakeHandoff = {
  shipment_id?: number | null;
  shipment_status?: string | null;
  leg_id?: number | null;
  leg_status?: string | null;
  proof_status?: string | null;
  proof_review_state?: string | null;
  proof_correction_required?: boolean;
  can_confirm_receipt?: boolean;
  blocked_reason?: string | null;
  scheduled_delivery_date?: string | null;
  delivery_window?: string | null;
  events?: IntakeHandoffEvent[];
};

type ReturnHandoff = {
  visible?: boolean;
  method?: "walk_in" | "customer_pickup" | "shop_delivery";
  can_release?: boolean;
  can_confirm_receipt?: boolean;
  action_label?: string;
  blocked_reason?: string | null;
  shipment_status?: string | null;
  leg_status?: string | null;
  proof_status?: string | null;
  proof_review_state?: string | null;
  proof_correction_required?: boolean;
  external_tracking?: {
    carrier?: string | null;
    tracking_number?: string | null;
    tracking_url?: string | null;
  } | null;
  events?: IntakeHandoffEvent[];
  recovery?: {
    code: "returned_to_shop_awaiting_arrangement";
    label: string;
    state: "awaiting_arrangement" | "awaiting_payment" | "shop_pickup" | "ready_for_dispatch";
    can_schedule_redelivery: boolean;
    can_set_shop_pickup: boolean;
  } | null;
};

type RepairOrder = {
  id: string;
  database_id: number;
  customer: string;
  email: string;
  phone: string;
  item: string;
  service: string;
  total: string;
  status: "new_request" | "assigned_to_repairer" | "repairer_accepted" | "owner_approval_pending" | "owner_approved" | "waiting_customer_confirmation" | "confirmed" | "in-progress" | "awaiting_parts" | "completed" | "ready-for-pickup" | "shipped" | "picked_up" | "under-review" | "pending" | "received" | "repairer_rejected" | "manager_rejected" | "owner_rejected" | "rejected" | "cancelled";
  createdAt: string;
  startedAt?: string;
  completedAt?: string;
  notes?: string;
  imageUrl?: string;
  imageUrls?: string[];
  repairDetails?: string[];
  description?: string;
  shoeType?: string;
  brand?: string;
  serviceType?: "pickup" | "walkin";
  intakeDeliveryMethod?: "walk_in" | "customer_delivery" | "shop_pickup";
  intakeDeliveryFee?: number | string | null;
  intakeLogisticsLockedAt?: string | null;
  intakeHandoff?: IntakeHandoff | null;
  returnDeliveryMethod?: "walk_in" | "customer_pickup" | "shop_delivery";
  returnDeliveryFee?: number | string | null;
  returnLogisticsLockedAt?: string | null;
  returnHandoff?: ReturnHandoff | null;
  pickupAddressLine?: string;
  pickupBarangay?: string;
  pickupCity?: string;
  pickupRegion?: string;
  pickupPostalCode?: string;
  returnAddressLine?: string;
  returnBarangay?: string;
  returnCity?: string;
  returnRegion?: string;
  returnPostalCode?: string;
  selectedServices?: Array<{ name: string; price?: string } | string>;
  conversation_id?: number | null;
  payment_enabled?: boolean;
  payment_status?: string;
  paymongo_payment_id?: string | null;
  payment_policy?: 'deposit_50' | 'full_upfront';
  totalPaidAmount?: number | string | null;
  totalRefundedAmount?: number | string | null;
  pickup_enabled?: boolean;
  pickup_enabled_at?: string | null;
  preferredDate?: string | null;
  repairPackageId?: number | null;
  packageName?: string | null;
  packagePrice?: string | null;
  addOnsSubtotal?: string | null;
  finalPrice?: string | null;
  taxMode?: RepairTaxMode | null;
  vatRate?: number | null;
  vatAmount?: string | null;
  grandTotal?: string | null;
  pricingBreakdown?: {
    package_name?: string;
    package_price?: number | string;
    add_ons_total?: number | string;
    base_total?: number | string;
    final_total?: number | string;
  } | null;
  isWarrantyJob?: boolean;
  billingMode?: string | null;
  warrantyDisplayAlias?: string | null;
};

type RepairRefundQueueItem = {
  id: number;
  refund_no: string;
  module_reference_id: number;
  request_type: "full" | "partial";
  requested_amount: number | string;
  reason_code: string;
  reason_notes: string | null;
  requested_at: string | null;
  repairer_status: string;
  evidence_media: string[];
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

const useStaticData = false;
const REPAIR_VAT_RATE_PERCENT = 12;
const DELIVERY_METHOD_OVERRIDES_KEY = 'repair_delivery_method_overrides';
const REPAIR_REQUEST_LIMIT_KEY = 'repair_request_limit';
const DEFAULT_REPAIR_REQUEST_LIMIT = 20;

const readRepairRequestLimit = (): number => {
  if (typeof window === 'undefined') return DEFAULT_REPAIR_REQUEST_LIMIT;
  const raw = window.localStorage.getItem(REPAIR_REQUEST_LIMIT_KEY);
  const parsed = Number(raw);
  if (!Number.isFinite(parsed) || parsed < 1) return DEFAULT_REPAIR_REQUEST_LIMIT;
  return Math.floor(parsed);
};

type DeliveryMethodOverride = 'pickup' | 'walkin';

const readDeliveryMethodOverrides = (): Record<string, DeliveryMethodOverride> => {
  try {
    const raw = localStorage.getItem(DELIVERY_METHOD_OVERRIDES_KEY);
    if (!raw) return {};

    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return {};

    return Object.entries(parsed).reduce<Record<string, DeliveryMethodOverride>>((acc, [repairId, method]) => {
      if (method === 'pickup' || method === 'walkin') {
        acc[repairId] = method;
      }
      return acc;
    }, {});
  } catch {
    return {};
  }
};

const getMonthKey = (date: Date): string => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}`;
};

const staticOrders: RepairOrder[] = [
  {
    id: "RR-1000",
    database_id: 1000,
    customer: "Jade Navarro",
    email: "jade.navarro@example.com",
    phone: "0917-555-0100",
    item: "Canvas Sneakers",
    service: "Assessment",
    total: "₱0.00",
    status: "under-review",
    createdAt: "2026-02-09 08:40",
    notes: "Waiting for staff review and approval.",
    description: "Light sole separation and scuff marks on the toe area.",
    shoeType: "Sneakers",
    brand: "Converse",
    serviceType: "pickup",
    pickupAddressLine: "Blk 12 Lot 8 San Isidro St.",
    pickupBarangay: "Barangay 5",
    pickupCity: "Tagaytay City",
    pickupRegion: "Cavite",
    pickupPostalCode: "4120",
    selectedServices: [
      { name: "Sole re-glue", price: "₱350" },
      { name: "Deep clean", price: "₱450" },
    ],
    imageUrls: [
      "/images/product/product-01.jpg",
      "/images/product/product-02.jpg",
      "/images/product/product-03.jpg",
      "/images/product/product-04.jpg",
      "/images/product/product-05.jpg",
    ],
  },
  {
    id: "RR-1001",
    database_id: 1001,
    customer: "Ava Santos",
    email: "ava.santos@example.com",
    phone: "0917-555-0101",
    item: "Running Shoes",
    service: "Deep clean + sole fix",
    total: "₱850.00",
    status: "received",
    createdAt: "2026-02-10 09:15",
    notes: "Customer requested quick turnaround.",
  },
  {
    id: "RR-1002",
    database_id: 1002,
    customer: "Liam Cruz",
    email: "liam.cruz@example.com",
    phone: "0917-555-0102",
    item: "Leather Boots",
    service: "Conditioning + polish",
    total: "₱1,200.00",
    status: "received",
    createdAt: "2026-02-10 10:05",
  },
  {
    id: "RR-1003",
    database_id: 1003,
    customer: "Mia Velasquez",
    email: "mia.velasquez@example.com",
    phone: "0917-555-0103",
    item: "Suede Sneakers",
    service: "Suede clean",
    total: "₱650.00",
    status: "in-progress",
    createdAt: "2026-02-11 14:30",
    startedAt: "2026-02-12 09:00",
  },
  {
    id: "RR-1004",
    database_id: 1004,
    customer: "Noah Reyes",
    email: "noah.reyes@example.com",
    phone: "0917-555-0104",
    item: "Dress Shoes",
    service: "Heel repair",
    total: "₱900.00",
    status: "ready-for-pickup",
    createdAt: "2026-02-09 11:20",
    completedAt: "2026-02-12 16:45",
  },
  {
    id: "RR-1005",
    database_id: 1005,
    customer: "Emma Dela Cruz",
    email: "emma.delacruz@example.com",
    phone: "0917-555-0105",
    item: "High-Top Sneakers",
    service: "Stitch repair + clean",
    total: "₱1,050.00",
    status: "completed",
    createdAt: "2026-02-08 13:10",
    completedAt: "2026-02-11 15:30",
  },
  {
    id: "RR-1006",
    database_id: 1006,
    customer: "Miguel Torres",
    email: "miguel.torres@example.com",
    phone: "0917-555-0106",
    item: "Formal Shoes",
    service: "Full restoration",
    total: "₱0.00",
    status: "rejected",
    createdAt: "2026-02-07 09:45",
    notes: "Customer did not confirm appointment within 24 hours.",
  },
];

const normalizeRepairStatus = (status: string | null | undefined): RepairOrder["status"] => {
  const value = (status ?? "").toLowerCase();

  switch (value) {
    case "in_progress":
    case "in-progress":
      return "in-progress";
    case "ready_for_pickup":
    case "ready-for-pickup":
      return "ready-for-pickup";
    case "under_review":
    case "under-review":
      return "under-review";
    case "owner_rejected":
      return "owner_rejected";
    case "repairer_rejected":
    case "waiting for approval to reject":
    case "waiting_for_approval_to_reject":
      return "repairer_rejected";
    case "manager_rejected":
      return "manager_rejected";
    case "shipped":
      return "shipped";
    default:
      return value as RepairOrder["status"];
  }
};

const isRejectedWorkflowStatus = (status: string) => (
  status === "rejected"
  || status === "owner_rejected"
  || status === "repairer_rejected"
  || status === "manager_rejected"
);

const getRepairStatusLabel = (status: string) => {
  if (status === "new_request") return "New Request";
  if (status === "assigned_to_repairer") return "Assigned to Repairer";
  if (status === "under-review") return "Under Review";
  if (status === "pending" || status === "repairer_accepted" || status === "owner_approval_pending" || status === "owner_approved") return "Pending";
  if (status === "in-progress") return "In Progress";
  if (status === "completed") return "Work Done";
  if (status === "ready-for-pickup") return "Ready for Pickup";
  if (status === "shipped") return "Shipped";
  if (status === "picked_up" || status === "received") return "Received";
  if (status === "repairer_rejected") return "Pending Rejection";
  if (status === "manager_rejected" || status === "owner_rejected" || status === "rejected") return "Rejected";
  return status.charAt(0).toUpperCase() + status.slice(1);
};

const getPaymentStatusBadgeLabel = (paymentStatus?: string): string | null => {
  const normalized = String(paymentStatus ?? '').toLowerCase();
  if (normalized === 'refunded') return 'Refunded';
  if (normalized === 'partially_refunded') return 'Partially Refunded';
  if (normalized === 'partially_paid') return 'Deposit Paid';
  return null;
};

const toNumber = (value: unknown): number | null => {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === 'string') {
    const normalized = value.replace(/[^0-9.-]/g, '');
    if (!normalized) {
      return null;
    }
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
  }

  return null;
};

const formatPesoAmount = (value: unknown): string | null => {
  const parsed = toNumber(value);
  return parsed === null ? null : `₱${parsed.toFixed(2)}`;
};

const humanizeReasonCode = (value: string | null | undefined): string => {
  const normalized = String(value ?? "").trim();
  if (!normalized) {
    return "Unspecified";
  }

  return normalized
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(" ");
};

const extractRefundEvidenceMedia = (refund: any): string[] => {
  const normalizeUrl = (raw: string): string | null => {
    const value = String(raw ?? '').trim();
    if (!value) return null;

    if (value.startsWith('http://') || value.startsWith('https://') || value.startsWith('/')) {
      return value;
    }

    return `/storage/${value.replace(/^\/+/, '')}`;
  };

  const mediaCandidates: unknown[] = [];

  if (Array.isArray(refund?.media)) {
    mediaCandidates.push(...refund.media);
  }

  const snapshot = refund?.evidence_snapshot;
  if (Array.isArray(snapshot)) {
    mediaCandidates.push(...snapshot);
  } else if (snapshot && typeof snapshot === 'object') {
    if (Array.isArray(snapshot.media)) {
      mediaCandidates.push(...snapshot.media);
    }
    if (Array.isArray(snapshot.images)) {
      mediaCandidates.push(...snapshot.images);
    }
  }

  const mapped = mediaCandidates.map((item) => {
    if (typeof item === 'string') {
      return normalizeUrl(item);
    }

    if (!item || typeof item !== 'object') {
      return null;
    }

    return normalizeUrl(String((item as any).url ?? (item as any).path ?? (item as any).src ?? ''));
  });

  return Array.from(new Set(mapped.filter((url): url is string => Boolean(url))));
};

// Icons
const WrenchIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
  </svg>
);

const ClockIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const CheckCircleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const CurrencyDollarIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const MagnifyingGlassIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
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

const EyeIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const ChevronLeftIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
  </svg>
);

const ChevronRightIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
  </svg>
);

const PackageIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
  </svg>
);

const MotorcycleIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 18a2 2 0 100-4 2 2 0 000 4zm14 0a2 2 0 100-4 2 2 0 000 4zM7 16h6l3-5h3M11 16l-2-5h4" />
  </svg>
);

const RefundIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M16.023 9.348h4.992V4.356m0 0l-3.181 3.182a8.25 8.25 0 00-13.803 3.7M7.977 14.652H2.985v4.992m0 0l3.181-3.182a8.25 8.25 0 0013.803-3.7"
    />
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

export default function JobOrdersRepair() {
  const [error, setError] = useState<string | null>(null);
  const { auth } = usePage().props as any;
  const userRole = String(auth?.user?.role || '').toUpperCase();
  const userRoles = Array.isArray(auth?.user?.roles)
    ? auth.user.roles.map((role: string) => String(role).toUpperCase())
    : [];
  const userPermissions = Array.isArray(auth?.permissions)
    ? auth.permissions
    : [];
  const canAccessRepairModule =
    userPermissions.includes('access-repair-job-orders') ||
    userPermissions.includes('access-repairer-dashboard') ||
    userPermissions.includes('access-repairer-support') ||
    userRole === 'STAFF' ||
    userRole === 'MANAGER' ||
    userRole === 'REPAIRER' ||
    userRoles.includes('STAFF') ||
    userRoles.includes('MANAGER') ||
    userRoles.includes('REPAIRER');
  const [selectedTab, setSelectedTab] = useState<string>("under-review");
  const [searchTerm, setSearchTerm] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 10;
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [viewOrder, setViewOrder] = useState<RepairOrder | null>(null);
  const [enlargedImage, setEnlargedImage] = useState<string | null>(null);
  const [orders, setOrders] = useState<RepairOrder[]>(useStaticData ? staticOrders : []);
  const [repairerRefundQueue, setRepairerRefundQueue] = useState<RepairRefundQueueItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const isOrdersRequestInFlightRef = useRef(false);
  const [isShippingModalOpen, setIsShippingModalOpen] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<RepairOrder | null>(null);
  const [carrierCompany, setCarrierCompany] = useState("");
  const carrierCompanySelectRef = useRef<HTMLSelectElement | null>(null);
  const [carrierName, setCarrierName] = useState("");
  const [carrierPhone, setCarrierPhone] = useState("");
  const [trackingNumber, setTrackingNumber] = useState("");
  const [trackingLink, setTrackingLink] = useState("");
  const [etaPreset, setEtaPreset] = useState("1-2 business days");
  const [isRejectionModalOpen, setIsRejectionModalOpen] = useState(false);
  const [selectedRejectionReason, setSelectedRejectionReason] = useState("");
  const [rejectionReason, setRejectionReason] = useState("");
  const [isRefundReviewModalOpen, setIsRefundReviewModalOpen] = useState(false);
  const [refundReviewOrder, setRefundReviewOrder] = useState<RepairOrder | null>(null);
  const [refundReviewItem, setRefundReviewItem] = useState<RepairRefundQueueItem | null>(null);
  const [refundReviewMode, setRefundReviewMode] = useState<"approve" | "reject">("approve");
  const [refundApprovedAmountInput, setRefundApprovedAmountInput] = useState("");
  const [refundAssessmentNoteInput, setRefundAssessmentNoteInput] = useState("");
  const [refundRejectionReasonInput, setRefundRejectionReasonInput] = useState("");
  const [isRefundReviewSubmitting, setIsRefundReviewSubmitting] = useState(false);
  const [highlightRepairToken, setHighlightRepairToken] = useState<string | null>(null);
  const [deliveryMethodOverrides, setDeliveryMethodOverrides] = useState<Record<string, DeliveryMethodOverride>>({});
  const [materialUsages, setMaterialUsages] = useState<RepairMaterialUsage[]>([]);
  const [materialPlanItems, setMaterialPlanItems] = useState<RepairMaterialPlanItem[]>([]);
  const [availableMaterials, setAvailableMaterials] = useState<RepairMaterialInventoryItem[]>([]);
  const [isMaterialsLoading, setIsMaterialsLoading] = useState(false);
  const [isLoggingMaterialUsage, setIsLoggingMaterialUsage] = useState(false);
  const isLoggingMaterialUsageRef = useRef(false);
  const [materialForm, setMaterialForm] = useState({
    inventory_item_id: "",
    quantity_used: "",
    notes: "",
  });
  // Repair workload limit — server prop is source of truth; localStorage is a cross-tab cache
  const { repair_workload_limit: propLimit } = usePage().props as any;
  const initialLimit = typeof propLimit === 'number' && propLimit >= 1 ? propLimit : readRepairRequestLimit();
  const [repairRequestLimit, setRepairRequestLimit] = useState<number>(initialLimit);
  // Sync the DB value into localStorage once on mount so other pages/tabs read a fresh value
  useEffect(() => {
    window.localStorage.setItem(REPAIR_REQUEST_LIMIT_KEY, String(initialLimit));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Predefined rejection reasons
  const rejectionReasons = [
    "Item cannot be repaired",
    "Damage is not covered under warranty",
    "Repair cost exceeds item value",
    "Required parts are not available",
    "Service is beyond our capability",
    "Customer request is outside scope",
    "Other (please specify in notes)",
  ];

  if (!canAccessRepairModule) {
    return (
      <AppLayoutERP>
        <div className="max-w-xl mx-auto mt-24 text-center p-8 bg-white dark:bg-gray-900 rounded-xl shadow">
          <h2 className="text-2xl font-bold mb-2 text-red-600">Access Denied</h2>
          <p className="text-gray-700 dark:text-gray-300">You do not have permission to view the Repair Services module.</p>
        </div>
      </AppLayoutERP>
    );
  }

  // Parse highlight parameter from URL
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const highlightParam = params.get('highlightRepair') || params.get('highlight');

    if (!highlightParam) {
      return;
    }

    setHighlightRepairToken(String(highlightParam).trim());
  }, []);

  // Scroll to and highlight repair when ID changes
  useEffect(() => {
    if (!highlightRepairToken || orders.length === 0) {
      return;
    }

    const scrollTimer = window.setTimeout(() => {
      const targetElement =
        document.querySelector(`[data-repair-id="${highlightRepairToken}"]`) ||
        document.querySelector(`[data-repair-request-id="${highlightRepairToken}"]`);
      if (targetElement) {
        targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 200);

    return () => window.clearTimeout(scrollTimer);
  }, [highlightRepairToken, orders]);

  useEffect(() => {
    const refreshOverrides = () => {
      setDeliveryMethodOverrides(readDeliveryMethodOverrides());
      setRepairRequestLimit(readRepairRequestLimit());
    };

    refreshOverrides();
    window.addEventListener('storage', refreshOverrides);

    return () => {
      window.removeEventListener('storage', refreshOverrides);
    };
  }, []);

  useEffect(() => {
    setOrders((prev) =>
      prev.map((order) => {
        const override = deliveryMethodOverrides[String(order.database_id)];
        if (!override || order.serviceType === override) {
          return order;
        }

        return {
          ...order,
          serviceType: override,
          pickupAddressLine: override === 'pickup' ? order.pickupAddressLine : undefined,
          pickupBarangay: override === 'pickup' ? order.pickupBarangay : undefined,
          pickupCity: override === 'pickup' ? order.pickupCity : undefined,
          pickupRegion: override === 'pickup' ? order.pickupRegion : undefined,
          pickupPostalCode: override === 'pickup' ? order.pickupPostalCode : undefined,
        };
      })
    );
  }, [deliveryMethodOverrides]);

  // Fetch repair requests from backend
  useEffect(() => {
    void fetchOrders(true);
    void fetchRepairerRefundQueue();
  }, []);

  const fetchOrders = async (showLoading = false) => {
    if (useStaticData) {
      setOrders(staticOrders);
      if (showLoading) setIsLoading(false);
      return;
    }

    if (isOrdersRequestInFlightRef.current) return;
    isOrdersRequestInFlightRef.current = true;

    try {
      if (showLoading) setIsLoading(true);
      const response = await axios.get('/api/repairer/repairs');

      if (response.data.success) {
        // Map the API response to match the RepairOrder type
        const mappedOrders = response.data.data.map((repair: any) => {
          const mappedServices = (repair.services ?? [])
            .map((s: any) => s?.name)
            .filter((name: unknown) => typeof name === 'string' && name.trim() !== '')
            .join(', ');
          const posServiceSummary = String(
            repair.manual_service_summary
              ?? repair.pricing_breakdown?.service_summary
              ?? repair.description
              ?? ''
          ).trim();
          const packageServiceName = String(
            repair.pricing_breakdown?.package_name
              ?? repair.repair_package?.name
              ?? ''
          ).trim();
          const pricingMode = String(repair.pricing_breakdown?.mode ?? '').toLowerCase();
          const baseCandidates = [
            toNumber(repair.pricing_breakdown?.base_total),
            toNumber(repair.final_total),
            toNumber(repair.pricing_breakdown?.final_total),
            toNumber(repair.total),
          ].filter((value): value is number => value !== null && Number.isFinite(value));
          const fallbackBase =
            toNumber(repair.pricing_breakdown?.base_total ?? repair.final_total ?? repair.pricing_breakdown?.final_total ?? repair.total)
            ?? 0;
          const billableBaseAmount = pricingMode === 'manual_pos' && baseCandidates.length > 0
            ? Math.max(...baseCandidates)
            : fallbackBase;
          const rawVatRate = Number(repair.vat_rate);
          const vatRate = Number.isFinite(rawVatRate) && rawVatRate > 0 ? rawVatRate : REPAIR_VAT_RATE_PERCENT;
          const fallbackTaxMode = pricingMode === 'manual_pos' ? 'vat_inclusive' : 'legacy_additive';
          const rawTaxMode = String(repair.tax_mode ?? repair.pricing_breakdown?.tax_mode ?? fallbackTaxMode).toLowerCase();
          const taxMode: RepairTaxMode = rawTaxMode === 'vat_inclusive'
            ? 'vat_inclusive'
            : (rawTaxMode === 'legacy_add_on' ? 'legacy_add_on' : 'legacy_additive');
          const breakdown = buildRepairBreakdown({
            finalTotal: billableBaseAmount,
            vatRate,
            taxMode,
          });
          const primaryEmail = String(repair.email ?? '').trim();
          const accountEmail = String(repair.user?.email ?? '').trim();
          const hasPrimaryEmail = primaryEmail !== '' && !primaryEmail.toLowerCase().endsWith('@local.invalid');
          const normalizedEmail = hasPrimaryEmail
            ? primaryEmail
            : (accountEmail !== '' ? accountEmail : primaryEmail);
          const displayEmail = normalizedEmail === '' || normalizedEmail.toLowerCase().endsWith('@local.invalid')
            ? 'N/A'
            : normalizedEmail;

          return {
          id: repair.request_id || `REP-${repair.id}`,
          database_id: repair.id,
          customer: repair.customer_name || repair.user?.first_name + ' ' + repair.user?.last_name || 'N/A',
          email: displayEmail,
          phone: repair.phone || 'N/A',
          item: repair.shoe_type || 'N/A',
          service: mappedServices || posServiceSummary || packageServiceName || 'N/A',
          total: formatPesoAmount(billableBaseAmount) || '₱0.00',
          repairPackageId: repair.repair_package_id ?? null,
          packageName: repair.pricing_breakdown?.package_name || repair.repair_package?.name || null,
          packagePrice: formatPesoAmount(repair.package_price ?? repair.pricing_breakdown?.package_price),
          addOnsSubtotal: formatPesoAmount(repair.add_ons_total ?? repair.pricing_breakdown?.add_ons_total),
          finalPrice: formatPesoAmount(breakdown.netSubtotal),
          taxMode,
          vatRate: breakdown.vatRate,
          vatAmount: formatPesoAmount(breakdown.vatAmount),
          grandTotal: formatPesoAmount(breakdown.grandTotal),
          pricingBreakdown: repair.pricing_breakdown || null,
          isWarrantyJob: Boolean(repair.is_warranty_job),
          billingMode: repair.billing_mode ? String(repair.billing_mode) : null,
          warrantyDisplayAlias: repair.warranty_display_alias ? String(repair.warranty_display_alias) : null,
          status: normalizeRepairStatus(repair.status),
          createdAt: new Date(repair.created_at).toLocaleString('en-US', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
          }),
          startedAt: repair.started_at ? new Date(repair.started_at).toLocaleString() : undefined,
          completedAt: repair.completed_at ? new Date(repair.completed_at).toLocaleString() : undefined,
          preferredDate: repair.scheduled_dropoff_date
            ? new Date(repair.scheduled_dropoff_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
            : null,
          notes: repair.description || posServiceSummary || '',
          description: repair.description || posServiceSummary || undefined,
          shoeType: repair.shoe_type,
          brand: repair.brand,
          intakeDeliveryMethod: repair.intake_delivery_method || (repair.delivery_method === 'walk_in' ? 'walk_in' : 'customer_delivery'),
          intakeDeliveryFee: toNumber(repair.intake_delivery_fee),
          intakeLogisticsLockedAt: repair.intake_logistics_locked_at || null,
          intakeHandoff: repair.intake_handoff || null,
          returnDeliveryMethod: repair.return_delivery_method || (repair.delivery_method === 'walk_in' ? 'walk_in' : 'customer_pickup'),
          returnDeliveryFee: toNumber(repair.return_delivery_fee),
          returnLogisticsLockedAt: repair.return_logistics_locked_at || null,
          returnHandoff: repair.return_handoff || null,
          serviceType: deliveryMethodOverrides[String(repair.id)] || ((repair.intake_delivery_method || repair.delivery_method) === 'walk_in' ? 'walkin' : 'pickup'),
          pickupAddressLine: (repair.intake_address || repair.pickup_address)?.address_line || null,
          pickupBarangay: (repair.intake_address || repair.pickup_address)?.barangay || null,
          pickupCity: (repair.intake_address || repair.pickup_address)?.city || null,
          pickupRegion: (repair.intake_address || repair.pickup_address)?.region || null,
          pickupPostalCode: (repair.intake_address || repair.pickup_address)?.postal_code || null,
          returnAddressLine: repair.return_address?.address_line || null,
          returnBarangay: repair.return_address?.barangay || null,
          returnCity: repair.return_address?.city || null,
          returnRegion: repair.return_address?.region || null,
          returnPostalCode: repair.return_address?.postal_code || null,
          imageUrls: (() => {
            let images = repair.images;
            
            // If images is a string, try to parse it as JSON
            if (typeof images === 'string') {
              try {
                images = JSON.parse(images);
              } catch (e) {
                console.error('Failed to parse images JSON:', e);
                images = [];
              }
            }
            
            // Ensure images is an array
            if (!Array.isArray(images)) {
              images = images ? [images] : [];
            }
            
            // Map images to include /storage/ prefix
            return images.map((img: string) => {
              // If image is already a full URL, return as is
              if (img.startsWith('http://') || img.startsWith('https://') || img.startsWith('/storage/')) {
                return img;
              }
              // Otherwise, prepend the storage path
              return `/storage/${img}`;
            });
          })(),
          selectedServices: repair.services?.map((s: any) => ({ name: s.name, price: `₱${s.price}` })) || [],
          conversation_id: repair.conversation_id,
          payment_enabled: repair.payment_enabled || false,
          payment_status: repair.payment_status || 'pending',
          totalPaidAmount: toNumber(repair.total_paid_amount),
          totalRefundedAmount: toNumber(repair.total_refunded_amount),
          paymongo_payment_id: repair.paymongo_payment_id || null,
          payment_policy: repair.payment_policy || 'deposit_50',
          pickup_enabled: repair.pickup_enabled || false,
          pickup_enabled_at: repair.pickup_enabled_at || null
        };
      });
        setOrders(mappedOrders);
        setViewOrder((current) => current
          ? mappedOrders.find((order: RepairOrder) => order.database_id === current.database_id) ?? current
          : current);
      }
    } catch (error) {
      console.error('Failed to fetch repair requests:', error);
      if (showLoading) setError('Failed to load repair requests');
    } finally {
      isOrdersRequestInFlightRef.current = false;
      if (showLoading) setIsLoading(false);
    }
  };

  const fetchRepairerRefundQueue = async () => {
    if (useStaticData) {
      setRepairerRefundQueue([]);
      return;
    }

    try {
      const response = await axios.get('/api/repairer/refunds');
      if (!response.data?.success) {
        return;
      }

      const rawQueue = Array.isArray(response.data.data) ? response.data.data : [];
      const mappedQueue = rawQueue
        .map((refund: any): RepairRefundQueueItem | null => {
          const refundId = Number(refund?.id);
          const repairId = Number(refund?.module_reference_id);
          if (!Number.isFinite(refundId) || refundId <= 0 || !Number.isFinite(repairId) || repairId <= 0) {
            return null;
          }

          return {
            id: refundId,
            refund_no: String(refund?.refund_no ?? ''),
            module_reference_id: repairId,
            request_type: String(refund?.request_type ?? 'full') === 'partial' ? 'partial' : 'full',
            requested_amount: refund?.requested_amount ?? 0,
            reason_code: String(refund?.reason_code ?? ''),
            reason_notes: refund?.reason_notes ? String(refund.reason_notes) : null,
            requested_at: refund?.requested_at ? String(refund.requested_at) : null,
            repairer_status: String(refund?.repairer_status ?? ''),
            evidence_media: extractRefundEvidenceMedia(refund),
          };
        })
        .filter((refund: RepairRefundQueueItem | null): refund is RepairRefundQueueItem => {
          return refund !== null && refund.repairer_status === 'pending';
        });

      setRepairerRefundQueue(mappedQueue);
    } catch (queueError) {
      console.error('Failed to fetch repair refund queue:', queueError);
    }
  };

  useEffect(() => {
    if (useStaticData) return;

    const intervalId = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return;

      void fetchOrders();
      void fetchRepairerRefundQueue();
    }, 10000);

    return () => {
      window.clearInterval(intervalId);
    };
  }, []);

  useEffect(() => {
    if (!viewOrder) return;

    const latestOrder = orders.find((order) => order.database_id === viewOrder.database_id);
    if (latestOrder) {
      setViewOrder(latestOrder);
    }
  }, [orders]);

  useEffect(() => {
    if (!isViewModalOpen) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [isViewModalOpen]);

  const canTrackMaterials = (status: RepairOrder["status"] | undefined) => {
    return status === "in-progress" || status === "awaiting_parts";
  };

  const fetchRepairMaterials = async (repairId: number) => {
    try {
      setIsMaterialsLoading(true);
      const response = await repairMaterialsApi.getRepairUsage(repairId);
      if (response.success) {
        setMaterialUsages(response.data.usages ?? []);
        setMaterialPlanItems(response.data.plan_items ?? []);
        setAvailableMaterials(response.data.materials ?? []);
      }
    } catch (err) {
      console.error("Failed to load repair materials", err);
      setMaterialUsages([]);
      setMaterialPlanItems([]);
      setAvailableMaterials([]);
    } finally {
      setIsMaterialsLoading(false);
    }
  };

  useEffect(() => {
    if (isViewModalOpen && viewOrder?.database_id) {
      fetchRepairMaterials(viewOrder.database_id);
      return;
    }

    setMaterialUsages([]);
    setMaterialPlanItems([]);
    setAvailableMaterials([]);
    setMaterialForm({
      inventory_item_id: "",
      quantity_used: "",
      notes: "",
    });
  }, [isViewModalOpen, viewOrder?.database_id]);

  useEffect(() => {
    if (!isViewModalOpen || !viewOrder) return;
    if (materialForm.inventory_item_id) return;
    if (materialPlanItems.length === 0) return;

    const suggestedPlan = materialPlanItems.find((item) => item.remaining_quantity > 0) ?? materialPlanItems[0];
    const suggestedQuantity = Math.max(
      1,
      Math.ceil(suggestedPlan.remaining_quantity > 0 ? suggestedPlan.remaining_quantity : suggestedPlan.planned_quantity),
    );

    setMaterialForm((prev) => ({
      ...prev,
      inventory_item_id: String(suggestedPlan.inventory_item_id),
      quantity_used: prev.quantity_used || String(suggestedQuantity),
    }));
  }, [isViewModalOpen, materialForm.inventory_item_id, materialPlanItems, viewOrder]);

  const parseWholeQuantity = (rawValue: string, minValue: number): number | null => {
    const trimmed = String(rawValue ?? "").trim();
    if (!trimmed) {
      return null;
    }

    const parsed = Number(trimmed);
    if (!Number.isFinite(parsed) || parsed < minValue || !Number.isInteger(parsed)) {
      return null;
    }

    return parsed;
  };

  const handleLogMaterialUsage = async () => {
    if (!viewOrder) return;
    if (isLoggingMaterialUsageRef.current) return;

    const workflowStatus = String(viewOrder.status ?? "").toLowerCase();
    const canLogByStatus = ["in-progress", "in_progress", "awaiting_parts"].includes(workflowStatus);
    if (!canLogByStatus) {
      await Swal.fire({
        title: "Cannot log materials yet",
        text: "Move this repair to In Progress (or Awaiting Parts) first before logging material usage.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    const quantityUsed = parseWholeQuantity(materialForm.quantity_used, 0);
    if (!materialForm.inventory_item_id || quantityUsed === null) {
      await Swal.fire({
        title: "Missing details",
        text: "Please select a material and enter a whole-number quantity (0, 1, 2...).",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (quantityUsed === 0 && !String(materialForm.notes ?? "").trim()) {
      await Swal.fire({
        title: "Note required for zero quantity",
        text: "Add a note explaining why quantity is 0 (for example: used carry-over material from previous repair).",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    isLoggingMaterialUsageRef.current = true;
    setIsLoggingMaterialUsage(true);

    try {
      const response = await repairMaterialsApi.logRepairUsage(viewOrder.database_id, {
        inventory_item_id: Number(materialForm.inventory_item_id),
        quantity_used: quantityUsed,
        notes: materialForm.notes || undefined,
      });

      if (response.success) {
        const stockStatus = response.meta?.stock_status ?? "ok";
        const footerDetails: string[] = [];

        if (typeof response.meta?.remaining_quantity === "number") {
          footerDetails.push(`Remaining stock: ${response.meta.remaining_quantity}`);
        }

        if (response.meta?.auto_reorder?.triggered && response.meta.auto_reorder.request_number) {
          footerDetails.push(`Auto-reorder: ${response.meta.auto_reorder.request_number}`);
        } else if (response.meta?.auto_reorder?.existing_request_number) {
          footerDetails.push(`Pending request: ${response.meta.auto_reorder.existing_request_number}`);
        }

        setMaterialForm((prev) => ({ ...prev, quantity_used: "", notes: "" }));
        await fetchRepairMaterials(viewOrder.database_id);
        await Swal.fire({
          title: "Usage logged",
          text: response.message,
          icon: stockStatus === "ok" ? "success" : "warning",
          footer: footerDetails.length > 0 ? footerDetails.join(" • ") : undefined,
          confirmButtonColor: "#2563eb",
        });
      }
    } catch (error: any) {
      await Swal.fire({
        title: "Failed to log usage",
        text: error?.response?.data?.message || "Please try again.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    } finally {
      isLoggingMaterialUsageRef.current = false;
      setIsLoggingMaterialUsage(false);
    }
  };

  const handleRemoveMaterialUsage = async (usageId: number) => {
    if (!viewOrder) return;

    const result = await Swal.fire({
      title: "Remove material usage?",
      text: "This will restore the stock quantity.",
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Remove",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#dc2626",
    });

    if (!result.isConfirmed) return;

    try {
      const response = await repairMaterialsApi.removeRepairUsage(viewOrder.database_id, usageId);
      if (response.success) {
        await fetchRepairMaterials(viewOrder.database_id);
        await Swal.fire({
          title: "Removed",
          text: response.message,
          icon: "success",
          confirmButtonColor: "#2563eb",
        });
      }
    } catch (error: any) {
      await Swal.fire({
        title: "Failed to remove",
        text: error?.response?.data?.message || "Please try again.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleRequestMaterialFromOrder = async () => {
    if (!viewOrder) return;

    const quantityNeeded = parseWholeQuantity(materialForm.quantity_used, 1);
    if (!materialForm.inventory_item_id || quantityNeeded === null) {
      await Swal.fire({
        title: "Missing details",
        text: "Please select a material and enter a whole-number quantity (minimum 1) before requesting.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      const response = await repairMaterialsApi.createMaterialRequest({
        inventory_item_id: Number(materialForm.inventory_item_id),
        quantity_needed: quantityNeeded,
        priority: "medium",
        notes: materialForm.notes || `Requested from repair order ${viewOrder.id}`,
        repair_request_id: viewOrder.database_id,
      });

      if (response.success) {
        await Swal.fire({
          title: "Request submitted",
          text: response.message,
          icon: "success",
          confirmButtonColor: "#2563eb",
        });
      }
    } catch (error: any) {
      await Swal.fire({
        title: "Failed to request material",
        text: error?.response?.data?.message || "Please try again.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  // Filter orders based on tab and search
  const filteredOrders = useMemo(() => {
    return orders.filter((order) => {
      let matchesTab;
      if (selectedTab === "all") {
        matchesTab = true;
      } else if (selectedTab === "under-review") {
        // New Request tab includes new_request, assigned_to_repairer and under-review status
        matchesTab = order.status === "under-review" || order.status === "assigned_to_repairer" || order.status === "new_request";
      } else if (selectedTab === "pending") {
        // Pending tab includes pending, repairer_accepted, owner_approval_pending, and owner_approved
        matchesTab = order.status === "pending" || order.status === "repairer_accepted" || order.status === "owner_approval_pending" || order.status === "owner_approved";
      } else if (selectedTab === "completed") {
        // Completed tab only shows picked_up status (customer has picked up the item)
        matchesTab = order.status === "picked_up";
      } else if (selectedTab === "ready-for-pickup") {
        matchesTab = order.status === "ready-for-pickup" || order.status === "shipped";
      } else if (selectedTab === "warranty") {
        matchesTab = isWarrantyNoChargeOrder(order);
      } else if (selectedTab === "rejected") {
        matchesTab = isRejectedWorkflowStatus(order.status);
      } else {
        matchesTab = order.status === selectedTab;
      }
      const matchesSearch =
        String(order.id).toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.customer.toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.item.toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.service.toLowerCase().includes(searchTerm.toLowerCase());
      return matchesTab && matchesSearch;
    });
  }, [orders, selectedTab, searchTerm]);

  const pendingRefundByRepairId = useMemo(() => {
    const byRepair = new Map<number, RepairRefundQueueItem>();
    for (const refund of repairerRefundQueue) {
      if (!byRepair.has(refund.module_reference_id)) {
        byRepair.set(refund.module_reference_id, refund);
      }
    }
    return byRepair;
  }, [repairerRefundQueue]);

  // Pagination
  const totalPages = Math.ceil(filteredOrders.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedOrders = filteredOrders.slice(startIndex, endIndex);

  // Calculate statistics
  const stats = useMemo(() => {
    const total = orders.length;
    const underReview = orders.filter(o => o.status === "under-review" || o.status === "assigned_to_repairer" || o.status === "new_request").length;
    const pending = orders.filter(o => o.status === "pending" || o.status === "repairer_accepted" || o.status === "owner_approval_pending" || o.status === "owner_approved").length;
    const received = orders.filter(o => o.status === "received").length;
    const inProgress = orders.filter(o => o.status === "in-progress").length;
    const workCompleted = orders.filter(o => o.status === "completed").length;
    const readyForPickup = orders.filter(o => o.status === "ready-for-pickup" || o.status === "shipped").length;
    const pickedUp = orders.filter(o => o.status === "picked_up").length;
    const completedAll = orders.filter(o => o.status === "picked_up").length;
    const warranty = orders.filter(o => isWarrantyNoChargeOrder(o)).length;
    const rejected = orders.filter(o => isRejectedWorkflowStatus(o.status)).length;
    const cancelled = orders.filter(o => o.status === "cancelled").length;
    const totalRevenue = orders
      .filter(o => o.status !== "cancelled" && !isRejectedWorkflowStatus(o.status) && !isWarrantyNoChargeOrder(o))
      .reduce((sum, o) => {
        return sum + calculateRepairRevenue({
          serviceGrossAmount: toNumber(o.grandTotal) ?? toNumber(o.total) ?? 0,
          serviceNetAmount: toNumber(o.finalPrice ?? o.total) ?? 0,
          totalPaidAmount: toNumber(o.totalPaidAmount) ?? 0,
          refundedAmount: toNumber(o.totalRefundedAmount) ?? 0,
          paymentStatus: o.payment_status,
          paymentPolicy: o.payment_policy,
          intakeDeliveryMethod: o.intakeDeliveryMethod,
          intakeDeliveryFee: toNumber(o.intakeDeliveryFee) ?? 0,
          intakeLogisticsLockedAt: o.intakeLogisticsLockedAt,
          returnDeliveryMethod: o.returnDeliveryMethod,
          returnDeliveryFee: toNumber(o.returnDeliveryFee) ?? 0,
          returnLogisticsLockedAt: o.returnLogisticsLockedAt,
        });
      }, 0);
    return { total, underReview, pending, received, inProgress, workCompleted, readyForPickup, pickedUp, completedAll, warranty, rejected, cancelled, totalRevenue };
  }, [orders]);

  const activeRepairCount = useMemo(() => {
    const activeStatuses: RepairOrder['status'][] = [
      'assigned_to_repairer',
      'repairer_accepted',
      'owner_approved',
      'waiting_customer_confirmation',
      'pending',
      'received',
      'in-progress',
      'awaiting_parts',
      'completed',
      'ready-for-pickup',
      'shipped',
    ];

    return orders.filter((order) => activeStatuses.includes(order.status)).length;
  }, [orders]);

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      "new_request": "bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300",
      "assigned_to_repairer": "bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300",
      "under-review": "bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400",
      "pending": "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
      "received": "bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400",
      "in-progress": "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
      "completed": "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
      "picked_up": "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
      "ready-for-pickup": "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400",
      "shipped": "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400",
      "repairer_rejected": "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
      "manager_rejected": "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      "owner_rejected": "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      "rejected": "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      "cancelled": "bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400",
    };
    return colors[status] || "bg-gray-100 text-gray-800";
  };

  const getOrderGrandTotalValue = (order: Pick<RepairOrder, 'grandTotal' | 'total'>) => {
    return toNumber(order.grandTotal) ?? toNumber(order.total) ?? 0;
  };

  function isWarrantyNoChargeOrder(order: Pick<RepairOrder, 'isWarrantyJob' | 'billingMode'>) {
    return Boolean(order.isWarrantyJob) || String(order.billingMode ?? '').toLowerCase() === 'warranty_no_charge';
  }

  const getDisplayedPaidAmount = (order: Pick<RepairOrder, 'totalPaidAmount' | 'payment_status' | 'payment_policy' | 'grandTotal' | 'total'>) => {
    const recordedPaid = toNumber(order.totalPaidAmount);
    const resolvedRecordedPaid = recordedPaid !== null && recordedPaid > 0 ? recordedPaid : 0;

    const status = (order.payment_status ?? '').toLowerCase();
    const policy = order.payment_policy ?? 'deposit_50';
    const grandTotal = getOrderGrandTotalValue(order);

    let inferredPaid = 0;

    if (status === 'completed') {
      inferredPaid = grandTotal;
      return Math.max(resolvedRecordedPaid, inferredPaid);
    }

    if (status === 'paid' || status === 'partially_paid') {
      if (policy === 'full_upfront') {
        inferredPaid = grandTotal;
        return Math.max(resolvedRecordedPaid, inferredPaid);
      }

      inferredPaid = Math.round(grandTotal * 0.5 * 100) / 100;
      return Math.max(resolvedRecordedPaid, inferredPaid);
    }

    return resolvedRecordedPaid;
  };

  const isFullyPaidForRelease = (order: Pick<RepairOrder, 'payment_policy' | 'payment_status'>) => {
    const policy = order.payment_policy ?? 'deposit_50';
    const normalizedStatus = (order.payment_status || '').toLowerCase();

    return normalizedStatus === 'completed' || (policy !== 'deposit_50' && normalizedStatus === 'paid');
  };

  const getReleasePaymentBlockedMessage = (order: Pick<RepairOrder, 'payment_policy'>) => {
    switch (order.payment_policy ?? 'deposit_50') {
      case 'deposit_50':
        return 'Waiting for customer to pay the remaining 50% balance';
      case 'full_upfront':
        return 'Waiting for customer payment to be completed';
      default:
        return 'Waiting for customer payment to be completed';
    }
  };

  const canMarkReceived = (order: Pick<RepairOrder, 'intakeHandoff'>) => {
    return Boolean(order.intakeHandoff?.can_confirm_receipt);
  };

  const getMarkReceivedBlockedMessage = (order: Pick<RepairOrder, 'intakeHandoff'>) => {
    return order.intakeHandoff?.blocked_reason || 'Physical receipt is not available yet.';
  };

  const isWalkInReturn = (order: Pick<RepairOrder, 'returnDeliveryMethod' | 'serviceType'>) => {
    if (order.returnDeliveryMethod === 'walk_in') return true;
    if (order.returnDeliveryMethod === 'customer_pickup' || order.returnDeliveryMethod === 'shop_delivery') return false;
    return order.serviceType === 'walkin';
  };

  const isWalkInIntake = (order: Pick<RepairOrder, 'intakeDeliveryMethod' | 'serviceType'>) => {
    return order.intakeDeliveryMethod
      ? order.intakeDeliveryMethod === 'walk_in'
      : order.serviceType === 'walkin';
  };

  const isPosManualWalkIn = (order: Pick<RepairOrder, 'id' | 'intakeDeliveryMethod' | 'serviceType'>) => {
    return isWalkInIntake(order) && String(order.id || '').toUpperCase().startsWith('REP-POS-');
  };

  const canActivateOnlineRemainingBalance = (order: Pick<RepairOrder, 'status' | 'payment_policy' | 'payment_status' | 'returnDeliveryMethod' | 'serviceType' | 'payment_enabled'>) => {
    const paymentPolicy = order.payment_policy ?? 'deposit_50';
    const paymentStatus = (order.payment_status ?? '').toLowerCase();
    const orderStatus = (order.status ?? '').toLowerCase();

    return !isWalkInReturn(order)
      && paymentPolicy === 'deposit_50'
      && (paymentStatus === 'paid' || paymentStatus === 'partially_paid')
      && (orderStatus === 'ready-for-pickup' || orderStatus === 'ready_for_pickup')
      && !Boolean(order.payment_enabled);
  };

  const isInShopPaymentRecorded = (order: Pick<RepairOrder, 'payment_status' | 'paymongo_payment_id'>) => {
    const status = (order.payment_status ?? '').toLowerCase();
    if (!['paid', 'partially_paid', 'completed'].includes(status)) return false;

    const paymentId = (order.paymongo_payment_id ?? '').toLowerCase();
    return paymentId.startsWith('in_shop');
  };

  const handleMarkReceived = async (order: RepairOrder) => {
    const handoffCopy = order.intakeDeliveryMethod === 'walk_in'
      ? 'Confirm that the customer has dropped off the shoes at your shop.'
      : order.intakeDeliveryMethod === 'shop_pickup'
        ? 'Confirm that the assigned shop rider has handed the shoes over at your shop.'
        : 'Confirm that the customer-arranged courier has handed the shoes over at your shop.';
    const result = await Swal.fire({
      title: "Confirm physical receipt?",
      html: `
        <p class="text-gray-700 mb-2">${handoffCopy}</p>
        <p class="font-semibold text-gray-900">${order.service} for ${order.customer}</p>
      `,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Confirm physical receipt",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#2563eb",
    });

    if (!result.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repairer/repairs/${order.database_id}/mark-received`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (data.success) {
        await fetchOrders();
        setSelectedTab("received");
        setCurrentPage(1);

        await Swal.fire({
          title: "Marked as Received!",
          text: `${order.id} has been marked as received. You can now begin the repair.`,
          icon: "success",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        });
      } else {
        throw new Error(data.message || 'Failed to update status');
      }
    } catch (error: any) {
      console.error('Failed to mark as received:', error);
      await Swal.fire({
        title: "Failed to Mark Received",
        text: error.message || "Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleStartWork = async (order: RepairOrder) => {
    const result = await Swal.fire({
      title: "Start working on this repair?",
      text: `${order.service} for ${order.customer}`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, start work",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#2563eb",
    });

    if (!result.isConfirmed) return;

    try {
      const readiness = await repairMaterialsApi.validateStartReadiness(order.database_id);

      if (!readiness.success || readiness.data.readiness_state === "blocked") {
        await Swal.fire({
          title: "Cannot start work",
          text: "Required materials are unavailable. Please request materials first.",
          icon: "warning",
          confirmButtonColor: "#2563eb",
        });
        return;
      }
    } catch (readinessError: any) {
      if (readinessError?.response?.data?.data?.readiness_state === "blocked") {
        await Swal.fire({
          title: "Cannot start work",
          text: "Required materials are unavailable. Please request materials first.",
          icon: "warning",
          confirmButtonColor: "#2563eb",
        });
        return;
      }

      await Swal.fire({
        title: "Readiness check failed",
        text: readinessError?.response?.data?.message || "Unable to validate material readiness right now.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repairer/repairs/${order.database_id}/start-work`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (data.success) {
        setOrders((prev) =>
          prev.map((o) => (o.id === order.id ? { ...o, status: "in-progress", startedAt: new Date().toLocaleString() } : o))
        );
        setSelectedTab("in-progress");
        setCurrentPage(1);

        await Swal.fire({
          title: "Work started",
          text: `Repair work for ${order.id} is now in progress.`,
          icon: "success",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        });
      } else {
        throw new Error(data.message || 'Failed to update status');
      }
    } catch (error: any) {
      console.error('Failed to start work:', error);
      await Swal.fire({
        title: "Failed to start",
        text: error.message || "Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleResumeWork = async (orderId: string) => {
    const result = await Swal.fire({
      title: 'Resume Work?',
      text: 'Parts have arrived and work can continue.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Resume Work',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
    });

    if (!result.isConfirmed) return;

    try {
      const response = await axios.post(`/api/repairer/repairs/${orderId}/resume-work`);
      
      if (response.data.success) {
        await Swal.fire({
          title: 'Work Resumed!',
          text: response.data.message,
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
        fetchOrders();
      }
    } catch (error: any) {
      await Swal.fire({
        title: 'Error',
        text: error.response?.data?.message || 'Failed to resume work',
        icon: 'error',
      });
    }
  };

  const handleMarkCompleted = async (orderId: string) => {
    const result = await Swal.fire({
      title: 'Mark as Completed',
      text: 'Are you sure you want to mark this repair as completed?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Mark Completed',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#10b981',
    });

    if (!result.isConfirmed) return;

    const confirmProceedWithVariance = async () => {
      const proceedResult = await Swal.fire({
        title: "Material variance detected",
        text: "Some planned vs actual materials do not match and have no review notes. Continue anyway?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Continue",
        cancelButtonText: "Review Materials",
        confirmButtonColor: "#10b981",
      });

      return proceedResult.isConfirmed;
    };

    const completeRepair = (
      noMaterialsUsedConfirmed: boolean = false,
      varianceOverrideConfirmed: boolean = false
    ) => {
      return axios.post(`/api/repairer/repairs/${orderId}/mark-completed`, {
        completion_notes: '',
        no_materials_used_confirmed: noMaterialsUsedConfirmed,
        variance_override_confirmed: varianceOverrideConfirmed,
      });
    };

    try {
      const response = await completeRepair(false);
      
      if (response.data.success) {
        await Swal.fire({
          title: 'Repair Completed!',
          text: 'Customer will be notified that repair is done.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
        fetchOrders();
      }
    } catch (error: any) {
      if (error?.response?.status === 422 && error?.response?.data?.requires_material_confirmation) {
        const confirmNoMaterials = await Swal.fire({
          title: 'No Materials Logged',
          text: error?.response?.data?.message || 'No materials usage is logged for this repair. Confirm if no materials were used.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Confirm No Materials Used',
          cancelButtonText: 'Back',
          confirmButtonColor: '#10b981',
        });

        if (!confirmNoMaterials.isConfirmed) return;

        try {
          const retryResponse = await completeRepair(true);
          if (retryResponse.data.success) {
            await Swal.fire({
              title: 'Repair Completed!',
              text: 'Customer will be notified that repair is done.',
              icon: 'success',
              confirmButtonColor: '#2563eb',
            });
            fetchOrders();
          }
        } catch (retryError: any) {
          if (retryError?.response?.status === 422 && (retryError?.response?.data?.requires_variance_review
            || retryError?.response?.data?.data?.readiness_state === 'variance_review_needed')) {
            const shouldProceed = await confirmProceedWithVariance();
            if (!shouldProceed) return;

            try {
              const varianceRetryResponse = await completeRepair(true, true);
              if (varianceRetryResponse.data.success) {
                await Swal.fire({
                  title: 'Repair Completed!',
                  text: 'Customer will be notified that repair is done.',
                  icon: 'success',
                  confirmButtonColor: '#2563eb',
                });
                fetchOrders();
              }
            } catch (varianceRetryError: any) {
              await Swal.fire({
                title: 'Error',
                text: varianceRetryError.response?.data?.message || 'Failed to mark as completed',
                icon: 'error',
              });
            }

            return;
          }

          await Swal.fire({
            title: 'Error',
            text: retryError.response?.data?.message || 'Failed to mark as completed',
            icon: 'error',
          });
        }

        return;
      }

      if (error?.response?.status === 422 && (error?.response?.data?.requires_variance_review
        || error?.response?.data?.data?.readiness_state === 'variance_review_needed')) {
        const shouldProceed = await confirmProceedWithVariance();
        if (!shouldProceed) return;

        try {
          const varianceRetryResponse = await completeRepair(false, true);
          if (varianceRetryResponse.data.success) {
            await Swal.fire({
              title: 'Repair Completed!',
              text: 'Customer will be notified that repair is done.',
              icon: 'success',
              confirmButtonColor: '#2563eb',
            });
            fetchOrders();
          }
        } catch (varianceRetryError: any) {
          await Swal.fire({
            title: 'Error',
            text: varianceRetryError.response?.data?.message || 'Failed to mark as completed',
            icon: 'error',
          });
        }

        return;
      }

      await Swal.fire({
        title: 'Error',
        text: error.response?.data?.message || 'Failed to mark as completed',
        icon: 'error',
      });
    }
  };

  const handleMarkReady = async (orderId: string) => {
    const result = await Swal.fire({
      title: 'Mark as Ready for Pickup',
      text: 'Are you sure you want to mark this repair as ready for pickup?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Mark Ready',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#10b981',
    });

    if (!result.isConfirmed) return;

    const confirmProceedWithVariance = async () => {
      const proceedResult = await Swal.fire({
        title: 'Material variance detected',
        text: 'Some planned vs actual materials do not match and have no review notes. Continue anyway?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Continue',
        cancelButtonText: 'Review Materials',
        confirmButtonColor: '#10b981',
      });

      return proceedResult.isConfirmed;
    };

    const markReadyRepair = (
      noMaterialsUsedConfirmed: boolean = false,
      varianceOverrideConfirmed: boolean = false
    ) => {
      return axios.post(`/api/repairer/repairs/${orderId}/mark-ready`, {
        pickup_instructions: '',
        no_materials_used_confirmed: noMaterialsUsedConfirmed,
        variance_override_confirmed: varianceOverrideConfirmed,
      });
    };

    try {
      const response = await markReadyRepair(false);
      
      if (response.data.success) {
        setIsViewModalOpen(false);
        setViewOrder(null);

        await Swal.fire({
          title: 'Ready for Pickup!',
          text: 'Customer will be notified to pick up their item.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
        fetchOrders();
      }
    } catch (error: any) {
      if (error?.response?.status === 422 && error?.response?.data?.requires_material_confirmation) {
        const confirmNoMaterials = await Swal.fire({
          title: 'No Materials Logged',
          text: error?.response?.data?.message || 'No materials usage is logged for this repair. Confirm if no materials were used.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Confirm No Materials Used',
          cancelButtonText: 'Back',
          confirmButtonColor: '#10b981',
        });

        if (!confirmNoMaterials.isConfirmed) return;

        try {
          const retryResponse = await markReadyRepair(true);
          if (retryResponse.data.success) {
            setIsViewModalOpen(false);
            setViewOrder(null);

            await Swal.fire({
              title: 'Ready for Pickup!',
              text: 'Customer will be notified to pick up their item.',
              icon: 'success',
              confirmButtonColor: '#2563eb',
            });
            fetchOrders();
          }
        } catch (retryError: any) {
          if (retryError?.response?.status === 422 && (retryError?.response?.data?.requires_variance_review
            || retryError?.response?.data?.data?.readiness_state === 'variance_review_needed')) {
            const shouldProceed = await confirmProceedWithVariance();
            if (!shouldProceed) return;

            try {
              const varianceRetryResponse = await markReadyRepair(true, true);
              if (varianceRetryResponse.data.success) {
                setIsViewModalOpen(false);
                setViewOrder(null);

                await Swal.fire({
                  title: 'Ready for Pickup!',
                  text: 'Customer will be notified to pick up their item.',
                  icon: 'success',
                  confirmButtonColor: '#2563eb',
                });
                fetchOrders();
              }
            } catch (varianceRetryError: any) {
              await Swal.fire({
                title: 'Error',
                text: varianceRetryError.response?.data?.message || 'Failed to mark as ready',
                icon: 'error',
              });
            }

            return;
          }

          await Swal.fire({
            title: 'Error',
            text: retryError.response?.data?.message || 'Failed to mark as ready',
            icon: 'error',
          });
        }

        return;
      }

      if (error?.response?.status === 422 && (error?.response?.data?.requires_variance_review
        || error?.response?.data?.data?.readiness_state === 'variance_review_needed')) {
        const shouldProceed = await confirmProceedWithVariance();
        if (!shouldProceed) return;

        try {
          const varianceRetryResponse = await markReadyRepair(false, true);
          if (varianceRetryResponse.data.success) {
            setIsViewModalOpen(false);
            setViewOrder(null);

            await Swal.fire({
              title: 'Ready for Pickup!',
              text: 'Customer will be notified to pick up their item.',
              icon: 'success',
              confirmButtonColor: '#2563eb',
            });
            fetchOrders();
          }
        } catch (varianceRetryError: any) {
          await Swal.fire({
            title: 'Error',
            text: varianceRetryError.response?.data?.message || 'Failed to mark as ready',
            icon: 'error',
          });
        }

        return;
      }

      await Swal.fire({
        title: 'Error',
        text: error.response?.data?.message || 'Failed to mark as ready',
        icon: 'error',
      });
    }
  };

  const handleActivatePickup = async (targetOrder: RepairOrder) => {
    const handoff = targetOrder.returnHandoff;
    const actionLabel = handoff?.action_label || 'Record return handoff';

    if (!handoff?.can_release) {
      await Swal.fire({
        title: 'Handoff not available',
        text: handoff?.blocked_reason || 'Refresh the repair and check the return requirements.',
        icon: 'warning',
        confirmButtonColor: '#2563eb',
      });
      return;
    }

    const result = await Swal.fire({
      title: `${actionLabel}?`,
      text: targetOrder.returnDeliveryMethod === 'walk_in'
        ? 'Confirm that the repaired shoes are being released directly to the customer.'
        : 'Confirm that the repaired shoes were handed to the correct courier or rider.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: actionLabel,
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
    });

    if (!result.isConfirmed) return;

    try {
      const response = await axios.post(`/api/repairer/repairs/${targetOrder.database_id}/activate-pickup`);
      
      if (response.data.success) {
        await Swal.fire({
          title: 'Handoff recorded',
          text: 'The customer can now confirm receipt of the repaired shoes.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
        fetchOrders();
      }
    } catch (error: any) {
      await Swal.fire({
        title: 'Error',
        text: error.response?.data?.message || 'Failed to record the return handoff.',
        icon: 'error',
      });
    }
  };

  const handleActivatePayment = async (orderId: string) => {
    const targetOrder =
      (viewOrder && String(viewOrder.database_id) === orderId ? viewOrder : null)
      || orders.find((order) => String(order.database_id) === orderId)
      || null;

    if (targetOrder && isPosManualWalkIn(targetOrder)) {
      await Swal.fire({
        title: 'Not Required',
        text: 'POS walk-in repairs do not need payment activation. Cashier handles payment collection in Unified POS.',
        icon: 'info',
        confirmButtonColor: '#2563eb',
      });
      return;
    }

    const result = await Swal.fire({
      title: 'Activate Payment?',
      text: 'This will allow the customer to pay for this specific repair.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Activate Payment',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
    });

    if (!result.isConfirmed) return;

    try {
      const response = await axios.post(`/api/repairer/repairs/${orderId}/activate-payment`);
      
      if (response.data.success) {
        setOrders((prev) =>
          prev.map((o) =>
            String(o.database_id) === String(orderId)
              ? { ...o, payment_enabled: true }
              : o
          )
        );

        setViewOrder((prev) =>
          prev && String(prev.database_id) === String(orderId)
            ? { ...prev, payment_enabled: true }
            : prev
        );

        await Swal.fire({
          title: 'Payment Activated!',
          text: 'Customer can now pay for this repair.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
        fetchOrders();
      }
    } catch (error: any) {
      await Swal.fire({
        title: 'Error',
        text: error.response?.data?.message || 'Failed to activate payment',
        icon: 'error',
      });
    }
  };

  const handleCompleteWork = async (order: RepairOrder) => {
    const result = await Swal.fire({
      title: "Mark as completed?",
      text: `${order.service} for ${order.customer}`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, mark completed",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#2563eb",
    });

    if (!result.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repair-requests/${order.database_id}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ status: 'ready-for-pickup' }),
      });

      const data = await response.json();

      if (data.success) {
        setOrders((prev) =>
          prev.map((o) => (o.id === order.id ? { ...o, status: "ready-for-pickup", completedAt: new Date().toLocaleString() } : o))
        );
        setSelectedTab("ready-for-pickup");
        setCurrentPage(1);

        await Swal.fire({
          title: "Work completed",
          text: `${order.id} is now ready for customer pickup.`,
          icon: "success",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        });
      } else {
        throw new Error(data.message || 'Failed to update status');
      }
    } catch (error: any) {
      console.error('Failed to complete work:', error);
      await Swal.fire({
        title: "Failed to complete",
        text: error.message || "Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleViewOrder = (order: RepairOrder) => {
    setViewOrder(order);
    setIsViewModalOpen(true);
  };

  const calculateEtaDate = (preset: string): string => {
    const today = new Date();
    let daysToAdd = 3;

    if (preset.includes("1-2")) daysToAdd = 2;
    else if (preset.includes("1-3")) daysToAdd = 3;
    else if (preset.includes("2-4")) daysToAdd = 4;
    else if (preset.includes("3-6")) daysToAdd = 6;

    let currentDate = new Date(today);
    let addedDays = 0;

    while (addedDays < daysToAdd) {
      currentDate.setDate(currentDate.getDate() + 1);
      if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) {
        addedDays++;
      }
    }

    return currentDate.toISOString().split('T')[0];
  };

  const handleShipOrder = (order: RepairOrder) => {
    if (isWalkInReturn(order)) {
      Swal.fire({
        title: 'Walk-in pickup order',
        text: 'Walk-in repairs should be handed directly to the customer. Use Receive instead of Ship.',
        icon: 'info',
        confirmButtonColor: '#2563eb',
      });
      return;
    }

    setSelectedOrder(order);
    setEtaPreset("1-2 business days");
    setCarrierCompany("");
    setCarrierName("");
    setCarrierPhone("");
    setTrackingNumber("");
    setTrackingLink("");
    setIsShippingModalOpen(true);
  };

  const handleConfirmShipping = async () => {
    if (!selectedOrder) return;
    const normalizedCarrierCompany = (carrierCompany || carrierCompanySelectRef.current?.value || '').trim();

    // Validation
    if (!etaPreset) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please select an Estimated Delivery Date",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!normalizedCarrierCompany) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please select a Shipping Business",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!carrierName) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter the Rider Name",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!carrierPhone) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter the Rider Phone Number",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    const normalizedTrackingNumber = trackingNumber.trim();

    if (!normalizedTrackingNumber) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter a Tracking Number",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!/^\d+$/.test(normalizedTrackingNumber)) {
      await Swal.fire({
        title: "Invalid Tracking Number",
        text: "Tracking Number must contain numbers only.",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    const etaDate = calculateEtaDate(etaPreset);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repairer/repairs/${selectedOrder.database_id}/ship`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          tracking_number: normalizedTrackingNumber,
          carrier_company: normalizedCarrierCompany,
          carrier_name: carrierName,
          carrier_phone: carrierPhone,
          tracking_link: trackingLink || null,
          estimated_delivery_date: etaDate,
        }),
      });

      const data = await response.json();

      if (data.success) {
        setOrders((prev) =>
          prev.map((o) =>
            o.id === selectedOrder.id
              ? {
                  ...o,
                  status: "shipped",
                }
              : o
          )
        );

        setIsShippingModalOpen(false);
        setSelectedOrder(null);
        setCarrierCompany("");
        setCarrierName("");
        setCarrierPhone("");
        setTrackingNumber("");
        setTrackingLink("");

        await Swal.fire({
          title: "Success",
          text: `Order ${selectedOrder.id} has been marked as shipped.`,
          icon: "success",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        });
      } else {
        throw new Error(data.message || 'Failed to update shipping information');
      }
    } catch (error: any) {
      console.error('Failed to confirm shipping:', error);
      await Swal.fire({
        title: "Error",
        text: error.message || "Failed to confirm shipping. Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const formatIntakeDeliveryMethod = (order: Pick<RepairOrder, 'intakeDeliveryMethod' | 'serviceType'>) => {
    if (order.intakeDeliveryMethod === "walk_in") return "Customer drop-off";
    if (order.intakeDeliveryMethod === "customer_delivery") return "Customer-arranged courier";
    if (order.intakeDeliveryMethod === "shop_pickup") return "Shop rider pickup";
    if (order.serviceType === "walkin") return "Customer drop-off";
    if (order.serviceType === "pickup") return "Customer-arranged courier";
    return "Not specified";
  };

  const formatLogisticsStatus = (status?: string | null) => {
    if (!status) return "Not available";
    if (status === "proof_correction_required") return "Proof correction required";
    return status
      .replace(/_/g, " ")
      .replace(/\b\w/g, (letter) => letter.toUpperCase());
  };

  const getReturnDeliveryMethod = (order: RepairOrder): "walk_in" | "customer_pickup" | "shop_delivery" => {
    if (order.returnDeliveryMethod === "walk_in" || order.returnDeliveryMethod === "customer_pickup" || order.returnDeliveryMethod === "shop_delivery") {
      return order.returnDeliveryMethod;
    }

    return order.serviceType === "walkin" ? "walk_in" : "customer_pickup";
  };

  const formatReturnDeliveryMethod = (order: RepairOrder) => {
    const method = getReturnDeliveryMethod(order);

    if (method === "walk_in") return "Customer Pick-up at Shop";
    if (method === "shop_delivery") return "Shop Delivery to Customer";
    return "Customer Arranges Courier Pickup";
  };

  const getShippingAddress = (order: RepairOrder) => {
    const returnParts = [
      order.returnAddressLine,
      order.returnBarangay,
      order.returnCity,
      order.returnRegion,
      order.returnPostalCode,
    ].filter(Boolean);

    if (returnParts.length) {
      return returnParts.join(', ');
    }

    const fallbackParts = [
      order.pickupAddressLine,
      order.pickupBarangay,
      order.pickupCity,
      order.pickupRegion,
      order.pickupPostalCode,
    ].filter(Boolean);

    return fallbackParts.length ? fallbackParts.join(', ') : 'No shipping address provided';
  };

  const handleReviewAction = async (action: "accept" | "reject" | "message") => {
    if (!viewOrder) return;

    const isPosCreatedOrder = String(viewOrder.id ?? "").toUpperCase().startsWith("REP-POS-");

    if (action === "message") {
      return;
    }

    if (action === "accept" || action === "reject") {
      const isAcceptAction = action === "accept";
      const confirmation = await Swal.fire({
        title: isAcceptAction ? "Accept Request?" : "Reject Request?",
        text: isAcceptAction
          ? (isPosCreatedOrder
            ? "This will move the request forward. POS walk-ins do not open support chat because the customer has no account."
            : "This will move the request forward and open the customer support chat.")
          : "This will proceed to the rejection form where you can select a reason.",
        icon: "question",
        showCancelButton: true,
        confirmButtonText: isAcceptAction ? "Yes, accept" : "Yes, continue",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#111827",
      });

      if (!confirmation.isConfirmed) {
        return;
      }
    }

    if (action === "accept") {
      if (activeRepairCount > repairRequestLimit) {
        await Swal.fire({
          title: 'Repair Limit Reached',
          text: `This repairer already has ${activeRepairCount} active job orders. Increase the limit to accept more requests.`,
          icon: 'warning',
          confirmButtonText: 'OK',
          confirmButtonColor: '#2563eb',
        });
        return;
      }

      try {
        const response = await axios.post(`/api/repairer/repairs/${viewOrder.database_id}/accept`);
        
        if (response.data.success) {
          const createdConversationId =
            response.data.conversation_id ||
            response.data.conversation?.id ||
            viewOrder.conversation_id;

          const shouldOpenChat = !isPosCreatedOrder && Number(createdConversationId) > 0;

          await Swal.fire({
            title: "Request Accepted",
            text: shouldOpenChat
              ? (response.data.message || "Opening support chat with this customer...")
              : (response.data.message || "Repair accepted. No support chat was opened for this POS walk-in order."),
            icon: "success",
            confirmButtonText: shouldOpenChat ? "Open Chat" : "Continue",
            confirmButtonColor: "#2563eb",
          });

          if (shouldOpenChat) {
            router.visit(`/erp/staff/repairer-support?conversation_id=${createdConversationId}`);
            return;
          }

          setIsViewModalOpen(false);
          await fetchOrders();
        }
      } catch (error: any) {
        console.error('Failed to accept repair:', error);
        await Swal.fire({
          title: "Error",
          text: error.response?.data?.message || "Failed to accept the repair request. Please try again.",
          icon: "error",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        });
      }
    } else if (action === "reject") {
      // Close view modal and open rejection modal
      setIsViewModalOpen(false);
      setSelectedRejectionReason("");
      setRejectionReason("");
      setIsRejectionModalOpen(true);
    }
  };

  const handleSubmitRejection = async () => {
    const rejectionTarget = viewOrder ?? selectedOrder;
    if (!rejectionTarget || !selectedRejectionReason.trim()) {
      await Swal.fire({
        title: "Validation Error",
        text: "Please select a rejection reason.",
        icon: "warning",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    // Combine selected reason with additional notes
    const fullReason = rejectionReason.trim() 
      ? `${selectedRejectionReason}\n\nAdditional Notes: ${rejectionReason}`
      : selectedRejectionReason;

    try {
      const response = await axios.post(`/api/repairer/repairs/${rejectionTarget.database_id}/reject`, {
        reason: fullReason
      });
      
      if (response.data.success) {
        // Close rejection modal immediately
        setIsRejectionModalOpen(false);

        // Refresh the repairs list
        fetchOrders();

        await Swal.fire({
          title: "Request Rejected",
          text: response.data.message || "The request has been rejected and manager has been notified.",
          icon: "success",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        });

        setViewOrder(null);
        setSelectedOrder(null);
        setSelectedRejectionReason("");
        setRejectionReason("");
        setSelectedTab("rejected");
      }
    } catch (error: any) {
      console.error('Error rejecting request:', error);
      await Swal.fire({
        title: "Error",
        text: error.response?.data?.message || "Failed to reject the request. Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const endorseRefundForFinance = async (refundId: number, assessmentNote: string, requestedAmount: number) => {
    return axios.post(`/api/repairer/refunds/${refundId}/approve`, {
      assessment_note: assessmentNote,
      approved_amount: requestedAmount,
    });
  };

  const rejectRefundForRepairer = async (refundId: number, assessmentNote: string, reason: string) => {
    return axios.post(`/api/repairer/refunds/${refundId}/reject`, {
      assessment_note: assessmentNote,
      reason,
    });
  };

  const closeRefundReviewModal = () => {
    setIsRefundReviewModalOpen(false);
    setRefundReviewOrder(null);
    setRefundReviewItem(null);
    setRefundReviewMode("approve");
    setRefundApprovedAmountInput("");
    setRefundAssessmentNoteInput("");
    setRefundRejectionReasonInput("");
    setIsRefundReviewSubmitting(false);
  };

  const handleRefundReview = async (order: RepairOrder, refund: RepairRefundQueueItem) => {
    const requestedAmount = toNumber(refund.requested_amount) ?? 0;
    setRefundReviewOrder(order);
    setRefundReviewItem(refund);
    setRefundReviewMode("approve");
    setRefundApprovedAmountInput(requestedAmount > 0 ? requestedAmount.toFixed(2) : "");
    setRefundAssessmentNoteInput("");
    setRefundRejectionReasonInput("");
    setIsRefundReviewModalOpen(true);
  };

  const submitRefundReview = async () => {
    if (!refundReviewItem) {
      return;
    }

    const assessmentNote = refundAssessmentNoteInput.trim();
    if (!assessmentNote) {
      await Swal.fire({
        title: 'Assessment note required',
        text: 'Please add an assessment note before submitting.',
        icon: 'warning',
        confirmButtonColor: '#2563eb',
      });
      return;
    }

    setIsRefundReviewSubmitting(true);

    try {
      if (refundReviewMode === 'approve') {
        const approvedAmount = Number(refundApprovedAmountInput);
        if (!Number.isFinite(approvedAmount) || approvedAmount <= 0) {
          await Swal.fire({
            title: 'Invalid amount',
            text: 'Enter a valid approved amount.',
            icon: 'warning',
            confirmButtonColor: '#2563eb',
          });
          setIsRefundReviewSubmitting(false);
          return;
        }

        await endorseRefundForFinance(refundReviewItem.id, assessmentNote, approvedAmount);
        await Promise.all([fetchOrders(), fetchRepairerRefundQueue()]);
        closeRefundReviewModal();
        await Swal.fire({
          title: 'Refund endorsed',
          text: 'Finance can now continue with review and execution.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
      } else {
        const rejectionReason = refundRejectionReasonInput.trim();
        if (!rejectionReason) {
          await Swal.fire({
            title: 'Rejection reason required',
            text: 'Please provide a rejection reason before submitting.',
            icon: 'warning',
            confirmButtonColor: '#2563eb',
          });
          setIsRefundReviewSubmitting(false);
          return;
        }

        await rejectRefundForRepairer(refundReviewItem.id, assessmentNote, rejectionReason);
        await Promise.all([fetchOrders(), fetchRepairerRefundQueue()]);
        closeRefundReviewModal();
        await Swal.fire({
          title: 'Refund request rejected',
          text: 'The customer will see that this refund request was declined.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
      }
    } catch (reviewError: any) {
      await Swal.fire({
        title: refundReviewMode === 'approve' ? 'Unable to endorse refund' : 'Unable to reject refund',
        text: reviewError?.response?.data?.message || 'Please try again.',
        icon: 'error',
        confirmButtonColor: '#2563eb',
      });
    } finally {
      setIsRefundReviewSubmitting(false);
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Repair Services - Solespace ERP" />
      {error && <ErrorModal message={error} onClose={() => setError(null)} />}
      
      <div className="space-y-6">
        {/* Header */}
        <div className="flex justify-between items-start">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Shoe Repair & Cleaning Services</h1>
            <p className="text-gray-600 dark:text-gray-400 mt-2">Manage shoe cleaning and repair service orders</p>
          </div>
          <div className="flex flex-col items-end gap-2">
            <p className="text-xs text-gray-600 dark:text-gray-400">
              Active workload: <span className={`font-semibold ${activeRepairCount > repairRequestLimit ? 'text-red-600 dark:text-red-400' : ''}`}>{activeRepairCount}</span> / {repairRequestLimit}
            </p>
            <p className="text-xs text-gray-600 dark:text-gray-400">
              Pending refund reviews: <span className={`font-semibold ${repairerRefundQueue.length > 0 ? 'text-amber-600 dark:text-amber-400' : ''}`}>{repairerRefundQueue.length}</span>
            </p>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
          <MetricCard
            title="Pending"
            value={stats.pending}
            change={0}
            changeType="increase"
            icon={ClockIcon}
            color="warning"
            description="Awaiting service start"
          />
          <MetricCard
            title="In Progress"
            value={stats.inProgress}
            change={5}
            changeType="increase"
            icon={ClockIcon}
            color="info"
            description="Currently being serviced"
          />
          <MetricCard
            title="Ready for Pickup"
            value={stats.readyForPickup}
            change={12}
            changeType="increase"
            icon={PackageIcon}
            color="success"
            description="Completed services"
          />
          <MetricCard
            title="Service Revenue (Excl. VAT)"
            value={`₱${stats.totalRevenue.toLocaleString()}`}
            change={18}
            changeType="increase"
            icon={CurrencyDollarIcon}
            color="success"
            description="Services + paid shop-owned delivery, excl. VAT"
          />
        </div>

        {/* Main Content */}
        <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
          {/* Tabs, Search, and Actions */}
          <div className="p-6 border-b border-gray-200 dark:border-gray-800">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
              {/* Tabs */}
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setSelectedTab("all")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "all"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  All Services ({stats.total})
                </button>
                <button
                  onClick={() => setSelectedTab("under-review")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "under-review"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  New Request ({stats.underReview})
                </button>
                <button
                  onClick={() => setSelectedTab("pending")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "pending"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Pending ({stats.pending})
                </button>
                <button
                  onClick={() => setSelectedTab("received")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "received"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Received ({stats.received})
                </button>
                <button
                  onClick={() => setSelectedTab("in-progress")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "in-progress"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  In Progress ({stats.inProgress})
                </button>
                <button
                  onClick={() => setSelectedTab("ready-for-pickup")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "ready-for-pickup"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Ready for Pickup ({stats.readyForPickup})
                </button>
                <button
                  onClick={() => setSelectedTab("warranty")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "warranty"
                      ? "bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Warranty ({stats.warranty})
                </button>
                <button
                  onClick={() => setSelectedTab("completed")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "completed"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Completed ({stats.completedAll})
                </button>
                <button
                  onClick={() => setSelectedTab("rejected")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "rejected"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Rejected ({stats.rejected})
                </button>
                <button
                  onClick={() => setSelectedTab("cancelled")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "cancelled"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Cancelled ({stats.cancelled})
                </button>
              </div>

              {/* Search */}
              <div className="relative flex-1 max-w-md">
                <div className="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                  <MagnifyingGlassIcon className="size-5 text-gray-400" />
                </div>
                <input
                  type="text"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  placeholder="Search repairs..."
                  className="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
          </div>

          {/* Table */}
          <div className="h-135 overflow-y-auto overflow-x-hidden">
            {isLoading ? (
              <div className="flex items-center justify-center py-12">
                <div className="flex flex-col items-center gap-3">
                  <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Loading repair orders...</p>
                </div>
              </div>
            ) : (
              <table className="w-full table-fixed divide-y divide-gray-200 dark:divide-gray-800">
              <colgroup>
                <col className="w-[13%]" />
                <col className="w-[8%]" />
                <col className="w-[14%]" />
                <col className="w-[10%]" />
                <col className="w-[10%]" />
                <col className="w-[7%]" />
                <col className="w-[7%]" />
                <col className="w-[9%]" />
                <col className="w-[8%]" />
                <col className="w-[14%]" />
              </colgroup>
              <thead className="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Customer
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Item
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Service
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Intake Method
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Price
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Paid
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Created
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Preferred Date
                  </th>
                  <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-white/[0.02] divide-y divide-gray-200 dark:divide-gray-800">
                {paginatedOrders.length > 0 ? (
                  paginatedOrders.map((order) => (
                    (() => {
                      const pendingRefund = pendingRefundByRepairId.get(order.database_id) ?? null;
                      const isHighlighted =
                        Boolean(highlightRepairToken) &&
                        (
                          String(order.database_id) === String(highlightRepairToken) ||
                          String(order.id) === String(highlightRepairToken)
                        );

                      return (
                    <tr 
                      key={order.id} 
                      data-repair-id={order.database_id}
                      data-repair-request-id={order.id}
                      className={`hover:bg-gray-50 dark:hover:bg-white/[0.02] transition-colors ${
                        isHighlighted ? 'border-l-4 border-l-black bg-gray-50 dark:bg-gray-900/50' : ''
                      }`}
                    >
                      <td className="px-4 py-4">
                        <div className="text-sm">
                          <div className="font-medium text-gray-900 dark:text-white">{order.customer}</div>
                          <div className="text-gray-500 dark:text-gray-400 break-all">{order.phone}</div>
                        </div>
                      </td>
                      <td className="px-4 py-4">
                        <span className="text-sm text-gray-900 dark:text-white wrap-break-word">{order.item}</span>
                      </td>
                      <td className="px-4 py-4">
                        <div className="space-y-1">
                          <span className="block text-sm font-medium text-gray-700 dark:text-gray-300">{order.service}</span>
                          {order.packageName && (
                            <span className="block text-xs text-blue-700 dark:text-blue-400">
                              Package: {order.packageName}
                            </span>
                          )}
                          {isWarrantyNoChargeOrder(order) && (
                            <span className="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                              Warranty Rework
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-4">
                        <div className="flex flex-col gap-1">
                          <span className={`px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs leading-5 font-semibold rounded-full ${getStatusColor(order.status)}`}>
                            {getRepairStatusLabel(order.status)}
                          </span>
                          {getPaymentStatusBadgeLabel(order.payment_status) && (
                            <span className="px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs leading-5 font-semibold rounded-full bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                              {getPaymentStatusBadgeLabel(order.payment_status)}
                            </span>
                          )}
                          {isInShopPaymentRecorded(order) && (
                            <span className="px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs leading-5 font-semibold rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                              In-Shop Payment
                            </span>
                          )}
                          {isWarrantyNoChargeOrder(order) && (
                            <span className="px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                              Warranty
                            </span>
                          )}
                          {pendingRefund && (
                            <span className="px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                              Refund Review Pending
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-white">
                        {formatIntakeDeliveryMethod(order)}
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-900 dark:text-white font-medium">
                        {isWarrantyNoChargeOrder(order) ? (
                          <div className="space-y-0.5">
                            <span className="block text-emerald-700 dark:text-emerald-300">No Charge</span>
                            <span className="block text-[11px] font-normal text-emerald-600 dark:text-emerald-400">
                              Covered by warranty
                            </span>
                          </div>
                        ) : (
                          order.grandTotal || order.total
                        )}
                      </td>
                      <td className="px-4 py-4 text-sm font-semibold">
                        {order.payment_status === 'refunded' ? (
                          <span className="text-rose-600 dark:text-rose-400">Refunded</span>
                        ) : order.payment_status === 'partially_refunded' ? (
                          <span className="text-rose-600 dark:text-rose-400">Partially Refunded</span>
                        ) : (
                          (() => {
                            const paidAmount = getDisplayedPaidAmount(order);

                            if (paidAmount <= 0) {
                              return <span className="text-gray-400 dark:text-gray-500">—</span>;
                            }

                            const status = (order.payment_status ?? '').toLowerCase();
                            const amountText = formatPesoAmount(paidAmount) ?? '₱0.00';
                            const paidClass = status === 'completed'
                              ? 'text-green-600 dark:text-green-400'
                              : 'text-amber-600 dark:text-amber-400';

                            return <span className={paidClass}>{amountText}</span>;
                          })()
                        )}
                      </td>
                      <td className="px-4 py-4 text-sm text-gray-500 dark:text-gray-400 wrap-break-word">
                        {order.createdAt}
                      </td>
                      <td className="px-4 py-4 text-sm wrap-break-word">
                        {order.preferredDate
                          ? <span className="font-medium text-blue-700 dark:text-blue-400">{order.preferredDate}</span>
                          : <span className="text-gray-400 dark:text-gray-500">—</span>}
                      </td>
                      <td className="px-4 py-4 text-sm font-medium whitespace-nowrap">
                        <div className="flex items-center gap-1 whitespace-nowrap [&>button]:inline-flex [&>button]:size-10 [&>button]:items-center [&>button]:justify-center [&>button]:rounded-lg [&>button>svg]:size-5">
                          <button
                            onClick={() => handleViewOrder(order)}
                            className="inline-flex items-center justify-center p-2 text-blue-600 hover:text-blue-700 hover:bg-blue-50 dark:text-blue-400 dark:hover:text-blue-300 dark:hover:bg-blue-900/30 rounded-lg transition-colors"
                            title="View details"
                          >
                            <EyeIcon className="size-5" />
                          </button>

                          {pendingRefund && (
                            <button
                              onClick={() => handleRefundReview(order, pendingRefund)}
                              className="inline-flex items-center justify-center p-2 text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/70"
                              title={`Review refund (${pendingRefund.refund_no || `#${pendingRefund.id}`})`}
                              aria-label="Review Refund Request"
                            >
                              <RefundIcon className="size-5" />
                            </button>
                          )}

                          {order.intakeHandoff && (order.status === "pending" || canMarkReceived(order)) && (
                            <button
                              onClick={() => handleMarkReceived(order)}
                              disabled={!canMarkReceived(order)}
                              className={`inline-flex items-center justify-center p-2 rounded-lg transition-colors ${
                                canMarkReceived(order)
                                  ? 'text-cyan-600 hover:text-cyan-700 hover:bg-cyan-50 dark:text-cyan-400 dark:hover:text-cyan-300 dark:hover:bg-cyan-900/30'
                                  : 'text-gray-400 cursor-not-allowed'
                              }`}
                              title={
                                canMarkReceived(order)
                                  ? 'Confirm physical receipt'
                                  : getMarkReceivedBlockedMessage(order)
                              }
                              aria-label="Review physical receipt"
                            >
                              <PackageIcon className="size-5" />
                            </button>
                          )}
                          {(order.status === "owner_approved" || order.status === "repairer_accepted" || order.status === "waiting_customer_confirmation" || order.status === "pending") && !order.payment_enabled && !isPosManualWalkIn(order) && !isWarrantyNoChargeOrder(order) && (
                            <button
                              onClick={() => handleActivatePayment(String(order.database_id))}
                              className="inline-flex items-center justify-center p-2 rounded-lg text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:text-amber-300 dark:hover:bg-amber-900/30 transition-colors"
                              title="Activate payment for this repair"
                              aria-label="Activate Payment"
                            >
                              <CurrencyDollarIcon className="size-5" />
                            </button>
                          )}

                          {order.status === "received" && (
                            <>
                              {!order.payment_enabled && !isPosManualWalkIn(order) && !isWarrantyNoChargeOrder(order) && (
                                <button
                                  onClick={() => handleActivatePayment(String(order.database_id))}
                                  className="inline-flex items-center justify-center p-2 rounded-lg text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:text-amber-300 dark:hover:bg-amber-900/30 transition-colors"
                                  title="Activate payment for this repair"
                                  aria-label="Activate Payment"
                                >
                                  <CurrencyDollarIcon className="size-5" />
                                </button>
                              )}
                              <button
                                onClick={() => handleStartWork(order)}
                                className="inline-flex items-center justify-center p-2 rounded-lg text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:text-indigo-300 dark:hover:bg-indigo-900/30 transition-colors"
                                title="Start work"
                                aria-label="Start Work"
                              >
                                <WrenchIcon className="size-5" />
                              </button>
                            </>
                          )}

                          {(order.status === "in-progress" || order.status === "completed") && (
                            <button
                              onClick={() => handleMarkReady(String(order.database_id))}
                              className="inline-flex items-center justify-center p-2 text-green-600 hover:text-green-700 hover:bg-green-50 dark:text-green-400 dark:hover:text-green-300 dark:hover:bg-green-900/30 rounded-lg transition-colors"
                              title="Ready for Pickup"
                              aria-label="Ready for Pickup"
                            >
                              <CheckCircleIcon className="size-5" />
                            </button>
                          )}
                          
                          {order.status === "awaiting_parts" && (
                            <button
                              onClick={() => handleResumeWork(String(order.database_id))}
                              className="inline-flex items-center justify-center p-2 rounded-lg text-blue-600 hover:text-blue-700 hover:bg-blue-50 dark:text-blue-400 dark:hover:text-blue-300 dark:hover:bg-blue-900/30 transition-colors"
                              title="Resume work (parts arrived)"
                              aria-label="Resume Work"
                            >
                              <WrenchIcon className="size-5" />
                            </button>
                          )}
                          
                          {order.status === "ready-for-pickup"
                            && !isWarrantyNoChargeOrder(order)
                            && canActivateOnlineRemainingBalance(order) && (
                            <button
                              onClick={() => handleActivatePayment(String(order.database_id))}
                              className="inline-flex items-center justify-center p-2 rounded-lg text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:text-amber-300 dark:hover:bg-amber-900/30 transition-colors"
                              title="Activate online payment for remaining balance"
                              aria-label="Activate Remaining Balance"
                            >
                              <CurrencyDollarIcon className="size-5" />
                            </button>
                          )}
                          {order.returnHandoff
                            && order.returnHandoff.visible !== false
                            && !order.returnHandoff.recovery
                            && ["ready-for-pickup", "shipped"].includes(order.status) && (
                            <button
                              onClick={() => handleActivatePickup(order)}
                              disabled={!order.returnHandoff.can_release}
                              className={`inline-flex items-center justify-center p-2 rounded-lg transition-colors ${
                                order.returnHandoff.can_release
                                  ? 'text-purple-600 hover:text-purple-700 hover:bg-purple-50 dark:text-purple-400 dark:hover:text-purple-300 dark:hover:bg-purple-900/30'
                                  : 'text-gray-400 cursor-not-allowed'
                              }`}
                              title={order.returnHandoff.blocked_reason || order.returnHandoff.action_label}
                              aria-label={order.returnHandoff.action_label || 'Record return handoff'}
                            >
                              <PackageIcon className="size-5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                      );
                    })()
                  ))
                ) : (
                  <tr>
                    <td colSpan={9} className="px-6 py-12 text-center">
                      <p className="text-sm text-gray-500 dark:text-gray-400">No repair orders found</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
            )}
          </div>

          {/* Pagination */}
          {filteredOrders.length > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800">
              <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(endIndex, filteredOrders.length)}</span> of{" "}
                  <span className="font-medium">{filteredOrders.length}</span>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                    disabled={currentPage === 1}
                    className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    title="Previous page"
                  >
                    <ChevronLeftIcon className="size-5" />
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
                          className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                            currentPage === page
                              ? "bg-blue-600 text-white"
                              : "bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800"
                          }`}
                        >
                          {page}
                        </button>
                      );
                    } else if (page === currentPage - 2 || page === currentPage + 2) {
                      return (
                        <span key={page} className="px-2 text-gray-500">
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
                    <ChevronRightIcon className="size-5" />
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* View Order Modal */}
        {isViewModalOpen && viewOrder && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-0">
            <div
              className="absolute inset-0"
              onClick={() => {
                setIsViewModalOpen(false);
                setViewOrder(null);
              }}
            />

            <div className="relative bg-white dark:bg-gray-800 shadow-2xl w-screen h-screen max-w-none max-h-screen rounded-none overflow-hidden flex flex-col">
              <div className="sticky top-0 bg-white dark:bg-gray-800 px-4 sm:px-6 pt-5 pb-4 border-b border-gray-200 dark:border-gray-700 z-10">
                <div className="mx-auto w-full max-w-6xl flex items-start justify-between gap-4">
                  <div>
                    <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Repair Service Details</h3>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Order {viewOrder.id}</p>
                  </div>
                  <div className="flex flex-col items-end gap-2">
                    <span className={`px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs font-semibold rounded-full ${getStatusColor(viewOrder.status)}`}>
                      {getRepairStatusLabel(viewOrder.status)}
                    </span>
                    {getPaymentStatusBadgeLabel(viewOrder.payment_status) && (
                      <span className="px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs font-semibold rounded-full bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                        {getPaymentStatusBadgeLabel(viewOrder.payment_status)}
                      </span>
                    )}
                    {isInShopPaymentRecorded(viewOrder) && (
                      <span className="px-2.5 py-1 inline-flex w-fit max-w-max whitespace-nowrap text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        In-Shop Payment
                      </span>
                    )}
                  </div>
                </div>
              </div>

              <div className="flex-1 overflow-y-auto">
                <div className="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-10 py-6 space-y-6">
                {/* Shoe Images */}
                {((viewOrder.imageUrls && viewOrder.imageUrls.length > 0) || viewOrder.imageUrl) && (
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Shoe Images</p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                      {(viewOrder.imageUrls && viewOrder.imageUrls.length > 0
                        ? viewOrder.imageUrls
                        : [viewOrder.imageUrl as string]
                      ).map((src, index) => (
                        <button
                          key={`${src}-${index}`}
                          type="button"
                          onClick={() => setEnlargedImage(src)}
                          className="group relative h-52 w-full rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500/40 transition-shadow"
                          title="View image"
                        >
                          <img
                            src={src}
                            alt={`${viewOrder.item} ${index + 1}`}
                            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                          />
                          <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors" />
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {/* Customer Info */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Customer</p>
                    <p className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.customer}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Email</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.email}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Phone</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.phone}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Created</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.createdAt}</p>
                  </div>
                  {viewOrder.preferredDate && (
                    <div>
                      <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Customer's Preferred Date</p>
                      <p className="text-sm font-semibold text-blue-700 dark:text-blue-400">{viewOrder.preferredDate}</p>
                    </div>
                  )}
                </div>

                {/* Service Details */}
                <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                  <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Service Details</p>
                  <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Shoe Type</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.shoeType || viewOrder.item || 'Not specified'}</span>
                    </div>
                    {viewOrder.brand && (
                      <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600 dark:text-gray-400">Brand</span>
                        <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.brand}</span>
                      </div>
                    )}
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Services Requested</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">
                        {viewOrder.service || 'Not specified'}
                      </span>
                    </div>
                    {viewOrder.packageName && (
                      <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600 dark:text-gray-400">Package Name</span>
                        <span className="text-sm font-medium text-gray-900 dark:text-white">
                          {viewOrder.packageName}
                        </span>
                      </div>
                    )}
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Intake Delivery Method</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">
                        {formatIntakeDeliveryMethod(viewOrder)}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Return Delivery Method</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">
                        {formatReturnDeliveryMethod(viewOrder)}
                      </span>
                    </div>
                    {(viewOrder.packagePrice || viewOrder.addOnsSubtotal || viewOrder.finalPrice) && (
                      <>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Package Base Price</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.packagePrice || '₱0.00'}</span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Add-ons Subtotal</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.addOnsSubtotal || '₱0.00'}</span>
                        </div>
                        <div className="flex items-center justify-between pt-1 border-t border-gray-200 dark:border-gray-700">
                          <span className="text-sm text-gray-700 dark:text-gray-300">Subtotal (Before VAT)</span>
                          <span className="text-sm font-semibold text-gray-900 dark:text-white">{viewOrder.finalPrice || viewOrder.total}</span>
                        </div>
                      </>
                    )}
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">VAT ({viewOrder.vatRate ?? REPAIR_VAT_RATE_PERCENT}%)</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.vatAmount || '₱0.00'}</span>
                    </div>
                    <div className="flex items-center justify-between pt-1 border-t border-gray-200 dark:border-gray-700">
                      <span className="text-sm text-gray-700 dark:text-gray-300">Grand Total</span>
                      <span className="text-sm font-semibold text-gray-900 dark:text-white">
                        {isWarrantyNoChargeOrder(viewOrder) ? 'No Charge (Warranty)' : (viewOrder.grandTotal || viewOrder.total)}
                      </span>
                    </div>
                    {isWarrantyNoChargeOrder(viewOrder) && (
                      <p className="text-xs text-emerald-700 dark:text-emerald-300">
                        This linked warranty job is covered under your warranty policy and is intentionally billed at no charge.
                      </p>
                    )}
                    {viewOrder.startedAt && (
                      <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600 dark:text-gray-400">Started At</span>
                        <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.startedAt}</span>
                      </div>
                    )}
                    {viewOrder.completedAt && (
                      <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600 dark:text-gray-400">Completed At</span>
                        <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.completedAt}</span>
                      </div>
                    )}
                  </div>
                </div>

                {viewOrder.intakeHandoff && viewOrder.intakeDeliveryMethod === 'shop_pickup' && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                      Intake logistics
                    </p>
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm dark:border-blue-800 dark:bg-blue-900/20">
                      <p className="font-semibold text-gray-900 dark:text-white">Delivery progress</p>
                      <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div>
                          <dt className="text-xs text-gray-500 dark:text-gray-400">Shipment</dt>
                          <dd className="font-medium text-gray-900 dark:text-white">{formatLogisticsStatus(viewOrder.intakeHandoff.shipment_status)}</dd>
                        </div>
                        <div>
                          <dt className="text-xs text-gray-500 dark:text-gray-400">Pickup leg</dt>
                          <dd className="font-medium text-gray-900 dark:text-white">{formatLogisticsStatus(viewOrder.intakeHandoff.leg_status)}</dd>
                        </div>
                        <div>
                          <dt className="text-xs text-gray-500 dark:text-gray-400">Proof</dt>
                          <dd className="font-medium text-gray-900 dark:text-white">{formatLogisticsStatus(viewOrder.intakeHandoff.proof_status)}</dd>
                        </div>
                      </dl>
                      {(viewOrder.intakeHandoff.scheduled_delivery_date || viewOrder.intakeHandoff.delivery_window) && (
                        <p className="mt-3 text-gray-700 dark:text-gray-300">
                          Scheduled: {viewOrder.intakeHandoff.scheduled_delivery_date || 'Date pending'}
                          {viewOrder.intakeHandoff.delivery_window
                            ? ` · ${formatLogisticsStatus(viewOrder.intakeHandoff.delivery_window)}`
                            : ''}
                        </p>
                      )}
                      {viewOrder.intakeHandoff.events && viewOrder.intakeHandoff.events.length > 0 && (
                        <ol className="mt-4 space-y-2 border-l-2 border-blue-300 pl-4 dark:border-blue-700">
                          {viewOrder.intakeHandoff.events.map((event, index) => (
                            <li key={event.id ?? index}>
                              <p className="font-medium text-gray-900 dark:text-white">
                                {event.label
                                  || event.description
                                  || event.message
                                  || formatLogisticsStatus(event.event_type || event.status)}
                              </p>
                              {(event.occurred_at || event.created_at) && (
                                <p className="text-xs text-gray-500 dark:text-gray-400">
                                  {new Date(event.occurred_at || event.created_at || '').toLocaleString()}
                                </p>
                              )}
                            </li>
                          ))}
                        </ol>
                      )}
                      <button
                        type="button"
                        onClick={() => fetchOrders()}
                        className="mt-4 inline-flex items-center justify-center rounded-md border border-blue-300 bg-white px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 dark:border-blue-700 dark:bg-gray-900 dark:text-blue-300 dark:hover:bg-blue-900/40"
                      >
                        Refresh delivery status
                      </button>
                    </div>
                  </div>
                )}

                {viewOrder.returnHandoff && viewOrder.returnHandoff.visible !== false && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                      {viewOrder.returnHandoff.recovery ? "Return recovery" : "Return handoff"}
                    </p>
                    {viewOrder.returnHandoff.recovery ? (
                      <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-900/20">
                        <p className="font-semibold text-gray-900 dark:text-white">
                          {viewOrder.returnHandoff.recovery.label}
                        </p>
                        <p className="mt-1 text-gray-700 dark:text-gray-300">
                          {viewOrder.returnHandoff.recovery.state === "awaiting_payment"
                            ? "Waiting for the customer to confirm the address and pay the new delivery fee."
                            : viewOrder.returnHandoff.recovery.state === "shop_pickup"
                              ? "Keep the repaired shoes at the shop until the customer collects them."
                              : viewOrder.returnHandoff.recovery.state === "ready_for_dispatch"
                                ? "The re-delivery fee is paid. The Dispatcher can assign the new delivery."
                                : "Customer must choose re-delivery or shop pickup in My Repairs."}
                        </p>
                        {viewOrder.returnHandoff.recovery.state === "shop_pickup" && (
                          <button
                            type="button"
                            onClick={() => handleActivatePickup(viewOrder)}
                            disabled={!viewOrder.returnHandoff.can_release}
                            className="mt-4 min-h-11 w-full rounded-md bg-purple-600 px-4 py-2 font-semibold text-white hover:bg-purple-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600"
                          >
                            {viewOrder.returnHandoff.action_label || "Release to customer"}
                          </button>
                        )}
                      </div>
                    ) : (
                    <div className="rounded-lg border border-purple-200 bg-purple-50 p-4 text-sm dark:border-purple-800 dark:bg-purple-900/20">
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                          <p className="font-semibold text-gray-900 dark:text-white">
                            {formatReturnDeliveryMethod(viewOrder)}
                          </p>
                          {viewOrder.returnHandoff.blocked_reason && !viewOrder.returnHandoff.can_release && (
                            <p className="mt-1 text-amber-700 dark:text-amber-300">
                              {viewOrder.returnHandoff.blocked_reason}
                            </p>
                          )}
                        </div>
                        <button
                          type="button"
                          onClick={() => handleActivatePickup(viewOrder)}
                          disabled={!viewOrder.returnHandoff.can_release}
                          aria-label={viewOrder.returnHandoff.action_label || 'Record return handoff'}
                          className={`rounded-md px-3 py-2 font-semibold ${
                            viewOrder.returnHandoff.can_release
                              ? 'bg-purple-600 text-white hover:bg-purple-700'
                              : 'cursor-not-allowed bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                          }`}
                        >
                          {viewOrder.returnHandoff.action_label || 'Record return handoff'}
                        </button>
                      </div>

                      {viewOrder.returnDeliveryMethod === 'customer_pickup' && (
                        <div className="mt-4 rounded-lg border border-purple-100 bg-white p-3 dark:border-purple-900 dark:bg-gray-900">
                          <p className="font-semibold text-gray-900 dark:text-white">Customer courier tracking</p>
                          {viewOrder.returnHandoff.external_tracking?.tracking_number ? (
                            <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                              <div>
                                <dt className="text-xs text-gray-500 dark:text-gray-400">Carrier</dt>
                                <dd className="font-medium text-gray-900 dark:text-white">
                                  {viewOrder.returnHandoff.external_tracking.carrier || 'Not provided'}
                                </dd>
                              </div>
                              <div>
                                <dt className="text-xs text-gray-500 dark:text-gray-400">Tracking number</dt>
                                <dd className="font-medium text-gray-900 dark:text-white">
                                  {viewOrder.returnHandoff.external_tracking.tracking_number}
                                </dd>
                              </div>
                              {viewOrder.returnHandoff.external_tracking.tracking_url && (
                                <div className="sm:col-span-2">
                                  <a
                                    href={viewOrder.returnHandoff.external_tracking.tracking_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-semibold text-purple-700 underline dark:text-purple-300"
                                  >
                                    Open courier tracking
                                  </a>
                                </div>
                              )}
                            </dl>
                          ) : (
                            <p className="mt-2 text-gray-600 dark:text-gray-300">
                              The customer has not provided tracking details yet.
                            </p>
                          )}
                        </div>
                      )}

                      {viewOrder.returnDeliveryMethod === 'shop_delivery' && (
                        <dl className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                          <div>
                            <dt className="text-xs text-gray-500 dark:text-gray-400">Shipment</dt>
                            <dd className="font-medium text-gray-900 dark:text-white">
                              {formatLogisticsStatus(viewOrder.returnHandoff.shipment_status)}
                            </dd>
                          </div>
                          <div>
                            <dt className="text-xs text-gray-500 dark:text-gray-400">Delivery leg</dt>
                            <dd className="font-medium text-gray-900 dark:text-white">
                              {formatLogisticsStatus(viewOrder.returnHandoff.leg_status)}
                            </dd>
                          </div>
                          <div>
                            <dt className="text-xs text-gray-500 dark:text-gray-400">Proof</dt>
                            <dd className="font-medium text-gray-900 dark:text-white">
                              {formatLogisticsStatus(viewOrder.returnHandoff.proof_status)}
                            </dd>
                          </div>
                        </dl>
                      )}
                    </div>
                    )}
                  </div>
                )}

                {/* Selected Services */}
                {viewOrder.selectedServices && viewOrder.selectedServices.length > 0 && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Selected Services</p>
                    <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4">
                      <ul className="space-y-2">
                        {viewOrder.selectedServices.map((service, index) => {
                          const name = typeof service === "string" ? service : service.name;
                          const price = typeof service === "string" ? undefined : service.price;
                          return (
                            <li key={`${name}-${index}`} className="flex items-center justify-between gap-3">
                              <span className="text-sm text-gray-700 dark:text-gray-300">{name}</span>
                              {price && <span className="text-sm font-medium text-gray-900 dark:text-white">{price}</span>}
                            </li>
                          );
                        })}
                      </ul>
                    </div>
                  </div>
                )}

                {canTrackMaterials(viewOrder.status) && (
                <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                  <div className="flex items-center justify-between mb-2">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Materials Used</p>
                  </div>

                  <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 space-y-3">
                    <div className="rounded-lg border border-blue-200 bg-blue-50/80 px-3 py-2 dark:border-blue-800 dark:bg-blue-900/20">
                      <p className="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300 mb-2">
                        Planned From Templates
                      </p>

                      {isMaterialsLoading ? (
                        <p className="text-xs text-blue-700 dark:text-blue-300">Loading material plan...</p>
                      ) : materialPlanItems.length > 0 ? (
                        <div className="space-y-2">
                          {materialPlanItems.map((planItem) => {
                            const remaining = Number(planItem.remaining_quantity ?? 0);
                            const suggestedQty = Math.max(
                              1,
                              Math.ceil(remaining > 0 ? remaining : Number(planItem.planned_quantity ?? 1)),
                            );

                            return (
                              <div
                                key={planItem.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-blue-100 bg-white px-2 py-2 dark:border-blue-900 dark:bg-gray-900"
                              >
                                <div className="min-w-0">
                                  <p className="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {planItem.inventory_item?.name || `Material #${planItem.inventory_item_id}`}
                                  </p>
                                  <p className="text-xs text-gray-600 dark:text-gray-400">
                                    Planned: {planItem.planned_quantity} • Logged: {planItem.actual_quantity} • Remaining: {planItem.remaining_quantity}
                                  </p>
                                </div>
                                <button
                                  type="button"
                                  onClick={() =>
                                    setMaterialForm((prev) => ({
                                      ...prev,
                                      inventory_item_id: String(planItem.inventory_item_id),
                                      quantity_used: String(suggestedQty),
                                    }))
                                  }
                                  className="rounded-md bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                >
                                  Use Plan
                                </button>
                              </div>
                            );
                          })}
                        </div>
                      ) : (
                        <p className="text-xs text-blue-700 dark:text-blue-300">
                          No template plan found for this repair yet. Add material templates to the selected package/services, then reopen this modal.
                        </p>
                      )}
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-4 gap-2">
                      <select
                        title="Select material"
                        aria-label="Select material"
                        value={materialForm.inventory_item_id}
                        onChange={(event) => setMaterialForm((prev) => ({ ...prev, inventory_item_id: event.target.value }))}
                        className="md:col-span-2 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm"
                      >
                        <option value="">Select material</option>
                        {availableMaterials.map((material) => (
                          <option key={material.id} value={material.id}>
                            {material.name} ({material.sku || 'N/A'}) — Available: {material.available_quantity}
                          </option>
                        ))}
                      </select>

                      <input
                        type="number"
                        min={0}
                        step={1}
                        placeholder="Qty"
                        value={materialForm.quantity_used}
                        onChange={(event) => setMaterialForm((prev) => ({ ...prev, quantity_used: event.target.value }))}
                        className="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm"
                      />

                      <input
                        type="text"
                        placeholder="Notes"
                        value={materialForm.notes}
                        onChange={(event) => setMaterialForm((prev) => ({ ...prev, notes: event.target.value }))}
                        className="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm"
                      />
                    </div>

                    <div className="flex flex-wrap gap-2">
                      <button
                        type="button"
                        onClick={handleLogMaterialUsage}
                        disabled={!canTrackMaterials(viewOrder.status) || isLoggingMaterialUsage}
                        className={`px-3 py-2 text-sm rounded-lg font-medium transition-colors ${
                          canTrackMaterials(viewOrder.status) && !isLoggingMaterialUsage
                            ? 'bg-blue-600 hover:bg-blue-700 text-white'
                            : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                        }`}
                      >
                        {isLoggingMaterialUsage ? 'Logging...' : 'Log Usage'}
                      </button>

                      <button
                        type="button"
                        onClick={handleRequestMaterialFromOrder}
                        className="px-3 py-2 text-sm rounded-lg font-medium border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                      >
                        Request Material
                      </button>
                    </div>

                    {isMaterialsLoading ? (
                      <p className="text-sm text-gray-500">Loading material usage...</p>
                    ) : materialUsages.length === 0 ? (
                      <p className="text-sm text-gray-500">No material usage logged yet.</p>
                    ) : (
                      <div className="space-y-2">
                        {materialUsages.map((usage) => (
                          <div
                            key={usage.id}
                            className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2"
                          >
                            <div className="min-w-0">
                              <p className="text-sm font-medium text-gray-900 dark:text-white truncate">
                                {usage.inventory_item?.name || `Material #${usage.inventory_item_id}`}
                              </p>
                              <p className="text-xs text-gray-500 dark:text-gray-400">
                                Qty: {usage.quantity_used} • {new Date(usage.used_at).toLocaleString()}
                                {usage.notes ? ` • ${usage.notes}` : ''}
                              </p>
                            </div>
                            {canTrackMaterials(viewOrder.status) && (
                              <button
                                type="button"
                                onClick={() => handleRemoveMaterialUsage(usage.id)}
                                className="text-xs px-2 py-1 rounded-md bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-900/60"
                              >
                                Remove
                              </button>
                            )}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
                )}

                {/* Pickup Address */}
                {viewOrder.intakeDeliveryMethod === 'shop_pickup' && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                      🚚 Customer's Collection Address
                    </p>
                    <div className="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
                      {viewOrder.pickupAddressLine || viewOrder.pickupBarangay || viewOrder.pickupCity ? (
                        <div className="space-y-2">
                          {viewOrder.pickupAddressLine && (
                            <div className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.pickupAddressLine}</div>
                          )}
                          {(viewOrder.pickupBarangay || viewOrder.pickupCity) && (
                            <div className="text-sm text-gray-700 dark:text-gray-300">
                              {[viewOrder.pickupBarangay, viewOrder.pickupCity].filter(Boolean).join(", ")}
                            </div>
                          )}
                          {(viewOrder.pickupRegion || viewOrder.pickupPostalCode) && (
                            <div className="text-sm text-gray-700 dark:text-gray-300">
                              {[viewOrder.pickupRegion, viewOrder.pickupPostalCode].filter(Boolean).join(" ")}
                            </div>
                          )}
                        </div>
                      ) : (
                        <div className="text-sm text-gray-500 dark:text-gray-400 italic">
                          No pickup address is available.
                        </div>
                      )}
                    </div>
                    </>
                  </div>
                )}

                {/* Repair Tasks */}
                {viewOrder.repairDetails && viewOrder.repairDetails.length > 0 && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Repair Tasks</p>
                    <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4">
                      <ul className="space-y-2">
                        {viewOrder.repairDetails.map((detail, index) => (
                          <li key={index} className="flex items-start gap-2">
                            <svg className="size-5 text-blue-600 dark:text-blue-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span className="text-sm text-gray-700 dark:text-gray-300">{detail}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  </div>
                )}

                {/* Customer Notes */}
                {viewOrder.notes && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Customer Notes</p>
                    <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                      <div className="flex gap-2">
                        <svg className="size-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p className="text-sm text-gray-700 dark:text-gray-300">{viewOrder.notes}</p>
                      </div>
                    </div>
                  </div>
                )}

                {/* Issue Description */}
                {viewOrder.description && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Description</p>
                    <div className="bg-gray-50 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                      <p className="text-sm text-gray-700 dark:text-gray-300">{viewOrder.description}</p>
                    </div>
                  </div>
                )}
                </div>
              </div>

              {/* Footer */}
              <div className="bg-gray-50 dark:bg-gray-900/30 px-4 sm:px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                <div className="mx-auto w-full max-w-6xl flex flex-wrap items-center justify-between gap-3">
                {viewOrder.status === "assigned_to_repairer" && (
                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      onClick={() => handleReviewAction("accept")}
                      className="px-4 py-2 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors"
                    >
                      Accept
                    </button>
                    <button
                      onClick={() => handleReviewAction("reject")}
                      className="px-4 py-2 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors"
                    >
                      Reject
                    </button>
                  </div>
                )}
                {viewOrder.status === "under-review" && (
                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      onClick={() => handleReviewAction("accept")}
                      className="px-4 py-2 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors"
                    >
                      Accept
                    </button>
                    <button
                      onClick={() => handleReviewAction("reject")}
                      className="px-4 py-2 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 font-medium transition-colors"
                    >
                      Reject
                    </button>
                    <button
                      onClick={() => handleReviewAction("message")}
                      className="px-4 py-2 bg-white hover:bg-gray-50 text-gray-900 border border-gray-900 rounded-lg font-medium transition-colors"
                    >
                      Message
                    </button>
                  </div>
                )}
                
                {(viewOrder.status === "owner_approved" ||
                  viewOrder.status === "repairer_accepted" ||
                  viewOrder.status === "waiting_customer_confirmation" ||
                  viewOrder.status === "pending") &&
                  !viewOrder.payment_enabled &&
                  !isPosManualWalkIn(viewOrder) &&
                  !isWarrantyNoChargeOrder(viewOrder) && (
                    <button
                      type="button"
                      onClick={() => handleActivatePayment(String(viewOrder.database_id))}
                      className="rounded-lg bg-amber-600 px-4 py-2.5 font-semibold text-white transition-colors hover:bg-amber-700"
                      aria-label="Activate Payment"
                    >
                      Activate Payment
                    </button>
                  )}

                {viewOrder.intakeHandoff && (viewOrder.status === "pending" || canMarkReceived(viewOrder)) && (
                  <div className="min-w-[16rem] flex-1">
                    <button
                      type="button"
                      onClick={() => handleMarkReceived(viewOrder)}
                      disabled={!canMarkReceived(viewOrder)}
                      className={`w-full rounded-lg px-4 py-2.5 font-semibold transition-colors ${
                        canMarkReceived(viewOrder)
                          ? 'bg-cyan-600 text-white hover:bg-cyan-700'
                          : 'cursor-not-allowed bg-gray-200 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                      }`}
                      aria-label="Confirm physical receipt"
                    >
                      Confirm physical receipt
                    </button>
                    {!canMarkReceived(viewOrder) && (
                      <p className="mt-2 text-sm text-amber-700 dark:text-amber-300" role="status">
                        {getMarkReceivedBlockedMessage(viewOrder)}
                      </p>
                    )}
                  </div>
                )}

                {viewOrder.status === "received" && (
                  <div className="text-sm text-gray-600 dark:text-gray-400">Use action icons in the table row to process this order.</div>
                )}
                {viewOrder.status === "ready-for-pickup" && (
                  <div className="text-sm text-gray-600 dark:text-gray-400">Use action icons in the table row to process this order.</div>
                )}
                {viewOrder.status === "shipped" && (
                  <div className="text-sm text-gray-600 dark:text-gray-400">Use action icons in the table row to process this order.</div>
                )}
                <button
                  onClick={() => {
                    setIsViewModalOpen(false);
                    setViewOrder(null);
                  }}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-medium transition-colors"
                >
                  Close
                </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {enlargedImage && (
          <div className="fixed inset-0 z-[999999] bg-black/80 backdrop-blur-sm flex items-center justify-center p-6">
            <button
              type="button"
              className="absolute inset-0"
              onClick={() => setEnlargedImage(null)}
              aria-label="Close image preview"
            />
            <div className="relative max-w-5xl w-full">
              <img
                src={enlargedImage}
                alt="Enlarged shoe"
                className="w-full max-h-[80vh] object-contain rounded-2xl shadow-2xl"
              />
              <button
                type="button"
                onClick={() => setEnlargedImage(null)}
                className="absolute top-3 right-3 size-9 bg-white/90 hover:bg-white text-gray-900 rounded-full text-sm font-medium shadow flex items-center justify-center"
                aria-label="Close image preview"
              >
                <svg className="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" />
                </svg>
              </button>
            </div>
          </div>
        )}

        {/* Shipping Modal */}
        {isShippingModalOpen && selectedOrder && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col">
              <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <h2 className="text-xl font-bold text-gray-900 dark:text-white">Ship Order</h2>
              </div>
              
              <div className="px-6 py-4 overflow-y-auto flex-1">
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Order ID
                    </label>
                    <p className="text-sm text-gray-900 dark:text-white font-semibold">{selectedOrder.id}</p>
                  </div>
                  
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Customer
                    </label>
                    <p className="text-sm text-gray-900 dark:text-white">{selectedOrder.customer}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">{selectedOrder.email}</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Shipping Address
                    </label>
                    <p className="text-sm text-gray-900 dark:text-white">{getShippingAddress(selectedOrder)}</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Estimated Delivery Date *
                    </label>
                    <select
                      value={etaPreset}
                      onChange={(e) => setEtaPreset(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                      title="Estimated Delivery Date"
                    >
                      <option value="1-2 business days">1-2 business days</option>
                      <option value="1-3 business days">1-3 business days</option>
                      <option value="2-4 business days">2-4 business days</option>
                      <option value="3-6 business days">3-6 business days</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Shipping Business *
                    </label>
                    <select
                      ref={carrierCompanySelectRef}
                      value={carrierCompany}
                      onChange={(e) => setCarrierCompany(e.target.value.trim())}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                      title="Shipping Business"
                      required
                    >
                      <option value="" disabled>Select shipping business</option>
                      <option value="Lalamove">Lalamove</option>
                      <option value="J&T">J&amp;T</option>
                      <option value="Express Padala">Express Padala</option>
                    </select>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Rider Name *
                      </label>
                      <input
                        type="text"
                        value={carrierName}
                        onChange={(e) => setCarrierName(e.target.value)}
                        aria-label="Rider Name"
                        title="Rider Name"
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Rider Phone *
                      </label>
                      <input
                        type="tel"
                        value={carrierPhone}
                        onChange={(e) => {
                          const digits = e.target.value.replace(/\D/g, '').slice(0, 11);
                          setCarrierPhone(digits);
                        }}
                        maxLength={11}
                        aria-label="Rider Phone"
                        title="Rider Phone"
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Tracking Number *
                    </label>
                    <input
                      type="text"
                      value={trackingNumber}
                      onChange={(e) => setTrackingNumber(e.target.value.replace(/\D/g, ''))}
                      inputMode="numeric"
                      pattern="[0-9]*"
                      aria-label="Tracking Number"
                      title="Tracking Number"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <p className="text-xs text-gray-500 mt-1">Record tracking number from the courier (numbers only). This field is required before confirming shipping.</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Tracking Link *
                    </label>
                    <input
                      type="url"
                      value={trackingLink}
                      onChange={(e) => setTrackingLink(e.target.value)}
                      aria-label="Tracking Link"
                      title="Tracking Link"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <p className="text-xs text-gray-500 mt-1">Provide the tracking link so customers can track delivery in real time.</p>
                  </div>
                </div>
              </div>

              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex-shrink-0 bg-gray-50 dark:bg-gray-900/30 flex items-center justify-end gap-3">
                <button
                  onClick={() => {
                    setIsShippingModalOpen(false);
                    setSelectedOrder(null);
                    setEtaPreset("1-2 business days");
                    setCarrierCompany("");
                    setCarrierName("");
                    setCarrierPhone("");
                    setTrackingNumber("");
                    setTrackingLink("");
                  }}
                  className="px-4 py-2 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 font-medium transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={handleConfirmShipping}
                  className="px-4 py-2 text-white bg-blue-600 hover:bg-blue-700 rounded-lg font-medium transition-colors"
                >
                  Confirm Shipping
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Rejection Modal */}
        {isRejectionModalOpen && (
          <div className="fixed inset-0 z-[999999] bg-gray-900/50 flex items-end sm:items-center sm:justify-center p-4">
            <div className="w-full sm:max-w-md bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh] sm:max-h-none overflow-hidden">
              {/* Header */}
              <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex-shrink-0">
                <h2 className="text-xl font-bold text-gray-900 dark:text-white">Reject Request</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">Please provide a reason for rejecting this request</p>
              </div>

              {/* Content */}
              <div className="px-6 py-4 overflow-y-auto flex-1">
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Order ID
                    </label>
                    <p className="text-sm text-gray-900 dark:text-white font-semibold">{(viewOrder ?? selectedOrder)?.id}</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Rejection Reason *
                    </label>
                    <select
                      value={selectedRejectionReason}
                      onChange={(e) => setSelectedRejectionReason(e.target.value)}
                      title="Rejection Reason"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="">-- Select a reason --</option>
                      {rejectionReasons.map((reason) => (
                        <option key={reason} value={reason}>
                          {reason}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Additional Notes (Optional)
                    </label>
                    <textarea
                      value={rejectionReason}
                      onChange={(e) => setRejectionReason(e.target.value)}
                      placeholder="Add any additional details or notes..."
                      rows={4}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                    />
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      {rejectionReason.length} characters
                    </p>
                  </div>
                </div>
              </div>

              {/* Footer */}
              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex-shrink-0 bg-gray-50 dark:bg-gray-900/30 flex items-center justify-end gap-3">
                <button
                  onClick={() => {
                    setIsRejectionModalOpen(false);
                    setSelectedOrder(null);
                    setSelectedRejectionReason("");
                    setRejectionReason("");
                  }}
                  className="px-4 py-2 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 font-medium transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={handleSubmitRejection}
                  className="px-4 py-2 text-white bg-red-600 hover:bg-red-700 rounded-lg font-medium transition-colors"
                >
                  Confirm Rejection
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Refund Review Modal */}
        {isRefundReviewModalOpen && refundReviewItem && refundReviewOrder && (
          <div className="fixed inset-0 z-[999999] bg-gray-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="w-full max-w-3xl bg-white dark:bg-gray-800 rounded-2xl shadow-2xl flex flex-col max-h-[92vh] overflow-hidden">
              <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <h2 className="text-xl font-bold text-gray-900 dark:text-white">Review Refund Request</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">Assess and endorse or reject this request with full context.</p>
              </div>

              <div className="px-6 py-5 overflow-y-auto flex-1 space-y-5">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                  <div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Refund No</p>
                    <p className="font-semibold text-gray-900 dark:text-white mt-1">{refundReviewItem.refund_no || `#${refundReviewItem.id}`}</p>
                  </div>
                  <div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Repair</p>
                    <p className="font-semibold text-gray-900 dark:text-white mt-1">{refundReviewOrder.id}</p>
                  </div>
                  <div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Requested Amount</p>
                    <p className="font-semibold text-gray-900 dark:text-white mt-1">{formatPesoAmount(refundReviewItem.requested_amount) ?? '₱0.00'}</p>
                  </div>
                  <div className="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Reason</p>
                    <p className="font-semibold text-gray-900 dark:text-white mt-1">{humanizeReasonCode(refundReviewItem.reason_code)}</p>
                  </div>
                </div>

                {refundReviewItem.reason_notes && (
                  <div className="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/70 dark:bg-amber-900/20 p-3">
                    <p className="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Customer Notes</p>
                    <p className="text-sm text-amber-900 dark:text-amber-100 mt-1 whitespace-pre-wrap">{refundReviewItem.reason_notes}</p>
                  </div>
                )}

                <div>
                  <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Evidence</p>
                  {refundReviewItem.evidence_media.length > 0 ? (
                    <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                      {refundReviewItem.evidence_media.map((mediaUrl, index) => (
                        <a
                          key={`${mediaUrl}-${index}`}
                          href={mediaUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="group relative rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-900"
                          title="Open evidence"
                        >
                          <img
                            src={mediaUrl}
                            alt={`Refund evidence ${index + 1}`}
                            className="h-28 w-full object-cover group-hover:scale-105 transition-transform"
                            onError={(event) => {
                              const target = event.currentTarget;
                              target.style.display = 'none';
                              const fallback = target.nextElementSibling as HTMLElement | null;
                              if (fallback) fallback.style.display = 'flex';
                            }}
                          />
                          <div className="hidden h-28 w-full items-center justify-center px-2 text-xs text-gray-700 dark:text-gray-300 text-center">
                            Open Attachment
                          </div>
                        </a>
                      ))}
                    </div>
                  ) : (
                    <div className="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 px-4 py-5 text-sm text-gray-500 dark:text-gray-400">
                      No evidence attached to this refund request.
                    </div>
                  )}
                </div>

                <div className="rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-4">
                  <div className="inline-flex rounded-lg p-1 bg-gray-100 dark:bg-gray-900">
                    <button
                      type="button"
                      onClick={() => setRefundReviewMode('approve')}
                      className={`px-4 py-2 text-sm font-medium rounded-md transition-colors ${refundReviewMode === 'approve' ? 'bg-emerald-600 text-white' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-800'}`}
                    >
                      Approve
                    </button>
                    <button
                      type="button"
                      onClick={() => setRefundReviewMode('reject')}
                      className={`px-4 py-2 text-sm font-medium rounded-md transition-colors ${refundReviewMode === 'reject' ? 'bg-red-600 text-white' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-800'}`}
                    >
                      Reject
                    </button>
                  </div>

                  {refundReviewMode === 'approve' ? (
                    <div className="space-y-3">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Approved Amount *</label>
                        <input
                          type="number"
                          min="0.01"
                          step="0.01"
                          value={refundApprovedAmountInput}
                          onChange={(event) => setRefundApprovedAmountInput(event.target.value)}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
                          placeholder="Enter approved amount"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Assessment Note *</label>
                        <textarea
                          value={refundAssessmentNoteInput}
                          onChange={(event) => setRefundAssessmentNoteInput(event.target.value)}
                          rows={4}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white resize-none"
                          placeholder="Explain your assessment before endorsing to finance"
                        />
                      </div>
                    </div>
                  ) : (
                    <div className="space-y-3">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Rejection Reason *</label>
                        <textarea
                          value={refundRejectionReasonInput}
                          onChange={(event) => setRefundRejectionReasonInput(event.target.value)}
                          rows={3}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white resize-none"
                          placeholder="State why this refund request is being rejected"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Assessment Note *</label>
                        <textarea
                          value={refundAssessmentNoteInput}
                          onChange={(event) => setRefundAssessmentNoteInput(event.target.value)}
                          rows={4}
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white resize-none"
                          placeholder="Add internal assessment context"
                        />
                      </div>
                    </div>
                  )}
                </div>
              </div>

              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30 shrink-0 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={closeRefundReviewModal}
                  disabled={isRefundReviewSubmitting}
                  className="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium"
                >
                  Close
                </button>
                <button
                  type="button"
                  onClick={submitRefundReview}
                  disabled={isRefundReviewSubmitting}
                  className={`px-4 py-2 rounded-lg text-white font-medium ${refundReviewMode === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-red-600 hover:bg-red-700'} disabled:opacity-60 disabled:cursor-not-allowed`}
                >
                  {isRefundReviewSubmitting
                    ? 'Submitting...'
                    : refundReviewMode === 'approve'
                      ? 'Approve and Endorse'
                      : 'Reject Request'}
                </button>
              </div>
            </div>
          </div>
        )}

      </div>
    </AppLayoutERP>
  );
}
