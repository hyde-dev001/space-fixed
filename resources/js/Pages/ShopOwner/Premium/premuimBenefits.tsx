import React, { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';

type PremiumPlan = {
	plan_code: string;
	name: string;
	description: string | null;
	price: string | number;
	duration_days: number;
	showroom_slot_limit: number;
};

type PremiumSubscription = {
	id: number;
	status: 'pending' | 'active' | 'expired' | 'cancelled' | 'failed';
	plan_code: string | null;
	showroom_slot_limit: number | null;
	starts_at: string | null;
	ends_at: string | null;
	premiumPlan?: {
		name?: string | null;
	} | null;
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
	const [plans, setPlans] = useState<PremiumPlan[]>([]);
	const [subscription, setSubscription] = useState<PremiumSubscription | null>(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [checkoutPlan, setCheckoutPlan] = useState<string | null>(null);
	const [cancellingSubscription, setCancellingSubscription] = useState(false);

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

	const activeSubscription = subscription?.status === 'active' ? subscription : null;
	const formatCurrency = (value: string | number) => `₱${Number(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
	const getDurationLabel = (days: number) => (days === 30 ? '1 month' : `${days} days`);
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
		setCancellingSubscription(true);
		setError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.post(
				'/api/shop-owner/premium/cancel',
				{ subscription_id: activeSubscription?.id ?? undefined },
				{
					withCredentials: true,
					headers: { 'X-CSRF-TOKEN': csrfToken || '' },
				},
			);

			setSubscription(response.data?.subscription ?? null);
		} catch (err: any) {
			setError(err?.response?.data?.message || 'Unable to cancel premium subscription.');
		} finally {
			setCancellingSubscription(false);
		}
	};

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
									const canCheckoutThisPlan = !activeSubscription && !checkoutPlan;
									const planButtonLabel = checkoutPlan === plan.plan_code
										? 'Starting Checkout...'
										: isCurrentPlan
											? 'Current Plan'
											: activeSubscription
												? 'Active Subscription'
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
															<p className="mt-1 text-xs text-black/65">
																Your subscription will automatically renew at the end of each billing period unless you cancel before the renewal date.
															</p>
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
												<button
													type="button"
													onClick={() => {
														if (canCheckoutThisPlan) {
															handleCheckout(plan.plan_code);
														}
													}}
													disabled={!canCheckoutThisPlan}
													className={`${actionBtnBase} ${
														isCurrentPlan
															? `${actionBtnDark} cursor-default`
															: activeSubscription
																? 'cursor-not-allowed border border-gray-300 bg-gray-100 text-gray-400'
																: actionBtnDark
													}`}
												>
													{planButtonLabel}
													<svg className="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
														<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
													</svg>
												</button>
												{isCurrentPlan ? (
													<button
														type="button"
														onClick={handleCancelSubscription}
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
		</>
	);
};

export default PremiumBenefits;
