import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from './Navigation';

interface Props {
  // Add props from Laravel controller later
}

const checkIcon = (
  <div className="w-5 h-5 border border-gray-300 rounded-full flex items-center justify-center flex-shrink-0 bg-gray-50">
    <svg className="w-3 h-3 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
    </svg>
  </div>
);

const btnBase =
  'group inline-flex items-center justify-center gap-2 rounded-full font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
const btnDark =
  'border border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';
const btnLight =
  'border border-gray-300 bg-white text-gray-900 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';

const plans = [
  {
    name: 'Basic',
    price: '₱249',
    description: 'Best for getting started with one month of premium showroom access.',
    features: [
      '15 days access to the virtual showroom',
      'Display capacity: up to 48 shoe slots in your showroom',
      'View shoes in horizontally degree detail',
      'Enable to upload a image sequence of the shoes',
    ],
  },
  {
    name: 'Pro',
    price: '₱399',
    description: 'Ideal for ongoing premium access and benefits.',
    features: [
      '1 month access to the virtual showroom',
      'Display capacity: up to 60 shoe slots in your showroom',
      'View shoes in horizontally degree detail',
      'Enable to upload a image sequence of the shoes',
    ],
  },
  {
    name: 'Premium',
    price: '₱599',
    description: 'Best value for long-term premium membership.',
    features: [
      '1 month access to the virtual showroom',
      'Display capacity: up to 84 shoe slots in your showroom',
      'View shoes in horizontally degree detail',
      'Enable to upload a image sequence of the shoes',
    ],
  },
];

const Services: React.FC<Props> = () => {
  return (
    <>
      <Head title="Services - SoleSpace" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 pt-28 pb-12 lg:pt-32 lg:pb-20">

          {/* Breadcrumb */}
          <div className="text-[11px] sm:text-xs text-black/55 tracking-[0.18em] uppercase mb-8">
            Home / Services
          </div>

          {/* Page heading */}
          <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold text-black mb-4 tracking-tight uppercase">
            Premium Benefits
          </h1>
          <p className="text-base text-black/65 mb-2 max-w-3xl leading-relaxed font-light">
            Unlock exclusive advantages designed specifically for shop owners and repairers in our premium program.
          </p>
          <p className="text-xs text-black/40 uppercase tracking-[0.16em] font-semibold mb-14">
            Exclusive for Shop Owners &amp; Repairers
          </p>

          {/* Pricing Plans */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8 mb-16">
            {plans.map((plan) => (
              <div
                key={plan.name}
                className="group rounded-3xl border border-gray-300 bg-white shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-400 hover:shadow-[0_28px_48px_-24px_rgba(15,23,42,0.55)] flex flex-col"
              >
                <div className="p-8 border-b border-gray-200">
                  <h3 className="text-xs font-bold text-black/50 uppercase tracking-[0.18em] mb-3">{plan.name}</h3>
                  <div className="text-5xl font-bold text-black mb-4 tracking-tight">{plan.price}</div>
                  <p className="text-black/60 text-sm leading-relaxed">{plan.description}</p>
                </div>
                <div className="p-8 flex flex-col flex-grow">
                  <ul className="space-y-3.5 mb-8 flex-grow">
                    {plan.features.map((feat) => (
                      <li key={feat} className="flex items-start gap-3">
                        {checkIcon}
                        <span className="text-black/65 text-sm leading-snug">{feat}</span>
                      </li>
                    ))}
                  </ul>
                  <Link
                    href="/shop-owner-register"
                    className={`${btnBase} ${btnDark} w-full px-6 py-3 text-xs justify-center`}
                  >
                    Get Started
                    <svg className="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                  </Link>
                </div>
              </div>
            ))}
          </div>

          {/* Virtual Showroom Explainer */}
          <div className="mb-16 rounded-3xl border border-gray-300 bg-white shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] p-8 lg:p-12">
            <div className="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:items-center">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-black/45">How It Works</p>
                <h2 className="mb-5 text-3xl lg:text-4xl font-bold tracking-tight text-black uppercase">
                  What is a Virtual Showroom?
                </h2>
                <p className="mb-4 text-black/65 text-sm leading-relaxed">
                  The Virtual Showroom is an interactive online display space where shop owners can showcase their shoes and customers can explore them in a more engaging way. Instead of browsing a simple product list, customers experience a digital showroom that feels closer to visiting a real store.
                </p>
                <p className="mb-3 text-black/65 text-sm leading-relaxed">Shop owners can display multiple products depending on their plan:</p>
                <ul className="mb-4 space-y-1.5 text-sm text-black/65">
                  <li><span className="font-semibold text-black">Basic Plan:</span> Up to 48 display slots</li>
                  <li><span className="font-semibold text-black">Pro Plan:</span> Up to 60 display slots</li>
                  <li><span className="font-semibold text-black">Premium Plan:</span> Up to 84 display slots</li>
                </ul>
                <p className="mb-4 text-black/65 text-sm leading-relaxed">
                  Each slot allows you to upload and showcase a shoe inside the virtual showroom, helping customers easily discover and browse your collection.
                </p>
                <p className="mb-4 text-black/65 text-sm leading-relaxed">
                  Customers can swipe left or right to view shoes horizontally, allowing them to see different sides of the product and better appreciate its design and details. The showroom also includes Day Mode and Night Mode, giving users the option to switch lighting environments for a more comfortable viewing experience.
                </p>
                <p className="text-black/65 text-sm leading-relaxed">
                  This interactive experience helps customers examine products more closely while helping shop owners present their shoes in a modern, visually appealing, and immersive storefront.
                </p>
              </div>
              <div className="grid grid-cols-1 gap-4">
                <div className="overflow-hidden rounded-2xl border border-gray-200">
                  <img
                    src="/images/SHOWROOM/image.png"
                    alt="Virtual showroom interior overview"
                    className="h-full w-full object-cover"
                  />
                </div>
                <div className="overflow-hidden rounded-2xl border border-gray-200">
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
          <p className="text-center text-black/55 text-sm leading-relaxed max-w-2xl mx-auto mb-16">
            Premium benefits are exclusively available to verified shop owners and repairers. Contact us to learn more about eligibility and getting started.
          </p>

          {/* Community Call-to-Action */}
          <div className="rounded-3xl border border-gray-300 bg-gray-50/60 shadow-[0_16px_35px_-24px_rgba(15,23,42,0.35)] py-16 px-8 text-center">
            <div className="max-w-3xl mx-auto">
              <div className="w-16 h-16 border-2 border-black rounded-full flex items-center justify-center mx-auto mb-8">
                <svg className="w-8 h-8 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </div>
              <h2 className="text-3xl lg:text-4xl font-bold text-black tracking-tight uppercase mb-4">
                Join Our Community
              </h2>
              <p className="text-base text-black/65 leading-relaxed font-light mb-10 max-w-2xl mx-auto">
                Be part of our growing shoe business community. Connect with fellow shop owners and repairers, share insights, and grow your business together.
              </p>
              <Link
                href="/shop-owner-register"
                className={`${btnBase} ${btnDark} px-10 py-3.5 text-sm`}
              >
                Register as Shop Owner or Repairer
                <svg className="h-4 w-4 transition-transform duration-300 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </Link>
            </div>
          </div>

        </div>
      </div>
    </>
  );
};

export default Services;
