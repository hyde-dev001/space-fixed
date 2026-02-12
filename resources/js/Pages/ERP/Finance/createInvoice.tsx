import { Head, router } from "@inertiajs/react";
import React, { useMemo, useState, useEffect } from "react";
import Swal from "sweetalert2";
import { useTaxRates, useAccounts } from "../../../hooks/useFinanceQueries";

type Account = {
	id: string;
	code: string;
	name: string;
	type: string;
};

type TaxRate = {
	id: string;
	name: string;
	rate: number;
	type: string;
	is_percentage?: boolean;
	fixed_amount?: number;
};

async function getAuthHeaders() {
	return {
		"Content-Type": "application/json",
		"X-Requested-With": "XMLHttpRequest",
		...(typeof window !== "undefined" && localStorage.getItem("authToken")
			? { Authorization: `Bearer ${localStorage.getItem("authToken")}` }
			: {}),
	};
}

const TrashIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg
		className={className}
		fill="none"
		viewBox="0 0 24 24"
		stroke="currentColor"
		strokeWidth={2}
	>
		<path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
	</svg>
);

const PlusIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
	</svg>
);

const ArrowLeftIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
	</svg>
);

const PencilIcon: React.FC<{ className?: string }> = ({ className }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
		<path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
	</svg>
);

type ProductRow = {
	id: number;
	name: string;
	price: number;
	quantity: number;
	discount: number;
};

const paymentConditions = [
	"Net 7",
	"Net 15",
	"Net 30",
	"Due on receipt",
];

export default function FinanceCreateInvoice() {
	const [rows, setRows] = useState<ProductRow[]>([]);
	const [editingRow, setEditingRow] = useState<ProductRow | null>(null);
	const [isEditModalOpen, setIsEditModalOpen] = useState(false);
	const [productName, setProductName] = useState("");
	const [productPrice, setProductPrice] = useState<number>(0);
	const [productQty, setProductQty] = useState<number>(1);
	const [productDiscount, setProductDiscount] = useState<number>(0);
	const [fieldErrors, setFieldErrors] = useState<Record<string, boolean>>({});
	const [selectedAccountId, setSelectedAccountId] = useState<string>("");
	const [selectedTaxId, setSelectedTaxId] = useState<string | number>("");
	const [loading, setLoading] = useState(false);

	// React Query hooks - automatically handle loading, caching, refetching
	const { data: accountsData = [], isLoading: isLoadingAccounts } = useAccounts();
	const { data: taxRates = [], isLoading: isLoadingTaxRates } = useTaxRates();
	
	// Filter to revenue accounts only
	const accounts = useMemo(() => 
		accountsData.filter((a: Account) => a.type === "Revenue"),
		[accountsData]
	);

	const [invoiceNumber, setInvoiceNumber] = useState("INV-" + Date.now());
	const [customerName, setCustomerName] = useState("");
	const [customerEmail, setCustomerEmail] = useState("");
	const [customerAddress, setCustomerAddress] = useState("");
	const [paymentCondition, setPaymentCondition] = useState(paymentConditions[1]);
	const [issueDate, setIssueDate] = useState(new Date().toISOString().split('T')[0]);
	const [dueDate, setDueDate] = useState("");
	const [additionalInfo, setAdditionalInfo] = useState("");

	// Set default account and tax when data is loaded
	useEffect(() => {
		if (accounts.length > 0 && !selectedAccountId) {
			setSelectedAccountId(accounts[0].id);
		}
	}, [accounts, selectedAccountId]);

	useEffect(() => {
		if (taxRates.length > 0 && !selectedTaxId) {
			// Select default tax (VAT 12%)
			const defaultTax = taxRates.find((t: TaxRate) => t.name.includes("VAT 12%") && !t.name.includes("Inclusive"));
			if (defaultTax) {
				setSelectedTaxId(defaultTax.id);
			} else {
				setSelectedTaxId(taxRates[0].id);
			}
		}
	}, [taxRates, selectedTaxId]);

	const totals = useMemo(() => {
		const subtotal = rows.reduce((sum, row) => {
			const discounted = row.price * row.quantity * (1 - row.discount / 100);
			return sum + discounted;
		}, 0);
		
		const selectedTax = taxRates.find((t: any) => t.id == selectedTaxId); // Use == for loose comparison
		let taxAmount = 0;
		let taxLabel = "Tax";
		
		if (selectedTax) {
			// Check both is_percentage boolean and type string
			const isPercentage = selectedTax.is_percentage === true || selectedTax.type === 'percentage';
			const isFixed = selectedTax.type === 'fixed';
			
			if (isPercentage) {
				taxAmount = subtotal * (selectedTax.rate / 100);
				taxLabel = `${selectedTax.name} (${selectedTax.rate}%)`;
			} else if (isFixed && selectedTax.fixed_amount) {
				taxAmount = selectedTax.fixed_amount;
				taxLabel = `${selectedTax.name}`;
			} else {
				taxAmount = selectedTax.rate;
				taxLabel = `${selectedTax.name}`;
			}
		}
		
		const grandTotal = subtotal + taxAmount;
		return {
			subtotal,
			tax: taxAmount,
			taxLabel,
			grandTotal,
		};
	}, [rows, taxRates, selectedTaxId]);

	const handleAddRow = async () => {
		if (!productName.trim()) return;
		const confirm = await Swal.fire({
			title: "Add this product?",
			text: "This will append the line item to the invoice.",
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Add Product",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#2563eb",
			reverseButtons: true,
		});
		if (!confirm.isConfirmed) return;

		const cleanPrice = Math.max(0, productPrice);
		const cleanQty = Math.max(1, productQty);
		const cleanDiscount = Math.min(100, Math.max(0, productDiscount));

		const newRow: ProductRow = {
			id: rows.length ? rows[rows.length - 1].id + 1 : 1,
			name: productName.trim(),
			price: cleanPrice,
			quantity: cleanQty,
			discount: cleanDiscount,
		};
		setRows((prev) => [...prev, newRow]);

		setProductName("");
		setProductPrice(0);
		setProductQty(1);
		setProductDiscount(0);
	};

	const handleEditRow = (row: ProductRow) => {
		setEditingRow(row);
		setIsEditModalOpen(true);
	};

	const handleEditField = (field: keyof ProductRow, value: number | string) => {
		setEditingRow((prev) => (prev ? { ...prev, [field]: value } : prev));
	};

	const handleEditSave = async () => {
		if (!editingRow) return;

		const result = await Swal.fire({
			title: "Save changes?",
			text: "This will update the selected line item.",
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Save",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#2563eb",
			reverseButtons: true,
		});
		if (!result.isConfirmed) return;

		const cleanPrice = Math.max(0, editingRow.price);
		const cleanQty = Math.max(1, editingRow.quantity);
		const cleanDiscount = Math.min(100, Math.max(0, editingRow.discount));

		setRows((prev) =>
			prev.map((row) =>
				row.id === editingRow.id
					? {
						...row,
						name: editingRow.name.trim(),
						price: cleanPrice,
						quantity: cleanQty,
						discount: cleanDiscount,
					}
				: row
			)
		);
		setIsEditModalOpen(false);
		setEditingRow(null);
	};

	const handleEditCancel = () => {
		setIsEditModalOpen(false);
		setEditingRow(null);
	};

	const handleRemoveRow = async (id: number) => {
		const result = await Swal.fire({
			title: "Delete product?",
			text: "This will remove the line item from the invoice.",
			icon: "warning",
			showCancelButton: true,
			confirmButtonText: "Yes, delete",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#d33",
			reverseButtons: true,
		});

		if (result.isConfirmed) {
			setRows((prev) => prev.filter((r) => r.id !== id));
			if (editingRow?.id === id) {
				setEditingRow(null);
				setIsEditModalOpen(false);
			}
		}
	};

	const formatCurrency = (value: number) =>
		new Intl.NumberFormat("en-PH", {
			style: "currency",
			currency: "PHP",
			minimumFractionDigits: 2,
		}).format(value);

	const inputClass = (key: string) => `w-full rounded-lg border ${fieldErrors[key] ? "border-red-500 focus:ring-2 focus:ring-red-500" : "border-gray-300 dark:border-gray-600 focus:ring-2 focus:ring-blue-500/40"} bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white`;

	const handleSaveInvoice = async () => {
		const missingKeys: string[] = [];
		if (!invoiceNumber.trim()) missingKeys.push("invoiceNumber");
		if (!customerName.trim()) missingKeys.push("customerName");
		if (!issueDate.trim()) missingKeys.push("issueDate");
		if (rows.length === 0) {
			await Swal.fire({
				title: "No line items",
				text: "Please add at least one product to the invoice.",
				icon: "error",
				confirmButtonColor: "#d33",
			});
			return;
		}

		if (missingKeys.length) {
			setFieldErrors((prev) => ({ ...prev, ...Object.fromEntries(missingKeys.map((k) => [k, true])) }));
			await Swal.fire({
				title: "Invalid missing input",
				text: "Please fill in the required fields marked in red.",
				icon: "error",
				confirmButtonColor: "#d33",
			});
			return;
		}

		const result = await Swal.fire({
			title: "Save this invoice?",
			text: "We will save your invoice details and line items.",
			icon: "question",
			showCancelButton: true,
			confirmButtonText: "Save Invoice",
			cancelButtonText: "Cancel",
			confirmButtonColor: "#2563eb",
			reverseButtons: true,
		});

		if (!result.isConfirmed) return;

		setLoading(true);
		try {
			const headers = await getAuthHeaders();
			
			const items = rows.map((row) => {
				const unitPrice = row.price * (1 - row.discount / 100);
				const quantity = row.quantity;
				const taxRate = 10;
				
				return {
					description: row.name,
					quantity: quantity,
					unit_price: unitPrice,
					tax_rate: taxRate,
					account_id: selectedAccountId || accounts[0]?.id,
				};
			});

			const invoiceData = {
				reference: invoiceNumber,
				customer_name: customerName,
				customer_email: customerEmail || null,
				date: issueDate,
				due_date: dueDate || null,
				notes: additionalInfo || null,
				items: items,
			};

		const response = await fetch("/api/finance/session/invoices", {
			});

			const data = await response.json();

			if (!response.ok) {
				throw new Error(data.message || "Failed to create invoice");
			}

			await Swal.fire({
				title: "Invoice saved!",
				text: `Invoice ${invoiceNumber} has been created successfully.`,
				icon: "success",
				confirmButtonColor: "#2563eb",
			});

		router.visit('/finance?section=invoice-generation');
		} catch (error) {
			await Swal.fire({
				title: "Error",
				text: error instanceof Error ? error.message : "Failed to create invoice",
				icon: "error",
				confirmButtonColor: "#d33",
			});
		} finally {
			setLoading(false);
		}
	};

	return (
		<>
			<Head title="Create Invoice - Solespace ERP" />
			<div className="min-h-screen bg-gray-50 dark:bg-gray-900 py-6 px-4 sm:px-6 lg:px-8">
				<div className="max-w-7xl mx-auto space-y-6 pb-20">
					<div>
						<a
							href="/finance?section=invoice-generation"
							className="inline-flex items-center gap-2 text-sm font-semibold text-gray-600 hover:text-gray-700"
						>
							<ArrowLeftIcon className="w-4 h-4" />
							Back to invoice page
						</a>
					</div>
					<div className="flex items-center justify-between">
						<div>
							<h1 className="text-2xl font-bold text-gray-900 dark:text-white">Create Invoice</h1>
							<p className="text-sm text-gray-600 dark:text-gray-400">Generate a new invoice and add line items.</p>
						</div>
						<div className="flex items-center gap-3">
							<button
								onClick={handleSaveInvoice}
								disabled={loading}
								className="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 shadow-sm inline-flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
							>
								<PlusIcon className="w-4 h-4" />
								{loading ? "Saving..." : "Save Invoice"}
							</button>
						</div>
					</div>

					<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 space-y-6">
						<h2 className="text-lg font-semibold text-gray-900 dark:text-white">Invoice Details</h2>

						<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Invoice Number</label>
								<input
									value={invoiceNumber}
									onChange={(e) => {
										setInvoiceNumber(e.target.value);
										if (fieldErrors.invoiceNumber) setFieldErrors((prev) => ({ ...prev, invoiceNumber: false }));
									}}
									className={inputClass("invoiceNumber")}
									placeholder="Enter invoice number"
								/>
							</div>
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Customer Name</label>
								<input
									value={customerName}
									onChange={(e) => {
										setCustomerName(e.target.value);
										if (fieldErrors.customerName) setFieldErrors((prev) => ({ ...prev, customerName: false }));
									}}
									className={inputClass("customerName")}
									placeholder="Enter customer name"
								/>
							</div>
						</div>

						<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Customer Email</label>
								<input
									type="email"
									value={customerEmail}
									onChange={(e) => setCustomerEmail(e.target.value)}
									className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
									placeholder="customer@example.com (optional)"
								/>
							</div>
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Customer Address</label>
								<input
									value={customerAddress}
									onChange={(e) => setCustomerAddress(e.target.value)}
									className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
									placeholder="Enter customer address (optional)"
								/>
							</div>
						</div>

						<div className="grid grid-cols-1 md:grid-cols-1 gap-4">
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Payment Condition</label>
								<select
									value={paymentCondition}
									onChange={(e) => {
										setPaymentCondition(e.target.value);
										if (fieldErrors.paymentCondition) setFieldErrors((prev) => ({ ...prev, paymentCondition: false }));
									}}
									className={inputClass("paymentCondition")}
								>
									{paymentConditions.map((pc) => (
										<option key={pc} value={pc}>
											{pc}
										</option>
									))}
								</select>
							</div>
						</div>

						<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Issue Date</label>
								<input
									type="date"
									value={issueDate}
									onChange={(e) => {
										setIssueDate(e.target.value);
										if (fieldErrors.issueDate) setFieldErrors((prev) => ({ ...prev, issueDate: false }));
									}}
									className={inputClass("issueDate")}
								/>
							</div>
							<div className="flex flex-col gap-2">
								<label className="text-sm text-gray-700 dark:text-gray-300">Due Date</label>
								<input
									type="date"
									value={dueDate}
									onChange={(e) => {
										setDueDate(e.target.value);
										if (fieldErrors.dueDate) setFieldErrors((prev) => ({ ...prev, dueDate: false }));
									}}
									className={inputClass("dueDate")}
								/>
							</div>
						</div>

						<div className="flex flex-col gap-2">
							<label className="text-sm text-gray-700 dark:text-gray-300">Additional Info</label>
							<textarea
								value={additionalInfo}
								onChange={(e) => setAdditionalInfo(e.target.value)}
								rows={3}
								className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
								placeholder="Receipt info (optional)"
							/>
						</div>
					</div>

					<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
						<div className="overflow-x-auto">
							<table className="min-w-full text-sm">
								<thead className="bg-gray-50 dark:bg-gray-900/40 text-gray-600 dark:text-gray-300">
									<tr>
										<th className="px-4 py-3 text-left">S. No.</th>
										<th className="px-4 py-3 text-left">Products</th>
										<th className="px-4 py-3 text-center">Quantity</th>
										<th className="px-4 py-3 text-right">Unit Cost</th>
										<th className="px-4 py-3 text-center">Discount</th>
										<th className="px-4 py-3 text-right">Total</th>
										<th className="px-4 py-3 text-center">Action</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
									{rows.map((row, idx) => {
										const total = row.price * row.quantity * (1 - row.discount / 100);
										return (
											<tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-gray-900/40">
												<td className="px-4 py-3 text-gray-700 dark:text-gray-300">{idx + 1}</td>
												<td className="px-4 py-3 text-gray-900 dark:text-white">{row.name}</td>
												<td className="px-4 py-3 text-center text-gray-900 dark:text-white">{row.quantity}</td>
												<td className="px-4 py-3 text-right text-gray-900 dark:text-white">{formatCurrency(row.price)}</td>
												<td className="px-4 py-3 text-center text-gray-900 dark:text-white">{row.discount}%</td>
												<td className="px-4 py-3 text-right text-gray-900 dark:text-white">{formatCurrency(total)}</td>
												<td className="px-4 py-3 text-center">
													<div className="flex items-center justify-center gap-3">
														<button
															onClick={() => handleEditRow(row)}
															className="inline-flex items-center justify-center text-blue-600 hover:text-blue-700"
															title="Edit"
														>
															<PencilIcon className="w-4 h-4" />
														</button>
														<button
															onClick={() => handleRemoveRow(row.id)}
															className="inline-flex items-center justify-center text-red-500 hover:text-red-600"
															title="Remove"
														>
															<TrashIcon className="w-4 h-4" />
														</button>
													</div>
												</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						</div>

						<div className="p-4 border-t border-gray-200 dark:border-gray-700">
							<div className="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
								<div className="md:col-span-4 flex flex-col gap-1">
									<label className="text-xs text-gray-600 dark:text-gray-400">Product Name</label>
									<input
										value={productName}
										onChange={(e) => setProductName(e.target.value)}
										placeholder="Enter product name"
										className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
									/>
								</div>
								<div className="md:col-span-2 flex flex-col gap-1">
									<label className="text-xs text-gray-600 dark:text-gray-400">Price</label>
									<input
										type="number"
										min={0}
										value={productPrice}
										onChange={(e) => setProductPrice(Number(e.target.value))}
										className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
									/>
								</div>
								<div className="md:col-span-2 flex flex-col gap-1">
									<label className="text-xs text-gray-600 dark:text-gray-400">Quantity</label>
									<input
										type="number"
										min={1}
										value={productQty}
										onChange={(e) => setProductQty(Number(e.target.value))}
										className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
									/>
								</div>
								<div className="md:col-span-2 flex flex-col gap-1">
									<label className="text-xs text-gray-600 dark:text-gray-400">Discount</label>
									<input
										type="number"
										min={0}
										max={100}
										value={productDiscount}
										onChange={(e) => setProductDiscount(Number(e.target.value))}
										className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
									/>
								</div>
								<div className="md:col-span-2 flex">
									<button
										onClick={handleAddRow}
										disabled={!selectedAccountId}
										className="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 text-sm font-semibold shadow disabled:opacity-50 disabled:cursor-not-allowed"
									>
										<PlusIcon className="w-5 h-5" />
										Add Product
									</button>
								</div>
							</div>

							{accounts.length > 0 && (
								<div className="col-span-full mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
									<div>
										<label className="text-xs text-gray-600 dark:text-gray-400">Revenue Account</label>
										<select
											value={selectedAccountId}
											onChange={(e) => setSelectedAccountId(e.target.value)}
											className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white mt-1"
										>
											{accounts.map((acc) => (
												<option key={acc.id} value={acc.id}>
													{acc.code} - {acc.name}
												</option>
											))}
										</select>
									</div>
									{taxRates.length > 0 && (
										<div>
											<label className="text-xs text-gray-600 dark:text-gray-400">Tax Rate</label>
											<select
												value={selectedTaxId}
												onChange={(e) => setSelectedTaxId(e.target.value)}
												className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white mt-1"
											>
												{taxRates.map((tax: any) => {
													const isPercentage = tax.is_percentage === true || tax.type === 'percentage';
													const isFixed = tax.type === 'fixed';
													let displayText = tax.name;
													
													if (isPercentage) {
														displayText += ` (${tax.rate}%)`;
													} else if (isFixed && tax.fixed_amount != null) {
														displayText += ` (₱${Number(tax.fixed_amount).toFixed(2)})`;
													} else if (tax.rate > 0) {
														displayText += ` (₱${tax.rate})`;
													}
													
													return (
														<option key={tax.id} value={tax.id}>
															{displayText}
														</option>
													);
												})}
											</select>
										</div>
									)}
								</div>
							)}
						</div>
					</div>

					{isEditModalOpen && editingRow && (
						<div className="fixed inset-0 z-[999999] flex items-center justify-center bg-black/60 backdrop-blur-sm px-4">
							<div className="w-full max-w-lg rounded-2xl bg-white dark:bg-gray-900 shadow-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
								<div className="flex items-center justify-between">
									<h3 className="text-lg font-semibold text-gray-900 dark:text-white">Edit Product</h3>
									<button
										onClick={handleEditCancel}
										className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
										title="Close"
									>
										✕
									</button>
								</div>
								<div className="grid grid-cols-1 gap-3">
									<div className="flex flex-col gap-1">
										<label className="text-sm text-gray-700 dark:text-gray-300">Product Name</label>
										<input
											value={editingRow.name}
											onChange={(e) => handleEditField("name", e.target.value)}
											className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
										/>
									</div>
									<div className="grid grid-cols-1 md:grid-cols-3 gap-3">
										<div className="flex flex-col gap-1">
											<label className="text-sm text-gray-700 dark:text-gray-300">Price</label>
											<input
												type="number"
												min={0}
												value={editingRow.price}
												onChange={(e) => handleEditField("price", Number(e.target.value))}
												className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
											/>
										</div>
										<div className="flex flex-col gap-1">
											<label className="text-sm text-gray-700 dark:text-gray-300">Quantity</label>
											<input
												type="number"
												min={1}
												value={editingRow.quantity}
												onChange={(e) => handleEditField("quantity", Number(e.target.value))}
												className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
											/>
										</div>
										<div className="flex flex-col gap-1">
											<label className="text-sm text-gray-700 dark:text-gray-300">Discount (%)</label>
											<input
												type="number"
												min={0}
												max={100}
												value={editingRow.discount}
												onChange={(e) => handleEditField("discount", Number(e.target.value))}
												className="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white"
											/>
										</div>
									</div>
								</div>
								<div className="flex items-center justify-end gap-3 pt-2">
									<button
										onClick={handleEditCancel}
										className="px-4 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800"
									>
										Cancel
									</button>
									<button
										onClick={handleEditSave}
										className="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 shadow"
									>
										Save Changes
									</button>
								</div>
							</div>
						</div>
					)}

					<div className="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
						<div>
							<p className="text-sm text-gray-600 dark:text-gray-400">After filling product details, press Enter/Return or click "Add Product" to add it.</p>
						</div>
						<div className="space-y-1 text-right">
							<div className="flex items-center justify-between gap-8 text-sm text-gray-700 dark:text-gray-300">
								<span>Sub Total</span>
								<span className="font-semibold text-gray-900 dark:text-white">{formatCurrency(totals.subtotal)}</span>
							</div>
							<div className="flex items-center justify-between gap-8 text-sm text-gray-700 dark:text-gray-300">
								<span>{totals.taxLabel}</span>
								<span className="font-semibold text-gray-900 dark:text-white">{formatCurrency(totals.tax)}</span>
							</div>
							<div className="flex items-center justify-between gap-8 text-base font-bold text-gray-900 dark:text-white">
								<span>Total</span>
								<span>{formatCurrency(totals.grandTotal)}</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
