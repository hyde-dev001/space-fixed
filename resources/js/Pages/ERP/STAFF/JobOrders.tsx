import React, { useMemo, useState } from "react";
import Swal from "sweetalert2";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import ErrorModal from "../../../components/common/ErrorModal";

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
  total: string;
  paymentStatus: string;
  status: "pending" | "processing" | "shipped" | "delivered" | "cancelled";
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
  const [error, setError] = useState<string | null>(null);
  const { auth } = usePage().props as any;
  const userRole = auth?.user?.role;
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
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const itemsPerPage = 10;

  // Fetch orders from API on mount
  React.useEffect(() => {
    const fetchOrders = async () => {
      try {
        setLoading(true);
        const response = await fetch('/api/staff/orders', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
          }
        });
        
        if (!response.ok) {
          throw new Error('Failed to fetch orders');
        }
        
        const data = await response.json();
        
        // Map API data to Order type
        const mappedOrders: Order[] = data.map((order: any) => ({
          id: order.id,
          order_number: order.order_number,
          customer: order.customer_name || 'Unknown',
          email: order.customer_email || '',
          phone: order.customer_phone || '',
          shippingAddress: order.shipping_address || '',
          total: `₱${parseFloat(order.total_amount || 0).toLocaleString()}`,
          paymentStatus: order.payment_status || 'pending',
          status: order.status as any,
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
        }));
        
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

  if (userRole !== "STAFF" && userRole !== "MANAGER") {
    return (
      <AppLayoutERP>
        <div className="max-w-xl mx-auto mt-24 text-center p-8 bg-white dark:bg-gray-900 rounded-xl shadow">
          <h2 className="text-2xl font-bold mb-2 text-red-600">Access Denied</h2>
          <p className="text-gray-700 dark:text-gray-300">You do not have permission to view the Staff module.</p>
        </div>
      </AppLayoutERP>
    );
  }

  // Filter orders based on tab and search
  const filteredOrders = useMemo(() => {
    return orders.filter((order) => {
      const matchesTab = selectedTab === "all" || order.status === selectedTab;
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
    const cancelled = orders.filter(o => o.status === "cancelled").length;
    // Only include non-cancelled orders in revenue calculation
    const totalRevenue = orders
      .filter(o => o.status !== "cancelled")
      .reduce((sum, o) => sum + parseFloat(o.total.replace(/[^0-9.]/g, "")), 0);
    return { total, pending, processing, shipped, delivered, cancelled, totalRevenue };
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
      "pending": "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
      "processing": "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
      "shipped": "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400",
      "delivered": "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
      "cancelled": "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
    };
    return colors[status] || "bg-gray-100 text-gray-800";
  };

  const handleProcessOrder = async (order: Order) => {
    const result = await Swal.fire({
      title: "Process this order?",
      text: `Order ${order.order_number} for ${order.customer}`,
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
      const response = await fetch(`/api/staff/orders/${order.id}/status`, {
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
        text: `Order ${order.order_number} is now Processing.`,
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
    setEtaPreset("");
    // Prepopulate if values already exist (view-only for shipped orders)
    setCarrierCompany(order.carrierCompany || "");
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

  const handleConfirmShipping = async () => {
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
        text: "Please select a Carrier Company",
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

    if (!trackingNumber) {
      await Swal.fire({
        title: "Missing Information",
        text: "Please enter a Tracking Number",
        icon: "warning",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      // Fetch fresh CSRF token
      const csrfResponse = await fetch('/api/csrf-token', {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      const csrfData = await csrfResponse.json();
      const csrfToken = csrfData.csrf_token;

      // Call API to update order status with shipping info
      const response = await fetch(`/api/staff/orders/${selectedOrder.id}/status`, {
        method: 'PATCH',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
          status: 'shipped',
          tracking_number: trackingNumber,
          carrier_company: carrierCompany,
          carrier_name: carrierName,
          carrier_phone: carrierPhone,
          tracking_link: trackingLink || null,
          eta: etaPreset,
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to update order shipping information');
      }

      // Update local state
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
                trackingNumber,
                trackingLink,
                eta: etaPreset,
              }
            : o
        )
      );

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
        text: `Order ${selectedOrder.order_number} has been marked as shipped.`,
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
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Job Orders - Solespace ERP" />
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
                  onClick={() => setSelectedTab("cancelled")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "cancelled"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Cancel ({stats.cancelled})
                </button>
              </div>

              {/* Search and Actions */}
              <div className="flex items-center gap-3">
                {/* Search */}
                <div className="relative flex-1 lg:flex-initial">
                  <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-gray-400" />
                  <input
                    type="text"
                    placeholder="Search..."
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
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
                <tr>
                  <th className="px-6 py-4 text-left">
                    <input
                      type="checkbox"
                      checked={
                        paginatedOrders.length > 0 &&
                        selectedOrders.length === paginatedOrders.length
                      }
                      onChange={handleSelectAll}
                      className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
                    />
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Order ID
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Customer
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gr
                  ay-300">
                    Product
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Quantity
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Total
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Status
                  </th>
                  <th className="px-6 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">
                    ETA
                  </th>
                  <th className="px-6 py-4 text-center text-sm font-semibold text-gray-700 dark:text-gray-300">
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
                      <td className="px-6 py-4">
                        <input
                          type="checkbox"
                          checked={selectedOrders.includes(order.id)}
                          onChange={() => handleSelectOrder(order.id)}
                          className="rounded border-gray-300 dark:border-gray-700 text-blue-600 focus:ring-blue-500"
                        />
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-gray-900 dark:text-white">
                            {order.id}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-white">{order.customer}</div>
                          <div className="text-xs text-gray-500 dark:text-gray-400">{order.email}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-gray-700 dark:text-gray-300">{order.product}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-gray-700 dark:text-gray-300">{order.quantity}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm font-medium text-gray-900 dark:text-white">{order.total}</span>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusColor(order.status)}`}>
                          {order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-gray-700 dark:text-gray-300">{order.eta || '-'}</span>
                      </td>
                      <td className="px-6 py-4 text-center">
                        <div className="flex items-center justify-center gap-2">
                          <button
                            onClick={() => handleViewOrder(order)}
                            className="p-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                            title="View order details"
                          >
                            <EyeIcon className="size-5" />
                          </button>
                          {order.status === "pending" && (
                            <button
                              onClick={() => handleProcessOrder(order)}
                              className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
                              title="Start processing"
                            >
                              Process
                            </button>
                          )}
                          {order.status === "processing" && (
                            <button
                              onClick={() => handleShipOrder(order)}
                              className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors"
                              title="Mark as shipped"
                            >
                              Ship
                            </button>
                          )}
                          {/* Shipped orders will be completed when customer confirms receipt */}
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center">
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
                    <p className="text-sm text-gray-900 dark:text-white">{selectedOrder.shippingAddress}</p>
                  </div>
                  
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Estimated Delivery Date *
                    </label>
                    <select
                      value={etaPreset}
                      onChange={(e) => setEtaPreset(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="">Select ETA</option>
                      <option value="1-2 business days">1-2 business days</option>
                      <option value="1-3 business days">1-3 business days</option>
                      <option value="2-4 business days">2-4 business days</option>
                      <option value="3-6 business days">3-6 business days</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Carrier Company *
                    </label>
                    <select
                      value={carrierCompany}
                      onChange={(e) => setCarrierCompany(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="">Select carrier</option>
                      <option value="Lalamove">Lalamove</option>
                      <option value="J&T">J&amp;T</option>
                      <option value="Express Padala">Express Padala</option>
                    </select>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Carrier Name *
                      </label>
                      <input
                        type="text"
                        value={carrierName}
                        onChange={(e) => setCarrierName(e.target.value)}
                        placeholder="Rider name"
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Carrier Phone *
                      </label>
                      <input
                        type="tel"
                        value={carrierPhone}
                        onChange={(e) => {
                          // Allow digits only and limit to 10 characters
                          const digits = e.target.value.replace(/\D/g, '').slice(0, 10);
                          setCarrierPhone(digits);
                        }}
                        maxLength={10}
                        placeholder="9XXXXXXXXX (10 digits)"
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tracking Number *</label>
                    <input
                      type="text"
                      value={trackingNumber}
                      onChange={(e) => setTrackingNumber(e.target.value)}
                      placeholder="Enter tracking number provided by courier"
                      disabled={selectedOrder.status === 'shipped'}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <p className="text-xs text-gray-500 mt-1">Record tracking number from the courier. This field is required before confirming shipping.</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tracking Link (optional)</label>
                    <input
                      type="url"
                      value={trackingLink}
                      onChange={(e) => setTrackingLink(e.target.value)}
                      placeholder="https://tracking.example.com/track/..."
                      disabled={selectedOrder.status === 'shipped'}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <p className="text-xs text-gray-500 mt-1">Optional link so customers can track delivery in real time.</p>
                  </div>

                  <div>
                    <div className="flex items-center justify-between mb-2">
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Auto Message
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
                  onClick={handleConfirmShipping}
                  className="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                >
                  Confirm Shipping
                </button>
                <button
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
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Product</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.product}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Quantity</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.quantity}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Total</span>
                      <span className="text-sm font-semibold text-gray-900 dark:text-white">{viewOrder.total}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Ordered At</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.orderedAt}</span>
                    </div>
                  </div>
                </div>
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
                <button
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
    </AppLayoutERP>
  );
}
