import { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";

type SlipStatus = "processed" | "pending" | "approved" | "paid" | "rejected";

type Deduction = {
    name: string;
    amount: number;
};

type SlipRecord = {
    payrollId: number;
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
        total: number;
    };
    // Hours breakdown (to match Generate Payslip output)
    totalRegularHours?: number;
    totalOvertimeHours?: number;
    totalSpecialHolidayHours?: number;
    totalRegularHolidayHours?: number;
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
const toNumber = (value: unknown): number => {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : 0;
};

const buildDeductionDetails = (apiPayroll: any) => {
    const withholdingTax = toNumber(apiPayroll.tax_deductions ?? apiPayroll.tax_amount);
    const sss = toNumber(apiPayroll.sss_contributions ?? apiPayroll.sss);
    const philhealth = toNumber(apiPayroll.philhealth ?? apiPayroll.philhealth_contributions);
    const pagibig = toNumber(apiPayroll.pag_ibig ?? apiPayroll.pagibig);

    const legacyTotal = toNumber(apiPayroll.deductions);
    const componentTotal = toNumber(apiPayroll.total_deductions);
    const statutoryTotal = withholdingTax + sss + philhealth + pagibig;
    const total = legacyTotal > 0 ? legacyTotal : componentTotal + statutoryTotal;
    const other = Math.max(0, total - statutoryTotal);

    return {
        withholding_tax: withholdingTax,
        sss,
        philhealth,
        pagibig,
        other,
        total,
    };
};

const resolveSlipStatus = (apiPayroll: any): SlipStatus => {
    const approvalStatus = String(apiPayroll.approval_status || "").toLowerCase();
    const status = String(apiPayroll.status || "").toLowerCase();

    if (approvalStatus === "rejected") {
        return "rejected";
    }

    if (status === "paid") {
        return "paid";
    }

    if (status === "approved" || approvalStatus === "approved") {
        return "approved";
    }

    if (status === "processed") {
        return "processed";
    }

    return "pending";
};

const transformPayrollFromApi = (apiPayroll: any): SlipRecord => {
    const employeeName = apiPayroll.employee 
        ? `${apiPayroll.employee.first_name || ''} ${apiPayroll.employee.last_name || ''}`.trim()
        : 'Unknown';
    
    const department = apiPayroll.employee?.department || 'N/A';
    const employeeIdDisplay = apiPayroll.employee?.employee_id || 'N/A';
    const deductionDetails = buildDeductionDetails(apiPayroll);
    const attendanceDays = toNumber(apiPayroll.attendance_days);
    const regularHours = toNumber(apiPayroll.regular_hours);
    const absentDays = toNumber(apiPayroll.absent_days ?? apiPayroll.leave_days);
    
    return {
        payrollId: toNumber(apiPayroll.id),
        id: `PS-${apiPayroll.id}`,
        employeeName: employeeName,
        employeeId: employeeIdDisplay,
        department: department,
        month: apiPayroll.payroll_period || apiPayroll.pay_period_start,
        payPeriod: apiPayroll.pay_period_start && apiPayroll.pay_period_end 
            ? `${new Date(apiPayroll.pay_period_start).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${new Date(apiPayroll.pay_period_end).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
            : apiPayroll.payroll_period || 'N/A',
        grossPay: toNumber(apiPayroll.gross_salary || apiPayroll.base_salary),
        deductions: deductionDetails.total,
        netPay: toNumber(apiPayroll.net_salary),
        generatedOn: apiPayroll.generated_at 
            ? new Date(apiPayroll.generated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            : new Date(apiPayroll.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
        status: resolveSlipStatus(apiPayroll),
        deductionDetails,
        totalRegularHours: regularHours > 0 ? regularHours : attendanceDays * 8,
        totalOvertimeHours: toNumber(apiPayroll.overtime_hours),
        totalSpecialHolidayHours: toNumber(apiPayroll.special_holiday_hours),
        totalRegularHolidayHours: toNumber(apiPayroll.regular_holiday_hours),
        totalUndertimeHours: toNumber(apiPayroll.undertime_hours),
        totalAbsentDays: absentDays,
    };
};

const pageSize = 7;

const statusLabels: Record<SlipStatus, string> = {
    processed: "Processed",
    pending: "Pending",
    approved: "Approved",
    paid: "Paid",
    rejected: "Rejected",
};

const statusStyles: Record<SlipStatus, string> = {
    processed: "bg-green-100 text-green-700 border border-green-200",
    pending: "bg-yellow-100 text-yellow-700 border border-yellow-200",
    approved: "bg-indigo-100 text-indigo-700 border border-indigo-200",
    paid: "bg-blue-100 text-blue-700 border border-blue-200",
    rejected: "bg-red-100 text-red-700 border border-red-200",
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

const formatHours = (value: number) => `${Math.round(value * 100) / 100}h`;

const getInitials = (name: string) =>
    name
        .split(" ")
        .filter(Boolean)
        .map((n) => n[0])
        .join("")
        .slice(0, 2)
        .toUpperCase();

export default function ViewSlip() {
    const [slipData, setSlipData] = useState<SlipRecord[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState<string>("");
    const [month, setMonth] = useState<string>("");
    const [page, setPage] = useState(1);
    const [selectedSlip, setSelectedSlip] = useState<SlipRecord | null>(null);
    const [loadingSlipId, setLoadingSlipId] = useState<number | null>(null);
    const [slipDetailError, setSlipDetailError] = useState<{ payrollId: number; message: string } | null>(null);
    const [paginationMeta, setPaginationMeta] = useState<any>(null);

    // Fetch payroll data from API
    useEffect(() => {
        const fetchPayrolls = async () => {
            setIsLoading(true);
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                const params = new URLSearchParams();
                if (search) params.append('search', search);
                if (status) params.append('workflow_status', status);
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

    const openSlip = async (slip: SlipRecord) => {
        setSelectedSlip(slip);
        setSlipDetailError(null);
        setLoadingSlipId(slip.payrollId);

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch(`/api/hr/payroll/${slip.payrollId}`, {
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

            const detail = await response.json();
            const detailSlip = transformPayrollFromApi(detail);

            setSelectedSlip((current) => {
                if (!current || current.payrollId !== slip.payrollId) {
                    return current;
                }

                return {
                    ...current,
                    ...detailSlip,
                    id: current.id,
                };
            });
        } catch (error) {
            console.error(`Error fetching payroll detail for ${slip.payrollId}:`, error);
            setSlipDetailError({
                payrollId: slip.payrollId,
                message: 'Unable to load full payslip details. Showing list snapshot.',
            });
        } finally {
            setLoadingSlipId((current) => (current === slip.payrollId ? null : current));
        }
    };

    const closeSlip = () => {
        setSelectedSlip(null);
        setLoadingSlipId(null);
        setSlipDetailError(null);
    };

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

    const isSlipDetailLoading = selectedSlip ? loadingSlipId === selectedSlip.payrollId : false;
    const activeSlipError = selectedSlip && slipDetailError?.payrollId === selectedSlip.payrollId
        ? slipDetailError.message
        : null;

    const startIndex = (page - 1) * pageSize;
    const endIndex = Math.min(startIndex + pageSize, filtered.length);

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-2">
                <h1 className="text-2xl font-semibold text-gray-900 dark:text-white">View Slip</h1>
                <p className="text-gray-600 dark:text-gray-400">Review and download employee payslips by period.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
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
                    <label className="text-sm text-gray-600 dark:text-gray-300">Status</label>
                    <select
                        value={status}
                        onChange={(e) => handleStatus(e.target.value)}
                        aria-label="Filter by status"
                        className="mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
                    >
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="paid">Paid</option>
                        <option value="rejected">Rejected</option>
                    </select>
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
                                            {statusLabels[slip.status]}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4 text-center">
                                        <button
                                            onClick={() => {
                                                void openSlip(slip);
                                            }}
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
                                            className={`min-w-10 h-10 px-3 rounded-lg font-medium transition-colors ${
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
                <div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
                    <div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto p-8">
                        <div className="flex items-start justify-between mb-4">
                            <div>
                                <h3 className="text-2xl font-bold text-gray-900 dark:text-white">Payslip Details</h3>
                                <p className="text-gray-500 dark:text-gray-400 text-sm">{selectedSlip.month} · {selectedSlip.payPeriod}</p>
                                {isSlipDetailLoading && (
                                    <p className="text-xs text-blue-600 dark:text-blue-400 mt-1">Loading detailed payslip fields...</p>
                                )}
                                {activeSlipError && (
                                    <p className="text-xs text-amber-600 dark:text-amber-400 mt-1">{activeSlipError}</p>
                                )}
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
                                <p className="text-sm text-gray-500 dark:text-gray-400">Status: {statusLabels[selectedSlip.status]}</p>
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
                                    <span className="text-lg font-bold text-gray-900 dark:text-white">{formatHours(selectedSlip.totalRegularHours || 0)}</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Overtime Hours</span>
                                    <span className="text-lg font-bold text-green-600 dark:text-green-400">{formatHours(selectedSlip.totalOvertimeHours || 0)}</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Special Holiday Hours</span>
                                    <span className="text-lg font-bold text-blue-600 dark:text-blue-400">{formatHours(selectedSlip.totalSpecialHolidayHours || 0)}</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Regular Holiday Hours</span>
                                    <span className="text-lg font-bold text-blue-600 dark:text-blue-400">{formatHours(selectedSlip.totalRegularHolidayHours || 0)}</span>
                                </div>
                                <div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Undertime</span>
                                    <span className="text-lg font-bold text-amber-600 dark:text-amber-400">{formatHours(selectedSlip.totalUndertimeHours || 0)}</span>
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
                                <h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-3 uppercase tracking-wide">Deductions</h4>
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
                                                -{formatPHP(selectedSlip.deductionDetails?.total ?? selectedSlip.deductions)}
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
                        </div>
                    </div>
                </div>,
                document.body
            )}
        </div>
    );
}
