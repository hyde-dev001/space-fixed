import React, { useMemo, useState, useEffect, useRef, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
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
  const [sortBy, setSortBy] = useState('near_me');
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

  useEffect(() => {
    requestLocation();
  }, [requestLocation]);

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

  const buttonBaseClass =
    'group inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const buttonDarkClass =
    'border border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';

  return (
    <>
      <Head title="Repair Services - SoleSpace" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 pt-28 pb-12 lg:pt-32 lg:pb-20">
          {/* Header row */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-8 gap-4">
            <div className="text-[11px] sm:text-xs text-black/55 tracking-[0.18em] uppercase">Home / All Repair</div>
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
                    className={`h-3.5 w-3.5 text-gray-700 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
                    viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                  >
                    <path d="M5 12L10 7L15 12" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </span>
              </button>

              {isSortOpen && (
                <div className="absolute right-0 mt-3 w-56 rounded-2xl border border-gray-300 bg-white py-3 shadow-[0_20px_40px_-24px_rgba(15,23,42,0.55)] z-20" role="menu">
                  {sortOptions.map((option) => {
                    const isActive = sortBy === option.value;
                    return (
                      <button
                        key={option.value}
                        type="button"
                        role="menuitem"
                        onClick={() => handleSortSelect(option.value)}
                        className="group w-full px-5 py-2.5 text-left text-sm"
                      >
                        <span className={`relative inline-block ${isActive ? 'text-black font-semibold' : 'text-black/75'}`}>
                          {option.label}
                          <span className={`absolute bottom-0 left-0 h-[1.5px] bg-black transition-all duration-300 ${isActive ? 'w-full' : 'w-0 group-hover:w-full'}`} />
                        </span>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          </div>

          {locError && (
            <div className="mb-8 rounded-2xl bg-red-50 border border-red-200 px-5 py-3.5 text-sm text-red-700">
              {locError}
            </div>
          )}

          <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold text-black mb-4 tracking-tight uppercase">ALL REPAIR SERVICES</h1>
          <p className="text-base text-black/65 mb-10 max-w-3xl leading-relaxed font-light">
            Browse our curated collection of repair shops. Click a shop card to view details and request service.
          </p>

          {/* Shops Grid */}
          <div className="mb-12">
            {sortedShops.length === 0 ? (
              <div className="text-center py-16 border border-gray-200 rounded-3xl bg-gray-50/50">
                <p className="text-black/60 text-lg">No repair shops available at the moment.</p>
                <p className="text-black/40 text-sm mt-2">Please check back later.</p>
              </div>
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                {sortedShops.map((shop) => (
                  <Link
                    key={shop.id}
                    href={`/repair-shop/${shop.id}`}
                    className="group block rounded-3xl border border-gray-300 bg-white shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-400 hover:shadow-[0_28px_48px_-24px_rgba(15,23,42,0.55)]"
                  >
                    <div className="aspect-square bg-gray-50 rounded-t-3xl flex items-center justify-center overflow-hidden">
                      <img
                        src={shop.image}
                        alt={shop.shopName}
                        className="h-full w-full object-cover group-hover:scale-105 transition-transform duration-500"
                        onError={handleImageError}
                        loading="lazy"
                      />
                    </div>

                    <div className="border-t border-gray-200 p-4">
                      <h3 className="text-sm font-bold text-black uppercase tracking-[0.06em] line-clamp-1 mb-1">
                        {shop.shopName}
                      </h3>
                      <p className="text-xs text-black/55 mb-1">
                        {shop.shopLocation}
                        {shop.shopRating > 0 && (
                          <span className="ml-1">· {shop.shopRating} ⭐</span>
                        )}
                      </p>
                      {shop.distance != null && (
                        <p className="text-xs text-black/50 mb-3 flex items-center gap-1">
                          <svg className="w-3 h-3 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                          </svg>
                          {formatDistance(shop.distance)}
                        </p>
                      )}
                      <div className={`${buttonBaseClass} ${buttonDarkClass} w-full mt-2`}>
                        View Shop
                        <svg className="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                        </svg>
                      </div>
                    </div>
                  </Link>
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