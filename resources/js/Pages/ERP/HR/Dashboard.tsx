import React, { Suspense, lazy, useState, useEffect } from "react";
import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";
import Tooltip from "../../../components/common/Tooltip";

// Icon Components
const UsersIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
  </svg>
);

const CheckCircleIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const StoreIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
  </svg>
);

const CalendarIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

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

// Professional Metric Card Component
const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }: {
  title: string;
  value: number;
  change: number;
  changeType: 'increase' | 'decrease';
  icon: React.FC<{ className?: string }>;
  color: 'success' | 'error' | 'warning' | 'info';
  description: string;
}) => {
  const getColorClasses = () => {
    switch (color) {
      case 'success': return 'from-green-500 to-emerald-600';
      case 'error': return 'from-red-500 to-rose-600';
      case 'warning': return 'from-yellow-500 to-orange-600';
      case 'info': return 'from-blue-500 to-indigo-600';
      default: return 'from-gray-500 to-gray-600';
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700"
      tabIndex={0} aria-label={`${title}: ${value} (${description})`}>
      {/* Animated background gradient */}
      <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

      <div className="relative">
        <div className="flex items-center justify-between mb-4">
          <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="text-white size-7 drop-shadow-sm" />
          </div>

          <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
            changeType === 'increase'
              ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
              : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
          }`}>
            {changeType === 'increase' ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
            {Math.abs(change)}%
          </div>
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
            {title}
          </p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {value.toLocaleString()}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {description}
          </p>
        </div>
      </div>
    </div>
  );
};
// Bar Chart Component for Employee Distribution
const EmployeeDistributionChart: React.FC<{ departments: string[], counts: number[] }> = ({ departments, counts }) => {
  const options: ApexOptions = {
    colors: ["#3170c4"],
    chart: {
      fontFamily: "Outfit, sans-serif",
      type: "bar",
      height: 350,
      toolbar: {
        show: false,
      },
    },
    plotOptions: {
      bar: {
        horizontal: false,
        columnWidth: "39%",
        borderRadius: 5,
        borderRadiusApplication: "end",
      },
    },
    dataLabels: {
      enabled: false,
    },
    stroke: {
      show: true,
      width: 4,
      colors: ["transparent"],
    },
    xaxis: {
      categories: departments,
      axisBorder: {
        show: false,
      },
      axisTicks: {
        show: false,
      },
    },
    legend: {
      show: true,
      position: "top",
      horizontalAlign: "left",
      fontFamily: "Outfit",
    },
    yaxis: {
      title: {
        text: undefined,
      },
    },
    grid: {
      yaxis: {
        lines: {
          show: true,
        },
      },
    },
    fill: {
      opacity: 1,
    },
    tooltip: {
      x: {
        show: false,
      },
      y: {
        formatter: (val: number) => `${val} employees`,
      },
    },
  };

  const series = [
    {
      name: "Employees",
      data: counts,
    },
  ];

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
      <div className="flex items-center justify-between mb-6">
        <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">
          Employee Distribution by Department
        </h3>
      </div>

      <div className="max-w-full overflow-x-auto custom-scrollbar">
        <div className="-ml-5 min-w-[650px] xl:min-w-full pl-2">
          <Suspense
            fallback={
              <div className="h-96 bg-gray-100 dark:bg-gray-800 rounded-2xl animate-pulse"></div>
            }
          >
            <Chart options={options} series={series} type="bar" height={350} />
          </Suspense>
        </div>
      </div>
    </div>
  );
};

// TypeScript interfaces for dashboard data
interface DepartmentData {
  department: string;
  count: number;
  percentage: number;
}

interface StatusData {
  status: string;
  count: number;
}

interface DashboardData {
  headcount: {
    current_headcount: number;
    by_department: DepartmentData[];
    by_status: StatusData[];
    by_location: any[];
    monthly_trend: any[];
  };
  turnover: any;
  attendance: any;
  payroll: any;
  performance: any;
  summary: {
    active_employees: number;
    total_departments: number;
    pending_leave_requests: number;
    this_month_payroll: number;
  };
}

export function HRDashboard() {
  const [dashboardData, setDashboardData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [clockedIn, setClockedIn] = useState(false);
  const [loginTime, setLoginTime] = useState<string | null>(null);
  const [logoutTime, setLogoutTime] = useState<string | null>(null);
  const [lunchBreakTime, setLunchBreakTime] = useState<string | null>(null);
  const [showAttendanceModal, setShowAttendanceModal] = useState(false);

  const handleClockIn = () => {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    setLoginTime(timeString);
    setClockedIn(true);
    setLogoutTime(null);
  };

  const handleClockOut = () => {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    setLogoutTime(timeString);
    setClockedIn(false);
  };

  const handleLunchBreak = () => {
    if (!clockedIn) return;
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    setLunchBreakTime(timeString);
  };

  const computeTotalHours = (start: string | null, end: string | null) => {
    if (!start || !end) return '--';
    const parseTime = (value: string) => {
      const match = value.match(/^(\d{1,2}):(\d{2}):(\d{2})\s?(AM|PM)$/i);
      if (!match) return null;
      const hours = parseInt(match[1], 10);
      const minutes = parseInt(match[2], 10);
      const seconds = parseInt(match[3], 10);
      const period = match[4].toUpperCase();
      const normalizedHours = (hours % 12) + (period === 'PM' ? 12 : 0);
      return normalizedHours * 3600 + minutes * 60 + seconds;
    };

    const startSeconds = parseTime(start);
    const endSeconds = parseTime(end);
    if (startSeconds === null || endSeconds === null) return '--';
    const diffSeconds = Math.max(0, endSeconds - startSeconds);
    const hours = diffSeconds / 3600;
    return `${hours.toFixed(2)} hrs`;
  };

  // Fetch dashboard data
  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true);
        const response = await fetch('/api/hr/dashboard', {
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
        });

        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        setDashboardData(data);
        setError(null);
      } catch (err) {
        console.error('Error fetching dashboard data:', err);
        setError('Failed to load dashboard data');
      } finally {
        setLoading(false);
      }
    };

    fetchDashboardData();
  }, []);

  // Calculate metrics from API data
  const getStatusCount = (status: string): number => {
    if (!dashboardData) return 0;
    const statusData = dashboardData.headcount.by_status.find(s => s.status === status);
    return statusData ? statusData.count : 0;
  };

  const totalEmployees = dashboardData?.headcount.current_headcount || 0;
  const activeEmployees = getStatusCount('active');
  const onLeaveCount = getStatusCount('on_leave');
  const inactiveCount = getStatusCount('inactive');
  const suspendedCount = getStatusCount('suspended');
  const totalDepartments = dashboardData?.summary.total_departments || 0;

  // Calculate percentages
  const employmentRate = totalEmployees > 0 ? ((activeEmployees / totalEmployees) * 100).toFixed(1) : '0.0';
  const leaveRate = totalEmployees > 0 ? ((onLeaveCount / totalEmployees) * 100).toFixed(1) : '0.0';
  const activePercentage = totalEmployees > 0 ? ((activeEmployees / totalEmployees) * 100).toFixed(1) : '0.0';
  const onLeavePercentage = totalEmployees > 0 ? ((onLeaveCount / totalEmployees) * 100).toFixed(1) : '0.0';
  const inactivePercentage = totalEmployees > 0 ? ((inactiveCount / totalEmployees) * 100).toFixed(1) : '0.0';
  const suspendedPercentage = totalEmployees > 0 ? ((suspendedCount / totalEmployees) * 100).toFixed(1) : '0.0';
  const availabilityRate = totalEmployees > 0 ? (((activeEmployees + onLeaveCount) / totalEmployees) * 100).toFixed(1) : '0.0';
  
  // Calculate average employees per department
  const avgPerDepartment = totalDepartments > 0 ? Math.round(totalEmployees / totalDepartments) : 0;

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-40 bg-gray-100 dark:bg-gray-800 rounded-2xl animate-pulse"></div>
          ))}
        </div>
        <div className="h-96 bg-gray-100 dark:bg-gray-800 rounded-2xl animate-pulse"></div>
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="h-80 bg-gray-100 dark:bg-gray-800 rounded-2xl animate-pulse"></div>
          <div className="h-80 bg-gray-100 dark:bg-gray-800 rounded-2xl animate-pulse"></div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl">
        <p className="text-red-600 dark:text-red-400">{error}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Top Metrics Row */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <Tooltip content="Total employees in the system" position="bottom">
          <MetricCard
            title="Total Employees"
            value={totalEmployees}
            change={12}
            changeType="increase"
            icon={UsersIcon}
            color="info"
            description="Total registered employees"
          />
        </Tooltip>
        <Tooltip content="Employees currently working" position="bottom">
          <MetricCard
            title="Active Employees"
            value={activeEmployees}
            change={8}
            changeType="increase"
            icon={CheckCircleIcon}
            color="success"
            description="Currently working"
          />
        </Tooltip>
        <Tooltip content="Number of departments" position="bottom">
          <MetricCard
            title="Departments"
            value={totalDepartments}
            change={5}
            changeType="decrease"
            icon={StoreIcon}
            color="warning"
            description="Active departments"
          />
        </Tooltip>
        <Tooltip content="Employees currently on leave" position="bottom">
          <MetricCard
            title="On Leave"
            value={onLeaveCount}
            change={15}
            changeType="increase"
            icon={CalendarIcon}
            color="error"
            description="Employees on leave"
          />
        </Tooltip>
      </div>

      {/* Attendance Modal */}
      {showAttendanceModal && (
        <div
          className="fixed inset-0 bg-black/80 flex items-center justify-center z-[99999] p-4"
          style={{ backdropFilter: 'blur(10px)' }}
        >
          <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-5xl h-[80vh] flex flex-col">
            {/* Modal Header */}
            <div className="flex items-center justify-between px-6 py-5 border-b border-gray-200 dark:border-slate-700">
              <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Attendance History</h2>
              <button
                onClick={() => setShowAttendanceModal(false)}
                className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors p-1"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Modal Body */}
            <div className="px-6 py-5 flex-1 overflow-hidden">
              <div className="h-full overflow-auto rounded-lg border border-gray-200 dark:border-slate-700">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 dark:bg-slate-800 sticky top-0">
                    <tr>
                      <th className="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Date</th>
                      <th className="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Time In</th>
                      <th className="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Lunch Break</th>
                      <th className="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Time Out</th>
                      <th className="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Total Hours</th>
                      <th className="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
                    {loginTime || logoutTime ? (
                      <tr className="hover:bg-gray-50 dark:hover:bg-slate-800/70">
                        <td className="px-4 py-3 text-gray-900 dark:text-white">
                          {new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                        </td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-200">{loginTime || '--'}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-200">{lunchBreakTime || '--'}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-200">{logoutTime || '--'}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-200">
                          {computeTotalHours(loginTime, logoutTime)}
                        </td>
                        <td className="px-4 py-3">
                          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                            {clockedIn ? 'Active' : 'Completed'}
                          </span>
                        </td>
                      </tr>
                    ) : (
                      <tr>
                        <td colSpan={6} className="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                          No attendance records yet
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="px-6 py-4 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-3">
              <button
                onClick={() => {
                  // Export functionality
                  const data = `Date,Time In,Lunch Break,Time Out,Total Hours,Status\n${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })},${loginTime || '--'},${lunchBreakTime || '--'},${logoutTime || '--'},${computeTotalHours(loginTime, logoutTime)},${clockedIn ? 'Active' : 'Completed'}`;
                  const blob = new Blob([data], { type: 'text/csv' });
                  const url = window.URL.createObjectURL(blob);
                  const a = document.createElement('a');
                  a.href = url;
                  a.download = `attendance-${new Date().getTime()}.csv`;
                  a.click();
                }}
                className="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium transition-all duration-200 text-sm flex items-center gap-2 active:scale-95"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Export
              </button>
              <button
                onClick={() => setShowAttendanceModal(false)}
                className="px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-900 dark:text-white rounded-lg font-medium transition-all duration-200 text-sm"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Employee Distribution Chart */}
      {dashboardData && <EmployeeDistributionChart 
        departments={dashboardData.headcount.by_department.map(d => d.department)}
        counts={dashboardData.headcount.by_department.map(d => d.count)}
      />}

      {/* Bottom Section - Workforce Analytics and Employment Status */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Workforce Analytics */}
        <div className="rounded-2xl bg-white border border-gray-200 dark:bg-white/[0.03] dark:border-gray-800 p-6">
          <div className="flex items-center justify-between mb-6">
            <div>
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                Workforce Analytics
              </h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Snapshot of current workforce health
              </p>
            </div>
            <span className="px-3 py-1 bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 text-xs font-semibold rounded-full">
              Live
            </span>
          </div>
          <div className="grid grid-cols-2 gap-4">
            {/* Employment Rate */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Employment Rate</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-blue-600 dark:text-blue-400">{employmentRate}%</span>
                <span className="px-2 py-0.5 bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 text-xs font-semibold rounded">Active</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Active vs total staff</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-blue-500 rounded-full" style={{ width: `${employmentRate}%` }}></div>
              </div>
            </div>

            {/* Leave Rate */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Leave Rate</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-purple-600 dark:text-purple-400">{leaveRate}%</span>
                <span className="px-2 py-0.5 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 text-xs font-semibold rounded">On Leave</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Currently away from duty</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-purple-500 rounded-full" style={{ width: `${leaveRate}%` }}></div>
              </div>
            </div>

            {/* Avg per Department */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Avg per Department</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-teal-600 dark:text-teal-400">{avgPerDepartment}</span>
                <span className="px-2 py-0.5 bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400 text-xs font-semibold rounded">Employees</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Per department average</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-teal-500 rounded-full" style={{ width: '65%' }}></div>
              </div>
            </div>

            {/* Availability */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Availability</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-orange-600 dark:text-orange-400">{availabilityRate}%</span>
                <span className="px-2 py-0.5 bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 text-xs font-semibold rounded">Ready</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Available to deploy</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-orange-500 rounded-full" style={{ width: `${availabilityRate}%` }}></div>
              </div>
            </div>
          </div>
        </div>

        {/* Employment Status */}
        <div className="rounded-2xl bg-white border border-gray-200 dark:bg-white/[0.03] dark:border-gray-800 p-6">
          <div className="flex items-center justify-between mb-6">
            <div>
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                Employment Status
              </h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Headcount by status with shares
              </p>
            </div>
            <span className="text-xs text-gray-500 dark:text-gray-400 font-medium">
              Today
            </span>
          </div>
          
          <div className="grid grid-cols-2 gap-3">
            {/* Active Employees */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Active Employees</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-green-600 dark:text-green-400">{activeEmployees}</span>
                <span className="px-2 py-0.5 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 text-xs font-semibold rounded">{activePercentage}%</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Currently working</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-green-500 rounded-full" style={{ width: `${activePercentage}%` }}></div>
              </div>
            </div>

            {/* On Leave */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">On Leave</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-orange-600 dark:text-orange-400">{onLeaveCount}</span>
                <span className="px-2 py-0.5 bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 text-xs font-semibold rounded">{onLeavePercentage}%</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Temporary absence</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-orange-500 rounded-full" style={{ width: `${onLeavePercentage}%` }}></div>
              </div>
            </div>

            {/* Inactive */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Inactive</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-gray-600 dark:text-gray-400">{inactiveCount}</span>
                <span className="px-2 py-0.5 bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400 text-xs font-semibold rounded">{inactivePercentage}%</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Not currently employed</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-gray-500 rounded-full" style={{ width: `${inactivePercentage}%` }}></div>
              </div>
            </div>

            {/* Suspended */}
            <div className="p-4 rounded-xl bg-white dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700">
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">Suspended</p>
              <div className="flex items-baseline gap-2 mb-2">
                <span className="text-3xl font-bold text-red-600 dark:text-red-400">{suspendedCount}</span>
                <span className="px-2 py-0.5 bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 text-xs font-semibold rounded">{suspendedPercentage}%</span>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Disciplinary action</p>
              <div className="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div className="h-full bg-red-500 rounded-full" style={{ width: `${suspendedPercentage}%` }}></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
