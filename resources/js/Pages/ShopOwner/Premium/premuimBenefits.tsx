import React from 'react';
import { Head, Link } from '@inertiajs/react';

interface Props {
	// Add props from Laravel controller later
}

const PremiumBenefits: React.FC<Props> = () => {
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
									Unlock exclusive advantages designed specifically for shop owners and repairers in our premium program.
								</p>
								<p className="text-sm font-semibold uppercase tracking-wider text-black/50">Exclusive for Shop Owners and Repairers</p>
							</div>

							<div className="mb-16 grid grid-cols-1 gap-8 md:grid-cols-3 lg:gap-12">
								<div className="group flex h-full flex-col overflow-hidden border-2 border-black bg-white transition-all duration-300 hover:shadow-2xl">
									<div className="border-b-2 border-black p-8">
										<h3 className="mb-4 text-2xl font-bold uppercase tracking-wide text-black">Basic</h3>
										<div className="mb-6 text-5xl font-bold text-black">₱249</div>
										<p className="mb-6 text-sm leading-relaxed text-black/70">Best for getting started with one month of premium showroom access.</p>
									</div>
									<div className="grow p-8">
										<ul className="mb-8 space-y-4">
											<li className="flex items-center gap-3">
												<div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black">
													<svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
														<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
													</svg>
												</div>
												<span className="text-sm text-black/70">15 days access to the virtual showroom</span>
											</li>
											<li className="flex items-center gap-3">
												<div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black">
													<svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
														<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
													</svg>
												</div>
												<span className="text-sm text-black/70">Display capacity: up to 48 shoe slots in your showroom</span>
											</li>
											<li className="flex items-center gap-3">
												<div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black">
													<svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
														<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
													</svg>
												</div>
												<span className="text-sm text-black/70">View shoes in horizontally degree detail</span>
											</li>
											<li className="flex items-center gap-3">
												<div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black">
													<svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
														<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
													</svg>
												</div>
												<span className="text-sm text-black/70">Enable to upload a image sequence of the shoes</span>
											</li>
										</ul>
										<Link href="/payment?source=premium&plan=basic&total=249" className="block w-full bg-black px-6 py-4 text-center text-sm font-semibold uppercase tracking-wider text-white transition-colors hover:bg-black/80">
											Subscribe Now
										</Link>
									</div>
								</div>

								<div className="group flex h-full flex-col overflow-hidden border-2 border-black bg-white transition-all duration-300 hover:shadow-2xl">
									<div className="border-b-2 border-black p-8">
										<h3 className="mb-4 text-2xl font-bold uppercase tracking-wide text-black">Pro</h3>
										<div className="mb-6 text-5xl font-bold text-black">₱399</div>
										<p className="mb-6 text-sm leading-relaxed text-black/70">Ideal for ongoing premium access and benefits</p>
									</div>
									<div className="grow p-8">
										<ul className="mb-8 space-y-4">
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">1 month access to the virtual showroom</span></li>
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">Display capacity: up to 60 shoe slots in your showroom</span></li>
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">View shoes in horizontally degree detail</span></li>
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">Enable to upload a image sequence of the shoes</span></li>
										</ul>
										<Link href="/payment?source=premium&plan=pro&total=399" className="block w-full bg-black px-6 py-4 text-center text-sm font-semibold uppercase tracking-wider text-white transition-colors hover:bg-black/80">Subscribe Now</Link>
									</div>
								</div>

								<div className="group flex h-full flex-col overflow-hidden border-2 border-black bg-white transition-all duration-300 hover:shadow-2xl">
									<div className="border-b-2 border-black p-8">
										<h3 className="mb-4 text-2xl font-bold uppercase tracking-wide text-black">Premium</h3>
										<div className="mb-6 text-5xl font-bold text-black">₱599</div>
										<p className="mb-6 text-sm leading-relaxed text-black/70">Best value for long-term premium membership</p>
									</div>
									<div className="grow p-8">
										<ul className="mb-8 space-y-4">
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">1 month access to the virtual showroom</span></li>
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">Display capacity: up to 84 shoe slots in your showroom</span></li>
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">View shoes in horizontally degree detail</span></li>
											<li className="flex items-center gap-3"><div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-black"><svg className="h-3 w-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg></div><span className="text-sm text-black/70">Enable to upload a image sequence of the shoes</span></li>
										</ul>
										<Link href="/payment?source=premium&plan=premium&total=599" className="block w-full bg-black px-6 py-4 text-center text-sm font-semibold uppercase tracking-wider text-white transition-colors hover:bg-black/80">Subscribe Now</Link>
									</div>
								</div>
							</div>

							<div className="mb-16 border-2 border-black bg-white p-6 lg:p-8">
								<div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-center">
									<div>
										<p className="mb-2 text-xs font-semibold uppercase tracking-wider text-black/60">How It Works</p>
										<h3 className="mb-3 text-3xl font-bold tracking-tight text-black">What is a Virtual Showroom?</h3>
										<p className="mb-3 text-black/70">The Virtual Showroom is an interactive online display space where shop owners can showcase their shoes and customers can explore them in a more engaging way. Instead of browsing a simple product list, customers experience a digital showroom that feels closer to visiting a real store.</p>
										<p className="mb-2 text-black/70">Shop owners can display multiple products depending on their plan:</p>
										<ul className="mb-3 list-disc space-y-1 pl-6 text-black/70">
											<li><span className="font-semibold text-black">Basic Plan:</span> Up to 48 display slots</li>
											<li><span className="font-semibold text-black">Pro Plan:</span> Up to 60 display slots</li>
											<li><span className="font-semibold text-black">Premium Plan:</span> Up to 84 display slots</li>
										</ul>
										<p className="mb-3 text-black/70">Each slot allows you to upload and showcase a shoe inside the virtual showroom, helping customers easily discover and browse your collection.</p>
										<p className="mb-3 text-black/70">Customers can swipe left or right to view shoes horizontally, allowing them to see different sides of the product and better appreciate its design and details. The showroom also includes Day Mode and Night Mode, giving users the option to switch lighting environments for a more comfortable viewing experience.</p>
										<p className="mb-3 text-black/70">This interactive experience helps customers examine products more closely while helping shop owners present their shoes in a modern, visually appealing, and immersive storefront.</p>
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
