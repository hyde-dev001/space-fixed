import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";
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
  };
  products: {
    total: number;
    active: number;
    low_stock: number;
    out_of_stock: number;
  };
  customers: {
    total: number;
    unique: number;
    guests: number;
    repeat: number;
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

export default function Ecommerce() {
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
      <AppLayoutShopOwner>
        <Head title="Ecommerce Dashboard - Shop Owner" />
        <div className="flex items-center justify-center min-h-screen">
          <div className="text-center">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
            <p className="mt-4 text-gray-600 dark:text-gray-400">Loading dashboard...</p>
          </div>
        </div>
      </AppLayoutShopOwner>
    );
  }

  return (
    <AppLayoutShopOwner>
      <Head title="Ecommerce Dashboard - Shop Owner" />
      <div className="space-y-6">
        <div>
          <h3 className="text-2xl font-bold text-gray-800 dark:text-white/90">
            Ecommerce Dashboard
          </h3>
          <p className="mt-1 text-gray-500 dark:text-gray-400">
            Overview of your shop's ecommerce performance
          </p>
        </div>

        <EcommerceMetrics stats={stats} />

        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
          <MonthlySalesChart revenueTrend={stats?.revenue_trend || []} />
          <MonthlyTarget 
            thisMonth={stats?.revenue.this_month || 0}
            lastMonth={stats?.revenue.last_month || 0}
          />
        </div>

        <StatisticsChart stats={stats} />

        <RecentOrders orders={stats?.recent_orders || []} />
      </div>
    </AppLayoutShopOwner>
  );
}
