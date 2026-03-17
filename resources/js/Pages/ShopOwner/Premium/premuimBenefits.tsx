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

const PremiumBenefits: React.FC<Props> = () => {
	const [plans, setPlans] = useState<PremiumPlan[]>([]);
	const [subscription, setSubscription] = useState<PremiumSubscription | null>(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [checkoutPlan, setCheckoutPlan] = useState<string | null>(null);

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
	const pendingSubscription = subscription?.status === 'pending' ? subscription : null;
	const formatCurrency = (value: string | number) => `₱${Number(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
	const formatDate = (value: string | null) => {
		if (!value) return null;
		const date = new Date(value);
		if (Number.isNaN(date.getTime())) return null;
		return date.toLocaleDateString(undefined, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
		});
	};
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

	return (
		<>
			<Head title="Premium Benefits" />
			<div className="min-h-screen bg-white font-outfit antialiased">
				<div className="mx-auto max-w-480 px-6 lg:px-12">
					<section className="w-full bg-white py-24 lg:py-32">
						<div className="mx-auto max-w-480 px-6 lg:px-12">
							<div className="mb-10">
								<Link
									href="/shop-owner/settings"
									className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 hover:text-gray-900"
								>
									Back
								</Link>
							</div>
							<div className="mb-20">
								<h2 className="mb-6 text-5xl font-bold tracking-tight text-black lg:text-7xl">PREMIUM BENEFITS</h2>
								<p className="mb-8 max-w-2xl text-xl font-light leading-relaxed text-black/70">
									Unlock exclusive advantages designed for retail-capable shops that want access to the virtual showroom.
								</p>
								<p className="text-sm font-semibold uppercase tracking-wider text-black/50">Exclusive for Retail and Retail-Repair Shop Owners</p>
							</div>

							<div className="mb-10 rounded-2xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-700">
								{loading ? (
									<p>Loading premium plans and subscription status...</p>
								) : activeSubscription ? (
									<>
										<p className="font-semibold text-gray-900">
											Current plan: {activeSubscription.premiumPlan?.name || activeSubscription.plan_code || 'Premium'}
										</p>
										<p className="mt-1 text-gray-600">
											Active until {formatDate(activeSubscription.ends_at) || 'your subscription end date'}
											{activeSubscription.showroom_slot_limit ? ` • ${activeSubscription.showroom_slot_limit} showroom slots` : ''}
										</p>
									</>
								) : pendingSubscription ? (
									<>
										<p className="font-semibold text-gray-900">Premium payment pending</p>
										<p className="mt-1 text-gray-600">Your premium checkout has been created. Activation will happen after payment confirmation.</p>
									</>
								) : (
									<p>No active premium subscription yet. Choose a plan below to unlock the virtual showroom.</p>
								)}
								{error ? <p className="mt-3 text-sm font-medium text-red-600">{error}</p> : null}
							</div>

							<div className="mb-16 grid grid-cols-1 gap-8 md:grid-cols-3 lg:gap-12">
								{plans.map((plan) => {
									const isCurrentPlan = activeSubscription?.plan_code === plan.plan_code;
									const disableCheckout = Boolean(activeSubscription || pendingSubscription || checkoutPlan);
									return (
										<div key={plan.plan_code} className="group flex h-full flex-col overflow-hidden border-2 border-black bg-white transition-all duration-300 hover:shadow-2xl">
											<div className="border-b-2 border-black p-8">
												<h3 className="mb-4 text-2xl font-bold uppercase tracking-wide text-black">{plan.name}</h3>
												<div className="mb-6 text-5xl font-bold text-black">{formatCurrency(plan.price)}</div>
												<p className="mb-6 text-sm leading-relaxed text-black/70">{plan.description || 'Premium showroom access for your shop.'}</p>
											</div>
											<div className="grow p-8">
												<ul className="mb-8 space-y-4">
													<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">{getDurationLabel(plan.duration_days)} access to the virtual showroom</span></li>
													<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">Display capacity: up to {plan.showroom_slot_limit} shoe slots in your showroom</span></li>
													<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">View shoes in horizontal detail inside the showroom</span></li>
													<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">Enable image-sequence uploads for showroom presentation</span></li>
												</ul>
												<button
													type="button"
													onClick={() => handleCheckout(plan.plan_code)}
													disabled={disableCheckout}
													className="block w-full bg-black px-6 py-4 text-center text-sm font-semibold uppercase tracking-wider text-white transition-colors hover:bg-black/80 disabled:cursor-not-allowed disabled:bg-gray-400"
												>
													{checkoutPlan === plan.plan_code
														? 'Starting Checkout...'
														: isCurrentPlan
															? 'Current Plan'
															: pendingSubscription
																? 'Payment Pending'
																: activeSubscription
																	? 'Active Subscription'
																	: 'Subscribe Now'}
												</button>
											</div>
										</div>
									);
								})}
								{!loading && plans.length === 0 ? (
									<div className="md:col-span-3 rounded-2xl border border-gray-200 bg-gray-50 p-8 text-center text-gray-700">
										No premium plans are available right now. Please contact the administrator or try again later.
									</div>
								) : null}
							</div>

							<div className="mb-16 border-2 border-black bg-white p-6 lg:p-8">
								<div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-center">
									<div>
										<p className="mb-2 text-xs font-semibold uppercase tracking-wider text-black/60">How It Works</p>
										<h3 className="mb-3 text-3xl font-bold tracking-tight text-black">What is a Virtual Showroom?</h3>
										<p className="mb-3 text-black/70">The Virtual Showroom is an interactive online display space where shop owners can showcase their shoes and customers can explore them in a more engaging way. Instead of browsing a simple product list, customers experience a digital showroom that feels closer to visiting a real store.</p>
										<p className="mb-2 text-black/70">Shop owners can display multiple products depending on their plan:</p>
										<ul className="mb-3 list-disc space-y-1 pl-6 text-black/70">
											{plans.map((plan) => (
												<li key={plan.plan_code}><span className="font-semibold text-black">{plan.name}:</span> Up to {plan.showroom_slot_limit} display slots</li>
											))}
										</ul>
										<p className="mb-3 text-black/70">Each slot allows you to upload and showcase a shoe inside the virtual showroom, helping customers easily discover and browse your collection.</p>
										<p className="mb-3 text-black/70">Customers can swipe left or right to view shoes horizontally, allowing them to see different sides of the product and better appreciate its design and details. The showroom also includes Day Mode and Night Mode, giving users the option to switch lighting environments for a more comfortable viewing experience.</p>
										<p className="mb-3 text-black/70">This interactive experience helps customers examine products more closely while helping shop owners present their shoes in a modern, visually appealing, and immersive storefront.</p>
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
										<div className="overflow-hidden border-2 border-black">
											<img src="/images/SHOWROOM/image.png" alt="Virtual showroom interior overview" className="h-full w-full object-cover" />
										</div>
										<div className="overflow-hidden border-2 border-black">
											<img src="/images/SHOWROOM/image2.png" alt="Virtual showroom display slots example" className="h-full w-full object-cover" />
										</div>
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
