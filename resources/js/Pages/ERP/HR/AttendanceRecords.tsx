import React, { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";

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

const CalendarIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const SearchIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
  </svg>
);

const DownloadIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
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

const EyeIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

// Metric Card Component
const MetricCard = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description,
}: {
  title: string;
  value: number | string;
  change?: number;
  changeType?: "increase" | "decrease";
  icon: React.FC<{ className?: string }>;
  color: "success" | "error" | "warning" | "info";
  description: string;
}) => {
  const getColorClasses = () => {
    switch (color) {
      case "success":
        return "from-green-500 to-emerald-600";
      case "error":
        return "from-red-500 to-rose-600";
      case "warning":
        return "from-yellow-500 to-orange-600";
      case "info":
        return "from-blue-500 to-indigo-600";
      default:
        return "from-gray-500 to-gray-600";
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

          {change !== undefined && changeType && (
            <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}>
              {changeType === "increase" ? (
                <ArrowUpIcon className="size-3" />
              ) : (
                <ArrowDownIcon className="size-3" />
              )}
              {Math.abs(change)}%
            </div>
          )}
        </div>

        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
            {value}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
        </div>

        
      </div>
    </div>
  );
};

// Mock attendance data
const mockAttendanceData = [
  {
    id: 1,
    name: "Samantha Lopez",
    department: "Operations",
    position: "Store Manager",
    date: "2026-01-20",
    status: "present",
    checkIn: "08:00 AM",
    checkOut: "05:30 PM",
    totalHours: 9.5,
  },
  {
    id: 2,
    name: "Carlos Reyes",
    department: "Sales",
    position: "Sales Specialist",
    date: "2026-01-20",
    status: "present",
    checkIn: "08:45 AM",
    checkOut: "05:45 PM",
    totalHours: 9,
  },
  {
    id: 3,
    name: "Alicia Tan",
    department: "HR",
    position: "HR Generalist",
    date: "2026-01-20",
    status: "leave",
    checkIn: "-",
    checkOut: "-",
    totalHours: 0,
  },
  {
    id: 4,
    name: "Miguel Santos",
    department: "IT",
    position: "Support Engineer",
    date: "2026-01-20",
    status: "absent",
    checkIn: "-",
    checkOut: "-",
    totalHours: 0,
  },
  {
    id: 5,
    name: "Erika Del Rosario",
    department: "Marketing",
    position: "Campaign Lead",
    date: "2026-01-20",
    status: "present",
    checkIn: "08:15 AM",
    checkOut: "01:00 PM",
    totalHours: 4.75,
  },
  {
    id: 6,
    name: "James Wilson",
    department: "Finance",
    position: "Accountant",
    date: "2026-01-20",
    status: "present",
    checkIn: "09:00 AM",
    checkOut: "05:30 PM",
    totalHours: 8.5,
  },
];

// Transform snake_case API response to camelCase for frontend
const transformAttendanceFromApi = (apiRecord: any) => {
  const employee = apiRecord.employee || {};
  
  // Calculate total hours if we have both check-in and check-out times
  let totalHours = 0;
  if (apiRecord.working_hours) {
    totalHours = parseFloat(apiRecord.working_hours);
  } else if (apiRecord.check_in_time && apiRecord.check_out_time) {
    // Fallback calculation if working_hours not provided
    const checkIn = new Date(`2000-01-01 ${apiRecord.check_in_time}`);
    const checkOut = new Date(`2000-01-01 ${apiRecord.check_out_time}`);
    totalHours = (checkOut.getTime() - checkIn.getTime()) / (1000 * 60 * 60);
  }
  
  // Get employee name - try different field combinations
  let employeeName = "Unknown";
  if (employee.first_name && employee.last_name) {
    employeeName = `${employee.first_name} ${employee.last_name}`;
  } else if (employee.name) {
    employeeName = employee.name;
  } else if (employee.email) {
    employeeName = employee.email.split('@')[0]; // Use email username as fallback
  }
  
  // Format date to be more readable
  let formattedDate = apiRecord.date;
  try {
    const dateObj = new Date(apiRecord.date);
    formattedDate = dateObj.toLocaleDateString('en-US', { 
      year: 'numeric', 
      month: 'short', 
      day: 'numeric' 
    });
  } catch (e) {
    // If date parsing fails, keep original
    formattedDate = apiRecord.date;
  }
  
  return {
    id: apiRecord.id,
    name: employeeName,
    department: employee.department || apiRecord.department || "Unknown",
    position: employee.position || apiRecord.position || "Unknown",
    date: formattedDate,
    status: apiRecord.status,
    checkIn: apiRecord.check_in_time || apiRecord.checkInTime || "-",
    checkOut: apiRecord.check_out_time || apiRecord.checkOutTime || "-",
    totalHours: totalHours,
    notes: apiRecord.notes,
  };
};

const ViewAttendance: React.FC = () => {
  const [filterMonth, setFilterMonth] = useState(
    new Date().toISOString().slice(0, 7)
  );
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedStatus, setSelectedStatus] = useState<string>("");
  const [sortBy, setSortBy] = useState<"name" | "date" | "status">("name");
  const [selectedRecord, setSelectedRecord] = useState<any | null>(null);
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);
  const [attendanceData, setAttendanceData] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [paginationMeta, setPaginationMeta] = useState<any>(null);

  // Fetch attendance records from API
  useEffect(() => {
    const fetchAttendance = async () => {
      setIsLoading(true);
      try {
        const params = new URLSearchParams();
        if (filterMonth) {
          const [year, month] = filterMonth.split('-');
          const dateFrom = `${year}-${month}-01`;
          const lastDay = new Date(parseInt(year), parseInt(month), 0).getDate();
          const dateTo = `${year}-${month}-${lastDay}`;
          params.append('date_from', dateFrom);
          params.append('date_to', dateTo);
        }
        if (selectedStatus) params.append('status', selectedStatus);
        params.append('page', String(currentPage));
        params.append('per_page', String(itemsPerPage));

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const response = await fetch(`/api/hr/attendance?${params.toString()}`, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
          },
          credentials: 'include',
        });

        if (!response.ok) {
          const errorText = await response.text();
          console.error('API Error:', response.status, errorText);
          throw new Error(`Failed to fetch attendance: ${response.status}`);
        }

        const data = await response.json();
        
        // Check if response has Laravel pagination structure
        if (data.data && data.current_page) {
          const transformedData = data.data.map(transformAttendanceFromApi);
          setAttendanceData(transformedData);
          setPaginationMeta({
            current_page: data.current_page,
            last_page: data.last_page,
            per_page: data.per_page,
            total: data.total,
          });
        } else {
          const transformedData = Array.isArray(data) ? data.map(transformAttendanceFromApi) : [];
          setAttendanceData(transformedData);
        }
      } catch (error) {
        console.error('Error fetching attendance:', error);
        setAttendanceData([]);
      } finally {
        setIsLoading(false);
      }
    };

    fetchAttendance();
  }, [filterMonth, selectedStatus, currentPage, itemsPerPage]);

  // Calculate statistics
  const stats = useMemo(() => {
    const total = attendanceData.length;
    const present = attendanceData.filter((a) => a.status === "present").length;
    const absent = attendanceData.filter((a) => a.status === "absent").length;
    const leave = attendanceData.filter((a) => a.status === "leave").length;
    const late = attendanceData.filter((a) => a.status === "late").length;
    const presentPercentage = total > 0 ? Math.round((present / total) * 100) : 0;

    return { total, present, absent, leave, late, presentPercentage };
  }, [attendanceData]);

  // Filter and search data
  const filteredData = useMemo(() => {
    return attendanceData
      .filter((item) => {
        const matchesSearch =
          item.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
          item.position.toLowerCase().includes(searchTerm.toLowerCase());
        return matchesSearch;
      })
      .sort((a, b) => {
        if (sortBy === "name") return a.name.localeCompare(b.name);
        if (sortBy === "status") return a.status.localeCompare(b.status);
        return new Date(b.date).getTime() - new Date(a.date).getTime();
      });
  }, [attendanceData, searchTerm, sortBy]);

  // Convert filtered attendance data to CSV and trigger download
  const convertToCSV = (data: any[]) => {
    const headers = [
      "Employee",
      "Position",
      "Department",
      "Date",
      "Status",
      "Check In",
      "Check Out",
      "Total Hours",
      "Notes",
    ];

    const escape = (value: any) => {
      const v = value === null || value === undefined ? "" : String(value);
      return `"${v.replace(/"/g, '""')}"`;
    };

    const rows = data.map((d) => [
      d.name,
      d.position,
      d.department,
      d.date,
      d.status,
      d.checkIn,
      d.checkOut,
      d.totalHours ?? "",
      d.notes ?? "",
    ]);

    return [headers, ...rows].map((row) => row.map(escape).join(",")).join("\r\n");
  };

  const handleDownloadCSV = () => {
    try {
      const dataToExport = filteredData; // export the filtered (unpaginated) dataset
      if (!dataToExport || dataToExport.length === 0) {
        alert("No attendance records to download");
        return;
      }

      const csv = convertToCSV(dataToExport);
      const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      const monthPart = filterMonth || new Date().toISOString().slice(0, 7);
      const timePart = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
      a.setAttribute("download", `attendance_${monthPart}_${timePart}.csv`);
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      console.error("Error downloading CSV:", err);
      alert("Failed to download attendance CSV.");
    }
  };

  // Pagination calculations - use server pagination if available
  const usePagination = !!paginationMeta;
  const totalPages = usePagination ? paginationMeta.last_page : Math.ceil(filteredData.length / itemsPerPage);
  const startIndex = usePagination ? ((paginationMeta.current_page - 1) * paginationMeta.per_page) : (currentPage - 1) * itemsPerPage;
  const endIndex = usePagination ? startIndex + attendanceData.length : startIndex + itemsPerPage;
  const paginatedData = usePagination ? attendanceData : filteredData.slice(startIndex, endIndex);
  const totalItems = usePagination ? paginationMeta.total : filteredData.length;

  // Reset to page 1 when filters change
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, selectedStatus, sortBy]);

  // Get unique departments from actual attendance data
  const departments = useMemo(() => {
    return Array.from(
      new Set(attendanceData.map((a) => a.department).filter(d => d && d !== "Unknown"))
    ).sort();
  }, [attendanceData]);

  const getStatusColor = (status: string) => {
    switch (status) {
      case "present":
        return "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300";
      case "late":
        return "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300";
      case "absent":
        return "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300";
      case "leave":
        return "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300";
      default:
        return "bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300";
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case "present":
        return <CheckCircleIcon className="size-4" />;
      case "late":
        return <ClockIcon className="size-4" />;
      case "absent":
        return <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>;
      case "leave":
        return <CalendarIcon className="size-4" />;
      default:
        return null;
    }
  };



  return (
    <div className="w-full space-y-6">
      {/* Header */}
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 dark:text-white">
            Attendance Records
          </h1>
          <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Track and manage employee attendance with detailed records
          </p>
        </div>

        <div className="mt-4 flex justify-end">
          <button
            onClick={handleDownloadCSV}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-gray-300 dark:bg-gray-900 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            title="Download attendance (CSV)"
          >
            <DownloadIcon className="size-4 text-gray-600 dark:text-gray-300" />
            <span>Download</span>
          </button>
        </div>
      </div>

      {/* Metric Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6">
        <MetricCard
          title="Total Employees"
          value={stats.total}
          icon={UsersIcon}
          color="info"
          description="Total workforce"
          change={5}
          changeType="increase"
        />
        <MetricCard
          title="Present Today"
          value={stats.present}
          icon={CheckCircleIcon}
          color="success"
          description={`${stats.presentPercentage}% attendance rate`}
          change={12}
          changeType="increase"
        />
        <MetricCard
          title="Late"
          value={stats.late}
          icon={ClockIcon}
          color="warning"
          description="Checked in late"
        />
        <MetricCard
          title="Absent"
          value={stats.absent}
          icon={UsersIcon}
          color="error"
          description="Not marked present"
          change={3}
          changeType="decrease"
        />
        <MetricCard
          title="On Leave"
          value={stats.leave}
          icon={CalendarIcon}
          color="warning"
          description="Approved leave"
          change={0}
          changeType="increase"
        />
      </div>

      {/* Filters and Search */}
      <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {/* Search Bar */}
          <div className="lg:col-span-1">
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Search
            </label>
            <div className="relative">
              <SearchIcon className="absolute left-3 top-1/2 -translate-y-1/2 size-5 text-gray-400" />
              <input
                type="text"
                placeholder="Search by employee name or position..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
              />
            </div>
          </div>

          {/* Month Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Month
            </label>
            <input
              type="month"
              value={filterMonth}
              onChange={(e) => setFilterMonth(e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Status
            </label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-700 rounded-xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300"
            >
              <option value="">All Status</option>
              <option value="present">Present</option>
              <option value="late">Late</option>
              <option value="absent">Absent</option>
              <option value="leave">Leave</option>
            </select>
          </div>
        </div>
      </div>

      {/* Attendance Table */}
      <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-800">
              <tr>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Employee
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Date
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Status
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Check In
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Check Out
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Hours
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-gray-900 dark:text-white">
                  Action
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
              {isLoading ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center">
                    <div className="flex flex-col items-center justify-center text-gray-500 dark:text-gray-400">
                      <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-2"></div>
                      <p className="text-lg font-medium">Loading attendance records...</p>
                    </div>
                  </td>
                </tr>
              ) : paginatedData.length > 0 ? (
                paginatedData.map((record, idx) => (
                  <tr
                    key={idx}
                    className="hover:bg-gray-50 dark:hover:bg-gray-900/30 transition-colors duration-200"
                  >
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="flex-shrink-0 h-10 w-10">
                          <div className="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                            <span className="text-blue-600 dark:text-blue-300 font-medium text-sm">
                              {record.name
                                .split(" ")
                                .map((n) => n[0])
                                .join("")
                                .toUpperCase()}
                            </span>
                          </div>
                        </div>
                        <div>
                          <p className="font-medium text-gray-900 dark:text-white">
                            {record.name}
                          </p>
                          <p className="text-sm text-gray-500 dark:text-gray-400">
                            {record.position}
                          </p>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                      {record.date}
                    </td>
                    <td className="px-6 py-4">
                      <div className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium capitalize w-fit ${getStatusColor(record.status)}`}>
                        {record.status}
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        {record.checkIn}
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        {record.checkOut}
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="inline-flex items-center px-3 py-1.5 rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-sm font-medium">
                        {record.totalHours}h
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <button
                        onClick={() => {
                          setSelectedRecord(record);
                          setIsViewModalOpen(true);
                        }}
                        className="inline-flex items-center justify-center p-2 rounded-lg text-blue-600 hover:bg-blue-100 dark:text-blue-400 dark:hover:bg-blue-900/30 transition-colors duration-200"
                        title="View attendance details"
                      >
                        <EyeIcon className="size-5" />
                      </button>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center">
                    <p className="text-gray-500 dark:text-gray-400">
                      No attendance records found
                    </p>
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {(totalItems > 0 || paginatedData.length > 0) && (
          <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-800">
            <div className="flex items-center justify-between">
              <div className="text-sm text-gray-700 dark:text-gray-300">
                Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                <span className="font-medium">{Math.min(endIndex, totalItems)}</span> of{" "}
                <span className="font-medium">{totalItems}</span>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                  disabled={currentPage === 1}
                  className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  title="Previous page"
                >
                  <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                  </svg>
                </button>

                {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                  // Show first page, last page, current page, and pages around current
                  if (
                    page === 1 ||
                    page === totalPages ||
                    (page >= currentPage - 1 && page <= currentPage + 1)
                  ) {
                    return (
                      <button
                        key={page}
                        onClick={() => setCurrentPage(page)}
                        className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
                          currentPage === page
                            ? "bg-blue-600 text-white"
                            : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                        }`}
                      >
                        {page}
                      </button>
                    );
                  } else if (page === currentPage - 2 || page === currentPage + 2) {
                    return (
                      <span key={page} className="px-2 text-gray-500 dark:text-gray-400">
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
                  <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* View Modal - Attendance Receipt */}
      {isViewModalOpen && selectedRecord && createPortal(
        <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white dark:bg-gray-900 rounded-2xl max-w-3xl w-full max-h-[95vh] p-8 space-y-6 overflow-y-auto">
            <h2 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Attendance Receipt</h2>

            <div className="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950/50 p-5 shadow-sm space-y-4">
              <div className="flex items-center justify-between text-sm uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">
                <span>Record #{selectedRecord.id.toString().padStart(4, "0")}</span>
                <span>{new Date(selectedRecord.date).toLocaleDateString()}</span>
              </div>

              <div className="border-t border-dashed border-gray-200 dark:border-gray-800" />

              <div className="space-y-3 text-base font-mono">
                <div className="flex justify-between">
                  <span className="text-gray-600 dark:text-gray-400">Employee</span>
                  <span className="text-gray-900 dark:text-white font-semibold">{selectedRecord.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600 dark:text-gray-400">Position</span>
                  <span className="text-gray-900 dark:text-white">{selectedRecord.position}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600 dark:text-gray-400">Department</span>
                  <span className="text-gray-900 dark:text-white">{selectedRecord.department}</span>
                </div>
              </div>

              <div className="border-t border-dashed border-gray-200 dark:border-gray-800" />

              <div className="grid grid-cols-2 gap-4 text-base font-mono">
                <div className="space-y-1">
                  <p className="text-gray-500 dark:text-gray-400">Check-In</p>
                  <p className="text-gray-900 dark:text-white font-semibold">{selectedRecord.checkIn || "—"}</p>
                </div>
                <div className="space-y-1 text-right">
                  <p className="text-gray-500 dark:text-gray-400">Check-Out</p>
                  <p className="text-gray-900 dark:text-white font-semibold">{selectedRecord.checkOut || "—"}</p>
                </div>
                <div className="space-y-1">
                  <p className="text-gray-500 dark:text-gray-400">Total Hours</p>
                  <p className="text-gray-900 dark:text-white font-semibold">{selectedRecord.totalHours ? `${selectedRecord.totalHours}h` : "—"}</p>
                </div>
                <div className="space-y-1 text-right">
                  <p className="text-gray-500 dark:text-gray-400">Status</p>
                  <p className="text-gray-900 dark:text-white font-semibold capitalize">{selectedRecord.status}</p>
                </div>
              </div>

              <div className="border-t border-dashed border-gray-200 dark:border-gray-800" />

              <div className="flex items-center justify-between text-sm uppercase tracking-[0.08em] text-gray-500 dark:text-gray-400">
                <span>Generated by SoleSpace</span>
                <span>{new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}</span>
              </div>
            </div>

            <div className="flex gap-3 pt-4">
              <button
                onClick={() => setIsViewModalOpen(false)}
                className="flex-1 px-4 py-3 rounded-lg bg-gray-600 text-white text-base hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 transition-colors duration-200 font-medium"
              >
                Close
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

// Export with both names for compatibility
export default ViewAttendance;
export { ViewAttendance as AttendanceRecords };
