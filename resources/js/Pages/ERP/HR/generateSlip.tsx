import { useMemo, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import Swal from "sweetalert2";

// ==================== Type Definitions ====================
type EmployeeStatus = "active" | "inactive" | "on_leave";
type PayType = "monthly" | "daily" | "hourly";
type AttendanceStatus = "finalized" | "pending" | "not_started";

type Employee = {
	id: number;
	firstName: string;
	lastName: string;
	employeeId: string;
	department: string;
	position: string;
	status: EmployeeStatus;
	payType: PayType;
	monthlySalary?: number;
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
};

type AttendanceRecord = {
	date: string;
	status: "present" | "absent" | "late" | "half_day" | "on_leave";
	regularHours: number;
	overtimeHours: number;
	undertimeHours: number;
	checkIn?: string;
	checkOut?: string;
};

type PayrollPeriod = {
	month: string;
	startDate: string;
	endDate: string;
	attendanceStatus: AttendanceStatus;
	workingDays: number;
};

type PayrollCalculation = {
	// Hours Breakdown
	totalRegularHours: number;
	totalOvertimeHours: number;
	totalUndertimeHours: number;
	totalAbsentDays: number;
	
	// Earnings
	basicPay: number;
	overtimePay: number;
	salesCommission: number; // Commission from sales
	performanceBonus: number; // Target-based bonus
	otherAllowances: number; // Holiday pay, special bonuses
	totalEarnings: number;
	
	// Deductions
	withholdingTax: number;
	sssContribution: number;
	philhealthContribution: number;
	pagibigContribution: number;
	absentDeductions: number;
	loanDeductions: number;
	otherDeductions: number;
	totalDeductions: number;
	
	// Net Pay
	grossPay: number;
	netPay: number;
};

type Payslip = {
	id: string;
	employeeId: string;
	employeeName: string;
	department: string;
	position: string;
	payrollPeriod: PayrollPeriod;
	calculation: PayrollCalculation;
	generatedAt: string;
	generatedBy: string;
	status: "generated" | "approved" | "paid";
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

// ==================== Payroll Calculation Engine ====================

/**
 * Philippine SSS Contribution Table (2026)
 * Employee share based on monthly salary compensation
 */
const calculateSSSContribution = (monthlySalary: number): number => {
	if (monthlySalary < 4250) return 180;
	if (monthlySalary < 4750) return 202.50;
	if (monthlySalary < 5250) return 225;
	if (monthlySalary < 5750) return 247.50;
	if (monthlySalary < 6250) return 270;
	if (monthlySalary < 6750) return 292.50;
	if (monthlySalary < 7250) return 315;
	if (monthlySalary < 7750) return 337.50;
	if (monthlySalary < 8250) return 360;
	if (monthlySalary < 8750) return 382.50;
	if (monthlySalary < 9250) return 405;
	if (monthlySalary < 9750) return 427.50;
	if (monthlySalary < 10250) return 450;
	if (monthlySalary < 10750) return 472.50;
	if (monthlySalary < 11250) return 495;
	if (monthlySalary < 11750) return 517.50;
	if (monthlySalary < 12250) return 540;
	if (monthlySalary < 12750) return 562.50;
	if (monthlySalary < 13250) return 585;
	if (monthlySalary < 13750) return 607.50;
	if (monthlySalary < 14250) return 630;
	if (monthlySalary < 14750) return 652.50;
	if (monthlySalary < 15250) return 675;
	if (monthlySalary < 15750) return 697.50;
	if (monthlySalary < 16250) return 720;
	if (monthlySalary < 16750) return 742.50;
	if (monthlySalary < 17250) return 765;
	if (monthlySalary < 17750) return 787.50;
	if (monthlySalary < 18250) return 810;
	if (monthlySalary < 18750) return 832.50;
	if (monthlySalary < 19250) return 855;
	if (monthlySalary < 19750) return 877.50;
	if (monthlySalary >= 30000) return 1350; // Maximum
	return 900; // Default mid-range
};

/**
 * Philippine PhilHealth Contribution (2026)
 * Employee share = 2.5% of monthly basic salary (capped)
 */
const calculatePhilHealthContribution = (monthlySalary: number): number => {
	const rate = 0.025; // 2.5% employee share
	const minSalary = 10000;
	const maxSalary = 100000;
	
	let baseSalary = monthlySalary;
	if (baseSalary < minSalary) baseSalary = minSalary;
	if (baseSalary > maxSalary) baseSalary = maxSalary;
	
	return Math.round(baseSalary * rate * 100) / 100;
};

/**
 * Philippine Pag-IBIG Contribution (2026)
 * Employee share: 1-2% based on monthly compensation
 */
const calculatePagIbigContribution = (monthlySalary: number): number => {
	if (monthlySalary <= 1500) {
		return Math.round(monthlySalary * 0.01 * 100) / 100; // 1%
	} else {
		const contribution = Math.round(monthlySalary * 0.02 * 100) / 100; // 2%
		return Math.min(contribution, 100); // Capped at ₱100
	}
};

/**
 * Philippine Withholding Tax Calculation (2026)
 * Based on graduated tax rates
 */
const calculateWithholdingTax = (taxableIncome: number): number => {
	// Annual tax brackets
	if (taxableIncome <= 250000) return 0;
	if (taxableIncome <= 400000) return (taxableIncome - 250000) * 0.15;
	if (taxableIncome <= 800000) return 22500 + (taxableIncome - 400000) * 0.20;
	if (taxableIncome <= 2000000) return 102500 + (taxableIncome - 800000) * 0.25;
	if (taxableIncome <= 8000000) return 402500 + (taxableIncome - 2000000) * 0.30;
	return 2202500 + (taxableIncome - 8000000) * 0.35;
};

/**
 * Calculate monthly tax from annual taxable income
 */
const calculateMonthlyTax = (monthlyGrossPay: number, monthlyDeductions: number): number => {
	const monthlyTaxableIncome = monthlyGrossPay - monthlyDeductions;
	const annualTaxableIncome = monthlyTaxableIncome * 12;
	const annualTax = calculateWithholdingTax(annualTaxableIncome);
	const monthlyTax = annualTax / 12;
	return Math.round(monthlyTax * 100) / 100;
};

/**
 * Main Payroll Calculation Function
 */
const calculatePayroll = (
	employee: Employee,
	attendanceRecords: AttendanceRecord[],
	payrollPeriod: PayrollPeriod
): PayrollCalculation => {
	// Step 1: Calculate total hours
	const totalRegularHours = attendanceRecords.reduce((sum, record) => sum + record.regularHours, 0);
	const totalOvertimeHours = attendanceRecords.reduce((sum, record) => sum + record.overtimeHours, 0);
	const totalUndertimeHours = attendanceRecords.reduce((sum, record) => sum + record.undertimeHours, 0);
	const totalAbsentDays = attendanceRecords.filter(r => r.status === "absent").length;
	
	// Step 2: Determine pay rate based on employee type
	let hourlyRate = 0;
	let dailyRate = 0;
	let monthlyBase = 0;
	
	if (employee.payType === "monthly" && employee.monthlySalary) {
		monthlyBase = employee.monthlySalary;
		dailyRate = monthlyBase / payrollPeriod.workingDays;
		hourlyRate = dailyRate / 8; // Assuming 8 hours per day
	} else if (employee.payType === "daily" && employee.dailyRate) {
		dailyRate = employee.dailyRate;
		hourlyRate = dailyRate / 8;
		monthlyBase = dailyRate * payrollPeriod.workingDays;
	} else if (employee.payType === "hourly" && employee.hourlyRate) {
		hourlyRate = employee.hourlyRate;
		dailyRate = hourlyRate * 8;
		monthlyBase = dailyRate * payrollPeriod.workingDays;
	}
	
	// Step 3: Calculate earnings
	const basicPay = totalRegularHours * hourlyRate;
	const overtimeRate = hourlyRate * 1.25; // 25% overtime premium
	const overtimePay = totalOvertimeHours * overtimeRate;
	
	// Retail commission structure
	// Note: Sales commission would typically come from actual sales data
	// For now using 0 as placeholder - should be passed from sales records
	const salesCommission = 0; // TODO: Calculate from actual sales data
	const performanceBonus = 0; // TODO: Calculate based on target achievement
	const otherAllowances = employee.otherAllowances || 0;
	
	const totalEarnings = basicPay + overtimePay + salesCommission + performanceBonus + otherAllowances;
	const grossPay = basicPay + overtimePay;
	
	// Step 4: Calculate deductions
	const sssContribution = calculateSSSContribution(monthlyBase);
	const philhealthContribution = calculatePhilHealthContribution(monthlyBase);
	const pagibigContribution = calculatePagIbigContribution(monthlyBase);
	
	const totalStatutoryDeductions = sssContribution + philhealthContribution + pagibigContribution;
	const withholdingTax = calculateMonthlyTax(grossPay, totalStatutoryDeductions);
	
	// Absent deductions (pro-rated)
	const absentDeductions = totalAbsentDays * dailyRate;
	
	// Undertime deductions
	const undertimeDeductions = totalUndertimeHours * hourlyRate;
	
	// Loan deductions
	const loanDeductions = employee.loans?.monthlyDeduction || 0;
	
	const otherDeductions = absentDeductions + undertimeDeductions;
	
	const totalDeductions = 
		withholdingTax +
		sssContribution +
		philhealthContribution +
		pagibigContribution +
		otherDeductions +
		loanDeductions;
	
	// Step 5: Calculate net pay
	const netPay = totalEarnings - totalDeductions;
	
	return {
		totalRegularHours,
		totalOvertimeHours,
		totalUndertimeHours,
		totalAbsentDays,
		basicPay: Math.round(basicPay * 100) / 100,
		overtimePay: Math.round(overtimePay * 100) / 100,
		salesCommission: Math.round(salesCommission * 100) / 100,
		performanceBonus: Math.round(performanceBonus * 100) / 100,
		otherAllowances: Math.round(otherAllowances * 100) / 100,
		totalEarnings: Math.round(totalEarnings * 100) / 100,
		withholdingTax: Math.round(withholdingTax * 100) / 100,
		sssContribution: Math.round(sssContribution * 100) / 100,
		philhealthContribution: Math.round(philhealthContribution * 100) / 100,
		pagibigContribution: Math.round(pagibigContribution * 100) / 100,
		absentDeductions: Math.round(absentDeductions * 100) / 100,
		loanDeductions: Math.round(loanDeductions * 100) / 100,
		otherDeductions: Math.round(otherDeductions * 100) / 100,
		totalDeductions: Math.round(totalDeductions * 100) / 100,
		grossPay: Math.round(grossPay * 100) / 100,
		netPay: Math.round(netPay * 100) / 100,
	};
};

// ==================== Mock Data ====================

const payrollPeriods: PayrollPeriod[] = [
	{
		month: "January 2026",
		startDate: "2026-01-01",
		endDate: "2026-01-31",
		attendanceStatus: "finalized",
		workingDays: 22,
	},
	{
		month: "December 2025",
		startDate: "2025-12-01",
		endDate: "2025-12-31",
		attendanceStatus: "finalized",
		workingDays: 21,
	},
	{
		month: "November 2025",
		startDate: "2025-11-01",
		endDate: "2025-11-30",
		attendanceStatus: "finalized",
		workingDays: 20,
	},
	{
		month: "February 2026",
		startDate: "2026-02-01",
		endDate: "2026-02-28",
		attendanceStatus: "pending",
		workingDays: 20,
	},
];

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
		payType: 'monthly' as PayType, // Default to monthly
		monthlySalary: parseFloat(apiEmployee.salary || apiEmployee.monthly_salary || 0),
		dailyRate: apiEmployee.daily_rate ? parseFloat(apiEmployee.daily_rate) : undefined,
		hourlyRate: apiEmployee.hourly_rate ? parseFloat(apiEmployee.hourly_rate) : undefined,
		salesCommissionRate: apiEmployee.sales_commission_rate ? parseFloat(apiEmployee.sales_commission_rate) : 0,
		performanceBonusRate: apiEmployee.performance_bonus_rate ? parseFloat(apiEmployee.performance_bonus_rate) : 0,
		otherAllowances: apiEmployee.other_allowances ? parseFloat(apiEmployee.other_allowances) : 0,
		loans: undefined,
		lastSlipGenerated: undefined,
		hasSlipForPeriod: false,
	};
};

// ==================== Component ====================

const pageSize = 7;

const formatPHP = (value: number) =>
	value.toLocaleString("en-PH", { style: "currency", currency: "PHP" });

const getInitials = (firstName: string, lastName: string) =>
	(firstName.charAt(0) + lastName.charAt(0)).toUpperCase();

export default function GenerateSlip() {
	const [employeeData, setEmployeeData] = useState<Employee[]>([]);
	const [isLoadingEmployees, setIsLoadingEmployees] = useState(true);
	const [search, setSearch] = useState("");
	const [department, setDepartment] = useState<string>("");
	const [selectedPeriodIndex, setSelectedPeriodIndex] = useState(0);
	const [page, setPage] = useState(1);
	const [selectedEmployee, setSelectedEmployee] = useState<Employee | null>(null);
	const [isGenerating, setIsGenerating] = useState(false);
	const [calculation, setCalculation] = useState<PayrollCalculation | null>(null);
	const [isCalculating, setIsCalculating] = useState(false);
	
	// Manual hours input state
	const [totalRegularHours, setTotalRegularHours] = useState<number>(0);
	const [totalOvertimeHours, setTotalOvertimeHours] = useState<number>(0);
	const [totalUndertimeHours, setTotalUndertimeHours] = useState<number>(0);
	const [totalAbsentDays, setTotalAbsentDays] = useState<number>(0);
	
	// Batch generation states
	const [showValidationModal, setShowValidationModal] = useState(false);
	const [showPreviewModal, setShowPreviewModal] = useState(false);
	const [validationSummary, setValidationSummary] = useState<any>(null);
	const [previewData, setPreviewData] = useState<any>(null);
	const [isLoadingPreview, setIsLoadingPreview] = useState(false);
	const [generationProgress, setGenerationProgress] = useState({ current: 0, total: 0 });
	const [retryQueue, setRetryQueue] = useState<number[]>([]);

	const selectedPeriod = payrollPeriods[selectedPeriodIndex];

	// Fetch employees from API
	useEffect(() => {
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
				console.log('Employees API response:', data);

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
	}, []);

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
			const pendingOnly = !e.hasSlipForPeriod;
			return matchesSearch && matchesDepartment && matchesStatus && pendingOnly;
		});
	}, [search, department, employeeData]);

	const paginated = useMemo(() => {
		const start = (page - 1) * pageSize;
		return filtered.slice(start, start + pageSize);
	}, [filtered, page]);

	const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));

	const resetPage = () => setPage(1);

	const handleSearch = (value: string) => {
		setSearch(value);
		resetPage();
	};

	const handleDepartment = (value: string) => {
		setDepartment(value);
		resetPage();
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
		setTotalUndertimeHours(0);
		setTotalAbsentDays(0);
		
		setSelectedEmployee(employee);
		setCalculation(null);
		
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
			
			const response = await fetch(`/api/hr/attendance/employee/${employee.id}?${params.toString()}`, {
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
				// Use default values
				const expectedRegularHours = selectedPeriod.workingDays * 8;
				setTotalRegularHours(expectedRegularHours);
				return;
			}

			const data = await response.json();
			console.log('Attendance data:', data);
			
			// Auto-populate hours from attendance summary
			if (data.summary) {
				setTotalRegularHours(data.summary.total_regular_hours || 0);
				setTotalOvertimeHours(data.summary.total_overtime_hours || 0);
				setTotalUndertimeHours(data.summary.total_undertime_hours || 0);
				setTotalAbsentDays(data.summary.total_absent || 0);
				
				// Auto-calculate after fetching attendance
				setTimeout(() => {
					handleCalculate();
				}, 100);
			}
		} catch (error) {
			console.error('Error fetching attendance:', error);
			// Use default values
			const expectedRegularHours = selectedPeriod.workingDays * 8;
			setTotalRegularHours(expectedRegularHours);
		}
	};

	const closeEmployee = () => {
		setSelectedEmployee(null);
		setCalculation(null);
		setTotalRegularHours(0);
		setTotalOvertimeHours(0);
		setTotalUndertimeHours(0);
		setTotalAbsentDays(0);
	};
	
	// Generate all pending payslips with validation and preview
	const handleGenerateAll = async () => {
		const pendingEmployees = employeeData.filter(e => e.status === "active" && !e.hasSlipForPeriod);
		
		if (pendingEmployees.length === 0) {
			await Swal.fire({
				icon: "info",
				title: "No Pending Payslips",
				text: "All active employees already have payslips for this period.",
				confirmButtonColor: "#3b82f6",
			});
			return;
		}

		// Step 1: Show validation summary
		setIsLoadingPreview(true);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch('/api/hr/payroll/batch/preview', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: selectedPeriod.month,
					employeeIds: pendingEmployees.map(e => e.id),
				}),
			});

			if (!response.ok) {
				throw new Error('Failed to generate preview');
			}

			const previewResult = await response.json();
			setPreviewData(previewResult);
			setIsLoadingPreview(false);

			// Show validation summary
			const hasErrors = previewResult.errors.length > 0;
			const hasWarnings = previewResult.warnings.length > 0;

			if (hasErrors || hasWarnings) {
				const result = await Swal.fire({
					icon: hasErrors ? "error" : "warning",
					title: "Validation Issues Found",
					html: `
						<div class="text-left">
							<p class="mb-2 font-semibold">Review the following issues before proceeding:</p>
							${hasErrors ? `
								<div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-3">
									<p class="text-sm font-semibold text-red-800 mb-2">⚠️ Errors (${previewResult.errors.length}):</p>
									<ul class="list-disc list-inside text-sm text-red-700 space-y-1">
										${previewResult.errors.map((err: any) => `
											<li>${err.employee_name || `Employee ${err.employee_id}`}: ${err.message}</li>
										`).join('')}
									</ul>
								</div>
							` : ''}
							${hasWarnings ? `
								<div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
									<p class="text-sm font-semibold text-amber-800 mb-2">⚠️ Warnings (${previewResult.warnings.length}):</p>
									<ul class="list-disc list-inside text-sm text-amber-700 space-y-1">
										${previewResult.warnings.map((warn: any) => `
											<li>${warn.employee_name}: ${warn.message}</li>
										`).join('')}
									</ul>
								</div>
							` : ''}
							<div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
								<p class="text-sm text-blue-800">
									<strong>${previewResult.previews.length}</strong> payslips can be generated successfully
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
			setShowPreviewModal(true);

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
		if (!previewData) return;

		const result = await Swal.fire({
			icon: "question",
			title: "Generate All Payslips?",
			html: `
				<div class="text-left space-y-3">
					<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
						<h4 class="font-semibold text-blue-900 mb-2">📊 Summary</h4>
						<ul class="text-sm text-blue-800 space-y-1">
							<li><strong>${previewData.summary.preview_count}</strong> employees</li>
							<li><strong>Period:</strong> ${selectedPeriod.month}</li>
							<li><strong>Total Gross:</strong> ${formatPHP(previewData.summary.total_gross)}</li>
							<li><strong>Total Net:</strong> ${formatPHP(previewData.summary.total_net)}</li>
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
			setShowPreviewModal(false);
			return;
		}

		// Generate batch with backend
		setIsGenerating(true);
		setGenerationProgress({ current: 0, total: previewData.previews.length });
		setShowPreviewModal(false);

		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch('/api/hr/payroll/batch/generate', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: selectedPeriod.month,
					employeeIds: previewData.previews.map((p: any) => p.employee_id),
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
							lastSlipGenerated: new Date().toLocaleDateString('en-US', { 
								year: 'numeric', 
								month: 'short', 
								day: 'numeric' 
							})
						}
						: e
				)
			);
			
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

	// Export batch to CSV
	const handleExportBatch = async () => {
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			
			const response = await fetch('/api/hr/payroll/batch/export', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'text/csv',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: selectedPeriod.month,
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
			
			const response = await fetch('/api/hr/payroll/batch/retry', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': csrfToken || '',
					'Accept': 'application/json',
				},
				credentials: 'include',
				body: JSON.stringify({
					payrollPeriod: selectedPeriod.month,
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
	
	// Auto-calculate when hours change
	const handleCalculate = () => {
		if (!selectedEmployee) return;
		
		// Create attendance records from manual input
		const attendanceRecords: AttendanceRecord[] = [];
		const daysPresent = selectedPeriod.workingDays - totalAbsentDays;
		
		// Generate records based on manual input
		for (let i = 0; i < selectedPeriod.workingDays; i++) {
			attendanceRecords.push({
				date: `${selectedPeriod.startDate.substring(0, 8)}${String(i + 1).padStart(2, '0')}`,
				status: i < totalAbsentDays ? "absent" : "present",
				regularHours: i < totalAbsentDays ? 0 : totalRegularHours / daysPresent,
				overtimeHours: i < totalAbsentDays ? 0 : totalOvertimeHours / daysPresent,
				undertimeHours: i < totalAbsentDays ? 0 : totalUndertimeHours / daysPresent,
			});
		}
		
		const calc = calculatePayroll(selectedEmployee, attendanceRecords, selectedPeriod);
		setCalculation(calc);
	};

	const handleGenerateSlip = async () => {
		if (!selectedEmployee || !calculation) return;

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
						<li><strong>Net Pay:</strong> ${formatPHP(calculation.netPay)}</li>
					</ul>
					<p class="text-sm text-gray-600">This action will lock the payslip data and cannot be undone without proper authorization.</p>
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
			
			// Prepare payroll data for API (using camelCase as expected by controller)
			const payrollData = {
				employee_id: selectedEmployee.id,
				payrollPeriod: selectedPeriod.month,
				baseSalary: selectedEmployee.monthlySalary || 0,
				salesCommission: calculation.salesCommission,
				performanceBonus: calculation.performanceBonus,
				otherAllowances: calculation.otherAllowances,
				deductions: calculation.totalDeductions,
				paymentMethod: 'bank_transfer',
				notes: `Generated payslip for period ${selectedPeriod.month}. Regular hours: ${calculation.totalRegularHours}, Overtime: ${calculation.totalOvertimeHours}hrs, Absent: ${calculation.totalAbsentDays} days`,
			};

			console.log('Sending payroll data:', payrollData);

			const response = await fetch('/api/hr/payroll', {
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
				console.error('API Error Response:', errorData);
				console.error('Response Status:', response.status);
				throw new Error(errorData.message || errorData.error || `Failed to generate payslip (Status: ${response.status})`);
			}

			const data = await response.json();
			console.log('Payroll generated:', data);

			// Update employee data to mark slip as generated
			setEmployeeData(prevData => 
				prevData.map(e => 
					e.id === selectedEmployee.id 
						? { 
							...e, 
							hasSlipForPeriod: true,
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
							<li><strong>Gross Pay:</strong> ${formatPHP(calculation.grossPay)}</li>
							<li><strong>Net Pay:</strong> ${formatPHP(calculation.netPay)}</li>
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

	const startIndex = (page - 1) * pageSize;
	const endIndex = Math.min(startIndex + pageSize, filtered.length);

	const completedSlips = employeeData.filter(e => e.hasSlipForPeriod).length;
	const pendingSlips = employeeData.filter(e => e.status === "active" && !e.hasSlipForPeriod).length;
	const failedSlips = 0;

	return (
		<div className="space-y-6">
			<div className="flex flex-col gap-2">
				<h1 className="text-2xl font-semibold text-gray-900 dark:text-white">Generate Payslip</h1>
				<p className="text-gray-600 dark:text-gray-400">Calculate and generate employee payslips with automatic deductions.</p>
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
			<div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
				<div className="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
					<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Active Employees</h3>
					<div className="flex items-center gap-3">
						<button
							onClick={handleGenerateAll}
							disabled={pendingSlips === 0 || isGenerating}
							className="px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center gap-2"
							title={pendingSlips === 0 ? "No pending payslips" : `Generate ${pendingSlips} payslips`}
						>
							<CalculatorIcon className="size-4" />
							Generate All ({pendingSlips})
						</button>
						<div>
							<label className="text-sm text-gray-600 dark:text-gray-300">Payroll Period: </label>
							<select
								value={selectedPeriodIndex}
								onChange={(e) => setSelectedPeriodIndex(Number(e.target.value))}
								aria-label="Select payroll period"
								className="ml-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-1 text-sm text-gray-900 dark:text-white inline-block"
							>
								{payrollPeriods.map((period, index) => (
									<option key={index} value={index}>
										{period.month} {period.attendanceStatus === "finalized" ? "✓" : "⏳"}
									</option>
								))}
							</select>
						</div>
					</div>
				</div>
				<div className="overflow-x-auto">
					<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-800 text-sm">
						<thead className="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
							<tr>
								<th className="px-6 py-3 text-left">Employee</th>
								<th className="px-6 py-3 text-left">Position</th>
								<th className="px-6 py-3 text-left">Pay Type</th>
								<th className="px-6 py-3 text-left">Base Salary</th>
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
								<tr key={employee.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
									<td className="px-6 py-4">
										<div className="flex items-center gap-3">
											<div className="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
												<span className="text-blue-600 dark:text-blue-300 font-medium text-sm">{getInitials(employee.firstName, employee.lastName)}</span>
											</div>
											<div className="flex flex-col">
												<span className="font-semibold text-gray-900 dark:text-white">{employee.firstName} {employee.lastName}</span>
												<span className="text-xs text-gray-500 dark:text-gray-400">{employee.employeeId} · {employee.department}</span>
											</div>
										</div>
									</td>
									<td className="px-6 py-4 text-gray-700 dark:text-gray-300">{employee.position}</td>
									<td className="px-6 py-4">
										<span className="inline-flex items-center px-2 py-1 rounded-md bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 text-xs font-medium capitalize">
											{employee.payType}
										</span>
									</td>
									<td className="px-6 py-4 text-gray-900 dark:text-white font-semibold">
										{employee.monthlySalary ? formatPHP(employee.monthlySalary) : 
										 employee.dailyRate ? formatPHP(employee.dailyRate) + "/day" :
										 employee.hourlyRate ? formatPHP(employee.hourlyRate) + "/hr" : "N/A"}
									</td>
									<td className="px-6 py-4">
										{employee.hasSlipForPeriod ? (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-green-100 dark:bg-green-900/20 text-green-700 dark:text-green-300 text-xs font-semibold">
												Generated
											</span>
										) : (
											<span className="inline-flex items-center px-2 py-1 rounded-full bg-amber-100 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 text-xs font-semibold">
												Pending
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

			{/* Generation Modal - Continued in next part due to length */}
			{selectedEmployee && createPortal(
				<div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
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
									<div className="h-12 w-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
										<span className="text-blue-600 dark:text-blue-300 font-medium">{getInitials(selectedEmployee.firstName, selectedEmployee.lastName)}</span>
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
									<div className="flex justify-between py-1">
										<span className="text-gray-600 dark:text-gray-400">Pay Type:</span>
										<span className="font-medium capitalize">{selectedEmployee.payType}</span>
									</div>
								</div>
							</div>

							<div className="p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
								<p className="text-sm text-gray-600 dark:text-gray-400 mb-2">Pay Configuration</p>
								<div className="space-y-2 text-sm">
									<div className="flex justify-between">
										<span className="text-gray-600 dark:text-gray-400">Base Salary:</span>
										<span className="font-semibold text-gray-900 dark:text-white">
											{selectedEmployee.monthlySalary ? formatPHP(selectedEmployee.monthlySalary) + "/mo" : 
											 selectedEmployee.dailyRate ? formatPHP(selectedEmployee.dailyRate) + "/day" :
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
										✓ Auto-calculated from attendance records ({selectedPeriod.startDate} to {selectedPeriod.endDate})
									</p>
								</div>
								<button
									onClick={handleCalculate}
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
									<p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Expected: {selectedPeriod.workingDays * 8}h</p>
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
									<p className="text-xs text-red-500 dark:text-red-400 mt-1">Max: {selectedPeriod.workingDays}</p>
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
						) : calculation ? (
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
											<span className="text-lg font-bold text-gray-900 dark:text-white">{Math.round(calculation.totalRegularHours * 100) / 100}h</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Overtime Hours</span>
											<span className="text-lg font-bold text-green-600 dark:text-green-400">{Math.round(calculation.totalOvertimeHours * 100) / 100}h</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Undertime</span>
											<span className="text-lg font-bold text-amber-600 dark:text-amber-400">{Math.round(calculation.totalUndertimeHours * 100) / 100}h</span>
										</div>
										<div className="p-3 rounded-lg border border-gray-200 dark:border-gray-700">
											<span className="text-gray-600 dark:text-gray-400 block mb-1 text-xs">Absent Days</span>
											<span className="text-lg font-bold text-red-600 dark:text-red-400">{calculation.totalAbsentDays}</span>
										</div>
									</div>
								</div>

								{/* Earnings Breakdown */}
								<div className="rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5">
									<h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 uppercase tracking-wide">Earnings</h4>
									<div className="space-y-2.5 text-sm">
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Basic Pay</span>
											<span className="text-gray-900 dark:text-white font-medium">{formatPHP(calculation.basicPay)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Overtime Pay</span>
											<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(calculation.overtimePay)}</span>
										</div>
										{calculation.salesCommission > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Sales Commission</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(calculation.salesCommission)}</span>
											</div>
										)}
										{calculation.performanceBonus > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Performance Bonus</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(calculation.performanceBonus)}</span>
											</div>
										)}
										{calculation.otherAllowances > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Other Allowances</span>
												<span className="text-green-600 dark:text-green-400 font-medium">+{formatPHP(calculation.otherAllowances)}</span>
											</div>
										)}
										<div className="border-t border-gray-200 dark:border-gray-700 pt-2.5 mt-2.5">
											<div className="flex items-center justify-between">
												<span className="text-sm font-semibold text-gray-900 dark:text-white">Total Earnings</span>
												<span className="text-base font-bold text-green-600 dark:text-green-400">{formatPHP(calculation.totalEarnings)}</span>
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
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.withholdingTax)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">SSS Contribution</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.sssContribution)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">PhilHealth Contribution</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.philhealthContribution)}</span>
										</div>
										<div className="flex items-center justify-between">
											<span className="text-gray-600 dark:text-gray-400">Pag-IBIG Contribution</span>
											<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.pagibigContribution)}</span>
										</div>
										{calculation.absentDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Absent Deductions</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.absentDeductions)}</span>
											</div>
										)}
										{calculation.loanDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Loan Payment</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.loanDeductions)}</span>
											</div>
										)}
										{calculation.otherDeductions > 0 && (
											<div className="flex items-center justify-between">
												<span className="text-gray-600 dark:text-gray-400">Other Deductions</span>
												<span className="text-gray-900 dark:text-white font-medium">-{formatPHP(calculation.otherDeductions)}</span>
											</div>
										)}
										<div className="border-t border-gray-200 dark:border-gray-700 pt-2.5 mt-2.5">
											<div className="flex items-center justify-between">
												<span className="text-sm font-semibold text-gray-900 dark:text-white">Total Deductions</span>
												<span className="text-base font-bold text-red-600 dark:text-red-400">-{formatPHP(calculation.totalDeductions)}</span>
											</div>
										</div>
									</div>
								</div>

								{/* Net Pay Summary */}
								<div className="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-6">
									<div className="flex items-center justify-between">
										<div>
											<p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Net Pay</p>
											<p className="text-3xl font-extrabold text-green-600 dark:text-green-400">{formatPHP(calculation.netPay)}</p>
										</div>
										<CheckCircleIcon className="size-16 text-green-500 dark:text-green-400 opacity-50" />
									</div>
									<div className="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-4 text-sm">
										<div>
											<span className="text-gray-600 dark:text-gray-400 block">Gross Pay</span>
											<span className="font-semibold text-gray-900 dark:text-white">{formatPHP(calculation.grossPay)}</span>
										</div>
										<div>
											<span className="text-gray-600 dark:text-gray-400 block">Total Deductions</span>
											<span className="font-semibold text-red-600 dark:text-red-400">-{formatPHP(calculation.totalDeductions)}</span>
										</div>
									</div>
								</div>

								{/* Warning Message */}
								<div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-900/30 rounded-lg p-4">
									<div className="flex gap-3">
										<AlertIcon className="size-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
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
								<p className="text-lg font-medium">Enter hours and click Calculate</p>
								<p className="text-sm">Input the employee's hours worked above to see the payroll calculation</p>
							</div>
						)}

						{/* Action Buttons */}
						<div className="mt-6 flex justify-end gap-3">
							<button
								onClick={closeEmployee}
								disabled={isGenerating}
								className="px-5 py-2.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleGenerateSlip}
								disabled={isGenerating || isCalculating || !calculation}
								className={`px-5 py-2.5 rounded-lg text-white text-sm font-medium transition-all flex items-center gap-2 ${
									isGenerating || isCalculating || !calculation
										? "bg-blue-400 cursor-not-allowed"
										: "bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30"
								}`}
							>
								{isGenerating ? (
									<>
										<svg className="animate-spin size-4" fill="none" viewBox="0 0 24 24">
											<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
											<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
										</svg>
										Generating Payslip...
									</>
								) : (
									<>
										<CheckIcon className="size-4" />
										Generate & Lock Payslip
									</>
								)}
							</button>
						</div>
					</div>
				</div>,
				document.body
			)}

			{/* Preview Modal for Batch Generation */}
			{showPreviewModal && previewData && createPortal(
				<div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
					<div className="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-6xl w-full max-h-[90vh] overflow-y-auto p-8">
						<div className="flex items-start justify-between mb-6">
							<div>
								<h3 className="text-2xl font-bold text-gray-900 dark:text-white">Preview Batch Payroll</h3>
								<p className="text-gray-500 dark:text-gray-400 text-sm">{selectedPeriod.month} - {previewData.previews.length} Employees</p>
							</div>
							<button
								onClick={() => setShowPreviewModal(false)}
								className="text-2xl text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
							>
								×
							</button>
						</div>

						{/* Summary Cards */}
						<div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
							<div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
								<p className="text-sm text-blue-600 dark:text-blue-400 mb-1">Total Employees</p>
								<p className="text-2xl font-bold text-blue-900 dark:text-blue-300">{previewData.summary.preview_count}</p>
							</div>
							<div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
								<p className="text-sm text-green-600 dark:text-green-400 mb-1">Total Gross</p>
								<p className="text-2xl font-bold text-green-900 dark:text-green-300">{formatPHP(previewData.summary.total_gross)}</p>
							</div>
							<div className="bg-red-50 dark:bg-red-900/20 rounded-lg p-4">
								<p className="text-sm text-red-600 dark:text-red-400 mb-1">Total Deductions</p>
								<p className="text-2xl font-bold text-red-900 dark:text-red-300">{formatPHP(previewData.summary.total_gross - previewData.summary.total_net)}</p>
							</div>
							<div className="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4">
								<p className="text-sm text-purple-600 dark:text-purple-400 mb-1">Total Net</p>
								<p className="text-2xl font-bold text-purple-900 dark:text-purple-300">{formatPHP(previewData.summary.total_net)}</p>
							</div>
						</div>

						{/* Employee Preview Table */}
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
									{previewData.previews.map((preview: any) => (
										<tr key={preview.employee_id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
											<td className="px-4 py-3">
												<div className="flex flex-col">
													<span className="font-medium text-gray-900 dark:text-white">{preview.employee_name}</span>
													<span className="text-xs text-gray-500 dark:text-gray-400">{preview.position}</span>
												</div>
											</td>
											<td className="px-4 py-3 text-gray-700 dark:text-gray-300">{preview.department}</td>
											<td className="px-4 py-3 text-right">
												<div className="text-xs space-y-1">
													<div className="text-gray-600 dark:text-gray-400">
														R: {preview.attendance.total_regular_hours}h
													</div>
													{preview.attendance.total_overtime_hours > 0 && (
														<div className="text-green-600 dark:text-green-400">
															OT: {preview.attendance.total_overtime_hours}h
														</div>
													)}
													{preview.attendance.total_absent_days > 0 && (
														<div className="text-red-600 dark:text-red-400">
															Absent: {preview.attendance.total_absent_days}d
														</div>
													)}
												</div>
											</td>
											<td className="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">
												{formatPHP(preview.calculation.gross_salary)}
											</td>
											<td className="px-4 py-3 text-right text-red-600 dark:text-red-400">
												{formatPHP(preview.calculation.total_deductions)}
											</td>
											<td className="px-4 py-3 text-right font-bold text-green-600 dark:text-green-400">
												{formatPHP(preview.calculation.net_salary)}
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>

						{/* Action Buttons */}
						<div className="flex justify-end gap-3">
							<button
								onClick={() => setShowPreviewModal(false)}
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
				<div className="fixed inset-0 z-[999999] bg-black/80 backdrop-blur-sm flex items-center justify-center">
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
							<div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
								<div 
									className="bg-blue-600 h-full transition-all duration-300 rounded-full"
									style={{ width: `${(generationProgress.current / generationProgress.total) * 100}%` }}
								/>
							</div>
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