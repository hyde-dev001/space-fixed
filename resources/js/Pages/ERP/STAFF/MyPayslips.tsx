import React, { useState, useEffect, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';

// ─────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────

interface PayrollComponent {
	id: number;
	component_type: 'earning' | 'deduction' | 'benefit';
	component_name: string;
	calculated_amount: string;
	description?: string;
	is_taxable?: boolean;
}

interface Payslip {
	id: number;
	pay_period_start: string;
	pay_period_end: string;
	payroll_period?: string;
	basic_salary: string;
	gross_salary: string;
	total_deductions: string;
	tax_amount: string;
	sss_contributions: string;
	philhealth: string;
	pag_ibig: string;
	overtime_pay: string;
	bonus: string;
	net_salary: string;
	status: 'pending' | 'processed' | 'paid';
	approval_status: 'pending' | 'approved' | 'rejected';
	payment_date?: string;
	payment_method?: string;
	attendance_days?: number;
	overtime_hours?: string;
	components: PayrollComponent[];
}

interface Paginated<T> {
	data: T[];
	current_page: number;
	last_page: number;
	per_page: number;
	total: number;
}

// ─────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────

const formatPHP = (value: string | number): string =>
	Number(value).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });

const formatDate = (dateStr: string): string => {
	if (!dateStr) return '—';
	return new Date(dateStr).toLocaleDateString('en-PH', {
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	});
};

const periodLabel = (slip: Payslip): string => {
	if (slip.payroll_period) return slip.payroll_period;
	const start = new Date(slip.pay_period_start);
	return start.toLocaleDateString('en-PH', { year: 'numeric', month: 'long' });
};

// ─────────────────────────────────────────────────────────
// Icons
// ─────────────────────────────────────────────────────────

const DocumentIcon = () => (
	<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
	</svg>
);

const PrintIcon = () => (
	<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a1 1 0 001-1v-4a1 1 0 00-1-1H9a1 1 0 00-1 1v4a1 1 0 001 1zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
	</svg>
);

const ChevronDownIcon = () => (
	<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
	</svg>
);

const ChevronUpIcon = () => (
	<svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M5 15l7-7 7 7" />
	</svg>
);

// ─────────────────────────────────────────────────────────
// Print styles (injected only during print)
// ─────────────────────────────────────────────────────────

const PRINT_STYLE = `
@media print {
	body > *:not(#payslip-print-root) { display: none !important; }
	#payslip-print-root { display: block !important; }
	.no-print { display: none !important; }
	@page { margin: 20mm; }
}
`;

// ─────────────────────────────────────────────────────────
// PayslipDetail — full breakdown for one payslip
// ─────────────────────────────────────────────────────────

interface PayslipDetailProps {
	slip: Payslip;
	employeeName: string;
	shopName: string;
}

const PayslipDetail: React.FC<PayslipDetailProps> = ({ slip, employeeName, shopName }) => {
	const printRef = useRef<HTMLDivElement>(null);

	const handlePrint = () => {
		const content = printRef.current?.innerHTML ?? '';
		const win = window.open('', '_blank', 'width=800,height=600');
		if (!win) return;
		win.document.write(`
			<html><head><title>Payslip — ${periodLabel(slip)}</title>
			<style>
				body { font-family: Arial, sans-serif; font-size: 13px; color: #111; }
				table { width: 100%; border-collapse: collapse; margin-top: 8px; }
				td, th { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; }
				th { background: #f3f4f6; font-weight: 600; }
				.text-right { text-align: right; }
				.total-row td { font-weight: 700; border-top: 2px solid #111; }
				h1 { font-size: 20px; margin: 0 0 4px; }
				h2 { font-size: 15px; margin: 16px 0 4px; color: #374151; }
				.header { display: flex; justify-content: space-between; margin-bottom: 16px; }
				.badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
					background: ${slip.status === 'paid' ? '#d1fae5' : '#dbeafe'}; color: ${slip.status === 'paid' ? '#065f46' : '#1e40af'}; }
			</style></head><body>${content}</body></html>
		`);
		win.document.close();
		win.focus();
		win.print();
		win.close();
	};

	const earnings = slip.components.filter(c => c.component_type === 'earning');
	const deductions = slip.components.filter(c => c.component_type === 'deduction');

	// Build statutory deductions that aren't already in components
	const statutoryRows: { label: string; amount: number }[] = [];
	const componentNames = slip.components.map(c => c.component_name.toLowerCase());
	if (Number(slip.sss_contributions) > 0 && !componentNames.some(n => n.includes('sss')))
		statutoryRows.push({ label: 'SSS Contribution', amount: Number(slip.sss_contributions) });
	if (Number(slip.philhealth) > 0 && !componentNames.some(n => n.includes('philhealth')))
		statutoryRows.push({ label: 'PhilHealth Contribution', amount: Number(slip.philhealth) });
	if (Number(slip.pag_ibig) > 0 && !componentNames.some(n => n.includes('pag') || n.includes('pagibig')))
		statutoryRows.push({ label: 'Pag-IBIG Contribution', amount: Number(slip.pag_ibig) });
	if (Number(slip.tax_amount) > 0 && !componentNames.some(n => n.includes('tax') || n.includes('withholding')))
		statutoryRows.push({ label: 'Withholding Tax (BIR)', amount: Number(slip.tax_amount) });

	return (
		<div>
			{/* Print button */}
			<div className="flex justify-end mb-4 no-print">
				<button
					onClick={handlePrint}
					className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
				>
					<PrintIcon />
					Print Payslip
				</button>
			</div>

			{/* Printable area */}
			<div ref={printRef} className="bg-white dark:bg-gray-900 rounded-xl p-6 border border-gray-200 dark:border-gray-700">
				{/* Header */}
				<div className="flex items-start justify-between border-b border-gray-200 dark:border-gray-700 pb-5 mb-5">
					<div>
						<h2 className="text-xl font-bold text-gray-900 dark:text-white">{shopName}</h2>
						<p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Official Payslip</p>
					</div>
					<span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ${
						slip.status === 'paid'
							? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
						: slip.approval_status === 'approved'
							? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
							: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
				}`}>
					{slip.status === 'paid' ? 'PAID' : slip.approval_status === 'approved' ? 'APPROVED' : 'PENDING APPROVAL'}
					</span>
				</div>

				{/* Meta */}
				<div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm mb-6">
					<div>
						<span className="text-gray-500 dark:text-gray-400">Employee</span>
						<p className="font-semibold text-gray-900 dark:text-white">{employeeName}</p>
					</div>
					<div>
						<span className="text-gray-500 dark:text-gray-400">Pay Period</span>
						<p className="font-semibold text-gray-900 dark:text-white">
							{formatDate(slip.pay_period_start)} — {formatDate(slip.pay_period_end)}
						</p>
					</div>
					{slip.attendance_days != null && (
						<div>
							<span className="text-gray-500 dark:text-gray-400">Days Present</span>
							<p className="font-semibold text-gray-900 dark:text-white">{slip.attendance_days} days</p>
						</div>
					)}
					{slip.payment_date && (
						<div>
							<span className="text-gray-500 dark:text-gray-400">Payment Date</span>
							<p className="font-semibold text-gray-900 dark:text-white">{formatDate(slip.payment_date)}</p>
						</div>
					)}
					{slip.payment_method && (
						<div>
							<span className="text-gray-500 dark:text-gray-400">Payment Method</span>
							<p className="font-semibold text-gray-900 dark:text-white capitalize">{slip.payment_method.replace('_', ' ')}</p>
						</div>
					)}
				</div>

				{/* Earnings table */}
				<div className="mb-5">
					<h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">Earnings</h3>
					<table className="w-full text-sm">
						<thead>
							<tr className="border-b border-gray-200 dark:border-gray-700">
								<th className="text-left py-2 text-gray-500 dark:text-gray-400 font-medium">Description</th>
								<th className="text-right py-2 text-gray-500 dark:text-gray-400 font-medium">Amount</th>
							</tr>
						</thead>
						<tbody>
							{earnings.length > 0 ? earnings.map(e => (
								<tr key={e.id} className="border-b border-gray-100 dark:border-gray-800">
									<td className="py-2 text-gray-700 dark:text-gray-300">{e.component_name}</td>
									<td className="py-2 text-right text-gray-900 dark:text-white">{formatPHP(e.calculated_amount)}</td>
								</tr>
							)) : (
								<tr className="border-b border-gray-100 dark:border-gray-800">
									<td className="py-2 text-gray-700 dark:text-gray-300">Basic Salary</td>
									<td className="py-2 text-right text-gray-900 dark:text-white">{formatPHP(slip.basic_salary)}</td>
								</tr>
							)}
							{Number(slip.overtime_pay) > 0 && !earnings.some(e => e.component_name.toLowerCase().includes('overtime')) && (
								<tr className="border-b border-gray-100 dark:border-gray-800">
									<td className="py-2 text-gray-700 dark:text-gray-300">Overtime Pay</td>
									<td className="py-2 text-right text-gray-900 dark:text-white">{formatPHP(slip.overtime_pay)}</td>
								</tr>
							)}
						</tbody>
						<tfoot>
							<tr>
								<td className="pt-3 font-bold text-gray-900 dark:text-white">Gross Pay</td>
								<td className="pt-3 text-right font-bold text-gray-900 dark:text-white">{formatPHP(slip.gross_salary)}</td>
							</tr>
						</tfoot>
					</table>
				</div>

				{/* Deductions table */}
				<div className="mb-6">
					<h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-2">Deductions</h3>
					<table className="w-full text-sm">
						<thead>
							<tr className="border-b border-gray-200 dark:border-gray-700">
								<th className="text-left py-2 text-gray-500 dark:text-gray-400 font-medium">Description</th>
								<th className="text-right py-2 text-gray-500 dark:text-gray-400 font-medium">Amount</th>
							</tr>
						</thead>
						<tbody>
							{deductions.map(d => (
								<tr key={d.id} className="border-b border-gray-100 dark:border-gray-800">
									<td className="py-2 text-gray-700 dark:text-gray-300">{d.component_name}</td>
									<td className="py-2 text-right text-red-600 dark:text-red-400">−{formatPHP(d.calculated_amount)}</td>
								</tr>
							))}
							{statutoryRows.map(row => (
								<tr key={row.label} className="border-b border-gray-100 dark:border-gray-800">
									<td className="py-2 text-gray-700 dark:text-gray-300">{row.label}</td>
									<td className="py-2 text-right text-red-600 dark:text-red-400">−{formatPHP(row.amount)}</td>
								</tr>
							))}
						</tbody>
						<tfoot>
							<tr>
								<td className="pt-3 font-bold text-gray-900 dark:text-white">Total Deductions</td>
								<td className="pt-3 text-right font-bold text-red-600 dark:text-red-400">−{formatPHP(slip.total_deductions)}</td>
							</tr>
						</tfoot>
					</table>
				</div>

				{/* Net pay */}
				<div className="flex items-center justify-between bg-gray-50 dark:bg-gray-800 rounded-lg px-5 py-4 border border-gray-200 dark:border-gray-700">
					<span className="text-base font-bold text-gray-900 dark:text-white">NET PAY</span>
					<span className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{formatPHP(slip.net_salary)}</span>
				</div>

				{/* Footer note */}
				<p className="text-xs text-gray-400 dark:text-gray-500 text-center mt-4">
					This is an official payslip issued by {shopName}. Please keep for your records.
				</p>
			</div>
		</div>
	);
};

// ─────────────────────────────────────────────────────────
// Main page
// ─────────────────────────────────────────────────────────

export default function MyPayslips() {
	const page = usePage();
	const auth = (page.props.auth as any);
	const user = auth?.user as any;
	const shopName: string = auth?.shopName ?? auth?.shop_name ?? user?.shop_name ?? 'Your Employer';
	const employeeName: string = user
		? `${user.first_name ?? ''} ${user.last_name ?? ''}`.trim() || user.name || 'Employee'
		: 'Employee';

	const [payslips, setPayslips] = useState<Payslip[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [expandedId, setExpandedId] = useState<number | null>(null);
	const [currentPage, setCurrentPage] = useState(1);
	const [lastPage, setLastPage] = useState(1);
	const [total, setTotal] = useState(0);
	const [searchPeriod, setSearchPeriod] = useState('');

	const fetchPayslips = async (pg = 1, period = '') => {
		setIsLoading(true);
		try {
			const params = new URLSearchParams({ page: String(pg), per_page: '10' });
			if (period) params.set('period', period);
			const res = await fetch(`/api/staff/payslips/my?${params}`, { credentials: 'include' });
			if (!res.ok) throw new Error('Failed to fetch payslips');
			const json: Paginated<Payslip> = await res.json();
			setPayslips(json.data);
			setCurrentPage(json.current_page);
			setLastPage(json.last_page);
			setTotal(json.total);
		} catch {
			setPayslips([]);
		} finally {
			setIsLoading(false);
		}
	};

	useEffect(() => { fetchPayslips(1, searchPeriod); }, []);

	const handleSearch = (e: React.FormEvent) => {
		e.preventDefault();
		setCurrentPage(1);
		fetchPayslips(1, searchPeriod);
	};

	const toggleExpand = (id: number) => {
		setExpandedId(prev => (prev === id ? null : id));
	};

	return (
		<AppLayoutERP>
			<Head title="My Payslips" />

			<div className="min-h-screen bg-gray-50 dark:bg-gray-950 px-4 py-8">
				<div className="max-w-3xl mx-auto">

					{/* Page header */}
					<div className="mb-6">
						<div className="flex items-center gap-3 mb-1">
							<div className="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg text-blue-600 dark:text-blue-400">
								<DocumentIcon />
							</div>
							<h1 className="text-2xl font-bold text-gray-900 dark:text-white">My Payslips</h1>
						</div>
						<p className="text-sm text-gray-500 dark:text-gray-400 ml-11">
							View and print your salary payslips.
						</p>
					</div>

					{/* Search */}
					<form onSubmit={handleSearch} className="flex gap-2 mb-6">
						<input
							type="text"
							value={searchPeriod}
							onChange={e => setSearchPeriod(e.target.value)}
							placeholder="Search by period (e.g. February 2026)…"
							className="flex-1 px-4 py-2.5 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
						/>
						<button
							type="submit"
							className="px-4 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
						>
							Search
						</button>
					</form>

					{/* Loading state */}
					{isLoading && (
						<div className="flex justify-center items-center py-20">
							<div className="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" />
						</div>
					)}

					{/* Empty state */}
					{!isLoading && payslips.length === 0 && (
						<div className="text-center py-20">
							<div className="inline-flex items-center justify-center w-14 h-14 bg-gray-100 dark:bg-gray-800 rounded-full mb-4">
								<DocumentIcon />
							</div>
						<p className="text-gray-600 dark:text-gray-400 font-medium">No payslips yet</p>
						<p className="text-sm text-gray-400 dark:text-gray-500 mt-1">
							Your payslips will appear here once HR generates them.
							</p>
						</div>
					)}

					{/* Payslip list */}
					{!isLoading && payslips.length > 0 && (
						<div className="space-y-3">
							{payslips.map(slip => (
								<div
									key={slip.id}
									className="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm"
								>
									{/* Row header */}
									<button
										onClick={() => toggleExpand(slip.id)}
										className="w-full flex items-center justify-between px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors text-left"
									>
										<div className="flex items-center gap-4">
											<div className="p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-blue-500">
												<DocumentIcon />
											</div>
											<div>
												<p className="font-semibold text-gray-900 dark:text-white text-sm">
													{periodLabel(slip)}
												</p>
												<p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
													{formatDate(slip.pay_period_start)} — {formatDate(slip.pay_period_end)}
												</p>
											</div>
										</div>

										<div className="flex items-center gap-5">
											<div className="text-right">
												<p className="text-xs text-gray-400 dark:text-gray-500">Net Pay</p>
												<p className="font-bold text-emerald-600 dark:text-emerald-400 text-sm">
													{formatPHP(slip.net_salary)}
												</p>
											</div>
											<span className={`hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${
												slip.status === 'paid'
													? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
												: slip.approval_status === 'approved'
													? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
													: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
										}`}>
											{slip.status === 'paid' ? 'Paid' : slip.approval_status === 'approved' ? 'Approved' : 'Pending'}
											</span>
											<span className="text-gray-400 dark:text-gray-500">
												{expandedId === slip.id ? <ChevronUpIcon /> : <ChevronDownIcon />}
											</span>
										</div>
									</button>

									{/* Expandable detail */}
									{expandedId === slip.id && (
										<div className="border-t border-gray-200 dark:border-gray-700 px-5 py-5">
											<PayslipDetail
												slip={slip}
												employeeName={employeeName}
												shopName={shopName}
											/>
										</div>
									)}
								</div>
							))}
						</div>
					)}

					{/* Pagination */}
					{!isLoading && lastPage > 1 && (
						<div className="flex items-center justify-between mt-6 text-sm text-gray-600 dark:text-gray-400">
							<span>Showing {payslips.length} of {total} payslips</span>
							<div className="flex gap-2">
								<button
									disabled={currentPage <= 1}
									onClick={() => { fetchPayslips(currentPage - 1, searchPeriod); }}
									className="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
								>
									← Prev
								</button>
								<span className="px-3 py-1.5">Page {currentPage} / {lastPage}</span>
								<button
									disabled={currentPage >= lastPage}
									onClick={() => { fetchPayslips(currentPage + 1, searchPeriod); }}
									className="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
								>
									Next →
								</button>
							</div>
						</div>
					)}

				</div>
			</div>
		</AppLayoutERP>
	);
}
