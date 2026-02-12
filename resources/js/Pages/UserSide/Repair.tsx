import React, { useState } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import Navigation from './Navigation';

interface Service {
  id: number;
  title: string;
  price: string;
  image: string;
  shopName: string;
  shopLocation: string;
  shopRating: number;
}

interface Props {
  // will accept props from backend later
}

const Repair: React.FC<Props> = () => {
  // Mock shop profile & services (replace with real data later)
  const shop = { id: 1, name: 'SoleHouse', location: 'Dasmariñas, Cavite', rating: 4.8 };

  const services: Service[] = [
    { id: 1, title: 'Sole Replacement', price: '₱450', image: '/images/shop/shop1.jpg', shopName: 'SoleHouse', shopLocation: 'Dasmariñas, Cavite', shopRating: 4.8 },
    { id: 2, title: 'Heel Repair', price: '₱300', image: '/images/shop/shop2.jpg', shopName: 'HeelCraft', shopLocation: 'Bacoor, Cavite', shopRating: 2.6 },
    { id: 3, title: 'Stitching & Patch', price: '₱250', image: '/images/shop/shop3.jpg', shopName: 'PatchPros', shopLocation: 'Imus, Cavite', shopRating: 4.7 },
    { id: 4, title: 'Deep Clean & Condition', price: '₱200', image: '/images/shop/shop4.jpg', shopName: 'CleanWorks', shopLocation: 'Dasmariñas, Cavite', shopRating: 3.5 },
    { id: 5, title: 'Custom Paint', price: '₱600', image: '/images/shop/shop5.jpg', shopName: 'Artistic Soles', shopLocation: 'Carmona, Cavite', shopRating: 4.9 },
    { id: 6, title: 'Zipper Replacement', price: '₱350', image: '/images/shop/shop.jpg', shopName: 'ZipFix', shopLocation: 'Silang, Cavite', shopRating: 4.4 },
  ];

  // Handle image loading errors with fallback
  const handleImageError = (e: React.SyntheticEvent<HTMLImageElement, Event>) => {
    const target = e.target as HTMLImageElement;
    target.src = '/images/shop/shop.jpg'; // Fallback to default shop image
  };

  // (Removed inline request form and requests list — handled elsewhere)

  return (
    <>
      <Head title={`${shop.name} - Repair Services`} />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 py-12 lg:py-20">
          {/* Shop Header - mimic large hero style like landing/products */}
          <div className="mb-10">
            <div className="flex items-center justify-between mb-8">
              <div className="text-sm text-black/60">HOME / ALL REPAIRE</div>
              <div className="flex items-center gap-4">
                {/* placeholder to match Products spacing */}
              </div>
            </div>

            <h1 className="text-4xl lg:text-5xl font-bold text-black mb-4 tracking-tight">ALL REPAIRE</h1>
            <p className="text-base text-black/70 mb-8 max-w-2xl leading-relaxed font-light">Browse our curated collection of repair services. Click a shop card to view details and request service.</p>
          </div>

          {/* Services Grid - use same card style as Products */}
          <div className="mb-12">
            <div className="grid gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {services.map((s) => (
                <button
                  key={s.id}
                  onClick={() => router.visit(`/repair-shop/${s.id}`)}
                  className="group block w-full text-left bg-white rounded-lg border border-gray-100 p-4 hover:shadow-lg transition-shadow duration-150"
                  type="button"
                >
                  <div className="aspect-square bg-gray-50 rounded-md flex items-center justify-center p-6 overflow-hidden">
                    <img 
                      src={s.image} 
                      alt={s.title} 
                      className="max-h-full max-w-full object-contain"
                      onError={handleImageError}
                      loading="lazy"
                    />
                  </div>

                  <div className="text-center mt-4">
                    <Link href={`/shop-profile/${s.id}`} className="text-sm text-black/80 font-medium hover:text-black transition-colors inline-block">
                      {s.shopName}
                    </Link>
                    <div className="text-xs text-black/50 mt-1">{s.shopLocation} • {s.shopRating} ⭐</div>
                    <div className="mt-3">
                      <div className="px-4 py-2 border border-black text-black text-sm rounded hover:bg-black hover:text-white transition inline-block">View Shop</div>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          </div>

        </div>
      </div>
    </>
  );
};

export default Repair;
