import React, { useMemo, useState } from "react";
import Swal from "sweetalert2";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";
import ErrorModal from "../../../../components/common/ErrorModal";
import axios from "axios";

type OrderItem = {
  id: number;
  product_id: number | null;
  product_name: string;
  product_slug: string;
  product_image: string | null;
  price: string;
  quantity: number;
  subtotal: string;
  size: string | null;
  color: string | null;
};

type Order = {
  id: number;
  order_number: string;
  customer: string;
  email: string;
  phone: string;
  shippingAddress: string;
  total_amount: number;
  shipping_fee: number;
  vat_amount: number | null;
  vat_rate: number | null;
  grand_total: number;
  paymentStatus: string;
  paymentMethod?: string;
  status: "pending" | "processing" | "shipped" | "delivered" | "cancelled" | "refund";
  cancellation_reason?: string | null;
  cancellation_note?: string | null;
  cancellation_other_reason_note?: string | null;
  eta?: string;
  orderedAt: string;
  processedAt?: string;
  shippedAt?: string;
  carrierCompany?: string;
  carrierName?: string;
  carrierPhone?: string;
  trackingNumber?: string;
  trackingLink?: string;
  items: OrderItem[];
  quantity: number;
  shopName?: string;
  product?: string;
  pickup_enabled?: boolean;
  pickup_enabled_at?: string | null;
  latest_refund?: {
    id: number;
    status: string;
    reason_code?: string | null;
    reason_note?: string | null;
    other_reason_note?: string | null;
    shop_owner_status: string;
    finance_status: string;
    return_status: string;
    return_source?: string;
    customer_return_tracking_number?: string | null;
    customer_return_carrier?: string | null;
    customer_return_rider_name?: string | null;
    customer_return_rider_phone?: string | null;
    customer_return_tracking_link?: string | null;
    customer_return_shipped_at?: string | null;
    staff_return_tracking_number?: string | null;
    staff_return_carrier?: string | null;
    staff_return_rider_name?: string | null;
    staff_return_rider_phone?: string | null;
    staff_return_tracking_link?: string | null;
    staff_return_shipped_at?: string | null;
    return_arranged_by_staff_at?: string | null;
    return_confirmed_at?: string | null;
    refund_executed_at?: string | null;
    rejection_reason?: string | null;
    flow_type?: string;
  } | null;
  shippingAddressLine?: string;
  shippingBarangay?: string;
  shippingCity?: string;
  shippingProvince?: string;
  shippingRegion?: string;
  shippingPostalCode?: string;
};

const parseAmount = (value: unknown): number => {
  const parsed = Number.parseFloat(String(value ?? 0).replace(/[^0-9.-]/g, ""));
  return Number.isFinite(parsed) ? parsed : 0;
};

const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;")
    .replace(/'/g, "&#39;");
const buildPickupAddressHtml = (order: Order): string => {
  const street = String(order.shippingAddressLine || '').trim();
  const barangay = String(order.shippingBarangay || '').trim();
  const city = String(order.shippingCity || '').trim();
  const province = String(order.shippingProvince || '').trim();
  const region = String(order.shippingRegion || '').trim();
  const postalCode = String(order.shippingPostalCode || '').trim();

  const hasStructured = [street, barangay, city, province, region, postalCode].some((part) => part !== '');

  if (hasStructured) {
    const rows = [
      { label: 'Street / Building', value: street },
      { label: 'Barangay / Landmark', value: barangay },
      { label: 'City', value: city },
      { label: 'Province', value: province },
      { label: 'Region', value: region },
      { label: 'Postal Code', value: postalCode },
    ].filter((row) => row.value !== '');

    return rows
      .map((row) => `<div style="display:flex;gap:8px;align-items:flex-start;"><span style="min-width:130px;font-size:12px;color:#64748b;">${escapeHtml(row.label)}:</span><span style="font-size:13px;color:#111827;font-weight:600;line-height:1.35;">${escapeHtml(row.value)}</span></div>`)
      .join('');
  }

  const fallbackAddress = String(order.shippingAddress || '').trim() || 'No shipping address found on this order.';
  return `<div style="font-size:13px;color:#111827;font-weight:600;line-height:1.4;">${escapeHtml(fallbackAddress)}</div>`;
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
const ClipboardListIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
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

const CalendarIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

const MagnifyingGlassIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
  </svg>
);

const FunnelIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
  </svg>
);

const ArrowDownTrayIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
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

const PencilIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
  </svg>
);

const TrashIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

const MinusIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
  </svg>
);

const PlusIcon: React.FC<{ className?: string }> = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
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

export default function JobOrdersPage() {
  const { auth } = usePage().props as any;
  const shopOwnerRegistrationType = String(auth?.shop_owner?.registration_type || auth?.registration_type || '').toLowerCase();
  const isIndividualRegistration = shopOwnerRegistrationType !== 'company';

  const [error, setError] = useState<string | null>(null);
  const [selectedTab, setSelectedTab] = useState<string>("pending");
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedOrders, setSelectedOrders] = useState<number[]>([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [isShippingModalOpen, setIsShippingModalOpen] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [viewOrder, setViewOrder] = useState<Order | null>(null);
  const [eta, setEta] = useState("");
  const [etaPreset, setEtaPreset] = useState("");
  const [carrierCompany, setCarrierCompany] = useState("");
  const [carrierName, setCarrierName] = useState("");
  const [carrierPhone, setCarrierPhone] = useState("");
  const [trackingNumber, setTrackingNumber] = useState("");
  const [trackingLink, setTrackingLink] = useState("");
  const [isConfirmingShipping, setIsConfirmingShipping] = useState(false);
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const itemsPerPage = 10;

  // Fetch orders from API on mount
  React.useEffect(() => {
    const fetchOrders = async () => {
      try {
        setLoading(true);
        const response = await fetch('/api/shop-owner/orders', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
          }
        });
        
        if (!response.ok) {
          throw new Error('Failed to fetch orders');
        }
        
        const data = await response.json();
        const ordersData = Array.isArray(data) ? data : (data.data || []);
        
        // Map API data to Order type
        const mappedOrders: Order[] = ordersData.map((order: any) => {
          const itemSubtotal = parseAmount(order.total_amount);
          const shippingFee = parseAmount(order.shipping_fee);
          const hasStoredVat = order.vat_amount !== null && order.vat_amount !== undefined;
          const rawVatRate = Number(order.vat_rate);
          const vatRate = hasStoredVat && Number.isFinite(rawVatRate) && rawVatRate >= 0 ? rawVatRate : null;
          const rawVatAmount = Number(order.vat_amount);
          const vatAmount = hasStoredVat && Number.isFinite(rawVatAmount) && rawVatAmount >= 0
            ? rawVatAmount
            : null;
          const grandTotal = parseAmount(order.grand_total || itemSubtotal + shippingFee + (vatAmount ?? 0));

          return {
            id: order.id,
            order_number: order.order_number,
            customer: order.customer_name || 'Unknown',
            email: order.customer_email || '',
            phone: order.customer_phone || '',
            shippingAddress: order.shipping_address || '',
            shippingAddressLine: order.shipping_address_line || '',
            shippingBarangay: order.shipping_barangay || '',
            shippingCity: order.shipping_city || '',
            shippingProvince: order.shipping_province || '',
            shippingRegion: order.shipping_region || '',
            shippingPostalCode: order.shipping_postal_code || '',
            total_amount: itemSubtotal,
            shipping_fee: shippingFee,
            vat_amount: vatAmount,
            vat_rate: vatRate,
            grand_total: grandTotal,
            paymentStatus: order.payment_status || 'pending',
            paymentMethod: order.payment_method || '',
            status: order.status as any,
            cancellation_reason: order.cancellation_reason || null,
            cancellation_note: order.cancellation_note || null,
            cancellation_other_reason_note: order.cancellation_other_reason_note || null,
            eta: order.eta || undefined,
            orderedAt: new Date(order.created_at).toLocaleString(),
            carrierCompany: order.carrier_company || undefined,
            carrierName: order.carrier_name || undefined,
            carrierPhone: order.carrier_phone || undefined,
            trackingNumber: order.tracking_number || undefined,
            trackingLink: order.tracking_link || undefined,
            items: order.items || [],
            quantity: order.items ? order.items.reduce((sum: number, item: any) => sum + (item.quantity || 0), 0) : 0,
            shopName: order.shop?.shop_name || undefined,
            product: order.items && order.items.length > 0 ? order.items[0].product_name : '',
            pickup_enabled: order.pickup_enabled || false,
            pickup_enabled_at: order.pickup_enabled_at || null,
            latest_refund: order.latest_refund || null,
          };
        });
        
        setOrders(mappedOrders);
      } catch (error) {
        console.error('Error fetching orders:', error);
        await Swal.fire({
          title: 'Error',
          text: 'Failed to load orders. Please refresh the page.',
          icon: 'error',
          confirmButtonColor: '#2563eb'
        });
      } finally {
        setLoading(false);
      }
    };
    
    fetchOrders();
  }, []);

  const getShippingMessage = () => {
    if (!selectedOrder) return "";
    const etaText = etaPreset || "(ETA not set)";
    const carrierText = carrierCompany ? `${carrierCompany}${carrierName ? ` - ${carrierName}` : ""}` : "(carrier not set)";
    const base = `Thank you for your purchase! We already shipped your item via ${carrierText} and you will receive the item by ${etaText}.`;
    const trackingPart = trackingNumber ? ` Tracking Number: ${trackingNumber}.` : "";
    const linkPart = trackingLink ? ` Track here: ${trackingLink}` : "";
    return `${base}${trackingPart}${linkPart}`;
  };

  // Filter orders based on tab and search
  const filteredOrders = useMemo(() => {
    return orders.filter((order) => {
      const isRefundOrder = order.status === "refund" || String(order.paymentStatus || '').toLowerCase() === 'refunded';
      const matchesTab = selectedTab === "all" || (selectedTab === 'refund' ? isRefundOrder : order.status === selectedTab);
      const matchesSearch =
        String(order.id).toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.customer.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (order.product || '').toLowerCase().includes(searchTerm.toLowerCase());
      return matchesTab && matchesSearch;
    });
  }, [orders, selectedTab, searchTerm]);

  // Pagination
  const totalPages = Math.ceil(filteredOrders.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedOrders = filteredOrders.slice(startIndex, endIndex);

  // Calculate statistics
  const stats = useMemo(() => {
    const total = orders.length;
    const pending = orders.filter(o => o.status === "pending").length;
    const processing = orders.filter(o => o.status === "processing").length;
    const shipped = orders.filter(o => o.status === "shipped").length;
    const delivered = orders.filter(o => o.status === "delivered").length;
    const refund = orders.filter(o => o.status === "refund" || String(o.paymentStatus || '').toLowerCase() === 'refunded').length;
    // Exclude cancelled and refunded orders from recognized revenue.
    const totalRevenue = orders
      .filter(o => o.status !== "cancelled" && o.status !== "refund" && String(o.paymentStatus || '').toLowerCase() !== 'refunded')
      .reduce((sum, o) => sum + o.grand_total, 0);
    return { total, pending, processing, shipped, delivered, refund, totalRevenue };
  }, [orders]);

  const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.checked) {
      setSelectedOrders(paginatedOrders.map((order) => order.id));
    } else {
      setSelectedOrders([]);
    }
  };

  const handleSelectOrder = (id: number) => {
    setSelectedOrders((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      "pending": "bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40",
      "processing": "bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40",
      "shipped": "bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40",
      "delivered": "bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40",
      "cancelled": "bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-700/40",
      "refund": "bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40",
    };
    return colors[status] || "bg-gray-100 text-gray-800 ring-1 ring-inset ring-gray-200";
  };

  const formatStatusLabel = (status: string) => {
    return status.charAt(0).toUpperCase() + status.slice(1);
  };

  const formatOrderTotal = (total: string | number) => {
    if (typeof total === 'number' && Number.isFinite(total)) {
      return `₱${total.toLocaleString()}`;
    }

    const raw = String(total || '').trim();
    const parsed = Number.parseFloat(raw.replace(/[^0-9.-]/g, ''));
    return Number.isFinite(parsed) ? `₱${parsed.toLocaleString()}` : raw;
  };

  const resolveOrderItemColor = (order: Pick<Order, 'items'>, item: Pick<OrderItem, 'product_name' | 'color'>) => {
    const explicitColor = String(item.color || '').trim();
    if (explicitColor) return explicitColor;

    const sameProductColors = (order.items || [])
      .filter((line) => String(line.product_name || '').trim().toLowerCase() === String(item.product_name || '').trim().toLowerCase())
      .map((line) => String(line.color || '').trim())
      .filter(Boolean);

    const uniqueColors = Array.from(new Set(sameProductColors));
    return uniqueColors.length === 1 ? uniqueColors[0] : '';
  };

  const formatItemSizeColor = (order: Pick<Order, 'items'>, item: Pick<OrderItem, 'size' | 'color' | 'product_name'>) => {
    const size = String(item.size || '').trim();
    const color = resolveOrderItemColor(order, item);

    if (size && color) return `${size} / ${color}`;
    if (size) return size;
    if (color) return `Color: ${color}`;
    return '-';
  };

  const formatOrderSizeColor = (order: Pick<Order, 'items'>) => {
    if (!order.items || order.items.length === 0) return '-';
    return order.items.map((item) => formatItemSizeColor(order, item)).join(', ');
  };

  const isCodOrder = (order: Pick<Order, 'paymentMethod'>) => {
    const normalized = (order.paymentMethod || '').toLowerCase();
    return normalized === 'cod' || normalized === 'cash_on_delivery' || normalized === 'cash on delivery';
  };

  const isOrderPaid = (order: Pick<Order, 'paymentStatus'>) => {
    const normalized = (order.paymentStatus || '').toLowerCase();
    return normalized === 'paid' || normalized === 'completed';
  };

  const getRefundReturnDisplay = (order: Order) => {
    const paymentStatus = String(order.paymentStatus || '').toLowerCase();
    const orderStatus = String(order.status || '').toLowerCase();
    const latestRefund = order.latest_refund;

    if (latestRefund && String(latestRefund.flow_type || '').toLowerCase() === 'request_approval') {
      const refundStatus = String(latestRefund.status || '').toLowerCase();
      const shopOwnerStatus = String(latestRefund.shop_owner_status || '').toLowerCase();
      const financeStatus = String(latestRefund.finance_status || '').toLowerCase();
      const returnStatus = String(latestRefund.return_status || '').toLowerCase();

      if (paymentStatus === 'refunded' || refundStatus === 'succeeded') {
        return {
          label: 'Refunded',
          className: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40',
        };
      }

      if (refundStatus === 'rejected' || shopOwnerStatus === 'rejected' || financeStatus === 'rejected') {
        return {
          label: 'Refund Rejected',
          className: 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-700/40',
        };
      }

      if (returnStatus === 'pending_customer_shipment') {
        return {
          label: 'Awaiting Return Shipment',
          className: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40',
        };
      }

      if (returnStatus === 'pending_staff_pickup') {
        return {
          label: 'Staff Pickup Scheduled',
          className: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40',
        };
      }

      if (returnStatus === 'in_transit') {
        return {
          label: 'Return In Transit',
          className: 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        };
      }

      if (returnStatus === 'received' && financeStatus === 'approved') {
        return {
          label: isIndividualRegistration ? 'Ready for Refund Payout' : 'Ready for Finance Refund',
          className: 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40',
        };
      }

      if (returnStatus === 'received') {
        return {
          label: 'Returned & Received',
          className: 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-900/20 dark:text-sky-300 dark:ring-sky-700/40',
        };
      }

      if (shopOwnerStatus !== 'approved' || financeStatus !== 'approved') {
        return {
          label: 'Awaiting Approval',
          className: 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
        };
      }

      if (refundStatus === 'processing') {
        return {
          label: 'Refund Processing',
          className: 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        };
      }
    }

    if (paymentStatus === 'refunded') {
      return {
        label: 'Refunded',
        className: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40',
      };
    }

    if (orderStatus === 'refund' || orderStatus === 'returned') {
      return {
        label: 'Returned',
        className: 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
      };
    }

    return {
      label: '-',
      className: 'text-gray-500 dark:text-gray-400',
    };
  };

  const handleProcessOrder = async (order: Order) => {
    const result = await Swal.fire({
      title: "Process this order?",
      text: `Process this order for ${order.customer}?`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, process",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#2563eb",
    });

    if (!result.isConfirmed) return;

    try {
      // Fetch fresh CSRF token
      const csrfResponse = await fetch('/api/csrf-token', {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      const csrfData = await csrfResponse.json();
      const csrfToken = csrfData.csrf_token;

      // Call API to update order status
      const response = await fetch(`/api/shop-owner/orders/${order.id}/status`, {
        method: 'PATCH',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ status: 'processing' })
      });

      if (!response.ok) {
        throw new Error('Failed to update order status');
      }

      // Update local state
      setOrders((prev) =>
        prev.map((o) => (o.id === order.id ? { ...o, status: "processing", processedAt: new Date().toLocaleString() } : o))
      );

      await Swal.fire({
        title: "Order processed",
        text: "This order is now Processing.",
        icon: "success",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    } catch (error) {
      await Swal.fire({
        title: "Processing failed",
        text: "Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    }
  };

  const handleShipOrder = (order: Order) => {
    setSelectedOrder(order);
    setEta("");
    setEtaPreset(order.eta || "1-2 business days");
    // Prepopulate if values already exist (view-only for shipped orders)
    setCarrierCompany(order.carrierCompany || "Lalamove");
    setCarrierName(order.carrierName || "");
    setCarrierPhone(order.carrierPhone || "");
    setTrackingNumber(order.trackingNumber || "");
    setTrackingLink(order.trackingLink || "");
    setIsShippingModalOpen(true);
  };

  const handleViewOrder = (order: Order) => {
    setViewOrder(order);
    setIsViewModalOpen(true);
  };

  const canConfirmReturnReceived = (order: Order) => {
    if (!isIndividualRegistration) return false;

    const latestRefund = order.latest_refund;
    if (!latestRefund) return false;

    return String(latestRefund.flow_type || '').toLowerCase() === 'request_approval'
      && ['pending_staff_pickup', 'in_transit'].includes(String(latestRefund.return_status || '').toLowerCase())
      && !['rejected', 'failed', 'succeeded'].includes(String(latestRefund.status || '').toLowerCase());
  };

  const canArrangeReturnPickup = (order: Order) => {
    if (!isIndividualRegistration) return false;

    const latestRefund = order.latest_refund;
    if (!latestRefund) return false;

    const returnStatus = String(latestRefund.return_status || '').toLowerCase();
    const flowType = String(latestRefund.flow_type || '').toLowerCase();
    const isBlocked = ['rejected', 'failed', 'succeeded'].includes(String(latestRefund.status || '').toLowerCase());

    return flowType === 'request_approval'
      && ['pending_customer_shipment', 'pending_staff_pickup'].includes(returnStatus)
      && !isBlocked;
  };

  const handleArrangeReturnPickup = async (order: Order) => {
    const pickupAddressHtml = buildPickupAddressHtml(order);
    const customerName = String(order.customer || 'Customer').trim() || 'Customer';
    const customerPhone = String(order.phone || '').trim() || 'No phone provided';
    const existingPickup = order.latest_refund || null;
    const defaultCarrier = String(existingPickup?.staff_return_carrier || '').trim();
    const defaultRiderName = String(existingPickup?.staff_return_rider_name || '').trim();
    const defaultRiderPhone = String(existingPickup?.staff_return_rider_phone || '').trim();
    const defaultTrackingNumber = String(existingPickup?.staff_return_tracking_number || '').trim();
    const defaultTrackingLink = String(existingPickup?.staff_return_tracking_link || '').trim();

    const shipmentInput = await Swal.fire({
      title: 'Arrange Return Pickup',
      html: `
        <div style="display:grid;gap:14px;text-align:left;">
          <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;background:#f8fafc;display:grid;gap:8px;">
            <p style="margin:0;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#475569;">Order Context</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <div>
                <p style="margin:0;font-size:11px;color:#64748b;">Order #</p>
                <p style="margin:2px 0 0;font-size:13px;font-weight:600;color:#111827;">${escapeHtml(String(order.order_number || '-'))}</p>
              </div>
              <div>
                <p style="margin:0;font-size:11px;color:#64748b;">Customer</p>
                <p style="margin:2px 0 0;font-size:13px;font-weight:600;color:#111827;">${escapeHtml(customerName)}</p>
              </div>
            </div>
            <div>
              <p style="margin:0;font-size:11px;color:#64748b;">Customer Phone</p>
              <p style="margin:2px 0 0;font-size:13px;font-weight:600;color:#111827;">${escapeHtml(customerPhone)}</p>
            </div>
          </div>

          <div style="display:grid;gap:6px;">
            <label style="font-size:13px;font-weight:700;color:#334155;">Customer Pickup Address</label>
            <div style="margin:0;min-height:80px;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;background:#f9fafb;display:grid;gap:6px;">
              ${pickupAddressHtml}
            </div>
          </div>

          <div style="display:grid;gap:10px;">
            <p style="margin:0;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#475569;">Courier Assignment</p>

            <div style="display:grid;gap:6px;">
              <label style="font-size:13px;font-weight:600;color:#334155;">Carrier Company</label>
              <input id="swal-carrier-company" class="swal2-input" placeholder="e.g. J&T, LBC, Ninja Van" value="${escapeHtml(defaultCarrier)}" style="margin:0;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;" />
            </div>

            <div style="display:grid;gap:6px;">
              <label style="font-size:13px;font-weight:600;color:#334155;">Rider Name</label>
              <input id="swal-rider-name" class="swal2-input" placeholder="Rider full name" value="${escapeHtml(defaultRiderName)}" style="margin:0;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;" />
            </div>

            <div style="display:grid;gap:6px;">
              <label style="font-size:13px;font-weight:600;color:#334155;">Rider Number</label>
              <input id="swal-rider-phone" class="swal2-input" placeholder="09XXXXXXXXX" value="${escapeHtml(defaultRiderPhone)}" inputmode="numeric" pattern="[0-9]*" maxlength="15" style="margin:0;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;" />
            </div>

            <div style="display:grid;gap:6px;">
              <label style="font-size:13px;font-weight:600;color:#334155;">Tracking Number</label>
              <input id="swal-tracking-number" class="swal2-input" placeholder="Tracking number" value="${escapeHtml(defaultTrackingNumber)}" style="margin:0;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;" />
            </div>

            <div style="display:grid;gap:6px;">
              <label style="font-size:13px;font-weight:600;color:#334155;">Tracking Link</label>
              <input id="swal-tracking-link" class="swal2-input" placeholder="https://..." value="${escapeHtml(defaultTrackingLink)}" style="margin:0;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;" />
            </div>
          </div>

          <p style="margin:0;font-size:12px;color:#64748b;">Tip: You can save initial rider details now, then update later with final tracking status.</p>
        </div>
      `,
      showCancelButton: true,
      showCloseButton: true,
      width: 640,
      confirmButtonText: 'Save Pickup',
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#6b7280',
      focusConfirm: false,
      didOpen: () => {
        const riderPhoneInput = document.getElementById('swal-rider-phone') as HTMLInputElement | null;
        if (!riderPhoneInput) return;

        riderPhoneInput.addEventListener('input', () => {
          riderPhoneInput.value = riderPhoneInput.value.replace(/\D/g, '');
        });

        const carrierInput = document.getElementById('swal-carrier-company') as HTMLInputElement | null;
        if (carrierInput) {
          carrierInput.focus();
        }
      },
      preConfirm: () => {
        const carrierCompany = (document.getElementById('swal-carrier-company') as HTMLInputElement | null)?.value?.trim() || '';
        const riderName = (document.getElementById('swal-rider-name') as HTMLInputElement | null)?.value?.trim() || '';
        const riderPhone = (document.getElementById('swal-rider-phone') as HTMLInputElement | null)?.value?.trim() || '';
        const trackingNumber = (document.getElementById('swal-tracking-number') as HTMLInputElement | null)?.value?.trim() || '';
        const trackingLink = (document.getElementById('swal-tracking-link') as HTMLInputElement | null)?.value?.trim() || '';

        if (!carrierCompany || !riderName || !riderPhone || !trackingNumber || !trackingLink) {
          Swal.showValidationMessage('Please complete all pickup details.');
          return null;
        }

        if (!/^\d+$/.test(riderPhone)) {
          Swal.showValidationMessage('Rider number must contain numbers only.');
          return null;
        }

        try {
          new URL(trackingLink);
        } catch {
          Swal.showValidationMessage('Tracking link must be a valid URL.');
          return null;
        }

        return {
          carrierCompany,
          riderName,
          riderPhone,
          trackingNumber,
          trackingLink,
        };
      },
    });

    if (!shipmentInput.isConfirmed || !shipmentInput.value) return;

    try {
      const csrfResponse = await fetch('/api/csrf-token', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      const csrfData = await csrfResponse.json();

      const response = await fetch(`/api/shop-owner/orders/${order.id}/arrange-return-pickup`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfData.csrf_token,
        },
        body: JSON.stringify({
          tracking_number: shipmentInput.value.trackingNumber,
          carrier_company: shipmentInput.value.carrierCompany,
          rider_name: shipmentInput.value.riderName,
          rider_phone: shipmentInput.value.riderPhone,
          tracking_link: shipmentInput.value.trackingLink,
        }),
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data?.message || 'Unable to arrange return pickup.');
      }

      const ordersResponse = await fetch('/api/shop-owner/orders', {
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
        }
      });

      if (ordersResponse.ok) {
        const refreshed = await ordersResponse.json();
        const payload = Array.isArray(refreshed) ? refreshed : (refreshed.data || []);
        const mappedOrders: Order[] = payload.map((item: any) => {
          const itemSubtotal = parseAmount(item.total_amount);
          const shippingFee = parseAmount(item.shipping_fee);
          const hasStoredVat = item.vat_amount !== null && item.vat_amount !== undefined;
          const rawVatRate = Number(item.vat_rate);
          const vatRate = hasStoredVat && Number.isFinite(rawVatRate) && rawVatRate >= 0 ? rawVatRate : null;
          const rawVatAmount = Number(item.vat_amount);
          const vatAmount = hasStoredVat && Number.isFinite(rawVatAmount) && rawVatAmount >= 0
            ? rawVatAmount
            : null;
          const grandTotal = parseAmount(item.grand_total || itemSubtotal + shippingFee + (vatAmount ?? 0));

          return {
            id: item.id,
            order_number: item.order_number,
            customer: item.customer_name || 'Unknown',
            email: item.customer_email || '',
            phone: item.customer_phone || '',
            shippingAddress: item.shipping_address || '',
            total_amount: itemSubtotal,
            shipping_fee: shippingFee,
            vat_amount: vatAmount,
            vat_rate: vatRate,
            grand_total: grandTotal,
            paymentStatus: item.payment_status || 'pending',
            paymentMethod: item.payment_method || '',
            status: item.status as any,
            cancellation_reason: item.cancellation_reason || null,
            cancellation_note: item.cancellation_note || null,
            cancellation_other_reason_note: item.cancellation_other_reason_note || null,
            orderedAt: new Date(item.created_at).toLocaleString(),
            items: item.items || [],
            quantity: item.items?.reduce((sum: number, row: any) => sum + row.quantity, 0) || 0,
            product: item.items?.[0]?.product_name || '',
            carrierCompany: item.carrier_company || undefined,
            carrierName: item.carrier_name || undefined,
            carrierPhone: item.carrier_phone || undefined,
            trackingNumber: item.tracking_number || undefined,
            trackingLink: item.tracking_link || undefined,
            eta: item.eta || undefined,
            pickup_enabled: item.pickup_enabled || false,
            pickup_enabled_at: item.pickup_enabled_at || null,
            latest_refund: item.latest_refund || null,
          };
        });

        setOrders(mappedOrders);
      }

      await Swal.fire('Pickup Arranged', data?.message || 'Return pickup details were saved successfully.', 'success');
    } catch (error) {
      await Swal.fire({
        title: 'Failed',
        text: error instanceof Error ? error.message : 'Unable to arrange return pickup.',
        icon: 'error',
        confirmButtonColor: '#2563eb',
      });
    }
  };

  const handleConfirmReturnReceived = async (order: Order) => {
    const result = await Swal.fire({
      title: 'Confirm Returned Item Received?',
      text: `Mark returned item for order ${order.order_number} as received?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, confirm',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
    });

    if (!result.isConfirmed) return;

    try {
      const csrfResponse = await fetch('/api/csrf-token', {
        credentials: 'include',
        headers: { 'Accept': 'application/json' },
      });
      const csrfData = await csrfResponse.json();
      const csrfToken = csrfData.csrf_token;

      const response = await fetch(`/api/shop-owner/orders/${order.id}/confirm-return-received`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({}),
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data?.message || 'Unable to confirm returned item.');
      }

      const ordersResponse = await fetch('/api/shop-owner/orders', {
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
        }
      });

      if (ordersResponse.ok) {
        const refreshed = await ordersResponse.json();
        const payload = Array.isArray(refreshed) ? refreshed : (refreshed.data || []);
        const mappedOrders: Order[] = payload.map((item: any) => {
          const itemSubtotal = parseAmount(item.total_amount);
          const shippingFee = parseAmount(item.shipping_fee);
          const hasStoredVat = item.vat_amount !== null && item.vat_amount !== undefined;
          const rawVatRate = Number(item.vat_rate);
          const vatRate = hasStoredVat && Number.isFinite(rawVatRate) && rawVatRate >= 0 ? rawVatRate : null;
          const rawVatAmount = Number(item.vat_amount);
          const vatAmount = hasStoredVat && Number.isFinite(rawVatAmount) && rawVatAmount >= 0
            ? rawVatAmount
            : null;
          const grandTotal = parseAmount(item.grand_total || itemSubtotal + shippingFee + (vatAmount ?? 0));

          return {
            id: item.id,
            order_number: item.order_number,
            customer: item.customer_name || 'Unknown',
            email: item.customer_email || '',
            phone: item.customer_phone || '',
            shippingAddress: item.shipping_address || '',
            total_amount: itemSubtotal,
            shipping_fee: shippingFee,
            vat_amount: vatAmount,
            vat_rate: vatRate,
            grand_total: grandTotal,
            paymentStatus: item.payment_status || 'pending',
            paymentMethod: item.payment_method || '',
            status: item.status as any,
            cancellation_reason: item.cancellation_reason || null,
            cancellation_note: item.cancellation_note || null,
            cancellation_other_reason_note: item.cancellation_other_reason_note || null,
            orderedAt: new Date(item.created_at).toLocaleString(),
            items: item.items || [],
            quantity: item.items?.reduce((sum: number, row: any) => sum + row.quantity, 0) || 0,
            product: item.items?.[0]?.product_name || '',
            carrierCompany: item.carrier_company || undefined,
            carrierName: item.carrier_name || undefined,
            carrierPhone: item.carrier_phone || undefined,
            trackingNumber: item.tracking_number || undefined,
            trackingLink: item.tracking_link || undefined,
            eta: item.eta || undefined,
            pickup_enabled: item.pickup_enabled || false,
            pickup_enabled_at: item.pickup_enabled_at || null,
            latest_refund: item.latest_refund || null,
          };
        });

        setOrders(mappedOrders);
      }

      await Swal.fire('Confirmed', data?.message || 'Returned item marked as received.', 'success');
    } catch (error) {
      await Swal.fire({
        title: 'Failed',
        text: error instanceof Error ? error.message : 'Unable to confirm returned item.',
        icon: 'error',
        confirmButtonColor: '#2563eb',
      });
    }
  };

  // Helper function to convert ETA preset to actual date
  const calculateEtaDate = (preset: string): string => {
    const today = new Date();
    let daysToAdd = 3; // default
    
    // Extract the maximum days from the preset string
    if (preset.includes("1-2")) daysToAdd = 2;
    else if (preset.includes("1-3")) daysToAdd = 3;
    else if (preset.includes("2-4")) daysToAdd = 4;
    else if (preset.includes("3-6")) daysToAdd = 6;
    
    // Add days (skip weekends for business days calculation)
    let currentDate = new Date(today);
    let addedDays = 0;
    
    while (addedDays < daysToAdd) {
      currentDate.setDate(currentDate.getDate() + 1);
      // Skip weekends (0 = Sunday, 6 = Saturday)
      if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) {
        addedDays++;
      }
    }
    
    // Format as YYYY-MM-DD
    return currentDate.toISOString().split('T')[0];
  };

  const handleConfirmShipping = async (e?: React.MouseEvent<HTMLButtonElement>) => {
    e?.preventDefault();
    e?.stopPropagation();
    
    if (!selectedOrder) return;

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

    if (!carrierCompany) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please select a Carrier Business",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!carrierName) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter the Carrier Name",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    if (!carrierPhone) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter the Carrier Phone Number",
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

    const trackingLinkValue = trackingLink.trim();

    if (!trackingLinkValue) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter a Tracking Link",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      new URL(trackingLinkValue);
    } catch {
      await Swal.fire({
        title: "Invalid Tracking Link",
        text: "Please enter a valid URL (include https://)",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      setIsConfirmingShipping(true);

      // Fetch fresh CSRF token
      const csrfResponse = await fetch('/api/csrf-token', {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      
      if (!csrfResponse.ok) {
        throw new Error('Failed to get CSRF token');
      }
      
      const csrfData = await csrfResponse.json();
      const csrfToken = csrfData.csrf_token;

      // Calculate actual ETA date from preset
      const etaDate = calculateEtaDate(etaPreset);

      // Call API to update order status with shipping info
      const response = await fetch(`/api/shop-owner/orders/${selectedOrder.id}/status`, {
        method: 'PATCH',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
          status: 'shipped',
          tracking_number: normalizedTrackingNumber,
          carrier_company: carrierCompany,
          carrier_name: carrierName,
          carrier_phone: carrierPhone,
          tracking_link: trackingLinkValue,
          eta: etaDate,
        })
      });

      const responseText = await response.text();
  
      if (!response.ok) {
        // Try to parse as JSON, fallback to text
        let errorMessage = 'Failed to update order shipping information';
        try {
          const errorData = JSON.parse(responseText);
          errorMessage = errorData.message || errorMessage;
        } catch {
          errorMessage = responseText || errorMessage;
        }
        throw new Error(errorMessage);
      }
      
      // Parse the successful response
      const data = JSON.parse(responseText);

      // Refresh orders list from server to ensure we have the latest data
      try {
        const ordersResponse = await fetch('/api/shop-owner/orders', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
          }
        });
        
        if (ordersResponse.ok) {
          const ordersData = await ordersResponse.json();
          const payload = Array.isArray(ordersData) ? ordersData : (ordersData.data || []);
          const mappedOrders: Order[] = payload.map((order: any) => {
            const itemSubtotal = parseAmount(order.total_amount);
            const shippingFee = parseAmount(order.shipping_fee);
            const grandTotal = parseAmount(order.grand_total || itemSubtotal + shippingFee);

            return {
              id: order.id,
              order_number: order.order_number,
              customer: order.customer_name || 'Unknown',
              email: order.customer_email || '',
              phone: order.customer_phone || '',
              shippingAddress: order.shipping_address || '',
              total_amount: itemSubtotal,
              shipping_fee: shippingFee,
              grand_total: grandTotal,
              paymentStatus: order.payment_status || 'pending',
              paymentMethod: order.payment_method || '',
              status: order.status as any,
              cancellation_reason: order.cancellation_reason || null,
              cancellation_note: order.cancellation_note || null,
              cancellation_other_reason_note: order.cancellation_other_reason_note || null,
              orderedAt: new Date(order.created_at).toLocaleString(),
              items: order.items || [],
              quantity: order.items?.reduce((sum: number, item: any) => sum + item.quantity, 0) || 0,
              product: order.items?.[0]?.product_name || '',
              carrierCompany: order.carrier_company || undefined,
              carrierName: order.carrier_name || undefined,
              carrierPhone: order.carrier_phone || undefined,
              trackingNumber: order.tracking_number || undefined,
              trackingLink: order.tracking_link || undefined,
              eta: order.eta || undefined,
              pickup_enabled: order.pickup_enabled || false,
              pickup_enabled_at: order.pickup_enabled_at || null,
              latest_refund: order.latest_refund || null,
            };
          });
          setOrders(mappedOrders);
        }
      } catch (fetchError) {
        console.error('Error refreshing orders:', fetchError);
        // Still update local state even if refresh fails
        setOrders((prev) =>
          prev.map((o) =>
            o.id === selectedOrder.id
              ? {
                  ...o,
                  status: "shipped",
                  shippedAt: new Date().toLocaleString(),
                  carrierCompany,
                  carrierName,
                  carrierPhone,
                  trackingNumber: normalizedTrackingNumber,
                  trackingLink: trackingLinkValue,
                  eta: etaDate,
                }
              : o
          )
        );
      }

      // Close modal
      setIsShippingModalOpen(false);
      setSelectedOrder(null);
      setEta("");
      setEtaPreset("");
      setCarrierCompany("");
      setCarrierName("");
      setCarrierPhone("");
      setTrackingNumber("");
      setTrackingLink("");

      await Swal.fire({
        title: "Success",
        text: "This order has been marked as shipped.",
        icon: "success",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    } catch (error) {
      console.error('Error confirming shipping:', error);
      await Swal.fire({
        title: "Error",
        text: error instanceof Error ? error.message : "Failed to confirm shipping. Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
    } finally {
      setIsConfirmingShipping(false);
    }
  };

  const handleActivatePickup = async (orderId: number) => {
    const result = await Swal.fire({
      title: 'Activate Pickup Confirmation?',
      text: 'This will allow the customer to confirm they have received their order.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Activate',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2563eb',
    });

    if (!result.isConfirmed) return;

    try {
      // Fetch fresh CSRF token
      const csrfResponse = await fetch('/api/csrf-token', {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      
      if (!csrfResponse.ok) {
        throw new Error('Failed to get CSRF token');
      }
      
      const csrfData = await csrfResponse.json();
      const csrfToken = csrfData.csrf_token;

      const response = await axios.post(`/api/shop-owner/orders/${orderId}/activate-pickup`, {}, {
        headers: {
          'X-CSRF-TOKEN': csrfToken,
        },
        withCredentials: true,
      });
      
      if (response.data.success) {
        setOrders((prev) =>
          prev.map((order) =>
            order.id === orderId
              ? {
                  ...order,
                  pickup_enabled: true,
                  pickup_enabled_at: new Date().toISOString(),
                }
              : order
          )
        );

        setViewOrder((prev) =>
          prev && prev.id === orderId
            ? {
                ...prev,
                pickup_enabled: true,
                pickup_enabled_at: new Date().toISOString(),
              }
            : prev
        );

        await Swal.fire({
          title: 'Pickup Activated!',
          text: 'Customer can now confirm they received their order.',
          icon: 'success',
          confirmButtonColor: '#2563eb',
        });
      }
    } catch (error: any) {
      await Swal.fire({
        title: 'Error',
        text: error.response?.data?.message || 'Failed to activate pickup',
        icon: 'error',
      });
    }
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Job Orders Retail" />
      {error && <ErrorModal message={error} onClose={() => setError(null)} />}
      
      {loading ? (
        <div className="flex items-center justify-center h-96">
          <div className="text-center">
            <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            <p className="text-gray-600 dark:text-gray-400">Loading orders...</p>
          </div>
        </div>
      ) : (
        <div className="space-y-6">
        {/* Header */}
        <div className="flex justify-between items-start">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Customer Orders</h1>
            <p className="text-gray-600 dark:text-gray-400 mt-2">Process and manage customer shoe orders</p>
          </div>
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <MetricCard
            title="Pending Orders"
            value={stats.pending}
            change={12}
            changeType="increase"
            icon={ClipboardListIcon}
            color="warning"
            description="Awaiting processing"
          />
          <MetricCard
            title="Processing"
            value={stats.processing}
            change={8}
            changeType="increase"
            icon={ClockIcon}
            color="info"
            description="Currently being prepared"
          />
          <MetricCard
            title="Shipped"
            value={stats.shipped}
            change={15}
            changeType="increase"
            icon={CheckCircleIcon}
            color="success"
            description="Out for delivery"
          />
          <MetricCard
            title="Total Revenue"
            value={`₱${stats.totalRevenue.toLocaleString()}`}
            change={20}
            changeType="increase"
            icon={CurrencyDollarIcon}
            color="success"
            description="From all orders"
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
                  All Orders ({stats.total})
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
                  onClick={() => setSelectedTab("processing")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "processing"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Processing ({stats.processing})
                </button>
                <button
                  onClick={() => setSelectedTab("shipped")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "shipped"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Shipped ({stats.shipped})
                </button>
                <button
                  onClick={() => setSelectedTab("delivered")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "delivered"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Delivered ({stats.delivered})
                </button>
                <button
                  onClick={() => setSelectedTab("refund")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "refund"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Refund ({stats.refund})
                </button>
              </div>

              {/* Search and Actions */}
              <div className="flex items-center gap-3">
                {/* Search */}
                <div className="relative flex-1 lg:flex-initial">
                  <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-gray-400" />
                  <input
                    type="text"
                    title="Search orders"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    className="w-full lg:w-64 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
                  />
                </div>

                {/* Filter Button */}
                <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                  <FunnelIcon className="size-5" />
                  <span className="hidden sm:inline text-sm font-medium">Filter</span>
                </button>

                {/* Export Button */}
                <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                  <ArrowDownTrayIcon className="size-5" />
                  <span className="hidden sm:inline text-sm font-medium">Export</span>
                </button>
              </div>
            </div>
          </div>

          {/* Table */}
          <div className="h-135 overflow-y-auto overflow-x-auto">
            <table className="w-full min-w-270 table-fixed">
              <colgroup>
                <col className="w-[5%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
                <col className="w-[11.875%]" />
              </colgroup>
              <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800 sticky top-0 z-10">
                <tr>
                  <th className="box-border px-4 py-4 text-left">
                    <input
                      type="checkbox"
                      title="Select all orders on this page"
                      checked={
                        paginatedOrders.length > 0 &&
                        selectedOrders.length === paginatedOrders.length
                      }
                      onChange={handleSelectAll}
                      className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
                    />
                  </th>
                  <th className="box-border px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Customer
                  </th>
                  <th className="box-border px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Product
                  </th>
                  <th className="box-border px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Size / Color
                  </th>
                  <th className="box-border px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Quantity
                  </th>
                  <th className="box-border px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Amount Breakdown
                  </th>
                  <th className="box-border px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Status
                  </th>
                  <th className="box-border px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Refunded/Return
                  </th>
                  <th className="box-border px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    ETA
                  </th>
                  <th className="box-border px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {paginatedOrders.length > 0 ? (
                  paginatedOrders.map((order) => (
                    <tr
                      key={order.id}
                      className="hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors"
                    >
                      <td className="box-border px-4 py-4 align-top">
                        <input
                          type="checkbox"
                          title={`Select order ${order.order_number}`}
                          aria-label={`Select order ${order.order_number}`}
                          checked={selectedOrders.includes(order.id)}
                          onChange={() => handleSelectOrder(order.id)}
                          className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
                        />
                      </td>
                      <td className="box-border px-4 py-4 align-top">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-white truncate">{order.customer}</div>
                          <div className="text-xs text-gray-500 dark:text-gray-400 truncate">{order.email}</div>
                        </div>
                      </td>
                      <td className="box-border px-4 py-4 align-top">
                        <span className="text-sm text-gray-700 dark:text-gray-300 block truncate">{order.product}</span>
                      </td>
                      <td className="box-border px-4 py-4 text-center align-top">
                        <span className="text-sm text-gray-700 dark:text-gray-300">
                          {formatOrderSizeColor(order)}
                        </span>
                      </td>
                      <td className="box-border px-4 py-4 text-center align-top">
                        <span className="text-sm text-gray-700 dark:text-gray-300">{order.quantity}</span>
                      </td>
                      <td className="box-border px-4 py-4 text-left align-top">
                        <div className="space-y-1">
                          <div className="flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>Item Subtotal</span>
                            <span className="font-medium text-gray-800 dark:text-gray-200">{formatOrderTotal(order.total_amount)}</span>
                          </div>
                          <div className="flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>Shipping Fee</span>
                            <span className="font-medium text-gray-800 dark:text-gray-200">{formatOrderTotal(order.shipping_fee)}</span>
                          </div>
                          <div className="flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span>{order.vat_rate !== null ? `VAT (${order.vat_rate}%)` : 'VAT'}</span>
                            <span className="font-medium text-gray-800 dark:text-gray-200">{order.vat_amount !== null ? formatOrderTotal(order.vat_amount) : 'N/A'}</span>
                          </div>
                          <div className={`flex items-center justify-between gap-2 text-sm font-semibold ${
                            order.status === 'cancelled' || String(order.paymentStatus || '').toLowerCase() === 'refunded'
                              ? 'text-red-600 dark:text-red-400'
                              : 'text-emerald-600 dark:text-emerald-400'
                          }`}>
                            <span>Grand Total</span>
                            <span className="inline-flex items-center gap-1">
                              <span className={`inline-flex items-center justify-center rounded-full ${
                                order.status === 'cancelled' || String(order.paymentStatus || '').toLowerCase() === 'refunded'
                                  ? 'bg-red-100 p-0.5 dark:bg-red-900/30'
                                  : 'bg-emerald-100 p-0.5 dark:bg-emerald-900/30'
                              }`}>
                                {order.status === 'cancelled' || String(order.paymentStatus || '').toLowerCase() === 'refunded' ? (
                                  <MinusIcon className="size-3" />
                                ) : (
                                  <PlusIcon className="size-3" />
                                )}
                              </span>
                              {formatOrderTotal(order.grand_total)}
                            </span>
                          </div>
                        </div>
                      </td>
                      <td className="box-border px-4 py-4 align-top">
                        <div className="flex flex-col items-start gap-2 min-h-12">
                          <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full whitespace-nowrap ${getStatusColor(order.status)}`}>
                            <span className="size-1.5 rounded-full bg-current opacity-70" aria-hidden="true" />
                            {formatStatusLabel(order.status)}
                          </span>
                        </div>
                      </td>
                      <td className="box-border px-4 py-4 align-top">
                        {(() => {
                          const refundReturn = getRefundReturnDisplay(order);
                          if (refundReturn.label === '-') {
                            return <span className={refundReturn.className}>-</span>;
                          }

                          return (
                            <span className={`inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full whitespace-nowrap ${refundReturn.className}`}>
                              {refundReturn.label}
                            </span>
                          );
                        })()}
                      </td>
                      <td className="box-border px-4 py-4 align-top">
                        <span className="text-sm text-gray-700 dark:text-gray-300">
                          {order.eta || '-'}
                        </span>
                      </td>
                      <td className="box-border px-4 py-4 text-center align-top">
                        <div className="flex flex-wrap items-center justify-center gap-2">
                          <button
                            type="button"
                            onClick={() => handleViewOrder(order)}
                            className="p-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                            title="View order details"
                          >
                            <EyeIcon className="size-5" />
                          </button>
                          {order.status === "pending" && (
                            <button
                              type="button"
                              onClick={() => handleProcessOrder(order)}
                              className="p-2 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-colors"
                              title="Start processing"
                              aria-label="Start processing"
                            >
                              <CheckCircleIcon className="size-5" />
                            </button>
                          )}
                          {order.status === "processing" && (
                            <button
                              type="button"
                              onClick={() => handleShipOrder(order)}
                              className="p-2 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-colors"
                              title="Mark as shipped"
                              aria-label="Mark as shipped"
                            >
                              <CheckCircleIcon className="size-5" />
                            </button>
                          )}
                          {canConfirmReturnReceived(order) && (
                            <button
                              type="button"
                              onClick={() => handleConfirmReturnReceived(order)}
                              className="p-2 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg transition-colors"
                              title="Confirm returned item received"
                              aria-label="Confirm returned item received"
                            >
                              <CheckCircleIcon className="size-5" />
                            </button>
                          )}
                          {canArrangeReturnPickup(order) && (
                            <button
                              type="button"
                              onClick={() => handleArrangeReturnPickup(order)}
                              className="p-2 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors"
                              title="Arrange return pickup"
                              aria-label="Arrange return pickup"
                            >
                              <PencilIcon className="size-5" />
                            </button>
                          )}
                          {/* Shipped orders will be completed when customer confirms receipt */}
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={10} className="box-border px-6 py-12 text-center">
                      <p className="text-sm text-gray-500 dark:text-gray-400">No orders found</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
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
                          className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                            page === currentPage
                              ? "bg-blue-600 text-white"
                              : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
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

        {/* Shipping Modal */}
        {isShippingModalOpen && selectedOrder && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full max-h-[80vh] flex flex-col">
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
                    <p className="text-sm text-gray-900 dark:text-white">{selectedOrder.shippingAddress}</p>
                  </div>
                  
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Estimated Delivery Date *
                    </label>
                    <select
                      title="Estimated delivery date"
                      value={etaPreset}
                      onChange={(e) => setEtaPreset(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                      title="Shipping business"
                      value={carrierCompany}
                      onChange={(e) => setCarrierCompany(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
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
                        title="Rider name"
                        value={carrierName}
                        onChange={(e) => setCarrierName(e.target.value)}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Rider Phone *
                      </label>
                      <input
                        type="tel"
                        title="Rider phone"
                        value={carrierPhone}
                        onChange={(e) => {
                          // Allow digits only and limit to 11 characters
                          const digits = e.target.value.replace(/\D/g, '').slice(0, 11);
                          setCarrierPhone(digits);
                        }}
                        maxLength={11}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tracking Number *</label>
                    <input
                      type="text"
                      title="Tracking number"
                      value={trackingNumber}
                      onChange={(e) => setTrackingNumber(e.target.value.replace(/\D/g, ''))}
                      inputMode="numeric"
                      pattern="[0-9]*"
                      disabled={selectedOrder.status === 'shipped'}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <p className="text-xs text-gray-500 mt-1">Record tracking number from the courier. This field is required before confirming shipping.</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tracking Link *</label>
                    <input
                      type="url"
                      title="Tracking link"
                      value={trackingLink}
                      onChange={(e) => setTrackingLink(e.target.value)}
                      required
                      disabled={selectedOrder.status === 'shipped'}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <p className="text-xs text-gray-500 mt-1">Provide the tracking link so customers can track delivery in real time.</p>
                  </div>

                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Message
                      </label>
                      <button
                        type="button"
                        onClick={() => {
                          const message = getShippingMessage();
                          if (message) navigator.clipboard.writeText(message);
                        }}
                        className="inline-flex items-center gap-2 px-2 py-1 text-xs font-medium text-blue-600 hover:text-blue-700"
                        title="Copy message"
                      >
                        <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-4 12h6a2 2 0 002-2v-8a2 2 0 00-2-2h-6a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Copy
                      </button>
                    </div>
                    <div className="text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900/30 rounded-lg p-3">
                      {getShippingMessage()}
                    </div>
                  </div>
                </div>
              </div>

              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 flex-shrink-0">
                <button
                  type="button"
                  onClick={handleConfirmShipping}
                  disabled={isConfirmingShipping}
                  className="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 disabled:cursor-not-allowed text-white rounded-lg font-medium transition-colors"
                >
                  {isConfirmingShipping ? (
                    <span className="inline-flex items-center gap-2">
                      <svg className="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" opacity="0.25" />
                        <path d="M22 12a10 10 0 00-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                      </svg>
                      Confirming...
                    </span>
                  ) : (
                    'Confirm Shipping'
                  )}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setIsShippingModalOpen(false);
                    setSelectedOrder(null);
                    setEta("");
                    setEtaPreset("");
                    setCarrierCompany("");
                    setCarrierName("");
                    setCarrierPhone("");
                    setTrackingNumber("");
                    setTrackingLink("");
                  }}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-medium transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        )}

        {/* View Order Modal */}
        {isViewModalOpen && viewOrder && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4">
            <div className="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full p-6">
              <div className="flex items-start justify-between mb-4">
                <div>
                  <h2 className="text-xl font-bold text-gray-900 dark:text-white">Order Details</h2>
                  <p className="text-sm text-gray-500 dark:text-gray-400">{viewOrder.id}</p>
                </div>
                <button
                  onClick={() => {
                    setIsViewModalOpen(false);
                    setViewOrder(null);
                  }}
                  className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                  aria-label="Close"
                >
                  <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <div className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Full Name</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.customer}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Phone Number</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.phone}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Email</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.email}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Address</p>
                    <p className="text-sm text-gray-900 dark:text-white">{viewOrder.shippingAddress}</p>
                  </div>
                </div>

                <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                  <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Order Purchase</p>
                  <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 space-y-2">
                    {viewOrder.items && viewOrder.items.length > 1 ? (
                      // Multiple items — show each item
                      <>
                        {viewOrder.items.map((item: OrderItem, idx: number) => (
                          <div key={idx} className="pb-2 mb-2 border-b border-gray-200 dark:border-gray-700 last:border-0 last:mb-0 last:pb-0">
                            <div className="flex items-center justify-between">
                              <span className="text-sm text-gray-600 dark:text-gray-400">Product</span>
                              <span className="text-sm font-medium text-gray-900 dark:text-white">{item.product_name}</span>
                            </div>
                            <div className="flex items-center justify-between">
                              <span className="text-sm text-gray-600 dark:text-gray-400">Size</span>
                              <span className="text-sm font-medium text-gray-900 dark:text-white">{item.size || '-'}</span>
                            </div>
                            <div className="flex items-center justify-between">
                              <span className="text-sm text-gray-600 dark:text-gray-400">Color</span>
                              <span className="text-sm font-medium text-gray-900 dark:text-white">{resolveOrderItemColor(viewOrder, item) || '-'}</span>
                            </div>
                            <div className="flex items-center justify-between">
                              <span className="text-sm text-gray-600 dark:text-gray-400">Qty</span>
                              <span className="text-sm font-medium text-gray-900 dark:text-white">{item.quantity}</span>
                            </div>
                          </div>
                        ))}
                        <div className="flex items-center justify-between pt-1">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Item Subtotal</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{formatOrderTotal(viewOrder.total_amount)}</span>
                        </div>
                        <div className="flex items-center justify-between pt-1">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Shipping Fee</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{formatOrderTotal(viewOrder.shipping_fee)}</span>
                        </div>
                        <div className="flex items-center justify-between pt-1">
                          <span className="text-sm text-gray-600 dark:text-gray-400">{viewOrder.vat_rate !== null ? `VAT (${viewOrder.vat_rate}%)` : 'VAT'}</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.vat_amount !== null ? formatOrderTotal(viewOrder.vat_amount) : 'N/A'}</span>
                        </div>
                        <div className="flex items-center justify-between pt-1">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Grand Total</span>
                          <span className={`inline-flex items-center gap-1 text-sm font-semibold ${
                            viewOrder.status === 'cancelled' || String(viewOrder.paymentStatus || '').toLowerCase() === 'refunded'
                              ? 'text-red-600 dark:text-red-400'
                              : 'text-emerald-600 dark:text-emerald-400'
                          }`}>
                            <span className={`inline-flex items-center justify-center rounded-full ${
                              viewOrder.status === 'cancelled' || String(viewOrder.paymentStatus || '').toLowerCase() === 'refunded'
                                ? 'bg-red-100 p-0.5 dark:bg-red-900/30'
                                : 'bg-emerald-100 p-0.5 dark:bg-emerald-900/30'
                            }`}>
                              {viewOrder.status === 'cancelled' || String(viewOrder.paymentStatus || '').toLowerCase() === 'refunded' ? (
                                <MinusIcon className="size-3" />
                              ) : (
                                <PlusIcon className="size-3" />
                              )}
                            </span>
                            {formatOrderTotal(viewOrder.grand_total)}
                          </span>
                        </div>
                      </>
                    ) : (
                      // Single item
                      <>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Product</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.product}</span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Size</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">
                            {viewOrder.items?.[0]?.size || '-'}
                          </span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Color</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">
                            {viewOrder.items?.[0] ? resolveOrderItemColor(viewOrder, viewOrder.items[0]) || '-' : '-'}
                          </span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Quantity</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.quantity}</span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Item Subtotal</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{formatOrderTotal(viewOrder.total_amount)}</span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Shipping Fee</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{formatOrderTotal(viewOrder.shipping_fee)}</span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">{viewOrder.vat_rate !== null ? `VAT (${viewOrder.vat_rate}%)` : 'VAT'}</span>
                          <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.vat_amount !== null ? formatOrderTotal(viewOrder.vat_amount) : 'N/A'}</span>
                        </div>
                        <div className="flex items-center justify-between">
                          <span className="text-sm text-gray-600 dark:text-gray-400">Grand Total</span>
                          <span className={`inline-flex items-center gap-1 text-sm font-semibold ${
                            viewOrder.status === 'cancelled' || String(viewOrder.paymentStatus || '').toLowerCase() === 'refunded'
                              ? 'text-red-600 dark:text-red-400'
                              : 'text-emerald-600 dark:text-emerald-400'
                          }`}>
                            <span className={`inline-flex items-center justify-center rounded-full ${
                              viewOrder.status === 'cancelled' || String(viewOrder.paymentStatus || '').toLowerCase() === 'refunded'
                                ? 'bg-red-100 p-0.5 dark:bg-red-900/30'
                                : 'bg-emerald-100 p-0.5 dark:bg-emerald-900/30'
                            }`}>
                              {viewOrder.status === 'cancelled' || String(viewOrder.paymentStatus || '').toLowerCase() === 'refunded' ? (
                                <MinusIcon className="size-3" />
                              ) : (
                                <PlusIcon className="size-3" />
                              )}
                            </span>
                            {formatOrderTotal(viewOrder.grand_total)}
                          </span>
                        </div>
                      </>
                    )}
                    <div className="flex items-center justify-between pt-1 border-t border-gray-200 dark:border-gray-700">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Ordered At</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.orderedAt}</span>
                    </div>
                  </div>
                </div>
                {(String(viewOrder.cancellation_reason || '').trim()
                  || String(viewOrder.cancellation_other_reason_note || '').trim()
                  || String(viewOrder.latest_refund?.reason_code || '').trim()
                  || String(viewOrder.latest_refund?.other_reason_note || '').trim()) && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Reason Details</p>
                    {(() => {
                      const isCancelledOrder = String(viewOrder.status || '').toLowerCase() === 'cancelled';
                      const cancellationMessage = String(
                        viewOrder.cancellation_other_reason_note
                          || viewOrder.cancellation_reason
                          || ''
                      ).trim();
                      const refundMessage = String(
                        viewOrder.latest_refund?.other_reason_note
                          || viewOrder.latest_refund?.reason_code
                          || ''
                      ).trim();

                      const label = isCancelledOrder ? 'Cancellation Message' : 'Refund Message';
                      const value = isCancelledOrder ? cancellationMessage : refundMessage;

                      if (!value) return null;

                      return (
                        <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300">
                          <div className="flex items-center justify-between">
                            <span className="text-gray-600">{label}</span>
                            <span className="font-medium">{value}</span>
                          </div>
                        </div>
                      );
                    })()}
                  </div>
                )}
                {(viewOrder.trackingNumber || viewOrder.trackingLink || viewOrder.eta) && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Shipping & Tracking</p>
                    <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                      <div className="flex items-center justify-between">
                        <span className="text-gray-600">ETA</span>
                        <span className="font-medium">{viewOrder.eta || '-'}</span>
                      </div>
                      <div className="flex items-center justify-between">
                        <span className="text-gray-600">Carrier</span>
                        <span className="font-medium">{viewOrder.carrierCompany || '-'}</span>
                      </div>
                      {viewOrder.trackingNumber && (
                        <div className="flex items-center justify-between">
                          <span className="text-gray-600">Tracking #</span>
                          <span className="font-medium">{viewOrder.trackingNumber}</span>
                        </div>
                      )}
                      {viewOrder.trackingLink && (
                        <div className="flex items-center justify-between">
                          <span className="text-gray-600">Tracking Link</span>
                          <a className="font-medium text-blue-600 hover:underline" href={viewOrder.trackingLink} target="_blank" rel="noreferrer">View tracking</a>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>

              <div className="mt-6 flex gap-3">
                {canConfirmReturnReceived(viewOrder) && (
                  <button
                    type="button"
                    onClick={() => handleConfirmReturnReceived(viewOrder)}
                    className="px-4 py-2 border border-indigo-600 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors"
                    title="Confirm returned item received"
                  >
                    Confirm Return Received
                  </button>
                )}
                {canArrangeReturnPickup(viewOrder) && (
                  <button
                    type="button"
                    onClick={() => handleArrangeReturnPickup(viewOrder)}
                    className="px-4 py-2 border border-amber-600 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium transition-colors"
                    title="Arrange return pickup"
                  >
                    Arrange Return Pickup
                  </button>
                )}
                {viewOrder.status === "shipped" && (
                  <button
                    type="button"
                    onClick={() => handleActivatePickup(viewOrder.id)}
                    disabled={viewOrder.pickup_enabled}
                    className={`px-4 py-2 border border-black rounded-lg font-medium transition-colors ${
                      viewOrder.pickup_enabled
                        ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
                        : 'bg-white hover:bg-gray-100 text-black'
                    }`}
                    title={viewOrder.pickup_enabled ? 'Pickup already activated' : 'Activate pickup confirmation'}
                  >
                    {viewOrder.pickup_enabled ? '✓ Pickup Activated' : 'Activate Receive'}
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => {
                    setIsViewModalOpen(false);
                    setViewOrder(null);
                  }}
                  className="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-medium transition-colors"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    )}
    </AppLayoutShopOwner>
  );
}
