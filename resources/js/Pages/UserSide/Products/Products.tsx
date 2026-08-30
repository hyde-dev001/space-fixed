import React, { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import { useCart } from '../../../contexts/CartContext';
import NotificationBell from "../../../components/common/NotificationBell";
import StarRating from '../../../components/common/StarRating';
import { useBadgeCounts } from '../../../hooks/useBadgeCounts';
import { GPS_POSITION_OPTIONS, getCurrentPositionWithTimeout } from '@/utils/geolocation';

type Product = {
  id: number;
  name: string;
  price: number;
  sales_count?: number;
  compare_at_price?: number | null;
  slug: string;
  main_image: string | null;
  hover_image?: string | null;
  gallery_images?: string[];
  brand: string | null;
  stock_quantity: number;
  description?: string | null;
  average_rating?: number;
  shop_owner?: {
    id: number;
    name?: string;
    business_name?: string;
    latitude?: number | null;
    longitude?: number | null;
  };
};

type ShopSearchResult = {
  id: number;
  name: string;
  location?: string | null;
  image?: string | null;
  url: string;
  virtual_showroom_url?: string | null;
};

interface Props {
  // will accept products from backend later
}

const ALLOWED_CATEGORY_FILTERS = ['men', 'women', 'kids', 'sports'] as const;

// --- Near Me helpers ---
const haversine = (lat1: number, lng1: number, lat2: number, lng2: number): number => {
  const R = 6371;
  const dLat = (lat2 - lat1) * (Math.PI / 180);
  const dLng = (lng2 - lng1) * (Math.PI / 180);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1 * (Math.PI / 180)) * Math.cos(lat2 * (Math.PI / 180)) *
    Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};

const formatDistance = (km: number): string => {
  if (km < 1) return `${Math.round(km * 1000)} m away`;
  return `${km.toFixed(1)} km away`;
};

const Products: React.FC<Props> = () => {
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
  const meHref = isAuthenticated ? '/customer-profile' : '/login';
  const urlParams = new URLSearchParams(window.location.search);
  const searchParam = urlParams.get('search') || '';
  const rawCategoryParam = (urlParams.get('category') || '').toLowerCase();
  const categoryParam = ALLOWED_CATEGORY_FILTERS.includes(rawCategoryParam as typeof ALLOWED_CATEGORY_FILTERS[number])
    ? rawCategoryParam
    : '';
  
  const [products, setProducts] = useState<Product[]>([]);
  const [shopResults, setShopResults] = useState<ShopSearchResult[]>([]);
  const [loadingShops, setLoadingShops] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState('near_me');
  const [isSortOpen, setIsSortOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState(searchParam);
  const [activeCategory, setActiveCategory] = useState(categoryParam);
  const [mobileSearchQuery, setMobileSearchQuery] = useState(searchParam);
  const [currentPage, setCurrentPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [activeImageIndexes, setActiveImageIndexes] = useState<Record<number, number>>({});
  const [userCoords, setUserCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [locating, setLocating] = useState(false);
  const [locError, setLocError] = useState<string | null>(null);
  const [mobileAccountOpen, setMobileAccountOpen] = useState(false);
  const [mobileSearchFocused, setMobileSearchFocused] = useState(false);
  const [mobileSuggestionProducts, setMobileSuggestionProducts] = useState<ShopSearchResult[]>([]);
  const [mobileSuggestionShops, setMobileSuggestionShops] = useState<ShopSearchResult[]>([]);
  const [isMobileSearchingSuggestions, setIsMobileSearchingSuggestions] = useState(false);
  const sortMenuRef = useRef<HTMLDivElement | null>(null);
  const mobileAccountRef = useRef<HTMLDivElement | null>(null);
  const mobileSearchContainerRef = useRef<HTMLDivElement | null>(null);
  const mobileSearchAbortRef = useRef<AbortController | null>(null);
  const hoverTimersRef = useRef<Record<number, number>>({});

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const search = params.get('search') || '';
    const category = (params.get('category') || '').toLowerCase();
    setSearchQuery(search);
    setMobileSearchQuery(search);
    setActiveCategory(
      ALLOWED_CATEGORY_FILTERS.includes(category as typeof ALLOWED_CATEGORY_FILTERS[number])
        ? category
        : ''
    );
  }, [window.location.search]);

  useEffect(() => {
    fetchProducts();
  }, [sortBy, currentPage, searchQuery, activeCategory]);

  useEffect(() => {
    const fetchShops = async () => {
      const query = searchQuery.trim();

      if (!query) {
        setShopResults([]);
        setLoadingShops(false);
        return;
      }

      try {
        setLoadingShops(true);
        const response = await fetch(`/api/search/suggestions?query=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json' },
        });

        if (!response.ok) throw new Error('Failed to fetch shop results');

        const data = await response.json();
        setShopResults(Array.isArray(data.shops) ? data.shops : []);
      } catch (error) {
        console.error('Error fetching shop results:', error);
        setShopResults([]);
      } finally {
        setLoadingShops(false);
      }
    };

    fetchShops();
  }, [searchQuery]);

  // Mobile search suggestions
  useEffect(() => {
    const query = mobileSearchQuery.trim();

    if (query.length < 2) {
      setMobileSuggestionProducts([]);
      setMobileSuggestionShops([]);
      setIsMobileSearchingSuggestions(false);
      if (mobileSearchAbortRef.current) {
        mobileSearchAbortRef.current.abort();
        mobileSearchAbortRef.current = null;
      }
      return;
    }

    const timeoutId = window.setTimeout(async () => {
      try {
        if (mobileSearchAbortRef.current) {
          mobileSearchAbortRef.current.abort();
        }

        const controller = new AbortController();
        mobileSearchAbortRef.current = controller;
        setIsMobileSearchingSuggestions(true);

        const response = await fetch(`/api/search/suggestions?query=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error('Failed to load search suggestions');
        }

        const data = await response.json();
        setMobileSuggestionProducts(Array.isArray(data.products) ? data.products : []);
        setMobileSuggestionShops(Array.isArray(data.shops) ? data.shops : []);
      } catch (error: any) {
        if (error?.name !== 'AbortError') {
          setMobileSuggestionProducts([]);
          setMobileSuggestionShops([]);
        }
      } finally {
        setIsMobileSearchingSuggestions(false);
      }
    }, 220);

    return () => window.clearTimeout(timeoutId);
  }, [mobileSearchQuery]);

  // Close mobile search suggestions when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (sortMenuRef.current && !sortMenuRef.current.contains(event.target as Node)) {
        setIsSortOpen(false);
      }
      if (mobileAccountRef.current && !mobileAccountRef.current.contains(event.target as Node)) {
        setMobileAccountOpen(false);
      }
      if (mobileSearchContainerRef.current && !mobileSearchContainerRef.current.contains(event.target as Node)) {
        setMobileSearchFocused(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleLogout = () => {
    setMobileAccountOpen(false);
    router.post('/user/logout', {}, {
      preserveState: false,
      preserveScroll: false,
    });
  };

  useEffect(() => {
    return () => {
      Object.values(hoverTimersRef.current).forEach((timerId) => window.clearInterval(timerId));
      hoverTimersRef.current = {};
    };
  }, []);

  const fetchProducts = async () => {
    try {
      setLoading(true);
      const params = new URLSearchParams();

      if (sortBy === 'near_me') {
        // Fetch all products so we can sort client-side by shop distance
        params.append('sort', '-created_at');
        params.append('page', '1');
        params.append('per_page', '1000');
      } else if (sortBy === 'best_selling') {
        // Fetch more items so unrated products can be removed before display
        params.append('sort', '-sales_count');
        params.append('page', '1');
        params.append('per_page', '1000');
      } else {
        // Sorting (use - prefix for descending)
        if (sortBy === 'featured') {
          params.append('sort', '-created_at');
        } else if (sortBy === 'best_selling') {
          params.append('sort', '-sales_count');
        } else if (sortBy === 'name_asc') {
          params.append('sort', 'name');
        } else if (sortBy === 'name_desc') {
          params.append('sort', '-name');
        } else if (sortBy === 'price_asc') {
          params.append('sort', 'price');
        } else if (sortBy === 'price_desc') {
          params.append('sort', '-price');
        } else if (sortBy === 'created_at_asc') {
          params.append('sort', 'created_at');
        } else {
          params.append('sort', '-created_at');
        }

        // Pagination
        params.append('page', currentPage.toString());
      }

      // Search filter
      if (searchQuery) {
        params.append('filter[search_all]', searchQuery);
      }

      // Category filter (only for supported storefront categories)
      if (activeCategory) {
        params.append('filter[category]', activeCategory);
      }

      const response = await fetch(`/api/products/?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
      });

      if (!response.ok) throw new Error('Failed to fetch products');

      const data = await response.json();
      let productsData = data.products.data || [];

      if (sortBy === 'best_selling') {
        productsData = productsData
          .filter((product) => Number(product.average_rating ?? 0) > 0)
          .sort((a, b) => {
            const ratingA = Number(a.average_rating ?? 0);
            const ratingB = Number(b.average_rating ?? 0);
            const salesA = Number(a.sales_count ?? 0);
            const salesB = Number(b.sales_count ?? 0);

            if (ratingB !== ratingA) {
              return ratingB - ratingA;
            }

            return salesB - salesA;
          });
      }

      setProducts(productsData);
      setCurrentPage(data.products.current_page || 1);
      setLastPage(data.products.last_page || 1);
      setTotal(data.products.total || 0);
    } catch (error) {
      console.error('Error fetching products:', error);
    } finally {
      setLoading(false);
    }
  };

  const goToPage = (page: number) => {
    if (page >= 1 && page <= lastPage) {
      setCurrentPage(page);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const sortLabelMap: Record<string, string> = {
    featured: 'Featured',
    best_selling: 'Best selling',
    name_asc: 'Alphabetically, A-Z',
    name_desc: 'Alphabetically, Z-A',
    price_asc: 'Price, low to high',
    price_desc: 'Price, high to low',
    created_at_asc: 'Date, old to new',
    created_at_desc: 'Date, new to old',
    near_me: 'Near me📍',
  };

  const sortOptions = [
    { value: 'near_me', label: 'Near me' },
    { value: 'featured', label: 'Featured' },
    { value: 'best_selling', label: 'Best selling' },
    { value: 'name_asc', label: 'Alphabetically, A-Z' },
    { value: 'name_desc', label: 'Alphabetically, Z-A' },
    { value: 'price_asc', label: 'Price, low to high' },
    { value: 'price_desc', label: 'Price, high to low' },
    { value: 'created_at_asc', label: 'Date, old to new' },
    { value: 'created_at_desc', label: 'Date, new to old' },
  ];

  const getProductImages = (product: Product) => {
    const images = [product.main_image, ...(product.gallery_images ?? [])].filter(Boolean) as string[];
    return Array.from(new Set(images));
  };

  const startImageCycle = (product: Product) => {
    const images = getProductImages(product);
    if (images.length <= 1) return;

    setActiveImageIndexes((prev) => ({ ...prev, [product.id]: 1 }));

    if (hoverTimersRef.current[product.id]) {
      window.clearInterval(hoverTimersRef.current[product.id]);
    }

    hoverTimersRef.current[product.id] = window.setInterval(() => {
      setActiveImageIndexes((prev) => {
        const currentIndex = prev[product.id] ?? 1;
        return {
          ...prev,
          [product.id]: (currentIndex + 1) % images.length,
        };
      });
    }, 800);
  };

  const stopImageCycle = (productId: number) => {
    if (hoverTimersRef.current[productId]) {
      window.clearInterval(hoverTimersRef.current[productId]);
      delete hoverTimersRef.current[productId];
    }

    setActiveImageIndexes((prev) => ({ ...prev, [productId]: 0 }));
  };

  // --- Near Me ---
  const requestLocation = useCallback(() => {
    if (!navigator.geolocation) {
      setLocError('Geolocation is not supported by your browser.');
      return;
    }
    setLocating(true);
    setLocError(null);
    void getCurrentPositionWithTimeout(GPS_POSITION_OPTIONS)
      .then((position) => {
        setUserCoords({ lat: position.coords.latitude, lng: position.coords.longitude });
      })
      .catch(() => {
        setLocError('Location access denied. Please allow location access and try again.');
      })
      .finally(() => setLocating(false));
  }, []);

  useEffect(() => {
    if (sortBy === 'near_me' && !userCoords) {
      requestLocation();
    }
  }, [sortBy, userCoords, requestLocation]);

  const sortedProducts = useMemo(() => {
    if (sortBy !== 'near_me' || !userCoords) return products;
    return [...products].sort((a, b) => {
      const aLat = a.shop_owner?.latitude;
      const aLng = a.shop_owner?.longitude;
      const bLat = b.shop_owner?.latitude;
      const bLng = b.shop_owner?.longitude;
      const aDist =
        aLat != null && aLng != null
          ? haversine(userCoords.lat, userCoords.lng, aLat, aLng)
          : Infinity;
      const bDist =
        bLat != null && bLng != null
          ? haversine(userCoords.lat, userCoords.lng, bLat, bLng)
          : Infinity;
      return aDist - bDist;
    });
  }, [products, sortBy, userCoords]);

  const getShopDistance = (product: Product): number | null => {
    if (!userCoords || product.shop_owner?.latitude == null || product.shop_owner?.longitude == null)
      return null;
    return haversine(userCoords.lat, userCoords.lng, product.shop_owner.latitude!, product.shop_owner.longitude!);
  };

  const isShowroomSearch = searchQuery.trim().toLowerCase().includes('showroom');
  const displayProducts = useMemo(() => {
    const query = mobileSearchQuery.trim().toLowerCase();
    let baseProducts = sortedProducts;

    // Safety net: best selling should only show rated products, highest rated first.
    if (sortBy === 'best_selling') {
      baseProducts = [...sortedProducts]
        .filter((product) => Number(product.average_rating ?? 0) > 0)
        .sort((a, b) => {
          const ratingA = Number(a.average_rating ?? 0);
          const ratingB = Number(b.average_rating ?? 0);
          const salesA = Number(a.sales_count ?? 0);
          const salesB = Number(b.sales_count ?? 0);

          if (ratingB !== ratingA) {
            return ratingB - ratingA;
          }

          return salesB - salesA;
        });
    }

    if (!query) return baseProducts;

    return baseProducts.filter((product) => {
      const name = (product.name || '').toLowerCase();
      const brand = (product.brand || '').toLowerCase();
      const shopName = (product.shop_owner?.business_name || product.shop_owner?.name || '').toLowerCase();
      return name.includes(query) || brand.includes(query) || shopName.includes(query);
    });
  }, [sortedProducts, mobileSearchQuery, sortBy]);
  const buttonBaseClass =
    'group inline-flex items-center justify-center gap-2 rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const buttonDarkClass =
    'border border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/50';
  const buttonLightClass =
    'border border-gray-300 bg-white text-gray-900 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';
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
      <Head title="Products" />
      <div className="userside-products-page min-h-screen bg-white font-outfit antialiased">
        <div className="hidden xl:block">
          <Navigation />
        </div>

        <div className="fixed top-0 left-0 right-0 z-50 flex h-16 items-center gap-2 bg-white px-3 shadow-sm xl:hidden">
          {/* Home button / Breadcrumb */}
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

          {/* Search field */}
          <div ref={mobileSearchContainerRef} className="relative flex-1">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (mobileSearchQuery.trim()) {
                  const params = new URLSearchParams();
                  params.append('search', mobileSearchQuery.trim());
                  window.location.href = `/products?${params.toString()}`;
                }
              }}
              className="relative"
            >
              <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 z-20">
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35m1.6-5.4a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
              </span>
              <input
                type="text"
                value={mobileSearchQuery}
                onChange={(e) => setMobileSearchQuery(e.target.value)}
                onFocus={() => setMobileSearchFocused(true)}
                placeholder="Search products"
                className="w-full rounded-full border border-gray-300 bg-gray-50 py-2 pl-9 pr-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-[#16233b] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#16233b]/20"
                aria-label="Search products"
              />
            </form>

            {/* Mobile Search Suggestions Dropdown */}
            {mobileSearchFocused && mobileSearchQuery.trim().length >= 2 && (
              <div className="absolute left-0 right-0 top-[calc(100%+8px)] z-50 w-full max-w-sm overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                {isMobileSearchingSuggestions ? (
                  <div className="px-4 py-3 text-sm text-gray-500">Searching suggestions...</div>
                ) : mobileSuggestionProducts.length === 0 && mobileSuggestionShops.length === 0 ? (
                  <div className="px-4 py-3 text-sm text-gray-500">No suggestions found.</div>
                ) : (
                  <>
                    <div className="border-b border-gray-200 px-4 py-2">
                      <p className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Suggestions</p>
                    </div>
                    <div className="max-h-80 overflow-y-auto">
                      {mobileSuggestionProducts.length > 0 && (
                        <div className="border-b border-gray-200 px-3 py-2">
                          <p className="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Products</p>
                          {mobileSuggestionProducts.slice(0, 5).map((product) => (
                            <Link
                              key={`mobile-product-${product.id}`}
                              href={product.url}
                              className="flex items-center gap-2 rounded-lg px-2 py-2 transition hover:bg-gray-50"
                              onClick={() => setMobileSearchFocused(false)}
                            >
                              <div className="h-8 w-8 shrink-0 overflow-hidden rounded-lg border border-gray-100 bg-gray-100">
                                {product.image ? (
                                  <img src={product.image} alt={product.name} className="h-full w-full object-cover" />
                                ) : (
                                  <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-gray-500">P</div>
                                )}
                              </div>
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-xs font-medium text-gray-900">{product.name}</p>
                              </div>
                            </Link>
                          ))}
                        </div>
                      )}

                      {mobileSuggestionShops.length > 0 && (
                        <div className="px-3 py-2">
                          <p className="px-1 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">
                            Shop Profiles
                          </p>
                          {mobileSuggestionShops.slice(0, 4).map((shop) => (
                            <Link
                              key={`mobile-shop-${shop.id}`}
                              href={shop.url}
                              className="flex items-center gap-2 rounded-lg px-2 py-2 transition hover:bg-gray-50"
                              onClick={() => setMobileSearchFocused(false)}
                            >
                              <div className="h-8 w-8 shrink-0 overflow-hidden rounded-full border border-gray-100 bg-gray-100">
                                {shop.image ? (
                                  <img src={shop.image} alt={shop.name} className="h-full w-full object-cover" />
                                ) : (
                                  <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-gray-500">S</div>
                                )}
                              </div>
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-xs font-medium text-gray-900">{shop.name}</p>
                                {shop.location && <p className="truncate text-[10px] text-gray-500">{shop.location}</p>}
                              </div>
                            </Link>
                          ))}
                        </div>
                      )}
                    </div>
                  </>
                )}
              </div>
            )}
          </div>

          {isAuthenticated && (
            <NotificationBell
              basePath="/api/notifications"
              iconSize={20}
              className="h-9 w-9 rounded-full text-gray-700 hover:bg-gray-100 hover:text-[#16233b] transition-colors"
              badgeClassName="absolute right-0 top-0 inline-flex h-5 min-w-5 translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold leading-none text-white ring-2 ring-white"
            />
          )}

          {/* User / Account icon with dropdown */}
          <div className="relative" ref={mobileAccountRef}>
            <button
              type="button"
              onClick={() => setMobileAccountOpen((o) => !o)}
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 transition-colors"
              aria-label="Account"
              title="Account"
            >
              <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </button>

            {mobileAccountOpen && (
              <>
                {/* Backdrop */}
                <div
                  className="fixed inset-0 z-40"
                  onClick={() => setMobileAccountOpen(false)}
                />
                {/* Dropdown */}
                <div className="absolute right-0 top-full z-50 mt-2 w-52 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_18px_35px_-20px_rgba(15,23,42,0.45)]">
                  {isAuthenticated ? (
                    <>
                      <Link
                        href="/customer-profile"
                        onClick={() => setMobileAccountOpen(false)}
                        className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50 border-b border-gray-100"
                      >
                        <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit Profile
                      </Link>
                      <Link
                        href="/shop-owner-register"
                        onClick={() => setMobileAccountOpen(false)}
                        className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50 border-b border-gray-100"
                      >
                        <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m8-8a4 4 0 100-8 4 4 0 000 8zm6-3v6m3-3h-6" />
                        </svg>
                        Join Our Team
                      </Link>
                      <button
                        type="button"
                        onClick={() => { setMobileAccountOpen(false); handleLogout(); }}
                        className="flex w-full items-center gap-3 px-4 py-3 text-sm font-medium text-red-600 hover:bg-red-50"
                      >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Log out
                      </button>
                    </>
                  ) : (
                    <Link
                      href="/login"
                      onClick={() => setMobileAccountOpen(false)}
                      className="flex items-center gap-3 px-4 py-3 text-sm font-medium text-black hover:bg-gray-50"
                    >
                      <svg className="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                      </svg>
                      Login
                    </Link>
                  )}
                </div>
              </>
            )}
          </div>

          {/* Cart icon with badge */}
          <Link
            href="/checkout"
            className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-700 hover:bg-gray-100 hover:text-[#16233b] transition-colors"
            aria-label="Shopping cart"
          >
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

        <div className="mx-auto w-full max-w-[430px] px-4 pb-24 pt-16 md:max-w-none md:px-5 lg:px-6 xl:max-w-[1920px] xl:px-6 xl:pb-20 xl:pt-32 2xl:px-12 2xl:pb-20">
          <div className="mb-8 w-full md:max-w-none">
            <div className="flex items-center justify-between gap-4 mb-6">
              <nav className="text-[11px] xl:text-xs text-black/55 tracking-[0.18em] uppercase">
                <Link href="/" className="hover:text-black transition-colors">Home</Link>
                <span className="mx-2 text-black/35">/</span>
                <span>All Shoes</span>
              </nav>
              <div className="relative" ref={sortMenuRef}>
                <button
                  type="button"
                  onClick={() => setIsSortOpen((prev) => !prev)}
                  className="flex items-center gap-2 text-sm text-black/80 hover:text-black transition-colors"
                >
                  <span>
                    <span className="font-semibold">Sort by:</span>{' '}
                    <span>{sortLabelMap[sortBy]}</span>
                  </span>
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                    <svg
                      className={`h-3.5 w-3.5 text-gray-700 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
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
                  <div className="absolute right-0 left-auto z-20 mt-3 w-[min(92vw,14.5rem)] rounded-2xl border border-gray-300 bg-white py-3 shadow-[0_20px_40px_-24px_rgba(15,23,42,0.55)] xl:w-56" role="menu">
                    {sortOptions.map((option) => {
                      const isActive = sortBy === option.value;

                      return (
                        <button
                          key={option.value}
                          type="button"
                          role="menuitem"
                          onClick={() => {
                            if (option.value === 'near_me') {
                              setSortBy('near_me');
                              setCurrentPage(1);
                              setIsSortOpen(false);
                              if (!userCoords) requestLocation();
                            } else {
                              setSortBy(option.value);
                              setIsSortOpen(false);
                            }
                          }}
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
          </div>

          {locError && (
            <div className="mb-8 rounded-2xl bg-red-50 border border-red-200 px-5 py-3.5 text-sm text-red-700">
              {locError}
            </div>
          )}

          {searchQuery && (
            <>
              <h1 className="mb-2 text-2xl sm:text-3xl font-bold tracking-tight text-black uppercase xl:mb-3 xl:text-4xl xl:font-bold 2xl:text-5xl">
                Search Results for "{searchQuery}"
              </h1>
              <p className="mb-8 max-w-3xl text-sm sm:text-base font-light leading-relaxed text-black/65 xl:mb-10">
                Showing results matching "{searchQuery}"
              </p>
            </>
          )}

          {searchQuery && (
            <div className="mb-10">
              <h2 className="mb-4 text-xl font-bold text-black uppercase tracking-[0.08em]">
                {isShowroomSearch ? 'Shops with virtual showroom' : 'Matching shops'}
              </h2>
              {loadingShops ? (
                <p className="text-sm text-black/60">Loading shop profiles...</p>
              ) : shopResults.length === 0 ? (
                <p className="text-sm text-black/60">
                  {isShowroomSearch
                    ? 'No shop profiles with virtual showroom were found.'
                    : 'No matching shop profiles found.'}
                </p>
              ) : (
                <div className="grid gap-4 xl:grid-cols-2 2xl:grid-cols-3">
                  {shopResults.map((shop) => (
                    <div key={shop.id} className="rounded-2xl border border-gray-300 bg-white p-4 shadow-[0_16px_30px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)]">
                      <Link href={shop.url} className="flex items-center gap-3">
                        <div className="h-11 w-11 overflow-hidden rounded-full bg-gray-100 border border-gray-200">
                          {shop.image ? (
                            <img src={shop.image} alt={shop.name} className="h-full w-full object-cover" />
                          ) : (
                            <div className="flex h-full w-full items-center justify-center text-xs text-gray-500">S</div>
                          )}
                        </div>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-black uppercase tracking-[0.06em]">{shop.name}</p>
                          {shop.location && <p className="truncate text-xs text-black/55">{shop.location}</p>}
                        </div>
                      </Link>
                      <div className="mt-3 flex gap-2">
                        <Link
                          href={shop.url}
                          className={`${buttonBaseClass} ${buttonLightClass}`}
                        >
                          View profile
                        </Link>
                        {shop.virtual_showroom_url && (
                          <Link
                            href={shop.virtual_showroom_url}
                            className={`${buttonBaseClass} ${buttonDarkClass}`}
                          >
                            Virtual showroom
                          </Link>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {loading ? (
            <div className="text-center py-16 border border-gray-200 rounded-3xl bg-gray-50/50">
              <p className="text-black/60">Loading products...</p>
            </div>
          ) : products.length === 0 ? (
            <div className="text-center py-16 border border-gray-200 rounded-3xl bg-gray-50/50">
              <p className="text-black/60">
                {searchQuery && shopResults.length > 0
                  ? 'No matching products found, but matching shop profiles are available above.'
                  : 'No products available for this search right now.'}
              </p>
            </div>
          ) : (
            <div className="grid auto-rows-fr grid-cols-2 gap-3 xl:gap-4 xl:grid-cols-3 2xl:grid-cols-4">
              {displayProducts.map((p) => {
                const productImages = getProductImages(p);
                const activeImageIndex = activeImageIndexes[p.id] ?? 0;
                const activeImage = productImages[activeImageIndex] ?? p.main_image;
                const shopDist = (sortBy === 'near_me' && userCoords) ? getShopDistance(p) : null;
                const productHref = activeCategory
                  ? `/products/${p.slug}?category=${encodeURIComponent(activeCategory)}`
                  : `/products/${p.slug}`;

                return (
                <Link
                  key={p.id}
                  href={productHref}
                  className="group block h-full rounded-2xl border border-gray-200 bg-white shadow-[0_12px_28px_-24px_rgba(15,23,42,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-gray-300 hover:shadow-[0_24px_40px_-24px_rgba(15,23,42,0.55)] xl:rounded-3xl xl:border-gray-300 xl:shadow-[0_16px_35px_-24px_rgba(15,23,42,0.45)]"
                  onMouseEnter={() => startImageCycle(p)}
                  onMouseLeave={() => stopImageCycle(p.id)}
                >
                  <div className="relative flex h-full flex-col overflow-hidden rounded-2xl bg-white xl:rounded-3xl">
                    {p.compare_at_price && p.compare_at_price > p.price && (
                      <div className="absolute left-4 top-4 bg-red-600 text-white text-[10px] px-3 py-1.5 rounded-full font-semibold uppercase tracking-[0.14em] z-10 shadow-sm">
                        SALE
                      </div>
                    )}
                    {p.stock_quantity === 0 && (
                      <div className="absolute left-4 top-4 bg-black text-white text-[10px] px-3 py-1.5 rounded-full font-semibold uppercase tracking-[0.14em] z-10 shadow-sm">
                        SOLD OUT
                      </div>
                    )}

                    <div className="relative aspect-3/4 overflow-hidden bg-gray-50 xl:aspect-square">
                      {activeImage ? (
                        <>
                          {productImages.map((image, imageIndex) => {
                            const isActiveImage = imageIndex === activeImageIndex;

                            return (
                              <img
                                key={`${p.id}-${imageIndex}`}
                                src={image}
                                alt={p.name}
                                className={`absolute inset-0 h-full w-full object-cover transition-all duration-700 ease-in-out ${
                                  isActiveImage
                                    ? 'opacity-100 scale-100 group-hover:scale-110'
                                    : 'opacity-0 scale-100 pointer-events-none'
                                }`}
                                loading="lazy"
                                onError={(e) => {
                                  if (p.main_image) {
                                    e.currentTarget.src = p.main_image;
                                  }
                                }}
                              />
                            );
                          })}
                        </>
                      ) : (
                        <div className="text-gray-400 text-sm">No Image</div>
                      )}
                    </div>

                    <div className="flex min-h-36 flex-col border-t border-gray-200 p-2.5 xl:min-h-48.5 xl:p-3.5">
                      <div className="flex items-start justify-between gap-2 mb-1">
                        <h3 className="mb-1 min-h-8 line-clamp-2 text-xs font-bold uppercase tracking-[0.06em] text-black flex-1 xl:mb-1.5 xl:min-h-10 xl:text-sm">{p.name}</h3>
                        <div className="shrink-0">
                          {p.average_rating !== undefined && (
                            <StarRating rating={p.average_rating} size="sm" />
                          )}
                        </div>
                      </div>
                      
                      <div className="mb-1 min-h-4 xl:mb-1.5 xl:min-h-[1.1rem]">
                        {p.brand && (
                          <p className="text-[10px] uppercase tracking-[0.12em] text-black/55 xl:text-xs">{p.brand}</p>
                        )}
                      </div>
                      
                      <div className="mb-1 hidden min-h-[1.1rem] xl:mb-1.5 xl:block">
                        {p.shop_owner && (
                          <p className="text-xs text-black/60">
                            Sold by{' '}
                            <span
                              className="font-semibold text-black hover:underline"
                              onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                window.location.href = `/shop-profile/${p.shop_owner?.id}`;
                              }}
                            >
                              {p.shop_owner.business_name || p.shop_owner.name || 'Shop'}
                            </span>
                          </p>
                        )}
                      </div>

                      {/* Distance indicator hidden */}
                      
                      <div className="mt-auto flex items-baseline justify-between border-t border-gray-200 pt-2 xl:pt-3">
                        <div className="flex flex-col gap-0.5">
                          <div className="text-base font-bold text-black xl:text-lg">₱{p.price.toLocaleString()}</div>
                          {p.compare_at_price && p.compare_at_price > p.price && (
                            <div className="text-xs text-black/40 line-through">₱{p.compare_at_price.toLocaleString()}</div>
                          )}
                        </div>
                        <div className="text-[10px] uppercase tracking-[0.08em] text-black/55 xl:text-xs">
                          {p.stock_quantity > 0 ? `${p.stock_quantity} left` : 'Out of stock'}
                        </div>
                      </div>
                    </div>
                  </div>
                </Link>
                );
              })}
            </div>
          )}

          {/* Pagination */}
          {!loading && products.length > 0 && lastPage > 1 && sortBy !== 'near_me' && (
            <div className="mt-10 flex items-center justify-center gap-2">
              <button
                onClick={() => goToPage(currentPage - 1)}
                disabled={currentPage === 1}
                className={`px-4 py-2 border rounded-full text-xs font-semibold uppercase tracking-[0.12em] transition-colors ${
                  currentPage === 1
                    ? 'border-gray-200 text-gray-400 cursor-not-allowed'
                    : 'border-gray-300 text-black hover:bg-gray-50'
                }`}
              >
                Previous
              </button>

              <div className="flex items-center gap-1">
                {Array.from({ length: lastPage }, (_, i) => i + 1).map((page) => {
                  if (
                    page === 1 ||
                    page === lastPage ||
                    (page >= currentPage - 1 && page <= currentPage + 1)
                  ) {
                    return (
                      <button
                        key={page}
                        onClick={() => goToPage(page)}
                        className={`min-w-9 px-3 py-2 border rounded-full text-xs font-semibold uppercase tracking-[0.12em] transition-colors ${
                          currentPage === page
                            ? 'bg-[#16233b] text-white border-[#16233b]'
                            : 'border-gray-300 text-black hover:bg-gray-50'
                        }`}
                      >
                        {page}
                      </button>
                    );
                  } else if (page === currentPage - 2 || page === currentPage + 2) {
                    return (
                      <span key={page} className="px-2 text-gray-400">...</span>
                    );
                  }
                  return null;
                })}
              </div>

              <button
                onClick={() => goToPage(currentPage + 1)}
                disabled={currentPage === lastPage}
                className={`px-4 py-2 border rounded-full text-xs font-semibold uppercase tracking-[0.12em] transition-colors ${
                  currentPage === lastPage
                    ? 'border-gray-200 text-gray-400 cursor-not-allowed'
                    : 'border-gray-300 text-black hover:bg-gray-50'
                }`}
              >
                Next
              </button>
            </div>
          )}

          {/* Results info */}
          {!loading && products.length > 0 && (
            <div className="mt-5 hidden text-center text-xs uppercase tracking-[0.14em] text-black/50 xl:block">
              {sortBy === 'near_me'
                ? `Showing ${displayProducts.length} products sorted by distance`
                : `Showing ${products.length} of ${total} products (Page ${currentPage} of ${lastPage})`}
            </div>
          )}
        </div>

        <div className="fixed bottom-0 left-0 right-0 z-40 border-t border-gray-200 bg-white xl:hidden">
          <div className="mx-auto grid w-full max-w-[430px] grid-cols-5 px-2 py-2 text-[11px] text-gray-600 md:max-w-none md:px-4">
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

export default Products;
