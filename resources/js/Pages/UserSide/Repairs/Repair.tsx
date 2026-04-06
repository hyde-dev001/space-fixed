import React, { useMemo, useState, useEffect, useRef, useCallback } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import { useCart } from '../../../contexts/CartContext';
import NotificationBell from '../../../Components/common/NotificationBell';
import { useBadgeCounts } from '../../../hooks/useBadgeCounts';

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
  const page = usePage();
  const { cartCount, isLoading: cartLoading } = useCart();
  const isAuthenticated = Boolean((page.props as any)?.auth?.user);
  const initialChatIconCount = Number((page.props as any)?.chatIconCount ?? 0);
  const liveBadgeCounts = useBadgeCounts(isAuthenticated, {
    chatIconCount: initialChatIconCount,
  });
  const chatIconCount = isAuthenticated
    ? liveBadgeCounts.chatIconCount
    : initialChatIconCount;
  const cartBadgeCount = Number((page.props as any)?.cartIconCount ?? (cartLoading ? 0 : cartCount) ?? 0);
  const meHref = isAuthenticated ? '/customer-profile' : '/user/login';
  const [sortBy, setSortBy] = useState('near_me');
  const [isSortOpen, setIsSortOpen] = useState(false);
  const [mobileSearchQuery, setMobileSearchQuery] = useState('');
  const [userCoords, setUserCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [locating, setLocating] = useState(false);
  const [locError, setLocError] = useState<string | null>(null);
  const sortMenuRef = useRef<HTMLDivElement | null>(null);
  const accountMenuRef = useRef<HTMLDivElement | null>(null);
  const [isAccountMenuOpen, setIsAccountMenuOpen] = useState(false);

  const handleImageError = (e: React.SyntheticEvent<HTMLImageElement, Event>) => {
    (e.target as HTMLImageElement).src = '/images/shop/shop.jpg';
  };

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (sortMenuRef.current && !sortMenuRef.current.contains(event.target as Node)) {
        setIsSortOpen(false);
      }
      if (accountMenuRef.current && !accountMenuRef.current.contains(event.target as Node)) {
        setIsAccountMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleMobileLogout = () => {
    setIsAccountMenuOpen(false);
    router.post('/user/logout');
  };

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

  const displayShops = useMemo(() => {
    const query = mobileSearchQuery.trim().toLowerCase();
    if (!query) return sortedShops;

    return sortedShops.filter((shop) => {
      return shop.shopName.toLowerCase().includes(query) || shop.shopLocation.toLowerCase().includes(query);
    });
  }, [mobileSearchQuery, sortedShops]);

  const buttonBaseClass =
    'group inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const buttonDarkClass =
    'border border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';
  const currentPath = window.location.pathname;
  const activeMobileTab =
    currentPath === '/'
      ? 'home'
      : currentPath.startsWith('/products')
        ? 'products'
        : currentPath.startsWith('/customer-profile')
          ? 'me'
          : currentPath.startsWith('/messages')
            ? 'inbox'
            : currentPath.startsWith('/repair')
              ? 'repair'
              : '';
  const mobileNavItemClasses = (isActive: boolean) =>
    `group relative flex flex-col items-center justify-center gap-1 rounded-lg px-1 py-0.5 transition-all duration-300 ${
      isActive ? 'text-[#16233b]' : 'text-gray-600 hover:text-[#16233b]'
    }`;
  const mobileNavIconClasses = (isActive: boolean) =>
    `h-5 w-5 transition-all duration-300 ${isActive ? 'scale-110' : 'scale-100'}`;
  const mobileNavLabelClasses = (isActive: boolean) =>
    `transition-all duration-300 ${isActive ? 'font-semibold' : 'font-normal'}`;

  return (
    <>
      <Head title="Repair Services - SoleSpace" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <div className="hidden xl:block">
          <Navigation />
        </div>

        <div className="fixed top-0 left-0 right-0 z-50 flex h-16 items-center gap-2 bg-white px-3 shadow-sm xl:hidden">
          <Link
            href="/"
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 transition-colors"
            aria-label="Home"
            title="Home"
          >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10.5l9-7 9 7V20a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9.5z" />
            </svg>
          </Link>
          <div className="relative flex-1">
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 z-20">
              <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </span>
              <input
                type="text"
                value={mobileSearchQuery}
                onChange={(e) => setMobileSearchQuery(e.target.value)}
                placeholder="Search repair shops"
                className="w-full rounded-full border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-[#16233b] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#16233b]/20"
                aria-label="Search repair shops"
              />
          </div>
            {isAuthenticated && (
              <NotificationBell
                basePath="/api/notifications"
                iconSize={20}
                className="h-9 w-9 rounded-full text-gray-700 hover:bg-gray-100 hover:text-[#16233b] transition-colors"
                badgeClassName="absolute right-0 top-0 inline-flex h-5 min-w-5 translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold leading-none text-white ring-2 ring-white"
              />
            )}
            <div className="relative" ref={accountMenuRef}>
              <button
                type="button"
                onClick={() => setIsAccountMenuOpen((prev) => !prev)}
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 transition-colors"
                aria-label="Account menu"
                aria-controls="repair-account-menu"
              >
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
              </button>

              {isAccountMenuOpen && (
                <>
                  <div
                    className="fixed inset-0 z-40"
                    onClick={() => setIsAccountMenuOpen(false)}
                  />
                  <div
                    id="repair-account-menu"
                    className="absolute right-0 top-full z-50 mt-2 w-52 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_18px_35px_-20px_rgba(15,23,42,0.45)]"
                  >
                    {isAuthenticated ? (
                      <>
                        <Link
                          href="/my-orders"
                          className="flex items-center gap-3 border-b border-gray-100 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50"
                          onClick={() => setIsAccountMenuOpen(false)}
                        >
                          <span>Orders</span>
                        </Link>
                        <Link
                          href="/my-repairs"
                          className="flex items-center gap-3 border-b border-gray-100 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50"
                          onClick={() => setIsAccountMenuOpen(false)}
                        >
                          <span>Repair</span>
                        </Link>
                        <Link
                          href="/customer-profile"
                          className="flex items-center gap-3 border-b border-gray-100 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50"
                          onClick={() => setIsAccountMenuOpen(false)}
                        >
                          <span>Edit Profile</span>
                        </Link>
                        <button
                          type="button"
                          className="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-red-600 hover:bg-red-50"
                          onClick={handleMobileLogout}
                        >
                          <span>Log out</span>
                        </button>
                      </>
                    ) : (
                      <Link
                        href={meHref}
                        className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50"
                        onClick={() => setIsAccountMenuOpen(false)}
                      >
                        <span>Customer Login</span>
                      </Link>
                    )}
                  </div>
                </>
              )}
            </div>
            <Link href="/checkout" className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 hover:text-[#16233b] transition-colors" aria-label="Shopping cart">
              <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4h2l2.2 10.2a2 2 0 001.96 1.58h7.68a2 2 0 001.95-1.56L21 7H8" />
                <circle cx="10" cy="19" r="1.5" strokeWidth={2} />
                <circle cx="17" cy="19" r="1.5" strokeWidth={2} />
              </svg>
              {cartBadgeCount > 0 && (
                <span className="absolute right-0 top-0 inline-flex h-5 min-w-5 translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold leading-none text-white ring-2 ring-white">
                  {cartBadgeCount > 99 ? '99+' : cartBadgeCount}
                </span>
              )}
            </Link>
        </div>

        <div className="mx-auto w-full max-w-107.5 px-4 pb-24 pt-16 sm:max-w-170 md:max-w-225 lg:max-w-5xl xl:max-w-480 xl:px-6 xl:pt-32 xl:pb-20 2xl:px-12">
          {/* Header row */}
          <div className="mb-4 flex items-center justify-between gap-3 sm:mb-5">
            <div className="text-[11px] sm:text-xs text-black/55 tracking-[0.18em] uppercase">Home / All Repair</div>
            <div className="relative" ref={sortMenuRef}>
              <button
                type="button"
                onClick={() => setIsSortOpen((prev) => !prev)}
                className="flex items-center gap-1.5 text-xs font-medium text-black/80 sm:gap-2 sm:text-sm"
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
                <div className="absolute right-0 mt-3 z-20 w-[min(92vw,14.5rem)] rounded-2xl border border-gray-300 bg-white py-3 shadow-[0_20px_40px_-24px_rgba(15,23,42,0.55)] xl:w-56" role="menu">
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

          <h1 className="mb-2 text-[clamp(1.75rem,6vw,2.6rem)] font-bold tracking-tight text-black uppercase xl:hidden">ALL REPAIR SERVICES</h1>
          <p className="mb-8 max-w-3xl text-sm leading-relaxed text-black/65 sm:text-base xl:hidden">
            Browse our curated collection of repair shops. Click a shop card to view details and request service.
          </p>

          {/* Shops Grid */}
          <div className="mb-12">
            {displayShops.length === 0 ? (
              <div className="text-center py-16 border border-gray-200 rounded-3xl bg-gray-50/50">
                <p className="text-black/60 text-lg">No repair shops available at the moment.</p>
                <p className="text-black/40 text-sm mt-2">Please check back later.</p>
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-3 xl:gap-4 xl:grid-cols-3 2xl:grid-cols-4">
                {displayShops.map((shop) => (
                  <Link
                    key={shop.id}
                    href={`/repair-shop/${shop.id}`}
                    className="group flex h-full flex-col rounded-2xl border border-gray-200 bg-white shadow-[0_12px_28px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-300 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)] xl:rounded-3xl xl:border-gray-300 xl:shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)]"
                  >
                    <div className="aspect-[0.95] overflow-hidden rounded-t-2xl bg-gray-50 xl:aspect-square xl:rounded-t-3xl">
                      <img
                        src={shop.image}
                        alt={shop.shopName}
                        className="h-full w-full object-cover group-hover:scale-105 transition-transform duration-500"
                        onError={handleImageError}
                        loading="lazy"
                      />
                    </div>

                    <div className="flex flex-1 flex-col border-t border-gray-200 p-2.5 xl:p-4">
                      <h3 className="mb-1 line-clamp-2 text-[11px] font-bold uppercase tracking-[0.08em] text-black xl:text-sm">
                        {shop.shopName}
                      </h3>
                      <p className="mb-1 text-[10px] text-black/55 xl:text-xs">
                        {shop.shopLocation}
                        {shop.shopRating > 0 && (
                          <span className="ml-1">· {shop.shopRating} ⭐</span>
                        )}
                      </p>
                      {shop.distance != null && (
                        <p className="mb-3 flex items-center gap-1 text-[10px] text-black/50 xl:text-xs">
                          <svg className="w-3 h-3 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                          </svg>
                          {formatDistance(shop.distance)}
                        </p>
                      )}
                      <div className={`${buttonBaseClass} ${buttonDarkClass} mt-auto w-full text-[10px] xl:text-xs`}>
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

        <div className="fixed bottom-0 left-0 right-0 z-40 border-t border-gray-200 bg-white xl:hidden">
          <div className="mx-auto grid w-full max-w-107.5 grid-cols-5 px-2 py-2 text-[11px] text-gray-600 sm:max-w-170 md:max-w-225 lg:max-w-5xl">
            <Link href="/" className={mobileNavItemClasses(activeMobileTab === 'home')}>
              <span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'home' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
              <svg className={mobileNavIconClasses(activeMobileTab === 'home')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10.5l9-7 9 7V20a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9.5z" /></svg>
              <span className={mobileNavLabelClasses(activeMobileTab === 'home')}>Home</span>
            </Link>
            <Link href="/products" className={mobileNavItemClasses(activeMobileTab === 'products')}>
              <span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'products' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
              <svg className={mobileNavIconClasses(activeMobileTab === 'products')} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 15h4l2.2-3.2a1 1 0 01.82-.43H14l3.2 2.4a2 2 0 001.2.4H21v3H3v-2z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 15h.01M17 15h.01" />
              </svg>
              <span className={mobileNavLabelClasses(activeMobileTab === 'products')}>Products</span>
            </Link>
            <Link href="/repair-services" className={mobileNavItemClasses(activeMobileTab === 'repair')}>
              <span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'repair' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
              <svg className={mobileNavIconClasses(activeMobileTab === 'repair')} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.7 6.3a4 4 0 01-5.4 5.4l-5.2 5.2a1 1 0 000 1.4l1.3 1.3a1 1 0 001.4 0l5.2-5.2a4 4 0 005.4-5.4l-2.1 2.1-2.3-.5-.5-2.3 2.2-2.1z" />
              </svg>
              <span className={mobileNavLabelClasses(activeMobileTab === 'repair')}>Repair</span>
            </Link>
            <Link href="/messages" className={mobileNavItemClasses(activeMobileTab === 'inbox')}>
              <span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'inbox' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
              <div className="relative">
                <svg className={mobileNavIconClasses(activeMobileTab === 'inbox')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z" /></svg>
                {chatIconCount > 0 && (
                  <span className="absolute -right-2 -top-1.5 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-white">
                    {chatIconCount > 99 ? '99+' : chatIconCount}
                  </span>
                )}
              </div>
              <span className={mobileNavLabelClasses(activeMobileTab === 'inbox')}>Inbox</span>
            </Link>
            <Link href={meHref} className={mobileNavItemClasses(activeMobileTab === 'me')}>
              <span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'me' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
              <svg className={mobileNavIconClasses(activeMobileTab === 'me')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
              <span className={mobileNavLabelClasses(activeMobileTab === 'me')}>Me</span>
            </Link>
          </div>
        </div>
      </div>
    </>
  );
};

export default Repair;