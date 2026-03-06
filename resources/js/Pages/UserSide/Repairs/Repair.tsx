import React, { useMemo, useState, useEffect, useRef } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

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
  const [sortBy, setSortBy] = useState('featured');
  const [isSortOpen, setIsSortOpen] = useState(false);
  const sortMenuRef = useRef<HTMLDivElement | null>(null);

  // Handle image loading errors with fallback
  const handleImageError = (e: React.SyntheticEvent<HTMLImageElement, Event>) => {
    const target = e.target as HTMLImageElement;
    target.src = '/images/shop/shop.jpg'; // Fallback to default shop image
  };

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (sortMenuRef.current && !sortMenuRef.current.contains(event.target as Node)) {
        setIsSortOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const sortLabelMap: Record<string, string> = {
    featured: 'Featured',
    best_selling: 'Best selling',
    name_asc: 'Alphabetically, A-Z',
    name_desc: 'Alphabetically, Z-A',
    price_asc: 'Price, low to high',
    price_desc: 'Price, high to low',
    created_at_asc: 'Date, old to new',
    created_at_desc: 'Date, new to old',
  };

  const sortOptions = [
    { value: 'featured', label: 'Featured' },
    { value: 'best_selling', label: 'Best selling' },
    { value: 'name_asc', label: 'Alphabetically, A-Z' },
    { value: 'name_desc', label: 'Alphabetically, Z-A' },
    { value: 'price_asc', label: 'Price, low to high' },
    { value: 'price_desc', label: 'Price, high to low' },
    { value: 'created_at_asc', label: 'Date, old to new' },
    { value: 'created_at_desc', label: 'Date, new to old' },
  ];

  const sortedShops = useMemo(() => {
    const items = [...shops];

    if (sortBy === 'best_selling') {
      return items.sort((a, b) => b.shopRating - a.shopRating);
    }

    if (sortBy === 'name_asc') {
      return items.sort((a, b) => a.shopName.localeCompare(b.shopName));
    }

    if (sortBy === 'name_desc') {
      return items.sort((a, b) => b.shopName.localeCompare(a.shopName));
    }

    if (sortBy === 'created_at_asc') {
      return items.sort((a, b) => a.id - b.id);
    }

    if (sortBy === 'created_at_desc') {
      return items.sort((a, b) => b.id - a.id);
    }

    return items;
  }, [shops, sortBy]);

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
              <div className="relative" ref={sortMenuRef}>
                <button
                  type="button"
                  onClick={() => setIsSortOpen((prev) => !prev)}
                  className="flex items-center gap-2 text-sm text-black/80"
                >
                  <span>
                    <span className="font-semibold">Sort by:</span>{' '}
                    <span>{sortLabelMap[sortBy]}</span>
                  </span>
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                    <svg
                      className={`h-3.5 w-3.5 text-black/70 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
                      viewBox="0 0 20 20"
                      fill="none"
                      xmlns="http://www.w3.org/2000/svg"
                      aria-hidden="true"
                    >
                      <path d="M5 12L10 7L15 12" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </span>
                </button>

                {isSortOpen && (
                  <div className="absolute right-0 mt-3 w-52 border border-gray-200 bg-white py-3 shadow-sm z-20" role="menu">
                    {sortOptions.map((option) => (
                      <button
                        key={option.value}
                        type="button"
                        role="menuitem"
                        onClick={() => {
                          setSortBy(option.value);
                          setIsSortOpen(false);
                        }}
                        className="group w-full px-5 py-2 text-left text-sm"
                      >
                        <span className="relative inline-block text-black/80 group-hover:text-black">
                          {option.label}
                          <span className="absolute -bottom-0.5 left-0 h-px w-full bg-black origin-left scale-x-0 transition-transform duration-300 group-hover:scale-x-100" />
                        </span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>

            <h1 className="text-4xl lg:text-5xl font-bold text-black mb-4 tracking-tight">ALL REPAIR SERVICES</h1>
            <p className="text-base text-black/70 mb-8 max-w-2xl leading-relaxed font-light">Browse our curated collection of repair shops. Click a shop card to view details and request service.</p>
          </div>

          {/* Shops Grid */}
          <div className="mb-12">
            {sortedShops.length === 0 ? (
              <div className="text-center py-20">
                <p className="text-black/60 text-lg">No repair shops available at the moment.</p>
                <p className="text-black/40 text-sm mt-2">Please check back later.</p>
              </div>
            ) : (
              <div className="grid gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                {sortedShops.map((shop) => (
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
                      <div className="text-xs text-black/50 mt-1">
                        {shop.shopLocation}
                        {shop.shopRating > 0 && ` • ${shop.shopRating} ⭐`}
                      </div>
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