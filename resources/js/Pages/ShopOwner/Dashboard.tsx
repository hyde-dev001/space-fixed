import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
import AppLayoutERP from "../../layout/AppLayout_ERP";
import EcommerceMetrics from "../../components/ecommerce/EcommerceMetrics";
import RecentOrders from "../../components/ecommerce/RecentOrders";
import MonthlySalesChart from "../../components/ecommerce/MonthlySalesChart";
import MonthlyTarget from "../../components/ecommerce/MonthlyTarget";
import StatisticsChart from "../../components/ecommerce/StatisticsChart";

interface DashboardStats {
  revenue: {
    total: number;
    this_month: number;
    last_month: number;
    growth: number;
    average_order: number;
  };
  orders: {
    total: number;
    this_month: number;
    last_month: number;
    growth: number;
    pending: number;
    processing: number;
    shipped: number;
    completed: number;
    cancelled?: number;
    refunded?: number;
    partially_refunded?: number;
  };
  products: {
    total: number;
    active: number;
    low_stock: number;
    out_of_stock: number;
  };
  customers: {
    total: number;
    unique?: number;
    guests?: number;
    repeat?: number;
    unique_customers?: number;
    guest_orders?: number;
    repeat_customers?: number;
  };
  top_products: Array<{
    product_id: number;
    product_name: string;
    product_slug: string;
    product_image: string | null;
    total_quantity: number;
    total_revenue: number;
  }>;
  recent_orders: Array<{
    id: number;
    order_number: string;
    customer_name: string;
    customer_email: string;
    total_amount: number;
    status: string;
    items_count: number;
    created_at: string;
  }>;
  revenue_trend: Array<{
    date: string;
    revenue: number;
  }>;
}

interface DashboardPageProps {
  auth?: {
    shop_owner?: {
      business_type?: string | null;
      registration_type?: string | null;
    };
  };
  erpMode?: boolean;
  showPhaseThreePlaceholders?: boolean;
}

export default function Ecommerce() {
  const { auth, erpMode, showPhaseThreePlaceholders = false } = usePage().props as DashboardPageProps;
  const Layout = erpMode === true ? AppLayoutERP : AppLayoutShopOwner;
  const businessType = String(auth?.shop_owner?.business_type ?? "").toLowerCase();
  const registrationType = String(auth?.shop_owner?.registration_type ?? "").toLowerCase();
  const hideOrderMetrics = businessType === "repair" && registrationType === "individual";
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchDashboardStats();
  }, []);

  const fetchDashboardStats = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/shop-owner/dashboard/stats', {
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
        }
      });

      if (!response.ok) {
        throw new Error('Failed to fetch dashboard stats');
      }

      const data = await response.json();
      
      setStats(data);
    } catch (error) {
      console.error('Error fetching dashboard stats:', error);
      await Swal.fire({
        title: 'Error',
        text: 'Failed to load dashboard statistics. Please refresh the page.',
        icon: 'error',
        confirmButtonColor: '#2563eb'
      });
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <Layout>
        <Head title="Dashboard - Shop Owner" />
        <div className="flex items-center justify-center min-h-screen">
          <div className="text-center">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
            <p className="mt-4 text-gray-600 dark:text-gray-400">Loading dashboard...</p>
          </div>
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <Head title="Dashboard - Shop Owner" />
      <div className="space-y-6">
        <div>
          <h3 className="text-2xl font-bold text-gray-800 dark:text-white/90">
            Dashboard
          </h3>
          <p className="mt-1 text-gray-500 dark:text-gray-400">
            {hideOrderMetrics
              ? "Overview of your shop's repair performance"
              : "Overview of your shop's ecommerce performance"}
          </p>
        </div>

        {showPhaseThreePlaceholders && (
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2" aria-label="Phase 3 dashboard areas">
            <section
              aria-labelledby="required-actions-phase-three"
              className="rounded-2xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-white/[0.03]"
            >
              <h4 id="required-actions-phase-three" className="text-base font-semibold text-gray-700 dark:text-white/80">
                Required Actions — Coming in Phase 3
              </h4>
              <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Existing module and approval pages remain the current action surfaces.
              </p>
            </section>

            <section
              aria-labelledby="exceptions-phase-three"
              className="rounded-2xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-white/[0.03]"
            >
              <h4 id="exceptions-phase-three" className="text-base font-semibold text-gray-700 dark:text-white/80">
                Exceptions — Coming in Phase 3
              </h4>
              <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Exception review continues in the existing module and approval pages until Phase 3.
              </p>
            </section>
          </div>
        )}

      <EcommerceMetrics
        stats={stats}
        showOrdersMetric={!hideOrderMetrics}
        isRepairIndividualDashboard={hideOrderMetrics}
      />

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <MonthlySalesChart revenueTrend={stats?.revenue_trend || []} />
        <MonthlyTarget 
          thisMonth={stats?.revenue.this_month || 0}
          lastMonth={stats?.revenue.last_month || 0}
        />
      </div>

      {!hideOrderMetrics && <StatisticsChart stats={stats} />}

      {!hideOrderMetrics && <RecentOrders orders={stats?.recent_orders || []} />}
      </div>
    </Layout>
  );
}
