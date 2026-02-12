import { useEffect, useRef } from "react";
import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";
import flatpickr from "flatpickr";
import ChartTab from "../common/ChartTab";
import { CalenderIcon } from "../../icons";

interface DashboardStats {
  revenue: {
    total: number;
    this_month: number;
    last_month: number;
    growth_percentage: number;
    average_order: number;
  };
  orders: {
    total: number;
    pending: number;
    processing: number;
    shipped: number;
    completed: number;
    cancelled: number;
    growth_percentage: number;
  };
  products: {
    total: number;
    active: number;
    low_stock: number;
    out_of_stock: number;
  };
  customers: {
    total: number;
    unique_customers: number;
    guest_orders: number;
    repeat_customers: number;
  };
  top_products: Array<any>;
  recent_orders: Array<any>;
  revenue_trend: Array<any>;
}

interface StatisticsChartProps {
  stats?: DashboardStats | null;
}

export default function StatisticsChart({ stats }: StatisticsChartProps) {
  const datePickerRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!datePickerRef.current) return;

    const today = new Date();
    const sevenDaysAgo = new Date();
    sevenDaysAgo.setDate(today.getDate() - 6);

    const fp = flatpickr(datePickerRef.current, {
      mode: "range",
      appendTo: document.body as HTMLElement,
      monthSelectorType: "static",
      dateFormat: "M d",
      defaultDate: [sevenDaysAgo, today],
      clickOpens: true,
      prevArrow:
        '<svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 15L7.5 10L12.5 5" stroke="" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      nextArrow:
        '<svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.5 15L12.5 10L7.5 5" stroke="" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    });

    return () => {
      if (!Array.isArray(fp)) {
        fp.destroy();
      }
    };
  }, []);

  // Prepare data from stats
  const ordersData = stats ? [
    { label: 'Pending', value: stats.orders.pending, color: '#FFA500' },
    { label: 'Processing', value: stats.orders.processing, color: '#465FFF' },
    { label: 'Shipped', value: stats.orders.shipped, color: '#9CB9FF' },
    { label: 'Completed', value: stats.orders.completed, color: '#039855' },
    { label: 'Cancelled', value: stats.orders.cancelled, color: '#D92D20' },
  ] : [];

  const categories = ordersData.map(item => item.label);
  const values = ordersData.map(item => item.value);
  const colors = ordersData.map(item => item.color);

  const options: ApexOptions = {
    legend: {
      show: false,
      position: "top",
      horizontalAlign: "left",
    },
    colors: colors.length > 0 ? colors : ["#465FFF"],
    chart: {
      fontFamily: "Outfit, sans-serif",
      height: 310,
      type: "bar",
      toolbar: {
        show: false,
      },
    },
    plotOptions: {
      bar: {
        horizontal: false,
        columnWidth: "55%",
        borderRadius: 5,
        borderRadiusApplication: "end",
      },
    },
    dataLabels: {
      enabled: false,
    },
    stroke: {
      show: true,
      width: 2,
      colors: ["transparent"],
    },
    grid: {
      xaxis: {
        lines: {
          show: false,
        },
      },
      yaxis: {
        lines: {
          show: true,
        },
      },
    },
    tooltip: {
      enabled: true,
      y: {
        formatter: (val: number) => `${val} orders`,
      },
    },
    xaxis: {
      type: "category",
      categories: categories.length > 0 ? categories : ["No Data"],
      axisBorder: {
        show: false,
      },
      axisTicks: {
        show: false,
      },
    },
    yaxis: {
      labels: {
        style: {
          fontSize: "12px",
          colors: ["#6B7280"],
        },
      },
      title: {
        text: "Orders",
        style: {
          fontSize: "12px",
          color: "#6B7280",
        },
      },
    },
  };

  const series = [
    {
      name: "Orders",
      data: values.length > 0 ? values : [0],
    },
  ];

  return (
    <div className="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
      <div className="flex flex-col gap-5 mb-6 sm:flex-row sm:justify-between">
        <div className="w-full">
          <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">
            Order Statistics
          </h3>
          <p className="mt-1 text-gray-500 text-theme-sm dark:text-gray-400">
            Orders breakdown by status
          </p>
        </div>
        <div className="flex items-center gap-3 sm:justify-end">
          <ChartTab />
          <div className="relative inline-flex items-center">
            <CalenderIcon className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 lg:left-3 lg:top-1/2 lg:translate-x-0 lg:-translate-y-1/2 size-5 text-gray-500 dark:text-gray-400 pointer-events-none z-10" />
            <input
              ref={datePickerRef}
              className="h-10 w-10 lg:w-40 lg:h-auto  lg:pl-10 lg:pr-3 lg:py-2 rounded-lg border border-gray-200 bg-white text-sm font-medium text-transparent lg:text-gray-700 outline-none dark:border-gray-700 dark:bg-gray-800 dark:lg:text-gray-300 cursor-pointer"
              placeholder="Select date range"
            />
          </div>
        </div>
      </div>

      <div className="max-w-full overflow-x-auto custom-scrollbar">
        <div className="min-w-[600px] xl:min-w-full">
          {stats ? (
            <>
              <Chart options={options} series={series} type="bar" height={310} />
              <div className="mt-4 flex flex-wrap justify-center gap-4">
                {ordersData.map((item, index) => (
                  <div key={index} className="flex items-center gap-2">
                    <div 
                      className="w-3 h-3 rounded-full" 
                      style={{ backgroundColor: item.color }}
                    ></div>
                    <span className="text-sm text-gray-600 dark:text-gray-400">
                      {item.label}: <span className="font-medium text-gray-800 dark:text-white/90">{item.value}</span>
                    </span>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <div className="flex items-center justify-center h-[310px] text-gray-500 dark:text-gray-400">
              No statistics data available
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
