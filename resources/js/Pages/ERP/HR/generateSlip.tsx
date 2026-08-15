import { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import { usePage } from "@inertiajs/react";
import Swal from "sweetalert2";

// ==================== Type Definitions ====================
type EmployeeStatus = "active" | "inactive" | "suspended" | "terminated";
type AttendanceStatus = "finalized" | "pending" | "not_started";
type PayrollWorkflowStatus = "pending" | "awaiting_checker" | "awaiting_final_approval" | "ready_for_disbursement" | "paid" | "rejected";

type Employee = {
	id: number;
	firstName: string;
	lastName: string;
	employeeId: string;
	department: string;
	position: string;
	status: EmployeeStatus;
	dailySalary?: number;
	monthlyEquivalentSalary?: number;
	dailyRate?: number;
	hourlyRate?: number;
	// Retail shop compensation structure
	salesCommissionRate?: number; // Percentage of sales (e.g., 0.05 = 5%)
	performanceBonusRate?: number; // Target-based bonus rate
	otherAllowances?: number; // Holiday pay, special bonuses, etc.
	loans?: {
		amount: number;
		monthlyDeduction: number;
	};
	lastSlipGenerated?: string;
	hasSlipForPeriod?: boolean; // Whether slip already exists for selected period
	payrollWorkflowStatus?: PayrollWorkflowStatus;
};

type PayrollPeriod = {
	month: string;
	periodKey?: string;
	payCycle?: "monthly" | "semi_monthly";
	startDate: string;
	endDate: string;
	attendanceStatus: AttendanceStatus;
	workingDays: number;
	expectedAttendanceDays?: number;
	expectedRegularHours?: number;
	hasConfiguredOperatingHours?: boolean;
};

type PayrollHoursBreakdown = {
	regularHours: number;
	overtimeHours: number;
	specialHolidayHours: number;
	regularHolidayHours: number;
	undertimeHours: number;
	absentDays: number;
	attendanceDays: number;
	leaveDays: number;
};

type PayrollEarningsBreakdown = {
	basicPay: number;
	overtimePay: number;
	specialHolidayPay: number;
	regularHolidayPay: number;
	salesCommission: number;
	performanceBonus: number;
	otherAllowances: number;
	totalEarnings: number;
};

type PayrollDeductionsBreakdown = {
	withholdingTax: number;
	sssContribution: number;
	philhealthContribution: number;
	pagibigContribution: number;
	absentDeductions: number;
	undertimeDeductions: number;
	loanDeductions: number;
	otherDeductions: number;
	totalDeductions: number;
};

type PayrollBreakdown = {
	employeeId: number;
	employeeName: string;
	department: string;
	position: string;
	hours: PayrollHoursBreakdown;
	earnings: PayrollEarningsBreakdown;
	deductions: PayrollDeductionsBreakdown;
	grossPay: number;
	totalDeductions: number;
	netPay: number;
};

type BatchPreviewIssue = {
	employee_id: number;
	employee_name?: string;
	message: string;
	severity: "error" | "warning";
};

type BatchPreviewSummary = {
	total_employees: number;
	preview_count: number;
	error_count: number;
	warning_count: number;
	total_gross: number;
	total_net: number;
};

type BatchPreviewResponse = {
	previews: PayrollBreakdown[];
	errors: BatchPreviewIssue[];
	warnings: BatchPreviewIssue[];
	summary: BatchPreviewSummary;
};

type ThirteenthMonthReleaseResult = {
	year: number;
	release_date: string;
	processed_count: number;
	skipped_count: number;
	items: Array<{
		employee_id: number;
		employee_name: string;
		status: "released" | "skipped";
		reason?: string | null;
		payroll_id?: number;
		accrued: number;
		released: number;
		released_now: number;
		remaining_balance?: number;
	}>;
};

type GovernanceReadinessStatus = {
	totalPayrolls: number;
	checkerApproved: number;
	awaitingChecker: number;
	awaitingFinalApprover: number;
	paidPayrolls: number;
	requireChecker: boolean;
	requireFinalApprover: boolean;
};

type SingleSlipAttendanceSummary = {
	attendanceDays: number;
	leaveDays: number;
	absentDays: number;
	regularHours: number;
	overtimeHours: number;
	undertimeHours: number;
	specialHolidayHours: number;
	regularHolidayHours: number;
};

// ==================== Icon Components ====================
const CheckCircleIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const ClockIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
	</svg>
);

const AlertIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
	</svg>
);

const EyeIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
	</svg>
);

const CheckIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
	</svg>
);

const CalculatorIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
	</svg>
);

const LockIcon = ({ className = "size-5" }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
	</svg>
);

// ==================== Transformation Functions ====================

// Transform employee API response
const transformEmployeeFromApi = (apiEmployee: any): Employee => {
	// Handle name parsing: if first_name/last_name are null, split the 'name' field
	let firstName = apiEmployee.first_name || '';
	let lastName = apiEmployee.last_name || '';
	
	// If first_name/last_name are empty but name exists, split it
	if (!firstName && !lastName && apiEmployee.name) {
		const nameParts = apiEmployee.name.trim().split(' ');
		firstName = nameParts[0] || '';
		lastName = nameParts.slice(1).join(' ') || '';
	}
	
	return {
		id: apiEmployee.id,
		firstName: firstName,
		lastName: lastName,
		employeeId: apiEmployee.employee_id || `EMP-${apiEmployee.id}`,
		department: apiEmployee.department || 'N/A',
		position: apiEmployee.position || 'N/A',
		status: apiEmployee.status as EmployeeStatus,
		dailySalary: parseFloat(apiEmployee.salary || apiEmployee.daily_rate || 0),
		monthlyEquivalentSalary: apiEmployee.monthly_salary ? parseFloat(apiEmployee.monthly_salary) : undefined,
		dailyRate: apiEmployee.daily_rate ? parseFloat(apiEmployee.daily_rate) : undefined,
		hourlyRate: apiEmployee.hourly_rate ? parseFloat(apiEmployee.hourly_rate) : undefined,
		salesCommissionRate: apiEmployee.sales_commission_rate ? parseFloat(apiEmployee.sales_commission_rate) : 0,
		performanceBonusRate: apiEmployee.performance_bonus_rate ? parseFloat(apiEmployee.performance_bonus_rate) : 0,
		otherAllowances: apiEmployee.other_allowances ? parseFloat(apiEmployee.other_allowances) : 0,
		loans: undefined,
		lastSlipGenerated: undefined,
		hasSlipForPeriod: false,
		payrollWorkflowStatus: "pending",
	};
};

// ==================== Component ====================

const pageSize = 7;

const formatPHP = (value: number) =>
	value.toLocaleString("en-PH", { style: "currency", currency: "PHP" });

const getInitials = (firstName: string, lastName: string) =>
	(firstName.charAt(0) + lastName.charAt(0)).toUpperCase();

const toNumber = (value: unknown): number => Number(value ?? 0);

const formatHours = (value: number) => `${Math.round(value * 100) / 100}h`;
const clampNonNegative = (value: unknown): number => Math.max(0, Number(value ?? 0));

const buildSingleSlipAttendanceSummary = (summary: any): SingleSlipAttendanceSummary => ({
	attendanceDays: Number(summary?.attendance_days ?? 0),
	leaveDays: Number(summary?.total_on_leave ?? 0),
	absentDays: Number(summary?.total_absent ?? 0),
	regularHours: Number(summary?.total_regular_hours ?? 0),
	overtimeHours: Number(summary?.total_overtime_hours ?? 0),
	undertimeHours: clampNonNegative(summary?.total_undertime_hours),
	specialHolidayHours: Number(summary?.special_holiday_hours ?? 0),
	regularHolidayHours: Number(summary?.regular_holiday_hours ?? 0),
});

const normalizeSinglePreviewBreakdown = (employee: Employee, calc: any): PayrollBreakdown => ({
	employeeId: employee.id,
	employeeName: `${employee.firstName} ${employee.lastName}`,
	department: employee.department,
	position: employee.position,
	hours: {
		regularHours: toNumber(calc?.hours?.regular_hours),
		overtimeHours: toNumber(calc?.hours?.overtime_hours),
		specialHolidayHours: toNumber(calc?.hours?.special_holiday_hours),
		regularHolidayHours: toNumber(calc?.hours?.regular_holiday_hours),
		undertimeHours: toNumber(calc?.hours?.undertime_hours),
		absentDays: toNumber(calc?.hours?.absent_days),
		attendanceDays: toNumber(calc?.hours?.attendance_days),
		leaveDays: toNumber(calc?.hours?.leave_days),
	},
	earnings: {
		basicPay: toNumber(calc?.earnings?.basic_pay),
		overtimePay: toNumber(calc?.earnings?.overtime_pay),
		specialHolidayPay: toNumber(calc?.earnings?.special_holiday_pay),
		regularHolidayPay: toNumber(calc?.earnings?.regular_holiday_pay),
		salesCommission: toNumber(calc?.earnings?.sales_commission),
		performanceBonus: toNumber(calc?.earnings?.performance_bonus),
		otherAllowances: toNumber(calc?.earnings?.other_allowances),
		totalEarnings: toNumber(calc?.earnings?.total_earnings),
	},
	deductions: {
		withholdingTax: toNumber(calc?.deductions?.withholding_tax),
		sssContribution: toNumber(calc?.deductions?.sss_contribution),
		philhealthContribution: toNumber(calc?.deductions?.philhealth_contribution),
		pagibigContribution: toNumber(calc?.deductions?.pagibig_contribution),
		absentDeductions: toNumber(calc?.deductions?.absent_deductions),
		undertimeDeductions: toNumber(calc?.deductions?.undertime_deductions),
		loanDeductions: 0,
		otherDeductions: toNumber(calc?.deductions?.other_deductions),
		totalDeductions: toNumber(calc?.deductions?.total_deductions),
	},
	grossPay: toNumber(calc?.gross_pay),
	totalDeductions: toNumber(calc?.deductions?.total_deductions),
	netPay: toNumber(calc?.net_pay),
});

const normalizeBatchPreviewIssue = (issue: any): BatchPreviewIssue => ({
	employee_id: toNumber(issue?.employee_id),
	employee_name: issue?.employee_name,
	message: String(issue?.message ?? issue?.error ?? "Unknown validation issue"),
	severity: issue?.severity === "warning" ? "warning" : "error",
});

const normalizeBatchPreviewBreakdown = (preview: any): PayrollBreakdown => {
	const attendance = preview?.attendance ?? preview?.calculation?.attendance_summary ?? {};
	const calculation = preview?.calculation ?? {};

	return {
		employeeId: toNumber(preview?.employee_id),
		employeeName: String(preview?.employee_name ?? "Unknown Employee"),
		department: String(preview?.department ?? "N/A"),
		position: String(preview?.position ?? "N/A"),
		hours: {
			regularHours: toNumber(attendance?.total_regular_hours),
			overtimeHours: toNumber(attendance?.total_overtime_hours),
			specialHolidayHours: toNumber(attendance?.special_holiday_hours),
			regularHolidayHours: toNumber(attendance?.regular_holiday_hours),
			undertimeHours: toNumber(attendance?.total_undertime_hours),
			absentDays: toNumber(attendance?.total_absent_days),
			attendanceDays: toNumber(attendance?.total_present_days),
			leaveDays: 0,
		},
		earnings: {
			basicPay: toNumber(calculation?.basic_pay),
			overtimePay: toNumber(calculation?.overtime_pay),
			specialHolidayPay: toNumber(calculation?.special_holiday_pay),
			regularHolidayPay: toNumber(calculation?.regular_holiday_pay),
			salesCommission: toNumber(calculation?.sales_commission),
			performanceBonus: toNumber(calculation?.performance_bonus),
			otherAllowances: toNumber(calculation?.other_allowances),
			totalEarnings: toNumber(calculation?.gross_salary),
		},
		deductions: {
			withholdingTax: toNumber(calculation?.withholding_tax),
			sssContribution: toNumber(calculation?.sss_contribution),
			philhealthContribution: toNumber(calculation?.philhealth_contribution),
			pagibigContribution: toNumber(calculation?.pagibig_contribution),
			absentDeductions: toNumber(calculation?.absent_deductions),
			undertimeDeductions: toNumber(calculation?.undertime_deductions),
			loanDeductions: toNumber(calculation?.loan_deductions),
			otherDeductions: toNumber(calculation?.other_deductions),
			totalDeductions: toNumber(calculation?.total_deductions),
		},
		grossPay: toNumber(calculation?.gross_salary),
		totalDeductions: toNumber(calculation?.total_deductions),
		netPay: toNumber(calculation?.net_salary),
	};
};

const normalizeBatchPreviewResponse = (payload: any): BatchPreviewResponse => ({
	previews: Array.isArray(payload?.previews)
		? payload.previews.map((preview: any) => normalizeBatchPreviewBreakdown(preview))
		: [],
	errors: Array.isArray(payload?.errors)
		? payload.errors.map((issue: any) => normalizeBatchPreviewIssue(issue))
		: [],
	warnings: Array.isArray(payload?.warnings)
		? payload.warnings.map((issue: any) => normalizeBatchPreviewIssue(issue))
		: [],
	summary: {
		total_employees: toNumber(payload?.summary?.total_employees),
		preview_count: toNumber(payload?.summary?.preview_count),
		error_count: toNumber(payload?.summary?.error_count),
		warning_count: toNumber(payload?.summary?.warning_count),
		total_gross: toNumber(payload?.summary?.total_gross),
		total_net: toNumber(payload?.summary?.total_net),
	},
});

export default function GenerateSlip() {
	const { auth, initialPayrollEmployees } = usePage().props as any;
	const ownerMode = auth?.erpActor?.ownerMode === true;
	const hrApiBase = ownerMode ? "/api/shop-owner/hr" : "/api/hr";
	const [employeeData, setEmployeeData] = useState<Employee[]>([]);
	const [isLoadingEmployees, setIsLoadingEmployees] = useState(true);
	const [payrollPeriods, setPayrollPeriods] = useState<PayrollPeriod[]>([]);
	const [isLoadingPeriods, setIsLoadingPeriods] = useState(true);
	const [isPeriodModalOpen, setIsPeriodModalOpen] = useState(false);
	const [periodSearch, setPeriodSearch] = useState("");
	const [search, setSearch] = useState("");
	const [department, setDepartment] = useState<string>("");
	const [selectedPeriodIndex, setSelectedPeriodIndex] = useState(0);
	const [page, setPage] = useState(1);
	const [selectedEmployeeIds, setSelectedEmployeeIds] = useState<number[]>([]);
	const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);
	const [isGenerating, setIsGenerating] = useState(false);
	const [payrollBreakdown, setPayrollBreakdown] = useState<PayrollBreakdown | null>(null);
	const [isCalculating, setIsCalculating] = useState(false);
	const [attendanceSummary, setAttendanceSummary] = useState<SingleSlipAttendanceSummary | null>(null);
	
	// Manual hours input state
	const [totalRegularHours, setTotalRegularHours] = useState<number>(0);
	const [totalOvertimeHours, setTotalOvertimeHours] = useState<number>(0);
	const [specialHolidayHours, setSpecialHolidayHours] = useState<number>(0);
	const [regularHolidayHours, setRegularHolidayHours] = useState<number>(0);
	const [totalUndertimeHours, setTotalUndertimeHours] = useState<number>(0);
	const [totalAbsentDays, setTotalAbsentDays] = useState<number>(0);
	
	// Batch generation states
	const [showBatchPreviewModal, setShowBatchPreviewModal] = useState(false);
	const [batchPreviewData, setBatchPreviewData] = useState<BatchPreviewResponse | null>(null);
	const [isLoadingPreview, setIsLoadingPreview] = useState(false);
	const [generationProgress, setGenerationProgress] = useState({ current: 0, total: 0 });
	const [retryQueue, setRetryQueue] = useState<number[]>([]);
	const [thirteenthYear, setThirteenthYear] = useState<number>(new Date().getFullYear());
	const [isProcessingThirteenth, setIsProcessingThirteenth] = useState(false);
	const [isLoadingGovernanceStatus, setIsLoadingGovernanceStatus] = useState(false);
	const [governanceStatus, setGovernanceStatus] = useState<GovernanceReadinessStatus>({
		totalPayrolls: 0,
		checkerApproved: 0,
		awaitingChecker: 0,
		awaitingFinalApprover: 0,
		paidPayrolls: 0,
		requireChecker: true,
		requireFinalApprover: true,
	});

	const selectedPeriod = payrollPeriods[selectedPeriodIndex];
	const filteredPayrollPeriods = useMemo(() => {
		const term = periodSearch.trim().toLowerCase();
		if (!term) return payrollPeriods;

		return payrollPeriods.filter((period) => {
			const text = `${period.month} ${period.startDate} ${period.endDate} ${period.periodKey ?? ""}`.toLowerCase();
			return text.includes(term);
		});
	}, [payrollPeriods, periodSearch]);

	const attendanceStatusBadgeClasses: Record<AttendanceStatus, string> = {
		finalized: "bg-emerald-100 text-emerald-700 border border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800",
		pending: "bg-amber-100 text-amber-700 border border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800",
		not_started: "bg-gray-100 text-gray-700 border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700",
	};

	const attendanceStatusLabel: Record<AttendanceStatus, string> = {
		finalized: "Finalized",
		pending: "Pending",
		not_started: "Not Started",
	};

	const handlePeriodSelect = (index: number) => {
		setSelectedPeriodIndex(index);
		setIsPeriodModalOpen(false);
		setPeriodSearch("");
	};
	const payrollPeriodKey = useMemo(
		() => {
			if (!selectedPeriod) return "";
			if (selectedPeriod.periodKey) return selectedPeriod.periodKey;
			return selectedPeriod.startDate ? selectedPeriod.startDate.slice(0, 7) : "";
		},
		[selectedPeriod]
	);

	// Fetch payroll periods from API
	useEffect(() => {
		const fetchPeriods = async () => {
			setIsLoadingPeriods(true);
			try {
				const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
				const response = await fetch(hrApiBase + '/payroll/periods', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						'X-CSRF-TOKEN': csrfToken || '',
						'Accept': 'application/json',
					},
					credentials: 'include',
				});
				if (response.ok) {
					const data: PayrollPeriod[] = await response.json();
					if (Array.isArray(data) && data.length > 0) {
						setPayrollPeriods(data);
						const latestStartedIndex = data.findIndex((period) => period.attendanceStatus !== 'not_started');
						setSelectedPeriodIndex(latestStartedIndex >= 0 ? latestStartedIndex : 0);
					}
				}
			} catch (error) {
				console.error('Error fetching payroll periods:', error);
			} finally {
				setIsLoadingPeriods(false);
			}
		};
		fetchPeriods();
	}, [hrApiBase]);

	// Fetch employees from API
	useEffect(() => {
		if (ownerMode) {
			const ownerEmployees = Array.isArray(initialPayrollEmployees)
				? initialPayrollEmployees.map(transformEmployeeFromApi)
				: [];
			setEmployeeData(ownerEmployees);
			setIsLoadingEmployees(false);
			return;
		}

		const fetchEmployees = async () => {
			setIsLoadingEmployees(true);
			try {
				const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
				
				const params = new URLSearchParams();
				params.append('status', 'active');
				params.append('per_page', '100'); // Get all active employees

				const response = await fetch(`/api/hr/employees?${params.toString()}`, {
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
					const transformedData = data.data.map(transformEmployeeFromApi);
					setEmployeeData(transformedData);
				} else if (Array.isArray(data)) {
					const transformedData = data.map(transformEmployeeFromApi);
					setEmployeeData(transformedData);
				} else {
					console.error('Unexpected API response format:', data);
					setEmployeeData([]);
				}
			} catch (error) {
				console.error('Error fetching employees:', error);
				setEmployeeData([]);
			} finally {
				setIsLoadingEmployees(false);
			}
		};

		fetchEmployees();
	}, [ownerMode, initialPayrollEmployees]);

	useEffect(() => {
		if (!isPeriodModalOpen) return;

		const handleEscape = (event: KeyboardEvent) => {
			if (event.key === 'Escape') {
				setIsPeriodModalOpen(false);
				setPeriodSearch("");
			}
		};

		document.addEventListener('keydown', handleEscape);
		return () => document.removeEventListener('keydown', handleEscape);
	}, [isPeriodModalOpen]);

	useEffect(() => {
		const fetchGovernanceReadiness = async () => {
			if (!payrollPeriodKey) {
				setGovernanceStatus((prev) => ({
					...prev,
					totalPayrolls: 0,
					checkerApproved: 0,
					awaitingChecker: 0,
					awaitingFinalApprover: 0,
					paidPayrolls: 0,
				}));
				return;
			}

			setIsLoadingGovernanceStatus(true);
			try {
				const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
				const params = new URLSearchParams();
				params.append('period', payrollPeriodKey);
				params.append('per_page', '500');

				const response = await fetch(hrApiBase + '/payroll?' + params.toString(), {
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

				const payload = await response.json();
				const payrollRows = Array.isArray(payload?.data)
					? payload.data
					: Array.isArray(payload)
						? payload
						: [];

				const workflowStatusByEmployeeId = new Map<number, PayrollWorkflowStatus>();
				const generatedAtByEmployeeId = new Map<number, string>();
				const rejectedEmployeeIds = new Set<number>();
				for (const row of payrollRows) {
					const employeeId = Number(row?.employee_id ?? 0);
					if (!employeeId) continue;

					const workflowStatusRaw = String(row?.workflow_status || '').toLowerCase();
					const statusRaw = String(row?.status || '').toLowerCase();
					const approvalStatusRaw = String(row?.approval_status || '').toLowerCase();

					// Rejected payrolls should be regenerable in this screen.
					if (approvalStatusRaw === 'rejected') {
						rejectedEmployeeIds.add(employeeId);
						continue;
					}

					let normalizedStatus: PayrollWorkflowStatus = 'pending';
					if (workflowStatusRaw === 'paid' || statusRaw === 'paid') {
						normalizedStatus = 'paid';
					} else if (workflowStatusRaw === 'ready_for_disbursement' || (statusRaw === 'approved' && Boolean(row?.final_approved_by))) {
						normalizedStatus = 'ready_for_disbursement';
					} else if (workflowStatusRaw === 'awaiting_final_approval' || (approvalStatusRaw === 'approved' && statusRaw === 'pending')) {
						normalizedStatus = 'awaiting_final_approval';
					} else if (workflowStatusRaw === 'awaiting_checker' || approvalStatusRaw === 'pending') {
						normalizedStatus = 'awaiting_checker';
					}

					workflowStatusByEmployeeId.set(employeeId, normalizedStatus);
					if (row?.created_at) {
						generatedAtByEmployeeId.set(employeeId, String(row.created_at));
					}
				}

				setEmployeeData((prevData) => prevData.map((employee) => {
					if (rejectedEmployeeIds.has(employee.id)) {
						return {
							...employee,
							hasSlipForPeriod: false,
							lastSlipGenerated: undefined,
							payrollWorkflowStatus: 'rejected',
						};
					}

					const workflowStatus = workflowStatusByEmployeeId.get(employee.id);
					if (!workflowStatus) {
						return {
							...employee,
							hasSlipForPeriod: false,
							lastSlipGenerated: undefined,
							payrollWorkflowStatus: 'pending',
						};
					}

					const generatedAt = generatedAtByEmployeeId.get(employee.id);
					return {
						...employee,
						hasSlipForPeriod: true,
						lastSlipGenerated: generatedAt
							? new Date(generatedAt).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
							: employee.lastSlipGenerated,
						payrollWorkflowStatus: workflowStatus,
					};
				}));

				const summary = payrollRows.reduce(
					(acc: GovernanceReadinessStatus, row: any) => {
						const status = String(row?.status || '').toLowerCase();
						const hasCheckerApproval = Boolean(row?.approved_by);
						const hasFinalApproval = Boolean(row?.final_approved_by) || status === 'paid';

						acc.totalPayrolls += 1;
						if (hasCheckerApproval) {
							acc.checkerApproved += 1;
						} else {
							acc.awaitingChecker += 1;
						}

						if (hasCheckerApproval && !hasFinalApproval) {
							acc.awaitingFinalApprover += 1;
						}

						if (status === 'paid') {
							acc.paidPayrolls += 1;
						}

						return acc;
					},
					{
						totalPayrolls: 0,
						checkerApproved: 0,
						awaitingChecker: 0,
						awaitingFinalApprover: 0,
						paidPayrolls: 0,
						requireChecker: true,
						requireFinalApprover: true,
					}
				);

				setGovernanceStatus(summary);
			} catch (error) {
				console.error('Error fetching governance readiness:', error);
				setGovernanceStatus((prev) => ({
					...prev,
					totalPayrolls: 0,
					checkerApproved: 0,
					awaitingChecker: 0,
					awaitingFinalApprover: 0,
					paidPayrolls: 0,
				}));
			} finally {
				setIsLoadingGovernanceStatus(false);
			}
		};

		fetchGovernanceReadiness();
	}, [hrApiBase, payrollPeriodKey]);

	const departments = useMemo(
		() => Array.from(new Set(employeeData.map((e) => e.department))),
		[employeeData]
	);

	const filtered = useMemo(() => {
		const term = search.trim().toLowerCase();
		return employeeData.filter((e) => {
			const matchesSearch = term
				? [e.firstName, e.lastName, e.employeeId, e.department, e.position]
					.join(" ")
					.toLowerCase()
					.includes(term)
				: true;
			const matchesDepartment = department ? e.department === department : true;
			const matchesStatus = e.status === "active";
			return matchesSearch && matchesDepartment && matchesStatus;
		});
	}, [search, department, employeeData]);

	const paginated = useMemo(() => {
		const start = (page - 1) * pageSize;
		return filtered.slice(start, start + pageSize);
	}, [filtered, page]);

	const selectedPendingEmployees = useMemo(
		() => employeeData.filter((e) => selectedEmployeeIds.includes(e.id) && e.status === "active" && !e.hasSlipForPeriod),
		[employeeData, selectedEmployeeIds]
	);

	const filteredSelectableIds = useMemo(
		() => filtered.filter((e) => !e.hasSlipForPeriod && e.status === "active").map((e) => e.id),
		[filtered]
	);

	const isAllFilteredSelected = filteredSelectableIds.length > 0 && filteredSelectableIds.every((id) => selectedEmployeeIds.includes(id));

	const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));

	useEffect(() => {
		const validEmployeeIds = new Set(
			employeeData.filter((e) => e.status === "active" && !e.hasSlipForPeriod).map((e) => e.id)
		);
		setSelectedEmployeeIds((prev) => prev.filter((id) => validEmployeeIds.has(id)));
	}, [employeeData]);

	const resetPage = () => setPage(1);

	const handleSearch = (value: string) => {
		setSearch(value);
		resetPage();
	};

	const handleDepartment = (value: string) => {
		setDepartment(value);
		resetPage();
	};

	const toggleEmployeeSelection = (employeeId: number) => {
		setSelectedEmployeeIds((prev) =>
			prev.includes(employeeId)
				? prev.filter((id) => id !== employeeId)
				: [...prev, employeeId]
		);
	};

	const toggleAllFilteredSelection = () => {
		if (filteredSelectableIds.length === 0) return;

		setSelectedEmployeeIds((prev) => {
			if (isAllFilteredSelected) {
				return prev.filter((id) => !filteredSelectableIds.includes(id));
			}

			const selectedSet = new Set(prev);
			filteredSelectableIds.forEach((id) => selectedSet.add(id));
			return Array.from(selectedSet);
		});
	};

	const openEmployee = async (employee: Employee) => {
		// Validation: Check if attendance is finalized
		if (selectedPeriod.attendanceStatus !== "finalized") {
			await Swal.fire({
				icon: "warning",
				title: "Attendance Not Finalized",
				text: `Attendance for ${selectedPeriod.month} has not been finalized yet. Please finalize attendance before generating payslips.`,
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		// Validation: Check if slip already exists
		if (employee.hasSlipForPeriod) {
			await Swal.fire({
				icon: "info",
				title: "Payslip Already Exists",
				html: `A payslip for <strong>${employee.firstName} ${employee.lastName}</strong> already exists for ${selectedPeriod.month}.<br><br>Please use "View Slip" to see existing payslips.`,
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		// Validation: Check employee status
		if (employee.status !== "active") {
			await Swal.fire({
				icon: "error",
				title: "Inactive Employee",
				text: `Cannot generate payslip for inactive employee.`,
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		// Reset hours input fields and fetch attendance data
		setTotalRegularHours(0);
		setTotalOvertimeHours(0);
		setSpecialHolidayHours(0);
		setRegularHolidayHours(0);
		setTotalUndertimeHours(0);
		setTotalAbsentDays(0);
		setAttendanceSummary(null);
		
		setSelectedEmployee(employee);
		setPayrollBreakdown(null);
		
		// Auto-fetch attendance data for this period
		fetchAttendanceAndCalculate(employee);
	};

	const fetchAttendanceAndCalculate = async (employee: Employee) => {
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			// Fetch attendance data for the selected period
			const params = new URLSearchParams();
			params.append('start_date', selectedPeriod.startDate);
			params.append('end_date', selectedPeriod.endDate);
			
			const response = await fetch(hrApiBase + '/attendance/employee/' + employee.id + '?' + params.toString(), {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
			});

			if (!response.ok) {
				console.error('Failed to fetch attendance data');
				await Swal.fire({
					icon: 'error',
					title: 'Attendance Fetch Failed',
					text: 'Could not load attendance data for this employee and period. Payroll preview needs attendance records first.',
					confirmButtonColor: '#dc2626',
				});
				return;
			}

			const data = await response.json();

			// Auto-populate hours from attendance summary
			if (data.summary) {
				const nextAttendanceSummary = buildSingleSlipAttendanceSummary(data.summary);
				setAttendanceSummary(nextAttendanceSummary);
				setTotalRegularHours(nextAttendanceSummary.regularHours);
				setTotalOvertimeHours(nextAttendanceSummary.overtimeHours);
				setSpecialHolidayHours(nextAttendanceSummary.specialHolidayHours);
				setRegularHolidayHours(nextAttendanceSummary.regularHolidayHours);
				setTotalUndertimeHours(nextAttendanceSummary.undertimeHours);
				setTotalAbsentDays(nextAttendanceSummary.absentDays);

				await handleCalculate(employee, nextAttendanceSummary);
			}
		} catch (error) {
			console.error('Error fetching attendance:', error);
			await Swal.fire({
				icon: 'error',
				title: 'Attendance Fetch Failed',
				text: 'Could not load attendance data for this employee and period. Payroll preview needs attendance records first.',
				confirmButtonColor: '#dc2626',
			});
		}
	};

	const closeEmployee = () => {
		setSelectedEmployee(null);
		setPayrollBreakdown(null);
		setAttendanceSummary(null);
		setTotalRegularHours(0);
		setTotalOvertimeHours(0);
		setSpecialHolidayHours(0);
		setRegularHolidayHours(0);
		setTotalUndertimeHours(0);
		setTotalAbsentDays(0);
	};
	
	// Generate all pending payslips with validation and preview
	const handleGenerateAll = async () => {
		const pendingEmployees = selectedPendingEmployees;
		
		if (pendingEmployees.length === 0) {
			await Swal.fire({
				icon: "info",
				title: "No Selected Employees",
				text: "Please select at least one pending employee before generating payslips.",
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		// Step 1: Show validation summary
		setIsLoadingPreview(true);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch(hrApiBase + '/payroll/batch/preview', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: payrollPeriodKey || selectedPeriod.month,
					employeeIds: pendingEmployees.map(e => e.id),
				}),
			});

			if (!response.ok) {
				throw new Error('Failed to generate preview');
			}

			const previewPayload = normalizeBatchPreviewResponse(await response.json());
			setBatchPreviewData(previewPayload);
			setIsLoadingPreview(false);

			// Show validation summary
			const hasErrors = previewPayload.errors.length > 0;
			const hasWarnings = previewPayload.warnings.length > 0;

			if (hasErrors || hasWarnings) {
				const result = await Swal.fire({
					icon: hasErrors ? "error" : "warning",
					title: "Validation Issues Found",
					html: `
						<div class="text-left">
							<p class="mb-2 font-semibold">Review the following issues before proceeding:</p>
							${hasErrors ? `
								<div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-3">
									<p class="text-sm font-semibold text-red-800 mb-2">⚠️ Errors (${previewPayload.errors.length}):</p>
									<ul class="list-disc list-inside text-sm text-red-700 space-y-1">
										${previewPayload.errors.map((err) => `
											<li>${err.employee_name || `Employee ${err.employee_id}`}: ${err.message}</li>
										`).join('')}
									</ul>
								</div>
							` : ''}
							${hasWarnings ? `
								<div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
									<p class="text-sm font-semibold text-amber-800 mb-2">⚠️ Warnings (${previewPayload.warnings.length}):</p>
									<ul class="list-disc list-inside text-sm text-amber-700 space-y-1">
										${previewPayload.warnings.map((warn) => `
											<li>${warn.employee_name}: ${warn.message}</li>
										`).join('')}
									</ul>
								</div>
							` : ''}
							<div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
								<p class="text-sm text-blue-800">
									<strong>${previewPayload.previews.length}</strong> payslips can be generated successfully
								</p>
							</div>
						</div>
					`,
					showCancelButton: true,
					confirmButtonText: hasErrors ? "Fix Issues First" : "Continue Anyway",
					cancelButtonText: "Cancel",
					confirmButtonColor: hasErrors ? "#dc2626" : "#f59e0b",
					cancelButtonColor: "#6b7280",
				});

				if (!result.isConfirmed || hasErrors) {
					return;
				}
			}

			// Step 2: Show preview modal
			setShowBatchPreviewModal(true);

		} catch (error) {
			console.error('Error generating preview:', error);
			setIsLoadingPreview(false);
			await Swal.fire({
				icon: "error",
				title: "Preview Failed",
				text: "Could not generate payroll preview. Please try again.",
				confirmButtonColor: "#dc2626",
			});
		}
	};

	// Confirm and generate batch
	const handleConfirmBatchGeneration = async () => {
		if (!batchPreviewData) return;

		const result = await Swal.fire({
			icon: "question",
			title: "Generate All Payslips?",
			html: `
				<div class="text-left space-y-3">
					<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
						<h4 class="font-semibold text-blue-900 mb-2">📊 Summary</h4>
						<ul class="text-sm text-blue-800 space-y-1">
							<li><strong>${batchPreviewData.summary.preview_count}</strong> employees</li>
							<li><strong>Period:</strong> ${selectedPeriod.month}</li>
							<li><strong>Total Gross:</strong> ${formatPHP(batchPreviewData.summary.total_gross)}</li>
							<li><strong>Total Net:</strong> ${formatPHP(batchPreviewData.summary.total_net)}</li>
						</ul>
					</div>

					<div class="bg-green-50 border border-green-200 rounded-lg p-4">
						<label class="flex items-center gap-2 text-sm cursor-pointer">
							<input type="checkbox" id="sendNotifications" checked class="rounded text-green-600" />
							<span class="text-green-800">📧 Send email notifications to employees</span>
						</label>
					</div>

					<p class="text-xs text-gray-600">
						This will create payslips and lock the data. You can export to CSV after generation.
					</p>
				</div>
			`,
			showCancelButton: true,
			confirmButtonText: "Generate Payslips",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#16a34a",
			cancelButtonColor: "#6b7280",
			preConfirm: () => {
				return {
					sendNotifications: (document.getElementById('sendNotifications') as HTMLInputElement)?.checked
				};
			}
		});

		if (!result.isConfirmed) {
			setShowBatchPreviewModal(false);
			return;
		}

		// Generate batch with backend
		setIsGenerating(true);
		setGenerationProgress({ current: 0, total: batchPreviewData.previews.length });
		setShowBatchPreviewModal(false);

		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch(hrApiBase + '/payroll/batch/generate', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: payrollPeriodKey || selectedPeriod.month,
					employeeIds: batchPreviewData.previews.map((preview) => preview.employeeId),
					paymentMethod: 'bank_transfer',
					sendNotifications: result.value.sendNotifications,
				}),
			});

			if (!response.ok) {
				const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
				throw new Error(errorData.message || 'Failed to generate payslips');
			}

			const batchResult = await response.json();
			
			// Update employee data
			setEmployeeData(prevData => 
				prevData.map(e => 
					batchResult.payrolls.some((p: any) => p.employee_id === e.id)
						? { 
							...e, 
							hasSlipForPeriod: true,
							payrollWorkflowStatus: "awaiting_checker",
							lastSlipGenerated: new Date().toLocaleDateString('en-US', { 
								year: 'numeric', 
								month: 'short', 
								day: 'numeric' 
							})
						}
						: e
				)
			);
			setSelectedEmployeeIds((prev) => prev.filter((id) => !batchResult.payrolls.some((p: any) => p.employee_id === id)));
			
			setGenerationProgress({ current: batchResult.created, total: batchResult.created });
			setIsGenerating(false);

			// Show results with retry option
			const hasErrors = batchResult.errors > 0;
			if (hasErrors) {
				setRetryQueue(batchResult.retry_queue || []);
			}

			await Swal.fire({
				icon: hasErrors ? "warning" : "success",
				title: "Batch Generation Complete",
				html: `
					<div class="text-left space-y-3">
						<div class="bg-green-50 border border-green-200 rounded-lg p-3">
							<p class="text-sm text-green-800">
								✅ <strong>${batchResult.created}</strong> payslips generated successfully
							</p>
						</div>

						${hasErrors ? `
							<div class="bg-red-50 border border-red-200 rounded-lg p-3">
								<p class="text-sm text-red-800 mb-2">
									❌ <strong>${batchResult.errors}</strong> failed
								</p>
								<ul class="list-disc list-inside text-xs text-red-700 space-y-1">
									${batchResult.error_details.slice(0, 5).map((err: any) => `
										<li>${err.employee_name}: ${err.error}</li>
									`).join('')}
									${batchResult.error_details.length > 5 ? '<li>...and more</li>' : ''}
								</ul>
							</div>
						` : ''}

						<div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
							<p class="text-sm text-blue-800">
								<strong>Total Net Payout:</strong> ${formatPHP(batchResult.summary.total_net)}
							</p>
						</div>

						${result.value.sendNotifications ? `
							<p class="text-xs text-gray-600">
								📧 Email notifications sent to employees
							</p>
						` : ''}
					</div>
				`,
				showDenyButton: hasErrors && batchResult.retry_queue.length > 0,
				confirmButtonText: "Export to CSV",
				denyButtonText: `Retry Failed (${batchResult.retry_queue.length})`,
				showCancelButton: true,
				cancelButtonText: "Close",
				confirmButtonColor: "#16a34a",
				denyButtonColor: "#f59e0b",
			}).then((result) => {
				if (result.isConfirmed) {
					handleExportBatch();
				} else if (result.isDenied) {
					handleRetryFailed();
				}
			});

		} catch (error: any) {
			console.error('Error generating batch:', error);
			setIsGenerating(false);
			setGenerationProgress({ current: 0, total: 0 });
			
			await Swal.fire({
				icon: "error",
				title: "Batch Generation Failed",
				text: error.message || "An error occurred. Please try again.",
				confirmButtonColor: "#dc2626",
			});
		}
	};

	const closeBatchPreviewModal = () => {
		setShowBatchPreviewModal(false);
	};

	// Export batch to CSV
	const handleExportBatch = async () => {
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch(hrApiBase + '/payroll/batch/export', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'text/csv',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: payrollPeriodKey || selectedPeriod.month,
					format: 'csv',
				}),
			});

			if (!response.ok) {
				throw new Error('Export failed');
			}

			// Download file
			const blob = await response.blob();
			const url = window.URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = `payroll_batch_${selectedPeriod.month.replace(' ', '_')}_${new Date().toISOString().split('T')[0]}.csv`;
			document.body.appendChild(a);
			a.click();
			window.URL.revokeObjectURL(url);
			document.body.removeChild(a);

			await Swal.fire({
				icon: "success",
				title: "Export Successful",
				text: "Payroll batch exported to CSV",
				timer: 2000,
				showConfirmButton: false,
			});

		} catch (error) {
			console.error('Export error:', error);
			await Swal.fire({
				icon: "error",
				title: "Export Failed",
				text: "Could not export payroll batch",
				confirmButtonColor: "#dc2626",
			});
		}
	};

	// Retry failed generations
	const handleRetryFailed = async () => {
		if (retryQueue.length === 0) return;

		setIsGenerating(true);
		
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch(hrApiBase + '/payroll/batch/retry', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: payrollPeriodKey || selectedPeriod.month,
					employeeIds: retryQueue,
					paymentMethod: 'bank_transfer',
				}),
			});

			if (!response.ok) {
				throw new Error('Retry failed');
			}

			const retryResult = await response.json();
			
			setIsGenerating(false);
			setRetryQueue(retryResult.retry_queue || []);

			await Swal.fire({
				icon: retryResult.errors === 0 ? "success" : "warning",
				title: "Retry Complete",
				html: `
					<div class="text-left space-y-2">
						<p class="text-green-600">✅ ${retryResult.created} payslips generated</p>
						${retryResult.errors > 0 ? `<p class="text-red-600">❌ ${retryResult.errors} still failed</p>` : ''}
					</div>
				`,
			});

		} catch (error) {
			console.error('Retry error:', error);
			setIsGenerating(false);
			
			await Swal.fire({
				icon: "error",
				title: "Retry Failed",
				text: "Could not retry failed generations",
				confirmButtonColor: "#dc2626",
			});
		}
	};
	
	// Auto-calculate when hours change (backend-driven for Phase 1-4 parity)
	const handleCalculate = async (
		employeeOverride?: Employee,
		attendanceOverride?: SingleSlipAttendanceSummary
	) => {
		const activeEmployee = employeeOverride ?? selectedEmployee;
		const activeAttendanceSummary = attendanceOverride ?? attendanceSummary;

		if (!activeEmployee || !selectedPeriod || !activeAttendanceSummary) return;

		const regularHours = attendanceOverride?.regularHours ?? totalRegularHours;
		const overtimeHours = attendanceOverride?.overtimeHours ?? totalOvertimeHours;
		const undertimeHours = clampNonNegative(attendanceOverride?.undertimeHours ?? totalUndertimeHours);
		const absentDays = activeAttendanceSummary.absentDays;
		const attendanceDays = activeAttendanceSummary.attendanceDays;
		const leaveDays = activeAttendanceSummary.leaveDays;
		const currentSpecialHolidayHours = attendanceOverride?.specialHolidayHours ?? specialHolidayHours;
		const currentRegularHolidayHours = attendanceOverride?.regularHolidayHours ?? regularHolidayHours;

		setIsCalculating(true);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

			const response = await fetch(hrApiBase + '/payroll/calculate-preview', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					employee_id: activeEmployee.id,
					start_date: selectedPeriod.startDate,
					end_date: selectedPeriod.endDate,
					attendance_days: attendanceDays,
					leave_days: leaveDays,
					regular_hours: regularHours,
					overtime_hours: overtimeHours,
					special_holiday_hours: currentSpecialHolidayHours,
					regular_holiday_hours: currentRegularHolidayHours,
					undertime_hours: undertimeHours,
					absent_days: absentDays,
					sales_commission: (activeEmployee.monthlyEquivalentSalary ?? ((activeEmployee.dailySalary ?? activeEmployee.dailyRate ?? 0) * (selectedPeriod.workingDays || 26))) * (activeEmployee.salesCommissionRate ?? 0),
					performance_bonus: (activeEmployee.monthlyEquivalentSalary ?? ((activeEmployee.dailySalary ?? activeEmployee.dailyRate ?? 0) * (selectedPeriod.workingDays || 26))) * (activeEmployee.performanceBonusRate ?? 0),
					other_allowances: activeEmployee.otherAllowances ?? 0,
				}),
			});

			if (!response.ok) {
				const errorData = await response.json().catch(() => ({ error: 'Failed to calculate preview' }));
				const validationError = errorData?.errors
					? Object.values(errorData.errors).flat().find(Boolean)
					: null;
				throw new Error(String(validationError || errorData.error || errorData.message || 'Failed to calculate preview'));
			}

			const previewData = await response.json();
			const calc = previewData?.calculation;

			if (!calc) {
				throw new Error('Invalid preview response');
			}

			setPayrollBreakdown(normalizeSinglePreviewBreakdown(activeEmployee, calc));
		} catch (error) {
			console.error('Error calculating payroll preview:', error);
			setPayrollBreakdown(null);
			await Swal.fire({
				icon: 'error',
				title: 'Calculation Failed',
				text: error instanceof Error ? error.message : 'Failed to calculate payroll preview.',
				confirmButtonColor: '#dc2626',
			});
		} finally {
			setIsCalculating(false);
		}
	};

	const handleGenerateSlip = async () => {
		if (!selectedEmployee || !payrollBreakdown || !attendanceSummary) return;

		// Confirm generation
		const result = await Swal.fire({
			icon: "question",
			title: "Generate Payslip?",
			html: `
				<div class="text-left">
					<p class="mb-2">You are about to generate a payslip for:</p>
					<ul class="list-disc list-inside mb-4">
						<li><strong>Employee:</strong> ${selectedEmployee.firstName} ${selectedEmployee.lastName}</li>
						<li><strong>Period:</strong> ${selectedPeriod.month}</li>
						<li><strong>Net Pay:</strong> ${formatPHP(payrollBreakdown.netPay)}</li>
					</ul>
					<p class="text-sm text-gray-600">This saves a payroll record for tracking. Actual payout and physical payslip handout are handled offline by the shop owner.</p>
				</div>
			`,
			showCancelButton: true,
			confirmButtonText: "Generate Payslip",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#3b82f6",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		setIsGenerating(true);
		
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			// Prepare payroll data for the store() endpoint.
			// base_salary and deductions are intentionally omitted — store() reads
			// base salary from the employee record and computes statutory deductions
			// (SSS, PhilHealth, Pag-IBIG, tax) via PayrollService.
			const attendanceDays = attendanceSummary.attendanceDays;
			const leaveDays = attendanceSummary.leaveDays;
			const absentDays = attendanceSummary.absentDays;

			const payrollData = {
				employee_id:      selectedEmployee.id,
				payrollPeriod:    payrollPeriodKey || selectedPeriod.month,
				paymentMethod:    'bank_transfer',
				// Attendance inputs — drive the service's day-worked calculation
				attendance_days:  attendanceDays,
				leave_days:       leaveDays,
				absent_days:      absentDays,
				overtime_hours:   totalOvertimeHours,
				special_holiday_hours: specialHolidayHours,
				regular_holiday_hours: regularHolidayHours,
				undertime_hours:  clampNonNegative(totalUndertimeHours),
				// Extra earnings — appended as custom components by the service
				salesCommission:  payrollBreakdown.earnings.salesCommission,
				performanceBonus: payrollBreakdown.earnings.performanceBonus,
				otherAllowances:  payrollBreakdown.earnings.otherAllowances,
				notes: `Generated payslip for period ${selectedPeriod.month}. Regular: ${totalRegularHours}h, OT: ${totalOvertimeHours}h, Special Holiday: ${specialHolidayHours}h, Regular Holiday: ${regularHolidayHours}h, Undertime: ${totalUndertimeHours}h, Absent: ${absentDays} day(s)`,
			};

			const response = await fetch(hrApiBase + '/payroll', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify(payrollData),
			});

			if (!response.ok) {
				const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
				throw new Error(errorData.message || errorData.error || `Failed to generate payslip (Status: ${response.status})`);
			}

			const data = await response.json();

			// Update employee data to mark slip as generated
			setEmployeeData(prevData => 
				prevData.map(e => 
					e.id === selectedEmployee.id 
						? { 
							...e, 
							hasSlipForPeriod: true,
							payrollWorkflowStatus: "awaiting_checker",
							lastSlipGenerated: new Date().toLocaleDateString('en-US', { 
								year: 'numeric', 
								month: 'short', 
								day: 'numeric' 
							})
						}
						: e
				)
			);
			
			setIsGenerating(false);

			await Swal.fire({
				icon: "success",
				title: "Payslip Generated Successfully",
				html: `
					<div class="text-left">
						<p class="mb-2">Payslip details:</p>
						<ul class="list-disc list-inside mb-4">
							<li><strong>Employee:</strong> ${selectedEmployee.firstName} ${selectedEmployee.lastName}</li>
							<li><strong>Period:</strong> ${selectedPeriod.month}</li>
							<li><strong>Gross Pay:</strong> ${formatPHP(payrollBreakdown.grossPay)}</li>
							<li><strong>Net Pay:</strong> ${formatPHP(payrollBreakdown.netPay)}</li>
						</ul>
						<p class="text-sm text-green-700">The payslip has been locked and is ready for review.</p>
					</div>
				`,
				confirmButtonColor: "#3b82f6",
				timer: 5000,
			});

			closeEmployee();
		} catch (error: any) {
			console.error('Error generating payslip:', error);
			setIsGenerating(false);
			
			await Swal.fire({
				icon: "error",
				title: "Failed to Generate Payslip",
				text: error.message || "An error occurred while generating the payslip. Please try again.",
				confirmButtonColor: "#3b82f6",
			});
		}
	};

	const handleCalculateClick = () => {
		void handleCalculate();
	};

	const startIndex = (page - 1) * pageSize;
	const endIndex = Math.min(startIndex + pageSize, filtered.length);

	const completedSlips = employeeData.filter(e => e.hasSlipForPeriod).length;
	const pendingSlips = employeeData.filter(e => e.status === "active" && !e.hasSlipForPeriod).length;
	const failedSlips = 0;
	const currentYear = new Date().getFullYear();
	const thirteenthYearOptions = [currentYear - 1, currentYear, currentYear + 1];

	const handleReleaseThirteenthMonth = async () => {
		if (ownerMode) {
			await Swal.fire({
				icon: "info",
				title: "Owner review only",
				text: "13th-month release is completed through the Finance approval workflow.",
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		const controlledReleaseDate = `${thirteenthYear}-12-31`;

		const result = await Swal.fire({
			icon: "question",
			title: "Run 13th-Month Release?",
			html: `
				<div class="text-left">
					<p class="mb-2">This will execute controlled 13th-month release for:</p>
					<ul class="list-disc list-inside mb-3">
						<li><strong>Year:</strong> ${thirteenthYear}</li>
						<li><strong>Release Date:</strong> ${controlledReleaseDate}</li>
						<li><strong>Window:</strong> December-only (unless backend override is allowed)</li>
					</ul>
					<p class="text-xs text-gray-600">Only employees with accrued unreleased balance and valid December payroll will be processed.</p>
				</div>
			`,
			showCancelButton: true,
			confirmButtonText: "Run Release",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#16a34a",
			cancelButtonColor: "#6b7280",
		});

		if (!result.isConfirmed) return;

		setIsProcessingThirteenth(true);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await fetch(hrApiBase + '/payroll/13th-month/release', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					year: thirteenthYear,
					release_date: controlledReleaseDate,
				}),
			});

			const payload = await response.json().catch(() => ({}));
			if (!response.ok) {
				throw new Error(payload.error || payload.message || '13th-month release failed');
			}

			const releaseData: ThirteenthMonthReleaseResult = payload.result;

			await Swal.fire({
				icon: releaseData.processed_count > 0 ? 'success' : 'info',
				title: '13th-Month Release Completed',
				html: `
					<div class="text-left space-y-2">
						<p>Year: <strong>${releaseData.year}</strong></p>
						<p>Release Date: <strong>${releaseData.release_date}</strong></p>
						<p class="text-green-700">✅ Processed: <strong>${releaseData.processed_count}</strong></p>
						<p class="text-amber-700">⏭️ Skipped: <strong>${releaseData.skipped_count}</strong></p>
					</div>
				`,
				confirmButtonColor: '#3b82f6',
			});
		} catch (error: any) {
			await Swal.fire({
				icon: 'error',
				title: 'Release Failed',
				text: error?.message || 'Failed to execute 13th-month release',
				confirmButtonColor: '#dc2626',
			});
		} finally {
			setIsProcessingThirteenth(false);
		}
	};

	return (
		<div className="space-y-6">
			<div className="flex flex-col gap-2">
				<h1 className="text-2xl font-semibold text-gray-900 dark:text-white">Generate Payslip</h1>
				<p className="text-gray-600 dark:text-gray-400">Generate payroll records for SME tracking; payslips are released physically by the shop owner.</p>
			</div>

			{/* 13th-Month Controls */}
			<div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm p-6">
				<div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
					<div>
						<h3 className="text-sm font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">13th-Month Release</h3>
						<p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
							Run controlled December release for your selected year.
						</p>
					</div>
					<div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
						<select
							value={thirteenthYear}
							onChange={(e) => setThirteenthYear(Number(e.target.value))}
							aria-label="Select 13th-month year"
							className="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
						>
							{thirteenthYearOptions.map((year) => (
								<option key={year} value={year}>{year}</option>
							))}
						</select>
						<button
							onClick={handleReleaseThirteenthMonth}
							disabled={ownerMode || isProcessingThirteenth}
							className="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
						>
							{ownerMode ? 'Finance workflow required' : isProcessingThirteenth ? 'Processing…' : 'Run 13th-Month Release'}
						</button>
					</div>
				</div>
			</div>

			{/* Release Authorization Status */}
			<div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm p-6">
				<div className="flex flex-col gap-3">
					<div>
						<h3 className="text-sm font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">Release Authorization Status</h3>
						<p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
							{selectedPeriod ? `${selectedPeriod.month} readiness for release controls` : 'Select a payroll period to view release readiness'}
						</p>
					</div>
					<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
						<div className="rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 px-4 py-3">
							<p className="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">Checker Approved</p>
							<p className="text-lg font-semibold text-gray-900 dark:text-white mt-2">
								{isLoadingGovernanceStatus
									? 'Loading…'
									: `${governanceStatus.checkerApproved}/${governanceStatus.totalPayrolls} payrolls`}
							</p>
							<p className="text-xs text-gray-500 dark:text-gray-500 mt-2">
								{governanceStatus.requireChecker
									? `${governanceStatus.awaitingChecker} awaiting checker sign-off`
									: 'Checker step is not required'}
							</p>
						</div>
						<div className="rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 px-4 py-3">
							<p className="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">Final Approver Required</p>
							<p className="text-lg font-semibold text-gray-900 dark:text-white mt-2">
								{isLoadingGovernanceStatus
									? 'Loading…'
									: governanceStatus.requireFinalApprover
										? `${governanceStatus.awaitingFinalApprover} awaiting final release`
										: 'Not required'}
							</p>
							<p className="text-xs text-gray-500 dark:text-gray-500 mt-2">
								{isLoadingGovernanceStatus
									? 'Fetching governance checks...'
									: `${governanceStatus.paidPayrolls} already paid`}
							</p>
						</div>
					</div>
				</div>
			</div>

			{/* Filters */}
			<div className="grid grid-cols-1 md:grid-cols-3 gap-4">
				<div className="md:col-span-2">
					<label className="text-sm text-gray-600 dark:text-gray-300">Search</label>
					<input
						value={search}
						onChange={(e) => handleSearch(e.target.value)}
						placeholder="Search by name, ID, position, or department"
						className="mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
					/>
				</div>
				<div>
					<label className="text-sm text-gray-600 dark:text-gray-300">Department</label>
					<select
						value={department}
						onChange={(e) => handleDepartment(e.target.value)}
						aria-label="Filter by department"
						className="mt-1 w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
					>
						<option value="">All</option>
						{departments.map((d) => (
							<option key={d} value={d}>{d}</option>
						))}
					</select>
				</div>
			</div>

			{/* Employee Table */}
			<div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
				<div className="px-6 py-5 border-b border-gray-100 dark:border-gray-800 bg-linear-to-r from-gray-50 to-white dark:from-gray-900 dark:to-gray-900/90">
					<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
						<div>
							<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Active Employees</h3>
							<p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
								Select employees to include in this payroll run.
							</p>
						</div>

						<div className="w-full lg:w-auto">
							<div className="flex flex-wrap items-center gap-2 lg:flex-nowrap lg:justify-end">
								<label className="text-sm font-medium text-gray-600 dark:text-gray-300">Payroll Period</label>
								<button
									type="button"
									onClick={() => setIsPeriodModalOpen(true)}
									disabled={isLoadingPeriods || payrollPeriods.length === 0}
									aria-label="Open payroll period picker"
									className="min-w-64 h-14 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-4 text-left disabled:opacity-50 disabled:cursor-not-allowed hover:border-blue-300 dark:hover:border-blue-600 hover:shadow-sm transition-all"
								>
									<div className="flex items-center justify-between gap-3">
										<div className="min-w-0">
											<p className="text-sm font-semibold text-gray-900 dark:text-white truncate leading-tight">
												{isLoadingPeriods
													? "Loading periods..."
													: selectedPeriod
														? selectedPeriod.month
														: "No periods available"}
											</p>
										</div>
										<div className="shrink-0">
											<svg className="size-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
											</svg>
										</div>
									</div>
								</button>
								<button
									onClick={handleGenerateAll}
									disabled={selectedPendingEmployees.length === 0 || isGenerating}
									className="h-14 px-5 rounded-xl bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
									title={selectedPendingEmployees.length === 0 ? "Select employees first" : `Generate ${selectedPendingEmployees.length} payslips`}
								>
									<CalculatorIcon className="size-4" />
									Generate Selected ({selectedPendingEmployees.length})
								</button>
							</div>
						</div>
					</div>
				</div>

				{selectedPendingEmployees.length > 0 && (
					<div className="px-6 py-3 border-b border-blue-100 dark:border-blue-900/40 bg-blue-50/60 dark:bg-blue-900/10">
						<p className="text-sm text-blue-800 dark:text-blue-300">
							{selectedPendingEmployees.length} employee{selectedPendingEmployees.length > 1 ? "s" : ""} selected for payroll generation.
						</p>
					</div>
				)}
				<div className="overflow-x-auto">
					<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800 text-sm">
						<thead className="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
							<tr>
								<th className="px-4 py-3 text-center">
									<input
										type="checkbox"
										checked={isAllFilteredSelected}
										onChange={toggleAllFilteredSelection}
										disabled={filteredSelectableIds.length === 0}
										className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
										aria-label="Select all filtered employees"
									/>
								</th>
								<th className="px-6 py-3 text-left">Employee</th>
								<th className="px-6 py-3 text-left">Position</th>
								<th className="px-6 py-3 text-left">Base Rate</th>
								<th className="px-6 py-3 text-left">Status</th>
								<th className="px-6 py-3 text-center">Action</th>
							</tr>
						</thead>
						<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
							{isLoadingEmployees ? (
								<tr>
									<td colSpan={6} className="px-6 py-12 text-center">
										<div className="flex flex-col items-center justify-center space-y-3">
											<div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
											<p className="text-sm text-gray-500 dark:text-gray-400">Loading employees...</p>
										</div>
									</td>
								</tr>
							) : paginated.length === 0 ? (
								<tr>
									<td className="px-6 py-6 text-center text-gray-500 dark:text-gray-400" colSpan={6}>
										No active employees found.
									</td>
								</tr>
							) : (
								paginated.map((employee) => (
								<tr
									key={employee.id}
									className={`transition-colors ${
										selectedEmployeeIds.includes(employee.id)
											? "bg-blue-50/70 dark:bg-blue-900/10"
											: "hover:bg-gray-50 dark:hover:bg-gray-800/50"
									}`}
								>
									<td className="px-4 py-4 text-center">
										<input
											type="checkbox"
											checked={selectedEmployeeIds.includes(employee.id)}
											onChange={() => toggleEmployeeSelection(employee.id)}
											disabled={employee.hasSlipForPeriod || employee.status !== "active"}
											className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-40"
											aria-label={`Select ${employee.firstName} ${employee.lastName}`}
										/>
									</td>
									<td className="px-6 py-4">
										<div className="flex items-center gap-3">
											<div className="h-10 w-10 rounded-full bg-gray-950 flex items-center justify-center">
												<span className="text-white font-medium text-sm">{getInitials(employee.firstName, employee.lastName)}</span>
											</div>
											<div className="flex flex-col">
												<span className="font-semibold text-gray-900 dark:text-white">{employee.firstName} {employee.lastName}</span>
												<span className="text-xs text-gray-500 dark:text-gray-400">{employee.employeeId} · {employee.department}</span>
											</div>
										</div>
									</td>
									<td className="px-6 py-4 text-gray-700 dark:text-gray-300">{employee.position}</td>
									<td className="px-6 py-4 text-gray-900 dark:text-white font-semibold">
										{(employee.dailySalary ?? employee.dailyRate) ? formatPHP(employee.dailySalary ?? employee.dailyRate ?? 0) + "/day" : 
										 employee.hourlyRate ? formatPHP(employee.hourlyRate) + "/hr" : "N/A"}
									</td>
									<td className="px-6 py-4">
										{employee.payrollWorkflowStatus === "rejected" ? (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-red-100 dark:bg-red-900/20 text-red-700 dark:text-red-300 text-xs font-semibold">
												Rejected
											</span>
										) : !employee.hasSlipForPeriod || employee.payrollWorkflowStatus === "pending" ? (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-amber-100 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 text-xs font-semibold">
												Pending
											</span>
										) : employee.payrollWorkflowStatus === "awaiting_checker" ? (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-amber-100 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 text-xs font-semibold">
												Awaiting Finance
											</span>
										) : employee.payrollWorkflowStatus === "awaiting_final_approval" ? (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-sky-100 dark:bg-sky-900/20 text-sky-700 dark:text-sky-300 text-xs font-semibold">
												Awaiting Owner
											</span>
										) : employee.payrollWorkflowStatus === "ready_for_disbursement" ? (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-violet-100 dark:bg-violet-900/20 text-violet-700 dark:text-violet-300 text-xs font-semibold">
												Ready to Disburse
											</span>
										) : (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-green-100 dark:bg-green-900/20 text-green-700 dark:text-green-300 text-xs font-semibold">
												Paid
											</span>
										)}
									</td>
									<td className="px-6 py-4 text-center">
										<button
											onClick={() => openEmployee(employee)}
											disabled={employee.hasSlipForPeriod}
											className={`inline-flex items-center justify-center p-2 rounded-lg transition-colors ${
												employee.hasSlipForPeriod
													? "opacity-50 cursor-not-allowed bg-gray-100 dark:bg-gray-800"
													: "hover:bg-blue-50 dark:hover:bg-blue-900/20"
											}`}
											title={employee.hasSlipForPeriod ? "Payslip already generated" : "Generate payslip for this employee"}
											aria-label="Generate payslip"
										>
											<CalculatorIcon className={`size-5 ${employee.hasSlipForPeriod ? "text-gray-400" : "text-blue-600 dark:text-blue-400"}`} />
										</button>
									</td>
								</tr>
							))
							)}
						</tbody>
					</table>
				</div>

				{/* Pagination */}
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

			{isPeriodModalOpen && createPortal(
				<div
					className="fixed inset-0 z-999999 bg-black/55 backdrop-blur-sm flex items-center justify-center p-4"
					onClick={() => {
						setIsPeriodModalOpen(false);
						setPeriodSearch("");
					}}
				>
					<div
						className="w-full max-w-2xl rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-2xl overflow-hidden"
						onClick={(event) => event.stopPropagation()}
					>
						<div className="flex items-start justify-between gap-4 p-5 border-b border-gray-100 dark:border-gray-800 bg-linear-to-r from-slate-50 to-white dark:from-gray-900 dark:to-gray-900">
							<div>
								<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Select Payroll Period</h3>
								<p className="text-sm text-gray-500 dark:text-gray-400 mt-1">Choose the period for calculation and payslip generation.</p>
							</div>
							<button
								type="button"
								onClick={() => {
									setIsPeriodModalOpen(false);
									setPeriodSearch("");
								}}
								className="text-2xl leading-none text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
								aria-label="Close period picker"
							>
								×
							</button>
						</div>

						<div className="p-5 space-y-4">
							<div className="relative">
								<svg className="size-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
								</svg>
								<input
									type="text"
									value={periodSearch}
									onChange={(event) => setPeriodSearch(event.target.value)}
									placeholder="Search period (month or date)"
									className="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 pl-9 pr-3 py-2.5 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40 focus:border-blue-400"
								/>
							</div>

							<div className="max-h-[60vh] overflow-y-auto rounded-xl border border-gray-100 dark:border-gray-800 bg-gray-50/40 dark:bg-gray-900/20 p-2 space-y-2">
								{filteredPayrollPeriods.length === 0 ? (
									<div className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-900 rounded-lg border border-dashed border-gray-200 dark:border-gray-700">
										No payroll periods found.
									</div>
								) : (
									filteredPayrollPeriods.map((period) => {
										const actualIndex = payrollPeriods.findIndex((candidate) => candidate === period);
										const isSelected = actualIndex === selectedPeriodIndex;

										return (
											<button
												key={`${period.month}-${period.startDate}-${period.endDate}`}
												type="button"
												onClick={() => handlePeriodSelect(actualIndex)}
												className={`w-full text-left px-4 py-3 rounded-xl border transition-all cursor-pointer ${
													isSelected
														? "bg-blue-50 border-blue-200 shadow-sm dark:bg-blue-900/20 dark:border-blue-800"
														: "bg-white border-gray-200 hover:border-blue-300 hover:shadow-sm hover:-translate-y-px dark:bg-gray-900 dark:border-gray-800 dark:hover:border-blue-700"
												}`}
											>
												<div className="flex items-start justify-between gap-3">
													<div className="min-w-0">
														<p className="text-sm font-semibold text-gray-900 dark:text-white">{period.month}</p>
														<p className="text-sm text-gray-700 dark:text-gray-300 mt-1 font-medium">{period.startDate} to {period.endDate}</p>
														<p className="text-sm text-gray-600 dark:text-gray-400 mt-1">{period.workingDays} working days</p>
													</div>
													<div className="shrink-0 flex items-center gap-2">
														<span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${attendanceStatusBadgeClasses[period.attendanceStatus]}`}>
															{attendanceStatusLabel[period.attendanceStatus]}
														</span>
														<svg className="size-4 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
															<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
														</svg>
														{isSelected && (
															<CheckIcon className="size-4 text-blue-600 dark:text-blue-400" />
														)}
													</div>
												</div>
											</button>
										);
									})
								)}
							</div>
						</div>

						<div className="flex justify-end p-5 border-t border-gray-100 dark:border-gray-800">
							<button
								type="button"
								onClick={() => {
									setIsPeriodModalOpen(false);
									setPeriodSearch("");
								}}
								className="px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800"
							>
								Done
							</button>
						</div>
					</div>
				</div>,
				document.body
			)}

			{/* Generation Modal - Continued in next part due to length */}
			{selectedEmployee && createPortal(
				<div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto p-8">
						<div className="flex items-start justify-between mb-6">
							<div>
								<h3 className="text-2xl font-bold text-gray-900 dark:text-white">Generate Payslip</h3>
								<p className="text-gray-500 dark:text-gray-400 text-sm">{selectedPeriod.month} ({selectedPeriod.startDate} to {selectedPeriod.endDate})</p>
							</div>
							<button
								onClick={closeEmployee}
								className="text-2xl text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
							>
								×
							</button>
						</div>

						{/* Employee Info */}
						<div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
							<div className="p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
								<p className="text-sm text-gray-600 dark:text-gray-400 mb-2">Employee Information</p>
								<div className="flex items-center gap-3">
									<div className="h-12 w-12 rounded-full bg-gray-950 flex items-center justify-center">
										<span className="text-white font-medium">{getInitials(selectedEmployee.firstName, selectedEmployee.lastName)}</span>
									</div>
									<div>
										<p className="text-lg font-semibold text-gray-900 dark:text-white">{selectedEmployee.firstName} {selectedEmployee.lastName}</p>
										<p className="text-sm text-gray-600 dark:text-gray-400">{selectedEmployee.employeeId} · {selectedEmployee.position}</p>
									</div>
								</div>
								<div className="mt-3 text-sm text-gray-700 dark:text-gray-300">
									<div className="flex justify-between py-1">
										<span className="text-gray-600 dark:text-gray-400">Department:</span>
										<span className="font-medium">{selectedEmployee.department}</span>
									</div>
								</div>
							</div>

							<div className="p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
								<p className="text-sm text-gray-600 dark:text-gray-400 mb-2">Pay Configuration</p>
								<div className="space-y-2 text-sm">
									<div className="flex justify-between">
										<span className="text-gray-600 dark:text-gray-400">Base Rate:</span>
										<span className="font-semibold text-gray-900 dark:text-white">
											{(selectedEmployee.dailySalary ?? selectedEmployee.dailyRate) ? formatPHP(selectedEmployee.dailySalary ?? selectedEmployee.dailyRate ?? 0) + "/day" : 
											 selectedEmployee.hourlyRate ? formatPHP(selectedEmployee.hourlyRate) + "/hr" : "N/A"}
										</span>
									</div>
									{(selectedEmployee.salesCommissionRate ?? 0) > 0 && (
										<div className="flex justify-between">
											<span className="text-gray-600 dark:text-gray-400">Sales Commission:</span>
											<span className="font-medium text-green-600 dark:text-green-400">{((selectedEmployee.salesCommissionRate ?? 0) * 100).toFixed(1)}% of sales</span>
										</div>
									)}
									{(selectedEmployee.performanceBonusRate ?? 0) > 0 && (
										<div className="flex justify-between">
											<span className="text-gray-600 dark:text-gray-400">Performance Bonus:</span>
											<span className="font-medium text-green-600 dark:text-green-400">{((selectedEmployee.performanceBonusRate ?? 0) * 100).toFixed(1)}% of target</span>
										</div>
									)}
									{(selectedEmployee.otherAllowances ?? 0) > 0 && (
										<div className="flex justify-between">
											<span className="text-gray-600 dark:text-gray-400">Other Allowances:</span>
											<span className="font-medium text-gray-900 dark:text-white">{formatPHP(selectedEmployee.otherAllowances ?? 0)}</span>
										</div>
									)}
									{selectedEmployee.loans && selectedEmployee.loans.monthlyDeduction > 0 && (
										<div className="flex justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400">Loan Deduction:</span>
											<span className="font-medium text-red-600 dark:text-red-400">-{formatPHP(selectedEmployee.loans.monthlyDeduction)}</span>
										</div>
									)}
								</div>
							</div>
						</div>

						{/* Hours Breakdown Section */}
						<div className="mb-6 p-6 rounded-xl border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/10">
							<div className="flex items-center justify-between mb-4">
								<div>
									<h4 className="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
										<ClockIcon className="size-5 text-blue-600 dark:text-blue-400" />
										Hours Breakdown
									</h4>
									<p className="text-sm text-blue-600 dark:text-blue-400 mt-1">
										✓ Regular, overtime, undertime, and absent days are auto-calculated from attendance ({selectedPeriod.startDate} to {selectedPeriod.endDate})
									</p>
									<p className="text-xs text-blue-500 dark:text-blue-300 mt-1">
										Holiday hours are auto-filled from attendance/holiday data and can still be edited.
									</p>
								</div>
								<button
									onClick={handleCalculateClick}
									className="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-all flex items-center gap-2"
								>
									<CalculatorIcon className="size-4" />
									Calculate
								</button>
							</div>
							
							<div className="grid grid-cols-1 md:grid-cols-4 gap-4">
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										Regular Hours
									</label>
									<div className="relative">
										<input
											type="number"
											min="0"
											step="0.5"
											value={totalRegularHours}
											readOnly
											className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white cursor-not-allowed"
											placeholder="0"
										/>
										<span className="absolute right-3 top-2.5 text-xs text-green-600 dark:text-green-400">Auto</span>
									</div>
									<p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
										Expected: {selectedPeriod.hasConfiguredOperatingHours
											? formatHours(Number(selectedPeriod.expectedRegularHours ?? 0))
											: 'Not configured'}
									</p>
								</div>
								
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										Overtime Hours
									</label>
									<div className="relative">
										<input
											type="number"
											min="0"
											step="0.5"
											value={totalOvertimeHours}
											readOnly
											className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white cursor-not-allowed"
											placeholder="0"
										/>
										<span className="absolute right-3 top-2.5 text-xs text-green-600 dark:text-green-400">Auto</span>
									</div>
									<p className="text-xs text-green-500 dark:text-green-400 mt-1">+25% rate</p>
								</div>
								
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										Undertime Hours
									</label>
									<div className="relative">
										<input
											type="number"
											min="0"
											step="0.5"
											value={totalUndertimeHours}
											readOnly
											className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white cursor-not-allowed"
											placeholder="0"
										/>
										<span className="absolute right-3 top-2.5 text-xs text-green-600 dark:text-green-400">Auto</span>
									</div>
									<p className="text-xs text-orange-500 dark:text-orange-400 mt-1">Deducted</p>
								</div>
								
								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										Absent Days
									</label>
									<div className="relative">
										<input
											type="number"
											min="0"
											value={totalAbsentDays}
											readOnly
											className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white cursor-not-allowed"
											placeholder="0"
										/>
										<span className="absolute right-3 top-2.5 text-xs text-green-600 dark:text-green-400">Auto</span>
									</div>
									<p className="text-xs text-red-500 dark:text-red-400 mt-1">
										Max: {selectedPeriod.hasConfiguredOperatingHours
											? Number(selectedPeriod.expectedAttendanceDays ?? 0)
											: selectedPeriod.workingDays}{' '}
										{selectedPeriod.hasConfiguredOperatingHours ? 'scheduled days' : 'weekdays'}
									</p>
								</div>

								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										Special Holiday Hours
									</label>
									<input
										type="number"
										min="0"
										step="0.5"
										value={specialHolidayHours}
										onChange={(e) => setSpecialHolidayHours(Number(e.target.value) || 0)}
										className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
										placeholder="0"
									/>
										<p className="text-xs text-blue-500 dark:text-blue-400 mt-1">Auto-filled from holiday calendar, editable override</p>
								</div>

								<div>
									<label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
										Regular Holiday Hours
									</label>
									<input
										type="number"
										min="0"
										step="0.5"
										value={regularHolidayHours}
										onChange={(e) => setRegularHolidayHours(Number(e.target.value) || 0)}
										className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white"
										placeholder="0"
									/>
										<p className="text-xs text-blue-500 dark:text-blue-400 mt-1">Auto-filled from holiday calendar, editable override</p>
								</div>

							</div>
						</div>

						{/* Calculation Results */}
						{isCalculating ? (
							<div className="flex items-center justify-center py-12">
								<div className="text-center">
									<svg className="animate-spin size-12 text-blue-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
										<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
										<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
									</svg>
									<p className="text-gray-600 dark:text-gray-400">Calculating payroll...</p>
								</div>
							</div>
						) : payrollBreakdown ? (
							<div className="space-y-4">
								{/* Hours Breakdown */}
								<div className="rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5">
									<h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 uppercase tracking-wide flex items-center gap-2">
										<ClockIcon className="size-4" />
										Hours Breakdown
									</h4>
									<div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Regular Hours</span>
											<span className="text-lg font-bold text-gray-900 dark:text-white">{formatHours(payrollBreakdown.hours.regularHours)}</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Overtime Hours</span>
											<span className="text-lg font-bold text-green-600 dark:text-green-400">{formatHours(payrollBreakdown.hours.overtimeHours)}</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Special Holiday Hours</span>
											<span className="text-lg font-bold text-blue-600 dark:text-blue-400">{formatHours(payrollBreakdown.hours.specialHolidayHours)}</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Regular Holiday Hours</span>
											<span className="text-lg font-bold text-blue-600 dark:text-blue-400">{formatHours(payrollBreakdown.hours.regularHolidayHours)}</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Undertime</span>
											<span className="text-lg font-bold text-amber-600 dark:text-amber-400">{formatHours(payrollBreakdown.hours.undertimeHours)}</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Absent Days</span>
											<span className="text-lg font-bold text-red-600 dark:text-red-400">{payrollBreakdown.hours.absentDays}</span>
										</div>
									</div>
								</div>

								{/* Earnings Breakdown */}
								<div className="rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5">
									<h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 uppercase tracking-wide">Earnings</h4>
									<div className="space-y-2.5 text-sm">
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Basic Pay</span>
											<span className="text-gray-900 dark:text-white font-medium">{formatPHP(payrollBreakdown.earnings.basicPay)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Overtime Pay</span>
											<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(payrollBreakdown.earnings.overtimePay)}</span>
										</div>
										{payrollBreakdown.earnings.specialHolidayPay > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Special Holiday Pay</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(payrollBreakdown.earnings.specialHolidayPay)}</span>
											</div>
										)}
										{payrollBreakdown.earnings.regularHolidayPay > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Regular Holiday Pay</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(payrollBreakdown.earnings.regularHolidayPay)}</span>
											</div>
										)}
										{payrollBreakdown.earnings.salesCommission > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Sales Commission</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(payrollBreakdown.earnings.salesCommission)}</span>
											</div>
										)}
										{payrollBreakdown.earnings.performanceBonus > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Performance Bonus</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(payrollBreakdown.earnings.performanceBonus)}</span>
											</div>
										)}
										{payrollBreakdown.earnings.otherAllowances > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Other Allowances</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(payrollBreakdown.earnings.otherAllowances)}</span>
											</div>
										)}
										<div className="border-t border-gray-200 dark:border-gray-700 pt-2.5 mt-2.5">
											<div className="flex items-center justify-between">
												<span className="text-sm font-semibold text-gray-900 dark:text-white">Total Earnings</span>
												<span className="text-base font-bold text-green-600 dark:text-green-400">{formatPHP(payrollBreakdown.earnings.totalEarnings)}</span>
											</div>
										</div>
									</div>
								</div>

								{/* Deductions Breakdown */}
								<div className="rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5">
									<h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 uppercase tracking-wide">Deductions</h4>
									<div className="space-y-2.5 text-sm">
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Withholding Tax</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.withholdingTax)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">SSS Contribution</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.sssContribution)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">PhilHealth Contribution</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.philhealthContribution)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Pag-IBIG Contribution</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.pagibigContribution)}</span>
										</div>
										{payrollBreakdown.deductions.absentDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Absent Deductions</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.absentDeductions)}</span>
											</div>
										)}
										{payrollBreakdown.deductions.undertimeDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Undertime Deductions</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.undertimeDeductions)}</span>
											</div>
										)}
										{payrollBreakdown.deductions.loanDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Loan Payment</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.loanDeductions)}</span>
											</div>
										)}
										{payrollBreakdown.deductions.otherDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Other Deductions</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(payrollBreakdown.deductions.otherDeductions)}</span>
											</div>
										)}
										<div className="border-t border-gray-200 dark:border-gray-700 pt-2.5 mt-2.5">
											<div className="flex items-center justify-between">
												<span className="text-sm font-semibold text-gray-900 dark:text-white">Total Deductions</span>
												<span className="text-base font-bold text-red-600 dark:text-red-400">-{formatPHP(payrollBreakdown.totalDeductions)}</span>
											</div>
										</div>
									</div>
								</div>

								{/* Net Pay Summary */}
								<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-6">
									<div className="flex items-center justify-between">
										<div>
											<p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Net Pay</p>
											<p className="text-3xl font-extrabold text-green-600 dark:text-green-400">{formatPHP(payrollBreakdown.netPay)}</p>
										</div>
										<CheckCircleIcon className="size-16 text-green-500 dark:text-green-400 opacity-50" />
									</div>
									<div className="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-4 text-sm">
										<div>
											<span className="text-gray-600 dark:text-gray-400 block">Gross Pay</span>
											<span className="font-semibold text-gray-900 dark:text-white">{formatPHP(payrollBreakdown.grossPay)}</span>
										</div>
										<div>
											<span className="text-gray-600 dark:text-gray-400 block">Total Deductions</span>
											<span className="font-semibold text-red-600 dark:text-red-400">-{formatPHP(payrollBreakdown.totalDeductions)}</span>
										</div>
									</div>
								</div>

								{/* Warning Message */}
								<div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/30 rounded-lg p-4">
									<div className="flex gap-3">
										<AlertIcon className="size-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
										<div className="text-sm text-amber-800 dark:text-amber-300">
											<p className="font-semibold mb-1">Important Notice</p>
											<p>Once generated, this payslip will be locked and cannot be modified without proper authorization. Please verify all calculations before proceeding.</p>
										</div>
									</div>
								</div>
							</div>
						) : (
							<div className="text-center py-12 text-gray-500 dark:text-gray-400">
								<CalculatorIcon className="size-16 mx-auto mb-4 opacity-30" />
								<p className="text-lg font-medium">Review attendance inputs and click Calculate</p>
								<p className="text-sm">A payroll preview will appear here for the selected employee and period.</p>
							</div>
						)}

						<div className="mt-6 pt-4 border-t border-gray-200 dark:border-gray-800 flex justify-end gap-3">
							<button
								onClick={closeEmployee}
								className="px-5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleGenerateSlip}
								disabled={!payrollBreakdown || isCalculating || isGenerating}
								className="px-5 py-2.5 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-green-500/30"
							>
								<CheckIcon className="size-4" />
								{isGenerating ? 'Generating...' : 'Generate Payslip'}
							</button>
						</div>

					</div>
				</div>,
				document.body
			)}

			{showBatchPreviewModal && batchPreviewData && createPortal(
				<div className="fixed inset-0 z-999999 bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-6xl w-full max-h-[90vh] overflow-y-auto p-8">
						<div className="flex items-start justify-between mb-6">
							<div>
								<h3 className="text-2xl font-bold text-gray-900 dark:text-white">Batch Payroll Preview</h3>
								<p className="text-gray-500 dark:text-gray-400 text-sm">{selectedPeriod.month} ({selectedPeriod.startDate} to {selectedPeriod.endDate})</p>
							</div>
							<button
								onClick={closeBatchPreviewModal}
								className="text-2xl text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
							>
								×
							</button>
						</div>

						<div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
							<div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
								<p className="text-sm text-green-600 dark:text-green-400 mb-1">Total Gross</p>
								<p className="text-2xl font-bold text-green-900 dark:text-green-300">{formatPHP(batchPreviewData.summary.total_gross)}</p>
							</div>
							<div className="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
								<p className="text-sm text-red-600 dark:text-red-400 mb-1">Total Deductions</p>
								<p className="text-2xl font-bold text-red-900 dark:text-red-300">{formatPHP(batchPreviewData.summary.total_gross - batchPreviewData.summary.total_net)}</p>
							</div>
							<div className="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4">
								<p className="text-sm text-purple-600 dark:text-purple-400 mb-1">Total Net</p>
								<p className="text-2xl font-bold text-purple-900 dark:text-purple-300">{formatPHP(batchPreviewData.summary.total_net)}</p>
							</div>
						</div>

						<div className="overflow-x-auto mb-6">
							<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800 text-sm">
								<thead className="bg-gray-50 dark:bg-gray-900/40">
									<tr>
										<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Employee</th>
										<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Department</th>
										<th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Hours</th>
										<th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Gross</th>
										<th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Deductions</th>
										<th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Net Pay</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100 dark:divide-gray-800">
									{batchPreviewData.previews.map((preview) => (
										<tr key={preview.employeeId} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
											<td className="px-4 py-3">
												<div className="flex flex-col">
													<span className="font-medium text-gray-900 dark:text-white">{preview.employeeName}</span>
													<span className="text-xs text-gray-500 dark:text-gray-400">{preview.position}</span>
												</div>
											</td>
											<td className="px-4 py-3 text-gray-700 dark:text-gray-300">{preview.department}</td>
											<td className="px-4 py-3 text-right">
												<div className="text-xs space-y-1">
													<div className="text-gray-600 dark:text-gray-400">
														R: {formatHours(preview.hours.regularHours)}
													</div>
													{preview.hours.overtimeHours > 0 && (
														<div className="text-green-600 dark:text-green-400">
															OT: {formatHours(preview.hours.overtimeHours)}
														</div>
													)}
													{preview.hours.absentDays > 0 && (
														<div className="text-red-600 dark:text-red-400">
															Absent: {preview.hours.absentDays}d
														</div>
													)}
												</div>
											</td>
											<td className="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">
												{formatPHP(preview.grossPay)}
											</td>
											<td className="px-4 py-3 text-right text-red-600 dark:text-red-400">
												{formatPHP(preview.totalDeductions)}
											</td>
											<td className="px-4 py-3 text-right font-bold text-green-600 dark:text-green-400">
												{formatPHP(preview.netPay)}
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>

						<div className="flex justify-end gap-3">
							<button
								onClick={closeBatchPreviewModal}
								className="px-5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleConfirmBatchGeneration}
								className="px-5 py-2.5 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition-all flex items-center gap-2 shadow-lg shadow-green-500/30"
							>
								<CheckIcon className="size-4" />
								Confirm & Generate All
							</button>
						</div>
					</div>
				</div>,
				document.body
			)}

			{/* Generation Progress Overlay */}
			{isGenerating && generationProgress.total > 0 && createPortal(
				<div className="fixed inset-0 z-999999 bg-black/80 backdrop-blur-sm flex items-center justify-center">
					<div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl p-8 max-w-md w-full">
						<div className="text-center">
							<div className="mb-4">
								<svg className="animate-spin size-16 text-blue-600 mx-auto" fill="none" viewBox="0 0 24 24">
									<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
									<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
								</svg>
							</div>
							<h3 className="text-xl font-bold text-gray-900 dark:text-white mb-2">
								Generating Payslips...
							</h3>
							<p className="text-gray-600 dark:text-gray-400 mb-4">
								Processing {generationProgress.current} of {generationProgress.total} employees
							</p>
							<progress
								className="h-4 w-full overflow-hidden rounded-full appearance-none [&::-webkit-progress-bar]:bg-gray-200 dark:[&::-webkit-progress-bar]:bg-gray-700 [&::-webkit-progress-value]:bg-blue-600 [&::-moz-progress-bar]:bg-blue-600"
								value={generationProgress.current}
								max={generationProgress.total}
							/>
							<p className="text-sm text-gray-500 dark:text-gray-400 mt-2">
								{Math.round((generationProgress.current / generationProgress.total) * 100)}% Complete
							</p>
						</div>
					</div>
				</div>,
				document.body
			)}

		</div>
	);
}
