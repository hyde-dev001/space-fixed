import React, { useState } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import Navigation from './Navigation';

interface Shop {
  id: number;
  shopName: string;
  shopLocation: string;
  shopRating: number;
  image: string;
  phone?: string;
  email?: string;
  bio?: string;
}

interface Props {
  shops: Shop[];
}

const Repair: React.FC<Props> = ({ shops }) => {
  // Handle image loading errors with fallback
  const handleImageError = (e: React.SyntheticEvent<HTMLImageElement, Event>) => {
    const target = e.target as HTMLImageElement;
    target.src = '/images/shop/shop.jpg'; // Fallback to default shop image
  };

  // (Removed inline request form and requests list — handled elsewhere)

  return (
    <>
      <Head title="Repair Services - SoleSpace" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 py-12 lg:py-20">
          {/* Shop Header */}
          <div className="mb-10">
            <div className="flex items-center justify-between mb-8">
              <div className="text-sm text-black/60">HOME / ALL REPAIR</div>
              <div className="flex items-center gap-4">
                {/* placeholder to match Products spacing */}
              </div>
            </div>

            <h1 className="text-4xl lg:text-5xl font-bold text-black mb-4 tracking-tight">ALL REPAIR SERVICES</h1>
            <p className="text-base text-black/70 mb-8 max-w-2xl leading-relaxed font-light">Browse our curated collection of repair shops. Click a shop card to view details and request service.</p>
          </div>

          {/* Shops Grid */}
          <div className="mb-12">
            {shops.length === 0 ? (
              <div className="text-center py-20">
                <p className="text-black/60 text-lg">No repair shops available at the moment.</p>
                <p className="text-black/40 text-sm mt-2">Please check back later.</p>
              </div>
            ) : (
              <div className="grid gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                {shops.map((shop) => (
                  <button
                    key={shop.id}
                    onClick={() => router.visit(`/repair-shop/${shop.id}`)}
                    className="group block w-full text-left bg-white rounded-lg border border-gray-100 p-4 hover:shadow-lg transition-shadow duration-150"
                    type="button"
                  >
                    <div className="aspect-square bg-gray-50 rounded-md flex items-center justify-center p-6 overflow-hidden">
                      <img 
                        src={shop.image} 
                        alt={shop.shopName} 
                        className="max-h-full max-w-full object-contain"
                        onError={handleImageError}
                        loading="lazy"
                      />
                    </div>

                    <div className="text-center mt-4">
                      <Link href={`/repair-shop/${shop.id}`} className="text-sm text-black/80 font-medium hover:text-black transition-colors inline-block">
                        {shop.shopName}
                      </Link>
                      <div className="text-xs text-black/50 mt-1">{shop.shopLocation} • {shop.shopRating} ⭐</div>
                      <div className="mt-3">
                        <div className="px-4 py-2 border border-black text-black text-sm rounded hover:bg-black hover:text-white transition inline-block">View Shop</div>
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </div>

        </div>
      </div>
    </>
  );
};

export default Repair;