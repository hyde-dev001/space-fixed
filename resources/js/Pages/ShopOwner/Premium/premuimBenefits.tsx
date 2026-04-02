import React, { useEffect, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';

const cancellationReasons = [
	{ value: 'reduce_costs', label: 'I need to reduce business costs' },
	{ value: 'low_value', label: 'I am not getting enough value from the subscription' },
	{ value: 'technical_issues', label: 'I experienced technical issues' },
	{ value: 'missing_features', label: 'It is missing features I need' },
	{ value: 'subscribed_by_mistake', label: 'I subscribed by mistake' },
	{ value: 'temporary_pause', label: 'I only need a temporary pause' },
	{ value: 'others', label: 'Others' },
] as const;

type PremiumPlan = {
	id: number;
	plan_code: string;
	name: string;
	description: string | null;
	price: string | number;
	duration_days: number;
	showroom_slot_limit: number;
};

type PremiumSubscription = {
	id: number;
	status: 'pending' | 'active' | 'expired' | 'cancelled' | 'deactivated' | 'failed';
	plan_code: string | null;
	showroom_slot_limit: number | null;
	starts_at: string | null;
	ends_at: string | null;
	cancellation_reason?: string | null;
	cancellation_notes?: string | null;
	premiumPlan?: {
		id?: number | null;
		name?: string | null;
		price?: string | number | null;
		showroom_slot_limit?: number | null;
	} | null;
	pendingPremiumPlan?: {
		id?: number | null;
		name?: string | null;
		plan_code?: string | null;
		showroom_slot_limit?: number | null;
	} | null;
	pending_premium_plan?: {
		id?: number | null;
		name?: string | null;
		plan_code?: string | null;
		showroom_slot_limit?: number | null;
	} | null;
	pending_plan_effective_at?: string | null;
};

type UpgradePreview = {
	current_plan: {
		id: number;
		name: string;
		plan_code: string;
		price: number;
		showroom_slot_limit: number;
	};
	new_plan: {
		id: number;
		name: string;
		plan_code: string;
		price: number;
		showroom_slot_limit: number;
	};
	remaining_days: number;
	daily_rate?: number;
	remaining_value: number;
	new_plan_price: number;
	final_price: number;
	new_expiry: string;
	payment_required: boolean;
	slot_delta: number;
};

interface Props {}

const tutorialBtnBase =
	'group inline-flex items-center justify-center gap-2 rounded-full font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
const tutorialBtnLight =
	'border border-[#213257] bg-[#16284a] text-white shadow-[0_18px_35px_-18px_rgba(0,0,0,0.65)] hover:-translate-y-0.5 hover:border-[#2a3f6a] hover:bg-[#13213d] hover:text-white hover:shadow-[0_24px_38px_-18px_rgba(0,0,0,0.75)] focus-visible:ring-[#16284a]/70 focus-visible:ring-offset-2 focus-visible:ring-offset-black';
const actionBtnBase =
	'group inline-flex w-full items-center justify-center gap-2 rounded-full px-6 py-3 text-xs font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
const actionBtnDark =
	'border border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';

const checkIcon = (
	<div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-gray-50">
		<svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
		</svg>
	</div>
);

const PremiumBenefits: React.FC<Props> = () => {
	const pageProps = usePage().props as any;
	const shopOwnerId = pageProps.shop_owner?.id ?? pageProps.auth?.shop_owner?.id ?? null;
	const virtualShowroomHref = shopOwnerId ? `/shop-profile/${shopOwnerId}/virtual-showroom?from=shop-owner-premium` : null;

	const [plans, setPlans] = useState<PremiumPlan[]>([]);
	const [subscription, setSubscription] = useState<PremiumSubscription | null>(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [checkoutPlan, setCheckoutPlan] = useState<string | null>(null);
	const [cancellingSubscription, setCancellingSubscription] = useState(false);
	const [showCancelModal, setShowCancelModal] = useState(false);
	const [cancelReason, setCancelReason] = useState<string>('');
	const [cancelReasonNotes, setCancelReasonNotes] = useState('');
	const [cancelReasonError, setCancelReasonError] = useState<string | null>(null);
	const [showUpgradeModal, setShowUpgradeModal] = useState(false);
	const [upgradePreview, setUpgradePreview] = useState<UpgradePreview | null>(null);
	const [loadingPlanAction, setLoadingPlanAction] = useState<string | null>(null);
	const [confirmingUpgrade, setConfirmingUpgrade] = useState(false);
	const [schedulingDowngradePlanCode, setSchedulingDowngradePlanCode] = useState<string | null>(null);
	const [showDowngradeModal, setShowDowngradeModal] = useState(false);
	const [selectedDowngradePlan, setSelectedDowngradePlan] = useState<PremiumPlan | null>(null);

	useEffect(() => {
		const loadPremiumData = async () => {
			try {
				const [plansResponse, subscriptionResponse] = await Promise.all([
					axios.get('/api/shop-owner/premium/plans', { withCredentials: true }),
					axios.get('/api/shop-owner/premium/subscription', { withCredentials: true }),
				]);

				setPlans(plansResponse.data?.plans ?? []);
				setSubscription(subscriptionResponse.data?.subscription ?? null);
				setError(null);
			} catch (err: any) {
				setError(err?.response?.data?.message || 'Failed to load premium plans.');
			} finally {
				setLoading(false);
			}
		};

		loadPremiumData();
	}, []);

	useEffect(() => {
		if (!showCancelModal && !showUpgradeModal && !showDowngradeModal) return;

		const onKeyDown = (event: KeyboardEvent) => {
			if (event.key === 'Escape') {
				setShowCancelModal(false);
				setShowUpgradeModal(false);
				setShowDowngradeModal(false);
			}
		};

		document.body.style.overflow = 'hidden';
		window.addEventListener('keydown', onKeyDown);

		return () => {
			document.body.style.overflow = '';
			window.removeEventListener('keydown', onKeyDown);
		};
	}, [showCancelModal, showUpgradeModal, showDowngradeModal]);

	const now = new Date();
	const hasRemainingAccess = (sub: PremiumSubscription | null) => {
		if (!sub) return false;
		if (!['active', 'cancelled'].includes(sub.status)) return false;
		if (!sub.ends_at) return sub.status === 'active';
		return new Date(sub.ends_at).getTime() >= now.getTime();
	};
	const activeSubscription = hasRemainingAccess(subscription) ? subscription : null;
	const formatCurrency = (value: string | number) => `₱${Number(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
	const getDurationLabel = (days: number) => (days === 30 ? '1 month' : `${days} days`);
	const getPlanPrice = (plan: PremiumPlan) => Number(plan.price ?? 0);
	const currentPlanCode = activeSubscription?.plan_code ? String(activeSubscription.plan_code).toLowerCase() : null;
	const currentPlan = plans.find((plan) => String(plan.plan_code).toLowerCase() === currentPlanCode) || null;
	const pendingPlanCode =
		subscription?.pendingPremiumPlan?.plan_code ||
		subscription?.pending_premium_plan?.plan_code ||
		null;
	const pendingPlanName =
		subscription?.pendingPremiumPlan?.name ||
		subscription?.pending_premium_plan?.name ||
		null;

	const refreshSubscription = async () => {
		const response = await axios.get('/api/shop-owner/premium/subscription', { withCredentials: true });
		setSubscription(response.data?.subscription ?? null);
	};

	const handleUpgradePreview = async (plan: PremiumPlan) => {
		setLoadingPlanAction(plan.plan_code);
		setError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.post(
				'/api/shop-owner/premium/upgrade',
				{ new_plan_id: plan.id },
				{ withCredentials: true, headers: { 'X-CSRF-TOKEN': csrfToken || '' } },
			);

			setUpgradePreview(response.data as UpgradePreview);
			setShowUpgradeModal(true);
		} catch (err: any) {
			setError(err?.response?.data?.message || 'Unable to preview upgrade pricing.');
		} finally {
			setLoadingPlanAction(null);
		}
	};

	const handleConfirmUpgrade = async () => {
		if (!upgradePreview) return;

		setConfirmingUpgrade(true);
		setError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.post(
				'/api/shop-owner/premium/confirm-upgrade',
				{ new_plan_id: upgradePreview.new_plan.id },
				{ withCredentials: true, headers: { 'X-CSRF-TOKEN': csrfToken || '' } },
			);

			const checkoutUrl = response.data?.checkout_url;
			if (checkoutUrl) {
				window.location.href = checkoutUrl;
				return;
			}

			setShowUpgradeModal(false);
			setUpgradePreview(null);
			await refreshSubscription();
		} catch (err: any) {
			setError(err?.response?.data?.message || 'Unable to confirm upgrade.');
		} finally {
			setConfirmingUpgrade(false);
		}
	};

	const handleScheduleDowngrade = async (plan: PremiumPlan) => {
		setSchedulingDowngradePlanCode(plan.plan_code);
		setError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.post(
				'/api/shop-owner/premium/schedule-downgrade',
				{ new_plan_id: plan.id },
				{ withCredentials: true, headers: { 'X-CSRF-TOKEN': csrfToken || '' } },
			);

			setSubscription(response.data?.subscription ?? null);
			return true;
		} catch (err: any) {
			setError(err?.response?.data?.message || 'Unable to schedule downgrade.');
			return false;
		} finally {
			setSchedulingDowngradePlanCode(null);
		}
	};

	const openDowngradeModal = (plan: PremiumPlan) => {
		setSelectedDowngradePlan(plan);
		setShowDowngradeModal(true);
	};

	const closeDowngradeModal = () => {
		if (selectedDowngradePlan && schedulingDowngradePlanCode === selectedDowngradePlan.plan_code) return;
		setShowDowngradeModal(false);
		setSelectedDowngradePlan(null);
	};

	const handleConfirmDowngrade = async () => {
		if (!selectedDowngradePlan) return;
		const scheduled = await handleScheduleDowngrade(selectedDowngradePlan);
		if (!scheduled) return;

		setShowDowngradeModal(false);
		setSelectedDowngradePlan(null);
	};
	const handleCheckout = async (planCode: string) => {
		setCheckoutPlan(planCode);
		setError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.post(
				'/api/shop-owner/premium/checkout',
				{ plan_code: planCode },
				{
					withCredentials: true,
					headers: { 'X-CSRF-TOKEN': csrfToken || '' },
				},
			);

			const checkoutUrl = response.data?.checkout_url;
			if (checkoutUrl) {
				window.location.href = checkoutUrl;
				return;
			}

			setError('Payment gateway did not return a checkout URL.');
		} catch (err: any) {
			setError(err?.response?.data?.message || 'Unable to start premium checkout.');
		} finally {
			setCheckoutPlan(null);
		}
	};

	const handleCancelSubscription = async () => {
		if (subscription?.status === 'cancelled') return;
		if (!cancelReason) {
			setCancelReasonError('Please select a reason before continuing.');
			return;
		}

		const isOthers = cancelReason === 'others';
		if (isOthers && !cancelReasonNotes.trim()) {
			setCancelReasonError('Please add notes when you select Others.');
			return;
		}

		setCancellingSubscription(true);
		setCancelReasonError(null);
		setError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.post(
				'/api/shop-owner/premium/cancel',
				{
					subscription_id: activeSubscription?.id ?? undefined,
					cancellation_reason: cancelReason,
					cancellation_notes: isOthers ? cancelReasonNotes.trim() : undefined,
				},
				{
					withCredentials: true,
					headers: { 'X-CSRF-TOKEN': csrfToken || '' },
				},
			);

			setSubscription(response.data?.subscription ?? null);
			setShowCancelModal(false);
			setCancelReason('');
			setCancelReasonNotes('');
		} catch (err: any) {
			setError(err?.response?.data?.message || 'Unable to cancel premium subscription.');
		} finally {
			setCancellingSubscription(false);
		}
	};

	const openCancelModal = () => {
		setCancelReason('');
		setCancelReasonNotes('');
		setCancelReasonError(null);
		setShowCancelModal(true);
	};

	const closeUpgradeModal = () => {
		if (confirmingUpgrade) return;
		setShowUpgradeModal(false);
		setUpgradePreview(null);
	};

	const currentPlanPrice = Number(currentPlan?.price ?? 0);
	const selectedDowngradePrice = Number(selectedDowngradePlan?.price ?? 0);
	const downgradeSavings = Math.max(0, currentPlanPrice - selectedDowngradePrice);
	const estimatedDowngradeDate = activeSubscription?.ends_at
		? new Date(activeSubscription.ends_at).toLocaleDateString()
		: 'the end of your current cycle';

	return (
		<>
			<Head title="Premium Benefits" />
			<div className="min-h-screen bg-white font-outfit antialiased">
				<div className="mx-auto max-w-480 px-6 pb-12 pt-24 lg:px-12 lg:pb-20 lg:pt-28">
					<section className="w-full bg-white">
						<div className="mb-8 text-[11px] uppercase tracking-[0.18em] text-black/55 sm:text-xs">Shop Owner / Premium Benefits</div>
						<div className="mb-10">
								<Link
									href="/shop-owner/settings"
									className="inline-flex items-center rounded-full border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-[0.14em] text-black/70 transition hover:border-gray-400 hover:text-black"
								>
									Back
								</Link>
						</div>
						<div className="mb-14">
							<h1 className="mb-4 text-4xl font-bold uppercase tracking-tight text-black sm:text-5xl lg:text-6xl">
								Premium Benefits
							</h1>
							<p className="mb-2 max-w-3xl text-base font-light leading-relaxed text-black/65">
								Unlock exclusive advantages designed for retail-capable shops that want access to the virtual showroom.
							</p>
							<p className="text-xs font-semibold uppercase tracking-[0.16em] text-black/40">
								Exclusive for Retail and Retail-Repair Shop Owners
							</p>
						</div>

						{subscription?.status === 'deactivated' ? (
							<div className="mb-10 overflow-hidden rounded-3xl border border-[#16233b]/20 bg-linear-to-r from-[#16233b]/8 via-[#16233b]/4 to-transparent shadow-[0_22px_40px_-28px_rgba(15,23,42,0.6)]">
								<div className="flex items-start gap-4 p-6 sm:p-7">
									<div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#16233b] text-white">
										<svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z" />
										</svg>
									</div>
									<div>
										<p className="text-xs font-semibold uppercase tracking-[0.16em] text-[#16233b]/75">Subscription Update</p>
										<div className="mt-2 space-y-3 text-sm leading-relaxed text-[#0f1b33]">
											<p>Dear Valued Customer,</p>
											<p>
												We would like to inform you that your subscription has been temporarily deactivated as part of an ongoing account and policy review process. This action is taken to ensure compliance with our terms and to maintain the integrity and security of our services.
											</p>
											<p>
												If you believe this action has been taken in error or if you require further clarification, we encourage you to reach out to our support team. You may contact us at <a href="mailto:solespace@gmail.com" className="font-semibold underline underline-offset-2">SOLESPACE@GMAIL.COM</a>, and we will be happy to assist you promptly.
											</p>
											<p>
												We appreciate your understanding and cooperation while we complete this review. Thank you for your patience.
											</p>
											<p>
												Sincerely,<br />
												Customer Support Team
											</p>
										</div>
										{subscription.cancellation_notes ? (
											<p className="mt-3 rounded-xl border border-[#16233b]/15 bg-white/80 px-3 py-2 text-sm text-[#0f1b33]">
												<span className="font-semibold">Admin message:</span> {subscription.cancellation_notes}
											</p>
										) : null}
									</div>
								</div>
							</div>
						) : null}

							{loading ? (
								<div className="mb-10 rounded-2xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-700">
									<p>Loading premium plans and subscription status...</p>
								</div>
							) : null}
							{error ? (
								<div className="mb-10 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm font-medium text-red-700">{error}</div>
							) : null}

							<div className="mb-16 grid grid-cols-1 gap-6 md:grid-cols-3 lg:gap-8">
								{plans.map((plan) => {
									const isCurrentPlan =
										Boolean(activeSubscription?.plan_code) &&
										String(activeSubscription?.plan_code).toLowerCase() === String(plan.plan_code).toLowerCase();
									const hasActiveSubscription = Boolean(activeSubscription);
									const currentPrice = currentPlan ? getPlanPrice(currentPlan) : 0;
									const selectedPrice = getPlanPrice(plan);
									const isUpgradeOption = hasActiveSubscription && !isCurrentPlan && selectedPrice > currentPrice;
									const isDowngradeOption = hasActiveSubscription && !isCurrentPlan && selectedPrice < currentPrice;
									const canCheckoutThisPlan = !activeSubscription && !checkoutPlan;
									const canClickPlanAction =
										(!!canCheckoutThisPlan) ||
										isUpgradeOption ||
										isDowngradeOption;
									const shouldShowShowroomButton = isCurrentPlan && Boolean(virtualShowroomHref);
									const isPlanLoading =
										checkoutPlan === plan.plan_code ||
										loadingPlanAction === plan.plan_code ||
										schedulingDowngradePlanCode === plan.plan_code;
									const isPlanPendingDowngrade =
										Boolean(pendingPlanCode) &&
										String(pendingPlanCode).toLowerCase() === String(plan.plan_code).toLowerCase();
									const planButtonLabel = isPlanLoading
										? isUpgradeOption
											? 'Calculating...'
											: isDowngradeOption
												? 'Scheduling...'
												: 'Starting Checkout...'
										: isCurrentPlan
											? 'View Virtual Showroom'
											: isUpgradeOption
												? 'Upgrade Now'
												: isDowngradeOption
													? (isPlanPendingDowngrade ? 'Downgrade Scheduled' : 'Schedule Downgrade')
													: 'Subscribe Now';
									return (
										<div
											key={plan.plan_code}
											className={`group flex h-full flex-col rounded-3xl border bg-white shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:shadow-[0_28px_48px_-24px_rgba(15,23,42,0.55)] ${
												isCurrentPlan ? 'border-[#16233b]' : 'border-gray-300 hover:border-gray-400'
											}`}
										>
											<div className="border-b border-gray-200 p-8">
												<span
													className={`mb-3 inline-flex rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ${
														isCurrentPlan
															? 'border-[#16233b] bg-[#16233b] text-white'
															: 'invisible border-gray-200 text-transparent'
													}`}
												>
													Current Plan
												</span>
												<h3 className="mb-3 text-xs font-bold uppercase tracking-[0.18em] text-black/50">{plan.name}</h3>
												<div className="mb-4 text-5xl font-bold tracking-tight text-black">{formatCurrency(plan.price)}</div>
												<p className="mb-5 text-sm leading-relaxed text-black/60">{plan.description || 'Premium showroom access for your shop.'}</p>
												<div className="min-h-20">
													{isCurrentPlan ? (
														<div className="rounded-xl border border-[#16233b]/20 bg-[#16233b]/5 px-4 py-3">
															<p className="text-xs font-semibold uppercase tracking-[0.14em] text-[#16233b]">Plan Status</p>
															{subscription?.status === 'cancelled' ? (
																<p className="mt-1 text-xs text-black/65">
																	Your subscription is cancelled and will stay active until {subscription?.ends_at ? new Date(subscription.ends_at).toLocaleDateString() : 'the end of your current cycle'}.
																</p>
															) : subscription?.status === 'deactivated' ? (
																<p className="mt-1 text-xs text-black/65">
																	This plan is currently deactivated by admin. Please review the subscription notice for full details.
																</p>
															) : (
																<p className="mt-1 text-xs text-black/65">
																	Your subscription will automatically renew at the end of each billing period unless you cancel before the renewal date.
																</p>
															)}
															{pendingPlanName ? (
																<p className="mt-2 text-xs font-medium text-[#16233b]">
																	Downgrade scheduled to {pendingPlanName} on {subscription?.pending_plan_effective_at ? new Date(subscription.pending_plan_effective_at).toLocaleDateString() : 'your next cycle'}.
																</p>
															) : null}
														</div>
													) : null}
												</div>
											</div>
											<div className="flex grow flex-col p-8">
												<ul className="mb-8 grow space-y-3.5">
													<li className="flex items-start gap-3">{checkIcon}<span className="text-sm leading-snug text-black/65">{getDurationLabel(plan.duration_days)} access to the virtual showroom</span></li>
													<li className="flex items-start gap-3">{checkIcon}<span className="text-sm leading-snug text-black/65">Display capacity: up to {plan.showroom_slot_limit} shoe slots in your showroom</span></li>
													<li className="flex items-start gap-3">{checkIcon}<span className="text-sm leading-snug text-black/65">View shoes in horizontal detail inside the showroom</span></li>
													<li className="flex items-start gap-3">{checkIcon}<span className="text-sm leading-snug text-black/65">Enable image-sequence uploads for showroom presentation</span></li>
												</ul>
												{shouldShowShowroomButton ? (
													<Link href={virtualShowroomHref as string} className={`${actionBtnBase} ${actionBtnDark}`}>
														{planButtonLabel}
														<svg className="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
															<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
														</svg>
													</Link>
												) : (
													<button
														type="button"
														onClick={() => {
															if (canCheckoutThisPlan) {
																handleCheckout(plan.plan_code);
																return;
															}

															if (isUpgradeOption) {
																handleUpgradePreview(plan);
																return;
															}

															if (isDowngradeOption && !isPlanPendingDowngrade) {
																openDowngradeModal(plan);
															}
														}}
														disabled={!canClickPlanAction || isPlanLoading || isPlanPendingDowngrade}
														className={`${actionBtnBase} ${
															isCurrentPlan
																? `${actionBtnDark} cursor-default`
																: !canClickPlanAction || isPlanPendingDowngrade
																	? 'cursor-not-allowed border border-gray-300 bg-gray-100 text-gray-400'
																	: actionBtnDark
														}`}
													>
														{planButtonLabel}
														<svg className="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
															<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
														</svg>
													</button>
												)}
												{isCurrentPlan && subscription?.status !== 'cancelled' ? (
													<button
														type="button"
														onClick={openCancelModal}
														disabled={cancellingSubscription}
														className="mt-3 block w-full rounded-full border border-[#16233b]/30 bg-white px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.14em] text-[#16233b] transition hover:border-[#16233b] hover:bg-[#16233b]/5 disabled:cursor-not-allowed disabled:opacity-60"
													>
														{cancellingSubscription ? 'Cancelling...' : 'Cancel Premium'}
													</button>
												) : activeSubscription ? (
													<div className="mt-3 h-10.5" aria-hidden="true" />
												) : (
													<div className="mt-3 h-10.5" aria-hidden="true" />
												)}
											</div>
										</div>
									);
								})}
								{!loading && plans.length === 0 ? (
									<div className="rounded-2xl border border-gray-200 bg-gray-50 p-8 text-center text-gray-700 md:col-span-3">
										No premium plans are available right now. Please contact the administrator or try again later.
									</div>
								) : null}
							</div>

							<div className="mb-16 rounded-3xl border border-gray-300 bg-white p-8 shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] lg:p-12">
								<div className="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:items-center">
									<div>
										<p className="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-black/45">How It Works</p>
										<h3 className="mb-5 text-3xl font-bold uppercase tracking-tight text-black lg:text-4xl">What is a Virtual Showroom?</h3>
										<p className="mb-4 text-sm leading-relaxed text-black/65">The Virtual Showroom is an interactive online display space where shop owners can showcase their shoes and customers can explore them in a more engaging way. Instead of browsing a simple product list, customers experience a digital showroom that feels closer to visiting a real store.</p>
										<p className="mb-3 text-sm leading-relaxed text-black/65">Shop owners can display multiple products depending on their plan:</p>
										<ul className="mb-4 space-y-1.5 text-sm text-black/65">
											{plans.map((plan) => (
												<li key={plan.plan_code}><span className="font-semibold text-black">{plan.name}:</span> Up to {plan.showroom_slot_limit} display slots</li>
											))}
										</ul>
										<p className="mb-4 text-sm leading-relaxed text-black/65">Each slot allows you to upload and showcase a shoe inside the virtual showroom, helping customers easily discover and browse your collection.</p>
										<p className="mb-4 text-sm leading-relaxed text-black/65">Customers can swipe left or right to view shoes horizontally, allowing them to see different sides of the product and better appreciate its design and details. The showroom also includes Day Mode and Night Mode, giving users the option to switch lighting environments for a more comfortable viewing experience.</p>
										<p className="text-sm leading-relaxed text-black/65">This interactive experience helps customers examine products more closely while helping shop owners present their shoes in a modern, visually appealing, and immersive storefront.</p>
										<div className="mt-28 flex justify-center lg:mt-32">
											<Link
												href="/services/product-image-spin-tutorial?from=premium-benefits"
												className={`${tutorialBtnBase} ${tutorialBtnLight} px-8 py-3.5 text-xs sm:px-10 sm:py-4 sm:text-sm`}
											>
												Product Image Spin Tutorial
											</Link>
										</div>
									</div>
									<div className="grid grid-cols-1 gap-4">
										<div className="overflow-hidden rounded-2xl border border-gray-200">
											<img src="/images/SHOWROOM/image.png" alt="Virtual showroom interior overview" className="h-full w-full object-cover" />
										</div>
										<div className="overflow-hidden rounded-2xl border border-gray-200">
											<img src="/images/SHOWROOM/image2.png" alt="Virtual showroom display slots example" className="h-full w-full object-cover" />
										</div>
									</div>
								</div>
							</div>
						</section>
				</div>
			</div>

				{showUpgradeModal && upgradePreview ? (
					<div className="fixed inset-0 z-2000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-[2px]">
						<div className="w-full max-w-2xl rounded-3xl border border-gray-200 bg-white p-6 shadow-[0_30px_65px_-30px_rgba(15,23,42,0.65)] sm:p-7" role="dialog" aria-modal="true" aria-labelledby="upgrade-modal-title">
							<div className="mb-5 flex items-start justify-between gap-4 border-b border-gray-200 pb-5">
								<div>
									<h2 id="upgrade-modal-title" className="text-2xl font-bold tracking-tight text-black">Upgrade to {upgradePreview.new_plan.name}</h2>
									<p className="mt-1.5 text-sm leading-relaxed text-black/65">
										Your upgrade takes effect immediately after payment is completed.
									</p>
								</div>
								<button
									type="button"
									onClick={closeUpgradeModal}
									disabled={confirmingUpgrade}
									className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-300 text-black/70 transition hover:border-gray-400 hover:text-black disabled:cursor-not-allowed disabled:opacity-60"
									aria-label="Close upgrade modal"
								>
									<svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
										<path strokeLinecap="round" strokeLinejoin="round" d="M6 6l12 12M18 6L6 18" />
									</svg>
								</button>
							</div>

							<div className="space-y-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Current Plan</span><span className="font-semibold text-black">{upgradePreview.current_plan.name}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Remaining Days</span><span className="font-semibold text-black">{Number(upgradePreview.remaining_days || 0).toFixed(2)} days</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">New Plan Price</span><span className="font-semibold text-black">{formatCurrency(upgradePreview.new_plan_price)}</span></div>
								{typeof upgradePreview.daily_rate === 'number' ? (
									<div className="flex items-center justify-between gap-3"><span className="text-black/65">Current Plan Daily Rate</span><span className="font-semibold text-black">{formatCurrency(upgradePreview.daily_rate)}</span></div>
								) : null}
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Remaining Value Credit</span><span className="font-semibold text-emerald-700">{formatCurrency(Math.max(0, upgradePreview.remaining_value))}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Credit Computation</span><span className="font-semibold text-black/75">{typeof upgradePreview.daily_rate === 'number' ? `${formatCurrency(upgradePreview.daily_rate)} x ${Number(upgradePreview.remaining_days || 0).toFixed(2)} days` : `${Number(upgradePreview.remaining_days || 0).toFixed(2)} days`}</span></div>
								<div className="flex items-center justify-between gap-3 border-t border-gray-200 pt-3"><span className="text-black/65">You Pay Today</span><span className="text-lg font-bold text-black">{formatCurrency(upgradePreview.final_price)}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">New Expiry</span><span className="font-semibold text-black">{new Date(upgradePreview.new_expiry).toLocaleDateString()}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Slot Increase</span><span className="font-semibold text-black">+{upgradePreview.slot_delta} slots ({upgradePreview.current_plan.showroom_slot_limit} → {upgradePreview.new_plan.showroom_slot_limit})</span></div>
							</div>

							<div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
								<button
									type="button"
									onClick={closeUpgradeModal}
									disabled={confirmingUpgrade}
									className="rounded-full border border-gray-300 px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.12em] text-black/70 transition hover:border-gray-400 hover:text-black disabled:cursor-not-allowed disabled:opacity-60"
								>
									Cancel
								</button>
								<button
									type="button"
									onClick={handleConfirmUpgrade}
									disabled={confirmingUpgrade}
									className="rounded-full border border-[#16233b] bg-[#16233b] px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.12em] text-white transition hover:-translate-y-0.5 hover:bg-black disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:opacity-60"
								>
									{confirmingUpgrade ? 'Processing...' : 'Confirm Upgrade'}
								</button>
							</div>
						</div>
					</div>
				) : null}

				{showDowngradeModal && selectedDowngradePlan ? (
					<div className="fixed inset-0 z-2000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-[2px]">
						<div className="w-full max-w-2xl rounded-3xl border border-gray-200 bg-white p-6 shadow-[0_30px_65px_-30px_rgba(15,23,42,0.65)] sm:p-7" role="dialog" aria-modal="true" aria-labelledby="downgrade-modal-title">
							<div className="mb-5 flex items-start justify-between gap-4 border-b border-gray-200 pb-5">
								<div>
									<h2 id="downgrade-modal-title" className="text-2xl font-bold tracking-tight text-black">Schedule Downgrade to {selectedDowngradePlan.name}?</h2>
									<p className="mt-1.5 text-sm leading-relaxed text-black/65">
										Your current plan stays active for now. Downgrade will apply on {estimatedDowngradeDate}.
									</p>
								</div>
								<button
									type="button"
									onClick={closeDowngradeModal}
									disabled={schedulingDowngradePlanCode === selectedDowngradePlan.plan_code}
									className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-300 text-black/70 transition hover:border-gray-400 hover:text-black disabled:cursor-not-allowed disabled:opacity-60"
									aria-label="Close downgrade modal"
								>
									<svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
										<path strokeLinecap="round" strokeLinejoin="round" d="M6 6l12 12M18 6L6 18" />
									</svg>
								</button>
							</div>

							<div className="space-y-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Current Plan</span><span className="font-semibold text-black">{currentPlan?.name || 'Current plan'}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">New Plan</span><span className="font-semibold text-black">{selectedDowngradePlan.name}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Current Price</span><span className="font-semibold text-black">{formatCurrency(currentPlanPrice)}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">New Price (next cycle)</span><span className="font-semibold text-black">{formatCurrency(selectedDowngradePrice)}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Estimated Savings per cycle</span><span className="font-semibold text-emerald-700">{formatCurrency(downgradeSavings)}</span></div>
								<div className="flex items-center justify-between gap-3"><span className="text-black/65">Slot Capacity Change</span><span className="font-semibold text-black">{currentPlan?.showroom_slot_limit ?? '-'} → {selectedDowngradePlan.showroom_slot_limit}</span></div>
							</div>

							<div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
								<button
									type="button"
									onClick={closeDowngradeModal}
									disabled={schedulingDowngradePlanCode === selectedDowngradePlan.plan_code}
									className="rounded-full border border-gray-300 px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.12em] text-black/70 transition hover:border-gray-400 hover:text-black disabled:cursor-not-allowed disabled:opacity-60"
								>
									Cancel
								</button>
								<button
									type="button"
									onClick={handleConfirmDowngrade}
									disabled={schedulingDowngradePlanCode === selectedDowngradePlan.plan_code}
									className="rounded-full border border-[#16233b] bg-[#16233b] px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.12em] text-white transition hover:-translate-y-0.5 hover:bg-black disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:opacity-60"
								>
									{schedulingDowngradePlanCode === selectedDowngradePlan.plan_code ? 'Scheduling...' : 'Confirm Downgrade'}
								</button>
							</div>
						</div>
					</div>
				) : null}

				{showCancelModal ? (
					<div className="fixed inset-0 z-2000 flex items-center justify-center bg-black/60 p-4 backdrop-blur-[2px]">
						<div className="w-full max-w-4xl rounded-3xl border border-gray-200 bg-white p-6 shadow-[0_30px_65px_-30px_rgba(15,23,42,0.65)] sm:p-7" role="dialog" aria-modal="true" aria-labelledby="cancel-premium-title">
							<div className="mb-5 flex items-start justify-between gap-4 border-b border-gray-200 pb-5">
								<div>
									<h2 id="cancel-premium-title" className="text-2xl font-bold tracking-tight text-black">Cancel Premium Subscription?</h2>
									<p className="mt-1.5 text-sm leading-relaxed text-black/65">
										Help us improve by sharing your reason for cancellation.
									</p>
								</div>
								<button
									type="button"
									onClick={() => setShowCancelModal(false)}
									className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-300 text-black/70 transition hover:border-gray-400 hover:text-black"
									aria-label="Close cancellation modal"
								>
									<svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
										<path strokeLinecap="round" strokeLinejoin="round" d="M6 6l12 12M18 6L6 18" />
									</svg>
								</button>
							</div>

							<div className="mb-6 rounded-2xl border border-[#16233b]/20 bg-[#16233b]/5 p-4 text-sm text-[#16233b]">
								<p className="font-semibold uppercase tracking-[0.12em]">What happens after cancellation</p>
								<p className="mt-2 leading-relaxed">
									Your subscription remains active until the end of your current billing period. You will not be charged on the next billing date, and the current payment is non-refundable.
								</p>
							</div>

							<div className="space-y-2.5">
								<p className="text-xs font-semibold uppercase tracking-[0.14em] text-black/60">Reason for cancellation</p>
								{cancellationReasons.map((reason) => (
									<label
										key={reason.value}
										className={`flex cursor-pointer items-start gap-3 rounded-2xl border px-4 py-3.5 text-sm transition ${
											cancelReason === reason.value
												? 'border-[#16233b] bg-[#16233b]/5 text-black shadow-[0_10px_20px_-18px_rgba(22,35,59,0.9)]'
												: 'border-gray-200 text-black/80 hover:border-gray-300'
										}`}
									>
										<input
											type="radio"
											name="cancel-reason"
											value={reason.value}
											checked={cancelReason === reason.value}
											onChange={(e) => {
												setCancelReason(e.target.value);
												setCancelReasonError(null);
											}}
											className="mt-0.5 h-4 w-4 border-gray-400 text-[#16233b] focus:ring-[#16233b]"
										/>
										<span className="leading-relaxed">{reason.label}</span>
									</label>
								))}
							</div>

							{cancelReason === 'others' ? (
								<div className="mt-4">
									<label htmlFor="cancel-notes" className="mb-2 block text-xs font-semibold uppercase tracking-[0.14em] text-black/60">
										Additional Notes
									</label>
									<textarea
										id="cancel-notes"
										value={cancelReasonNotes}
										onChange={(e) => {
											setCancelReasonNotes(e.target.value);
											setCancelReasonError(null);
										}}
										rows={4}
										placeholder="Please share your reason in more detail..."
										className="w-full rounded-2xl border border-gray-300 px-4 py-3 text-sm text-black outline-none transition focus:border-[#16233b] focus:ring-2 focus:ring-[#16233b]/15"
									/>
								</div>
							) : null}

							{cancelReasonError ? (
								<p className="mt-3 text-sm font-medium text-red-700">{cancelReasonError}</p>
							) : null}

							<div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
								<button
									type="button"
									onClick={() => setShowCancelModal(false)}
									className="rounded-full border border-gray-300 px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.12em] text-black/70 transition hover:border-gray-400 hover:text-black"
								>
									Keep Subscription
								</button>
								<button
									type="button"
									onClick={handleCancelSubscription}
									disabled={cancellingSubscription}
									className="rounded-full border border-[#8f1212] bg-[#b91c1c] px-5 py-2.5 text-xs font-semibold uppercase tracking-[0.12em] text-white transition hover:-translate-y-0.5 hover:bg-[#9f1616] disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:opacity-60"
								>
									{cancellingSubscription ? 'Cancelling...' : 'Confirm Cancellation'}
								</button>
							</div>
						</div>
					</div>
				) : null}
		</>
	);
};

export default PremiumBenefits;
