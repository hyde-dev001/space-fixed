import { useMemo, useState } from "react";
import { createPortal } from "react-dom";

import { useEffect, useState } from "react";

type SlipStatus = "processed" | "pending" | "paid";

type Deduction = {
    name: string;
    amount: number;
};

type SlipRecord = {
    id: string;
    employeeName: string;
    employeeId: string;
    department: string;
    month: string;
    payPeriod: string;
    grossPay: number;
    deductions: number;
    netPay: number;
    generatedOn: string;
    status: SlipStatus;
    deductionDetails?: {
        withholding_tax: number;
        sss: number;
        philhealth: number;
        pagibig: number;
        other: number;
    };
    // Hours breakdown (to match Generate Payslip output)
    totalRegularHours?: number;
    totalOvertimeHours?: number;
    totalUndertimeHours?: number;
    totalAbsentDays?: number;
};

const CheckIcon = ({ className = "size-5" }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
    </svg>
);

const ClockIcon = ({ className = "size-5" }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const CheckCircleIcon = ({ className = "size-5" }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const AlertIcon = ({ className = "size-5" }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
);

// Transform function to convert snake_case API response to camelCase
const transformPayrollFromApi = (apiPayroll: any): SlipRecord => {
    const employeeName = apiPayroll.employee 
        ? `${apiPayroll.employee.first_name || ''} ${apiPayroll.employee.last_name || ''}`.trim()
        : 'Unknown';
    
    const department = apiPayroll.employee?.department || 'N/A';
    const employeeIdDisplay = apiPayroll.employee?.employee_id || 'N/A';
    
    return {
        id: `PS-${apiPayroll.id}`,
        employeeName: employeeName,
        employeeId: employeeIdDisplay,
        department: department,
        month: apiPayroll.payroll_period || apiPayroll.pay_period_start,
        payPeriod: apiPayroll.pay_period_start && apiPayroll.pay_period_end 
            ? `${new Date(apiPayroll.pay_period_start).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${new Date(apiPayroll.pay_period_end).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
            : apiPayroll.payroll_period || 'N/A',
        grossPay: parseFloat(apiPayroll.gross_salary || apiPayroll.base_salary || 0),
        deductions: parseFloat(apiPayroll.total_deductions || apiPayroll.deductions || 0),
        netPay: parseFloat(apiPayroll.net_salary || 0),
        generatedOn: apiPayroll.generated_at 
            ? new Date(apiPayroll.generated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            : new Date(apiPayroll.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
        status: apiPayroll.status as SlipStatus,
        deductionDetails: {
            withholding_tax: parseFloat(apiPayroll.tax_deductions || apiPayroll.tax_amount || 0),
            sss: parseFloat(apiPayroll.sss_contributions || 0),
            philhealth: parseFloat(apiPayroll.philhealth || 0),
            pagibig: parseFloat(apiPayroll.pag_ibig || 0),
            other: 0,
        },
        totalRegularHours: apiPayroll.attendance_days ? apiPayroll.attendance_days * 8 : 0,
        totalOvertimeHours: parseFloat(apiPayroll.overtime_hours || 0),
        totalUndertimeHours: 0,
        totalAbsentDays: apiPayroll.leave_days || 0,
    };
};

const mockSlipData: SlipRecord[] = [
    {
        id: "PS-1023",
        employeeName: "Ava Thompson",
        employeeId: "EMP-1203",
        department: "Engineering",
        month: "January 2026",
        payPeriod: "Jan 1 - Jan 31",
        grossPay: 7200,
        deductions: 950,
        netPay: 6250,
        generatedOn: "Jan 30, 2026",
        status: "processed",
        deductionDetails: {
            withholding_tax: 350,
            sss: 285,
            philhealth: 180,
            pagibig: 100,
            other: 35,
        },
        totalRegularHours: 168,
        totalOvertimeHours: 10,
        totalUndertimeHours: 0,
        totalAbsentDays: 2,
    },
    {
        id: "PS-1022",
        employeeName: "Liam Chen",
        employeeId: "EMP-1188",
        department: "Product",
        month: "January 2026",
        payPeriod: "Jan 1 - Jan 31",
        grossPay: 6800,
        deductions: 820,
        netPay: 5980,
        generatedOn: "Jan 30, 2026",
        status: "processed",
        deductionDetails: {
            withholding_tax: 310,
            sss: 255,
            philhealth: 165,
            pagibig: 80,
            other: 10,
        },
        totalRegularHours: 170,
        totalOvertimeHours: 6,
        totalUndertimeHours: 2,
        totalAbsentDays: 1,
    },
    {
        id: "PS-1021",
        employeeName: "Noah Patel",
        employeeId: "EMP-1166",
        department: "Finance",
        month: "January 2026",
        payPeriod: "Jan 1 - Jan 31",
        grossPay: 6100,
        deductions: 690,
        netPay: 5410,
        generatedOn: "Jan 30, 2026",
        status: "processed",
        deductionDetails: {
            withholding_tax: 280,
            sss: 230,
            philhealth: 150,
            pagibig: 0,
            other: 30,
        },
        totalRegularHours: 160,
        totalOvertimeHours: 12,
        totalUndertimeHours: 4,
        totalAbsentDays: 2,
    },
    {
        id: "PS-1020",
        employeeName: "Sophia Reyes",
        employeeId: "EMP-1152",
        department: "Operations",
        month: "December 2025",
        payPeriod: "Dec 1 - Dec 31",
        grossPay: 6400,
        deductions: 730,
        netPay: 5670,
        generatedOn: "Dec 29, 2025",
        status: "processed",
        deductionDetails: {
            withholding_tax: 290,
            sss: 245,
            philhealth: 160,
            pagibig: 25,
            other: 10,
        },
        totalRegularHours: 168,
        totalOvertimeHours: 8,
        totalUndertimeHours: 0,
        totalAbsentDays: 1,
    },
    {
        id: "PS-1019",
        employeeName: "Mason Clark",
        employeeId: "EMP-1144",
        department: "Support",
        month: "December 2025",
        payPeriod: "Dec 1 - Dec 31",
        grossPay: 5200,
        deductions: 610,
        netPay: 4590,
        generatedOn: "Dec 29, 2025",
        status: "processed",
        deductionDetails: {
            withholding_tax: 220,
            sss: 195,
            philhealth: 130,
            pagibig: 50,
            other: 15,
        },
        totalRegularHours: 150,
        totalOvertimeHours: 4,
        totalUndertimeHours: 6,
        totalAbsentDays: 3,
    },
    {
        id: "PS-1018",
        employeeName: "Olivia Carter",
        employeeId: "EMP-1129",
        department: "Marketing",
        month: "November 2025",
        payPeriod: "Nov 1 - Nov 30",
        grossPay: 5900,
        deductions: 680,
        netPay: 5220,
        generatedOn: "Nov 28, 2025",
        status: "processed",
        deductionDetails: {
            withholding_tax: 260,
            sss: 225,
            philhealth: 145,
            pagibig: 40,
            other: 10,
        },
        totalRegularHours: 160,
        totalOvertimeHours: 6,
        totalUndertimeHours: 2,
        totalAbsentDays: 2,
    },
    {
        id: "PS-1017",
        employeeName: "Ethan Brooks",
        employeeId: "EMP-1118",
        department: "Engineering",
        month: "November 2025",
        payPeriod: "Nov 1 - Nov 30",
        grossPay: 7050,
        deductions: 940,
        netPay: 6110,
        generatedOn: "Nov 28, 2025",
        status: "processed",
        deductionDetails: {
            withholding_tax: 340,
            sss: 270,
            philhealth: 175,
            pagibig: 120,
            other: 35,
        },
        totalRegularHours: 158,
        totalOvertimeHours: 14,
        totalUndertimeHours: 0,
        totalAbsentDays: 1,
    },
    {
        id: "PS-1016",
        employeeName: "Mia Lopez",
        employeeId: "EMP-1105",
        department: "HR",
        month: "October 2025",
        payPeriod: "Oct 1 - Oct 31",
        grossPay: 5600,
        deductions: 640,
        netPay: 4960,
        generatedOn: "Oct 29, 2025",
        status: "processed",
        deductionDetails: {
            withholding_tax: 240,
            sss: 210,
            philhealth: 140,
            pagibig: 35,
            other: 15,
        },
        totalRegularHours: 168,
        totalOvertimeHours: 5,
        totalUndertimeHours: 3,
        totalAbsentDays: 1,
    },
];

const pageSize = 7;

const statusStyles: Record<SlipStatus, string> = {
    processed: "bg-green-100 text-green-700 border border-green-200",
    pending: "bg-yellow-100 text-yellow-700 border border-yellow-200",
    paid: "bg-blue-100 text-blue-700 border border-blue-200",
};

// Action Icons
const EyeIcon = ({ className = "size-5" }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
    </svg>
);

// Utilities
const formatPHP = (value: number) =>
    value.toLocaleString("en-PH", { style: "currency", currency: "PHP" });

const getInitials = (name: string) =>
    name
        .split(" ")
        .filter(Boolean)
        .map((n) => n[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();

const DownloadIcon = ({ className = "size-5" }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 25 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12.6686 16.75C12.4526 16.75 12.2579 16.6587 12.1211 16.5126L7.5115 11.9059C7.21851 11.6131 7.21836 11.1382 7.51116 10.8452C7.80396 10.5523 8.27883 10.5521 8.57182 10.8449L11.9186 14.1896V4C11.9186 3.58579 12.2544 3.25 12.6686 3.25C13.0828 3.25 13.4186 3.58579 13.4186 4V14.1854L16.7615 10.8449C17.0545 10.5521 17.5294 10.5523 17.8222 10.8453C18.115 11.1383 18.1148 11.6131 17.8218 11.9059L13.2469 16.4776C13.1093 16.644 12.9013 16.75 12.6686 16.75ZM5.41663 16C5.41663 15.5858 5.08084 15.25 4.66663 15.25C4.25241 15.25 3.91663 15.5858 3.91663 16V18.5C3.91663 19.7426 4.92399 20.75 6.16663 20.75H19.1675C20.4101 20.75 21.4175 19.7426 21.4175 18.5V16C21.4175 15.5858 21.0817 15.25 20.6675 15.25C20.2533 15.25 19.9175 15.5858 19.9175 16V18.5C19.9175 18.9142 19.5817 19.25 19.1675 19.25H6.16663C5.75241 19.25 5.41663 18.9142 5.41663 18.5V16Z" />
    </svg>
);

export default function ViewSlip() {
    const [slipData, setSlipData] = useState<SlipRecord[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState<string>("");
    const [month, setMonth] = useState<string>("");
    const [page, setPage] = useState(1);
    const [selectedSlip, setSelectedSlip] = useState<SlipRecord | null>(null);
    const [paginationMeta, setPaginationMeta] = useState<any>(null);

    // Fetch payroll data from API
    useEffect(() => {
        const fetchPayrolls = async () => {
            setIsLoading(true);
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                const params = new URLSearchParams();
                if (search) params.append('search', search);
                if (status) params.append('status', status);
                if (month) params.append('period', month);
                params.append('page', page.toString());
                params.append('per_page', pageSize.toString());

                const response = await fetch(`/api/hr/payroll?${params.toString()}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || '',
                        'Accept': 'application/json',
                    },
                    credentials: 'include',
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                console.log('Payroll API response:', data);

                if (data.data && Array.isArray(data.data)) {
                    const transformedData = data.data.map(transformPayrollFromApi);
                    setSlipData(transformedData);
                    setPaginationMeta({
                        current_page: data.current_page,
                        last_page: data.last_page,
                        per_page: data.per_page,
                        total: data.total,
                    });
                } else if (Array.isArray(data)) {
                    const transformedData = data.map(transformPayrollFromApi);
                    setSlipData(transformedData);
                    setPaginationMeta(null);
                } else {
                    console.error('Unexpected API response format:', data);
                    setSlipData([]);
                }
            } catch (error) {
                console.error('Error fetching payrolls:', error);
                setSlipData([]);
            } finally {
                setIsLoading(false);
            }
        };

        fetchPayrolls();
    }, [search, status, month, page]);

    const months = useMemo(
        () => Array.from(new Set(slipData.map((s) => s.month))),
        [slipData]
    );

    const filtered = useMemo(() => {
        // If server pagination is active, return data as-is (filtering done server-side)
        if (paginationMeta) {
            return slipData;
        }
        
        // Client-side filtering
        const term = search.trim().toLowerCase();
        return slipData.filter((s) => {
            const matchesSearch = term
                ? [s.employeeName, s.employeeId, s.department, s.id]
                        .join(" ")
                        .toLowerCase()
                        .includes(term)
                : true;
            const matchesStatus = status ? s.status === status : true;
            const matchesMonth = month ? s.month === month : true;
            return matchesSearch && matchesStatus && matchesMonth;
        });
    }, [search, status, month, slipData, paginationMeta]);

    const paginated = useMemo(() => {
        // If server pagination is active, use the data as-is
        if (paginationMeta) {
            return filtered;
        }
        
        // Client-side pagination
        const start = (page - 1) * pageSize;
        return filtered.slice(start, start + pageSize);
    }, [filtered, page, paginationMeta]);

    const totalPages = paginationMeta ? paginationMeta.last_page : Math.max(1, Math.ceil(filtered.length / pageSize));

    const openSlip = (slip: SlipRecord) => setSelectedSlip(slip);
    const closeSlip = () => setSelectedSlip(null);

    const resetPage = () => setPage(1);

    const handleSearch = (value: string) => {
        setSearch(value);
        resetPage();
    };

    const handleStatus = (value: string) => {
        setStatus(value);
        resetPage();
    };

    const handleMonth = (value: string) => {
        setMonth(value);
        resetPage();
    };

    const startIndex = (page - 1) * pageSize;
    const endIndex = Math.min(startIndex + pageSize, filtered.length);

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-2">
                <h1 className="text-2xl font-semibold text-gray-900 dark:text-white">View Slip</h1>
                <p className="text-gray-600 dark:text-gray-400">Review and download employee payslips by period.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="md:col-span-2">
                    <label className="text-sm text-gray-600 dark:text-gray-300">Search</label>
                    <input
                        value={search}
                        onChange={(e) => handleSearch(e.target.value)}
                        placeholder="Search by name, ID, or department"
                        className="mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
                    />
                </div>
                <div>
                    <label className="text-sm text-gray-600 dark:text-gray-300">Month</label>
                    <select
                        value={month}
                        onChange={(e) => handleMonth(e.target.value)}
                        aria-label="Filter by month"
                        className="mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
                    >
                        <option value="">All</option>
                        {months.map((m) => (
                            <option key={m} value={m}>{m}</option>
                        ))}
                    </select>
                </div>
            </div>

            <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
                <div className="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Payslips</h3>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800 text-sm">
                        <thead className="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th className="px-6 py-3 text-left">Employee</th>
                                <th className="px-6 py-3 text-left">Month</th>
                                <th className="px-6 py-3 text-left">Gross</th>
                                <th className="px-6 py-3 text-left">Net</th>
                                <th className="px-6 py-3 text-left">Status</th>
                                <th className="px-6 py-3 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                            {isLoading ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center">
                                        <div className="flex flex-col items-center justify-center space-y-3">
                                            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                                            <p className="text-sm text-gray-500 dark:text-gray-400">Loading payslips...</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : paginated.length === 0 ? (
                                <tr>
                                    <td className="px-6 py-6 text-center text-gray-500 dark:text-gray-400" colSpan={6}>
                                        No payslips found.
                                    </td>
                                </tr>
                            ) : (
                                paginated.map((slip) => (
                                <tr key={slip.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td className="px-6 py-4">
                                        <div className="flex items-center gap-3">
                                            <div className="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                                <span className="text-blue-600 dark:text-blue-300 font-medium text-sm">{getInitials(slip.employeeName)}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="font-semibold text-gray-900 dark:text-white">{slip.employeeName}</span>
                                                <span className="text-xs text-gray-500 dark:text-gray-400">{slip.employeeId} · {slip.department}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 text-gray-700 dark:text-gray-300">
                                        <div className="flex flex-col">
                                            <span className="font-medium">{slip.month}</span>
                                            <span className="text-xs text-gray-500 dark:text-gray-400">{slip.payPeriod}</span>
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 text-gray-700 dark:text-gray-300">{formatPHP(slip.grossPay)}</td>
                                    <td className="px-6 py-4 text-gray-900 dark:text-white font-semibold">{formatPHP(slip.netPay)}</td>
                                    <td className="px-6 py-4">
                                        <span className={`inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold ${statusStyles[slip.status]}`}>
                                            {slip.status.charAt(0).toUpperCase() + slip.status.slice(1)}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4 text-center">
                                        <button
                                            onClick={() => openSlip(slip)}
                                            className="inline-flex items-center justify-center p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                                            title="View payslip details"
                                            aria-label="View payslip details"
                                        >
                                            <EyeIcon className="size-5 text-blue-600 dark:text-blue-400" />
                                        </button>
                                    </td>
                                </tr>
                            ))
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="px-6 py-4 border-t border-gray-100 dark:border-gray-800">
                    <div className="flex items-center justify-between">
                        <div className="text-sm text-gray-700 dark:text-gray-300">
                            Showing <span className="font-medium">{filtered.length === 0 ? 0 : startIndex + 1}</span> to <span className="font-medium">{endIndex}</span> of <span className="font-medium">{filtered.length}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
                                disabled={page === 1}
                                className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                title="Previous page"
                            >
                                <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>

                            {Array.from({ length: totalPages }, (_, i) => i + 1).map((p) => {
                                if (p === 1 || p === totalPages || (p >= page - 1 && p <= page + 1)) {
                                    return (
                                        <button
                                            key={p}
                                            onClick={() => setPage(p)}
                                            className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
                                                page === p
                                                    ? "bg-blue-600 text-white"
                                                    : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                                            }`}
                                        >
                                            {p}
                                        </button>
                                    );
                                } else if (p === page - 2 || p === page + 2) {
                                    return (
                                        <span key={p} className="px-2 text-gray-500 dark:text-gray-400">
                                            ...
                                        </span>
                                    );
                                }
                                return null;
                            })}

                            <button
                                onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
                                disabled={page === totalPages}
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
            </div>

            {selectedSlip && createPortal(
                <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
                    <div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto p-8">
                        <div className="flex items-start justify-between mb-4">
                            <div>
                                <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Payslip Details</h3>
                                <p className="text-gray-500 dark:text-gray-400 text-sm">{selectedSlip.month} · {selectedSlip.payPeriod}</p>
                            </div>
                            <button
                                onClick={closeSlip}
                                className="text-2xl text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                            >
                                ×
                            </button>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div className="p-4 rounded-xl border border-gray-200 dark:border-gray-800">
                                <p className="text-sm text-gray-500 dark:text-gray-400">Employee</p>
                                <div className="mt-1 flex items-center gap-3">
                                    <div className="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                        <span className="text-blue-600 dark:text-blue-300 font-medium text-sm">{getInitials(selectedSlip.employeeName)}</span>
                                    </div>
                                    <div>
                                        <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedSlip.employeeName}</p>
                                        <p className="text-sm text-gray-500 dark:text-gray-400">{selectedSlip.employeeId} · {selectedSlip.department}</p>
                                    </div>
                                </div>
                            </div>
                            <div className="p-4 rounded-xl border border-gray-200 dark:border-gray-800">
                                <p className="text-sm text-gray-500 dark:text-gray-400">Generated On</p>
                                <p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedSlip.generatedOn}</p>
                                <p className="text-sm text-gray-500 dark:text-gray-400">Status: {selectedSlip.status}</p>
                            </div>
                        </div>

                        {/* Hours Breakdown - matches Generate Payslip styling */}
                        <div className="rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 mb-4">
                            <h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 uppercase tracking-wide flex items-center gap-2">
                                <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Hours Breakdown
                            </h4>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Regular Hours</span>
                                    <span className="text-lg font-bold text-gray-900 dark:text-white">{Math.round((selectedSlip.totalRegularHours || 0) * 100) / 100}h</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Overtime Hours</span>
                                    <span className="text-lg font-bold text-green-600 dark:text-green-400">{Math.round((selectedSlip.totalOvertimeHours || 0) * 100) / 100}h</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Undertime</span>
                                    <span className="text-lg font-bold text-amber-600 dark:text-amber-400">{Math.round((selectedSlip.totalUndertimeHours || 0) * 100) / 100}h</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Absent Days</span>
                                    <span className="text-lg font-bold text-red-600 dark:text-red-400">{selectedSlip.totalAbsentDays || 0}</span>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-xl border border-gray-200 dark:border-gray-800 p-5 space-y-3">
                            <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                                <span>Gross Pay</span>
                                <span className="text-lg font-semibold text-gray-900 dark:text-white">{formatPHP(selectedSlip.grossPay)}</span>
                            </div>
                            
                            {/* Deductions Breakdown Section */}
                            <div className="border-t border-dashed border-gray-200 dark:border-gray-700 pt-4">
                                <h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-3 uppercase tracking-wide text-gray-700 dark:text-gray-300">Deductions</h4>
                                <div className="space-y-2.5">
                                    {/* Withholding Tax */}
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-gray-600 dark:text-gray-400">Withholding Tax</span>
                                        <span className="text-gray-900 dark:text-white font-medium">
                                            -{formatPHP(selectedSlip.deductionDetails?.withholding_tax || 0)}
                                        </span>
                                    </div>
                                    
                                    {/* SSS Contribution */}
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-gray-600 dark:text-gray-400">SSS Contribution (Employee)</span>
                                        <span className="text-gray-900 dark:text-white font-medium">
                                            -{formatPHP(selectedSlip.deductionDetails?.sss || 0)}
                                        </span>
                                    </div>
                                    
                                    {/* PhilHealth Contribution */}
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-gray-600 dark:text-gray-400">PhilHealth Contribution (Employee)</span>
                                        <span className="text-gray-900 dark:text-white font-medium">
                                            -{formatPHP(selectedSlip.deductionDetails?.philhealth || 0)}
                                        </span>
                                    </div>
                                    
                                    {/* Pag-IBIG Contribution */}
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="text-gray-600 dark:text-gray-400">Pag-IBIG Contribution (Employee)</span>
                                        <span className="text-gray-900 dark:text-white font-medium">
                                            -{formatPHP(selectedSlip.deductionDetails?.pagibig || 0)}
                                        </span>
                                    </div>
                                    
                                    {/* Other Deductions */}
                                    {(selectedSlip.deductionDetails?.other || 0) > 0 && (
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-gray-600 dark:text-gray-400">Other Deductions</span>
                                            <span className="text-gray-900 dark:text-white font-medium">
                                                -{formatPHP(selectedSlip.deductionDetails?.other || 0)}
                                            </span>
                                        </div>
                                    )}
                                    
                                    {/* Total Deductions Row */}
                                    <div className="border-t border-gray-200 dark:border-gray-700 pt-2.5 mt-2.5">
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm font-semibold text-gray-900 dark:text-white">Total Deductions</span>
                                            <span className="text-base font-bold text-red-600 dark:text-red-400">
                                                -{formatPHP(selectedSlip.deductions)}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div className="border-t border-dashed border-gray-200 dark:border-gray-700" />
                            <div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 -mx-5 px-5 py-3">
                                <div className="flex items-center justify-between">
                                    <span className="text-base font-bold text-gray-900 dark:text-white">Net Pay</span>
                                    <span className="text-2xl font-extrabold text-gray-900 dark:text-white">{formatPHP(selectedSlip.netPay)}</span>
                                </div>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                onClick={closeSlip}
                                className="px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200 text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                            >
                                Close
                            </button>
                            <button
                                className="p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                                title="Download"
                                aria-label="Download"
                            >
                                <DownloadIcon className="size-5 text-blue-600 dark:text-blue-400" />
                            </button>
                        </div>
                    </div>
                </div>,
                document.body
            )}
        </div>
    );
}
