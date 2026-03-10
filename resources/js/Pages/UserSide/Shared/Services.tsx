import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from './Navigation';

interface Props {
  // Add props from Laravel controller later
}

const Services: React.FC<Props> = () => {
  return (
    <>
      <Head title="Services" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

      <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
        {/* Premium Services Section */}
        <section className="w-full bg-white py-24 lg:py-32">
          <div className="max-w-[1920px] mx-auto px-6 lg:px-12">
            <div className="mb-20">
              <h2 className="text-5xl lg:text-7xl font-bold text-black mb-6 tracking-tight">
                PREMIUM BENEFITS
              </h2>
              <p className="text-xl text-black/70 max-w-2xl leading-relaxed font-light mb-8">
                Unlock exclusive advantages designed specifically for shop owners and repairers in our premium program.
              </p>
              <p className="text-sm text-black/50 uppercase tracking-wider font-semibold">
                Exclusive for Shop Owners & Repairers
              </p>
            </div>

            {/* Pricing Plans */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12 mb-16">
              {/* Weekly Plan */}
              <div className="group bg-white border-2 border-black overflow-hidden hover:shadow-2xl transition-all duration-300 flex flex-col h-full">
                <div className="p-8 border-b-2 border-black">
                  <h3 className="text-2xl font-bold text-black mb-4 uppercase tracking-wide">Basic</h3>
                  <div className="text-5xl font-bold text-black mb-6">₱249</div>
                  <p className="text-black/70 text-sm leading-relaxed mb-6">Best for getting started with one month of premium showroom access.</p>
                </div>
                <div className="p-8 flex flex-col justify-end flex-grow">
                  <ul className="space-y-4 mb-8">
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">15 days access to the virtual showroom</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">Display capacity: up to 48 shoe slots in your showroom</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">View shoes in horizontally degree detail</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">Enable to upload a image sequence of the shoes</span>
                    </li>
                  </ul>
                  <Link href="/shop-owner-register" className="block w-full px-6 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors text-center">
                    Get Started
                  </Link>
                </div>
              </div>

              {/* Monthly Plan */}
              <div className="group bg-white border-2 border-black overflow-hidden hover:shadow-2xl transition-all duration-300 flex flex-col h-full">
                <div className="p-8 border-b-2 border-black">
                  <h3 className="text-2xl font-bold text-black mb-4 uppercase tracking-wide">Pro</h3>
                  <div className="text-5xl font-bold text-black mb-6">₱399</div>
                  <p className="text-black/70 text-sm leading-relaxed mb-6">Ideal for ongoing premium access and benefits</p>
                </div>
                <div className="p-8 flex flex-col justify-end flex-grow">
                  <ul className="space-y-4 mb-8">
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">1 month access to the virtual showroom</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">Display capacity: up to 60 shoe slots in your showroom</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">View shoes in horizontally degree detail</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">Enable to upload a image sequence of the shoes</span>
                    </li>
                  </ul>
                  <Link href="/shop-owner-register" className="block w-full px-6 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors text-center">
                    Get Started
                  </Link>
                </div>
              </div>

              {/* Yearly Plan */}
              <div className="group bg-white border-2 border-black overflow-hidden hover:shadow-2xl transition-all duration-300 flex flex-col h-full">
                <div className="p-8 border-b-2 border-black">
                  <h3 className="text-2xl font-bold text-black mb-4 uppercase tracking-wide">Premium</h3>
                  <div className="text-5xl font-bold text-black mb-6">₱599</div>
                  <p className="text-black/70 text-sm leading-relaxed mb-6">Best value for long-term premium membership</p>
                </div>
                <div className="p-8 flex flex-col justify-end flex-grow">
                  <ul className="space-y-4 mb-8">
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">1 month access to the virtual showroom</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">Display capacity: up to 84 shoe slots in your showroom</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">View shoes in horizontally degree detail</span>
                    </li>
                    <li className="flex items-center gap-3">
                      <div className="w-5 h-5 border-2 border-black rounded-full flex items-center justify-center flex-shrink-0">
                        <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                      </div>
                      <span className="text-black/70 text-sm">Enable to upload a image sequence of the shoes</span>
                    </li>
                  </ul>
                  <Link href="/shop-owner-register" className="block w-full px-6 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors text-center">
                    Get Started
                  </Link>
                </div>
              </div>
            </div>

            {/* Virtual Showroom Explainer */}
            <div className="mb-16 border-2 border-black bg-white p-6 lg:p-8">
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-center">
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-black/60">How It Works</p>
                  <h3 className="mb-3 text-3xl font-bold tracking-tight text-black">What is a Virtual Showroom?</h3>
                  <p className="mb-3 text-black/70">
                    The Virtual Showroom is an interactive online display space where shop owners can showcase their shoes and customers can explore them in a more engaging way. Instead of browsing a simple product list, customers experience a digital showroom that feels closer to visiting a real store.
                  </p>
                  <p className="mb-2 text-black/70">Shop owners can display multiple products depending on their plan:</p>
                  <ul className="mb-3 list-disc space-y-1 pl-6 text-black/70">
                    <li>
                      <span className="font-semibold text-black">Basic Plan:</span> Up to 48 display slots
                    </li>
                    <li>
                      <span className="font-semibold text-black">Pro Plan:</span> Up to 60 display slots
                    </li>
                    <li>
                      <span className="font-semibold text-black">Premium Plan:</span> Up to 84 display slots
                    </li>
                  </ul>
                  <p className="mb-3 text-black/70">
                    Each slot allows you to upload and showcase a shoe inside the virtual showroom, helping customers easily discover and browse your collection.
                  </p>
                  <p className="mb-3 text-black/70">
                    Customers can swipe left or right to view shoes horizontally, allowing them to see different sides of the product and better appreciate its design and details. The showroom also includes Day Mode and Night Mode, giving users the option to switch lighting environments for a more comfortable viewing experience.
                  </p>
                  <p className="mb-3 text-black/70">
                    This interactive experience helps customers examine products more closely while helping shop owners present their shoes in a modern, visually appealing, and immersive storefront.
                  </p>
                </div>
                <div className="grid grid-cols-1 gap-4">
                  <div className="overflow-hidden border-2 border-black">
                    <img
                      src="/images/SHOWROOM/image.png"
                      alt="Virtual showroom interior overview"
                      className="h-full w-full object-cover"
                    />
                  </div>
                  <div className="overflow-hidden border-2 border-black">
                    <img
                      src="/images/SHOWROOM/image2.png"
                      alt="Virtual showroom display slots example"
                      className="h-full w-full object-cover"
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* Additional Info */}
            <div className="text-center">
              <p className="text-black/70 text-sm leading-relaxed max-w-2xl mx-auto">
                Premium benefits are exclusively available to verified shop owners and repairers. Contact us to learn more about eligibility and getting started.
              </p>
            </div>

            {/* Community Call-to-Action */}
            <div className="mt-20 text-center bg-gray-50 py-16 px-8 rounded-2xl border border-gray-200">
              <div className="max-w-3xl mx-auto">
                <div className="flex items-center justify-center gap-4 mb-6">
                  <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                  </svg>
                  <h3 className="text-3xl lg:text-4xl font-bold text-black tracking-tight">
                    Join Our Community
                  </h3>
                </div>
                <p className="text-xl text-black/70 leading-relaxed font-light mb-8 max-w-2xl mx-auto">
                  Be part of our growing shoe business community. Connect with fellow shop owners and repairers, share insights, and grow your business together.
                </p>
                <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
                  <Link href="/shop-owner-register" className="inline-block px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-all duration-300 transform hover:scale-105">
                    Register as Shop Owner or Repairer
                  </Link>
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

export default Services;
