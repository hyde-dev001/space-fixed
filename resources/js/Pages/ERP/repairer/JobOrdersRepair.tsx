import { useMemo, useState, useEffect } from "react";
import Swal from "sweetalert2";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import ErrorModal from "../../../components/common/ErrorModal";

type RepairOrder = {
  id: string;
  customer: string;
  email: string;
  phone: string;
  item: string;
  service: string;
  total: string;
  status: "under-review" | "pending" | "received" | "in-progress" | "completed" | "ready-for-pickup" | "rejected";
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
  pickupAddressLine?: string;
  pickupBarangay?: string;
  pickupCity?: string;
  pickupRegion?: string;
  pickupPostalCode?: string;
  selectedServices?: Array<{ name: string; price?: string } | string>;
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

const useStaticData = true;

const staticOrders: RepairOrder[] = [
  {
    id: "RR-1000",
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
  const userRole = auth?.user?.role;
  const [selectedTab, setSelectedTab] = useState<string>("under-review");
  const [searchTerm, setSearchTerm] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [viewOrder, setViewOrder] = useState<RepairOrder | null>(null);
  const [enlargedImage, setEnlargedImage] = useState<string | null>(null);
  const [orders, setOrders] = useState<RepairOrder[]>(useStaticData ? staticOrders : []);
  const [isLoading, setIsLoading] = useState(true);
  const [isShippingModalOpen, setIsShippingModalOpen] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState<RepairOrder | null>(null);
  const [carrierCompany, setCarrierCompany] = useState("");
  const [carrierName, setCarrierName] = useState("");
  const [carrierPhone, setCarrierPhone] = useState("");
  const [trackingNumber, setTrackingNumber] = useState("");
  const [trackingLink, setTrackingLink] = useState("");
  const [isRejectionModalOpen, setIsRejectionModalOpen] = useState(false);
  const [selectedRejectionReason, setSelectedRejectionReason] = useState("");
  const [rejectionReason, setRejectionReason] = useState("");
  const itemsPerPage = 10;

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

  // Fetch repair requests from backend
  useEffect(() => {
    if (useStaticData) {
      setOrders(staticOrders);
      setIsLoading(false);
      return;
    }

    const fetchOrders = async () => {
      try {
        setIsLoading(true);
        const response = await fetch('/api/repair-requests', {
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          },
        });

        if (!response.ok) {
          throw new Error('Failed to fetch repair requests');
        }

        const data = await response.json();
        if (data.success) {
          setOrders(data.data);
        }
      } catch (error) {
        console.error('Failed to fetch repair requests:', error);
        setError('Failed to load repair requests');
      } finally {
        setIsLoading(false);
      }
    };

    fetchOrders();
  }, []);

  // Filter orders based on tab and search
  const filteredOrders = useMemo(() => {
    return orders.filter((order) => {
      let matchesTab;
      if (selectedTab === "all") {
        matchesTab = true;
      } else if (selectedTab === "under-review") {
        // New Request tab includes only under-review status
        matchesTab = order.status === "under-review";
      } else {
        matchesTab = order.status === selectedTab;
      }
      const matchesSearch =
        order.id.toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.customer.toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.item.toLowerCase().includes(searchTerm.toLowerCase()) ||
        order.service.toLowerCase().includes(searchTerm.toLowerCase());
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
    const underReview = orders.filter(o => o.status === "under-review" || o.status === "pending").length;
    const pending = orders.filter(o => o.status === "pending").length;
    const received = orders.filter(o => o.status === "received").length;
    const inProgress = orders.filter(o => o.status === "in-progress").length;
    const completed = orders.filter(o => o.status === "completed").length;
    const readyForPickup = orders.filter(o => o.status === "ready-for-pickup").length;
    const rejected = orders.filter(o => o.status === "rejected").length;
    const totalRevenue = orders.reduce((sum, o) => sum + parseFloat(o.total.replace(/[^0-9.]/g, "")), 0);
    return { total, underReview, pending, received, inProgress, completed, readyForPickup, rejected, totalRevenue };
  }, [orders]);

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      "under-review": "bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400",
      "pending": "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
      "received": "bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400",
      "in-progress": "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
      "completed": "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
      "ready-for-pickup": "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400",
      "rejected": "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
    };
    return colors[status] || "bg-gray-100 text-gray-800";
  };

  const handleMarkReceived = async (order: RepairOrder) => {
    const result = await Swal.fire({
      title: "Mark as received?",
      text: `${order.service} for ${order.customer}`,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, mark received",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#2563eb",
    });

    if (!result.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repair-requests/${order.id}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ status: 'received' }),
      });

      const data = await response.json();

      if (data.success) {
        setOrders((prev) =>
          prev.map((o) => (o.id === order.id ? { ...o, status: "received" } : o))
        );
        setViewOrder((prev) => prev ? { ...prev, status: "received" } : null);
        setSelectedTab("received");
        setCurrentPage(1);

        await Swal.fire({
          title: "Marked as received",
          text: `${order.id} has been marked as received.`,
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
        title: "Failed to mark received",
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
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repair-requests/${order.id}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ status: 'in-progress' }),
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
      
      const response = await fetch(`/api/repair-requests/${order.id}/status`, {
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

  const handleShipOrder = (order: RepairOrder) => {
    setSelectedOrder(order);
    setCarrierCompany("");
    setCarrierName("");
    setCarrierPhone("");
    setTrackingNumber("");
    setTrackingLink("");
    setIsShippingModalOpen(true);
  };

  const handleConfirmShipping = async () => {
    if (!selectedOrder) return;

    // Validation
    if (!carrierCompany) {
      await Swal.fire({
        title: "Required field",
        text: "Please select a Carrier Company.",
        icon: "warning",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const response = await fetch(`/api/repair-requests/${selectedOrder.id}/status`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          status: 'shipped',
          tracking_number: trackingNumber,
          carrier_company: carrierCompany,
          carrier_name: carrierName,
          carrier_phone: carrierPhone,
          tracking_link: trackingLink || null,
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

  const formatServiceType = (serviceType?: RepairOrder["serviceType"]) => {
    if (serviceType === "pickup") return "Pick Up";
    if (serviceType === "walkin") return "Walk In";
    return "Not specified";
  };

  const handleReviewAction = async (action: "accept" | "reject" | "message") => {
    if (!viewOrder) return;

    if (action === "message") {
      return;
    }

    if (action === "accept") {
      // Close modal immediately
      setIsViewModalOpen(false);
      
      // Update order status
      setOrders((prev) =>
        prev.map((o) => (o.id === viewOrder.id ? { ...o, status: "pending" } : o))
      );

      await Swal.fire({
        title: "Request Accepted",
        text: `${viewOrder.id} has been moved to Pending status.`,
        icon: "success",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });

      setViewOrder(null);
    } else if (action === "reject") {
      // Close view modal and open rejection modal
      setIsViewModalOpen(false);
      setSelectedRejectionReason("");
      setRejectionReason("");
      setIsRejectionModalOpen(true);
    }
  };

  const handleSubmitRejection = async () => {
    if (!viewOrder || !selectedRejectionReason.trim()) {
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
      // Close rejection modal immediately
      setIsRejectionModalOpen(false);

      // Update order status
      setOrders((prev) =>
        prev.map((o) => (o.id === viewOrder.id ? { ...o, status: "rejected", notes: fullReason } : o))
      );

      await Swal.fire({
        title: "Request Rejected",
        text: `${viewOrder.id} has been rejected.`,
        icon: "success",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });

      setViewOrder(null);
      setSelectedRejectionReason("");
      setRejectionReason("");
      setSelectedTab("rejected");
    } catch (error) {
      console.error('Error rejecting request:', error);
      await Swal.fire({
        title: "Error",
        text: "Failed to reject the request. Please try again.",
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#2563eb",
      });
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
        </div>

        {/* Metrics */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
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
            title="Service Revenue"
            value={`₱${stats.totalRevenue.toLocaleString()}`}
            change={18}
            changeType="increase"
            icon={CurrencyDollarIcon}
            color="success"
            description="From repair services"
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
                  onClick={() => setSelectedTab("rejected")}
                  className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    selectedTab === "rejected"
                      ? "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                      : "text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-900/50"
                  }`}
                >
                  Rejected ({stats.rejected})
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
          <div className="overflow-x-auto">
            {isLoading ? (
              <div className="flex items-center justify-center py-12">
                <div className="flex flex-col items-center gap-3">
                  <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Loading repair orders...</p>
                </div>
              </div>
            ) : (
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
              <thead className="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Repair ID
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Customer
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Item
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Service
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Price
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Created
                  </th>
                  <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-white/[0.02] divide-y divide-gray-200 dark:divide-gray-800">
                {paginatedOrders.length > 0 ? (
                  paginatedOrders.map((order) => (
                    <tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-white/[0.02] transition-colors">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="text-sm font-medium text-gray-900 dark:text-white">{order.id}</span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm">
                          <div className="font-medium text-gray-900 dark:text-white">{order.customer}</div>
                          <div className="text-gray-500 dark:text-gray-400">{order.phone}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="text-sm text-gray-900 dark:text-white">{order.item}</span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{order.service}</span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusColor(order.status)}`}>
                          {order.status === "under-review"
                            ? "Under Review"
                            : order.status === "pending"
                            ? "Pending"
                            : order.status === "in-progress"
                            ? "In Progress"
                            : order.status === "ready-for-pickup"
                            ? "Ready for Pickup"
                            : order.status === "received"
                            ? "Received"
                            : order.status === "rejected"
                            ? "Rejected"
                            : order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-medium">
                        {order.total}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                        {order.createdAt}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => handleViewOrder(order)}
                            className="inline-flex items-center justify-center p-2 text-blue-600 hover:text-blue-700 hover:bg-blue-50 dark:text-blue-400 dark:hover:text-blue-300 dark:hover:bg-blue-900/30 rounded-lg transition-colors"
                            title="View details"
                          >
                            <EyeIcon className="size-5" />
                          </button>
                          {order.status === "pending" && (
                            <button
                              onClick={() => handleShipOrder(order)}
                              className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors"
                              title="Ship order"
                            >
                              Ship
                            </button>
                          )}
                          {order.status === "received" && (
                            <button
                              onClick={() => handleStartWork(order)}
                              className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
                              title="Start work"
                            >
                              Start Work
                            </button>
                          )}
                          {order.status === "in-progress" && (
                            <button
                              onClick={() => handleCompleteWork(order)}
                              className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors"
                              title="Mark as completed"
                            >
                              Complete
                            </button>
                          )}
                          {order.status === "ready-for-pickup" && (
                            <button
                              onClick={() => handleShipOrder(order)}
                              className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition-colors"
                              title="Mark as shipped"
                            >
                              Ship
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center">
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
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div
              className="absolute inset-0"
              onClick={() => {
                setIsViewModalOpen(false);
                setViewOrder(null);
              }}
            />

            <div className="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
              <div className="sticky top-0 bg-white dark:bg-gray-800 px-6 pt-6 pb-4 border-b border-gray-200 dark:border-gray-700 z-10">
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Repair Service Details</h3>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Order {viewOrder.id}</p>
                  </div>
                  <span className={`px-3 py-1 text-xs font-semibold rounded-full ${getStatusColor(viewOrder.status)}`}>
                    {viewOrder.status === "under-review"
                      ? "Under Review"
                      : viewOrder.status === "pending"
                      ? "Pending"
                      : viewOrder.status === "in-progress"
                      ? "In Progress"
                      : viewOrder.status === "ready-for-pickup"
                      ? "Ready for Pickup"
                      : viewOrder.status === "rejected"
                      ? "Rejected"
                      : viewOrder.status.charAt(0).toUpperCase() + viewOrder.status.slice(1)}
                  </span>
                </div>
              </div>

              <div className="px-6 py-4 space-y-4">
                {/* Shoe Images */}
                {((viewOrder.imageUrls && viewOrder.imageUrls.length > 0) || viewOrder.imageUrl) && (
                  <div>
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Shoe Images</p>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                      {(viewOrder.imageUrls && viewOrder.imageUrls.length > 0
                        ? viewOrder.imageUrls
                        : [viewOrder.imageUrl as string]
                      ).map((src, index) => (
                        <button
                          key={`${src}-${index}`}
                          type="button"
                          onClick={() => setEnlargedImage(src)}
                          className={`group relative rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500/40 transition-shadow ${
                            index === 0 ? "md:col-span-2 md:row-span-2" : ""
                          }`}
                          title="View image"
                        >
                          <img
                            src={src}
                            alt={`${viewOrder.item} ${index + 1}`}
                            className={`w-full object-cover transition-transform duration-300 group-hover:scale-[1.02] ${
                              index === 0 ? "h-64 md:h-72" : "h-32 md:h-32"
                            }`}
                          />
                          <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors" />
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {/* Customer Info */}
                <div className="grid grid-cols-2 gap-4 pt-4 border-t border-gray-200 dark:border-gray-700">
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
                </div>

                {/* Service Details */}
                <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                  <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Service Details</p>
                  <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Shoe Type</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.shoeType || viewOrder.item}</span>
                    </div>
                    {viewOrder.brand && (
                      <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600 dark:text-gray-400">Brand</span>
                        <span className="text-sm font-medium text-gray-900 dark:text-white">{viewOrder.brand}</span>
                      </div>
                    )}
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Service Type</span>
                      <span className="text-sm font-medium text-gray-900 dark:text-white">
                        {formatServiceType(viewOrder.serviceType)}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-gray-600 dark:text-gray-400">Service Fee</span>
                      <span className="text-sm font-semibold text-gray-900 dark:text-white">{viewOrder.total}</span>
                    </div>
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

                {/* Pickup Address */}
                {viewOrder.serviceType === "pickup" && (
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Pickup Address</p>
                    <div className="bg-gray-50 dark:bg-gray-900/30 rounded-lg p-4 space-y-2">
                      {viewOrder.pickupAddressLine && (
                        <div className="text-sm text-gray-700 dark:text-gray-300">{viewOrder.pickupAddressLine}</div>
                      )}
                      <div className="text-sm text-gray-700 dark:text-gray-300">
                        {[viewOrder.pickupBarangay, viewOrder.pickupCity].filter(Boolean).join(", ")}
                      </div>
                      <div className="text-sm text-gray-700 dark:text-gray-300">
                        {[viewOrder.pickupRegion, viewOrder.pickupPostalCode].filter(Boolean).join(" ")}
                      </div>
                    </div>
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

              {/* Footer */}
              <div className="sticky bottom-0 bg-gray-50 dark:bg-gray-900/30 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
                {viewOrder.status === "under-review" && (
                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      onClick={() => handleReviewAction("accept")}
                      className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors"
                    >
                      Accept
                    </button>
                    <button
                      onClick={() => handleReviewAction("reject")}
                      className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors"
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
                {viewOrder.status === "pending" && (
                  <div className="flex flex-wrap items-center gap-3">
                    <button
                      onClick={() => handleMarkReceived(viewOrder)}
                      className="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg font-medium transition-colors"
                      title="Mark as received"
                    >
                      Received
                    </button>
                  </div>
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
                <h2 className="text-xl font-bold text-gray-900 dark:text-white">Ship Repair Order</h2>
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
                      Item
                    </label>
                    <p className="text-sm text-gray-900 dark:text-white">{selectedOrder.item}</p>
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
                      <option value="">Select Carrier</option>
                      <option value="JNT">JNT</option>
                      <option value="LBC">LBC</option>
                      <option value="Lalamove">Lalamove</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Carrier Name
                    </label>
                    <input
                      type="text"
                      value={carrierName}
                      onChange={(e) => setCarrierName(e.target.value)}
                      placeholder="e.g., John Doe"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Carrier Phone
                    </label>
                    <input
                      type="tel"
                      value={carrierPhone}
                      onChange={(e) => setCarrierPhone(e.target.value)}
                      placeholder="e.g., +63 965 123 4567"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Tracking Number
                    </label>
                    <input
                      type="text"
                      value={trackingNumber}
                      onChange={(e) => setTrackingNumber(e.target.value)}
                      placeholder="e.g., 123456789"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Tracking Link
                    </label>
                    <input
                      type="url"
                      value={trackingLink}
                      onChange={(e) => setTrackingLink(e.target.value)}
                      placeholder="e.g., https://track.example.com"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                </div>
              </div>

              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex-shrink-0 bg-gray-50 dark:bg-gray-900/30 flex items-center justify-end gap-3">
                <button
                  onClick={() => {
                    setIsShippingModalOpen(false);
                    setSelectedOrder(null);
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
                    <p className="text-sm text-gray-900 dark:text-white font-semibold">{viewOrder?.id}</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                      Rejection Reason *
                    </label>
                    <select
                      value={selectedRejectionReason}
                      onChange={(e) => setSelectedRejectionReason(e.target.value)}
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
      </div>
    </AppLayoutERP>
  );
}

const sampleRepairOrders: RepairOrder[] = [
  { 
    id: 'REP-20260201001', 
    customer: 'John Doe', 
    email: 'john@email.com',
    phone: '+63 912 345 6789',
    item: 'Nike Air Max 90', 
    service: 'Sole Replacement',
    total: '₱650',
    status: 'pending',
    createdAt: '2026-02-01 10:30 AM',
    notes: 'Customer requested white sole replacement. Please use high-quality rubber sole.',
    imageUrl: '/images/product/product-01.jpg',
    repairDetails: ['Replace worn-out sole', 'Clean upper material', 'Apply protective coating', 'Quality check after completion']
  },
  { 
    id: 'REP-20260201002', 
    customer: 'Jane Smith', 
    email: 'jane@email.com',
    phone: '+63 923 456 7890',
    item: 'Adidas Ultraboost', 
    service: 'Deep Cleaning',
    total: '₱350',
    status: 'in-progress',
    createdAt: '2026-02-01 09:15 AM',
    startedAt: '2026-02-01 11:00 AM',
    notes: 'Deep clean with odor removal treatment. Customer wants shoes ready by tomorrow.',
    imageUrl: '/images/product/product-02.jpg',
    repairDetails: ['Deep clean with premium solution', 'Remove all dirt and stains', 'Odor removal treatment', 'Sanitize interior', 'Air dry completely']
  },
  { 
    id: 'REP-20260131003', 
    customer: 'Mike Johnson', 
    email: 'mike@email.com',
    phone: '+63 934 567 8901',
    item: 'New Balance 550', 
    service: 'Stitch Repair',
    total: '₱450',
    status: 'ready-for-pickup',
    createdAt: '2026-01-31 02:00 PM',
    startedAt: '2026-01-31 03:00 PM',
    completedAt: '2026-02-01 10:00 AM',
    notes: 'Heel stitching came loose. Customer needs it before weekend.',
    imageUrl: '/images/product/product-03.jpg',
    repairDetails: ['Re-stitch heel area with reinforced thread', 'Repair side seam tear', 'Strengthen weak points', 'Clean surrounding area']
  },
  { 
    id: 'REP-20260201004', 
    customer: 'Sarah Williams', 
    email: 'sarah@email.com',
    phone: '+63 945 678 9012',
    item: 'Puma RS-X', 
    service: 'Color Restoration',
    total: '₱800',
    status: 'pending',
    createdAt: '2026-02-02 08:00 AM',
    notes: 'Midsole color has faded badly. Customer wants original red color restored.',
    imageUrl: '/images/product/product-04.jpg',
    repairDetails: ['Clean midsole thoroughly', 'Remove old/faded paint', 'Apply red color restoration', 'Multiple coating layers', 'Seal with protective finish']
  },
  { 
    id: 'REP-20260131005', 
    customer: 'David Brown', 
    email: 'david@email.com',
    phone: '+63 956 789 0123',
    item: 'Jordan 1 High', 
    service: 'Premium Cleaning',
    total: '₱500',
    status: 'in-progress',
    createdAt: '2026-01-31 01:00 PM',
    startedAt: '2026-02-01 09:00 AM',
    notes: 'Vintage Jordan 1s need special care. Premium leather conditioning required.',
    imageUrl: '/images/product/product-05.jpg',
    repairDetails: ['Deep clean leather upper', 'Condition and moisturize leather', 'Clean white midsoles', 'Polish and shine', 'Apply leather protector']
  },
  { 
    id: 'REP-20260130006', 
    customer: 'Emma Davis', 
    email: 'emma@email.com',
    phone: '+63 967 890 1234',
    item: 'Vans Old Skool', 
    service: 'Canvas Repair',
    total: '₱400',
    status: 'completed',
    createdAt: '2026-01-30 10:00 AM',
    startedAt: '2026-01-30 02:00 PM',
    completedAt: '2026-01-31 11:00 AM',
    notes: 'Canvas has small tears on toe area.',
    imageUrl: '/images/product/product-06.jpg',
    repairDetails: ['Patch canvas tears on toe box', 'Reinforce weak canvas areas', 'Match original canvas color', 'Ensure seamless repair']
  },
];
