import { Head } from "@inertiajs/react";
import { FormEvent, useMemo, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";

type PromoKind = "voucher" | "discount";
type DiscountMode = "percentage" | "fixed";
type CampaignStatus = "draft" | "scheduled" | "active" | "expired";

type ProductOption = {
	id: string;
	name: string;
	category: string;
	stock: number;
	price: number;
};

type Campaign = {
	id: number;
	kind: PromoKind;
	name: string;
	code: string;
	productId: string;
	productName: string;
	discountMode: DiscountMode;
	value: number;
	minSpend: number;
	usageLimit: number;
	usedCount: number;
	startDate: string;
	endDate: string;
	status: CampaignStatus;
};

type PromoFormState = {
	kind: PromoKind;
	name: string;
	code: string;
	productId: string;
	discountMode: DiscountMode;
	value: string;
	minSpend: string;
	usageLimit: string;
	startDate: string;
	endDate: string;
};

const products: ProductOption[] = [
	{ id: "sku-urban-kicks", name: "Urban Kicks Pro", category: "Sneakers", stock: 32, price: 3299 },
	{ id: "sku-classic-loafer", name: "Classic Loafer", category: "Formal", stock: 18, price: 2890 },
	{ id: "sku-runner-lite", name: "Runner Lite", category: "Performance", stock: 41, price: 2599 },
	{ id: "sku-street-high", name: "Street High", category: "Lifestyle", stock: 12, price: 3499 },
];

const initialCampaigns: Campaign[] = [
	{
		id: 1,
		kind: "voucher",
		name: "Weekend Drop",
		code: "WEEKEND10",
		productId: "sku-urban-kicks",
		productName: "Urban Kicks Pro",
		discountMode: "percentage",
		value: 10,
		minSpend: 2000,
		usageLimit: 80,
		usedCount: 26,
		startDate: "2026-03-15",
		endDate: "2026-03-28",
		status: "active",
	},
	{
		id: 2,
		kind: "discount",
		name: "Formal Shoe Markdown",
		code: "AUTO-FORMAL",
		productId: "sku-classic-loafer",
		productName: "Classic Loafer",
		discountMode: "fixed",
		value: 350,
		minSpend: 0,
		usageLimit: 50,
		usedCount: 9,
		startDate: "2026-03-20",
		endDate: "2026-04-05",
		status: "scheduled",
	},
	{
		id: 3,
		kind: "voucher",
		name: "Flash Sale Recovery",
		code: "FLASHBACK",
		productId: "sku-runner-lite",
		productName: "Runner Lite",
		discountMode: "percentage",
		value: 15,
		minSpend: 2500,
		usageLimit: 40,
		usedCount: 40,
		startDate: "2026-03-01",
		endDate: "2026-03-10",
		status: "expired",
	},
];

const buildInitialForm = (): PromoFormState => {
	const today = new Date();
	const nextWeek = new Date(today);
	nextWeek.setDate(today.getDate() + 7);

	return {
		kind: "voucher",
		name: "",
		code: "",
		productId: products[0]?.id ?? "",
		discountMode: "percentage",
		value: "",
		minSpend: "",
		usageLimit: "100",
		startDate: today.toISOString().slice(0, 10),
		endDate: nextWeek.toISOString().slice(0, 10),
	};
};

const formatCurrency = (amount: number) =>
	new Intl.NumberFormat("en-PH", {
		style: "currency",
		currency: "PHP",
		maximumFractionDigits: 0,
	}).format(amount);

const getStatusClasses = (status: CampaignStatus) => {
	switch (status) {
		case "active":
			return "bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200";
		case "scheduled":
			return "bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200";
		case "expired":
			return "bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-200";
		default:
			return "bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200";
	}
};

const TicketIcon = ({ className = "" }: { className?: string }) => (
	<svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
		<path d="M3 9a2 2 0 0 0 0 6v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a2 2 0 0 0 0-6V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3z" />
		<path d="M13 5v14" />
		<path d="M13 9h4" />
		<path d="M13 15h4" />
	</svg>
);

const TagIcon = ({ className = "" }: { className?: string }) => (
	<svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
		<path d="M20.59 13.41 11 23H4v-7l9.59-9.59A2 2 0 0 1 15 6h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.41 1.41Z" />
		<path d="M15 9h.01" />
	</svg>
);

const SparkIcon = ({ className = "" }: { className?: string }) => (
	<svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
		<path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3Z" />
		<path d="M5 19l1-2 2-1-2-1-1-2-1 2-2 1 2 1 1 2Z" />
		<path d="M19 21l.7-1.3L21 19l-1.3-.7L19 17l-.7 1.3L17 19l1.3.7L19 21Z" />
	</svg>
);

const ChartIcon = ({ className = "" }: { className?: string }) => (
	<svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
		<path d="M3 3v18h18" />
		<path d="M7 14l4-4 3 3 5-6" />
	</svg>
);

export default function VouchersDiscountPage() {
	const [campaigns, setCampaigns] = useState<Campaign[]>(initialCampaigns);
	const [form, setForm] = useState<PromoFormState>(buildInitialForm);
	const [filter, setFilter] = useState<"all" | PromoKind>("all");

	const selectedProduct = useMemo(
		() => products.find((product) => product.id === form.productId) ?? products[0],
		[form.productId]
	);

	const visibleCampaigns = useMemo(() => {
		if (filter === "all") {
			return campaigns;
		}

		return campaigns.filter((campaign) => campaign.kind === filter);
	}, [campaigns, filter]);

	const metrics = useMemo(() => {
		const active = campaigns.filter((campaign) => campaign.status === "active").length;
		const scheduled = campaigns.filter((campaign) => campaign.status === "scheduled").length;
		const totalRedemptions = campaigns.reduce((sum, campaign) => sum + campaign.usedCount, 0);
		const averageDiscount = campaigns.length
			? Math.round(campaigns.reduce((sum, campaign) => sum + campaign.value, 0) / campaigns.length)
			: 0;

		return { active, scheduled, totalRedemptions, averageDiscount };
	}, [campaigns]);

	const handleChange = (field: keyof PromoFormState, value: string) => {
		setForm((current) => {
			const next = { ...current, [field]: value };

			if (field === "kind" && value === "discount") {
				next.code = "AUTO-DISCOUNT";
			}

			if (field === "kind" && value === "voucher" && current.code === "AUTO-DISCOUNT") {
				next.code = "";
			}

			return next;
		});
	};

	const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
		event.preventDefault();

		if (!form.name.trim() || !form.productId || !form.value || !form.startDate || !form.endDate) {
			await Swal.fire({
				title: "Incomplete details",
				text: "Fill in the promo name, product, value, and schedule before saving.",
				icon: "warning",
				confirmButtonColor: "#111827",
			});
			return;
		}

		if (new Date(form.endDate) < new Date(form.startDate)) {
			await Swal.fire({
				title: "Invalid schedule",
				text: "End date must be later than the start date.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
			return;
		}

		const product = products.find((item) => item.id === form.productId);
		if (!product) {
			return;
		}

		const today = new Date().toISOString().slice(0, 10);
		let status: CampaignStatus = "draft";
		if (form.startDate > today) {
			status = "scheduled";
		} else if (form.endDate >= today) {
			status = "active";
		} else {
			status = "expired";
		}

		const nextCampaign: Campaign = {
			id: Date.now(),
			kind: form.kind,
			name: form.name.trim(),
			code: form.kind === "voucher" ? form.code.trim().toUpperCase() : "AUTO-DISCOUNT",
			productId: product.id,
			productName: product.name,
			discountMode: form.discountMode,
			value: Number(form.value),
			minSpend: Number(form.minSpend || 0),
			usageLimit: Number(form.usageLimit || 0),
			usedCount: 0,
			startDate: form.startDate,
			endDate: form.endDate,
			status,
		};

		setCampaigns((current) => [nextCampaign, ...current]);
		setForm(buildInitialForm());

		await Swal.fire({
			title: "Campaign created",
			text: `${nextCampaign.name} is ready for ${nextCampaign.productName}.`,
			icon: "success",
			confirmButtonColor: "#111827",
		});
	};

	return (
		<AppLayoutShopOwner>
			<Head title="Vouchers & Discount - Shop Owner" />

			<div className="space-y-6">
				<section className="overflow-hidden rounded-[28px] border border-slate-200 bg-[radial-gradient(circle_at_top_left,_rgba(29,78,216,0.12),_transparent_32%),linear-gradient(135deg,#ffffff_0%,#f8fafc_55%,#eef2ff_100%)] p-6 shadow-sm md:p-8">
					<div className="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
						<div className="max-w-2xl">
							<span className="inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-slate-600 ring-1 ring-slate-200 backdrop-blur">
								Promo Management
							</span>
							<h1 className="mt-4 text-3xl font-bold tracking-tight text-slate-900 md:text-4xl">
								Vouchers & Discount
							</h1>
							<p className="mt-3 max-w-xl text-sm leading-6 text-slate-600 md:text-base">
								Create product-based vouchers and discounts for your shop without leaving the dashboard flow. This page is tuned for fast setup, clear schedules, and cleaner promo monitoring.
							</p>
						</div>

						<div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:min-w-[460px]">
							<div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur">
								<div className="flex items-center justify-between text-slate-500">
									<TicketIcon className="h-5 w-5" />
									<span className="text-xs font-medium uppercase tracking-wide">Live</span>
								</div>
								<p className="mt-4 text-2xl font-bold text-slate-900">{metrics.active}</p>
								<p className="text-xs text-slate-500">Running campaigns</p>
							</div>
							<div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur">
								<div className="flex items-center justify-between text-slate-500">
									<SparkIcon className="h-5 w-5" />
									<span className="text-xs font-medium uppercase tracking-wide">Queued</span>
								</div>
								<p className="mt-4 text-2xl font-bold text-slate-900">{metrics.scheduled}</p>
								<p className="text-xs text-slate-500">Scheduled promos</p>
							</div>
							<div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur">
								<div className="flex items-center justify-between text-slate-500">
									<ChartIcon className="h-5 w-5" />
									<span className="text-xs font-medium uppercase tracking-wide">Used</span>
								</div>
								<p className="mt-4 text-2xl font-bold text-slate-900">{metrics.totalRedemptions}</p>
								<p className="text-xs text-slate-500">Total redemptions</p>
							</div>
							<div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur">
								<div className="flex items-center justify-between text-slate-500">
									<TagIcon className="h-5 w-5" />
									<span className="text-xs font-medium uppercase tracking-wide">Avg</span>
								</div>
								<p className="mt-4 text-2xl font-bold text-slate-900">
									{form.discountMode === "percentage" ? `${metrics.averageDiscount}%` : formatCurrency(metrics.averageDiscount)}
								</p>
								<p className="text-xs text-slate-500">Typical promo value</p>
							</div>
						</div>
					</div>
				</section>

				<section className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_420px]">
					<div className="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
						<div className="flex flex-col gap-3 border-b border-slate-200 pb-5 md:flex-row md:items-center md:justify-between">
							<div>
								<h2 className="text-xl font-semibold text-slate-900">Create a new campaign</h2>
								<p className="mt-1 text-sm text-slate-500">Set promo rules, attach a product, and launch when ready.</p>
							</div>
							<div className="inline-flex rounded-2xl border border-slate-200 bg-slate-50 p-1">
								{(["voucher", "discount"] as PromoKind[]).map((kind) => (
									<button
										key={kind}
										type="button"
										onClick={() => handleChange("kind", kind)}
										className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${form.kind === kind ? "bg-slate-900 text-white shadow-sm" : "text-slate-500 hover:text-slate-800"}`}
									>
										{kind === "voucher" ? "Voucher Code" : "Product Discount"}
									</button>
								))}
							</div>
						</div>

						<form className="mt-6 space-y-5" onSubmit={handleSubmit}>
							<div className="grid gap-5 md:grid-cols-2">
								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Campaign name</span>
									<input
										value={form.name}
										onChange={(event) => handleChange("name", event.target.value)}
										placeholder="Example: Payday Boost"
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Promo code</span>
									<input
										value={form.kind === "discount" ? "AUTO-DISCOUNT" : form.code}
										onChange={(event) => handleChange("code", event.target.value)}
										disabled={form.kind === "discount"}
										placeholder="Example: SAVE20"
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Select product</span>
									<select
										value={form.productId}
										onChange={(event) => handleChange("productId", event.target.value)}
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									>
										{products.map((product) => (
											<option key={product.id} value={product.id}>
												{product.name} · {product.category}
											</option>
										))}
									</select>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Discount type</span>
									<select
										value={form.discountMode}
										onChange={(event) => handleChange("discountMode", event.target.value)}
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									>
										<option value="percentage">Percentage off</option>
										<option value="fixed">Fixed peso off</option>
									</select>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Promo value</span>
									<div className="relative">
										<span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm font-medium text-slate-400">
											{form.discountMode === "percentage" ? "%" : "PHP"}
										</span>
										<input
											type="number"
											min="0"
											value={form.value}
											onChange={(event) => handleChange("value", event.target.value)}
											placeholder={form.discountMode === "percentage" ? "10" : "300"}
											className="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-14 pr-4 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
										/>
									</div>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Minimum spend</span>
									<div className="relative">
										<span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm font-medium text-slate-400">PHP</span>
										<input
											type="number"
											min="0"
											value={form.minSpend}
											onChange={(event) => handleChange("minSpend", event.target.value)}
											placeholder="0"
											className="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-14 pr-4 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
										/>
									</div>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Start date</span>
									<input
										type="date"
										value={form.startDate}
										onChange={(event) => handleChange("startDate", event.target.value)}
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">End date</span>
									<input
										type="date"
										value={form.endDate}
										onChange={(event) => handleChange("endDate", event.target.value)}
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>

								<label className="space-y-2 text-sm text-slate-600 md:col-span-2">
									<span className="font-medium text-slate-800">Usage limit</span>
									<input
										type="number"
										min="0"
										value={form.usageLimit}
										onChange={(event) => handleChange("usageLimit", event.target.value)}
										placeholder="100"
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>
							</div>

							<div className="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-end">
								<button
									type="button"
									onClick={() => setForm(buildInitialForm())}
									className="rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
								>
									Reset form
								</button>
								<button
									type="submit"
									className="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"
								>
									Save campaign
								</button>
							</div>
						</form>
					</div>

					<aside className="space-y-6">
						<div className="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
							<div className="flex items-center justify-between">
								<h2 className="text-lg font-semibold text-slate-900">Promo preview</h2>
								<span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
									{form.kind}
								</span>
							</div>
							<div className="mt-5 rounded-[24px] bg-slate-950 p-5 text-white shadow-[0_20px_60px_-30px_rgba(15,23,42,0.85)]">
								<div className="flex items-start justify-between gap-4">
									<div>
										<p className="text-xs uppercase tracking-[0.24em] text-slate-400">Offer Summary</p>
										<h3 className="mt-3 text-2xl font-semibold">{form.name || "Untitled promo"}</h3>
									</div>
									<TicketIcon className="h-10 w-10 text-slate-300" />
								</div>

								<div className="mt-6 grid grid-cols-2 gap-3">
									<div className="rounded-2xl border border-white/10 bg-white/5 p-4">
										<p className="text-xs uppercase tracking-wide text-slate-400">Discount</p>
										<p className="mt-2 text-xl font-semibold">
											{form.value
												? form.discountMode === "percentage"
													? `${form.value}% off`
													: `${formatCurrency(Number(form.value))} off`
												: "Set value"}
										</p>
									</div>
									<div className="rounded-2xl border border-white/10 bg-white/5 p-4">
										<p className="text-xs uppercase tracking-wide text-slate-400">Code</p>
										<p className="mt-2 text-xl font-semibold tracking-[0.18em]">
											{form.kind === "voucher" ? (form.code || "NO-CODE") : "AUTO"}
										</p>
									</div>
								</div>

								<div className="mt-6 space-y-3 text-sm text-slate-300">
									<div className="flex items-center justify-between gap-4">
										<span>Product</span>
										<span className="font-medium text-white">{selectedProduct?.name ?? "Select product"}</span>
									</div>
									<div className="flex items-center justify-between gap-4">
										<span>Schedule</span>
										<span className="font-medium text-white">{form.startDate} to {form.endDate}</span>
									</div>
									<div className="flex items-center justify-between gap-4">
										<span>Minimum spend</span>
										<span className="font-medium text-white">{formatCurrency(Number(form.minSpend || 0))}</span>
									</div>
								</div>
							</div>
						</div>

						<div className="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
							<h2 className="text-lg font-semibold text-slate-900">Selected product snapshot</h2>
							<div className="mt-5 space-y-4">
								<div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
									<p className="text-xs uppercase tracking-wide text-slate-500">Product</p>
									<p className="mt-2 text-lg font-semibold text-slate-900">{selectedProduct?.name}</p>
									<p className="mt-1 text-sm text-slate-500">{selectedProduct?.category}</p>
								</div>
								<div className="grid grid-cols-2 gap-3">
									<div className="rounded-2xl border border-slate-200 p-4">
										<p className="text-xs uppercase tracking-wide text-slate-500">Price</p>
										<p className="mt-2 text-lg font-semibold text-slate-900">{formatCurrency(selectedProduct?.price ?? 0)}</p>
									</div>
									<div className="rounded-2xl border border-slate-200 p-4">
										<p className="text-xs uppercase tracking-wide text-slate-500">Stock</p>
										<p className="mt-2 text-lg font-semibold text-slate-900">{selectedProduct?.stock ?? 0} units</p>
									</div>
								</div>
								<p className="rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700 ring-1 ring-inset ring-emerald-200">
									Tip: Use vouchers for urgency-based campaigns and auto discounts for evergreen promos on fast-moving products.
								</p>
							</div>
						</div>
					</aside>
				</section>

				<section className="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
					<div className="flex flex-col gap-4 border-b border-slate-200 pb-5 md:flex-row md:items-center md:justify-between">
						<div>
							<h2 className="text-xl font-semibold text-slate-900">Active campaign tracker</h2>
							<p className="mt-1 text-sm text-slate-500">Review current voucher and discount setups for your products.</p>
						</div>
						<div className="inline-flex rounded-2xl border border-slate-200 bg-slate-50 p-1">
							{([
								{ label: "All", value: "all" },
								{ label: "Vouchers", value: "voucher" },
								{ label: "Discounts", value: "discount" },
							] as const).map((option) => (
								<button
									key={option.value}
									type="button"
									onClick={() => setFilter(option.value)}
									className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${filter === option.value ? "bg-white text-slate-900 shadow-sm" : "text-slate-500 hover:text-slate-800"}`}
								>
									{option.label}
								</button>
							))}
						</div>
					</div>

					<div className="mt-6 overflow-x-auto">
						<table className="min-w-full divide-y divide-slate-200">
							<thead>
								<tr className="text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
									<th className="pb-3 pr-4">Campaign</th>
									<th className="pb-3 pr-4">Product</th>
									<th className="pb-3 pr-4">Offer</th>
									<th className="pb-3 pr-4">Schedule</th>
									<th className="pb-3 pr-4">Usage</th>
									<th className="pb-3">Status</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-slate-100">
								{visibleCampaigns.map((campaign) => (
									<tr key={campaign.id} className="align-top">
										<td className="py-4 pr-4">
											<div>
												<div className="flex items-center gap-3">
													<span className={`inline-flex h-10 w-10 items-center justify-center rounded-2xl ${campaign.kind === "voucher" ? "bg-blue-50 text-blue-700" : "bg-amber-50 text-amber-700"}`}>
														{campaign.kind === "voucher" ? <TicketIcon className="h-5 w-5" /> : <TagIcon className="h-5 w-5" />}
													</span>
													<div>
														<p className="font-semibold text-slate-900">{campaign.name}</p>
														<p className="text-sm text-slate-500">{campaign.code}</p>
													</div>
												</div>
											</div>
										</td>
										<td className="py-4 pr-4 text-sm text-slate-600">{campaign.productName}</td>
										<td className="py-4 pr-4 text-sm text-slate-600">
											{campaign.discountMode === "percentage"
												? `${campaign.value}% off`
												: `${formatCurrency(campaign.value)} off`}
										</td>
										<td className="py-4 pr-4 text-sm text-slate-600">{campaign.startDate}<br />{campaign.endDate}</td>
										<td className="py-4 pr-4 text-sm text-slate-600">
											{campaign.usedCount} / {campaign.usageLimit || "Unlimited"}
										</td>
										<td className="py-4">
											<span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold capitalize ${getStatusClasses(campaign.status)}`}>
												{campaign.status}
											</span>
										</td>
									</tr>
								))}
							</tbody>
						</table>

						{visibleCampaigns.length === 0 && (
							<div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">
								No campaigns match the current filter.
							</div>
						)}
					</div>
				</section>
			</div>
		</AppLayoutShopOwner>
	);
}
