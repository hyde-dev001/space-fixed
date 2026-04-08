import { Head } from "@inertiajs/react";
import { FormEvent, useEffect, useMemo, useState } from "react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";

type PromoKind = "voucher" | "discount";
type DiscountMode = "percentage" | "fixed";
type CampaignStatus = "draft" | "scheduled" | "active" | "expired";

type ProductOption = {
	id: number;
	name: string;
	category: string;
	stock: number;
	price: number;
	compareAtPrice: number | null;
	scheduledSalePrice: number | null;
	saleStartsAt: string | null;
	saleEndsAt: string | null;
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

type ApiCampaign = {
	id: number;
	kind: "voucher" | "sale";
	name: string;
	code: string | null;
	scope: "shop_wide" | "product_specific";
	discount_mode: DiscountMode;
	value: number;
	min_spend: number;
	usage_limit: number | null;
	used_count: number;
	start_at: string;
	end_at: string;
	status: CampaignStatus;
	products?: Array<{ id: number; name: string }>;
};

type PromoFormState = {
	kind: PromoKind;
	name: string;
	code: string;
	productId: string;
	discountScheduleEnabled: boolean;
	discountMode: DiscountMode;
	value: string;
	minSpend: string;
	usageLimit: string;
	startDate: string;
	endDate: string;
};

const buildInitialForm = (firstProductId: string = ""): PromoFormState => {
	const today = new Date();
	const nextWeek = new Date(today);
	nextWeek.setDate(today.getDate() + 7);

	return {
		kind: "voucher",
		name: "",
		code: "",
		productId: firstProductId,
		discountScheduleEnabled: false,
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
	const [campaigns, setCampaigns] = useState<Campaign[]>([]);
	const [products, setProducts] = useState<ProductOption[]>([]);
	const [isLoading, setIsLoading] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const [isUndoing, setIsUndoing] = useState(false);
	const [form, setForm] = useState<PromoFormState>(buildInitialForm());
	const [filter, setFilter] = useState<"all" | PromoKind>("all");

	const mapApiCampaignToUi = (campaign: ApiCampaign): Campaign => {
		const scopedProducts = Array.isArray(campaign.products) ? campaign.products : [];
		const firstProduct = scopedProducts[0];
		const isVoucher = campaign.kind === "voucher";

		return {
			id: Number(campaign.id),
			kind: isVoucher ? "voucher" : "discount",
			name: campaign.name,
			code: campaign.code || (isVoucher ? "" : "AUTO-DISCOUNT"),
			productId: firstProduct ? String(firstProduct.id) : "",
			productName: scopedProducts.length > 0
				? scopedProducts.map((product) => product.name).join(", ")
				: "All products",
			discountMode: campaign.discount_mode,
			value: Number(campaign.value || 0),
			minSpend: Number(campaign.min_spend || 0),
			usageLimit: campaign.usage_limit === null ? 0 : Number(campaign.usage_limit || 0),
			usedCount: Number(campaign.used_count || 0),
			startDate: String(campaign.start_at || "").slice(0, 10),
			endDate: String(campaign.end_at || "").slice(0, 10),
			status: campaign.status,
		};
	};

	const fetchProducts = async (): Promise<ProductOption[]> => {
		const response = await fetch("/api/shop-owner/promos/products", {
			credentials: "include",
			headers: {
				Accept: "application/json",
			},
		});

		if (!response.ok) {
			throw new Error("Failed to load products");
		}

		const data = await response.json();
		const list = Array.isArray(data?.data) ? data.data : [];

		const mapped = list.map((product: any) => ({
			id: Number(product.id),
			name: String(product.name || ""),
			category: String(product.category || "Uncategorized"),
			stock: Number(product.stock_quantity || 0),
			price: Number(product.price || 0),
			compareAtPrice: product.compare_at_price === null || product.compare_at_price === undefined
				? null
				: Number(product.compare_at_price || 0),
			scheduledSalePrice: product.scheduled_sale_price === null || product.scheduled_sale_price === undefined
				? null
				: Number(product.scheduled_sale_price || 0),
			saleStartsAt: product.sale_starts_at ? String(product.sale_starts_at) : null,
			saleEndsAt: product.sale_ends_at ? String(product.sale_ends_at) : null,
		}));

		setProducts(mapped);
		return mapped;
	};

	const fetchCampaigns = async () => {
		const response = await fetch("/api/shop-owner/promos", {
			credentials: "include",
			headers: {
				Accept: "application/json",
			},
		});

		if (!response.ok) {
			throw new Error("Failed to load campaigns");
		}

		const data = await response.json();
		const list: ApiCampaign[] = Array.isArray(data?.data) ? data.data : [];
		setCampaigns(list.map(mapApiCampaignToUi));
	};

	useEffect(() => {
		let cancelled = false;

		const loadData = async () => {
			setIsLoading(true);
			try {
				const loadedProducts = await fetchProducts();
				await fetchCampaigns();

				if (!cancelled && loadedProducts.length > 0) {
					setForm((current) => ({
						...current,
						productId: current.productId || String(loadedProducts[0].id),
					}));
				}
			} catch (error) {
				console.error("Failed to load promo management data:", error);
				await Swal.fire({
					icon: "error",
					title: "Load failed",
					text: "Unable to load promo data. Please refresh and try again.",
					confirmButtonColor: "#111827",
				});
			} finally {
				if (!cancelled) {
					setIsLoading(false);
				}
			}
		};

		void loadData();

		return () => {
			cancelled = true;
		};
	}, []);

	const selectedProduct = useMemo(
		() => products.find((product) => product.id === Number(form.productId)) ?? products[0],
		[form.productId, products]
	);

	const isProductDiscountMode = form.kind === "discount";
	const parsedFormValue = form.value.trim() === "" ? Number.NaN : Number(form.value);
	const currentProductPrice = Number(selectedProduct?.price || 0);
	const baselineOriginalPrice = selectedProduct && selectedProduct.compareAtPrice !== null && selectedProduct.compareAtPrice > selectedProduct.price
		? Number(selectedProduct.compareAtPrice)
		: currentProductPrice;
	const hasScheduledDiscount = Boolean(
		selectedProduct
		&& selectedProduct.scheduledSalePrice !== null
		&& selectedProduct.saleStartsAt
		&& selectedProduct.saleEndsAt
	);
	const canUndoSale = Boolean(
		isProductDiscountMode
		&& selectedProduct
		&& selectedProduct.compareAtPrice !== null
		&& (
			Number(selectedProduct.compareAtPrice) > Number(selectedProduct.price)
			|| hasScheduledDiscount
		)
	);
	const saleSavings = isProductDiscountMode && Number.isFinite(parsedFormValue)
		? Math.max(0, baselineOriginalPrice - parsedFormValue)
		: 0;
	const saleSavingsPercent = isProductDiscountMode && currentProductPrice > 0
		? Math.round((saleSavings / baselineOriginalPrice) * 100)
		: 0;
	const previewTitle = form.name || (isProductDiscountMode ? `${selectedProduct?.name ?? "Selected product"} sale` : "Untitled promo");

	const handleUndoSalePrice = async () => {
		if (!selectedProduct || selectedProduct.compareAtPrice === null) {
			return;
		}

		const originalPrice = Number(selectedProduct.compareAtPrice);
		if (!Number.isFinite(originalPrice) || originalPrice <= 0) {
			return;
		}

		const confirmation = await Swal.fire({
			title: "Undo sale price?",
			text: `Restore ${selectedProduct.name} from ${formatCurrency(selectedProduct.price)} back to ${formatCurrency(originalPrice)}?`,
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#111827",
			cancelButtonColor: "#9CA3AF",
			confirmButtonText: "Yes, restore",
			cancelButtonText: "Cancel",
		});

		if (!confirmation.isConfirmed) {
			return;
		}

		setIsUndoing(true);

		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

			const response = await fetch(`/api/shop-owner/products/${selectedProduct.id}`, {
				method: "PUT",
				credentials: "include",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": csrfToken,
				},
				body: JSON.stringify({
					price: originalPrice,
					compare_at_price: null,
					scheduled_sale_price: null,
					sale_starts_at: null,
					sale_ends_at: null,
				}),
			});

			const result = await response.json().catch(() => ({}));
			if (!response.ok) {
				throw new Error(result?.message || "Failed to restore original price");
			}

			const refreshedProducts = await fetchProducts();
			await fetchCampaigns();

			setForm((current) => ({
				...current,
				value: "",
				discountScheduleEnabled: false,
				productId: current.productId || String(refreshedProducts[0]?.id || ""),
			}));

			await Swal.fire({
				title: "Sale undone",
				text: `${selectedProduct.name} price is restored to ${formatCurrency(originalPrice)}.`,
				icon: "success",
				confirmButtonColor: "#111827",
			});
		} catch (error: any) {
			await Swal.fire({
				title: "Undo failed",
				text: error?.message || "Unable to restore original price.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		} finally {
			setIsUndoing(false);
		}
	};

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

	const handleChange = (field: keyof Omit<PromoFormState, "discountScheduleEnabled">, value: string) => {
		setForm((current) => {
			const next = { ...current, [field]: value };

			if (field === "kind" && value === "discount") {
				next.code = "AUTO-DISCOUNT";
			}

			if (field === "kind" && value === "voucher" && current.code === "AUTO-DISCOUNT") {
				next.code = "";
				next.discountScheduleEnabled = false;
			}

			return next;
		});
	};

	const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
		event.preventDefault();

		if (isProductDiscountMode) {
			if (!form.productId) {
				await Swal.fire({
					title: "Select a product",
					text: "Product Discount requires one selected product.",
					icon: "warning",
					confirmButtonColor: "#111827",
				});
				return;
			}

			if (!form.value) {
				await Swal.fire({
					title: "Sale price required",
					text: "Enter the new sale price for the selected product.",
					icon: "warning",
					confirmButtonColor: "#111827",
				});
				return;
			}

			if (!selectedProduct) {
				await Swal.fire({
					title: "Product not found",
					text: "Please reselect a valid product.",
					icon: "error",
					confirmButtonColor: "#111827",
				});
				return;
			}

			const proposedPrice = Number(form.value);
			if (!Number.isFinite(proposedPrice) || proposedPrice <= 0) {
				await Swal.fire({
					title: "Invalid sale price",
					text: "Sale price must be greater than 0.",
					icon: "warning",
					confirmButtonColor: "#111827",
				});
				return;
			}

			if (proposedPrice >= baselineOriginalPrice) {
				await Swal.fire({
					title: "Not a sale price",
					text: `New price must be lower than original price (${formatCurrency(baselineOriginalPrice)}).`,
					icon: "warning",
					confirmButtonColor: "#111827",
				});
				return;
			}

			if (form.discountScheduleEnabled) {
				if (!form.startDate || !form.endDate) {
					await Swal.fire({
						title: "Schedule required",
						text: "Please set both start and end date for scheduled discount.",
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
			}
		} else if (!form.name.trim() || !form.value || !form.startDate || !form.endDate) {
			await Swal.fire({
				title: "Incomplete details",
				text: "Fill in the promo name, value, and schedule before saving.",
				icon: "warning",
				confirmButtonColor: "#111827",
			});
			return;
		}

		if (form.kind === "voucher" && !form.code.trim()) {
			await Swal.fire({
				title: "Voucher code required",
				text: "Please provide a promo code for voucher campaigns.",
				icon: "warning",
				confirmButtonColor: "#111827",
			});
			return;
		}

		if ((!isProductDiscountMode || form.discountScheduleEnabled) && new Date(form.endDate) < new Date(form.startDate)) {
			await Swal.fire({
				title: "Invalid schedule",
				text: "End date must be later than the start date.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
			return;
		}

		setIsSaving(true);

		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

			if (isProductDiscountMode) {
				const productId = Number(form.productId);
				const proposedPrice = Number(form.value);
				const shouldScheduleDiscount = form.discountScheduleEnabled;
				const startsAtDate = form.startDate ? new Date(form.startDate) : null;
				const shouldApplyImmediately = Boolean(
					shouldScheduleDiscount
					&& startsAtDate
					&& startsAtDate <= new Date()
				);

				const response = await fetch(`/api/shop-owner/products/${productId}`, {
					method: "PUT",
					credentials: "include",
					headers: {
						"Content-Type": "application/json",
						Accept: "application/json",
						"X-CSRF-TOKEN": csrfToken,
					},
					body: JSON.stringify({
						price: shouldScheduleDiscount
							? selectedProduct?.price
							: proposedPrice,
						compare_at_price: selectedProduct?.compareAtPrice && selectedProduct.compareAtPrice > 0
							? selectedProduct.compareAtPrice
							: selectedProduct?.price,
						scheduled_sale_price: shouldScheduleDiscount ? proposedPrice : null,
						sale_starts_at: shouldScheduleDiscount ? form.startDate : null,
						sale_ends_at: shouldScheduleDiscount ? form.endDate : null,
					}),
				});

				const result = await response.json().catch(() => ({}));

				if (!response.ok) {
					throw new Error(result?.message || "Failed to update sale price");
				}

				const refreshedProducts = await fetchProducts();
				await fetchCampaigns();

				setForm((current) => ({
					...current,
					value: "",
					code: "AUTO-DISCOUNT",
					discountScheduleEnabled: false,
					productId: current.productId || String(refreshedProducts[0]?.id || ""),
				}));

				await Swal.fire({
					title: shouldScheduleDiscount ? "Discount schedule saved" : "Sale price updated",
					text: shouldScheduleDiscount
						? (
							shouldApplyImmediately
								? `${selectedProduct?.name || "Product"} is on sale at ${formatCurrency(proposedPrice)} until ${form.endDate}.`
								: `${selectedProduct?.name || "Product"} discount is scheduled from ${form.startDate} to ${form.endDate}.`
						)
						: `${selectedProduct?.name || "Product"} is now on sale at ${formatCurrency(proposedPrice)}.`,
					icon: "success",
					confirmButtonColor: "#111827",
				});

				return;
			}

			const payload = {
				kind: form.kind === "discount" ? "sale" : "voucher",
				scope: form.productId ? "product_specific" : "shop_wide",
				product_ids: form.productId ? [Number(form.productId)] : [],
				name: form.name.trim(),
				code: form.kind === "voucher" ? form.code.trim().toUpperCase() : null,
				discount_mode: form.discountMode,
				value: Number(form.value),
				min_spend: Number(form.minSpend || 0),
				usage_limit: Number(form.usageLimit || 0) || null,
				start_at: form.startDate,
				end_at: form.endDate,
			};

			const response = await fetch("/api/shop-owner/promos", {
				method: "POST",
				credentials: "include",
				headers: {
					"Content-Type": "application/json",
					Accept: "application/json",
					"X-CSRF-TOKEN": csrfToken,
				},
				body: JSON.stringify(payload),
			});

			const result = await response.json().catch(() => ({}));

			if (!response.ok) {
				throw new Error(result?.message || "Failed to save campaign");
			}

			await fetchCampaigns();
			setForm(buildInitialForm(products[0] ? String(products[0].id) : ""));

			await Swal.fire({
				title: "Campaign created",
				text: `${payload.name} is ready for ${form.productId ? selectedProduct?.name || "selected product" : "all products"}.`,
				icon: "success",
				confirmButtonColor: "#111827",
			});
		} catch (error: any) {
			await Swal.fire({
				title: "Save failed",
				text: error?.message || "Unable to create campaign.",
				icon: "error",
				confirmButtonColor: "#111827",
			});
		} finally {
			setIsSaving(false);
		}
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

				<section className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.2fr)_420px]">
					<div className="rounded-[26px] border border-slate-200 bg-white p-6 shadow-sm">
						{isLoading && (
							<div className="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
								Loading products and campaigns...
							</div>
						)}
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
								{!isProductDiscountMode && (
									<label className="space-y-2 text-sm text-slate-600">
										<span className="font-medium text-slate-800">Campaign name</span>
										<input
											value={form.name}
											onChange={(event) => handleChange("name", event.target.value)}
											placeholder="Example: Payday Boost"
											className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
										/>
									</label>
								)}

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
										{!isProductDiscountMode && <option value="">All products (Shop-wide)</option>}
										{products.map((product) => (
											<option key={product.id} value={String(product.id)}>
												{product.name} · {product.category}
											</option>
										))}
									</select>
								</label>

								{!isProductDiscountMode ? (
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
								) : (
									<label className="space-y-2 text-sm text-slate-600">
										<span className="font-medium text-slate-800">Current price</span>
										<input
											value={formatCurrency(currentProductPrice)}
											disabled
											className="w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-slate-700 outline-none disabled:cursor-not-allowed"
										/>
									</label>
								)}

								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">{isProductDiscountMode ? "New sale price" : "Promo value"}</span>
									<div className="relative">
										<span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm font-medium text-slate-400">
											{isProductDiscountMode ? "PHP" : (form.discountMode === "percentage" ? "%" : "PHP")}
										</span>
										<input
											type="number"
											min="0"
											value={form.value}
											onChange={(event) => handleChange("value", event.target.value)}
											placeholder={isProductDiscountMode ? "Enter sale price" : (form.discountMode === "percentage" ? "10" : "300")}
											className="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-14 pr-4 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
										/>
									</div>
									{isProductDiscountMode && selectedProduct && (
										<p className="text-xs text-slate-500">
											Set a price lower than {formatCurrency(baselineOriginalPrice)} to mark this product on sale.
										</p>
									)}
								</label>

								{isProductDiscountMode && (
									<label className="space-y-2 text-sm text-slate-600 md:col-span-2">
										<span className="font-medium text-slate-800">Discount duration</span>
										<div className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
											<label className="inline-flex items-center gap-2 text-sm text-slate-700">
												<input
													type="checkbox"
													checked={form.discountScheduleEnabled}
													onChange={(event) => setForm((current) => ({
														...current,
														discountScheduleEnabled: event.target.checked,
													}))}
													className="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400"
												/>
												<span>Set start and end date (auto-restore to original price after end date)</span>
											</label>
										</div>
									</label>
								)}

								{!isProductDiscountMode && (
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
								)}

								{(!isProductDiscountMode || form.discountScheduleEnabled) && (
								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">Start date</span>
									<input
										type="date"
										value={form.startDate}
										onChange={(event) => handleChange("startDate", event.target.value)}
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>
								)}

								{(!isProductDiscountMode || form.discountScheduleEnabled) && (
								<label className="space-y-2 text-sm text-slate-600">
									<span className="font-medium text-slate-800">End date</span>
									<input
										type="date"
										value={form.endDate}
										onChange={(event) => handleChange("endDate", event.target.value)}
										className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-slate-900 focus:ring-4 focus:ring-slate-200"
									/>
								</label>
								)}

								{!isProductDiscountMode && (
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
								)}
							</div>

							<div className="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-end">
								<button
									type="button"
									onClick={() => setForm(buildInitialForm(products[0] ? String(products[0].id) : ""))}
									className="rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
								>
									Reset form
								</button>
								{isProductDiscountMode && canUndoSale && (
									<button
										type="button"
										onClick={handleUndoSalePrice}
										disabled={isUndoing}
										className="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
									>
										{isUndoing ? "Undoing..." : "Undo sale"}
									</button>
								)}
								<button
									type="submit"
									disabled={isSaving}
									className="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
								>
									{isSaving ? "Saving..." : "Save campaign"}
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
										<h3 className="mt-3 text-2xl font-semibold">{previewTitle}</h3>
									</div>
									<TicketIcon className="h-10 w-10 text-slate-300" />
								</div>

								<div className="mt-6 grid grid-cols-2 gap-3">
									<div className="rounded-2xl border border-white/10 bg-white/5 p-4">
										<p className="text-xs uppercase tracking-wide text-slate-400">Discount</p>
										<p className="mt-2 text-xl font-semibold">
											{form.value
												? isProductDiscountMode
													? formatCurrency(Number(form.value))
													: (form.discountMode === "percentage"
														? `${form.value}% off`
														: `${formatCurrency(Number(form.value))} off`)
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
										<span className="font-medium text-white">{selectedProduct?.name ?? "All products"}</span>
									</div>
									<div className="flex items-center justify-between gap-4">
										<span>Schedule</span>
										<span className="font-medium text-white">
											{isProductDiscountMode
												? (form.discountScheduleEnabled ? `${form.startDate} to ${form.endDate}` : "Apply immediately")
												: `${form.startDate} to ${form.endDate}`}
										</span>
									</div>
									<div className="flex items-center justify-between gap-4">
										<span>{isProductDiscountMode ? "Savings" : "Minimum spend"}</span>
										<span className="font-medium text-white">
											{isProductDiscountMode
												? `${formatCurrency(saleSavings)}${saleSavingsPercent > 0 ? ` (${saleSavingsPercent}% off)` : ""}`
												: formatCurrency(Number(form.minSpend || 0))}
										</span>
									</div>
								</div>
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
