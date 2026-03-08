import React, { useMemo, useState, useEffect, useRef, useCallback } from 'react';
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
  latitude: number | null;
  longitude: number | null;
}

interface Props {
  shops: Shop[];
}

// Haversine formula — returns distance in km
function haversine(lat1: number, lon1: number, lat2: number, lon2: number): number {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLon = ((lon2 - lon1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) *
      Math.cos((lat2 * Math.PI) / 180) *
      Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function formatDistance(km: number): string {
  if (km < 1) return `${Math.round(km * 1000)} m away`;
  return `${km.toFixed(1)} km away`;
}

const Repair: React.FC<Props> = ({ shops }) => {
  const [sortBy, setSortBy] = useState('featured');
  const [isSortOpen, setIsSortOpen] = useState(false);
  const [userCoords, setUserCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [locating, setLocating] = useState(false);
  const [locError, setLocError] = useState<string | null>(null);
  const sortMenuRef = useRef<HTMLDivElement | null>(null);

  const handleImageError = (e: React.SyntheticEvent<HTMLImageElement, Event>) => {
    (e.target as HTMLImageElement).src = '/images/shop/shop.jpg';
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

  const requestLocation = useCallback(() => {
    if (!navigator.geolocation) {
      setLocError('Your browser does not support location.');
      return;
    }
    setLocating(true);
    setLocError(null);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setUserCoords({ lat: pos.coords.latitude, lng: pos.coords.longitude });
        setSortBy('near_me');
        setLocating(false);
      },
      () => {
        setLocError('Location access denied. Please allow location in your browser.');
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 10000 },
    );
  }, []);

  const handleSortSelect = (value: string) => {
    if (value === 'near_me') {
      requestLocation();
    } else {
      setSortBy(value);
    }
    setIsSortOpen(false);
  };

  const sortLabelMap: Record<string, string> = {
    featured: 'Featured',
    best_selling: 'Best selling',
    name_asc: 'Alphabetically, A-Z',
    name_desc: 'Alphabetically, Z-A',
    near_me: 'Near me',
  };

  const sortOptions = [
    { value: 'featured', label: 'Featured' },
    { value: 'best_selling', label: 'Best selling' },
    { value: 'name_asc', label: 'Alphabetically, A-Z' },
    { value: 'name_desc', label: 'Alphabetically, Z-A' },
    { value: 'near_me', label: 'Near me 📍' },
  ];

  // Attach computed distance to each shop when we have user coords
  const shopsWithDistance = useMemo(() => {
    if (!userCoords) return shops.map(s => ({ ...s, distance: null as number | null }));
    return shops.map(s => ({
      ...s,
      distance:
        s.latitude != null && s.longitude != null
          ? haversine(userCoords.lat, userCoords.lng, s.latitude, s.longitude)
          : null,
    }));
  }, [shops, userCoords]);

  const sortedShops = useMemo(() => {
    const items = [...shopsWithDistance];
    switch (sortBy) {
      case 'best_selling':
        return items.sort((a, b) => b.shopRating - a.shopRating);
      case 'name_asc':
        return items.sort((a, b) => a.shopName.localeCompare(b.shopName));
      case 'name_desc':
        return items.sort((a, b) => b.shopName.localeCompare(a.shopName));
      case 'near_me':
        return items.sort((a, b) => {
          if (a.distance == null && b.distance == null) return 0;
          if (a.distance == null) return 1;
          if (b.distance == null) return -1;
          return a.distance - b.distance;
        });
      default:
        return items;
    }
  }, [shopsWithDistance, sortBy]);

  const currentSortLabel =
    sortBy === 'near_me' && locating ? 'Getting location…' : sortLabelMap[sortBy];

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
                    <span>{currentSortLabel}</span>
                  </span>
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                    <svg
                      className={`h-3.5 w-3.5 text-black/70 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
                      viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
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
                        onClick={() => handleSortSelect(option.value)}
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
            <p className="text-base text-black/70 mb-4 max-w-2xl leading-relaxed font-light">Browse our curated collection of repair shops. Click a shop card to view details and request service.</p>

            {/* Near Me strip */}
            {sortBy !== 'near_me' && (
              <button
                type="button"
                onClick={requestLocation}
                disabled={locating}
                className="inline-flex items-center gap-2 px-4 py-2 border border-black text-sm font-medium hover:bg-black hover:text-white transition disabled:opacity-50"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                </svg>
                {locating ? 'Getting your location…' : 'Show shops near me'}
              </button>
            )}
            {sortBy === 'near_me' && userCoords && (
              <div className="inline-flex items-center gap-2 px-4 py-2 bg-black text-white text-sm">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                </svg>
                Sorted by distance from your location
                <button
                  type="button"
                  onClick={() => { setSortBy('featured'); setUserCoords(null); }}
                  className="ml-2 underline text-white/70 hover:text-white text-xs"
                >
                  Clear
                </button>
              </div>
            )}
            {locError && (
              <p className="mt-2 text-sm text-red-600">{locError}</p>
            )}
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
                      {/* Distance badge — only shown when Near Me is active */}
                      {shop.distance != null && (
                        <div className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-black/70">
                          <svg className="w-3 h-3" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                          </svg>
                          {formatDistance(shop.distance)}
                        </div>
                      )}
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